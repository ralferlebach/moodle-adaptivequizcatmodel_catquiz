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
 * Restore subplugin class for catquiz.
 *
 * @package    catmodel_catquiz
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_adaptivequizcatmodel_catquiz_subplugin extends restore_subplugin {
    /**
     * Define the restore structure for the subplugin.
     *
     * @param string $connectionpoint The connection point the structure is attached to.
     * @return array Array of restore_path_elements.
     */
    public function define_subplugin_structure($connectionpoint) {
        $this->connectionpoint = $connectionpoint;
        $paths = [];

        $elepath = $this->get_pathfor('catquiz_test');
        $restorepath = new restore_path_element('catquiz_test', $elepath);
        $restorepath->set_processing_object($this);
        $paths[] = $restorepath;

        return $paths;
    }

    /**
     * Process the restoration of catquiz test data.
     *
     * @param array $data The data from the backup file.
     */
    public function process_catquiz_test($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        // Adjust the componentid to point to the newly restored activity.
        $data->componentid = $this->task->get_activityid();
        $data->component = 'mod_adaptivequiz';
        $data->timemodified = time();

        // Insert record with modified data.
        $newitemid = $DB->insert_record('local_catquiz_tests', $data);

        // Store mapping in case we need it later in the restore.
        $this->set_mapping('catquiz_test', $oldid, $newitemid);
    }
}
