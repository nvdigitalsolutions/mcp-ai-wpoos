# 163 — ECA front-end implementation plan

**Status:** plan (follows `163-eca-frontend-proposal.md`) — **P0, P1 and P2 complete; P3 surface complete** (2026-11-24)
**Date:** 2026-11-24
**Baseline:** the eca.directory forge build (`examples/nvoos-astro-forge/sites/eca.json`,
7 bands, 122 pages, chrome/band/SEO gates green)

**Landed so far** (`2137` forge tests green, generated site builds 143 pages):

| Phase | Artifact | Evidence |
|---|---|---|
| P0 | `lib/eca.ts` — `nvoos-eca/v1` normaliser, facets, ICS, validator | 134 tests; 0 validation errors over all 105 live records; stage 93/105 |
| P1 | `eca` preset, `applyPresetToProfile` repair, `lib/eca-scaffold.ts` | `sites/eca.json` scaffolds; `meta` passthrough reaches the project |
| P2 | `lib/eca-ui.ts` — card, facet rail + island, booking banner, catalogue, 4 landing families | `/activities/` 105 cards; `/stage/senior-school/` 31; 5 type + 7 category landings |
| P2/P3 | detail page + per-activity `.ics` | 103 feeds; `EXDATE` on the half-term Tuesday; `Course`/`CourseInstance` JSON-LD |

Outstanding: P3 availability refresh (D2, deferred), P4 calendar/timetable/term
page, P5 site search + shortlist + info pages, P6 Laravel parity, P7 gates/rollout.

Estimates are engineering days for one developer; every phase ends green on
`npm test`, `npm run typecheck` and the phase's own gate.

---

## 0. Workstreams

| WS | What | Lives in |
|---|---|---|
| **A. Format** | `nvoos-eca/v1` types, normaliser, derivation rules, facet engine, ICS builder | `examples/nvoos-astro-forge/lib/eca.ts` (+ tests) |
| **B. Config** | `profile.eca` block, `eca` preset, scaffold emissions | `lib/types.ts`, `lib/presets.ts`, `lib/scaffold.ts` |
| **C. UI** | cards, facets, detail, stage/day/category, calendar, timetable, shortlist, search | `templates/blog/src/{components/eca,pages,scripts,styles}` |
| **D. Contracts** | WP projection endpoint, Laravel routes, JSON Schema, contract tests | `addons/pro/includes/rest/`, Laravel app, `lib/eca.ts` validator |
| **E. Gates** | `verify --eca`, axe, ICS validity, JSON-LD, sitemap policy | `lib/eca-audit.ts`, `scripts/forge.mjs`, e2e |

`A` is the only workstream that everything else imports; it ships first and alone.

---

## Phase 0 — Format + normaliser (1.5 d)

**Goal:** a pure, tested module that turns raw ECA records into `nvoos-eca/v1`.

Tasks

1. `lib/eca.ts`:
   - types: `EcaRecord`, `EcaSchedule`, `EcaCapacity`, `EcaPricing`, `EcaStage`,
     `EcaConfig`, `EcaFacets`.
   - `normalizeEca(raw, config)` — meta-first, derivation-second, `inferred[]`.
   - derivation helpers: `inferDay`, `inferStage`, `inferYearGroups`,
     `parseTimes`, `parsePrice`, `inferProvider`, `inferVenue`.
   - `buildFacets(records)` — counts + fixed facet order.
   - `filterEca(records, selection)` — OR within a facet, AND across.
   - `buildIcs(records, term)` — RFC 5545, `RRULE`, stable `UID`.
   - `validateEcaRecord(record)` — returns the field paths that violate the
     contract (shared with `verify --eca`).
2. `tests/eca.test.ts` — fixtures:
   - a **full-meta** record (all `_eca_*` present);
   - a **thin/live** record (title-only day, prose price “Rs. 22,500 per student
     per term”, “external provider Meu Labs”);
   - a **free, internal, junior** record;
   - a **cancelled / invite-only / audition** record;
   - `buildFacets` ordering + counts; `filterEca` (multi-value OR, cross-facet
     AND, empty result); `buildIcs` (single VEVENT, `RRULE`, term clipping,
     unique `UID`s); `validateEcaRecord` (missing slug, bad time, bad currency).
