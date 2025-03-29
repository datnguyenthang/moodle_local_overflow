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
 * Library of interface functions and constants for module overflow
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 *
 * All the overflow specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();
require_once(dirname(__FILE__) . '/locallib.php');

// Readtracking constants.
define('OVERFLOW_TRACKING_OFF', 0);
define('OVERFLOW_TRACKING_OPTIONAL', 1);
define('OVERFLOW_TRACKING_FORCED', 2);

// Subscription constants.
define('OVERFLOW_CHOOSESUBSCRIBE', 0);
define('OVERFLOW_FORCESUBSCRIBE', 1);
define('OVERFLOW_INITIALSUBSCRIBE', 2);
define('OVERFLOW_DISALLOWSUBSCRIBE', 3);

// Mailing state constants.
define('OVERFLOW_MAILED_PENDING', 0);
define('OVERFLOW_MAILED_SUCCESS', 1);
define('OVERFLOW_MAILED_ERROR', 2);
define('OVERFLOW_MAILED_REVIEW_SUCCESS', 3);

// Constants for the post rating.
define('OVERFLOW_PREFERENCE_STARTER', 0);
define('OVERFLOW_PREFERENCE_TEACHER', 1);

// Reputation constants.
define('OVERFLOW_REPUTATION_MODULE', 0);
define('OVERFLOW_REPUTATION', 1);

// Allow ratings?
define('OVERFLOW_RATING_FORBID', 0);
define('OVERFLOW_RATING_ALLOW', 1);

// Allow reputations?
define('OVERFLOW_REPUTATION_FORBID', 0);
define('OVERFLOW_REPUTATION_ALLOW', 1);

// Allow negative reputations?
define('OVERFLOW_REPUTATION_POSITIVE', 0);
define('OVERFLOW_REPUTATION_NEGATIVE', 1);

// Rating constants.
define('RATING_NEUTRAL', 0);
define('RATING_DOWNVOTE', 1);
define('RATING_REMOVE_DOWNVOTE', 10);
define('RATING_UPVOTE', 2);
define('RATING_REMOVE_UPVOTE', 20);
define('RATING_SOLVED', 3);
define('RATING_REMOVE_SOLVED', 30);
define('RATING_HELPFUL', 4);
define('RATING_REMOVE_HELPFUL', 40);

/* Moodle core API */

/**
 * Returns the information on whether the module supports a feature.
 *
 * See {plugin_supports()} for more info.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 *
 * @return mixed true if the feature is supported, null if unknown
 */
function overflow_supports($feature) {

    if (defined('FEATURE_MOD_PURPOSE')) {
        if ($feature == FEATURE_MOD_PURPOSE) {
            return MOD_PURPOSE_COLLABORATION;
        }
    }

    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;

        default:
            return null;
    }
}

/**
 * Handle changes following the creation of a overflow instance.
 *
 * @param object   $context        The context of the overflow
 * @param stdClass $overflow The overflow object
 */
function overflow_instance_created($context, $overflow) {

    // Check if users are forced to be subscribed to the overflow instance.
    if ($overflow->forcesubscribe == OVERFLOW_INITIALSUBSCRIBE) {

        // Get a list of all potential subscribers.
        $users = \local_overflow\subscriptions::get_potential_subscribers($context, 'u.id, u.email');

        // Subscribe all potential subscribers to this overflow.
        foreach ($users as $user) {
            \local_overflow\subscriptions::subscribe_user($user->id, $overflow, $context);
        }
    }
}

/**
 * Updates an instance of the overflow in the database.
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param stdClass                         $overflow An object from the form in mod_form.php
 * @param local_overflow_mod_form|null $mform          The form instance itself (if needed)
 *
 * @return boolean Success/Fail
 */
