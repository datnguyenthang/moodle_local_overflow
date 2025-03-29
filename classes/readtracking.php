<?php
// This file is part of a plugin for Moodle - http://moodle.org/
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
 * overflow readtracking manager.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow;

use context_system;
use moodle_exception;

/**
 * Static methods for managing the tracking of read posts and discussions.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class readtracking {

    /**
     * Determine if a user can track overflows and optionally a particular overflow instance.
     * Checks the site settings and the overflow settings (if requested).
     *
     * @param object $overflow
     *
     * @return boolean
     * */
    public static function overflow_can_track_overflows($overflow = null) {
        global $USER;

        // Check if readtracking is disabled for the module.
        if (!get_config('local_overflow', 'trackreadposts')) {
            return false;
        }

        // Guests are not allowed to track overflows.
        if (isguestuser($USER) || empty($USER->id)) {
            return false;
        }

        // If no specific overflow is submitted, check the modules basic settings.
        if (is_null($overflow)) {
            if (get_config('local_overflow', 'allowforcedreadtracking')) {
                // Since we can force tracking, assume yes without a specific forum.
                return true;
            } else {
                // User tracks overflows by default.
                return true;
            }
        }
        // Check the settings of the overflow instance.
        $allowed = ($overflow->trackingtype == OVERFLOW_TRACKING_OPTIONAL);
        $forced = ($overflow->trackingtype == OVERFLOW_TRACKING_FORCED);

        return ($allowed || $forced);
    }

    /**
     * Tells whether a specific overflow is tracked by the user.
     *
     * @param object      $overflow
     * @param object|null $user
     *
     * @return bool
     */
    public static function overflow_is_tracked($overflow, $user = null) {
        global $USER, $DB;

        // Get the user.
        if (is_null($user)) {
            $user = $USER;
        }

        // Guests cannot track a overflow. The overflow should be generally trackable.
        if (isguestuser($USER) || empty($USER->id) || !self::OVERFLOW_can_track_overflows($overflow)) {
            return false;
        }

        // Check the settings of the overflow instance.
        $allowed = ($overflow->trackingtype == OVERFLOW_TRACKING_OPTIONAL);
        $forced = ($overflow->trackingtype == OVERFLOW_TRACKING_FORCED);

        // Check the preferences of the user.
        $userpreference = $DB->get_record('overflow_tracking',
            ['userid' => $user->id, 'overflowid' => $overflow->id]);

        // Return the boolean.
        if (get_config('local_overflow', 'allowforcedreadtracking')) {
            return ($forced || ($allowed && $userpreference === false));
        } else {
            return (($allowed || $forced) && $userpreference === false);
        }
    }

    /**
     * Marks a specific overflow instance as read by a specific user.
     *
     * @param object $overflow
     * @param null   $userid
     */
    public static function overflow_mark_overflow_read($overflow, $userid = null) {
        global $USER;

        // If no user is submitted, use the current one.
        if (!isset($userid)) {
            $userid = $USER->id;
        }

        // Get all the discussions with unread messages in this overflow instance.
        $discussions = overflow_get_discussions_unread($overflow);

        // Iterate through all of this discussions.
        foreach ($discussions as $discussionid) {
            // Mark the discussion as read.
            $markedcheck = self::overflow_mark_discussion_read($discussionid, context_system::instance(), $userid);
            overflow_throw_exception_with_check($markedcheck !== true, 'markreadfailed');
        }

        return true;
    }

    /**
     * Marks a specific discussion as read by a specific user.
     *
     * @param int  $discussionid
     * @param context_system $context
     * @param int $userid
     */
    public static function overflow_mark_discussion_read($discussionid, $context, $userid = null) {
        global $USER;

        // Get all posts.
        $posts = overflow_get_all_discussion_posts($discussionid, true, $context);

        // If no user is submitted, use the current one.
        if (!isset($userid)) {
            $userid = $USER->id;
        }

        // Iterate through all posts of the discussion.
        foreach ($posts as $post) {

            // Ignore already read posts.
            if (!is_null($post->postread)) {
                continue;
            }

            // Mark the post as read.
            $postreadcheck = self::overflow_mark_post_read($userid, $post);
            overflow_throw_exception_with_check(!$postreadcheck, 'markreadfailed');
        }

        // The discussion has been marked as read.
        return true;
    }

    /**
     * Marks a specific post as read by a specific user.
     *
     * @param int    $userid
     * @param object $post
     *
     * @return bool
     */
    public static function overflow_mark_post_read($userid, $post) {

        // If the post is older than the limit.
        if (self::overflow_is_old_post($post)) {
            return true;
        }

        // Create a new read record.
        return self::overflow_add_read_record($userid, $post->id);
    }

    /**
     * Checks if a post is older than the limit.
     *
     * @param object $post
     *
     * @return bool
     */
    public static function overflow_is_old_post($post) {

        // Transform objects into arrays.
        $post = (array) $post;

        // Get the current time.
        $currenttimestamp = time();

        // Calculate the time, where older posts are considered read.
        $oldposttimestamp = $currenttimestamp - (get_config('local_overflow', 'oldpostdays') * 24 * 3600);

        // Return if the post is newer than that time.
        return ($post['modified'] < $oldposttimestamp);
    }

    /**
     * Mark a post as read by a user.
     *
     * @param int $userid
     * @param int $postid
     *
     * @return bool
     */
    public static function overflow_add_read_record($userid, $postid) {
        global $DB;

        // Get the current time and the cutoffdate.
        $now = time();
        $cutoffdate = $now - (get_config('local_overflow', 'oldpostdays') * 24 * 3600);

        // Check for read records for this user an this post.
        $oldrecord = $DB->get_record('overflow_read', ['postid' => $postid, 'userid' => $userid]);
        if (!$oldrecord) {

            // If there are no old records, create a new one.
            $sql = "INSERT INTO {overflow_read} (userid, postid, discussionid, overflowid, firstread, lastread)
                 SELECT ?, p.id, p.discussion, d.overflow, ?, ?
                   FROM {overflow_posts} p
                        JOIN {overflow_discussions} d ON d.id = p.discussion
                  WHERE p.id = ? AND p.modified >= ?";

            return $DB->execute($sql, [$userid, $now, $now, $postid, $cutoffdate]);
        }

        // Else update the existing one.
        $sql = "UPDATE {overflow_read}
                    SET lastread = ?
                WHERE userid = ? AND postid = ?";

        return $DB->execute($sql, [$now, $userid, $userid]);
    }

    /**
     * Deletes read record for the specified index.
     * At least one parameter must be specified.
     *
     * @param int $userid
     * @param int $postid
     * @param int $discussionid
     * @param int $overflowid
     *
     * @return bool
     */
    public static function overflow_delete_read_records($userid = -1, $postid = -1, $discussionid = -1, $overflowid = -1) {
        global $DB;

        // Initiate variables.
        $params = [];
        $select = '';

        // Create the sql-Statement depending on the submitted parameters.
        if ($userid > -1) {
            if ($select != '') {
                $select .= ' AND ';
            }
            $select .= 'userid = ?';
            $params[] = $userid;
        }
        if ($postid > -1) {
            if ($select != '') {
                $select .= ' AND ';
            }
            $select .= 'postid = ?';
            $params[] = $postid;
        }
        if ($discussionid > -1) {
            if ($select != '') {
                $select .= ' AND ';
            }
            $select .= 'discussionid = ?';
            $params[] = $discussionid;
        }
        if ($overflowid > -1) {
            if ($select != '') {
                $select .= ' AND ';
            }
            $select .= 'overflowid = ?';
            $params[] = $overflowid;
        }

        // Check if at least one parameter was specified.
        if ($select == '') {
            return false;
        } else {
            return $DB->delete_records_select('overflow_read', $select, $params);
        }
    }

    /**
     * Deletes all read records that are related to posts that are older than the cutoffdate.
     * This function is only called by the modules cronjob.
     */
    public static function overflow_clean_read_records() {
        global $DB;

        // Stop if there cannot be old posts.
        if (!get_config('local_overflow', 'oldpostdays')) {
            return;
        }

        // Find the timestamp for records older than allowed.
        $cutoffdate = time() - (get_config('local_overflow', 'oldpostdays') * 24 * 60 * 60);

        // Find the timestamp of the oldest read record.
        // This will speedup the delete query.
        $sql = "SELECT MIN(p.modified) AS first
                FROM {overflow_posts} p
                JOIN {overflow_read} r ON r.postid = p.id";

        // If there is no old read record, end this method.
        if (!$first = $DB->get_field_sql($sql)) {
            return;
        }

        // Delete the old read tracking information between that timestamp and the cutoffdate.
        $sql = "DELETE
                FROM {overflow_read}
                WHERE postid IN (SELECT p.id
                                    FROM {overflow_posts} p
                                    WHERE p.modified >= ? AND p.modified < ?)";
        $DB->execute($sql, [$first, $cutoffdate]);
    }

    /**
     * Stop to track a overflow instance.
     *
     * @param int $overflowid The overflow ID
     * @param int $userid           The user ID
     *
     * @return bool Whether the deletion was successful
     */
    public static function overflow_stop_tracking($overflowid, $userid = null) {
        global $USER, $DB;

        // Set the user.
        if (is_null($userid)) {
            $userid = $USER->id;
        }

        // Check if the user already stopped to track the overflow.
        $params = ['userid' => $userid, 'overflowid' => $overflowid];
        $isstopped = $DB->record_exists('overflow_tracking', $params);

        // Stop tracking the overflow if not already stopped.
        if (!$isstopped) {

            // Create the tracking object.
            $tracking = new \stdClass();
            $tracking->userid = $userid;
            $tracking->overflowid = $overflowid;

            // Insert into the database.
            $DB->insert_record('overflow_tracking', $params);
        }

        // Delete all connected read records.
        $deletion = self::overflow_delete_read_records($userid, -1, -1, $overflowid);

        // Return whether the deletion was successful.
        return $deletion;
    }

    /**
     * Start to track a overflow instance.
     *
     * @param int $overflowid The overflow ID
     * @param int $userid           The user ID
     *
     * @return bool Whether the deletion was successful
     */
    public static function overflow_start_tracking($overflowid, $userid = null) {
        global $USER, $DB;

        // Get the current user.
        if (is_null($userid)) {
            $userid = $USER->id;
        }

        // Delete the tracking setting of this user for this overflow.
        return $DB->delete_records('overflow_tracking', ['userid' => $userid, 'overflowid' => $overflowid]);
    }

    /**
     * Get a list of forums not tracked by the user.
     *
     * @param int $userid   The user ID
     * @param int $overflowid The overflow ID
     *
     * @return array Array with untracked overflows
     */
    public static function get_untracked_overflows($userid, $overflowid) {
        global $DB;

        $trackingsql = '1=1';

        // Check whether readtracking may be forced.
        if (get_config('local_overflow', 'allowforcedreadtracking')) {

            // Create a part of a sql-statement.
            $trackingsql .= " AND (m.trackingtype = " . OVERFLOW_TRACKING_OFF . "
                            OR (m.trackingtype = " . OVERFLOW_TRACKING_OPTIONAL . " AND mt.id IS NOT NULL))";
        } else {
            // Readtracking may be forced.

            // Create another sql-statement.
            $trackingsql .= " AND (m.trackingtype = " . OVERFLOW_TRACKING_OFF .
                " OR ((m.trackingtype = " . OVERFLOW_TRACKING_OPTIONAL .
                " OR m.trackingtype = " . OVERFLOW_TRACKING_FORCED . ") AND mt.id IS NOT NULL))";
        }

        // Create the sql-queryx.
        $sql = "SELECT m.id
                    FROM {overflow} m
                LEFT JOIN {overflow_tracking} mt ON (mt.overflowid = m.id AND mt.userid = ?)
                    WHERE $trackingsql";

        // Get all untracked overflows from the database.
        $overflows = $DB->get_records_sql($sql, [$userid]);

        // Check whether there are no untracked overflows.
        if (!$overflows) {
            return [];
        }

        // Loop through all overflows.
        foreach ($overflows as $overflow) {
            $overflows[$overflow->id] = $overflow;
        }

        // Return all untracked overflows.
        return $overflows;
    }

    /**
     * Get number of unread posts in a overflow instance.
     *
     * @param int    $overflowid
     *
     * @return int|mixed
     */
    public static function overflow_count_unread_posts_overflow($overflowid) {
        global $DB, $USER;

        $overflow = $DB->get_record_sql("SELECT m.*, tm.id as hasdisabledtracking " .
                "FROM {overflow} m " .
                "LEFT JOIN {overflow_tracking} tm ON m.id = tm.overflowid AND tm.userid = :userid " .
                "WHERE m.id = :overflowid", ['userid' => $USER->id, 'overflowid' => $overflowid]);

        // Return if tracking is off, or ((optional or forced, but forced disallowed by admin) and user has disabled tracking).
        if ($overflow->trackingtype == OVERFLOW_TRACKING_OFF || (
                        ($overflow->trackingtype == OVERFLOW_TRACKING_OPTIONAL || (
                                        $overflow->trackingtype == OVERFLOW_TRACKING_FORCED &&
                                        !get_config('local_overflow', 'allowforcedreadtracking')
                                )
                        ) && $overflow->hasdisabledtracking)) {
            return 0;
        }
        // Get the current timestamp and the cutoffdate.
        $now = round(time(), -2);
        $cutoffdate = $now - (get_config('local_overflow', 'oldpostdays') * 24 * 60 * 60);

        // Define a sql-query.
        $params = [$USER->id, $overflowid, $cutoffdate];
        $sql = "SELECT COUNT(p.id)
                  FROM {overflow_posts} p
                  JOIN {overflow_discussions} d ON p.discussion = d.id
             LEFT JOIN {overflow_read} r ON (r.postid = p.id AND r.userid = ?)
                 WHERE d.overflow = ? AND p.modified >= ? AND r.id IS NULL";

        // Return the number of unread posts per overflow.
        return $DB->get_field_sql($sql, $params);
    }
}
