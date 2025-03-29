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
 * Set tracking option for the overflow.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Require needed files.
require_once("../../config.php");
require_once("locallib.php");
global $CFG, $DB, $USER;

$context = context_system::instance();
$PAGE->set_context($context);

// Get submitted parameters.
$id = required_param('id', PARAM_INT);                       // The overflow to track or untrack.
$returnpage = optional_param('returnpage', 'index.php', PARAM_FILE); // The page to return to.

// A session key is needed to change the tracking options.
require_sesskey();

// Retrieve the overflow instance to track or untrack.
$overflow = overflow_get_record_or_exception('overflow', ['id' => $id], 'invalidoverflowid');

// From now on the user needs to be logged in and enrolled.
require_login();

// Set the page to return to.
$url = '/local/overflow/' . $returnpage;
$params = ['id' => $overflow->id];
$returnpageurl = new moodle_url($url, $params);
$returnto = overflow_go_back_to($returnpageurl);

// Check whether the user can track the overflow instance.
$cantrack = \local_overflow\readtracking::overflow_can_track_overflows($overflow);

// Do not continue if the user is not allowed to track the overflow. Redirect the user back.
if (!$cantrack) {
    redirect($returnto);
    exit;
}

// Create an info object.
$info = new stdClass();
$info->name = fullname($USER);
$info->overflow = format_string($overflow->name);

// Set parameters for an event.
$eventparams = [
    'context' => context_system::instance(),
    'relateduserid' => $USER->id,
    'other' => ['overflowid' => $overflow->id],
];

// Check whether the overflow is tracked.
$istracked = \local_overflow\readtracking::overflow_is_tracked($overflow);
if ($istracked) {
    // The overflow instance is tracked. The next step is to untrack.

    // Untrack the overflow instance.
    if (\local_overflow\readtracking::overflow_stop_tracking($overflow->id)) {
        // Successful stopped to track.

        // Trigger the readtracking disabled event.
        $event = \local_overflow\event\readtracking_disabled::create($eventparams);
        $event->trigger();

        // Redirect the user back to where he is coming from.
        redirect($returnpageurl, get_string('nownottracking', 'local_overflow', $info), 1);

    } else {
        // The insertion failed. Print an error message.
        throw new moodle_exception('cannottrack', 'local_overflow', get_local_referer(false));
    }

} else {
    // The overflow instance is not tracked. The next step is to track.

    // Track the overflow instance.
    if (\local_overflow\readtracking::overflow_start_tracking($overflow->id)) {
        // Successfully started to track.

        // Trigger the readtracking event.
        $event = \local_overflow\event\readtracking_enabled::create($eventparams);
        $event->trigger();

        // Redirect the user back to where he is coming from.
        redirect($returnto, get_string('nowtracking', 'local_overflow', $info), 1);

    } else {
        // The deletion failed. Print an error message.
        throw new moodle_exception('cannottrack', 'local_overflow', get_local_referer(false));
    }
}
