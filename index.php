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
 * overflow index.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
//defined('MOODLE_INTERNAL') || die();

global $CFG, $DB, $PAGE, $USER, $SESSION, $OUTPUT;


require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
global $CFG, $DB, $PAGE, $USER, $SESSION, $OUTPUT;
require_once(dirname(__FILE__) . '/locallib.php');

$context = context_system::instance(); 
$PAGE->set_context($context); 

// Fetch submitted parameters.
//$id = 1;
$id = required_param('id', PARAM_INT);
$subscribe = optional_param('subscribe', null, PARAM_INT);

// Set an url to go back to the page.
$url = new moodle_url('/local/overflow/index.php', ['id' => $id]);

// Check whether the subscription parameter was set.
if ($subscribe !== null) {
    require_sesskey();
    $url->param('subscribe', $subscribe);
}

// The the url of this page.
$PAGE->set_url($url);

$PAGE->set_pagelayout('incourse');
unset($SESSION->fromdiscussion);


// Cache some strings.
$string = [];
$string['overflow'] = get_string('overflow', 'local_overflow');
$string['overflows'] = get_string('overflows', 'local_overflow');
$string['modulenameplural'] = get_string('modulenameplural', 'local_overflow');
$string['description'] = get_string('description');
$string['discussions'] = get_string('discussions', 'local_overflow');
$string['subscribed'] = get_string('subscribed', 'local_overflow');
$string['unreadposts'] = get_string('unreadposts', 'local_overflow');
$string['tracking'] = get_string('tracking', 'local_overflow');
$string['markallread'] = get_string('markallread', 'local_overflow');
$string['trackoverflow'] = get_string('trackoverflow', 'local_overflow');
$string['notrackoverflow'] = get_string('notrackoverflow', 'local_overflow');
$string['subscribe'] = get_string('subscribe', 'local_overflow');
$string['unsubscribe'] = get_string('unsubscribe', 'local_overflow');
$string['subscribeenrolledonly'] = get_string('subscribeenrolledonly', 'local_overflow');
$string['allsubscribe'] = get_string('allsubscribe', 'local_overflow');
$string['allunsubscribe'] = get_string('allunsubscribe', 'local_overflow');
$string['generaloverflows'] = get_string('generaloverflows', 'local_overflow');
$string['yes'] = get_string('yes');
$string['no'] = get_string('no');

// Begin to print a table for the general area.
$generaltable = new html_table();
$generaltable->head = [$string['overflow'], $string['description'], $string['discussions']];
$generaltable->align = ['left', 'left', 'center'];

// Check whether overflows can be tracked.
$cantrack = \local_overflow\readtracking::overflow_can_track_overflows();
if ($cantrack) {
    $untracked = \local_overflow\readtracking::get_untracked_overflows($USER->id, $id);

    // Add information about the unread posts to the table.
    $generaltable->head[] = $string['unreadposts'];
    $generaltable->align[] = 'center';

    // Add information about the tracking to the table.
    $generaltable->head[] = $string['tracking'];
    $generaltable->align[] = 'center';
}

// Fill the subscription cache for this page and user combination.
\local_overflow\subscriptions::fill_subscription_cache($id, $USER->id);

// Initiate tables and variables.
$table = new html_table();
$generaloverflows = [];
$showsubscriptioncolumns = false;

// Parse and organize all overflows.
$overflow = $DB->get_record('overflow', ['id' => $id]);

if (!$overflow) {
    notice(get_string('notexists', 'local_overflow'));
}

// Check whether the user can see the list.
if (!has_capability('local/overflow:viewdiscussion', $context))  {
    notice(get_string('notallowed', 'local_overflow'));
}


// Get information about the subscription state.
$cansubscribe = \local_overflow\subscriptions::is_subscribable($overflow, $context);
$overflow->cansubscribe = $cansubscribe || has_capability('local/overflow:managesubscriptions', $context);
$overflow->issubscribed = \local_overflow\subscriptions::is_subscribed($USER->id, $overflow, $context);
$showsubscriptioncolumns = $showsubscriptioncolumns || $overflow->issubscribed || $overflow->cansubscribe;

// Add the overflow to the cache.
$generaloverflows[$id] = $overflow;

