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

/**
 * Deleting the CATquiz data failed; the transaction was rolled back, so nothing was deleted (issue #11, AK7).
 *
 * The message names what was to be deleted and the cause; the way out is to run the same deletion again.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_failed_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $what What was to be deleted, e.g. "activity 12".
     * @param \Throwable $cause
     */
    public function __construct(string $what, \Throwable $cause) {
        parent::__construct(
            'cleanupfailed',
            'adaptivequizcatmodel_catquiz',
            '',
            (object) ['what' => $what, 'cause' => get_class($cause) . ': ' . $cause->getMessage()],
            $cause->getMessage()
        );
    }
}
