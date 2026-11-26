# 165 — the publication gate, the `/manage` surface, and the ECA first run

> **RECONSTRUCTED 2026-11-26.** The original 968-line runbook was an untracked
> file and was lost when the working tree was cleaned; so were
> `lib/gate-publish.ts`, `lib/gate-runtime.ts`, the two gate pages and four test
> files. Everything below is rebuilt from what survived — the `gatePublish` /
> `GatePublication` contracts in `lib/types.ts`, `lib/gate.ts`'s `GateProbe`,
> the call sites in `lib/scaffold.ts` and `scripts/forge.mjs`, and the parts of
> the original read into a session before the loss (§4's URL-replacement passes,
> §7 in full, §9's shape). **The code is the authority**; where this document
> and the code disagree, the code wins.
>
> Sections that could not be reconstructed and are still missing: §2.1's
> verified live-state table, §8.9 (what the ECA bridge build actually shipped,
> beyond "bridge 1.2.0 live on `cms.eca.directory`, 105 activities"), and the
> research-basis citations in §3.

## 1. What this is

Two distinct gates, often confused:

| Gate | Question it answers | Module |
|---|---|---|
| **Forge gate** (Proposal 152) | "How does the *forge* get in to a gated source?" | `lib/gate.ts` |
| **Publication gate** (this proposal) | "How must the *generated site* behave while the CMS is gated?" | `lib/gate-publish.ts` + `lib/gate-runtime.ts` |

The second is the Elementor-parity feature: a WordPress site in maintenance
mode shows a holding page to the public, but a signed-in editor still sees the
real site. A forged managed site reproduces that with its own session, because
the CMS's cookie lives on `cms.<domain>` and is never sent to the apex.

## 2. Topology

```text
              ┌──────────────────────────────┐        ┌────────────────────────────┐
Visitors ───▶ │ eca.directory (Velocity)      │  BFF   │ cms.eca.directory (WP)     │
              │  server.js  ─ front controller│ ─────▶ │  /wp-json/nvoos-commerce/v1│
              │   ├─ forge-gate/runtime.mjs   │        │  /wp-json/nvoos-forge/v1   │
              │   ├─ forge-gate/static.mjs    │        │  /wp-admin (never proxied) │
              │   └─ dist/server/entry.mjs    │        └────────────────────────────┘
              └──────────────────────────────┘
```

Rule: **WordPress is never the public origin.** A profile's `url` is the CMS
host; `ASTRO_SITE` / `deploy.publicOrigin` is the public one. `WP_HOME` and
`WP_SITEURL` must point at the CMS host.

## 3. The publication gate — the contract

### 3.1 Artifact

`gate.generated.json` is written by `writeGateManifest()` at scaffold time,
**always** (even with the gate off — it is the runtime's boot state):

```json
{
  "mode": "off | maintenance | coming_soon",
  "source": "bridge | probe | override | build",
  "plugin": "elementor | core | null",
  "retryAfter": 900,
  "checkedAt": "2026-11-26T…",
  "honored": true,
  "policy": "replace | keep",
  "page": "maintenance | coming-soon | null",
  "comingSoonIndexable": false,
  "session": { "bypass": true, "path": "/wp-json/nvoos-commerce/v1/auth/me", "cookieName": "nvoos_forge_session" },
  "bypass":  { "cookieName": "nvoos_gate", "ttlSeconds": 43200 },
  "managed": { "enabled": true, "pollSeconds": 60, "maxStalenessSeconds": 900 },
  "retryAfterSeconds": 600,
  "honor": "auto | coming_soon-only | off",
  "trust": "bridge | probe"
}
```

No secret is ever written: the bypass token and the session HMAC are derived at
runtime from `NVOOS_FORGE_GATE_SECRET` / `NVOOS_MANAGE_SECRET`.

### 3.2 Verdict resolution (`resolveLivePublication`)

- **`override`** (`--gate maintenance|coming_soon|off`) skips the network.
- **`trust: "bridge"` (default)** — only the bridge's
  `GET /wp-json/nvoos-forge/v1/gate` is authoritative. HTML inference is *not*
  enough, because a cached anonymous page can look gated to a logged-out probe.
- **`trust: "probe"`** — allow HTML inference when the bridge is absent
  (503 / `Retry-After` → maintenance, 200 holding page → coming-soon).
- **`honor`** can decline a mode: `coming_soon-only` refuses maintenance,
  `off` refuses everything. A declined verdict keeps its evidence
  (`source`, `plugin`, `checkedAt`) but sets `honored: false`, `mode: "off"`,
  `page: null`.
- **Fails open.** A probe that throws (unreachable, SSRF guard, timeout)
  publishes the real site. A build is never blocked by a CMS outage.

### 3.3 Request ladder (`server.js`)

```text
1. gate.handle(req, res)      → true = answered
2. static.handle(req, res)    → true = served dist/client
3. handler(req, res)          → Astro (middleware mode)
```

| Request | Result |
|---|---|
| Allowlisted (`/api/`, `/_astro/`, `/manage`, `/manage-assets/`, `/media/`, `/robots.txt`, `/sitemap.xml`, `/feed.xml`, `/favicon.ico`, `/.well-known/`) | passes through untouched |
| `POST /api/gate` + `Authorization: Bearer <NVOOS_REVALIDATE_TOKEN>` | switches mode; `503` when unset, `401` on a bad token |
| `?nvoos-gate=<token>` (valid) | `302` to the clean URL + a host-only `nvoos_gate` cookie (`HttpOnly`, `SameSite=Lax`, `Secure` behind TLS) |
| `?nvoos-gate=<token>` (invalid) | `403`, no cookie |
| Verified manager session | real site + `x-nvoos-gate: bypass-manage`, `x-robots-tag: noindex, nofollow`, `cache-control: private, no-store` |
| Valid bypass cookie | real site + `x-nvoos-gate: bypass-link`, same headers |
| **maintenance**, everyone else | `503` + `Retry-After` + `private, no-store` + `x-robots-tag` + the maintenance page |
| **coming_soon**, everyone else | `200` + `private, no-store` + `x-robots-tag` (unless `comingSoon.indexable`) + the coming-soon page |

The gate pages are **prerendered** (`src/pages/{maintenance,coming-soon}.astro`,
`export const prerender = true`) and read from `dist/client/<page>/index.html`.
A stripped build falls back to a built-in page — a gate never 500s.

### 3.4 The session contract

`forge-gate/runtime.mjs` verifies the `/manage` cookie **locally**, with no CMS
round-trip, so a manager still sees the site when the CMS is down:

```text
token   = <exp>.<uid>.<base64url(name)>.<hmac>
hmac    = HMAC-SHA256(secret, 'manage.v1.' + <exp>.<uid>.<base64url(name)>)
secret  = NVOOS_MANAGE_SECRET || NVOOS_FORGE_GATE_SECRET
```

That format is a **contract between `lib/manage-runtime.ts` (which emits the
signer) and `lib/gate-runtime.ts` (which emits the verifier)**. Changing one
without the other silently un-authenticates every manager.
Verified with `timingSafeEqual`, and a cookie's *presence* is never trusted.

### 3.5 Polling and staleness

The runtime polls the bridge every `managed.pollSeconds` (floor 15 s). A failed
poll **never changes the verdict** — the site keeps publishing (or keeps
gating) with the last known state rather than flapping; after
`maxStalenessSeconds` it logs `verdict stale — keeping <mode>`.

## 4. The `/manage` surface

Two paths, one page (`deploy.manage: true`):

- **7a — standalone.** A vanilla, server-rendered sign-in using a WordPress
  **Application Password** (`wp/v2/users/me?context=edit`, server-to-server).
  Requires `edit_posts` | `manage_options` | `manage_astro`. Mints a host-only,
  HMAC-signed session; the gate trusts it. Works with JavaScript disabled.
- **7b — embedded editor** (`deploy.manageBundle: true`). The scaffold vendors
  the bridge's `astro-admin.{js,css}` into the customer project's
  `public/manage-assets/` and `/manage` embeds the Astro CMS.

**The Application Password never reaches the browser.** Sign-in seals it into a
second host-only, `HttpOnly` cookie (`nvoos_forge_cred`, AES-256-GCM, HKDF from
the manage secret, session uid as associated data); the BFF attaches it to
`nvoos-commerce/v1` calls and forces `private, no-store` on any
credential-scoped response. This is the OWASP BFF pattern.

**`/manage` ships no inline script.** The session config travels as a
`type="application/json"` data block and `public/manage-assets/manage-boot.js`
does the injection, so the page works under `script-src 'self'` — the velocity
storefront's standard.

**SRI pins the bundle.** `integrity="sha384-…"` on both files (also recorded in
`nvoos.json` → `admin.bundle`); a stale or substituted bundle is refused by the
browser. A missing bridge checkout **fails the scaffold closed** with a named
error.

The bundle lands in the **customer project only** — never in `templates/blog/`,
which is mirrored to the public forge repo.

## 5. Ops

```sh
# Scaffold (the gate verdict is resolved here unless --gate is given)
node scripts/forge.mjs sites/eca.json --target <dir> [--gate auto|off|maintenance|coming_soon]

# What the artifact recorded
node -e "console.log(require('<dir>/gate.generated.json'))"

# Runtime env (Velocity app)
NVOOS_TARGET_SITE=https://cms.eca.directory   # the CMS origin
NVOOS_FORGE_GATE_SECRET=<random>               # bypass links + session fallback
NVOOS_MANAGE_SECRET=<random>                   # signs /manage (falls back to the gate secret)
NVOOS_REVALIDATE_TOKEN=<random>                # enables POST /api/gate
PORT=<injected by Velocity>
```

## 6. The drill (condensed)

1. Turn the CMS gate on (Elementor: mode **and** template must both be set).
2. Within the poll window the public site shows the holding page; `curl -I /`
   → `503`, `Retry-After`, `private, no-store`, `x-robots-tag`.
3. Anonymous in a private window → still gated.
4. Sign in at `/manage` with an Application Password → the **same** browser
   sees the real site (`x-nvoos-gate: bypass-manage`), with no CMS round-trip.
   A subscriber is refused at sign-in (403, no cookie).
5. `POST /api/gate` with the bearer token → the mode flips without a rebuild.
6. Turn the CMS gate off → the public site returns on the next poll.
7. A stale/tampered session → gated again.

## 7. Open items

- §8.9 (the ECA bridge build's actual deltas) was not reconstructed. Known:
  bridge **1.2.0** serves `content/ecas` + `content/eca-categories` and is live
  on `cms.eca.directory` (105 activities).
- The velocity storefront's `buildAdminConfig()` omits `user.features`, so its
  `/manage` cannot show the Activities nav; the forge's does. One-line fix on
  the velocity side.
- Nothing in this proposal is committed yet beyond the parts already on
  `alpha-working`; the loss that prompted this reconstruction is itself the
  argument for committing early.
