# CSP + client navigation (transferred from velocity, 2026-11-09)

How the Velocity storefront's CSP outage maps onto forge-generated projects.
Full playbook (route-by-route hash comparison, the `client:load`-only rule,
the Cloudflare finding): `mcp-ai-wpoos-astro-storefront` → `## CSP + ClientRouter`.

## What the forge generates today

- **`script-src 'self' 'unsafe-inline'`**, emitted by `lib/scaffold.ts` — not
  hashes. Deliberate: the template ships several `is:inline` scripts
  (`templates/blog/src/layouts/Base.astro` theme boot,
  `components/ConsentBanner.astro`, `components/Tracking.astro`,
  `components/design/Form.astro`).
- **No `ClientRouter` and no `client:*` islands** in the template.
- **Managed/SSR mode emits `routeRules`** with `maxAge: 300, swr: 600` for
  `/products/**` and `/posts/**`, plus `maxAge: 0` for `/manage/**` and
  `/api/**`; `lib/managed-runtime.ts` sets `prerender = false`, and
  `lib/scaffold.ts` can inject `export const prerender = true` for mixed mode.

Those two facts are why the outage that hit velocity does **not** affect forge
output today — and they are the two things to keep from drifting.

## The hazard, stated generally

A hash-scoped `script-src` is computed per **document**, and a client-side
navigation keeps the SOURCE document's policy (a fetched fragment's CSP header
is ignored). So the moment a project has both:

1. a hashed `script-src`, and
2. any client-side routing (`ClientRouter` / view transitions),

every inline script on a navigated-to page must also exist on the page you
navigated FROM, or it is blocked. Astro's `client:visible` and `client:idle`
also emit a per-page inline bootstrap (`IntersectionObserver` /
`requestIdleCallback`), so even directives reintroduce the variance. Only
`client:load` adds nothing.

`'unsafe-inline'` avoids the whole class — which is exactly why the template
uses it. Tightening to hashes is a deliberate project decision with this cost,
not a free upgrade.

## `swr` + incremental deploys

`swr: 600` legitimately serves stale HTML while revalidating. Two consequences
worth knowing before handing a project to an incremental host:

- With a hashed CSP, stale HTML carries the previous build's island directives
  and inline scripts → CSP violations on navigation into it.
- With `unsafe-inline`, no CSP error — but the page silently shows stale
  content, which is worse because nothing surfaces it.

## Static-file shadowing (the nine-day-stale PDP)

Velocity deploys the mirror incrementally and **never deletes files that left
the repo**. A prerendered page that is removed from the tree keeps being served
from `dist/client` and **shadows the SSR route** (the Node adapter serves
`dist/client` before the renderer). On velocity this froze
`/products/1/aurora-3-seater-sofa/` at 31-Oct build-time markup for nine days,
referencing a chunk no build produced, and it survived **restarts** and
**redeploys**.

Only a **fully** SSR project may purge `dist/client/**/*.html` at boot (a
correct build emits none) — velocity does, in
`scripts/lib/purge-prerendered.mjs`, with `scripts/sync-dist.mjs` refusing to
assemble a tree that contains any. A project with `export const prerender =
true` pages must **not** purge: its HTML is legitimate, and it needs full-tree
deploys instead.

## Triage rule

When exactly ONE route is stale across restarts *and* redeploys, and a
cache-buster (`?cb=999`) changes nothing, suspect **static-file shadowing**, not
caching. Compare the page's island directives (`client="load|visible|idle"`) and
`/_astro/<chunk>.<hash>.js` names against a local build — a chunk hash absent
from your build proves the origin is serving a stale file.
