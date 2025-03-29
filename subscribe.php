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
 * Subscribe to or unsubscribe from a overflow or manage overflow subscription mode.
 *
 * This script can be used by either individual users to subscribe to or
 * unsubscribe from a overflow (no 'mode' param provided), or by overflow managers
 * to control the subscription mode (by 'mode' param).
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/locallib.php');

global $CFG, $DB, $PAGE, $USER, $SESSION, $OUTPUT;

// Define required and optional params.
$id = required_param('id', PARAM_INT);                         // The overflow to set subscription on.
$mode = optional_param('mode', null, PARAM_INT);      // The overflow's subscription mode.
$user = optional_param('user', 0, PARAM_INT);         // The userid of the user to subscribe, defaults to $USER.
$discussionid = optional_param('d', null, PARAM_INT); // The discussionid to subscribe.
$sesskey = optional_param('sesskey', null, PARAM_RAW);
$returnurl = optional_param('returnurl', null, PARAM_RAW);

// Set the url to return to the same action.
$url = new moodle_url('/local/overflow/subscribe.php', ['id' => $id]);
if (!is_null($mode)) {
    $url->param('mode', $mode);
}
if ($user !== 0) {
    $url->param('user', $user);
}
if (!is_null($sesskey)) {
    $url->param('sesskey', $sesskey);
}
if (!is_null($discussionid)) {
    $url->param('d', $discussionid);
    $discussion = overflow_get_record_or_exception('overflow_discussions',
                                                          ['id' => $discussionid, 'overflow' => $id], 'invaliddiscussionid');
}

// Set the pages URL.
$PAGE->set_url($url);

// Get all necessary objects.
$overflow = $DB->get_record('overflow', ['id' => $id], '*', MUST_EXIST);
$context = context_system::instance();
$PAGE->set_context($context);

// Define variables.
$notify = [];
$notify['success'] = \core\output\notification::NOTIFY_SUCCESS;
$notify['error'] = \core\output\notification::NOTIFY_ERROR;
$strings = [];
$strings['subscribeenrolledonly'] = get_string('subscribeenrolledonly', 'local_overflow');
$strings['everyonecannowchoose'] = get_string('everyonecannowchoose', 'local_overflow');
$strings['everyoneisnowsubscribed'] = get_string('everyoneisnowsubscribed', 'local_overflow');
$strings['noonecansubscribenow'] = get_string('noonecansubscribenow', 'local_overflow');
$strings['invalidforcesubscribe'] = get_string('invalidforcesubscribe', 'local_overflow');

// Check if the user was requesting the subscription himself.
if ($user) {
    // Check the login.
    require_sesskey();

    // Check the users capabilities.
    if (!has_capability('local/overflow:managesubscriptions', $context)) {
        throw new moodle_exception('nopermissiontosubscribe', 'local_overflow');
    }

    // Retrieve the user from the database.
    $user = $DB->get_record('user', ['id' => $user], '*', MUST_EXIST);

} else {

    // The user requested the subscription himself.
    $user = $USER;
}

// Check if the user is already subscribed.
$issubscribed = \local_overflow\subscriptions::is_subscribed($user->id, $overflow, $context, $discussionid);

// To subscribe to a overflow or a discussion, the user needs to be logged in.
require_login();

// Guests, visitors and not enrolled people cannot subscribe.

if (is_null($mode) && !isloggedin()) {

    // Prepare the output.
    $PAGE->set_title($overflow->name);
    $PAGE->set_heading($overflow->name);

    // Redirect guest users to a login page.
    if (isguestuser()) {
        echo $OUTPUT->header();
        $message = $strings['subscribeenrolledonly'] . '<br /></ br>' . get_string('liketologin');
        $url = new moodle_url('/local/overflow/view.php', ['m' => $id]);
        echo $OUTPUT->confirm($message, get_login_url(), $url);
        echo $OUTPUT->footer();
        exit;
    } else {
        // There should not be any links leading to this place. Just redirect.
        $url = new moodle_url('/local/overflow/view.php', ['m' => $id]);
        redirect($url, $strings['subscribeenrolledonly'], null, $notify['error']);
    }
}

