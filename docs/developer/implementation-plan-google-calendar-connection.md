# Implementation Plan — Google Calendar Connection

**Status:** Proposed
**Author:** NV oOS engineering
**Target:** Base plugin (`includes/`) + Pro addon (`addons/pro/`)
**Scope:** Add a first-class **Google Calendar** connection to both connection surfaces, mirroring Gmail and Google Drive, and back it with a production-grade Calendar API client (auth, incremental sync, push notifications, quota-safe retry).

---

## 1. Executive summary

NV oOS today exposes **Gmail** and **Google Drive** as connections on two independent surfaces:

| Surface | URL | Storage | Cardinality | OAuth owner |
|---|---|---|---|---|
| **Base — Tools → Connections** | `admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=google_drive` | `wp_mcp_ai_settings` option | **one** per service | `WP_MCP_AI_OAuth_Manager` |
| **Pro — Remote Sites** | `admin.php?page=wp-mcp-ai-remote-sites` | `wp_mcp_ai_pro_remote_sites` option | **many** per service | `WP_MCP_AI_Pro_Remote_Sites_Admin` |

Google Calendar exists in the codebase only in fragments, none of which are wired to a connection:

| Existing artefact | File | Reality |
|---|---|---|
| `create_google_calendar_event` tool | `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-create-google-calendar-event.php` | **Non-functional out of the box.** Credentials come *only* from `apply_filters( 'wp_mcp_ai_google_calendar_access_token' )` / `…_service_account_credentials`, both defaulting to empty. Always returns `wp_mcp_ai_calendar_missing_credentials` unless a site adds custom PHP. No `connection_id` support; `additionalProperties => false` blocks adding one without a schema edit. |
| `sync_google_calendar` tool | `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-google-calendar.php` | **Stub.** Calls `apply_filters( 'wp_mcp_ai_google_calendar_sync', false, … )`; when the filter is absent it writes `_google_calendar_synced = 'pending'` and returns a fake `event_id` of `'pending-' . time()`. No HTTP call is made. |
| `gws-calendar` bundled skill | `addons/pro/includes/bundled-skills/gws-calendar/SKILL.md` | Documents an external `gws` CLI, not the plugin. Reference material only. |
| Remote Sites | `addons/pro/includes/**/*remote-site*.php` | **Zero** `calendar` references. |

**Goal of this plan:** make Google Calendar a real connection on both surfaces, then replace the two stub/filter-only tools with connection-aware implementations built on a shared, quota-safe API client.

**Recommended sequencing:** Phases 0–3 deliver the user-visible feature (a connection you can authorize and tools that work). Phases 4–6 add the differentiating capability (incremental sync, push notifications, booking bridge) and can ship independently.

---

## 2. Research findings — Google Calendar API v3 industry standards

All facts below are drawn from Google's official Workspace Calendar API and Google Identity documentation. Citations in §12.

### 2.1 OAuth scopes — and the verification cliff

The scope choice is the single highest-leverage decision in this plan, because it determines whether **every site owner** must pass Google's OAuth app-verification review.

| Scope | Grants | Expected tier | Verification burden |
|---|---|---|---|
| `https://www.googleapis.com/auth/calendar` | Read/write/share/delete **all** accessible calendars | Sensitive | Review + demo video, 3–5 business days |
| `https://www.googleapis.com/auth/calendar.events` | Read/write events on **all** calendars | Sensitive | Review + demo video |
| `https://www.googleapis.com/auth/calendar.events.readonly` | Read events on all calendars | Sensitive | Review |
| `https://www.googleapis.com/auth/calendar.readonly` | Read all calendars | Sensitive | Review |
| `https://www.googleapis.com/auth/calendar.app.created` | Create secondary calendars **and fully manage events on those only** | **Non-sensitive** | **None** (brand verification only) |
| `https://www.googleapis.com/auth/calendar.calendarlist.readonly` | List subscribed calendars | **Non-sensitive** | **None** |
| `https://www.googleapis.com/auth/calendar.freebusy` | Availability only | Sensitive (narrow) | Review |
| `https://www.googleapis.com/auth/calendar.acls` / `.acls.readonly` | Sharing permissions | Sensitive | Review |
| `https://www.googleapis.com/auth/calendar.settings.readonly` | Calendar settings | Sensitive | Review |

Three corrections to common assumptions, worth recording because they change the plan:

1. **`calendar.acl` does not exist** — the real scope is **`calendar.acls`** (plural). Same for `calendar.acls.readonly`.
2. **No Calendar scope is *restricted*.** The CASA third-party security assessment applies only to restricted scopes (Gmail, Drive, health data). A Calendar-only integration therefore never needs a security assessment — a meaningful advantage over the existing Gmail connection.
3. **A password change does not revoke a Calendar-only refresh token.** Google revokes on password change only when the token carries **Gmail** scopes. Bundling Gmail and Calendar scopes into one OAuth client would forfeit this durability.

**Verification exemption that matters most here:** projects with **Publishing status = `Testing`** are exempt from review — but Google then issues refresh tokens that **expire after 7 days**. Since every NV oOS site owner supplies their own Google Cloud project, this is the #1 predictable support ticket. It must be surfaced in the admin UI, not buried in docs.

### 2.2 Granular consent — you must not assume you got what you asked for

Since 2019-era clients, Google shows a **granular consent screen** whenever ≥1 non-Sign-In scope is requested alongside Sign-In scopes, or ≥2 non-Sign-In scopes are requested. The user can approve a **subset**.

Implementation rule: **persist the `scope` field from the token response** (space-delimited, case-sensitive) and gate every feature on it. `enable_granular_consent=false` has no effect on OAuth clients created after 2019. Domain-wide-delegation and Google-"Trusted" apps bypass the screen (all-or-nothing).

### 2.3 Incremental sync (`syncToken`)

```
full sync (no syncToken) ──paginate via nextPageToken──▶ last page yields nextSyncToken  [PERSIST]
                                                                      │
incremental sync (syncToken=…) ──┬─ 200 + nextSyncToken → store, done
                                 ├─ 200 + nextPageToken → resend SAME syncToken + pageToken
                                 └─ 410 Gone            → wipe local store, restart full sync
```

Hard constraints:

* `nextPageToken` and `nextSyncToken` are **mutually exclusive**; only the final page carries a sync token.
* Every request in a sync sequence must use an **identical parameter set** except `syncToken` / `pageToken`.
* These parameters return **HTTP 400** when combined with `syncToken`: `timeMin`, `timeMax`, `updatedMin`, `q`, `orderBy`, `iCalUID`, `privateExtendedProperty`, `sharedExtendedProperty`.
  → **This is the classic defect.** `timeMin` is legal on the *initial* full sync but illegal on every incremental request. The parameter builder must branch on sync mode.
* `showDeleted=false` is **not allowed** with `syncToken` (deletions must be delivered so clients can purge).
* Legacy `updatedMin` sync is explicitly deprecated by Google, and reminder-only changes don't bump `updated` — another reason to use `syncToken`.

**HTTP 410 has three distinct meanings.** Branch on `error.errors[0].reason`, never on the status code:

