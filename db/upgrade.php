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

    if ($oldversion < 2026100903) {
        // Local_catquiz #137: the protocol of cli/recover_legacy_results.php.
        $table = new xmldb_table('adaptivequiz_cat_recovery');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('catattemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('starttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cutoff', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('oldreason', XMLDB_TYPE_CHAR, '255');
        $table->add_field('steps', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('globalscaleid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('rangelower', XMLDB_TYPE_NUMBER, '10, 5');
        $table->add_field('rangeupper', XMLDB_TYPE_NUMBER, '10, 5');
        $table->add_field('rangesource', XMLDB_TYPE_CHAR, '20');
        $table->add_field('valid', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('newreason', XMLDB_TYPE_CHAR, '255');
        $table->add_field('resultpercent', XMLDB_TYPE_NUMBER, '10, 5');
        $table->add_field('codeversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('attemptid', XMLDB_INDEX_UNIQUE, ['attemptid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100903, 'adaptivequizcatmodel', 'catquiz');
    }

    if ($oldversion < 2026100905) {
        // A CHAR NOT NULL column with '' as default makes XMLDB print a debugging message
        // on install, which moodle-plugin-ci treats as a failure. The source is empty for
        // rows that never got a range, so the column may simply be NULL.
        $table = new xmldb_table('adaptivequiz_cat_recovery');
        $field = new xmldb_field('rangesource', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'rangeupper');
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->change_field_notnull($table, $field);
            $dbman->change_field_default($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026100905, 'adaptivequizcatmodel', 'catquiz');
    }

    if ($oldversion < 2026100906) {
        // Issue #8: older CATquiz attempts whose snapshot holds no valid result keep it - the regular
        // regrade does not take snapshots again. The recovery of cli/recover_legacy_results.php takes
        // them on as an ad-hoc task: the upgrade itself only queues it.
        \adaptivequizcatmodel_catquiz\task\recover_legacy_results::queue_migration();

        upgrade_plugin_savepoint(true, 2026100906, 'adaptivequizcatmodel', 'catquiz');
    }

    if ($oldversion < 2026101703) {
        // Issue #8, audit of 2026-10-18: the migration of 2026100906 left attempts started before 2026-07-14
        // as they were - a cutoff the recovery policy does not have. Queued again, now without one; attempts
        // recovered then are protocolled and skipped.
        \adaptivequizcatmodel_catquiz\task\recover_legacy_results::queue_migration();

        upgrade_plugin_savepoint(true, 2026101703, 'adaptivequizcatmodel', 'catquiz');
    }

    return true;
}
