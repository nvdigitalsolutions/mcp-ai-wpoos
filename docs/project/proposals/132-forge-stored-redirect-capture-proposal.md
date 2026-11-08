# Proposal 132 — Stored redirect capture (Redirection / Rank Math)

> Owner follow-up 2026-11-08, after 130 (trackers) and 131 (SEO head):
> *"make sure the forge is also able to capture the trackers which might be
> enabled on the site … is the SEO section forged as well?"* — the last
> un-captured surface is the one 131 explicitly declared out of scope:
> **the redirect rules the site already relies on.** A forged rebuild that
> drops them silently 404s every legacy URL, inbound link, QR code, and
> campaign shortlink on cutover day.

**Status:** shipped 2026-11-08 (bridge 0.3.0 + forge integration).
**Related:** 096 (forge core / `_redirects`), 106 (§1.2 SEO passthrough),
131 (SEO head — this closes its "Redirection rules" out-of-scope line),
130 (verification/meta capture).

---

## 0. Summary

| Surface | Before 132 | After 132 |
|---|---|---|
| Redirection rules | ❌ invisible to the forge | ✅ read from `redirection_items` via the bridge |
| Rank Math rules | ❌ invisible | ✅ read from `rank_math_redirections` via the bridge |
| Static `_redirects` | ⚠️ URL-structure 301s only | ✅ stored rules merged, rebuild URLs win conflicts |
| Non-301 semantics | ❌ everything assumed 301 | ✅ 301/302/303/307/308 + 404/410 carried faithfully, projected for the host |
| Portability | ❌ no host model | ✅ documented Netlify/Cloudflare matrix + explicit projection |
| Unsupported rule shapes | ❌ silent loss | ✅ counted (`regex`, `query`, `match`, `action`) — never silently widened |
| Exposure | — | ✅ opt-in + optional shared token |

---

## 1. Why this matters (the cutover failure mode)

A redirect rule is a **promise to the outside world**: "this old URL still
resolves." Those URLs live outside your control — search indexes, partner
sites, printed material, email campaigns, ad platforms, and years of
backlinks. Cutting a WordPress site over to a static host without carrying the
rules breaks all of them at once, and the damage is invisible until traffic
drops weeks later.

Google's own guidance ("Redirects and Google Search") is to use **server-side
301s** and to **avoid redirect chains** — each hop loses signal and adds
latency. That is exactly the shape of this feature: emit a single-hop map at
the publish root, as close to the origin as the host allows.

---

## 2. Research (2026-11-08)

### 2.1 Redirection — storage