function overflow_update_instance(stdClass $overflow, ?local_overflow_mod_form $mform = null) {
    global $DB;

    $overflow->timemodified = time();
    $overflow->id = $overflow->instance;

    // Get the old record.
    $oldoverflow = $DB->get_record('overflow', ['id' => $overflow->id]);

    // Find the context of the module.
    $context = context_system::instance();

    // Check if the subscription state has changed.
    if ($overflow->forcesubscribe != $oldoverflow->forcesubscribe) {
        if ($overflow->forcesubscribe == OVERFLOW_INITIALSUBSCRIBE) {
            // Get a list of potential subscribers.
            $users = \local_overflow\subscriptions::get_potential_subscribers($context, 'u.id, u.email', '');

            // Subscribe all those users to the overflow instance.
            foreach ($users as $user) {
                \local_overflow\subscriptions::subscribe_user($user->id, $overflow, $context);
            }
        } else if ($overflow->forcesubscribe == OVERFLOW_CHOOSESUBSCRIBE) {
            // Delete all current subscribers.
            $DB->delete_records('overflow_subscriptions', ['overflow' => $overflow->id]);
        }
    }

    // Update the overflow instance in the database.
    $result = $DB->update_record('overflow', $overflow);

    overflow_grade_item_update($overflow);

    // Update all grades.
    overflow_update_all_grades_for_cm($overflow->id);

    return $result;
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * This is only required if the module is generating calendar events.
 *
 * @param int $overflowid Overflow ID
 *
 * @return bool
 */
function overflow_refresh_events($overflowid = 0) {
    global $DB;

    if ($overflowid == 0) {
        if (!$DB->get_records('overflow')) {
            return true;
        }
    } else {
        if (!$DB->get_records('overflow', ['id' => $overflowid])) {
            return true;
        }
    }

    return true;
}

/**
 * Removes an instance of the overflow from the database.
 *
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 *
 * @return boolean Success/Failure
 */
function overflow_delete_instance($id) {
    global $DB;

    // Initiate the variables.
    $result = true;

    // Get the needed objects.
    if (!$overflow = $DB->get_record('overflow', ['id' => $id])) {
        return false;
    }

    // Get the context module.
    $context = context_system::instance();

    // Delete all connected files.
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    // Delete the subscription elements.
    $DB->delete_records('overflow_subscriptions', ['overflow' => $overflow->id]);
    $DB->delete_records('overflow_discuss_subs', ['overflow' => $overflow->id]);
    $DB->delete_records('overflow_grades', ['overflowid' => $overflow->id]);

    // Delete the discussion recursivly.
    if ($discussions = $DB->get_records('overflow_discussions', ['overflow' => $overflow->id])) {
        require_once('locallib.php');
        foreach ($discussions as $discussion) {
            if (!overflow_delete_discussion($discussion, $overflow)) {
                $result = false;
            }
        }
    }

    // Delete the read records.
    \local_overflow\readtracking::overflow_delete_read_records(-1, -1, -1, $overflow->id);

    // Delete the overflow instance.
    if (!$DB->delete_records('overflow', ['id' => $overflow->id])) {
        $result = false;
    }

    // Return whether the deletion was successful.
    return $result;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 *
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param stdClass         $user           The user record
 * @param stdClass         $overflow The overflow instance record
 *
 * @return stdClass|null
 */
function overflow_user_outline($user, $overflow) {
    $return = new stdClass();
    $return->time = 0;
    $return->info = '';

    return $return;
}

/**
 * Given a overflow and a time, this module should find recent activity
 * that has occurred in overflow activities and print it out.
 *
 * @param bool     $viewfullnames Should we display full names
 * @param int      $timestart     Print activity since this timestamp
 *
 * @return boolean True if anything was printed, otherwise false
 */
function overflow_print_recent_activity($viewfullnames, $timestart) {
    return false;
}

/**
 * Returns all other caps used in the module.
 *
 * For example, this could be array('moodle/site:accessallgroups') if the
 * module uses that capability.
 *
 * @return array
 */
function overflow_get_extra_capabilities() {
    return [];
}

/* File API */

/**
 * Returns the lists of all browsable file areas within the given module context.
 *
 *
 * @param stdClass $context
 *
 * @return array of [(string)filearea] => (string)description
 */
function overflow_get_file_areas($context) {
    return [
        'attachment' => get_string('areaattachment', 'local_overflow'),
        'post' => get_string('areapost', 'local_overflow'),
    ];
}

/**
 * File browsing support for overflow file areas.
 *
 * @package  local_overflow
 * @category files
 *
 * @param file_browser $browser
 * @param array        $areas
 * @param stdClass     $context
 * @param string       $filearea
 * @param int          $itemid
 * @param string       $filepath
 * @param string       $filename
 *
 * @return file_info instance or null if not found
 */
function overflow_get_file_info($browser, $areas, $context, $filearea, $itemid, $filepath, $filename) {
    return null;
}

/**
 * Serves the files from the overflow file areas.
 *
 * @package  local_overflow
 * @category files
 *
 * @param stdClass $context       the overflow's context
 * @param string   $filearea      the name of the file area
 * @param array    $args          extra arguments (itemid, path)
 * @param bool     $forcedownload whether or not force download
 * @param array    $options       additional options affecting the file serving
 */
function local_overflow_pluginfile($overflow, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $CFG;
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    $areas = overflow_get_file_areas($context);
    // Filearea must contain a real area.
    if (!isset($areas[$filearea])) {
        return false;
    }

    $filename = array_pop($args);
    $itemid = array_pop($args);

    // Check if post, discussion or overflow still exists.
    if (!$post = $DB->get_record('overflow_posts', ['id' => $itemid])) {
        return false;
    }
    if (!$discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion])) {
        return false;
    }
    if (!$overflow = $DB->get_record('overflow', ['id' => $overflow->id])) {
        return false;
    }

    if (!$args) {
        // Empty path, use root.
        $filepath = '/';
    } else {
        // Assemble filepath.
        $filepath = '/' . implode('/', $args) . '/';
    }
    $fs = get_file_storage();

    $file = $fs->get_file($context->id, 'local_overflow', $filearea, $itemid, $filepath, $filename);

    // Make sure we're allowed to see it...
    if (!overflow_user_can_see_post($overflow, $discussion, $post)) {
        return false;
    }

    // Finally send the file.
    send_stored_file($file, 86400, 0, true, $options); // Download MUST be forced - security!
}

