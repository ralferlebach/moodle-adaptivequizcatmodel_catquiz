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

/**
 * Unit tests for the item administration factory.
 *
 * The factory is the entry point mod_adaptivequiz uses to obtain this sub-plugin's
 * item administration, so its contract with the host module is what matters here.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \adaptivequizcatmodel_catquiz\local\catmodel\itemadministration\catquiz_item_administration_factory
 */

namespace adaptivequizcatmodel_catquiz\local\catmodel\itemadministration;

use advanced_testcase;
use mod_adaptivequiz\local\itemadministration\item_administration;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;
use ReflectionClass;

/**
 * Tests the factory contract with mod_adaptivequiz.
 *
 * @package    adaptivequizcatmodel_catquiz
 */
final class catquiz_item_administration_factory_test extends advanced_testcase {
    /**
     * The factory implements the interface the host module expects.
     *
     * mod_adaptivequiz resolves the factory through this interface; if the
     * implements clause were dropped the sub-plugin would silently stop being used.
     *
     * @return void
     */
    public function test_factory_implements_the_host_interface(): void {
        $this->assertTrue(
            (new ReflectionClass(catquiz_item_administration_factory::class))
                ->implementsInterface(item_administration_factory::class)
        );
    }

    /**
     * The produced item administration implements the host's interface too.
     *
     * @return void
     */
    public function test_produced_administration_implements_the_host_interface(): void {
        $this->assertTrue(
            (new ReflectionClass(catquiz_item_administration::class))
                ->implementsInterface(item_administration::class)
        );
    }

    /**
     * The factory method keeps the signature mod_adaptivequiz calls it with.
     *
     * @return void
     */
    public function test_factory_method_signature(): void {
        $method = (new ReflectionClass(catquiz_item_administration_factory::class))
            ->getMethod('item_administration_implementation');

        $parameters = $method->getParameters();

        $this->assertCount(3, $parameters);
        $this->assertSame('question_usage_by_activity', $parameters[0]->getType()->getName());
        // The host module's attempt class is `mod_adaptivequiz\local\attempt` itself,
        // not a class inside an `attempt` sub-namespace.
        $this->assertSame('mod_adaptivequiz\local\attempt', $parameters[1]->getType()->getName());
        $this->assertSame('stdClass', $parameters[2]->getType()->getName());
        $this->assertSame(item_administration::class, $method->getReturnType()->getName());
    }
}
