# Google Calendar API v3 — Implementation Reference

Condensed reference for the constraints NV oOS encodes in `includes/google/`. Every rule here is enforced somewhere in code; this document exists so you do not have to reverse-engineer *why*.

For the architecture and the two connection surfaces, see [Google Calendar Connection Architecture](../developer/architecture/integrations/google-calendar-connection.md).

## OAuth Scopes and Verification Tiers

| Scope | Access | Sensitivity | Verification |
|-------|--------|-------------|--------------|
| `calendar.app.created` | Only calendars this app created | Non-sensitive | None |
| `calendar.calendarlist.readonly` | List calendars on the calendar list | Non-sensitive | None |
| `calendar.events` | Read/write events on accessible calendars | Sensitive | Required |
| `calendar.events.readonly` | Read events | Sensitive | Required |
| `calendar.readonly` | Read all accessible calendars | Sensitive | Required |
| `calendar` | Full read/write on all calendars | Sensitive | Required |
| `calendar.settings.readonly` | Read user Calendar settings | Sensitive | Required |
| `calendar.freebusy` | Read availability only | Sensitive (narrow) | Required |
| `calendar.acls` | Read/write sharing permissions on owned calendars | Sensitive | Required |

The scope is `calendar.acls` — **plural**. `calendar.acl` does not exist.

### NV oOS scope profiles

| Profile | Scopes | Verification |
|---------|--------|--------------|
| `minimal` | `calendar.app.created` + `calendar.calendarlist.readonly` | **None** |
| `standard` (default) | `calendar.events` + `calendar.calendarlist.readonly` | Sensitive review, typically 3–5 business days |
| `full` | `calendar` + `calendar.settings.readonly` | Sensitive review |

Defined in `WP_MCP_AI_Google_Calendar_Scopes`. Extend via `wp_mcp_ai_google_calendar_scope_profiles`; never inline a scope URL elsewhere.

### Granular consent

The consent screen lets users approve a **subset** of requested scopes. Requested ≠ granted.

- Persist the token response's `scope` field and gate every call on it.
- Scope implication is real: a grant of `calendar` satisfies a requirement for `calendar.events`. Use `WP_MCP_AI_Google_Calendar_Scopes::has_scope()` rather than string comparison.
- A grant cannot be widened server-side. Missing scope means re-consent.

### Refresh-token hazards

| Constraint | Consequence |
|------------|-------------|
| Publishing status **Testing** → refresh tokens expire after **7 days** | The classic "worked last week" failure. Move the app to **In production** |
| Max **100** live refresh tokens per Google account per client ID | Exceeding it **silently invalidates the oldest** token |

Because of the 100-token ceiling, **never re-run the authorization flow merely to obtain a fresh access token.** Mint and cache instead (`WP_MCP_AI_Google_OAuth_Service::mint_access_token()`).

## Forbidden Sync Parameters

These eight `events.list` parameters are legal on a **full** sync and return **HTTP 400** when combined with `syncToken`:

| Parameter | Purpose on a full sync |
|-----------|------------------------|
| `timeMin` | Lower bound on event end time |
| `timeMax` | Upper bound on event start time |
| `updatedMin` | Only events modified since |
| `q` | Free-text search |
| `orderBy` | `startTime` \| `updated` |
| `iCalUID` | Look up by iCalendar UID |
| `privateExtendedProperty` | Filter on a private extended property |
| `sharedExtendedProperty` | Filter on a shared extended property |

Additionally: **`showDeleted=false` is not allowed with `syncToken`.** Omit the parameter on the incremental branch rather than sending `false`.

`WP_MCP_AI_Google_Calendar_Client::build_sync_params()` enforces the split — do not hand-assemble sync parameters.

### Sync-token placement

`nextSyncToken` is returned **only on the last page** of a paginated result. Intermediate pages carry `nextPageToken` instead. Persisting a token from an intermediate page produces a permanently stale cursor that yields `410` on every subsequent pass.

## HTTP 410 Reason Table

Branch on `error.errors[0].reason`, never on the bare status code.

| Reason | Context | Handling |
|--------|---------|----------|
| `fullSyncRequired` | `events.list` with `syncToken` | Wipe local sync state, resync from scratch |
| `updatedMinTooLongAgo` | `events.list` with `updatedMin` | Wipe local sync state, resync from scratch |
| `deleted` | `events.delete` | **Success.** The event is already gone; the desired state holds |

## Retry Matrix

| Status | Reason | Retry? |
|--------|--------|--------|
| `403` | `rateLimitExceeded` | ✅ |
| `403` | `userRateLimitExceeded` | ✅ |
| `403` | `quotaExceeded` | ✅ |
| `429` | any | ✅ |
| `5xx` | any | ✅ |
| `403` | bare, or any other reason | ❌ — permission problems are not transient |
| `400` | any | ❌ — malformed request |
| `404` | any | ❌ |
| `409` | `duplicate` | ❌ — the resource already exists |

**Backoff**: `min( 2^n + rand( 0..1000ms ), 32s )`.

Jitter must be **recalculated on every attempt**. A jitter computed once and reused re-synchronises colliding clients instead of dispersing them. Override with `wp_mcp_ai_google_calendar_retry_backoff`.

## Quota

Read the actual numbers from **Google Cloud Console → APIs & Services → Calendar API → Quotas**. The values below are the published defaults and are the starting point, not a guarantee.

| Limit | Value | Applies to |
|-------|-------|------------|
| Queries per minute per project | 10,000 | Projects created **on or after 2026-05-01** |
| Queries per minute per user | 600 | Projects created **on or after 2026-05-01** |
| Legacy projects | Prior quotas retained | Projects created before 2026-05-01 |