// Create the url to redirect the user back to where he is coming from.
$urlindex = 'index.php?id=' . $overflow->id;
$urlview = 'view.php?m=' . $id;
$returnto = optional_param('backtoindex', 0, PARAM_INT) ? $urlindex : $urlview;
if ($returnurl) {
    $returnto = $returnurl;
}

// Change the general subscription state.
if (!is_null($mode) && has_capability('local/overflow:managesubscriptions', $context)) {
    require_sesskey();

    // Set the new mode.
    switch ($mode) {

        // Everyone can choose what he wants.
        case OVERFLOW_CHOOSESUBSCRIBE:
            \local_overflow\subscriptions::set_subscription_mode($overflow->id, OVERFLOW_CHOOSESUBSCRIBE);
            redirect($returnto, $strings['everyonecannowchoose'], null, $notify['success']);
            break;

        // Force users to be subscribed.
        case OVERFLOW_FORCESUBSCRIBE:
            \local_overflow\subscriptions::set_subscription_mode($overflow->id, OVERFLOW_FORCESUBSCRIBE);
            redirect($strings['everyoneisnowsubscribed'], $string, null, $notify['success']);
            break;

        // Default setting.
        case OVERFLOW_INITIALSUBSCRIBE:
            // If users are not forced, subscribe all users.
            if ($overflow->forcesubscribe <> OVERFLOW_INITIALSUBSCRIBE) {
                $users = \local_overflow\subscriptions::get_potential_subscribers($context, 0, 'u.id, u.email', '');
                foreach ($users as $user) {
                    \local_overflow\subscriptions::subscribe_user($overflow->id, $overflow, $context);
                }
            }

            // Change the subscription state.
            \local_overflow\subscriptions::set_subscription_mode($overflow->id, OVERFLOW_INITIALSUBSCRIBE);

            // Redirect the user.
            $string = get_string('everyoneisnowsubscribed', 'local_overflow');
            redirect($returnto, $strings['everyoneisnowsubscribed'], null, $notify['success']);
            break;

        // Do not allow subscriptions.
        case OVERFLOW_DISALLOWSUBSCRIBE:
            \local_overflow\subscriptions::set_subscription_mode($overflow->id, OVERFLOW_DISALLOWSUBSCRIBE);
            $string = get_string('noonecansubscribenow', 'local_overflow');
            redirect($strings['noonecansubscribenow'], $string, null, $notify['success']);
            break;

        default:
            throw new moodle_exception($strings['invalidforcesubscribe']);
    }
}

// Redirect the user back if the user is forced to be subscribed.
$isforced = \local_overflow\subscriptions::is_forcesubscribed($overflow);
if ($isforced && has_capability('local/overflow:allowforcesubscribe', $context)) {
    redirect($returnto, $strings['everyoneisnowsubscribed'], null, $notify['success']);
    exit;
}

// Create an info object.
$info = new stdClass();
$info->name = fullname($user);
$info->overflow = format_string($overflow->name);

