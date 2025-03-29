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
 * overflow subscription manager.
 *
 * This file is created by borrowing code from the mod_forum module.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow;

/**
 * overflow subscription manager.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscriptions
{

    /**
     * The status value for an unsubscribed discussion.
     *
     * @var int
     */
    const OVERFLOW_DISCUSSION_UNSUBSCRIBED = -1;

    /**
     * The subscription cache for overflows.
     *
     * The first level key is the user ID
     * The second level is the overflow ID
     * The Value then is bool for subscribed of not.
     *
     * @var array[] An array of arrays.
     */
    protected static $overflowcache = [];

    /**
     * The list of overflows which have been wholly retrieved for the subscription cache.
     *
     * This allows for prior caching of an entire overflow to reduce the
     * number of DB queries in a subscription check loop.
     *
     * @var bool[]
     */
    protected static $fetchedoverflows = [];

    /**
     * The subscription cache for overflow discussions.
     *
     * The first level key is the user ID
     * The second level is the overflow ID
     * The third level key is the discussion ID
     * The value is then the users preference (int)
     *
     * @var array[]
     */
    protected static $discussioncache = [];

    /**
     * The list of overflows which have been wholly retrieved for the discussion subscription cache.
     *
     * This allows for prior caching of an entire overflows to reduce the
     * number of DB queries in a subscription check loop.
     *
     * @var bool[]
     */
    protected static $fetcheddiscussions = [];

    /**
     * Returns whether a user is subscribed to this overflow or a specific discussion within the overflow.
     *
     * If a discussion is specified then report whether the user is subscribed to posts to this
     * particular discussion, taking into account the overflow preference.
     * If it is not specified then considere only the overflows preference.
     *
     * @param int    $userid
     * @param object $overflow
     * @param object $context
     * @param int   $discussionid
     *
     * @return bool
     */
    public static function is_subscribed($userid, $overflow, $context, $discussionid = null)
    {

        // Is the user forced to be subscribed to the overflow?
        if (
            self::is_forcesubscribed($overflow) &&
            has_capability('local/overflow:allowforcesubscribe', $context, $userid)
        ) {
            return true;
        }

        // Check the overflow instance if no discussionid is submitted.
        if (is_null($discussionid)) {
            return self::is_subscribed_to_overflow($userid, $overflow);
        }

        // The subscription details for the discussion needs to be checked.
        $subscriptions = self::fetch_discussion_subscription($overflow->id, $userid);

        // Check if there is a record for the discussion.
        if (isset($subscriptions[$discussionid])) {
            return ($subscriptions[$discussionid]) != self::OVERFLOW_DISCUSSION_UNSUBSCRIBED;
        }

        // Return whether the user is subscribed to the forum.
        return self::is_subscribed_to_overflow($userid, $overflow);
    }

    /**
     * Helper to determine whether a overflow has it's subscription mode set to forced.
     *
     * @param object $overflow The record of the overflow to test
     *
     * @return bool
     */
    public static function is_forcesubscribed($overflow)
    {
        return ($overflow->forcesubscribe == OVERFLOW_FORCESUBSCRIBE);
    }

    /**
     * Whether a user is subscribed to this moodloverflow.
     *
     * @param int    $userid         The user ID
     * @param object $overflow The record of the overflow to test
     *
     * @return boolean
     */
    private static function is_subscribed_to_overflow($userid, $overflow)
    {
        return self::fetch_subscription_cache($overflow->id, $userid);
    }

    /**
     * Fetch the overflow subscription data for the specified userid an overflow.
     *
     * @param int $overflowid The forum to retrieve a cache for
     * @param int $userid           The user ID
     *
     * @return boolean
     */
    public static function fetch_subscription_cache($overflowid, $userid)
    {

        // If the cache is already filled, return the result.
        if (isset(self::$overflowcache[$userid]) && isset(self::$overflowcache[$userid][$overflowid])) {
            return self::$overflowcache[$userid][$overflowid];
        }

        // Refill the cache.
        self::fill_subscription_cache($overflowid, $userid);

        // Catch empty results.
        if (!isset(self::$overflowcache[$userid]) || !isset(self::$overflowcache[$userid][$overflowid])) {
            return false;
        }

        // Else return the subscription state.
        return self::$overflowcache[$userid][$overflowid];
    }

    /**
     * Fill the overflow subscription data for the specified userid an overflow.
     *
     * If the userid is not specified, then all subscription data for that overflow is fetched
     * in a single query and is used for subsequent lookups without requiring further database queries.
     *
     * @param int  $overflowid The overflow to retrieve a cache for
     * @param null $userid           The user ID
     
    public static function fill_subscription_cache_for_course($overflowid, $userid = null)
    {
        global $DB;

        // Check if the overflow has not been fetched as a whole.
        if (!isset(self::$fetchedoverflows[$overflowid])) {

            // Is a specified user requested?
            if (isset($userid)) {

                // Create the cache for the user.
                if (!isset(self::$overflowcache[$userid])) {
                    self::$overflowcache[$userid] = [];
                }

                // Check if the user is subscribed to the overflow.
                if (!isset(self::$overflowcache[$userid][$overflowid])) {

                    // Request to the database.
                    $params = ['userid' => $userid, 'overflow' => $overflowid];
                    if ($DB->record_exists('overflow_subscriptions', $params)) {
                        self::$overflowcache[$userid][$overflowid] = true;
                    } else {
                        self::$overflowcache[$userid][$overflowid] = false;
                    }
                }

            } else { // The request is not connected to a specific user.

                // Request all records.
                $params = ['overflow' => $overflowid];
                $subscriptions = $DB->get_recordset('overflow_subscriptions', $params, '', 'id, userid');

                // Loop through the records.
                foreach ($subscriptions as $data) {

                    // Create a new record if necessary.
                    if (!isset(self::$overflowcache[$data->userid])) {
                        self::$overflowcache[$data->userid] = [];
                    }

                    // Mark the subscription state.
                    self::$overflowcache[$data->userid][$overflowid] = true;
                }

                // Mark the overflow as fetched.
                self::$fetchedoverflows[$overflowid] = true;
                $subscriptions->close();
            }
        }
    }
    */

    /**
     * This is returned as an array of discussions for that overflow which contain the preference in a stdClass.
     *
     * @param int  $overflowid The overflow ID
     * @param int $userid           The user ID
     *
     * @return array of stClass objects
     */
    public static function fetch_discussion_subscription($overflowid, $userid = null)
    {

        // Fill the discussion cache.
        self::fill_discussion_subscription_cache($overflowid, $userid);

        // Create an array, if there is no record.
        if (!isset(self::$discussioncache[$userid]) || !isset(self::$discussioncache[$userid][$overflowid])) {
            return [];
        }

        // Return the cached subscription state.
        return self::$discussioncache[$userid][$overflowid];
    }

    /**
     * Fill the discussion subscription data for the specified user ID and overflow.
     *
     * If the user ID is not specified, all discussion subscription data for that overflow is
     * fetched in a single query and is used for subsequent lookups without requiring further database queries.
     *
     * @param int  $overflowid The overflow ID
     * @param int $userid           The user ID
     */
    public static function fill_discussion_subscription_cache($overflowid, $userid = null)
    {
        global $DB;

        // Check if the discussions of this overflow has been fetched as a whole.
        if (!isset(self::$fetcheddiscussions[$overflowid])) {

            // Check if data for a specific user is requested.
            if (isset($userid)) {

                // Create a new record if necessary.
                if (!isset(self::$discussioncache[$userid])) {
                    self::$discussioncache[$userid] = [];
                }

                // Check if the overflow instance is already cached.
                if (!isset(self::$discussioncache[$userid][$overflowid])) {

                    // Get all records.
                    $params = ['userid' => $userid, 'overflow' => $overflowid];
                    $subscriptions = $DB->get_recordset(
                        'overflow_discuss_subs',
                        $params,
                        null,
                        'id, discussion, preference'
                    );

                    // Loop through all of these and add them to the discussion cache.
                    foreach ($subscriptions as $id => $data) {
                        self::add_to_discussion_cache($overflowid, $userid, $data->discussion, $data->preference);
                    }

                    // Close the record set.
                    $subscriptions->close();
                }

            } else {
                // No user ID is submitted.

                // Get all records.
                $params = ['overflow' => $overflowid];
                $subscriptions = $DB->get_recordset(
                    'overflow_discuss_subs',
                    $params,
                    null,
                    'id, userid, discussion, preference'
                );

                // Loop throuch all of them and add them to the discussion cache.
                foreach ($subscriptions as $id => $data) {
                    self::add_to_discussion_cache($overflowid, $data->userid, $data->discussion, $data->preference);
                }

                // Mark the discussions as fetched and close the recordset.
                self::$fetcheddiscussions[$overflowid] = true;
                $subscriptions->close();
            }
        }
    }

    /**
     * Add the specified discussion and the users preference to the discussion subscription cache.
     *
     * @param int $overflowid The overflow ID
     * @param int $userid           The user ID
     * @param int $discussion       The discussion ID
     * @param int $preference       The preference to store
     */
    private static function add_to_discussion_cache($overflowid, $userid, $discussion, $preference)
    {

        // Create a new array for the user if necessary.
        if (!isset(self::$discussioncache[$userid])) {
            self::$discussioncache[$userid] = [];
        }

        // Create a new array for the overflow if necessary.
        if (!isset(self::$discussioncache[$userid][$overflowid])) {
            self::$discussioncache[$userid][$overflowid] = [];
        }

        // Save the users preference for that discussion in this array.
        self::$discussioncache[$userid][$overflowid][$discussion] = $preference;
    }

    /**
     * Determines whether a overflow has it's subscription mode set to disabled.
     *
     * @param object $overflow The overflow ID
     *
     * @return bool
     */
    public static function subscription_disabled($overflow)
    {
        return ($overflow->forcesubscribe == OVERFLOW_DISALLOWSUBSCRIBE);
    }

    /**
     * Checks wheter the specified overflow can be subscribed to.
     *
     * @param object $overflow The overflow ID
     * @param \context_system $context The system context.
     *
     * @return boolean
     */
    public static function is_subscribable($overflow, $context)
    {

        // Check if the user is an authenticated user.
        $authenticated = (isloggedin() && !isguestuser());

        // Check if subscriptions are disabled for the overflow.
        $disabled = self::subscription_disabled($overflow);

        // Check if the overflow forces the user to be subscribed.
        $forced = self::is_forcesubscribed($overflow) &&
            has_capability('local/overflow:allowforcesubscribe', $context);

        // Return the result.
        return ($authenticated && !$forced && !$disabled);
    }

    /**
     * Set the overflow subscription mode.
     *
     * By default when called without options, this is set to OVERFLOW_FORCESUBSCRIBE.
     *
     * @param int $overflowid The overflow ID
     * @param int $status           The new subscrription status
     *
     * @return bool
     */
    public static function set_subscription_mode($overflowid, $status = 1)
    {
        global $DB;

        // Change the value in the database.
        return $DB->set_field('overflow', 'forcesubscribe', $status, ['id' => $overflowid]);
    }

    /**
     * Returns the current subscription mode for the overflow.
     *
     * @param object $overflow The overflow record
     *
     * @return int The overflow subscription mode
     */
    public static function get_subscription_mode($overflow)
    {
        return $overflow->forcesubscribe;
    }

    /**
     * Returns an array of overflow that the current user is subscribed to and is allowed to unsubscribe from.
     *
     * @return array Array of unsubscribable overflows
     */
    public static function get_unsubscribable_overflows()
    {
        global $USER, $DB;

        $sql = "SELECT m.id as cm
                FROM {overflow} m
                LEFT JOIN {overflow_subscriptions} ms ON (ms.overflow = m.id AND ms.userid = :userid)
                WHERE m.forcesubscribe <> :forcesubscribe AND ms.id IS NOT NULL";
        $params = [
            'userid' => $USER->id,
            'forcesubscribe' => OVERFLOW_FORCESUBSCRIBE,
        ];
        $overflows = $DB->get_recordset_sql($sql, $params);

        // Loop through all of the results and add them to an array.
        $unsubscribableoverflows = [];
        foreach ($overflows as $overflow) {
            $unsubscribableoverflows[] = $overflow;
        }
        $overflows->close();

        // Return the array.
        return $unsubscribableoverflows;
    }

    /**
     * Get the list of potential subscribers to a overflow.
     *
     * @param \context_system $context The overflow context.
     * @param string          $fields  The list of fields to return for each user.
     * @param string          $sort    Sort order.
     *
     * @return array List of users.
     */
    public static function get_potential_subscribers($context, $fields, $sort = '')
    {
        global $DB;

        // Only enrolled users can subscribe.
        list($esql, $params) = get_enrolled_sql($context, 'local/overflow:allowforcesubscribe');

        // Default ordering of the list.
        if (!$sort) {
            list($sort, $sortparams) = users_order_by_sql('u');
            $params = array_merge($params, $sortparams);
        }

        // Fetch results from the database.
        $sql = "SELECT $fields
                FROM {user} u
                JOIN ($esql) je ON je.id = u.id
                ORDER BY $sort";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Fill the overflow subscription data for all overflow that the user can subscribe to in a spevific overflow.
     *
     * @param int $overflowid The overflow ID
     * @param int $userid   The user ID
     */
    public static function fill_subscription_cache($overflowid, $userid)
    {
        global $DB;

        // Create an array for the user if necessary.
        if (!isset(self::$overflowcache[$userid])) {
            self::$overflowcache[$userid] = [];
        }

        // Fetch a record set for all overflowids and their subscription id.
        $sql = "SELECT m.id AS overflowid,s.id AS subscriptionid
                    FROM {overflow} m
                LEFT JOIN {overflow_subscriptions} s ON (s.overflow = m.id AND s.userid = :userid)
                    WHERE m.id = :overflow AND m.forcesubscribe <> :subscriptionforced";
        $params = [
            'userid' => $userid,
            'overflow' => $overflowid,
            'subscriptionforced' => OVERFLOW_FORCESUBSCRIBE,
        ];
        $subscriptions = $DB->get_recordset_sql($sql, $params);

        // Loop through all records.
        foreach ($subscriptions as $id => $data) {
            self::$overflowcache[$userid][$id] = !empty($data->subscriptionid);
        }

        // Close the recordset.
        $subscriptions->close();
    }

    /**
     * Returns a list of user object who are subscribed to this overflow.
     *
     * @param object        $overflow     The overflow record
     * @param \context_system $context            The overflow context
     * @param string          $fields             Requested user fields
     * @param boolean         $includediscussions Whether to take discussion subscriptions into consideration
     *
     * @return array list of users
     */
    public static function get_subscribed_users($overflow, $context, $fields = null, $includediscussions = false)
    {
        global $CFG, $DB;

        // Default fields if none are submitted.
        if (empty($fields)) {
            if ($CFG->branch >= 311) {
                $allnames = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
            } else {
                $allnames = get_all_user_name_fields(true, 'u');
            }
            $fields = "u.id, u.username, $allnames, u.maildisplay, u.mailformat, u.maildigest,
                u.imagealt, u.email, u.emailstop, u.city, u.country, u.lastaccess, u.lastlogin,
                u.picture, u.timezone, u.theme, u.lang, u.trackforums, u.mnethostid";
        }

        if (self::subscription_disabled($overflow)) {
            $results = [];
        } else {

            // Only enrolled users can subscribe to a overflow.
            list($esql, $params) = get_enrolled_sql($context, '', 0, true);
            $params['overflowid'] = $overflow->id;

            // Check discussion subscriptions as well?
            if ($includediscussions) {

                // Determine more params.
                $params['soverflowid'] = $overflow->id;
                $params['dsoverflowid'] = $overflow->id;
                $params['unsubscribed'] = self::OVERFLOW_DISCUSSION_UNSUBSCRIBED;

                // SQL-statement to fetch all needed fields from the database.
                $sql = "SELECT $fields
                        FROM (
                            SELECT userid FROM {overflow_subscriptions} s
                            WHERE s.overflow = :soverflowid
                            UNION
                            SELECT userid FROM {overflow_discuss_subs} ds
                            WHERE ds.overflow = :dsoverflowid AND ds.preference <> :unsubscribed
                        ) subscriptions
                        JOIN {user} u ON u.id = subscriptions.userid
                        JOIN ($esql) je ON je.id = u.id
                        ORDER BY u.email ASC";

            } else {
                // Dont include the discussion subscriptions.

                // SQL-statement to fetch all needed fields from the database.
                $sql = "SELECT $fields
                        FROM {user} u
                        JOIN ($esql) je ON je.id = u.id
                        JOIN {overflow_subscriptions} s ON s.userid = u.id
                        WHERE s.overflow = :overflowid
                        ORDER BY u.email ASC";
            }

            // Fetch the data.
            $results = $DB->get_records_sql($sql, $params);

            if (self::is_forcesubscribed($overflow)) {
                foreach (self::get_potential_subscribers($context, $fields, 'u.email ASC') as $id => $user) {
                    $results[$id] = $user;
                }
            }
        }

        // Remove all guest users from the results. They should never be subscribed to a overflow.
        unset($results[$CFG->siteguest]);

        // Return all subscribed users.
        return $results;
    }

    /**
     * Reset the discussion cache.
     *
     * This cache is used to reduce the number of database queries
     * when checking overflow discussion subscriptions states.
     */
    public static function reset_discussion_cache()
    {

        // Reset the discussion cache.
        self::$discussioncache = [];

        // Reset the fetched discussions.
        self::$fetcheddiscussions = [];
    }

    /**
     * Reset the overflow cache.
     *
     * This cache is used to reduce the number of database queries
     * when checking overflow subscription states.
     */
    public static function reset_overflow_cache()
    {

        // Reset the cache.
        self::$overflowcache = [];

        // Reset the fetched overflows.
        self::$fetchedoverflows = [];
    }

    /**
     * Adds user to the subscriber list.
     *
     * @param int             $userid         The user ID
     * @param \stdClass       $overflow The overflow record
     * @param \context_system $context        The system context
     * @param bool            $userrequest    Whether the user requested this change themselves.
     *
     * @return bool|int Returns true if the user is already subscribed or the subscription id if successfully subscribed.
     */
    public static function subscribe_user($userid, $overflow, $context, $userrequest = false)
    {
        global $DB;

        // Check if the user is already subscribed.
        if (self::is_subscribed($userid, $overflow, $context)) {
            return true;
        }

        // Create a new subscription object.
        $sub = new \stdClass();
        $sub->userid = $userid;
        $sub->overflow = $overflow->id;

        // Insert the record into the database.
        $result = $DB->insert_record('overflow_subscriptions', $sub);

        // If the subscription was requested by the user, remove all records for the discussions within this overflow.
        if ($userrequest) {

            // Delete all those discussion subscriptions.
            $params = [
                'userid' => $userid,
                'overflowid' => $overflow->id,
                'preference' => self::OVERFLOW_DISCUSSION_UNSUBSCRIBED,
            ];
            $where = 'userid = :userid AND overflow = :overflowid AND preference <> :preference';
            $DB->delete_records_select('overflow_discuss_subs', $where, $params);

            // Reset the subscription caches for this overflow.
            // We know that there were previously entries and there aren't any more.
            if (isset(self::$discussioncache[$userid]) && isset(self::$discussioncache[$userid][$overflow->id])) {
                foreach (self::$discussioncache[$userid][$overflow->id] as $discussionid => $preference) {
                    if ($preference != self::OVERFLOW_DISCUSSION_UNSUBSCRIBED) {
                        unset(self::$discussioncache[$userid][$overflow->id][$discussionid]);
                    }
                }
            }
        }

        // Reset the cache for this overflow.
        self::$overflowcache[$userid][$overflow->id] = true;

        // Trigger an subscription created event.
        $params = [
            'context' => $context,
            'objectid' => $result,
            'relateduserid' => $userid,
            'other' => ['overflowid' => $overflow->id],
        ];
        $event = event\subscription_created::create($params);
        $event->trigger();

        // Return the subscription ID.
        return $result;
    }

    /**
     * Removes user from the subscriber list.
     *
     * @param int             $userid         The user ID.
     * @param \stdClass       $overflow The overflow record
     * @param \context_system $context        The system context
     * @param boolean         $userrequest    Whether the user requested this change themselves.
     *
     * @return bool Always returns true
     */
    public static function unsubscribe_user($userid, $overflow, $context, $userrequest = null)
    {
        global $DB;

        // Check if there is a subscription record.
        $params = ['userid' => $userid, 'overflow' => $overflow->id];
        if ($subscription = $DB->get_record('overflow_subscriptions', $params)) {

            // Delete this record.
            $DB->delete_records('overflow_subscriptions', ['id' => $subscription->id]);

            // Was the unsubscription requested by the user?
            if ($userrequest) {

                // Delete the discussion subscriptions as well.
                $params = [
                    'userid' => $userid,
                    'overflow' => $overflow->id,
                    'preference' => self::OVERFLOW_DISCUSSION_UNSUBSCRIBED,
                ];
                $DB->delete_records('overflow_discuss_subs', $params);

                // Update the discussion cache.
                if (isset(self::$discussioncache[$userid]) && isset(self::$discussioncache[$userid][$overflow->id])) {
                    self::$discussioncache[$userid][$overflow->id] = [];
                }
            }

            // Reset the cache for this overflow.
            self::$overflowcache[$userid][$overflow->id] = false;

            // Trigger an subscription deletion event.
            $params = [
                'context' => $context,
                'objectid' => $subscription->id,
                'relateduserid' => $userid,
                'other' => ['overflowid' => $overflow->id],
            ];
            $event = event\subscription_deleted::create($params);
            $event->add_record_snapshot('overflow_subscriptions', $subscription);
            $event->trigger();
        }

        // The unsubscription was successful.
        return true;
    }

    /**
     * Subscribes the user to the specified discussion.
     *
     * @param int             $userid     The user ID
     * @param \stdClass       $discussion The discussion record
     * @param \context_system $context    The system context
     *
     * @return bool Whether a change was made
     */
    public static function subscribe_user_to_discussion($userid, $discussion, $context)
    {
        global $DB;

        // Check if the user is already subscribed to the discussion.
        $params = ['userid' => $userid, 'discussion' => $discussion->id];
        $subscription = $DB->get_record('overflow_discuss_subs', $params);

        // Dont continue if the user is already subscribed.
        if ($subscription && $subscription->preference != self::OVERFLOW_DISCUSSION_UNSUBSCRIBED) {
            return false;
        }

        // Check if the user is already subscribed to the overflow.
        $params = ['userid' => $userid, 'overflow' => $discussion->overflow];
        if ($DB->record_exists('overflow_subscriptions', $params)) {

            // Check if the user is unsubscribed from the discussion.
            if ($subscription && $subscription->preference == self::OVERFLOW_DISCUSSION_UNSUBSCRIBED) {

                // Delete the discussion preference.
                $DB->delete_records('overflow_discuss_subs', ['id' => $subscription->id]);
                unset(self::$discussioncache[$userid][$discussion->overflow][$discussion->id]);

            } else {
                // The user is already subscribed to the forum.
                return false;
            }

        } else {
            // The user is not subscribed to the overflow.

            // Check if there is already a subscription to the discussion.
            if ($subscription) {

                // Update the existing record.
                $subscription->preference = time();
                $DB->update_record('overflow_discuss_subs', $subscription);

            } else {
                // Else a new record needs to be created.
                $subscription = new \stdClass();
                $subscription->userid = $userid;
                $subscription->overflow = $discussion->overflow;
                $subscription->discussion = $discussion->id;
                $subscription->preference = time();

                // Insert the subscription record into the database.
                $subscription->id = $DB->insert_record('overflow_discuss_subs', $subscription);
                self::$discussioncache[$userid][$discussion->overflow][$discussion->id] = $subscription->preference;
            }
        }

        // Create a discussion subscription created event.
        $params = [
            'context' => $context,
            'objectid' => $subscription->id,
            'relateduserid' => $userid,
            'other' => ['overflowid' => $discussion->overflow, 'discussion' => $discussion->id],
        ];
        $event = event\discussion_subscription_created::create($params);
        $event->trigger();

        // The subscription was successful.
        return true;
    }

    /**
     * Unsubscribes the user from the specified discussion.
     *
     * @param int             $userid     The user ID
     * @param \stdClass       $discussion The discussion record
     * @param \context_system $context    The context module
     *
     * @return bool Whether a change was made
     */
    public static function unsubscribe_user_from_discussion($userid, $discussion, $context)
    {
        global $DB;

        // Check the users subscription preference for this discussion.
        $params = ['userid' => $userid, 'discussion' => $discussion->id];
        $subscription = $DB->get_record('overflow_discuss_subs', $params);

        // If the user not already subscribed to the discussion, do not continue.
        if ($subscription && $subscription->preference == self::OVERFLOW_DISCUSSION_UNSUBSCRIBED) {
            return false;
        }

        // Check if the user is subscribed to the overflow.
        $params = ['userid' => $userid, 'overflow' => $discussion->overflow];
        if (!$DB->record_exists('overflow_subscriptions', $params)) {

            // Check if the user isn't subscribed to the overflow.
            if ($subscription && $subscription->preference != self::OVERFLOW_DISCUSSION_UNSUBSCRIBED) {

                // Delete the discussion subscription.
                $DB->delete_records('overflow_discuss_subs', ['id' => $subscription->id]);
                unset(self::$discussioncache[$userid][$discussion->overflow][$discussion->id]);

            } else {
                // Else the user is not subscribed to the overflow.

                // Nothing has to be done here.
                return false;
            }

        } else {
            // There is an subscription record for this overflow.

            // Check whether an subscription record for this discussion.
            if ($subscription) {

                // Update the existing record.
                $subscription->preference = self::OVERFLOW_DISCUSSION_UNSUBSCRIBED;
                $DB->update_record('overflow_discuss_subs', $subscription);

            } else {
                // There is no record.

                // Create a new discussion subscription record.
                $subscription = new \stdClass();
                $subscription->userid = $userid;
                $subscription->overflow = $discussion->overflow;
                $subscription->discussion = $discussion->id;
                $subscription->preference = self::OVERFLOW_DISCUSSION_UNSUBSCRIBED;

                // Insert the discussion subscription record into the database.
                $subscription->id = $DB->insert_record('overflow_discuss_subs', $subscription);
            }

            // Update the cache.
            self::$discussioncache[$userid][$discussion->overflow][$discussion->id] = $subscription->preference;
        }

        // Trigger an discussion subscription deletetion event.
        $params = [
            'context' => $context,
            'objectid' => $subscription->id,
            'relateduserid' => $userid,
            'other' => ['overflowid' => $discussion->overflow, 'discussion' => $discussion->id],
        ];
        $event = event\discussion_subscription_deleted::create($params);
        $event->trigger();

        // The user was successfully unsubscribed from the discussion.
        return true;
    }

    /**
     * Generate and return the subscribe or unsubscribe link for a overflow.
     *
     * @param object $overflow the overflow. Fields used are $overflow->id and $overflow->forcesubscribe.
     * @param \context $context        the context object for this overflow.
     * @param array  $messages       text used for the link in its various states
     *                               (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
     *                               Any strings not passed in are taken from the $defaultmessages array
     *                               at the top of the function.
     *
     * @return string
     */
    public static function overflow_get_subscribe_link($overflow, $context, $messages = [])
    {
        global $USER, $OUTPUT;

        // Define strings.
        $defaultmessages = [
            'subscribed' => get_string('unsubscribe', 'local_overflow'),
            'unsubscribed' => get_string('subscribe', 'local_overflow'),
            'forcesubscribed' => get_string('everyoneissubscribed', 'local_overflow'),
            'cantsubscribe' => get_string('disallowsubscribe', 'local_overflow'),
        ];

        // Combine strings the submitted messages.
        $messages = $messages + $defaultmessages;

        // Check whether the user is forced to be subscribed to the overflow.
        $isforced = self::is_forcesubscribed($overflow) && has_capability('local/overflow:allowforcesubscribe', $context);
        $isdisabled = self::subscription_disabled($overflow);

        // Return messages depending on the subscription state.
        if ($isforced) {
            return $messages['forcesubscribed'];
        } else if ($isdisabled && !has_capability('local/overflow:managesubscriptions', $context)) {
            return $messages['cantsubscribe'];
        } else {

            // The user needs to be enrolled.
            if (!isloggedin()) {
                return '';
            }

            // Check whether the user is subscribed.
            $issubscribed = self::is_subscribed($USER->id, $overflow, $context);

            // Define the text of the link depending on the subscription state.
            if ($issubscribed) {
                $linktext = $messages['subscribed'];
                $linktitle = get_string('subscribestop', 'local_overflow');
            } else {
                $linktext = $messages['unsubscribed'];
                $linktitle = get_string('subscribestart', 'local_overflow');
            }

            // Create an options array.
            $options = [];
            $options['id'] = $overflow->id;
            $options['sesskey'] = sesskey();
            $options['returnurl'] = 0;
            $options['backtoindex'] = 1;

            // Return the link to subscribe the user.
            $url = new \moodle_url('/local/overflow/subscribe.php', $options);

            return $OUTPUT->single_button($url, $linktext, 'get', ['title' => $linktitle]);
        }
    }

    /**
     * Given a new post, subscribes the user to the thread the post was posted in.
     *
     * @param \stdClass       $overflow The overflow record
     * @param \stdClass       $discussion     The discussion record
     * @param \context_system $context  The context of the system
     *
     * @return bool
     */
    public static function overflow_post_subscription($overflow, $discussion, $context)
    {
        global $USER;

        // Check for some basic information.
        $force = self::is_forcesubscribed($overflow);
        $disabled = self::subscription_disabled($overflow);

        // Do not continue if the user is already forced to be subscribed.
        if ($force && has_capability('local/overflow:allowforcesubscribe', $context)) {
            return false;
        }

        // Do not continue if subscriptions are disabled.
        if ($disabled) {

            // If the user is subscribed, unsubscribe him.
            $subscribed = self::is_subscribed($USER->id, $overflow, $context);
            $canmanage = has_capability('moodle/course:manageactivities', $context, $USER->id);
            if ($subscribed && !$canmanage) {
                self::unsubscribe_user($USER->id, $overflow, $context);
            }

            // Do not continue.
            return false;
        }

        // Subscribe the user to the discussion.
        self::subscribe_user_to_discussion($USER->id, $discussion, $context);

        return true;
    }

    /**
     * Return the markup for the discussion subscription toggling icon.
     *
     * @param object $overflow The forum overflow.
     * @param \context $context
     * @param int    $discussionid   The discussion to create an icon for.
     *
     * @return string The generated markup.
     */
    public static function get_discussion_subscription_icon($overflow, $context, $discussionid)
    {
        global $OUTPUT, $PAGE, $USER;

        // Set the url to return to.
        $returnurl = $PAGE->url->out();

        // Check if the discussion is subscrived.
        $status = self::is_subscribed($USER->id, $overflow, $context, $discussionid);

        // Create a link to subscribe or unsubscribe to the discussion.
        $array = [
            'sesskey' => sesskey(),
            'id' => $overflow->id,
            'd' => $discussionid,
            'returnurl' => $returnurl,
        ];
        $subscriptionlink = new \moodle_url('/local/overflow/subscribe.php', $array);

        // Create an icon to unsubscribe.
        if ($status) {

            // Create the icon.
            $string = get_string('clicktounsubscribe', 'local_overflow');
            $output = $OUTPUT->pix_icon('i/subscribed', $string, 'local_overflow');

            // Return the link.
            $array = [
                'title' => get_string('clicktounsubscribe', 'local_overflow'),
                'class' => 'discussiontoggle text-muted',
                'data-overflowid' => $overflow->id,
                'data-discussionid' => $discussionid,
                'data-includetext' => false,
            ];

            return \html_writer::link($subscriptionlink, $output, $array);
        }

        // Create an icon to subscribe.
        $string = get_string('clicktosubscribe', 'local_overflow');
        $output = $OUTPUT->pix_icon('i/unsubscribed', $string, 'local_overflow');

        // Return the link.
        $array = [
            'title' => get_string('clicktosubscribe', 'local_overflow'),
            'class' => 'discussiontoggle text-muted',
            'data-overflowid' => $overflow->id,
            'data-discussionid' => $discussionid,
            'data-includetext' => false,
        ];

        return \html_writer::link($subscriptionlink, $output, $array);
    }
}
