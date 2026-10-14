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

namespace adaptivequizcatmodel_catquiz\local\catmodel;

/**
 * The activity's code for why CATquiz ended a test (mod_adaptivequiz issue #20).
 *
 * CATquiz reports its own status (local_catquiz\local\status); the activity explains the end on the
 * result page from its own, smaller set of codes. This is the one place that translates.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stop_code {
    /** @var array CATquiz status => code of mod_adaptivequiz's stop_reason. */
    private const MAP = [
        'reachedmaximumquestions' => 'maximumquestions',
        'stoptargetprecision' => 'targetprecision',
        // The estimate no longer moves: as good as it gets - precise enough, not out of questions.
        'abortpersonabilitynotchanged' => 'targetprecision',
        'noremainingquestions' => 'noeligiblequestions',
        'noremainingquestionsbeforeminimum' => 'noeligiblequestions',
        'errornoitems' => 'noeligiblequestions',
        'emptyfirstquestionlist' => 'noeligiblequestions',
        'exceededmaxattempttime' => 'timelimit',
        'attemptclosedbytimelimit' => 'timelimit',
        'error' => 'error',
        'errorfetchnextquestion' => 'error',
        'testiteminrelatedscale' => 'error',
    ];

    /**
     * The activity's code for a CATquiz status; unknown statuses keep their own name.
     *
     * The activity shows a neutral notice for a code it does not know - better than claiming a
     * reason that was not the one.
     *
     * @param string|null $status A status of local_catquiz\local\status, or null when none was given.
     * @return string|null
     */
    public static function from_status(?string $status): ?string {
        if ($status === null || $status === '') {
            return null;
        }
        return self::MAP[$status] ?? $status;
    }
}
