# Project Management Toolkit Enhancement Plan — Dashboard, Engine & Phase Parity with CRM

> **Status:** 📋 Proposed  
> **Date:** 2026-06-13  
> **Scope:** `addons/pro/includes/tools/project-management/` + supporting infrastructure  
> **Inspired by:** [`CRM_TOOLKIT_ENHANCEMENT_PLAN.md`](CRM_TOOLKIT_ENHANCEMENT_PLAN.md) (the gold-standard pattern in this codebase)  
> **Companion docs:** [`PRO_TOOLKIT_ENHANCEMENT_REVIEW.md`](PRO_TOOLKIT_ENHANCEMENT_REVIEW.md), [`PRO_TOOLKITS_IMPLEMENTATION_PLAN.md`](PRO_TOOLKITS_IMPLEMENTATION_PLAN.md)

---

## 1. Executive Summary

The Project Management toolkit currently ships **~20 tools** organised around basic project/task/event CRUD, task-dependency linking, and 8 PARA-method classification tools. A PM Notification Manager exists (`WP_MCP_AI_PM_Notification_Manager`) for assignment/status-change emails and a daily due-date digest. By contrast, the CRM toolkit ships **~89 tools across 15 sub-modules** with:

- a shared `WP_MCP_AI_CRM_Engine` (scoring, lifecycle, routing, pipeline, DNC, currency),
- a standards registry (`CRM_Codes`),
- an audit ledger, capability map, consent ledger, pipeline stage registry, classifier,
- an 11-tab Command Center dashboard with KPI grid, pipeline visualisation, lead/activity/sequence/analytics/duplicates tabs,
- per-CPT Research & Add and Settings pages,
- assistant blueprints with one-click install,
- a dedicated top-level admin menu (`NV CRM`) with icon and submenu structure,
- REST controller for Toolkit Shell SPA integration.

The **Project Management toolkit needs the same depth** before it can credibly serve as an AI-first project portfolio management (PPM) surface. Drawing on industry research (monday.com, Celoxis, Epicflow, PMI/Gartner 2026 forecasts) and the architectural patterns already proven in CRM, this document proposes a **4-phase enhancement plan (A → D)** that adds **~45+ new tools** and elevates PM to full architectural parity with CRM.

### Industry Research Summary (May–June 2026)

| Domain | Standard / Reference |
|---|---|
| **Portfolio KPIs** | 5–7 actionable dashboard metrics: schedule variance (SV), cost performance index (CPI), burndown velocity, resource utilisation %, blocked-task count, overdue-task %, portfolio health score. monday.com / Celoxis / Epicflow all converge on this shape. |
| **Pipeline stages** | Project lifecycle: Idea → Planning → Active → At-Risk → On-Hold → Completed → Cancelled → Archived. Mirrors CRM's Salesforce pipeline (Qualification → Discovery → … → Closed-Won/Lost). |
| **Burndown / velocity** | Sprint burndown (remaining effort vs. time) and velocity (story points / tasks completed per week). Industry standard from Scrum/Agile (Atlassian, Linear, Jira). |
| **Resource utilisation** | Workload distribution % per assignee, over/under-allocation flags. Monday.com Workload View, Celoxis Resource Loading Chart. |
| **AI triage** | Autonomous task health monitoring, risk-flag detection (stale tasks, scope creep signals, deadline-at-risk), auto-replanning. Gartner predicts 80% of PM tasks AI-driven by 2030. |
| **PARA methodology** | Tiago Forte's four-category system: Projects (active, deadline-driven), Areas (ongoing responsibilities), Resources (reference material), Archives (inactive). Weekly review cadence, cross-category promotion (Resource → Project). Already partially implemented in NV oOS with feature flag. |
| **Gantt / timeline** | Dependency-aware Gantt charts with critical-path highlighting. Industry standard (Microsoft Project, Smartsheet, Monday Gantt view). |
| **Workflow automation** | Trigger → Condition → Action rules (e.g. "when task status → done, create next sprint task"). Mirrors CRM's `mcp_ai_crm_workflow_rule` pattern. |
| **Assistant blueprints** | Prebuilt AI assistant configs for Scrum Master, PMO Director, Resource Manager, Sprint Coach — matching CRM's B2B SaaS SDR / Agency / Real Estate blueprints. |

---

## 2. Current State vs Target State

### 2.1 What PM Already Has (~20 tools)

