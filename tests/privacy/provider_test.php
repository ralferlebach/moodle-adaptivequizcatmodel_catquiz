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

namespace adaptivequizcatmodel_catquiz\privacy;

use context_module;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_adaptivequiz\local\attempt\attempt_state;
use stdClass;

/**
 * The CATquiz results of one activity can be exported and deleted from that activity.
 *
 * local_catquiz has a provider of its own, but it works at course level. Deleting a single
 * adaptive quiz never reaches it - which is the gap this provider closes. These tests pin that the
 * rows of one activity are found and that the rows of another are left alone.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var stdClass The activity under test. */
    private stdClass $adaptivequiz;

    /** @var context_module The context of that activity. */
    private context_module $context;

    /** @var stdClass The activity that must stay untouched. */
    private stdClass $otheractivity;

    /** @var stdClass One user with a CATquiz attempt. */
    private stdClass $owner;

    /** @var stdClass Another user with a CATquiz attempt in the same activity. */
    private stdClass $other;

    /**
     * Builds two activities, each with CATquiz attempts.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $this->adaptivequiz = $generator->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => 'catquiz',
        ]);
        $this->context = context_module::instance($this->adaptivequiz->cmid);
        $this->otheractivity = $generator->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => 'catquiz',
        ]);

        $this->owner = $generator->create_user();
        $this->other = $generator->create_user();

        $this->create_catquiz_attempt($this->adaptivequiz, $this->owner, 0.75);
        $this->create_catquiz_attempt($this->adaptivequiz, $this->other, -0.25);
        $this->create_catquiz_attempt($this->otheractivity, $this->owner, 1.5);
    }

    /**
     * Creates an attempt of the activity plus the CATquiz rows belonging to it.
     *
     * @param stdClass $adaptivequiz The activity the attempt belongs to.
     * @param stdClass $user The user taking it.
     * @param float $ability The ability CATquiz is meant to have estimated.
     * @return int Id of the attempt of the activity.
     */
    private function create_catquiz_attempt(stdClass $adaptivequiz, stdClass $user, float $ability): int {
        global $DB;

        $now = time();

        $attemptid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id,
            'userid' => $user->id,
            'uniqueid' => 0,
            'attemptstate' => attempt_state::COMPLETED,
            'attemptstopcriteria' => 'done',
            'questionsattempted' => 5,
            'difficultysum' => 25.0,
            'standarderror' => 0.3,
            'measure' => $ability,
            'timecreated' => $now - 300,
            'timemodified' => $now,
            'timefinished' => $now,
        ]);

        $catattemptid = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $user->id,
            'scaleid' => 1,
            'contextid' => 1,
            'courseid' => $adaptivequiz->course,
            'attemptid' => $attemptid,
            'component' => 'mod_adaptivequiz',
            'instanceid' => $adaptivequiz->id,
            'teststrategy' => 4,
            'status' => 1,
            'total_number_of_testitems' => 20,
            'number_of_testitems_used' => 5,
            'personability_before_attempt' => 0.0,
            'personability_after_attempt' => $ability,
            'starttime' => $now - 300,
            'endtime' => $now,
            'json' => '{}',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $DB->insert_record('local_catquiz_attemptscale', (object) [
            'catattemptid' => $catattemptid,
            'userid' => $user->id,
            'contextid' => 1,
            'catscaleid' => 1,
            'score' => $ability,
            'standarderror' => 0.3,
            'n' => 5,
            'fraction' => 0.8,
            'isprimary' => 1,
            'isvalid' => 1,
            'timecreated' => $now,
        ]);

        $DB->insert_record('local_catquiz_progress', (object) [
            'userid' => $user->id,
            'component' => 'mod_adaptivequiz',
            'attemptid' => $attemptid,
            'json' => '{}',
            'quizsettings' => '{}',
        ]);

        return $attemptid;
    }

    /**
     * Returns how many CATquiz attempts exist for the given activity and user.
     *
     * @param stdClass $adaptivequiz The activity to count in.
     * @param stdClass|null $user The user to count for, null for everyone.
     * @return int
     */
    private function catquiz_attempt_count(stdClass $adaptivequiz, ?stdClass $user = null): int {
        global $DB;

        $conditions = ['instance' => $adaptivequiz->id];
        if ($user !== null) {
            $conditions['userid'] = $user->id;
        }
        $attemptids = $DB->get_fieldset_select(
            'adaptivequiz_attempt',
            'id',
            'instance = :instance' . ($user !== null ? ' AND userid = :userid' : ''),
            $conditions
        );

        if (empty($attemptids)) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);

        return $DB->count_records_select('local_catquiz_attempts', "attemptid $insql", $inparams);
    }

    /**
     * The export carries the ability CATquiz estimated.
     */
    public function test_export_contains_the_catquiz_result(): void {
        provider::export_catmodel_user_data((int) $this->owner->id, $this->context, []);

        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $exported = $writer->get_data([get_string('pluginname', 'adaptivequizcatmodel_catquiz')]);
        $this->assertCount(1, $exported->attempts);
        $this->assertEquals(0.75, $exported->attempts[0]->abilityafter);
        $this->assertCount(1, $exported->attempts[0]->scales);
        $this->assertEquals(0.75, $exported->attempts[0]->scales[0]->ability);
    }

    /**
     * Deleting one user leaves the other one and the other activity alone.
     */
    public function test_delete_for_user_is_limited_to_that_user(): void {
        provider::delete_catmodel_data_for_user((int) $this->owner->id, $this->context);

        $this->assertSame(0, $this->catquiz_attempt_count($this->adaptivequiz, $this->owner));
        $this->assertSame(1, $this->catquiz_attempt_count($this->adaptivequiz, $this->other));
        $this->assertSame(1, $this->catquiz_attempt_count($this->otheractivity, $this->owner));
    }

    /**
     * Deleting the activity removes the results of everyone in it, and nobody else's.
     */
    public function test_delete_for_context_is_limited_to_that_activity(): void {
        provider::delete_catmodel_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $this->catquiz_attempt_count($this->adaptivequiz));
        $this->assertSame(1, $this->catquiz_attempt_count($this->otheractivity, $this->owner));
    }

    /**
     * The scale results and the progress of a deleted attempt go with it.
     */
    public function test_delete_removes_the_dependent_rows(): void {
        global $DB;

        provider::delete_catmodel_data_for_all_users_in_context($this->context);

        $attemptids = $DB->get_fieldset_select(
            'adaptivequiz_attempt',
            'id',
            'instance = :instance',
            ['instance' => $this->adaptivequiz->id]
        );
        [$insql, $inparams] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);

        $this->assertSame(
            0,
            $DB->count_records_select('local_catquiz_progress', "attemptid $insql", $inparams),
            'The progress of the deleted attempts is still there.'
        );
        $this->assertSame(
            0,
            $DB->count_records('local_catquiz_attemptscale', ['catscaleid' => 1, 'userid' => $this->other->id]),
            'The scale results of the deleted attempts are still there.'
        );
    }

    /**
     * Deleting an approved list of users removes exactly those.
     */
    public function test_delete_for_users(): void {
        provider::delete_catmodel_data_for_users(
            new approved_userlist($this->context, 'adaptivequizcatmodel_catquiz', [(int) $this->other->id])
        );

        $this->assertSame(1, $this->catquiz_attempt_count($this->adaptivequiz, $this->owner));
        $this->assertSame(0, $this->catquiz_attempt_count($this->adaptivequiz, $this->other));
    }

    /**
     * Both users of the activity are named, the user of the other activity is not.
     */
    public function test_userlist_names_the_users_of_this_activity(): void {
        $userlist = new userlist($this->context, 'adaptivequizcatmodel_catquiz');
        provider::add_catmodel_users_to_userlist($userlist);

        $this->assertEqualsCanonicalizing(
            [(int) $this->owner->id, (int) $this->other->id],
            $userlist->get_userids()
        );
    }
}