3. Fixture JSON captured from the live REST (2–3 records) so the thin path is
   pinned to reality.

Acceptance

- `tests/eca.test.ts` ≥ 30 assertions, all green; `tsc --noEmit` clean.
- No `fetch`, no Astro import, no `Date.now()` in `lib/eca.ts` (determinism).
- Every derivation appends to `inferred` and the test asserts the exact list.

Risks: over-eager prose parsing (mitigated: capitalised-token + range checks,
`inferred` surfaced); locale-specific money (mitigated: `EcaConfig.currency`).

---

## Phase 1 — Profile block, preset, scaffold emissions (1 d)

Tasks

1. `lib/types.ts`: `SiteProfile.eca?: EcaConfig` (schema, basePath, schoolName,
   timezone, currency, terms, stages, days, timeBands, categoryColors,
   venueAliases).
2. `lib/presets.ts`: `eca` descriptor; `suggestPreset()` returns `eca` when a
   collection's `postType`/`restBase` is `mcp_ai_eca` (or `nvoos-eca`).
   `applyPresetToProfile()` seeds `profile.eca` defaults (currency from the
   design locale, one stage pair, current term placeholder).
3. `lib/scaffold.ts`:
   - emit `src/lib/eca.generated.ts` (config + basePath + stage list);
   - emit the ECA routes/components **only** when the preset is `eca` (the blog
     template stays byte-identical for existing sites);
   - wire `/search-index.json` and the `.ics` endpoints;
   - add the redirect block (`/eca/*` → `/activities/*`, day pages, junior list).
4. `tests/scaffold.test.ts` — a preset-`eca` scaffold asserts: the routes exist,
   `eca.generated.ts` carries the config, the redirect map contains the legacy
   paths, and a non-`eca` profile emits none of it.

Acceptance

- Forge an ECA profile into `./eca-frontend`, `astro check` + `astro build` pass
  against the live dataset (105 activities) with **no** ECA pages failing.
- Existing preset e2e (blog/storefront/minimal) unchanged and green.

---

## Phase 2 — Catalogue, facets, cards, landings (3 d)

Tasks

1. Components (`templates/blog/src/components/eca/`):
   - `ActivityCard.astro` — title, category chip, day/time, venue, year groups,
     price, availability badge; one link; shortlist button.
   - `FacetPanel.astro` — `<fieldset>` groups, counts, chips, clear-all,
     drawer variant ≤ 767 px.
   - `ActivityFacts.astro`, `AvailabilityBadge.astro`, `CategoryChip.astro`,
     `EmptyState.astro` (with nearest non-empty suggestions).
2. Routes:
   - `activities/index.astro` — H1, count, sort, facets, grid, no-JS list.
   - `stage/[stage].astro`, `day/[day].astro`, `category/[slug].astro` — the
     same grid with a preset selection + their own copy.
   - `activities/[slug].astro` — detail (Phase 3 fills the facts/ICS).
3. `src/scripts/eca-facets.ts` (island ≤ 40 KB gzip):
   - reads the embedded index, applies `filterEca`, syncs `?q&day&stage&cat&year&time&avail&price&sort`,
     `history.pushState`, `aria-live` count, focus to the results heading,
     restores state on `popstate`, persists selection in `sessionStorage`.
4. CSS: card grid (auto-fit minmax), aspect-ratio images, sticky filter bar,
   drawer, reduced-motion.
5. `src/pages/search-index.json.ts` — `{slug,title,category,day,venue,yearGroups,keywords}`.
6. Tests: e2e scaffold with a 12-activity fixture → assert card count, facet
   counts, URL state round-trip, empty state, axe clean on catalogue + drawer.

Acceptance

- `verify --text` on `/activities/` ≥ 95 % of the rendered activity titles.
- axe: 0 violations on catalogue, drawer open, empty state.
- Facet state survives reload + back-forward; no CLS when the drawer opens.
- Island bundle ≤ 40 KB gzip (asserted in the e2e).

---

