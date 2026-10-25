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

use adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration;
use advanced_testcase;
use local_catquiz\local\monitoring\timeline;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The share of this sub-plugin in the timeline of an attempt, host to CATquiz (adapter #6).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(catquiz_item_administration::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequizcatmodel_catquiz_post_create_attempt_callback')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequizcatmodel_catquiz_post_complete_attempt_callback')]
final class trace_test extends advanced_testcase {
    /** @var string[] Spans of the first request: the host's, this sub-plugin's and CATquiz's. */
    private const FIRST_REQUEST = [
        'host:lock_wait', 'host:catmodel_attempt_created', 'adapter:post_create_attempt', 'adapter:create_new_for_attempt',
        'adapter:prepare_attempt_caches', 'prepare_attempt_caches', 'host:select_item', 'adapter:evaluate',
        'adapter:fetch_question_id', 'catquiz:fetch_question_id', 'host:load_question', 'host:question_usage',
    ];

    /** @var string[] Spans this sub-plugin and CATquiz write themselves; the rest come from the host. */
    private const OWN = [
        'adapter:post_create_attempt', 'adapter:create_new_for_attempt', 'adapter:prepare_attempt_caches',
        'prepare_attempt_caches', 'adapter:evaluate', 'adapter:fetch_question_id', 'catquiz:fetch_question_id',
    ];

    /**
     * Starts every test as a request of its own.
     */
    protected function setUp(): void {
        parent::setUp();
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK=true');
        trace_scenario::begin_request();
    }

    /**
     * Leaves no state for the next test.
     */
    protected function tearDown(): void {
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        trace_scenario::begin_request();
        parent::tearDown();
    }

    /**
     * Plays an attempt to its end and returns the questions in the order they were administered.
     *
     * @param trace_scenario $scenario
     * @param bool $trace Whether the trace is on; checked after every request.
     * @return array questionids, traceids (one per request), attemptid
     */
    private function play(trace_scenario $scenario, bool $trace): array {
        global $DB;
        $ids = [];
        $traces = [];
        $attemptid = null;
        for ($n = 0; $n < 20; $n++) {
            $result = $scenario->request($n === 0 ? null : $n % 3 !== 1);
            $attemptid = $result['attemptid'];
            if (!$trace) {
                $this->assertSame([], timeline::get_spans(), 'With the trace off nothing is recorded.');
            }
            $traces[] = timeline::flush();
            if ($result['stopped']) {
                break;
            }
            $ids[] = $result['questionid'];
        }
        if (!$trace) {
            $this->assertSame([null], array_values(array_unique($traces, SORT_REGULAR)));
            $this->assertSame(0, $DB->count_records(timeline::TABLE));
        }
        return ['questionids' => $ids, 'traceids' => $traces, 'attemptid' => $attemptid];
    }

    /**
     * Switched off - the default - the attempt runs as before and leaves no trace; switched on, the same
     * attempt takes the same questions.
     */
    public function test_trace_off_changes_nothing_and_on_selects_the_same(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $scenario = trace_scenario::create($this->getDataGenerator());
        $this->setUser($scenario->userid);

        set_config(timeline::CONFIG, 0, 'local_catquiz');
        $transaction = $DB->start_delegated_transaction();
        \cache_helper::purge_all();
        $off = $this->play($scenario, false);
        try {
            $transaction->rollback(new \moodle_exception('rollback'));
        } catch (\moodle_exception $e) {
            $this->assertSame('rollback', $e->errorcode);
        }
        \cache_helper::purge_all();
        $this->assertCount(4, $off['questionids'], 'The attempt ran to its length.');

        set_config(timeline::CONFIG, 1, 'local_catquiz');
        $on = $this->play($scenario, true);

        $this->assertSame($off['questionids'], $on['questionids'], 'The trace does not change the selection.');
        $this->assertCount(5, array_filter($on['traceids']), 'One trace per request, the completing one included.');
    }

    /**
     * Host, sub-plugin and CATquiz in one trace of the first request: one request id, the attempt on every
     * span of this sub-plugin, and the spans nested in time.
     */
    public function test_first_request_is_one_correlated_timeline(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $scenario = trace_scenario::create($this->getDataGenerator());
        $this->setUser($scenario->userid);
        set_config(timeline::CONFIG, 1, 'local_catquiz');

        $result = $scenario->request(null);
        $hostclass = trace_scenario::HOST_TIMING;
        $hostid = method_exists($hostclass, 'request_id') ? $hostclass::request_id() : null;
        $traceid = timeline::flush();
        $this->assertNotNull($traceid);
        $row = $DB->get_record(timeline::TABLE, ['id' => $traceid]);
        $this->assertSame($result['attemptid'], (int) $row->adaptiveattemptid);
        $this->assertSame(timeline::KIND_FIRST_ITEM, $row->kind);

        $spans = trace_scenario::spans($traceid);
        $missinghost = [];
        foreach (self::FIRST_REQUEST as $name) {
            if (in_array($name, self::OWN, true)) {
                $this->assertArrayHasKey($name, $spans, "Span $name is missing.");
            } else if (!isset($spans[$name])) {
                $missinghost[] = $name;
            }
        }
        if (class_exists($hostclass)) {
            $this->assertSame([], $missinghost, 'The host measures these spans.');
        }

        // One request: CATquiz takes the host's id where the host has one.
        $request = $spans[timeline::REQUEST_SPAN]['meta'];
        $this->assertNotEmpty($request['requestid']);
        if ($hostid !== null) {
            $this->assertSame($hostid, $request['requestid']);
        }
        $this->assertFalse($request['fatal']);
        $this->assertGreaterThan(0, $request['memorypeakkb']);

        // The attempt on every span of this sub-plugin.
        foreach (
            ['adapter:post_create_attempt', 'adapter:create_new_for_attempt', 'adapter:prepare_attempt_caches',
                'adapter:evaluate', 'adapter:fetch_question_id'] as $name
        ) {
            $this->assertSame($result['attemptid'], $spans[$name]['meta']['attemptid'], "Attempt on $name.");
        }

        // Nested in time: the host's selection holds this sub-plugin's, which holds CATquiz's.
        $this->assert_within($spans['adapter:fetch_question_id'], $spans['adapter:evaluate']);
        $this->assert_within($spans['catquiz:fetch_question_id'], $spans['adapter:fetch_question_id']);
        $this->assert_within($spans['prepare_attempt_caches'], $spans['adapter:prepare_attempt_caches']);
        $this->assert_within($spans['adapter:prepare_attempt_caches'], $spans['adapter:post_create_attempt']);
        if (isset($spans['host:select_item'])) {
            $this->assert_within($spans['adapter:evaluate'], $spans['host:select_item']);
        }
        if (isset($spans['host:catmodel_attempt_created'])) {
            $this->assert_within($spans['adapter:post_create_attempt'], $spans['host:catmodel_attempt_created']);
        }
        // Memory on CATquiz's spans.
        $this->assertArrayHasKey('peakkb', $spans['catquiz:fetch_question_id']);
        $this->assertArrayHasKey('memdeltakb', $spans['adapter:evaluate']);
    }

    /**
     * The request that ends the attempt is traced as such, with the finalisation in it.
     */
    public function test_completing_request_is_traced(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $scenario = trace_scenario::create($this->getDataGenerator());
        $this->setUser($scenario->userid);
        set_config(timeline::CONFIG, 1, 'local_catquiz');

        $played = $this->play($scenario, true);
        $last = end($played['traceids']);
        $row = $DB->get_record(timeline::TABLE, ['id' => $last]);

        $this->assertSame(timeline::KIND_COMPLETE, $row->kind);
        $this->assertSame(4, (int) $row->questionnumber, 'The number of items answered.');
        $this->assertSame($played['attemptid'], (int) $row->adaptiveattemptid);
        $spans = trace_scenario::spans($last);
        foreach (
            ['adapter:evaluate', 'adapter:fetch_question_id', 'catquiz:fetch_question_id',
                'adapter:post_complete_attempt', 'adapter:attempt_finalizer'] as $name
        ) {
            $this->assertArrayHasKey($name, $spans, "Span $name is missing.");
        }
        $this->assert_within($spans['adapter:attempt_finalizer'], $spans['adapter:post_complete_attempt']);
        $this->assertSame($played['attemptid'], $spans['adapter:attempt_finalizer']['meta']['attemptid']);
    }

    /**
     * A process that ends several attempts - the scheduled task of the time limit - writes one trace per attempt.
     */
    public function test_each_completed_attempt_gets_its_own_trace(): void {
        global $DB;
        $this->resetAfterTest();
        set_config(timeline::CONFIG, 1, 'local_catquiz');
        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $context = \context_module::instance($adaptivequiz->cmid);

        foreach ([701, 702] as $id) {
            $attempt = (object) ['id' => $id, 'questionsattempted' => 3, 'timefinished' => time(), 'attemptstopcriteria' => ''];
            adaptivequizcatmodel_catquiz_post_complete_attempt_callback($adaptivequiz, $context, 2, $attempt);
        }
        timeline::flush();

        $rows = $DB->get_records(timeline::TABLE, null, 'id');
        $this->assertSame([701, 702], array_map('intval', array_values(array_column($rows, 'adaptiveattemptid'))));
        foreach ($rows as $row) {
            $this->assertSame(timeline::KIND_COMPLETE, $row->kind);
            $names = array_column(json_decode($row->spans, true), 'name');
            $this->assertSame(1, count(array_keys($names, 'adapter:attempt_finalizer')), 'Its own finalisation only.');
        }
    }

    /**
     * Asserts that a span lies within another one on the time axis of the request (1 ms for rounding).
     *
     * @param array $inner
     * @param array $outer
     */
    private function assert_within(array $inner, array $outer): void {
        $this->assertNotNull($inner['ms'], $inner['name'] . ' has a duration.');
        $this->assertNotNull($outer['ms'], $outer['name'] . ' has a duration.');
        if (!str_starts_with($outer['name'], 'host:')) {
            // One stack: the inner span is deeper. The host keeps a stack of its own.
            $this->assertGreaterThan($outer['depth'], $inner['depth'], $inner['name'] . ' is nested in ' . $outer['name']);
        }
        $this->assertGreaterThanOrEqual(
            $outer['offsetms'] - 1.0,
            $inner['offsetms'],
            $inner['name'] . ' starts in ' . $outer['name']
        );
        $this->assertLessThanOrEqual(
            $outer['offsetms'] + $outer['ms'] + 1.0,
            $inner['offsetms'] + $inner['ms'],
            $inner['name'] . ' ends in ' . $outer['name']
        );
    }
}