| `reason` | Action |
|---|---|
| `fullSyncRequired` | Delete token, **clear local store**, full resync |
| `updatedMinTooLongAgo` | Same |
| `deleted` | Deleting an already-deleted event — **treat as success, not an error** |

### 2.4 `status: "cancelled"` — two code paths, not one

| Case | Guaranteed fields | Correct handling |
|---|---|---|
| Cancelled **exception** of a live recurring event | `id`, `recurringEventId`, `originalStartTime` | **Keep the local row** for the parent series' lifetime; suppress that occurrence from display |
| Any other cancelled event (true deletion) | `id` only | **Delete the local row** |

Discriminator: presence of `recurringEventId`. Getting this wrong causes either phantom occurrences or permanently resurrecting deleted events.

### 2.5 Push notifications (`watch` / channels)

| Property | Value |
|---|---|
| Watchable resources | Events, ACL (per-calendar); CalendarList, Settings (per-user) |
| Receiver requirements | **HTTPS with a valid CA-signed certificate.** Self-signed, untrusted, revoked, or hostname-mismatched certs are rejected |
| `robots.txt` | Google's `APIs-Google` agent **respects it** — a disallowed `/wp-json/` silently kills push |
| Channel `id` | Client-generated, unique per project, **max 64 chars** |
| Channel `token` | Arbitrary, **max 256 chars**, echoed as `X-Goog-Channel-Token`. Never put secrets here |
| TTL | `params.ttl` in seconds, **default and max `604800` (7 days)** |
| Renewal | **No auto-renewal.** Call `watch` again with a **new** `id`; expect an overlap window with two live channels |
| Stop | `POST /calendar/v3/channels/stop` with `{ id, resourceId }` |
| Notification body | **Empty.** You must call the API to learn what changed |

Headers: `X-Goog-Channel-ID`, `X-Goog-Resource-ID`, `X-Goog-Resource-URI`, `X-Goog-Resource-State` (`sync` \| `exists` \| `not_exists`), `X-Goog-Message-Number` (always `1` for `sync`; thereafter strictly increasing but **non-sequential**), `X-Goog-Channel-Token`, `X-Goog-Channel-Expiration`.

Two operational traps:

1. **Sync-message race.** Google may deliver the `sync` handshake *before* the `watch` HTTP response returns. Write the channel row **before** issuing `watch`, or buffer unknown channel IDs.
2. **Push is not reliable.** Google states plainly: *"Notifications are not 100% reliable. Expect a small percentage of messages to get dropped."* Push is a **trigger**, never a transport. Always pair it with a jittered safety-net poll.

Expected response codes: `200/201/202/204/102` = success; `500/502/503/504` = retried; anything else = failure.

### 2.6 Quotas, rate limits, backoff

Google reset Calendar quotas on **2026-05-01**. Projects created on/after that date get:

| Limit | Value |
|---|---|
| Per minute per project | 10,000 requests |
| Per minute **per user** per project | 600 requests |
| Per day per project (billing threshold) | 1,000,000 requests |

Quotas use a **sliding per-minute window**. Projects active Nov 2025–Apr 2026 keep legacy quotas, which Google does not publish — read actual values from Cloud Console rather than hardcoding.

Retry matrix:

| Retry | Do **not** retry |
|---|---|
| `403 rateLimitExceeded`, `403 userRateLimitExceeded`, `403 quotaExceeded`, `429`, `500`, `502`, `503`, `504` | `400`, `401`, `403 forbiddenForNonOrganizer`, `404`, `409 duplicate`, `410 fullSyncRequired` (resync instead) |

Google's algorithm — note the jitter is **recalculated every attempt**, specifically to de-synchronize fleets:

```
wait = min( 2^n + random_ms(0..1000), maximum_backoff )   // maximum_backoff = 32s or 64s
```

Anti-patterns Google names explicitly, both of which NV oOS must avoid:

* *"An anti-pattern here is to repeatedly poll every calendar of interest."* → use push + `syncToken`.
* *"A common bad practice for a Calendar client is to perform a full sync at midnight."* → randomize per-site sync time and vary intervals **±25%**.

Two cost details: **`events.patch` costs 3 quota units** (prefer `get` + `update`), and under domain-wide delegation the **service account** is charged against per-user quota unless you set **`quotaUser`** / `x-goog-quota-user`.

### 2.7 Time, recurrence, attendees, conferencing

* **All-day vs timed:** `start.date`/`end.date` (`yyyy-mm-dd`) vs `start.dateTime`/`end.dateTime` (+ optional `timeZone`). You may not mix. **`end` is exclusive** — a single-day all-day event on `2026-06-01` has `end.date = "2026-06-02"`. This off-by-one is the most common Calendar integration defect.
* **Timezones:** IANA names only (`Europe/Zurich`), never abbreviations or offsets. `dateTime` requires an offset *unless* `timeZone` is set. `timeZone` is **required** for recurring events. `freebusy.query` defaults to **UTC** while `events.list` defaults to the **calendar's** timezone — always send `timeZone` explicitly.
* **Recurrence:** RFC 5545 strings in `recurrence[]` — `RRULE`, `RDATE`, `EXDATE`. `DTSTART`/`DTEND` are **forbidden** there. `COUNT` and `UNTIL` are mutually exclusive. Key instance rows on **`originalStartTime`**, not `start` (a rescheduled instance keeps its original identity).
* **"This and following"** has no single-call primitive: trim the master's `RRULE` with `UNTIL`, then `insert` a new series. Side effect: *"resets any exceptions happening after the target instance."*
* **Attendees:** always set `responseStatus: "needsAction"` on new events — Google warns that `accepted`/`declined`/`tentative` can be reset and the event may not appear for guests. Beyond **200 guests** response status isn't propagated. Service accounts need DWD to populate attendees.
* **`sendUpdates`:** `all` \| `externalOnly` \| `none`. Google documents `none` as dangerous: *"events not syncing to external calendars or events being lost altogether."* Use `events.import` for bulk migration instead.
* **Google Meet:** requires `conferenceDataVersion=1` on **every** modification request plus `conferenceData.createRequest.requestId`. The `requestId` is an **idempotency key** — reusing it makes Google ignore the request, so persist it against the booking row and reuse on retry. Link generation is **asynchronous**: poll `events.get` until `createRequest.status.statusCode === 'success'`. Never reuse conference data across events (privacy leak).
* **Reminders:** `useDefault` or ≤**5** `overrides` with `method` ∈ {`email`,`popup`} and `minutes` ∈ 0–40320.

### 2.8 Refresh-token lifecycle

| Condition | Effect |
|---|---|
| Publishing status `Testing` + external user type | **Refresh token expires in 7 days** |
| >100 refresh tokens per Google account per client ID | Oldest is invalidated **silently, without warning** |
| Unused for 6 months | Revoked |
| Password change | Revoked **only if the token carries Gmail scopes** |
| Admin sets service to Restricted | `admin_policy_enforced` |