| Tool slug | Surface | Notes |
|---|---|---|
| `create_project` / `update_project` | Project CRUD (create+update merged) | CPT: `mcp_ai_project` |
| `list_projects` | List/filter projects | Status, date-range, assignee filters |
| `delete_project` | Delete project | |
| `create_task` / `update_task` | Task CRUD (create+update merged) | CPT: `mcp_ai_task` |
| `list_tasks` | List/filter tasks | Project, status, priority, assignee filters |
| `delete_task` | Delete task | |
| `add_task_dependency` / `remove_task_dependency` / `get_task_dependencies` | Task dependency graph | |
| 8 PARA tools | classify / create-area / list-areas / move-to-archives / promote-resource / update-area / weekly-review / capture-decision | Feature-flagged behind `enable_para_organization` |
| `pm_capture_decision` | MemPalace decision capture | `tier=core`, 5-year TTL |

**Supporting infrastructure:**
- CPTs: `mcp_ai_project`, `mcp_ai_task`, `mcp_ai_event`, `mcp_task_plan`, `mcp_task_template`
- Taxonomies: `mcp_ai_project_category` (8 default terms), `mcp_ai_task_category` (9 default terms), `mcp_ai_para` (4 locked roots)
- PARA framework: Taxonomy + Area CPT + Lifecycle + Admin Columns (feature-flagged)
- PM Notification Manager: Assignment/status-change emails + daily due-date digest cron
- AI Actions metabox + Bulk AI actions on edit screens
- Research & Add pages for Projects, Tasks, Events (under CPT submenus)
- Settings pages for Projects, Tasks, Events (under CPT submenus)
- Toolkit settings page at `NV oOS Pro Dashboard → Project Management Settings` (Overview / Configuration / Tools / Research / Help)

### 2.2 What PM Is Missing (Compared to CRM's Depth)

| Gap | CRM equivalent that exists today |
|---|---|
| **Shared engine class** (status lifecycle, scoring, resource calc, currency formatting) | `WP_MCP_AI_CRM_Engine` |
| **Standards registry** (project statuses, task priorities, estimation methods, risk levels) | `WP_MCP_AI_CRM_Codes` |
| **Pipeline stage registry** (project lifecycle stages with stage-specific metadata) | `WP_MCP_AI_CRM_Pipeline_Stages` |
| **Capability map** (project_manager, scrum_master, product_owner, team_member, stakeholder, resource_manager, pm_viewer) | `WP_MCP_AI_CRM_Capabilities` |
| **Top-level admin menu** ("NV PM" or "NV Projects") with dedicated dashboard | `WP_MCP_AI_CRM_Admin_Menu` + Command Center |
| **Command Center dashboard** with KPI grid, pipeline, kanban, analytics tabs | `WP_MCP_AI_CRM_Command_Center_Page` (11 tabs) |
| **Per-toolkit settings option** with documented filterable defaults | `wp_mcp_ai_crm_toolkit_settings` |
| **REST controller** for Toolkit Shell SPA | `WP_MCP_AI_CRM_REST_Controller` |
| **Assistant blueprints** (`examples/*.json`) | `tools/crm/examples/` |
| **Blueprint installer + one-click admin UI** | `WP_MCP_AI_CRM_Blueprints_Page` |

**Direct functional gaps:**

| Capability | Status |
|---|---|
| Event CRUD tools (create/list/update/delete events via AI) | ❌ CPT exists but no AI tools |
| Burndown / velocity analytics | ❌ Absent |
| Resource utilisation dashboard | ❌ Absent |
| Portfolio health scoring | ❌ Absent |
| Gantt / timeline computation | ❌ Absent |
| Project risk assessment (AI-driven) | ❌ Absent |
| Stale-task / blocker detection | ❌ Absent |
| Workflow automation rules for PM | ❌ Absent (CRM has `mcp_ai_crm_workflow_rule`) |
| Sprint management tools | ❌ Absent |
| Time tracking / estimation vs actual | ❌ Absent |
| Cross-project dependency tracking | ❌ Absent |
| Project → Archive lifecycle automation | ⚠️ PARA lifecycle exists but feature-flagged |
| Weekly review automation (PARA) | ⚠️ `para-weekly-review` tool exists but gated |
| Task template instantiation | ⚠️ `mcp_task_template` CPT exists but no tool |

---

## 3. Proposed Architecture

### 3.1 New Shared Infrastructure

