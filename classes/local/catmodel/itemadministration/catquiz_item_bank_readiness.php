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

namespace adaptivequizcatmodel_catquiz\local\catmodel\itemadministration;

use local_catquiz\catquiz_handler;
use mod_adaptivequiz\local\catmodel\itemadministration\catmodel_item_bank_readiness;
use stdClass;

/**
 * Tells the host whether a CATquiz test is ready for an attempt.
 *
 * Without this the host judged a CATquiz activity by its own rules - question banks linked to the
 * instance, its item parameters valid - which CATquiz never meets, because it keeps its items in
 * CAT scales. The activity page then offered no attempt at all. The decision belongs to CATquiz;
 * this only passes the question on.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catquiz_item_bank_readiness implements catmodel_item_bank_readiness {
    /**
     * Returns whether an attempt can be started for this instance as far as items are concerned.
     *
     * @param stdClass $adaptivequiz The instance record.
     * @return bool
     */
    public function is_item_bank_ready(stdClass $adaptivequiz): bool {
        // local_catquiz cannot be declared as a dependency here - it depends on this subplugin, and
        // the pair would become uninstallable. An older local_catquiz without the readiness check
        // must not block every attempt: it had no such notion, so it counts as ready.
        if (!method_exists(catquiz_handler::class, 'is_ready_for_attempt')) {
            return true;
        }

        return catquiz_handler::is_ready_for_attempt('mod_adaptivequiz', (int) $adaptivequiz->id);
    }
}
