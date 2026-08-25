# NV oOS Google Calendar Connection Architecture

## Overview

Google Calendar is exposed through **two independent connection surfaces** that share **one foundation** in `includes/google/`. The base plugin ships a single-connection surface under Tools → Connections; NV oOS Pro ships a multi-connection surface under Remote Sites. Neither surface re-implements OAuth, retry policy, or scope handling — both delegate to the shared classes.

```
┌─────────────────────────────────────────────────────────────────┐
│                    TWO CONNECTION SURFACES                       │
├──────────────────────────────┬──────────────────────────────────┤
│ BASE (one connection)        │ PRO (many connections)           │
│ Tools → Connections →        │ NV oOS → Remote Sites →          │
│ Google Calendar              │ type: google_calendar            │
│                              │                                  │
│ Stored in: wp_mcp_ai_settings│ Stored in: connection post meta  │
│ Secrets: plain option values │ Secrets: AES-256-CBC encrypted   │
└──────────────┬───────────────┴───────────────┬──────────────────┘
               │                               │
               └───────────────┬───────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│              SHARED FOUNDATION — includes/google/                │
│                                                                  │
│  WP_MCP_AI_Google_Calendar_Scopes       scope profile registry   │
│  WP_MCP_AI_Google_OAuth_Service         state, authorize, token  │
│  WP_MCP_AI_Google_Calendar_Client       Calendar v3 + retry      │
│  WP_MCP_AI_Google_Calendar_Credentials  resolution + client build│
│  WP_MCP_AI_Google_Calendar_Sync         full + incremental sync  │
│  WP_MCP_AI_Google_Calendar_Push         channels + REST webhook  │
└───────────────────────────────┬─────────────────────────────────┘
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│         7 Pro tools — addons/pro/includes/tools/                 │
│         google-workspace/  +  calendar-booking/                  │
│         All accept an optional connection_id                     │
└─────────────────────────────────────────────────────────────────┘
```

## The Shared Foundation

`includes/google/` is base-tier code (it loads with or without Pro) bootstrapped by `google-calendar-init.php`, which is hooked from `includes/bootstrap/loader.php`.

| Class | File | Role |
|-------|------|------|
| `WP_MCP_AI_Google_Calendar_Scopes` | `class-wp-mcp-ai-google-calendar-scopes.php` | Scope profile registry — the single source of truth for scope strings |
| `WP_MCP_AI_Google_OAuth_Service` | `class-wp-mcp-ai-google-oauth-service.php` | State generation/consumption, authorize URL, code exchange, access-token minting + caching, userinfo, revoke |
| `WP_MCP_AI_Google_Calendar_Client` | `class-wp-mcp-ai-google-calendar-client.php` | Calendar API v3 client: retry/backoff, `410` discrimination, pagination, sync-param guard |
| `WP_MCP_AI_Google_Calendar_Credentials` | `class-wp-mcp-ai-google-calendar-credentials.php` | Resolves credentials from connection → settings → filters; builds configured clients |
| `WP_MCP_AI_Google_Calendar_Sync` | `class-wp-mcp-ai-google-calendar-sync.php` | Full + incremental sync, cancelled-event routing, jittered scheduling |
| `WP_MCP_AI_Google_Calendar_Push` | `class-wp-mcp-ai-google-calendar-push.php` | Push channel lifecycle + REST webhook receiver |

The folder's own conventions (scope-string ownership, redirect-URI byte-equality, single-use state) are documented in [`includes/google/README.md`](../../../../includes/google/README.md) and must be read before touching any file in it.

## Why a New Folder Instead of a Fifth OAuth Copy

The Google OAuth start/callback pair was already copy-pasted four times before Calendar existed (base Gmail, base Drive, Pro Gmail, Pro Drive) and the copies had drifted — base and Pro Drive requested *different* scope sets for the same product. Adding Calendar as a fifth copy would have compounded that. `WP_MCP_AI_Google_OAuth_Service` is the extraction point: new Google integrations build on it rather than duplicating it.

