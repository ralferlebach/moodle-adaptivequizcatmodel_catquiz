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

use adaptivequizcatmodel_catquiz\local\catmodel\stop_code;
use advanced_testcase;
use local_catquiz\local\status;
use mod_adaptivequiz\local\attempt\stop_reason;

/**
 * Every status CATquiz ends a test with has the activity's code for it (mod_adaptivequiz #20).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\adaptivequizcatmodel_catquiz\local\catmodel\stop_code::class)]
final class stop_code_test extends advanced_testcase {
    /**
     * CATquiz status and the activity's code.
     *
     * @return array
     */
    public static function statuses(): array {
        return [
            'maximum' => [status::ERROR_REACHED_MAXIMUM_QUESTIONS, stop_reason::MAXIMUM],
            'precision' => [status::STOP_TARGET_PRECISION, stop_reason::TARGET_PRECISION],
            'every scale done (local_catquiz #172)' => [status::STOP_SCALES_COMPLETE, stop_reason::SCALES_COMPLETE],
            'estimate stable' => [status::ABORT_PERSONABILITY_NOT_CHANGED, stop_reason::TARGET_PRECISION],
            'pool empty' => [status::ERROR_NO_REMAINING_QUESTIONS, stop_reason::NO_ELIGIBLE],
            'pool empty before minimum' => [
                status::ERROR_NO_REMAINING_QUESTIONS_BEFORE_MINIMUM,
                stop_reason::NO_ELIGIBLE_BEFORE_MINIMUM,
            ],
            'no items' => [status::ERROR_NO_ITEMS, stop_reason::NO_ELIGIBLE],
            'no first question' => [status::ERROR_EMPTY_FIRST_QUESTION_LIST, stop_reason::NO_ELIGIBLE],
            'time in the attempt' => [status::EXCEEDED_MAX_ATTEMPT_TIME, stop_reason::TIMELIMIT],
            'closed by the task' => [status::CLOSED_BY_TIMELIMIT, stop_reason::TIMELIMIT],
            'general error' => [status::ERROR_GENERAL, stop_reason::ERROR],
            'fetch error' => [status::ERROR_FETCH_NEXT_QUESTION, stop_reason::ERROR],
            'item in related scale' => [status::ERROR_TESTITEM_ALREADY_IN_RELATED_SCALE, stop_reason::ERROR],
        ];
    }

    /**
     * The mapping.
     *
     * @param string $status
     * @param string $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function test_status_maps_to_code(string $status, string $code): void {
        $this->assertSame($code, stop_code::from_status($status));
    }

    /**
     * No status, no code; an unknown one keeps its name - the activity shows a neutral notice.
     */
    public function test_missing_and_unknown_status(): void {
        $this->assertNull(stop_code::from_status(null));
        $this->assertNull(stop_code::from_status(''));
        $this->assertSame('somethingnew', stop_code::from_status('somethingnew'));
        $this->assertSame(stop_reason::NOTICE_INFO, stop_reason::notice_kind(stop_code::from_status('somethingnew')));
    }

    /**
     * Every status that can end a test is mapped - a new one would otherwise slip through unnoticed.
     */
    public function test_every_stopping_status_is_mapped(): void {
        $reflection = new \ReflectionClass(status::class);
        foreach ($reflection->getConstants() as $name => $value) {
            if (in_array($value, [status::OK, status::STATUS_UNDEFINED], true)) {
                continue;
            }
            $this->assertContains(
                stop_code::from_status($value),
                [stop_reason::MAXIMUM, stop_reason::TARGET_PRECISION, stop_reason::SCALES_COMPLETE, stop_reason::TIMELIMIT,
                    stop_reason::NO_ELIGIBLE, stop_reason::NO_ELIGIBLE_BEFORE_MINIMUM, stop_reason::ERROR],
                "Status $name has no code of the activity."
            );
        }
    }

    /**
     * The status numbers CATquiz stored over time (local_catquiz_attempts.status), read back: every number of the
     * mapping, -1 of attempts before #64 (an error was stored as "undefined") and a number no version wrote. A regular
     * end - maximum, precision, every scale done - comes only from its own status; -1 and an unknown number never read
     * as one, nor as an empty pool: neutral, no claim (adapter #12 AK3, AK7).
     */
    public function test_stored_status_numbers_of_every_version(): void {
        $mapping = (new \ReflectionClass(status::class))->getProperty('mapping');
        $mapping->setAccessible(true);
        $byint = array_flip($mapping->getValue());
        $this->assertCount(15, $mapping->getValue());
        $this->assertEqualsCanonicalizing(range(-1, 13), array_keys($byint), 'The numbers -1 to 13, none reused.');

        $regular = [stop_reason::MAXIMUM, stop_reason::TARGET_PRECISION, stop_reason::SCALES_COMPLETE];
        $regularstatuses = [status::ERROR_REACHED_MAXIMUM_QUESTIONS, status::STOP_TARGET_PRECISION,
            status::ABORT_PERSONABILITY_NOT_CHANGED, status::STOP_SCALES_COMPLETE];
        foreach ([...array_keys($byint), 99, -7] as $number) {
            $name = $byint[$number] ?? null;
            $code = $name === null ? null : stop_code::from_status($name);
            if (in_array($code, $regular, true)) {
                $this->assertContains($name, $regularstatuses, "Status $number reads as a regular end.");
            }
            if ($name === null || $name === status::STATUS_UNDEFINED || $name === status::OK) {
                // Unknown, undefined (an error before #64) or still running: no regular end, no empty pool, no error
                // claimed either - the host shows a neutral notice or none.
                $claims = [...$regular, stop_reason::NO_ELIGIBLE, stop_reason::NO_ELIGIBLE_BEFORE_MINIMUM];
                $this->assertNotContains($code, $claims, "Status $number");
                $this->assertNotSame(stop_reason::NOTICE_NONE, stop_reason::notice_kind($code ?? 'unknown'), "Status $number");
            }
        }
        // The errors stay errors, whatever their number.
        $errors = [status::ERROR_GENERAL, status::ERROR_FETCH_NEXT_QUESTION, status::ERROR_TESTITEM_ALREADY_IN_RELATED_SCALE];
        foreach ($errors as $name) {
            $this->assertSame(stop_reason::ERROR, stop_code::from_status($name), $name);
            $this->assertSame(stop_reason::NOTICE_ERROR, stop_reason::notice_kind(stop_code::from_status($name)));
        }
    }
}
