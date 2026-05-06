# Third-Party Notices — NV oOS SaaS Controller Addon

This file is the per-addon attribution surface for `addons/saas-controller/`.
The canonical, repo-wide attribution index is the root [`CREDITS.md`](../../CREDITS.md);
this file mirrors the SaaS-Controller-specific entries with full
name / version / license / copyright / homepage metadata.

> **License of the addon itself:** the NV Digital Solutions code in this
> addon is **proprietary** (see [`LICENSE`](LICENSE) in this directory). The
> third-party components listed below remain governed by their own upstream
> licenses (MIT / BSD-3-Clause / Apache-2.0 / GPL-2.0-or-later as applicable);
> their inclusion does not relicense any NV Digital Solutions code, and the
> proprietary `LICENSE` does not modify the terms of those upstream
> components.

> **Last reviewed:** May 2026

---

## Bucket A — Worker build-time (devDependencies; not shipped at runtime)

The Worker is bundled to a single ESM file (`worker/dist/index.js`) by esbuild.
None of the packages in this section ship inside the release ZIP — only the
compiled artifact does.

| Package | Version | License | Copyright | Homepage |
|---|---|---|---|---|
| `wrangler` | ^4.59.1 | MIT OR Apache-2.0 | © Cloudflare, Inc. | <https://github.com/cloudflare/workers-sdk> |
| `@cloudflare/workers-types` | ^4.20250109.0 | Apache-2.0 | © Cloudflare, Inc. | <https://github.com/cloudflare/workerd> |
| `esbuild` | ^0.24.2 | MIT | © Evan Wallace | <https://github.com/evanw/esbuild> |
| `miniflare` | ^4.20250109.0 | MIT | © Cloudflare, Inc. & contributors | <https://github.com/cloudflare/workers-sdk/tree/main/packages/miniflare> |

**Security note:** `wrangler` is pinned to **^4.59.1** because earlier versions
(< 3.114.17 and < 4.59.1) are affected by a published GHSA OS-command-injection
advisory in `wrangler pages deploy` (verified via the GitHub Advisory Database
during planning; do not relax this floor).

---

## Bucket B — Admin UI runtime (bundled into `assets/build/`)

Bundled into a single JS/CSS asset pair by `@wordpress/scripts`. The packages
below are the only npm dependencies that physically appear in the
distributed plugin ZIP, embedded inside the built bundle.

| Package | Version | License | Copyright | Homepage |
|---|---|---|---|---|
| `@tanstack/react-query` | ^5.62.0 | MIT | © Tanner Linsley & TanStack contributors | <https://tanstack.com/query> |
| `zod` | ^3.24.1 | MIT | © Colin McDonnell | <https://zod.dev/> |
| `diff` | ^7.0.0 | BSD-3-Clause | © Kevin Decker & jsdiff contributors | <https://github.com/kpdecker/jsdiff> |
| `date-fns` | ^4.1.0 | MIT | © date-fns contributors | <https://date-fns.org/> |
| `clsx` | ^2.1.1 | MIT | © Luke Edwards | <https://github.com/lukeed/clsx> |

**WordPress core externals** — `@wordpress/element`, `@wordpress/components`,
`@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/data`, `@wordpress/icons`,
`@wordpress/url` — are auto-externalized by `@wordpress/scripts` and resolved
against the running WordPress install at admin-page load time. They are
**not** bundled into our artifact and therefore are not redistributed by us;
attribution remains with WordPress core (GPLv2-or-later).

---

## Bucket C — Dev tooling (devDependencies; never shipped)

| Package | Version | License | Homepage |
|---|---|---|---|
| `@wordpress/scripts` | ^30.0.0 | GPL-2.0-or-later / MPL-2.0 | <https://github.com/WordPress/gutenberg/tree/trunk/packages/scripts> |
| `@wordpress/eslint-plugin` | ^21.0.0 | GPL-2.0-or-later | <https://github.com/WordPress/gutenberg/tree/trunk/packages/eslint-plugin> |
| `@wordpress/jest-preset-default` | ^12.0.0 | GPL-2.0-or-later | <https://github.com/WordPress/gutenberg/tree/trunk/packages/jest-preset-default> |
| `@wordpress/prettier-config` | ^4.0.0 | GPL-2.0-or-later | <https://github.com/WordPress/gutenberg/tree/trunk/packages/prettier-config> |
| `typescript` | ^5.7.2 | Apache-2.0 | <https://github.com/microsoft/TypeScript> |
| `npm-run-all` | ^4.1.5 | MIT | <https://github.com/mysticatea/npm-run-all> |
| `@types/diff` | ^7.0.0 | MIT | <https://github.com/DefinitelyTyped/DefinitelyTyped> |
| `@types/wordpress__components` | ^23.0.0 | MIT | <https://github.com/DefinitelyTyped/DefinitelyTyped> |

---

## Reporting an attribution error

If anything here is incomplete, incorrect, or out of date, please open an
issue or PR against the [mcp-ai-wpoos repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos).
Updates here must be mirrored in the repo-wide [`CREDITS.md`](../../CREDITS.md)
in the same commit (the `bin/verify-credits.sh` script enforces this).
