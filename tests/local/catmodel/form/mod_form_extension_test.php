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

namespace adaptivequizcatmodel_catquiz\local\catmodel\form;

use advanced_testcase;

/**
 * The validation of the activity form by this sub-plugin.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_form_extension::class)]
final class mod_form_extension_test extends advanced_testcase {
    /**
     * With another CAT model - or none - this sub-plugin has no errors to report; the result was an undefined
     * variable and a type error.
     */
    public function test_another_catmodel_has_no_errors(): void {
        $extension = new mod_form_extension();

        $this->assertSame([], $extension->validation_callback(['catmodel' => 'testcatmodel'], []));
        $this->assertSame([], $extension->validation_callback(['catmodel' => ''], []));
        $this->assertSame([], $extension->validation_callback([], []));
    }

    /**
     * With CATquiz the validation of CATquiz answers.
     */
    public function test_catquiz_validates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $errors = (new mod_form_extension())->validation_callback(['catmodel' => 'catquiz'], []);

        $this->assertIsArray($errors);
    }
}
