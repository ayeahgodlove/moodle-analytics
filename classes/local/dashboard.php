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
 * Dashboard data and output helpers.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsdashboard\local;

defined('MOODLE_INTERNAL') || die();

use context_course;
use html_table;
use html_table_row;
use html_writer;
use moodle_url;
use stdClass;

/**
 * Role-aware dashboard queries and Moodle-native output.
 */
class dashboard {

    /**
     * Return courses in which the user can manage activities or grade assignments.
     *
     * @param int $userid User id.
     * @return array
     */
    public static function get_teaching_courses(int $userid): array {
        global $DB;

        $courses = [];
        foreach (['moodle/course:manageactivities', 'mod/assign:grade'] as $capability) {
            $matchingcourses = get_user_capability_course(
                $capability,
                $userid,
                false,
                'fullname,shortname,visible,enablecompletion,idnumber'
            );
            foreach ($matchingcourses ?: [] as $course) {
                $courses[$course->id] = $course;
            }
        }

        $result = [];
        foreach ($courses as $course) {
            $context = context_course::instance((int)$course->id);
            $learners = count_enrolled_users($context, 'mod/assign:submit', 0, true);
            list($enrolledsql, $enrolledparams) = get_enrolled_sql($context, 'mod/assign:submit', 0, true);
            $pendingparams = array_merge($enrolledparams, [
                'courseid' => (int)$course->id,
                'submitted' => 'submitted',
            ]);

            $pendinggrading = (int)$DB->get_field_sql(
                "SELECT COUNT(DISTINCT s.id)
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment
                   JOIN {course_modules} cm ON cm.instance = a.id
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                   JOIN ({$enrolledsql}) enrolled ON enrolled.id = s.userid
              LEFT JOIN {assign_grades} ag
                     ON ag.assignment = s.assignment
                    AND ag.userid = s.userid
                    AND ag.attemptnumber = s.attemptnumber
                  WHERE a.course = :courseid
                    AND s.latest = 1
                    AND s.status = :submitted
                    AND (ag.id IS NULL OR ag.grade IS NULL)",
                $pendingparams
            );

            $cutoff = time() - (14 * DAYSECS);
            $canviewrevenue = has_capability('local/analyticsdashboard:viewcourserevenue', $context, $userid);
            $monthstart = make_timestamp((int)date('Y'), (int)date('n'), 1);
            $result[$course->id] = [
                'course' => $course,
                'learners' => $learners,
                'pendinggrading' => $pendinggrading,
                'at-risk' => self::count_inactive_learners($course, $enrolledsql, $enrolledparams, $cutoff),
                'completion' => self::get_course_completion_rate($course, $enrolledsql, $enrolledparams),
                'canupdate' => has_capability('moodle/course:update', $context, $userid),
                'cangrade' => has_capability('mod/assign:grade', $context, $userid),
                'revenue' => $canviewrevenue
                    ? self::get_paid_enrolment_revenue(null, (int)$course->id)
                    : null,
                'revenuethismonth' => $canviewrevenue
                    ? self::get_paid_enrolment_revenue($monthstart, (int)$course->id)
                    : null,
            ];
        }

        return array_values($result);
    }

    /**
     * Return the signed-in user's enrolled courses.
     *
     * @param int $userid User id.
     * @return array
     */
    public static function get_enrolled_courses(int $userid): array {
        $courses = enrol_get_my_courses(
            'id,fullname,shortname,idnumber,visible',
            'sortorder ASC'
        );
        return array_filter($courses, static function($course) {
            return (int)$course->id !== SITEID;
        });
    }

