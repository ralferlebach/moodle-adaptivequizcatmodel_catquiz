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
            'estimate stable' => [status::ABORT_PERSONABILITY_NOT_CHANGED, stop_reason::TARGET_PRECISION],
            'pool empty' => [status::ERROR_NO_REMAINING_QUESTIONS, stop_reason::NO_ELIGIBLE],
            'pool empty before minimum' => [status::ERROR_NO_REMAINING_QUESTIONS_BEFORE_MINIMUM, stop_reason::NO_ELIGIBLE],
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
                [stop_reason::MAXIMUM, stop_reason::TARGET_PRECISION, stop_reason::TIMELIMIT, stop_reason::NO_ELIGIBLE,
                    stop_reason::ERROR],
                "Status $name has no code of the activity."
            );
        }
    }
}