/* Navigation API */

/**
 * Extends the settings navigation with the overflow settings.
 *
 * This function is called when the context for the page is a overflow module. This is not called by AJAX
 * so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation      $settingsnav        complete settings navigation tree
 * @param navigation_node|null     $overflownode overflow administration node
 */
function overflow_extend_settings_navigation(settings_navigation $settingsnav, ?navigation_node $overflownode = null) {
    global $CFG, $DB, $PAGE, $USER;

    $context = context_system::instance();

    // Retrieve the current moodle record.
    $overflow = $DB->get_record('overflow', ['id' => $PAGE->cm->instance]);

    // Check if the user can subscribe to the instance.
    $canmanage = has_capability('local/overflow:managesubscriptions', $context);
    $forcesubscribed = \local_overflow\subscriptions::is_forcesubscribed($overflow);
    $subscdisabled = \local_overflow\subscriptions::subscription_disabled($overflow);
    $cansubscribe = isloggedin() && (!$subscdisabled || $canmanage) &&
        !($forcesubscribed && has_capability('local/overflow:allowforcesubscribe', $context));
    $cantrack = \local_overflow\readtracking::overflow_can_track_overflows($overflow);

    // Display a link to subscribe or unsubscribe.
    if ($cansubscribe) {

        // Choose the linktext depending on the current state of subscription.
        $issubscribed = \local_overflow\subscriptions::is_subscribed($USER->id, $overflow, $context);
        if ($issubscribed) {
            $linktext = get_string('unsubscribe', 'local_overflow');
        } else {
            $linktext = get_string('subscribe', 'local_overflow');
        }

        // Add the link to the menu.
        $url = new moodle_url('/local/overflow/subscribe.php', ['id' => $overflow->id, 'sesskey' => sesskey()]);
        $overflownode->add($linktext, $url, navigation_node::TYPE_SETTING);
    }

    // Display a link to enable or disable readtracking.
    if ($cantrack) {

        // Check some basic capabilities.
        $isoptional = ($overflow->trackingtype == overflow_TRACKING_OPTIONAL);
        $forceallowed = get_config('local_overflow', 'allowforcedreadtracking');
        $isforced = ($overflow->trackingtype == overflow_TRACKING_FORCED);

        // Check whether the readtracking state can be changed.
        if ($isoptional || (!$forceallowed && $isforced)) {

            // Generate the text of the link depending on the current state.
            $istracked = \local_overflow\readtracking::overflow_is_tracked($overflow);
            if ($istracked) {
                $linktext = get_string('notrackoverflow', 'local_overflow');
            } else {
                $linktext = get_string('trackoverflow', 'local_overflow');
            }

            // Generate the link.
            $url = '/local/overflow/tracking.php';
            $params = ['id' => $overflow->id, 'sesskey' => sesskey()];
            $link = new moodle_url($url, $params);

            // Add the link to the menu.
            $overflownode->add($linktext, $link, navigation_node::TYPE_SETTING);
        }
    }

}

