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

---

## Pass — the band's real surface, and its cards (2026-11-24, fourth pass)

### Finding 1 — a 20%-opacity `::before` was shadowing the band's own gradient

`77f4271d` paints `linear-gradient(#27104a 30%, #ffffff 0%)` on its own box and
a decorative `url(bg-01.png)` on a positioned `::before` at **opacity 0.2**.
`readBackground` read the pseudo FIRST (correct for an opaque overlay), so the
band's surface was recorded as the PHOTO, the gradient was never emitted, the
rebuild painted white — and `readableBandType` then dropped the source's white
intro copy as "invisible on white".

Fix: a positioned pseudo below 50 % opacity is a **veil**, not a surface; skip it
and read the band's own paint. Measured effect on the profile — **twelve leaves,
all of them the fix**:

| Path | Before | After |
|---|---|---|
| `design.sections[3].bgGradient` (77f4271d) | — | `linear-gradient(#27104a 30%, #ffffff 0%)` |
| `design.sections[6].bgColor` (71dbef2b) | — | `#8764a0` |
| `colors.band:77f4271d.image` | `url(bg-01.png)` | the gradient |
| `colors.band:71dbef2b` | `transparent` | `#8764a0` |

Two bands were mis-surfaced, not one: the contact/form band (`71dbef2b`) had the
same veil and is purple in the source.

### Finding 2 — the band's cards are CONTAINERS

Elementor 4.x publishes each card as `e-con-full.e-child`, so `columnItems`
(which needs `.elementor-container`) cannot see the band: the flat merge produced
**20 bare items for 6 cards**, and `FeatureGrid` resolved three measured lanes
against items with no `columnIndex` → **three empty cards**.

`cardContainerItems()` now groups repeated sibling containers where each is a
**card** — a heading AND a list. That predicate is what separates the three phase
cards from the comparison table's cells (a cell holds a heading and nothing else)
and from the intro row (one child). Items carry `columnIndex`, so the laned grid
renders exactly the source's cards.

Two details the first attempts got wrong, kept in the code as comments:

- **Word order is copy.** The card's label (`h3 "Phase 1"`) precedes its title
  (`h4 "Pilot Setup"`); printed after it, the band's text shingles stopped
  matching. Items now carry `kicker`, rendered above the heading.
- **Never drop a word.** The comparison table shares the band. Its labels ride
  the band's text (not the card grid), because dropping them made the fidelity
  guard demote the whole band to raw text — twice.

### Finding 3 — a measured heading ink must outrank the PANEL rule

`.ds-section-wrap--panel .ds-grid .ds-card :is(h3,h4,h5)` (0,3,1) paints card
headings in the panel's derived ink. On the white slab the source's card titles
are `#27104a`; the panel made them all `#111111` (ΔE00 22.9). A card-scoped
repeat of the measured heading ink (`[data-section-id].ds-section-wrap .ds-grid
.ds-card h4`, 0,4,1) wins without `!important`, so hover/focus states still can.

### Result (build `final22`)

| Metric | before | after |
|---|---|---|
| band `77f4271d` text coverage | 8.6 % | **84.9 %** |
| page text parity | 68.6 % | **85.1 %** (1 band failing: `319dfe29`, the hello-world post) |
| colour system | 7 drifts (session start) | **0 fail, 1 warn** (ΔE00 4.62), 0 missing |
| band cards | 3 empty | 3 cards: kicker, title, weeks, 4 bullets, link |

### Still open on this band

- The **comparison table** is rebuilt as a run of text, not a table: the rows
  and role columns want `comparison` detection (Proposal 136) for a band that
  also carries a card row — a compound section, which the model does not have.
