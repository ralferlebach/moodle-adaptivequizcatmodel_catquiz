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

/**
 * The install.xml installs without XMLDB debugging messages.
 *
 * A CHAR NOT NULL column with '' as default makes XMLDB print a debugging message on
 * install. moodle-plugin-ci treats any debugging message of "phpunit --install" as a
 * failure, so the whole CI of this plugin and of local_catquiz, which installs it, went
 * red over the recovery column rangesource (2026100903).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class install_xml_test extends advanced_testcase {
    /**
     * No CHAR NOT NULL column carries an empty default.
     *
     * @return void
     */
    public function test_no_char_not_null_with_empty_default(): void {
        $xml = simplexml_load_file(__DIR__ . '/../db/install.xml');
        $this->assertNotFalse($xml);

        $offending = [];
        foreach ($xml->xpath('//TABLE/FIELDS/FIELD') as $field) {
            $type = (string) $field['TYPE'];
            $notnull = (string) $field['NOTNULL'] === 'true';
            if ($type === 'char' && $notnull && isset($field['DEFAULT']) && (string) $field['DEFAULT'] === '') {
                $table = (string) $field->xpath('../..')[0]['NAME'];
                $offending[] = $table . '.' . (string) $field['NAME'];
            }
        }
        $this->assertSame([], $offending, 'CHAR NOT NULL with DEFAULT "" triggers an XMLDB debugging message on install.');
    }
}
