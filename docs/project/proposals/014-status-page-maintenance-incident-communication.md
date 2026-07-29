# Status Page, Maintenance Announcements & Incident Communication Workflow

**Proposal 014**
**Date:** 2026-07-28
**Status:** Proposal
**Target release:** v1.2.0 (Phase 1), v1.3.0 (Phase 2), v1.4.0 (Phase 3)
**Compatibility:** Base: PHP 7.4+ · Pro: PHP 8.1+

---

## 1. Executive Summary

NV oOS already has mature **internal/operator-facing** operational monitoring — the Pro Agent Command Center tracks agent uptime, the Webhook Status Page monitors integration health, the Cron Status Service surfaces job pipelines, and Site Health tests probe API connectivity and model availability. What is missing is the **external/customer-facing** layer: a public status page that site visitors, clients, and stakeholders can consult, a scheduled maintenance announcement system, and a structured incident communication workflow.

This proposal adds these three capabilities as a coherent operational communication surface, reusing existing infrastructure (outbound webhooks, channel broadcast, cron status job-source adapters, the incident learning CPT, and the circuit-breaker pattern) rather than building from scratch.

### Why this matters

- **Transparency builds trust.** When an AI-powered site has degraded service (provider outage, model unavailability, tool failure), users should see a status page, not a silent failure or cryptic error.
- **Planned maintenance needs communication.** The plugin already schedules assistant runs, channel broadcasts, and workflow executions. When those systems undergo maintenance, stakeholders need advance notice — not surprise downtime.
- **Incidents require structured response.** The existing Incident Learning System (`mcp_ai_lesson` CPT, ISO 27001 A.5.27) captures post-mortem lessons but has no real-time incident lifecycle. Operators need a workflow: Detect → Investigate → Identify → Communicate → Resolve → Learn.
- **The infrastructure foundation already exists.** Webhook dispatch (`WP_MCP_AI_Outbound_Webhook`), multi-channel broadcast (Schedule Manager), job-source health adapters (`Interface_WP_MCP_AI_Cron_Status_Job_Source`), and the circuit-breaker state machine are all tested and in production.

---

## 2. Industry Best Practices

### 2.1 Status Page Standards

Leading status page platforms (Atlassian Statuspage, Cachet, Gatus, Uptime Kuma) follow common patterns:

- **Component-based modeling.** Services are modeled as independent components (e.g. "OpenAI API", "Gemini API", "Chat Engine", "Tool Registry") with individual status: `operational`, `degraded_performance`, `partial_outage`, `major_outage`, `under_maintenance`.
- **Public + private views.** A public-facing page shows non-sensitive status; an authenticated admin view shows detailed diagnostics and incident management controls.
- **REST API for automation.** Status data is exposed via a read-only public endpoint so monitoring tools (UptimeRobot, Pingdom, Datadog) and external status aggregators can consume it.
- **Historical uptime.** A rolling 30/90-day uptime percentage per component builds long-term reliability credibility.

### 2.2 Incident Communication (ITIL-aligned)

The ITIL incident management lifecycle maps cleanly to this proposal:

| ITIL Phase | Our Phase | Communication |
|---|---|---|
| Detection | `detected` | Internal alert only |
| Diagnosis | `investigating` | "We are investigating reports of..." |
| Resolution | `identified` | "The issue has been identified as..." |
| Recovery | `monitoring` | "A fix has been deployed, we are monitoring..." |
| Closure | `resolved` | "This incident has been resolved." |

Each phase transition triggers configurable notifications (email, webhook, Slack, Telegram) via the existing Outbound Webhook and Channel Broadcast systems.

### 2.3 Maintenance Announcements

Best practices from cloud providers (AWS, GCP, Cloudways) and plugin ecosystems:

- **Advance notice.** Minimum 24–72 hours for non-emergency maintenance; configurable per window.
- **Countdown + banner.** A frontend banner with a countdown timer (60 minutes → 0) so users know when the window closes.
- **Affected services.** Not all maintenance affects all services; the system should scope announcements to specific components.
- **Auto-resolve.** When the maintenance window end time passes, the banner auto-clears (with an optional grace period).

