<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Administration settings for the analytics dashboard.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_analyticsdashboard',
        get_string('pluginname', 'local_analyticsdashboard'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_analyticsdashboard/remindersenabled',
        get_string('remindersenabled', 'local_analyticsdashboard'),
        get_string('remindersenabled_desc', 'local_analyticsdashboard'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'local_analyticsdashboard/inactivitydays',
        get_string('inactivitydays', 'local_analyticsdashboard'),
        get_string('inactivitydays_desc', 'local_analyticsdashboard'),
        14,
        [7 => 7, 14 => 14, 21 => 21, 30 => 30, 45 => 45, 60 => 60]
    ));
}
