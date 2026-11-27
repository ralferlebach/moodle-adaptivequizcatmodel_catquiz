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
 * Counts the CATquiz rows that lead nowhere, by likely cause and age - read only (issue #11, AK8).
 *
 * Nothing here deletes or changes a row: what to do with old rows is the operator's decision, after this inventory.
 * The result holds counts only - no ids, no people.
 *
 * Classes, by the two id spaces of local_catquiz #95 (see attempt_cleanup):
 * - progress_orphan_hostid: a progress row whose attemptid is no CAT attempt but the attempt of the activity of the
 *   same person - working state from before local_catquiz #101, keyed by the activity's attempt id;
 * - progress_orphan_none: a progress row whose attemptid is no CAT attempt and no attempt of that person - its
 *   attempt was deleted (among others by the old deletion of the activity, which took the wrong number);
 * - progress_foreign: a progress row whose attemptid is the CAT attempt of somebody else - the number of one space
 *   met the other;
 * - cat_attempt_no_host_instance / cat_attempt_no_host_noinstance: a CAT attempt of the activity whose attempt of
 *   the activity is gone, its activity still there or gone as well;
 * - cat_attempt_no_hostid: a CAT attempt of the activity without the attempt id of the activity;
 * - personparams_orphan: an ability that names a CAT attempt which does not exist;
 * - trace_orphan: a trace row of an attempt of the activity that is gone.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class orphan_inventory {
    /** @var int[] Upper bounds of the age classes in days; the last class is everything older. */
    public const AGES = [30, 180, 365];

    /**
     * The inventory.
     *
     * @param int|null $now Unix time the ages are counted from; now if null.
     * @return array class => [total, age classes (le30, le180, le365, older), table, cause]
     */
    public static function collect(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        [$compsql, $compparams] = $DB->get_in_or_equal(attempt_cleanup::COMPONENTS, SQL_PARAMS_NAMED, 'comp');
        $manager = $DB->get_manager();

        $classes = [
            'progress_orphan_hostid' => [
                'local_catquiz_progress', 'p', 'timemodified',
                "NOT EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = p.attemptid)
                 AND EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = p.attemptid AND h.userid = p.userid)",
                [],
                'Working state keyed by the attempt of the activity (before local_catquiz #101).',
            ],
            'progress_orphan_none' => [
                'local_catquiz_progress', 'p', 'timemodified',
                "NOT EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = p.attemptid)
                 AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = p.attemptid AND h.userid = p.userid)",
                [],
                'Its attempt was deleted; among others by the old deletion of the activity (adapter #11).',
            ],
            'progress_foreign' => [
                'local_catquiz_progress', 'p', 'timemodified',
                "EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = p.attemptid AND a.userid <> p.userid)",
                [],
                'Names the CAT attempt of somebody else: the two id spaces met.',
            ],
            'progress_steps_orphan' => [
                'local_catquiz_progress_steps', 's', 'timecreated',
                "NOT EXISTS (SELECT 1 FROM {local_catquiz_progress} p WHERE p.attemptid = s.catattemptid)",
                [],
                'An event of a working state whose head is gone (local_catquiz #177 keeps both together).',
            ],
            'cat_attempt_no_host_instance' => [
                'local_catquiz_attempts', 'c', 'timecreated',
                "c.component $compsql AND c.attemptid IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = c.attemptid)
                 AND EXISTS (SELECT 1 FROM {adaptivequiz} q WHERE q.id = c.instanceid)",
                $compparams,
                'The attempt of the activity is gone, the activity is there.',
            ],
            'cat_attempt_no_host_noinstance' => [
                'local_catquiz_attempts', 'c', 'timecreated',
                "c.component $compsql AND c.attemptid IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = c.attemptid)
                 AND NOT EXISTS (SELECT 1 FROM {adaptivequiz} q WHERE q.id = c.instanceid)",
                $compparams,
                'The attempt of the activity and the activity are gone.',
            ],
            'cat_attempt_no_hostid' => [
                'local_catquiz_attempts', 'c', 'timecreated',
                "c.component $compsql AND c.attemptid IS NULL",
                $compparams,
                'A CAT attempt of the activity without the attempt id of the activity.',
            ],
            'personparams_orphan' => [
                'local_catquiz_personparams', 'pp', 'timecreated',
                "pp.attemptid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = pp.attemptid)",
                [],
                'An ability that names a CAT attempt which does not exist.',
            ],
            'trace_orphan' => [
                'local_catquiz_trace', 't', 'timecreated',
                "NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = t.adaptiveattemptid)",
                [],
                'A trace of an attempt of the activity that is gone; the retention task of local_catquiz removes it.',
            ],
        ];

        $result = [];
        foreach ($classes as $name => [$table, $alias, $timefield, $where, $params, $cause]) {
            if (!$manager->table_exists($table)) {
                continue;
            }
            $select = 'COUNT(1) AS total';
            $bounds = [];
            foreach (self::AGES as $i => $days) {
                $bounds["age$i"] = $now - $days * DAYSECS;
                $select .= ", SUM(CASE WHEN $alias.$timefield >= :age$i THEN 1 ELSE 0 END) AS le$days";
            }
            $row = $DB->get_record_sql("SELECT $select FROM {{$table}} $alias WHERE $where", $bounds + $params);
            $counts = ['total' => (int) $row->total];
            $previous = 0;
            foreach (self::AGES as $days) {
                $within = (int) ($row->{"le$days"} ?? 0);
                $counts["le$days"] = $within - $previous;
                $previous = $within;
            }
            $counts['older'] = $counts['total'] - $previous;
            $result[$name] = $counts + ['table' => $table, 'cause' => $cause];
        }
        return $result;
    }

    /**
     * The rows of each table the inventory looks at, to show that it changed nothing.
     *
     * @return int[] table => rows
     */
    public static function table_sizes(): array {
        global $DB;
        $sizes = [];
        foreach (
            [
                'local_catquiz_progress', 'local_catquiz_progress_steps', 'local_catquiz_attempts', 'local_catquiz_personparams',
                'local_catquiz_trace', 'adaptivequiz_attempt',
            ] as $table
        ) {
            if ($DB->get_manager()->table_exists($table)) {
                $sizes[$table] = $DB->count_records($table);
            }
        }
        return $sizes;
    }
}
