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
 * Recovers the results of older CATquiz attempts for the gradebook (local_catquiz #137).
 *
 * A dry run unless --execute is given. See legacy_result_recovery for what is done, and in which order.
 *
 * Usage:
 *   php mod/adaptivequiz/catmodel/catquiz/cli/recover_legacy_results.php [--execute] [--since=YYYY-MM-DD]
 *       [--attemptid=N] [--csv=/path/file.csv]
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use adaptivequizcatmodel_catquiz\local\recovery\legacy_result_recovery;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['since' => '', 'execute' => false, 'attemptid' => 0, 'csv' => '', 'help' => false],
    ['h' => 'help']
);

$usage = <<<EOT
Recovers the results of older CATquiz attempts for the gradebook. Dry run unless --execute is given.

Every completed attempt without a valid result is judged again from its answers by today's rules,
whenever it ran; a missing range is taken from the scale as it stands today and marked. Graded is the
global scale. Recovered attempts are protocolled and skipped by a later run. The upgrade queues the same
recovery as a task, without a date (adapter #8).

  --execute            Write. Without it nothing is changed.
  --since=YYYY-MM-DD   Optional filter of the operator: only attempts started from this day on, 00:00 in
                       the server's time zone; attempts started earlier or without a start time are left
                       as they are (outside_window). Without it the date decides nothing.
  --attemptid=N        Only this attempt (adaptivequiz_attempt.id).
  --csv=FILE           Write one line per candidate attempt to FILE.
  -h, --help           This help.

EOT;

if ($options['help'] || $unrecognised) {
    cli_write($usage);
    exit($options['help'] ? 0 : 1);
}
$cutoff = null;
if ($options['since'] !== '') {
    try {
        $cutoff = legacy_result_recovery::cutoff($options['since']);
    } catch (invalid_parameter_exception $e) {
        cli_error("--since must be a date YYYY-MM-DD, not '{$options['since']}'.");
    }
}
$window = $cutoff === null ? 'without a date filter'
    : 'since ' . (new DateTime('@' . $cutoff))->setTimezone(core_date::get_server_timezone_object())->format('Y-m-d H:i T');

$recovery = new legacy_result_recovery(
    $cutoff,
    (bool) $options['execute'],
    $options['attemptid'] ? (int) $options['attemptid'] : null
);
$report = $recovery->run();

if ($options['csv'] !== '') {
    $handle = fopen($options['csv'], 'w');
    if (!$handle) {
        cli_error("Cannot write {$options['csv']}.");
    }
    fputcsv($handle, legacy_result_recovery::csv_columns());
    foreach ($report->rows as $row) {
        fputcsv($handle, legacy_result_recovery::csv_fields($row));
    }
    fclose($handle);
}

cli_writeln(($options['execute'] ? 'Recovery' : 'Dry run') . ' ' . $window
    . ': ' . count($report->rows) . ' candidate attempt(s).');
foreach ($report->counts as $class => $count) {
    cli_writeln(sprintf('  %-22s %6d', $class, $count));
}
cli_writeln(sprintf('  %-22s %6d', $options['execute'] ? 'graded' : 'would be graded', $report->graded));
if (!$options['execute']) {
    cli_writeln('Nothing was changed. Run again with --execute to recover.');
}
