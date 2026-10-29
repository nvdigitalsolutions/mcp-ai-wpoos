# Funiq Payload Admin — Velocity App (Proposal 085 rollback leg)

Cloudways **Velocity** (managed Node.js: NGINX + PM2) deploy of the
**licensed Funiq CMS** — the Payload 3 admin that backs the licensed Funiq
mobile app. This is the **A/B rollback leg** re-scoped by the 2026-10-29
decision record in `docs/project/plans/funiq-bridge-velocity-storefront-implementation.md`
(Proposal 092; owner decision Q1: keep A/B until parity review, then
retire).

> **What the licensed item actually is (verified 2026-10-29).** The Envato
> item "Funiq — Furniture Store App with Payload CMS" contains **no web
> PWA**. It ships (a) a React Native 0.82 mobile app (`funiq/`, Android +
> iOS only) and (b) a Payload 3.64 CMS on Next.js 15 (`payload-cms/funiq-cms/`).
> The original plan's "serve the PWA `dist/` on Velocity" premise was
> therefore invalid; owner decision **option (a)**: repackage this rollback
> leg around the **Payload admin** — the "actual Funiq React backend".
> Forensics + parity mapping:
> `docs/project/plans/funiq-pwa-parity-review-checklist.md` §1–§2.

> **One-way mirror notice.** The deployed repo
> `nvdigitalsolutions/nvoos-funiq-velocity` is a one-way mirror assembled by
> the sync workflow in the monorepo. History is intentionally flat (one
> commit per sync, force-pushed). **Never commit to the mirror directly** —
> changes made there are overwritten by the next sync. Open PRs against the
> monorepo instead.
>
> **License boundary (Envato).** The Payload CMS build output is a
> PURCHASED, Envato-licensed item. Its source lives only in the **private**
> repo `nvoos-funiq-pwa`; its built artifact lives only in the **private**
> mirror `nvoos-funiq-velocity`. **No licensed bytes ever enter the public
> monorepo** — this example ships only NV-authored deploy scripts and docs,
> and the sync workflow fails if the mirror target is not private.

## Architecture

```
Mobile app (React Native) ──MAIN_URL──┐
Browser ──────────────────────────────┤
                                      ▼
        Velocity app (node server.js — Next.js standalone)
              ├── /admin                 Payload admin UI       ← licensed build
              ├── /api/*                 Payload REST API       ← licensed build
              │    (products, categories, brands, colors,
              │     statuses, promocodes, globals/banner,
              │     globals/carousel, graphql, …)
              ├── /media/*               uploaded media (local disk by default)
              └── PostgreSQL             DATABASE_URI (adapter: @payloadcms/db-postgres)
```

This leg serves the **licensed app's native contract** — the nine GET
endpoints the mobile app binds to (`{docs, totalDocs}` Payload envelope).
It has **no proxy layer**: Payload serves its own API and admin from one
origin. The Astro storefront leg (`nvoos-astro-velocity`) serves the
**bridge contract** (`nvoos-commerce/v1` against WordPress). The two legs
are independent; no A/B web-rule splitting is required between them (the
storefront A/B is Astro vs. the previous manual PWA deploy, see the plan).

