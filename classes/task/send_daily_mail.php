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
 * Task schedule configuration for the plugintype_pluginname plugin.
 *
 * @package   local_overflow
 * @copyright 2023, Tamaro Walter
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_overflow\task;

/**
 * This task sends a daily mail of unread posts
 */
class send_daily_mail extends \core\task\scheduled_task
{

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name()
    {
        return get_string('tasksenddailymail', 'local_overflow');
    }

    /**
     * Execute the task.
     */
    public function execute()
    {
        global $DB;

        // Call your own api.
        $users = $DB->get_records_sql('SELECT DISTINCT userid FROM {overflow_mail_info}');
        if (empty($users)) {
            mtrace('No daily mail to send.');
            return;
        }
        // Go through each user that has unread posts.
        foreach ($users as $user) {
            // Sorts the records  
            $userdata = $DB->get_records('overflow_mail_info', ['userid' => $user->userid], 'forumid');
            $mail = [];
            // Fill the $mail array.
            foreach ($userdata as $row) {
                $currentcourse = $DB->get_record('course', ['id' => $row->courseid], 'fullname, id');
                // Check if the user is enrolled in the course, if not, go to the next row.
                if (!is_enrolled(\context_course::instance($row->courseid), $user->userid, '', true)) {
                    continue;
                }

                $currentforum = $DB->get_record('overflow', ['id' => $row->forumid], 'name, id');
                $coursemoduleid = get_coursemodule_from_instance('overflow', $row->forumid);
                $discussion = $DB->get_record('overflow_discussions', ['id' => $row->forumdiscussionid], 'name, id');
                $unreadposts = $row->numberofposts;

                // Build url to the course, forum, and discussion.
                $linktocourse = new \moodle_url('/course/view.php', ['id' => $currentcourse->id]);
                $linktoforum = new \moodle_url('/local/overflow/view.php', ['id' => $coursemoduleid->id]);
                $linktodiscussion = new \moodle_url('/local/overflow/discussion.php', ['d' => $discussion->id]);

                // Now change the url to a clickable html link.
                $linktocourse = \html_writer::link($linktocourse->out(), $currentcourse->fullname);
                $linktoforum = \html_writer::link($linktoforum->out(), $currentforum->name);
                $linktodiscussion = \html_writer::link($linktodiscussion->out(), $discussion->name);

                // Build a single line string with the digest information and add it to the mailarray.
                $string = get_string('digestunreadpost', 'local_overflow', [
                    'linktocourse' => $linktocourse,
                    'linktoforum' => $linktoforum,
                    'linktodiscussion' => $linktodiscussion,
                    'unreadposts' => $unreadposts,
                ]);
                $mail[] = $string;
            }
            // Build the final message and send it to user. Then remove the sent records.
            $message = implode('<br>', $mail);
            $userto = $DB->get_record('user', ['id' => $user->userid]);
            $from = \core_user::get_noreply_user();
            $subject = get_string('tasksenddailymail', 'local_overflow');
            email_to_user($userto, $from, $subject, $message);
            $DB->delete_records('overflow_mail_info', ['userid' => $user->userid]);
        }
    }
}
