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
 * Restore of what CATquiz keeps for an activity: a test settings record of its own, pointing to the restored
 * activity, and the files of its feedback texts (adaptivequizcatmodel_catquiz #1).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_adaptivequizcatmodel_catquiz_subplugin extends restore_subplugin {
    /** @var string[] Keys of the stored settings that name the activity or its course module. */
    private const INSTANCE_KEYS = ['coursemodule', 'cmidnumber', 'instance', 'section', 'update', 'add', 'return', 'sr'];

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
     * A settings record of its own for the restored activity.
     *
     * @param array $data The data from the backup file.
     */
    public function process_catquiz_test($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $activityid = (int) $this->task->get_activityid();
        $courseid = (int) $this->task->get_courseid();

        $data->componentid = $activityid;
        $data->component = 'mod_adaptivequiz';
        $data->courseid = $courseid;
        $data->parentid = 0;
        // Scale and CAT context are site wide: kept when they exist here, else the settings have to be chosen again.
        if (!empty($data->catscaleid) && !$DB->record_exists('local_catquiz_catscales', ['id' => $data->catscaleid])) {
            $data->catscaleid = null;
        }
        if (!empty($data->contextid) && !$DB->record_exists('local_catquiz_catcontext', ['id' => $data->contextid])) {
            $data->contextid = null;
        }
        $json = json_decode((string) $data->json);
        if (is_object($json)) {
            foreach (self::INSTANCE_KEYS as $key) {
                unset($json->$key);
            }
            $json->componentid = $activityid;
            $json->courseid = $courseid;
            $json->course = $courseid;
            $data->json = json_encode($json);
        }
        $data->status = $data->status ?? 1;
        $data->timecreated = $data->timecreated ?? time();
        $data->timemodified = time();

        $newitemid = $DB->insert_record('local_catquiz_tests', $data);
        $this->set_mapping('catquiz_test', $oldid, $newitemid);
    }

    /**
     * The files of the feedback texts, once the activity and its context exist.
     */
    public function after_execute_adaptivequiz() {
        global $DB;
        $areas = $DB->get_fieldset_select(
            'backup_files_temp',
            'DISTINCT filearea',
            "backupid = :backupid AND component = 'local_catquiz' AND " . $DB->sql_like('filearea', ':area'),
            ['backupid' => $this->get_restoreid(), 'area' => 'feedback\\_files\\_%']
        );
        foreach ($areas as $area) {
            // The item id is the id of the activity.
            $this->add_related_files('local_catquiz', $area, 'adaptivequiz');
        }
    }
}