    /**
     * Collect administrator metrics and gateway-independent course payment totals.
     *
     * @return stdClass
     */
    public static function get_site_analytics(): stdClass {
        global $DB;

        $now = time();
        $monthstart = make_timestamp((int)date('Y', $now), (int)date('n', $now), 1);
        $cutoff = $now - (14 * DAYSECS);
        $params = [
            'activeenrolment' => ENROL_USER_ACTIVE,
            'enabledinstance' => ENROL_INSTANCE_ENABLED,
            'siteid' => SITEID,
        ];
        $basejoins = "FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                        JOIN {course} c ON c.id = e.courseid
                        JOIN {user} u ON u.id = ue.userid";
        $activewhere = "ue.status = :activeenrolment AND e.status = :enabledinstance
                        AND c.id <> :siteid AND u.deleted = 0 AND u.suspended = 0";

        $analytics = new stdClass();
        $analytics->users = $DB->count_records_select('user', 'deleted = 0 AND suspended = 0 AND confirmed = 1');
        $analytics->courses = $DB->count_records_select('course', 'id <> :siteid AND visible = 1', ['siteid' => SITEID]);
        $analytics->enrolments = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.id) {$basejoins} WHERE {$activewhere}",
            $params
        );
        $analytics->atriskenrolments = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.id) {$basejoins}
               LEFT JOIN {user_lastaccess} ula ON ula.userid = ue.userid AND ula.courseid = e.courseid
              WHERE {$activewhere}
                AND (ula.timeaccess IS NULL OR ula.timeaccess < :cutoff)",
            array_merge($params, ['cutoff' => $cutoff])
        );
        $analytics->revenuealltime = self::get_paid_enrolment_revenue();
        $analytics->revenuethismonth = self::get_paid_enrolment_revenue($monthstart);

        return $analytics;
    }

    /**
     * Return active learner counts for a course whose last course access is older than the cutoff.
     *
     * @param stdClass $course Course.
     * @param string $enrolledsql SQL for active enrolled learners.
     * @param array $enrolledparams Enrolment query parameters.
     * @param int $cutoff Timestamp.
     * @return int
     */
    private static function count_inactive_learners(
        stdClass $course,
        string $enrolledsql,
        array $enrolledparams,
        int $cutoff
    ): int {
        global $DB;

        $params = array_merge($enrolledparams, ['courseid' => (int)$course->id, 'cutoff' => $cutoff]);
        return (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT enrolled.id)
               FROM ({$enrolledsql}) enrolled
          LEFT JOIN {user_lastaccess} ula ON ula.userid = enrolled.id AND ula.courseid = :courseid
              WHERE ula.timeaccess IS NULL OR ula.timeaccess < :cutoff",
            $params
        );
    }

    /**
     * Return the completed learner percentage for a course, or null if completion is disabled.
     *
     * @param stdClass $course Course.
     * @param string $enrolledsql SQL for active enrolled learners.
     * @param array $enrolledparams Enrolment query parameters.
     * @return float|null
     */
    private static function get_course_completion_rate(
        stdClass $course,
        string $enrolledsql,
        array $enrolledparams
    ): ?float {
        global $DB;

        if (empty($course->enablecompletion)) {
            return null;
        }

        $context = context_course::instance((int)$course->id);
        $learners = count_enrolled_users($context, 'mod/assign:submit', 0, true);
        if (!$learners) {
            return 0.0;
        }

        $params = array_merge($enrolledparams, ['courseid' => (int)$course->id]);
        $completed = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.userid)
               FROM {course_completions} cc
               JOIN ({$enrolledsql}) enrolled ON enrolled.id = cc.userid
              WHERE cc.course = :courseid
                AND cc.timecompleted IS NOT NULL
                AND cc.timecompleted > 0",
            $params
        );

        return round(($completed / $learners) * 100, 1);
    }

    /**
     * Return paid course enrolment revenue grouped by currency.
     *
     * Moodle's core payments table contains confirmed payments from every gateway.
     *
     * @param int|null $since Include payments on or after this timestamp.
     * @param int|null $courseid Restrict results to a single course.
     * @return array
     */
    private static function get_paid_enrolment_revenue(?int $since = null, ?int $courseid = null): array {
        global $DB;

        $timewhere = '';
        $coursewhere = '';
        $params = ['component' => 'enrol_fee', 'paymentarea' => 'fee', 'enroltype' => 'fee'];
        if ($since !== null) {
            $timewhere = ' AND p.timecreated >= :since';
            $params['since'] = $since;
        }
        if ($courseid !== null) {
            $coursewhere = ' AND e.courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        return $DB->get_records_sql(
            "SELECT p.currency, SUM(CAST(p.amount AS DECIMAL(20, 2))) AS revenue,
                    COUNT(p.id) AS transactions
               FROM {payments} p
               JOIN {enrol} e ON e.id = p.itemid
              WHERE p.component = :component
                AND p.paymentarea = :paymentarea
                AND e.enrol = :enroltype
                {$timewhere}
                {$coursewhere}
           GROUP BY p.currency
           ORDER BY p.currency",
            $params
        );
    }

    /**
     * Return visible assignments due in the next 30 days for a user's enrolled courses.
     *
     * @param array $courses Enrolled course records.
     * @return array
     */
    public static function get_upcoming_assignments(array $courses): array {
        global $DB;

        if (!$courses) {
            return [];
        }

        $courseids = array_map(static function($course) {
            return (int)$course->id;
        }, $courses);
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');
        $params['now'] = time();
        $params['dueby'] = time() + (30 * DAYSECS);

        return $DB->get_records_sql(
            "SELECT a.id, a.course, a.name, a.duedate, cm.id AS cmid,
                    c.fullname AS coursefullname, c.idnumber AS coursecode
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
               JOIN {course} c ON c.id = a.course
              WHERE a.course {$insql}
                AND cm.visible = 1
                AND a.duedate > :now
                AND a.duedate <= :dueby
           ORDER BY a.duedate ASC",
            $params,
            0,
            20
        );
    }

    /**
     * Render a Moodle-themed metric card.
     *
     * @param string $label Metric label.
     * @param int|float|string $value Metric value.
     * @return string
     */
    public static function stat_card(string $label, $value): string {
        $card = html_writer::div(
            html_writer::div(
                html_writer::tag('div', s($label), ['class' => 'analytics-dashboard__stat-label']) .
                html_writer::tag('div', s((string)$value), ['class' => 'analytics-dashboard__stat-value']),
                'analytics-dashboard__stat-body'
            ),
            'analytics-dashboard__stat-card h-100'
        );

        return html_writer::div($card, 'col-12 col-sm-6 col-xl-3');
    }

    /**
     * Render a table of courses that the current user can teach or grade.
     *
     * @param array $courses Teacher course metrics.
     * @return string
     */
    public static function teacher_course_table(array $courses): string {
        $table = new html_table();
        $table->id = 'analytics-dashboard-teacher-courses';
        $table->attributes['class'] = 'table table-striped table-hover analytics-dashboard__table analytics-dashboard__table--teacher';
        $table->head = [
            get_string('course'),
            get_string('course_code', 'local_analyticsdashboard'),
            get_string('learners', 'local_analyticsdashboard'),
            get_string('completion_rate', 'local_analyticsdashboard'),
            get_string('learners_at_risk', 'local_analyticsdashboard'),
            get_string('pending_grading', 'local_analyticsdashboard'),
            get_string('course_revenue', 'local_analyticsdashboard'),
            get_string('actions'),
        ];

        foreach ($courses as $metrics) {
            $course = $metrics['course'];
            $progress = $metrics['completion'] === null
                ? get_string('completion_not_enabled', 'local_analyticsdashboard')
                : format_float($metrics['completion'], 1) . '%';
            $revenue = $metrics['revenue'] === null
                ? get_string('revenue_unavailable', 'local_analyticsdashboard')
                : self::format_course_revenue($metrics['revenue'], $metrics['revenuethismonth']);
            $actions = html_writer::link(
                new moodle_url('/course/view.php', ['id' => $course->id]),
                get_string('open_course', 'local_analyticsdashboard')
            );
            if ($metrics['canupdate']) {
                $actions .= ' · ' . html_writer::link(
                    new moodle_url('/course/edit.php', ['id' => $course->id]),
                    get_string('manage_course', 'local_analyticsdashboard')
                );
            }
            if ($metrics['cangrade']) {
                $actions .= ' · ' . html_writer::link(
                    new moodle_url('/mod/assign/index.php', ['id' => $course->id]),
                    get_string('grade_assignments', 'local_analyticsdashboard')
                );
            }

            $completionstatus = $metrics['completion'] === null
                ? 'not-enabled'
                : ((float)$metrics['completion'] >= 100
                    ? 'complete'
                    : ((float)$metrics['completion'] > 0 ? 'in-progress' : 'not-started'));
            $row = new html_table_row([
                html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $course->id]),
                    format_string($course->fullname)
                ),
                trim((string)$course->idnumber) !== '' ? s($course->idnumber) : '—',
                (int)$metrics['learners'],
                $progress,
                (int)$metrics['at-risk'],
                (int)$metrics['pendinggrading'],
                $revenue,
                $actions,
            ]);
            $row->attributes['data-course-status'] = empty($course->visible) ? 'hidden' : 'visible';
            $row->attributes['data-completion'] = $completionstatus;
            $row->attributes['data-risk'] = (int)$metrics['at-risk'] > 0 ? 'yes' : 'no';
            $row->attributes['data-pending'] = (int)$metrics['pendinggrading'] > 0 ? 'yes' : 'no';
            $table->data[] = $row;
        }

        return self::table_container(
            self::table_tools('analytics-dashboard-teacher-courses', [
                'course-status' => [
                    'label' => get_string('course_visibility', 'local_analyticsdashboard'),
                    'options' => [
                        'visible' => get_string('visible_label', 'local_analyticsdashboard'),
                        'hidden' => get_string('hidden_label', 'local_analyticsdashboard'),
                    ],
                ],
                'completion' => [
                    'label' => get_string('completion_rate', 'local_analyticsdashboard'),
                    'options' => [
                        'complete' => get_string('completed', 'local_analyticsdashboard'),
                        'in-progress' => get_string('in_progress', 'local_analyticsdashboard'),
                        'not-started' => get_string('not_started', 'local_analyticsdashboard'),
                        'not-enabled' => get_string('completion_not_enabled', 'local_analyticsdashboard'),
                    ],
                ],
                'risk' => [
                    'label' => get_string('learners_at_risk', 'local_analyticsdashboard'),
                    'options' => [
                        'yes' => get_string('yes', 'local_analyticsdashboard'),
                        'no' => get_string('no', 'local_analyticsdashboard'),
                    ],
                ],
                'pending' => [
                    'label' => get_string('pending_grading', 'local_analyticsdashboard'),
                    'options' => [
                        'yes' => get_string('yes', 'local_analyticsdashboard'),
                        'no' => get_string('no', 'local_analyticsdashboard'),
                    ],
                ],
            ]),
            $table,
            'analytics-dashboard__table-wrap--teacher'
        );
    }

    /**
     * Keep controls outside the horizontally scrolling table viewport.
     *
     * @param string $tools Search and filter controls.
     * @param html_table $table Table to render.
     * @param string $tableclass Additional table wrapper class.
     * @return string
     */
    private static function table_container(string $tools, html_table $table, string $tableclass): string {
        return html_writer::div(
            $tools . html_writer::div(
                html_writer::table($table),
                'analytics-dashboard__table-wrap ' . $tableclass
            ),
            'analytics-dashboard__table-container'
        );
    }

    /**
     * Render search and expandable column filters for an analytics table.
     *
     * @param string $tableid Table identifier.
     * @param array $filters Filter definitions keyed by row data attribute.
     * @return string
     */
    private static function table_tools(string $tableid, array $filters = []): string {
        $searchid = $tableid . '-search';
        $search = html_writer::label(
            get_string('table_search', 'local_analyticsdashboard'),
            $searchid,
            false,
            ['class' => 'analytics-dashboard__search-label']
        ) . html_writer::empty_tag('input', [
            'type' => 'search',
            'id' => $searchid,
            'class' => 'form-control analytics-dashboard__search',
            'placeholder' => get_string('table_search_placeholder', 'local_analyticsdashboard'),
            'autocomplete' => 'off',
            'data-table-search' => '',
            'aria-controls' => $tableid,
        ]);

        $advanced = '';
        if ($filters) {
            $filtercontrols = '';
            foreach ($filters as $field => $filter) {
                $selectid = $tableid . '-filter-' . $field;
                $options = ['' => get_string('all_options', 'local_analyticsdashboard')];
                foreach ($filter['options'] as $value => $label) {
                    $options[$value] = $label;
                }
                $filtercontrols .= html_writer::div(
                    html_writer::label($filter['label'], $selectid) .
                    html_writer::select($options, 'filter-' . $field, '', false, [
                        'id' => $selectid,
                        'class' => 'custom-select form-select',
                        'data-filter-field' => $field,
                        'aria-controls' => $tableid,
                    ]),
                    'analytics-dashboard__filter'
                );
            }

            $advanced = html_writer::tag('details',
                html_writer::tag('summary', get_string('advanced_filters', 'local_analyticsdashboard')) .
                html_writer::div($filtercontrols, 'analytics-dashboard__filter-grid'),
                ['class' => 'analytics-dashboard__advanced-filters']
            );
        }

        $reset = html_writer::tag('button', get_string('clear_filters', 'local_analyticsdashboard'), [
            'type' => 'button',
            'class' => 'btn btn-outline-secondary analytics-dashboard__filter-reset',
            'data-table-reset' => '',
            'aria-controls' => $tableid,
        ]);
        $status = html_writer::tag('span', '', [
            'class' => 'analytics-dashboard__filter-status',
            'data-filter-status' => '',
            'data-results-template' => get_string('table_results_count', 'local_analyticsdashboard'),
            'data-no-results' => get_string('table_no_results', 'local_analyticsdashboard'),
            'role' => 'status',
            'aria-live' => 'polite',
        ]);

        return html_writer::div($search . $advanced . $reset . $status, 'analytics-dashboard__table-tools');
    }

    /**
     * Format course revenue by currency without combining unlike currencies.
     *
     * @param array $alltime Revenue totals per currency.
     * @param array $thismonth Revenue totals per currency this month.
     * @return string
     */
    private static function format_course_revenue(array $alltime, array $thismonth): string {
        if (!$alltime) {
            return get_string('no_paid_enrolments', 'local_analyticsdashboard');
        }

        $monthly = [];
        foreach ($thismonth as $row) {
            $monthly[$row->currency] = $row;
        }

        $lines = [];
        foreach ($alltime as $row) {
            $current = $monthly[$row->currency] ?? null;
            $monthlyamount = $current
                ? \core_payment\helper::get_cost_as_string((float)$current->revenue, $row->currency)
                : \core_payment\helper::get_cost_as_string(0, $row->currency);
            $lines[] = html_writer::tag(
                'div',
                get_string('revenue_all_time', 'local_analyticsdashboard') . ': ' .
                    \core_payment\helper::get_cost_as_string((float)$row->revenue, $row->currency) .
                    ' (' . (int)$row->transactions . ') | ' .
                    get_string('revenue_this_month', 'local_analyticsdashboard') . ': ' . $monthlyamount
            );
        }

        return implode('', $lines);
    }

    /**
     * Render site-wide paid enrolment revenue, with separate totals per currency.
     *
     * @param array $alltime Revenue by currency.
     * @param array $thismonth Revenue by currency this month.
     * @return string
     */
    public static function revenue_table(array $alltime, array $thismonth): string {
        $monthly = [];
        foreach ($thismonth as $row) {
            $monthly[$row->currency] = $row;
        }

        $table = new html_table();
        $table->id = 'analytics-dashboard-revenue';
        $table->attributes['class'] = 'table table-striped analytics-dashboard__table analytics-dashboard__table--revenue';
        $table->head = [
            get_string('currency', 'local_analyticsdashboard'),
            get_string('revenue_all_time', 'local_analyticsdashboard'),
            get_string('revenue_this_month', 'local_analyticsdashboard'),
            get_string('transactions', 'local_analyticsdashboard'),
        ];

        foreach ($alltime as $row) {
            $thismonthrow = $monthly[$row->currency] ?? null;
            $currency = (string)$row->currency;
            $table_row = new html_table_row([
                s($currency),
                \core_payment\helper::get_cost_as_string((float)$row->revenue, $row->currency),
                $thismonthrow
                    ? \core_payment\helper::get_cost_as_string((float)$thismonthrow->revenue, $row->currency)
                    : \core_payment\helper::get_cost_as_string(0, $row->currency),
                (int)$row->transactions,
            ]);
            $table_row->attributes['data-currency'] = strtolower($currency);
            $table->data[] = $table_row;
        }

        if (!$alltime) {
            return html_writer::tag('p', get_string('no_paid_enrolments', 'local_analyticsdashboard'));
        }

        return self::table_container(
            self::table_tools('analytics-dashboard-revenue', [
                'currency' => [
                    'label' => get_string('currency', 'local_analyticsdashboard'),
                    'options' => array_combine(
                        array_map(static function($record) {
                            return strtolower((string)$record->currency);
                        }, $alltime),
                        array_map(static function($record) {
                            return (string)$record->currency;
                        }, $alltime)
                    ),
                ],
            ]),
            $table,
            'analytics-dashboard__table-wrap--revenue'
        );
    }

    /**
     * Render completed Moodle core payment records with gateway and date filters.
     *
     * @param array $filters Validated filters and pagination state.
     * @return string
     */
    public static function core_payment_history(array $filters): string {
        global $DB, $OUTPUT;

        $conditions = [];
        $params = [];
        if ($filters['search'] !== '') {
            $conditions[] = '(' .
                $DB->sql_like('u.firstname', ':firstname', false, false) . ' OR ' .
                $DB->sql_like('u.lastname', ':lastname', false, false) . ' OR ' .
                $DB->sql_like('u.email', ':email', false, false) . ' OR ' .
                $DB->sql_like('p.component', ':component', false, false) . ' OR ' .
                $DB->sql_like('p.paymentarea', ':paymentarea', false, false) . ' OR ' .
                $DB->sql_like('p.gateway', ':gatewaysearch', false, false) . ' OR ' .
                $DB->sql_like('c.fullname', ':coursename', false, false) . ')';
            $pattern = '%' . $DB->sql_like_escape($filters['search']) . '%';
            $params += [
                'firstname' => $pattern,
                'lastname' => $pattern,
                'email' => $pattern,
                'component' => $pattern,
                'paymentarea' => $pattern,
                'gatewaysearch' => $pattern,
                'coursename' => $pattern,
            ];
        }
        if ($filters['gateway'] !== '') {
            $conditions[] = 'p.gateway = :selectedgateway';
            $params['selectedgateway'] = $filters['gateway'];
        }
        if ($filters['fromtimestamp'] !== null) {
            $conditions[] = 'p.timecreated >= :datefrom';
            $params['datefrom'] = $filters['fromtimestamp'];
        }
        if ($filters['totimestamp'] !== null) {
            $conditions[] = 'p.timecreated < :dateto';
            $params['dateto'] = $filters['totimestamp'] + DAYSECS;
        }

        $fromsql = 'FROM {payments} p
                      JOIN {user} u ON u.id = p.userid
                 LEFT JOIN {enrol} e ON p.component = :enrolcomponent
                                    AND p.paymentarea = :enrolarea
                                    AND e.id = p.itemid
                                    AND e.enrol = :enroltype
                 LEFT JOIN {course} c ON c.id = e.courseid';
        $params += [
            'enrolcomponent' => 'enrol_fee',
            'enrolarea' => 'fee',
            'enroltype' => 'fee',
        ];
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $total = (int)$DB->count_records_sql(
            "SELECT COUNT(1) {$fromsql}{$where}",
            $params
        );
        $page = (int)$filters['page'];
        $perpage = (int)$filters['perpage'];
        $pages = max(1, (int)ceil($total / $perpage));
        $page = min($page, $pages - 1);
        $records = $DB->get_records_sql(
            "SELECT p.id, p.component, p.paymentarea, p.itemid, p.amount, p.currency,
                    p.gateway, p.timecreated, u.firstname, u.lastname, u.email,
                    c.fullname AS coursename
               {$fromsql}{$where}
           ORDER BY p.timecreated DESC, p.id DESC",
            $params,
            $page * $perpage,
            $perpage
        );

        $gatewaylist = $DB->get_fieldset_sql(
            'SELECT DISTINCT gateway FROM {payments} ORDER BY gateway'
        );
        $gatewayoptions = ['' => get_string('all_options', 'local_analyticsdashboard')];
        foreach ($gatewaylist as $gatewayname) {
            $gatewayoptions[$gatewayname] = $gatewayname;
        }

        $formurl = new moodle_url('/local/analyticsdashboard/', ['tab' => 'transactions']);
        $form = html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $formurl->out(false),
            'class' => 'analytics-dashboard__payment-filters',
        ]);
        $form .= html_writer::empty_tag('input', [
            'type' => 'search',
            'name' => 'paymentsearch',
            'value' => $filters['search'],
            'class' => 'form-control',
            'placeholder' => get_string('payment_history_search', 'local_analyticsdashboard'),
            'aria-label' => get_string('payment_history_search', 'local_analyticsdashboard'),
        ]);
        $form .= html_writer::select(
            $gatewayoptions,
            'paymentgateway',
            $filters['gateway'],
            false,
            [
                'class' => 'form-select',
                'aria-label' => get_string('payment_history_gateway', 'local_analyticsdashboard'),
            ]
        );
        $form .= html_writer::empty_tag('input', [
            'type' => 'date',
            'name' => 'paymentdatefrom',
            'value' => $filters['datefrom'],
            'class' => 'form-control',
            'aria-label' => get_string('payment_history_date_from', 'local_analyticsdashboard'),
        ]);
        $form .= html_writer::empty_tag('input', [
            'type' => 'date',
            'name' => 'paymentdateto',
            'value' => $filters['dateto'],
            'class' => 'form-control',
            'aria-label' => get_string('payment_history_date_to', 'local_analyticsdashboard'),
        ]);
        $form .= html_writer::select(
            array_combine([10, 25, 50, 100], [10, 25, 50, 100]),
            'paymentperpage',
            $perpage,
            false,
            [
                'class' => 'form-select',
                'aria-label' => get_string('payment_history_per_page', 'local_analyticsdashboard'),
            ]
        );
        $form .= html_writer::tag('button', get_string('search'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]);
        $form .= html_writer::link(
            $formurl,
            get_string('clear_filters', 'local_analyticsdashboard'),
            ['class' => 'btn btn-outline-secondary']
        );
        $form .= html_writer::end_tag('form');

        if (!$records) {
            return $form . html_writer::tag(
                'p',
                get_string('no_core_payment_history', 'local_analyticsdashboard'),
                ['class' => 'analytics-dashboard__empty-state']
            );
        }

        $table = new html_table();
        $table->attributes['class'] = 'table table-striped table-hover analytics-dashboard__table analytics-dashboard__table--payments';
        $table->head = [
            get_string('date'),
            get_string('user'),
            get_string('payment_item', 'local_analyticsdashboard'),
            get_string('payment_amount', 'local_analyticsdashboard'),
            get_string('payment_gateway', 'local_analyticsdashboard'),
            get_string('payment_id', 'local_analyticsdashboard'),
        ];

        foreach ($records as $record) {
            $item = $record->coursename
                ? format_string($record->coursename)
                : s($record->component . ' / ' . $record->paymentarea . ' (#' . $record->itemid . ')');
            $user = html_writer::tag('span', s(fullname($record)), ['class' => 'fw-semibold']) .
                html_writer::tag('small', s($record->email), ['class' => 'd-block text-muted']);
            $table->data[] = [
                userdate($record->timecreated),
                $user,
                $item,
                \core_payment\helper::get_cost_as_string((float)$record->amount, $record->currency),
                s($record->gateway),
                (int)$record->id,
            ];
        }

        $pagingurl = new moodle_url('/local/analyticsdashboard/', [
            'tab' => 'transactions',
            'paymentsearch' => $filters['search'],
            'paymentgateway' => $filters['gateway'],
            'paymentdatefrom' => $filters['datefrom'],
            'paymentdateto' => $filters['dateto'],
            'paymentperpage' => $perpage,
        ]);
        return $form .
            html_writer::tag(
                'p',
                get_string('payment_history_count', 'local_analyticsdashboard', $total),
                ['class' => 'analytics-dashboard__payment-count']
            ) .
            html_writer::div(html_writer::table($table), 'analytics-dashboard__table-wrap') .
            html_writer::div(
                $OUTPUT->paging_bar($total, $page, $perpage, $pagingurl, 'paymentpage'),
                'analytics-dashboard__payment-pagination'
            );
    }

    /**
     * Render upcoming assignment deadlines.
     *
     * @param array $assignments Assignment records.
     * @return string
     */
    public static function upcoming_assignments_table(array $assignments): string {
        $table = new html_table();
        $table->id = 'analytics-dashboard-upcoming-assignments';
        $table->attributes['class'] = 'table table-striped analytics-dashboard__table analytics-dashboard__table--assignments';
        $table->head = [
            get_string('assignment', 'assign'),
            get_string('course'),
            get_string('course_code', 'local_analyticsdashboard'),
            get_string('duedate', 'assign'),
        ];

        $courses = [];
        foreach ($assignments as $assignment) {
            $coursecode = trim((string)$assignment->coursecode);
            $coursekey = (string)$assignment->course;
            $courses[$coursekey] = format_string($assignment->coursefullname) .
                ($coursecode !== '' ? ' (' . $coursecode . ')' : '');
            $row = new html_table_row([
                html_writer::link(
                    new moodle_url('/mod/assign/view.php', ['id' => $assignment->cmid]),
                    format_string($assignment->name)
                ),
                html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $assignment->course]),
                    format_string($assignment->coursefullname)
                ),
                $coursecode !== '' ? s($coursecode) : '—',
                userdate($assignment->duedate),
            ]);
            $row->attributes['data-course'] = $coursekey;
            $row->attributes['data-due-soon'] = $assignment->duedate <= time() + (7 * DAYSECS)
                ? 'yes'
                : 'no';
            $table->data[] = $row;
        }

        return self::table_container(
            self::table_tools('analytics-dashboard-upcoming-assignments', [
                'course' => [
                    'label' => get_string('course'),
                    'options' => $courses,
                ],
                'due-soon' => [
                    'label' => get_string('duedate', 'assign'),
                    'options' => [
                        'yes' => get_string('due_within_week', 'local_analyticsdashboard'),
                        'no' => get_string('due_later', 'local_analyticsdashboard'),
                    ],
                ],
            ]),
            $table,
            'analytics-dashboard__table-wrap--assignments'
        );
    }
}
