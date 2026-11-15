# Template endpoints and the deploy-branch README

Cost-paid notes from adding `/README.md` as a build-time endpoint (2026-11-09).
Every item here was a failing `astro check` or a silently-absent file first.

## 1. A README on the mirror branch must be an ARTIFACT

`site/<name>` branches in `nvdigitalsolutions/nvoos-forge-sites` are
**force-pushed on every build**: `mirror-push.mjs` clones, `git checkout
--orphan`, `git rm -rf .`, `cpSync(SOURCE_DIR, tmp)`. Anything hand-written on
the branch is deleted by the next build.

So a report README has to ship through `dist/`, beside `design-audit.json` and
`tokens.dtcg.json`. Do **not** hand-edit the branch, and do not "reset" it to
add a file — rebuild instead (`Forge Sites` workflow, `site: <slug>`).

## 2. `nvoos.json` OMITS keys when they are unset

The manifest is written with conditional spreads and `JSON.stringify`:

```ts
...( hasTrackingModule && profile.tracking ? { tracking: {...} } : {} ),
kind: c.kind,   // undefined ⇒ the key disappears from the JSON
```

A JSON module's inferred type follows the literal file, so on the presets that
omit them, `manifest.tracking` and `collection.kind` **do not exist** and
`astro check` fails with `ts2339`.

Read the manifest through an explicit shape with every field optional:

```ts
type ManifestShape = { tracking?: {...}; collections?: Array<{ kind?: string }>; site?: string };
const meta = manifest as unknown as ManifestShape;
```

An `as` cast to an all-optional type hits the weak-type check — cast through
`unknown`. Never trust the inferred JSON type for anything conditional.

## 3. `getCollection` is typed per preset

The scaffold prunes collections by preset, so `'products'` is not a key of the
generated project's `DataEntryMap` on a blog-only site. A direct
`getCollection('products')` is a **type** error even though it would work at
runtime once Woo is present.

```ts
const loadCollection = getCollection as unknown as ( name: string ) => Promise< Array< unknown > >;
try { count = ( await loadCollection( 'products' ) ).length; } catch { count = 0; }
```

Wrap it in `try`/`catch` as well: an optional catalogue must never take the
build — and therefore the deploy — down.

## 4. `.md.ts` endpoints work, and `-t` will fool you

`src/pages/<route>.md.ts` emits `<route>.md` (same mechanics as
`sitemap.xml.ts` → `sitemap.xml`; `posts/[...parts]/index.md.ts` already
proved it). A `.ts` endpoint takes **no frontmatter fence**.

When debugging a missing artifact in `forge-e2e.test.ts`, remember its build
runs in a *separate* `it()`. `vitest -t "<my test>"` skips that build, so
`dist/` does not exist and the failure looks like a missing route. Run the
whole file.

## 5. Determinism is the contract

The composer reads no clock: the timestamp is the manifest's `generatedAt`,
not `Date.now()`. Same discipline as `lib/llms.ts` — a no-op rebuild must not
churn a file that ships to a branch.