---

## 3. Existing Infrastructure (What We Already Have)

This proposal is additive, not greenfield. The following existing systems are directly reusable:

### 3.1 Health & Monitoring Signals

| System | File | What It Provides |
|---|---|---|
| Pro Agent Command Center | `addons/pro/includes/admin/class-wp-mcp-ai-pro-agent-command-center.php` | Uptime tracking (`get_system_uptime_pct()`), agent health, real-time monitoring dashboard |
| Webhook Status Page | `addons/pro/includes/admin/class-wp-mcp-ai-pro-webhook-status-page.php` | Health badges (ok/warning/error), summary cards, per-connection testing |
| Site Health Integration | `includes/class-wp-mcp-ai-site-health.php` | WP core health tests for API connectivity, model availability, credentials, schema |
| Cron Status Service | `includes/services/class-wp-mcp-ai-cron-status-service.php` | Pluggable job monitoring via `Interface_WP_MCP_AI_Cron_Status_Job_Source` |

### 3.2 Notification & Dispatch Infrastructure

| System | File | What It Provides |
|---|---|---|
| Outbound Webhook Manager | `includes/class-wp-mcp-ai-outbound-webhook.php` | HMAC-signed webhook subscriptions, event dispatch, signature verification |
| Job Notifier | `includes/class-wp-mcp-ai-job-notifier.php` | SSE streams + webhook dispatch for async job lifecycle events |
| Channel Broadcast (Schedule Manager) | `addons/pro/includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php` | Scheduled messages to Telegram, Slack, Discord, Teams, Messenger, WhatsApp |
| PM Notification Manager | `addons/pro/includes/class-wp-mcp-ai-pm-notification-manager.php` | Email notifications with cooldown, cron-scheduled digests |

### 3.3 Incident & Failure Patterns

| System | File | What It Provides |
|---|---|---|
| Incident Learning System | `includes/class-wp-mcp-ai-incident-learning.php` | `mcp_ai_lesson` CPT for post-incident review, ISO 27001 A.5.27 |
| Circuit Breaker | `addons/pro/includes/class-wp-mcp-ai-circuit-breaker.php` | CLOSED/OPEN/HALF_OPEN state machine, configurable thresholds |
| Ezuite Alert Manager | `addons/pro/includes/class-wp-mcp-ai-ezuite-alert-manager.php` | Cooldown-based alert throttling, email notifications |
| SLA Manager | `includes/class-wp-mcp-ai-sla-manager.php` | Tiered prioritization with Little's Law, capacity planning |

---

## 4. Architecture

### 4.1 Component Model

```
┌─────────────────────────────────────────────────────────────┐
│                    Status Page System                        │
│                                                             │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │ Service         │  │ Maintenance     │  │ Incident     │ │
│  │ Components      │  │ Windows         │  │ Workflow     │ │
│  │                 │  │                 │  │              │ │
│  │ - AI Provider   │  │ - Start/End     │  │ - Phases     │ │
│  │ - Tool Registry │  │ - Message       │  │ - Timeline   │ │
│  │ - Chat Engine   │  │ - Services      │  │ - Updates    │ │
│  │ - Queue Health  │  │ - Channels      │  │ - Resolved   │ │
│  │ - Cache Status  │  │                 │  │              │ │
│  └────────┬────────┘  └────────┬────────┘  └──────┬───────┘ │
│           │                    │                   │         │
│           └────────────────────┼───────────────────┘         │
│                                │                             │
│                    ┌───────────▼──────────┐                  │
│                    │   Public Status API  │                  │
│                    │  GET /mcp-ai/v1/     │                  │
│                    │       status         │                  │
│                    └───────────┬──────────┘                  │
│                                │                             │
│           ┌────────────────────┼───────────────────┐         │
│           ▼                    ▼                    ▼        │
│  ┌────────────────┐  ┌────────────────┐  ┌──────────────┐  │
│  │ Shortcode      │  │ Admin Dashboard│  │ External      │  │
│  │ [nvoos_status] │  │ (Pro only)     │  │ Consumers     │  │
│  └────────────────┘  └────────────────┘  └──────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Data Flow — Service Status Update

```
Health Probe (wp_cron or manual)
    │
    ├─ Service_Status_Source::check_health()
    │       │
    │       ├─ OpenAI:     ping models.list → latency_ms, available
    │       ├─ Gemini:     ping models.list → latency_ms, available
    │       ├─ Tool Registry: count available tools → total, healthy
    │       ├─ Queue:      check backlog depth → depth, oldest_age
    │       └─ Cache:      test object cache hit → hit, miss_rate
    │
    ├─ WP_MCP_AI_Service_Status_Registry::update_component()
    │       │
    │       └─ Store in wp_mcp_ai_service_status option (autoload=no)
    │
    └─ If status changed:
            ├─ Fire wp_mcp_ai_service_status_changed action
            ├─ Outbound Webhook dispatch (event: status.changed)
            └─ Optionally auto-create incident (if degraded → major_outage)
