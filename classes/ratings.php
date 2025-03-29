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
 * The overflow ratings manager.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow;
use moodle_exception;

/**
 * Static methods for managing the ratings of posts.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ratings
{

    /**
     * Add a rating.
     * This is the basic function to add or edit ratings.
     *
     * @param object $overflow
     * @param int    $postid
     * @param int    $rating
     * @param int   $userid
     *
     * @return bool|int
     */
    public static function overflow_add_rating($overflow, $postid, $rating, $userid)
    {
        global $DB;

        // Is the submitted rating valid?
        $possibleratings = [
            RATING_NEUTRAL,
            RATING_DOWNVOTE,
            RATING_UPVOTE,
            RATING_SOLVED,
            RATING_HELPFUL,
            RATING_REMOVE_DOWNVOTE,
            RATING_REMOVE_UPVOTE,
            RATING_REMOVE_SOLVED,
            RATING_REMOVE_HELPFUL,
        ];
        overflow_throw_exception_with_check(!in_array($rating, $possibleratings), 'invalidratingid');

        // Get the related post.
        $post = overflow_get_record_or_exception('overflow_posts', ['id' => $postid], 'invalidparentpostid');

        // Check if the post belongs to a discussion.
        $discussion = overflow_get_record_or_exception(
            'overflow_discussions',
            ['id' => $post->discussion],
            'notpartofdiscussion'
        );


        // Are multiple marks allowed?
        $markssetting = $DB->get_record('overflow', ['id' => $overflow->id], 'allowmultiplemarks');
        $multiplemarks = (bool) $markssetting->allowmultiplemarks;

        // Retrieve the contexts.
        $context = \context_system::instance();

        // Redirect the user if capabilities are missing.
        if (!self::overflow_user_can_rate($post, $context, $userid)) {

            // Catch unenrolled users.
            $returnurl = '/local/overflow/view.php?m' . $overflow->id;
            overflow_catch_unenrolled_user($context, $returnurl);

            // Notify the user, that he can not post a new discussion.
            throw new moodle_exception('norateoverflow', 'local_overflow');
        }

        // Make sure post author != current user, unless they have permission.
        $authorcheck = ($post->userid == $userid) && !(($rating == RATING_SOLVED || $rating == RATING_REMOVE_SOLVED) &&
            has_capability('local/overflow:marksolved', $context));
        overflow_throw_exception_with_check($authorcheck, 'rateownpost');

        // Check if we are removing a mark.
        if (in_array($rating / 10, $possibleratings)) {
            overflow_get_config_or_exception(
                'overflow',
                'allowratingchange',
                'noratingchangeallowed',
                'overflow'
            );

            // Delete the rating.
            return self::overflow_remove_rating($postid, $rating / 10, $userid, $context);
        }

        // Check for an older rating in this discussion.
        $oldrating = self::overflow_check_old_rating($postid, $userid);

        // Mark a post as solution or as helpful.
        if ($rating == RATING_SOLVED || $rating == RATING_HELPFUL) {
            // Make sure that a helpful mark is made by the user who started the discussion.
            $isnotstartuser = $rating == RATING_HELPFUL && $userid != $discussion->userid;
            overflow_throw_exception_with_check($isnotstartuser, 'nostartuser');

            // Make sure that a solution mark is made by a teacher (or someone with the right capability).
            $isnotteacher = $rating == RATING_SOLVED && !has_capability('local/overflow:marksolved', $context);
            overflow_throw_exception_with_check($isnotteacher, 'notteacher');

            // Check if multiple marks are not enabled.
            if (!$multiplemarks) {

                // Get other ratings in the discussion.
                $sql = "SELECT *
                        FROM {overflow_ratings}
                        WHERE discussionid = ? AND rating = ?";
                $otherrating = $DB->get_record_sql($sql, [$discussion->id, $rating]);

                // If there is an old rating, update it. Else create a new rating record.
                if ($otherrating) {
                    return self::overflow_update_rating_record($post->id, $rating, $userid, $otherrating->id, $context);

                } else {
                    return self::overflow_add_rating_record(
                        $overflow->id,
                        $discussion->id,
                        $post->id,
                        $rating,
                        $userid,
                        $context
                    );
                }
            } else {
                // If multiplemarks are allowed, only create a new rating.
                return self::overflow_add_rating_record(
                    $overflow->id,
                    $discussion->id,
                    $post->id,
                    $rating,
                    $userid,
                    $context
                );
            }
        }

        // Update an rating record.
        if ($oldrating['normal']) {
            overflow_get_config_or_exception(
                'overflow',
                'allowratingchange',
                'noratingchangeallowed',
                'overflow'
            );

            // Check if the rating can still be changed.
            if (!self::overflow_can_be_changed($postid, $oldrating['normal']->rating, $userid)) {
                return false;
            }

            // Update the rating record.
            return self::overflow_update_rating_record($post->id, $rating, $userid, $oldrating['normal']->id, $context);
        }

        // Create a new rating record.
        return self::overflow_add_rating_record(
            $overflow->id,
            $post->discussion,
            $postid,
            $rating,
            $userid,
            $context
        );
    }

    /**
     * Get the reputation of a user.
     *
     * @param int  $overflowid
     * @param int $userid
     * @param bool $forcesinglerating If true you only get the reputation for the given $overflowid,
     * even if widereputation = true
     *
     * @return int
     */
    public static function overflow_get_reputation($overflowid, $userid, $forcesinglerating = false)
    {
        // Check the overflow instance.
        $overflow = overflow_get_record_or_exception(
            'overflow',
            ['id' => $overflowid],
            'invalidoverflowid'
        );

        // Check whether the reputation can be summed over
        if ($overflow->widereputation && !$forcesinglerating) {
            return self::overflow_get_reputation_overflow($overflow->id, $userid);
        }

        // Else return the reputation within this instance.
        return self::overflow_get_reputation_instance($overflow->id, $userid);
    }

    /**
     * Sort the answers of a discussion by their marks, votes and for equal votes by time modified.
     *
     * @param array $posts all the posts from a discussion.
     */
    public static function overflow_sort_answers_by_ratings($posts)
    {
        // Create a copy that only has the answer posts and save the parent post.
        $answerposts = $posts;
        $parentpost = array_shift($answerposts);

        // Create an empty array for the sorted posts and add the parent post.
        $sortedposts = [];
        $sortedposts[0] = $parentpost;

        // Check if solved posts are preferred over helpful posts.
        $solutionspreferred = false;
        if ($posts[array_key_first($posts)]->ratingpreference == 1) {
            $solutionspreferred = true;
        }
        // Build array groups for different types of answers (solved and helpful, only solved/helpful, unmarked).
        $solvedhelpfulposts = [];
        $solvedposts = [];
        $helpfulposts = [];
        $unmarkedposts = [];

        // Sort the answer posts by ratings..
        // markedsolved == 1 means the post is marked as solved.
        // markedhelpful == 1 means the post is marked as helpful.
        // Step 1: Iterate trough the answerposts and assign each post to a group.
        foreach ($answerposts as $post) {
            if ($post->markedsolution > 0) {
                if ($post->markedhelpful > 0) {
                    $solvedhelpfulposts[] = $post;
                } else {
                    $solvedposts[] = $post;
                }
            } else {
                if ($post->markedhelpful > 0) {
                    $helpfulposts[] = $post;
                } else {
                    $unmarkedposts[] = $post;
                }
            }
        }

        // Step 2: Sort each group after their votes and eventually time modified.
        self::overflow_sort_postgroup($solvedhelpfulposts, 0, count($solvedhelpfulposts) - 1);
        self::overflow_sort_postgroup($solvedposts, 0, count($solvedposts) - 1);
        self::overflow_sort_postgroup($helpfulposts, 0, count($helpfulposts) - 1);
        self::overflow_sort_postgroup($unmarkedposts, 0, count($unmarkedposts) - 1);

        // Step 3: Put each group together in the right order depending on the rating preferences.
        $temp = $solutionspreferred ? array_merge($solvedposts, $helpfulposts) : array_merge($helpfulposts, $solvedposts);
        $sortedposts = array_merge($sortedposts, $solvedhelpfulposts, $temp, $unmarkedposts);

        // Rearrange the indices and return the sorted posts.
        $neworder = [];
        foreach ($sortedposts as $post) {
            $neworder[$post->id] = $post;
        }

        // Return now the sorted posts.
        return $neworder;
    }

    /**
     * Did the current user rated the post?
     *
     * @param int  $postid
     * @param null $userid
     *
     * @return mixed
     */
    public static function overflow_user_rated($postid, $userid = null)
    {
        global $DB, $USER;

        // Is a user submitted?
        if (!$userid) {
            $userid = $USER->id;
        }

        // Get the rating.
        $sql = "SELECT firstrated, rating
                  FROM {overflow_ratings}
                  WHERE userid = ? AND postid = ? AND (rating = 1 OR rating = 2)";

        return ($DB->get_record_sql($sql, [$userid, $postid]));
    }

    /**
     * Get the rating of a single post.
     *
     * @param int $postid
     *
     * @return array
     */
    public static function overflow_get_rating($postid)
    {
        global $DB;

        // Retrieve the full post.
        $post = overflow_get_record_or_exception('overflow_posts', ['id' => $postid], 'postnotexist');

        // Get the rating for this single post.
        return self::overflow_get_ratings_by_discussion($post->discussion, $postid);
    }

    /**
     * Get the ratings of all posts in a discussion.
     *
     * @param int  $discussionid
     * @param null $postid
     *
     * @return array
     */
    public static function overflow_get_ratings_by_discussion($discussionid, $postid = null)
    {
        global $DB;

        // Get the amount of votes.
        $sql = "SELECT id as postid,
                       (SELECT COUNT(rating) FROM {overflow_ratings} WHERE postid=p.id AND rating = 1) AS downvotes,
	                   (SELECT COUNT(rating) FROM {overflow_ratings} WHERE postid=p.id AND rating = 2) AS upvotes,
                       (SELECT COUNT(rating) FROM {overflow_ratings} WHERE postid=p.id AND rating = 3) AS issolved,
                       (SELECT COUNT(rating) FROM {overflow_ratings} WHERE postid=p.id AND rating = 4) AS ishelpful
                  FROM {overflow_posts} p
                 WHERE p.discussion = ?
              GROUP BY p.id";
        $votes = $DB->get_records_sql($sql, [$discussionid]);

        // A single post is requested.
        if ($postid) {

            // Check if the post is part of the discussion.
            if (array_key_exists($postid, $votes)) {
                return $votes[$postid];
            }

            // The requested post is not part of the discussion.
            throw new moodle_exception('postnotpartofdiscussion', 'local_overflow');
        }

        // Return the array.
        return $votes;
    }

    /**
     * Check if a discussion is marked as solved or helpful.
     *
     * @param int  $discussionid
     * @param bool $teacher
     *
     * @return bool|mixed
     */
    public static function overflow_discussion_is_solved($discussionid, $teacher = false)
    {
        global $DB;

        // Is the teachers solved-status requested?
        if ($teacher) {

            // Check if a teacher marked a solution as solved.
            if ($DB->record_exists('overflow_ratings', ['discussionid' => $discussionid, 'rating' => 3])) {

                // Return the rating records.
                return $DB->get_records('overflow_ratings', ['discussionid' => $discussionid, 'rating' => 3]);
            }

            // The teacher has not marked the discussion as solved.
            return false;
        }

        // Check if the topic starter marked a solution as helpful.
        if ($DB->record_exists('overflow_ratings', ['discussionid' => $discussionid, 'rating' => 4])) {

            // Return the rating records.
            return $DB->get_records('overflow_ratings', ['discussionid' => $discussionid, 'rating' => 4]);
        }

        // The topic starter has not marked a solution as helpful.
        return false;
    }

    /**
     * Get the reputation of a user within a single instance.
     *
     * @param int  $overflowid
     * @param null $userid
     *
     * @return int
     */
    public static function overflow_get_reputation_instance($overflowid, $userid = null)
    {
        global $DB, $USER;

        // Get the user id.
        if (!isset($userid)) {
            $userid = $USER->id;
        }

        // Check the overflow instance.
        if (!$overflow = $DB->get_record('overflow', ['id' => $overflowid])) {
            throw new moodle_exception('invalidoverflowid', 'local_overflow');
        }

        // Initiate a variable.
        $reputation = 0;
        // Get all posts of this user in this module.
        // Do not count votes for own posts.
        $sql = "SELECT r.id, r.postid as post, r.rating
              FROM {overflow_posts} p
              JOIN {overflow_ratings} r ON p.id = r.postid
              JOIN {overflow} m ON r.overflowid = m.id
             WHERE p.userid = ? AND NOT r.userid = ? AND r.overflowid = ? AND m.anonymous <> ?";

        if ($overflow->anonymous == anonymous::QUESTION_ANONYMOUS) {
            $sql .= " AND p.parent <> 0 ";
        }

        $sql .= "ORDER BY r.postid ASC";

        $params = [$userid, $userid, $overflowid, anonymous::EVERYTHING_ANONYMOUS];
        $records = $DB->get_records_sql($sql, $params);
        // Iterate through all ratings.
        foreach ($records as $record) {
            switch ($record->rating) {
                case RATING_DOWNVOTE:
                    $reputation += get_config('local_overflow', 'votescaledownvote');
                    break;
                case RATING_UPVOTE:
                    $reputation += get_config('local_overflow', 'votescaleupvote');
                    break;
                case RATING_HELPFUL:
                    $reputation += get_config('local_overflow', 'votescalehelpful');
                    break;
                case RATING_SOLVED:
                    $reputation += get_config('local_overflow', 'votescalesolved');
                    break;
            }
        }

        // Get votes this user made.
        // Votes for own posts are not counting.
        $sql = "SELECT COUNT(id) as amount
                FROM {overflow_ratings}
                 WHERE userid = ? AND overflowid = ? AND (rating = 1 OR rating = 2)";
        $params = [$userid, $overflowid];
        $votes = $DB->get_record_sql($sql, $params);

        // Add reputation for the votes.
        $reputation += get_config('local_overflow', 'votescalevote') * $votes->amount;

        // Can the reputation of a user be negative?
        if (!$overflow->allownegativereputation && $reputation <= 0) {
            $reputation = 0;
        }

        // Return the rating of the user.
        return $reputation;
    }

    /**
     * Get the reputation of a user .
     *
     * @param int  $overflowid
     * @param null $userid
     *
     * @return int
     */
    public static function overflow_get_reputation_overflow($overflowid, $userid = null)
    {
        global $USER, $DB;

        // Get the userid.
        if (!isset($userid)) {
            $userid = $USER->id;
        }

        // Initiate a variable.
        $reputation = 0;

        // Get all overflow instances
        $sql = "SELECT id
                    FROM {overflow}
                WHERE id = ?
                    AND widereputation = 1";
        $params = [$overflowid];
        $instances = $DB->get_records_sql($sql, $params);

        // Sum the reputation of each individual instance.
        foreach ($instances as $instance) {
            $reputation += self::overflow_get_reputation_instance($instance->id, $userid);
        }

        // The result does not need to be corrected.
        return $reputation;
    }

    /**
     * Check for all old rating records from a user for a specific post.
     *
     * @param int  $postid
     * @param int  $userid
     * @param null $oldrating
     *
     * @return array|mixed
     */
    private static function overflow_check_old_rating($postid, $userid, $oldrating = null)
    {
        global $DB;

        // Initiate the array.
        $rating = [];

        $sql = "SELECT *
                FROM {overflow_ratings}";
        // Get the normal rating.
        $condition = " WHERE userid = ? AND postid = ? AND (rating = 1 OR rating = 2)";
        $rating['normal'] = $DB->get_record_sql($sql . $condition, [$userid, $postid]);

        // Return the rating if it is requested.
        if ($oldrating == RATING_DOWNVOTE || $oldrating == RATING_UPVOTE) {
            return $rating['normal'];
        }

        // Get the solved rating.
        $condition = " WHERE postid = ? AND rating = 3";
        $rating['solved'] = $DB->get_record_sql($sql . $condition, [$postid]);

        // Return the rating if it is requested.
        if ($oldrating == RATING_SOLVED) {
            return $rating['solved'];
        }

        // Get the helpful rating.
        $condition = " WHERE postid = ? AND rating = 4";
        $rating['helpful'] = $DB->get_record_sql($sql . $condition, [$postid]);

        // Return the rating if it is requested.
        if ($oldrating == RATING_HELPFUL) {
            return $rating['helpful'];
        }

        // Return all ratings.
        return $rating;
    }

    /**
     * Check if the rating can be changed.
     *
     * @param int $postid
     * @param int $rating
     * @param int $userid
     *
     * @return bool
     */
    private static function overflow_can_be_changed($postid, $rating, $userid)
    {
        // Check if the old read record exists.
        $old = self::overflow_check_old_rating($postid, $userid, $rating);
        if (!$old) {
            return false;
        }

        return true;
    }

    /**
     * Removes a rating record.
     * @param int             $postid
     * @param int             $rating
     * @param int             $userid
     * @param \context_system $context
     *
     * @return bool
     */
    private static function overflow_remove_rating($postid, $rating, $userid, $context)
    {
        global $DB;

        // Check if the post can be removed.
        if (!self::overflow_can_be_changed($postid, $rating, $userid)) {
            return false;
        }

        // Get the old rating record.
        $oldrecord = self::overflow_check_old_rating($postid, $userid, $rating);

        // Trigger an event.
        $event = \local_overflow\event\rating_deleted::create(['objectid' => $oldrecord->id, 'context' => $context]);
        $event->add_record_snapshot('overflow_ratings', $oldrecord);
        $event->trigger();

        // Remove the rating record.
        return $DB->delete_records('overflow_ratings', ['id' => $oldrecord->id]);
    }

    /**
     * Add a new rating record.
     *
     * @param int             $overflowid
     * @param int             $discussionid
     * @param int             $postid
     * @param int             $rating
     * @param int             $userid
     * @param \context_system $context
     *
     * @return bool|int
     */
    private static function overflow_add_rating_record($overflowid, $discussionid, $postid, $rating, $userid, $context)
    {
        global $DB;

        // Create the rating record.
        $record = new \stdClass();
        $record->userid = $userid;
        $record->postid = $postid;
        $record->discussionid = $discussionid;
        $record->overflowid = $overflowid;
        $record->rating = $rating;
        $record->firstrated = time();
        $record->lastchanged = time();

        // Add the record to the database.
        $recordid = $DB->insert_record('overflow_ratings', $record);

        // Trigger an event.
        $params = [
            'objectid' => $recordid,
            'context' => $context,
        ];
        $event = \local_overflow\event\rating_created::create($params);
        $event->trigger();

        // Add the record to the database.
        return $recordid;
    }

    /**
     * Update an existing rating record.
     *
     * @param int             $postid
     * @param int             $rating
     * @param int             $userid
     * @param int             $ratingid
     * @param \context_system $context
     *
     * @return bool
     */
    private static function overflow_update_rating_record($postid, $rating, $userid, $ratingid, $context)
    {
        global $DB;

        // Update the record.
        $sql = "UPDATE {overflow_ratings}
                   SET postid = ?, userid = ?, rating=?, lastchanged = ?
                 WHERE id = ?";

        // Trigger an event.
        $params = [
            'objectid' => $ratingid,
            'context' => $context,
        ];
        $event = \local_overflow\event\rating_updated::create($params);
        $event->trigger();

        return $DB->execute($sql, [$postid, $userid, $rating, time(), $ratingid]);
    }

    /**
     * Check if a user can rate the post.
     *
     * @param object $post
     * @param \context_system   $context
     * @param null|int $userid
     *
     * @return bool
     */
    public static function overflow_user_can_rate($post, $context, $userid = null)
    {
        global $USER;
        if (!$userid) {
            // Guests and non-logged-in users can not rate.
            if (isguestuser() || !isloggedin()) {
                return false;
            }
            $userid = $USER->id;
        }

        // Check the capability.
        return capabilities::has(capabilities::RATE_POST, $context, $userid)
            && $post->reviewed == 1;
    }

    /**
     * Helper function for overflow_sort_answer_by_rating. Sorts a group of posts (solved and helpful, only solved/helpful
     * and other) after their votesdifference and if needed after their modified time.
     *
     * @param array $posts  The array that will be sorted
     * @param int   $low    Startindex from where equal votes will be checked
     * @param int   $high   Endindex until where equal votes will be checked
     * @return void
     */
    private static function overflow_sort_postgroup(&$posts, $low, $high)
    {
        // First sort the array after their votesdifference.
        overflow_quick_array_sort($posts, 0, $high, 'votesdifference', 'desc');

        // Check if posts have the same votesdifference and sort them after their modified time if needed.
        while ($low < $high) {
            if ($posts[$low]->votesdifference == $posts[$low + 1]->votesdifference) {
                $tempstartindex = $low;
                $tempendindex = $tempstartindex + 1;
                while (
                    ($tempendindex + 1 <= $high) &&
                    ($posts[$tempendindex]->votesdifference == $posts[$tempendindex + 1]->votesdifference)
                ) {
                    $tempendindex++;
                }
                overflow_quick_array_sort($posts, $tempstartindex, $tempendindex, 'modified', 'asc');
                $low = $tempendindex + 1;
            } else {
                $low++;
            }
        }
    }
}
