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

use adaptivequizcatmodel_catquiz\local\catmodel\result\catquiz_result_provider;
use advanced_testcase;
use context_course;
use context_module;
use mod_adaptivequiz\local\result\attempt_result;
use mod_adaptivequiz\local\result\result_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/local/catquiz/tests/fixtures/measured_progress.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * CATquiz results reach the gradebook through the host's result contract (issue #7).
 *
 * From the finaliser through this adapter and the host to the gradebook: the score is the
 * finalised ability of the primary scale, the range that of the scale, and an invalid result -
 * fraction 0 or 1 among others - gives no grade, not even 0.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(catquiz_result_provider::class)]
final class result_provider_test extends advanced_testcase {
    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass The participant. */
    private stdClass $user;

    /** @var int The root scale of the test. */
    private int $scaleid;

    /** @var int Next question usage id. */
    private int $uniqueid = 5000;

    /**
     * A CATquiz activity on a scale with the given range.
     *
     * @param float|null $min
     * @param float|null $max
     * @param array $settings Further activity settings.
     */
    private function activity(?float $min, ?float $max, array $settings = []): void {
        global $DB;

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0, 'name' => 'Mathematics', 'minscalevalue' => $min, 'maxscalevalue' => $max,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $this->course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ] + $settings);
        $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $adaptivequiz->id]);
        $DB->insert_record('local_catquiz_tests', (object) [
            'name' => 'Test', 'component' => 'mod_adaptivequiz', 'componentid' => $adaptivequiz->id,
            'catscaleid' => $this->scaleid, 'json' => json_encode([]), 'status' => 0, 'parentid' => 0,
            'courseid' => $this->course->id, 'usermodified' => 2, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]);
        adaptivequiz_grade_item_update($this->adaptivequiz);
    }

    /**
     * A running attempt whose CATquiz data hold the given ability for the primary scale.
     *
     * @param float $ability
     * @param float $fraction Fraction of every answer; 0 or 1 make the scale invalid (issue #140).
     * @return stdClass The adaptivequiz_attempt.
     */
    private function attempt(float $ability, float $fraction = 0.5): stdClass {
        global $DB;

        $uniqueid = ++$this->uniqueid;
        $attempt = (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => 4,
            // The host's own measure would map onto a grade; it must play no part.
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 3.5,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => 1000 + $uniqueid,
        ];
        $attempt->id = $DB->insert_record('adaptivequiz_attempt', $attempt);
        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $this->user->id, 'scaleid' => $this->scaleid, 'contextid' => 9, 'attemptid' => $attempt->id,
            'component' => 'mod_adaptivequiz', 'courseid' => $this->course->id, 'instanceid' => $this->adaptivequiz->id,
            'status' => 0, 'number_of_testitems_used' => 4, 'endtime' => null,
            'json' => json_encode([
                'personabilities_abilities' => [$this->scaleid => ['value' => $ability, 'toreport' => true]],
                'se' => [$this->scaleid => 0.3],
                'primaryscale' => ['id' => $this->scaleid],
            ]),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->setUser($this->user);
        local_catquiz_measured_progress((int) $attempt->id, [$this->scaleid => 4], 9, [], [$this->scaleid => $fraction]);
        return $attempt;
    }

    /**
     * Completes an attempt the way the activity does: finaliser, snapshot, gradebook.
     *
     * @param stdClass $attempt
     * @return stdClass The attempt afterwards.
     */
    private function complete(stdClass $attempt): stdClass {
        global $DB;
        adaptivequiz_complete_attempt(
            (int) $attempt->uniqueid,
            $this->adaptivequiz,
            context_module::instance(get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id)->id),
            (int) $this->user->id,
            '0.3',
            'done'
        );
        return $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]);
    }

    /**
     * The grade the gradebook holds for the participant.
     *
     * @return float|null
     */
    private function grade(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'adaptivequiz', $this->adaptivequiz->id, $this->user->id);
        $grade = $grades->items[0]->grades[$this->user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Ability 1.2 on a scale from -4 to 4: 65 % in the gradebook, the range kept with the attempt.
     */
    public function test_valid_result_from_finaliser_to_gradebook(): void {
        $this->resetAfterTest();
        $this->activity(-4, 4);

        $attempt = $this->complete($this->attempt(1.2));

        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertEqualsWithDelta(1.2, (float) $attempt->resultscore, 1e-4);
        $this->assertEqualsWithDelta(-4.0, (float) $attempt->resultlower, 1e-9);
        $this->assertEqualsWithDelta(4.0, (float) $attempt->resultupper, 1e-9);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
    }

    /**
     * A question the system administered twice changes neither the grade nor the completion (#125).
     *
     * The usage holds four questions and the first one again in a fifth slot - the technical duplicate
     * of older activity versions. The result is the one of the four: valid, 65 %, and the rule
     * "valid result" is met. The duplicate is no reason to invalidate and no second measurement.
     */
    public function test_technical_duplicate_changes_neither_grade_nor_completion(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        // Completion is switched on afterwards: the course has none when the activity is created.
        $this->activity(-4, 4);
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        $DB->set_field('adaptivequiz', 'completionvalidresult', 1, ['id' => $this->adaptivequiz->id]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, [
            'id' => get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id)->id,
        ]);

        $attempt = $this->attempt(1.2);
        $cm = get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id);
        $context = context_module::instance($cm->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => context_course::instance($this->course->id)->id]);
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $first = null;
        for ($i = 0; $i < 4; $i++) {
            $question = \question_bank::load_question(
                $generator->create_question('truefalse', null, ['category' => $category->id])->id
            );
            $first = $first ?? $question;
            $quba->add_question($question);
        }
        $quba->add_question($first);
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->set_field('adaptivequiz_attempt', 'uniqueid', $quba->get_id(), ['id' => $attempt->id]);
        $attempt->uniqueid = $quba->get_id();

        $attempt = $this->complete($attempt);

        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertEqualsWithDelta(1.2, (float) $attempt->resultscore, 1e-4);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
        rebuild_course_cache($this->course->id, true);
        $cminfo = get_fast_modinfo($this->course->id, (int) $this->user->id)->get_cm($cm->id);
        $completion = new \mod_adaptivequiz\completion\custom_completion($cminfo, (int) $this->user->id);
        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionvalidresult'));
    }

    /**
     * A scale from 0 to 100: the lower bound 0 is used, not replaced by the default range.
     */
    public function test_zero_lower_bound(): void {
        $this->resetAfterTest();
        $this->activity(0, 100);

        $this->complete($this->attempt(50.0));

        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-3);
    }

    /**
     * A scale from -6 to 0: a negative range with the upper bound 0.
     */
    public function test_negative_range_with_upper_bound_zero(): void {
        $this->resetAfterTest();
        $this->activity(-6, 0);

        $this->complete($this->attempt(-1.5));

        $this->assertEqualsWithDelta(75.0, $this->grade(), 1e-3);
    }

    /**
     * A scale whose bounds form no range: an error that stops grading - no substitute range.
     */
    public function test_scale_without_a_range_gives_no_grade(): void {
        $this->resetAfterTest();
        $this->activity(2, 2);

        $attempt = $this->complete($this->attempt(2.0));

        $this->assertEquals(0, $attempt->resultvalid);
        $this->assertSame(result_service::REASON_RANGE_INVALID, $attempt->resultreason);
        $this->assertNull($this->grade());
        $this->assertFalse(result_service::definition($this->adaptivequiz)->is_gradable());
    }

    /**
     * Every answer right or every answer wrong: invalid, and no grade - not 0.
     *
     * @return array
     */
    public static function extreme_fractions(): array {
        return ['all correct' => [1.0, 'fraction_all_correct'], 'all incorrect' => [0.0, 'fraction_all_incorrect']];
    }

    /**
     * Fraction 0 or 1 gives no grade.
     *
     * @param float $fraction
     * @param string $reason
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('extreme_fractions')]
    public function test_extreme_fraction_gives_no_grade(float $fraction, string $reason): void {
        $this->resetAfterTest();
        $this->activity(-4, 4);

        $attempt = $this->complete($this->attempt(2.0, $fraction));

        $this->assertEquals(0, $attempt->resultvalid);
        $this->assertStringContainsString($reason, (string) $attempt->resultreason);
        $this->assertNull($attempt->resultscore, 'The diagnostic score stays inside CATquiz.');
        $this->assertNull($this->grade());
    }

    /**
     * A later invalid attempt neither replaces nor zeroes the grade of a valid one.
     */
    public function test_invalid_after_valid_keeps_the_valid_grade(): void {
        $this->resetAfterTest();
        $this->activity(-4, 4, ['grademethod' => ADAPTIVEQUIZ_ATTEMPTLAST]);

        $this->complete($this->attempt(0.0));
        $this->complete($this->attempt(3.0, 1.0));

        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-3);
    }

    /**
     * A changed range of the scale does not change the grade of a finished attempt.
     */
    public function test_changed_scale_range_keeps_historical_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(-4, 4);

        $this->complete($this->attempt(1.2));
        $DB->set_field('local_catquiz_catscales', 'minscalevalue', 0, ['id' => $this->scaleid]);
        \cache::make('local_catquiz', 'catscales')->purge();
        adaptivequiz_update_grades($this->adaptivequiz);

        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
        $this->assertEqualsWithDelta(0.0, result_service::definition($this->adaptivequiz)->lower, 1e-9);
    }

    /**
     * Not finalised, no stored result, no result for the global scale: no gradable score.
     */
    public function test_attempts_without_a_global_scale_result(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(-4, 4);
        $provider = new catquiz_result_provider();

        $attempt = $this->attempt(1.0);
        $this->assertSame(
            catquiz_result_provider::REASON_NOT_FINALISED,
            $provider->get_attempt_result($this->adaptivequiz, $attempt)->reason
        );

        $catattemptid = (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $attempt->id]);
        $DB->set_field('local_catquiz_attempts', 'endtime', time(), ['id' => $catattemptid]);
        $this->assertSame(
            catquiz_result_provider::REASON_NO_RESULT,
            $provider->get_attempt_result($this->adaptivequiz, $attempt)->reason
        );

        // Only a subscale has a result - valid and primary, but not the global scale.
        $DB->insert_record('local_catquiz_personparams', (object) [
            'attemptid' => $catattemptid, 'userid' => $this->user->id, 'contextid' => 9, 'catscaleid' => $this->scaleid + 1000,
            'ability' => 0.5, 'isprimary' => 1, 'isvalid' => 1, 'resultsource' => 'current', 'rangelower' => -4,
            'rangeupper' => 4, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertSame(
            catquiz_result_provider::REASON_NO_GLOBAL,
            $provider->get_attempt_result($this->adaptivequiz, $attempt)->reason
        );
    }

    /**
     * The verdict on the global scale (decision of 2026-10-14): the criteria the test sets, applied to it.
     *
     * Minimum questions per scale and of the test, maximum standard error, the fraction rule. The
     * strategy's own verdict - not reported first, reporting off, hidden - plays no part, nor do the
     * minimum standard error and the maximum number of questions.
     *
     * @return array
     */
    public static function global_scale_verdicts(): array {
        $all = ['perscale' => 4, 'test' => 6, 'semax' => 0.5];
        return [
            'every criterion met' => [8, 0.4, 0.5, $all, null],
            'the strategy reported a subscale' => [8, 0.4, 0.5, $all, null, 0, 'not_primary,reporting_disabled,hidden'],
            'below the minimum per scale' => [3, 0.4, 0.5, $all, 'n_min,min_questions_not_reached'],
            'below the minimum of the test' => [5, 0.4, 0.5, $all, 'min_questions_not_reached'],
            'standard error too large' => [8, 0.6, 0.5, $all, 'se_max'],
            'standard error unknown' => [8, null, 0.5, $all, 'se_max'],
            'every answer right' => [8, 0.4, 1.0, $all, 'fraction_all_correct'],
            'every answer wrong' => [8, 0.4, 0.0, $all, 'fraction_all_incorrect'],
            'no criterion set' => [1, 3.0, 0.5, [], null],
            'not measured' => [0, 0.4, null, [], 'not_measured_in_current_attempt'],
        ];
    }

    /**
     * The criteria decide, whatever the strategy said.
     *
     * @param int $n
     * @param float|null $se
     * @param float|null $fraction
     * @param array $criteria perscale, test, semax
     * @param string|null $reason Null: gradable.
     * @param int $isvalid The strategy's verdict.
     * @param string $status The strategy's reasons.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('global_scale_verdicts')]
    public function test_verdict_on_the_global_scale(
        int $n,
        ?float $se,
        ?float $fraction,
        array $criteria,
        ?string $reason,
        int $isvalid = 1,
        string $status = ''
    ): void {
        $settings = (object) [
            'maxquestionsscalegroup' => ['catquiz_minquestionspersubscale' => $criteria['perscale'] ?? 0],
            'maxquestionsgroup' => (object) ['catquiz_minquestions' => $criteria['test'] ?? 0, 'catquiz_maxquestions' => 2],
            'catquiz_standarderrorgroup' => [
                'catquiz_standarderror_min' => 0.45,
                'catquiz_standarderror_max' => $criteria['semax'] ?? '',
            ],
        ];
        $row = (object) [
            'ability' => 1.0, 'n' => $n, 'standarderror' => $se, 'fraction' => $fraction,
            'isvalid' => $isvalid, 'validationstatus' => $status,
        ];

        $this->assertSame($reason, catquiz_result_provider::rejection($row, $settings));
    }

    /**
     * From the stored result to the gradebook: the global scale's own N, standard error and fraction count.
     *
     * The strategy reported a subscale ("lowest skill gap") and marked the global scale "not primary" -
     * graded all the same. A subscale's verdict plays no part.
     */
    public function test_graded_on_the_global_scale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(-4, 4);
        $attempt = $this->complete($this->attempt(1.2));
        $catattemptid = (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $attempt->id]);
        $DB->delete_records('local_catquiz_personparams', ['attemptid' => $catattemptid]);
        $rows = [
            [$this->scaleid, -1.0, 0, 'not_primary', 8, 0.5],
            [$this->scaleid + 1000, -3.5, 1, '', 8, 0.5],
        ];
        foreach ($rows as [$scaleid, $value, $valid, $validationstatus, $n, $fraction]) {
            $DB->insert_record('local_catquiz_personparams', (object) [
                'attemptid' => $catattemptid, 'userid' => $this->user->id, 'contextid' => 9, 'catscaleid' => $scaleid,
                'ability' => $value, 'isprimary' => $valid, 'isvalid' => $valid, 'validationstatus' => $validationstatus,
                'n' => $n, 'standarderror' => 0.3, 'fraction' => $fraction,
                'resultsource' => 'current', 'rangelower' => -4, 'rangeupper' => 4,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }

        result_service::regrade($this->adaptivequiz, [(int) $attempt->id]);

        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]);
        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertEqualsWithDelta(-1.0, (float) $attempt->resultscore, 1e-9);
        $this->assertEqualsWithDelta(37.5, $this->grade(), 1e-3);

        // The same attempt with every answer of the global scale right: no grade, whatever the subscale.
        $global = ['attemptid' => $catattemptid, 'catscaleid' => $this->scaleid];
        $DB->set_field('local_catquiz_personparams', 'fraction', 1.0, $global);
        result_service::regrade($this->adaptivequiz, [(int) $attempt->id]);
        $this->assertSame('fraction_all_correct', $DB->get_field('adaptivequiz_attempt', 'resultreason', ['id' => $attempt->id]));
        $this->assertNull($this->grade());
    }

    /**
     * The global scale is the one the attempt was finalised on, not the one the test names today.
     */
    public function test_global_scale_of_the_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(-4, 4);
        $attempt = $this->complete($this->attempt(1.2));
        $catattemptid = (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $attempt->id]);
        $this->assertEquals($this->scaleid, $DB->get_field('local_catquiz_attempts', 'scaleid', ['id' => $catattemptid]));

        // The test is switched to another scale afterwards.
        $DB->set_field('local_catquiz_tests', 'catscaleid', $this->scaleid + 1000, ['componentid' => $this->adaptivequiz->id]);

        $result = (new catquiz_result_provider())->get_attempt_result($this->adaptivequiz, $attempt);
        $this->assertTrue($result->valid);
        $this->assertEqualsWithDelta(1.2, $result->score, 1e-4);
    }

    /**
     * The definition comes from the test's scale - also from the scale chosen in the form.
     */
    public function test_definition_is_the_scale_range(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(-4, 4);

        $definition = result_service::definition($this->adaptivequiz);
        $this->assertTrue($definition->is_gradable());
        $this->assertSame([-4.0, 4.0], [$definition->lower, $definition->upper]);
        $this->assertStringContainsString('Mathematics', $definition->label);

        $other = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0, 'name' => 'Other', 'minscalevalue' => 0, 'maxscalevalue' => 10,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $formdata = (object) ((array) $this->adaptivequiz + ['catquiz_catscales' => $other]);
        $definition = result_service::definition($formdata);
        $this->assertSame([0.0, 10.0], [$definition->lower, $definition->upper]);
    }

    /**
     * The adapter reports and nothing else: an attempt result is a plain contract value.
     */
    public function test_result_is_the_host_contract(): void {
        $this->resetAfterTest();
        $this->activity(-4, 4);

        $result = (new catquiz_result_provider())->get_attempt_result($this->adaptivequiz, (object) ['id' => -1]);

        $this->assertInstanceOf(attempt_result::class, $result);
        $this->assertFalse($result->valid);
    }
}
