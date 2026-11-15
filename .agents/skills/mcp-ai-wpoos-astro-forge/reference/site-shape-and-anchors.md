# One-pager shape, anchors, and the per-site gate

2026-11-09. Everything here was either a silently-broken gate or a defect that
reached a live client branch first.

## 1. `shape` is a SETTING, not an inference

`--inventory` derives a verdict into `profile.inventory.shape`
(`one-pager | multi-page | hybrid`) — and for a long time that verdict was only
**printed**. It drove nothing, and jayainc had no `inventory` block at all, so
"one-pager" was a report line rather than a decision.

`profile.shape` is now the operator setting:

```ts
resolveSiteShape( profile ) = profile.shape ?? profile.inventory?.shape ?? 'multi-page'
```

An inference must never outrank a decision, and an unanalysed profile is
multi-page (the safe default). The scaffold records the resolved `shape` and
the `navAnchors` in `nvoos.json`; the branch README shows a `Site shape` row;
`analyze` warns when the profile does not declare it, or when the declared
value disagrees with the source.

## 2. The sanitizer strips `id` unless you allow it

`id` was in **no** `allowedAttributes` entry, so every source anchor
(`<section id="about">`) was removed and in-page navigation could never work.
`id` is now allowed on `section` and `div` only, and only when it matches a
plain identifier and does not shadow a forge-owned namespace:

```ts
const SAFE_ANCHOR_ID = /^[a-z][a-z0-9_-]{0,40}$/i;
const RESERVED_ANCHOR_ID = /^(nvoos-|pagefind|ds-)/i;
```

`id` is inert, so this adds no execution surface. If an anchor ever lives on
another tag, extend **both** the allowlist and `anchorId()` — adding the
attribute without the value check re-opens id collisions with our components.

## 3. Header nav precedence

`templates/blog/src/layouts/Base.astro`:

1. `design.chrome.nav` — the analysed nav (jayainc: HOME/ABOUT/PRODUCTS/…);
2. `manifest.navAnchors` — only for `shape === 'one-pager'` (virtuallyjamaica);
3. the generic Home/Posts/Search links.

A content-driven site has no `chrome.nav`, which is why step 2 exists. Labels
come from the anchor (`about-us` → `About Us`); never scrape the source nav.

## 4. `verify` runs in CI now — mind what it does NOT cover

`forge-sites.yml` runs `verify --seo --tracking` **before** the mirror push and
aborts on failure. Before that, the gates only ran when a human typed the
command — which is how a broken band-flow parser and a demo post shipped.

- The one-pager anchor check is a **warning** (`⚠ Nav anchor: #x …`), not a
  failure, so a dead link is visible without blocking a deploy.
- **Band flow compares `design.sections` only.** A content-driven site
  (`sections: []`) has no coverage at all — a band can vanish and nothing
  notices. That is the #7549 class.
- `--geometry` is not run in CI: it needs Playwright on the `forge-builder`
  runner.

## 5. NEVER `analyze --write` over a hand-authored profile

`mergeProfileDraft()` does `merged.design = applyDesignPins( fresh.design, … )`
— the **whole design block is replaced**, taking hand-authored `sections`,
`palette`, `chrome` and the `pins` rationale with it. jayainc's nine band
sections would have been destroyed.

Recipe:

```sh
node scripts/forge.mjs analyze <url> --write sites/.fresh-<site>.json \
  --design --wireframes --vocabulary --geometry --tracking
# then merge ONLY the keys you want (builder, outline, vocabulary) into the
# real profile, and prove it: `git diff sites/<site>.json` must show no
# deletions other than the vocabulary snapshot + analyzedAt.
```

## 6. Placeholder content used to ship by default (fixed)

`wp-loader.ts` merges the local MDX glob **under** the WP entries, and the
template seeded `src/content/posts/hello-world.mdx` — so every scaffolded site
published "Hello from the forge" at `/posts/hello-world/hello-world/`.

`scaffold()` now removes the seed unless `demoContent: true` (the offline demo
and hybrid-local e2e opt in), and `forge-sites.yml` asserts
`dist/posts/hello-world` does not exist.

## 7. Small traps

- **`builtBandsOf()` must be attribute-order independent.** It required
  `data-section-id` immediately after `<div`; the renderer emits
  `data-section` first, so the regex matched nothing and the gate reported
  every band as dropped. Match the tag, read the attributes independently, and
  keep the **first** (outermost) wrapper per id — 23 tags carry 9 ids.
- **`vitest -t "<one test>"` skips the build `it()`.** `dist/` is then absent
  and the failure looks like a missing route. Run the whole file.
- **Windows `swapStagedProject` can `EPERM` on rename** under load (a different
  scaffold test fails each run, and passes in isolation). Trust CI.
- **`analyze <url>` builds a FRESH profile** — it will not see `shape`/`pins`
  from a committed file. Only `analyze <site.json>` loads one.
