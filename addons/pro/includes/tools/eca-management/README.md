# ECA Management Toolkit

> Extra-Curricular Activities (ECA) and Student management tools.

## Purpose

Tools for managing ECAs, students, attendance, timetables, waitlists, notifications, and iSAMS/SOCS synchronisation in educational environments.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Check ECA Conflicts | `check_eca_conflicts` | Detect scheduling conflicts for a student across ECAs |
| Configure ECA Notifications | `configure_eca_notifications` | Set up notification rules for ECA events |
| Create ECA Workflow Rule | `create_eca_workflow_rule` | Define automated workflow rules for ECAs |
| Create ECA | `create_eca` | Register a new extra-curricular activity |
| Delete ECA | `delete_eca` | Remove an ECA and its enrollments |
| Enroll Student in ECA | `enroll_student_eca` | Enroll a student in an ECA with conflict checking |
| Export ECA Data | `export_eca_data` | Export ECA data to CSV/JSON |
| Generate ECA Analytics | `generate_eca_analytics` | Produce analytics reports for ECA participation |
| Generate ECA Participation Report | `generate_eca_participation_report` | Per-student or per-ECA participation summary |
| Get ECA Attendance Report | `get_eca_attendance_report` | Fetch attendance records for an ECA session |
| Get ECA Timetable | `get_eca_timetable` | Retrieve the weekly schedule for ECAs |
| Get ECA | `get_eca` | Fetch a single ECA by ID |
| Import ECAs CSV | `import_ecas_csv` | Bulk-import ECAs from CSV |
| List ECAs | `list_ecas` | List all ECAs with filtering |
| Manage ECA Term | `manage_eca_term` | Configure term dates and schedules |
| Manage ECA Waitlist | `manage_eca_waitlist` | View and manage waitlisted students |
| Mark ECA Attendance | `mark_eca_attendance` | Record attendance for an ECA session |
| Research ECA | `research_eca` | AI-assisted ECA research and recommendations |
| Send ECA Notification | `send_eca_notification` | Dispatch notifications to students/parents |
| Send ECA Parent Report | `send_eca_parent_report` | Email parent summary reports |
| Set ECA Schedule | `set_eca_schedule` | Define recurring schedule for an ECA |
| Sync ECA Enrollments from iSAMS | `sync_eca_enrollments_from_isams` | Pull enrollments from iSAMS MIS |
| Sync ECAs from iSAMS | `sync_ecas_from_isams` | Pull ECA definitions from iSAMS |
| Sync ECAs from SOCS | `sync_ecas_from_socs` | Pull ECA definitions from SOCS |
| Sync ECAs to iSAMS | `sync_ecas_to_isams` | Push ECA data to iSAMS |
| Update ECA | `update_eca` | Modify an existing ECA |
| Withdraw Student from ECA | `withdraw_student_eca` | Remove a student from an ECA |

### Student Management

| Tool | Slug | Description |
|------|------|-------------|
| Bulk Enroll Students | `bulk_enroll_students` | Mass-enroll students into ECAs |
| Create Student | `create_student` | Register a new student |
| Delete Student | `delete_student` | Remove a student record |
| Get Student Participation Summary | `get_student_participation_summary` | Overview of a student's ECA involvement |
| Get Student | `get_student` | Fetch a student by ID |
| List Students | `list_students` | List all students with filtering |
| Sync Students from iSAMS | `sync_students_from_isams` | Pull student records from iSAMS |
| Update Student | `update_student` | Modify student details |
| iSAMS Query | `isams_query` | Direct query against iSAMS API |

## Dependencies

- WordPress 6.0+
- iSAMS API credentials (for sync tools)
- SOCS API credentials (for SOCS sync)

## Registration

Loaded by `eca-management-init.php` in `addons/pro/includes/`. Gated on `enable_eca_management` setting.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [ECA CPT: `addons/pro/includes/class-wp-mcp-ai-eca-cpt.php`](../../class-wp-mcp-ai-eca-cpt.php)
