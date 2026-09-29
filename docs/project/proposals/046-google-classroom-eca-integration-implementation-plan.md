# Implementation Plan 046 — Google Classroom Integration for the ECA Pro Toolkit

**Status:** ✅ Implemented — Phases 0–5 complete (foundation, connection, tools, sync/push, tests, docs). PHPCS: 0 errors on all changed files. Suites: 27 + 11 + 6 tests green. Remaining: PR + changelog version bump, live OAuth smoke test against a real Google Cloud project.
**Date:** 2026-09-29
**Proposal:** [`046-google-classroom-eca-integration.md`](./046-google-classroom-eca-integration.md)

## Design decisions (locked)

1. **Build on the shared Google stack.** All Classroom OAuth state, redirect-URI
   construction, token minting, and caching flow through
   `WP_MCP_AI_Google_OAuth_Service` — no fifth copy of the OAuth start/callback
   pair (`includes/google/README.md` explicitly forbids it). Scope strings are
   written **only** in `WP_MCP_AI_Google_Classroom_Scopes`.
2. **Base foundation, Pro consumers.** The four foundation classes (Scopes,
   Client, Credentials, Push) live in `includes/google/` next to the Calendar set
   and are loaded from `includes/bootstrap/loader.php`. They depend on nothing
   Pro-only at class level; credential resolution requires a Pro Remote Sites
   `google_classroom` connection (same graceful pattern as Calendar's
   `resolve_from_connection()`).
3. **Three tiered scope profiles — `readonly`, `write`, `push`.** Classroom
   scopes are all *restricted*; the primary deployment model is an internal
   school GCP project (no Google review). Every tool gates on
   `require_scope()` because granular consent can grant a subset.
4. **Per-user OAuth only for push.** `registrations.create()` fails with
   `@MissingGrant` under domain-wide delegation (official docs), so the watcher
   uses the connection's own OAuth grant. Registrations expire weekly and are
   renewed daily via a cron hook; no push without HTTPS + public host
   (`is_push_eligible()` mirrors Calendar).
5. **Classroom is the companion surface; ECA CPTs stay the system of record.**
   Mapping metadata (`_eca_google_course_id`, `_student_google_id`,
   `_student_google_email`, `_eca_classroom_sync`) lives on the ECA/student
   records; Classroom-specific fields are never reverse-imported into
   ECA-specific meta.
6. **Feature flag + capability gates.** Every tool checks
   `enable_eca_classroom_integration` in `is_available()`; read tools require
   `edit_posts`, write/push tools require `manage_options` at execute time (ECA
   tool precedent). Canonical envelope + two-gate sanitisation on every tool.
7. **Identity matching:** Google `userId` (`courses.students.list`) is primary,
   email fallback via `classroom.profile.emails`. MIS (iSAMS/SOCS) wins on
   conflict unless `override_mis` is set.
8. **Quota discipline:** `pageSize ≤ 100`, page-token pagination, exponential
   backoff with jitter on `RESOURCE_EXHAUSTED` (client-level retry mirrors
   Calendar), quotaUser attribution from `user_email`, push over polling.
9. **Webhook is ack-fast, work-later.** The Pub/Sub receiver returns 200
   immediately and defers the API read + delta application to a background
   single cron event — never inline API calls inside the webhook request.

## Phase 0 — Base foundation (`includes/google/`)

### 0.1 Changed files

| File | Change |
|---|---|
| `includes/google/class-wp-mcp-ai-google-classroom-scopes.php` (new) | `WP_MCP_AI_Google_Classroom_Scopes`: all Classroom scope constants; `readonly` (courses.readonly, rosters.readonly, profile.emails, profile.photos), `write` (adds courses, rosters, announcements, coursework.students, student-submissions.students.readonly, topics, courseworkmaterials), `push` (readonly + push-notifications) profiles; `normalise_profile()`, `get_profile_scopes()`, `get_profile_scope_string()`, labels/descriptions/options, `parse_granted()`, `get_implied_by()`, `has_scope()`, `missing_scope_error()` — the Calendar registry shape, `requires_verification => true` on all profiles. |
| `includes/google/class-wp-mcp-ai-google-classroom-client.php` (new) | `WP_MCP_AI_Google_Classroom_Client` over the existing HTTP layer: base `https://classroom.googleapis.com/v1`; endpoints for courses (list/get/create/patch/delete), students (list/get/delete), announcements (create/list), courseWork (create/list), studentSubmissions (list), registrations (create/delete/list), guardians (list), userProfiles (get); `paginate()` page-token loop; `request()` with retry/backoff; `interpret_response()` branching on `error.errors[0].reason` — `RESOURCE_EXHAUSTED` → retryable, `@MissingGrant` / `notFound` / `failedPrecondition` → stable codes; `is_auth_failure()`. |
| `includes/google/class-wp-mcp-ai-google-classroom-credentials.php` (new) | `WP_MCP_AI_Google_Classroom_Credentials`: `resolve( $connection_id )` via `WP_MCP_AI_Pro_Remote_Site_Manager` (type `google_classroom`, decrypt-on-read, enabled check, completeness check), `make_client()` with lazy `mint_access_token()` provider + quotaUser attribution, `require_scope()`, `resolve_default_course_id()`, `CONNECTION_TYPE = 'google_classroom'`. |
| `includes/google/class-wp-mcp-ai-google-classroom-push.php` (new) | `WP_MCP_AI_Google_Classroom_Push`: REST route `mcp-ai/v1/google-classroom/webhook` (Pub/Sub push, shared-secret token verification — state-changing route, never `__return_true`); registration store (option `wp_mcp_ai_google_classroom_registrations`); `register_for_course()` / `unregister_for_course()` / `unregister_all_for_connection()`; `renew_expiring_registrations()` daily cron; `is_push_eligible()`; notification handling → schedules `wp_mcp_ai_google_classroom_notification` single event (ack-fast). |
| `includes/google/google-classroom-init.php` (new) | Loads the four classes, registers the renewal cron hook + the push receiver, gates scheduling on `wp_mcp_ai_google_classroom_has_registrations()`. |
| `includes/bootstrap/loader.php` | `require_once` the Classroom init directly after the Calendar init line. |
| `includes/google/README.md` | Add Classroom rows to Purpose, Public Surface, and Conventions; note that Classroom scopes are restricted. |

