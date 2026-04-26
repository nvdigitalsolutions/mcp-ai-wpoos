# Project Management Toolkit

> Projects, tasks, events and dependencies — a complete project-management back end with
> AI task-planning, calendar export, and a Research & Add admin page.

| | |
|---|---|
| **Activation setting** | `enable_project_management` |
| **Admin location** | NV oOS → Settings → Pro Features → Project Management |
| **Custom Post Types** | 3 (Projects, Tasks, Events) |
| **Available since** | Pro v1.x |

---

## What it provides

The toolkit registers three CPTs and ships a set of CRUD tools for each, plus AI-driven
planning helpers.

### Custom post types

| CPT slug | Class file | Purpose |
|---|---|---|
| `mcp_ai_project` | `class-wp-mcp-ai-project-cpt.php` | A project / engagement |
| `mcp_ai_task` | `class-wp-mcp-ai-task-cpt.php` | A task within a project, with dependencies |
| `mcp_ai_event` | `class-wp-mcp-ai-event-cpt.php` | A scheduled event tied to projects/tasks |

### Tools (selected)

- **Projects:** `create_project`, `update_project`, `delete_project`, `list_projects`,
  `research_project`
- **Tasks:** `create_task`, `update_task`, `delete_task`, `list_tasks`,
  `add_task_dependency`, `remove_task_dependency`, `get_task_dependencies`
- **Task plans (AI-assisted):** `create_task_plan`, `update_task_plan`, `get_task_plan`
- **Events:** `create_event`, `update_event`, `delete_event`, `list_events`,
  `export_calendar_ics`

### Admin pages

- Settings, Research & Add, and consolidation pages for each CPT (Project, Task, Event).
- Calendar exports use the `ics` NPM package.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Project Management** under **NV oOS → Settings → Pro Features**.
3. The Projects, Tasks and Events menus appear in the admin sidebar.

---

## Related docs

- [Pro Toolkits index](README.md)
- [Calendar Booking](calendar-booking.md) — for appointment-style scheduling
- [DJ Management](dj-management.md) — event-centric variant for entertainment venues
