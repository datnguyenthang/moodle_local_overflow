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
 * Prints a particular instance of overflow
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package   local_overflow
 * @copyright 2023 Tamaro Walter
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Include config and locallib.
require_once(__DIR__.'/../../config.php');
global $CFG, $PAGE, $DB, $OUTPUT, $SESSION;
require_once($CFG->dirroot . '/local/overflow/locallib.php');

use local_overflow\tables\userstats_table;
// Declare optional parameters.
$mid = required_param('mid', PARAM_INT);             // Moodleoveflow ID, overflow that started the statistics.

// Define important variables.
if ($mid) {
    $overflow = $DB->get_record('overflow', ['id' => $mid], '*');
}
// Require a login.
require_login();

// Set the context.
$context = context_system::instance();
$PAGE->set_context($context);

// Do a capability check, in case a user iserts the userstats-url manually.
if (has_capability('local/overflow:viewanyrating', $context) && get_config('local_overflow', 'showuserstats')) {
    // Print the page header.
    $PAGE->set_url('/local/overflow/userstats.php', ['mid' => $overflow->id, ]);
    $PAGE->set_title(format_string('User statistics'));
    $PAGE->set_heading(format_string('User statistics of overflow: ' . $overflow->name));

    // Output starts here.
    echo $OUTPUT->header();
    echo $OUTPUT->heading('');
    $table = new userstats_table('statisticstable' , $overflow->id, $PAGE->url);
    $table->out();
    echo $OUTPUT->footer();
} else {
    redirect(new moodle_url('/'));
}

