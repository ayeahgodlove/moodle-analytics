<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * English language strings for the analytics dashboard.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Learning analytics dashboard';
$string['dashboard'] = 'Learning dashboard';
$string['dashboard_kicker'] = 'Learning dashboard';
$string['welcome'] = 'Welcome, {$a}';
$string['dashboard_intro'] = 'Review course activity, learner progress and upcoming assignments.';
$string['admin_dashboard'] = 'Site overview';
$string['teacher_dashboard'] = 'Teaching dashboard';
$string['dashboard_tabs'] = 'Dashboard views';
$string['transaction_history'] = 'Transaction history';
$string['no_teaching_courses'] = 'You do not currently have any courses to manage.';
$string['payment_history_explanation'] = 'Completed payments recorded in Moodle core across all payment gateways. Pending or failed gateway attempts are not included.';
$string['payment_history_search'] = 'Search users, courses, gateways, or payment type';
$string['payment_history_gateway'] = 'Payment gateway';
$string['payment_history_date_from'] = 'From date';
$string['payment_history_date_to'] = 'To date';
$string['payment_history_per_page'] = 'Rows per page';
$string['payment_history_count'] = '{$a} payments found';
$string['invalid_payment_date'] = 'Enter a valid payment date.';
$string['invalid_payment_date_range'] = 'The start date must be on or before the end date.';
$string['no_core_payment_history'] = 'No payments match these filters.';
$string['payment_item'] = 'Course or payment type';
$string['payment_amount'] = 'Amount';
$string['payment_gateway'] = 'Gateway';
$string['payment_id'] = 'Payment ID';
$string['active_users'] = 'Active users';
$string['visible_courses'] = 'Visible courses';
$string['active_enrolments'] = 'Active course enrolments';
$string['at_risk_enrolments'] = 'Enrolments with no course access in 14 days';
$string['teaching_courses'] = 'Courses you teach';
$string['learners'] = 'Learners';
$string['pending_grading'] = 'Submissions awaiting grading';
$string['learners_at_risk'] = 'Learners with no access in 14 days';
$string['course_revenue'] = 'Paid enrolment revenue by currency';
$string['course_code'] = 'Course code';
$string['course_visibility'] = 'Course visibility';
$string['visible_label'] = 'Visible';
$string['hidden_label'] = 'Hidden';
$string['all_options'] = 'All';
$string['completed'] = 'Completed';
$string['in_progress'] = 'In progress';
$string['not_started'] = 'Not started';
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['table_search'] = 'Search this table';
$string['table_search_placeholder'] = 'Search names, codes, or values';
$string['advanced_filters'] = 'Advanced filters';
$string['clear_filters'] = 'Clear filters';
$string['table_results_count'] = 'Showing {$a->visible} of {$a->total} rows';
$string['table_no_results'] = 'No rows match these filters.';
$string['due_within_week'] = 'Due within 7 days';
$string['due_later'] = 'Due in 8–30 days';
$string['revenue_unavailable'] = 'Not available for this role';
$string['completion_rate'] = 'Course completion';
$string['completion_not_enabled'] = 'Not enabled';
$string['open_course'] = 'Open';
$string['manage_course'] = 'Manage';
$string['grade_assignments'] = 'Grade';
$string['progress'] = 'Activity completion';
$string['grades'] = 'Grades';
$string['view_grades'] = 'View grades';
$string['continue_learning'] = 'Continue learning';
$string['upcoming_assignments'] = 'Upcoming assignments';
$string['no_upcoming_assignments'] = 'There are no assignments due in the next 30 days.';
$string['no_courses_yet'] = 'You are not enrolled in any courses yet.';
$string['paid_enrolment_revenue'] = 'Paid course enrolment revenue';
$string['revenue_explanation'] = 'Confirmed paid-enrolment transactions from Moodle core, grouped by currency. Totals include payments from any gateway and are not converted between currencies.';
$string['currency'] = 'Currency';
$string['revenue_all_time'] = 'All time';
$string['revenue_this_month'] = 'This month';
$string['transactions'] = 'Transactions';
$string['no_paid_enrolments'] = 'No paid course enrolments have been recorded yet.';
$string['remindersenabled'] = 'Enable learner re-engagement reminders';
$string['remindersenabled_desc'] = 'When enabled, Moodle sends notifications to learners who have not visited an enrolled course for the selected period. While inactivity continues, a course reminder is sent no more often than monthly (or the chosen inactivity period if longer). Learners can manage delivery in their Moodle notification preferences. Moodle cron must be running.';
$string['inactivitydays'] = 'Course inactivity period';
$string['inactivitydays_desc'] = 'Send a reminder after this many days without visiting an enrolled course.';
$string['task_send_learner_reminders'] = 'Send learner re-engagement reminders';
$string['messageprovider:inactivity_reminder'] = 'Course inactivity reminder';
$string['reminder_subject'] = 'Come back to {$a}';
$string['reminder_body'] = 'It has been a while since you visited {$a}. Continue where you left off whenever you are ready.';
$string['privacy:metadata:preference:reminderlog'] = 'Stores course reminder timestamps to prevent sending repeated re-engagement notifications too frequently.';
