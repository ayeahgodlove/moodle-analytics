<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Send opt-out capable Moodle notifications to learners who have become inactive.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsdashboard\task;

defined('MOODLE_INTERNAL') || die();

use context_course;
use core\message\message;
use core_user;
use html_writer;
use moodle_url;

/**
 * Daily task for configured course re-engagement reminders.
 */
class send_learner_reminders extends \core\task\scheduled_task {

    /**
     * Get the scheduled task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_learner_reminders', 'local_analyticsdashboard');
    }

    /**
     * Send a reminder after the configured inactivity period.
     */
    public function execute(): void {
        global $DB;

        if (empty(get_config('local_analyticsdashboard', 'remindersenabled'))) {
            return;
        }

        $days = (int)get_config('local_analyticsdashboard', 'inactivitydays');
        if (!in_array($days, [7, 14, 21, 30, 45, 60], true)) {
            mtrace('Invalid learner inactivity reminder period; using 14 days.');
            $days = 14;
        }
        $now = time();
        $cutoff = $now - ($days * DAYSECS);
        $cooldown = max(30, $days) * DAYSECS;
        $courses = $DB->get_records_select(
            'course',
            'id <> :siteid AND visible = 1',
            ['siteid' => SITEID],
            'id ASC',
            'id,fullname'
        );

        foreach ($courses as $course) {
            $context = context_course::instance((int)$course->id);
            list($enrolledsql, $enrolledparams) = get_enrolled_sql(
                $context,
                'mod/assign:submit',
                0,
                true
            );
            $params = array_merge($enrolledparams, [
                'enrolcourseid' => (int)$course->id,
                'accesscourseid' => (int)$course->id,
                'activeenrolment' => ENROL_USER_ACTIVE,
                'enabledinstance' => ENROL_INSTANCE_ENABLED,
                'cutoff' => $cutoff,
            ]);
            $users = $DB->get_recordset_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname
                   FROM ({$enrolledsql}) enrolled
                   JOIN {user} u ON u.id = enrolled.id
                   JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = :activeenrolment
                   JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :enrolcourseid
                                 AND e.status = :enabledinstance
              LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = :accesscourseid
                  WHERE (CASE
                            WHEN ula.timeaccess IS NULL OR ula.timeaccess = 0 THEN ue.timecreated
                            ELSE ula.timeaccess
                         END) <= :cutoff
                    AND u.deleted = 0
                    AND u.suspended = 0",
                $params
            );

            foreach ($users as $user) {
                $preference = 'local_analyticsdashboard_reminderlog';
                $reminderlog = json_decode(
                    (string)get_user_preferences($preference, '{}', (int)$user->id),
                    true
                );
                if (!is_array($reminderlog)) {
                    mtrace("Invalid reminder cooldown preference for user {$user->id}; resetting it.");
                    $reminderlog = [];
                }
                $lastsent = (int)($reminderlog[(string)$course->id] ?? 0);
                if ($lastsent > 0 && ($now - $lastsent) < $cooldown) {
                    continue;
                }

                $sent = $this->send_reminder($user, $course);
                if (!$sent) {
                    mtrace("Analytics dashboard reminder could not be sent to user {$user->id} for course {$course->id}.");
                    continue;
                }

                $reminderlog[(string)$course->id] = $now;
                $encodedlog = json_encode($reminderlog);
                if ($encodedlog === false) {
                    mtrace("Could not encode reminder cooldown preference for user {$user->id}.");
                    continue;
                }
                set_user_preference($preference, $encodedlog, (int)$user->id);
            }
            $users->close();
        }
    }

    /**
     * Send the Moodle notification for one learner and course.
     *
     * @param \stdClass $user Recipient.
     * @param \stdClass $course Course.
     * @return bool Whether Moodle accepted the notification.
     */
    private function send_reminder(\stdClass $user, \stdClass $course): bool {
        $coursename = format_string($course->fullname);
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $message = new message();
        $message->component = 'local_analyticsdashboard';
        $message->name = 'inactivity_reminder';
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('reminder_subject', 'local_analyticsdashboard', $coursename);
        $message->fullmessage = get_string('reminder_body', 'local_analyticsdashboard', $coursename) .
            "\n\n" . $courseurl->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = html_writer::tag(
            'p',
            get_string('reminder_body', 'local_analyticsdashboard', $coursename)
        ) . html_writer::link($courseurl, get_string('continue_learning', 'local_analyticsdashboard'));
        $message->smallmessage = get_string('reminder_subject', 'local_analyticsdashboard', $coursename);
        $message->notification = 1;
        $message->contexturl = $courseurl->out(false);
        $message->contexturlname = get_string('continue_learning', 'local_analyticsdashboard');

        return (bool)message_send($message);
    }
}