### 0.2 Tests

| File | Coverage |
|---|---|
| `tests/test-google-classroom-foundation.php` (new) | Scope-profile implication under granular consent (`has_scope()` true/false incl. implied broader scopes); profile switch never leaks write scopes; `parse_granted()` normalises `%20`; client error-reason branching (`RESOURCE_EXHAUSTED` retryable, `@MissingGrant` terminal + actionable, 404 stable code, transport error retryable); pagination accumulates pageTokens; credentials reject wrong type / disabled / incomplete connections; push registration renewal-window maths and webhook token verification (bad token → WP_Error, missing token → 401). |

## Phase 1 — Pro connection type

### 1.1 Changed files

| File | Change |
|---|---|
| `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` | `validate_connection_data()`: `google_classroom` case (client_id + client_secret required); `test_connection()`: dispatch to new `test_google_classroom_connection()` (real `courses.list` pageSize-1 probe once a refresh token exists, `needs_reconnect` flag on auth failure); `save_connection()`: add `classroom_course_id` + `classroom_sync_enabled` to the field list (scope_profile already generic). |
| `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` | `handle_actions()`: `google_classroom_oauth_connect` / `google_classroom_oauth_callback` dispatch; POST capture case; URL/`auth_type` case (`https://classroom.googleapis.com/v1`, `none`); type label `Google Classroom` + colour; `<option>` in the type select; edit-form field block (client id/secret, authorized redirect URI via `build_remote_redirect_uri('google_classroom_oauth_callback')`, scope-profile select, refresh token textarea, default course ID, user email, granted-scopes readout); `toggleConnectionTypeFields()` JS case; new `handle_google_classroom_oauth_start()` / `handle_google_classroom_oauth_callback()` mirroring Calendar byte-for-byte except service key `google_classroom_remote` and profile scopes. |

## Phase 2 — Pro tools (`addons/pro/includes/tools/eca-management/`)

12 new tool files + registration. Every tool implements
`WP_MCP_AI_Tool_Interface`, `WP_MCP_AI_Tool_Capability_Flags_Interface`,
`WP_MCP_AI_Tool_Usage_Guidance_Interface`; carries
`profession_tags => [school_admin, it_admin]`, `toolkit => education`,
`post_type => mcp_ai_eca`, risk level `standard` (write tools `elevated`).

| # | Tool class / slug | Params (beyond `connection_id`) | Gate |
|---|---|---|---|
| 1 | `WP_MCP_AI_Tool_List_Classroom_Courses` / `list_classroom_courses` | `course_states`, `teacher_id`, `page_size` | edit_posts, readonly profile |
| 2 | `WP_MCP_AI_Tool_Sync_Classroom_Roster_To_Students` / `sync_classroom_roster_to_students` | `course_id`, `dry_run`, `override_mis` | manage_options, rosters.readonly |
| 3 | `WP_MCP_AI_Tool_Sync_Classroom_Courses_To_ECAs` / `sync_classroom_courses_to_ecas` | `dry_run`, `update_existing`, `page_size` | manage_options, courses.readonly |
| 4 | `WP_MCP_AI_Tool_Link_Classroom_Course_To_ECA` / `link_classroom_course_to_eca` | `eca_id`, `course_id`, `sync_direction` | manage_options, courses.readonly |
| 5 | `WP_MCP_AI_Tool_Create_Classroom_Course` / `create_classroom_course` | `name`, `section`, `room`, `description`, `link_eca_id` | manage_options, courses |
| 6 | `WP_MCP_AI_Tool_Update_Classroom_Course` / `update_classroom_course` | `course_id`, `name`, `section`, `room`, `course_state` | manage_options, courses |
| 7 | `WP_MCP_AI_Tool_Post_Classroom_Announcement` / `post_classroom_announcement` | `course_id`, `text`, `materials` | manage_options, announcements |
| 8 | `WP_MCP_AI_Tool_Create_Classroom_Coursework` / `create_classroom_coursework` | `course_id`, `title`, `description`, `work_type`, `max_points`, `due_date`, `topic_id` | manage_options, coursework.students |
| 9 | `WP_MCP_AI_Tool_List_Classroom_Submissions` / `list_classroom_submissions` | `course_id`, `course_work_id`, `states`, `include_attendance_crossref` | edit_posts, submissions read |
| 10 | `WP_MCP_AI_Tool_Classroom_Course_Analytics` / `classroom_course_analytics` | `course_id` (or all linked), `include_submissions` | edit_posts, read-only set |
| 11 | `WP_MCP_AI_Tool_List_Classroom_Guardians` / `list_classroom_guardians` | `course_id`, `student_id` | manage_options, guardianlinks.students.readonly |
| 12 | `WP_MCP_AI_Tool_Manage_Classroom_Push_Watch` / `manage_classroom_push_watch` | `action` (register|renew|unregister|status), `course_id` | manage_options, push profile |

