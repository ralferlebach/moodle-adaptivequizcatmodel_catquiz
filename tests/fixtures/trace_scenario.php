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

use adaptivequizcatmodel_catquiz\local\monitoring\request_simulator;
use context_module;
use local_catquiz\local\monitoring\timeline;
use stdClass;

/**
 * A CATquiz activity with a pool, and the requests of an attempt as the host makes them (adapter #6).
 *
 * The requests run the way attempt.php runs them, see request_simulator.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class trace_scenario {
    /** @var string The host's request timing; optional. */
    public const HOST_TIMING = request_simulator::HOST_TIMING;

    /** @var stdClass The activity record, with context and cm. */
    public stdClass $adaptivequiz;

    /** @var context_module */
    public context_module $context;

    /** @var int The participant. */
    public int $userid;

    /** @var int Requests made so far. */
    public int $requests = 0;

    /**
     * Sets up a course, a participant, an activity driven by CATquiz and its pool.
     *
     * @param \testing_data_generator $generator
     * @param bool $large The simulation pool of 420 questions instead of the small one.
     * @param array $settings Settings of the test, see the generator of local_catquiz.
     * @param int $maximumquestions The activity's own maximum.
     * @return self
     */
    public static function create(
        \testing_data_generator $generator,
        bool $large = false,
        array $settings = [],
        int $maximumquestions = 50
    ): self {
        global $DB;

        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $instance = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'minimumquestions' => 1, 'maximumquestions' => $maximumquestions,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $catquiz = $generator->get_plugin_generator('local_catquiz');
        $catquiz->create_catquiz_questions([
            'filepath' => 'local/catquiz/tests/fixtures/' . ($large ? 'simulation.xml' : 'quiz-adaptivetest-Simulation-small.xml'),
            'filename' => $large ? 'simulation.xml' : 'quiz-adaptivetest-Simulation-small.xml',
            'courseid' => $course->id,
        ]);
        $catquiz->create_catquiz_importedcatscales([
            'filepath' => 'local/catquiz/tests/fixtures/' . ($large ? 'simulation.csv' : 'simulation_small.csv'),
            'filename' => $large ? 'simulation.csv' : 'simulation_small.csv',
        ]);
        $root = $DB->get_record('local_catquiz_catscales', ['parentid' => 0], '*', MUST_EXIST);
        if ($large) {
            $root->minscalevalue = -10.0;
            $root->maxscalevalue = 10.0;
            $DB->update_record('local_catquiz_catscales', $root);
        }
        $catquiz->create_catquiz_testsettings($settings + [
            'courseid' => $course->id,
            'adaptivecatquizid' => $instance->id,
            'catscalesid' => $root->id,
            'cateststrategyid' => LOCAL_CATQUIZ_STRATEGY_LOWESTSUB,
            'catmodel' => 'catquiz',
            'catquiz_maxquestions' => 4,
            'catquiz_standarderror_min' => 0.4,
            'catquiz_standarderror_max' => 0.6,
            'numberoffeedbackoptions' => 2,
        ]);

        $scenario = new self();
        $scenario->adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id, $course->id, false, MUST_EXIST);
        $scenario->context = context_module::instance($cm->id);
        $scenario->adaptivequiz->context = $scenario->context;
        $scenario->adaptivequiz->cm = $cm;
        $scenario->userid = (int) $student->id;
        return $scenario;
    }

    /**
     * Forgets the host's timing and the CATquiz timeline: the next request begins.
     */
    public static function begin_request(): void {
        request_simulator::begin_request();
    }

    /**
     * One request of the attempt: the answer to the pending item, then the next item, as attempt.php does it.
     *
     * The timeline is not written here; the caller decides when (timeline::flush()).
     *
     * @param bool|null $right How the pending item is answered; null for the first request.
     * @return array questionid (null when the attempt ended), stopped, attemptid, slots.
     */
    public function request(?bool $right): array {
        $this->requests++;
        return request_simulator::request($this->adaptivequiz, $this->context, $this->userid, $right);
    }

    /**
     * The spans of a written trace, by name: the first of each name.
     *
     * @param int|null $traceid
     * @return array
     */
    public static function spans(?int $traceid): array {
        global $DB;
        if ($traceid === null) {
            return [];
        }
        $spans = [];
        foreach (json_decode($DB->get_field(timeline::TABLE, 'spans', ['id' => $traceid]), true) as $span) {
            $spans[$span['name']] ??= $span;
        }
        return $spans;
    }
}