- The source's buttons are `href="#"`; the rebuild renders the links but they go
  nowhere (the source's own do too).

## Pass — the listing's SLICE is in its TITLE, and the classifier sees sanitised HTML (2026-11-25, fifth pass)

### Finding 1 — `eca-days` is a Monday listing, and its slug says nothing

`eca-days` was excluded from `ecaDataPages()` because nothing in the slug names a
weekday, so the page replayed the builder's widgets and shipped its 14th row — a
lorem-ipsum demo entry nothing in the record set reproduces. The page's own title
is **"Monday- Junior School"**, and the Monday slice is **13** records
(`_eca_day` counts — Tue 29, Wed 12, Thu 25, Fri 16, Mon 13, Sat 2, Sun 1, 2 empty).

A claim must be derived from what the site **says**, not from a slug convention a
builder happened to choose. The inventory now records each CMS page title
(`coveredPaths` adds `title` to the `_fields` term — no extra request), and
`titleWeekday()` claims any page whose title starts with a weekday:

```ts
const wpTitle = profile.inventory?.titles?.[ `/${ slug }/` ] ?? '';
const titleDay = titleWeekday( wpTitle );   // "Monday- Junior School" → Monday
```

`select-list` stays unclaimed: it is a chooser (two 2-lane teaser bands, no rows),
so "a weekday in the title" and "a `-list` suffix with rendered rows" remain the
only two claims.

### Finding 2 — the fidelity guard under-counted kickers and bullets

`slotWordCount()` decided whether a band's rebuilt slots carry ≥50 % of the band's
words. It counted `item.title`, `item.body` and `item.author` but not `item.kicker`
or `item.bullets` — both of which the renderer draws. A card-heavy band whose copy
lives mostly in a list could therefore be demoted to raw text and **disappear**.
Every slot the renderer emits must be counted, or the guard reads its own output
as lossy.

### Finding 3 — the classifier never sees an SVG (why the comparison table waits)

`77f4271d` also carries a "Compare User Roles" matrix (Student/Parent/Teacher × 4
features). Detection was written against the **raw** REST HTML and worked:

```json
{ "columns": ["Student","Parent","Teacher"],
  "rows": [{ "label": "Live ECA Catalogue", "values": ["✓","✓","✓"] }, …] }
```

but the build classifies **sanitised** HTML, and `sanitizeHtml` strips every
`<svg>` (`raw e-fas-check: true | clean: false`; `raw svg: 52 | clean svg: 0`).
The check/cross marks therefore never reach the classifier: the real pipeline
reports `kind feature-grid items 3 cmp no`.

This is not a heuristic to tune — it needs an **analyzer-measured fact**
(`bandFacts.comparison`, captured in the browser where the SVGs exist), which is a
new fact + profile field + emitter. All Part-A classifier code was reverted.

### Result (build `days25`)

| Metric | before | after |
|---|---|---|
| `/eca-days/` | replayed builder widgets, 14th row is demo copy | claimed data view, title `Monday- Junior School`, **15 rows** (13 records + header/controls) |
| page-band fails | 13 | **8** |
| colour system | 0 fail / 1 warn | **0 fail / 1 warn** (unchanged) |
| text parity | 85.1 % | **85.1 %** (unchanged) |
| tests | — | **2229 passed / 139 files**, typecheck clean |

Deployed to `site/eca`; staging verified `live release days25 matches this build`,
`/eca-days/` renders 118 row markers.

### Still open

- The `comparison` fact above (compound section: card row + matrix in one band).
  **Settled design (do not re-litigate):** the fact renders **in addition to**
  the classified kind, never instead of it — `77f4271d` carries three phase
  cards AND the matrix, so an override to `comparison` would delete the cards.
  Mirror the existing `theme.logos` precedent: capture `bandFacts.comparison` in
  the browser, emit it on the section entry in `sections.generated.ts`, and have
  `DesignRenderer.astro` append `<Comparison>` after the kind's component with
  the band heading suppressed (it already rendered). Detector contract: a
  container of ≥2 sibling ROWS, each with ≥2 equal-width cells, row 0 = column
  labels (text), body rows = a text label plus icon-only cells classified by
  icon class (`check`/`fa-check`/`e-fas-check` → ✓, `times`/`xmark`/`close` →
  ✗), never by the presence of an `<svg>` in sanitised HTML.
- `77f4271d`'s last 15 % of text (the matrix labels now ride the band, the values
  do not).
