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
use local_catquiz\local\monitoring\timeline;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * A long attempt - 160 items from a pool of 420 - through host, sub-plugin and CATquiz, traced (adapter #6).
 *
 * Runs only with the environment variable CATQUIZ_LONGRUN=1: it takes about a minute. With
 * CATQUIZ_LONGRUN_OUT=<file> the figures are written there as JSON; attempt-trace-longrun.md holds a run.
 * With CATQUIZ_LONGRUN_FEEDBACK=1 the feedback is built as in a real attempt and the run has 512 MiB;
 * without, the feedback is skipped (CATQUIZ_TESTING_SKIP_FEEDBACK) - a diagnostic of the selection alone.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\monitoring\request_simulator
 * @covers \adaptivequizcatmodel_catquiz\local\monitoring\longrun_stats
 */
final class longrun_test extends advanced_testcase {
    /** @var int Items administered in the attempt. */
    private const ITEMS = 160;

    /**
     * 160 items in one attempt: every request traced with its own id, no fatal error, no span left open, and
     * neither the memory of the process nor that of a request grows out of bounds with the attempt.
     *
     * @group longrun
     */
    public function test_long_attempt_is_traced_request_by_request(): void {
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
        $scenario = trace_scenario::create($this->getDataGenerator(), true, [
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_FASTEST,
            'catquiz_minquestions' => self::ITEMS,
            'catquiz_maxquestions' => self::ITEMS,
            'catquiz_minquestionspersubscale' => 0,
            'catquiz_maxquestionspersubscale' => 0,
            'catquiz_standarderror_min' => 0.0,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ], self::ITEMS + 20);
        $this->setUser($scenario->userid);
        set_config(timeline::CONFIG, 1, 'local_catquiz');

        $start = microtime(true);
        $requests = request_simulator::play(
            $scenario->adaptivequiz,
            $scenario->context,
            $scenario->userid,
            self::ITEMS,
            fn(int $n) => $n % 2 === 1
        );
        $seconds = round(microtime(true) - $start, 1);
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        $summary = longrun_stats::summarize($requests);
        $summary['seconds'] = $seconds;
        $summary['questions'] = count(array_unique(array_filter(array_column($requests, 'questionid'))));
        $summary['feedback'] = $withfeedback;
        $summary['memorylimit'] = (string) ini_get('memory_limit');
        $summary['processpeakmb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        $summary['moodle'] = (string) get_config('core', 'release');
        $summary['php'] = PHP_VERSION;
        if ($out = getenv('CATQUIZ_LONGRUN_OUT')) {
            file_put_contents($out, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($out . '.txt', longrun_stats::text($summary));
        }

        $this->assertSame(0, $summary['errors'], json_encode(array_filter(array_column($requests, 'errorwhere'))));
        $this->assertGreaterThanOrEqual(self::ITEMS, $summary['items'], 'The attempt ran its length.');
        $this->assertGreaterThanOrEqual(150, $summary['questions'], 'At least 150 different questions.');
        $this->assertSame($summary['requests'], count(array_filter(array_column($requests, 'traced'))), 'Every request traced.');
        $this->assertSame($summary['requests'], $summary['requestids']['distinct'], 'Each request its own id.');
        $this->assertSame(0, $summary['requestids']['missing']);
        $this->assertSame(0, $summary['fatals']);
        $this->assertSame([], $summary['open_spans']);
        foreach (['adapter:evaluate', 'adapter:fetch_question_id', 'catquiz:fetch_question_id'] as $name) {
            $this->assertGreaterThanOrEqual(self::ITEMS, $summary['spans'][$name]['n'], "$name in every request.");
        }
        $kinds = array_count_values(array_filter(array_column($requests, 'kind')));
        $this->assertSame(1, $kinds[timeline::KIND_FIRST_ITEM] ?? 0);
        $this->assertGreaterThanOrEqual(self::ITEMS - 1, $kinds[timeline::KIND_NEXT_ITEM] ?? 0);

        // Bounds with a wide margin, aggregated over the run, not pinned to a request: a leak of the process or a
        // request whose memory grows steeply with the length of the attempt shows here.
        $this->assertLessThan(128.0, $summary['memory']['endkb_per_item'], 'Memory of the process per item.');
        $this->assertLessThan(512.0, $summary['memory']['peakkb_per_item'], 'Peak memory of a request per item.');
        $this->assertLessThan(512.0, $summary['processpeakmb'], 'Within 512 MiB.');
        if ($withfeedback) {
            // The generated settings have no feedback ranges: the feedback reports that and leaves one area out.
            $this->resetDebugging();
        }
    }
}
