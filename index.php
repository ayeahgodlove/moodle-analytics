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

/**
 * Role-aware learning analytics dashboard.
 *
 * @package    local_analyticsdashboard
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/completionlib.php');

use core\context\system as context_system;
use local_analyticsdashboard\local\dashboard;

require_login();

$context = context_system::instance();
require_capability('local/analyticsdashboard:view', $context);

$PAGE->set_url(new moodle_url('/local/analyticsdashboard/'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('analytics-dashboard-page');
$PAGE->set_cacheable(false);
$PAGE->set_title(get_string('dashboard', 'local_analyticsdashboard'));
$PAGE->set_heading('');
$PAGE->requires->css(new moodle_url('/local/analyticsdashboard/styles.css'));
$PAGE->requires->js(new moodle_url('/local/analyticsdashboard/filters.js'));

$systemadmin = has_capability('moodle/site:config', $context);
$canviewpayments = has_capability('moodle/payment:viewpayments', $context);
$teachingcourses = dashboard::get_teaching_courses((int)$USER->id);
$enrolledcourses = dashboard::get_enrolled_courses((int)$USER->id);
$paymentsearch = trim(optional_param('paymentsearch', '', PARAM_TEXT));
$paymentgateway = optional_param('paymentgateway', '', PARAM_ALPHANUMEXT);
$paymentdatefrom = trim(optional_param('paymentdatefrom', '', PARAM_RAW_TRIMMED));
$paymentdateto = trim(optional_param('paymentdateto', '', PARAM_RAW_TRIMMED));
$paymentpage = max(0, optional_param('paymentpage', 0, PARAM_INT));
$paymentperpage = optional_param('paymentperpage', 25, PARAM_INT);
$paymentperpageoptions = [10, 25, 50, 100];
if (!in_array($paymentperpage, $paymentperpageoptions, true)) {
    $paymentperpage = 25;
}
$parsepaymentdate = static function(string $value): ?int {
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) ||
            $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $date->getTimestamp();
};
$paymentfromtimestamp = $parsepaymentdate($paymentdatefrom);
$paymenttotimestamp = $parsepaymentdate($paymentdateto);
$paymentdateerrors = [];
if ($paymentdatefrom !== '' && $paymentfromtimestamp === null) {
    $paymentdateerrors[] = get_string('invalid_payment_date', 'local_analyticsdashboard');
    $paymentdatefrom = '';
}
if ($paymentdateto !== '' && $paymenttotimestamp === null) {
    $paymentdateerrors[] = get_string('invalid_payment_date', 'local_analyticsdashboard');
    $paymentdateto = '';
}
if ($paymentfromtimestamp !== null && $paymenttotimestamp !== null &&
        $paymentfromtimestamp > $paymenttotimestamp) {
    $paymentdateerrors[] = get_string('invalid_payment_date_range', 'local_analyticsdashboard');
    $paymentfromtimestamp = null;
    $paymenttotimestamp = null;
    $paymentdatefrom = '';
    $paymentdateto = '';
}
if ($canviewpayments) {
    $availablegateways = $DB->get_fieldset_sql('SELECT DISTINCT gateway FROM {payments}');
    if (!in_array($paymentgateway, $availablegateways, true)) {
        $paymentgateway = '';
    }
}

$teachingdashboard = '';
if ($teachingcourses) {
    $teachingdashboard .= html_writer::start_div(
        'analytics-dashboard__section analytics-dashboard__section--teaching'
    );
    $teachingdashboard .= $OUTPUT->heading(
        get_string('teacher_dashboard', 'local_analyticsdashboard'),
        2,
        'analytics-dashboard__section-title'
    );
    $teachingdashboard .= html_writer::start_div('row analytics-dashboard__stat-grid');
    $teachingdashboard .= dashboard::stat_card(
        get_string('teaching_courses', 'local_analyticsdashboard'),
        count($teachingcourses)
    );
    $teachingdashboard .= dashboard::stat_card(
        get_string('learners', 'local_analyticsdashboard'),
        array_sum(array_column($teachingcourses, 'learners'))
    );
    $teachingdashboard .= dashboard::stat_card(
        get_string('pending_grading', 'local_analyticsdashboard'),
        array_sum(array_column($teachingcourses, 'pendinggrading'))
    );
    $teachingdashboard .= dashboard::stat_card(
        get_string('learners_at_risk', 'local_analyticsdashboard'),
        array_sum(array_column($teachingcourses, 'at-risk'))
    );
    $teachingdashboard .= html_writer::end_div();
    $teachingdashboard .= dashboard::teacher_course_table($teachingcourses);
    $teachingdashboard .= html_writer::end_div();
} else if ($systemadmin) {
    $teachingdashboard = $OUTPUT->notification(
        get_string('no_teaching_courses', 'local_analyticsdashboard'),
        \core\output\notification::NOTIFY_INFO
    );
}

echo $OUTPUT->header();
echo html_writer::start_div('analytics-dashboard');
echo html_writer::start_div('container analytics-dashboard__container');
$herocontent = html_writer::div(
    html_writer::tag('p', get_string('dashboard_kicker', 'local_analyticsdashboard'),
        ['class' => 'analytics-dashboard__eyebrow']) .
    html_writer::tag('h1', get_string('welcome', 'local_analyticsdashboard', fullname($USER)), [
        'id' => 'analytics-dashboard-hero-title',
    ]) .
    html_writer::tag('p', get_string('dashboard_intro', 'local_analyticsdashboard')),
    'analytics-dashboard__hero-inner'
);
$herowave = html_writer::tag(
    'svg',
    html_writer::tag('path', '', [
        'fill' => 'rgba(255,255,255,0.14)',
        'd' => 'M0 36c160 36 320-28 520-8s360 40 560 8 220-36 360-8v44H0z',
    ]) . html_writer::tag('path', '', [
        'fill' => 'rgba(255,255,255,0.22)',
        'd' => 'M0 52c220 22 400-16 660 4s400 18 780-12v36H0z',
    ]),
    [
        'class' => 'analytics-dashboard__hero-wave',
        'viewBox' => '0 0 1440 80',
        'preserveAspectRatio' => 'none',
        'aria-hidden' => 'true',
        'focusable' => 'false',
    ]
);
echo html_writer::div(
    $herocontent . $herowave,
    'analytics-dashboard__hero',
    ['aria-labelledby' => 'analytics-dashboard-hero-title']
);

if ($systemadmin) {
    $analytics = dashboard::get_site_analytics();
    echo html_writer::start_div('analytics-dashboard__section analytics-dashboard__section--overview');
    echo $OUTPUT->heading(
        get_string('admin_dashboard', 'local_analyticsdashboard'),
        2,
        'analytics-dashboard__section-title'
    );
    echo html_writer::start_div('row analytics-dashboard__stat-grid');
    echo dashboard::stat_card(get_string('active_users', 'local_analyticsdashboard'), $analytics->users);
    echo dashboard::stat_card(get_string('visible_courses', 'local_analyticsdashboard'), $analytics->courses);
    echo dashboard::stat_card(get_string('active_enrolments', 'local_analyticsdashboard'), $analytics->enrolments);
    echo dashboard::stat_card(get_string('at_risk_enrolments', 'local_analyticsdashboard'), $analytics->atriskenrolments);
    echo html_writer::end_div();

    if ($canviewpayments) {
        echo $OUTPUT->heading(get_string('paid_enrolment_revenue', 'local_analyticsdashboard'), 3);
        echo html_writer::tag('p', get_string('revenue_explanation', 'local_analyticsdashboard'),
            ['class' => 'text-muted analytics-dashboard__description']);
        echo dashboard::revenue_table($analytics->revenuealltime, $analytics->revenuethismonth);
    }
    echo html_writer::end_div();
}

if ($systemadmin && $canviewpayments) {
    $transactionsactive = optional_param('tab', '', PARAM_ALPHA) === 'transactions';
    $teachingtabid = 'analytics-dashboard-tab-teaching';
    $transactiontabid = 'analytics-dashboard-tab-transactions';
    $teachingpanelid = 'analytics-dashboard-panel-teaching';
    $transactionpanelid = 'analytics-dashboard-panel-transactions';

    echo html_writer::start_tag('ul', [
        'class' => 'nav nav-tabs analytics-dashboard__tabs',
        'role' => 'tablist',
        'aria-label' => get_string('dashboard_tabs', 'local_analyticsdashboard'),
    ]);
    echo html_writer::start_tag('li', ['class' => 'nav-item', 'role' => 'presentation']);
    echo html_writer::tag('button', get_string('teacher_dashboard', 'local_analyticsdashboard'), [
        'type' => 'button',
        'class' => 'nav-link' . ($transactionsactive ? '' : ' active'),
        'id' => $teachingtabid,
        'data-bs-toggle' => 'tab',
        'data-bs-target' => '#' . $teachingpanelid,
        'role' => 'tab',
        'aria-controls' => $teachingpanelid,
        'aria-selected' => $transactionsactive ? 'false' : 'true',
    ]);
    echo html_writer::end_tag('li');
    echo html_writer::start_tag('li', ['class' => 'nav-item', 'role' => 'presentation']);
    echo html_writer::tag('button', get_string('transaction_history', 'local_analyticsdashboard'), [
        'type' => 'button',
        'class' => 'nav-link' . ($transactionsactive ? ' active' : ''),
        'id' => $transactiontabid,
        'data-bs-toggle' => 'tab',
        'data-bs-target' => '#' . $transactionpanelid,
        'role' => 'tab',
        'aria-controls' => $transactionpanelid,
        'aria-selected' => $transactionsactive ? 'true' : 'false',
    ]);
    echo html_writer::end_tag('li');
    echo html_writer::end_tag('ul');

    echo html_writer::start_div('tab-content analytics-dashboard__tab-content');
    echo html_writer::div(
        $teachingdashboard,
        'tab-pane fade' . ($transactionsactive ? '' : ' show active'),
        [
            'id' => $teachingpanelid,
            'role' => 'tabpanel',
            'aria-labelledby' => $teachingtabid,
            'tabindex' => '0',
        ]
    );
    $paymentcontent = '';
    foreach ($paymentdateerrors as $paymentdateerror) {
        $paymentcontent .= $OUTPUT->notification(
            $paymentdateerror,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $paymentcontent .= html_writer::div(
        html_writer::tag(
            'p',
            get_string('payment_history_explanation', 'local_analyticsdashboard'),
            ['class' => 'text-muted analytics-dashboard__description']
        ) .
        dashboard::core_payment_history([
            'search' => $paymentsearch,
            'gateway' => $paymentgateway,
            'datefrom' => $paymentdatefrom,
            'dateto' => $paymentdateto,
            'fromtimestamp' => $paymentfromtimestamp,
            'totimestamp' => $paymenttotimestamp,
            'page' => $paymentpage,
            'perpage' => $paymentperpage,
        ]),
        'analytics-dashboard__section analytics-dashboard__section--transactions'
    );
    echo html_writer::div(
        $paymentcontent,
        'tab-pane fade' . ($transactionsactive ? ' show active' : ''),
        [
            'id' => $transactionpanelid,
            'role' => 'tabpanel',
            'aria-labelledby' => $transactiontabid,
            'tabindex' => '0',
        ]
    );
    echo html_writer::end_div();
} else {
    echo $teachingdashboard;
}

if ($enrolledcourses) {
    echo html_writer::start_div('analytics-dashboard__section analytics-dashboard__section--assignments');
    echo $OUTPUT->heading(get_string('upcoming_assignments', 'local_analyticsdashboard'), 2,
        'analytics-dashboard__section-title');
    $upcomingassignments = dashboard::get_upcoming_assignments($enrolledcourses);
    if ($upcomingassignments) {
        echo dashboard::upcoming_assignments_table($upcomingassignments);
    } else {
        echo $OUTPUT->notification(
            get_string('no_upcoming_assignments', 'local_analyticsdashboard'),
            \core\output\notification::NOTIFY_INFO
        );
    }
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
