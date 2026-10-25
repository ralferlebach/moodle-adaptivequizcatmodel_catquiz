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

use adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration;
use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use advanced_testcase;
use local_catquiz\local\attempt\administration_history;
use mod_adaptivequiz\cat_session;
use mod_adaptivequiz\local\attempt;
use question_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * A question is administered once per attempt, whatever the person does (local_catquiz #126).
 *
 * Host, sub-plugin and CATquiz together, request by request as attempt.php makes them: pilot items among the
 * first questions (the early return of the pilot selector skipped the registration of the item), a question
 * in two scales, reloads of the open question, a stale second tab posting an answer that is processed already,
 * and resuming after leaving the attempt. Every question of the usage appears once, the attempt counts each
 * answer once, nothing is marked as technical duplicate, and the attempt is completed and graded once with a
 * valid result.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration
 * @covers \local_catquiz\teststrategy\strategy
 */
final class duplicate_question_reproducer_test extends advanced_testcase {
    /** @var int Items administered at most. */
    private const ITEMS = 12;

    /** @var array The form of each slot as it was rendered: what a tab left open posts later. */
    private array $forms = [];

    /**
     * The usage of the running attempt.
     *
     * @param trace_scenario $scenario
     * @return int
     */
    private function usage(trace_scenario $scenario): int {
        global $DB;
        return (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', [
            'instance' => $scenario->adaptivequiz->id, 'userid' => $scenario->userid, 'attemptstate' => 'inprogress',
        ]);
    }

    /**
     * Keeps the form of the question shown, as the page rendered it.
     *
     * @param trace_scenario $scenario
     * @param array $shown As request_simulator::request().
     */
    private function render(trace_scenario $scenario, array $shown): void {
        if (!$shown['stopped'] && !isset($this->forms[$shown['slots']])) {
            $quba = question_engine::load_questions_usage_by_activity($this->usage($scenario));
            $this->forms[$shown['slots']] = $quba->prepare_simulated_post_data([$shown['slots'] => ['answer' => 0]]);
        }
    }

    /**
     * Posts the form of an earlier slot - a second tab, whose answer was processed already - and shows what the
     * attempt is at.
     *
     * @param trace_scenario $scenario
     * @param int $slot
     * @return array As request_simulator::request().
     */
    private function repost(trace_scenario $scenario, int $slot): array {
        $uniqueid = $this->usage($scenario);
        $postdata = $this->forms[$slot];
        try {
            cat_session::process_administered_item_result(
                $uniqueid,
                $scenario->adaptivequiz,
                new attempt($scenario->adaptivequiz, $scenario->userid),
                function (\question_usage_by_activity $quba) use ($postdata): void {
                    $quba->process_all_actions(time(), $postdata);
                    $quba->finish_all_questions(time());
                },
                $scenario->context
            );
        } catch (\question_out_of_sequence_exception $e) {
            // The host before audit 18.10.: attempt.php would show an error page - the attempt is unchanged.
            unset($e);
        }
        return request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
    }

    /**
     * Pilots, a question in two scales, reloads, a stale tab and a resume: no question twice, nothing counted twice.
     */
    public function test_no_question_is_administered_twice(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // The pilot selector of the testing class: a fixed pattern of pilot and calibrated items.
        putenv('USE_TESTING_CLASS_FOR=1');
        try {
            $this->run_scenario();
        } finally {
            putenv('USE_TESTING_CLASS_FOR');
        }
    }

    /**
     * The scenario of test_no_question_is_administered_twice().
     */
    private function run_scenario(): void {
        global $DB;
        $scenario = trace_scenario::create($this->getDataGenerator(), false, [
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
            // Pilot answers count towards the maximum of the usage, not towards the productive minimum.
            'catquiz_minquestions' => 4,
            'catquiz_maxquestions' => self::ITEMS,
            'catquiz_minquestionspersubscale' => 0,
            'catquiz_maxquestionspersubscale' => 0,
            'catquiz_standarderror_min' => 0.0,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
            'catquiz_pilotratio' => 50,
        ], self::ITEMS + 5);

        // Pilot items: questions without parameters.
        $items = array_values($DB->get_records('local_catquiz_items', ['componentname' => 'question'], 'id'));
        $this->assertGreaterThan(self::ITEMS + 6, count($items), 'The pool is large enough.');
        foreach (array_slice($items, 0, 6) as $item) {
            $DB->delete_records('local_catquiz_itemparams', ['itemid' => $item->id]);
            $DB->set_field('local_catquiz_items', 'activeparamid', null, ['id' => $item->id]);
        }
        // A calibrated question in a second scale: an item of its own with the same parameter.
        $twice = $items[8];
        $other = $DB->get_field_select(
            'local_catquiz_catscales',
            'MIN(id)',
            'id <> :id AND parentid <> 0',
            ['id' => $twice->catscaleid]
        );
        $param = $DB->get_record('local_catquiz_itemparams', ['itemid' => $twice->id], '*', IGNORE_MULTIPLE);
        $this->assertNotFalse($param, 'The question in two scales is calibrated.');
        $second = clone($twice);
        unset($second->id);
        $second->catscaleid = (int) $other;
        $second->activeparamid = null;
        $second->id = $DB->insert_record('local_catquiz_items', $second);
        $copy = clone($param);
        unset($copy->id);
        $copy->itemid = $second->id;
        $DB->set_field('local_catquiz_items', 'activeparamid', $DB->insert_record('local_catquiz_itemparams', $copy), [
            'id' => $second->id,
        ]);
        \cache_helper::purge_by_event('changesintestitems');
        $this->setUser($scenario->userid);

        $shown = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        $this->assertNotNull($shown['questionid'], json_encode($shown));
        $this->render($scenario, $shown);
        $requests = 1;
        while (!$shown['stopped'] && $requests < 5 * self::ITEMS) {
            $requests++;
            $step = $requests % 4;
            if ($step === 0) {
                // A reload - or coming back to the attempt: the open question again, no new slot.
                $again = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
                $this->assertSame($shown['questionid'], $again['questionid'], 'A reload shows the open question.');
                $this->assertSame($shown['slots'], $again['slots'], 'A reload adds no slot.');
                continue;
            }
            if ($step === 2 && $shown['slots'] > 1) {
                // The second tab still shows the question before: its answer was processed already.
                $stale = $this->repost($scenario, $shown['slots'] - 1);
                $this->assertSame($shown['questionid'], $stale['questionid'], 'A stale tab changes nothing.');
                $this->assertSame($shown['slots'], $stale['slots']);
                continue;
            }
            $shown = request_simulator::request(
                $scenario->adaptivequiz,
                $scenario->context,
                $scenario->userid,
                $requests % 3 !== 0
            );
            $this->render($scenario, $shown);
        }
        $this->assertTrue($shown['stopped'], 'The attempt ends.');

        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $this->assertSame(\mod_adaptivequiz\local\attempt\attempt_state::COMPLETED, $attempt->attemptstate);
        $quba = question_engine::load_questions_usage_by_activity((int) $attempt->uniqueid);
        $questionids = array_map(fn($slot) => (int) $quba->get_question($slot)->id, $quba->get_slots());
        $this->assertSame(
            array_values(array_unique($questionids)),
            $questionids,
            'Every question of the usage once: ' . json_encode($questionids)
        );
        $this->assertCount((int) $attempt->questionsattempted, $questionids, 'Each answer counted once.');
        $pilots = $DB->get_fieldset_select(
            'local_catquiz_items',
            'componentid',
            'activeparamid IS NULL AND componentname = :c',
            ['c' => 'question']
        );
        $this->assertNotEmpty(array_intersect($questionids, array_map('intval', $pilots)), 'Pilot items were administered.');
        $history = administration_history::for_attempt((int) $attempt->id, array_map('intval', $pilots));
        $this->assertSame(
            [],
            array_values(array_filter($history, fn($entry) => $entry->technicalduplicate)),
            'No technical duplicate.'
        );
        $this->assertSame(1, $DB->count_records('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id]));
        $item = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $scenario->adaptivequiz->id,
        ]);
        $this->assertNotFalse($item);
        $this->assertCount(1, $DB->get_records('grade_grades', ['itemid' => $item->id, 'userid' => $scenario->userid]));
        $this->assertSame(1, (int) $attempt->resultvalid, 'The result is valid: ' . $attempt->resultreason);
        // The generated test settings have no feedback ranges, so one feedback area reports that and is left
        // out; the feedback itself is built (the result above comes from it).
        $this->resetDebugging();
    }
}