**WordPress implication of the 100-token limit:** a plugin that re-runs the OAuth flow on every settings save, or a multisite network authorizing per-site, can silently burn through 100 tokens and invalidate the working one. Store **one refresh token per (site, Google account)** and reuse it; never re-authorize merely to "refresh". Guard the OAuth-start endpoint accordingly.

Token sizes to allow for in storage: auth code 256 B, access token 2048 B, refresh token 512 B.

Revocation: `POST https://oauth2.googleapis.com/revoke` with `token=<access-or-refresh>`; `200` on success. Revoking an access token also revokes its refresh token. Should be called from `uninstall.php`.

---

## 3. Architecture decisions

### ADR-1 — Support both connection surfaces, Pro-first

**Decision:** implement the Pro Remote Sites connection type (Phase 2) and the Base Tools→Connections tab (Phase 1) with a **shared** auth/API layer in the base plugin.

**Rationale:** the two surfaces are not redundant. Base gives WordPress.org users a working single-calendar connection with no Pro dependency; Pro gives multi-calendar/multi-account fan-out. Gmail and Drive already establish this pattern and users will expect Calendar to match. Putting the shared layer in `includes/` (base) keeps Pro a thin adapter and avoids duplicating the OAuth/retry logic a third time.

### ADR-2 — Scope selector, defaulting to the reviewed-but-capable set

**Decision:** ship a **scope profile** selector with three options; default to **Standard**.

| Profile | Scopes | Verification | Capability |
|---|---|---|---|
| **Minimal** (recommended for new Cloud projects) | `calendar.app.created` + `calendar.calendarlist.readonly` | **None** | Plugin creates and owns its own "NV oOS" secondary calendar; full CRUD there; can list other calendars but not read them |
| **Standard** (default) | `calendar.events` + `calendar.calendarlist.readonly` | Sensitive review | Read/write events on the user's real calendars |
| **Full** | `calendar` + `calendar.settings.readonly` | Sensitive review | Everything, incl. ACL/sharing and calendar management |

**Rationale:** the Minimal profile is a genuine competitive advantage — it removes Google's review entirely, which for a self-hosted plugin means every site owner avoids a 3–5 day approval and an unverified-app warning screen. But it cannot write to a user's primary calendar, which is what most people actually want. A selector lets the site owner make an informed trade-off. The admin UI must state the verification consequence next to each option.

**Rejected alternative:** hardcoding `calendar` (full). It maximizes the review burden and violates least privilege for no functional gain in the common case.

### ADR-3 — Persist and enforce granted scopes

**Decision:** store the token response's `scope` string as `granted_scopes` on the connection. Every tool checks it before acting and returns a specific `WP_Error` naming the missing scope.

**Rationale:** granular consent makes partial grants normal, not exceptional. Without this, a user who unticks one permission gets opaque `403`s from Google instead of an actionable "reconnect and grant Calendar events access" message.

### ADR-4 — Dual credential model on the tool layer

**Decision:** the Calendar tools accept **either** an OAuth refresh token (from a connection) **or** a service-account JSON (via the existing filters), resolved in that order.

**Rationale:** the existing `create_google_calendar_event` tool is service-account-only (JWT bearer grant). Connections produce refresh tokens. Supporting both is additive, preserves backward compatibility for any site already using the filters, and covers Google Workspace domain-wide-delegation deployments that legitimately prefer service accounts. Note service accounts **cannot** be used for consumer `@gmail.com` accounts at all — DWD is authorized only in the Workspace Admin console.

### ADR-5 — Introduce a shared Google auth/HTTP layer instead of a third copy-paste

**Decision:** add two new base classes and use them for **all new** Calendar code. Do **not** rewrite Gmail/Drive in this project.

* `WP_MCP_AI_Google_OAuth_Service` — authorize-URL construction, state transient, code exchange, refresh-token→access-token minting **with a cached access token**, `userinfo` lookup, revocation.
* `WP_MCP_AI_Google_Calendar_Client` — typed Calendar v3 wrapper: retry/backoff, `410` discrimination, pagination, `syncToken` mode branching, `quotaUser`.

**Rationale:** the audit found the Google OAuth start/callback logic duplicated **four times** (base Gmail, base Drive, Pro Gmail, Pro Drive), each ~150 lines, with drifted scope constants — e.g. base Drive uses `drive.readonly drive.metadata.readonly` while Pro Drive uses `drive.file drive.readonly` for the *same* product. Adding a fifth and sixth copy for Calendar would be negligent. Extracting a shared service for new code stops the bleeding without the risk of refactoring four working flows. A follow-up ticket can migrate Gmail/Drive onto it.

**Cost of ADR-5:** ~2 new files + a `README.md` for the new folder (required — see §9). Well under the cost of the duplication it prevents.

### ADR-6 — `calendar_id`, never `folder_id`

**Decision:** the Pro connection stores its default calendar in a **new** `calendar_id` field.

**Rationale:** `folder_id` is read **unconditionally for every connection type** at `class-wp-mcp-ai-pro-remote-sites-admin.php` L807 — it is not inside the `switch ( $connection_type )`. Reusing it would let a Calendar save clobber a Drive connection's folder scope. This requires a new allowlist key in `save_connection()` (§5, item P-12) and a preserve-on-empty branch.

### ADR-7 — Push notifications are a trigger; `syncToken` is the transport

**Decision:** the webhook handler validates, returns `200` immediately, and enqueues an Action Scheduler job. All actual reading happens in that job via `syncToken`. A jittered safety-net incremental sync runs every 6–12 h regardless.

**Rationale:** Calendar notifications carry no body, Google's delivery timeout budget is short, and Google itself documents dropped messages. Doing API work inside the webhook request would be both slow and unreliable.

### ADR-8 — Graceful degradation when push is impossible

**Decision:** detect push eligibility (HTTPS + non-loopback host + `/wp-json/` reachable and not `robots.txt`-disallowed). When ineligible, disable the watch feature, show an admin notice explaining why, and fall back to polling.

**Rationale:** local, staging, and self-signed-cert sites can never receive Google push. Silently failing `watch` calls produce confusing errors; an explicit capability check turns it into a clear message.

---

## 4. Phased implementation

### Phase 0 — Shared Google foundation (base plugin)

New folder `includes/google/` with a mandatory `README.md`.

| File | Contents |
|---|---|
| `includes/google/README.md` | Folder purpose, public surface, neighbours, `.context/` files to load. Required by `composer run docs:check-folder-readmes` (part of `ci:all`, `--scope=base`). |
| `includes/google/class-wp-mcp-ai-google-oauth-service.php` | `WP_MCP_AI_Google_OAuth_Service`. Static/instance methods: `build_authorize_url( array $args )`, `store_state( string $service, array $payload )`, `consume_state( string $service, string $state )` (single-use delete), `exchange_code( … )`, `mint_access_token( $client_id, $client_secret, $refresh_token, $cache_key )`, `fetch_userinfo( $access_token )`, `revoke( $token )`. Access tokens cached in a transient keyed by connection, TTL `expires_in − 300`. |
| `includes/google/class-wp-mcp-ai-google-calendar-client.php` | `WP_MCP_AI_Google_Calendar_Client`. Constructor takes an access-token provider callable + optional `quotaUser`. Methods: `list_calendars()`, `list_events( $calendar_id, array $params )`, `get_event()`, `insert_event()`, `update_event()`, `patch_event()`, `delete_event()`, `quick_add()`, `move_event()`, `instances()`, `freebusy( array $body )`, `watch( … )`, `stop_channel( $id, $resource_id )`. Internals: `request()` with the backoff matrix from §2.6, `classify_error()` returning a normalized reason, `paginate()`, `build_sync_params( string $mode, array $base )` enforcing §2.3. |
| `includes/google/class-wp-mcp-ai-google-calendar-scopes.php` | Scope profile registry (Minimal/Standard/Full), `profile_scopes()`, `has_scope( string $granted, string $needed )`, `profile_requires_verification()`. Single source of truth so base and Pro cannot drift the way Drive did. |

