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
use local_catquiz\local\result\personparam_repository;
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
 * @coversNothing
 */
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
     * @dataProvider replays
     */
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

        // Engine #164: the intermediate estimates of the running attempt are marked provisional and bounded.
        $this->assertTrue(
            defined(personparam_repository::class . '::PROVISIONAL'),
            'local_catquiz without the result contract of #164 - push local_catquiz first (doc/ergebnisvertrag-164.md).'
        );
        $selected = count($scales);
        $maxrows = 0;
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
            if (!$shown['stopped']) {
                $this->assert_running_estimates($scenario, $selected);
                $maxrows = max($maxrows, $DB->count_records('local_catquiz_personparams', ['userid' => $scenario->userid]));
            }
        }
        $this->assertTrue($shown['stopped'], 'The attempt ends.');
        // Growth: while the attempt ran, at most one row per selected scale - not one per answer.
        $this->assertGreaterThan(0, $maxrows, 'The running attempt has its estimates.');
        $this->assertLessThanOrEqual($selected, $maxrows, 'One intermediate row per scale, updated in place.');

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

        // Engine #164: after the finalisation no intermediate row is left; the results are final, with standard
        // error, and they are the reference values of the person (carry-over and peers).
        $rows = $DB->get_records('local_catquiz_personparams', ['userid' => $scenario->userid], 'id');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNotSame(personparam_repository::PROVISIONAL, (string) $row->validationstatus, 'No provisional row left.');
            $this->assertSame((int) $catattempt->id, (int) $row->attemptid, 'Every row belongs to the CAT attempt.');
            $this->assertSame('current', (string) $row->resultsource);
            $this->assertNotNull($row->standarderror, 'A final result has its standard error.');
            $this->assertTrue(personparam_repository::is_reference($row));
        }
        $this->assertCount(count(array_unique(array_map(fn($r) => (int) $r->catscaleid, $rows))), $rows, 'One row per scale.');

        // Engine #174: the global scale is part of the result - with its own estimate, every productive answer
        // counted once, and its standard error - although the strategy reports a subscale.
        $rootid = (int) $DB->get_field('local_catquiz_catscales', 'id', ['name' => 'Simulation']);
        $root = array_values(array_filter($rows, fn($r) => (int) $r->catscaleid === $rootid));
        $this->assertCount(1, $root, 'The global scale has its result row.');
        $progress = \local_catquiz\teststrategy\progress::load_for_reading((int) $catattempt->id);
        $this->assertNotNull($progress);
        $this->assertSame($progress->get_num_answered_productive_questions(), (int) $root[0]->n);
        $this->assertSame($progress->get_num_answered_productive_questions($rootid), (int) $root[0]->n);
        // Stored with the precision of the results (two decimals, as the feedback rounds them).
        $this->assertEqualsWithDelta($progress->get_abilities()[$rootid], (float) $root[0]->ability, 0.005 + 1e-9);
        $this->assertNotNull($root[0]->standarderror);

        // Reconciliation: finalising again changes nothing - no second result, grade or completion.
        $fingerprint = json_encode($rows, JSON_PRESERVE_ZERO_FRACTION);
        \local_catquiz\local\attempt\attempt_finalizer::finalize((int) $attempt->id, (int) $attempt->timefinished);
        $this->assertSame(
            $fingerprint,
            json_encode(
                $DB->get_records('local_catquiz_personparams', ['userid' => $scenario->userid], 'id'),
                JSON_PRESERVE_ZERO_FRACTION
            ),
            'A second finalisation leaves the results as they are.'
        );
        $this->assertCount(1, $DB->get_records('grade_grades', ['itemid' => $item->id, 'userid' => $scenario->userid]));
        $this->assertSame(1, $DB->count_records('logstore_standard_log', [
            'eventname' => '\mod_adaptivequiz\event\attempt_completed', 'objectid' => $attempt->id,
        ]), 'Still completed once.');

        $this->resetDebugging();
    }

    /**
     * Cases of a technical error (host #23, engine #172, adapter #12): before the minimum and after it.
     *
     * @return array
     */
    public static function technical_errors(): array {
        return [
            'technical error before the minimum' => ['10', false],
            'technical error after the minimum' => ['10011010101110', null],
        ];
    }

    /**
     * Host #23: a technical error ends the attempt as an error - never as a regular end - and the cause and the
     * validity are apart: the snapshot decides validity from the measurement, grade and completion follow the
     * snapshot, and the attempt is finalised and completed exactly once. The error is real: the item parameters of
     * the test disappear under the running attempt, and the engine fails with its general error.
     *
     * @dataProvider technical_errors
     * @param string $answers The answers before the error.
     * @param bool|null $valid The expected validity; null where the measurement decides.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('technical_errors')]
    public function test_a_technical_error_ends_the_attempt_once(string $answers, ?bool $valid): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $scenario = trace_scenario::create($this->getDataGenerator(), true, [
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_RELSUBS,
            'catquiz_minquestions' => 12,
            'catquiz_maxquestions' => 25,
            'catquiz_minquestionspersubscale' => 3,
            'catquiz_maxquestionspersubscale' => 5,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ], 25);
        $this->select_scales($scenario, ['Simulation', 'SimC', 'SimC01', 'SimC02', 'SimC03', 'SimC04', 'SimC05']);
        $DB->set_field('adaptivequiz', 'minimumquestions', 0, ['id' => $scenario->adaptivequiz->id]);
        $course = $this->enable_completion($scenario);
        $this->setUser($scenario->userid);
        $shown = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        for ($i = 0; $i < strlen($answers) - 1; $i++) {
            $shown = request_simulator::request(
                $scenario->adaptivequiz,
                $scenario->context,
                $scenario->userid,
                $answers[$i] === '1'
            );
            $this->assertFalse($shown['stopped'], 'Still running before the error.');
        }

        // The error: the parameters of the items disappear; the next request fails in the engine.
        $DB->delete_records('local_catquiz_itemparams');
        \cache_helper::purge_all();
        $shown = request_simulator::request(
            $scenario->adaptivequiz,
            $scenario->context,
            $scenario->userid,
            $answers[strlen($answers) - 1] === '1'
        );
        $this->assertTrue($shown['stopped'], 'The error ends the attempt.');
        $this->setAdminUser();

        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', MUST_EXIST);
        // The cause: an error, in the engine and in the host - not a regular end, not an empty pool.
        $this->assertSame(status::to_int(status::ERROR_GENERAL), (int) $catattempt->status);
        $this->assertSame('error', $attempt->stopcode);
        $this->assertSame(strlen($answers), (int) $attempt->questionsattempted);
        // Finalised once, completed once.
        $this->assertSame('complete', $attempt->attemptstate);
        $this->assertNotEmpty($catattempt->endtime);
        $this->assertSame(1, $DB->count_records('logstore_standard_log', [
            'eventname' => '\mod_adaptivequiz\event\attempt_completed', 'objectid' => $attempt->id,
        ]));
        // The validity: from the measurement, apart from the cause.
        $result = \local_catquiz\local\result\attempt_result_validator::validate((int) $attempt->id);
        $this->assertSame($result->is_valid() ? 1 : 0, (int) $attempt->resultvalid, 'The snapshot is the measurement.');
        if ($valid !== null) {
            $this->assertSame($valid, (bool) $attempt->resultvalid, (string) $attempt->resultreason);
        }
        $rootid = (int) $DB->get_field('local_catquiz_catscales', 'id', ['name' => 'Simulation']);
        $this->assertTrue($DB->record_exists(
            'local_catquiz_personparams',
            ['attemptid' => $catattempt->id, 'catscaleid' => $rootid]
        ), 'Engine #174: the global scale has its row.');

        // Grade and completion follow the snapshot - no grade and no completion for an invalid result, exactly one
        // grade and the completion for a valid one.
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'adaptivequiz',
            'iteminstance' => $scenario->adaptivequiz->id, 'courseid' => $scenario->adaptivequiz->course]);
        $grades = $DB->get_records_select(
            'grade_grades',
            'itemid = ? AND userid = ? AND finalgrade IS NOT NULL',
            [$item->id, $scenario->userid]
        );
        $this->assertCount((int) $attempt->resultvalid, $grades, 'A grade exactly for a valid result.');
        $cm = get_fast_modinfo($course)->get_cm($scenario->adaptivequiz->cm->id);
        $data = (new completion_info($course))->get_data($cm, false, $scenario->userid);
        $this->assertSame(
            (int) $attempt->resultvalid === 1 ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE,
            (int) $data->completionstate
        );
        // Finalising again changes nothing.
        $this->assertFalse(\local_catquiz\local\attempt\attempt_finalizer::finalize(
            (int) $attempt->id,
            (int) $attempt->timefinished
        ));
        $this->resetDebugging();
    }

    /**
     * Engine #164, end by the time limit after two answers: the task closes the attempt, the finaliser runs, no
     * intermediate row is left, the result is invalid (too few items) and gives neither a start value nor a peer.
     */
    public function test_an_attempt_closed_by_the_time_limit_keeps_no_intermediate_row(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->preventResetByRollback();
        $scales = ['Simulation', 'SimB', 'SimB01', 'SimB02', 'SimB03', 'SimB04'];
        $scenario = trace_scenario::create($this->getDataGenerator(), true, [
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
            'catquiz_minquestions' => 12,
            'catquiz_maxquestions' => 25,
            'catquiz_minquestionspersubscale' => 3,
            'catquiz_maxquestionspersubscale' => 5,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ], 25);
        $this->select_scales($scenario, $scales);
        $DB->set_field('adaptivequiz', 'minimumquestions', 0, ['id' => $scenario->adaptivequiz->id]);
        $this->setUser($scenario->userid);
        request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, true);
        request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, false);
        $this->assert_running_estimates($scenario, count($scales));
        $this->setAdminUser();

        // Two days old: beyond the default limit of a day.
        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $DB->set_field('adaptivequiz_attempt', 'timecreated', time() - 2 * DAYSECS, ['id' => $attempt->id]);
        set_config('maximum_attempt_duration_hours', 24, 'local_catquiz');
        ob_start();
        (new \local_catquiz\task\cancel_expired_attempts())->execute();
        ob_end_clean();

        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame('complete', $attempt->attemptstate);
        $this->assertNotEmpty($catattempt->endtime, 'The finaliser ran.');
        $rows = $DB->get_records('local_catquiz_personparams', ['userid' => $scenario->userid], 'id');
        foreach ($rows as $row) {
            $this->assertNotSame(personparam_repository::PROVISIONAL, (string) $row->validationstatus, 'No provisional row left.');
            $this->assertNotSame('prior', (string) $row->resultsource, 'No start value of the default is carried.');
            $this->assertNotNull($row->standarderror, 'A final result has its standard error.');
        }
        $this->assertLessThanOrEqual(count($scales), count($rows));
        $root = (int) $DB->get_field('local_catquiz_catscales', 'id', ['name' => 'Simulation']);
        $contextid = (int) $catattempt->contextid;
        $this->assertNull(
            personparam_repository::get_latest_valid($scenario->userid, $contextid, $root),
            'Two answers are no admissible value: no start value of the next attempt.'
        );
        [$sql, $params] = \local_catquiz\local\statistics\peer_population::sql($contextid, $root);
        $this->assertFalse(
            $DB->record_exists_sql("SELECT 1 FROM ($sql) x WHERE x.userid = :who", $params + ['who' => $scenario->userid]),
            'And no peer.'
        );
        $this->resetDebugging();
    }

    /**
     * The estimates of a running attempt (engine #164): provisional, of its own CAT attempt, at most one per scale,
     * none of them a reference value.
     *
     * @param trace_scenario $scenario
     * @param int $selected The number of selected scales.
     */
    private function assert_running_estimates(trace_scenario $scenario, int $selected): void {
        global $DB;
        $host = (int) $DB->get_field('adaptivequiz_attempt', 'MAX(id)', ['instance' => $scenario->adaptivequiz->id]);
        $catid = (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $host]);
        $rows = $DB->get_records('local_catquiz_personparams', ['userid' => $scenario->userid]);
        $this->assertLessThanOrEqual($selected, count($rows));
        foreach ($rows as $row) {
            $this->assertSame(personparam_repository::PROVISIONAL, (string) $row->validationstatus, 'Marked provisional.');
            $this->assertSame($catid, (int) $row->attemptid, 'Of the running CAT attempt.');
            $this->assertSame(0, (int) $row->isvalid);
            $this->assertFalse(personparam_repository::is_reference($row), 'Not a reference value.');
        }
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
