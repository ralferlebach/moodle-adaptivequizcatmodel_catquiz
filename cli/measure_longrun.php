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
 * Long attempts through host, sub-plugin and CATquiz, request by request (adapter #6).
 *
 * Every simulated request runs as attempt.php runs it - the answer to the pending item, then the next item -
 * with the host's timing and the CATquiz timeline started afresh, and the trace written at its end. The
 * figures: p50/p95 per span, the growth of memory per item (of the process and of a request's peak), the
 * growth of loading the question usage, fatal errors, open spans and the request ids.
 *
 * The attempts are made on an existing activity by existing users without an open attempt there, and deleted
 * afterwards together with their traces (--keep keeps them). Nothing is seeded.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use adaptivequizcatmodel_catquiz\local\monitoring\longrun_stats;
use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');

[$options] = cli_get_params([
    'help' => false,
    'cmid' => 0,
    'items' => 150,
    'users' => 1,
    'workers' => 1,
    'answers' => 'pattern:1010',
    'trace' => 'on',
    'out' => '',
    'sameuser' => false,
    'keep' => false,
    'worker' => -1,
    'userids' => '',
], ['h' => 'help']);

if ($options['help'] || empty($options['cmid'])) {
    cli_writeln("Long attempts through host, sub-plugin and CATquiz, request by request (adapter #6).\n");
    cli_writeln('  --cmid=N                 Course module of the adaptivequiz activity (CATquiz as CAT model).');
    cli_writeln('  --items=N                Items per attempt at most (default 150).');
    cli_writeln('  --users=N                Attempts, one per user (default 1).');
    cli_writeln('  --workers=N              Processes running the attempts side by side (default 1).');
    cli_writeln('  --sameuser               All workers on the attempt of one user: lock contention.');
    cli_writeln('  --answers=pattern:1010   Answers repeating the pattern (1 right, 0 wrong), or random:SEED.');
    cli_writeln('  --trace=on|off           The CATquiz timeline for this process (default on).');
    cli_writeln('  --out=FILE.json          Writes the figures as JSON; the text goes to the console.');
    cli_writeln('  --keep                   Keeps the attempts and their traces.');
    exit(0);
}

$cm = get_coursemodule_from_id('adaptivequiz', (int) $options['cmid'], 0, false, MUST_EXIST);
$adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $cm->instance], '*', MUST_EXIST);
if (($adaptivequiz->catmodel ?? '') !== 'catquiz') {
    cli_error('The activity does not use CATquiz as its CAT model.');
}
$context = context_module::instance($cm->id);
$adaptivequiz->context = $context;
$adaptivequiz->cm = $cm;
$items = max(1, (int) $options['items']);
$trace = $options['trace'] !== 'off';

// The switch for this process only; the site's setting stays as it is.
$CFG->forced_plugin_settings['local_catquiz']['attempttrace'] = $trace ? 1 : 0;
putenv('CATQUIZ_TESTING_SKIP_FEEDBACK=true');

/**
 * The answer function from --answers.
 *
 * @param string $spec pattern:1010 or random:SEED
 * @param int $userid
 * @return callable fn(int $n): bool
 */
function measure_longrun_answers(string $spec, int $userid): callable {
    [$type, $value] = array_pad(explode(':', $spec, 2), 2, '');
    if ($type === 'random') {
        $seed = (int) $value + $userid;
        return function (int $n) use ($seed): bool {
            mt_srand($seed + $n);
            return mt_rand(0, 1) === 1;
        };
    }
    $pattern = preg_replace('/[^01]/', '', $value) ?: '10';
    return fn(int $n) => $pattern[($n - 1) % strlen($pattern)] === '1';
}

/**
 * Runs the attempts of some users in this process.
 *
 * @param stdClass $adaptivequiz
 * @param context_module $context
 * @param int[] $userids
 * @param int $items
 * @param string $answers
 * @param int $worker
 * @return array requests, attemptids
 */
function measure_longrun_run(
    stdClass $adaptivequiz,
    context_module $context,
    array $userids,
    int $items,
    string $answers,
    int $worker
): array {
    global $DB;
    $requests = [];
    $attemptids = [];
    foreach ($userids as $userid) {
        \core\session\manager::set_user($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
        $played = request_simulator::play($adaptivequiz, $context, $userid, $items, measure_longrun_answers($answers, $userid));
        foreach ($played as $request) {
            $request['worker'] = $worker;
            $request['userid'] = $userid;
            if (!empty($request['attemptid'])) {
                $attemptids[$request['attemptid']] = true;
            }
            $requests[] = $request;
        }
    }
    return ['requests' => $requests, 'attemptids' => array_keys($attemptids)];
}

if ((int) $options['worker'] >= 0) {
    // A worker: its users, its requests as JSON on stdout.
    $userids = array_map('intval', array_filter(explode(',', (string) $options['userids'])));
    $answers = (string) $options['answers'];
    $result = measure_longrun_run($adaptivequiz, $context, $userids, $items, $answers, (int) $options['worker']);
    echo json_encode($result);
    exit(0);
}

// Users without an open attempt on this activity; nobody is created.
$wanted = max(1, (int) $options['users']);
$users = $DB->get_fieldset_sql(
    "SELECT u.id
       FROM {user} u
      WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2
        AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} a
                         WHERE a.userid = u.id AND a.instance = :instance AND a.attemptstate = 'inprogress')
   ORDER BY u.id",
    ['instance' => $adaptivequiz->id],
    0,
    $options['sameuser'] ? 1 : $wanted
);
if (!$users) {
    cli_error('No user without an open attempt on this activity.');
}
if (count($users) < $wanted && !$options['sameuser']) {
    cli_writeln(sprintf('Only %d users without an open attempt; running %d attempts.', count($users), count($users)));
}

