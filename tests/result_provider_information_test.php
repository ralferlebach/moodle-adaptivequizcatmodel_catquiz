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
use mod_adaptivequiz\local\result\link\link_parameters;
use mod_adaptivequiz\local\result\result_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/catquiz/tests/fixtures/trace_scenario.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * The test information of a CATquiz activity for the Fisher-Rao link function (mod_adaptivequiz #25), on a real,
 * calibrated item battery - the simulation pool of local_catquiz.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(catquiz_result_provider::class)]
final class result_provider_information_test extends advanced_testcase {
    /**
     * Integral of sqrt(I) with a fixed-step Simpson rule: an independent check of the adaptive quadrature.
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
     * The battery of the activity gives a positive, finite information; Fisher-Rao on it matches the fixed-step rule,
     * is 0 at L and 100 at U, and is accepted by the activity's settings check.
     */
    public function test_fisher_rao_on_the_calibrated_battery(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = trace_scenario::create($this->getDataGenerator());
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $provider = new catquiz_result_provider();
        $definition = $provider->get_result_definition($adaptivequiz);
        [$l, $u] = [$definition->lower, $definition->upper];

        $info = $provider->test_information($adaptivequiz);
        $this->assertNotNull($info);
        for ($t = $l; $t <= $u; $t += ($u - $l) / 20) {
            $value = $info($t);
            $this->assertTrue(is_finite($value) && $value > 0.0, "I($t) = $value");
        }

        $total = self::simpson($info, $l, $u);
        foreach ([$l + 0.37 * ($u - $l), ($l + $u) / 2, $l + 0.81 * ($u - $l)] as $score) {
            $expected = 100 * self::simpson($info, $l, $score) / $total;
            $actual = result_service::percentage($score, $l, $u, 'fisher_rao', new link_parameters($l, $u, null, 1.0, $info));
            $this->assertEqualsWithDelta($expected, $actual, 1e-6, "score $score");
        }
        $parameters = new link_parameters($l, $u, null, 1.0, $info);
        $this->assertEqualsWithDelta(0.0, result_service::percentage($l, $l, $u, 'fisher_rao', $parameters), 1e-12);
        $this->assertEqualsWithDelta(100.0, result_service::percentage($u, $l, $u, 'fisher_rao', $parameters), 1e-12);

        $adaptivequiz->linkfunction = 'fisher_rao';
        $this->assertNull(result_service::link_settings_error($adaptivequiz));
    }

    /**
     * The battery as it is now: without versioning, a changed item selection changes the next computation.
     */
    public function test_the_current_battery_counts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = trace_scenario::create($this->getDataGenerator());
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $scenario->adaptivequiz->id], '*', MUST_EXIST);
        $provider = new catquiz_result_provider();
        $definition = $provider->get_result_definition($adaptivequiz);
        [$l, $u] = [$definition->lower, $definition->upper];
        $score = $l + 0.3 * ($u - $l);
        $before = result_service::percentage($score, $l, $u, 'fisher_rao', new link_parameters(
            $l,
            $u,
            null,
            1.0,
            $provider->test_information($adaptivequiz)
        ));

        // Every item with an easy question loses its active parameters: the battery measures elsewhere now.
        $ids = $DB->get_fieldset_sql(
            'SELECT i.id FROM {local_catquiz_items} i JOIN {local_catquiz_itemparams} p ON p.id = i.activeparamid
              WHERE p.difficulty < 0'
        );
        $this->assertNotEmpty($ids);
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $DB->set_field_select('local_catquiz_items', 'activeparamid', null, "id $insql", $params);
        \cache_helper::purge_by_event('changesintestitems');
        \cache::make('local_catquiz', 'catquiz_item_params')->purge();

        $after = result_service::percentage($score, $l, $u, 'fisher_rao', new link_parameters(
            $l,
            $u,
            null,
            1.0,
            $provider->test_information($adaptivequiz)
        ));
        $this->assertNotNull($after);
        $this->assertGreaterThan(1.0, abs($after - $before), "before $before, after $after");
    }

    /**
     * Without a test of its own the activity has no battery: no information, and the settings check says so.
     */
    public function test_no_battery_no_information(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $this->assertNull((new catquiz_result_provider())->test_information($instance));
    }
}
