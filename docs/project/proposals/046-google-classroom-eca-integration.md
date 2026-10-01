# Proposal 046 — Google Classroom Integration for the ECA Pro Toolkit

**Status:** 🚧 Implemented — foundation + connection + 12 tools + sync/push + tests landed; release changelog pending (no PR opened yet)
**Date:** 2026-09-29
**Scope:** Base plugin (`includes/google/` — shared foundation only) + Pro addon (`addons/pro/` — connection type, tools, sync, push)
**Related:** [`includes/google/README.md`](../../../includes/google/README.md) (shared Google OAuth infrastructure); [`addons/pro/includes/eca/README.md`](../../../addons/pro/includes/eca/README.md); [`addons/pro/includes/tools/eca-management/README.md`](../../../addons/pro/includes/tools/eca-management/README.md); ECA toolkit history (PR #4568, 24-tool ECA wave; PR #5423 ECA document generation); iSAMS/SOCS integration tools (`sync_ecas_from_isams`, `isams_query`)

## Summary

The ECA (Extra-Curricular Activities) Pro Toolkit manages activities, students,
enrolments, attendance, scheduling, notifications, and reporting through 35 tools,
the `mcp_ai_eca` / `mcp_ai_student` CPTs, and two custom tables (enrolments,
attendance). Its only external integrations today are **iSAMS and SOCS** — both
school *MIS* systems. Most schools in the toolkit's target market run **Google
Classroom** as their day-to-day learning platform: courses, rosters, announcements,
assignments, and guardian links all live there, and ECA coordinators already have
Classroom open all day.

This proposal adds a **Google Classroom integration tier** to the ECA toolkit:

1. **Connection layer** — a `google_classroom` Remote Sites connection type built on
   the existing shared OAuth service in `includes/google/` (no fifth copy of the
   OAuth flow — the folder README explicitly forbids it).
2. **12 new ECA tools** covering courses, rosters, announcements, coursework,
   submissions, guardians, and push registration, all following the canonical
   envelope + two-gate sanitisation rules.
3. **Bi-directional sync** — Classroom rosters → `mcp_ai_student` records and
   Classroom courses ↔ `mcp_ai_eca` records, with identity matching by Google
   `userId` (fallback: email).
4. **Push-notification watcher** — Pub/Sub registrations for
   `COURSE_ROSTER_CHANGES` and `COURSE_WORK_CHANGES` feeds with weekly-renewal
   via Action Scheduler, mirroring the existing Calendar push class.
5. **Automation presets** — a nightly "Classroom ↔ ECA reconcile" schedule preset and
   a Workflow Builder blueprint.

Everything is opt-in behind `enable_eca_classroom_integration`, default-off, and
obeys the existing conventions (granular-consent scope gating, jittered sync,
`manage_options` gates, coverage manifest). Google Classroom is treated as the
**companion surface**; the ECA CPTs remain the system of record for ECA-specific
fields (venue, cost, capacity, term) that Classroom does not model.

## Motivation / Gap Analysis

The ECA toolkit is the education flagship of the Pro addon, but its data model ends
where the school day begins. Concrete gaps:

- **Rosters are duplicated by hand.** A club roster exists in Classroom (course
  members), in the MIS (iSAMS/SOCS), and in `mcp_ai_student` enrolments. The iSAMS
  sync tools cover MIS → ECA, but schools without heavy MIS usage (or where
  teachers provision courses themselves) keep the Classroom roster as the live
  source. There is no path from Classroom into the ECA toolkit today.
- **No reach into the stream.** `send_eca_notification` and
  `send_eca_parent_report` generate messages, but nothing can post an
  announcement into the Classroom stream where students actually see it.
- **Attendance vs coursework blind spot.** The attendance engine tracks sessions,
  but cannot cross-reference Classroom assignments/submissions (e.g. "did the
  students who missed rehearsal also miss the permission-slip form?").
- **The Google layer already exists.** `includes/google/` ships a hardened OAuth
  service (single-use state, token caching, redirect-URI byte-equality,
  granular-consent gating) and the Calendar integration proved the whole pattern
  end-to-end: shared credentials classes → Remote Sites connection type → tools →
  sync engine → push webhook. Google Classroom is the same shape of problem with
  the same reusable foundation, and the folder README directs all new Google
  integrations to build on it.
- **iSAMS/SOCS are MIS-side; Classroom is LMS-side.** Together the three cover the
  full school data picture (identity + timetables from MIS, daily learning surface
  from LMS, ECA-specific ops from the toolkit). This proposal deliberately does
  **not** replace iSAMS/SOCS — it complements them.

## Research & Industry Standards

### 1. Classroom API surface (REST v1)

The API is a standard REST surface (`classroom.googleapis.com/v1`) with JSON
payloads, OAuth 2.0 bearer auth, and page tokens for pagination. Key collections:
`courses`, `courses.students`, `courses.teachers`, `courses.aliases`,
`courses.announcements`, `courses.courseWork`, `courses.courseWork.studentSubmissions`,
`courses.courseWorkMaterials`, `courses.topics`, `userProfiles`, `registrations`,
`invitations`, `guardians` / `guardianInvitations`.

Relevant scopes (verbatim from the official auth guide):

| Scope | Grants | ECA tool tier |
|---|---|---|
| `classroom.courses.readonly` | View classes | Read-only profile |
| `classroom.courses` | Create/edit/delete classes | Write profile |
| `classroom.rosters.readonly` | View rosters | Read-only profile |
| `classroom.rosters` | Manage rosters | Write profile |
| `classroom.profile.emails` | View member emails | Required for identity matching |
| `classroom.profile.photos` | View profile photos | Optional (reports) |
| `classroom.announcements` | Manage announcements | Write profile |
| `classroom.coursework.students.readonly` | View coursework + grades for taught/admin classes | Read-only profile |
| `classroom.coursework.students` | Manage coursework + grades | Write profile |
| `classroom.student-submissions.students.readonly` | View submissions | Read-only profile |
| `classroom.courseworkmaterials` | Manage classwork materials | Write profile |
| `classroom.topics` | Manage topics | Write profile |
| `classroom.guardianlinks.students.readonly` | View guardians | Parent-report profile (opt-in) |
| `classroom.push-notifications` | Receive change notifications | Push watcher |

**Design principle P1 — narrowest scopes, tiered profiles.** Google explicitly
recommends requesting only what the app needs; users grant *more readily* to
limited scope sets. The connection will offer three opt-in profiles (read-only,
write, push) instead of one maximal set. This mirrors the Calendar `Scopes` class
pattern and is enforced at runtime.

### 2. Quotas & rate limits (official limits page, updated 2026-09-03)

| Limit | Value |
|---|---|
| Queries/day/client | 4,000,000 (~46 QPS avg) |
| Queries/min/client | 3,000 (50 QPS) |
| Queries/min/user | 1,200 (**20 QPS**) |

- Quota is checked on a **60-second moving average** — spikes are tolerated, so
  bursty sync is acceptable but sustained overshoot is not.
- Google's guidance: retry `RESOURCE_EXHAUSTED` with **exponential backoff**, and
  prefer **push notifications over polling** where offered.

**Design principle P2 — budget against 20 QPS/user.** Bulk sync must paginate
(`pageSize` ≤ 100), respect `pageToken`, and run through the existing jittered
Action Scheduler pattern (the Calendar sync engine already does interval jitter);
push watchers replace polling for change detection.

### 3. Push notifications (official best-practices guide)

- Notifications are delivered to a **Cloud Pub/Sub topic**; the topic owner must
  grant `classroom-notifications@system.gserviceaccount.com` publish permission.
- A **registration** binds a *feed* to a *destination*. Feeds:
  `COURSE_ROSTER_CHANGES` (per domain or per course) and `COURSE_WORK_CHANGES`
  (per course, covering coursework + student submissions).
- **Registrations expire after ~1 week** and must be renewed by re-issuing the
  identical `registrations.create()` before expiry.
- `registrations.create()` requires `classroom.push-notifications` **plus** the
  data-viewing scopes; the authorising account must be a teacher/admin of the
  course. **Domain-wide delegation is not supported for registrations**
  (returns `@MissingGrant`) — a per-user OAuth grant must be retained.
- Payloads carry `collection` (`courses.students`, `courses.teachers`,
  `courses.courseWork`, `courses.courseWork.studentSubmissions`), `eventType`, and
  a `resourceId` map that can be passed **unmodified** to the matching `get()`.
- Delivery is "usually within a few minutes" — not real-time, and **not
  guaranteed** for courses whose owner is outside a Google Workspace for Education
  domain (documented Google caveat).

**Design principle P3 — push where possible, poll as fallback.** The watcher uses
one shared Pub/Sub topic + one push-endpoint subscription (Google recommends a
single topic to avoid scaling issues), renews daily via Action Scheduler, and a
manual sync tool remains the fallback for non-domain accounts.

### 4. EdTech interoperability standards

- **OneRoster 1.2 (1EdTech/IMS Global)** is the de-facto standard for sharing
  rosters between an SIS/MIS and an LMS. Google Classroom *itself* consumes
  OneRoster through its SIS integration program (gradebook sync, roster import).
  For third-party integrators, though, the supported surface is the **Classroom
  REST API** — Classroom does not expose a OneRoster service endpoint to apps.
  Conclusion: we build on the REST API; the iSAMS/SOCS connectors already cover
  the MIS side, so the ECA toolkit sits as the hub between MIS (OneRoster-style
  sync) and LMS (REST).
- **LTI 1.3** is the standard for launching tools *inside* an LMS, and Google's
  **Classroom add-ons framework** (now generally available) is the Classroom-native
  equivalent (attachments on coursework via `classroom.addons.*` scopes). These are
  surfaces for embedding the ECA toolkit *in the Classroom UI* — valuable but a
  separate product surface; proposed here as a Phase-4 stretch, not part of the
  initial integration.
- **Edlink / industry integrator analyses** consistently flag: Classroom returns
  roster data only per course (no domain-wide roster without combining other
  Workspace APIs), and read/edit permission cannot be split per user at the app
  level — scope profiles must be chosen per OAuth flow. Both constraints are
  baked into the design below.

### 5. Data governance & compliance

- **FERPA / COPPA / GDPR.** Google Workspace for Education core services (including
  Classroom) are operated in a FERPA/COPPA/GDPR-compliant manner (Google acts as a
  "School Official" under FERPA for education data). When a third-party app
  accesses that data via the API, **the school and the integrator inherit the
  handling obligations**: the Workspace for Education Terms of Service and the
  Education API terms bind data use to the app's stated functionality (no
  advertising, data-minimisation, deletion obligations).
- **Restricted scopes.** Classroom scopes are classified *restricted*. An app used
  only by the owning Google Workspace domain (the normal deployment for a
  self-hosted NV oOS school site) can run in **internal/testing mode** with no
  Google review. Public distribution across many schools triggers **OAuth app
  verification** and, for the most sensitive scopes, a CASA security assessment
  (industry reports cite the >100-users threshold). The proposal therefore ships
  the *internal-school* deployment model as primary and documents the
  public-distribution path as a compliance gate, not a code change.

**Design principle P4 — data minimisation by construction.** Student PII is
limited to what the ECA toolkit already stores (`_student_email`, name, year); the
sync fetches only `userId`, `name`, `emailAddress`, and course membership — never
grades-by-default, never guardian data without an explicit opt-in profile.
Write operations are audited through the existing audit logger; Classroom sync
state follows the Calendar sync-state options pattern.

### 6. Best-practice synthesis (adopted as binding design principles)

1. Build on `includes/google/` shared OAuth — no copied flows, no inline scopes.
2. Tiered opt-in scope profiles; **gate every call on the granted scope set**
   (granular consent means users may approve subsets — the Calendar
   `require_scope()` precedent).
3. Budget to 20 QPS/user; paginate; backoff on `RESOURCE_EXHAUSTED`; prefer push.
4. Never re-run the authorisation flow for a fresh token (Google's 100-live-token
   limit) — use the cached `mint_access_token()` path.
5. Push registrations renewed daily (weekly expiry); per-user OAuth, never
   domain-wide delegation, for registrations.
6. MIS (iSAMS/SOCS) is authoritative for identity; Classroom matches by Google
   `userId` with email fallback; ECA CPTs remain the system of record.
7. Canonical envelope, two-gate sanitisation, capability gates, audit logging,
   coverage manifest — the repo's standing tool rules.

## Design

Four layers, each landing behind `enable_eca_classroom_integration` (default off):

### Layer 0 — Shared foundation (`includes/google/`, Base tier)

Mirrors the Calendar file set one-for-one:

| New file | Responsibility |
|---|---|
| `class-wp-mcp-ai-google-classroom-scopes.php` | The **only** place Classroom scope strings may be written. Three profiles: `readonly` (courses.readonly, rosters.readonly, profile.emails, profile.photos), `write` (adds courses, rosters, announcements, coursework.students, submissions.readonly, topics, courseworkmaterials), `push` (adds push-notifications). Exposes `require_scope()` for granular-consent gating. |
| `class-wp-mcp-ai-google-classroom-credentials.php` | Credential resolution (Pro Remote Sites connection → base settings fallback), `make_client()`, token minting via `WP_MCP_AI_Google_OAuth_Service` (cached access tokens, no re-auth loops), sensitive-field declaration. |
| `class-wp-mcp-ai-google-classroom-client.php` | Thin REST client over the existing HTTP layer: pagination loop, `fields` partial-response projection, error classification (branch on `error.errors[0].reason`; `RESOURCE_EXHAUSTED` → retryable with backoff; `@MissingGrant` → actionable "re-connect" error), usage of the `wp_mcp_ai_google_classroom_*` filter family for backoff/endpoint override. |
| `class-wp-mcp-ai-google-classroom-push.php` | Registration lifecycle: `registrations.create()` per course for `COURSE_ROSTER_CHANGES` + `COURSE_WORK_CHANGES`, renewal check, Pub/Sub push REST route handler (`mcp-ai/v1/google-classroom/webhook`) modelled on the Calendar push route (signature-independent: Pub/Sub push can carry an OIDC token, handled like the Chat webhook's OIDC path). |

### Layer 1 — Remote Sites connection type (Pro)

- New `google_classroom` connection type in `WP_MCP_AI_Pro_Remote_Site_Manager`
  and the admin form, reusing the existing OAuth start/callback wiring
  (`WP_MCP_AI_Google_OAuth_Service::build_remote_redirect_uri()`).
- Fields: `client_id`, `client_secret`, `refresh_token`, `user_email`,
  `granted_scopes` (persisted from the token response — the Calendar convention),
  `default_course_id`, `sync_enabled`, `sync_interval`, `push_topic`.
- `test_connection()` performs a real `courses.list` probe (pageSize 1) once a
  refresh token exists; before authorisation it acknowledges saved credentials
  (Calendar behaviour).
- `is_restricted_host()` is bypassed for `classroom.googleapis.com`-family hosts
  the same way other Google types are handled; secrets masked in UI via
  `get_sensitive_fields()`.

### Layer 2 — ECA tools (Pro, `addons/pro/includes/tools/eca-management/`)

Twelve tools, all with `connection_id` first-class (iSAMS tool precedent),
`get_usage_guidance()` cross-references, `profession_tags: [school_admin,
it_admin]`, `risk_level: standard` (write tools `elevated` where they mutate
Classroom):

| # | Tool slug | Description | Key scopes |
|---|---|---|---|
| 1 | `list_classroom_courses` | List active/archived Classroom courses (search by `courseStates`, paginated) | courses.readonly |
| 2 | `sync_classroom_roster_to_students` | Pull course students → upsert `mcp_ai_student` with `_student_google_id`; returns matched/new/skipped counts | rosters.readonly, profile.emails |
| 3 | `sync_classroom_courses_to_ecas` | Map Classroom courses → `mcp_ai_eca` (create or update via `_eca_google_course_id`); dry-run mode | courses.readonly |
| 4 | `link_classroom_course_to_eca` | Explicit 1:1 link between an ECA and a Classroom course + sync-direction flag | courses.readonly |
| 5 | `create_classroom_course` | Provision a Classroom course when an ECA is approved (owner = connected teacher account) | courses |
| 6 | `update_classroom_course` | Rename, change section/room, or archive a linked course | courses |
| 7 | `post_classroom_announcement` | Post an ECA notice to the course stream (e.g. session changes) | announcements |
| 8 | `create_classroom_coursework` | Create an assignment or question (permission slips, rehearsal check-ins) | coursework.students |
| 9 | `list_classroom_submissions` | Submissions for a coursework item; cross-reference turn-in state vs ECA attendance | student-submissions.students.readonly |
| 10 | `classroom_course_analytics` | Aggregate enrolment + submission completion per linked ECA; feed `generate_eca_analytics` / participation reports | read-only set |
| 11 | `list_classroom_guardians` | Guardians for course students → parent-report addressing (opt-in profile only) | guardianlinks.students.readonly |
| 12 | `manage_classroom_push_watch` | Create/renew/delete registrations per linked course; reports push health | push-notifications |

Deliberately **out of scope for v1**: grade writes (`studentSubmissions.patch` /
return submissions) and domain-level roster reads — both are admin-only surfaces
with materially different consent/abuse profiles; they become follow-ups with
toolkit-owner sign-off.

### Layer 3 — Sync & automation

- **Identity matching:** match on `_student_google_id` (`courses.students.list`
  `userId`) first, then email (`classroom.profile.emails`). MIS wins on conflict;
  the tool exposes `override_mis` flag for explicit user choice.
- **Roster sync:** chunked by pageToken, jittered interval, idempotent upserts
  keyed on Google `userId`, sync-state option per connection (Calendar pattern).
- **Push watcher:** on webhook receipt, enqueue a *background* Action Scheduler
  job (never inline API calls in the webhook request — SSE/HTTP timeouts), which
  fetches the changed resource via the unmodified `resourceId` map and applies
  the matching delta (student joined/left → enrolment upsert/withdraw;
  coursework created → notify assistant).
- **Automation:** one Pro Schedule Manager preset ("Classroom ↔ ECA nightly
  reconcile") and one Workflow Builder blueprint (sync roster → detect enrolment
  gaps → post announcement → email coordinator), both behind the feature flag.

## Data Model

- `mcp_ai_student`: new meta `_student_google_id` (unique per connection),
  `_student_google_email` (match fallback).
- `mcp_ai_eca`: new meta `_eca_google_course_id`, `_eca_google_course_alias`,
  `_eca_classroom_sync` (`off|roster-in|bi-directional`).
- New option/transient family `wp_mcp_ai_classroom_*`: per-connection sync state,
  registration cache (`registration_id`, `expiry_time`), access-token cache
  (reuse the existing `wp_mcp_ai_google_access_token_<md5>` shape).
- Meta schema registrations added to `WP_MCP_AI_Pro_CPT_Meta_Schema` (education
  section) and the JetEngine ECA field registry, so the new fields surface in
  REST, JetEngine, and the Meta Helper automatically.

## Testing

- **Foundation suite** `tests/test-google-classroom-foundation.php` (mirrors the
  36-test Calendar suite): scope-profile implication under granular consent;
  profile switches don't leak write scopes; error-reason branching
  (`RESOURCE_EXHAUSTED` retryable, `@MissingGrant` actionable, 404-on-linked-course
  handled); pagination accumulates pageTokens; registration renewal-window maths;
  webhook payload → delta mapping; single-use OAuth state reuse assertions.
- **Tool tests** (`tests/test-tool-pro-*`): each of the 12 tools — canonical
  envelope, two-gate sanitisation on entry/exit, capability enforcement
  (`manage_options` for write tools), feature-flag gating, dry-run modes produce
  no mutations, `connection_id` resolution falls back to settings cleanly.
- **Integration** (mocked transport): roster sync idempotency (double-run no
  duplicates), MIS-wins conflict resolution, push job schedules on webhook and
  applies delta once.
- Coverage manifest + `tool-status.txt` entries for all new slugs; PHPCS
  (`composer run lint`) and the two custom tool sniffs enforced in CI.

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Granular consent: user approves a scope subset | Persist `granted_scopes` from token response; `require_scope()` gates every tool; profile labels shown at connect time (Calendar precedent) |
| Registration expiry (weekly) | Daily Action Scheduler renewal of all active registrations; stale-registration health surfaced in `manage_classroom_push_watch` |
| Registrations fail under domain-wide delegation (`@MissingGrant`) | Ship per-user OAuth only for push; document; probe surfaces actionable "re-connect" error |
| Push unreliable for non-Workspace-for-Education course owners (documented Google caveat) | Manual/periodic sync tools remain the fallback; analytics tool reports last-sync age |
| Quota exhaustion during bulk sync (20 QPS/user) | PageSize ≤ 100, pageToken pagination, exponential backoff on `RESOURCE_EXHAUSTED`, jittered cron intervals, no unbounded agent-triggered loops |
| Student-data exposure | Data minimisation (fixed field projections), no grades by default, guardian scope opt-in-only, audit logging of writes, capability-gated REST, secrets never through text sanitisers |
| Public-distribution compliance (restricted scopes) | Primary model is internal-school GCP projects (no verification); public path documented as a CASA-assessment gate, not silently shipped |
| Classroom lacks ECA-specific fields (venue, cost, capacity) | ECA CPTs remain system of record; Classroom is companion surface; mapping metadata in `_eca_google_*` keys, never reverse |
| iSAMS vs Classroom roster conflicts | MIS-wins default with explicit override; identity keyed on Google `userId` + email fallback |
| Token ceiling (100 live refresh tokens per client) | Never re-auth for tokens; cached mint path; disconnect flow revokes (existing OAuth disconnect machinery) |

## Rollout Plan

1. **PR cluster A — Foundation (Base):** `includes/google/` Classroom scopes +
   credentials + client + tests. No UI, no tools. Feature-flagged at the class
   level only.
2. **PR cluster B — Connection (Pro):** `google_classroom` Remote Sites type,
   admin form, test probe, OAuth wiring, settings subtab entry under the ECA
   settings page, meta-schema fields.
3. **PR cluster C — Tools (Pro):** the 12 tools + `init.php` registration +
   ECA MCP server candidate slugs + settings-page tools list + docs (tool
   reference, ECA README updates).
4. **PR cluster D — Sync & push (Pro):** roster sync engine, push class +
   webhook route, Action Scheduler renewal, schedule preset + workflow blueprint.
5. **Release:** `enable_eca_classroom_integration` flips on in the changelog
   entry; version-bump + docs catch-up per the standard release track.

**Success criteria:** a school site can connect a teacher/coordinator Google
account → one-click map Classroom courses to ECAs → nightly roster reconcile →
push-driven enrolment deltas → an ECA announcement posted into Classroom from an
assistant conversation, with every step audited and behind granted scopes.

## Open Questions

1. Should `create_classroom_course` provision under the *connected teacher's*
   ownership (default) or a shared coordinator account? (Affects `ownerId`
   handling and support burden.)
2. Guardian scope (`guardianlinks.students.readonly`) is sensitive even among
   read scopes — ship in v1 behind the opt-in profile, or defer entirely?
3. Is the pub/sub topic a school-owned GCP resource (recommended) or should we
   document topic creation as a deploy prerequisite? (Requires the school to
   grant `classroom-notifications@system.gserviceaccount.com` publish rights.)
4. Submissions tool: read-only in v1 per proposal; confirm grade-write follow-up
   is wanted by the toolkit owner before planning it.

## Sources

**Google (official):**

- [Classroom API — Usage Limits](https://developers.google.com/workspace/classroom/reference/limits) (quotas, updated 2026-09-03)
- [Classroom API — Choose scopes](https://developers.google.com/workspace/classroom/guides/auth) (scope table, narrowest-scope guidance, verification)
- [Classroom API — Push notifications](https://developers.google.com/workspace/classroom/best-practices/push-notifications) (feeds, registrations, weekly expiry, `@MissingGrant`, payload shape)
- [Classroom API — REST reference](https://developers.google.com/workspace/classroom/reference/rest)
- [Classroom — SIS integrations (OneRoster)](https://developers.google.com/workspace/classroom/sis-integrations/validate-your-SIS)
- [Google Workspace for Education Terms of Service](https://workspace.google.com/terms/education_terms/) and [Education API terms](https://developers.google.com/workspace/education-api-terms)
- [Google for Education — Privacy & Security FAQs](https://edu.google.com/intl/ALL_us/our-values/privacy-security/frequently-asked-questions/) (FERPA/COPPA/GDPR posture)
- [Google Cloud — FERPA compliance](https://cloud.google.com/security/compliance/ferpa) / [COPPA compliance](https://cloud.google.com/security/compliance/coppa)
- [OAuth API verification FAQ](https://support.google.com/cloud/answer/9110914) (restricted scopes, CASA)

**Industry:**

- [1EdTech — OneRoster 1.2](https://www.1edtech.org/standards/oneroster) and [the 1.2 spec](https://www.imsglobal.org/spec/oneroster/v1p2) (SIS↔LMS roster standard; REST + CSV exchange)
- [Edlink — How Google Classroom integration differs from other LMSs](https://ed.link/community/how-google-classroom-integration-differs-from-other-lmss/) (per-course rosters, no split read/edit per user, Workspace-API composition)
- [RapidDev — Classroom scopes are restricted; public apps need a security audit past 100 users](https://www.rapidevelopers.com/bubble-integrations/google-classroom)
- [Common Sense Privacy — Google Classroom report](https://privacy.commonsense.org/privacy-report/Google-Classroom) (FERPA school-official status, COPPA consent flows)

**In-repo:**

- `includes/google/README.md` — shared OAuth infrastructure and its binding conventions
- `addons/pro/includes/eca/README.md`, `addons/pro/includes/tools/eca-management/README.md` — ECA toolkit architecture
- `addons/pro/includes/tools/eca-management/class-wp-mcp-ai-tool-sync-ecas-from-isams.php` — iSAMS tool + `connection_id` pattern
- `CHANGELOG.md` — ECA toolkit history (PR #4568 24-tool wave, PR #5421/#5423/#5434 settings & doc-gen fixes)
- `.context/conventions.md`, `.context/security-checklist.md`, `.context/pro-vs-base.md`, `.context/tool-registry.md` — standing conventions
- Proposal 045 (this file's format precedent) and the Calendar integration's tests for the foundation-suite shape