$workers = max(1, (int) $options['workers']);
$started = microtime(true);
$requests = [];
$attemptids = [];
if ($workers === 1) {
    $result = measure_longrun_run($adaptivequiz, $context, array_map('intval', $users), $items, (string) $options['answers'], 0);
    $requests = $result['requests'];
    $attemptids = $result['attemptids'];
} else {
    // Each worker a process of its own, with its own users - or all of them on one user's attempt.
    $chunks = [];
    foreach ($users as $i => $userid) {
        $chunks[$i % $workers][] = $userid;
    }
    if ($options['sameuser']) {
        $chunks = array_fill(0, $workers, [$users[0]]);
    }
    $processes = [];
    foreach ($chunks as $worker => $chunk) {
        $command = [PHP_BINARY, __FILE__, '--cmid=' . (int) $options['cmid'], '--items=' . $items,
            '--answers=' . $options['answers'], '--trace=' . ($trace ? 'on' : 'off'), '--worker=' . $worker,
            '--userids=' . implode(',', $chunk)];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            cli_error("Worker $worker could not be started.");
        }
        $processes[$worker] = [$process, $pipes];
    }
    foreach ($processes as $worker => [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $result = json_decode((string) $output, true);
        if (!is_array($result)) {
            cli_writeln("Worker $worker gave no result: " . substr(trim($errors . ' ' . $output), 0, 300));
            continue;
        }
        $requests = array_merge($requests, $result['requests']);
        $attemptids = array_merge($attemptids, $result['attemptids']);
    }
}
$seconds = round(microtime(true) - $started, 1);

$summary = longrun_stats::summarize($requests);
$summary['seconds'] = $seconds;
$summary['workers'] = $workers;
$summary['users'] = $options['sameuser'] ? 1 : count($users);
$summary['trace'] = $trace;
$summary['attempts'] = count(array_unique($attemptids));

cli_writeln(sprintf(
    'Activity %d, %d items at most, %d worker(s), trace %s, %.1f s',
    (int) $options['cmid'],
    $items,
    $workers,
    $trace ? 'on' : 'off',
    $seconds
));
cli_write(longrun_stats::text($summary));
foreach ($requests as $request) {
    if (!empty($request['error'])) {
        cli_writeln(sprintf(
            '  error in worker %d at item %d: %s (%s)',
            $request['worker'],
            $request['item'],
            $request['error'],
            $request['errorwhere'] ?? ''
        ));
    }
}

if ($options['out'] !== '') {
    $perrequest = array_map(fn($r) => array_intersect_key($r, array_flip([
        'worker', 'userid', 'attemptid', 'item', 'kind', 'requestid', 'requestms', 'qubaloadms', 'memoryendkb',
        'memorypeakkb', 'stopped', 'error',
    ])), $requests);
    file_put_contents(
        (string) $options['out'],
        json_encode(['summary' => $summary, 'requests' => $perrequest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    cli_writeln('Written: ' . $options['out']);
}

if (!$options['keep']) {
    // The attempts were made for the measurement only: away with them, their CATquiz data and their traces.
    foreach (array_unique($attemptids) as $attemptid) {
        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid]);
        if (!$attempt) {
            continue;
        }
        if (function_exists('adaptivequiz_delete_attempt')) {
            adaptivequiz_delete_attempt($adaptivequiz, $attempt);
        } else {
            question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
            $DB->delete_records('question_usages', ['id' => $attempt->uniqueid]);
            $DB->delete_records('adaptivequiz_attempt', ['id' => $attempt->id]);
            adaptivequizcatmodel_catquiz_post_delete_attempt_callback($adaptivequiz, $attempt);
            adaptivequiz_update_grades($adaptivequiz, $attempt->userid);
        }
        $DB->delete_records('local_catquiz_trace', ['adaptiveattemptid' => $attemptid]);
    }
    cli_writeln(sprintf('Deleted %d attempts and their traces (--keep keeps them).', count(array_unique($attemptids))));
}