### 2.1 Registration

| File | Change |
|---|---|
| `addons/pro/mcp-ai-wpoos-pro.php` | Add the 12 class→path entries to `wp_mcp_ai_pro_register_tools()` (ECA block, gated on `enable_eca_management` + `enable_eca_classroom_integration`) and the 12 slug→group entries to `wp_mcp_ai_pro_tool_group_map` (`wordpress-core`). |
| `addons/pro/includes/admin/class-wp-mcp-ai-eca-settings-page.php` | `get_tools_list()`: 12 new labels under an "Integration & Sync — Google Classroom" comment; overview tab bullet; settings field block for `enable_eca_classroom_integration` + `eca_classroom_default_course_id` + `eca_classroom_sync_interval`. |
| `addons/pro/includes/class-wp-mcp-ai-pro-cpt-meta-schema.php` | Education section: `_student_google_id`, `_student_google_email` on the student schema; `_eca_google_course_id`, `_eca_classroom_sync` on the ECA schema. |
| `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-eca-mcp-server.php` | Append the 12 slugs to `candidate_tool_slugs()`. |

## Phase 3 — Sync engine & push wiring (Pro)

### 3.1 Changed files

| File | Change |
|---|---|
| `addons/pro/includes/eca/class-wp-mcp-ai-eca-classroom-sync.php` (new) | `WP_MCP_AI_ECA_Classroom_Sync`: `sync_roster( $connection_id, $course_id, $dry_run )` (students.list → upsert `mcp_ai_student` by `_student_google_id`, email fallback, MIS-wins, per-connection sync-state option); `sync_courses( $connection_id, $dry_run )` (courses.list → upsert/link `mcp_ai_eca` by `_eca_google_course_id`); `apply_notification( $payload )` (collection → targeted delta: roster CREATED → upsert student + enrolment; DELETED → withdraw; courseWork changes → log + assistant notify hook); `schedule_daily_reconcile()` with jittered interval; cron hooks `wp_mcp_ai_eca_classroom_reconcile` / `wp_mcp_ai_google_classroom_notification`. |
| `addons/pro/includes/eca/init.php` | Load the sync class file (after the DB accessors). |
| `addons/pro/includes/eca/README.md` | Add the sync class to the public surface + inputs/outputs. |

## Phase 4 — Tests (Pro)

| File | Coverage |
|---|---|
| `addons/pro/tests/test-eca-classroom-tools.php` (new) | Schema shape for all 12 tools (`connection_id` + required params present); `is_available()` false without the feature flag / ECA disabled / Pro absent; capability gates (subscriber blocked on read + write; admin passes); two-gate sanitisation spot checks; dry-run paths produce zero writes; link tool persists both meta keys; analytics tool aggregates without network (mocked client). |
| `addons/pro/tests/test-eca-classroom-sync.php` (new) | Roster upsert idempotency (double run, no duplicates); email-fallback match; MIS-wins conflict; notification delta (CREATED student → enrolment row; DELETED → withdraw) with mocked client; schedule registration gated on connections. |

## Phase 5 — Docs & release

- `addons/pro/README.md`: ECA Management System entry gains the Classroom line.
- `docs/tool-reference.md`: 12 new tool entries.
- `CHANGELOG.md`: unreleased-section entries per PR cluster.
- `tool-status.txt` / coverage manifest entries for the 12 slugs.

## Validation gates (run at each phase)

1. `php -l` on every new/changed PHP file.
2. `composer run lint` (PHPCS, `vendor/bin/phpcs`) on the changed files only.
3. `vendor/bin/phpunit tests/test-google-classroom-foundation.php` (Phase 0) and the two Pro suites (Phase 4) — via `docker compose exec` test environment where local PHP lacks the test install.
4. Grep audit: no inline Classroom scope strings outside `Scopes`; no `__return_true` permission callback on the webhook route; every new tool slug present in the tool group map, settings `get_tools_list()`, MCP server candidates, and `tool-status.txt`.
5. Manual smoke (Docker site): create a `google_classroom` connection → save client id/secret → complete OAuth → `list_classroom_courses` → link a course → roster sync → `manage_classroom_push_watch status`.
