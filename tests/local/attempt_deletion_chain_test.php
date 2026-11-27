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

namespace adaptivequizcatmodel_catquiz\local;

use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use adaptivequizcatmodel_catquiz\trace_scenario;
use advanced_testcase;
use context_module;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The real deletion paths with two running attempts whose ids cross (issue #11).
 *
 * Activity A and activity B of one course each have an attempt in progress: the first question answered, the
 * second shown - real attempts through host, adapter and CATquiz, with question usage, CAT attempt, progress and
 * trace. Then their CAT attempts are renumbered so that the numbers cross: A's CAT attempt has the number of B's
 * activity attempt and B's CAT attempt the number of A's. Deleting by the wrong number now hits the other one.
 * A third activity C in another course holds a finished attempt with a grade.
 *
 * Every path - deleting the activity (course_delete_module), the course, one attempt, the course reset - must
 * remove A completely, leave B and C exactly as they were, create no new orphan, and B must go on to its next
 * question afterwards. A failure in any step of the deletion leaves everything as it was, and the same deletion
 * run again completes.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\attempt_cleanup
 * @covers \adaptivequizcatmodel_catquiz\local\cleanup_failed_exception
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\instance\instance_actions_handler
 * @covers ::adaptivequizcatmodel_catquiz_post_delete_attempt_callback
 */
final class attempt_deletion_chain_test extends advanced_testcase {
    /** @var trace_scenario Course, pool, activity A and its participant. */
    private trace_scenario $scenario;

    /** @var array name => [adaptivequiz, context, cm, userid] for A, B and C. */
    private array $act = [];

    /** @var int A pre-#101 progress row, keyed by the activity's attempt id: history, never deleted by number. */
    private int $legacyprogress;

    /** @var int An ability of A's person outside any attempt: not A's attempt data. */
    private int $freeability;

    /**
     * A and B with crossed ids, C in another course, and rows that must stay.
     *
     * @param string $component How the CAT attempts name the activity (both spellings occur in production).
     * @param bool $instanceset Whether the CAT attempts name their activity - a running one does not yet, a finished
     *      one does; the old deletion of the activity found its rows by it.
     * @param string $ids crossed (A's CAT id = B's activity attempt id and the other way round), same (A's CAT id =
     *      A's own activity attempt id) or apart (no CAT id is an activity attempt id).
     */
    private function build(string $component = 'mod_adaptivequiz', bool $instanceset = false, string $ids = 'crossed'): void {
        global $DB;
        $this->resetAfterTest();
        // The deletions are transactions under test: no test transaction around them (PostgreSQL).
        $this->preventResetByRollback();
        // The feedback of the attempts is not what this test is about.
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK=true');
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->scenario = trace_scenario::create($generator);
        $course = get_course($this->scenario->adaptivequiz->course);
        $this->act['A'] = [$this->scenario->adaptivequiz, $this->scenario->context, $this->scenario->userid];

        // B: a second activity of the course on the same scale, another participant.
        $userb = $generator->create_user();
        $generator->enrol_user($userb->id, $course->id, 'student');
        $instance = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'minimumquestions' => 1, 'maximumquestions' => 50,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $root = $DB->get_record('local_catquiz_catscales', ['parentid' => 0], '*', MUST_EXIST);
        $generator->get_plugin_generator('local_catquiz')->create_catquiz_testsettings([
            'courseid' => $course->id, 'adaptivecatquizid' => $instance->id, 'catscalesid' => $root->id,
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB, 'catmodel' => 'catquiz',
            'catquiz_maxquestions' => 4, 'catquiz_standarderror_min' => 0.4, 'catquiz_standarderror_max' => 0.6,
            'numberoffeedbackoptions' => 2,
        ]);
        $b = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaptivequiz', $b->id, $course->id, false, MUST_EXIST);
        $b->context = context_module::instance($cm->id);
        $b->cm = $cm;
        $this->act['B'] = [$b, $b->context, (int) $userb->id];

        // Both attempts in progress: the first question answered, the second shown.
        $this->play('A', [null, true]);
        $this->play('B', [null, false]);
        foreach (['A', 'B'] as $name) {
            $DB->set_field('local_catquiz_attempts', 'component', $component, ['id' => $this->cat($name)]);
            if ($instanceset) {
                $DB->set_field('local_catquiz_attempts', 'instanceid', $this->act[$name][0]->id, ['id' => $this->cat($name)]);
            }
        }
        switch ($ids) {
            case 'crossed':
                $this->cross();
                break;
            case 'same':
                $this->same_ids();
                break;
            case 'apart':
                $this->ids_apart();
                break;
        }

        // C: another course, a finished attempt with CATquiz data and a grade.
        $this->act['C'] = $this->finished_activity_elsewhere();

        // Rows that are not A's attempt data and stay with every deletion.
        $hosta = $this->host('A');
        $this->legacyprogress = (int) $DB->insert_record('local_catquiz_progress', (object) [
            'attemptid' => 970000 + $hosta, 'userid' => $this->act['A'][2], 'component' => 'mod_adaptivequiz',
            'json' => '{"legacy":true}', 'timemodified' => time() - 400 * DAYSECS,
        ]);
        $this->freeability = (int) $DB->insert_record('local_catquiz_personparams', (object) [
            'attemptid' => null, 'userid' => $this->act['A'][2], 'catscaleid' => $root->id, 'contextid' => 1,
            'ability' => 0.25, 'isprimary' => 1, 'isvalid' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        \cache_helper::purge_all();
    }

    /**
     * Ends skipping the feedback.
     */
    protected function tearDown(): void {
        putenv('CATQUIZ_TESTING_SKIP_FEEDBACK');
        attempt_cleanup::simulate_fault(null);
        parent::tearDown();
    }

    /**
     * Requests of an attempt, as its participant.
     *
     * @param string $name A or B.
     * @param array $answers null for the first request, then true/false for the answer to the pending item.
     * @return array The result of the last request.
     */
    private function play(string $name, array $answers): array {
        [$adaptivequiz, $context, $userid] = $this->act[$name];
        $this->setUser($userid);
        $result = [];
        foreach ($answers as $right) {
            $result = request_simulator::request($adaptivequiz, $context, $userid, $right);
        }
        $this->setAdminUser();
        return $result;
    }

    /**
     * The id of the activity's attempt.
     *
     * @param string $name
     * @return int
     */
    private function host(string $name): int {
        global $DB;
        [$adaptivequiz, , $userid] = $this->act[$name];
        return (int) $DB->get_field('adaptivequiz_attempt', 'MAX(id)', ['instance' => $adaptivequiz->id, 'userid' => $userid]);
    }

    /**
     * The id of the CAT attempt of the activity's attempt.
     *
     * @param string $name
     * @return int
     */
    private function cat(string $name): int {
        global $DB;
        [$compsql, $params] = $DB->get_in_or_equal(attempt_cleanup::COMPONENTS);
        return (int) $DB->get_field_select(
            'local_catquiz_attempts',
            'MAX(id)',
            "attemptid = ? AND component $compsql",
            array_merge([$this->host($name)], $params)
        );
    }

    /**
     * Renumbers the CAT attempts of A and B: A's gets the number of B's activity attempt and B's that of A's.
     */
    private function cross(): void {
        global $DB;
        $hosta = $this->host('A');
        $hostb = $this->host('B');
        $cata = $this->cat('A');
        $catb = $this->cat('B');
        $this->assertNotSame($hosta, $hostb);
        $this->assertTrue($cata > 0 && $catb > 0);
        $free = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_catquiz_attempts}') + 1000;
        $this->renumber($cata, $free);
        $this->renumber($catb, $free + 1);
        $this->renumber($free, $hostb);
        $this->renumber($free + 1, $hosta);
        $this->assertSame($hostb, $this->cat('A'), "A's CAT attempt has the number of B's activity attempt");
        $this->assertSame($hosta, $this->cat('B'), "B's CAT attempt has the number of A's activity attempt");
        \cache_helper::purge_all();
    }

    /**
     * A's CAT attempt gets the number of A's own activity attempt (same number in both id spaces); B's is moved
     * out of the way first if it holds that number.
     */
    private function same_ids(): void {
        global $DB;
        $hosta = $this->host('A');
        $free = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_catquiz_attempts}') + 1000;
        if ($this->cat('B') === $hosta) {
            $this->renumber($hosta, $free + 1);
        }
        if ($this->cat('A') !== $hosta) {
            $this->renumber($this->cat('A'), $hosta);
        }
        $this->assertSame($hosta, $this->cat('A'));
        \cache_helper::purge_all();
    }

    /**
     * The CAT attempts of A and B get numbers that no activity attempt has.
     */
    private function ids_apart(): void {
        global $DB;
        $free = max(
            (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_catquiz_attempts}'),
            (int) $DB->get_field_sql('SELECT MAX(id) FROM {adaptivequiz_attempt}')
        ) + 5000;
        $this->renumber($this->cat('A'), $free);
        $this->renumber($this->cat('B'), $free + 1);
        foreach (['A', 'B'] as $name) {
            $this->assertFalse($DB->record_exists('adaptivequiz_attempt', ['id' => $this->cat($name)]));
        }
        \cache_helper::purge_all();
    }

    /**
     * Everything of a snapshot as one string: every field of every row, so that equality means equal values, not
     * only equal counts (re-audit 17.11.2026: "byte-/wertgleich").
     *
     * @param array $snapshot
     * @return string
     */
    private static function fingerprint(array $snapshot): string {
        return json_encode($snapshot, JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Gives a CAT attempt another id, with everything that references it.
     *
     * @param int $from
     * @param int $to
     */
    private function renumber(int $from, int $to): void {
        global $DB;
        $DB->execute('UPDATE {local_catquiz_attempts} SET id = ? WHERE id = ?', [$to, $from]);
        $DB->execute('UPDATE {local_catquiz_progress} SET attemptid = ? WHERE attemptid = ?', [$to, $from]);
        if ($DB->get_manager()->table_exists('local_catquiz_progress_steps')) {
            // Local_catquiz #177: the events of the working state belong to the CAT attempt as well.
            $DB->execute('UPDATE {local_catquiz_progress_steps} SET catattemptid = ? WHERE catattemptid = ?', [$to, $from]);
        }
        $DB->execute('UPDATE {local_catquiz_personparams} SET attemptid = ? WHERE attemptid = ?', [$to, $from]);
    }

    /**
     * An activity in another course with a finished attempt, its CATquiz data and a grade.
     *
     * @return array [adaptivequiz, context, userid]
     */
    private function finished_activity_elsewhere(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_and_enrol($course, 'student');
        $instance = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $DB->set_field('adaptivequiz', 'catmodel', 'catquiz', ['id' => $instance->id]);
        $hostid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $instance->id, 'userid' => $user->id, 'uniqueid' => 0, 'attemptstate' => 'complete',
            'attemptstopcriteria' => '', 'questionsattempted' => 3, 'difficultysum' => 0, 'standarderror' => 0,
            'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $catid = 960000 + $hostid;
        $DB->import_record('local_catquiz_attempts', (object) [
            'id' => $catid, 'userid' => $user->id, 'attemptid' => $hostid, 'component' => 'mod_adaptivequiz',
            'instanceid' => $instance->id, 'courseid' => $course->id, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_catquiz_progress', (object) [
            'attemptid' => $catid, 'userid' => $user->id, 'component' => 'mod_adaptivequiz', 'json' => '{}',
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_catquiz_personparams', (object) [
            'attemptid' => $catid, 'userid' => $user->id, 'catscaleid' => 1, 'contextid' => 1, 'ability' => 1.5,
            'isprimary' => 1, 'isvalid' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
        $adaptivequiz->cmidnumber = '';
        grade_update(
            'mod/adaptivequiz',
            $course->id,
            'mod',
            'adaptivequiz',
            $instance->id,
            0,
            ['userid' => $user->id, 'rawgrade' => 7.5]
        );
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id, $course->id, false, MUST_EXIST);
        return [$adaptivequiz, context_module::instance($cm->id), (int) $user->id];
    }

    /**
     * Everything that belongs to the attempt of an activity, as rows - to compare before and after.
     *
     * @param string $name
     * @return array
     */
    private function snapshot(string $name): array {
        global $DB;
        [$adaptivequiz, , $userid] = $this->act[$name];
        $host = $DB->get_records('adaptivequiz_attempt', ['instance' => $adaptivequiz->id], 'id');
        $uniqueids = array_filter(array_map(fn($h) => (int) $h->uniqueid, $host));
        [$compsql, $compparams] = $DB->get_in_or_equal(attempt_cleanup::COMPONENTS);
        $cat = $host ? $DB->get_records_select(
            'local_catquiz_attempts',
            'attemptid IN (' . implode(',', array_keys($host)) . ") AND component $compsql",
            $compparams,
            'id'
        ) : [];
        $catids = array_keys($cat) ?: [0];
        $in = implode(',', $catids);
        $grade = grade_get_grades($adaptivequiz->course, 'mod', 'adaptivequiz', $adaptivequiz->id, $userid);
        return [
            'activity' => $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]),
            'host' => $host,
            'usages' => $uniqueids ? $DB->get_records_list('question_usages', 'id', $uniqueids, 'id') : [],
            'questionattempts' => $uniqueids ? $DB->count_records_select(
                'question_attempts',
                'questionusageid IN (' . implode(',', $uniqueids) . ')'
            ) : 0,
            'cat' => $cat,
            'progress' => $DB->get_records_select('local_catquiz_progress', "attemptid IN ($in)", null, 'id'),
            'personparams' => $DB->get_records_select('local_catquiz_personparams', "attemptid IN ($in)", null, 'id'),
            'trace' => $host ? $DB->count_records_select(
                'local_catquiz_trace',
                'adaptiveattemptid IN (' . implode(',', array_keys($host)) . ')'
            ) : 0,
            'tests' => $DB->get_records(
                'local_catquiz_tests',
                ['componentid' => $adaptivequiz->id, 'component' => 'mod_adaptivequiz']
            ),
            'grade' => array_map(fn($item) => $item->grades, $grade->items ?? []),
        ];
    }

    /**
     * Rows that lead nowhere, by table - their ids.
     *
     * @return array
     */
    private function orphans(): array {
        global $DB;
        [$compsql, $compparams] = $DB->get_in_or_equal(attempt_cleanup::COMPONENTS);
        return [
            'progress' => $DB->get_fieldset_sql('SELECT p.id FROM {local_catquiz_progress} p
                WHERE NOT EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = p.attemptid) ORDER BY p.id'),
            'personparams' => $DB->get_fieldset_sql('SELECT pp.id FROM {local_catquiz_personparams} pp
                WHERE pp.attemptid IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM {local_catquiz_attempts} a WHERE a.id = pp.attemptid) ORDER BY pp.id'),
            'cat' => $DB->get_fieldset_sql("SELECT c.id FROM {local_catquiz_attempts} c WHERE c.component $compsql
                AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = c.attemptid) ORDER BY c.id", $compparams),
            'trace' => $DB->get_fieldset_sql('SELECT t.id FROM {local_catquiz_trace} t
                WHERE NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.id = t.adaptiveattemptid) ORDER BY t.id'),
            'usages' => $DB->get_fieldset_sql("SELECT u.id FROM {question_usages} u WHERE u.component = 'mod_adaptivequiz'
                AND NOT EXISTS (SELECT 1 FROM {adaptivequiz_attempt} h WHERE h.uniqueid = u.id) ORDER BY u.id"),
        ];
    }

    /**
     * Rows per table.
     *
     * @return int[]
     */
    private function counts(): array {
        global $DB;
        $counts = [];
        foreach (
            ['adaptivequiz', 'adaptivequiz_attempt', 'question_usages', 'question_attempts', 'local_catquiz_attempts',
                'local_catquiz_progress', 'local_catquiz_personparams', 'local_catquiz_trace', 'local_catquiz_tests'] as $table
        ) {
            $counts[$table] = $DB->count_records($table);
        }
        return $counts;
    }

    /**
     * Rows of A per table, from its snapshot.
     *
     * @param array $a
     * @return int[]
     */
    private static function rows_of(array $a): array {
        return [
            'adaptivequiz' => $a['activity'] ? 1 : 0, 'adaptivequiz_attempt' => count($a['host']),
            'question_usages' => count($a['usages']), 'question_attempts' => $a['questionattempts'],
            'local_catquiz_attempts' => count($a['cat']), 'local_catquiz_progress' => count($a['progress']),
            'local_catquiz_personparams' => count($a['personparams']), 'local_catquiz_trace' => $a['trace'],
            'local_catquiz_tests' => count($a['tests']),
        ];
    }

    /**
     * B goes on: its next request answers the pending item and shows the next one or ends the attempt.
     *
     * @param array $before B's snapshot before the deletion.
     */
    private function assert_b_goes_on(array $before): void {
        global $DB;
        $catb = (int) array_key_first($before['cat']);
        $slotsbefore = $before['questionattempts'];
        $result = $this->play('B', [true]);
        $this->assertSame($this->host('B'), $result['attemptid'], 'B continues its own attempt');
        $this->assertSame($catb, $this->cat('B'), 'B continues its own CAT attempt');
        $after = $this->snapshot('B');
        if (!$result['stopped']) {
            $this->assertSame($slotsbefore + 1, $after['questionattempts'], 'B was shown its next question');
        }
        $progress = reset($after['progress']);
        $this->assertNotFalse($progress, 'B keeps its progress');
        $this->assertNotSame(reset($before['progress'])->json, $progress->json, 'B\'s progress took the answer');
        $this->assertSame(
            1,
            $DB->count_records('local_catquiz_attempts', ['attemptid' => $this->host('B')]),
            'no second CAT attempt for B'
        );
    }

    /**
     * Deleting A by one of the paths.
     *
     * @param string $path activity, course, attempt or reset
     */
    private function delete_a(string $path): void {
        global $DB;
        [$adaptivequiz] = $this->act['A'];
        switch ($path) {
            case 'activity':
                course_delete_module((int) $adaptivequiz->cm->id);
                break;
            case 'course':
                delete_course((int) $adaptivequiz->course, false);
                break;
            case 'attempt':
                $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $this->host('A')], '*', MUST_EXIST);
                adaptivequiz_delete_attempt($DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]), $attempt);
                break;
            case 'reset':
                adaptivequiz_reset_userdata((object) ['courseid' => $adaptivequiz->course, 'reset_adaptivequiz_all' => 1]);
                break;
        }
    }

    /**
     * The spellings of the component.
     *
     * @return array
     */
    public static function components(): array {
        return [
            'mod_adaptivequiz' => ['mod_adaptivequiz', false],
            'adaptivequiz (older)' => ['adaptivequiz', false],
            'adaptivequiz (older), activity named' => ['adaptivequiz', true],
        ];
    }

    /**
     * Deleting activity A through course_delete_module(): A completely gone, B and C exactly as before and B goes
     * on, no new orphan; the table counts fall by A's rows exactly (AK3, AK4, AK5, AK6, DoD2).
     *
     * @dataProvider components
     * @param string $component
     * @param bool $instanceset
     */
    public function test_deleting_the_activity(string $component, bool $instanceset): void {
        $this->build($component, $instanceset);
        $a = $this->snapshot('A');
        $b = $this->snapshot('B');
        $c = $this->snapshot('C');
        $orphans = $this->orphans();
        $counts = $this->counts();
        $this->assertNotEmpty($a['progress'], 'A has progress');
        $this->assertNotEmpty($b['progress'], 'B has progress');

        $this->delete_a('activity');

        $delta = [];
        foreach ($this->counts() as $table => $n) {
            $delta[$table] = $counts[$table] - $n;
        }
        $expected = self::rows_of($a);
        $expected['local_catquiz_tests'] = count($a['tests']);
        $this->assertEquals($expected, $delta, 'Exactly A\'s rows are gone: ' . json_encode($delta));
        $this->assertEquals($b, $this->snapshot('B'), 'B unchanged');
        $this->assertEquals($c, $this->snapshot('C'), 'C unchanged');
        $this->assertEquals($orphans, $this->orphans(), 'No new orphan');
        $this->assert_kept_rows();
        $this->assert_b_goes_on($b);
    }

    /**
     * The id domains and states of A (re-audit 17.11.2026, CI order for #11).
     *
     * @return array [ids, state]
     */
    public static function domains_and_states(): array {
        return [
            'same number in both id spaces, running' => ['same', 'running'],
            'numbers apart, running' => ['apart', 'running'],
            'crossed, finished with grade' => ['crossed', 'finished'],
            'crossed, running, cleanup run again' => ['crossed', 'repeat'],
            'crossed, the activity attempt already gone' => ['crossed', 'nohost'],
        ];
    }

    /**
     * Deleting activity A for every id domain and every state of A: A's data gone, B and C equal value by value,
     * no new orphan, B goes on; a cleanup run again changes nothing.
     *
     * @param string $ids
     * @param string $state
     * @dataProvider domains_and_states
     */
    public function test_id_domains_and_attempt_states(string $ids, string $state): void {
        global $DB;
        // A CAT attempt without its activity attempt can only be found through the activity it names.
        $this->build('mod_adaptivequiz', $state === 'nohost', $ids);
        if ($state === 'finished') {
            for ($i = 0; $i < 10 && !($this->play('A', [true])['stopped'] ?? false); $i++) {
                continue;
            }
            $this->assertSame('complete', $DB->get_field('adaptivequiz_attempt', 'attemptstate', ['id' => $this->host('A')]));
        }
        $cata = $this->cat('A');
        $hosta = $this->host('A');
        $a = $this->snapshot('A');
        if ($state === 'nohost') {
            // A historical orphan: the CAT attempt of A without its activity attempt.
            $DB->delete_records('adaptivequiz_attempt', ['id' => $hosta]);
        }
        $b = $this->snapshot('B');
        $c = $this->snapshot('C');
        $orphans = $this->orphans();
        $this->assertNotEmpty($a['progress'], 'A has progress');
        $this->assertNotEmpty($a['personparams'], 'A has person parameters');

        $this->delete_a('activity');
        if ($state === 'repeat') {
            $fingerprints = [self::fingerprint($this->snapshot('B')), self::fingerprint($this->snapshot('C')), $this->counts()];
            attempt_cleanup::delete_for_instance((int) $this->act['A'][0]->id);
            attempt_cleanup::delete_for_host_attempts([$hosta]);
            $this->assertSame($fingerprints, [self::fingerprint($this->snapshot('B')), self::fingerprint($this->snapshot('C')),
                $this->counts()], 'a second cleanup changes nothing');
        }

        foreach (
            ['local_catquiz_attempts' => 'id', 'local_catquiz_progress' => 'attemptid',
                'local_catquiz_personparams' => 'attemptid'] as $table => $field
        ) {
            $this->assertSame(0, $DB->count_records($table, [$field => $cata]), "nothing of A left in $table");
        }
        $this->assertSame(self::fingerprint($b), self::fingerprint($this->snapshot('B')), 'B unchanged, value by value');
        $this->assertSame(self::fingerprint($c), self::fingerprint($this->snapshot('C')), 'C unchanged, value by value');
        $after = $this->orphans();
        foreach ($after as $kind => $ids) {
            $this->assertSame([], array_values(array_diff($ids, $orphans[$kind])), "no new orphan: $kind");
        }
        if ($state === 'nohost') {
            $this->assertNotContains((string) $cata, $after['cat'], 'the historical orphan of A is gone with its activity');
        }
        $this->assert_kept_rows();
        $this->assert_b_goes_on($b);
    }

    /**
     * Deleting the course of A and B: both completely gone, C as before, no new orphan (AK4, AK5, AK6).
     */
    public function test_deleting_the_course(): void {
        $this->build();
        $c = $this->snapshot('C');
        $orphans = $this->orphans();
        $a = $this->snapshot('A');
        $b = $this->snapshot('B');

        $this->delete_a('course');

        foreach (['A' => $a, 'B' => $b] as $name => $before) {
            foreach (['progress', 'personparams', 'cat'] as $part) {
                foreach (array_keys($before[$part]) as $id) {
                    $table = $part === 'cat' ? 'local_catquiz_attempts' : "local_catquiz_$part";
                    $this->assertFalse($GLOBALS['DB']->record_exists($table, ['id' => $id]), "$name $part $id gone");
                }
            }
        }
        $this->assertEquals($c, $this->snapshot('C'), 'C unchanged');
        $this->assertEquals($orphans, $this->orphans(), 'No new orphan');
        $this->assert_kept_rows();
    }

    /**
     * Deleting A's attempt and resetting the course (AK5): A's data gone; B as before and on after the attempt.
     *
     * @dataProvider components
     * @param string $component
     * @param bool $instanceset
     */
    public function test_deleting_the_attempt(string $component, bool $instanceset): void {
        $this->build($component, $instanceset);
        $b = $this->snapshot('B');
        $orphans = $this->orphans();

        $this->delete_a('attempt');

        $this->assertSame(0, $this->cat('A'));
        $after = $this->snapshot('A');
        $this->assertSame([], $after['host']);
        $this->assertEquals($b, $this->snapshot('B'), 'B unchanged');
        $this->assertEquals($orphans, $this->orphans(), 'No new orphan');
        $this->assert_kept_rows();
        $this->assert_b_goes_on($b);
    }

    /**
     * The course reset removes A and B with their CATquiz data, C stays, no new orphan (AK5).
     */
    public function test_course_reset(): void {
        $this->build();
        $c = $this->snapshot('C');
        $orphans = $this->orphans();

        $this->delete_a('reset');

        foreach (['A', 'B'] as $name) {
            $this->assertSame([], $this->snapshot($name)['host'], "$name's attempts gone");
            $this->assertSame([], $this->snapshot($name)['cat'], "$name's CAT attempts gone");
        }
        $this->assertEquals($c, $this->snapshot('C'));
        $this->assertEquals($orphans, $this->orphans(), 'No new orphan');
        $this->assert_kept_rows();
    }

    /**
     * Steps and paths of a failing deletion.
     *
     * @return array
     */
    public static function failures(): array {
        $cases = [];
        foreach (['activity', 'attempt', 'reset'] as $path) {
            foreach (['personparams', 'progress', 'attempts', 'trace', 'commit'] as $step) {
                $cases["$path, $step"] = [$path, $step];
            }
        }
        $cases['activity, tests'] = ['activity', 'tests'];
        return $cases;
    }

    /**
     * A failure in any step leaves everything as it was, says what and why, and the same deletion run again
     * completes without touching B (AK7).
     *
     * @dataProvider failures
     * @param string $path
     * @param string $step
     */
    public function test_a_failure_changes_nothing_and_a_retry_completes(string $path, string $step): void {
        global $DB;
        $this->build();
        $all = ['A' => $this->snapshot('A'), 'B' => $this->snapshot('B'), 'C' => $this->snapshot('C')];
        $counts = $this->counts();
        $cm = (int) $this->act['A'][0]->cm->id;
        $this->assertFalse($DB->is_transaction_started(), 'no transaction open before the deletion');

        attempt_cleanup::simulate_fault(static function (string $at) use ($step): void {
            if ($at === $step) {
                throw new \dml_write_exception("simulated failure at $step");
            }
        });
        try {
            $this->delete_a($path);
            $this->fail('The deletion must fail');
        } catch (cleanup_failed_exception $e) {
            $this->assertStringContainsString("simulated failure at $step", $e->getMessage());
            $this->assertStringContainsString('Nothing was deleted', $e->getMessage());
        } finally {
            attempt_cleanup::simulate_fault(null);
        }
        $this->assertFalse($DB->is_transaction_started(), 'no transaction left open');
        $this->assertEquals($counts, $this->counts(), 'nothing deleted');
        foreach ($all as $name => $before) {
            $this->assertEquals($before, $this->snapshot($name), "$name unchanged");
        }
        if ($path === 'activity') {
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $cm]), 'the activity is still there');
        }

        // The same deletion again.
        $this->delete_a($path);
        $this->assertSame(0, $this->cat('A'), 'A\'s CAT data gone');
        if ($path !== 'reset') {
            $this->assertEquals($all['B'], $this->snapshot('B'), 'B unchanged');
            $this->assert_b_goes_on($all['B']);
        }
        $this->assertEquals($all['C'], $this->snapshot('C'), 'C unchanged');
    }

    /**
     * Steps of the cleanup on its own.
     *
     * @return array
     */
    public static function cleanup_steps(): array {
        $cases = [];
        foreach (['instance', 'attempts'] as $call) {
            foreach (['personparams', 'progress', 'attempts', 'trace', 'commit'] as $step) {
                $cases["$call, $step"] = [$call, $step];
            }
        }
        $cases['instance, tests'] = ['instance', 'tests'];
        return $cases;
    }

    /**
     * The cleanup called on its own - as the privacy API does, without a transaction of the activity - is one
     * transaction itself: a failure in any step deletes nothing, a second run completes (AK7).
     *
     * @dataProvider cleanup_steps
     * @param string $call instance or attempts
     * @param string $step
     */
    public function test_the_cleanup_alone_deletes_all_or_nothing(string $call, string $step): void {
        $this->build();
        $all = ['A' => $this->snapshot('A'), 'B' => $this->snapshot('B'), 'C' => $this->snapshot('C')];
        $counts = $this->counts();
        $run = function () use ($call): void {
            if ($call === 'instance') {
                attempt_cleanup::delete_for_instance((int) $this->act['A'][0]->id);
            } else {
                attempt_cleanup::delete_for_host_attempts([$this->host('A')]);
            }
        };

        attempt_cleanup::simulate_fault(static function (string $at) use ($step): void {
            if ($at === $step) {
                throw new \dml_write_exception("simulated failure at $step");
            }
        });
        try {
            $run();
            $this->fail('The cleanup must fail');
        } catch (cleanup_failed_exception $e) {
            $this->assertStringContainsString("simulated failure at $step", $e->getMessage());
        } finally {
            attempt_cleanup::simulate_fault(null);
        }
        $this->assertEquals($counts, $this->counts(), 'nothing deleted');
        foreach ($all as $name => $before) {
            $this->assertEquals($before, $this->snapshot($name), "$name unchanged");
        }

        $run();
        $this->assertSame(0, $this->cat('A'), 'A\'s CAT data gone');
        $this->assertSame([], $this->snapshot('A')['progress']);
        $this->assertEquals($all['B'], $this->snapshot('B'), 'B unchanged');
        $this->assertEquals($all['C'], $this->snapshot('C'), 'C unchanged');
    }

    /**
     * The rows that are not A's attempt data are still there.
     */
    private function assert_kept_rows(): void {
        global $DB;
        $this->assertTrue(
            $DB->record_exists('local_catquiz_progress', ['id' => $this->legacyprogress]),
            'progress from before local_catquiz #101 is never deleted by number'
        );
        $this->assertTrue(
            $DB->record_exists('local_catquiz_personparams', ['id' => $this->freeability]),
            'an ability outside any attempt stays'
        );
    }
}
