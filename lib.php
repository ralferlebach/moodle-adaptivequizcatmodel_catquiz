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
 * Definition of plugin's system functions.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2023 Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\catquiz_handler;
use local_catquiz\local\attempt\cat_model_params;
use mod_adaptivequiz\local\attempt;

/**
 * Callback to execute when a fresh attempt on adaptive quiz has been created.
 *
 * Picked up by mod_adaptivequiz component only.
 *
 * @param stdClass $adaptivequiz
 * @param attempt $attempt
 */
function adaptivequizcatmodel_catquiz_post_create_attempt_callback(stdClass $adaptivequiz, attempt $attempt): void {
        cat_model_params::create_new_for_attempt($attempt->get_attempt()->id);
        catquiz_handler::prepare_attempt_caches();
}

/**
 * Callback to execute when a question answer is processed.
 * @param stdClass $adaptivequiz
 * @param mixed $cm
 * @param stdClass $attemptrecord
 */
function adaptivequizcatmodel_catquiz_attempt_finished_feedback(
    stdClass $adaptivequiz,
    mixed $cm,
    stdClass $attemptrecord
): string {
    return catquiz_handler::attempt_finished($adaptivequiz, $cm, $attemptrecord);
}

/**
 * Callback to execute when a question answer is processed.
 *
 * Picked up by mod_adaptivequiz component only.
 * @param question_usage_by_activity $quba
 * @param stdClass $adaptivequiz
 * @param attempt $attempt
 */
function adaptivequizcatmodel_catquiz_post_process_item_result_callback(
    question_usage_by_activity $quba,
    stdClass $adaptivequiz,
    attempt $attempt
): void {
}

/**
 * Callback returning the URL of this sub-plugin's own attempts report.
 *
 * Picked up by mod_adaptivequiz only (see attempts_number::when_custom_catmodel_in_use).
 * When a custom CAT model is in use, the activity does NOT render its built-in
 * attempts report - it only shows the number of attempts, and turns that number
 * into a link when this callback provides a URL. Without the callback a
 * teacher has no way at all to reach an attempts overview (and, through it, the
 * "Close attempt" action) for a catquiz-driven activity.
 *
 * The report is local_catquiz's own feedback page, which lists the attempts of the
 * given activity instance.
 *
 * @param stdClass $adaptivequiz An activity instance record.
 * @param stdClass $cm A course module record, as returned by get_coursemodule_from_id().
 *
 * @return moodle_url
 */
function adaptivequizcatmodel_catquiz_attempts_report_url(stdClass $adaptivequiz, stdClass $cm): moodle_url {
    return new moodle_url(
        '/local/catquiz/feedback.php',
        [
            'courseid' => $cm->course,
            'instanceid' => $adaptivequiz->id,
        ]
    );
}
