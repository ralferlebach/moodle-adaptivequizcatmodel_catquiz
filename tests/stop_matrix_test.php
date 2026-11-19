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
use adaptivequizcatmodel_catquiz\local\catmodel\stop_code;
use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use advanced_testcase;
use completion_info;
use local_catquiz\local\attempt\attempt_finalizer;
use local_catquiz\local\status;
use mod_adaptivequiz\local\attempt\stop_reason;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Stop contract across the strategies (adapter #12 AK8): RELSUBS, LOWESTSUB, FASTEST, ALLSUBS and CLASSIC, each with a
 * regular end, a test that cannot reach its minimum, and a technical error - real attempts through host, adapter and
 * engine.
 *
 * Checked for every one, whatever its result: the host's code is the translation of the engine's status (one place,
 * stop_code); a technical error is "error" in both and never a regular end; the validity of the snapshot is the
 * statistical validity of the global scale's final row (global_scale_validity - the grade's, not the reporting's),
 * independent of the cause; a grade and the completion exactly for a valid result; the attempt is completed once,
 * and finalising again changes nothing. A test whose minimum cannot be reached (more questions than the pool and the
 * per-subscale limits allow) is refused at the start with the reason, as attempt.php does it in both lines: no
 * question, no CAT attempt, the empty attempt left as it is, no grade, nothing completed. The end of a running attempt
 * before its minimum is the engine's (end_without_question_test: every scale done, or the pool empty, before the
 * minimum) and the mapping's (stop_code_test).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class stop_matrix_test extends advanced_testcase {
    /** @var array The engine's status of each end. */
    private const ENDS = [
        'regular' => [status::ERROR_REACHED_MAXIMUM_QUESTIONS, status::STOP_TARGET_PRECISION,
            status::ABORT_PERSONABILITY_NOT_CHANGED, status::STOP_SCALES_COMPLETE],
        'refused' => [],
        'error' => [status::ERROR_GENERAL],
    ];

    /**
     * Strategy x end.
     *
     * @return array
     */
    public static function matrix(): array {
        $strategies = [
            'RELSUBS' => LOCAL_CATQUIZ_STRATEGY_RELSUBS,
            'LOWESTSUB' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
            'FASTEST' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
            'ALLSUBS' => LOCAL_CATQUIZ_STRATEGY_ALLSUBS,
            'CLASSIC' => LOCAL_CATQUIZ_STRATEGY_CLASSIC,
        ];
        $cases = [];
        foreach ($strategies as $name => $strategy) {
            foreach (array_keys(self::ENDS) as $end) {
                $cases["$name, $end"] = [$strategy, $end];
            }
        }
        return $cases;
    }

    /**
     * One attempt of the matrix.
     *
     * @dataProvider matrix
     * @param int $strategy
     * @param string $end regular, refused or error.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function test_the_end_of_an_attempt(int $strategy, string $end): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        // Refused: a minimum of 200 questions on a pool of 28.
        $settings = [
            'cateststrategyid' => $strategy,
            'catquiz_minquestions' => $end === 'refused' ? 200 : 3,
            'catquiz_maxquestions' => $end === 'refused' ? 250 : 6,
            'catquiz_minquestionspersubscale' => 0,
            'catquiz_maxquestionspersubscale' => 0,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ];
        $scenario = trace_scenario::create($this->getDataGenerator(), false, $settings, 250);
        $DB->set_field('adaptivequiz', 'minimumquestions', 0, ['id' => $scenario->adaptivequiz->id]);
        $scenario->adaptivequiz->minimumquestions = 0;
        $CFG->enablecompletion = 1;
        $courseid = (int) $scenario->adaptivequiz->course;
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $courseid]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $scenario->adaptivequiz->cm->id]);
        $DB->set_field('adaptivequiz', 'completionvalidresult', 1, ['id' => $scenario->adaptivequiz->id]);
        $scenario->adaptivequiz->completionvalidresult = 1;
        rebuild_course_cache($courseid, true);
        $course = get_course($courseid);

        $this->setUser($scenario->userid);
        if ($end === 'refused') {
            $this->assert_refused($scenario, $course, $courseid);
            return;
        }
        $shown = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        $answered = 0;
        while (!$shown['stopped'] && $answered < 260) {
            if ($end === 'error' && $answered === 4) {
                // The error: the parameters of the items disappear; the next request fails in the engine.
                $DB->delete_records('local_catquiz_itemparams');
                \cache_helper::purge_all();
            }
            $shown = request_simulator::request(
                $scenario->adaptivequiz,
                $scenario->context,
                $scenario->userid,
                $answered % 3 !== 1
            );
            $answered++;
        }
        $this->assertTrue($shown['stopped'], 'The attempt ends.');
        $this->setAdminUser();

        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'adaptivequiz',
            'iteminstance' => $scenario->adaptivequiz->id, 'courseid' => $courseid]);
        $grades = fn() => $item ? $DB->count_records_select(
            'grade_grades',
            'itemid = ? AND userid = ? AND finalgrade IS NOT NULL',
            [$item->id, $scenario->userid]
        ) : 0;
        $completion = function () use ($course, $scenario): int {
            $cm = get_fast_modinfo($course)->get_cm($scenario->adaptivequiz->cm->id);
            return (int) (new completion_info($course))->get_data($cm, false, $scenario->userid)->completionstate;
        };
        $events = fn() => $DB->count_records('logstore_standard_log', [
            'eventname' => '\mod_adaptivequiz\event\attempt_completed', 'objectid' => $attempt->id,
        ]);
        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', MUST_EXIST);
        $mapping = (new \ReflectionClass(status::class))->getProperty('mapping');
        $mapping->setAccessible(true);
        $statusname = array_flip($mapping->getValue())[(int) $catattempt->status] ?? null;
        $label = "status $statusname, host {$attempt->stopcode}, " . $attempt->resultreason;

        // The end is the expected kind, and the host says what the engine says.
        $this->assertContains($statusname, self::ENDS[$end], $label);
        $this->assertSame(stop_code::from_status($statusname), $attempt->stopcode, $label);
        if ($end === 'error') {
            $this->assertSame(stop_reason::ERROR, $attempt->stopcode);
            $this->assertSame(stop_reason::NOTICE_ERROR, stop_reason::notice_kind($attempt->stopcode));
        } else {
            $this->assertNotSame(stop_reason::ERROR, $attempt->stopcode, $label);
        }

        // Completed once, finalised once.
        $this->assertSame('complete', $attempt->attemptstate);
        $this->assertNotEmpty($attempt->timefinished);
        $this->assertNotEmpty($catattempt->endtime);
        $this->assertSame(1, $events(), $label);

        // Validity: the statistical validity of the global scale's final row (global_scale_validity - the grade's,
        // not the reporting's), whatever the cause; grade and completion exactly for a valid result.
        $root = $DB->get_record('local_catquiz_personparams', [
            'attemptid' => $catattempt->id, 'catscaleid' => (int) $catattempt->scaleid,
        ]);
        $settings = catquiz_result_provider::quiz_settings($catattempt, $scenario->adaptivequiz);
        $valid = $root && catquiz_result_provider::rejection($root, $settings) === null;
        $this->assertSame($valid ? 1 : 0, (int) $attempt->resultvalid, $label);
        $this->assertNotNull($root, 'The global scale has its row: ' . $label);
        $this->assertSame((int) $attempt->resultvalid, $grades(), 'A grade exactly for a valid result: ' . $label);
        $this->assertSame(
            (int) $attempt->resultvalid === 1 ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE,
            $completion(),
            $label
        );

        // Finalising again changes nothing.
        $before = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]);
        $this->assertFalse(attempt_finalizer::finalize((int) $attempt->id, (int) $attempt->timefinished));
        $this->assertEquals($before, $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]));
        $this->resetDebugging();
    }

    /**
     * A test whose minimum cannot be reached: refused at the start with the reason; nothing measured, graded or completed.
     *
     * @param trace_scenario $scenario
     * @param \stdClass $course
     * @param int $courseid
     */
    private function assert_refused(trace_scenario $scenario, \stdClass $course, int $courseid): void {
        global $DB;
        try {
            request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
            $this->fail('The test starts although its minimum cannot be reached.');
        } catch (\mod_adaptivequiz\local\catmodel\catmodel_session_exception $e) {
            $this->assertStringContainsString('200', $e->getMessage() . ' ' . $e->a, 'The reason names the minimum.');
        }
        $this->setAdminUser();
        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $attempt->uniqueid, 'No question usage.');
        $this->assertSame(0, (int) $attempt->questionsattempted);
        $this->assertNotSame('complete', $attempt->attemptstate, 'The empty attempt is left as it is.');
        $this->assertEmpty($attempt->stopcode);
        $this->assertFalse($DB->record_exists('local_catquiz_attempts', ['attemptid' => $attempt->id]), 'No CAT attempt.');
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'adaptivequiz',
            'iteminstance' => $scenario->adaptivequiz->id, 'courseid' => $courseid]);
        $this->assertSame(0, $item ? $DB->count_records_select(
            'grade_grades',
            'itemid = ? AND userid = ? AND finalgrade IS NOT NULL',
            [$item->id, $scenario->userid]
        ) : 0, 'No grade.');
        $cm = get_fast_modinfo($course)->get_cm($scenario->adaptivequiz->cm->id);
        $state = (int) (new completion_info($course))->get_data($cm, false, $scenario->userid)->completionstate;
        $this->assertSame(COMPLETION_INCOMPLETE, $state);
        $this->resetDebugging();
    }
}
