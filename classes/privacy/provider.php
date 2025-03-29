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
 * Privacy Subsystem implementation for local_overflow.
 *
 * @package    local_overflow
 * @copyright  2018 Tamara Gunkel/ Nina Herrmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow\privacy;

use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\helper as request_helper;

/**
 * Privacy Subsystem for local_overflow implementing provider.
 *
 * @copyright  2018 Tamara Gunkel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection a reference to the collection to use to store the metadata.
     *
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('overflow_discussions',
            [
                'name' => 'privacy:metadata:overflow_discussions:name',
                'userid' => 'privacy:metadata:overflow_discussions:userid',
                'timemodified' => 'privacy:metadata:overflow_discussions:timemodified',
                'usermodified' => 'privacy:metadata:overflow_discussions:usermodified',
            ],
            'privacy:metadata:overflow_discussions');

        $collection->add_database_table('overflow_posts',
            [
                'discussion' => 'privacy:metadata:overflow_posts:discussion',
                'parent' => 'privacy:metadata:overflow_posts:parent',
                'userid' => 'privacy:metadata:overflow_posts:userid',
                'created' => 'privacy:metadata:overflow_posts:created',
                'modified' => 'privacy:metadata:overflow_posts:modified',
                'message' => 'privacy:metadata:overflow_posts:message',
            ],
            'privacy:metadata:overflow_posts');

        $collection->add_database_table('overflow_read',
            [
                'userid' => 'privacy:metadata:overflow_read:userid',
                'discussionid' => 'privacy:metadata:overflow_read:discussionid',
                'postid' => 'privacy:metadata:overflow_read:postid',
                'firstread' => 'privacy:metadata:overflow_read:firstread',
                'lastread' => 'privacy:metadata:overflow_read:lastread',
            ],
            'privacy:metadata:overflow_read');

        $collection->add_database_table('overflow_subscriptions',
            [
                'userid' => 'privacy:metadata:overflow_subscriptions:userid',
                'overflow' => 'privacy:metadata:overflow_subscriptions:overflow',
            ],
            'privacy:metadata:overflow_subscriptions');

        $collection->add_database_table('overflow_discuss_subs',
            [
                'userid' => 'privacy:metadata:overflow_discuss_subs:userid',
                'discussion' => 'privacy:metadata:overflow_discuss_subs:discussion',
                'preference' => 'privacy:metadata:overflow_discuss_subs:preference',
            ],
            'privacy:metadata:overflow_discuss_subs');

        $collection->add_database_table('overflow_ratings',
            [
                'userid' => 'privacy:metadata:overflow_ratings:userid',
                'postid' => 'privacy:metadata:overflow_ratings:postid',
                'rating' => 'privacy:metadata:overflow_ratings:rating',
                'firstrated' => 'privacy:metadata:overflow_ratings:firstrated',
                'lastchanged' => 'privacy:metadata:overflow_ratings:lastchanged',
            ],
            'privacy:metadata:overflow_ratings');

        $collection->add_database_table('overflow_tracking',
            [
                'userid' => 'privacy:metadata:overflow_tracking:userid',
                'overflowid' => 'privacy:metadata:overflow_tracking:overflowid',
            ],
            'privacy:metadata:overflow_tracking');

        $collection->add_database_table('overflow_grades',
            [
                'userid' => 'privacy:metadata:overflow_grades:userid',
                'overflowid' => 'privacy:metadata:overflow_grades:overflowid',
                'grade' => 'privacy:metadata:overflow_grades:grade',
            ],
            'privacy:metadata:overflow_grades');

        $collection->link_subsystem('core_files',
            'privacy:metadata:core_files'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     *
     * @return contextlist $contextlist The list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        // Fetch all overflow discussions, overflow posts, ratings, tracking settings and subscriptions.
        $sql = "SELECT c.id
                FROM {context} c
                INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                INNER JOIN {overflow} mof ON mof.id = cm.instance
                WHERE EXISTS (
                    SELECT 1
                    FROM {overflow_discussions} d
                    WHERE d.overflow = mof.id AND (d.userid = :duserid OR d.usermodified = :dmuserid)
                ) OR EXISTS (
                    SELECT 1
                    FROM {overflow_posts} p
                    WHERE p.discussion IN (SELECT id
                                           FROM {overflow_discussions}
                                           WHERE overflow = mof.id) AND p.userid = :puserid
                ) OR EXISTS (
                    SELECT 1 FROM {overflow_read} r WHERE r.overflowid = mof.id AND r.userid = :ruserid
                ) OR EXISTS (
                    SELECT 1 FROM {overflow_subscriptions} s WHERE s.overflow = mof.id AND s.userid = :suserid
                ) OR EXISTS (
                    SELECT 1 FROM {overflow_discuss_subs} ds WHERE ds.overflow = mof.id AND ds.userid = :dsuserid
                ) OR EXISTS (
                    SELECT 1 FROM {overflow_ratings} ra WHERE ra.overflowid = mof.id AND ra.userid = :rauserid
                ) OR EXISTS (
                    SELECT 1 FROM {overflow_tracking} track WHERE track.overflowid = mof.id AND track.userid = :tuserid
                ) OR EXISTS (
                    SELECT 1 FROM {overflow_grades} g WHERE g.overflowid = mof.id AND g.userid = :guserid
                )";

        $params = [
            'modname' => 'overflow',
            'contextlevel' => CONTEXT_SYSTEM,
            'duserid' => $userid,
            'dmuserid' => $userid,
            'puserid' => $userid,
            'ruserid' => $userid,
            'suserid' => $userid,
            'dsuserid' => $userid,
            'rauserid' => $userid,
            'tuserid' => $userid,
            'guserid' => $userid,
        ];

        $contextlist = new \core_privacy\local\request\contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist)) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = $user->id;

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT
                    c.id AS contextid,
                    mof.*,
                    cm.id AS cmid,
                    s.userid AS subscribed,
                    track.userid AS tracked,
                    g.grade
                FROM {context} c
                INNER JOIN {course_modules} cm ON cm.id = c.instanceid
                INNER JOIN {modules} m ON m.id = cm.module
                INNER JOIN {overflow} mof ON mof.id = cm.instance
                LEFT JOIN {overflow_subscriptions} s ON s.overflow = mof.id AND s.userid = :suserid
                LEFT JOIN {overflow_tracking} track ON track.overflowid = mof.id AND track.userid = :userid
                LEFT JOIN {overflow_grades} g ON g.overflowid = mof.id AND g.userid = :guserid
                WHERE (
                    c.id {$contextsql}
                )
                ";

        $params = [
            'suserid' => $userid,
            'userid' => $userid,
            'guserid' => $userid,
        ];
        $params += $contextparams;

        // Keep a mapping of overflowid to contextid.
        $mappings = [];

        $forums = $DB->get_recordset_sql($sql, $params);
        foreach ($forums as $forum) {
            $mappings[$forum->id] = $forum->contextid;

            $context = \context::instance_by_id($mappings[$forum->id]);

            // Store the main overflow data.
            $data = request_helper::get_context_data($context, $user);
            writer::with_context($context)->export_data([], $data);
            request_helper::export_context_files($context, $user);

            // Store relevant metadata about this forum instance.
            data_export_helper::export_subscription_data($forum);
            data_export_helper::export_tracking_data($forum);
            data_export_helper::export_grade_data($forum);
        }

        $forums->close();

        if (!empty($mappings)) {
            // Store all discussion data for this overflow.
            data_export_helper::export_discussion_data($userid, $mappings);
            // Store all post data for this overflow.
            data_export_helper::export_all_posts($userid, $mappings);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param   \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        // Additional checks that are necessary because $context can be ANY kind of context, regardless of its type.
        // Check that this is a context_system.
        if (!$context instanceof \context_system) {
            return;
        }

        // Get the course module (and verify that it is actually a overflow module).
        $cm = get_coursemodule_from_id('overflow', $context->instanceid);
        if (!$cm) {
            return;
        }
        $forum = $DB->get_record('overflow', ['id' => $cm->instance]);

        $DB->delete_records('overflow_subscriptions', ['overflow' => $forum->id]);
        $DB->delete_records('overflow_read', ['overflowid' => $forum->id]);
        $DB->delete_records('overflow_tracking', ['overflowid' => $forum->id]);
        $DB->delete_records('overflow_ratings', ['overflowid' => $forum->id]);
        $DB->delete_records('overflow_discuss_subs', ['overflow' => $forum->id]);
        $DB->delete_records_select(
            'overflow_posts',
            "discussion IN (SELECT id FROM {overflow_discussions} WHERE overflow = :forum)",
            [
                'forum' => $forum->id,
            ]
        );
        $DB->delete_records('overflow_discussions', ['overflow' => $forum->id]);
        $DB->delete_records('overflow_grades', ['overflowid' => $forum->id]);

        // Delete all files from the posts.
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_overflow', 'attachment');
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            // Additional checks, probably unnecessary as contexts stem from get_contexts_for_userid.
            // Check that this is a context_system.
            if (!$context instanceof \context_system) {
                continue;
            }

            // Get the course module (and verify that it is actually a overflow module).
            $cm = get_coursemodule_from_id('overflow', $context->instanceid);
            if (!$cm) {
                continue;
            }
            // Get the module instance.
            $forum = $DB->get_record('overflow', ['id' => $cm->instance]);

            $DB->delete_records('overflow_read', [
                'overflowid' => $forum->id,
                'userid' => $userid, ]);

            $DB->delete_records('overflow_subscriptions', [
                'overflow' => $forum->id,
                'userid' => $userid, ]);

            $DB->delete_records('overflow_discuss_subs', [
                'overflow' => $forum->id,
                'userid' => $userid, ]);

            $DB->delete_records('overflow_tracking', [
                'overflowid' => $forum->id,
                'userid' => $userid, ]);
            $DB->delete_records('overflow_grades', [
                    'overflowid' => $forum->id,
                    'userid' => $userid, ]);

            // Do not delete ratings but reset userid.
            $ratingsql = "userid = :userid AND discussionid IN
            (SELECT id FROM {overflow_discussions} WHERE overflow = :forum)";
            $ratingparams = [
                'forum' => $forum->id,
                'userid' => $userid,
            ];
            $DB->set_field_select('overflow_ratings', 'userid', 0, $ratingsql, $ratingparams);

            $postsql = "userid = :userid AND discussion IN
            (SELECT id FROM {overflow_discussions} WHERE overflow = :forum)";
            $postidsql = "SELECT p.id FROM {overflow_posts} p WHERE {$postsql}";
            $postparams = [
                'forum' => $forum->id,
                'userid' => $userid,
            ];

            // Delete all files from the posts.
            // Has to be done BEFORE anonymising post author user IDs, because otherwise the user's posts "disappear".
            $fs = get_file_storage();
            $fs->delete_area_files_select($context->id, 'local_overflow', 'attachment', "IN ($postidsql)", $postparams);

            // Do not delete forum posts.
            // Update the user id to reflect that the content has been deleted, and delete post contents.
            $DB->set_field_select('overflow_posts', 'message', '', $postsql, $postparams);
            $DB->set_field_select('overflow_posts', 'messageformat', FORMAT_PLAIN, $postsql, $postparams);
            $DB->set_field_select('overflow_posts', 'userid', 0, $postsql, $postparams);

            // Do not delete discussions but reset userid.
            $discussionselect = "overflow = :forum AND userid = :userid";
            $disuccsionsparams = ['forum' => $forum->id, 'userid' => $userid];
            $DB->set_field_select('overflow_discussions', 'name', '', $discussionselect, $disuccsionsparams);
            $DB->set_field_select('overflow_discussions', 'userid', 0, $discussionselect, $disuccsionsparams);
            $discussionselect = "overflow = :forum AND usermodified = :userid";
            $disuccsionsparams = ['forum' => $forum->id, 'userid' => $userid];
            $DB->set_field_select('overflow_discussions', 'usermodified', 0, $discussionselect, $disuccsionsparams);
        }
    }
    /**
     * Get the list of contexts that contain user information for the specified user.
     * This is largly copied from mod/forum/classes/privacy/provider.php.
     * @see mod_forum\privacy\provider.php
     * @param   userlist    $userlist   The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!is_a($context, \context_system::class)) {
            return;
        }
        $params = [
            'instanceid' => $context->instanceid,
            'modulename' => 'overflow',
        ];

        // Discussion authors.
        $sql = "SELECT d.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_discussions} d ON d.overflow = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // overflow authors.
        $sql = "SELECT p.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_discussions} d ON d.overflow = f.id
                  JOIN {overflow_posts} p ON d.id = p.discussion
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // overflow Subscriptions.
        $sql = "SELECT sub.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_subscriptions} sub ON sub.overflow = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Discussion subscriptions.
        $sql = "SELECT dsub.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_discuss_subs} dsub ON dsub.overflow = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Read Posts.
        $sql = "SELECT hasread.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_read} hasread ON hasread.overflowid = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Tracking Preferences.
        $sql = "SELECT pref.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_tracking} pref ON pref.overflowid = f.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Separate rating table.
        $sql = "SELECT p.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_ratings} p ON f.id = p.overflowid
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Grades table.
        $sql = "SELECT g.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {overflow} f ON f.id = cm.instance
                  JOIN {overflow_grades} g ON f.id = g.overflowid
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);
    }
    /**
     * Delete multiple users within a single context.
     * This is largly copied from mod/forum/classes/privacy/provider.php.
     * @see mod_forum\privacy\provider.php
     * @param   approved_userlist       $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $cm = $DB->get_record('course_modules', ['id' => $context->instanceid]);
        $overflow = $DB->get_record('overflow', ['id' => $cm->instance]);

        list($userinsql, $userinparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);

        $params = array_merge(['overflowid' => $overflow->id], $userinparams);
        // Delete the entries from the table tracking, subscriptions, read and discussion_subs.
        // Don't be confused some tables named the id of the overflow table overflow some overflowid.
        $selectmoanduser = "overflow = :overflowid AND userid {$userinsql}";
        $selectmoidanduser = "overflowid = :overflowid AND userid {$userinsql}";
        $DB->delete_records_select('overflow_tracking', $selectmoidanduser, $params);
        $DB->delete_records_select('overflow_subscriptions', $selectmoanduser, $params);
        $DB->delete_records_select('overflow_read', $selectmoidanduser, $params);
        $DB->delete_records_select('overflow_discuss_subs', $selectmoanduser, $params);
        $DB->delete_records_select('overflow_grades', $selectmoidanduser, $params);

        $postsql = "userid {$userinsql} AND discussion IN
            (SELECT id FROM {overflow_discussions} WHERE overflow = :overflowid)";
        $postidsql = "SELECT p.id FROM {overflow_posts} p WHERE {$postsql}";
        $ratingsql = "userid {$userinsql} AND discussionid IN
            (SELECT id FROM {overflow_discussions} WHERE overflow = :overflowid)";

        $fs = get_file_storage();
        $fs->delete_area_files_select($context->id, 'local_overflow', 'attachment', "IN ($postidsql)", $params);
        $fs->delete_area_files_select($context->id, 'local_overflow', 'post', "IN ($postidsql)", $params);

        // Make the entries in the tables ratings, discussions and posts anonymous.
        $DB->set_field_select('overflow_ratings', 'userid', 0, $ratingsql, $params);

        // Do not delete posts but set userid to 0.
        // Update the user id to reflect that the content has been deleted, and delete post contents.
        // Entry in database persist.
        $DB->set_field_select('overflow_posts', 'message', '', $postsql, $params);
        $DB->set_field_select('overflow_posts', 'messageformat', FORMAT_PLAIN, $postsql, $params);
        $DB->set_field_select('overflow_posts', 'userid', 0, $postsql, $params);

        // Do not delete discussions but reset userid.
        $DB->set_field_select('overflow_discussions', 'name', '', $selectmoanduser, $params);
        $DB->set_field_select('overflow_discussions', 'userid', 0, $selectmoanduser, $params);
        $discussionselect = "overflow = :overflowid AND usermodified {$userinsql}";
        $DB->set_field_select('overflow_discussions', 'usermodified', 0, $discussionselect, $params);
    }
}
