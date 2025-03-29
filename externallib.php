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
 * External overflow API
 *
 * @package    local_overflow
 * @copyright  2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_overflow\output\overflow_email;
use local_overflow\review;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");
require_once($CFG->dirroot . '/local/overflow/locallib.php');

/**
 * Class implementing the external API, esp. for AJAX functions.
 *
 * @package    local_overflow
 * @copyright  2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_overflow_external extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function record_vote_parameters() {
        return new external_function_parameters(
            [
                'postid' => new external_value(PARAM_INT, 'id of post'),
                'ratingid' => new external_value(PARAM_INT, 'rating'),
            ]
        );
    }

    /**
     * Returns the result of the vote (new rating and reputations).
     * @return external_multiple_structure
     */
    public static function record_vote_returns() {
        return new external_single_structure(
            [
                'postrating' => new external_value(PARAM_INT, 'new post rating'),
                'ownerreputation' => new external_value(PARAM_INT, 'new reputation of post owner'),
                'raterreputation' => new external_value(PARAM_INT, 'new reputation of rater'),
                'ownerid' => new external_value(PARAM_INT, 'user id of post owner'),
            ]
        );
    }

    /**
     * Records upvotes and downvotes.
     *
     * @param int $postid ID of post
     * @param int $ratingid Rating value
     * @return array with updated information about rating /reputation
     */
    public static function record_vote($postid, $ratingid) {
        global $DB, $USER;

        // Parameter validation.
        $params = self::validate_parameters(self::record_vote_parameters(), [
            'postid' => $postid,
            'ratingid' => $ratingid,
        ]);

        $transaction = $DB->start_delegated_transaction();

        $post = $DB->get_record('overflow_posts', ['id' => $params['postid']], '*', MUST_EXIST);

        // Check if the discussion is valid.
        $discussion = overflow_get_record_or_exception('overflow_discussions', ['id' => $post->discussion],
                                                             'invaliddiscussionid');

        // Check if the related overflow instance is valid.
        $overflow = overflow_get_record_or_exception('overflow', ['id' => $discussion->overflow],
                                                                 'invalidoverflowid');


        // Security checks.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/overflow:ratepost', $context);

        // Rate the post.
        if (!\local_overflow\ratings::overflow_add_rating($overflow,
            $params['postid'], $params['ratingid'], $USER->id)) {
            throw new moodle_exception('ratingfailed', 'local_overflow');
        }

        $post = overflow_get_post_full($params['postid']);
        $postownerid = $post->userid;
        $rating = \local_overflow\ratings::overflow_get_ratings_by_discussion($discussion->id,
            $params['postid']);
        $ownerrating = \local_overflow\ratings::overflow_get_reputation($overflow->id, $postownerid);
        $raterrating = \local_overflow\ratings::overflow_get_reputation($overflow->id, $USER->id);

        $cannotseeowner = \local_overflow\anonymous::is_post_anonymous($discussion, $overflow, $USER->id) &&
            $USER->id != $postownerid;

        $params['postrating'] = $rating->upvotes - $rating->downvotes;
        $params['ownerreputation'] = $cannotseeowner ? null : $ownerrating;
        $params['raterreputation'] = $raterrating;
        $params['ownerid'] = $cannotseeowner ? null : $postownerid;

        $transaction->allow_commit();

        overflow_update_user_grade($overflow, $ownerrating, $postownerid);
        overflow_update_user_grade($overflow, $raterrating, $USER->id);

        return $params;
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters
     */
    public static function review_approve_post_parameters() {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'id of post'),
        ]);
    }

    /**
     * Returns description of return value.
     * @return external_value
     */
    public static function review_approve_post_returns() {
        return new external_value(PARAM_TEXT, 'the url of the next post to review');
    }

    /**
     * Approve a post.
     *
     * @param int $postid ID of post to approve.
     * @return string|null Url of next post to review.
     */
    public static function review_approve_post($postid) {
        global $DB;

        $params = self::validate_parameters(self::review_approve_post_parameters(), ['postid' => $postid]);
        $postid = $params['postid'];

        $post = $DB->get_record('overflow_posts', ['id' => $postid], '*', MUST_EXIST);
        $discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
        $overflow = $DB->get_record('overflow', ['id' => $discussion->overflow], '*', MUST_EXIST);

        $context = context_system::instance();

        require_capability('local/overflow:reviewpost', $context);

        if ($post->reviewed) {
            throw new coding_exception('post was already approved!');
        }

        if (!review::is_post_in_review_period($post)) {
            throw new coding_exception('post is not yet in review period!');
        }

        $post->reviewed = 1;
        $post->timereviewed = time();

        $DB->update_record('overflow_posts', $post);

        if ($post->modified > $discussion->timemodified) {
            $discussion->timemodified = $post->modified;
            $discussion->usermodified = $post->userid;
            $DB->update_record('overflow_discussions', $discussion);
        }

        return review::get_first_review_post($overflow->id, $post->id);
    }

    /**
     * Returns description of method parameters.
     * @return external_function_parameters
     */
    public static function review_reject_post_parameters() {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'id of post'),
            'reason' => new external_value(PARAM_RAW, 'reason of rejection'),
        ]);
    }

    /**
     * Returns description of return value.
     * @return external_value
     */
    public static function review_reject_post_returns() {
        return new external_value(PARAM_TEXT, 'the url of the next post to review');
    }

    /**
     * Rejects a post.
     *
     * @param int $postid ID of post to reject.
     * @param string|null $reason The reason for rejection.
     * @return string|null Url of next post to review.
     */
    public static function review_reject_post($postid, $reason = null) {
        global $DB, $PAGE, $OUTPUT;

        $params = self::validate_parameters(self::review_reject_post_parameters(), ['postid' => $postid, 'reason' => $reason]);
        $postid = $params['postid'];

        $post = $DB->get_record('overflow_posts', ['id' => $postid], '*', MUST_EXIST);
        $discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
        $overflow = $DB->get_record('overflow', ['id' => $discussion->overflow], '*', MUST_EXIST);
        
        $context = context_system::instance();

        $PAGE->set_context($context);

        require_capability('local/overflow:reviewpost', $context);

        if ($post->reviewed) {
            throw new coding_exception('post was already approved!');
        }

        if (!review::is_post_in_review_period($post)) {
            throw new coding_exception('post is not yet in review period!');
        }

        // Has to be done before deleting the post.
        $rendererhtml = $PAGE->get_renderer('local_overflow', 'email', 'htmlemail');
        $renderertext = $PAGE->get_renderer('local_overflow', 'email', 'textemail');

        $userto = core_user::get_user($post->userid);

        $maildata = new overflow_email(
                $overflow,
                $discussion,
                $post,
                $userto,
                $userto,
                false
        );

        $textcontext = $maildata->export_for_template($renderertext, true);
        $htmlcontext = $maildata->export_for_template($rendererhtml, false);

        if ($params['reason'] ?? null) {
            $htmlcontext['reason'] = format_text_email($params['reason'], FORMAT_PLAIN);
            $textcontext['reason'] = $htmlcontext['reason'];
        }

        email_to_user(
                $userto,
                \core_user::get_noreply_user(),
                get_string('email_rejected_subject', 'local_overflow', $textcontext),
                $OUTPUT->render_from_template('local_overflow/email_rejected_text', $textcontext),
                $OUTPUT->render_from_template('local_overflow/email_rejected_html', $htmlcontext)
        );

        $url = review::get_first_review_post($overflow->id, $post->id);

        if (!$post->parent) {
            // Delete discussion, if this is the question.
            overflow_delete_discussion($discussion, $overflow);
        } else {
            overflow_delete_post($post, true, $overflow);
        }

        return $url;
    }
}
