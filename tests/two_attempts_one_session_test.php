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
use context_module;
use question_engine;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * Two attempts of one person in one session (local_catquiz #146 DoD3): attempt A runs, attempt B is started in
 * another CAT test on the same pool, then A is resumed - through host, adapter, engine and question usage, with the
 * session caches of one session.
 *
 * Starting B must not purge A's pool, progress or feedback data: A goes on with its own questions - none twice, none
 * lost -, its progress holds every answer once and lists the questions of its usage in their order, and its feedback
 * data counts what A played. B is checked the same way.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class two_attempts_one_session_test extends advanced_testcase {
    /**
     * A second CAT test in the course of the scenario, on the same scale and pool.
     *
     * @param trace_scenario $scenario
     * @return array [activity record with context and cm, context]
     */
    private function second_activity(trace_scenario $scenario): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $instance = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $scenario->adaptivequiz->course, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'minimumquestions' => 1, 'maximumquestions' => 50,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $root = $DB->get_record('local_catquiz_catscales', ['parentid' => 0], '*', MUST_EXIST);
        $generator->get_plugin_generator('local_catquiz')->create_catquiz_testsettings([
            'courseid' => $scenario->adaptivequiz->course,
            'adaptivecatquizid' => $instance->id,
            'catscalesid' => $root->id,
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
            'catmodel' => 'catquiz',
            'catquiz_maxquestions' => 10,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
            'numberoffeedbackoptions' => 2,
        ]);
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id, $adaptivequiz->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $adaptivequiz->context = $context;
        $adaptivequiz->cm = $cm;
        return [$adaptivequiz, $context];
    }

    /**
     * Answers in one attempt, request by request.
     *
     * @param stdClass $adaptivequiz
     * @param context_module $context
     * @param int $userid
     * @param array $answers
     * @param bool $first Whether the first request starts the attempt (no answer to send).
     */
    private static function play(stdClass $adaptivequiz, context_module $context, int $userid, array $answers, bool $first): void {
        if ($first) {
            request_simulator::request($adaptivequiz, $context, $userid, null);
        }
        foreach ($answers as $right) {
            $shown = request_simulator::request($adaptivequiz, $context, $userid, $right);
            if ($shown['stopped']) {
                throw new \coding_exception('The attempt ended early.');
            }
        }
    }

    /**
     * The state of an attempt: questions of its usage, of its progress, its answers, its feedback data.
     *
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @return array
     */
    private function state(stdClass $adaptivequiz, int $userid): array {
        global $DB;
        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $adaptivequiz->id, 'userid' => $userid], '*', MUST_EXIST);
        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', MUST_EXIST);
        $quba = question_engine::load_questions_usage_by_activity((int) $attempt->uniqueid);
        $usage = array_map(fn($slot) => (int) $quba->get_question($slot)->id, $quba->get_slots());
        $json = (string) $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $catattempt->id]);
        // Local_catquiz #177 stores played questions and responses as events: read as stored, in either format.
        $progress = method_exists(\local_catquiz\teststrategy\progress::class, 'stored_structure')
            ? json_decode(json_encode(\local_catquiz\teststrategy\progress::stored_structure((int) $catattempt->id, $json)), true)
            : json_decode($json, true);
        $played = array_map('intval', array_keys((array) $progress['playedquestions']));
        $answered = array_map('intval', array_keys((array) $progress['responses']));
        return ['attempt' => $attempt, 'cat' => $catattempt, 'usage' => $usage, 'played' => $played, 'answered' => $answered];
    }

    /**
     * Checks one attempt after n answers.
     *
     * @param array $state
     * @param int $answers
     * @param string $label
     */
    private function assert_consistent(array $state, int $answers, string $label): void {
        $this->assertSame('inprogress', $state['attempt']->attemptstate, $label);
        $this->assertCount($answers + 1, $state['usage'], "$label: one slot per answer and the question shown.");
        $this->assertSame(array_values(array_unique($state['usage'])), $state['usage'], "$label: no question twice.");
        $this->assertSame($state['usage'], $state['played'], "$label: the progress lists the usage's questions in order.");
        $this->assertCount($answers, $state['answered'], "$label: every answer once in the progress - none lost.");
        $this->assertSame(array_slice($state['usage'], 0, $answers), $state['answered'], "$label: the answered ones.");
        $this->assertSame($answers, (int) $state['attempt']->questionsattempted, $label);
    }

    /**
     * A runs, B starts, A is resumed.
     */
    public function test_starting_b_leaves_a_running_attempt_as_it_is(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = trace_scenario::create($this->getDataGenerator(), false, [
            'catquiz_maxquestions' => 10,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
        ], 50);
        [$b, $bcontext] = $this->second_activity($scenario);
        $userid = $scenario->userid;
        $this->setUser($userid);

        self::play($scenario->adaptivequiz, $scenario->context, $userid, [true, false, true], true);
        $before = $this->state($scenario->adaptivequiz, $userid);
        $this->assert_consistent($before, 3, 'A before B');

        // B starts in the same session and is answered twice.
        self::play($b, $bcontext, $userid, [false, true], true);
        $this->assert_consistent($this->state($b, $userid), 2, 'B');

        // A as it was left: nothing of it purged or changed by B.
        $this->assertEquals($before, $this->state($scenario->adaptivequiz, $userid), 'Starting B changed nothing of A.');

        // A is resumed: the question it showed is answered, the next ones follow, none of them twice.
        self::play($scenario->adaptivequiz, $scenario->context, $userid, [true, true, false], false);
        $after = $this->state($scenario->adaptivequiz, $userid);
        $this->assert_consistent($after, 6, 'A resumed');
        $this->assertSame($before['usage'], array_slice($after['usage'], 0, 4), 'A goes on from where it was.');

        // B goes on as well, still consistent.
        self::play($b, $bcontext, $userid, [true], false);
        $this->assert_consistent($this->state($b, $userid), 3, 'B resumed');
        // Two attempts, two CAT attempts, each with its own progress.
        $this->assertSame(2, $DB->count_records('local_catquiz_attempts', ['userid' => $userid]));
        $this->assertSame(2, $DB->count_records('local_catquiz_progress', ['userid' => $userid]));
        // The colour bar of a scale without feedback ranges is left out with a developer notice - not the subject.
        $this->resetDebugging();
    }
}