- `319dfe29` at 69 % — the CMS's only post is `hello-world`; a client site should
  hide a "latest articles" band with no real posts rather than render it.
- Home-band geometry (`3589820` lanes 286/954 → 273/907, tablet stacking 766 →
  1638) — pre-existing, untouched by this pass.
- `href="#"` sweep: the source's own buttons go nowhere.

### Follow-on — a label can name a RANGE of days, and the parser read only its first word

Verifying the deployed page against the CMS surfaced a 15th Monday row the
source's own listing does not print: **SS Lion's Mane** (id 11153). Its
`_eca_day` meta is empty, but its prose says

> ECA Day & Time: **Monday to Friday** (specific session times are not stated in the booklet)

`parseDays()` split on `& , / + and` — none of which appear in `Monday to
Friday` — so it took the leading word and recorded Monday alone. The record is a
five-day activity; the Monday-only model hid it from Tue–Fri entirely (worse than
the source, whose meta-only query hides it from every day).

`parseDays()` now expands a RANGE (`Monday to Friday`, `Mon–Fri`, `Tue - Thurs`,
`Friday to Monday` wraps) before splitting, and only when both words are day
names — so a clock range (`Tuesday 2:45 p.m. – 3:30 p.m.`) and a hyphenated
title (`Monday- Junior School`) are unaffected. Shipped separately as #8236.

Effect (build `days26`): day landings now carry the record on every weekday it
runs — Tue 31→32, Wed 12→13, Thu 26→27, Fri 17→18, Mon 14→15. Gate numbers are
unchanged (page bands 8 fail / 10 warn, text parity 85.1 %), because a claimed
data view is compared against the record set, not the builder's query.

**This is a deliberate, visible divergence from the source:** the live
`/eca-days/` prints 14 items because its JetEngine query reads `_eca_day` only.
The rebuild prints 15, because the record's own copy says Monday. Data wins over
the query; the inference is recorded in `inferred` (`rule: label:day`).

Records with no day anywhere appear in the full catalogue only — that is a CMS
data-entry gap, not a parser gap. `SS Maths Mastery Hub` (11155) is one.

## Pass — the card that was the title, and why the updates band is not hidden (2026-11-25, sixth pass)

### The finding

The home's updates band rendered the same string twice:

```
Our Updates          ← eyebrow  (measured: 14px h2)
News & Insights      ← title    (measured: 33px icon-box heading)
News & Insights      ← the SAME string again, as a one-card "grid"
Follow along as we…  ← that card's body
[Read more articles]
```

The source publishes the band as a kicker heading **plus an Elementor
icon-box** whose title is the real heading and whose body is the band's lede.
The measured eyebrow/title pair correctly promotes the icon-box heading to the
band title — but the icon-box was *also* rebuilt as a card, so the title printed
twice and the grid was a single empty-looking tile.

### The fix

`FeatureGrid.astro`: a card whose heading **is the band's rendered title**, and
that carries **no media and no link of its own**, is the band's sub-heading — its
copy folds into the lede and the card is not rendered; when every card folds, the
grid is not emitted. Cards holding a picture or a destination are never folded,
because that would delete content the title does not speak for.

Text parity is unchanged (85.1 %): the fold removes a duplicate, not a word.

### Why the band is not hidden