```

### 4.3 Data Flow — Incident Lifecycle

```
Detection (auto or manual)
    │
    ├─ Create mcp_ai_incident CPT post (status: detected)
    │       └─ Fire wp_mcp_ai_incident_created action
    │
    ├─ Operator updates phase: investigating
    │       ├─ Add timeline update (CPT comment or post_meta)
    │       ├─ Fire wp_mcp_ai_incident_updated action
    │       └─ Dispatch webhooks + channel broadcast
    │
    ├─ Operator updates phase: identified
    │       └─ (same update pattern)
    │
    ├─ Operator updates phase: monitoring
    │       └─ (same update pattern)
    │
    └─ Operator updates phase: resolved
            ├─ Set post_status: resolved
            ├─ Fire wp_mcp_ai_incident_resolved action
            ├─ Link to mcp_ai_lesson (Incident Learning System)
            └─ Update status page component status back to operational
```

### 4.4 New CPTs

#### `mcp_ai_service` (Base — PHP 7.4+)

Represents a monitored service component. Not public-facing; used internally.

| Field | Type | Description |
|---|---|---|
| `post_title` | string | Component name (e.g. "OpenAI API") |
| `post_status` | string | `publish` (active) or `draft` (disabled) |
| `_mcp_ai_service_slug` | string | Machine-readable slug (e.g. `openai_api`) |
| `_mcp_ai_service_group` | string | Grouping key (e.g. `ai_providers`, `infrastructure`) |
| `_mcp_ai_service_status` | string | `operational`, `degraded_performance`, `partial_outage`, `major_outage`, `under_maintenance` |
| `_mcp_ai_service_status_updated` | datetime | Last status change timestamp |
| `_mcp_ai_service_latency_ms` | int | Last measured latency |
| `_mcp_ai_service_public` | bool | Whether visible on public status page |

#### `mcp_ai_maintenance` (Pro — PHP 8.1+)

Represents a scheduled maintenance window.

| Field | Type | Description |
|---|---|---|
| `post_title` | string | Window title (e.g. "Database Optimization") |
| `post_content` | string | Rich description / message to display |
| `post_status` | string | `scheduled`, `in_progress`, `completed`, `cancelled` |
| `_mcp_ai_maintenance_start` | datetime | Scheduled start time |
| `_mcp_ai_maintenance_end` | datetime | Scheduled end time |
| `_mcp_ai_maintenance_services` | array | Affected service component slugs |
| `_mcp_ai_maintenance_notify_channels` | array | Channel broadcast targets |
| `_mcp_ai_maintenance_notify_before` | int | Minutes before start to send reminder |
| `_mcp_ai_maintenance_banner_enabled` | bool | Show frontend banner during window |

#### `mcp_ai_incident` (Pro — PHP 8.1+)

Represents a live operational incident. Distinct from `mcp_ai_lesson` (which is for post-mortem learning).

| Field | Type | Description |
|---|---|---|
| `post_title` | string | Incident summary (e.g. "Elevated OpenAI API Errors") |
| `post_status` | string | `detected`, `investigating`, `identified`, `monitoring`, `resolved` |
| `_mcp_ai_incident_severity` | string | `minor`, `major`, `critical` |
| `_mcp_ai_incident_services` | array | Affected service component slugs |
| `_mcp_ai_incident_timeline` | array | Array of `{timestamp, phase, message}` updates |
| `_mcp_ai_incident_resolved_at` | datetime | When resolved |
| `_mcp_ai_incident_lesson_id` | int | Linked `mcp_ai_lesson` post ID (post-resolution) |
| `_mcp_ai_incident_notify_channels` | array | Channel broadcast targets for updates |

### 4.5 New Interface: `Interface_WP_MCP_AI_Service_Status_Source` (Base)

Follows the existing `Interface_WP_MCP_AI_Cron_Status_Job_Source` pattern.

```php
interface Interface_WP_MCP_AI_Service_Status_Source {
    /**
     * Unique slug identifying this service component.
     *
     * @return string e.g. 'openai_api', 'gemini_api'
     */
    public function get_slug();

