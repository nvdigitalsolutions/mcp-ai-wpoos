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