The audit suggested hiding "a band with no real posts". The card is already
suppressed — `isPlaceholderCard()` drops a source post card whose heading is
WordPress's `Hello world!` / `Sample Page` and that has no body, media or
attribution. What remains in this band is the client's **own copy** (`Our
Updates`, `News & Insights`, the rollout paragraph). Hiding the band would delete
that copy, so it is not hidden.

Hiding instead is a deliberate product switch, not a bug fix: it needs a
posts-band signal (the measured class facts already carry
`elementor-widget-posts`) plus a build-time check of the posts collection, and it
costs one band of **positional** parity, because the band audit aligns source and
build bands by position. Tracked, not done.

### The band's 69 % is the placeholder, and the gate is telling the truth

`319dfe29` still fails text parity at 69 %, and every missing shingle belongs to
the sample post: `hello world`, `august 4 2025`, `read more`. That content is
deliberately not reproduced, so the gate is correct and the failure is expected
while the CMS holds only WordPress's placeholder post. Deleting `hello-world` in
the CMS removes the failure. A baseline-side filter (drop the post card whose own
heading is a placeholder title when capturing `analyze --text`) would encode the
expectation properly; it needs a re-analyse of the profile, so it is deferred.

### A band whose grid folds away sizes to its copy

The measured `min-height: 478px` framed three lines of copy, because the
source's height included the post card. Once the card folds into the lede, that
floor covers nothing.

The rule moved into `titleEchoItems()` (`lib/band-copy.ts`) so `FeatureGrid` (which
renders the cards) and `DesignRenderer` (which decides the wrapper's height) share
one answer. When **every** card folds, the band's measured `min-height` is skipped
in both paths that emit it — the inline `wrapStyle` value and the per-device
scoped CSS — and the band sizes to its content (build `days29`: **478px → 239px**).
A card whose measured lane paints a surface is never folded, so a panel is never
lost.

**Cost, recorded deliberately:** `--geometry` now reports three fails on this
band (`desktop 478 → 239`, `tablet 530 → 239`, `mobile 559 → 268`). The source's
height is the height *with* the post card; reproducing it would mean shipping
~240px of dead space. Geometry fails therefore go **5 → 8**, all three on
`319dfe29`, and all explained by content we intentionally do not render. A
per-band "removed content" opt-out in the audit is the way to encode this
properly; not done.

### Note for anyone rebuilding this site by hand

`src/forge/*.ts` and `src/lib/*.ts` in a scaffolded project are **copies**. A
change to `lib/eca.ts` / `templates/blog/src/**` does not reach the built site
until the copy is refreshed (`cp` the file, or re-scaffold). Building `days28`
from a stale `src/forge/eca.ts` re-introduced the day-range type error that #8236
had already fixed.

## Pass — the comparison matrix, measured where the SVGs still exist (2026-11-25, seventh pass)

### What shipped

`77f4271d` carries a **"Compare User Roles"** matrix (Student / Parent / Teacher
against four features, a check or a cross in every value cell). The rebuild
rendered it as loose text inside a three-card feature grid, and the build-time
classifier could never do better: the marks are `<svg>`, and `sanitizeHtml`
strips every one (52 raw → 0 sanitised; the pipeline reports
`kind feature-grid items 3 cmp no`).

The fact is now measured at **analyze** time from the raw HTML, exactly as the
fifth pass prescribed:

| Piece | Where |
|---|---|
| `comparisonOf()` — structural detector (equal rows of equal cells, text header, label + icon-only cells, one mark per cell) | `lib/design.ts` |
| `comparisonFactsById()` — the same facts keyed by section id | `lib/design.ts` |
| emission on the section entry + the generated `SectionTheme` field | `lib/scaffold.ts` |
| `<Comparison>` appended **after** the band's own component, heading/links suppressed | `DesignRenderer.astro` |

The marks are stored as **text** (`✓` / `✗`), so nothing downstream depends on an
icon font.

### Why the id-keyed merge was needed

`comparisonOf()` first ran inside `analyzeSections()`, and the fact never
appeared in the profile. The reason: the band MODEL is chosen after the section
pass, and eca's profile is **`bandSource: outline`** — the Elementor section list
(fewer bands than the outline) is discarded and the outline bands, which carry no
comparison, are used. Recording by id lets either model pick the fact up. A
detector inside a pass whose output can be discarded is invisible, not wrong.

### Result (build `days32`)

- the band renders a real `<table class="ds-compare">` with `<caption>Compare
  User Roles</caption>`, `scope="col"` role headers and `scope="row"` feature
  labels — `✓ ✓ ✓ / ✓ ✗ ✓ / ✓ ✓ ✓ / ✓ ✓ ✓`
- **text parity 85.1 % → 85.6 %**, colour 0 fail / 1 warn (unchanged), page bands
  8 fail / 10 warn (unchanged)
- tests **2237 passed / 140 files**, typecheck clean

### Hazard found — `analyze --design --write` can flip the band model

The refresh that recorded the fact also flipped this profile from `elementor`
bands to `outline` bands, and **silently dropped**:

- three `chrome` facts (`footerBrand`, `mobileNav`, `footerInk`) — `diffDesignFacts`
  warns about these;
- every section `bgColor` / `bgGradient`, including `77f4271d`'s
  `linear-gradient(#27104a 30%, #ffffff 0%)` — the fix an earlier pass existed
  to make. **Nothing warns about this at all.**

The profile committed here is therefore the PREVIOUS profile plus the verified
fact, not the refresh's output. Until the flip is reported (or pinned), treat a
design refresh on this site as "re-verify every band surface afterwards".

## Pass — what the forge itself gained (2026-11-25, eighth pass)

Everything above was paid for by hand. These are the changes that make the next
Elementor forge cheaper, in three PRs.

### Shipped

| PR | Change | Why |
|---|---|---|
| #8242 | Elementor Free + Pro widget vocabulary; a `list` role for query loops; `SemanticSection.dynamicList` | An unseeded widget name resolves to `content`, which flattens its structure **and** forces the band to `low` confidence. `posts` was unseeded, so a loop band was indistinguishable from an authored grid. |
| #8242 | `diffDesignFacts` reports a **band-model flip** and every dropped `bgColor`/`bgGradient`/`bgImage`/`overlay`/`panel`; `mergeProfileDraft` carries surfaces forward on surviving ids **when the model changed** | The refresh that recorded the comparison fact flipped `sections → outline` and repainted every band transparent, including the gradient an earlier pass existed to capture. Only the chrome loss was reported. |
| #8244 | `forge regen <profile> --target <dir> [--only …]` | Re-scaffolding to pick up a profile fact swaps the whole target and fails with `EPERM` on Windows; the alternative was hand-copying `sections.generated.ts`. Output is byte-identical to the scaffold's. |
| #8244 | `SiteProfile.gateProbe` + a `verify` warning | A gated source analysed without credentials yields the holding page and a plausible, wrong design block, with no trace afterwards. |
| #8244 | `BandGeometry.collapsed` + `data-section-collapsed` + a `diffGeometry` skip | A band the build deliberately renders shorter is a **decision**, not drift. eca's geometry fails went back from 8 to 5. |

### Shipped — the `structure` channel

`comparison` needed its own fact, its own detector, its own emitted field and its
own renderer branch. So will the next structural widget. That is the wrong shape,
and it is now fixed:

- **one channel**: `ForgeSection.structure?: ForgeStructureEntry[]` — `{ type, data }`
  per measured structure, emitted on the section entry as `SectionTheme.structure`;
- **`comparison` is its first entry**, still written to `ForgeSection.comparison`
  for one release (profiles in the wild carry it); the scaffold synthesises the
  `structure` entry from it, so an un-regenerated project still renders;
- **adding the next structure is a detector**, not a pipeline: call
  `detect` → push `{ type, data }` → add one branch in the renderer.

**Ordering rule (do not re-litigate).** A measured structure renders **in
addition to** the classified kind, never instead of it — the band that carries a
comparison matrix also carries three phase cards, and an override deletes them.
`theme.logos` is the existing precedent.

**Query loops.** `SemanticSection.dynamicList` is now recorded, and the renderer
drops a loop band only when the loop *was* the band — no title, no lede, nothing
else to frame. A band with copy keeps it (eca's updates band is exactly that
case), so the rule cannot delete a client's words.

### Shipped — the vision STRUCTURE verdict (unwired)

`lib/vision.ts` already ran a model for **pixel** comparison; it now also has the
structural role:

- `STRUCTURE_PROMPT` + `STRUCTURE_VOCABULARY` — a **closed** list, because the
  model's job is to pick a label the renderer already has a component for;
- `parseStructureVerdict()` — tolerates a fenced answer, refuses an invented
  label (`carousel-grid` → `unknown`), clamps confidence, and treats prose as
  `unknown` rather than guessing;
- `mergeStructureVerdicts()` — a deterministic detector always wins; vision may
  only fill a gap, above a confidence floor, and the entry carries
  `source: 'vision'` + the model id so a wrong label is attributable.

**Not wired to the CLI yet**, deliberately: it needs a model endpoint and a
per-band screenshot pass, and a half-wired vision path that silently returns
`unknown` is worse than none. The contract and its tests are in place so the
wiring is a small change.

### Design note — `e-con` as a named concept (not yet built)

Elementor 4.x replaced `.elementor-container > .elementor-column` with
`e-con` / `e-con-full` / `e-child` / `e-flex`, and the classifier learned it
ad hoc: `cardContainerItems()` recognises cards as "repeated sibling containers
whose children carry a heading AND a list". That predicate is doing three jobs
(rows, cards, lane surfaces) and the symptoms of getting it wrong were a band
twice its height and lanes 286/954 rendered 273/907.

The next step is to model the container explicitly: a **row** is an `e-flex`
container whose children are `e-child` containers with equal flex-basis; a
**card** is a child that owns a heading and a body; a **lane surface** is a
child whose computed background is not transparent. Per-device facts
(`elementor-widget-tablet_extra__width-initial`, `elementor-hidden-mobile`) then
attach to the same node instead of being re-derived from class strings.

### A vision STRUCTURE pass (built: `forge structure`)

`lib/vision.ts` already runs a VLM for **pixel** comparison and
`visionConfigFromLocal` already targets a local model (Ollama / LM Studio), so
the plumbing exists; what did not was a structural role.

For a band that resists markup classification (an oddly-built table, a timeline,
a phase row), `lib/structure-pass.ts` screenshots it at 1440 and 390, asks the
model to name **one** structure from a closed vocabulary, and merges the answer
into the band's `structure` channel. Rules, all enforced by the module:

- **opt-in and fail-soft** — no reachable endpoint prints
  `structure pass skipped (…)` and exits 0;
- **nothing is stored without `--write`** — the pass returns a merged copy;
- **cached** by `section id + sha256(screenshot)`, so a re-run costs nothing for
  an unchanged band, and a changed band is re-asked;
- **never authoritative** over a deterministic detector — a band that already
  carries a measured structure is skipped *without a model call*. That includes
  the typed `comparison` fast path: the renderer falls back to it only while
  `structure` is empty, so a vision label there would suppress the table it was
  meant to complement;
- **provenance recorded** (`source: 'vision'`, `model`, `confidence`, `note`), so
  a bad classification is attributable and removable;
- the model never writes markup or copy — it picks a label.

Three precision rules were added after running it against eca.directory:

1. **A rendered form is measured.** The enquiry band's heading (`Questions? Get a
   quick reply by sending your message`) reads as an FAQ, and the local model
   answered `faq` at 0.9 on **both** device ends. A band whose render contains a
   `<form>` is skipped, not asked.
2. **The two device reads must AGREE.** An abstention is not a contradiction (a
   matrix legitimately collapses on a phone), but two different labels mean the
   band is ambiguous at this model's resolution — nothing is stored.
3. **The heading rides along as evidence.** It is read from the measured render
   (`h1`–`h4` inside the band), capped at 140 chars, and appended to the prompt.
   Without it the model answered `text` for a hero; with it, `team` for a band
   headed *Meet the Project Team* — which is correct.

The prompt was also calibrated: the model must name a concrete visual cue first,
and a confidence of 0.9 is reserved for an unmistakable cue (below 0.5 if it is
choosing between labels). That turned four confident `text` answers into
abstentions.

Capture is injected, so the pass is unit-tested without a browser.

### What the local model can and cannot do (2026-11-25)

`qwen2.5vl:7b` over Ollama, 7 bands × 2 devices: 2 labels (one correct, one
harmless), 7 abstentions, 2 measured skips, 0 failures. It is the recommended
small open VLM for a 12 GB GPU, and the alternatives at that size (InternVL 2.5
8B, MiniCPM-V 2.6 8B) are comparable rather than better; a step change needs a
32B+ checkpoint or a paid API. So the dry run is the product at this size: read
the labels, then decide about `--write`.

## Pass — lane-level per-device facts (container model)

`ForgeSectionColumn` has carried `widthTablet` / `widthMobile` /
`hiddenMobile` / `hiddenTablet` for releases, but on an Elementor 4
container-built site the fact list was **always empty**: the lane read looked
only for `.elementor-container > .elementor-column`, and the container model
has no such nodes. So `elementor-hidden-mobile` on a card lane was recorded
nowhere, and the rebuild showed a lane the source hides.

Four pieces now line up:

1. **Extraction** (`containerLaneFactsOf`, `lib/design.ts`). The lane set is the
   band's biggest card row of sibling `e-con` nodes. **Explicit only**: a lane
   set is a fact only when at least one lane states a per-device intent — a
   `elementor-hidden-*` class, or a media-scoped `--width` on the lane's own
   `elementor-element-<id>` selector. An implicit `--width` is the desktop
   split; applying it per device squeezes the grid (the 3589820 lane drift).
2. **Shape normalisation.** `containerRows` groups siblings by class shape, and
   a lane carrying `elementor-hidden-mobile` has a *different* shape — so the
   hidden lane was split out of its own row and the lane set lost exactly the
   lane the fact is about. The pass re-groups the parent with the responsive
   vocabulary normalised away. This is local on purpose: `containerShape` is
   shared with the card and comparison readers, and changing it would relayout
   bands already verified byte-identical.
3. **Persistence.** `measuredLaneColumns` replaces the declared lane list with
   the measured one (real widths beat the declared split) — and used to drop
   every responsive field doing so. It now carries `hidden*` / `reverse*` /
   `width*` across positionally, and the measured emission writes them.
4. **Emission** (`bandLaneCss`, `DesignRenderer.astro`). `display:none` scoped to
   `[data-section-id] .ds-grid > :nth-child(i)` under the template's band media
   queries. Never a reorder: the hidden lane keeps its slot in the source's
   reading order and stays in the DOM for crawlers.

**Per-device widths *are* emitted — as a track COUNT.** Elementor's responsive
lane width is a flex basis: a row wraps when the running total passes 100%.
`lib/lane-tracks.ts` derives the first row's track count for tablet and mobile
(`[50,100] → 1`, `[50,50,50] → 2`), skipping lanes the source hides there and
inheriting a lane's desktop width when it states none (Elementor's own cascade).
It refuses to answer when the device is not addressed explicitly, or when a
visible lane has no basis at all. Emitted as `grid-template-columns` scoped to
the band, because `columnsFor` writes its count INLINE and no stylesheet rule
could outrank it. Verified on the built ECA page with a temporary fact: three
33.3% lanes whose last goes 100% on mobile emitted `repeat(2, …)` at ≤767 and
**no** tablet rule — the explicit-only rule holding.