Redirection stores everything in four tables (`{prefix}redirection_items`,
`_groups`, `_logs`, `_404`) plus a `redirection_options` row
([Redirection developer docs](https://redirection.me/developer/database/)).
The items schema in the plugin's own `includes/database/schema/class-latest.php`
(trunk) is:

| Column | Type | Meaning |
|---|---|---|
| `id` | int unsigned AI | row id |
| `url` | mediumtext | the **source** (a path, or a full URL) |
| `match_url` | varchar(2000) | normalized match key |
| `match_data` | text | per-match-type payload |
| `regex` | int unsigned | 1 = `url` is a regular expression |
| `position` | int unsigned | stored order (matters: first match wins) |
| `last_count` / `last_access` | int / datetime | hit telemetry — **never exported** |
| `group_id` | int | FK to `redirection_groups` |
| `status` | enum(`enabled`,`disabled`) | per-rule switch |
| `action_type` | varchar(20) | `url`, `error`, `random`, `pass`, `nothing` |
| `action_code` | int unsigned | 301/302/303/307/308 (redirect) or 404/410/451 (error) |
| `action_data` | mediumtext | for `url`: `{"url":"…"}` (JSON or PHP-serialized) |
| `match_type` | varchar(20) | `url`, `referrer`, `agent`, `cookie`, `ip`, `role`, `server`, `header`, `page`, `language`, `login`, `custom` |
| `title` | text | operator note — **never exported** |

Two extra facts drive the export:

1. **Groups gate rules.** A rule is only live when its group is enabled
   (`redirection_groups.status = 'enabled'`), so the export joins the groups
   table instead of trusting the item flag alone.
2. **Only `match_type = 'url'` is portable.** A referrer/cookie/IP/role match
   has no static-host equivalent (`_redirects` has no request-condition
   syntax that both major hosts support).

The plugin also exposes an authenticated REST API
([docs](https://redirection.me/developer/rest-api/)) with the same row shape
(`url`, `action_type`, `action_code`, `action_data`, `match_type`, `status`).
We deliberately **do not** call it: every route requires a WordPress nonce /
Redirection capability, which a build pipeline has no business holding on a
production site. The bridge reads the table read-only under its own opt-in
toggle instead.

### 2.2 Rank Math — storage

Rank Math stores redirections in `{prefix}rank_math_redirections`
(create-table SQL from rankmath/seo-by-rank-math#188):

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint unsigned AI | row id |
| `sources` | text | serialized (historical) / JSON (current) array of `{ pattern, comparison }` |
| `url_to` | text | destination |
| `header_code` | smallint unsigned | 301/302/303/307/308 or 404/410/451 |
| `hits` | bigint | telemetry — **never exported** |
| `status` | varchar(25) | `active` is the live value |
| `created` / `updated` / `last_accessed` | datetime | **never exported** |

`comparison` is one of `exact`, `contains`, `starts`, `ends`, `regex`
(Rank Math's source-match dropdown). Only `exact` is portable; the rest are
counted and skipped rather than widened into an over-matching rule.

Observed serialized shape (from a production `sources LIKE` query):

```
a:2:{s:7:"pattern";s:26:"ferry-to-cozumel-schedules";s:10:"comparison";s:5:"exact";}
```

Patterns are frequently stored **without** a leading slash, so the bridge
normalizes `pattern` → `/pattern`.

### 2.3 What a static host can actually express

The publish-root `_redirects` format is the common denominator:

| Status | Netlify ([docs](https://docs.netlify.com/manage/routing/redirects/redirect-options/)) | Cloudflare Pages ([docs](https://developers.cloudflare.com/pages/configuration/redirects/)) | We emit |
|---|---|---|---|
| 301 | ✅ documented | ✅ documented | **301** |
| 302 | ✅ documented | ✅ documented | **302** |
| 303 | not documented | ✅ documented | **302** |
| 307 | ❌ documented as unsupported ("use 302 instead") | ✅ documented | **302** |
| 308 | not documented | ✅ documented | **301** |
| 404 | ✅ documented | ❌ (rewrites/other codes unsupported) | **404** |
| 410 | not documented | ❌ | **404** |
| 451 | ❌ | ❌ | omitted, counted |

Consequences, and the decisions that follow:

* **Both hosts are first-class.** The documented intersection for redirects is
  301/302, so `_redirects` projects every method-preserving/other code onto
  it. `redirects.json` keeps the **faithful** source status for hosts,
  adapters, and audits that can express it.
* **Error rules are host-specific.** Netlify honours `404`; Cloudflare Pages
  does not need it — a path with no built asset already returns 404. Emitting
  `404` is therefore safe and useful on Netlify and harmless on Cloudflare;
  `410` is projected to `404`.
* **No regex, no query matching.** Cloudflare explicitly does not support
  query parameters in `_redirects`, and regex sources are Netlify-Pro-only.
  Rules that need either are counted, never rewritten into something broader.
* **Ordering matters** on both hosts (first match wins). The bridge returns a
  deterministic source-sorted list; the build re-sorts the final map.

### 2.4 Privacy / exposure model

A redirect list is not secret, but it is not public either: it reveals
unreleased product slugs, retired campaign URLs, and the site's URL history.
The export is therefore:

* **opt-in** (default off, like the forms bridge),
* **read-only**, and
* optionally **token-gated** (`X-Forge-Redirects-Token` /
  `Authorization: Bearer …`) with a constant-time comparison.

The response carries no row ids, hit counts, group ids, titles, or
timestamps — only the four fields a build needs.

---

## 3. Contract

`GET /wp-json/nvoos-forge/v1/redirects` — always `200` (a missing plugin is a
normal "nothing to export" state, never a 500).

```json
{
  "available": true,
  "enabled": true,
  "count": 2,
  "truncated": false,
  "providers": { "redirection": 1, "rankmath": 1 },
  "skipped": { "regex": 2, "query": 1, "match": 3, "action": 0, "invalid": 0, "duplicate": 1 },
  "redirects": [
    { "source": "/old-shop/", "target": "/shop/", "type": 301 },
    { "source": "/retired/", "target": "", "type": 410 }
  ]
}
```

Rules:

* `source` is a site-relative path beginning with `/` (never `/` itself).
* `target` is a site-relative path, an absolute external URL, or `''` for
  404/410.
* `type` ∈ {301, 302, 303, 307, 308, 404, 410}.
* Bounded to **500 rules**; `truncated: true` when more exist.
* Deterministic: sorted by `source`.
* `providers` / `skipped` are diagnostics, not build inputs.

---

## 4. Merge precedence

The forge has two independent sources of truth, and they can disagree:

| Situation | Winner | Why |
|---|---|---|
| Stored rule maps `/old/` → `/legacy/` | — | nothing else claims `/old/` |
| Stored rule maps `/old-about/` → `/elsewhere/`, but the rebuild serves the about page at `/about/` from WP's `/old-about/` | **rebuild** | the rebuild knows where it actually serves the content |
| Stored rule maps `/x/` → `/x/` (self) | dropped | a self-redirect is a loop |
| Stored rule maps `/` | dropped | the home path is not a redirect source |
| Rule with a non-portable status | dropped + counted | never guess a status |

Implementation: bridge rules are inserted first; the URL-structure pass
overwrites the same `from` key. The report exposes `bridge` (surviving stored
rules) and `warnings` (non-fatal fetch problems).

---

## 5. What was deliberately not done

| Item | Why | Extension point |
|---|---|---|
| Regex rules | Netlify-Pro-only, Cloudflare unsupported; rewriting them as splats would over-match | future `splat` projection with an explicit opt-in |
| Query-scoped rules | Cloudflare does not support query matching in `_redirects` | future host-flavour mode |
| Non-URL match types (referrer/cookie/IP/…) | no static equivalent | counted in `skipped.match` |
| 451 (legal) | neither host documents it | counted in `skipped.action` |
| Chain flattening (A→B→C ⇒ A→C) | needs the rebuilt URL map + external knowledge; a naive flatten can point at a non-existent page | future `verify --redirects` finding |
| Yoast SEO Premium redirects | different storage and licensing model; not verified in this phase | `nvoos_forge_bridge_redirect_providers` filter + a new collector |
| Cached responses | the data changes with content edits; a stale build input is worse than one query | future ETag/`last_modified` |

---

## 6. Acceptance criteria

1. The bridge exposes the route, opt-in, always `200`, and never leaks a row
   id, hit count, group id, title, or timestamp.
2. Redirection and Rank Math rules normalize into one `{source,target,type}`
   shape; disabled items, disabled groups, regex, query-scoped, and non-URL
   matches are skipped **and counted**.
3. The forge merges stored rules beneath the URL-structure map (rebuild wins),
   fails soft on a missing/unreachable bridge, and reports `bridge` +
   `warnings`.
4. The template fetches the export at build time, emits a faithful
   `redirects.json`, and postbuild writes a portable `_redirects` with a
   documented projection.
5. `npm run typecheck`, `npm test`, and `npm run test:e2e` are green.
