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

namespace adaptivequizcatmodel_catquiz;

use adaptivequizcatmodel_catquiz\local\monitoring\longrun_stats;
use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use advanced_testcase;
use local_catquiz\local\attempt\attempt_finalizer;
use local_catquiz\local\monitoring\timeline;
use local_catquiz\teststrategy\progress;
use question_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->dirroot . '/local/catquiz/tests/fixtures/concurrent_processes.php');

/**
 * Long attempts through host, sub-plugin and CATquiz, traced (adapter #6; re-audit 19.11.2026, long run P0).
 *
 * Runs only with the environment variable CATQUIZ_LONGRUN=1. CATQUIZ_LONGRUN_ITEMS sets the length (150 to 250,
 * default 250). The pool is the simulation pool of 420 single-choice items with twins of every second one as
 * true/false, numerical and short-answer questions - the same item parameters, other question types.
 *
 * One person: every request traced with its own id; no fatal error, no span left open; memory of the process and
 * of a request bounded per item (the size of the session is measured where sessions are real, in the attempt load
 * over HTTP, tests/load of local_catquiz); the request that answers the middle item dies after saving the
 * answer and the retry shows the next item once; at the end question usage and progress agree slot by slot, the
 * stored data are measured, and a second finalisation changes nothing.
 *
 * Twelve people at the same time (CATQUIZ_LONGRUN_PARALLEL, default 12; CATQUIZ_LONGRUN_PARALLEL_ITEMS, default
 * 150): real processes against the real database, each with its own attempt - no error, no item twice, nothing of
 * one person in the attempt of another, one finalisation each.
 *
 * With CATQUIZ_LONGRUN_OUT=<file> the figures are written there as JSON (and as text, <file>.txt). With
 * CATQUIZ_LONGRUN_FEEDBACK=1 the feedback is built as in a real attempt and the run has 512 MiB; without, the
 * feedback is skipped (CATQUIZ_TESTING_SKIP_FEEDBACK) - a diagnostic of the selection alone.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\monitoring\request_simulator
 * @covers \adaptivequizcatmodel_catquiz\local\monitoring\longrun_stats
 */
final class longrun_test extends advanced_testcase {
    use \local_catquiz_concurrent_processes;

    /**
     * The length of the long run.
     *
     * @return int
     */
    private static function items(): int {
        $items = (int) (getenv('CATQUIZ_LONGRUN_ITEMS') ?: 250);
        return max(150, min(250, $items));
    }

    /**
     * Skips unless the long run is asked for; sets up the feedback switch and the memory.
     *
     * @return bool Whether the feedback is built.
     */
    private function prepare(): bool {
        if (getenv('CATQUIZ_LONGRUN') !== '1') {
            $this->markTestSkipped('Long run: only with CATQUIZ_LONGRUN=1.');
        }
        // CATQUIZ_LONGRUN_FEEDBACK=1: with the whole feedback path, within 512 MiB (audit 18.10.). Without it the
        // feedback is skipped - a diagnostic run of the selection alone, which proves nothing about the feedback.
        $withfeedback = getenv('CATQUIZ_LONGRUN_FEEDBACK') === '1';
        if ($withfeedback) {
            ini_set('memory_limit', '512M');
        } else {
            putenv('CATQUIZ_TESTING_SKIP_FEEDBACK=true');
        }
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        return $withfeedback;
    }

    /**
     * A test as long as asked, on the simulation pool with other question types.
     *
     * @param int $items
     * @return array [trace_scenario, twins made by type]
     */
    private function scenario(int $items): array {
        $scenario = trace_scenario::create($this->getDataGenerator(), true, [
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
            'catquiz_minquestions' => $items,
            'catquiz_maxquestions' => $items,
            'catquiz_minquestionspersubscale' => 0,
            'catquiz_maxquestionspersubscale' => 0,
            'catquiz_standarderror_min' => 0.0,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ], $items + 20);
        $twins = $scenario->add_other_question_types();
        set_config(timeline::CONFIG, 1, 'local_catquiz');
        return [$scenario, $twins];
    }

