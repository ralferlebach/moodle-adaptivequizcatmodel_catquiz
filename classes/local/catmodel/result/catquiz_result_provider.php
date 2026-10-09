<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace adaptivequizcatmodel_catquiz\local\catmodel\result;

use local_catquiz\catscale;
use local_catquiz\local\result\personparam_repository;
use mod_adaptivequiz\local\result\attempt_result;
use mod_adaptivequiz\local\result\result_definition;
use mod_adaptivequiz\local\result\result_provider;
use stdClass;

/**
 * The results of a CATquiz test, for the gradebook of the activity (issue #7).
 *
 * The only place where CATquiz results are translated into the host's result contract
 * (mod_adaptivequiz issue #14). The score is the ability of the primary scale as the finaliser
 * filed it for this attempt, with CATquiz's own verdict on its validity - not the current person
 * parameters, which later attempts overwrite, and never the host's measure, which CATquiz does not
 * maintain. The range is the ability range of the scale; the host keeps it with the attempt, so a
 * later change of the scale does not change what the attempt was worth.
 *
 * Gradebook and completion stay with the host: this class only reports.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catquiz_result_provider implements result_provider {
    /** @var string The attempt has not been finalised by CATquiz. */
    public const REASON_NOT_FINALISED = 'not_finalised';

    /** @var string The finalised attempt has no primary scale. */
    public const REASON_NO_PRIMARY = 'no_primary_scale';

    /** @var string The finalised attempt has more than one valid primary scale. */
    public const REASON_AMBIGUOUS = 'primary_ambiguous';

    /** @var string The primary scale has no range. */
    public const REASON_NO_RANGE = 'range_unavailable';

    /** @var string A primary scale that CATquiz rejected without naming a reason. */
    public const REASON_INVALID = 'invalid';

    /**
     * The ability range of the test's scale.
     *
     * @param stdClass $adaptivequiz The instance, or the form data merged into it.
     * @return result_definition
     */
    public function get_result_definition(stdClass $adaptivequiz): result_definition {
        $scaleid = self::scale_of($adaptivequiz);
        if (!$scaleid) {
            return new result_definition(true, null, null, get_string('resultsourcenoscale', 'adaptivequizcatmodel_catquiz'));
        }

        $range = self::range($scaleid);
        $name = self::scale_name($scaleid);
        $label = get_string('resultsource', 'adaptivequizcatmodel_catquiz', $name);

        return new result_definition(true, $range[0] ?? null, $range[1] ?? null, $label);
    }

    /**
     * The finalised result of the attempt's primary scale.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt adaptivequiz_attempt
     * @return attempt_result
     */
    public function get_attempt_result(stdClass $adaptivequiz, stdClass $attempt): attempt_result {
        global $DB;

        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id]);
        if (!$catattempt || empty($catattempt->endtime)) {
            return attempt_result::invalid(self::REASON_NOT_FINALISED);
        }

        $primaries = array_filter(
            personparam_repository::get_for_attempt((int) $catattempt->id),
            fn($row) => !empty($row->isprimary)
        );
        $valid = array_filter($primaries, fn($row) => !empty($row->isvalid) && is_numeric($row->ability));

        if (count($valid) > 1) {
            return attempt_result::invalid(self::REASON_AMBIGUOUS);
        }
        if (!$valid) {
            if (!$primaries) {
                return attempt_result::invalid(self::REASON_NO_PRIMARY);
            }
            ksort($primaries);
            $reason = trim((string) reset($primaries)->validationstatus);
            return attempt_result::invalid($reason !== '' ? $reason : self::REASON_INVALID);
        }

        $row = reset($valid);
        $range = self::range((int) $row->catscaleid);
        if ($range === null) {
            return attempt_result::invalid(self::REASON_NO_RANGE);
        }

        return attempt_result::valid((float) $row->ability, $range[0], $range[1]);
    }

    /**
     * The scale of the test: the one chosen in the form, else the stored one.
     *
     * @param stdClass $adaptivequiz
     * @return int 0 when there is none.
     */
    private static function scale_of(stdClass $adaptivequiz): int {
        global $DB;

        if (!empty($adaptivequiz->catquiz_catscales) && is_numeric($adaptivequiz->catquiz_catscales)) {
            return (int) $adaptivequiz->catquiz_catscales;
        }
        $instanceid = $adaptivequiz->id ?? $adaptivequiz->instance ?? null;
        if (empty($instanceid)) {
            return 0;
        }
        return (int) $DB->get_field(
            'local_catquiz_tests',
            'catscaleid',
            ['component' => 'mod_adaptivequiz', 'componentid' => $instanceid],
            IGNORE_MULTIPLE
        );
    }

    /**
     * The ability range of a scale, which is that of its root.
     *
     * @param int $scaleid
     * @return float[]|null [lower, upper], or null when the scale does not exist.
     */
    private static function range(int $scaleid): ?array {
        try {
            $range = (new catscale($scaleid))->get_ability_range();
        } catch (\Throwable $e) {
            return null;
        }
        return [(float) $range['minscalevalue'], (float) $range['maxscalevalue']];
    }

    /**
     * The name of a scale, for the settings.
     *
     * @param int $scaleid
     * @return string
     */
    private static function scale_name(int $scaleid): string {
        global $DB;
        return format_string((string) $DB->get_field('local_catquiz_catscales', 'name', ['id' => $scaleid]));
    }
}