**Constants** (add to `includes/admin/class-wp-mcp-ai-admin-settings.php` beside the Gmail/Drive block at L36–42):

```php
const GOOGLE_CALENDAR_OAUTH_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_CALENDAR_OAUTH_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';
const GOOGLE_CALENDAR_OAUTH_REVOKE_ENDPOINT    = 'https://oauth2.googleapis.com/revoke';
const GOOGLE_CALENDAR_API_BASE                 = 'https://www.googleapis.com/calendar/v3';
const GOOGLE_CALENDAR_DEFAULT_SCOPE_PROFILE    = 'standard';
```

**Deliverable:** unit-testable auth + client with no admin UI. Ship behind no flag; nothing consumes it yet.

---

### Phase 1 — Base plugin: Tools → Connections → Google Calendar

Target URL: `admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=google_calendar`

The base surface is **entirely edits to existing files** — no new registration, container binding, or autoloader entry is needed, because `WP_MCP_AI_Section_Integrations` is already registered (`includes/admin/settings-dashboard-init.php` L152) and its subtab nav is data-driven off `get_subtab_groups()`.

| # | File | Change |
|---|---|---|
| B-1 | `includes/admin/sections/class-wp-mcp-ai-section-integrations.php` → `get_fields()` (after L117) | Add `google_calendar_client_id` (`text`), `google_calendar_client_secret` (`password`), `google_calendar_scope_profile` (`select`, options Minimal/Standard/Full, default `standard`), `google_calendar_default_calendar_id` (`text`, placeholder `primary`), `google_calendar_timezone` (`text`, placeholder from `wp_timezone_string()`) |
| B-2 | same → `get_subtab_groups()` (after L387) | `'google_calendar' => array( 'id' => 'google_calendar', 'label' => __( 'Google Calendar', … ), 'icon' => 'dashicons-calendar-alt', 'fields' => array( … B-1 keys … ) )`. **Array order = tab order**, so place it directly after `google_drive`. |
| B-3 | same → `render_subtab_footer()` (L547) | `case 'google_calendar': $this->render_google_calendar_footer(); break;` |
| B-4 | same → new `render_google_calendar_footer()` | Clone `render_google_drive_footer()` (L775–955). Three-state UI (connected / credentials-saved / no-credentials); Connect + Reconnect + Disconnect buttons; read-only **Authorized Redirect URI** display for `wp_mcp_ai_oauth=google_calendar_callback`; flash params `calendar_success` / `calendar_error`. **New content vs Drive:** a "Publishing status" warning block explaining the 7-day `Testing` refresh-token expiry (§2.8), and a granted-scope readout rendered from `granted_scopes`. |
| B-5 | `includes/admin/class-wp-mcp-ai-admin-settings.php` (L113) | `add_action( 'admin_post_wp_mcp_ai_google_calendar_oauth_start', array( $this->oauth_manager, 'handle_google_calendar_oauth_start' ) );` + `…_disconnect` |
| B-6 | `includes/integrations/class-wp-mcp-ai-oauth-manager.php` (L48 dispatcher) | `elseif ( 'google_calendar_callback' === $handler ) { $this->handle_google_calendar_oauth_callback(); }` |
| B-7 | same, new methods | `handle_google_calendar_oauth_start()`, `handle_google_calendar_oauth_callback()`, `handle_google_calendar_disconnect()`. **Implemented on top of `WP_MCP_AI_Google_OAuth_Service`** (ADR-5), not copy-pasted from Drive. Callback persists `google_calendar_refresh_token`, `google_calendar_user_email`, `google_calendar_granted_scopes`. Disconnect calls `revoke()` then unsets tokens, keeping client id/secret. |
| B-8 | `includes/admin/class-wp-mcp-ai-admin-settings-base.php` → `get_sensitive_fields()` (L409) | Add `google_calendar_client_secret`, `google_calendar_refresh_token` |
| B-9 | same → `get_default_settings()` (L556) | Add the 5 field keys + `google_calendar_refresh_token`, `google_calendar_user_email`, `google_calendar_granted_scopes` as `''`. (Drive omitted these, forcing `isset()` guards everywhere — do it properly for Calendar.) |
| B-10 | `includes/admin/class-wp-mcp-ai-settings-dashboard.php` → `$oauth_credential_token_map` (L755) | `'google_calendar_client_id' => array( 'google_calendar_refresh_token', 'google_calendar_user_email', 'google_calendar_granted_scopes' )` and the same for `…_client_secret`. **This is the only hand-maintained map here** — omitting it leaves stale tokens after a client-secret rotation. Also add `google_calendar_scope_profile` → the same token keys, since changing profile invalidates the grant. |
| B-11 | `includes/admin/class-wp-mcp-ai-admin-settings.php` → `get_connector_definitions()` (~L469) | Add a `google_calendar` entry for readiness reporting (Drive is missing one; fix for Calendar). |

**Save/redirect needs no changes.** `handle_save_settings()` already preserves `subtab=connections` + `connection=<id>` generically (verified at L469–488 and L1097–1100).

**Sanitization needs no central map change.** `sanitize_with_subtabs()` → `sanitize_fields()` whitelists purely by presence in `get_fields()` + the subtab `fields` array, dispatching on `type`. Note `password` fields are **skipped when empty** so a blank input never wipes a stored secret — correct behaviour, keep relying on it.

**Acceptance:** an admin can enter client id/secret, pick a scope profile, click Connect, complete Google consent, land back on the same tab with a green "Connected as …" state and a granted-scope list; Disconnect revokes upstream and clears tokens.

---

### Phase 2 — Pro addon: Remote Sites `google_calendar` connection type

19 edits across 2 files. The Remote Sites system has **no connection-type registry** — types are hardcoded at ~13 sites — so this is mechanical but must be exhaustive.

`addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`:

