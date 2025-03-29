<?php
// This file is part of Exabis Library
//
// (c) 2023 GTN - Global Training Network GmbH <office@gtn-solutions.com>
//
// Exabis Library is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This script is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You can find the GNU General Public License at <http://www.gnu.org/licenses/>.
//
// This copyright notice MUST APPEAR in all copies of the script!


require_once(__DIR__ . "/../../config.php");
require_once('admin_form.php');
require_login();


$context = context_system::instance();
/*require_capability('local/overflow:admin', $context);

if (!has_capability('local/overflow:admin', context_system::instance())) {
    throw new require_login_exception(get_string('notallowed', 'local_overflow'));
}
*/

$PAGE->set_url(new moodle_url('/local/overflow/admin.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_overflow'));
$PAGE->set_heading(get_string('pluginname', 'local_overflow'));

// Output page.
echo $OUTPUT->header();

$mform = new local_overflow_admin_form();

// Check if we are editing an existing news item.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'edit') {
    $id = required_param('id', PARAM_INT);
    if ($overflow = $DB->get_record('overflow', ['id' => $id])) {
        //$overflow->name = ['text' => $overflow->name, 'format' => FORMAT_HTML];
        $overflow->action = 'edit';
        $mform->set_data($overflow);
    }
} elseif ($action == 'delete') {
    // Delete hot news.
    $id = required_param('id', PARAM_INT);
    $DB->delete_records('overflow', ['id' => $id]);
}

// Check if the form is submitted.
if ($mform->is_cancelled()) {
    // Redirect to the main page if the user cancels the form.
    redirect(new moodle_url('/local/overflow/'));
} else if ($fromform = $mform->get_data()) {
    // Handle form submission, save the data.
    // Assuming `overflow_data` is a custom table where you want to save the form data.
    global $DB;

    // If there's an existing record (edit mode), update it
    if ($fromform->id) {
        // Prepare data for updating
        $update_data = (object) [
            'id' => $fromform->id,
            'name' => $fromform->name,
            'anonymous' => $fromform->anonymous,
            'needsreview' => $fromform->needsreview,
            'maxbytes' => $fromform->maxbytes,
            'maxattachments' => $fromform->maxattachments,
            'forcesubscribe' => $fromform->forcesubscribe,
            'trackingtype' => $fromform->trackingtype,
            'grademaxgrade' => $fromform->grademaxgrade,
            'gradescalefactor' => $fromform->gradescalefactor,
            'ratingpreference' => $fromform->ratingpreference,
            'allowrating' => $fromform->allowrating,
            'allowreputation' => $fromform->allowreputation,
            'widereputation' => $fromform->widereputation,
            'allownegativereputation' => $fromform->allownegativereputation,
            'allowmultiplemarks' => $fromform->allowmultiplemarks,
            'timemodified' => time(),
        ];

        // Update record in the database
        $DB->update_record('overflow', $update_data);

    } else {
        // Insert new record if no existing ID
        $insert_data = (object) [
            'name' => $fromform->name,
            'anonymous' => isset($fromform->anonymous) ? $fromform->anonymous : 0,
            'needsreview' => isset($fromform->needsreview) ? $fromform->needsreview : 0,
            'maxbytes' => $fromform->maxbytes,
            'maxattachments' => $fromform->maxattachments,
            'forcesubscribe' => $fromform->forcesubscribe,
            'trackingtype' => $fromform->trackingtype,
            'grademaxgrade' => $fromform->grademaxgrade,
            'gradescalefactor' => $fromform->gradescalefactor,
            'ratingpreference' => $fromform->ratingpreference,
            'allowrating' => isset($fromform->allowrating) ? $fromform->allowrating : 0,
            'allowreputation' => isset($fromform->allowreputation) ? $fromform->allowreputation : 0,
            'widereputation' => $fromform->widereputation,
            'allownegativereputation' => $fromform->allownegativereputation,
            'allowmultiplemarks' => $fromform->allowmultiplemarks,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        // Insert new record into the database
        $DB->insert_record('overflow', $insert_data);
    }
    redirect(new moodle_url('/local/overflow/admin.php'), get_string('save_overflow', 'local_overflow'));
} else {
    // Display the form.
    $mform->display();
}
// Fetch all news items.
$overflows = $DB->get_records('overflow');

echo '<table class="table">
    <tr>
        <th>' . get_string('id', 'local_overflow') . '</th>
        <th>' . get_string('name', 'local_overflow') . '</th>
        <th>' . get_string('timecreated', 'local_overflow') . '</th>
        <th>' . get_string('anonymous', 'local_overflow') . '</th>
    </tr>';

foreach ($overflows as $overflow) {
    echo '<tr>
        <td>' . $overflow->id . '</td>
        <td>' . $overflow->name . '</td>
        <td>' . userdate($overflow->timecreated) . '</td>
        <td>' . $overflow->anonymous . '</td>
        <td>
            <a href="?id=' . $overflow->id . '&action=edit">' . get_string('edit', 'local_overflow') . '</a>
            <a href="?id=' . $overflow->id . '&action=delete" onclick="return confirm(\'' . get_string('confirmdelete', 'local_overflow') . '\');">' . get_string('delete', 'local_overflow') . '</a>
        </td>
    </tr>';
}

echo '</table>';

echo $OUTPUT->footer();
