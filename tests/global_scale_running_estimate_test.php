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

use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use advanced_testcase;
use local_catquiz\catscale;
use local_catquiz\teststrategy\progress;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Engine #174 AK9 / DoD7 (re-audit of 20.11.2026): the global scale is estimated with every answer - while the attempt
 * runs, not only reconstructed at its end.
 *
 * The items of the simulation pool sit on the leaf scales (grandchildren of the global scale); the global scale has no
 * item of its own. Through host, adapter, engine and question usage, as attempt.php does it (request_simulator), the
 * two production lengths of #174 are played: lowest skill gap with 13 and relevant subscales with 17 questions. After
 * every answer, before the finalisation, the running state of the engine is read:
 *  - the answered question belongs to a descendant of the global scale, never to the global scale itself;
 *  - the progress counts it for the global scale: N of the global scale is the number of productive answers;
 *  - the progress has an estimate of the global scale, a finite number, and it moved with the answer;
 *  - the snapshot of the selection holds its standard error, a finite positive number.
 * At the end the result row of the global scale is the last running state: the same ability, N and standard error -
 * never the null fallback of the result validator, which stays a diagnosis of a defect
 * (global_scale_invariant_test in the engine).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class global_scale_running_estimate_test extends advanced_testcase {
    /**
     * The two production lengths of engine #174.
     *
     * @return array
     */
    public static function lengths(): array {
        return [
            'lowest skill gap, 13 questions' => [LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 13, '1101101101101101'],
            'relevant subscales, 17 questions' => [LOCAL_CATQUIZ_STRATEGY_RELSUBS, 17, '10011010101111110111'],
        ];
    }

    /**
     * Plays the attempt and reads the running state of the global scale after every answer.
     *
     * @param int $strategy
     * @param int $length
     * @param string $answers One character per question: 1 right, 0 wrong.
     * @dataProvider lengths
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lengths')]
    public function test_the_global_scale_is_estimated_with_every_answer(int $strategy, int $length, string $answers): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = trace_scenario::create($this->getDataGenerator(), true, [
            'cateststrategyid' => $strategy,
            'catquiz_minquestions' => $length,
            'catquiz_maxquestions' => $length,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ], 25);
        $DB->set_field('adaptivequiz', 'minimumquestions', 0, ['id' => $scenario->adaptivequiz->id]);
        $scenario->adaptivequiz->minimumquestions = 0;
        $rootid = (int) $DB->get_field('local_catquiz_catscales', 'id', ['parentid' => 0], MUST_EXIST);
        $descendants = array_map('intval', catscale::get_subscale_ids($rootid));
        $this->assertNotEmpty($descendants);
        $this->setUser($scenario->userid);

        $shown = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        $previous = null;
        $states = [];
        $answered = 0;
        while (!$shown['stopped'] && $answered < strlen($answers)) {
            $questionid = (int) $shown['questionid'];
            $answered++;
            $shown = request_simulator::request(
                $scenario->adaptivequiz,
                $scenario->context,
                $scenario->userid,
                $answers[$answered - 1] === '1'
            );
            if ($shown['stopped']) {
                break;
            }
            // The running state, before the finalisation.
            $catattemptid = (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $shown['attemptid']]);
            $progress = progress::load_for_reading($catattemptid);
            $this->assertNotNull($progress, "Answer $answered: the progress of the running attempt.");
            $played = $progress->get_playedquestions();
            $this->assertArrayHasKey($questionid, $played, "Answer $answered: the answered question was played.");
            $scale = (int) $played[$questionid]->catscaleid;
            $this->assertNotSame($rootid, $scale, "Answer $answered: the global scale has no item of its own.");
            $this->assertContains($scale, $descendants, "Answer $answered: an item of a sub- or grandchild scale.");

            $abilities = $progress->get_abilities();
            $this->assertArrayHasKey($rootid, $abilities, "Answer $answered: the global scale has an estimate.");
            $theta = (float) $abilities[$rootid];
            $this->assertTrue(is_finite($theta), "Answer $answered: a finite estimate of the global scale.");
            $n = $progress->get_num_answered_productive_questions($rootid);
            $this->assertSame($answered, $n, "Answer $answered: the answer counts for the global scale.");
            $this->assertSame($progress->get_num_answered_productive_questions(), $n);
            if ($previous !== null) {
                $this->assertNotEquals($previous, $theta, "Answer $answered: the estimate of the global scale moved.");
            }
            $snapshot = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['id' => $catattemptid]), true);
            $se = $snapshot['se'][$rootid] ?? null;
            $this->assertIsNumeric($se, "Answer $answered: the standard error of the global scale.");
            $this->assertTrue(is_finite((float) $se) && (float) $se > 0.0, "Answer $answered: a finite positive SE.");
            $states[$answered] = ['theta' => $theta, 'n' => $n, 'se' => (float) $se];
            $previous = $theta;
        }
        $this->assertTrue($shown['stopped'], 'The attempt ends.');
        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $shown['attemptid']], '*', MUST_EXIST);
        $this->assertSame($length, (int) $attempt->questionsattempted, "The attempt ends after $length questions.");
        $this->assertCount($length - 1, $states, 'The running state after every answer but the last.');

        // The end: the result row of the global scale is the last state of the engine, valid.
        $catattemptid = (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $attempt->id]);
        $progress = progress::load_for_reading($catattemptid);
        $row = $DB->get_record('local_catquiz_personparams', [
            'userid' => $scenario->userid, 'catscaleid' => $rootid, 'attemptid' => $catattemptid,
        ], '*', MUST_EXIST);
        $this->assertNotNull($row->ability, 'A number, not the null fallback of the validator.');
        $this->assertEqualsWithDelta($progress->get_abilities()[$rootid], (float) $row->ability, 0.005 + 1e-9);
        $this->assertSame($length, (int) $row->n, 'Every productive answer of the attempt counted once.');
        $snapshot = json_decode((string) $DB->get_field('local_catquiz_attempts', 'json', ['id' => $catattemptid]), true);
        $this->assertNotNull($row->standarderror);
        $this->assertEqualsWithDelta((float) $snapshot['se'][$rootid], (float) $row->standarderror, 0.005 + 1e-9);
        $this->assertStringNotContainsString(
            \local_catquiz\local\result\scale_result::REASON_GLOBAL_NOT_ESTIMATED,
            (string) $row->validationstatus,
            'No diagnosis of a missing estimate.'
        );
        // The SE of the global scale shrinks over the attempt: more answers, more information.
        $this->assertLessThan(reset($states)['se'], (float) $row->standarderror);
        // The colour bar of the comparison is left out for the scale limits of the fixture (as in the replays).
        $this->resetDebugging();
    }
}