### Quota attribution

Set `quotaUser` to a stable per-user identifier so Google charges the right bucket instead of pooling every site user into one. NV oOS passes the connection's `user_email`.

### Operation costs

| Operation | Quota units |
|-----------|-------------|
| `events.patch` (`partial: true`) | 3 |
| `events.get` + `events.update` (default path) | 2 |

`update_google_calendar_event` defaults to get+update because it is cheaper. `partial: true` is nonetheless required when the caller is not the event organiser.

### Other hard ceilings

| Ceiling | Value |
|---------|-------|
| `freeBusy` calendars per request | 50 |
| `events.list` `maxResults` cap | 2500 |
| `calendarList.list` page size cap | 250 |

## Push Notification Headers

Notifications are delivered as `POST` requests with **no body**. All state arrives in headers.

| Header | Meaning |
|--------|---------|
| `X-Goog-Channel-ID` | The channel UUID you supplied to `watch` |
| `X-Goog-Resource-ID` | Google's opaque resource identifier; **required** to stop the channel |
| `X-Goog-Resource-State` | `sync` \| `exists` \| `not_exists` |
| `X-Goog-Message-Number` | Increasing but **not** sequential — dedupe on a high-water mark, not contiguity |
| `X-Goog-Channel-Token` | The token you supplied to `watch`; verify with `hash_equals()` |
| `X-Goog-Channel-Expiration` | When the channel dies |

### Resource states

| State | Meaning | Action |
|-------|---------|--------|
| `sync` | Post-subscription handshake. Message number is always 1 | Acknowledge only; do not sync |
| `exists` | Something changed on the watched resource | Trigger an incremental sync |
| `not_exists` | The watched resource was removed | Stop the channel and clear local state |

### Delivery requirements

| Requirement | Detail |
|-------------|--------|
| Scheme | HTTPS only |
| Certificate | Valid, CA-signed. Self-signed and expired certificates fail |
| Host | Publicly resolvable. Loopback and private ranges can never work |
| `robots.txt` | Google honours it when delivering — a `Disallow` on the route kills push |
| Max channel TTL | **604800s (7 days)** |
| Renewal | **No renewal API.** Renewing means creating a **new** channel with a **new** id, then stopping the old one |
| Success codes | `200`, `201`, `202`, `204`, `102` |
| Reliability | A small percentage of messages **are dropped** |

Because notifications carry no body and delivery is lossy, **push is a trigger and `syncToken` is the transport.** A scheduled poll must remain the correctness guarantee.

## All-Day Events and Time Zones

### `end.date` is exclusive

| Intended event | `start.date` | `end.date` |
|----------------|--------------|------------|
| Single day, 2026-06-01 | `2026-06-01` | `2026-06-02` |
| Three days, 2026-06-01 → 2026-06-03 | `2026-06-01` | `2026-06-04` |

Setting `end.date` equal to `start.date` produces a zero-length event.

### Date vs dateTime

| Event kind | Fields |
|------------|--------|
| All-day | `start.date` + `end.date` (`YYYY-MM-DD`) |
| Timed | `start.dateTime` + `end.dateTime` (RFC3339) + `timeZone` |

Both bounds must use the **same** representation. Mixing `date` with `dateTime` is rejected.

### Time zones must be IANA names

| Valid | Invalid |
|-------|---------|
| `Europe/Zurich` | `+02:00` |
| `America/New_York` | `EST` |
| `Asia/Colombo` | `UTC+5:30` |

UTC offsets are not accepted in the `timeZone` field. Offsets *are* required inside RFC3339 `dateTime` values; values without one are interpreted in the effective timezone.

## Recurring Events

`status: "cancelled"` carries two different meanings and must be routed accordingly:

| Payload | Meaning | Local action |
|---------|---------|--------------|
| `status: cancelled` **with** `recurringEventId` | Cancelled *exception* within a recurring series | **RETAIN** for the parent series' lifetime |
| `status: cancelled` **without** `recurringEventId` | True deletion | **REMOVE** the local row |

Discarding cancelled exceptions makes deleted instances reappear the next time the series is expanded. `WP_MCP_AI_Google_Calendar_Sync::classify_events()` implements the split.

Related: `singleEvents=true` expands a series into instances and is **required** when `orderBy=startTime`. A full sync uses `singleEvents=false` so the series and its exceptions are mirrored as authored.

## Citations

- [Calendar API — Authentication and authorization](https://developers.google.com/workspace/calendar/api/auth)
- [Calendar API — Synchronize resources efficiently](https://developers.google.com/workspace/calendar/api/guides/sync)
- [Calendar API — Push notifications](https://developers.google.com/workspace/calendar/api/guides/push)
- [Calendar API — Usage limits and quotas](https://developers.google.com/workspace/calendar/api/guides/quota)
- [Calendar API — Handle API errors](https://developers.google.com/workspace/calendar/api/guides/errors)
- [Calendar API — Recurring events](https://developers.google.com/workspace/calendar/api/guides/recurringevents)
- [Calendar API — `events.list` reference](https://developers.google.com/workspace/calendar/api/v3/reference/events/list)
- [OAuth 2.0 Scopes for Google APIs](https://developers.google.com/identity/protocols/oauth2/scopes)
- [Using OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Granular permissions (incremental / partial consent)](https://developers.google.com/identity/protocols/oauth2/resources/granular-permissions)
- [Sensitive scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification)

## See Also

- [Google Calendar Connection Architecture](../developer/architecture/integrations/google-calendar-connection.md)
- [`includes/google/README.md`](../../includes/google/README.md) — folder conventions
- [Tool Reference](tools/tool-reference.md) — the seven Calendar tools