    /**
     * Human-readable component name.
     *
     * @return string e.g. 'OpenAI API'
     */
    public function get_name();

    /**
     * Grouping category for the component.
     *
     * @return string e.g. 'ai_providers', 'infrastructure'
     */
    public function get_group();

    /**
     * Perform a health check and return the current status.
     *
     * @return array{
     *     status: string,
     *     latency_ms: int|null,
     *     message: string,
     *     checked_at: int
     * }
     */
    public function check_health();

    /**
     * Whether this component should appear on the public status page.
     *
     * @return bool
     */
    public function is_public();
}
```

### 4.6 New REST Endpoints (Base)

| Method | Route | Auth | Description |
|---|---|---|---|
| `GET` | `/mcp-ai/v1/status` | Public (read) | Returns all public service statuses, active incidents, and upcoming maintenance |
| `GET` | `/mcp-ai/v1/status/components` | Public (read) | Service components only |
| `GET` | `/mcp-ai/v1/status/incidents` | Public (read) | Active and recent incidents |
| `GET` | `/mcp-ai/v1/status/maintenance` | Public (read) | Upcoming and active maintenance windows |
| `GET` | `/mcp-ai/v1/status/history` | Public (read) | Uptime history (30/90 day) |

**Pro-only endpoints** (require `manage_options`):

| Method | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/mcp-ai-pro/v1/status/components` | `manage_options` | Register/update a service component |
| `POST` | `/mcp-ai-pro/v1/status/incidents` | `manage_options` | Create a new incident |
| `PUT` | `/mcp-ai-pro/v1/status/incidents/{id}` | `manage_options` | Update incident phase/timeline |
| `POST` | `/mcp-ai-pro/v1/status/maintenance` | `manage_options` | Schedule a maintenance window |
| `PUT` | `/mcp-ai-pro/v1/status/maintenance/{id}` | `manage_options` | Update/cancel maintenance window |
| `POST` | `/mcp-ai-pro/v1/status/health-check` | `manage_options` | Trigger an on-demand health check |

### 4.7 New Shortcode (Base)

`[nvoos_status]` — renders a public status page on any WordPress page/post.

Attributes:
- `show_incidents="true"` — include active incident list
- `show_maintenance="true"` — include upcoming maintenance windows
- `show_history="false"` — include 90-day uptime history
- `compact="false"` — compact card layout vs full layout

### 4.8 Admin Pages (Pro)

All wired under the existing **NV oOS Pro Dashboard** top-level menu:

1. **Status Page** (`nvoos-pro-status`) — service component grid with health badges, incident timeline, maintenance calendar. Modeled on the Webhook Status Page pattern.
2. **Incidents** (`nvoos-pro-incidents`) — incident list with phase filter, create/edit incident forms, timeline editor.
3. **Maintenance** (`nvoos-pro-maintenance`) — maintenance window calendar/list, create/edit forms, notification channel config.

