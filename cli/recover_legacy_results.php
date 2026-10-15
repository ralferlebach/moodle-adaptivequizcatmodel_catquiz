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
 *   php mod/adaptivequiz/catmodel/catquiz/cli/recover_legacy_results.php --since=2026-07-14 [--execute]
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

Attempts that started before --since stay as they are. Every other completed attempt without a valid
result is judged again from its answers by today's rules; a missing range is taken from the scale as it
stands today and marked. Graded is the global scale. Recovered attempts are protocolled and skipped by
a later run.

  --since=YYYY-MM-DD   Required. Start of the window, 00:00 in the server's time zone. The upgrade to
                       2026100906 ran the recovery once with --since=2026-07-14 (task in the log).
  --execute            Write. Without it nothing is changed.
  --attemptid=N        Only this attempt (adaptivequiz_attempt.id).
  --csv=FILE           Write one line per candidate attempt to FILE.
  -h, --help           This help.

EOT;

if ($options['help'] || $unrecognised || $options['since'] === '') {
    cli_write($usage);
    exit($options['help'] ? 0 : 1);
}
try {
    // The same reading of the date as the one-off migration (task\recover_legacy_results).
    $cutoff = legacy_result_recovery::cutoff($options['since']);
} catch (invalid_parameter_exception $e) {
    cli_error("--since must be a date YYYY-MM-DD, not '{$options['since']}'.");
}
$since = (new DateTime('@' . $cutoff))->setTimezone(core_date::get_server_timezone_object());

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

cli_writeln(($options['execute'] ? 'Recovery' : 'Dry run') . ' since ' . $since->format('Y-m-d H:i T')
    . ': ' . count($report->rows) . ' candidate attempt(s).');
foreach ($report->counts as $class => $count) {
    cli_writeln(sprintf('  %-22s %6d', $class, $count));
}
cli_writeln(sprintf('  %-22s %6d', $options['execute'] ? 'graded' : 'would be graded', $report->graded));
if (!$options['execute']) {
    cli_writeln('Nothing was changed. Run again with --execute to recover.');
}
