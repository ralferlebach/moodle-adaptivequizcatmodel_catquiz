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

namespace adaptivequizcatmodel_catquiz\local\monitoring;

use context_module;
use mod_adaptivequiz\cat_session;
use mod_adaptivequiz\local\attempt;
use question_engine;
use question_usage_by_activity;
use stdClass;

/**
 * The requests of an attempt as the host's attempt.php makes them, for measurements and tests (adapter #6).
 *
 * Each request processes the answer to the pending item, then administers the next one - through
 * cat_session::administer_next_item() on the 3.0 host, through cat_session::run_item_administration() on the
 * legacy host - and completes the attempt when the CAT model stops. The host's timing and the CATquiz timeline
 * start afresh for every request; writing the trace (timeline::flush()) is up to the caller.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_simulator {
    /** @var string The host's request timing; optional. */
    public const HOST_TIMING = '\\mod_adaptivequiz\\local\\request_timing';

    /** @var string The CATquiz timeline; optional. */
    public const TIMELINE = '\\local_catquiz\\local\\monitoring\\timeline';

    /**
     * Forgets the host's timing and the CATquiz timeline, and starts the clock and the memory peak: a new request.
     */
    public static function begin_request(): void {
        $hostclass = self::HOST_TIMING;
        if (method_exists($hostclass, 'reset_request')) {
            $hostclass::reset_request();
        } else if (class_exists($hostclass)) {
            $hostclass::reset();
        }
        $timeline = self::TIMELINE;
        if (class_exists($timeline)) {
            $timeline::reset();
        }
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
    }

    /**
     * One request of the attempt of a person.
     *
     * @param stdClass $adaptivequiz The activity record; context is set on it.
     * @param context_module $context
     * @param int $userid The person; must be the current user.
     * @param bool|null $right How the pending item is answered; null when there is none to answer.
     * @param bool $timequba Also time loading the question usage once before the request (not part of the trace).
     * @return array questionid (null when the attempt ended), stopped, attemptid, slots, requestms, qubaloadms
     */
    public static function request(
        stdClass $adaptivequiz,
        context_module $context,
        int $userid,
        ?bool $right,
        bool $timequba = false
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $adaptivequiz->context = $context;
        $uniqueid = (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', [
            'instance' => $adaptivequiz->id, 'userid' => $userid, 'attemptstate' => 'inprogress',
        ]);
        $qubaloadms = null;
        if ($timequba && $uniqueid > 0) {
            $start = hrtime(true);
            question_engine::load_questions_usage_by_activity($uniqueid);
            $qubaloadms = round((hrtime(true) - $start) / 1e6, 3);
        }

        self::begin_request();
        $start = hrtime(true);
        $attempt = new attempt($adaptivequiz, $userid);

        if ($right !== null && $uniqueid > 0) {
            $helper = function (question_usage_by_activity $quba) use ($right): void {
                self::answer($quba, max($quba->get_slots()), $right);
            };
            self::measure('process_response', fn() => cat_session::process_administered_item_result(
                $uniqueid,
                $adaptivequiz,
                $attempt,
                $helper
            ));
        }

        if (method_exists(cat_session::class, 'administer_next_item')) {
            // The 3.0 host.
            $attempt->get_attempt();
            $attempt->initialize_quba();
            $evaluation = self::measure(
                'administer_item',
                fn() => cat_session::administer_next_item($adaptivequiz, $attempt)
            );
            if ($evaluation === null) {
                throw new \coding_exception('Another request holds the lock of this attempt.');
            }
            $stopped = $evaluation->item_administration_is_to_stop();
            if ($stopped) {
                adaptivequiz_complete_attempt(
                    $uniqueid,
                    $adaptivequiz,
                    $context,
                    $userid,
                    '0',
                    (string) $evaluation->stoppage_reason(),
                    $evaluation->stop_code()
                );
            }
        } else {
            // The legacy host completes the attempt itself.
            self::measure('administer_item', fn() => cat_session::run_item_administration(
                $uniqueid,
                $adaptivequiz,
                $context,
                $attempt
            ));
            $stopped = !empty($attempt->get_status());
        }
        $requestms = round((hrtime(true) - $start) / 1e6, 3);

        // Not get_attempt(): after the end of the attempt it would start the next one.
        $attemptid = (int) $DB->get_field_sql(
            'SELECT MAX(id) FROM {adaptivequiz_attempt} WHERE instance = ? AND userid = ?',
            [$adaptivequiz->id, $userid]
        );
        $questionid = null;
        $slots = 0;
        if (!$stopped) {
            $quba = $attempt->get_quba();
            $slots = count($quba->get_slots());
            $questionid = (int) $quba->get_question($attempt->get_question_slot_number())->id;
        }
        return [
            'questionid' => $questionid, 'stopped' => $stopped, 'attemptid' => $attemptid, 'slots' => $slots,
            'requestms' => $requestms, 'qubaloadms' => $qubaloadms,
        ];
    }

    /**
     * Plays an attempt request by request, writing the trace after each one, and returns what each request cost.
     *
     * @param stdClass $adaptivequiz
     * @param context_module $context
     * @param int $userid The person; must be the current user.
     * @param int $items Items to administer at most; one more request answers the last of them.
     * @param callable $answer fn(int $n): bool, whether the n-th item (1 for the first) is answered right.
     * @return array One entry per request: item, requestms, qubaloadms, memoryendkb, memorypeakkb, attemptid,
     *      stopped, traced, traceid, kind, requestid, spans, error (the class of an exception, never its message).
     */
    public static function play(stdClass $adaptivequiz, context_module $context, int $userid, int $items, callable $answer): array {
        global $DB;
        $timeline = self::TIMELINE;
        $requests = [];
        for ($n = 0; $n <= $items; $n++) {
            $entry = ['item' => $n, 'error' => null, 'traced' => false, 'spans' => []];
            try {
                $result = self::request($adaptivequiz, $context, $userid, $n === 0 ? null : (bool) $answer($n), true);
            } catch (\Throwable $e) {
                $entry['error'] = get_class($e);
                $entry['errorwhere'] = basename($e->getFile()) . ':' . $e->getLine();
                $requests[] = $entry;
                break;
            }
            // Measured before the trace is written: writing it costs memory of its own.
            $entry['memoryendkb'] = round(memory_get_usage() / 1024, 1);
            $entry['memorypeakkb'] = round(memory_get_peak_usage(true) / 1024, 1);
            $entry['item'] = $result['stopped'] ? $n : $n + 1;
            $entry += $result;
            $traceid = class_exists($timeline) ? $timeline::flush() : null;
            if ($traceid !== null) {
                $row = $DB->get_record($timeline::TABLE, ['id' => $traceid], 'id, kind, spans', MUST_EXIST);
                $entry['traced'] = true;
                $entry['traceid'] = (int) $row->id;
                $entry['kind'] = $row->kind;
                $entry['spans'] = json_decode((string) $row->spans, true) ?: [];
                foreach ($entry['spans'] as $span) {
                    if ($span['name'] === 'request') {
                        $entry['requestid'] = $span['meta']['requestid'] ?? null;
                    }
                }
            }
            $requests[] = $entry;
            if ($result['stopped']) {
                break;
            }
        }
        return $requests;
    }

    /**
     * Answers an item of a single choice: the correct choice, or another valid one.
     *
     * @param question_usage_by_activity $quba
     * @param int $slot
     * @param bool $right
     */
    public static function answer(question_usage_by_activity $quba, int $slot, bool $right): void {
        $correct = $quba->get_correct_response($slot)['answer'] ?? 0;
        $quba->process_action($slot, ['answer' => $right ? $correct : ($correct >= 1 ? $correct - 1 : $correct + 1)]);
        $quba->finish_all_questions(time());
    }

    /**
     * Runs a step inside a host span, as attempt.php does - or just runs it, with a host without timing.
     *
     * @param string $name
     * @param callable $fn
     * @return mixed
     */
    private static function measure(string $name, callable $fn) {
        $hostclass = self::HOST_TIMING;
        return class_exists($hostclass) ? $hostclass::measure($name, $fn) : $fn();
    }
}
