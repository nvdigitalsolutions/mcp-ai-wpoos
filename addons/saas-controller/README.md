# NV oOS SaaS Controller Addon

**Operator-side toolkit to deploy and manage the NV oOS Cloud control plane** (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter) from inside WP-Admin.

This addon is the operator-side counterpart to `addons/cloud-worker/`. Where `cloud-worker` is the deployed runtime, the **SaaS Controller** is the WordPress plugin that lets a maintainer **provision, plan/apply changes to, drift-check, and audit** that runtime — without leaving WP-Admin.

> **Status:** v0.1.0 scaffolding. Subsequent PRs will land the One-Click Wizard, Plan/Apply dashboard, drift banner, audit-log viewer, and smoke tests.

## Features (planned)

- **One-Click Wizard** — collect Cloudflare/Stripe/OpenRouter credentials, validate them, and provision D1 + KV + Worker bindings.
- **Plan / Apply** — terraform-style preview of every reconcile action before it runs (D1 schema diffs, secret rotations, Worker updates).
- **Drift Detector** — periodic check of the deployed Worker vs. the addon's pinned `dist/index.js` checksum.
- **Audit Log** — every Cloudflare/Stripe call captured with operator + result.
- **Smoke Tests** — one-click "is the SaaS reachable?" preflight.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS base plugin (active)
- Node.js 18.17+ (build-time only, never required at runtime)

## Installation

1. Upload the `nvoos-saas-controller` folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Navigate to **NV oOS → SaaS Controller** to launch the wizard.

## Architecture — npm Package Buckets

Every npm dependency in this addon falls into one of three buckets:

| Bucket | Where it lives | Ships in distribution ZIP? |
|---|---|---|
| **A. Worker build-time** | `worker/src/` → bundled to `worker/dist/index.js` via esbuild | Built artifact only — `node_modules/` excluded |
| **B. Admin UI runtime** | `assets/src/` → built to `assets/build/` via `@wordpress/scripts` | Built bundle only — sources excluded |
| **C. Dev tooling** | `devDependencies` in `package.json` | Never |

WordPress.org compliance: `node_modules/` is never shipped. Only the compiled artifacts under `assets/build/` and `worker/dist/` are included in the release ZIP.

## Build

```bash
cd addons/saas-controller
npm ci
npm run build           # builds both Worker and Admin UI bundles
npm run build:worker    # esbuild → worker/dist/index.js
npm run build:admin     # @wordpress/scripts → assets/build/
npm run typecheck       # tsc --noEmit
npm run lint:js         # eslint via @wordpress/scripts
npm run test            # jest via @wordpress/scripts
npm run worker:dryrun   # wrangler deploy --dry-run (no live publish)
```

The repo-wide `bin/build-addon-zips.sh` orchestrates this for releases and emits `build/nvoos-saas-controller-vX.Y.Z.zip`.

## Credits

This addon bundles the following third-party JavaScript libraries at runtime. Full per-package metadata (version, license, copyright, homepage) is in [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md), and the canonical repo-wide index is in the root [`CREDITS.md`](../../CREDITS.md).

| Package | License | Purpose |
|---|---|---|
| [`@tanstack/react-query`](https://tanstack.com/query) | MIT | Polling reconcile-job status, drift results, audit log. |
| [`zod`](https://zod.dev/) | MIT | Client-side schema validation of credentials & reconcile-plan JSON. |
| [`diff`](https://github.com/kpdecker/jsdiff) | BSD-3-Clause | Plan-preview before/after rendering. |
| [`date-fns`](https://date-fns.org/) | MIT | Audit-log timestamps and "last checked X ago" labels. |
| [`clsx`](https://github.com/lukeed/clsx) | MIT | Conditional className helper. |

WordPress core externals (`@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/data`, `@wordpress/icons`, `@wordpress/url`) are loaded from WP-Admin and are not bundled.

Build-time and dev-only packages (e.g. `wrangler`, `esbuild`, `@cloudflare/workers-types`, `@wordpress/scripts`, `typescript`, `miniflare`) are not shipped — see [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) for the full inventory.

## License

GPL-3.0-or-later. See the repository root `LICENSE`.
