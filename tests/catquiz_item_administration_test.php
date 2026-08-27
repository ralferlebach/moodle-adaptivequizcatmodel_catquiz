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

use advanced_testcase;
use adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration;
use context_course;
use context_module;
use local_catquiz\local\question\question_answer_evaluation;
use mod_adaptivequiz\local\attempt;
use question_bank;
use question_engine;

/**
 * Tests the CAT model item administration adapter (Issue #6).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration
 */
final class catquiz_item_administration_test extends advanced_testcase {
    /**
     * When the previous item is still unanswered (active slot), the adapter
     * reuses that QUBA slot instead of selecting a new question, so no duplicate
     * slot is created on reload.
     */
    public function test_active_previous_slot_is_reused(): void {
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $questionsgenerator = $datagenerator->get_plugin_generator('core_question');
        $modgenerator = $datagenerator->get_plugin_generator('mod_adaptivequiz');

        $course = $datagenerator->create_course();
        $user = $datagenerator->create_user();

        $qcategory = $questionsgenerator->create_question_category([
            'contextid' => context_course::instance($course->id)->id,
        ]);
        $question = $questionsgenerator->create_question('truefalse', null, [
            'category' => $qcategory->id,
        ]);
        $questionsgenerator->create_question_tag([
            'questionid' => $question->id,
            'tag' => 'adpq_3',
        ]);

        $adaptivequiz = $modgenerator->create_instance([
            'course' => $course->id,
            'questionpool' => [$qcategory->id],
            'lowestlevel' => 3,
            'highestlevel' => 6,
            'startinglevel' => 3,
            'minimumquestions' => 2,
            'maximumquestions' => 3,
            'standarderror' => 5,
        ]);

        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id, $course->id, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);

        $this->setUser($user);

        $adaptiveattempt = new attempt($adaptivequiz, $user->id);
        $adaptiveattempt->get_attempt();
        $adaptiveattempt->initialize_quba($modcontext);
        $quba = $adaptiveattempt->get_quba();

        // Administer one question and leave it unanswered: its slot stays active.
        $loaded = question_bank::load_question($question->id);
        $slot = $quba->add_question($loaded);
        $quba->start_question($slot);
        question_engine::save_questions_usage_by_activity($quba);
        $this->assertTrue($quba->get_question_state($slot)->is_active());

        $adapter = new catquiz_item_administration(
            $quba,
            new question_answer_evaluation($quba),
            $adaptivequiz,
            $adaptiveattempt
        );

        $evaluation = $adapter->evaluate_ability_to_administer_next_item($slot);

        $this->assertFalse($evaluation->item_administration_is_to_stop());
        $this->assertSame(
            $slot,
            $evaluation->next_item()->quba_slot(),
            'The existing active slot must be reused.'
        );
        $this->assertNull(
            $evaluation->next_item()->question_id(),
            'No new question must be selected while the previous item is unanswered.'
        );
    }
}
