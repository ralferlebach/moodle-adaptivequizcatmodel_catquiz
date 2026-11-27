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

use advanced_testcase;

/**
 * The inventory counts every kind of row that leads nowhere, by age, and writes nothing (issue #11, AK8).
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\adaptivequizcatmodel_catquiz\local\orphan_inventory::class)]
final class orphan_inventory_test extends advanced_testcase {
    /** @var int The moment the ages are counted from. */
    private const NOW = 1800000000;

    /**
     * One row of each class, some of them twice with other ages; healthy rows that must not count.
     */
    public function test_each_class_by_age_and_nothing_written(): void {
        global $DB;
        $this->resetAfterTest();
        $days = fn(int $n): int => self::NOW - $n * DAYSECS;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $host = function (int $userid) use ($DB, $instance): int {
            return (int) $DB->insert_record('adaptivequiz_attempt', (object) [
                'instance' => $instance->id, 'userid' => $userid, 'uniqueid' => 0, 'attemptstate' => 'complete',
                'attemptstopcriteria' => '', 'questionsattempted' => 1, 'difficultysum' => 0, 'standarderror' => 0,
                'measure' => 0, 'timecreated' => self::NOW, 'timemodified' => self::NOW,
            ]);
        };
        $cat = function (array $fields) use ($DB, $user): int {
            return (int) $DB->insert_record('local_catquiz_attempts', (object) ($fields + [
                'userid' => $user->id, 'component' => 'mod_adaptivequiz', 'timecreated' => self::NOW, 'timemodified' => self::NOW,
            ]));
        };
        $progress = function (int $attemptid, int $userid, int $time) use ($DB): void {
            $DB->insert_record('local_catquiz_progress', (object) [
                'attemptid' => $attemptid, 'userid' => $userid, 'component' => 'mod_adaptivequiz', 'json' => '{}',
                'timemodified' => $time,
            ]);
        };

        // Healthy: an attempt of the activity, its CAT attempt, progress, ability, trace.
        $healthyhost = $host((int) $user->id);
        $healthycat = $cat(['attemptid' => $healthyhost, 'instanceid' => $instance->id]);
        $progress($healthycat, (int) $user->id, $days(1));
        $DB->insert_record('local_catquiz_personparams', (object) ['attemptid' => $healthycat, 'userid' => $user->id,
            'catscaleid' => 1, 'contextid' => 1, 'isprimary' => 1, 'isvalid' => 1, 'timecreated' => $days(1), 'timemodified' => 0]);
        $DB->insert_record('local_catquiz_personparams', (object) ['attemptid' => null, 'userid' => $user->id,
            'catscaleid' => 1, 'contextid' => 1, 'isprimary' => 1, 'isvalid' => 1, 'timecreated' => $days(1), 'timemodified' => 0]);
        $DB->insert_record('local_catquiz_trace', (object) ['adaptiveattemptid' => $healthyhost, 'userid' => $user->id,
            'kind' => 'first_item', 'timecreated' => $days(1)]);

        // Progress keyed by the activity's attempt of the same person (before #101): 10 and 400 days old.
        // The attempts of the activity get numbers no CAT attempt has.
        foreach ([700000 => 10, 800000 => 400] as $offset => $age) {
            $legacyhost = $host((int) $user->id);
            $DB->execute('UPDATE {adaptivequiz_attempt} SET id = ? WHERE id = ?', [$legacyhost + $offset, $legacyhost]);
            $progress($legacyhost + $offset, (int) $user->id, $days($age));
        }
        // Progress of nothing: 100 days.
        $progress(990001, (int) $user->id, $days(100));
        // Progress naming somebody else's CAT attempt: 200 days.
        $foreigncat = $cat(['attemptid' => $host((int) $user->id), 'instanceid' => $instance->id]);
        $progress($foreigncat, (int) $other->id, $days(200));
        // CAT attempts without their attempt of the activity: activity there (5 days), activity gone (500 days).
        $cat(['attemptid' => 990002, 'instanceid' => $instance->id, 'timecreated' => $days(5)]);
        $cat(['attemptid' => 990003, 'instanceid' => 990004, 'timecreated' => $days(500), 'component' => 'adaptivequiz']);
        // A CAT attempt without the attempt id of the activity: 40 days.
        $cat(['attemptid' => null, 'instanceid' => $instance->id, 'timecreated' => $days(40)]);
        // An ability of a CAT attempt that does not exist: 365 days.
        $DB->insert_record('local_catquiz_personparams', (object) ['attemptid' => 990005, 'userid' => $user->id,
            'catscaleid' => 1, 'contextid' => 1, 'isprimary' => 1, 'isvalid' => 1, 'timecreated' => $days(365),
            'timemodified' => 0]);
        // Local_catquiz #177: events of a working state - one with its head (healthy), two whose head is gone: 20 and
        // 250 days. Only where the version of local_catquiz has the table.
        $hassteps = $DB->get_manager()->table_exists('local_catquiz_progress_steps');
        if ($hassteps) {
            foreach ([[$healthycat, 1, $days(1)], [990007, 1, $days(20)], [990007, 2, $days(250)]] as [$owner, $seq, $time]) {
                $DB->insert_record('local_catquiz_progress_steps', (object) [
                    'catattemptid' => $owner, 'seq' => $seq, 'eventtype' => 'shown', 'timecreated' => $time,
                ]);
            }
        }
        // A trace of an attempt that is gone: 3 days.
        $DB->insert_record('local_catquiz_trace', (object) ['adaptiveattemptid' => 990006, 'userid' => $user->id,
            'kind' => 'first_item', 'timecreated' => $days(3)]);

        $sizes = orphan_inventory::table_sizes();
        $writes = $DB->perf_get_writes();
        $inventory = orphan_inventory::collect(self::NOW);
        $this->assertSame($writes, $DB->perf_get_writes(), 'the inventory writes nothing');
        $this->assertSame($sizes, orphan_inventory::table_sizes());

        $expected = [
            'progress_orphan_hostid' => [2, 1, 0, 0, 1],
            'progress_orphan_none' => [1, 0, 1, 0, 0],
            'progress_foreign' => [1, 0, 0, 1, 0],
            'progress_steps_orphan' => [2, 1, 0, 1, 0],
            'cat_attempt_no_host_instance' => [1, 1, 0, 0, 0],
            'cat_attempt_no_host_noinstance' => [1, 0, 0, 0, 1],
            'cat_attempt_no_hostid' => [1, 0, 1, 0, 0],
            'personparams_orphan' => [1, 0, 0, 1, 0],
            'trace_orphan' => [1, 1, 0, 0, 0],
        ];
        if (!$hassteps) {
            unset($expected['progress_steps_orphan']);
        }
        foreach ($expected as $class => [$total, $le30, $le180, $le365, $older]) {
            $this->assertSame(
                ['total' => $total, 'le30' => $le30, 'le180' => $le180, 'le365' => $le365, 'older' => $older],
                array_intersect_key($inventory[$class], array_flip(['total', 'le30', 'le180', 'le365', 'older'])),
                $class
            );
            $this->assertNotEmpty($inventory[$class]['cause']);
        }
        $this->assertSame(array_keys($expected), array_keys($inventory));
    }

    /**
     * An empty site: every class is there with nothing in it.
     */
    public function test_empty(): void {
        $this->resetAfterTest();
        foreach (orphan_inventory::collect() as $class => $counts) {
            $this->assertSame(0, $counts['total'], $class);
        }
    }
}
