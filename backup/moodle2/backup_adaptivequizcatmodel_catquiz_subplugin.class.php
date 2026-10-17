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
 * Backup of what CATquiz keeps for an activity: its test settings and the files of its feedback texts.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_adaptivequizcatmodel_catquiz_subplugin extends backup_subplugin {
    /**
     * Define the subplugin structure.
     *
     * @param string $connectionpoint
     * @return backup_subplugin_element
     */
    public function define_subplugin_structure($connectionpoint) {
        global $DB;

        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name() . $connectionpoint);
        $subpluginelement = new backup_nested_element('catquiz_test', ['id'], [
            'parentid',
            'componentid',
            'component',
            'catscaleid',
            'contextid',
            'courseid',
            'name',
            'description',
            'descriptionformat',
            'json',
            'status',
            'timecreated',
            'timemodified',
        ]);

        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($subpluginelement);

        $subpluginelement->set_source_sql(
            'SELECT * FROM {local_catquiz_tests} WHERE component = :component AND componentid = :componentid',
            ['component' => backup_helper::is_sqlparam('mod_adaptivequiz'), 'componentid' => backup::VAR_PARENTID]
        );

        // The images of the feedback texts: one file area per scale and range, item id = the activity.
        // The areas exist only as far as texts have files, so they are looked up (adaptivequizcatmodel_catquiz #1).
        $areas = $DB->get_fieldset_select(
            'files',
            'DISTINCT filearea',
            "contextid = :contextid AND component = 'local_catquiz' AND " . $DB->sql_like('filearea', ':area'),
            ['contextid' => $this->task->get_contextid(), 'area' => 'feedback\\_files\\_%']
        );
        foreach ($areas as $area) {
            $subpluginelement->annotate_files('local_catquiz', $area, 'componentid');
        }

        return $subplugin;
    }
}