## Scope Profiles

Scope selection is the single most consequential decision on this integration, because it determines whether the site owner needs to survive a Google verification review.

| Profile | Scopes | Google verification |
|---------|--------|---------------------|
| `minimal` | `calendar.app.created`, `calendar.calendarlist.readonly` | **None** — both scopes are non-sensitive |
| `standard` (default) | `calendar.events`, `calendar.calendarlist.readonly` | Sensitive-scope review, typically 3–5 business days |
| `full` | `calendar`, `calendar.settings.readonly` | Sensitive-scope review |

### The trade-off

- **`minimal`** ships without any Google review. NV oOS creates and manages its *own* secondary calendar via `calendar.app.created` and cannot read or write the user's pre-existing calendars. This is the right default for a site that only needs NV oOS to own its bookings.
- **`standard`** is the shipped default because most operators want events written onto calendars they already use. It buys full event CRUD on existing calendars at the cost of a sensitive-scope review on a *published* Google Cloud project.
- **`full`** adds calendar creation/deletion, sharing permissions, and settings reads. Only choose it when the assistant genuinely manages calendars, not just events.

`WP_MCP_AI_Google_Calendar_Scopes::profile_requires_verification()` reports the review requirement so the admin UI can warn before the operator commits.

### Scope implication

Requested ≠ granted. Granular consent lets a user approve a subset of the requested scopes, so the token response's `scope` field is persisted and every tool gates on it. `has_scope()` understands implication — a grant of `calendar` satisfies a requirement for `calendar.events` — via `get_implied_by()`. Never compare scope strings directly.

### Adding a profile

```php
add_filter( 'wp_mcp_ai_google_calendar_scope_profiles', function ( $profiles ) {
    $profiles['readonly'] = array(
        'label'                 => 'Read-only',
        'description'           => 'Reads events, never writes.',
        'scopes'                => array(
            WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS_READONLY,
            WP_MCP_AI_Google_Calendar_Scopes::SCOPE_CALENDARLIST_READONLY,
        ),
        'requires_verification' => true,
    );

    return $profiles;
} );
```

Scope strings must come from the class constants. Inlining a `googleapis.com/auth/calendar*` URL anywhere else re-opens the drift problem this folder exists to close. Note the scope is `calendar.acls` (plural) — `calendar.acl` does not exist.

## Data Model

### Base surface — `wp_mcp_ai_settings`

**URL**: `admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=google_calendar`

| Settings key | Sensitive | Notes |
|--------------|-----------|-------|
| `google_calendar_client_id` | | Google OAuth2 Client ID (`*.apps.googleusercontent.com`) |
| `google_calendar_client_secret` | 🔒 | Google OAuth2 Client Secret |
| `google_calendar_refresh_token` | 🔒 | Written by the OAuth callback |
| `google_calendar_user_email` | | Auto-filled from the userinfo endpoint after OAuth |
| `google_calendar_granted_scopes` | | Space-delimited scopes Google actually granted |
| `google_calendar_scope_profile` | | `minimal` \| `standard` \| `full` |
| `google_calendar_default_calendar_id` | | Defaults to `primary` |
| `google_calendar_timezone` | | IANA name; falls back to the site timezone |

Secrets are `wp_unslash()`-only on input — never `sanitize_text_field()`, which corrupts them — and are listed in `get_sensitive_fields()` so the UI masks them.

### Pro surface — Remote Sites connection type `google_calendar`

**URL**: `admin.php?page=wp-mcp-ai-remote-sites`
**Badge colour**: `#0b8043`
**Forced values**: `url` = `https://www.googleapis.com/calendar/v3`, `auth_type` = `none`

Reused fields (shared with the other Google connection types):

