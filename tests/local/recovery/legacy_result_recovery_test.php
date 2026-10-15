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

namespace adaptivequizcatmodel_catquiz\local\recovery;

use adaptivequizcatmodel_catquiz\local\catmodel\result\catquiz_result_provider;
use adaptivequizcatmodel_catquiz\task\recover_legacy_results;
use advanced_testcase;
use context_module;
use mod_adaptivequiz\local\result\result_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/local/catquiz/tests/fixtures/measured_progress.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/db/upgrade.php');

/**
 * Recovery of older CATquiz results for the gradebook (local_catquiz #137, concept of 2026-10-14).
 *
 * Each test builds an attempt as the finaliser leaves it today and then puts it into one of the states
 * found on the production site: snapshot without a valid result because the range was not recorded,
 * because no result was stored per attempt, or because CATquiz had not finalised the attempt when the
 * snapshot was taken.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(legacy_result_recovery::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recover_legacy_results::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_adaptivequizcatmodel_catquiz_upgrade')]
final class legacy_result_recovery_test extends advanced_testcase {
    /** @var int The cutoff of every run: 14 July 2026. */
    private const CUTOFF = 1783980000;

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass The participant. */
    private stdClass $user;

    /** @var int The global scale of the test, -4 to 4. */
    private int $scaleid;

    /** @var int A subscale. */
    private int $subscaleid;

    /** @var int Next question usage id. */
    private int $uniqueid = 7000;

    /**
     * A CATquiz activity on a global scale from -4 to 4 with one subscale.
     */
    private function activity(): void {
        global $DB;

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0, 'name' => 'Mathematics', 'minscalevalue' => -4, 'maxscalevalue' => 4,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->subscaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => $this->scaleid, 'name' => 'Algebra', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $this->course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
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
     * An attempt, completed and finalised as today, started at the given time.
     *
     * @param int $starttime
     * @param array $options global (ability), sub (ability), primary (scale id), n (answers per scale),
     *      minquestions, fraction.
     * @return stdClass The adaptivequiz_attempt.
     */
    private function attempt(int $starttime, array $options = []): stdClass {
        global $DB;

        $options += ['global' => 1.2, 'sub' => null, 'primary' => null, 'n' => 4, 'minquestions' => 0, 'fraction' => 0.5];
        $uniqueid = ++$this->uniqueid;
        $attempt = (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => $options['n'],
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 3.5,
            'timecreated' => $starttime, 'timemodified' => $starttime, 'timefinished' => $starttime + 600,
        ];
        $attempt->id = $DB->insert_record('adaptivequiz_attempt', $attempt);
        $abilities = [$this->scaleid => ['value' => $options['global'], 'toreport' => true]];
        $nbyscale = [$this->scaleid => $options['n']];
        if ($options['sub'] !== null) {
            $abilities[$this->subscaleid] = ['value' => $options['sub'], 'toreport' => true];
            $nbyscale[$this->subscaleid] = $options['n'];
        }
        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $this->user->id, 'scaleid' => $this->scaleid, 'contextid' => 9, 'attemptid' => $attempt->id,
            'component' => 'mod_adaptivequiz', 'courseid' => $this->course->id, 'instanceid' => $this->adaptivequiz->id,
            'status' => 0, 'number_of_testitems_used' => $options['n'], 'endtime' => null,
            'json' => json_encode([
                'personabilities_abilities' => $abilities,
                'se' => array_fill_keys(array_keys($abilities), 0.3),
                'primaryscale' => ['id' => $options['primary'] ?? $this->scaleid],
            ]),
            'timecreated' => $starttime, 'timemodified' => $starttime,
        ]);
        $this->setUser($this->user);
        local_catquiz_measured_progress(
            (int) $attempt->id,
            $nbyscale,
            9,
            ['maxquestionsgroup' => ['catquiz_minquestions' => $options['minquestions'], 'catquiz_maxquestions' => 25]],
            array_fill_keys(array_keys($nbyscale), $options['fraction'])
        );
        adaptivequiz_complete_attempt(
            (int) $attempt->uniqueid,
            $this->adaptivequiz,
            context_module::instance(get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id)->id),
            (int) $this->user->id,
            '0.3',
            'done'
        );
        $DB->set_field('local_catquiz_attempts', 'starttime', $starttime, ['attemptid' => $attempt->id]);
        return $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]);
    }

    /**
     * The CAT attempt of an attempt.
     *
     * @param stdClass $attempt
     * @return int
     */
    private function catattemptid(stdClass $attempt): int {
        global $DB;
        return (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $attempt->id]);
    }

    /**
     * Puts the attempt into a state found in production, and takes its snapshot in that state.
     *
     * @param stdClass $attempt
     * @param string $state range_not_recorded, no_stored_result, not_finalised, no_answers, no_cat_attempt.
     * @return stdClass The attempt afterwards.
     */
    private function legacy(stdClass $attempt, string $state): stdClass {
        global $DB;
        $catattemptid = $this->catattemptid($attempt);
        switch ($state) {
            case 'range_not_recorded':
                $DB->set_field('local_catquiz_personparams', 'rangelower', null, ['attemptid' => $catattemptid]);
                $DB->set_field('local_catquiz_personparams', 'rangeupper', null, ['attemptid' => $catattemptid]);
                break;
            case 'no_stored_result':
                $DB->delete_records('local_catquiz_personparams', ['attemptid' => $catattemptid]);
                break;
            case 'not_finalised':
                $DB->delete_records('local_catquiz_personparams', ['attemptid' => $catattemptid]);
                $DB->set_field('local_catquiz_attempts', 'endtime', null, ['id' => $catattemptid]);
                break;
            case 'no_answers':
                $DB->delete_records('local_catquiz_personparams', ['attemptid' => $catattemptid]);
                $DB->delete_records_list('local_catquiz_progress', 'attemptid', [$catattemptid, $attempt->id]);
                \cache::make('local_catquiz', 'adaptivequizattempt')->purge();
                break;
            case 'no_cat_attempt':
                $DB->delete_records('local_catquiz_personparams', ['attemptid' => $catattemptid]);
                $DB->delete_records('local_catquiz_attempts', ['id' => $catattemptid]);
                break;
        }
        result_service::regrade($this->adaptivequiz, [(int) $attempt->id]);
        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]);
        $this->assertEquals(0, $attempt->resultvalid, "The state $state has no valid snapshot.");
        return $attempt;
    }

    /**
     * The grade of the participant.
     *
     * @return float|null
     */
    private function grade(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'adaptivequiz', $this->adaptivequiz->id, $this->user->id);
        $grade = $grades->items[0]->grades[$this->user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * A recovery run.
     *
     * @param bool $execute
     * @return stdClass
     */
    private function run_recovery(bool $execute): stdClass {
        return (new legacy_result_recovery(self::CUTOFF, $execute))->run();
    }

    /**
     * Fixture 3: missing range, otherwise valid - today's range, marked, and the grade. The dry run
     * predicts what the run does and changes nothing; a second run changes nothing either.
     */
    public function test_missing_range_is_recovered_and_graded(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'range_not_recorded');
        $this->assertSame(catquiz_result_provider::REASON_RANGE_NOT_RECORDED, $attempt->resultreason);
        $before = $DB->get_records('local_catquiz_personparams', ['attemptid' => $this->catattemptid($attempt)]);

        $dry = $this->run_recovery(false);
        $this->assertSame(1, $dry->counts[legacy_result_recovery::RECOVERED_VALID]);
        $this->assertEqualsWithDelta(65.0, $dry->rows[$attempt->id]->resultpercent, 1e-6);
        $this->assertSame(legacy_result_recovery::RANGE_CURRENT, $dry->rows[$attempt->id]->rangesource);
        $after = $DB->get_records('local_catquiz_personparams', ['attemptid' => $this->catattemptid($attempt)]);
        $this->assertEquals($before, $after);
        $this->assertNull($this->grade(), 'A dry run changes nothing.');
        $this->assertSame(0, $DB->count_records('adaptivequiz_cat_recovery'));

        $run = $this->run_recovery(true);

        $this->assertSame(1, $run->graded);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
        $row = $DB->get_record('local_catquiz_personparams', [
            'attemptid' => $this->catattemptid($attempt), 'catscaleid' => $this->scaleid,
        ]);
        $this->assertEqualsWithDelta(-4.0, (float) $row->rangelower, 1e-9);
        $this->assertSame(1, (int) $row->rangebackfilled, 'The range is marked as taken later.');
        $protocol = $DB->get_record('adaptivequiz_cat_recovery', ['attemptid' => $attempt->id]);
        $this->assertSame(catquiz_result_provider::REASON_RANGE_NOT_RECORDED, $protocol->oldreason);
        $this->assertSame(legacy_result_recovery::RANGE_CURRENT, $protocol->rangesource);
        $this->assertSame('revalidated,range_from_current_scale,snapshot', $protocol->steps);
        $this->assertEquals(1, $protocol->valid);
        $this->assertEqualsWithDelta(65.0, (float) $protocol->resultpercent, 1e-3);

        $again = $this->run_recovery(true);
        $this->assertSame(0, $again->graded, 'Safe to repeat.');
        $this->assertSame(0, $DB->count_records('adaptivequiz_cat_recovery') - 1);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
    }

    /**
     * The dominant production case: finalised before CATquiz stored a result per attempt.
     */
    public function test_attempt_without_stored_result_is_judged_and_graded(): void {
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'no_stored_result');
        $this->assertSame(catquiz_result_provider::REASON_NO_RESULT, $attempt->resultreason);

        $run = $this->run_recovery(true);

        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $run->rows[$attempt->id]->class);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
    }

    /**
     * Fixture 4: the strategy reported a subscale first ("lowest skill gap") - graded on the global scale.
     */
    public function test_graded_on_the_global_scale_not_the_reported_one(): void {
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->attempt(self::CUTOFF + DAYSECS, ['global' => 2.0, 'sub' => -3.0, 'primary' => null]);
        // The strategy designated the subscale.
        global $DB;
        $catattemptid = $this->catattemptid($attempt);
        $json = json_decode($DB->get_field('local_catquiz_attempts', 'json', ['id' => $catattemptid]), true);
        $json['primaryscale'] = ['id' => $this->subscaleid];
        $DB->set_field('local_catquiz_attempts', 'json', json_encode($json), ['id' => $catattemptid]);
        $this->legacy($attempt, 'no_stored_result');

        $dry = $this->run_recovery(false);
        $run = $this->run_recovery(true);

        $this->assertEqualsWithDelta(75.0, $dry->rows[$attempt->id]->resultpercent, 1e-6);
        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $run->rows[$attempt->id]->class);
        $this->assertEqualsWithDelta(75.0, $this->grade(), 1e-3);
    }

    /**
     * Production regression 4/12: below the minimum - judged, invalid, no grade, and protocolled.
     */
    public function test_below_the_minimum_gives_no_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->legacy($this->attempt(self::CUTOFF + DAYSECS, ['n' => 4, 'minquestions' => 12]), 'no_stored_result');

        $dry = $this->run_recovery(false);
        $run = $this->run_recovery(true);

        foreach ([$dry, $run] as $report) {
            $this->assertSame(legacy_result_recovery::RECOVERED_INVALID, $report->rows[$attempt->id]->class);
            $this->assertStringContainsString('min_questions_not_reached', $report->rows[$attempt->id]->newreason);
        }
        $this->assertNull($this->grade());
        $this->assertEquals(0, $DB->get_field('adaptivequiz_cat_recovery', 'valid', ['attemptid' => $attempt->id]));
        $this->assertSame(0, $this->run_recovery(true)->counts[legacy_result_recovery::RECOVERED_INVALID], 'Not judged twice.');
    }

    /**
     * A global scale with every answer right stays invalid after recovery.
     */
    public function test_statistically_invalid_global_scale_gives_no_grade(): void {
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->legacy($this->attempt(self::CUTOFF + DAYSECS, ['fraction' => 1.0]), 'no_stored_result');

        $run = $this->run_recovery(true);

        $this->assertSame(legacy_result_recovery::RECOVERED_INVALID, $run->rows[$attempt->id]->class);
        $this->assertNull($this->grade());
    }

    /**
     * The cutoff: an attempt started at the cutoff is in the window, one a second earlier is not.
     */
    public function test_cutoff_by_start_time(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $at = $this->legacy($this->attempt(self::CUTOFF), 'range_not_recorded');
        $before = $this->legacy($this->attempt(self::CUTOFF - 1), 'range_not_recorded');
        $nostart = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'range_not_recorded');
        $DB->set_field('local_catquiz_attempts', 'starttime', null, ['attemptid' => $nostart->id]);

        $run = $this->run_recovery(true);

        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $run->rows[$at->id]->class);
        $this->assertSame(legacy_result_recovery::OUTSIDE_WINDOW, $run->rows[$before->id]->class);
        $this->assertSame(legacy_result_recovery::OUTSIDE_WINDOW, $run->rows[$nostart->id]->class);
        foreach ([$before, $nostart] as $attempt) {
            $this->assertSame(
                catquiz_result_provider::REASON_RANGE_NOT_RECORDED,
                $DB->get_field('adaptivequiz_attempt', 'resultreason', ['id' => $attempt->id]),
                'Outside the window nothing changes.'
            );
            $this->assertNull($DB->get_field('local_catquiz_personparams', 'rangelower', [
                'attemptid' => $this->catattemptid($attempt), 'catscaleid' => $this->scaleid,
            ]));
        }
    }

    /**
     * Fixtures 7 and 8, and no answers left: not recovered, not changed.
     *
     * @return array
     */
    public static function unrecoverable_states(): array {
        return [
            'not finalised' => ['not_finalised', legacy_result_recovery::NOT_FINALISED],
            'no stored answers' => ['no_answers', legacy_result_recovery::NOT_RECONSTRUCTABLE],
            'no CAT attempt' => ['no_cat_attempt', legacy_result_recovery::NO_CAT_ATTEMPT],
        ];
    }

    /**
     * An attempt whose end state cannot be told is left alone.
     *
     * @param string $state
     * @param string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unrecoverable_states')]
    public function test_unrecoverable_attempts_are_left_alone(string $state, string $class): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), $state);
        $snapshot = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]);

        $run = $this->run_recovery(true);

        $this->assertSame($class, $run->rows[$attempt->id]->class);
        $this->assertEquals($snapshot, $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]));
        $this->assertSame(0, $DB->count_records('adaptivequiz_cat_recovery'));
        $this->assertNull($this->grade());
    }

    /**
     * Not finalised when the snapshot was taken, finalised since: recovered (fixture 6).
     */
    public function test_attempt_finalised_after_its_snapshot_is_recovered(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->attempt(self::CUTOFF + DAYSECS);
        $catattemptid = $this->catattemptid($attempt);
        $endtime = $DB->get_field('local_catquiz_attempts', 'endtime', ['id' => $catattemptid]);
        $attempt = $this->legacy($attempt, 'not_finalised');
        $this->assertSame(catquiz_result_provider::REASON_NOT_FINALISED, $attempt->resultreason);
        $DB->set_field('local_catquiz_attempts', 'endtime', $endtime, ['id' => $catattemptid]);

        $run = $this->run_recovery(true);

        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $run->rows[$attempt->id]->class);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
    }

    /**
     * A valid snapshot is no candidate: regular results stay as they are.
     */
    public function test_valid_attempts_are_not_touched(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->attempt(self::CUTOFF + DAYSECS);
        $this->assertEquals(1, $attempt->resultvalid);

        $run = $this->run_recovery(true);

        $this->assertArrayNotHasKey($attempt->id, $run->rows);
        $this->assertEquals($attempt, $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]));
    }

    /**
     * Only the attempt asked for, and a CSV line per candidate.
     */
    public function test_single_attempt_and_csv(): void {
        $this->resetAfterTest();
        $this->activity();
        $first = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'range_not_recorded');
        $second = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'range_not_recorded');

        $report = (new legacy_result_recovery(self::CUTOFF, false, (int) $second->id))->run();

        $this->assertSame([(int) $second->id], array_keys($report->rows));
        $fields = array_combine(
            legacy_result_recovery::csv_columns(),
            legacy_result_recovery::csv_fields($report->rows[$second->id])
        );
        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $fields['class']);
        $this->assertSame('revalidated,range_from_current_scale', $fields['steps']);
        $this->assertNotEmpty($first->id);
    }

    /**
     * Gives the attempt a question usage with this many slots, as the host leaves it.
     *
     * @param stdClass $attempt
     * @param int $slots
     * @return void
     */
    private function slots(stdClass $attempt, int $slots): void {
        global $DB;
        $context = \context_course::instance($this->course->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $context->id]);
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        for ($i = 0; $i < $slots; $i++) {
            $quba->add_question(\question_bank::load_question(
                $generator->create_question('truefalse', null, ['category' => $category->id])->id
            ));
        }
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->set_field('adaptivequiz_attempt', 'uniqueid', $quba->get_id(), ['id' => $attempt->id]);
    }

    /**
     * Runs the queued ad-hoc tasks of a kind, without their output in the test log.
     *
     * @param string $class
     * @return string The output.
     */
    private function run_tasks(string $class): string {
        ob_start();
        $this->runAdhocTasks($class);
        return (string) ob_get_clean();
    }

    /**
     * Issue #8: the regular regrade of the activity keeps an invalid snapshot as it is - only the recovery
     * takes it again.
     */
    public function test_regular_regrade_keeps_the_invalid_snapshot(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'range_not_recorded');

        \mod_adaptivequiz\task\regrade_activity::queue((int) $this->adaptivequiz->id);
        $this->run_tasks(\mod_adaptivequiz\task\regrade_activity::class);

        $this->assertEquals($attempt, $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]));
        $this->assertNull($this->grade());

        $this->run_recovery(true);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
    }

    /**
     * Issue #8: neither a historic no_primary_scale nor the primary flags of the stored rows decide - graded is
     * the global scale.
     */
    public function test_primary_markings_do_not_gate_the_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->legacy($this->attempt(self::CUTOFF + DAYSECS, ['sub' => -3.0]), 'range_not_recorded');
        $catattemptid = $this->catattemptid($attempt);
        $DB->set_field('adaptivequiz_attempt', 'resultreason', catquiz_result_provider::REASON_NO_PRIMARY, ['id' => $attempt->id]);
        $rows = ['attemptid' => $catattemptid];
        $DB->set_field('local_catquiz_personparams', 'isprimary', 0, $rows + ['catscaleid' => $this->scaleid]);
        $DB->set_field('local_catquiz_personparams', 'isprimary', 1, $rows + ['catscaleid' => $this->subscaleid]);

        $run = $this->run_recovery(true);

        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $run->rows[$attempt->id]->class);
        $this->assertSame(catquiz_result_provider::REASON_NO_PRIMARY, $run->rows[$attempt->id]->oldreason);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3, 'The global scale (1.2 on -4..4), not the subscale.');
    }

    /**
     * Production regression 33/25: more items administered than the maximum - invalid, no grade, also in the
     * dry run.
     */
    public function test_more_items_than_the_maximum_gives_no_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->attempt(self::CUTOFF + DAYSECS, ['n' => 25]);
        $this->slots($attempt, 33);
        $attempt = $this->legacy($attempt, 'no_stored_result');

        $dry = $this->run_recovery(false);
        $run = $this->run_recovery(true);

        foreach ([$dry, $run] as $report) {
            $this->assertSame(legacy_result_recovery::RECOVERED_INVALID, $report->rows[$attempt->id]->class);
            $this->assertStringContainsString('max_questions_exceeded', $report->rows[$attempt->id]->newreason);
        }
        $this->assertNull($this->grade());
        $this->assertNull($DB->get_field('adaptivequiz_attempt', 'resultpercent', ['id' => $attempt->id]));
    }

    /**
     * The maximum itself is a regular end: 25 slots at a maximum of 25 are graded.
     */
    public function test_items_at_the_maximum_are_graded(): void {
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->attempt(self::CUTOFF + DAYSECS, ['n' => 25]);
        $this->slots($attempt, 25);
        $attempt = $this->legacy($attempt, 'no_stored_result');

        $run = $this->run_recovery(true);

        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $run->rows[$attempt->id]->class);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
    }

    /**
     * An error with one activity is reported for its attempts; the others are graded, and a later run tries
     * the failed ones again.
     */
    public function test_an_error_does_not_stop_the_others(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->activity();
        $broken = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'range_not_recorded');
        $brokenactivity = $this->adaptivequiz;
        $this->activity();
        $sound = $this->legacy($this->attempt(self::CUTOFF + DAYSECS), 'range_not_recorded');
        // The grade item of the first activity cannot be written: its course is gone.
        $course = $DB->get_record('course', ['id' => $brokenactivity->course]);
        $DB->delete_records('course', ['id' => $course->id]);

        $run = $this->run_recovery(true);

        $this->assertSame(legacy_result_recovery::FAILED, $run->rows[$broken->id]->class);
        $this->assertNotEmpty($run->rows[$broken->id]->newreason);
        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $run->rows[$sound->id]->class);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
        $this->assertFalse($DB->record_exists('adaptivequiz_cat_recovery', ['attemptid' => $broken->id]));
        $this->assertNull(
            $DB->get_field('adaptivequiz_attempt', 'resultpercent', ['id' => $broken->id]),
            'Nothing of the failed grading is kept.'
        );
        $this->assertFalse($DB->is_transaction_started(), 'No transaction is left open.');

        $DB->import_record('course', $course);
        $again = $this->run_recovery(true);
        $this->assertSame(legacy_result_recovery::RECOVERED_VALID, $again->rows[$broken->id]->class);
        $this->assertArrayNotHasKey($sound->id, $again->rows, 'Graded once: no candidate any more.');
        $this->assertSame(1, $DB->count_records('adaptivequiz_cat_recovery', ['attemptid' => $sound->id]));
    }

    /**
     * Issue #8, end to end: the upgrade queues the migration once; the task recovers a mixed stock - valid,
     * 4/12, 33/25, not finalised, before the window - by the same classes as the dry run of the CLI, and the
     * gradebook gets the valid one only. A second run of the task changes nothing.
     */
    public function test_upgrade_queues_the_migration_once_and_it_recovers_the_stock(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        // 00:00 in the server's time zone; with the site in Germany that is the cutoff of every test here.
        $this->setTimezone('Europe/Berlin');
        $cutoff = legacy_result_recovery::cutoff(legacy_result_recovery::MIGRATION_SINCE);
        $this->assertSame(self::CUTOFF, $cutoff, 'The window of the migration is fixed.');
        $valid = $this->legacy($this->attempt($cutoff + DAYSECS), 'range_not_recorded');
        $belowmin = $this->legacy($this->attempt($cutoff + DAYSECS, ['n' => 4, 'minquestions' => 12]), 'no_stored_result');
        $abovemax = $this->attempt($cutoff + DAYSECS, ['n' => 25]);
        $this->slots($abovemax, 33);
        $abovemax = $this->legacy($abovemax, 'no_stored_result');
        $notfinalised = $this->legacy($this->attempt($cutoff + DAYSECS), 'not_finalised');
        $before = $this->legacy($this->attempt($cutoff - 1), 'range_not_recorded');
        $expected = [
            $valid->id => legacy_result_recovery::RECOVERED_VALID,
            $belowmin->id => legacy_result_recovery::RECOVERED_INVALID,
            $abovemax->id => legacy_result_recovery::RECOVERED_INVALID,
            $notfinalised->id => legacy_result_recovery::NOT_FINALISED,
            $before->id => legacy_result_recovery::OUTSIDE_WINDOW,
        ];
        $dry = (new legacy_result_recovery($cutoff, false))->run();
        $this->assertEquals($expected, array_map(fn($row) => $row->class, $dry->rows), 'The dry run of the CLI.');

        // The upgrade step, as from 2026100905.
        global $CFG;
        set_config('version', 2026100905, 'adaptivequizcatmodel_catquiz');
        $this->assertTrue(xmldb_adaptivequizcatmodel_catquiz_upgrade(2026100905));
        $this->assertEquals(2026100906, get_config('adaptivequizcatmodel_catquiz', 'version'));
        // The savepoint marks the site as upgrading; the upgrade is over before cron runs the task.
        unset_config('upgraderunning');
        unset($CFG->upgraderunning);
        $queued = \core\task\manager::get_adhoc_tasks(recover_legacy_results::class);
        $this->assertCount(1, $queued, 'The upgrade queued the migration.');
        recover_legacy_results::queue_migration();
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(recover_legacy_results::class), 'Queued once.');
        $this->assertSame($cutoff, (int) reset($queued)->get_custom_data()->cutoff);
        $this->assertNull($this->grade(), 'The upgrade itself grades nothing.');

        $output = $this->run_tasks(recover_legacy_results::class);

        $this->assertStringContainsString('recovered_valid', $output);
        foreach ($expected as $attemptid => $class) {
            $protocolled = $DB->get_record('adaptivequiz_cat_recovery', ['attemptid' => $attemptid]);
            if ($class === legacy_result_recovery::RECOVERED_VALID || $class === legacy_result_recovery::RECOVERED_INVALID) {
                $this->assertNotFalse($protocolled, "Attempt $attemptid is protocolled.");
                $this->assertEquals($class === legacy_result_recovery::RECOVERED_VALID ? 1 : 0, $protocolled->valid);
                $this->assertEquals($cutoff, $protocolled->cutoff);
            } else {
                $this->assertFalse($protocolled, "Attempt $attemptid is left alone.");
            }
        }
        $protocol = $DB->get_record('adaptivequiz_cat_recovery', ['attemptid' => $valid->id]);
        $this->assertSame(legacy_result_recovery::RANGE_CURRENT, $protocol->rangesource);
        $this->assertEquals($this->scaleid, $protocol->globalscaleid);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3, 'Last attempt with a valid result: the valid one.');
        foreach ([$belowmin, $abovemax, $notfinalised, $before] as $attempt) {
            $this->assertNull($DB->get_field('adaptivequiz_attempt', 'resultpercent', ['id' => $attempt->id]));
        }

        // Once more - a retry, say: nothing changes.
        $protocols = $DB->get_records('adaptivequiz_cat_recovery', null, 'id');
        recover_legacy_results::queue_migration();
        $this->run_tasks(recover_legacy_results::class);
        $this->assertEquals($protocols, $DB->get_records('adaptivequiz_cat_recovery', null, 'id'));
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-3);
    }
}