| # | Location | Change |
|---|---|---|
| P-1 | `handle_actions()` ~L193 | `google_calendar_oauth_connect` dispatch; nonce action **`'google_calendar_oauth_connect_' . $connection_id`** |
| P-2 | ~L206 | `google_calendar_oauth_callback` dispatch (no nonce — `state` provides CSRF) |
| P-3 | `switch ( $connection_type )` ~L479 | `case 'google_calendar':` reading `google_calendar_client_id` (`sanitize_text_field`), `_client_secret` (**unslash only**), `_refresh_token` (**unslash only**), `_user_email` (`sanitize_email`) |
| P-4 | ~L604 | `if ( 'google_calendar' === $connection_type ) { $url = 'https://www.googleapis.com/calendar/v3'; $auth_type = 'none'; }` |
| P-5 | `$connection_data` ~L807 | **New keys** `'calendar_id'`, `'scope_profile'`, `'granted_scopes'`, `'sync_token'`, `'channel_id'`, `'channel_resource_id'`, `'channel_expiration'` (ADR-6 — do **not** reuse `folder_id`) |
| P-6 | `$type_labels` ~L1747 | `'google_calendar' => __( 'Google Calendar', 'mcp-ai-wpoos-pro' )` |
| P-7 | `$type_colors` ~L1778 | `'google_calendar' => '#0b8043'` (Google Calendar green) |
| P-8 | `<select id="connection_type">` ~L2106 | `<option value="google_calendar">Google Calendar (Scheduling)</option>` |
| P-9 | after L3418 | 8 × `<tr class="google_calendar-only-field" style="display: none;">`: client id, client secret, redirect-URI display, refresh token, **calendar id**, **scope profile select**, user email, **granted-scopes readout** |
| P-10 | after L3534 | OAuth connect-button row, guarded by `$is_edit && 'google_calendar' === $connection['connection_type']` |
| P-11 | inline JS `toggleConnectionTypeFields()` | **Three insertions** — the toggle is **not** data-driven: (a) `var googleCalendarFields = document.querySelectorAll('.google_calendar-only-field');` near L7170; (b) a hide-all `forEach` near L7227; (c) an `else if (connectionType === 'google_calendar')` branch near L7385 setting `urlField.value`, `readOnly = true`, grey background, hiding `urlDescription`, and `authTypeSelect.value = 'none'`. **Omitting (c) leaves every row permanently `display:none` with no error.** |
| P-12 | `handle_google_calendar_oauth_start()` (new) | Delegate to `WP_MCP_AI_Google_OAuth_Service`. State transient `wp_mcp_ai_google_calendar_oauth_state_<md5(state)>`, 10 min TTL, payload `{ user_id, connection_id, time }`. Params: `response_type=code`, `access_type=offline`, `include_granted_scopes=true`, `prompt=consent`, `state`, optional `login_hint`. Scopes from the connection's `scope_profile`. Guard: refuse if `client_id`/`client_secret` unsaved. |
| P-13 | `handle_google_calendar_oauth_callback()` (new) | Consume+delete transient, verify `user_id`, exchange code, fall back to the existing `refresh_token` when Google omits one (re-consent case), fetch `userinfo` email, persist via `save_connection()` with `client_secret => ''` **and `_client_secret_encrypted => true`** so the stored secret is preserved without double-encryption. Persist `granted_scopes` from the response. Redirect to `&edit=<id>&oauth_success=…`. |

`addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`:

| # | Location | Change |
|---|---|---|
| P-14 | `save_connection()` allowlist ~L599 | Add `'calendar_id' => sanitize_text_field(…)`, `'scope_profile' => sanitize_key(…)`, `'granted_scopes' => sanitize_text_field(…)`, `'sync_token' => sanitize_text_field(…)`, `'channel_id' => sanitize_text_field(…)`, `'channel_resource_id' => sanitize_text_field(…)`, `'channel_expiration' => absint(…)`. **This is the highest-risk omission in the whole plan** — the rebuild discards any key not listed, silently, with no error. |
| P-15 | preserve-on-empty pass ~L264 | Mirror the `folder_id` block for each new non-credential field so an OAuth round-trip doesn't blank them |
| P-16 | `validate_connection_data()` ~L3222 | `if ( 'google_calendar' === $connection_type )` requiring `client_id` + `client_secret`; error code **`wp_mcp_ai_pro_missing_google_calendar_credentials`**. `refresh_token`, `calendar_id`, `user_email` optional. |
| P-17 | `test_connection()` ~L1126 | Follow the inline-stub convention used by gmail/google_drive/upwork/linkedin: `return array( 'success' => true, 'google_calendar' => true, 'message' => … )`. **Upgrade opportunity:** once Phase 0 lands, make this a real probe — `GET /users/me/calendarList?maxResults=1` when a refresh token exists, stub otherwise. Genuinely more useful than the Drive stub. |
| P-18 | `CREDENTIAL_FIELDS` L49–69 | **No change needed** — `client_secret` and `refresh_token` are already listed, so export/import encryption works automatically. |
| P-19 | `is_restricted_host()` L3270 | **Do not add.** `google_drive` is deliberately absent because the URL is force-set to a Google endpoint, never user-supplied. Same applies here. |

**Acceptance:** create a `google_calendar` connection, save credentials, click Connect, complete consent, see the connection listed with a green badge and a passing test.

---

### Phase 3 — Tool layer

#### 3a. Retrofit `create_google_calendar_event` (highest-value, lowest-effort)

`addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-create-google-calendar-event.php`

1. **Add `connection_id` to `get_parameters_schema()`.** Required, because the root has `additionalProperties => false` — an `execute()`-only change would be rejected by a strict validator.
2. **Insert a credential-resolution block** mirroring `class-wp-mcp-ai-pro-tool-search-drive.php` L127–192: when `connection_id` is present, `WP_MCP_AI_Pro_Remote_Site_Manager::get_connection()`, guard `'google_calendar' === $connection['connection_type']` (error `wp_mcp_ai_calendar_wrong_connection_type`), `decrypt_value()` the secret and refresh token; otherwise fall back to base `google_calendar_*` settings; otherwise fall back to the **existing filters** (ADR-4, backward compatible).
3. **Mint the access token** via `WP_MCP_AI_Google_OAuth_Service::mint_access_token()` when a refresh token is in play; keep `exchange_service_account_token()` for the service-account path.
4. **Enforce granted scopes** before the API call.
5. **Fix the return envelope.** The tool currently returns Google's raw decoded JSON. Per the repo's Unix Theory P0–P6 rule and the `WPMCPAI.Tools.CanonicalReturnEnvelope` sniff (severity 5), wrap it: `array( 'success' => true, 'event_id' => …, 'html_link' => …, 'summary' => …, 'start' => …, 'end' => …, 'calendar_id' => … )`. Add `use WP_MCP_AI_Tool_Chat_Response;` as `WP_MCP_AI_Tool_Search_Drive` does.
6. **Add `conference` support** — optional `create_meet_link` boolean → `conferenceData.createRequest` with a persisted idempotent `requestId` and `conferenceDataVersion=1`.
7. **Default `responseStatus` to `needsAction`** on all attendees (§2.7).
8. Reconcile `get_required_capability()` returning `edit_posts` against `execute()` enforcing `manage_options` — pick one, document it.

#### 3b. Replace the `sync_google_calendar` stub

`addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-google-calendar.php` currently makes no HTTP call. Rewrite it to push an NV oOS appointment to Google via the client, storing the returned `event_id` in `_google_calendar_event_id` and the real status in `_google_calendar_synced`. Keep the `wp_mcp_ai_google_calendar_sync` filter as an override for backward compatibility.

#### 3c. New tools

| Slug | Purpose | Notes |
|---|---|---|
| `list_google_calendars` | `GET /users/me/calendarList` | Read-only, `calendar.calendarlist.readonly` suffices |
| `list_google_calendar_events` | `GET /calendars/{id}/events` | `singleEvents`, `timeMin/Max`, `q`, `maxResults` (default 250, cap 2500), pagination |
| `update_google_calendar_event` | `PUT` (prefer `get`+`update` over `patch` — 3 quota units) | Single-instance vs series vs this-and-following (§2.7) |
| `delete_google_calendar_event` | `DELETE` | Treat `410 deleted` as success |
| `check_google_calendar_availability` | `POST /freeBusy` | Caps: 50 calendars, 100 per group |
| `quick_add_google_calendar_event` | `POST /events/quickAdd` | Natural-language entry — a great AI-assistant fit |

**Registration** (mirror Drive/Gmail, all in existing files):

* `addons/pro/mcp-ai-wpoos-pro.php` ~L666 — class→file map entries.
* `addons/pro/mcp-ai-wpoos-pro.php` ~L2117 — `wp_mcp_ai_pro_tool_group_map()` → `'external-tools'`.
* `includes/toolkit-metadata-mapping.php` ~L698 — `'toolkit' => 'integration_external'`.
* `includes/class-wp-mcp-ai-tool-recommendations.php` ~L271 and `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` ~L159 — add to Calendar/scheduling presets.
* `includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php` ~L332 — `'automation'`.

---

### Phase 4 — Incremental sync engine

New: `includes/google/class-wp-mcp-ai-google-calendar-sync.php` + a custom table or a per-connection option for local event mirror state.

* Per-connection state: `sync_token`, `last_full_sync_at`, `last_incremental_sync_at`, `sync_error_count`.
* **Two distinct parameter sets** (§2.3) — the sync-mode branch is the core of this phase:

```php
// Full sync — date narrowing ALLOWED
$params = array(
    'maxResults'   => 250,
    'singleEvents' => 'true',
    'showDeleted'  => 'true',
    'timeMin'      => $one_year_ago_rfc3339, // legal ONLY here
    'timeZone'     => $iana,
);

// Incremental — timeMin/timeMax/q/orderBy/updatedMin/iCalUID/*ExtendedProperty ⇒ HTTP 400
$params = array(
    'maxResults'   => 250,
    'singleEvents' => 'true',
    'showDeleted'  => 'true',   // must NOT be false with syncToken
    'timeZone'     => $iana,
    'syncToken'    => $stored_token,
);
```

* `410` handling branches on `error.errors[0].reason` (§2.3).
* Cancelled-event handling implements both paths from §2.4.
* Instance rows keyed on `originalStartTime` (§2.7).
* Scheduling via **Action Scheduler** (`as_schedule_recurring_action`), interval randomized ±25%, initial offset seeded from `get_current_blog_id()` so a fleet never syncs in lockstep.

### Phase 5 — Push notifications

* REST route `mcp-ai/v1/google-calendar/webhook` via `register_rest_route()` with a **real** `permission_callback` that validates `X-Goog-Channel-Token` against the stored value and confirms `X-Goog-Channel-ID` exists. **Never `__return_true`** on a state-changing route.
* Handler: dedupe on `(channel_id, X-Goog-Message-Number)` high-water mark, return `200`, enqueue an Action Scheduler incremental-sync job. Tolerate a `sync` message for an uncommitted channel (ADR-7 race) by writing the channel row **before** calling `watch`.
* Renewal job at ~6 days (TTL is 7); new `id` each time; tolerate the overlap window.
* Push-eligibility check per ADR-8; admin notice when ineligible.
* `channels/stop` on disconnect, connection delete, and in `uninstall.php`.

### Phase 6 — Calendar Booking toolkit bridge

Wire the existing `wp_mcp_ai_google_calendar_sync` filter to the real client so `calendar-booking` appointments round-trip:

* Appointment create/update/cancel → Calendar event insert/update/delete with `sendUpdates` from toolkit settings.
* `check_availability` consults `freeBusy` in addition to local slots.
* `_google_calendar_event_id` stored on the appointment for idempotent updates.
* Optional Meet link via 3a item 6, with the `requestId` persisted on the appointment.

---

## 5. Complete file-change matrix

Legend: **N** = new file · **E** = edit

### Base plugin

| File | Type | Phase |
|---|---|---|
| `includes/google/README.md` | N | 0 |
| `includes/google/class-wp-mcp-ai-google-oauth-service.php` | N | 0 |
| `includes/google/class-wp-mcp-ai-google-calendar-client.php` | N | 0 |
| `includes/google/class-wp-mcp-ai-google-calendar-scopes.php` | N | 0 |
| `includes/google/class-wp-mcp-ai-google-calendar-sync.php` | N | 4 |
| `includes/admin/class-wp-mcp-ai-admin-settings.php` | E | 0, 1 |
| `includes/admin/sections/class-wp-mcp-ai-section-integrations.php` | E | 1 |
| `includes/integrations/class-wp-mcp-ai-oauth-manager.php` | E | 1 |
| `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | E | 1 |
| `includes/admin/class-wp-mcp-ai-settings-dashboard.php` | E | 1 |
| `includes/class-wp-mcp-ai-tool-registry.php` | E | 3 |
| `includes/toolkit-metadata-mapping.php` | E | 3 |
| `includes/class-wp-mcp-ai-tool-recommendations.php` | E | 3 |
| `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` | E | 3 |
| `includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php` | E | 3 |
| `includes/class-wp-mcp-ai-rest.php` | E | 5 |
| `uninstall.php` | E | 5 |

### Pro addon

| File | Type | Phase |
|---|---|---|
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` | E (13 sites) | 2 |
| `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` | E (4 sites) | 2 |
| `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-create-google-calendar-event.php` | E | 3a |
| `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-google-calendars.php` | N | 3c |
| `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-google-calendar-events.php` | N | 3c |
| `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-update-google-calendar-event.php` | N | 3c |
| `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-delete-google-calendar-event.php` | N | 3c |
| `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-check-google-calendar-availability.php` | N | 3c |
| `addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-quick-add-google-calendar-event.php` | N | 3c |
| `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-google-calendar.php` | E (rewrite) | 3b, 6 |
| `addons/pro/mcp-ai-wpoos-pro.php` | E (2 sites) | 3 |

### Docs & tests