/**
 * Determine the current context if one wa not already specified.
 *
 * If a context of type context_context is specified, it is immediately returned and not checked.
 *
 * @param int            $overflowid The overflow ID
 * @param context_context $context          The current context
 *
 * @return context_context The context determined
 */
function overflow_get_context($overflowid, $context = null) {
    global $PAGE;
    $context = \context_system::instance();

    // Return the context.
    return $context;
}

/**
 * Sends mail notifications about new posts.
 *
 * @return bool
 */
function overflow_send_mails() {
    global $DB, $CFG, $PAGE;

    // Get the object of the top level site.
    $site = get_site();

    // Get the main renderers.
    $htmlout = $PAGE->get_renderer('local_overflow', 'email', 'htmlemail');
    $textout = $PAGE->get_renderer('local_overflow', 'email', 'textemail');

    // Initiate the arrays that are saving the users that are subscribed to posts that needs sending.
    $users = [];
    $userscount = 0; // Count($users) is slow. This avoids using this.

    // Status arrays.
    $mailcount = [];
    $errorcount = [];

    // Cache arrays.
    $discussions = [];
    $overflows = [];
    $subscribedusers = [];

    // Posts older than x days will not be mailed.
    // This will avoid problems with the cron not beeing ran for a long time.
    $timenow = time();
    $endtime = $timenow - get_config('local_overflow', 'maxeditingtime');
    $starttime = $endtime - (get_config('local_overflow', 'maxmailingtime') * 60 * 60);

    // Retrieve all unmailed posts.
    $posts = overflow_get_unmailed_posts($starttime, $endtime);
    if ($posts) {

        // Mark those posts as mailed.
        if (!overflow_mark_old_posts_as_mailed($endtime)) {
            mtrace('Errors occurred while trying to mark some posts as being mailed.');

            return false;
        }

        // Loop through all posts to be mailed.
        foreach ($posts as $postid => $post) {

            // Check the cache if the discussion exists.
            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {

                // Retrieve the discussion from the database.
                $discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion]);

                // If there is a record, update the cache. Else ignore the post.
                if ($discussion) {
                    $discussions[$discussionid] = $discussion;
                    \local_overflow\subscriptions::fill_subscription_cache($discussion->overflow);
                    \local_overflow\subscriptions::fill_discussion_subscription_cache($discussion->overflow);
                } else {
                    mtrace('Could not find discussion ' . $discussionid);
                    unset($posts[$postid]);
                    continue;
                }
            }

            // Retrieve the connected overflow instance from the database.
            $overflowid = $discussions[$discussionid]->overflow;
            if (!isset($overflows[$overflowid])) {

                // Retrieve the record from the database and update the cache.
                $overflow = $DB->get_record('overflow', ['id' => $overflowid]);
                if ($overflow) {
                    $overflows[$overflowid] = $overflow;
                } else {
                    mtrace('Could not find overflow ' . $overflowid);
                    unset($posts[$postid]);
                    continue;
                }
            }



            // Cache subscribed users of each overflow.
            if (!isset($subscribedusers[$overflowid])) {

                // Retrieve the context system.
                $context = context_system::instance();

                // Retrieve all subscribed users.
                $mid = $overflows[$overflowid];
                $subusers = \local_overflow\subscriptions::get_subscribed_users($mid, $context, 'u.*', true);
                if ($subusers) {

                    // Loop through all subscribed users.
                    foreach ($subusers as $postuser) {

                        // Save the user into the cache.
                        $subscribedusers[$overflowid][$postuser->id] = $postuser->id;
                        $userscount++;
                        overflow_minimise_user_record($postuser);
                        $users[$postuser->id] = $postuser;
                    }

                    // Release the memory.
                    unset($subusers);
                    unset($postuser);
                }
            }

            // Initiate the count of the mails send and errors.
            $mailcount[$postid] = 0;
            $errorcount[$postid] = 0;
        }
    }

    // Send mails to the users with information about the posts.
    if ($users && $posts) {
        // Send one mail to every user.
        foreach ($users as $userto) {
            // Terminate if the process takes more time then two minutes.
            core_php_time_limit::raise(120);

            // Tracing information.
            mtrace('Processing user ' . $userto->id);
            // Initiate the user caches to save memory.
            $userto = clone($userto);
            $userto->ciewfullnames = [];
            $userto->canpost = [];
            $userto->markposts = [];

            // Cache the capabilities of the user.
            // Check for moodle version. Version 401 supported until 8 December 2025.
            if ($CFG->branch >= 402) {
                \core\cron::setup_user($userto);
            } else {
                cron_setup_user($userto);
            }


            // Loop through all posts of this users.
            foreach ($posts as $postid => $post) {

                // Initiate variables for the post.
                $discussion = $discussions[$post->discussion];
                $overflow = $overflows[$discussion->overflow];

                // Check if user wants a resume.
                // in this case: make a new dataset in "overflow_mail_info" to save the posts data.
                // Dataset from overflow_mail_info will be send later in a mail.
                $usermailsetting = $userto->maildigest;
                if ($usermailsetting != 0) {
                    $dataobject = new stdClass();
                    $dataobject->userid = $userto->id;
                    $dataobject->forumid = $overflow->id;
                    $dataobject->forumdiscussionid = $discussion->id;
                    $record = $DB->get_record('overflow_mail_info',
                                                ['userid' => $dataobject->userid,
                                                    'forumid' => $dataobject->forumid,
                                                    'forumdiscussionid' => $dataobject->forumdiscussionid, ],
                                                    'numberofposts, id');
                    if (is_object($record)) {
                        $dataset = $record;
                        $dataobject->numberofposts = $dataset->numberofposts + 1;
                        $dataobject->id = $dataset->id;
                        $DB->update_record('overflow_mail_info', $dataobject);
                    } else {
                        $dataobject->numberofposts = 1;
                        $DB->insert_record('overflow_mail_info', $dataobject);
                    }
                    continue;
                }

                // Check whether the user is subscribed.
                if (!isset($subscribedusers[$overflow->id][$userto->id])) {
                    continue;
                }

                // Check whether the user is subscribed to the discussion.
                $uid = $userto->id;
                $did = $post->discussion;
                $issubscribed = \local_overflow\subscriptions::is_subscribed($uid, $overflow, $context, $did);
                if (!$issubscribed) {
                    continue;
                }

                // Check whether the user unsubscribed to the discussion after it was created.
                $subnow = \local_overflow\subscriptions::fetch_discussion_subscription($overflow->id, $userto->id);
                if ($subnow && isset($subnow[$post->discussion]) && ($subnow[$post->discussion] > $post->created)) {
                    continue;
                }

                if (\local_overflow\anonymous::is_post_anonymous($discussion, $overflow, $post->userid)) {
                    $userfrom = \core_user::get_noreply_user();
                } else {
                    // Check whether the sending user is cached already.
                    if (array_key_exists($post->userid, $users)) {
                        $userfrom = $users[$post->userid];
                    } else {
                        // We dont know the the user yet.

                        // Retrieve the user from the database.
                        $userfrom = $DB->get_record('user', ['id' => $post->userid]);
                        if ($userfrom) {
                            overflow_minimise_user_record($userfrom);
                        } else {
                            $uid = $post->userid;
                            $pid = $post->id;
                            mtrace('Could not find user ' . $uid . ', author of post ' . $pid . '. Unable to send message.');
                            continue;
                        }
                    }
                }

                // Setup roles and languages.
                // Check for moodle version. Version 401 supported until 8 December 2025.
                if ($CFG->branch >= 402) {
                    \core\cron::setup_user($userto);
                } else {
                    cron_setup_user($userto);
                }

                // Cache the users capability to view full names.
                if (!isset($userto->viewfullnames[$overflow->id])) {

                    // Find the context system.
                    $context = context_system::instance();

                    // Check the users capabilities.
                    $userto->viewfullnames[$overflow->id] = has_capability('moodle/site:viewfullnames', $context);
                }

                // Cache the users capability to post in the discussion.
                if (!isset($userto->canpost[$discussion->id])) {

                    // Find the context module.
                    $context = context_system::instance();

                    // Check the users capabilities.
                    $canpost = overflow_user_can_post($context, $post, $userto->id);
                    $userto->canpost[$discussion->id] = $canpost;
                }

                // Make sure the current user is allowed to see the post.
                if (!overflow_user_can_see_post($overflow, $discussion, $post)) {
                    mtrace('User ' . $userto->id . ' can not see ' . $post->id . '. Not sending message.');
                    continue;
                }

                // Sent the email.

                // Preapare to actually send the post now. Build up the content.
                $cleanname = str_replace('"', "'", strip_tags(format_string($overflow->name)));
                $context = context_system::instance();
                $shortname = format_string($overflow->name, true, ['context' => $context]);

                // Define a header to make mails easier to track.
                $emailmessageid = generate_email_messageid('moodleoverflow' . $overflow->id);
                $userfrom->customheaders = [
                    'List-Id: "' . $cleanname . '" ' . $emailmessageid,
                    'List-Help: ' . $CFG->wwwroot . '/local/overflow/view.php?m=' . $overflow->id,
                    'Message-ID: ' . generate_email_messageid(hash('sha256', $post->id . 'to' . $userto->id)),
                    'X-Overflow-Id: ' . $overflow->id,
                    'X-Overflow-Name: ' . format_string($overflow->fullname, true),

                    // Headers to help prevent auto-responders.
                    'Precedence: Bulk',
                    'X-Auto-Response-Suppress: All',
                    'Auto-Submitted: auto-generated',
                ];

                // Cache the users capabilities.
                if (!isset($userto->canpost[$discussion->id])) {
                    $canreply = overflow_user_can_post($context, $post, $userto->id);
                } else {
                    $canreply = $userto->canpost[$discussion->id];
                }

                // Format the data.
                $data = new \local_overflow\output\overflow_email(
                    $overflow,
                    $discussion,
                    $post,
                    $userfrom,
                    $userto,
                    $canreply
                );

                // Retrieve the unsubscribe-link.
                $userfrom->customheaders[] = sprintf('List-Unsubscribe: <%s>', $data->get_unsubscribediscussionlink());

                // Check the capabilities to view full names.
                if (!isset($userto->viewfullnames[$overflow->id])) {
                    $data->viewfullnames = has_capability('moodle/site:viewfullnames', $context, $userto->id);
                } else {
                    $data->viewfullnames = $userto->viewfullnames[$overflow->id];
                }

                // Retrieve needed variables for the mail.
                $var = new \stdClass();
                $var->subject = $data->get_subject();
                $var->overflowname = $cleanname;
                $var->sitefullname = format_string($site->fullname);
                $var->siteshortname = format_string($site->shortname);
                $postsubject = html_to_text(get_string('postmailsubject', 'local_overflow', $var), 0);
                $rootid = generate_email_messageid(hash('sha256', $discussion->firstpost . 'to' . $userto->id));

                // Check whether the post is a reply.
                if ($post->parent) {

                    // Add a reply header.
                    $parentid = generate_email_messageid(hash('sha256', $post->parent . 'to' . $userto->id));
                    $userfrom->customheaders[] = "In-Reply-To: $parentid";

                    // Comments need a reference to the starting post as well.
                    if ($post->parent != $discussion->firstpost) {
                        $userfrom->customheaders[] = "References: $rootid $parentid";
                    } else {
                        $userfrom->customheaders[] = "References: $parentid";
                    }
                }

                // Send the post now.
                mtrace('Sending ', '');

                // Create the message event.
                $eventdata = new \core\message\message();
                $eventdata->component = 'local_overflow';
                $eventdata->name = 'posts';
                $eventdata->userfrom = $userfrom;
                $eventdata->userto = $userto;
                $eventdata->subject = $postsubject;
                $eventdata->fullmessage = $textout->render($data);
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml = $htmlout->render($data);
                $eventdata->notification = 1;

                // Initiate another message array.
                $small = new \stdClass();
                $small->user = fullname($userfrom);
                $formatedstring = format_string($overflow->name, true);
                $small->overflowname = "$shortname: " . $formatedstring . ": " . $discussion->name;
                $small->message = $post->message;

                // Make sure the language is correct.
                $usertol = $userto->lang;
                $eventdata->smallmessage = get_string_manager()->get_string('smallmessage', 'local_overflow', $small, $usertol);

                // Generate the url to view the post.
                $url = '/local/overflow/discussion.php';
                $params = ['d' => $discussion->id];
                $contexturl = new moodle_url($url, $params, 'p' . $post->id);
                $eventdata->contexturl = $contexturl->out();
                $eventdata->contexturlname = $discussion->name;

                // Actually send the message.
                $mailsent = message_send($eventdata);

                // Check whether the sending failed.
                if (!$mailsent) {
                    mtrace('Error: local/overflow/classes/task/send_mail.php execute(): ' .
                        "Could not send out mail for id $post->id to user $userto->id ($userto->email) .. not trying again.");
                    $errorcount[$post->id]++;
                } else {
                    $mailcount[$post->id]++;
                }

                // Tracing message.
                mtrace('post ' . $post->id . ': ' . $discussion->name);
            }

            // Release the memory.
            unset($userto);
        }
    }

    // Check for all posts whether errors occurred.
    if ($posts) {

        // Loop through all posts.
        foreach ($posts as $post) {

            // Tracing information.
            mtrace($mailcount[$post->id] . " users were sent post $post->id, '$discussion->name'");

            // Mark the posts with errors in the database.
            if ($errorcount[$post->id]) {
                $DB->set_field('overflow_posts', 'mailed', OVERFLOW_MAILED_ERROR, ['id' => $post->id]);
            }
        }
    }

    // The task was completed.
    return true;
}

