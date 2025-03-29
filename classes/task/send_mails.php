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
 * A scheduled task for overflow cron.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow\task;

use core\session\exception;
use local_overflow\output\overflow_email;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../../locallib.php');

/**
 * Class for sending mails to users who have subscribed a overflow.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_mails extends \core\task\scheduled_task
{

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name()
    {
        return get_string('tasksendmails', 'local_overflow');
    }

    /**
     * Runs overflow cron.
     */
    public function execute()
    {

        // Send mail notifications.
        overflow_send_mails();

        $this->send_review_notifications();

        // The cron is finished.
        return true;

    }

    /**
     * Sends initial notifications for needed reviews to all users with review capability.
     */
    public function send_review_notifications()
    {
        global $DB, $OUTPUT, $PAGE, $CFG;

        $rendererhtml = $PAGE->get_renderer('local_overflow', 'email', 'htmlemail');
        $renderertext = $PAGE->get_renderer('local_overflow', 'email', 'textemail');

        $postinfos = $DB->get_records_sql(
            'SELECT p.*, d.overflow as mid, d.id as did FROM {overflow_posts} p ' .
            'JOIN {overflow_discussions} d ON p.discussion = d.id ' .
            "WHERE p.mailed = :mailpending AND p.reviewed = 0 AND p.created < :timecutoff " .
            "ORDER BY d.overflow, d.id",
            [
                'mailpending' => overflow_MAILED_PENDING,
                'timecutoff' => time() - get_config('local_overflow', 'reviewpossibleaftertime'),
            ]
        );

        if (empty($postinfos)) {
            mtrace('No review notifications to send.');
            return;
        }

        $overflow = null;
        $usersto = null;
        $discussion = null;
        $success = [];

        foreach ($postinfos as $postinfo) {

            if ($discussion == null || $discussion->id != $postinfo->did) {
                $discussion = $DB->get_record('overflow_discussions', ['id' => $postinfo->did], '*', MUST_EXIST);
            }

            $post = $postinfo;
            $userfrom = \core_user::get_user($postinfo->userid, '*', MUST_EXIST);

            foreach ($usersto as $userto) {
                try {
                    // Check for moodle version. Version 401 supported until 8 December 2025.
                    if ($CFG->branch >= 402) {
                        \core\cron::setup_user($userto);
                    } else {
                        cron_setup_user($userto);
                    }

                    $maildata = new overflow_email(
                        $overflow,
                        $discussion,
                        $post,
                        $userfrom,
                        $userto,
                        false
                    );

                    $textcontext = $maildata->export_for_template($renderertext, true);
                    $htmlcontext = $maildata->export_for_template($rendererhtml, false);

                    email_to_user(
                        $userto,
                        \core_user::get_noreply_user(),
                        get_string('email_review_needed_subject', 'local_overflow', $textcontext),
                        $OUTPUT->render_from_template('local_overflow/email_review_needed_text', $textcontext),
                        $OUTPUT->render_from_template('local_overflow/email_review_needed_html', $htmlcontext)
                    );
                } catch (exception $e) {
                    mtrace("Error sending review notification for post $post->id to user $userto->id!");
                }
            }
            $success[] = $post->id;
        }

        if (!empty($success)) {
            list($insql, $inparams) = $DB->get_in_or_equal($success);
            $DB->set_field_select(
                'overflow_posts',
                'mailed',
                overflow_MAILED_REVIEW_SUCCESS,
                'id ' . $insql,
                $inparams
            );
            mtrace('Sent review notifications for ' . count($success) . ' posts successfully!');
        }
    }

}