| Meta key | Type | Notes |
|----------|------|-------|
| `client_id` | string | Google OAuth2 Client ID |
| `client_secret` | string (encrypted) | Google OAuth2 Client Secret |
| `refresh_token` | string (encrypted) | Stored automatically after the OAuth flow |
| `user_email` | string | Auto-filled after OAuth; also used as `quotaUser` |
| `enabled` | bool | Standard connection toggle |

Calendar-specific fields:

| Meta key | Type | Notes |
|----------|------|-------|
| `calendar_id` | string | Target calendar; blank resolves to `primary` |
| `scope_profile` | string | `minimal` \| `standard` \| `full`; blank normalises to the default profile |
| `granted_scopes` | string | Space-delimited scopes Google actually granted |
| `sync_token` | string | Opaque incremental-sync cursor from `events.list` |
| `channel_id` | string | Active push channel identifier |
| `channel_resource_id` | string | Google's resource identifier, required to stop the channel |
| `channel_expiration` | int | Unix timestamp when the channel dies |

`client_id` + `client_secret` are required on save; a missing pair returns `wp_mcp_ai_pro_missing_google_calendar_credentials`. `calendar_id` and `scope_profile` are optional.

> **`calendar_id` is deliberately *not* `folder_id`.** `folder_id` is populated for every connection type (it is Google Drive's folder scope), so sharing it would let a Calendar save clobber a Drive folder scope on the same record. The duplication is the fix, not an oversight.

### Foundation-owned storage

| Storage | Key | Purpose |
|---------|-----|---------|
| Option | `wp_mcp_ai_google_calendar_sync_state` | Per-target sync cursor + failure counters |
| Option | `wp_mcp_ai_google_calendar_channels` | Push channel registry |
| Transient | `wp_mcp_ai_<service>_oauth_state_<md5>` | Single-use, user-bound OAuth state (TTL 600s) |
| Transient | `wp_mcp_ai_google_access_token_<md5>` | Access-token cache (TTL = `expires_in` − 300s) |
| Post meta | `_google_calendar_event_id` | Appointment → Google event mapping for `sync_google_calendar` idempotency |

Sync state is keyed per `connection_id` + `calendar_id` pair (`WP_MCP_AI_Google_Calendar_Sync::state_key()`), so one connection's `410` does not invalidate another's cursor.

## Credential Resolution Order

`WP_MCP_AI_Google_Calendar_Credentials::resolve()` walks three sources and stops at the first that yields a usable credential set. The winning source is reported back as `SOURCE_CONNECTION`, `SOURCE_SETTINGS`, or `SOURCE_FILTER`.

```
resolve( $connection_id, $context, $arguments )
   │
   ├─ 1. CONNECTION  (Pro only)
   │     Remote Sites record of type google_calendar
   │     • explicit $connection_id, else the first enabled record
   │     • secrets via Remote_Site_Manager::decrypt_value()
   │     └─> found? return SOURCE_CONNECTION
   │
   ├─ 2. SETTINGS  (base)
   │     wp_mcp_ai_settings → google_calendar_* keys
   │     └─> client_id + client_secret + refresh_token present?
   │         return SOURCE_SETTINGS
   │
   └─ 3. FILTERS  (escape hatch for pre-existing installs)
         wp_mcp_ai_google_calendar_access_token
         wp_mcp_ai_google_calendar_service_account_credentials
         wp_mcp_ai_google_calendar_default_calendar_id
         └─> return SOURCE_FILTER
```

`make_client()` turns a resolved credential array into a `WP_MCP_AI_Google_Calendar_Client` with a **lazy token provider** — a callable, not a literal string — so a long pagination run can refresh mid-flight instead of failing on a token that expired between pages.

## Sync Protocol

Incremental sync is the transport for all change detection. Push notifications are only a trigger.

```
                    ┌──────────────────────┐
                    │  no sync_token yet?  │
                    └──────┬───────────────┘
                           │ yes
                           ▼
            ┌──────────────────────────────┐
            │        FULL SYNC             │
            │  timeMin = now − 1 year      │
            │  showDeleted = true          │
            │  singleEvents = false        │
            │  (filter params ALLOWED)     │
            └──────────────┬───────────────┘
                           │ paginate ≤ 40 pages
                           ▼
            ┌──────────────────────────────┐
            │  nextSyncToken on LAST page  │
            │  persist to sync state       │
            └──────────────┬───────────────┘
                           │
        ┌──────────────────┴──────────────────┐
        │                                     │
        ▼                                     ▼
┌──────────────────┐              ┌────────────────────────┐
│ cron every ~6h   │              │ push notification      │
│ (jittered)       │              │ resource_state=exists  │
└────────┬─────────┘              └───────────┬────────────┘
         └──────────────┬─────────────────────┘
                        ▼
            ┌──────────────────────────────┐
            │     INCREMENTAL SYNC         │
            │  syncToken = <cursor>        │
            │  showDeleted omitted         │
            │  (filter params FORBIDDEN)   │
            └──────────────┬───────────────┘
                           │
              ┌────────────┴────────────┐
              │                         │
              ▼                         ▼
      ┌───────────────┐        ┌─────────────────────┐
      │   200 OK      │        │  410 Gone           │
      │ classify +    │        │ fullSyncRequired /  │
      │ store cursor  │        │ updatedMinTooLongAgo│
      └───────────────┘        └──────────┬──────────┘
                                          ▼
                               ┌─────────────────────┐
                               │ delete_state()      │
                               │ fire *_full_sync_   │
                               │ required, restart   │
                               └─────────────────────┘
```

### The forbidden-parameter split

`WP_MCP_AI_Google_Calendar_Client::build_sync_params()` exists to enforce one rule: **eight `events.list` parameters are legal on a full sync but return HTTP 400 when combined with `syncToken`.**

`timeMin`, `timeMax`, `updatedMin`, `q`, `orderBy`, `iCalUID`, `privateExtendedProperty`, `sharedExtendedProperty` — see `SYNC_FORBIDDEN_PARAMS`.

Additionally, `showDeleted=false` is not allowed with `syncToken`; the incremental branch omits the parameter entirely rather than sending `false`.

Never hand-assemble sync parameters. Call `build_sync_params( $mode, $base, $sync_token )` and let it strip.

### Cancelled-event routing

`status: "cancelled"` has two distinct meanings and `classify_events()` splits them:

| Payload | Meaning | Local action |
|---------|---------|--------------|
| `status: cancelled` **with** `recurringEventId` | Cancelled *exception* in a recurring series | **RETAIN** for the parent series' lifetime — dropping it makes the instance reappear |
| `status: cancelled` **without** `recurringEventId` | True deletion | **REMOVE** the local row |

Getting this backwards is the classic recurring-event bug: deleted single instances silently resurrect on the next expansion.

### Scheduling

| Constant | Value | Meaning |
|----------|-------|---------|
| `DEFAULT_INTERVAL` | `21600` | 6 hours between safety-net polls |
| `FULL_SYNC_LOOKBACK` | `31536000` | 1 year of history on a full sync |
| `MAX_PAGES` | `40` | Pagination ceiling per pass |
| `FAILURE_THRESHOLD` | `5` | Consecutive failures before the target is backed off |

`jittered_interval()` offsets the schedule with a deterministic per-site offset (`site_offset()`), so a fleet of NV oOS installs sharing one Google Cloud project does not stampede the same minute-bucket quota.

Cron hooks: `wp_mcp_ai_google_calendar_sync` (sync pass) and `wp_mcp_ai_google_calendar_renew_channels` (channel renewal).

## Push Notification Lifecycle

```
1. WATCH        POST /calendars/{id}/events/watch
                → channel_id, resource_id, expiration
                → persisted to wp_mcp_ai_google_calendar_channels
                          │
2. SYNC         Google sends X-Goog-Resource-State: sync
                → handshake only, message number always 1
                → acknowledged, no sync triggered
                          │
3. EXISTS       Google sends X-Goog-Resource-State: exists
                → verify X-Goog-Channel-Token via hash_equals()
                → dedupe on X-Goog-Message-Number high-water mark
                → schedule sync in 5s, return 200 immediately
                          │
4. RENEW        expiration − 86400 reached
                → create a NEW channel (there is no renew API)
                → brief overlap window: two channels, same change
                → old channel stopped
                          │
5. STOP         POST /channels/stop with channel_id + resource_id
```

**Route**: `POST /wp-json/mcp-ai/v1/google-calendar/webhook`

### Constraints that shape this design

- **HTTPS with a valid CA-signed certificate is mandatory.** `is_push_eligible()` checks the resolved `rest_url()` up front so an ineligible host produces an actionable admin message instead of an opaque `watch` failure. Loopback and private-network hosts can never work.
- **Maximum channel TTL is 604800s (7 days)** and there is no renewal API — renewal means creating a *new* channel with a *new* id, then stopping the old one. The overlap is intentional; the message-number high-water mark absorbs the duplicate delivery.
- **Notifications carry no body.** The receiver must call the API anyway, which is why the read is deferred to a scheduled job rather than done inline — inline reads risk exceeding Google's delivery timeout budget.
- **Google respects `robots.txt`** when delivering to the webhook URL. A `Disallow` covering the REST route silently kills push.
- **A small percentage of messages are dropped.** Push is a latency optimisation; the 6-hour cron poll is the correctness guarantee. Never treat push as the only path.
- Google accepts `200`, `201`, `202`, `204`, and `102` as delivery success; the receiver always returns `200`.

## Retry and Error Matrix

`WP_MCP_AI_Google_Calendar_Client::request()` classifies every failure before deciding to retry. Retrying the wrong class burns quota and masks bugs.

| Status | Reason | Retry? |
|--------|--------|--------|
| `403` | `rateLimitExceeded` | ✅ Yes |
| `403` | `userRateLimitExceeded` | ✅ Yes |
| `403` | `quotaExceeded` | ✅ Yes |
| `429` | any | ✅ Yes |
| `5xx` | any | ✅ Yes |
| `403` | bare / any other reason | ❌ Never — a permission problem is not transient |
| `400` | any | ❌ Never — malformed request |
| `404` | any | ❌ Never |
| `409` | `duplicate` | ❌ Never — the resource already exists |

**Backoff**: `min( 2^n + rand( 0..1000ms ), 32s )`. Jitter is recalculated on every attempt, not once — a fixed jitter re-synchronises colliding clients instead of dispersing them. `MAX_ATTEMPTS` is 5; `MAX_BACKOFF_SECONDS` is 32. Override with `wp_mcp_ai_google_calendar_retry_backoff`.

### HTTP 410 has three meanings

Branch on `error.errors[0].reason`, never on the bare status:

| Reason | Context | Handling |
|--------|---------|----------|
| `fullSyncRequired` | `events.list` with `syncToken` | Wipe local sync state and resync from scratch |
| `updatedMinTooLongAgo` | `events.list` with `updatedMin` | Same — wipe and resync |
| `deleted` | `events.delete` | **Success.** The event is already gone; the desired state holds |

`is_full_sync_required()` and `is_auth_failure()` encode this. Use them.

### Time and timezone rules

- **All-day `end.date` is exclusive.** A single-day event on `2026-06-01` has `end.date = "2026-06-02"`. Off-by-one here produces zero-length events Google silently rejects or renders wrong.
- **Time zones must be IANA names** (`Europe/Zurich`), never UTC offsets (`+02:00`). Google rejects offsets in the `timeZone` field.
- Timed `start`/`end` values are RFC3339 with a mandatory offset; values without one are interpreted in the effective timezone.

### Refresh-token hazards

- **Refresh tokens expire after 7 days while the Google Cloud OAuth consent screen publishing status is "Testing".** This is the single most common "it worked last week" report.
- **A Google account may hold at most 100 live refresh tokens per client ID.** Exceeding it silently invalidates the oldest. Consequence: **never re-run the authorization flow merely to obtain a fresh access token** — call `mint_access_token()`, which caches. A site that re-authorizes on every request will eventually break every other site sharing the client ID.
- `quotaUser` is set from the connection's `user_email` so Google attributes per-user quota correctly instead of pooling every site user into one bucket.

## Tools

All seven tools are Pro, accept an optional `connection_id`, enforce scopes via `require_scope()`, and return the canonical envelope (success array or `WP_Error`, never `array( 'success' => false )`).

| Slug | Directory | Notes |
|------|-----------|-------|
| `create_google_calendar_event` | `google-workspace/` | **Retrofitted** — gained `connection_id`, scope enforcement, canonical envelope, and `create_meet_link` |
| `list_google_calendars` | `google-workspace/` | Discovery: call this before assuming `primary` |
| `list_google_calendar_events` | `google-workspace/` | Paginated via `page_token` / `next_page_token` |
| `update_google_calendar_event` | `google-workspace/` | `partial: true` → PATCH (3 quota units); default → get+update (2 units) |
| `delete_google_calendar_event` | `google-workspace/` | Treats `410 deleted` as success |
| `check_google_calendar_availability` | `google-workspace/` | freeBusy; max 50 calendars per request |
| `quick_add_google_calendar_event` | `google-workspace/` | Natural-language `text` input |
| `sync_google_calendar` | `calendar-booking/` | **Rewritten from a stub** — pushes appointments to Google, keyed on `_google_calendar_event_id` post meta for idempotency, and recreates the event when it was deleted upstream |

`update_google_calendar_event` defaults to get+update because PATCH costs an extra quota unit; `partial: true` is nonetheless required when the caller is not the event organiser.

## Filter and Action Reference

### Filters

| Filter | Signature | Purpose |
|--------|-----------|---------|
| `wp_mcp_ai_google_calendar_scope_profiles` | `( array $profiles )` | Register or modify scope profiles |
| `wp_mcp_ai_google_calendar_retry_backoff` | `( float $wait, int $attempt )` | Override the computed backoff for one attempt |
| `wp_mcp_ai_google_calendar_access_token` | `( string $token, array $context, array $arguments, $tool )` | Supply an access token directly, bypassing stored credentials |
| `wp_mcp_ai_google_calendar_service_account_credentials` | `( array $credentials, array $context, array $arguments, $tool )` | Supply service-account credentials for the JWT path |
| `wp_mcp_ai_google_calendar_default_calendar_id` | `( string $calendar_id, array $context, array $arguments, $tool )` | Override the resolved default calendar |
| `wp_mcp_ai_google_calendar_request_timeout` | `( int $timeout, array $context, array $arguments, $tool )` | Per-tool HTTP timeout |
| `wp_mcp_ai_google_calendar_sync_params` | `( array $params, string $mode, string $connection_id, string $calendar_id )` | Adjust `events.list` parameters — applied *after* the forbidden-param split |
| `wp_mcp_ai_google_calendar_sync_targets` | `( array $targets )` | Add or remove connection/calendar pairs from the scheduled sweep |
| `wp_mcp_ai_google_calendar_sync_interval` | `( int $seconds )` | Base interval before jitter is applied |
| `wp_mcp_ai_google_calendar_push_eligible` | `( bool $eligible, string $url )` | Force push eligibility, e.g. behind a terminating proxy |
| `wp_mcp_ai_google_calendar_sync` | `( bool $result, int $appointment_id, array $arguments )` | Replace the `sync_google_calendar` tool's push implementation wholesale |

### Actions

| Action | Signature | Fires when |
|--------|-----------|------------|
| `wp_mcp_ai_google_calendar_synced` | `( array $report, string $connection_id, string $calendar_id )` | A sync pass completes; `$report` carries the classified event counts |
| `wp_mcp_ai_google_calendar_full_sync_required` | `( string $connection_id, string $calendar_id )` | A `410 fullSyncRequired` / `updatedMinTooLongAgo` invalidated the cursor |

## OAuth Handlers

### Base surface

| Hook / parameter | Value |
|------------------|-------|
| Start action | `admin_post_wp_mcp_ai_google_calendar_oauth_start` |
| Start nonce | `wp_mcp_ai_google_calendar_oauth_start` |
| Disconnect action | `admin_post_wp_mcp_ai_google_calendar_disconnect` |
| Disconnect nonce | `wp_mcp_ai_google_calendar_disconnect` |
| Callback | `?wp_mcp_ai_oauth=google_calendar_callback` |

### Pro surface

| Hook / parameter | Value |
|------------------|-------|
| Connect | `oauth_handler=google_calendar_oauth_connect` |
| Connect nonce | `google_calendar_oauth_connect_<connection_id>` |
| Callback | `oauth_handler=google_calendar_oauth_callback` |

Both surfaces build their redirect URI through `WP_MCP_AI_Google_OAuth_Service::build_redirect_uri()` / `build_remote_redirect_uri()`. Google requires the authorize-time and exchange-time URIs to be byte-identical; rebuilding one inline is exactly how that invariant breaks.

OAuth `state` is single-use and user-bound. `consume_state()` deletes the transient **before** validating, so a replayed callback cannot succeed inside the 600-second TTL window.

## Test Connection Behaviour

`test_connection()` on the Pro surface is deliberately two-phase:

1. **Before a refresh token exists** — returns a saved-credentials acknowledgement. There is nothing to probe yet, and failing here would make a correctly-saved connection look broken.
2. **Once a refresh token exists** — performs a **real probe**: `calendarList.list` with `maxResults=1`. This exercises token minting, scope grant, and network reachability in one call, at minimal quota cost.

A failure in phase 2 returns `wp_mcp_ai_pro_google_calendar_test_failed`.

## Troubleshooting

### "It worked last week and now every call fails"

The Google Cloud OAuth consent screen is still in **Testing** publishing status, so refresh tokens expire after **7 days**.

```
Google Cloud Console → APIs & Services → OAuth consent screen
  Publishing status: Testing   ← refresh tokens die after 7 days
```

Move the app to **In production**. For the `minimal` scope profile this needs no review because both of its scopes are non-sensitive. For `standard` and `full` it triggers sensitive-scope verification (typically 3–5 business days). Re-authorizing is a 7-day band-aid, not a fix — and repeated re-authorization walks the account toward the 100-token ceiling.

### A tool reports a missing scope even though consent was granted

Granular consent lets the user untick individual scopes on the consent screen. The token response's `scope` field is authoritative and is what gets persisted to `granted_scopes`.

```php
// What Google actually granted, per surface:
$settings['google_calendar_granted_scopes'];   // base
$connection['granted_scopes'];                 // Pro
```

Compare against the profile's requirement with `WP_MCP_AI_Google_Calendar_Scopes::has_scope()`, which resolves implication (`calendar` implies `calendar.events`). If the required scope is genuinely absent, the user must re-consent — there is no way to widen a grant server-side.

### `410 fullSyncRequired` keeps recurring

A single `410` is normal: sync tokens expire, and calendars that go untouched for long enough invalidate their cursor. The handler already wipes state, fires `wp_mcp_ai_google_calendar_full_sync_required`, and restarts with a full sync.

A `410` on *every* pass means the token is not being persisted. Check:

- `nextSyncToken` is only present on the **last** page of a paginated result. Storing a token from an intermediate page yields a permanently stale cursor. `paginate()` places it correctly — hand-rolled pagination often does not.
- Sync state is keyed per `connection_id` + `calendar_id`. Two targets writing to the same key will overwrite each other's cursors on every pass.
- A forbidden parameter reaching the incremental request produces `400`, not `410`; if you see `400` here, something bypassed `build_sync_params()`.

Inspect the stored state:

```bash
wp option get wp_mcp_ai_google_calendar_sync_state --format=json
```

### Push notifications never arrive

Work down this list — each item is a hard requirement, not a preference:

| Check | Requirement |
|-------|-------------|
| Scheme | HTTPS. Plain HTTP is rejected at `watch` time |
| Certificate | Valid, CA-signed. Self-signed and expired certs fail silently after subscription |
| Host | Publicly resolvable. Loopback, private ranges, and `.local` can never work |
| `robots.txt` | Must not `Disallow` the REST route — Google honours it when delivering |
| Channel expiry | `channel_expiration` in the past means the channel is dead; renewal creates a *new* channel id |
| Route | `POST /wp-json/mcp-ai/v1/google-calendar/webhook` must return 200 to an unauthenticated request carrying valid `X-Goog-*` headers |

```php
// Ask the plugin why it thinks push is impossible:
$eligible = WP_MCP_AI_Google_Calendar_Push::is_push_eligible();
if ( is_wp_error( $eligible ) ) {
    error_log( $eligible->get_error_message() );
}
```

```bash
wp option get wp_mcp_ai_google_calendar_channels --format=json
```

Remember that a small percentage of notifications are dropped by design. If data is merely *late* rather than absent, the 6-hour cron sweep is doing its job and push is a latency optimisation you may not need.

### "The connection saves but the field comes back blank"

`Remote_Site_Manager::save_connection()` writes an **explicit allowlist** of meta keys. A field that is rendered in the form but absent from that allowlist is silently dropped on save — no error, no warning, just an empty value on reload.

The Calendar-specific keys in the allowlist are:

```
calendar_id  scope_profile  granted_scopes  sync_token
channel_id   channel_resource_id            channel_expiration
```

When adding a Calendar field, it must be added in **both** places: the admin form renderer *and* the `save_connection()` allowlist. Adding it to only the form is the failure mode this section exists to name.

This is also why `calendar_id` is a separate key rather than reusing `folder_id` — see the note in [Data Model](#pro-surface--remote-sites-connection-type-google_calendar).

## Tests

```bash
vendor/bin/phpunit tests/test-google-calendar-foundation.php
```

36 tests covering scope implication, all eight forbidden sync parameters (via a data provider), `410` discrimination, retry classification, sync-token placement across pagination, cancelled-event routing, single-use and user-bound OAuth state, `invalid_grant` detection, interval jitter bounds, and per-target state isolation.

## Summary

- ✅ **One foundation, two surfaces** — base single-connection and Pro multi-connection share `includes/google/`
- ✅ **Scope profiles make the verification cost explicit** — `minimal` ships without Google review
- ✅ **`syncToken` is the transport, push is only a trigger** — correctness never depends on notification delivery
- ✅ **`410` and `status: cancelled` are both discriminated, not guessed** — the two bugs that silently corrupt calendar mirrors
- ✅ **Access tokens are cached, never re-authorized** — protects the 100-refresh-token-per-client-ID ceiling

## See Also

- [`includes/google/README.md`](../../../../includes/google/README.md) — folder conventions and public surface
- [`docs/reference/google-calendar-api-v3.md`](../../../reference/google-calendar-api-v3.md) — scope tiers, quota values, push headers, full error table
- [`addons/pro/includes/admin/README-REMOTE-CONNECTIONS.md`](../../../../addons/pro/includes/admin/README-REMOTE-CONNECTIONS.md) — the Pro connection registry
- [`oauth-settings-architecture.md`](oauth-settings-architecture.md) — why OAuth hooks live in the legacy Admin Settings class
