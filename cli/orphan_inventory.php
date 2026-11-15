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
 * Counts the CATquiz rows that lead nowhere, by likely cause and age. Read only (issue #11, AK8).
 *
 * Usage:
 *   php mod/adaptivequiz/catmodel/catquiz/cli/orphan_inventory.php [--json]
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use adaptivequizcatmodel_catquiz\local\orphan_inventory;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['json' => false, 'help' => false], ['h' => 'help']);

$usage = <<<EOT
Counts the CATquiz rows that lead nowhere, by likely cause and age - read only. Nothing is deleted or
changed; what to do with old rows is the operator's decision (see doc/deletion-and-orphans.md).
The output holds counts only: no ids, no people.

  --json      Print JSON instead of a table.
  -h, --help  This help.

EOT;

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    echo $usage;
    exit(0);
}

$before = orphan_inventory::table_sizes();
$inventory = orphan_inventory::collect();
$after = orphan_inventory::table_sizes();
if ($before !== $after) {
    // Rows were written meanwhile by running attempts; the counts are a snapshot, not wrong.
    cli_writeln('Note: the tables changed while counting (running attempts).');
}

$retention = get_config('local_catquiz', 'progressretention') ?: 'minimal';
$traceretention = (int) (get_config('local_catquiz', 'attempttraceretentiondays') ?: 14);

if ($options['json']) {
    echo json_encode([
        'generated' => date('c'), 'progressretention' => $retention, 'traceretentiondays' => $traceretention,
        'tables' => $after, 'classes' => $inventory,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

cli_writeln('CATquiz orphan inventory, ' . date('Y-m-d H:i') . " (read only; progress retention: $retention, "
    . "trace retention: $traceretention days)");
cli_writeln('');
cli_writeln(sprintf('%-32s %8s %8s %8s %8s %8s', 'class', 'total', '<=30d', '<=180d', '<=365d', 'older'));
foreach ($inventory as $class => $c) {
    cli_writeln(sprintf('%-32s %8d %8d %8d %8d %8d', $class, $c['total'], $c['le30'], $c['le180'], $c['le365'], $c['older']));
}
cli_writeln('');
foreach ($inventory as $class => $c) {
    cli_writeln("$class ({$c['table']}): {$c['cause']}");
}
cli_writeln('');
cli_writeln('Rows per table: ' . implode(', ', array_map(fn($t, $n) => "$t $n", array_keys($after), $after)));
