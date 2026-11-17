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
use completion_info;
use local_catquiz\local\status;
use question_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Replays of the two attempts of local_catquiz #172, through host, adapter, engine and question usage.
 *
 * In production (local_catquiz 2026100922) a RELSUBS attempt ended after 17 and a LOWESTSUB attempt after 16 of at
 * most 25 questions with "technical error" (host stopcode error, CAT status -1): every selected scale had been
 * dropped by its own rule, the active scale set was empty, and the selection reported that as a failure. The
 * answers of those attempts are not public; these replays rebuild their shape - same strategy, same limits (12 to
 * 25 questions, 3 to 5 per scale, no host minimum), same length, an empty active set after the minimum - on the
 * simulation pool, with fixed answer strings. Nothing is mocked: the requests go through the host's attempt
 * handling as attempt.php does it (request_simulator), the adapter, the engine and the question usage.
 *
 * Each replay checks the whole chain at its end (local_catquiz #172 AK1 to AK6, adapter #12, mod_adaptivequiz #23):
 * the stop reason of engine and host, the number of questions, the question usage (no question twice, none after
 * the end), one completion, the CAT result, the result snapshot, the gradebook and the completion state.
 *
 * Red before the fix: with the fallback of 2026100922 (end_without_question returning ERROR_GENERAL) both replays
 * end with the host code "error"; with the state of 2026111600 (ERROR_NO_REMAINING_QUESTIONS) with
 * "noeligiblequestions" - see the counter-checks in the delivery report of 17.11.2026.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class scale_set_complete_replay_test extends advanced_testcase {
    /**
     * The two attempts of #172.
     *
     * The answers are given as a string, one character per question (1 right, 0 wrong); the scale set is what the
     * test settings select (root, one domain and its leaf scales).
     *
     * @return array
     */
    public static function replays(): array {
        return [
            'RELSUBS, ends after 17' => [
                LOCAL_CATQUIZ_STRATEGY_RELSUBS,
                ['Simulation', 'SimC', 'SimC01', 'SimC02', 'SimC03', 'SimC04', 'SimC05'],
                '1001101010111111011100111110100110111111',
                17,
            ],
            'LOWESTSUB, ends after 16' => [
                LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
                ['Simulation', 'SimB', 'SimB01', 'SimB02', 'SimB03', 'SimB04'],
                '1101101101101101101101101101101101101101',
                16,
            ],
        ];
    }

    /**
     * Replays one attempt and checks the chain at its end.
     *
     * @param int $strategy
     * @param array $scales Names of the scales the test selects.
     * @param string $answers
     * @param int $length The number of questions the attempt ends after.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('replays')]
    public function test_replay(int $strategy, array $scales, string $answers, int $length): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // The completion event is counted in the standard log, which writes outside the test transaction only.
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $scenario = trace_scenario::create($this->getDataGenerator(), true, [
            'cateststrategyid' => $strategy,
            'catquiz_minquestions' => 12,
            'catquiz_maxquestions' => 25,
            'catquiz_minquestionspersubscale' => 3,
            'catquiz_maxquestionspersubscale' => 5,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ], 25);
        $this->select_scales($scenario, $scales);
        // As in production: no minimum of the host's own.
        $DB->set_field('adaptivequiz', 'minimumquestions', 0, ['id' => $scenario->adaptivequiz->id]);
        $scenario->adaptivequiz->minimumquestions = 0;
        $course = $this->enable_completion($scenario);
        $this->setUser($scenario->userid);

        $shown = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        $answered = 0;
        while (!$shown['stopped'] && $answered < strlen($answers)) {
            $answered++;
            $shown = request_simulator::request(
                $scenario->adaptivequiz,
                $scenario->context,
                $scenario->userid,
                $answers[$answered - 1] === '1'
            );
        }
        $this->assertTrue($shown['stopped'], 'The attempt ends.');

        // Engine and host: a regular end of its own, not an error and not an empty pool.
        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame('scalescomplete', $attempt->stopcode);
        $this->assertSame(status::to_int(status::STOP_SCALES_COMPLETE), (int) $catattempt->status);
        $this->assertSame((int) $catattempt->id, (int) $DB->get_field(
            'local_catquiz_progress',
            'attemptid',
            ['attemptid' => $catattempt->id, 'userid' => $scenario->userid]
        ), 'The progress belongs to the CAT attempt.');
        $saved = json_decode((string) $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $catattempt->id]), true);
        $this->assertSame([], array_values((array) $saved['activescales']), 'No scale is left active.');
        $this->assertNotEmpty($saved['droppedscales']);

        // Length: the replayed one, within minimum and maximum.
        $this->assertSame($length, (int) $attempt->questionsattempted);
        $this->assertGreaterThanOrEqual(12, $length);
        $this->assertLessThan(25, $length);

        // Question usage: one slot per answer, no question twice, none added after the end.
        $quba = question_engine::load_questions_usage_by_activity((int) $attempt->uniqueid);
        $questionids = array_map(fn($slot) => (int) $quba->get_question($slot)->id, $quba->get_slots());
        $this->assertCount($length, $questionids, 'One slot per answer, none after the end.');
        $this->assertSame(array_values(array_unique($questionids)), $questionids, 'No question twice.');
        foreach ($quba->get_slots() as $slot) {
            $this->assertTrue($quba->get_question_state($slot)->is_finished(), "Slot $slot is answered.");
        }

        // Completed once: one attempt, one completion event, the CAT attempt closed.
        $this->assertSame('complete', $attempt->attemptstate);
        $this->assertSame(1, $DB->count_records('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id]));
        $this->assertSame(1, $DB->count_records('logstore_standard_log', [
            'eventname' => '\mod_adaptivequiz\event\attempt_completed', 'objectid' => $attempt->id,
        ]), 'The attempt is completed once.');
        $this->assertNotEmpty($attempt->timefinished);
        $this->assertNotEmpty($catattempt->endtime);

        // The result: valid, from the engine's final values, with the root scale measured.
        $this->assertSame(1, (int) $attempt->resultvalid, 'Valid result: ' . $attempt->resultreason);
        $this->assertNotNull($catattempt->personability_after_attempt);
        // The result provider reports the score to one decimal.
        $this->assertEqualsWithDelta(
            (float) $catattempt->personability_after_attempt,
            (float) $attempt->resultscore,
            0.05 + 1e-9,
            'The snapshot holds the final ability of the CAT attempt.'
        );

        // Gradebook: one grade, the snapshot's percentage.
        $item = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $scenario->adaptivequiz->id,
            'courseid' => $scenario->adaptivequiz->course,
        ]);
        $this->assertNotFalse($item);
        $grades = $DB->get_records('grade_grades', ['itemid' => $item->id, 'userid' => $scenario->userid]);
        $this->assertCount(1, $grades);
        $grade = reset($grades);
        $this->assertNotNull($grade->finalgrade);
        $this->assertEqualsWithDelta(
            (float) $attempt->resultpercent * (float) $item->grademax / 100,
            (float) $grade->finalgrade,
            1e-4
        );

        // Completion: the rule "valid result" is met.
        $cm = get_fast_modinfo($course)->get_cm($scenario->adaptivequiz->cm->id);
        $data = (new completion_info($course))->get_data($cm, false, $scenario->userid);
        $this->assertSame(COMPLETION_COMPLETE, (int) $data->completionstate);

        $this->resetDebugging();
    }

    /**
     * Restricts the test to the named scales, as the subscale checkboxes of the test settings do.
     *
     * @param trace_scenario $scenario
     * @param array $names
     */
    private function select_scales(trace_scenario $scenario, array $names): void {
        global $DB;
        $test = $DB->get_record('local_catquiz_tests', [
            'componentid' => $scenario->adaptivequiz->id, 'component' => 'mod_adaptivequiz',
        ], '*', MUST_EXIST);
        $json = json_decode($test->json);
        foreach ($DB->get_records('local_catquiz_catscales', null, 'id', 'id, name') as $scale) {
            $json->{'catquiz_subscalecheckbox_' . $scale->id} = in_array($scale->name, $names, true);
        }
        $DB->set_field('local_catquiz_tests', 'json', json_encode($json), ['id' => $test->id]);
        \cache_helper::purge_all();
    }

    /**
     * Completion on the course, the rule "valid result" on the activity.
     *
     * @param trace_scenario $scenario
     * @return \stdClass The course.
     */
    private function enable_completion(trace_scenario $scenario): \stdClass {
        global $CFG, $DB;
        $CFG->enablecompletion = 1;
        $courseid = (int) $scenario->adaptivequiz->course;
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $courseid]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $scenario->adaptivequiz->cm->id]);
        $DB->set_field('adaptivequiz', 'completionvalidresult', 1, ['id' => $scenario->adaptivequiz->id]);
        $scenario->adaptivequiz->completionvalidresult = 1;
        rebuild_course_cache($courseid, true);
        return get_course($courseid);
    }
}