```
addons/pro/includes/tools/project-management/
├── README.md                                          (updated)
├── init.php                                           (updated)
├── class-wp-mcp-ai-pm-engine.php                      [NEW] status lifecycle, resource calc, portfolio scoring
├── class-wp-mcp-ai-pm-codes.php                       [NEW] statuses, priorities, estimation methods, risk levels
├── class-wp-mcp-ai-pm-pipeline-stages.php             [NEW] project lifecycle stages, transitional rules
├── class-wp-mcp-ai-pm-capabilities.php                [NEW] role → WP cap map for PM roles
├── class-wp-mcp-ai-pm-workflow-engine.php             [NEW] trigger→condition→action for PM automations
├── existing-tools/                                    [RELOCATED] current 20 tools re-homed here
│   ├── class-wp-mcp-ai-tool-create-project.php
│   ├── class-wp-mcp-ai-tool-list-projects.php
│   ├── (all existing tools)
│   └── init.php
├── events/                                            [NEW] event CRUD tools
│   ├── class-wp-mcp-ai-tool-create-event.php
│   ├── class-wp-mcp-ai-tool-list-events.php
│   ├── class-wp-mcp-ai-tool-update-event.php
│   └── class-wp-mcp-ai-tool-delete-event.php
├── analytics/                                         [NEW] burndown, velocity, portfolio health
│   ├── class-wp-mcp-ai-tool-get-burndown-chart.php
│   ├── class-wp-mcp-ai-tool-get-team-velocity.php
│   ├── class-wp-mcp-ai-tool-get-portfolio-health.php
│   ├── class-wp-mcp-ai-tool-get-resource-utilization.php
│   ├── class-wp-mcp-ai-tool-get-project-timeline.php
│   └── class-wp-mcp-ai-tool-forecast-completion.php
├── risk/                                              [NEW] risk detection & assessment
│   ├── class-wp-mcp-ai-tool-assess-project-risk.php
│   ├── class-wp-mcp-ai-tool-detect-stale-tasks.php
│   └── class-wp-mcp-ai-tool-identify-blockers.php
├── workflow/                                          [NEW] automation rules
│   ├── class-wp-mcp-ai-tool-create-pm-workflow-rule.php
│   ├── class-wp-mcp-ai-tool-list-pm-workflow-rules.php
│   └── class-wp-mcp-ai-tool-simulate-pm-workflow-rule.php
├── templates/                                         [NEW] task template instantiation
│   ├── class-wp-mcp-ai-tool-instantiate-task-template.php
│   ├── class-wp-mcp-ai-tool-list-task-templates.php
│   └── class-wp-mcp-ai-tool-create-task-template.php
├── sprints/                                           [NEW] sprint management
│   ├── class-wp-mcp-ai-tool-create-sprint.php
│   ├── class-wp-mcp-ai-tool-plan-sprint.php
│   └── class-wp-mcp-ai-tool-close-sprint.php
├── command-center/                                    [NEW] dashboard query tools
│   ├── class-wp-mcp-ai-tool-get-pm-kpis.php
│   ├── class-wp-mcp-ai-tool-get-project-pipeline.php
│   ├── class-wp-mcp-ai-tool-get-upcoming-deadlines.php
│   └── class-wp-mcp-ai-tool-get-my-tasks.php
├── reports/                                           [NEW] reporting & export
│   ├── class-wp-mcp-ai-tool-generate-status-report.php
│   └── class-wp-mcp-ai-tool-export-project-csv.php
└── examples/                                          [NEW] assistant blueprints
    ├── scrum-master.json
    ├── pmo-director.json
    ├── resource-manager.json
    └── sprint-coach.json
```

### 3.2 Settings Option

A new option `wp_mcp_ai_pm_toolkit_settings` (modelled on `wp_mcp_ai_crm_toolkit_settings`) holds PM-wide configuration:

```php
array(
    'default_project_status'       => 'planning',
    'default_task_priority'        => 'medium',
    'estimation_method'            => 'story_points',  // 'story_points' | 'hours' | 't_shirt'
    'burndown_basis'               => 'tasks',         // 'tasks' | 'story_points'
    'sprint_duration_days'         => 14,
    'working_days'                 => array( 1, 2, 3, 4, 5 ), // Mon–Fri
    'portfolio_health_weights'     => array(
        'schedule_variance'        => 0.25,
        'task_completion_rate'     => 0.25,
        'blocker_count'            => 0.20,
        'overdue_task_ratio'       => 0.15,
        'resource_utilization'     => 0.15,
    ),
    'risk_thresholds'              => array(
        'stale_task_days'          => 14,
        'overdue_warning_days'     => 3,
        'overdue_critical_days'    => 7,
        'utilization_high_pct'     => 90,
        'utilization_low_pct'      => 30,
    ),
    'notifications'                => array(
        'due_date_reminder_days'   => array( 7, 3, 1 ),
        'assignment_notify'        => true,
        'status_change_notify'     => true,
        'blocker_alert'            => true,
        'daily_digest'             => true,
    ),
    'integrations'                 => array(
        'calendar_provider'        => '',     // 'google' | 'outlook' | ''
        'google_calendar_oauth'    => '',
        'slack_webhook_url'        => '',
    ),
);
```

