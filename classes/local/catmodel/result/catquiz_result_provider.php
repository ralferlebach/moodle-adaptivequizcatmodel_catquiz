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
use local_catquiz\local\result\global_scale_validity;
use local_catquiz\local\result\personparam_repository;
use local_catquiz\teststrategy\progress;
use mod_adaptivequiz\local\result\attempt_result;
use mod_adaptivequiz\local\result\result_definition;
use mod_adaptivequiz\local\result\result_provider;
use stdClass;

/**
 * The results of a CATquiz test, for the gradebook of the activity (issue #7).
 *
 * The only place where CATquiz results are translated into the host's result contract
 * (mod_adaptivequiz issue #14). The score is the ability of the attempt's global scale - the scale of
 * the test when the attempt was finalised - as the finaliser filed it for this attempt (decision of
 * 2026-10-14: graded on the global scale, not on the scale a strategy reports first). It is gradable
 * when the global scale meets every criterion the test sets - minimum questions per scale and of the
 * test, maximum standard error, the general fraction rule - as local_catquiz global_scale_validity
 * judges it with the test settings of the attempt; that it is not the reported scale, or not reported
 * at all, does not stand in the way. Never the current person parameters, which later
 * attempts overwrite, and never the host's measure, which CATquiz does not maintain. The range is the
 * ability range of the scale as CATquiz froze it with the result when it finalised the attempt
 * (local_catquiz #137), so a later change of the scale does not change what the attempt was worth -
 * not even when the host takes its snapshot again.
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

    /** @var string No result is stored for the finalised attempt (finalised before CATquiz stored one per attempt). */
    public const REASON_NO_RESULT = 'no_stored_result';

    /** @var string The attempt has a stored result, but none for its global scale. */
    public const REASON_NO_GLOBAL = 'global_scale_missing';

    /** @var string Snapshots taken before 2026-10-14: no primary scale. Kept for reading them. */
    public const REASON_NO_PRIMARY = 'no_primary_scale';

    /** @var string Snapshots taken before 2026-10-14: more than one valid primary scale. Kept for reading them. */
    public const REASON_AMBIGUOUS = 'primary_ambiguous';

    /**
     * @var string[] Reasons that say how a scale is shown, not how well it was measured. They do not
     *      stand in the way of grading the global scale.
     */
    private const PRESENTATION_REASONS = ['not_primary', 'reporting_disabled', 'hidden'];

    /** @var string The primary scale has no range. */
    public const REASON_NO_RANGE = 'range_unavailable';

    /** @var string The attempt was finalised before CATquiz recorded the range with the result. */
    public const REASON_RANGE_NOT_RECORDED = 'range_not_recorded';

    /** @var string A global scale result that CATquiz rejected without naming a reason. */
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
     * The finalised result of the attempt's global scale.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt adaptivequiz_attempt
     * @return attempt_result
     */
    public function get_attempt_result(stdClass $adaptivequiz, stdClass $attempt): attempt_result {
        global $DB;

        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', IGNORE_MULTIPLE);
        if (!$catattempt || empty($catattempt->endtime)) {
            return attempt_result::invalid(self::REASON_NOT_FINALISED);
        }

        $rows = personparam_repository::get_for_attempt((int) $catattempt->id);
        if (!$rows) {
            return attempt_result::invalid(self::REASON_NO_RESULT);
        }
        $globalscaleid = !empty($catattempt->scaleid) ? (int) $catattempt->scaleid : self::scale_of($adaptivequiz);
        $global = array_values(array_filter($rows, fn($row) => (int) $row->catscaleid === $globalscaleid));
        if (!$global) {
            return attempt_result::invalid(self::REASON_NO_GLOBAL);
        }
        $row = $global[0];

        $rejection = self::rejection($row, self::quiz_settings($catattempt, $adaptivequiz));
        if ($rejection !== null) {
            return attempt_result::invalid($rejection);
        }
        // Issue #137 (CATquiz): the range frozen when the attempt was finalised - never today's.
        if (!isset($row->rangelower, $row->rangeupper) || !is_numeric($row->rangelower) || !is_numeric($row->rangeupper)) {
            // Finalised before ranges were recorded: the range of that time is unknown, and none is invented.
            return attempt_result::invalid(self::REASON_RANGE_NOT_RECORDED);
        }

        return attempt_result::valid((float) $row->ability, (float) $row->rangelower, (float) $row->rangeupper);
    }

    /**
     * Why the stored result of a global scale is not gradable, or null when it is.
     *
     * The criteria of local_catquiz global_scale_validity, applied to the stored N, standard error and
     * fraction of the global scale. A CATquiz without that class (before 2026100918) falls back to its
     * stored verdict, less the reasons that only say how a scale is shown.
     *
     * @param stdClass $row local_catquiz_personparams (ability, n, standarderror, fraction, isvalid, validationstatus).
     * @param stdClass|null $quizsettings The test settings of the attempt; null applies no configured criterion.
     * @return string|null The reasons, comma separated.
     */
    public static function rejection(stdClass $row, ?stdClass $quizsettings = null): ?string {
        if (!is_numeric($row->ability ?? null)) {
            return self::REASON_INVALID;
        }
        if (class_exists(global_scale_validity::class)) {
            $reasons = global_scale_validity::reasons($row, $quizsettings);
            return $reasons ? implode(',', $reasons) : null;
        }
        if (!empty($row->isvalid)) {
            return null;
        }
        $reasons = array_values(array_filter(array_map('trim', explode(',', (string) ($row->validationstatus ?? '')))));
        if (!$reasons) {
            return self::REASON_INVALID;
        }
        $statistical = array_values(array_diff($reasons, self::PRESENTATION_REASONS));
        return $statistical ? implode(',', $statistical) : null;
    }

    /**
     * The test settings an attempt ran with: from its progress, else the test's settings today.
     *
     * @param stdClass $catattempt local_catquiz_attempts
     * @param stdClass $adaptivequiz
     * @return stdClass|null
     */
    public static function quiz_settings(stdClass $catattempt, stdClass $adaptivequiz): ?stdClass {
        global $DB;
        try {
            $progress = progress::load_for_reading((int) $catattempt->id);
            if ($progress !== null) {
                return $progress->get_quiz_settings();
            }
        } catch (\Throwable $e) {
            // Unreadable progress: the test's settings today.
            unset($e);
        }
        $instanceid = $adaptivequiz->id ?? $catattempt->instanceid ?? null;
        $json = $instanceid ? $DB->get_field(
            'local_catquiz_tests',
            'json',
            ['component' => 'mod_adaptivequiz', 'componentid' => $instanceid],
            IGNORE_MULTIPLE
        ) : false;
        $settings = $json ? json_decode($json) : null;
        return $settings instanceof stdClass ? $settings : null;
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