/**
 * Returns a list of all posts that have not been mailed yet.
 *
 * @param int $starttime posts created after this time
 * @param int $endtime   posts created before this time
 *
 * @return array
 */
function overflow_get_unmailed_posts($starttime, $endtime) {
    global $DB;

    // Set params for the sql query.
    $params = [];
    $params['ptimestart'] = $starttime;
    $params['ptimeend'] = $endtime;

    $pendingmail = OVERFLOW_MAILED_PENDING;
    $reviewsent = OVERFLOW_MAILED_REVIEW_SUCCESS;

    // Retrieve the records.
    $sql = "SELECT p.*, d.overflow
            FROM {overflow_posts} p
            JOIN {overflow_discussions} d ON d.id = p.discussion
            WHERE p.mailed IN ($pendingmail, $reviewsent) AND p.reviewed = 1
            AND COALESCE(p.timereviewed, p.created) >= :ptimestart AND p.created < :ptimeend
            ORDER BY p.modified ASC";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Marks posts before a certain time as being mailed already.
 *
 * @param int $endtime
 *
 * @return bool
 */
function overflow_mark_old_posts_as_mailed($endtime) {
    global $DB;

    // Get the current timestamp.
    $now = time();

    // Define variables for the sql query.
    $params = [];
    $params['mailedsuccess'] = OVERFLOW_MAILED_SUCCESS;
    $params['mailedreviewsent'] = OVERFLOW_MAILED_REVIEW_SUCCESS;
    $params['now'] = $now;
    $params['endtime'] = $endtime;
    $params['mailedpending'] = OVERFLOW_MAILED_PENDING;

    // Define the sql query.
    $sql = "UPDATE {overflow_posts}
            SET mailed = :mailedsuccess
            WHERE (created < :endtime) AND mailed IN (:mailedpending, :mailedreviewsent) AND reviewed = 1";

    return $DB->execute($sql, $params);

}

/**
 * Removes unnecessary information from the user records for the mail generation.
 *
 * @param stdClass $user
 */
function overflow_minimise_user_record(stdClass $user) {

    // Remove all information for the mail generation that are not needed.
    unset($user->institution);
    unset($user->department);
    unset($user->address);
    unset($user->city);
    unset($user->url);
    unset($user->currentlogin);
    unset($user->description);
    unset($user->descriptionformat);
}

/**
 * Adds information about unread messages, that is only required for the view page (and
 * similar), to the course-module object.
 *
 * @param object $overflow
 */
function overflow_info_view($overflow) {

    $cantrack = \local_overflow\readtracking::overflow_can_track_overflows();
    $out = "";
    $url = new moodle_url('/local/overflow/index.php', ['id' => $overflow->id]);
    if (has_capability('local/overflow:reviewpost', context_system::instance())) {
        $reviewcount = \local_overflow\review::count_outstanding_reviews_in_overflow($overflow->id);
        if ($reviewcount) {
            $out .= '<span class="local_overflow-label-review"><a href="' . $url . '">';
            $out .= get_string('amount_waiting_for_review', 'local_overflow', $reviewcount);
            $out .= '</a></span> ';
        }
    }
    if ($cantrack) {
        $unread = \local_overflow\readtracking::overflow_count_unread_posts_overflow($overflow->id);
        if ($unread) {
            $out .= '<span class="local_overflow-label-unread"> <a href="' . $url . '">';
            if ($unread == 1) {
                $out .= get_string('unreadpostsone', 'local_overflow');
            } else {
                $out .= get_string('unreadpostsnumber', 'local_overflow', $unread);
            }
            $out .= '</a></span>';
        }
    }
    if ($out) {
        $cm->set_after_link($out);
    }
}

/**
 * Check if the user can create attachments in overflow.
 *
 * @param  stdClass $overflow overflow object
 * @param  context_system $context 
 *
 * @return bool true if the user can create attachments, false otherwise
 * @since  Moodle 3.3
 */
function overflow_can_create_attachment($overflow, $context) {
    // If maxbytes == 1 it means no attachments at all.
    if (empty($overflow->maxattachments) || $overflow->maxbytes == 1 ||
        !has_capability('local/overflow:createattachment', $context)
    ) {
        return false;
    }

    return true;
}

/**
 * Obtain grades from plugin's database tab
 *
 * @param stdClass $overflow overflow object
 * @param int $userid optional userid, 0 means all users.
 *
 * @return array array of grades
 */
function overflow_get_user_grades($overflow, $userid=0) {
    global $CFG, $DB;

    $params = ["overflowid" => $overflow->id];

    $sql = "SELECT u.id AS userid, g.grade AS rawgrade
              FROM {user} u, {overflow_grades} g
             WHERE u.id = g.userid AND g.overflowid = :overflowid";

    if ($userid) {
        $sql .= ' AND u.id = :userid ';
        $params["userid"] = $userid;
    }

    return $DB->get_records_sql($sql, $params);
}

/**
 * Update grades
 *
 * @param stdClass $overflow overflow object
 * @param int $userid userid
 * @param bool $nullifnone
 *
 */
function overflow_update_grades($overflow, $userid, $nullifnone = null) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    // Try to get the grades to update.
    if ($grades = overflow_get_user_grades($overflow, $userid)) {

        overflow_grade_item_update($overflow, $grades);

    } else if ($userid && $nullifnone) {

        // Insert a grade with rawgrade = null. As described in Gradebook API.
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        overflow_grade_item_update($overflow, $grade);

    } else {
        overflow_grade_item_update($overflow);
    }

}