## Phase 3 — Detail pages, availability, ICS (1.5 d)

Tasks

1. `activities/[slug].astro`: facts panel, description, “who it's for”, related
   (same category → same day, max 3), CTA by `booking.type`, shortlist.
2. `AvailabilityBadge` states only when `capacity` exists; a “check at
   registration” note when the data is build-time.
3. `activities/[slug]/session.ics.ts` + `feeds/[scope].ics.ts` using `buildIcs`.
4. JSON-LD: `Course` + `CourseInstance` (+ `Event` when dated), `BreadcrumbList`.
5. Tests: ICS parses (line folding, `RRULE`, `TZID`, unique `UID`), JSON-LD
   schema-valid, 404 for an unknown slug, related-links logic.

Acceptance

- Every activity page has: title, facts (at least day when inferred), one CTA,
  a canonical, a breadcrumb.
- ICS files parse with a strict parser; no duplicate `UID` across the site.

---

## Phase 4 — Calendar, timetable, terms (2.5 d)

Tasks

1. `lib/eca.ts` → `expandSessions(records, term)` — weekly expansion clipped by
   term dates + `exclude` ranges; deterministic ordering.
2. `calendar.astro` + island: agenda (default), week, month; term selector;
   stage/category filters reuse the facet state.
3. `CalendarGrid.astro` — `role="grid"`, arrow keys, `aria-live` period label,
   “+n more” overflow.
4. `timetable/[stage].astro` — days × time bands from `profile.eca.timeBands`,
   horizontal scroll + sticky day header.
5. `terms.astro` — term dates + key dates from `profile.eca.terms`.
6. Tests: expansion (term start mid-week, holiday exclusion, fortnightly),
   grid keyboard model, agenda no-JS.

Acceptance

- Agenda renders with JS disabled; week/month degrade to agenda.
- A session never renders outside its term.
- axe clean in all three views.

---

## Phase 5 — Search, shortlist, info pages (1.5 d)

Tasks

1. `search.astro` + island: lazy index load, ranking (title > keywords > category
   > venue), prefix match ≥ 2 chars, synonym map, `/search/?q=` URL state.
2. `shortlist.astro` + `ShortlistButton`: `localStorage` (`nvoos-eca:shortlist:v1`),
   compare table (day/time/venue/price), clear with confirmation.
3. Info pages from the pages collection: `how-it-works`, `faq`, `contact`,
   `terms`, `accessibility`, `policies` — with `/help` in the footer and the
   filter drawer (WCAG 3.2.6).
4. Tests: search ranking fixtures, shortlist persistence, 0-query state, axe.

Acceptance

- Search returns the expected top-3 for 10 fixture queries.
- Shortlist survives navigation and reload; no server call.

### Delivered 2026-11-24 — the contact band and placeholder posts

Two behaviours the fleet's parity gate surfaced on `eca.directory`. Both are
generic (a WP site of any shape hits them), not ECA-specific:

1. **A form with no transport names its people instead.** `https://eca.directory`
   has no forge bridge (`/wp-json/nvoos-forge/v1` → 404), so the source's
   enquiry form cannot post anywhere. `Form.astro` already degraded to a
   title-only band ("never a dead endpoint"); it now renders the school's
   people as mailto cards plus the `/help/` link. The list is DERIVED by the
   scaffold from `eca.contacts` (`resolveContactBlock`), so the home band,
   `/help/` and the JSON-LD cannot disagree about an address; an explicit
   `profile.contact` wins. `index.astro` skips the appended `#contact` block
   when the body already rendered one, so the page asks once.
2. **WordPress's default post never publishes as a card.** Every install ships
   `hello-world` ("Hello world!") and `sample-page`; a builder's posts widget
   renders them, and the rebuild published "Hello world!" as the school's only
   story. `isPlaceholderCard` drops a card whose whole content is the
   placeholder title — no body, media or attribution — at the single point
   where `slots.items` is assigned (the band reached `items` through three
   branches; a per-branch check missed two). The record keeps its route, so
   deleting the placeholder in the CMS restores the card.

The contact people are also published as `Organization.contactPoint` nodes, and
the styled contact cards resolve against the token layer (0 inert classes).

