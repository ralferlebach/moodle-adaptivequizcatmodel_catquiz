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
 * Contains function with the definition of upgrade steps for the plugin.
 *
 * @package   adaptivequizcatmodel_catquiz
 * @copyright  2025 Jacob Viertel <jacob.viertel@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines upgrade steps for the plugin.
 *
 * @param mixed $oldversion
 */
function xmldb_adaptivequizcatmodel_catquiz_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025090500) {
        // Transfer attempt parameters to a dedicated table.
        $table = new xmldb_table('adaptivequiz_cat_params');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('attempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('difficultysum', XMLDB_TYPE_NUMBER, '10, 7', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('standarderror', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('measure', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0.0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('attempt', XMLDB_KEY_FOREIGN, ['attempt'], 'adaptivequiz_attempt', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025090500, 'adaptivequizcatmodel', 'catquiz');
    }

    return true;
}