// Check if the user is subscribed to the overflow.
// The action is to unsubscribe the user.
if ($issubscribed) {

    // Check if there is a sesskey.
    if (is_null($sesskey)) {

        // Perpare the output.
        $PAGE->set_title($overflow->name);
        $PAGE->set_heading($overflow->name);
        echo $OUTPUT->header();

        // Create an url to get back to the view.
        $viewurl = new moodle_url('/local/overflow/view.php', ['m' => $id]);

        // Was a discussion id submitted?
        if ($discussionid) {

            // Create a new info object.
            $info2 = new stdClass();
            $info2->overflow = format_string($overflow->name);
            $info2->discussion = format_string($discussion->name);

            // Create a confirm statement.
            $string = get_string('confirmunsubscribediscussion', 'local_overflow', $info2);
            echo $OUTPUT->confirm($string, $PAGE->url, $viewurl);

        } else {
            // The discussion is not involved.

            // Create a confirm statement.
            $string = get_string('confirmunsubscribe', 'local_overflow', format_string($overflow->name));
            echo $OUTPUT->confirm($string, $PAGE->url, $viewurl);
        }

        // Print the rest of the page.
        echo $OUTPUT->footer();
        exit;
    }

    // From now on, a valid session key needs to be set.
    require_sesskey();

    // Check if a discussion id is submitted.
    if ($discussionid === null) {

        // Unsubscribe the user and redirect him back to where he is coming from.
        if (\local_overflow\subscriptions::unsubscribe_user($user->id, $overflow, $context, true)) {
            redirect($returnto, get_string('nownotsubscribed', 'local_overflow', $info), null, $notify['success']);
        } else {
            throw new moodle_exception('cannotunsubscribe', 'local_overflow', get_local_referer(false));
        }

    } else {

        // Unsubscribe the user from the discussion.
        if (\local_overflow\subscriptions::unsubscribe_user_from_discussion($user->id, $discussion, $context)) {
            $info->discussion = $discussion->name;
            redirect($returnto, get_string('discussionnownotsubscribed', 'local_overflow', $info), null, $notify['success']);
        } else {
            throw new moodle_exception('cannotunsubscribe', 'local_overflow', get_local_referer(false));
        }
    }

} else {
    // The user needs to be subscribed.

    // Check the capabilities.
    $capabilities = [];
    $capabilities['managesubscriptions'] = has_capability('local/overflow:managesubscriptions', $context);
    $capabilities['viewdiscussion'] = has_capability('local/overflow:viewdiscussion', $context);
    require_sesskey();

    // Check if subscriptionsare allowed.
    $disabled = \local_overflow\subscriptions::subscription_disabled($overflow);
    if ($disabled && !$capabilities['managesubscriptions']) {
        throw new moodle_exception('disallowsubscribe', 'local_overflow', get_local_referer(false));
    }

    // Check if the user can view discussions.
    if (!$capabilities['viewdiscussion']) {
        throw new moodle_exception('noviewdiscussionspermission', 'local_overflow', get_local_referer(false));
    }

    // Check the session key.
    if (is_null($sesskey)) {

        // Prepare the output.
        $PAGE->set_title($overflow->name);
        $PAGE->set_heading($overflow->name);
        echo $OUTPUT->header();

        // Create the url to redirect the user back to.
        $viewurl = new moodle_url('/local/overflow/view.php', ['m' => $id]);

        // Check whether a discussion is referenced.
        if ($discussionid) {

            // Create a new info object.
            $info2 = new stdClass();
            $info2->overflow = format_string($overflow->name);
            $info2->discussion = format_string($discussion->name);

            // Create a confirm dialog.
            $string = get_string('confirmsubscribediscussion', 'local_overflow', $info2);
            echo $OUTPUT->confirm($string, $PAGE->url, $viewurl);

        } else {
            // No discussion is referenced.

            // Create a confirm dialog.
            $string = get_string('confirmsubscribe', 'local_overflow', format_string($overflow->name));
            echo $OUTPUT->confirm($string, $PAGE->url, $viewurl);
        }

        // Print the missing part of the page.
        echo $OUTPUT->footer();
        exit;
    }

    // From now on, there needs to be a valid session key.
    require_sesskey();

    // Check if the subscription is refered to a discussion.
    if ($discussionid == null) {

        // Subscribe the user to the overflow instance.
        \local_overflow\subscriptions::subscribe_user($user->id, $overflow, $context, true);
        redirect($returnto, get_string('nowsubscribed', 'local_overflow', $info), null, $notify['success']);
        exit;

    } else {
        $info->discussion = $discussion->name;

        // Subscribe the user to the discussion.
        \local_overflow\subscriptions::subscribe_user_to_discussion($user->id, $discussion, $context);
        redirect($returnto, get_string('discussionnowsubscribed', 'local_overflow', $info), null, $notify['success']);
        exit;
    }
}
