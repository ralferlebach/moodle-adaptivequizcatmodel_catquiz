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

namespace adaptivequizcatmodel_catquiz;

use adaptivequizcatmodel_catquiz\local\catmodel\result\catquiz_result_provider;
use advanced_testcase;

/**
 * The result of an attempt is read on the range CATquiz froze at finalisation (local_catquiz #137).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\result\catquiz_result_provider
 */
final class result_provider_frozen_range_test extends advanced_testcase {
    /**
     * A finalised attempt whose primary result is filed with the given range.
     *
     * @param float|null $lower
     * @param float|null $upper
     * @return array [scaleid, adaptivequiz, attempt]
     */
    private function attempt(?float $lower, ?float $upper): array {
        global $DB;
        $scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'name' => 'Root', 'parentid' => 0, 'minscalevalue' => -3, 'maxscalevalue' => 3,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $attempt = (object) ['id' => 4711];
        $catattemptid = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => 2, 'attemptid' => $attempt->id, 'component' => 'mod_adaptivequiz', 'contextid' => 9,
            'status' => 0, 'endtime' => time(), 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_catquiz_personparams', (object) [
            'attemptid' => $catattemptid, 'userid' => 2, 'contextid' => 9, 'catscaleid' => $scaleid, 'ability' => 1.5,
            'isprimary' => 1, 'isvalid' => 1, 'resultsource' => 'current', 'validationstatus' => '',
            'n' => 4, 'standarderror' => 0.3, 'fraction' => 0.5,
            'rangelower' => $lower, 'rangeupper' => $upper, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        return [$scaleid, (object) ['id' => 1, 'catquiz_catscales' => $scaleid], $attempt];
    }

    /**
     * The frozen range counts, not the scale as it is today.
     */
    public function test_frozen_range_is_used(): void {
        global $DB;
        $this->resetAfterTest();
        [$scaleid, $adaptivequiz, $attempt] = $this->attempt(-5.0, 5.0);
        $DB->update_record('local_catquiz_catscales', (object) ['id' => $scaleid, 'minscalevalue' => -1, 'maxscalevalue' => 1]);

        $result = (new catquiz_result_provider())->get_attempt_result($adaptivequiz, $attempt);

        $this->assertTrue($result->valid);
        $this->assertEqualsWithDelta(1.5, $result->score, 1e-9);
        $this->assertEqualsWithDelta(-5.0, $result->lower, 1e-9);
        $this->assertEqualsWithDelta(5.0, $result->upper, 1e-9);
    }

    /**
     * Without a frozen range there is no valid result - the range is not invented from today's scale.
     */
    public function test_attempt_without_frozen_range(): void {
        $this->resetAfterTest();
        [, $adaptivequiz, $attempt] = $this->attempt(null, null);

        $result = (new catquiz_result_provider())->get_attempt_result($adaptivequiz, $attempt);

        $this->assertFalse($result->valid);
        $this->assertSame(catquiz_result_provider::REASON_RANGE_NOT_RECORDED, $result->reason);
    }
}
