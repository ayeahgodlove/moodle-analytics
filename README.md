# Learning analytics dashboard for Moodle

This Moodle local plugin adds a role-aware dashboard at **Learning dashboard** in the Moodle navigation. Its standard Moodle route is `/local/analyticsdashboard/`. The `local` segment identifies the plugin type in Moodle and is required by the conventional plugin route; a language prefix such as `/en` is not part of this plugin's route. The plugin targets Moodle 5.2 and uses Moodle's normal page renderer and active theme typography, with a dashboard-scoped stylesheet and bundled image for the responsive welcome hero.

## Dashboards

- **Teachers and instructors:** courses where the user can manage course activities or grade assignments, including course codes, active learners, pending assignment grading, completion rates where course completion is enabled, learners who have not accessed a course in 14 days, and paid enrolment revenue where permitted. The course table supports keyword search and filters for visibility, completion, at-risk enrolments, and pending grading.
- **Administrators:** active users, visible courses, active course enrolments, and inactive enrolments. Below Site overview, users with Moodle's `moodle/payment:viewpayments` capability can switch between the Teaching dashboard and a searchable, date- and gateway-filterable transaction history of completed payments recorded by Moodle core across gateways.
- **Learners and other authenticated roles:** upcoming assignments in their enrolled courses (with course codes), searchable and filterable by course and due date. The dashboard does not repeat the separate “My learning” course list.
- Revenue, assignment, and payment tables also provide searchable, filterable controls. The dashboard hero follows the site's full-width banner pattern; the duplicate Moodle page heading above it is suppressed only on this plugin page.

## Payment compatibility

Revenue is read from Moodle core's `payments` table for completed `enrol_fee` course-enrolment payments. Moodle's payment API writes these records after successful checkout regardless of the payment gateway, so this dashboard does not depend on private tables or APIs from `paygw_campay`, `paygw_moodlepayments`, or another gateway. Amounts are grouped by currency and never added across currencies. This metric is specifically paid course-enrolment revenue; payments for unrelated Moodle components are not presented as course sales.

The transaction-history tab reads the same Moodle core `payments` table and is limited to users who have Moodle's `moodle/payment:viewpayments` capability. It includes completed core payment records and does not include pending or failed attempts that remain only in a gateway's private transaction log.

## Learner reminders

Site administrators can enable daily course inactivity reminders under **Site administration → Plugins → Local plugins → Learning analytics dashboard**. Reminders are off by default. Choose an inactivity period from 7 to 60 days; Moodle cron must run for notifications to be delivered. While a learner remains inactive, the plugin sends a reminder no more often than monthly (or the chosen inactivity period if longer) per course. A per-user Moodle preference tracks this cooldown. Learners can control message delivery in their Moodle notification preferences. The preference is described in the plugin's privacy metadata.

## Install

Copy this plugin directory to `<moodle-root>/public/local/analyticsdashboard` (or to the `local` directory beneath the Moodle web root), then sign in as a site administrator and visit **Site administration → Notifications** to complete installation. The installation folder must be named `analyticsdashboard`.

For local development on the referenced XAMPP Moodle instance, install a copy in the Moodle web root. A directory junction is not suitable here because Moodle resolves `require('../../config.php')` relative to the real plugin path:

```powershell
Copy-Item -Recurse `
  -Path 'C:\xampp\htdocs\analytics-dashboard' `
  -Destination 'C:\xampp\htdocs\moodle\public\local\analyticsdashboard'
```

Then visit **Site administration → Notifications** in the configured Moodle site and complete the upgrade. Moodle cron should run regularly for scheduled reminders.

## Notes

- The dashboard does not assign roles or capabilities. Teacher course visibility and grading actions are determined by the user's existing Moodle permissions.
- Access to `/local/analyticsdashboard/` requires login and the system-context `local/analyticsdashboard:view` capability. The plugin grants this capability to the standard `user` archetype by default, so authenticated users can view the page unless an administrator changes their role permissions. The plugin does not grant guest access.
- Old course edit-mode links that omit Moodle's required session key are redirected to the normal course page; edit mode is never enabled by that redirect.
- Site-wide revenue is visible only to users with Moodle's payment-viewing capability.
- No payment credentials or external service calls are used.
