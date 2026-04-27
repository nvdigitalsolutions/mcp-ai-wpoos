# ECA Management System

> Extra-Curricular Activities (ECAs) and Students — a school/club management system with
> enrolment, attendance, scheduling, conflicts, notifications, and analytics.

| | |
|---|---|
| **Activation setting** | `enable_eca_management` |
| **Admin location** | NV oOS → Settings → Pro Features → ECA Management |
| **Custom Post Type** | 1 (`mcp_ai_eca`) plus students |
| **REST controller** | `WP_MCP_AI_ECA_REST_Controller` |

---

## What it provides

| Component | Class / file |
|---|---|
| ECA CPT | `WP_MCP_AI_ECA_CPT` (`class-wp-mcp-ai-eca-cpt.php`) |
| REST controller | `WP_MCP_AI_ECA_REST_Controller` (`includes/rest/class-wp-mcp-ai-eca-rest-controller.php`) |
| Research & Add admin page | `WP_MCP_AI_ECA_Research_Page` |
| Settings admin page | `WP_MCP_AI_ECA_Settings_Page` |
| Dashboard admin page | `WP_MCP_AI_ECA_Dashboard_Page` |

### Tools (selected)

- **CRUD:** `create_eca`, `delete_eca`, `get_eca`, `create_student`, `delete_student`
- **Enrolment:** `enroll_student_eca`, `bulk_enroll_students`
- **Scheduling:** `get_eca_timetable`, `check_eca_conflicts`
- **Reporting:** `get_eca_attendance_report`, `generate_eca_participation_report`,
  `generate_eca_analytics`, `export_eca_data`
- **Notifications & rules:** `configure_eca_notifications`, `create_eca_workflow_rule`

### Use cases

- Schools and universities tracking clubs, sports teams, and electives.
- Membership organizations running parallel activities for members.
- Camps and after-school programs.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **ECA Management** under **NV oOS → Settings → Pro Features**.
3. The ECA, Student, and Dashboard menus appear in the admin sidebar.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/docs/ECA_REST_API.md`](../ECA_REST_API.md) — REST endpoint reference
- [`addons/pro/docs/ECA_MANAGEMENT_COMPLETION_SUMMARY.md`](../ECA_MANAGEMENT_COMPLETION_SUMMARY.md)
