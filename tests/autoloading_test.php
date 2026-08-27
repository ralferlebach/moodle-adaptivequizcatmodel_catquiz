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
 * Guards that every shipped class can actually be autoloaded.
 *
 * Moodle derives the file path from the namespace, so a namespace that does not
 * match the directory makes the class silently unloadable: nothing complains at
 * install time, and the failure only surfaces when some code path finally tries
 * to instantiate it. This plugin used to ship five such classes - they went
 * unnoticed for a long time because they were dead copies of classes that live in
 * local_catquiz and mod_adaptivequiz.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration
 */

namespace adaptivequizcatmodel_catquiz;

use advanced_testcase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tests that the namespace of every class matches its location.
 *
 * @package    adaptivequizcatmodel_catquiz
 */
final class autoloading_test extends advanced_testcase {
    /**
     * Every class under classes/ must be autoloadable.
     *
     * @return void
     */
    public function test_every_shipped_class_is_autoloadable(): void {
        global $CFG;

        $base = $CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/classes';
        $this->assertDirectoryExists($base);

        $checked = 0;
        $unloadable = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (!preg_match('/^namespace\s+([^;]+);/m', $source, $matches)) {
                continue;
            }

            // Trim also removes a trailing CR from files with Windows line endings.
            $classname = trim($matches[1]) . '\\' . $file->getBasename('.php');
            $checked++;

            if (!class_exists($classname) && !interface_exists($classname) && !trait_exists($classname)) {
                $unloadable[] = sprintf(
                    '%s declares %s',
                    str_replace($base . '/', '', $file->getPathname()),
                    $classname
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'No classes were inspected - the test would be vacuous.');
        $this->assertSame(
            [],
            $unloadable,
            "The namespace of these classes does not match their location, so Moodle cannot load them:\n"
                . implode("\n", $unloadable)
        );
    }
}