| File | Type | Phase |
|---|---|---|
| `docs/developer/architecture/integrations/google-calendar-connection.md` | N | 1 |
| `docs/reference/google-calendar-api-v3.md` | N | 0 |
| `addons/pro/includes/admin/README-REMOTE-CONNECTIONS.md` | E (3 tables + type count in intro) | 2 |
| `addons/pro/includes/tools/google-workspace/README.md` | E (tool table) | 3 |
| `docs/tool-reference.md` | E | 3 |
| `docs/REMOTE_CONNECTIONS_GUIDE.md` | E | 2 |
| `tests/test-google-calendar-oauth-service.php` | N | 0 |
| `tests/test-google-calendar-client.php` | N | 0 |
| `tests/test-google-calendar-sync-params.php` | N | 4 |
| `tests/test-google-calendar-settings-subtab.php` | N | 1 |
| `tests/test-oauth-redirect-uri-consistency.php` | E | 1, 2 |
| `tests/test-remote-sites-oauth-redirect-uri.php` | E | 2 |
| `addons/pro/tests/test-remote-sites-admin.php` | E | 2 |
| `addons/pro/tests/tools/test-google-calendar-tools.php` | N | 3 |

---

## 6. Data model

### Base settings (`wp_mcp_ai_settings`)

| Key | Type | Sensitive |
|---|---|---|
| `google_calendar_client_id` | string | no |
| `google_calendar_client_secret` | string | **yes** |
| `google_calendar_refresh_token` | string | **yes** |
| `google_calendar_user_email` | email | no |
| `google_calendar_granted_scopes` | space-delimited string | no |
| `google_calendar_scope_profile` | `minimal`\|`standard`\|`full` | no |
| `google_calendar_default_calendar_id` | string (default `primary`) | no |
| `google_calendar_timezone` | IANA name | no |

### Pro connection (`wp_mcp_ai_pro_remote_sites[<id>]`)

Reused: `id`, `name`, `url` (`https://www.googleapis.com/calendar/v3`), `connection_type` (`google_calendar`), `auth_type` (`none`), `client_id`, `client_secret` 🔒, `refresh_token` 🔒, `user_email`, `enabled`, `created`, `updated`.

New: `calendar_id`, `scope_profile`, `granted_scopes`, `sync_token`, `channel_id`, `channel_resource_id`, `channel_expiration`.

🔒 = auto-encrypted (already in `CREDENTIAL_FIELDS`).

### Transients

| Key | TTL | Purpose |
|---|---|---|
| `wp_mcp_ai_google_calendar_oauth_state_<md5(state)>` | 10 min | CSRF state, single-use |
| `wp_mcp_ai_gcal_access_token_<hash>` | `expires_in − 300` | Access-token cache |
| `wp_mcp_ai_gcal_msgnum_<channel_id>` | 8 days | Push dedupe high-water mark |

---

## 7. Security checklist

Aligned with `.context/security-checklist.md` and the `wp-security-audit` / `wp-rest-api` skills.

* [ ] **Nonces** — `google_calendar_oauth_connect_<connection_id>` (Pro), `wp_mcp_ai_google_calendar_oauth_start` / `…_disconnect` (base). OAuth *callbacks* legitimately use the `state` param instead of a nonce; keep the `phpcs:ignore WordPress.Security.NonceVerification.Recommended` with the existing justification comment.
* [ ] **Capabilities** — `current_user_can( 'manage_options' )` on every admin handler; `user_can( $user_id, $cap )` inside every tool `execute()`.
* [ ] **State** — `wp_generate_uuid4()`, transient bound to `get_current_user_id()`, **deleted before validation** (single-use, replay-proof).
* [ ] **Redirect URI byte-equality** — the URI sent to the authorize endpoint and the one sent in the token exchange must be constructed by the same helper. Existing tests `tests/test-oauth-redirect-uri-consistency.php` and `tests/test-remote-sites-oauth-redirect-uri.php` must be extended to cover Calendar.
* [ ] **Secrets** — `client_secret` / `refresh_token` are `wp_unslash()`-only (never `sanitize_text_field`, which corrupts them), with the required `phpcs:ignore` comment; encrypted at rest by `CREDENTIAL_FIELDS`; added to `get_sensitive_fields()` so the admin UI masks them; never logged.
* [ ] **REST webhook** — real `permission_callback` validating `X-Goog-Channel-Token` + known `X-Goog-Channel-ID`. **Never `__return_true`.**
* [ ] **SSRF** — `url` is force-set to the Google endpoint and rendered read-only; `google_calendar` stays **out** of `is_restricted_host()`'s `$enforced_types` (ADR-6 rationale).
* [ ] **Output escaping** — every value in the new admin rows through `esc_attr()` / `esc_html()` / `esc_url()`; two-gate rule (sanitize at entry, escape at exit).
* [ ] **Token-invalidation map** — B-10, so a rotated client secret cannot leave a live-looking stale token.
* [ ] **100-token guard** — the OAuth-start endpoint must not be reachable from a settings save; only from an explicit Connect click.
* [ ] **Uninstall** — `channels/stop` for every live channel, then `POST /revoke` for every stored token, then delete rows.
* [ ] **i18n** — every string wrapped with the right text domain (`mcp-ai-wpoos` base, `mcp-ai-wpoos-pro` Pro); translator comments on all `sprintf()` placeholders. Verify with the `wp-i18n-audit` skill.

---

## 8. Testing plan

### Unit (no network)

* `build_sync_params()` rejects each of the 8 forbidden params in incremental mode and permits `timeMin` in full mode.
* `classify_error()` maps `410 fullSyncRequired` → resync, `410 deleted` → success, `403 rateLimitExceeded` → retry, `403 forbiddenForNonOrganizer` → no-retry.
* Backoff produces `min(2^n + jitter, 32s)` with jitter recomputed per attempt.
* All-day `end.date` exclusivity: a single-day event on `2026-06-01` serializes `end.date = "2026-06-02"`.
* Cancelled-event router: `recurringEventId` present → keep; absent → delete.
* `has_scope()` correctly rejects a Standard-profile action when only Minimal scopes were granted.
* Scope-profile registry returns the exact documented strings — assert `calendar.acls` (plural) to lock in the correction from §2.1.

### Integration (mocked HTTP via `pre_http_request`)

* Full OAuth round-trip on both surfaces: start → state stored → callback → token persisted → `granted_scopes` recorded.
* Callback with `error=access_denied` → user-facing error, no partial write.
* Callback where Google omits `refresh_token` → existing token preserved.
* Redirect-URI byte-equality between start and exchange.
* `save_connection()` round-trip proves `calendar_id` and the six new keys survive (guards against the P-14 allowlist trap).
* Paginated full sync ending in `nextSyncToken`; then incremental sync; then a `410 fullSyncRequired` triggering a wipe + resync.
* Webhook: valid token → `200` + job enqueued; wrong token → `403`; duplicate `X-Goog-Message-Number` → ignored; `sync` state for an unknown channel → tolerated.

### Manual QA

1. Google Cloud project in **`Testing`** status → confirm the 7-day-expiry warning renders.
2. Granular consent: untick a scope → confirm the tool returns a named-scope error, not a raw `403`.
3. Recurring event: edit one instance, then the whole series, then this-and-following.
4. All-day event across a DST boundary in a non-UTC calendar.
5. Meet link creation → confirm the poller waits for `statusCode: success`.
6. Local site over `http://` → confirm push is disabled with an explanatory notice, and polling still works.

### CI gates

