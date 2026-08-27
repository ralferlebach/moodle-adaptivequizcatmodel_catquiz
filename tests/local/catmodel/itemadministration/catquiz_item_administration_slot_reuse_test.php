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

/**
 * Guards that an unanswered item is re-served in its existing slot.
 *
 * Issue #6. When the previous item is still unanswered - a reload or a resume
 * before the answer was submitted - the item administration must return that
 * item's existing QUBA slot. Asking the CAT engine for a new question instead
 * makes mod_adaptivequiz add a SECOND slot for the same item, so the question
 * usage and the CAT progress diverge and the attempt runs past its configured
 * length.
 *
 * The check has to happen BEFORE the CAT selection: this plugin shipped a version
 * that called catquiz_handler::fetch_question_id() first and only afterwards
 * looked at the previous slot, which defeated the purpose.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration
 */

namespace adaptivequizcatmodel_catquiz\local\catmodel\itemadministration;

use advanced_testcase;
use context_system;
use local_catquiz\local\question\question_answer_evaluation;
use mod_adaptivequiz\local\attempt;
use question_bank;
use question_engine;
use stdClass;

/**
 * Tests the slot reuse of the item administration.
 *
 * @package    adaptivequizcatmodel_catquiz
 */
final class catquiz_item_administration_slot_reuse_test extends advanced_testcase {
    /**
     * Builds a usage holding one started, unanswered question.
     *
     * @return array The usage and the slot the question sits in.
     */
    private function usage_with_pending_question(): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, ['category' => $category->id]);

        $quba = question_engine::make_questions_usage_by_activity(
            'mod_adaptivequiz',
            context_system::instance()
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question(question_bank::load_question($question->id));
        $quba->start_all_questions();

        return [$quba, $slot];
    }

    /**
     * An unanswered previous item is re-served in its own slot.
     *
     * The CAT engine is deliberately NOT configured here: if the implementation
     * asked it for a new question, the call would fail loudly instead of silently
     * producing a second slot. That is exactly the regression being guarded.
     *
     * @return void
     */
    public function test_pending_previous_item_is_reused_without_asking_the_cat_engine(): void {
        $this->resetAfterTest(true);

        [$quba, $slot] = $this->usage_with_pending_question();
        $this->assertTrue(
            $quba->get_question_state($slot)->is_active(),
            'Precondition: the question must still be unanswered.'
        );

        $adaptivequiz = new stdClass();
        $adaptivequiz->id = -1;

        $administration = new catquiz_item_administration(
            $quba,
            new question_answer_evaluation($quba),
            $adaptivequiz,
            $this->createMock(attempt::class)
        );

        $evaluation = $administration->evaluate_ability_to_administer_next_item($slot);

        $this->assertFalse(
            $evaluation->item_administration_is_to_stop(),
            'Re-serving a pending item must not end the attempt.'
        );
        $this->assertSame(
            $slot,
            $evaluation->next_item()->quba_slot(),
            'The pending item must be re-served in its existing slot, not as a new question.'
        );
    }

    /**
     * The reuse must not swallow an answered item.
     *
     * Once the previous item has been graded, the CAT engine has to be asked for
     * the next question - otherwise the same item would be served forever.
     *
     * @return void
     */
    public function test_answered_previous_item_is_not_reused(): void {
        $this->resetAfterTest(true);

        [$quba, $slot] = $this->usage_with_pending_question();
        $quba->process_action($slot, ['answer' => 1]);
        $quba->finish_all_questions();

        $this->assertFalse(
            $quba->get_question_state($slot)->is_active(),
            'Precondition: the question must be graded.'
        );

        $adaptivequiz = new stdClass();
        $adaptivequiz->id = -1;

        $administration = new catquiz_item_administration(
            $quba,
            new question_answer_evaluation($quba),
            $adaptivequiz,
            $this->createMock(attempt::class)
        );

        // With a graded previous item the implementation must consult the CAT
        // engine. There is no test environment for instance -1, so that attempt
        // fails - which is precisely the evidence that the engine WAS consulted.
        $this->expectException(\Throwable::class);
        $administration->evaluate_ability_to_administer_next_item($slot);
    }
}