Programmatic access via `WP_MCP_AI_PM_Engine::get_toolkit_settings()`; filterable via `wp_mcp_ai_pm_toolkit_settings`.

### 3.3 Sub-toolkit Toggles (in `wp_mcp_ai_settings`)

| Toggle | Sub-module | Default |
|---|---|---|
| `enable_project_management` | core (existing — projects, tasks, events, dependencies) | off |
| `enable_para_organization` | PARA taxonomy + area CPT + lifecycle (existing, feature-flagged) | off |
| `enable_pm_analytics` | burndown, velocity, portfolio health, resource utilisation | inherits `enable_project_management` |
| `enable_pm_workflows` | workflow automation rules | inherits `enable_project_management` |
| `enable_pm_sprints` | sprint planning and management | off |

### 3.4 New Custom Post Types

| CPT slug | Purpose | Status |
|---|---|---|
| `mcp_ai_project` | Existing — project containers | ✅ Existing |
| `mcp_ai_task` | Existing — work items | ✅ Existing |
| `mcp_ai_event` | Existing — scheduled meetings/milestones | ✅ Existing |
| `mcp_task_plan` | Existing — markdown execution plans | ✅ Existing |
| `mcp_task_template` | Existing — reusable templates | ✅ Existing |
| `mcp_ai_sprint` | *Proposed* — sprint containers with start/end dates, goal, velocity target | 📋 Proposed |
| `mcp_ai_pm_workflow_rule` | *Proposed* — PM automation rules (mirrors `mcp_ai_crm_workflow_rule`) | 📋 Proposed |
| `mcp_ai_risk_register` | *Proposed* — risk log entries linked to projects | 📋 Proposed |

### 3.5 Reuse Map

| Existing surface | How PM Phase A–D uses it |
|---|---|
| `WP_MCP_AI_Pro_Schedule_Manager` | Drives due-date digest cron, sprint-ceremony reminders, weekly-review scheduling |
| `WP_MCP_AI_Pro_Workflow_Builder_Page` | Visual editor for `mcp_ai_pm_workflow_rule` records (CRM palette pattern) |
| `WP_MCP_AI_Pro_Capture_Tool_Base` | Base class for `pm_capture_*` tools |
| `WP_MCP_AI_PM_Notification_Manager` | Existing — extended with workflow-trigger dispatch |
| `WP_MCP_AI_PARA_Taxonomy` | PARA classification for all PM entities (already wired, feature-flagged) |
| `WP_MCP_AI_PARA_Lifecycle` | Project → Archive transition rules (already implemented) |
| `WP_MCP_AI_Validator_Service` | Date, email, URL validation in CRUD/event tools |
| MemPalace | Long-term project context memory (already wired via `pm_capture_decision`) |
| `chart.js` (NPM, already available) | Burndown charts, velocity sparklines, resource loading bars |
| `exceljs` (NPM, already available) | Project export reports |
| `pdfkit` (NPM, already available) | Status report PDF generation |
| `ical` / `ics` (NPM, already available) | Calendar feed export for project deadlines |

---

## 4. Phased Roadmap (A → D)

The roadmap follows the same A→D shape as CRM (condensed to 4 phases since PARA foundations already exist).

### Phase A — Foundations & Relocation *(no new tool slugs)*

**Goal:** Stand up the shared PM infrastructure without changing any existing behavior.

- Add `WP_MCP_AI_PM_Engine`, `_Codes`, `_Pipeline_Stages`, `_Capabilities`, `_Workflow_Engine`.
- Add `wp_mcp_ai_pm_toolkit_settings` option + `wp_mcp_ai_pm_toolkit_settings` filter.
- Add `WP_MCP_AI_PM_Engine::get_toolkit_settings()` (mirrors CRM engine API).
- Introduce `is_available()` / `get_unavailable_reason()` on every existing PM tool (backfill).
- Relocate existing 20 tools into `existing-tools/` subdirectory (no slug change).
- Backwards-compatible forwarder in `init.php`.
- Add PM-specific PHPCS sniff fixtures + tests directory `tests/pro/tools/project-management/`.
- **Un-feature-flag PARA:** Remove the review-window gate so PARA taxonomy + Area CPT + Lifecycle + Admin Columns load when `enable_para_organization` is on.

**Exit criterion:** Existing PM tools continue to work identically; `WP_MCP_AI_PM_Engine::get_toolkit_settings()` returns sane defaults; new directory structure is in place; PARA loads without the separate-review-window gate.

---

### Phase B — Admin Menu, Command Center Dashboard & Event CRUD *(≈ 14 new tools + admin UI)*

**Goal:** Give PM a dedicated top-level admin section with a command center dashboard matching CRM's depth, plus event CRUD parity.

#### B1 — Top-Level Admin Menu & Command Center Dashboard

