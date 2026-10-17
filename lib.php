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
use local_catquiz\local\attempt\attempt_finalizer;
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
    // The share of this sub-plugin in the timeline of CATquiz, where it has one (adapter #6).
    adaptivequizcatmodel_catquiz_span('adapter:post_create_attempt', function () use ($attempt) {
        adaptivequizcatmodel_catquiz_span(
            'adapter:create_new_for_attempt',
            fn() => cat_model_params::create_new_for_attempt($attempt->get_attempt()->id)
        );
        // The attempt goes along (local_catquiz #146): only its own state is set up, the other attempts of
        // the session keep theirs. An older CATquiz takes no argument and ignores it.
        catquiz_handler::prepare_attempt_caches((int) $attempt->get_attempt()->id);
    });
}

/**
 * Runs a step inside a span of the CATquiz timeline - or just runs it, with an older CATquiz (adapter #6).
 *
 * @param string $name
 * @param callable $fn
 * @param array $meta
 * @return mixed What the step returns.
 */
function adaptivequizcatmodel_catquiz_span(string $name, callable $fn, array $meta = []) {
    $timeline = '\\local_catquiz\\local\\monitoring\\timeline';
    if (!class_exists($timeline)) {
        return $fn();
    }
    return $timeline::span($name, $fn, $meta);
}

/**
 * The attempt's time limit in seconds, for the host's notice of an attempt that ran out of time (mod_adaptivequiz #20).
 *
 * @param stdClass $adaptivequiz
 * @param stdClass $attempt adaptivequiz_attempt record.
 * @return int|null null when the test sets none.
 */
function adaptivequizcatmodel_catquiz_attempt_time_limit(stdClass $adaptivequiz, stdClass $attempt): ?int {
    if (!method_exists(catquiz_handler::class, 'attempt_time_limit')) {
        return null;
    }
    return catquiz_handler::attempt_time_limit((int) $attempt->id);
}

/**
 * The time for one question of an attempt in seconds, for the host's resume dialog (mod_adaptivequiz #5).
 *
 * @param stdClass $adaptivequiz
 * @param stdClass $attempt adaptivequiz_attempt record.
 * @return int|null null when the test sets none.
 */
function adaptivequizcatmodel_catquiz_item_time_limit(stdClass $adaptivequiz, stdClass $attempt): ?int {
    if (!method_exists(catquiz_handler::class, 'item_time_limit')) {
        return null;
    }
    return catquiz_handler::item_time_limit((int) $attempt->id);
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

/**
 * Finalises the CAT result when the activity marks an attempt as completed.
 *
 * The activity calls this from the status change, not from the result page. Whether a participant
 * ever reaches that page is up to them; the result has to be persisted either way.
 * attempt_finalizer::finalize() is idempotent, so a later render path calling it again is harmless.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param context_module $context The context of that activity.
 * @param int $userid The user the attempt belongs to.
 * @param stdClass $attempt The completed attempt record.
 */
function adaptivequizcatmodel_catquiz_post_complete_attempt_callback(
    stdClass $adaptivequiz,
    context_module $context,
    int $userid,
    stdClass $attempt
): void {
    $timefinished = (int) ($attempt->timefinished ?? $attempt->timemodified ?? time());

    attempt_finalizer::finalize(
        (int) $attempt->id,
        $timefinished,
        (string) ($attempt->attemptstopcriteria ?? '')
    );
}

/**
 * Removes what CATquiz holds for an attempt the activity has deleted.
 *
 * The activity owns the attempt; CATquiz owns the ability estimates, the progress and the scale
 * results belonging to it. Deleting the attempt without this leaves those rows behind - personal
 * data of a user for an attempt that no longer exists.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param stdClass $attempt The attempt that was deleted.
 */
function adaptivequizcatmodel_catquiz_post_delete_attempt_callback(
    stdClass $adaptivequiz,
    stdClass $attempt
): void {
    \adaptivequizcatmodel_catquiz\local\attempt_cleanup::delete_for_host_attempts([(int) $attempt->id]);
}

/**
 * Whether the owner of a finished attempt may review its questions, files included.
 *
 * The activity asks this before it serves the files of a finished attempt's questions to its
 * owner - for instance in the question modals of the result page, also when the activity has been
 * hidden since. The test decides through its setting to show the questions.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param stdClass $attempt The finished attempt.
 * @return bool
 */
function adaptivequizcatmodel_catquiz_completed_attempt_review_allowed(stdClass $adaptivequiz, stdClass $attempt): bool {
    // An older local_catquiz lacks the class; then nothing is released.
    if (!class_exists(\local_catquiz\local\access\question_review::class)) {
        return false;
    }
    return \local_catquiz\local\access\question_review::released_to_owner((int) $adaptivequiz->id);
}