// Check whether the subscription columns need to be displayed.
if ($showsubscriptioncolumns) {
    // The user can subscribe to at least one overflow.

    // Add the subscription state to the table.
    $generaltable->head[] = $string['subscribed'];
}

// Handle wide subscriptions or unsubscriptions if requested.
if (!is_null($subscribe)) {

    // Catch guests and not subscribable overflows.
    if (isguestuser() || !$showsubscriptioncolumns) {

        // Redirect the user back.
        $url = new moodle_url('/local/overflow/index.php', ['id' => $id]);
        $notification = \core\output\notification::NOTIFY_ERROR;
        redirect($url, $string['subscribeenrolledonly'], null, $notification);
    }

    // Loop through all overflows.

        // Initiate variables.
        //$overflow = $overflows[$overflowid];
        $context = context_system::instance();
        $cansub = false;

        // Check capabilities.
        $cap['viewdiscussion'] = has_capability('local/overflow:viewdiscussion', $context);
        $cap['managesubscriptions'] = has_capability('local/overflow:managesubscriptions', $context);
        $cap['manageactivities'] = has_capability('moodle/course:manageactivities', $context, $USER->id);

        // Check whether the user can view the discussions.
        if ($cap['viewdiscussion']) {
            $cansub = true;
        }

        // Check whether the user can manage subscriptions.
        if ($cansub && !$cap['managesubscriptions']) {
            $cansub = false;
        }

        // Check the subscription state.
        $forcesubscribed = \local_overflow\subscriptions::is_forcesubscribed($overflow) &&
                has_capability('local/overflow:allowforcesubscribe', $context);
        if (!$forcesubscribed) {

            // Check the current state.
            $subscribed = \local_overflow\subscriptions::is_subscribed($USER->id, $overflow, $context);
            $subscribable = \local_overflow\subscriptions::is_subscribable($overflow, $context);

            // Check whether to subscribe or unsubscribe the user.
            if ($cap['manageactivities'] || $subscribable && $subscribe && !$subscribed && $cansub) {
                \local_overflow\subscriptions::subscribe_user($USER->id, $overflow, $context, true);
            } else {
                \local_overflow\subscriptions::unsubscribe_user($USER->id, $overflow, $context, true);
            }
        }


    // Create an url to return the user back to.
    $url = new moodle_url('/local/overflow/index.php', ['id' => $id]);
    $returnto = overflow_go_back_to($url);

    // Prepare the message to be displayed.
    $shortname = format_string($overflow->name, true, ['context' => $context]);
    $notification = \core\output\notification::NOTIFY_SUCCESS;

    // Redirect the user depending on the subscription state.
    if ($subscribe) {
        redirect($returnto, get_string('nowallsubscribed', 'local_overflow', $shortname), null, $notification);
    } else {
        redirect($returnto, get_string('nowallunsubscribed', 'local_overflow', $shortname), null, $notification);
    }
}