### 4.9 New MCP Tools (Pro)

Tools so AI assistants can interact with the status system:

| Tool Slug | Description |
|---|---|
| `get_service_status` | Query current status of one or all service components |
| `create_incident` | Create a new operational incident |
| `update_incident` | Update incident phase and add timeline entry |
| `resolve_incident` | Mark an incident as resolved |
| `schedule_maintenance` | Create a scheduled maintenance window |
| `get_status_summary` | Get a summary suitable for customer-facing communication |

---

## 5. Action & Filter Hooks

### 5.1 Action Hooks

| Hook | Fires When | Parameters |
|---|---|---|
| `wp_mcp_ai_service_status_changed` | A component's status transitions | `(string $slug, string $old_status, string $new_status, array $component)` |
| `wp_mcp_ai_health_check_completed` | A full health check cycle finishes | `(array $results)` |
| `wp_mcp_ai_incident_created` | A new incident is created | `(int $incident_id, array $incident_data)` |
| `wp_mcp_ai_incident_phase_changed` | An incident transitions phases | `(int $incident_id, string $old_phase, string $new_phase)` |
| `wp_mcp_ai_incident_resolved` | An incident is resolved | `(int $incident_id, array $incident_data)` |
| `wp_mcp_ai_maintenance_scheduled` | A maintenance window is created | `(int $window_id, array $window_data)` |
| `wp_mcp_ai_maintenance_started` | A maintenance window begins | `(int $window_id)` |
| `wp_mcp_ai_maintenance_completed` | A maintenance window ends | `(int $window_id)` |
| `wp_mcp_ai_maintenance_reminder` | Pre-maintenance reminder fires | `(int $window_id, int $minutes_until)` |

### 5.2 Filter Hooks

| Hook | Description | Parameters |
|---|---|---|
| `wp_mcp_ai_service_status_sources` | Register additional service status sources | `(array $sources)` |
| `wp_mcp_ai_status_public_components` | Filter which components appear publicly | `(array $components)` |
| `wp_mcp_ai_incident_notification_channels` | Filter notification channels for incidents | `(array $channels, int $incident_id)` |
| `wp_mcp_ai_maintenance_notification_channels` | Filter notification channels for maintenance | `(array $channels, int $window_id)` |
| `wp_mcp_ai_status_page_template` | Override the status page template path | `(string $template_path)` |

---

## 6. Security & Capabilities

| Operation | Required Capability | Notes |
|---|---|---|
| View public status (REST GET) | None | Public endpoint; `permission_callback`: `__return_true` |
| View detailed status (admin) | `manage_options` | Matches health endpoint pattern from PR #5718 |
| Create/update incidents | `manage_options` | State-changing; nonce-protected |
| Schedule maintenance | `manage_options` | State-changing; nonce-protected |
| Trigger health check | `manage_options` | May trigger outbound HTTP requests |
| AI tool: `get_service_status` | `edit_posts` | Read-only |
| AI tool: `create_incident` | `manage_options` | State-changing |
| AI tool: `schedule_maintenance` | `manage_options` | State-changing |

- Public REST responses are allowlisted — no internal IPs, credential status, or server paths.
- The `_mcp_ai_service_public` flag gates per-component visibility on the public endpoint.
- All tool parameters follow the two-gate sanitisation rule (sanitize at entry in `execute()`, escape at exit).
- Health probes use `wp_safe_remote_get()` with a 10-second timeout to prevent hanging requests.
- Webhook payloads are HMAC-SHA256 signed via the existing `WP_MCP_AI_Outbound_Webhook` infrastructure.

---

## 7. Data Storage