    /**
     * What an attempt left behind: question usage against progress, stored data, the finalisation.
     *
     * @param int $attemptid adaptivequiz_attempt.id
     * @return array slots, played, duplicates, answered, types, bytes, finalised, catuserid
     */
    private static function inspect(int $attemptid): array {
        global $DB;
        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        $cat = $DB->get_record('local_catquiz_attempts', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $quba = question_engine::load_questions_usage_by_activity((int) $attempt->uniqueid);
        $usage = [];
        $types = [];
        foreach ($quba->get_slots() as $slot) {
            $question = $quba->get_question($slot);
            $usage[] = (int) $question->id;
            $types[$question->get_type_name()] = ($types[$question->get_type_name()] ?? 0) + 1;
        }
        $progressjson = (string) $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $cat->id]);
        // Local_catquiz #177 stores played questions and responses as events: read as stored, in either format.
        $progress = method_exists(\local_catquiz\teststrategy\progress::class, 'stored_structure')
            ? json_decode(json_encode(\local_catquiz\teststrategy\progress::stored_structure((int) $cat->id, $progressjson)), true)
            : json_decode($progressjson, true);
        $progress = $progress ?: [];
        $played = array_map('intval', array_keys((array) ($progress['playedquestions'] ?? [])));
        $answered = array_map('intval', array_keys((array) ($progress['responses'] ?? [])));
        return [
            'userid' => (int) $attempt->userid,
            'catuserid' => (int) $cat->userid,
            'state' => $attempt->attemptstate,
            'questionsattempted' => (int) $attempt->questionsattempted,
            'slots' => count($usage),
            'usage' => $usage,
            'played' => $played,
            'answered' => $answered,
            'duplicates' => count($usage) - count(array_unique($usage)),
            'types' => $types,
            'bytes' => [
                'feedbackjson' => strlen((string) $cat->json),
                'debuginfo' => strlen((string) ($cat->debug_info ?? '')),
                'progressjson' => strlen($progressjson),
            ],
            'endtime' => (int) $cat->endtime,
            'results' => $DB->count_records('local_catquiz_personparams', ['attemptid' => $cat->id]),
        ];
    }

    /**
     * One person, a long attempt with a request that dies after the answer in the middle.
     *
     * @group longrun
     */
    public function test_long_attempt_is_traced_request_by_request(): void {
        global $DB;
        $withfeedback = $this->prepare();
        $items = self::items();
        [$scenario, $twins] = $this->scenario($items);
        $this->setUser($scenario->userid);

        $dieat = intdiv($items, 2);
        $start = microtime(true);
        $requests = request_simulator::play(
            $scenario->adaptivequiz,
            $scenario->context,
            $scenario->userid,
            $items,
            fn(int $n) => $n % 2 === 1,
            ['dieat' => $dieat]
        );
        $seconds = round(microtime(true) - $start, 1);
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        $attemptid = (int) end($requests)['attemptid'];
        $state = self::inspect($attemptid);

        // A second finalisation changes nothing: no second result, no other end time.
        $finished = (int) $DB->get_field('adaptivequiz_attempt', 'timefinished', ['id' => $attemptid]);
        $secondfinalise = attempt_finalizer::finalize($attemptid, $finished ?: time(), 'longrun');
        $after = self::inspect($attemptid);

        $summary = longrun_stats::summarize($requests);
        $summary['seconds'] = $seconds;
        $summary['questions'] = count(array_unique(array_filter(array_column($requests, 'questionid'))));
        $summary['feedback'] = $withfeedback;
        $summary['twins'] = $twins;
        $summary['types'] = $state['types'];
        $summary['stored_bytes'] = $state['bytes'];
        $summary['died_at'] = $dieat;
        $summary['finalised_again'] = $secondfinalise;
        $summary['memorylimit'] = (string) ini_get('memory_limit');
        $summary['processpeakmb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        $summary['moodle'] = (string) get_config('core', 'release');
        $summary['php'] = PHP_VERSION;
        $summary['itemsasked'] = $items;
        if ($out = getenv('CATQUIZ_LONGRUN_OUT')) {
            file_put_contents($out, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($out . '.txt', longrun_stats::text($summary) . "\n" . json_encode([
                'types' => $summary['types'], 'stored_bytes' => $summary['stored_bytes'], 'died_at' => $dieat,
                'finalised_again' => $secondfinalise, 'processpeakmb' => $summary['processpeakmb'],
            ], JSON_PRETTY_PRINT) . "\n");
        }

        $this->assertSame(0, $summary['errors'], json_encode(array_filter(array_column($requests, 'errorwhere'))));
        $this->assertGreaterThanOrEqual($items, $summary['items'], 'The attempt ran its length.');
        $this->assertGreaterThanOrEqual(150, $summary['questions'], 'At least 150 different questions.');
        // Every request is traced - but the one that died: it ended before its trace was written.
        $died = array_values(array_filter($requests, fn($r) => !empty($r['died'])));
        $traced = count(array_filter(array_column($requests, 'traced')));
        $this->assertSame($summary['requests'] - count($died), $traced, 'Every request traced.');
        $this->assertSame($traced, $summary['requestids']['distinct'], 'Each request its own id.');
        $this->assertSame(0, $summary['requestids']['missing']);
        $this->assertSame(0, $summary['fatals']);
        $this->assertSame([], $summary['open_spans']);
        foreach (['adapter:evaluate', 'adapter:fetch_question_id', 'catquiz:fetch_question_id'] as $name) {
            $this->assertGreaterThanOrEqual($items, $summary['spans'][$name]['n'], "$name in every request.");
        }
        $kinds = array_count_values(array_filter(array_column($requests, 'kind')));
        $this->assertSame(1, $kinds[timeline::KIND_FIRST_ITEM] ?? 0);
        $this->assertGreaterThanOrEqual($items - 1, $kinds[timeline::KIND_NEXT_ITEM] ?? 0);

        // Other question types than the single choice were administered and answered.
        $this->assertGreaterThanOrEqual(3, count($state['types']), 'Several question types: ' . json_encode($state['types']));

        // The request that died after the answer: the answer is kept once, the retry shows the next item once.
        $this->assertCount(1, $died, 'One request died after the answer.');
        $this->assertSame('complete', $state['state']);
        $this->assertSame(0, $state['duplicates'], 'No item twice in the question usage.');
        // Question usage and progress, slot by slot: the progress lists the questions of the usage in their order,
        // and every answered question once.
        $this->assertSame($state['usage'], $state['played'], 'Progress and question usage agree slot by slot.');
        $this->assertSame(array_values(array_unique($state['answered'])), $state['answered'], 'Every answer once.');
        $this->assertSame($state['questionsattempted'], count($state['answered']), 'The host counts what the progress holds.');

        // Idempotent finalisation.
        $this->assertFalse($secondfinalise, 'A second finalisation does nothing.');
        $this->assertSame($state['endtime'], $after['endtime']);
        $this->assertSame($state['results'], $after['results'], 'No second result.');
        $this->assertSame($state['bytes'], $after['bytes'], 'The stored data unchanged.');

        // Bounds with a wide margin, aggregated over the run, not pinned to a request: a leak of the process, a
        // request whose memory grows steeply with the attempt, or a session that grows with it shows here.
        $this->assertLessThan(128.0, $summary['memory']['endkb_per_item'], 'Memory of the process per item.');
        $this->assertLessThan(512.0, $summary['memory']['peakkb_per_item'], 'Peak memory of a request per item.');
        $this->assertLessThan(512.0, $summary['processpeakmb'], 'Within 512 MiB.');
        $this->assertLessThan(1024 * 1024, $state['bytes']['feedbackjson'], 'Stored feedback data below 1 MB.');
        if ($withfeedback) {
            // The generated settings have no feedback ranges: the feedback reports that and leaves one area out.
            $this->resetDebugging();
        }
    }

    /**
     * Twelve people at the same time, each a long attempt in a process of its own.
     *
     * @group longrun
     */
    public function test_twelve_people_at_the_same_time(): void {
        $withfeedback = $this->prepare();
        $this->require_concurrent_processes();
        $people = max(1, (int) (getenv('CATQUIZ_LONGRUN_PARALLEL') ?: 12));
        $items = max(10, min(250, (int) (getenv('CATQUIZ_LONGRUN_PARALLEL_ITEMS') ?: 150)));
        [$scenario] = $this->scenario($items);
        $users = [];
        for ($i = 0; $i < $people; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $scenario->adaptivequiz->course, 'student');
            $users[] = (int) $user->id;
        }

        $start = microtime(true);
        $answers = $this->at_the_same_time($people, function (int $i) use ($scenario, $users, $items): array {
            \core\session\manager::set_user(\core_user::get_user($users[$i]));
            $requests = request_simulator::play(
                $scenario->adaptivequiz,
                $scenario->context,
                $users[$i],
                $items,
                fn(int $n) => ($n + $i) % 3 !== 1
            );
            $summary = longrun_stats::summarize($requests);
            return [
                'attemptid' => (int) end($requests)['attemptid'],
                'errors' => $summary['errors'],
                'errorwhere' => array_values(array_filter(array_column($requests, 'errorwhere'))),
                'items' => $summary['items'],
                'requestms' => $summary['requestms'],
                'peakkb' => $summary['memory']['peakkb_max'],
                'processpeakmb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
        });
        $seconds = round(microtime(true) - $start, 1);
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');

        $runs = [];
        foreach ($answers as $i => $answer) {
            $this->assertArrayHasKey('ok', $answer, "Process $i: " . json_encode($answer));
            $run = $answer['ok'];
            $run['state'] = self::inspect($run['attemptid']);
            $runs[$i] = $run;
        }
        $all = array_merge(...array_map(fn($r) => [$r['requestms']['p95']], $runs));
        $summary = [
            'people' => $people, 'items' => $items, 'seconds' => $seconds, 'feedback' => $withfeedback,
            'requestms_p95_max' => max($all),
            'peakkb_max' => max(array_column($runs, 'peakkb')),
            'processpeakmb_max' => max(array_column($runs, 'processpeakmb')),
            'runs' => array_map(fn($r) => [
                'attemptid' => $r['attemptid'], 'errors' => $r['errors'], 'items' => $r['items'],
                'requestms' => $r['requestms'], 'duplicates' => $r['state']['duplicates'],
                'types' => $r['state']['types'], 'bytes' => $r['state']['bytes'],
            ], $runs),
            'moodle' => (string) get_config('core', 'release'),
            'php' => PHP_VERSION,
        ];
        if ($out = getenv('CATQUIZ_LONGRUN_OUT')) {
            file_put_contents(
                preg_replace('/(\.json)?$/', '-parallel.json', $out, 1),
                json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
        }

        $attempts = [];
        foreach ($runs as $i => $run) {
            $state = $run['state'];
            $this->assertSame(0, $run['errors'], "Person $i: " . json_encode($run['errorwhere']));
            $this->assertGreaterThanOrEqual($items, $run['items'], "Person $i ran the length of the test.");
            $this->assertSame('complete', $state['state'], "Person $i: the attempt ended.");
            // Nothing of one person in the attempt of another.
            $this->assertSame($users[$i], $state['userid'], "Person $i: the attempt of the person.");
            $this->assertSame($users[$i], $state['catuserid'], "Person $i: the CAT attempt of the person.");
            $this->assertSame(0, $state['duplicates'], "Person $i: no item twice.");
            $this->assertSame($state['usage'], $state['played'], "Person $i: progress and question usage agree.");
            $this->assertNotSame(0, $state['endtime'], "Person $i: finalised.");
            $this->assertFalse(attempt_finalizer::finalize($run['attemptid'], time(), 'longrun'), "Person $i: once.");
            $attempts[$run['attemptid']] = true;
        }
        $this->assertCount($people, $attempts, 'One attempt per person.');
        $this->assertLessThan(512.0, $summary['processpeakmb_max'], 'Each process within 512 MiB.');
        if ($withfeedback) {
            $this->resetDebugging();
        }
    }
}
