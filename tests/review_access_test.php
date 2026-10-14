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
use context_module;
use mod_adaptivequiz\local\question_file_access;

/**
 * Who may load the question files of a finished CATquiz attempt (mod_adaptivequiz issue #18).
 *
 * The activity asks the CAT model whether the owner may review the questions; CATquiz answers with
 * the test setting to show the questions. Teachers review through their report capability, other
 * participants never - also when the activity is hidden.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequizcatmodel_catquiz_completed_attempt_review_allowed')]
final class review_access_test extends advanced_testcase {
    /**
     * A CATquiz activity with a finished attempt of a participant.
     *
     * @param bool $showquestions The test setting to show the questions.
     * @param string $state The attempt state.
     * @return array [adaptivequiz, attempt, context, owner, course]
     */
    private function setup_attempt(bool $showquestions, string $state = 'complete'): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $adaptivequiz->id]);
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]);
        $DB->insert_record('local_catquiz_tests', (object) [
            'name' => 'Test', 'component' => 'mod_adaptivequiz', 'componentid' => $adaptivequiz->id,
            'json' => json_encode(['catquiz_showquestion' => $showquestions ? 1 : 0]),
            'status' => 0, 'parentid' => 0, 'courseid' => $course->id, 'usermodified' => 2,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $attempt = (object) [
            'instance' => $adaptivequiz->id, 'userid' => $owner->id, 'uniqueid' => 0, 'attemptstate' => $state,
            'attemptstopcriteria' => '', 'questionsattempted' => 2, 'difficultysum' => 0, 'standarderror' => 0,
            'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ];
        $attempt->id = $DB->insert_record('adaptivequiz_attempt', $attempt);
        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id);
        return [$adaptivequiz, $attempt, context_module::instance($cm->id), $owner, $course];
    }

    /**
     * The owner reviews when the test shows the questions, also with the activity hidden.
     */
    public function test_owner_follows_the_test_setting(): void {
        $this->resetAfterTest();

        [$adaptivequiz, $attempt, $context, $owner] = $this->setup_attempt(true);
        set_coursemodule_visible($context->instanceid, 0);
        $this->assertTrue(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $owner->id));

        [$adaptivequiz, $attempt, $context, $owner] = $this->setup_attempt(false);
        $this->assertFalse(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $owner->id));
    }

    /**
     * Teachers review through their capability; other participants never.
     */
    public function test_teacher_yes_other_participant_no(): void {
        $this->resetAfterTest();

        [$adaptivequiz, $attempt, $context, , $course] = $this->setup_attempt(true);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertTrue(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $teacher->id));
        $this->assertFalse(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $other->id));
    }

    /**
     * A running attempt is never "reviewed": its files follow the rules of the running test.
     */
    public function test_running_attempt_is_not_a_review(): void {
        $this->resetAfterTest();

        [$adaptivequiz, $attempt, $context, $owner] = $this->setup_attempt(true, 'inprogress');
        $this->assertFalse(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $owner->id));
    }

    /**
     * A finished attempt of a hidden activity: the owner gets through the course login and the check.
     */
    public function test_require_access_for_hidden_activity(): void {
        $this->resetAfterTest();

        [, $attempt, $context, $owner] = $this->setup_attempt(true);
        set_coursemodule_visible($context->instanceid, 0);
        \course_modinfo::clear_instance_cache();
        $this->setUser($owner);

        question_file_access::require_access($attempt);
        $this->addToAssertionCount(1);
    }
}