/**
 * Update plugin's grade item
 *
 * @param stdClass $overflow overflow object
 * @param array $grades array of grades
 *
 * @return int grade_update function success code
 */
function overflow_grade_item_update($overflow, $grades=null) {
    global $CFG, $DB;

    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = ['itemname' => $overflow->name, 'idnumber' => $overflow->id];

    if ($overflow->grademaxgrade <= 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($overflow->grademaxgrade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $overflow->grademaxgrade;
        $params['grademin'] = 0;

    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradeupdate = grade_update('local/overflow', 1, 'mod', 'overflow',
            $overflow->id, 0, $grades, $params);

    // Modify grade item category id.
    if (!is_null($overflow->gradecat) && $overflow->gradecat > 0) {
        $params = ['itemname' => $overflow->name, 'idnumber' => $overflow->id];
        $DB->set_field('grade_items', 'categoryid', $overflow->gradecat, $params);
    }

    return $gradeupdate;
}

/**
 * Map icons for font-awesome themes.
 */
function local_overflow_get_fontawesome_icon_map() {
    return [
        'local_overflow:i/commenting' => 'fa-commenting',
        'local_overflow:i/pending-big' => 'fa-clock-o text-danger overflow-icon-2x',
        'local_overflow:i/status-helpful' => 'fa-thumbs-up overflow-icon-1_5x overflow-text-orange',
        'local_overflow:i/status-solved' => 'fa-check overflow-icon-1_5x overflow-text-green',
        'local_overflow:i/reply' => 'fa-reply',
        'local_overflow:i/subscribed' => 'fa-bell overflow-icon-1_5x',
        'local_overflow:i/unsubscribed' => 'fa-bell-slash-o overflow-icon-1_5x',
        'local_overflow:i/vote-up' => 'fa-chevron-up overflow-icon-2x overflow-icon-no-margin',
        'local_overflow:i/vote-down' => 'fa-chevron-down overflow-icon-2x overflow-icon-no-margin',
    ];
}