// Check if there are overflows.
if ($generaloverflows) {

    // Loop through all of the overflows.
    foreach ($generaloverflows as $overflow) {

        // Retrieve the contexts.
        $context = context_system::instance();

        // Count the discussions within the overflow.
        $count = overflow_count_discussions($overflow);

        // Check whether the user can track the overflow.
        if ($cantrack) {

            // Check whether the tracking is disabled.
            if ($overflow->trackingtype == OVERFLOW_TRACKING_OFF) {
                $unreadlink = '-';
                $trackedlink = '-';
            } else {
                // The overflow can be tracked.

                // Check if this overflow is manually untracked.
                if (isset($untracked[$overflow->id])) {
                    $unreadlink = '-';

                } else if ($unread = \local_overflow\readtracking::overflow_count_unread_posts_overflow($overflow->id)
                ) {
                    // There are unread posts in the overflow instance.

                    // Create a string to be displayed.
                    $unreadlink = '<span class="unread">';
                    $unreadlink .= '<a href="view.php?m=' . $overflow->id . '">' . $unread . '</a>';
                    $unreadlink .= '<a title="' . $string['markallread'] . '" href="markposts.php?m=' . $overflow->id .
                        '&amp;mark=read&amp;sesskey=' . sesskey() . '">';
                    $unreadlink .= '<img src="' . $OUTPUT->image_url('t/markasread') . '" alt="' .
                        $string['markallread'] . '" class="iconsmall" />';
                    $unreadlink .= '</a>';
                    $unreadlink .= '</span>';

                } else {
                    // There are no unread messages for this overflow instance.

                    // Create a string to be displayed.
                    $unreadlink = '<span class="read">0</span>';
                }

                // Check whether the overflow instance can be tracked.
                $isforced = $overflow->trackingtype == OVERFLOW_TRACKING_FORCED;
                if ($isforced && (get_config('local_overflow', 'allowforcedreadtracking'))) {
                    // Tracking is set to forced.

                    // Define the string.
                    $trackedlink = $string['yes'];

                } else if ($overflow->trackingtype === OVERFLOW_TRACKING_OFF) {
                    // Tracking is set to off.

                    // Define the string.
                    $trackedlink = '-';

                } else {
                    // Tracking is optional.

                    // Define the url the button is linked to.
                    $trackingurlparams = ['id' => $overflow->id, 'sesskey' => sesskey()];
                    $trackingurl = new moodle_url('/local/overflow/tracking.php', $trackingurlparams);

                    // Check whether the overflow instance is tracked.
                    if (!isset($untracked[$overflow->id])) {
                        $trackingparam = ['title' => $string['notrackoverflow']];
                        $trackedlink = $OUTPUT->single_button($trackingurl, $string['yes'], 'post', $trackingparam);
                    } else {
                        $trackingparam = ['title' => $string['trackoverflow']];
                        $trackedlink = $OUTPUT->single_button($trackingurl, $string['no'], 'post', $trackingparam);
                    }
                }
            }
        }

        // Get information about the overflow instance.
        $overflowname = format_string($overflow->name, true);

        // Check if the context module is visible.
        $style = 'class="dimmed"';

        // Create links to the overflow and the discussion.
        $overflowlink = "<a href=\"view.php?m=$overflow->id\" $style>"
            . format_string($overflow->name, true) . '</a>';
        $discussionlink = "<a href=\"view.php?m=$overflow->id\" $style>" . $count . "</a>";

        // Create rows.
        $row = [$overflowlink, $overflow->name, $discussionlink];

        // Add the tracking information to the rows.
        if ($cantrack) {
            $row[] = $unreadlink;
            $row[] = $trackedlink;
        }

        // Add the subscription information to the rows.
        if ($showsubscriptioncolumns) {

            // Set options to create the subscription link.
            $suboptions = [
                'subscribed' => $string['yes'],
                'unsubscribed' => $string['no'],
                'forcesubscribed' => $string['yes'],
                'cantsubscribe' => '-',
            ];

            // Add the subscription link to the row.
            $row[] = \local_overflow\subscriptions::overflow_get_subscribe_link($overflow,
                $context, $suboptions);
        }

        // Add the rows to the table.
        $generaltable->data[] = $row;
    }
}

// Output the page.
$PAGE->navbar->add($string['overflows']);
$PAGE->set_title( $string['overflows']);
$PAGE->set_heading($overflow->name);

// The page should not be large, only pages containing broad tables are usually.
$PAGE->add_body_class('limitedwidth');

echo $OUTPUT->header();

// Show the subscribe all option only to non-guest and enrolled users.
if (!isguestuser() && isloggedin() && $showsubscriptioncolumns) {

    // Create a box.
    echo $OUTPUT->box_start('subscription');

    // Create the subscription link.
    $urlparams = ['id' => $id, 'sesskey' => sesskey()];
    $subscriptionlink = new moodle_url('/local/overflow/index.php', $urlparams);

    // Give the option to subscribe to all.
    $subscriptionlink->param('subscribe', 1);
    $htmllink = html_writer::link($subscriptionlink, $string['allsubscribe']);
    echo html_writer::tag('div', $htmllink, ['class' => 'helplink']);

    // Give the option to unsubscribe from all.
    $subscriptionlink->param('subscribe', 0);
    $htmllink = html_writer::link($subscriptionlink, $string['allunsubscribe']);
    echo html_writer::tag('div', $htmllink, ['class' => 'helplink']);

    // Print the box.
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

// Print the overflows.
if ($generaloverflows) {
    echo $OUTPUT->heading($string['generaloverflows'], 2);
    echo html_writer::table($generaltable);
}

// Print the pages footer.
echo $OUTPUT->footer();
