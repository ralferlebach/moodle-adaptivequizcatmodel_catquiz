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

namespace adaptivequizcatmodel_catquiz\backup;

use advanced_testcase;
use context_module;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Duplicating a CATquiz activity copies its test settings and feedback files into records of its own
 * (adaptivequizcatmodel_catquiz #1).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_adaptivequizcatmodel_catquiz_subplugin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_adaptivequizcatmodel_catquiz_subplugin::class)]
final class duplicate_test extends advanced_testcase {
    /** @var stdClass */
    private stdClass $course;

    /** @var stdClass The original activity. */
    private stdClass $adaptivequiz;

    /**
     * A CATquiz activity with settings and an image in a feedback text.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $this->course->id, 'catmodel' => 'catquiz',
        ]);
        $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $this->adaptivequiz->id]);
        $json = (object) [
            'componentid' => $this->adaptivequiz->id, 'courseid' => $this->course->id, 'coursemodule' => $this->adaptivequiz->cmid,
            'catquiz_selectteststrategy' => 4, 'maxquestionsgroup' => (object) ['catquiz_maxquestions' => 7],
            'feedbackeditor_scaleid_5_1' => '<p><img src="@@PLUGINFILE@@/figure.png"></p>',
        ];
        // Creating the activity may already have stored settings; there is one record per activity.
        $DB->delete_records('local_catquiz_tests', ['component' => 'mod_adaptivequiz', 'componentid' => $this->adaptivequiz->id]);
        $DB->insert_record('local_catquiz_tests', (object) [
            'name' => 'Settings', 'component' => 'mod_adaptivequiz', 'componentid' => $this->adaptivequiz->id,
            'courseid' => $this->course->id, 'json' => json_encode($json), 'status' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => context_module::instance($this->adaptivequiz->cmid)->id, 'component' => 'local_catquiz',
            'filearea' => 'feedback_files_5_1', 'itemid' => $this->adaptivequiz->id, 'filepath' => '/',
            'filename' => 'figure.png',
        ], 'image');
    }

    /**
     * Duplicates a course module with the API the Moodle version offers (issue #9).
     *
     * duplicate_module() is deprecated since Moodle 5.2 (MDL-86858); its replacement is
     * core_courseformat\local\cmactions::duplicate(). The class exists in 5.1 already, without that method - so
     * the check is on the method, not on the class or the version, as in mod_adaptivequiz.
     *
     * @param \cm_info $cm
     * @return \cm_info The copy.
     */
    private function duplicate(\cm_info $cm): \cm_info {
        if (method_exists(\core_courseformat\local\cmactions::class, 'duplicate')) {
            return (new \core_courseformat\local\cmactions($this->course))->duplicate($cm->id);
        }
        return duplicate_module($this->course, $cm);
    }

    /**
     * Deletes a course module with the API the Moodle version offers (issue #9).
     *
     * course_delete_module() is deprecated since Moodle 5.2 (MDL-86856); its replacement is
     * core_courseformat\local\cmactions::delete(), checked by its method as duplicate().
     *
     * @param int $cmid
     * @return void
     */
    private function delete(int $cmid): void {
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            (new \core_courseformat\local\cmactions($this->course))->delete($cmid);
            return;
        }
        course_delete_module($cmid);
    }

    /**
     * The copy has settings of its own with the same values, pointing to itself, and its own image.
     */
    public function test_duplicate_has_its_own_settings_and_files(): void {
        global $DB;
        $newcm = $this->duplicate(get_fast_modinfo($this->course)->get_cm($this->adaptivequiz->cmid));

        $original = $DB->get_record(
            'local_catquiz_tests',
            ['component' => 'mod_adaptivequiz', 'componentid' => $this->adaptivequiz->id]
        );
        $copy = $DB->get_record(
            'local_catquiz_tests',
            ['component' => 'mod_adaptivequiz', 'componentid' => $newcm->instance],
            '*',
            MUST_EXIST
        );
        $this->assertNotEquals($original->id, $copy->id);
        $json = json_decode($copy->json);
        $this->assertEquals($newcm->instance, $json->componentid);
        $this->assertEquals(4, $json->catquiz_selectteststrategy);
        $this->assertEquals(7, $json->maxquestionsgroup->catquiz_maxquestions);
        $this->assertObjectNotHasProperty('coursemodule', $json);
        $this->assertSame('catquiz', $DB->get_field('adaptivequiz', 'catmodel', ['id' => $newcm->instance]));

        $file = get_file_storage()->get_file(
            context_module::instance($newcm->id)->id,
            'local_catquiz',
            'feedback_files_5_1',
            $newcm->instance,
            '/',
            'figure.png'
        );
        $this->assertNotFalse($file, 'The image of the feedback came along.');
        $this->assertSame('image', $file->get_content());

        // The two are independent: changing the copy leaves the original, deleting the original leaves the copy.
        $DB->set_field('local_catquiz_tests', 'json', '{}', ['id' => $copy->id]);
        $this->assertSame($original->json, $DB->get_field('local_catquiz_tests', 'json', ['id' => $original->id]));
        $this->delete((int) $this->adaptivequiz->cmid);
        $this->assertFalse($DB->record_exists('local_catquiz_tests', ['id' => $original->id]));
        $this->assertTrue($DB->record_exists('local_catquiz_tests', ['id' => $copy->id]));
    }
}