**Known, not fixed:** the source's "Read more articles" button renders
`href="#"` — ten components share the `href ?? '#'` fallback, and a dead control
is a WCAG 2.4.4 problem. Worth one focused pass rather than a partial fix.

---

## Phase 6 — Contracts: WP projection + Laravel adapter (2 d)

Tasks

1. `addons/pro/includes/rest/class-wp-mcp-ai-eca-public-controller.php`:
   `GET /nvoos-eca/v1/activities` (filters + facets + `ETag` + `Cache-Control`),
   `/activities/<id>`, `/availability` (short TTL). Permission: public read,
   never `_eca_google_course_id`/classroom fields; rate-limited via the existing
   request guard.
2. `register_post_meta` for the public `_eca_*` fields as a fallback path
   (`show_in_rest`, `single`, read-only auth callback).
3. Laravel: the same routes/models returning the same JSON; a shared JSON Schema
   (`lib/eca.schema.json`) validated on both sides.
4. `lib/eca.ts` gains `fetchEcaCollection()` (shape-only; the loader supplies the
   transport) so the Astro build can read either host.
5. Contract tests: WP fixture + Laravel fixture produce byte-identical normalised
   records for the same activity.

Acceptance

- `forge analyze` on a `nvoos-eca/v1` source produces the same profile shape as
  on `wp/v2`.
- Schema validation fails the build on a drifted field (not a blank card).

---

## Phase 7 — Gates, docs, rollout (1.5 d)

Tasks

1. `lib/eca-audit.ts` + `verify --eca` (see proposal §12.4): format completeness,
   facet landings, day coverage, currency consistency, ICS validity, JSON-LD,
   sitemap/canonical policy.
2. e2e: a 12-activity fixture through scaffold → build → serve, asserting cards,
   facets, detail, ICS, calendar agenda, shortlist, axe.
3. Docs: `README` for the `eca` preset, the format reference (`docs/eca-format.md`),
   and the skill update (`mcp-ai-wpoos-astro-forge` + a `reference/eca-frontend.md`).
4. Rollout to eca.directory: regenerate the profile with `eca` config, scaffold,
   verify all gates, capture screenshots; add the redirect map; **no domain
   cutover** until the client approves (the rebuild is local-only by agreement).

Acceptance

- `verify --eca` green on eca.directory (or a documented, itemised exception
  list — e.g. day coverage 100 %, price coverage 100 % from prose).
- All existing gates stay green.
- A fresh clone can forge a school site from a profile in one command.

### Delivered 2026-11-24 — the gated parity pass

The first run of the full browser gates against a *gated* profile (the earlier
profile had been captured through the holding page, so it carried no geometry,
colours or page bands). Four defects fell out; all four are generic to any
builder source, not ECA-specific.

1. **The colour gate crashed instead of measuring.**
   `querySelector('#6daaaef9')` throws — a CSS identifier cannot start with a
digit — and one such id aborted every comparison. Band surfaces are now
addressed through `idSelectors` / `builtBandSelectors` (an attribute selector
for a hash id, the `data-section-id` marker for a rebuilt band, which carries no
`id` at all), and a malformed selector costs one region instead of the run.
   *Result: crash → 24 measured surfaces, 0 missing.*
2. **`--ds-measure` was the band's padding box, not its content column.**
   Elementor 4 containers (`e-con` / `e-con-inner`) are not `.container` or
`.row`, so the container pool found nothing and the scaffold fell back to
`containerWidth + 64` — 1444px where the source caps its copy at 1280. The
measured content box from `analyze --geometry` is now the middle rung between
the classic container fact and that guess.
   *Result: page-band failures 106 → 54.*
3. **Interaction states never won.** The per-element positional facts
   (`.post-body > * > div:nth-child(1) > … > a:nth-child(1)`) re-state the box
measured from the source, and three `:nth-child()` steps outrank any class-level
state rule — so a rebuilt button kept its resting background on hover with the
correct `:hover` rule present in the sheet. State deltas are now emitted
`!important`; the resting pass stays overrideable.
   *Result: interaction-state failures 4 → 0.*
