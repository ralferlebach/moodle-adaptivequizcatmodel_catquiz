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

namespace adaptivequizcatmodel_catquiz\privacy;

use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_adaptivequiz\privacy\adaptivequizcatmodel_provider;

/**
 * Reaches the CATquiz results of an activity from the activity's own privacy requests.
 *
 * local_catquiz has a privacy provider of its own, but it works at course level. Deleting a single
 * adaptive quiz never reaches it, and exporting one activity does not show what CATquiz computed
 * for it. This provider closes that gap: it answers for exactly the attempts of one activity.
 *
 * Rows are found through the attempt ids of the activity rather than through
 * local_catquiz_attempts.instanceid - the join over the host's own table says unambiguously which
 * attempts belong to this activity.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider, adaptivequizcatmodel_provider {
    /**
     * Declares that the data reached from here belongs to local_catquiz.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_plugintype_link('local', [], 'privacy:metadata:local_catquiz');

        return $collection;
    }

    /**
     * Returns the ids of the CATquiz attempts belonging to one activity, optionally one user.
     *
     * @param context_module $context The context of the activity.
     * @param int|null $userid Restrict to this user, null for everyone.
     * @param int[] $userids Restrict to these users, empty for no restriction.
     * @return array{0: int[], 1: int[]} CATquiz attempt ids and host attempt ids.
     */
    private static function attempt_ids(context_module $context, ?int $userid = null, array $userids = []): array {
        global $DB;

        $cm = get_coursemodule_from_id('adaptivequiz', $context->instanceid);
        if ($cm === false) {
            return [[], []];
        }

        $conditions = ['instance' => $cm->instance];
        $where = 'instance = :instance';

        if ($userid !== null) {
            $conditions['userid'] = $userid;
            $where .= ' AND userid = :userid';
        } else if (!empty($userids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $conditions += $inparams;
            $where .= " AND userid $insql";
        }

        $hostattemptids = $DB->get_fieldset_select('adaptivequiz_attempt', 'id', $where, $conditions);

        if (empty($hostattemptids)) {
            return [[], []];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($hostattemptids, SQL_PARAMS_NAMED);
        $catattemptids = $DB->get_fieldset_select(
            'local_catquiz_attempts',
            'id',
            "attemptid $insql AND component = :component",
            $inparams + ['component' => 'mod_adaptivequiz']
        );

        return [$catattemptids, $hostattemptids];
    }

    /**
     * Exports what CATquiz computed for the user in this activity.
     *
     * @param int $userid The user to export for.
     * @param context_module $context The context of the activity.
     * @param array $subcontext Where the activity has placed its own export.
     */
    public static function export_catmodel_user_data(int $userid, context_module $context, array $subcontext): void {
        global $DB;

        [$catattemptids] = self::attempt_ids($context, $userid);

        if (empty($catattemptids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($catattemptids, SQL_PARAMS_NAMED);
        $attempts = $DB->get_records_select('local_catquiz_attempts', "id $insql", $inparams);

        $exported = [];
        foreach ($attempts as $attempt) {
            $scales = $DB->get_records('local_catquiz_attemptscale', ['catattemptid' => $attempt->id]);

            $exported[] = (object) [
                'teststrategy' => $attempt->teststrategy,
                'status' => $attempt->status,
                'questionsused' => $attempt->number_of_testitems_used,
                'abilitybefore' => $attempt->personability_before_attempt,
                'abilityafter' => $attempt->personability_after_attempt,
                'scales' => array_values(array_map(fn($scale) => (object) [
                    'catscaleid' => $scale->catscaleid,
                    'ability' => $scale->score,
                    'standarderror' => $scale->standarderror,
                    'questions' => $scale->n,
                    'isprimary' => $scale->isprimary,
                    'isvalid' => $scale->isvalid,
                ], $scales)),
            ];
        }

        writer::with_context($context)->export_data(
            array_merge($subcontext, [get_string('pluginname', 'adaptivequizcatmodel_catquiz')]),
            (object) ['attempts' => $exported]
        );
    }

    /**
     * Deletes what CATquiz holds for everyone in this activity.
     *
     * @param context_module $context The context of the activity.
     */
    public static function delete_catmodel_data_for_all_users_in_context(context_module $context): void {
        self::delete(...self::attempt_ids($context));
    }

    /**
     * Deletes what CATquiz holds for one user in this activity.
     *
     * @param int $userid The user to delete for.
     * @param context_module $context The context of the activity.
     */
    public static function delete_catmodel_data_for_user(int $userid, context_module $context): void {
        self::delete(...self::attempt_ids($context, $userid));
    }

    /**
     * Deletes what CATquiz holds for the given users in this activity.
     *
     * @param approved_userlist $userlist The approved users, carrying the context.
     */
    public static function delete_catmodel_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        self::delete(...self::attempt_ids($context, null, $userlist->get_userids()));
    }

    /**
     * Adds the users CATquiz holds data for in this activity.
     *
     * @param userlist $userlist The userlist to add to, carrying the context.
     */
    public static function add_catmodel_users_to_userlist(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        [$catattemptids] = self::attempt_ids($context);

        if (empty($catattemptids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($catattemptids, SQL_PARAMS_NAMED);
        $userlist->add_from_sql('userid', "SELECT userid FROM {local_catquiz_attempts} WHERE id $insql", $inparams);
    }

    /**
     * Removes the CATquiz rows of the given attempts.
     *
     * @param int[] $catattemptids Ids in local_catquiz_attempts.
     * @param int[] $hostattemptids Ids of the attempts of the activity.
     */
    private static function delete(array $catattemptids, array $hostattemptids): void {
        global $DB;

        if (!empty($catattemptids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($catattemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_catquiz_attemptscale', "catattemptid $insql", $inparams);
            $DB->delete_records_select('local_catquiz_attempts', "id $insql", $inparams);
        }

        if (!empty($hostattemptids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($hostattemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(
                'local_catquiz_progress',
                "attemptid $insql AND component = :component",
                $inparams + ['component' => 'mod_adaptivequiz']
            );
        }
    }
}
