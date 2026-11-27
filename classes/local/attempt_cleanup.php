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
 * A deletion is one transaction (issue #11, AK7): it removes everything it found or nothing. A failure rolls back
 * and ends in a cleanup_failed_exception that names what was to be deleted and the cause; the data is then as
 * before and the same deletion can simply run again. Deleting again what is already gone changes nothing.
 *
 * What stays, on purpose (issue #11, AK4): progress rows whose attemptid is no CAT attempt - working state from
 * before local_catquiz #101, when progress was keyed by the activity's attempt id - are never deleted by number;
 * they belong to the read-only inventory (cli/orphan_inventory.php), not to a deletion.
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

    /** @var \Closure|null Unit tests only: called with the name of each step, may throw to simulate a failure. */
    private static ?\Closure $fault = null;

    /**
     * Unit tests only: a function called before each step of a deletion, which may throw.
     *
     * @param \Closure|null $fault fn(string $step): void; null to remove it.
     * @return void
     */
    public static function simulate_fault(?\Closure $fault): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('attempt_cleanup::simulate_fault() is for unit tests only.');
        }
        self::$fault = $fault;
    }

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
     * @throws cleanup_failed_exception Nothing was deleted.
     */
    public static function delete_for_host_attempts(array $hostattemptids): void {
        if (!$hostattemptids) {
            return;
        }
        $what = 'attempts ' . implode(', ', array_slice(array_map('intval', $hostattemptids), 0, 10))
            . (count($hostattemptids) > 10 ? ', ...' : '');
        self::in_transaction($what, static function () use ($hostattemptids): void {
            self::delete_host_attempt_data($hostattemptids);
        });
    }

    /**
     * Deletes everything local_catquiz keeps for an activity: its test settings and all its attempts' data.
     *
     * Called before the activity deletes its own attempts, so they still lead to the CAT attempts. CAT
     * attempts that name the activity but no longer have an attempt of the activity go as well.
     *
     * @param int $instanceid adaptivequiz.id
     * @return void
     * @throws cleanup_failed_exception Nothing was deleted.
     */
    public static function delete_for_instance(int $instanceid): void {
        self::in_transaction("activity $instanceid", static function () use ($instanceid): void {
            global $DB;
            self::step('tests');
            $DB->delete_records('local_catquiz_tests', ['componentid' => $instanceid, 'component' => 'mod_adaptivequiz']);

            $hostattemptids = $DB->get_fieldset_select(
                'adaptivequiz_attempt',
                'id',
                'instance = :instance',
                ['instance' => $instanceid]
            );
            self::delete_host_attempt_data($hostattemptids);

            [$compsql, $compparams] = $DB->get_in_or_equal(self::COMPONENTS, SQL_PARAMS_NAMED, 'comp');
            $orphans = $DB->get_fieldset_select(
                'local_catquiz_attempts',
                'id',
                "instanceid = :instanceid AND component $compsql",
                ['instanceid' => $instanceid] + $compparams
            );
            self::delete_cat_attempts(array_map('intval', $orphans));
        });
    }

    /**
     * Runs a deletion as one transaction; on failure, rolls back and says what and why.
     *
     * @param string $what What is deleted, for the message.
     * @param callable $deletion
     * @return void
     * @throws cleanup_failed_exception
     */
    private static function in_transaction(string $what, callable $deletion): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $deletion();
            self::step('commit');
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback(new cleanup_failed_exception($what, $e));
        }
    }

    /**
     * Deletes the CAT data of attempts of the activity, inside a transaction.
     *
     * @param int[] $hostattemptids adaptivequiz_attempt.id
     * @return void
     */
    private static function delete_host_attempt_data(array $hostattemptids): void {
        global $DB;
        if (!$hostattemptids) {
            return;
        }
        self::delete_cat_attempts(self::cat_attempt_ids($hostattemptids));
        self::step('trace');
        if ($DB->get_manager()->table_exists('local_catquiz_trace')) {
            foreach (array_chunk(array_map('intval', $hostattemptids), self::CHUNK) as $chunk) {
                [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'host');
                $DB->delete_records_select('local_catquiz_trace', "adaptiveattemptid $insql", $inparams);
            }
        }
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
            self::step('personparams');
            $DB->delete_records_select('local_catquiz_personparams', "attemptid $insql", $inparams);
            self::step('progress');
            if ($DB->get_manager()->table_exists('local_catquiz_progress_steps')) {
                // The events of the working state, from local_catquiz #177 on: with their head, in the same step.
                $DB->delete_records_select('local_catquiz_progress_steps', "catattemptid $insql", $inparams);
            }
            $DB->delete_records_select('local_catquiz_progress', "attemptid $insql", $inparams);
            self::step('attempts');
            $DB->delete_records_select('local_catquiz_attempts', "id $insql", $inparams);
        }
    }

    /**
     * A step of a deletion: where a unit test may make it fail.
     *
     * @param string $name
     * @return void
     */
    private static function step(string $name): void {
        if (self::$fault !== null) {
            (self::$fault)($name);
        }
    }
}