The WordPress Funiq CMS (`wp-admin → Funiq CMS`, the bridge admin SPA)
remains reachable same-origin from the Astro app via its `/wp-admin/*`
proxy + `ManageLink` (PR #7289) — unaffected by this leg.

## Mirror layout (assembled by `scripts/assemble-mirror.mjs`)

| Path | Origin | Notes |
|---|---|---|
| `server.js` | `.next/standalone/server.js` | Next's own entry — Velocity entry file |
| `src/` | standalone `src/` + `src/migrations/` | Payload runtime files + deploy migrations |
| `.next/` | full build output (no `cache/`, no `standalone/`) | `BUILD_ID`, manifests, `static/` |
| `package.json` + `package-lock.json` + `.npmrc` | vendor manifest | Velocity runs its normal `npm install` |
| `.env.example` | NV-authored | `DATABASE_URI`, `PAYLOAD_SECRET`, `HOSTNAME` |
| `LICENSE.txt` | private repo root | Envato purchase proof |
| `README.md`, `THIRD_PARTY_NOTICES.md`, `scripts/smoke.mjs` | this example | runbook + hermetic smoke |

Velocity runs **no build command** — only `npm install` + `node server.js`.
This layout was validated locally end-to-end on 2026-10-29 (Payload 3.64 /
Next 15.4, PostgreSQL 16, migrations applied, `/admin` · `/api/products` ·
assets · `/` all 200).

## Local smoke (hermetic — what CI runs)

```bash
# 1. Build the licensed CMS out of the private repo (dev-only):
git clone git@github.com:nvdigitalsolutions/nvoos-funiq-pwa.git /tmp/nvoos-funiq-pwa
CMS=/tmp/nvoos-funiq-pwa/payload-cms/funiq-cms
node scripts/enable-standalone.mjs "$CMS"
(cd "$CMS" && npm ci && npm run build)           # PAYLOAD_SECRET not needed at build

# 2. Hermetic Postgres (or use any reachable Postgres):
docker run -d --name funiq-pg -e POSTGRES_PASSWORD=postgres \
  -e POSTGRES_DB=funiq_smoke -p 55432:5432 postgres:16-alpine

# 3. One-time schema (Payload production never auto-pushes):
export DATABASE_URI=postgres://postgres:postgres@localhost:55432/funiq_smoke
export PAYLOAD_SECRET=smoke-test
(cd "$CMS" && npx payload migrate:create initial && npx payload migrate)

# 4. Assemble the mirror tree + smoke it:
node scripts/assemble-mirror.mjs "$CMS" /tmp/nvoos-funiq-velocity
cp -r "$CMS/node_modules" /tmp/nvoos-funiq-velocity/node_modules
(cd /tmp/nvoos-funiq-velocity && node scripts/smoke.mjs .)
```

## Owner activation (one-time)

1. ✅ Private repos `nvoos-funiq-pwa` (licensed source) and
   `nvoos-funiq-velocity` (empty mirror) — **done 2026-10-29**.
2. ✅ Monorepo secrets `NVOOS_FUNIQ_PWA_READ_TOKEN` (read-only clone) and
   `NVOOS_FUNIQ_VELOCITY_REPO_TOKEN` (push to the mirror) — **done**. The
   workflow skips with a notice until both exist.
3. Run the sync workflow once via `workflow_dispatch` to backfill the
   mirror (builds the CMS in CI, applies migrations against a throwaway
   Postgres, runs the smoke, force-pushes the mirror).
4. In Velocity: create a Node.js app and connect the mirror repo:

   | Setting | Value |
   |---|---|
   | Root directory | repo root |
   | Entry file | `server.js` |
   | Build command | none |
   | Node version | 22 LTS (vendor engines: ≥ 20.9) |

5. App-level env vars in Velocity: `DATABASE_URI` (PostgreSQL — Velocity
   managed DB if available, else any managed Postgres), `PAYLOAD_SECRET`
   (64-char random), `HOSTNAME=0.0.0.0`, `NODE_ENV=production`. Velocity
   injects `PORT` — never set it manually.
6. First boot creates the admin user on `/admin` (Payload's standard
   "create first user" flow). The schema is created by the migrations
   shipped in `src/migrations/` — if you point the app at a brand-new
   database, apply them first from the **private source checkout**:
   `npx payload migrate` (the mirror ships the files; the CLI needs the
   full config, which lives in `nvoos-funiq-pwa`).

## Ops notes

- **Uploads persistence.** Payload stores uploads on local disk by default
  (under the app tree). A git-connected Velocity redeploy that resets the
  working tree may drop untracked upload files. The vendor template's
  `storage-adapter-import-placeholder` markers show the intended production
  path is the S3 storage adapter (e.g. `@payloadcms/storage-s3` against
  Cloudflare R2). **Open decision for the owner** — local disk is fine for
  the rollback leg; switch to S3 before the leg hosts real media.
- **Mobile app pointing.** The app's `MAIN_URL` (`funiq/src/config/index.tsx`,
  default `http://localhost:3000`) must be rebuilt to point at this
  Velocity origin for the app to consume the hosted API. App builds happen
  in the private repo (not here). Note the app's list hook prefixes
  `MAIN_URL` onto `product.image` unconditionally while Payload emits
  absolute URLs — fix that line when rebuilding (see the parity checklist
  §2.2, quirk Q1).
- **Backups.** Everything durable is in PostgreSQL + uploads. Back up the
  database; media is regenerable from the bridge (or keep S3 as the
  durable store).
- **Re-sync cadence.** No content rebuild loop exists (Payload serves the
  DB live — nothing is baked at build time for content). Re-run the sync
  only when the licensed source or this recipe changes; `workflow_dispatch`
  does it.

## Rollback semantics

The leg is the **licensed backend/admin** fallback: if the WP bridge +
Astro storefront pair fails, the mobile app + Payload admin continue to
work against this app (and can be re-pointed at any time by rebuilding
`MAIN_URL`). Storefront A/B splitting (Astro vs. the previous manual PWA
deploy) is unchanged and documented in
`docs/operations/deployment/nvoos-astro-velocity-setup.md`.