Create `WP_MCP_AI_PM_Admin_Menu` + `WP_MCP_AI_PM_Command_Center_Page`, mirroring the CRM pattern:

**`WP_MCP_AI_PM_Admin_Menu`:**
- Parent slug: `nvoos-pm-dashboard`
- Menu icon: `dashicons-portfolio`
- Position: 31 (below NV CRM at 30)
- Submenu: Command Center, Projects (CPT link), Tasks (CPT link), Events (CPT link), Blueprints, Settings

**Command Center tabs (mirroring CRM's 11-tab shape):**

```
┌─────────────────────────────────────────────────────────────┐
│  NV PM  ▸  Command Center                           [PRO]   │
│  ┌─────────┬────────┬──────────┬──────────┬──────────────┐  │
│  │Overview │Projects│  Tasks   │  Events  │  Analytics   │  │
│  │         │        │          │          │              │  │
│  ├─────────┼────────┼──────────┼──────────┼──────────────┤  │
│  │  PARA   │  Risk  │Workflows │Templates │Configuration │  │
│  └─────────┴────────┴──────────┴──────────┴──────────────┘  │
│                                                             │
│  ┌──────────┬──────────┬──────────┬──────────┬──────────┐  │
│  │ Projects │  Tasks   │  Events  │Overdue % │ Blocked  │  │
│  │    12    │    47    │    8     │   23%    │    3     │  │
│  │  +2 this │  35 done │ 3 today  │  ▲ 5%   │  2 new   │  │
│  │   week   │  this wk │          │          │          │  │
│  └──────────┴──────────┴──────────┴──────────┴──────────┘  │
│                                                             │
│  Project Pipeline                                           │
│  Planning ████████░░░░░░░░░░░░░░░░░░░░░░ 2  ($0)           │
│  Active   ██████████████████████████████░░ 5  ($45K)        │
│  At-Risk  ████████████░░░░░░░░░░░░░░░░░░ 1  ($12K)         │
│  On-Hold  ████░░░░░░░░░░░░░░░░░░░░░░░░░░ 1  ($0)           │
│  Complete ████████████████████████████████ 3  ($28K)        │
│                                                             │
│  Upcoming Deadlines (Next 7 Days)                           │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Jun 14 │ Q2 Retro Prep          │ Task      │ 🔴    │   │
│  │ Jun 15 │ Marketing Site Launch  │ Project   │ 🟡    │   │
│  │ Jun 16 │ API v2 Documentation   │ Task      │ ⚪    │   │
│  │ Jun 17 │ Sprint 12 Review       │ Event     │ 🟢    │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  Recent Activity                                            │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 2h ago │ Task "Fix login bug" → Completed           │   │
│  │ 5h ago │ Project "Mobile App" status → At-Risk      │   │
│  │ 1d ago │ Task "Write tests" assigned to @jane       │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

**KPI grid metrics (Overview tab):**
1. **Active Projects** — count by status (active, at-risk, planning)
2. **Open Tasks** — total + completed-this-week delta
3. **Upcoming Events** — count next 7 days + today count
4. **Overdue %** — overdue tasks / total open tasks + trend arrow
5. **Blocked Tasks** — count + new-this-week delta
6. **Portfolio Health** — composite score 0–100 weighted by schedule variance, completion rate, blocker count, overdue ratio, resource utilisation
7. **Team Velocity** — tasks completed this sprint/week vs previous
8. **Resource Utilisation** — % of assignees over/under allocated

#### B2 — Event CRUD Tools (4 new tools)

Pattern follows existing `[operation]_[entity]` convention:

- `create_event` — Create `mcp_ai_event` with title, date/time, location, project link, attendees
- `list_events` — List/filter by project, date range, attendee
- `update_event` — Update event fields
- `delete_event` — Delete event

#### B3 — Command Center Query Tools (4 new tools)

- `get_pm_kpis` — Returns the same KPI payload the dashboard renders (active projects, open tasks, upcoming events, overdue %, blocked, portfolio health, velocity, utilisation)
- `get_project_pipeline` — Projects grouped by lifecycle stage with count + budget total (mirrors CRM pipeline view)
- `get_upcoming_deadlines` — Tasks/events due within N days, sorted by proximity
- `get_my_tasks` — Tasks assigned to the requesting user, grouped by project + status

**Hooks added in Phase B:**
- `wp_mcp_ai_pm_toolkit_settings` (filter on resolved settings)
- `wp_mcp_ai_pm_project_statuses` (filter to extend status enum)
- `wp_mcp_ai_pm_task_priorities` (filter to extend priority enum)
- `wp_mcp_ai_pm_pipeline_stages` (filter on lifecycle stage map)
- `wp_mcp_ai_pm_before_project_status_change`, `wp_mcp_ai_pm_after_project_status_change`
- `wp_mcp_ai_pm_portfolio_health_calculated` (action)

---

### Phase C — Analytics, Risk & Automation *(≈ 18 new tools)*

**Goal:** Bring PM to analytics parity with CRM — burndown, velocity, portfolio health, resource utilisation, risk detection, workflow automation.

#### C1 — Analytics Tools (6 new tools)

- `get_burndown_chart` — Returns sprint/project burndown data: ideal line, actual remaining, date axis. Computed from task completion timestamps vs. sprint duration. Outputs JSON arrays suitable for chart.js rendering.
- `get_team_velocity` — Tasks/points completed per sprint/week over last N periods, with rolling average. Supports filtering by project, assignee, or team.
- `get_portfolio_health` — Weighted composite score (0–100) across all active projects: schedule variance (25%), task completion rate (25%), blocker count (20%), overdue task ratio (15%), resource utilisation (15%). Weights configurable via `wp_mcp_ai_pm_toolkit_settings`.
- `get_resource_utilization` — Per-assignee workload: assigned tasks (open + in-progress), estimated hours, % of capacity. Flags over-allocated (>90%) and under-allocated (<30%) users.
- `get_project_timeline` — Dependency-aware Gantt data: tasks sorted by start date with dependency edges, computed critical path, and milestone markers. Outputs structured JSON for frontend rendering.
- `forecast_completion` — ML-naive heuristic forecast: based on historical velocity + remaining work, predicts completion date with confidence band (optimistic/expected/pessimistic). Optionally factors in current blocker count.

#### C2 — Risk Detection Tools (3 new tools)

- `assess_project_risk` — Scores project risk (0–100) across dimensions: schedule slippage (days behind), scope creep (new tasks added post-start without end-date adjustment), resource churn (unassigns/reassigns), blocker count, stale-tasks ratio. Returns dimensional breakdown + overall risk level (low/medium/high/critical).
- `detect_stale_tasks` — Finds tasks with no status change or activity in > N days (configurable, default 14). Optionally auto-flags with `_pm_stale` meta and notifies assignee.
- `identify_blockers` — Returns tasks flagged as blocked (`_pm_blocked` meta), grouped by project, with block duration. Also surfaces implicitly-blocked tasks (dependencies not yet completed).

#### C3 — Workflow Automation Tools (3 new tools)

Mirrors CRM's `mcp_ai_crm_workflow_rule` pattern with a PM-specific CPT (`mcp_ai_pm_workflow_rule`):

- `create_pm_workflow_rule` — Define trigger (task.status → done, project.status → active, task.assigned, event.date → today) + conditions (project.category = X, task.priority = Y) + actions (create_task, update_task_status, send_notification, create_sprint_task, move_to_archive).
- `list_pm_workflow_rules` — List all rules with filter by trigger type, active/inactive.
- `simulate_pm_workflow_rule` — Dry-run a rule against historical data to preview which tasks/projects would have been affected (critical for safe deployment).

#### C4 — Template & Sprint Tools (6 new tools)

- `instantiate_task_template` — Create tasks from a `mcp_task_template` content, parsing markdown checkboxes into individual task CPT entries under the target project.
- `list_task_templates` — List available templates with category/tag filter.
- `create_task_template` — Save a set of tasks as a reusable template.
- `create_sprint` — Create a sprint CPT with start/end dates, goal, linked project.
- `plan_sprint` — Move tasks from backlog into sprint, respecting capacity limits. Computes estimated capacity based on team velocity + working days.
- `close_sprint` — Complete sprint: move incomplete tasks back to backlog, compute sprint metrics (completed/planned ratio, velocity), archive sprint record.

**Hooks added in Phase C:**
- `wp_mcp_ai_pm_burndown_data` (filter to override burndown computation)
- `wp_mcp_ai_pm_risk_factors` (filter to extend risk-assessment dimensions)
- `wp_mcp_ai_pm_workflow_trigger` (action: fired when a rule matches)
- `wp_mcp_ai_pm_sprint_capacity` (filter on capacity computation)
- `wp_mcp_ai_pm_before_sprint_close`, `wp_mcp_ai_pm_after_sprint_close`

---

### Phase D — Assistant Blueprints, Reporting & Interop *(≈ 8 new tools + content)*

**Goal:** Make the toolkit importable into agents out of the box, with reporting, export, and interop surfaces.

#### D1 — Reporting & Export Tools (2 new tools)

- `generate_status_report` — Compile a project status report: summary, completed-this-period tasks, upcoming tasks, blockers, risks, burndown snapshot. Output as structured Markdown or HTML. Optionally render to PDF via pdfkit.
- `export_project_csv` — Export project tasks to CSV (compatible with Excel/Google Sheets import). Includes all task meta: title, status, priority, assignee, start/end dates, project, dependencies, tags.

#### D2 — Assistant Blueprints (4 blueprint JSON files)

Following the CRM `examples/` pattern, ship 4 curated assistant blueprints:

| Blueprint | Role | Key tools |
|---|---|---|
| `scrum-master.json` | Sprint facilitation | `create_sprint`, `plan_sprint`, `close_sprint`, `get_burndown_chart`, `get_team_velocity`, `list_tasks`, `create_task`, `update_task` |
| `pmo-director.json` | Portfolio oversight | `get_portfolio_health`, `get_project_pipeline`, `assess_project_risk`, `get_resource_utilization`, `generate_status_report`, `forecast_completion` |
| `resource-manager.json` | Workload balancing | `get_resource_utilization`, `get_my_tasks`, `list_projects`, `list_tasks`, `update_task` (+ assignee focus) |
| `sprint-coach.json` | Individual contributor coaching | `get_my_tasks`, `get_upcoming_deadlines`, `get_burndown_chart`, `update_task`, `pm_capture_decision` |

#### D3 — Admin Blueprints Page

Create `WP_MCP_AI_PM_Blueprints_Page` (mirrors `WP_MCP_AI_CRM_Blueprints_Page`):
- Browse PM blueprints with descriptions and tags
- One-click install into an NV oOS assistant
- Card grid layout matching CRM blueprint UI

#### D4 — Interop & Calendar Integration (2 new tools)

- `sync_to_calendar` — Export project deadlines, sprint ceremonies, and task due dates to Google Calendar / Outlook via OAuth (using existing password-vault pattern for credential storage). Generates `.ics` feed as fallback.
- `generate_weekly_digest` — Compile a markdown summary of the past week: tasks completed, new tasks created, blockers raised, project status changes, upcoming deadlines. Intended as input to a Monday-morning AI briefing.

#### D5 — Notification & Integration Hooks

- Extend `WP_MCP_AI_PM_Notification_Manager` with workflow-trigger dispatch (when a `pm_workflow_rule` fires an action that requires notification).
- Add Slack webhook integration for blocker alerts and daily digest delivery.
- Add `wp_mcp_ai_pm_command_center_widgets` (filter to add custom widgets to the dashboard).

---

## 5. Admin Menu & UX Pattern

### Top-Level Menu Structure

```
WordPress Admin Sidebar
├── ...
├── Dashboard
├── Posts
├── Media
├── Pages
├── ...
├── NV oOS Pro       (dashicons-superhero, pos 29)
├── NV CRM           (dashicons-groups, pos 30)    ← existing
│   ├── Command Center
│   ├── Customers (CPT)
│   ├── Blueprints
│   └── Settings
├── NV Projects      (dashicons-portfolio, pos 31) ← NEW
│   ├── Command Center
│   ├── Projects (CPT)
│   ├── Tasks (CPT)
│   ├── Events (CPT)
│   ├── Blueprints
│   └── Settings
├── ...
```

### Widget & Filter Extension Points

| Hook | Type | Purpose |
|---|---|---|
| `wp_mcp_ai_pm_toolkit_settings` | filter | Override resolved toolkit settings |
| `wp_mcp_ai_pm_capabilities` | filter | Override the role-to-capability map |
| `wp_mcp_ai_pm_code_packs` | filter | Register additional PM code packs |
| `wp_mcp_ai_pm_pipeline_stages` | filter | Override project lifecycle stage definitions |
| `wp_mcp_ai_pm_project_statuses` | filter | Extend project status enum |
| `wp_mcp_ai_pm_task_priorities` | filter | Extend task priority enum |
| `wp_mcp_ai_pm_risk_factors` | filter | Extend risk-assessment dimensions |
| `wp_mcp_ai_pm_burndown_data` | filter | Override burndown computation |
| `wp_mcp_ai_pm_workflow_trigger` | action | Fired when a workflow rule matches |
| `wp_mcp_ai_pm_sprint_capacity` | filter | Override sprint capacity calculation |
| `wp_mcp_ai_pm_command_center_widgets` | filter | Add custom widgets to the dashboard |
| `wp_mcp_ai_pm_before_project_status_change` | action | Pre-status-change hook |
| `wp_mcp_ai_pm_after_project_status_change` | action | Post-status-change hook |
| `wp_mcp_ai_pm_portfolio_health_calculated` | action | After portfolio health score computed |
| `wp_mcp_ai_pm_before_sprint_close` | action | Pre-sprint-close hook |
| `wp_mcp_ai_pm_after_sprint_close` | action | Post-sprint-close hook |

---

## 6. Tool Count Summary

| Phase | New Tools | Cumulative |
|---|---|---|
| **Pre-existing** | 20 | 20 |
| **Phase A** (foundations) | 0 (infrastructure only) | 20 |
| **Phase B** (dashboard + events + queries) | 12 | 32 |
| **Phase C** (analytics + risk + workflows + templates + sprints) | 18 | 50 |
| **Phase D** (reports + blueprints + interop) | 4 (tools) + 4 (blueprints) + admin UI | ~54 tools |

**Target parity:** CRM ships ~89 tools across 15 sub-modules with full shared engine. PM would ship ~54 tools across 10 sub-modules with shared engine — strong parity for a project-management domain which is naturally narrower than CRM (no multichannel comms, no consent/compliance, no external API integrations like Upwork/LinkedIn).

---

## 7. Migration & Back-Compat

- **No existing tool slugs change.** Tools are relocated into subdirectories but retain their registered slugs. The tool registry resolves by slug, not file path.
- **`init.php` remains the single entry point** that `mcp-ai-wpoos-pro.php` includes. It conditionally loads old paths if new ones don't exist (graceful fallback during transition).
- **`wp_mcp_ai_settings['enable_project_management']`** continues to gate the entire toolkit.
- **New sub-toggles** (`enable_pm_analytics`, `enable_pm_workflows`, `enable_pm_sprints`) default to `false` or inherit the main toggle — no surprise feature activation.
- **PARA un-feature-flagging** in Phase A means existing PARA data (terms, area CPT posts) becomes visible and operational without the separate review-window gate. No data migration needed — the taxonomy and CPT are already registered in the DB for sites that had the flag on.

---

## 8. Implementation Priorities (Recommended Build Order)

| Priority | Deliverable | Effort Estimate | Rationale |
|---|---|---|---|
| **P0** | Phase A — shared engine + codes + pipeline stages + capabilities | 3–5 days | Foundation everything else depends on. PARA un-flag is a one-line change. |
| **P1** | Phase B — admin menu + command center (overview tab with KPIs) | 4–6 days | Visible value. The dashboard is what users see first. Can ship with Overview + Projects + Tasks + Events tabs; add Analytics/PARA/Risk/Workflows tabs as later phases complete. |
| **P2** | Phase B — event CRUD + command center query tools | 2–3 days | Event tools fill the most obvious CRUD gap. Query tools power the dashboard AJAX. |
| **P3** | Phase C — analytics tools (burndown, velocity, portfolio health) | 4–5 days | Industry-standard PM metrics. High differentiation value vs. base WordPress. |
| **P4** | Phase C — risk + workflow + template/sprint tools | 5–7 days | Risk detection and workflow automation bring PM closer to CRM's autonomous-operation capability. |
| **P5** | Phase D — blueprints, reports, interop | 3–4 days | Polish layer. Blueprints enable one-click agent setup; reports provide stakeholder-facing output. |

**Total estimated effort: ~21–30 development days** for full Phase A–D delivery.

---

## 9. References

- **CRM Toolkit Enhancement Plan** — [`CRM_TOOLKIT_ENHANCEMENT_PLAN.md`](CRM_TOOLKIT_ENHANCEMENT_PLAN.md) — the architectural template this plan follows
- **Pro Toolkits Implementation Plan** — [`PRO_TOOLKITS_IMPLEMENTATION_PLAN.md`](PRO_TOOLKITS_IMPLEMENTATION_PLAN.md) — sibling toolkit planning
- **PARA Methodology** — Tiago Forte, "The PARA Method" (2023); [`fortelabs.com/blog/para`](https://fortelabs.com/blog/para/)
- **Industry PM KPIs** — monday.com KPI Dashboard (2026), Celoxis AI Project Management (2026), Epicflow AI in PM (2026), PMI/Gartner 80%-by-2030 forecast
- **Scrum Guide** — Schwaber & Sutherland (2020); burndown, velocity, sprint ceremonies
- **CRM Command Center** — [`../includes/admin/class-wp-mcp-ai-crm-command-center-page.php`](../includes/admin/class-wp-mcp-ai-crm-command-center-page.php) — UX reference
- **CRM Admin Menu** — [`../includes/admin/class-wp-mcp-ai-crm-admin-menu.php`](../includes/admin/class-wp-mcp-ai-crm-admin-menu.php) — menu pattern
- **PARA Framework (existing)** — [`../includes/para/README.md`](../includes/para/README.md) — current PARA state
- **PM Notification Manager** — [`../includes/class-wp-mcp-ai-pm-notification-manager.php`](../includes/class-wp-mcp-ai-pm-notification-manager.php)