| Data | Storage | Key / Table | Autoload |
|---|---|---|---|
| Service status snapshot | WordPress option | `wp_mcp_ai_service_status` | no |
| Uptime history (30-day) | WordPress option | `wp_mcp_ai_service_uptime_history` | no |
| Last health check timestamp | WordPress option | `wp_mcp_ai_last_health_check` | no |
| Service component definitions | CPT `mcp_ai_service` | `wp_posts` + `wp_postmeta` | N/A |
| Maintenance windows | CPT `mcp_ai_maintenance` | `wp_posts` + `wp_postmeta` | N/A |
| Incidents | CPT `mcp_ai_incident` | `wp_posts` + `wp_postmeta` | N/A |
| Incident timeline updates | Post meta (array) | `wp_postmeta._mcp_ai_incident_timeline` | N/A |

- Service status and uptime history use options (not transients) because they should survive cache flushes.
- CPT-based models (services, maintenance, incidents) benefit from WordPress's built-in REST API, revision tracking, and admin UI.
- Incident timeline updates are stored as a serialized array in post meta (expected: <50 updates per incident; well within post meta limits).

---

## 8. Cron & Background Jobs

| Job | Hook | Frequency | Description |
|---|---|---|---|
| Health check cycle | `wp_mcp_ai_health_check_cron` | Every 5 minutes | Probes all registered service sources; updates status; fires alerts on degradation |
| Maintenance window monitor | `wp_mcp_ai_maintenance_monitor_cron` | Every minute | Transitions windows: `scheduled` → `in_progress` → `completed`; fires start/end hooks |
| Maintenance reminder | `wp_mcp_ai_maintenance_reminder_cron` | Every 5 minutes | Checks for windows starting soon; dispatches pre-maintenance notifications |
| Uptime history rollup | `wp_mcp_ai_uptime_rollup_cron` | Hourly | Aggregates health check results into daily uptime percentages |
| History cleanup | `wp_mcp_ai_status_history_cleanup` | Daily | Prunes uptime history beyond 90 days |

