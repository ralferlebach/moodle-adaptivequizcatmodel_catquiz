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
use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use advanced_testcase;
use backup;
use backup_controller;
use completion_info;
use mod_adaptivequiz\local\result\result_service;
use mod_adaptivequiz\task\regrade_activity;
use restore_controller;
use restore_dbops;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * The six link functions of mod_adaptivequiz #25 on a real CATquiz attempt with the calibrated simulation battery.
 *
 * Nothing is mocked: one attempt is played through host, adapter, engine and question usage (the LOWESTSUB replay of
 * local_catquiz #172, 16 questions, a valid result). Its frozen result - score, range - is then graded with each link
 * function; every percentage is checked against an independent closed form (Fisher-Rao: a fixed-step Simpson rule over
 * the test information of the battery as CATquiz calibrates it now), and the gradebook, the value the LTI Advantage
 * grade service reads (grade_get_grades()), the result snapshot and the completion with it.
 *
 * Golden cases beside the six functions: grading switched off (GRADE_TYPE_NONE: no grade - never 0 -, the measurement
 * stays valid, completion "valid result" stays met), the option not to regrade (no task, grade and snapshot unchanged)
 * and its counterpart, and a backup with user data restored into a new course (settings, snapshot and grade alike).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class link_functions_calibrated_test extends advanced_testcase {
    /** @var string The answers of the replay (1 right, 0 wrong); it ends after 16 with a valid result. */
    private const ANSWERS = '1101101101101101101101101101101101101101';

    /** @var float Steepness k of the logistic functions - not the default 1, so that it is seen to be used. */
    private const STEEPNESS = 1.7;

    /**
     * Plays one attempt through host, adapter and engine to its regular end.
     *
     * @return array [trace_scenario, course, the host's attempt]
     */
    private function play(): array {
        global $CFG, $DB;
        $scenario = trace_scenario::create($this->getDataGenerator(), true, [
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
            'catquiz_minquestions' => 12,
            'catquiz_maxquestions' => 25,
            'catquiz_minquestionspersubscale' => 3,
            'catquiz_maxquestionspersubscale' => 5,
            'catquiz_standarderror_min' => 0.0001,
            'catquiz_standarderror_max' => 1.0,
            'catquiz_includetimelimit' => 0,
        ], 25);
        $test = $DB->get_record('local_catquiz_tests', [
            'componentid' => $scenario->adaptivequiz->id, 'component' => 'mod_adaptivequiz',
        ], '*', MUST_EXIST);
        $json = json_decode($test->json);
        $names = ['Simulation', 'SimB', 'SimB01', 'SimB02', 'SimB03', 'SimB04'];
        foreach ($DB->get_records('local_catquiz_catscales', null, 'id', 'id, name') as $scale) {
            $json->{'catquiz_subscalecheckbox_' . $scale->id} = in_array($scale->name, $names, true);
        }
        $DB->set_field('local_catquiz_tests', 'json', json_encode($json), ['id' => $test->id]);
        \cache_helper::purge_all();
        $DB->set_field('adaptivequiz', 'minimumquestions', 0, ['id' => $scenario->adaptivequiz->id]);
        $scenario->adaptivequiz->minimumquestions = 0;

        // Completion on the course, the rule "valid result" on the activity.
        $CFG->enablecompletion = 1;
        $courseid = (int) $scenario->adaptivequiz->course;
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $courseid]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $scenario->adaptivequiz->cm->id]);
        $DB->set_field('adaptivequiz', 'completionvalidresult', 1, ['id' => $scenario->adaptivequiz->id]);
        $scenario->adaptivequiz->completionvalidresult = 1;
        rebuild_course_cache($courseid, true);

        $this->setUser($scenario->userid);
        $shown = request_simulator::request($scenario->adaptivequiz, $scenario->context, $scenario->userid, null);
        for ($i = 0; !$shown['stopped'] && $i < strlen(self::ANSWERS); $i++) {
            $shown = request_simulator::request(
                $scenario->adaptivequiz,
                $scenario->context,
                $scenario->userid,
                self::ANSWERS[$i] === '1'
            );
        }
        $this->assertTrue($shown['stopped'], 'The attempt ends.');
        // The colour bar of a subscale without feedback ranges is left out with a developer notice - not the subject.
        $this->resetDebugging();
        $this->setAdminUser();
        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $attempt->resultvalid, 'A valid result: ' . $attempt->resultreason);
        $this->assertSame('linear', $attempt->resultlink, 'Graded with the default link function.');
        return [$scenario, get_course($courseid), $attempt];
    }

    /**
     * Integral of sqrt(I) with a fixed-step Simpson rule - independent of the adaptive quadrature of the host.
     *
     * @param \Closure $info
     * @param float $a
     * @param float $b
     * @return float
     */
    private static function simpson(\Closure $info, float $a, float $b): float {
        $n = 4000;
        $h = ($b - $a) / $n;
        $sum = sqrt($info($a)) + sqrt($info($b));
        for ($i = 1; $i < $n; $i++) {
            $sum += ($i % 2 ? 4 : 2) * sqrt($info($a + $i * $h));
        }
        return $sum * $h / 3;
    }

    /**
     * The percentage of a link function, written out here from its definition (#25), not taken from the host.
     *
     * @param string $link
     * @param float $score
     * @param float $l
     * @param float $u
     * @param float $p
     * @param float $k
     * @param \Closure $info The test information of the battery.
     * @return float
     */
    private static function expected(string $link, float $score, float $l, float $u, float $p, float $k, \Closure $info): float {
        switch ($link) {
            case 'linear':
                return 100 * ($score - $l) / ($u - $l);
            case 'piecewise_pass50':
                return $score <= $p ? 50 * ($score - $l) / ($p - $l) : 50 + 50 * ($score - $p) / ($u - $p);
            case 'logistic_mid':
                return 100 / (1 + exp(-$k * ($score - ($l + $u) / 2)));
            case 'logistic_pass':
                return 100 / (1 + exp(-$k * ($score - $p)));
            case 'guttman_pass':
                return $score >= $p ? 100.0 : 0.0;
            case 'fisher_rao':
                return 100 * self::simpson($info, $l, $score) / self::simpson($info, $l, $u);
        }
        throw new \coding_exception('unknown link function ' . $link);
    }

    /**
     * The grade of the participant as the gradebook API gives it - what the LTI Advantage grade service sends.
     *
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @return array [grade or null, grademax or null]
     */
    private static function api_grade(stdClass $adaptivequiz, int $userid): array {
        $grades = grade_get_grades($adaptivequiz->course, 'mod', 'adaptivequiz', $adaptivequiz->id, $userid);
        if (empty($grades->items)) {
            return [null, null];
        }
        $item = $grades->items[0];
        $grade = $item->grades[$userid]->grade ?? null;
        return [$grade === null ? null : (float) $grade, (float) $item->grademax];
    }

    /**
     * The completion state of the participant.
     *
     * @param stdClass $course
     * @param int $cmid
     * @param int $userid
     * @return int
     */
    private static function completion(stdClass $course, int $cmid, int $userid): int {
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        return (int) (new completion_info($course))->get_data($cm, false, $userid)->completionstate;
    }

    /**
     * Sets fields of the activity and returns its record.
     *
     * @param int $id
     * @param array $fields
     * @return stdClass
     */
    private static function set(int $id, array $fields): stdClass {
        global $DB;
        foreach ($fields as $field => $value) {
            $DB->set_field('adaptivequiz', $field, $value, ['id' => $id]);
        }
        return $DB->get_record('adaptivequiz', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * All six link functions on the frozen result of the real attempt; then no grading, and the option not to regrade.
     */
    public function test_the_six_link_functions_on_a_calibrated_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$scenario, $course, $attempt] = $this->play();
        $quizid = (int) $scenario->adaptivequiz->id;
        $cmid = (int) $scenario->adaptivequiz->cm->id;
        $userid = (int) $scenario->userid;

        $score = (float) $attempt->resultscore;
        [$l, $u] = [(float) $attempt->resultlower, (float) $attempt->resultupper];
        $this->assertTrue($l < $score && $score < $u, "The score $score lies within [$l, $u].");
        $info = (new catquiz_result_provider())->test_information($DB->get_record('adaptivequiz', ['id' => $quizid]));
        $this->assertNotNull($info, 'The calibrated battery has its test information.');
        // A pass score below the score and one above it: each function on both sides of P.
        $passes = [round($score - 0.4, 2), round($score + 0.3, 2)];

        $links = ['linear', 'piecewise_pass50', 'logistic_mid', 'logistic_pass', 'guttman_pass', 'fisher_rao'];
        $seen = [];
        foreach ($passes as $p) {
            $this->assertTrue($l < $p && $p < $u);
            foreach ($links as $link) {
                $record = self::set($quizid, ['linkfunction' => $link, 'passscore' => $p, 'linksteepness' => self::STEEPNESS]);
                $this->assertNull(result_service::link_settings_error($record), "$link: settings accepted");
                result_service::regrade($record, [], true);
                $row = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
                $expected = self::expected($link, $score, $l, $u, $p, self::STEEPNESS, $info);
                $label = "$link, P = $p, score $score";

                $this->assertSame($link, $row->resultlink, $label);
                $this->assertSame(1, (int) $row->resultvalid, $label);
                $this->assertEqualsWithDelta($score, (float) $row->resultscore, 1e-12, "$label: the score is not touched");
                // Stored with five decimals.
                $this->assertEqualsWithDelta($expected, (float) $row->resultpercent, 1e-5, $label);
                [$grade, $grademax] = self::api_grade($record, $userid);
                $this->assertNotNull($grade, $label);
                $this->assertEqualsWithDelta($expected * $grademax / 100, $grade, 1e-4, "$label: gradebook");
                $this->assertSame(COMPLETION_COMPLETE, self::completion($course, $cmid, $userid), "$label: completion");
                $seen[$link][] = round($expected, 6);
            }
        }
        // Each function graded both sides of P - the functions with a pass score differ there, the others do not.
        foreach (['piecewise_pass50', 'logistic_pass', 'guttman_pass'] as $link) {
            $this->assertNotEquals($seen[$link][0], $seen[$link][1], "$link depends on P");
        }
        foreach (['linear', 'logistic_mid', 'fisher_rao'] as $link) {
            $this->assertSame($seen[$link][0], $seen[$link][1], "$link does not depend on P");
        }
        $this->assertSame([100.0, 0.0], [(float) $seen['guttman_pass'][0], (float) $seen['guttman_pass'][1]]);

        // No grading (GRADE_TYPE_NONE): no grade - not 0 -, the measurement stays valid, completion stays met.
        $record = self::set($quizid, ['gradingmode' => result_service::GRADING_OFF]);
        result_service::regrade($record, [], true);
        $row = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->resultvalid);
        $this->assertSame(result_service::REASON_GRADING_OFF, $row->resultreason);
        $this->assertNull($row->resultpercent);
        $this->assertEqualsWithDelta($score, (float) $row->resultscore, 1e-12);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $quizid,
            'courseid' => $course->id, 'itemnumber' => 0]);
        $this->assertTrue(!$item || (int) $item->gradetype === GRADE_TYPE_NONE, 'No grade item with a value.');
        $this->assertNull(self::api_grade($record, $userid)[0], 'No grade, not 0.');
        $this->assertSame(COMPLETION_COMPLETE, self::completion($course, $cmid, $userid));

        // Grading on again: the stored score is graded again, with the activity's function.
        $record = self::set($quizid, ['gradingmode' => result_service::GRADING_ON, 'linkfunction' => 'linear']);
        result_service::regrade($record, [], true);
        [$grade, $grademax] = self::api_grade($record, $userid);
        $this->assertEqualsWithDelta(self::expected('linear', $score, $l, $u, 0, 1, $info) * $grademax / 100, $grade, 1e-4);

        // The option not to regrade: a changed link function leaves grade and snapshot as they are, no task is queued.
        $before = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
        $record = self::set($quizid, ['keepgrades' => 1]);
        $record->instance = $quizid;
        $record->coursemodule = $cmid;
        $record->linkfunction = 'guttman_pass';
        $record->passscore = $passes[1];
        adaptivequiz_update_instance($record);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(regrade_activity::class));
        $after = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
        foreach (['resultlink', 'resultpercent', 'resultscore', 'resultvalid', 'resulttime'] as $field) {
            $this->assertEquals($before->$field, $after->$field, "keepgrades: $field");
        }
        $this->assertEqualsWithDelta($grade, self::api_grade($record, $userid)[0], 1e-9);

        // Its counterpart: without it the regrade is queued and applies the new function.
        $record = self::set($quizid, ['keepgrades' => 0]);
        $record->instance = $quizid;
        $record->coursemodule = $cmid;
        $record->linkfunction = 'guttman_pass';
        $record->passscore = $passes[0];
        adaptivequiz_update_instance($record);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(regrade_activity::class));
        $this->expectOutputRegex('/1 grade\(s\) computed again/');
        $this->runAdhocTasks(regrade_activity::class);
        $this->assertSame('guttman_pass', $DB->get_field('adaptivequiz_attempt', 'resultlink', ['id' => $attempt->id]));
        $this->assertEqualsWithDelta($grademax, self::api_grade($record, $userid)[0], 1e-4, 'Above P: 100 %.');
    }

    /**
     * A backup with user data restored into a new course: link settings, the frozen snapshot and the grade alike.
     */
    public function test_backup_and_restore_keep_link_and_snapshot(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$scenario, $course, $attempt] = $this->play();
        $quizid = (int) $scenario->adaptivequiz->id;
        $p = round((float) $attempt->resultscore - 0.4, 2);
        $record = self::set($quizid, ['linkfunction' => 'logistic_pass', 'passscore' => $p,
            'linksteepness' => self::STEEPNESS, 'keepgrades' => 1]);
        result_service::regrade($record, [], true);
        $source = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
        [$grade] = self::api_grade($record, (int) $scenario->userid);

        $CFG->backup_file_logger_level = backup::LOG_NONE;
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $folder = 'restore_' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $folder);
        $newcourseid = restore_dbops::create_new_course('Restored', 'R', \core_course_category::get_default()->id);
        $rc = new restore_controller(
            $folder,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $restored = $DB->get_record('adaptivequiz', ['course' => $newcourseid], '*', MUST_EXIST);
        foreach (['linkfunction', 'passscore', 'linksteepness', 'keepgrades', 'gradingmode', 'catmodel'] as $field) {
            $this->assertEquals($record->$field, $restored->$field, "setting $field");
        }
        $copy = $DB->get_record('adaptivequiz_attempt', ['instance' => $restored->id], '*', MUST_EXIST);
        foreach (
            ['resultstatus', 'resultvalid', 'resultscore', 'resultlower', 'resultupper', 'resultpercent', 'resultlink',
                'resultlinkversion', 'resulttime', 'stopcode'] as $field
        ) {
            $this->assertEquals($source->$field, $copy->$field, "snapshot $field");
        }
        $this->assertEqualsWithDelta($grade, self::api_grade($restored, (int) $copy->userid)[0], 1e-4, 'The grade.');
    }
}
