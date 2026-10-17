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

namespace adaptivequizcatmodel_catquiz\local;

/**
 * Removes what local_catquiz keeps for attempts of the activity: when an attempt or the whole activity is deleted.
 *
 * Two id spaces meet here (local_catquiz #95): the attempt of the activity (adaptivequiz_attempt.id, kept in
 * local_catquiz_attempts.attemptid and local_catquiz_trace.adaptiveattemptid) and the CAT attempt
 * (local_catquiz_attempts.id, referenced by local_catquiz_progress.attemptid and
 * local_catquiz_personparams.attemptid). Progress and abilities are found through the CAT attempt only - deleting
 * them by the activity's attempt id hit the progress of other people's CAT attempts with the same number and
 * left the own one behind.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_cleanup {
    /** @var string[] local_catquiz_attempts.component is written in both spellings. */
    public const COMPONENTS = ['mod_adaptivequiz', 'adaptivequiz'];

    /** @var int Ids per IN list. */
    private const CHUNK = 500;

    /**
     * The CAT attempts that belong to attempts of the activity.
     *
     * @param int[] $hostattemptids adaptivequiz_attempt.id
     * @return int[] local_catquiz_attempts.id
     */
    public static function cat_attempt_ids(array $hostattemptids): array {
        global $DB;
        $ids = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $hostattemptids))), self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'host');
            [$compsql, $compparams] = $DB->get_in_or_equal(self::COMPONENTS, SQL_PARAMS_NAMED, 'comp');
            $ids = array_merge($ids, $DB->get_fieldset_select(
                'local_catquiz_attempts',
                'id',
                "attemptid $insql AND component $compsql",
                $inparams + $compparams
            ));
        }
        return array_map('intval', $ids);
    }

    /**
     * Deletes the CAT attempts of attempts of the activity, with their progress, abilities and trace.
     *
     * @param int[] $hostattemptids adaptivequiz_attempt.id
     * @return void
     */
    public static function delete_for_host_attempts(array $hostattemptids): void {
        global $DB;
        if (!$hostattemptids) {
            return;
        }
        self::delete_cat_attempts(self::cat_attempt_ids($hostattemptids));
        if ($DB->get_manager()->table_exists('local_catquiz_trace')) {
            foreach (array_chunk(array_map('intval', $hostattemptids), self::CHUNK) as $chunk) {
                [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'host');
                $DB->delete_records_select('local_catquiz_trace', "adaptiveattemptid $insql", $inparams);
            }
        }
    }

    /**
     * Deletes everything local_catquiz keeps for an activity: its test settings and all its attempts' data.
     *
     * Called before the activity deletes its own attempts, so they still lead to the CAT attempts. CAT
     * attempts that name the activity but no longer have an attempt of the activity go as well.
     *
     * @param int $instanceid adaptivequiz.id
     * @return void
     */
    public static function delete_for_instance(int $instanceid): void {
        global $DB;
        $DB->delete_records('local_catquiz_tests', ['componentid' => $instanceid, 'component' => 'mod_adaptivequiz']);

        $hostattemptids = $DB->get_fieldset_select('adaptivequiz_attempt', 'id', 'instance = :instance', ['instance' => $instanceid]);
        self::delete_for_host_attempts($hostattemptids);

        [$compsql, $compparams] = $DB->get_in_or_equal(self::COMPONENTS, SQL_PARAMS_NAMED, 'comp');
        $orphans = $DB->get_fieldset_select(
            'local_catquiz_attempts',
            'id',
            "instanceid = :instanceid AND component $compsql",
            ['instanceid' => $instanceid] + $compparams
        );
        self::delete_cat_attempts(array_map('intval', $orphans));
    }

    /**
     * Deletes CAT attempts with their progress and abilities.
     *
     * @param int[] $catattemptids local_catquiz_attempts.id
     * @return void
     */
    private static function delete_cat_attempts(array $catattemptids): void {
        global $DB;
        foreach (array_chunk($catattemptids, self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cat');
            $DB->delete_records_select('local_catquiz_personparams', "attemptid $insql", $inparams);
            $DB->delete_records_select('local_catquiz_progress', "attemptid $insql", $inparams);
            $DB->delete_records_select('local_catquiz_attempts', "id $insql", $inparams);
        }
    }
}
