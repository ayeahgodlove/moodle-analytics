<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This file is part of the local_analyticsdashboard plugin.

/**
 * Navigation callbacks for the analytics dashboard.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add a dashboard link to Moodle's normal navigation.
 *
 * @param global_navigation $navigation Navigation tree.
 */
function local_analyticsdashboard_extend_navigation(global_navigation $navigation): void {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $navigation->add(
        get_string('dashboard', 'local_analyticsdashboard'),
        new moodle_url('/local/analyticsdashboard/'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_analyticsdashboard',
        new pix_icon('i/report', '')
    );
}
