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

namespace adaptivequizcatmodel_catquiz\local;

use advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');

/**
 * Deleting an activity, an attempt or the attempts of a course removes the CATquiz data of exactly those attempts.
 *
 * The CAT attempt of activity B is given the number of activity A's attempt on purpose: deleting progress by
 * the activity's attempt id - as the activity deletion did - removes B's progress and keeps A's.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\attempt_cleanup
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\instance\instance_actions_handler
 * @covers ::adaptivequizcatmodel_catquiz_post_delete_attempt_callback
 */
final class attempt_cleanup_test extends advanced_testcase {
    /** @var stdClass */
    private stdClass $course;

    /** @var array Per activity: adaptivequiz record, host attempt, CAT attempt id. */
    private array $setup = [];

    /**
     * Two activities with an attempt each; B's CAT attempt has the id of A's activity attempt.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz');
        foreach (['A', 'B'] as $name) {
            $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            $instance = $generator->create_instance([
                'course' => $this->course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
                'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
            ]);
            $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $instance->id]);
            $hostattempt = (object) [
                'instance' => $instance->id, 'userid' => $user->id, 'uniqueid' => 0, 'attemptstate' => 'complete',
                'attemptstopcriteria' => '', 'questionsattempted' => 2, 'difficultysum' => 0, 'standarderror' => 0,
                'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ];
            $hostattempt->id = $DB->insert_record('adaptivequiz_attempt', $hostattempt);
            $DB->insert_record('local_catquiz_tests', (object) [
                'name' => 'Test ' . $name, 'component' => 'mod_adaptivequiz', 'componentid' => $instance->id,
                'json' => '{}', 'status' => 1, 'courseid' => $this->course->id, 'timecreated' => time(), 'timemodified' => time(),
            ]);
            $this->setup[$name] = [
                'adaptivequiz' => $DB->get_record('adaptivequiz', ['id' => $instance->id]),
                'attempt' => $hostattempt,
                'user' => $user,
            ];
        }
        // The CAT attempt of B carries the number of A's attempt; A's is far away from both.
        $this->setup['A']['catid'] = $this->cat_attempt('A', 900001 + (int) $this->setup['B']['attempt']->id);
        $this->setup['B']['catid'] = $this->cat_attempt('B', (int) $this->setup['A']['attempt']->id);
        $this->assertNotSame($this->setup['A']['catid'], $this->setup['B']['catid']);
    }

    /**
     * A CAT attempt with progress, an ability and a trace row, under a given id.
     *
     * @param string $name Activity A or B.
     * @param int $catid The id of the CAT attempt.
     * @return int
     */
    private function cat_attempt(string $name, int $catid): int {
        global $DB;
        $s = $this->setup[$name];
        $DB->import_record('local_catquiz_attempts', (object) [
            'id' => $catid, 'userid' => $s['user']->id, 'attemptid' => $s['attempt']->id, 'component' => 'mod_adaptivequiz',
            'instanceid' => $s['adaptivequiz']->id, 'courseid' => $this->course->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_catquiz_progress', (object) [
            'attemptid' => $catid, 'userid' => $s['user']->id, 'component' => 'mod_adaptivequiz',
            'json' => '{}', 'timemodified' => time(),
        ]);
        $DB->insert_record('local_catquiz_personparams', (object) [
            'attemptid' => $catid, 'userid' => $s['user']->id, 'catscaleid' => 1, 'contextid' => 1, 'ability' => 0.5,
            'isprimary' => 1, 'isvalid' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_catquiz_trace', (object) [
            'adaptiveattemptid' => $s['attempt']->id, 'userid' => $s['user']->id, 'kind' => 'first_item',
            'timecreated' => time(),
        ]);
        return $catid;
    }

    /**
     * Asserts that the CATquiz data of an activity's attempt is there or gone.
     *
     * @param string $name Activity A or B.
     * @param bool $exists
     */
    private function assert_cat_data(string $name, bool $exists): void {
        global $DB;
        $s = $this->setup[$name];
        $what = $exists ? 'kept' : 'deleted';
        $this->assertSame($exists, $DB->record_exists('local_catquiz_attempts', ['id' => $s['catid']]), "$name attempt $what");
        $this->assertSame($exists, $DB->record_exists('local_catquiz_progress', ['attemptid' => $s['catid']]), "$name progress $what");
        $this->assertSame(
            $exists,
            $DB->record_exists('local_catquiz_personparams', ['attemptid' => $s['catid']]),
            "$name abilities $what"
        );
        $this->assertSame(
            $exists,
            $DB->record_exists('local_catquiz_trace', ['adaptiveattemptid' => $s['attempt']->id]),
            "$name trace $what"
        );
    }

    /**
     * Deleting activity A removes A's settings and attempt data - and leaves B's, despite the shared number.
     */
    public function test_deleting_an_activity(): void {
        global $DB;
        adaptivequiz_delete_instance((int) $this->setup['A']['adaptivequiz']->id);

        $this->assert_cat_data('A', false);
        $this->assert_cat_data('B', true);
        $this->assertFalse($DB->record_exists('local_catquiz_tests', ['componentid' => $this->setup['A']['adaptivequiz']->id]));
        $this->assertTrue($DB->record_exists('local_catquiz_tests', ['componentid' => $this->setup['B']['adaptivequiz']->id]));
    }

    /**
     * The older spelling of the component and a CAT attempt without an attempt of the activity go as well.
     */
    public function test_deleting_an_activity_finds_both_spellings_and_orphans(): void {
        global $DB;
        $a = $this->setup['A'];
        $DB->set_field('local_catquiz_attempts', 'component', 'adaptivequiz', ['id' => $a['catid']]);
        $orphan = $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $a['user']->id, 'attemptid' => null, 'component' => 'mod_adaptivequiz',
            'instanceid' => $a['adaptivequiz']->id, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        adaptivequiz_delete_instance((int) $a['adaptivequiz']->id);

        $this->assert_cat_data('A', false);
        $this->assertFalse($DB->record_exists('local_catquiz_attempts', ['id' => $orphan]));
        $this->assert_cat_data('B', true);
    }

    /**
     * Deleting one attempt removes its CATquiz data only.
     */
    public function test_deleting_an_attempt(): void {
        global $DB;
        $a = $this->setup['A'];
        $DB->delete_records('adaptivequiz_attempt', ['id' => $a['attempt']->id]);
        adaptivequizcatmodel_catquiz_post_delete_attempt_callback($a['adaptivequiz'], $a['attempt']);

        $this->assert_cat_data('A', false);
        $this->assert_cat_data('B', true);
    }

    /**
     * Resetting the course's attempts removes the CATquiz data of every attempt of its activities.
     */
    public function test_course_reset(): void {
        adaptivequiz_reset_userdata((object) ['courseid' => $this->course->id, 'reset_adaptivequiz_all' => 1]);

        $this->assert_cat_data('A', false);
        $this->assert_cat_data('B', false);
    }
}