4. **The mobile gutter floor double-counted the band padding.** A measured 30px
   band gutter was topped up to 46px, costing 32px of copy width on a phone. The
floor is now `--forge-space` minus the band's own side padding.
   *Result: page-band failures 54 → 31.*

### Outstanding — three clusters, each with a diagnosed cause

`verify` on the rebuilt `eca.directory` now reports 31 page-band and 6 box
findings, and every one belongs to one of these:

| Cluster | Evidence | Cause | Plan |
|---|---|---|---|
| **A. ECA data pages replay the builder** | `/junior-school-list/` 6435 → 19844; `/tuesday/` 2312 → 5603; `/eca-days/` 1419 → 2898 | `[slug].astro` rebuilds the WP page widget-by-widget (609 widgets on a day page) including a `jet-listing-grid` that is *data*, not design. The source's day row is a 51px single line (title link, start/end, venue, year, Book button, divider) at `grid-col-desk-1`; the rebuild renders card blocks. | Claim the data slugs for the product template: a day route renders `Catalogue` in a **row** layout (new `layout="rows"`), a `<stage>-list` route renders the stage listing, `eca-days` the day index. Claiming must be explicit — a static route at the slug, with the slug filtered out of the `pages` collection so the route-keys gate keeps one URL per page. |
| **B. A raw band loses its row layout** | home band `3589820`: 521 → 1724 tall; lanes 286/954 → 1180/1180 | This band is emitted as raw markup (`data-section="text"` → `.post-body`), and the Elementor-4 primitives that made its columns sit side by side (`e-flex`, `e-con-inner`) never ship with it. The three columns stack. | Either map it to the existing split/team component (it is a 2-column icon-box row) or emit the measured column ratio onto the raw band's immediate row wrapper — the same `--ds-cols-template-desktop` the component bands already consume. Prefer the component: the measured `columns` fact [286, 954] is a 23/77 split the split component already models. |
| **C. The source's transparent band inset** | 10 findings: `1388 → 1440` desktop, `804 → 834` tablet | The day bands are `e-con-full` with `max-width: 96.394%` and 26px auto margins — an Elementor width percentage. The band paints **no** background (transparent over the page's `rgb(247,250,252)`), so the inset is invisible. | Do not reproduce it. Make the *gate* say so: compare a band's outer width only when either side paints a surface of its own; a transparent band's box has no visual identity and its content column is already compared separately. |

Two content gaps ride along and are smaller than they look:

- **Text parity 68.6 %**, dominated by band `77f4271d` at 8.6 % — an Elementor
  *toggle/accordion* band whose panel bodies the sanitizer drops (it rebuilds as
  a 3-card grid with empty cards). The fix is an accordion component fed from the
  panel facts, not a text extraction tweak.