- All cron hooks use `wp_next_scheduled()` guards before scheduling (following the plugin's existing cron pattern in `includes/bootstrap/activation.php`).
- Health checks respect a maximum probe timeout of 10 seconds; a stuck probe is treated as `major_outage` for that component.
- Maintenance transitions use `wp_schedule_single_event()` at the exact start/end timestamps (precision scheduling) plus a fallback 1-minute monitor.

---

## 9. Phase Rollout

### Phase 1 — Status Page Foundation (Base + Pro) — Target: v1.2.0

**Base plugin (PHP 7.4+):**

| File | Change |
|---|---|
| `includes/interfaces/interface-wp-mcp-ai-service-status-source.php` | **New.** `Interface_WP_MCP_AI_Service_Status_Source` |
| `includes/services/class-wp-mcp-ai-service-status-registry.php` | **New.** Registry that collects sources via `wp_mcp_ai_service_status_sources` filter, runs health checks, stores status in options |
| `includes/class-wp-mcp-ai-service-status-rest.php` | **New.** REST controller: `GET /mcp-ai/v1/status`, `/status/components`, `/status/incidents`, `/status/maintenance`, `/status/history` |
| `includes/class-wp-mcp-ai-status-shortcode.php` | **New.** `[nvoos_status]` shortcode |
| `includes/services/class-wp-mcp-ai-service-status-default-sources.php` | **New.** Built-in sources: `ai_provider_status`, `tool_registry_status`, `queue_health` |
| `includes/bootstrap/loader.php` | **Edit.** Require new files; register REST routes; register shortcode |
| `includes/bootstrap/activation.php` | **Edit.** Schedule `wp_mcp_ai_health_check_cron` and `wp_mcp_ai_uptime_rollup_cron` |

**Pro addon (PHP 8.1+):**

| File | Change |
|---|---|
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-status-page.php` | **New.** Admin status dashboard page (under Pro Dashboard menu) |
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-status-ajax.php` | **New.** AJAX handlers for admin status operations |
| `addons/pro/assets/css/pro-status-page.css` | **New.** Admin status page styles |
| `addons/pro/assets/js/pro-status-page.js` | **New.** Admin status page JS (live refresh, Chart.js uptime graph) |

### Phase 2 — Maintenance Announcement System (Pro) — Target: v1.3.0

| File | Change |
|---|---|
| `addons/pro/includes/class-wp-mcp-ai-maintenance-cpt.php` | **New.** `mcp_ai_maintenance` CPT registration, meta fields, status transitions |
| `addons/pro/includes/class-wp-mcp-ai-maintenance-rest.php` | **New.** REST CRUD for maintenance windows |
| `addons/pro/includes/class-wp-mcp-ai-maintenance-banner.php` | **New.** Frontend banner renderer (shortcode, auto-inject on affected pages) |
| `addons/pro/includes/class-wp-mcp-ai-maintenance-notifier.php` | **New.** Notification dispatcher: email, webhook, channel broadcast |
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-maintenance-page.php` | **New.** Admin maintenance calendar/list page |
| `addons/pro/assets/css/pro-maintenance.css` | **New.** Maintenance banner + admin styles |
| `addons/pro/assets/js/pro-maintenance.js` | **New.** Countdown timer, admin calendar JS |

### Phase 3 — Incident Communication Workflow (Pro) — Target: v1.4.0

| File | Change |
|---|---|
| `addons/pro/includes/class-wp-mcp-ai-incident-cpt.php` | **New.** `mcp_ai_incident` CPT registration, phase state machine, timeline meta |
| `addons/pro/includes/class-wp-mcp-ai-incident-rest.php` | **New.** REST CRUD for incidents, phase transitions |
| `addons/pro/includes/class-wp-mcp-ai-incident-notifier.php` | **New.** Incident notification dispatcher with phase-aware messaging |
| `addons/pro/includes/class-wp-mcp-ai-incident-lesson-bridge.php` | **New.** Bridges `mcp_ai_incident` → `mcp_ai_lesson` on resolution |
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-incidents-page.php` | **New.** Admin incidents list + editor page |
| `addons/pro/includes/tools/class-wp-mcp-ai-tool-create-incident.php` | **New.** AI tool: `create_incident` |
| `addons/pro/includes/tools/class-wp-mcp-ai-tool-update-incident.php` | **New.** AI tool: `update_incident` |
| `addons/pro/includes/tools/class-wp-mcp-ai-tool-get-service-status.php` | **New.** AI tool: `get_service_status` |
| `addons/pro/includes/tools/class-wp-mcp-ai-tool-schedule-maintenance.php` | **New.** AI tool: `schedule_maintenance` |
| `addons/pro/assets/css/pro-incidents.css` | **New.** Incident timeline + admin styles |
| `addons/pro/assets/js/pro-incidents.js` | **New.** Incident editor + timeline JS |

---

## 10. Backward Compatibility

- **No breaking changes to existing APIs.** All new endpoints, CPTs, and shortcodes are additive.
- **No database migrations.** New CPTs are registered fresh; no existing tables or options are modified.
- **No new Composer dependencies.** All implementation uses WordPress core APIs and the plugin's existing patterns.
- **Existing monitoring is unaffected.** The Pro Agent Command Center, Webhook Status Page, and Site Health tests continue to operate independently. The status page aggregates them; it does not replace them.
- **Opt-in health probes.** Service status sources are registered via the `wp_mcp_ai_service_status_sources` filter. If no sources are registered, the status page shows "No components configured" — it does not break.
- **Public endpoint is read-only.** No write operations are exposed without authentication.

---

## 11. Testing Strategy

### 11.1 Unit Tests

| Test | Coverage |
|---|---|
| `Test_Service_Status_Registry` | Source registration, health check aggregation, status transition detection |
| `Test_Service_Status_REST` | Public endpoint responses, field allowlisting, error states |
| `Test_Status_Shortcode` | Shortcode output, attribute handling, empty state |
| `Test_Maintenance_CPT` | CPT registration, meta save/read, status transitions |
| `Test_Incident_CPT` | CPT registration, phase state machine validation, timeline append |
| `Test_Incident_Notifier` | Phase-aware message templates, channel routing |
| `Test_Incident_Lesson_Bridge` | Incident→Lesson linkage on resolution |

### 11.2 Integration Tests

| Test | Coverage |
|---|---|
| `Test_Status_Health_Check_Flow` | Cron-triggered health check → status update → webhook dispatch |
| `Test_Maintenance_Banner_Display` | Banner visibility during/outside window, countdown accuracy |
| `Test_Incident_Full_Lifecycle` | Create → investigate → identify → monitor → resolve → link lesson |

### 11.3 REST API Tests

- All public endpoints return 200 with well-formed JSON.
- Unauthenticated write attempts return 401.
- Components with `is_public() === false` are excluded from public responses.
- Rate limiting on the public status endpoint (matching existing SSE rate limiter pattern).

---

## 12. Risks & Mitigations

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| **Health probes cause performance degradation** | Medium | Medium | Probes use `wp_safe_remote_get()` with 10s timeout; cached between cron runs; no probe on every page load |
| **Public status page exposes sensitive infrastructure details** | Low | High | Field allowlisting; `is_public()` gate; no internal IPs/credentials in public response |
| **Feature creep into full DevOps platform** | Medium | Medium | Strict scope per phase; reject out-of-scope requests with reference to this proposal |
| **Confusion with WordPress core `.maintenance` mode** | Medium | Low | Name the CPT `mcp_ai_maintenance` (not `maintenance`); document distinction in admin UI and readme |
| **Incident spam from automated health checks** | Medium | Medium | Auto-created incidents require a cooldown (no more than 1 per component per hour); manual override always available |
| **Database bloat from uptime history** | Low | Low | Daily rollup; 90-day retention; `wp_mcp_ai_status_history_cleanup` cron prunes old data |

---

## 13. Documentation Deliverables

| Document | Location | Description |
|---|---|---|
| Status Page Admin Guide | `docs/admin-guides/status-page.md` | How to configure components, read the dashboard, respond to incidents |
| Public Status Page User Guide | `docs/user-guides/status-page.md` | How end users interpret the status page and subscribe to updates |
| Maintenance Window Guide | `docs/admin-guides/maintenance-windows.md` | Scheduling, notification channels, banner configuration |
| Incident Response Runbook | `docs/operations/incident-response.md` | Step-by-step incident lifecycle, communication templates, escalation paths |
| REST API Reference (additions) | `docs/reference/rest-api.md` | New endpoints, request/response schemas |
| Tool Reference (additions) | `docs/reference/tool-reference.md` | New AI tools for status/incident/maintenance |

---

## 14. References

- [Pro Agent Command Center](../../addons/pro/includes/admin/class-wp-mcp-ai-pro-agent-command-center.php) — existing operator dashboard with uptime tracking
- [Webhook Status Page](../../addons/pro/includes/admin/class-wp-mcp-ai-pro-webhook-status-page.php) — existing status page pattern (health badges, summary cards)
- [Incident Learning System](../../includes/class-wp-mcp-ai-incident-learning.php) — existing `mcp_ai_lesson` CPT, ISO 27001 A.5.27
- [Outbound Webhook Manager](../../includes/class-wp-mcp-ai-outbound-webhook.php) — existing webhook subscription + dispatch infrastructure
- [Cron Status Service](../../includes/services/class-wp-mcp-ai-cron-status-service.php) — existing pluggable job monitoring pattern
- [Circuit Breaker](../../addons/pro/includes/class-wp-mcp-ai-circuit-breaker.php) — existing state machine pattern
- [Schedule Manager — Channel Broadcast](../../addons/pro/includes/admin/sections/class-wp-mcp-ai-section-schedule-manager.php) — existing multi-channel messaging
- [CLAUDE.md](../../CLAUDE.md) — naming conventions, PHP compat, security rules
- [Atlassian Statuspage Best Practices](https://www.atlassian.com/incident-management/statuspage)
- [ITIL Incident Management](https://wiki.en.it-processmaps.com/index.php/Incident_Management)
