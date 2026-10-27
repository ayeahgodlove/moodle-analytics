<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled tasks for the analytics dashboard.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_analyticsdashboard\task\send_learner_reminders',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '8',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
