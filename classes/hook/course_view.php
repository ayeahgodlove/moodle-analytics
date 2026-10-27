<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Safe redirects for obsolete course edit links.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsdashboard\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles old course-edit links that omit Moodle's session key.
 */
class course_view {

    /**
     * Redirect a legacy edit-mode URL without a sesskey to the ordinary course page.
     *
     * This never enables edit mode; Moodle remains responsible for validating
     * session keys on edit-mode requests that provide one.
     *
     * @param \core_course\hook\before_course_viewed $hook Course view hook.
     */
    public static function redirect_legacy_edit_request(
        \core_course\hook\before_course_viewed $hook
    ): void {
        $edit = optional_param('edit', false, PARAM_BOOL);
        $sesskey = optional_param('sesskey', '', PARAM_RAW);

        if ($edit && $sesskey === '') {
            redirect(new \moodle_url('/course/view.php', ['id' => (int)$hook->course->id]));
        }
    }
}
