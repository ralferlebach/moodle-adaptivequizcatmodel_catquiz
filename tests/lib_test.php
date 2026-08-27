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
 * Unit tests for the sub-plugin callbacks picked up by mod_adaptivequiz.
 *
 * These callbacks are plain functions discovered by name, so a rename or a
 * signature change fails silently: mod_adaptivequiz simply stops calling them and
 * the feature quietly disappears. These tests pin the contract.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::adaptivequizcatmodel_catquiz_attempts_report_url
 */

namespace adaptivequizcatmodel_catquiz;

use advanced_testcase;
use mod_adaptivequiz\output\attempts_number;
use moodle_url;
use stdClass;

/**
 * Tests the callbacks exposed in lib.php.
 *
 * @package    adaptivequizcatmodel_catquiz
 */
final class lib_test extends advanced_testcase {
    /**
     * The attempts report callback is discoverable under the expected name.
     *
     * mod_adaptivequiz looks the function up via get_plugin_list_with_function(),
     * so the exact name is part of the contract.
     *
     * @return void
     */
    public function test_attempts_report_url_callback_is_discoverable(): void {
        $this->resetAfterTest(true);

        $functions = get_plugin_list_with_function('adaptivequizcatmodel', 'attempts_report_url');

        $this->assertArrayHasKey(
            'adaptivequizcatmodel_catquiz',
            $functions,
            'mod_adaptivequiz discovers this callback by name; without it the attempts '
                . 'number on the activity page stays plain text and teachers cannot reach '
                . 'the attempts report at all.'
        );
        $this->assertSame(
            'adaptivequizcatmodel_catquiz_attempts_report_url',
            $functions['adaptivequizcatmodel_catquiz']
        );
    }

    /**
     * The callback points at the CAT model's own attempts report.
     *
     * @return void
     */
    public function test_attempts_report_url_points_at_the_catquiz_report(): void {
        $this->resetAfterTest(true);

        $adaptivequiz = new stdClass();
        $adaptivequiz->id = 42;
        $adaptivequiz->catmodel = 'catquiz';

        $cm = new stdClass();
        $cm->id = 99;
        $cm->course = 7;

        $url = adaptivequizcatmodel_catquiz_attempts_report_url($adaptivequiz, $cm);

        $this->assertInstanceOf(moodle_url::class, $url);
        $this->assertStringContainsString('/local/catquiz/feedback.php', $url->out(false));
        $this->assertSame('7', (string) $url->param('courseid'));
        $this->assertSame('42', (string) $url->param('instanceid'));
    }

    /**
     * End to end: mod_adaptivequiz turns the attempts number into a link only
     * because this callback provides a URL.
     *
     * @return void
     */
    public function test_activity_page_gets_a_report_link_for_this_catmodel(): void {
        $this->resetAfterTest(true);

        $cm = new stdClass();
        $cm->id = 99;
        $cm->course = 7;

        $withcatmodel = new stdClass();
        $withcatmodel->id = 42;
        $withcatmodel->catmodel = 'catquiz';

        $number = attempts_number::when_custom_catmodel_in_use($withcatmodel, $cm);
        $this->assertNotNull(
            $number->reporturl,
            'Without a report URL the activity page shows the attempts number as plain text.'
        );

        // Without a custom CAT model the built-in reporting applies instead.
        $withoutcatmodel = new stdClass();
        $withoutcatmodel->id = 42;
        $withoutcatmodel->catmodel = '';

        $this->assertNull(attempts_number::when_custom_catmodel_in_use($withoutcatmodel, $cm)->reporturl);
    }
}