- **Colour drift, 7 bands** — the rebuild paints `#1a1e1d` where the source's
  dominant ink is `#747474` (and the footer's `#27104a` becomes `#747474`). The
  band ink fact is measured but the rebuild's own `--ds-ink` outranks it; the
  fix is to emit the measured band ink as the section's default rather than
  inheriting the token layer.

### What this pass says about the forge (not only the site)

Three capabilities the ECA build needed that belong in the forge itself, because
every builder-sourced site needs them:

1. **A content-column measure from the measured box**, not from a container
   class pool. Done here (item 2 above); it is what makes `--ds-measure` mean
   "the column the copy occupies" for any builder.
2. **State rules that outrank positional facts.** Done here (item 3). Worth
   generalising into the documented rule "a generated state delta is
   `!important`, a resting fact is not".
3. **A "data page vs design page" split.** Not done. A page whose band is a
   dynamic listing (`jet-listing-grid`, a query loop, a Woo archive) is a data
   view: replaying its widgets is always wrong. The preset should classify it at
   analyze time and route it to a product template. This is the single largest
   remaining win on eca.directory and the reason cluster A exists.

---

## Test matrix (per phase)

| Layer | What | Where |
|---|---|---|
| Unit | normaliser, derivations, facets, filters, ICS, expansion, validation | `tests/eca.test.ts` |
| Unit | preset/scaffold emissions + redirects | `tests/scaffold.test.ts`, `tests/presets.test.ts` |
| Browser | axe on catalogue/detail/calendar/drawer; drawer CLS; keyboard grid | `tests/eca-browser.test.ts` |
| e2e | fixture → scaffold → install → build → serve → assert | `tests/eca-e2e.test.ts` |
| Gate | `verify --eca` on a forged build | `lib/eca-audit.ts` |
| Contract | WP ↔ Laravel JSON identity | `tests/eca-contract.test.ts` |

---

## Rollout order (eca.directory)

1. P0 + P1 → scaffold the ECA preset into `eca-frontend` with the live dataset;
   confirm the existing 7 bands/122 pages still build.
2. P2 → catalogue + stage/day/category pages; `/eca/*` redirects.
3. P3 → detail pages (105) + ICS; verify text/geometry on the new routes.
4. P4 → calendar/timetable; visual check.
5. P5 → search/shortlist/info; accessibility statement.
6. P7 → gates + docs; screenshots for client review. Domain stays untouched.

---

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Thin live data (no meta) | cards look empty | derivation + `inferred` labels; operator CSV import exists (`import_ecas_csv`) |
| Facet explosion (years × days × categories) | SEO duplication | index only the *landings*; filtered URLs canonical → `/activities/` |
| Static availability goes stale | wrong “places left” | build-time counts labelled; `/availability` endpoint on the SSR tier |
| ICS timezone/DST | wrong session times | `TZID` from profile, term-clipped rules, strict parser test |
| Category colour contrast | WCAG fail | colours paired with labels; contrast audited by the existing token gate |
| Preset coupling to the blog template | template drift | ECA routes/components are additive files; blog e2e unchanged |
| Large catalogues (2 000+) | build time | `maxPages` pagination + SSR tier for filtering |

---

## Pass — footer ink by ROLE, and the band-ink threshold (2026-11-24)

### The finding

The colour gate compares inks **one by one**: every source ink carrying ≥15 % of
a region's glyph area is matched against the rebuild's palette, and a source ink
the rebuild never paints is a failure. eca.directory's footer measures two:

```
rgb(116,116,116) #747474 — 62% — span.elementor-icon-list-text  (the link LISTS)
rgb(39,16,74)    #27104a — 38% — div.elementor-heading-title     (the block TITLES)
                                 a                              (the wordmark, 27.65px)
```

`applyChromeSurfaces` wrote the **palette winner** (`#747474`) into both
`chrome.footerInk` and `chrome.footerBarInk`. But the template paints the
rebuilt block titles (`.site-footer-block h3`) from `footerInk` and the list copy
(`p` → `--forge-footer-text-ink`) from `chrome.footerBrand.text.ink`. So the
rebuild painted every title grey, carried only ONE of the source's two inks, and
the gate reported both as drift (7 drifts).

The palette cannot answer "which of these is a heading" — it is a mass ranking,
and on a footer the longer link lists always win.

### The fix

1. `RegionColors.roles` (measured in the browser): the same glyph-area mass,
   split into heading-like and body text, summarised to one ink each.
   *Heading-like* is `h1`–`h6` **or** a `heading`/`title` class at weight ≥ 600 —
   Elementor publishes its block titles as `div.elementor-heading-title`, so the
   tag alone misses them. Optional by design: an older profile without `roles`
   behaves exactly as before.
2. `applyChromeSurfaces` writes each slot from its role — `footerInk` ← heading,
   `footerBarInk` ← body (the © line is body copy), and
   `footerBrand.text.ink` ← body **only when the two roles differ** (ΔE00 > 3),
   so a single-ink footer gains no redundant fact. Operator pins are preserved.
3. `readableBandType` / the eyebrow guard keep the measured ink unless it is
   *invisible* on its surface (contrast < 2.5), not merely below AA. A 4.5
   threshold repainted the source's deliberate `#747474`-on-`#f7fafc` (4.24:1)
   near-black and manufactured 29.5 ΔE of drift on five bands; a card-only ink
   the rebuild never renders is ~1:1 and must still be replaced.

### Result (build `gated15`, same profile otherwise)

| Metric | before | after |
|---|---|---|
| colour system drift | 7 | **3** |
| footer ink | `#27104a` → `#747474` (both slots) | `#27104a` titles / `#747474` lists — **clean** |
| everything else | — | unchanged (18/27 gates, page bands 13 fail/11 warn) |

### Known, still open — all three remaining colour findings

1. `41b89b66` and `319dfe29`: source `#747474` (54 %/39 %), build `#69727d`
   (ΔE 6.41). The band's `[data-section-id] p` rule *is* emitted with the right
   colour; `.ds-section .ds-card p { color: var(--forge-muted) }` (added for
   virtuallysrilanka, where a band ink harvested from a dark panel landed on a
   white card) outranks it at (0,2,1), and `--forge-muted` is the profile's
   `muted` token (`#69727d`). Fixing this properly means a **card-level** ink —
   either a per-band `--ds-card-ink` emitted only when the measured body ink is
   legible on the card surface, or a band surface the card can derive from. It
   must not be a blanket removal of the card rule: that would re-open the
   virtuallysrilanka bug.
2. `77f4271d`: source `#27104a` (32 %), build `#1a1e1d` (ΔE 22.2). This is the
   accordion/toggle band the rebuild does not reproduce (8.6 % text coverage —
   the sanitizer drops the panel bodies), so its copy is not the source's copy at
   all. It closes with the `items[].bullets` / accordion-content work, not with a
   colour rule.

The `sites/eca.json` diff also records `roles` on every band (harmless — only the
chrome slots read them) and drops two `/author/…` entries from
`inventory.missing` that discovery now resolves. The per-page band facts were
kept from the previous run: a re-measure re-keys Elementor's per-render
`elementor-dcss-…` class hashes with identical values, which is churn, not data.

---

## Pass — card ink, chosen by SURFACE not by contrast (2026-11-24, second pass)

### The finding

The last two band drifts (`41b89b66`, `319dfe29`) were not a missing fact. The
scoped rule WAS emitted, with the right colour:

```
[data-section-id="41b89b66"] p{…;color:rgb(116, 116, 116)}   → #747474
```

…and then `.ds-section .ds-card p { color: var(--forge-muted) }` — specificity
(0,2,1) against the band rule's (0,1,1) — repainted the card copy with the
`muted` token (`#69727d`, Elementor's global text colour). ΔE00 6.41 on both
bands.

That card rule is deliberate: it was added for virtuallysrilanka, where a
band-level ink harvested from a **dark panel** landed on a **white card**.

### The discriminator

Not contrast — eca.directory's `#747474` on the card is 4.34:1, and the whole
premise of this pass is that a source's low-contrast choice is parity, not a
defect. The honest question is *"was this ink measured on the surface the card
will paint?"*:

- band surface ≈ card surface (`contrast < 1.15`; `#ffffff` vs `#f5f5f4` = 1.08)
  → the ink was measured **on the card** → reuse it;
- band surface ≠ card surface (virtuallysrilanka's dark panel vs a white card)
  → the ink belongs to the panel → keep the template's `muted`.

### The fix

`cardInkFor(section, type, profile)` returns the mediated body ink only on the
same-surface case; `renderSectionThemeEntry` emits it as `SectionTheme.cardInk`,
and `DesignRenderer` writes one scoped rule:

```css
[data-section-id="…"] .ds-card:not(.ds-card--surface) p{color:#747474}
```

`--ds-card-ink`, a global token change, and removing the blanket rule were all
rejected: each would move every site in the fleet. `:not(.ds-card--surface)`
keeps a card that paints its OWN measured slab on its own derived ink.

### Result (build `cardink16`)

| Metric | gated15 | cardink16 |
|---|---|---|
| colour drift | 3 | **1** |
| `41b89b66` / `319dfe29` | `#69727d` (ΔE 6.41) | **clean** |

`cardInk` is emitted for five of the seven homepage bands (the hero and the two
listing bands included). A rule on a band that renders no `.ds-card` is inert,
so the fact is emitted wherever it is MEASURED rather than guessed per kind.

### The one remaining finding

`77f4271d` — source `#27104a` (32 %), build `#1a1e1d` (ΔE 22.2). Its copy is the
accordion panel bodies the sanitizer drops (8.6 % text coverage), so the rebuild
is not painting the source's copy at all. This closes with `items[].bullets` /
accordion content, not with a colour rule. After that, `verify --colors` reports
zero drift on eca.directory.

---

## Pass — card bullets, and why `77f4271d` is still open (2026-11-24, third pass)

### What shipped

`SectionItem.bullets` — a list a CARD carries. `iconListItems()` already turned
each `<li>` into an item, which is right when the list IS the band and wrong
inside a card; `extractStructuredItem()` and `columnItems()` now collect the
card's list as `bullets`, `FeatureGrid` renders it as `<ul class="ds-card-bullets">`,
and the legacy columns fallback keeps the band's own lede (copy outside the
container) instead of discarding it with `slots`.

Tests: `tests/semantic.test.ts` (+2) — a plain `<ul>` inside an icon box becomes
the card's bullets and not sibling cards; a `<nav>` list inside a card is never
absorbed.

### Why the band itself is not fixed yet

`77f4271d` is a **flex-container card grid** (Elementor 4.x), measured on the
live page:

```
e-con-inner                          (the band)
├─ e-con-boxed.e-child               (intro row: h2 "Phased Delivery" + icon-box)
├─ e-con-full.e-child                (phase row)
│   ├─ e-con-full.e-child            (CARD: h3 "Phase 1" + icon-box "Pilot Setup"
│   │                                 + p "Weeks 1–6" + icon-list ×4 + button)
│   ├─ …                             (Phase 2, Phase 3)
└─ e-con-full.e-child                (role row: "Compare User Roles" + 3 cards)
```

The flat merge sees only widgets, so it produces **20 bare items** for what are
**6 cards** (each caption and each of the 16 icon-list entries becomes an item),
and the rebuild resolves `items.find(columnIndex === i)` against 3 measured lanes
→ three EMPTY cards. Text coverage 8.6%.

Three attempted fixes, and what each measured:

| Attempt | Result |
|---|---|
| Group repeated sibling containers into cards | 5 flat items → 4 "cards", 20 → 8: bucket collection found the ROWS before the cards, and the wrong card set dropped words, so the **fidelity guard demoted the band to raw text**. Reverted. |
| Render the flat items when no item knows its lane (drop empty lanes) | Text coverage **68.6 % → 80.9 %** (band 8.6 % → 65.6 %), but the band's copy is WHITE on a background PHOTO the rebuild does not paint, so it rendered dark on a white panel: colour drift **1 → 2** (`#ffffff` vs `#1a1e1d`, ΔE 84) and mobile height 2070 → 3102. Reverted: it trades one broken band for two broken gates. |
| Emit the measured band photo as a CSS background | Not done — `ForgeSection.bgImage` makes a band resolve as `hero`, and a band-level photo needs its own fact plus an ink decision. This is the blocker underneath most of the remaining gap. |

### The two capabilities this band needs (in order)

1. **Card grouping driven from the widgets, bottom-up.** Assign every widget to
   its nearest card container and build one item per container — never drop a
   widget, so the fidelity guard cannot demote the band. (The top-down container
   scan above lost words; a bottom-up assignment cannot.)
2. **A band BACKGROUND PHOTO fact.** `RegionColors.image` already measures
   `url(bg-01.png)` for this band; nothing paints it. It needs an explicit
   `bgPhoto` on `ForgeSection` (so the theme stays `alt`, not `hero`), a CSS
   `background-size/position`, and an ink rule: when the measured surface is a
   photo, the source's ink was measured against THAT surface, so
   `readableBandType` must not drop it for "invisible on white".

Until both land, the band stays as verified (`buildId cardink16`).

### Verify numbers, cardink16 (unchanged by this pass)

| Gate | Value |
|---|---|
| colour system | 24 surfaces, **1 drift** (`77f4271d`), 0 missing |
| text parity | 68.6 % |
| page bands | 13 fail / 11 warn |
| scorecard | 18/27 |