```bash
composer run lint                    # WPCS + the two custom tool sniffs
composer run lint:compat             # PHP 7.4–8.3
composer run test                    # PHPUnit
composer run docs:check-folder-readmes   # requires includes/google/README.md
npm run lint:js
composer run ci:all
```

---

## 9. Docs & CI obligations

* **`includes/google/README.md` is mandatory.** `bin/check-folder-readmes.php` fails `ci:all` for any base folder under `includes/` containing PHP without a `README.md`. Follow `docs/developer/folder-readme-convention.md`: purpose, public surface, neighbours, and the `.context/*.md` files to load.
  *(Note: `ci:all` runs `--scope=base` only, so `addons/pro/includes/**` READMEs are checked solely by `docs:check-folder-readmes:all`. Update the Pro READMEs anyway — the Pro tools folder already has one and drift is what produced the gaps found in this audit.)*
* `README-REMOTE-CONNECTIONS.md` — add `google_calendar` to the Connection Type Registry table, add a Credential Fields section, add a tool-to-connection mapping row, and bump the "all 24 remote connection types" count in the intro.
* Save the Phase-0 research as `docs/reference/google-calendar-api-v3.md` so the scope tiers, forbidden sync params, and error table are discoverable at implementation time.

---

## 10. Risks & known traps

Ordered by likelihood × blast radius.

| # | Risk | Mitigation |
|---|---|---|
| 1 | **P-14 omission** — a new connection field is not added to the `save_connection()` allowlist. The rebuild discards it **silently, with no error**, and the field appears to "not save". | Integration test asserting every new key survives a save round-trip. |
| 2 | **P-11(c) omission** — no JS show-branch. Rows are inline `style="display:none"`, so the form renders completely empty with no diagnostic. | Manual QA step 1 on a fresh connection; consider a follow-up ticket to make the toggle data-driven off a PHP-emitted type→class map. |
| 3 | **`timeMin` sent with `syncToken`** → `HTTP 400`, sync silently stops. | `build_sync_params()` branches on mode; unit test per forbidden param. |
| 4 | **Testing-status 7-day token expiry** — connection "randomly" breaks weekly. Highest predicted support volume. | Prominent admin warning (B-4); a health check that surfaces `invalid_grant` as "reconnect required". |
| 5 | **All-day off-by-one** from exclusive `end.date`. | Dedicated unit test; helper that always adds one day. |
| 6 | **Cancelled recurring exceptions deleted locally** → the occurrence reappears on every sync. | Two-path router (§2.4) + test. |
| 7 | **100-refresh-token limit** silently invalidating the working token on a multisite or re-authorizing plugin. | One token per (site, account); OAuth start only from an explicit Connect click. |
| 8 | **`folder_id` collision** — a Calendar save clobbers a Drive connection's folder scope. | ADR-6: dedicated `calendar_id`. |
| 9 | **Canonical-envelope sniff failure** on the retrofitted tool (`WPMCPAI.Tools.CanonicalReturnEnvelope`, severity 5). | 3a item 5 fixes the existing raw-JSON return. |
| 10 | **Push silently dead** behind self-signed TLS or a `robots.txt` blocking `/wp-json/`. | ADR-8 eligibility check + admin notice. |
| 11 | **Sync-message race** — `sync` arrives before the `watch` response. | Write the channel row before calling `watch`; tolerate unknown IDs. |
| 12 | **Scope drift** between base and Pro, exactly as happened for Drive (`drive.file drive.readonly` vs `drive.readonly drive.metadata.readonly`). | Single `WP_MCP_AI_Google_Calendar_Scopes` registry (Phase 0) consumed by both surfaces. |
| 13 | **Quota exhaustion** from midnight/lockstep syncing. | ±25% jitter, blog-id-seeded offset, `quotaUser` under DWD. |

---

## 11. Effort estimate

| Phase | Scope | Estimate |
|---|---|---|
| 0 | Shared OAuth service, Calendar client, scope registry, README, unit tests | 3–4 d |
| 1 | Base Tools → Connections tab + OAuth handlers | 2–3 d |
| 2 | Pro Remote Sites connection type (19 edits) + tests | 2–3 d |
| 3a | Retrofit `create_google_calendar_event` (incl. envelope fix) | 1–2 d |
| 3b | Replace `sync_google_calendar` stub | 1 d |
| 3c | 6 new tools + registration | 3–4 d |
| 4 | Incremental sync engine | 4–5 d |
| 5 | Push notifications + renewal + eligibility | 4–5 d |
| 6 | Calendar Booking bridge | 2–3 d |
| — | Docs, CI, manual QA | 2–3 d |

**Minimum shippable feature (Phases 0–3a): ~8–12 days.** Full plan: ~24–33 days.

**Recommended first PR:** Phases 0 + 1. It is self-contained, adds no Pro dependency, is fully testable, and delivers a visible working connection — while establishing the shared layer that Phases 2–6 all build on.

---

## 12. Citations

Google Workspace Calendar API

1. Choose Calendar API scopes — https://developers.google.com/workspace/calendar/api/auth
2. Calendars and events (concepts) — https://developers.google.com/workspace/calendar/api/concepts/events-calendars
3. Recurring events — https://developers.google.com/workspace/calendar/api/guides/recurringevents
4. Synchronize resources efficiently — https://developers.google.com/workspace/calendar/api/guides/sync
5. Get push notifications — https://developers.google.com/workspace/calendar/api/guides/push
6. Usage limits — https://developers.google.com/workspace/calendar/api/guides/quota
7. Handle API errors — https://developers.google.com/workspace/calendar/api/guides/errors
8. API reference index — https://developers.google.com/workspace/calendar/api/v3/reference
9. Events resource — https://developers.google.com/workspace/calendar/api/v3/reference/events
10. `events.list` — https://developers.google.com/workspace/calendar/api/v3/reference/events/list
11. `events.insert` — https://developers.google.com/workspace/calendar/api/v3/reference/events/insert
12. `events.watch` — https://developers.google.com/workspace/calendar/api/v3/reference/events/watch
13. `freebusy.query` — https://developers.google.com/workspace/calendar/api/v3/reference/freebusy/query

Google Identity / OAuth 2.0

14. OAuth 2.0 scopes for Google APIs — https://developers.google.com/identity/protocols/oauth2/scopes
15. Using OAuth 2.0 to access Google APIs — https://developers.google.com/identity/protocols/oauth2
16. OAuth 2.0 for web server applications — https://developers.google.com/identity/protocols/oauth2/web-server
17. OAuth 2.0 for server-to-server applications — https://developers.google.com/identity/protocols/oauth2/service-account
18. Handling granular permissions — https://developers.google.com/identity/protocols/oauth2/resources/granular-permissions
19. Sensitive scope verification — https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification
20. Restricted scope verification — https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification
21. API Services User Data Policy — https://developers.google.com/terms/api-services-user-data-policy

Corroborating

22. Drive API push notifications (parallel implementation, TLS rules) — https://developers.google.com/workspace/drive/api/guides/push
23. OAuth app verification (Console scope labelling) — https://support.google.com/cloud/answer/9110914
