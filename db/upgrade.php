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
 * This file keeps track of upgrades to the overflow module
 *
 * Sometimes, changes between versions involve alterations to database
 * structures and other major things that may break installations. The upgrade
 * function in this file will attempt to perform all the necessary actions to
 * upgrade your older installation to the current version. If there's something
 * it cannot do itself, it will tell you what you need to do.  The commands in
 * here will all be database-neutral, using the functions defined in DLL libraries.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute overflow upgrade from the given old version
 *
 * @param int $oldversion
 *
 * @return bool
 */
function xmldb_local_overflow_upgrade($oldversion) {
    global $CFG;
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2017110713) {
        // Migrate config.
        set_config('manydiscussions', $CFG->overflow_manydiscussions, 'overflow');
        set_config('maxbytes', $CFG->overflow_maxbytes, 'overflow');
        set_config('maxattachments', $CFG->overflow_maxattachments, 'overflow');
        set_config('maxeditingtime', $CFG->overflow_maxeditingtime, 'overflow');
        set_config('trackingtype', $CFG->overflow_trackingtype, 'overflow');
        set_config('trackreadposts', $CFG->overflow_trackreadposts, 'overflow');
        set_config('allowforcedreadtracking', $CFG->overflow_allowforcedreadtracking, 'overflow');
        set_config('oldpostdays', $CFG->overflow_oldpostdays, 'overflow');
        set_config('cleanreadtime', $CFG->overflow_cleanreadtime, 'overflow');
        set_config('allowratingchange', $CFG->overflow_allowratingchange, 'overflow');
        set_config('votescalevote', $CFG->overflow_votescalevote, 'overflow');
        set_config('votescaledownvote', $CFG->overflow_votescaledownvote, 'overflow');
        set_config('votescaleupvote', $CFG->overflow_votescaleupvote, 'overflow');
        set_config('votescalesolved', $CFG->overflow_votescalesolved, 'overflow');
        set_config('votescalehelpful', $CFG->overflow_votescalehelpful, 'overflow');
        set_config('maxmailingtime', $CFG->overflow_maxmailingtime, 'overflow');

        // Delete old config.
        set_config('overflow_manydiscussions', null, 'overflow');
        set_config('overflow_maxbytes', null, 'overflow');
        set_config('overflow_maxattachments', null, 'overflow');
        set_config('overflow_maxeditingtime', null, 'overflow');
        set_config('overflow_trackingtype', null, 'overflow');
        set_config('overflow_trackreadposts', null, 'overflow');
        set_config('overflow_allowforcedreadtracking', null, 'overflow');
        set_config('overflow_oldpostdays', null, 'overflow');
        set_config('overflow_cleanreadtime', null, 'overflow');
        set_config('overflow_allowratingchange', null, 'overflow');
        set_config('overflow_votescalevote', null, 'overflow');
        set_config('overflow_votescaledownvote', null, 'overflow');
        set_config('overflow_votescaleupvote', null, 'overflow');
        set_config('overflow_votescalesolved', null, 'overflow');
        set_config('overflow_votescalehelpful', null, 'overflow');
        set_config('overflow_maxmailingtime', null, 'overflow');

        // Opencast savepoint reached.
        upgrade_mod_savepoint(true, 2017110713, 'overflow');
    }

    if ($oldversion < 2019052600) {

        // Define table overflow_grades to be created.
        $table = new xmldb_table('overflow_grades');

        // Adding fields to table overflow_grades.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('overflowid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('grade', XMLDB_TYPE_FLOAT, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table overflow_grades.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('overflowid', XMLDB_KEY_FOREIGN, ['overflowid'], 'overflow', ['id']);

        // Conditionally launch create table for overflow_grades.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table overflow to be edited.
        $table = new xmldb_table('overflow');

        // Define field grademaxgrade to be added to overflow.
        $field = new xmldb_field('grademaxgrade', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'allownegativereputation');

        // Conditionally launch add field grademaxgrade.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field gradescalefactor to be added to overflow.
        $field = new xmldb_field('gradescalefactor', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'grademaxgrade');

        // Conditionally launch add field gradescalefactor.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('gradecat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'gradescalefactor');

        // Conditionally launch add field gradecat.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        overflow_update_all_grades();

        // overflow savepoint reached.
        upgrade_mod_savepoint(true, 2019052600, 'overflow');
    }

    if ($oldversion < 2021060800) {

        // Define table overflow to be edited.
        $table = new xmldb_table('overflow');

        // Define field anonymous to be added to overflow.
        $field = new xmldb_field('anonymous', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 0, 'gradecat');

        // Conditionally launch add field anonymous.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // overflow savepoint reached.
        upgrade_mod_savepoint(true, 2021060800, 'overflow');
    }

    if ($oldversion < 2021072700) {
        // Define fields late and completed to be dropped from overflow_grades.
        $table = new xmldb_table('overflow_grades');

        $field = new xmldb_field('late');
        // Conditionally launch drop field late.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $field = new xmldb_field('completed');
        // Conditionally launch drop field completed.
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2021072700, 'overflow');
    }

    if ($oldversion < 2021111700) {

        // Define table overflow to be edited.
        $table = new xmldb_table('overflow');

        // Define field allowrating to be added to overflow.
        $field = new xmldb_field('allowrating', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 1, 'coursewidereputation');

        // Conditionally launch add field allowrating.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field allowreputation to be added to overflow.
        $field = new xmldb_field('allowreputation', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, 1, 'allowrating');

        // Conditionally launch add field allowreputation.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // overflow savepoint reached.
        upgrade_mod_savepoint(true, 2021111700, 'overflow');
    }

    if ($oldversion < 2022072000) {

        // Define field needsreview to be added to overflow.
        $table = new xmldb_table('overflow');
        $field = new xmldb_field('needsreview', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'anonymous');

        // Conditionally launch add field needsreview.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field reviewed and timereviewed to be added to overflow_posts.
        $table = new xmldb_table('overflow_posts');
        $field = new xmldb_field('reviewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'mailed');

        // Conditionally launch add field reviewed.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'reviewed');

        // Conditionally launch add field timereviewed.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('mailed', XMLDB_TYPE_INTEGER, '2', null, null, null, '0', 'attachment');
        // Launch change of precision for field mailed.
        $dbman->change_field_precision($table, $field);

        // overflow savepoint reached.
        upgrade_mod_savepoint(true, 2022072000, 'overflow');
    }

    if ($oldversion < 2022110700) {

        if (get_capability_info('local/overflow:reviewpost')) {
            foreach (get_archetype_roles('manager') as $role) {
                unassign_capability('local/overflow:reviewpost', $role->id);
            }

            foreach (get_archetype_roles('teacher') as $role) {
                assign_capability(
                    'local/overflow:reviewpost', CAP_ALLOW, $role->id, context_system::instance()
                );
            }
        }

        // overflow savepoint reached.
        upgrade_mod_savepoint(true, 2022110700, 'overflow');
    }

    if ($oldversion < 2023022400) {
        // Table for information of digest mail.
        $table = new xmldb_table('overflow_mail_info');

        // Adding fields to table overflow_mail_info.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('forumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('forumdiscussionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('numberofposts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('forumid', XMLDB_KEY_FOREIGN, ['forumid'], 'overflow', ['id']);
        $table->add_key('forumdiscussionid', XMLDB_KEY_FOREIGN,
                         ['forumdiscussionid'], 'overflow_discussions', ['id']);

        // Conditionally launch create table for overflow_mail_info.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // overflow savepoint reached.
        upgrade_mod_savepoint(true, 2023022400, 'overflow');
    }

    if ($oldversion < 2023040400) {
        // Define table overflow to be edited.
        $table = new xmldb_table('overflow');

        // Define field allowmultiplemarks to be added to overflow.
        $field = new xmldb_field('allowmultiplemarks', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'needsreview');

        // Conditionally launch add field allowmultiplemarks.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // overflow savepoint reached.
        upgrade_mod_savepoint(true, 2023040400, 'overflow');
    }

    if ($oldversion < 2024072600) {
        require_once($CFG->dirroot . '/local/overflow/db/upgradelib.php');

        local_overflow_move_draftfiles_to_permanent_filearea();

        upgrade_mod_savepoint(true, 2024072600, 'overflow');
    }

    return true;
}
