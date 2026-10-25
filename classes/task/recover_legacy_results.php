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

namespace adaptivequizcatmodel_catquiz\task;

use adaptivequizcatmodel_catquiz\local\recovery\legacy_result_recovery;
use core\task\adhoc_task;
use core\task\manager;
use local_catquiz\local\result\scale_result;

/**
 * The one-off migration of older CATquiz results (adaptivequizcatmodel_catquiz #8).
 *
 * The upgrade queues this task (2026100906, and again 2026101703). It runs the recovery of
 * cli/recover_legacy_results.php - the same class, the same triage - with --execute and without a cutoff:
 * candidates are chosen by what an attempt holds - an invalid snapshot, stored answers, not recovered
 * before - never by its date (adapter #8). The recovery protocol makes a repeated run change nothing. A
 * cutoff stored with a task queued by an older version is not used.
 *
 * The regular regrade of the activity is not touched: it keeps existing snapshots as they are.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recover_legacy_results extends adhoc_task {
    /**
     * Queues the migration, unless it is queued already.
     *
     * @return void
     */
    public static function queue_migration(): void {
        $task = new self();
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Runs the recovery and writes what it did to the task log.
     *
     * @return void
     */
    public function execute() {
        // The validation that rejects an attempt beyond its maximum (33 of 25) comes with local_catquiz.
        // With an older local_catquiz the task waits - Moodle retries it - rather than grade such attempts.
        if (!defined(scale_result::class . '::REASON_MAX_QUESTIONS_EXCEEDED')) {
            throw new \moodle_exception('recoveryneedscatquiz', 'adaptivequizcatmodel_catquiz');
        }

        mtrace('CATquiz legacy recovery (adaptivequizcatmodel_catquiz #8): every attempt, whenever it started.');
        $report = (new legacy_result_recovery(null, true))->run();

        mtrace('  ' . count($report->rows) . ' candidate attempt(s).');
        foreach ($report->counts as $class => $count) {
            mtrace(sprintf('  %-22s %6d', $class, $count));
        }
        mtrace(sprintf('  %-22s %6d', 'graded', $report->graded));
        foreach ($report->rows as $row) {
            if ($row->class === legacy_result_recovery::FAILED) {
                mtrace(sprintf(
                    '  failed: attempt %d (CAT attempt %s, activity %d): %s',
                    $row->attemptid,
                    $row->catattemptid ?? '-',
                    $row->instanceid,
                    $row->newreason
                ));
            }
        }
    }
}
