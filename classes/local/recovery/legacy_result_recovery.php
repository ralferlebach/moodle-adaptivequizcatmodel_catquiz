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

namespace adaptivequizcatmodel_catquiz\local\recovery;

use adaptivequizcatmodel_catquiz\local\catmodel\result\catquiz_result_provider;
use local_catquiz\local\attempt\attempt_revalidator;
use local_catquiz\local\result\attempt_result_validator;
use local_catquiz\local\result\personparam_repository;
use local_catquiz\teststrategy\progress;
use mod_adaptivequiz\local\result\link\linear_link_function;
use mod_adaptivequiz\local\result\result_service;
use stdClass;

/**
 * Recovers the results of older CATquiz attempts for the gradebook (local_catquiz #137).
 *
 * Triage, recovery, validation and grading, in that order and strictly apart:
 *
 * 1. Triage. Candidates are completed attempts of CATquiz activities without a valid result snapshot
 *    (also one a revalidation marked valid when the grading after it failed: valid, but no percentage).
 *    Whether an attempt is recovered depends on what it holds, not on when it ran (adapter #8): it is left
 *    as it is when it was recovered before (idempotence), has no CAT attempt, was never finalised by
 *    CATquiz, or has no stored answers (progress) to be judged from (reconstructability). Its start time
 *    plays no part - an attempt without one is a candidate like any other. Only an operator can narrow a
 *    run to attempts started from a day on (the CLI's --since); then an attempt started earlier, or
 *    without a start time, is left as it is (OUTSIDE_WINDOW).
 * 2. Recovery. The attempt is judged again from its answers by today's rules
 *    (local_catquiz attempt_revalidator - the validator of every finalisation). The result is stored
 *    per scale; a scale without a range recorded at finalisation gets the range of the scale as it
 *    stands today, marked (rangebackfilled = 1). Recovery alone never makes an attempt valid.
 * 3. Grading. The activity takes the attempt's snapshot again and rebuilds its grades - the host's
 *    own pipeline (result_service::regrade). Graded is the global scale (catquiz_result_provider).
 *
 * Without $execute nothing is written: the run reports what the recovery would do, attempt by attempt.
 * Every recovered attempt is protocolled in adaptivequiz_cat_recovery, which a later run skips - so a
 * second run changes nothing. An error with one attempt, or one activity, is reported for it (FAILED) and
 * the run goes on with the others; nothing of the failed attempt is kept, and a later run tries it again.
 *
 * Two ways in, one recovery: cli/recover_legacy_results.php (dry run, diagnosis, by hand, optionally from a
 * day on) and the migration the upgrade queues (task\recover_legacy_results, issue #8) - without a cutoff.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class legacy_result_recovery {
    /** @var string Recovered before: protocolled. */
    public const ALREADY_RECOVERED = 'already_recovered';

    /** @var string No CAT attempt belongs to the attempt. */
    public const NO_CAT_ATTEMPT = 'no_cat_attempt';

    /** @var string Started before the --since of the operator, or without a start time; only with --since. */
    public const OUTSIDE_WINDOW = 'outside_window';

    /** @var string CATquiz never finalised the attempt. */
    public const NOT_FINALISED = 'not_finalised';

    /** @var string The answers of the attempt are no longer stored. */
    public const NOT_RECONSTRUCTABLE = 'not_reconstructable';

    /** @var string Recovered, and the global scale is gradable. */
    public const RECOVERED_VALID = 'recovered_valid';

    /** @var string Recovered, and the global scale is not gradable. */
    public const RECOVERED_INVALID = 'recovered_invalid';

    /** @var string An error stopped the recovery of this attempt; nothing of it was kept. */
    public const FAILED = 'failed';

    /** @var string The range was recorded at finalisation. */
    public const RANGE_FROZEN = 'frozen';

    /** @var string The range was taken from the scale as it stands at recovery. */
    public const RANGE_CURRENT = 'current_scale';

    /** @var string[] The classes, in the order of the report. */
    public const CLASSES = [
        self::OUTSIDE_WINDOW, self::NO_CAT_ATTEMPT, self::NOT_FINALISED, self::NOT_RECONSTRUCTABLE,
        self::ALREADY_RECOVERED, self::RECOVERED_VALID, self::RECOVERED_INVALID, self::FAILED,
    ];

    /** @var string The protocol table. */
    private const TABLE = 'adaptivequiz_cat_recovery';

    /**
     * The cutoff of a window that starts on a day: 00:00 in the server's time zone.
     *
     * @param string $date YYYY-MM-DD
     * @return int Unix time
     * @throws \invalid_parameter_exception For anything but a date YYYY-MM-DD.
     */
    public static function cutoff(string $date): int {
        $since = \DateTime::createFromFormat('!Y-m-d', $date, \core_date::get_server_timezone_object());
        if (!$since || $since->format('Y-m-d') !== $date) {
            throw new \invalid_parameter_exception("A date YYYY-MM-DD, not '$date'.");
        }
        return $since->getTimestamp();
    }

    /**
     * The recovery.
     *
     * @param int|null $cutoff Unix time of an operator's --since: attempts that started earlier, or without a
     *      start time, are left as they are. null - the migration - for every attempt, whenever it ran.
     * @param bool $execute False: report only.
     * @param int|null $attemptid Only this attempt (adaptivequiz_attempt.id).
     */
    public function __construct(
        /** @var int|null Unix time of an operator's --since; null for no filter by date. */
        private readonly ?int $cutoff,
        /** @var bool False: report only. */
        private readonly bool $execute,
        /** @var int|null Only this attempt. */
        private readonly ?int $attemptid = null
    ) {
    }

    /**
     * Runs the recovery.
     *
     * @return stdClass counts (class => number), graded (new grades), rows (one per candidate, see row()).
     */
    public function run(): stdClass {
        global $DB;

        $report = (object) ['counts' => array_fill_keys(self::CLASSES, 0), 'graded' => 0, 'rows' => []];
        $params = ['state' => 'complete', 'model' => 'catquiz'];
        // Without a valid result snapshot: not valid, or marked valid by a revalidation whose grading failed
        // (a snapshot without a percentage) - such an attempt is taken on again, not lost.
        $where = "a.attemptstate = :state AND q.catmodel = :model
                  AND (COALESCE(a.resultvalid, 0) = 0 OR (a.resultpercent IS NULL AND a.resulttime IS NOT NULL))";
        if ($this->attemptid !== null) {
            $where .= ' AND a.id = :attemptid';
            $params['attemptid'] = $this->attemptid;
        }
        $candidates = $DB->get_records_sql(
            "SELECT a.id, a.instance, a.userid, a.resultreason
               FROM {adaptivequiz_attempt} a
               JOIN {adaptivequiz} q ON q.id = a.instance
              WHERE $where
           ORDER BY a.instance, a.id",
            $params
        );

        $byinstance = [];
        foreach ($candidates as $attempt) {
            $row = $this->recover($attempt);
            $report->counts[$row->class]++;
            $report->rows[(int) $attempt->id] = $row;
            if (in_array($row->class, [self::RECOVERED_VALID, self::RECOVERED_INVALID], true)) {
                $byinstance[(int) $attempt->instance][] = (int) $attempt->id;
            }
        }

        if ($this->execute) {
            foreach ($byinstance as $instanceid => $attemptids) {
                // Snapshots, grades and protocol of an activity together, or none of them: an attempt whose
                // grading failed keeps no snapshot that would hide it from a later run.
                $transaction = $DB->start_delegated_transaction();
                try {
                    $this->grade($instanceid, $attemptids, $report);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    try {
                        $transaction->rollback($e);
                    } catch (\Throwable $rethrown) {
                        // The rollback hands the error back; it is reported below.
                        unset($rethrown);
                    }
                    // The other activities are graded all the same; these attempts are tried again later.
                    foreach ($attemptids as $attemptid) {
                        $report->rows[$attemptid]->class = self::FAILED;
                        $report->rows[$attemptid]->newreason = $e->getMessage();
                    }
                }
            }
            $report->counts = array_fill_keys(self::CLASSES, 0);
            foreach ($report->rows as $each) {
                $report->counts[$each->class]++;
            }
        }
        $report->graded = count(array_filter($report->rows, fn($row) => $row->class === self::RECOVERED_VALID));
        return $report;
    }

    /**
     * Triage and, if it passes, recovery of one attempt.
     *
     * @param stdClass $attempt id, instance, userid, resultreason
     * @return stdClass See row().
     */
    private function recover(stdClass $attempt): stdClass {
        global $DB;

        $row = self::row($attempt);
        if ($DB->record_exists(self::TABLE, ['attemptid' => $attempt->id])) {
            $row->class = self::ALREADY_RECOVERED;
            return $row;
        }
        $catattempt = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attempt->id], '*', IGNORE_MULTIPLE);
        if (!$catattempt) {
            $row->class = self::NO_CAT_ATTEMPT;
            return $row;
        }
        $row->catattemptid = (int) $catattempt->id;
        $row->starttime = $catattempt->starttime === null ? null : (int) $catattempt->starttime;
        $row->globalscaleid = !empty($catattempt->scaleid) ? (int) $catattempt->scaleid : null;
        // A filter of the operator only; without it the date decides nothing (adapter #8).
        if ($this->cutoff !== null && ($row->starttime === null || $row->starttime < $this->cutoff)) {
            $row->class = self::OUTSIDE_WINDOW;
            return $row;
        }
        if (empty($catattempt->endtime)) {
            $row->class = self::NOT_FINALISED;
            return $row;
        }
        if (progress::load_for_reading((int) $catattempt->id) === null) {
            $row->class = self::NOT_RECONSTRUCTABLE;
            return $row;
        }

        try {
            $this->judge($catattempt, $row);
        } catch (\Throwable $e) {
            $row->class = self::FAILED;
            $row->newreason = $e->getMessage();
        }
        return $row;
    }

    /**
     * Judges the attempt again - stored, or only predicted - and fills in the row.
     *
     * @param stdClass $catattempt local_catquiz_attempts
     * @param stdClass $row
     * @return void
     */
    private function judge(stdClass $catattempt, stdClass $row): void {
        global $DB;

        // The range each scale was recorded with, before anything is written.
        $frozen = [];
        foreach (personparam_repository::get_for_attempt((int) $catattempt->id) as $stored) {
            if (is_numeric($stored->rangelower) && is_numeric($stored->rangeupper)) {
                $frozen[(int) $stored->catscaleid] = [(float) $stored->rangelower, (float) $stored->rangeupper];
            }
        }

        $row->steps[] = 'revalidated';
        if ($this->execute) {
            attempt_revalidator::revalidate($row->attemptid, true);
            $global = $row->globalscaleid === null ? null : $DB->get_record(
                'local_catquiz_personparams',
                ['attemptid' => $catattempt->id, 'catscaleid' => $row->globalscaleid],
                '*',
                IGNORE_MULTIPLE
            );
        } else {
            $global = self::predicted_row(attempt_result_validator::validate($row->attemptid), $row->globalscaleid);
        }

        if ($global === null) {
            $row->class = self::RECOVERED_INVALID;
            $row->newreason = catquiz_result_provider::REASON_NO_GLOBAL;
            return;
        }

        if (isset($frozen[$row->globalscaleid])) {
            [$row->rangelower, $row->rangeupper] = $frozen[$row->globalscaleid];
            $row->rangesource = self::RANGE_FROZEN;
        } else {
            [$row->rangelower, $row->rangeupper] = personparam_repository::current_range($row->globalscaleid);
            $row->rangesource = self::RANGE_CURRENT;
            $row->steps[] = 'range_from_current_scale';
        }

        $rejection = catquiz_result_provider::rejection(
            $global,
            catquiz_result_provider::quiz_settings($catattempt, (object) ['id' => $row->instanceid])
        );
        if ($rejection === null && $row->rangelower === null) {
            $rejection = catquiz_result_provider::REASON_RANGE_NOT_RECORDED;
        }
        $percent = null;
        if ($rejection === null) {
            $percent = result_service::percentage(
                (float) $global->ability,
                $row->rangelower,
                $row->rangeupper,
                linear_link_function::ID
            );
            if ($percent === null) {
                $rejection = result_service::REASON_RANGE_INVALID;
            }
        }
        $row->class = $rejection === null ? self::RECOVERED_VALID : self::RECOVERED_INVALID;
        $row->newreason = $rejection;
        $row->resultpercent = $percent;
    }

    /**
     * The stored row the revalidation would write for the global scale, from the validator's verdict.
     *
     * @param \local_catquiz\local\result\attempt_result $result
     * @param int|null $globalscaleid
     * @return stdClass|null ability, n, standarderror, fraction, isvalid, validationstatus - null when the global
     *      scale has no result.
     */
    private static function predicted_row(\local_catquiz\local\result\attempt_result $result, ?int $globalscaleid): ?stdClass {
        if ($globalscaleid === null) {
            return null;
        }
        foreach ($result->get_scale_results() as $scaleresult) {
            // Only scales measured in the attempt are stored, as personparam_repository does.
            if ((int) $scaleresult->scaleid !== $globalscaleid || !$scaleresult->measuredincurrentattempt) {
                continue;
            }
            return (object) [
                'ability' => $scaleresult->score,
                'n' => $scaleresult->n,
                'standarderror' => $scaleresult->standarderror,
                'fraction' => $scaleresult->fraction,
                'isvalid' => $scaleresult->valid ? 1 : 0,
                'validationstatus' => implode(',', $scaleresult->rejectionreasons),
            ];
        }
        return null;
    }

    /**
     * Takes the snapshots of the recovered attempts of an activity again, rebuilds its grades and
     * protocols each attempt with what the activity now holds.
     *
     * @param int $instanceid
     * @param int[] $attemptids
     * @param stdClass $report
     * @return void
     */
    private function grade(int $instanceid, array $attemptids, stdClass $report): void {
        global $DB;

        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $instanceid], '*', MUST_EXIST);
        result_service::regrade($adaptivequiz, $attemptids);
        $version = (int) get_config('adaptivequizcatmodel_catquiz', 'version');

        foreach ($attemptids as $attemptid) {
            $row = $report->rows[$attemptid];
            $snapshot = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], 'resultvalid, resultreason, resultpercent');
            // What the activity holds counts: the prediction is only the report of a dry run.
            $row->class = !empty($snapshot->resultvalid) ? self::RECOVERED_VALID : self::RECOVERED_INVALID;
            $row->newreason = $snapshot->resultreason;
            $row->resultpercent = $snapshot->resultpercent === null ? null : (float) $snapshot->resultpercent;
            $row->steps[] = 'snapshot';
            $DB->insert_record(self::TABLE, (object) [
                'attemptid' => $attemptid,
                'instanceid' => $instanceid,
                'catattemptid' => $row->catattemptid,
                // 0: no start time recorded, no --since given (the columns are NOT NULL).
                'starttime' => (int) ($row->starttime ?? 0),
                'cutoff' => (int) ($this->cutoff ?? 0),
                'oldreason' => $row->oldreason,
                'steps' => implode(',', $row->steps),
                'globalscaleid' => $row->globalscaleid,
                'rangelower' => $row->rangelower,
                'rangeupper' => $row->rangeupper,
                'rangesource' => (string) $row->rangesource,
                'valid' => $row->class === self::RECOVERED_VALID ? 1 : 0,
                'newreason' => $row->newreason,
                'resultpercent' => $row->resultpercent,
                'codeversion' => $version,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * The report row of a candidate, before triage.
     *
     * @param stdClass $attempt
     * @return stdClass
     */
    private static function row(stdClass $attempt): stdClass {
        return (object) [
            'attemptid' => (int) $attempt->id,
            'instanceid' => (int) $attempt->instance,
            'userid' => (int) $attempt->userid,
            'catattemptid' => null,
            'starttime' => null,
            'oldreason' => $attempt->resultreason,
            'class' => '',
            'steps' => [],
            'globalscaleid' => null,
            'rangelower' => null,
            'rangeupper' => null,
            'rangesource' => '',
            'newreason' => null,
            'resultpercent' => null,
        ];
    }

    /**
     * The columns of the CSV protocol.
     *
     * @return string[]
     */
    public static function csv_columns(): array {
        return [
            'attemptid', 'instanceid', 'userid', 'catattemptid', 'starttime', 'class', 'oldreason', 'steps',
            'globalscaleid', 'rangelower', 'rangeupper', 'rangesource', 'newreason', 'resultpercent',
        ];
    }

    /**
     * A report row as CSV fields.
     *
     * @param stdClass $row
     * @return array
     */
    public static function csv_fields(stdClass $row): array {
        $fields = [];
        foreach (self::csv_columns() as $column) {
            $value = $row->$column;
            $fields[] = is_array($value) ? implode(',', $value) : ($value === null ? '' : (string) $value);
        }
        return $fields;
    }
}
