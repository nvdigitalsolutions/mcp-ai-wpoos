# 163 — ECA front-end proposal (the default Astro app for the ECA toolkit)

**Status:** proposal (awaiting approval to implement)
**Date:** 2026-11-24
**Builds on:** 096 / 098 / 114–143 (the Astro forge), 152 (gated sources)
**Research:** [`163-eca-frontend-research.md`](163-eca-frontend-research.md)
**Plan:** [`163-eca-frontend-implementation-plan.md`](163-eca-frontend-implementation-plan.md)

---

## 1. Summary

The forge currently rebuilds *a* site. This proposal makes it rebuild **the
product**: a reusable, data-driven public front-end for the NV oOS **ECA toolkit**
running on WordPress **or** Laravel, with the eca.directory rebuild as its first
instance and design donor.

Three deliverables:

1. **A format** — `nvoos-eca/v1`, a versioned, host-agnostic JSON contract for one
   activity, derived from the toolkit's own fields (`_eca_*` meta, the
   `mcp_ai_eca_category` taxonomy, the enrolments table) with **explicit
   provenance** for anything inferred from prose. This is the "comprehensive ECA
   format" the forge, the WP plugin and the Laravel runtime all speak.
2. **A front-end** — a set of page templates that any school's dataset fills:
   home, catalogue with facets, activity detail, **stage templates** (Junior /
   Senior / Primary / Secondary…), day and category landings, calendar +
   timetable, term dates, info/help, search, shortlist.
3. **Forge integration** — an `eca` preset, a normaliser module, scaffold
   emissions and a new verification gate (`verify --eca`), so a client site is a
   profile + a stylesheet away.

**Non-goals** (deliberately out of scope for v1): accounts/parent portal,
payments checkout inside the Astro app, attendance registers, MIS sync UIs,
staff tooling. Those stay in the toolkit; the front-end links to them.

---

## 2. Why now

- The rebuild of eca.directory is complete (7 bands, 122 pages, chrome and band
  gates green). It is a faithful *reproduction* but not yet a *product*: it has
  no catalogue, no facets, no calendar, no stage split, and its 105 activities are
  only reachable as a flat list.
- The live site's own IA already asks for the product: six **day pages**, a
  **Junior/Senior chooser** (`select-list`), and a `junior-school-list` page whose
  body is still lorem ipsum. The current JetEngine grids are the thing this
  replaces.
- The toolkit already models everything a catalogue needs (`_eca_day`,
  `_eca_start_time`, `_eca_end_time`, `_eca_venue`, `_eca_year_groups`,
  `_eca_max_students`, `_eca_is_paid`/`_eca_cost`/`_eca_cost_period`,
  `_eca_requires_audition`, `_eca_booking_type`, `_eca_status`) and exposes the
  facet vocabulary through `list_ecas` (day, year_group, venue, status, search).
  Nothing needs to be invented — only surfaced.
- Every commercial comparable (SOCS, SchoolsBuddy, iSAMS) sells exactly this
  surface; see the capability matrix in the research file.

---

## 3. The format — `nvoos-eca/v1`

### 3.1 Principles

1. **Optional-first, provenance-explicit.** Every field except `id`, `slug`,
   `title`, `url` is optional. Anything derived from prose is recorded in
   `inferred` so the UI can hedge and the operator can correct it.
2. **No invented facts.** Unknown capacity ⇒ the availability UI says nothing.
   Unknown times ⇒ the card shows the day only. This is a hard rule: a parent
   acting on a wrong time is a safeguarding incident, not a bug.
3. **Host-agnostic.** WordPress and Laravel emit the same JSON. Field names are
   neutral (`schedule.day`, not `_eca_day`).
4. **Versioned.** `schema: "nvoos-eca/v1"`; additive changes bump the minor within
   v1, breaking changes become `v2` with a documented mapping.
5. **Deterministic derivation.** Same source ⇒ same record (no clocks, no random
   ids, stable ordering).

### 3.2 Record

```json
{
  "schema": "nvoos-eca/v1",
  "id": "10850",
  "code": "STEM-AI-TUE",
  "slug": "ai-for-kids-stem-tuesday",
  "url": "/activities/ai-for-kids-stem-tuesday/",
  "title": "AI for Kids (STEM) Tuesday",
  "summary": "Hands-on AI and coding programme with external provider Meu Labs.",
  "descriptionHtml": "<p>Hands-on AI and coding program…</p>",
  "categories": [
    { "slug": "technology", "name": "Technology", "color": "#8764a0" }
  ],
  "stages": ["senior"],
  "yearGroups": ["7", "8", "9"],
  "schedule": {
    "day": "tuesday",
    "start": "14:30",
    "end": "15:30",
    "durationMinutes": 60,
    "frequency": "weekly",
    "term": "2026-autumn",
    "timezone": "Asia/Colombo",
    "timeOfDay": "after-school"
  },
  "venue": { "name": "STEM Lab", "building": "Senior School" },
  "provider": { "type": "external", "name": "Meu Labs" },
  "staff": [{ "name": "—", "role": "lead" }],
  "capacity": { "max": 20, "enrolled": 14, "waitlisted": 3, "state": "open" },
  "pricing": {
    "paid": true,
    "amount": 22500,
    "currency": "LKR",
    "period": "per_term",
    "display": "Rs. 22,500 per term"
  },
  "booking": { "type": "open", "requiresAudition": false },
  "status": "active",
  "media": { "image": "…", "alt": "Students coding in the STEM lab" },
  "links": { "ics": "/activities/ai-for-kids-stem-tuesday/session.ics" },
  "source": { "kind": "wp", "postType": "mcp_ai_eca", "modified": "2026-06-23T11:45:17" },
  "inferred": ["schedule.day", "pricing.amount", "pricing.period", "provider.name", "stages"]
}
```

### 3.3 Field contract

| Field | Type | Source (WP) | Derivation when absent |
|---|---|---|---|
| `id` | string | `post.ID` | — |
| `code` | string? | `_eca_code` | uppercased slug fragments, or omitted |
| `slug` | string | `post.post_name` | — |
| `url` | string | computed | `/<base>/<slug>/` |
| `title` | string | `post_title` (decoded, plain) | — |
| `summary` | string? | first sentence of the description, ≤ 200 chars | trimmed `descriptionHtml` |
| `descriptionHtml` | string | `post_content` (sanitised by the loader) | `""` |
| `categories[]` | `{slug,name,color?}` | `mcp_ai_eca_category` terms | `other` |
| `stages[]` | `("junior"\|"senior"\|custom)[]` | `profile.eca.stages[].match` (title/content/year groups) | from `yearGroups`, else all stages |
| `yearGroups[]` | string[] | `_eca_year_groups` | title/content regex (`Year 4`, `Y4`, `Grade 3`), else `[]` |
| `schedule.day` | `monday…sunday`? | `_eca_day` | title, then slug, then content (`Tuesday`, `Tue`, `Tues`) |
| `schedule.start` / `.end` | `HH:MM`? | `_eca_start_time` / `_eca_end_time` | content regex (`14:30–15:30`, `2.30pm-3.30pm`), else omitted |
| `schedule.durationMinutes` | integer? | computed | `end − start` when both present |
| `schedule.frequency` | `weekly\|fortnightly\|term\|once` | `_eca_type`/`_eca_booking_type` heuristics | `weekly` |
| `schedule.term` | string? | `profile.eca.terms[].id` (active term) | profile default |
| `schedule.timeOfDay` | `before-school\|lunch\|after-school\|evening`? | computed from `start` | bucket on `profile.eca.timeBands` |
| `venue` | `{name, building?}`? | `_eca_venue` | content regex after “at/in”, else omitted |
| `provider` | `{type,name}`? | `_eca_type` = `external` + content “external provider X” | internal when no mention |
| `staff[]` | `{name, role?}`[] | `_eca_teachers` (user ids → display names; **never emails**) | omitted |
| `capacity` | `{max,enrolled,waitlisted,state}`? | `_eca_max_students` + enrolments counts | omitted entirely |
| `capacity.state` | `open\|waitlist\|full\|closed` | computed | `open` only when `max` known |
| `pricing` | `{paid,amount?,currency?,period?,display?}`? | `_eca_is_paid`, `_eca_cost`, `_eca_cost_period` | parse `Rs. 22,500 per student per term`; `free` when “free”/“no charge” |
| `booking` | `{type,requiresAudition}` | `_eca_booking_type`, `_eca_requires_audition` | `open` |
| `status` | `active\|cancelled\|inactive` | `_eca_status` | `active` |
| `media` | `{image,alt}`? | featured media | first content image |
| `links.ics` | string | computed when `schedule.day` + start | omitted |
| `source` | `{kind,postType,modified}` | — | — |
| `inferred[]` | string[] | computed | — |

Deliberately **not** in v1: `_eca_google_course_id`, `_eca_classroom_sync`
(management-only, never public), student data, attendance.

### 3.4 Derivation rules (the thin-data path)

These make the current 105 activities usable **without touching the CMS**:

- **Day**: `_eca_day` → title → slug → content. Normalise `Tues`/`Tuesday`,
  `Wed`/`Weds`. A title ending in a weekday is the strongest signal (every current
  entry follows it).
- **Stage**: explicit meta (`_eca_year_groups` mapped through
  `profile.eca.stages`) → a `Junior`/`Senior` token in the title or content →
  the page it is linked from (when a bridge sends it). Unmatched ⇒ both stages,
  with `inferred` noting it.
- **Price**: `_eca_is_paid` wins; else a currency regex (`Rs.`, `LKR`, `$`, `£`,
  `€`) + `per term|per year|per month|one-time`, else “free”.
- **Provider**: “external provider <Name>” / “run by <Name>” / “with <Name>” ⇒
  external; otherwise internal.
- **Time**: `HH:MM`, `H.MMpm`, `H:MM am` normalised to 24-hour; a range yields both
  ends; a single time yields `start` only.
- **Venue**: `_eca_venue` → “at/in <Place>” in the first 200 chars of the
  description, only when the token is capitalised and not a weekday.

Every rule that fires appends its path to `inferred`. The UI shows an “as
published” note on inferred time/price, never a fabricated one.

### 3.5 Site config (profile block)

```json
{
  "eca": {
    "schema": "nvoos-eca/v1",
    "basePath": "/activities",
    "schoolName": "British School in Colombo",
    "timezone": "Asia/Colombo",
    "currency": { "code": "LKR", "symbol": "Rs.", "locale": "en-LK" },
    "terms": [
      { "id": "2026-autumn", "label": "Autumn Term 2026",
        "start": "2026-09-01", "end": "2026-12-11", "current": true }
    ],
    "stages": [
      { "id": "junior", "label": "Junior School", "yearGroups": ["1","2","3","4","5","6"],
        "match": ["junior", "prep", "primary"], "copy": { "intro": "…" } },
      { "id": "senior", "label": "Senior School", "yearGroups": ["7","8","9","10","11","12","13"],
        "match": ["senior", "secondary", "college"] }
    ],
    "days": ["monday","tuesday","wednesday","thursday","friday"],
    "timeBands": { "before-school": "06:00-08:15", "lunch": "12:00-13:30",
                   "after-school": "13:30-18:00" },
    "categoryColors": { "sports": "#2f6f4e", "arts-music": "#a0612e" },
    "venueAliases": { "stem lab": "STEM Lab" }
  }
}
```

`stages` is the *template switch*: a school with Primary/Secondary (or Lower/Upper)
reconfigures data, not code. `days` filters the day strip; schools that run
Saturdays add `"saturday"`.

---

## 4. Information architecture

Public route table (canonical; the legacy `/eca/<id>/<slug>/` and `/eca/` paths
301 to these via the generated redirect map):

| Route | Purpose | Rendering |
|---|---|---|
| `/` | Home: hero, “next up” strip, stage chooser, category tiles, day strip, how-it-works, FAQ, CTA | static |
| `/activities/` | **Catalogue**: search + facets + result list | static + island |
| `/activities/<slug>/` | Activity detail | static (1 page/activity) |
| `/stage/<stage>/` | **Stage template** (Junior/Senior): stage hero + copy + its catalogue preset | static |
| `/day/<day>/` | Day landing (the live site's model) | static |
| `/category/<slug>/` | Category landing (SEO) | static |
| `/calendar/` | Calendar: agenda / week / month, term-scoped | static + island |
| `/timetable/<stage>/` | Days × time-band grid per stage | static + island |
| `/terms/` | Term dates + key dates | static |
| `/search/` | Site search results (`?q=`) | static + island |
| `/how-it-works/`, `/faq/`, `/contact/`, `/accessibility/`, `/policies/` | Info & help | static (pages collection) |
| `/shortlist/` | Client-side shortlist (localStorage) | static + island |
| `/feeds/activities.ics`, `/feeds/<stage>.ics`, `/feeds/<category>.ics` | Calendar subscriptions | static |

SEO policy per route type:

- **Index**: home, catalogue, activity detail, stage, category, day, terms, info.
- **Noindex, follow**: every filtered catalogue combination (`?…`) — the canonical
  points at the unfiltered catalogue; facet *landings* (stage/day/category) are
  the indexable equivalents (avoids infinite facet crawl).
- **Noindex**: `/search/`, `/shortlist/`, `/feeds/*`.

The nav becomes: **Activities · Calendar · Junior · Senior · About · Contact**
(≤ 6 items), with day/category landings in the footer + home strip. The current
site's day pages move under `/day/<day>/` and are no longer top-level nav.

---

## 5. Page templates

### 5.1 Home

1. **Hero** — school name, one sentence, primary CTA “Browse activities”, secondary
   “See this week's timetable”. Optional hero image from the design donor.
2. **Next up** — the next 5 activities that run today/tomorrow (from the same
   normalised set), each a compact card.
3. **Stage chooser** — one card per `stages[]` (Junior / Senior) with count and
   year-group range; this is the template switch the live `select-list` implies.
4. **Day strip** — Monday–Friday with counts, linking to `/day/<day>/`.
5. **Categories** — tiles with counts and the category colour + icon (never colour
   alone).
6. **How it works** — 3 steps (Choose → Register → Attend), matching the toolkit's
   `booking_type` modes.
7. **FAQ** — 4–6 questions, plain HTML (no `FAQPage` schema).
8. **Footer** — contact, help, accessibility, term dates, calendar subscribe.

### 5.2 Catalogue (`/activities/`)

- **Header**: H1 “Activities”, result count (`aria-live="polite"`), sort control
  (Day · Name · Availability).
- **Facets** (desktop sidebar / mobile sticky drawer): Day, Stage, Year group,
  Category, Time of day, Availability, Price. Values carry counts; multi-select OR
  within a facet, AND across facets.
- **Cards**: title, category chip(s), day + time, venue, year groups, price,
  availability badge; whole card is one link; a secondary “Add to shortlist”
  button (44×44 target).
- **Empty state**: “No activities match …” + per-chip removal + “Clear all” + the
  two nearest non-empty combinations.
- **URL**: `?q=&day=&stage=&cat=&year=&time=&avail=&price=&sort=`; every change
  pushes history; back/forward restores.
- **No-JS**: the full catalogue renders server-side (all cards). Without JS the
  facet form is a plain GET form that reloads with the parameters applied *when
  the site is SSR*; on the static tier the no-JS fallback is the unfiltered list
  plus the indexable facet landings. This asymmetry is documented on the page
  (“filtering needs JavaScript”) only for the static tier.

### 5.3 Activity detail (`/activities/<slug>/`)

Two columns (single column ≤ 900 px): main = title, summary, description,
“What to bring”, “Who it's for”; aside = **facts panel** (day, time, venue, year
groups, price, provider, availability, booking mode) + primary CTA + “Add to
calendar” (`.ics`) + “Add to shortlist”.

- **Availability**: only when `capacity` exists. `open` ⇒ “12 of 20 places
  left”; `waitlist` ⇒ “Full — join the waitlist”; `closed` ⇒ “Registration
  closed”.
- **Booking CTA** by mode: `open` ⇒ “Register” (links to the toolkit's enrolment
  URL / WooCommerce product); `audition` ⇒ “Register interest”; `invite_only` ⇒
  “Invitation only — contact the school”.
- **Related**: same category, then same day, max 3.
- **JSON-LD**: `Course` + `CourseInstance` + `BreadcrumbList` (+ `Event` when a
  dated session is known).

### 5.4 Stage template (`/stage/<stage>/`)

The “senior school vs junior school” template the current site hand-builds:

- Stage hero (label, year groups, intro copy from `profile.eca.stages[].copy`).
- The stage's catalogue **preset**: facets pre-filtered (`stage=<id>`), a day
  strip scoped to that stage, and its own H1 (`Junior School activities`).
- Stage-specific FAQ / notes (optional `copy.faq`).
- Cross-link to the other stage(s).

### 5.5 Day landing (`/day/<day>/`)

Replaces the six live day pages: H1 “Tuesday activities”, stage split as two
sections (or a stage tab), a time-ordered list, and links to the neighbouring
days. `ItemList` JSON-LD.

### 5.6 Category landing (`/category/<slug>/`)

H1 + intro (`profile.eca.categoryCopy`), the filtered list, counts per stage, and
`ItemList` + `BreadcrumbList`.

### 5.7 Calendar (`/calendar/`)

- **Views**: Agenda (default ≤ 767 px), Week, Month. Term selector when more than
  one term exists.
- **Agenda**: grouped by date, one row per session (date, time, activity, venue),
  each linking to the detail page.
- **Week/Month**: grid; each cell lists up to 3 activities + “+n more”; keyboard
  grid semantics (`role="grid"`, arrow keys, announced period change).
- **Filters**: stage + category reuse the same facet state machine.
- **Subscribe**: “Add to calendar” → `.ics` (webcal), plus per-stage/category
  feeds.
- **Expansion**: `schedule.frequency: weekly` + term dates ⇒ concrete sessions;
  holidays are excluded by the term's `start`/`end` and any `profile.eca.terms[].exclude`
  ranges.

### 5.8 Timetable (`/timetable/<stage>/`)

Days as columns, time bands as rows — the school's mental model (and the toolkit's
`get_eca_timetable` view). A cell holds an activity chip (category colour + label);
empty cells are silent. Scrolls horizontally on mobile with a sticky day header.

### 5.9 Info & help

`/how-it-works/`, `/faq/`, `/terms/`, `/contact/`, `/accessibility/`,
`/policies/`. Content comes from the pages collection; `/help` is the consistent
help affordance required by WCAG 3.2.6 (footer + filter drawer + empty states).

---

## 6. Search

Three layers, cheapest first:

1. **Facet filtering** (no query) — the primary discovery path.
2. **Client-side search index** — a pre-built `/search-index.json` of
   `{slug,title,category,day,venue,yearGroups,keywords}`; substring + token match,
   ranked (title > keywords > category > venue). Loaded on first focus of the
   search field, never on page load.
3. **Server search** (SSR/managed tier) — `nvoos-eca/v1/activities?q=` proxied to
   WordPress `search` or the Laravel query; the island upgrades to it when the
   endpoint answers.

Normalisation: lowercase, strip diacritics, split camelCase, expand a small
synonym map (`football → soccer`, `coding → computing`, `stem → science`), and
match on prefixes (min 2 chars).

---

## 7. Shortlist (no accounts)

“Add to shortlist” stores slugs in `localStorage` (`nvoos-eca:shortlist:v1`) —
survives navigation (WCAG 3.3.7), never syncs, never leaves the device. `/shortlist/`
lists them with day/time so a family can compare; “Clear” confirms. When the
toolkit has a registration flow, the shortlist becomes the payload of the
“Register” hand-off.

---

## 8. Structured data, SEO and feeds

- `Course` + `CourseInstance` on detail pages; `Event` + `eventSchedule` when
  sessions are dated; `ItemList` on catalogue/stage/day/category; `BreadcrumbList`
  everywhere; `Organization` once.
- Canonicals: catalogue → `/activities/`; stage/day/category → themselves;
  filtered variants → the unfiltered canonical.
- Sitemap: one `<url>` per activity + per landing; **no** query-string URLs;
  `lastmod` from `source.modified`.
- Redirects: `/eca/<id>/<slug>/` → `/activities/<slug>/`; `/eca/` →
  `/activities/`; `/eca-days/` → `/day/monday/`; `/tuesday/` … → `/day/<day>/`;
  `/junior-school-list/` → `/stage/junior/`.
- Feeds: RFC 5545, one `VEVENT` per activity with
  `RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=<term end>`, `TZID` from the profile, stable
  `UID` (`<code>@<host>`) so re-subscribing updates rather than duplicates.

---

## 9. Accessibility contract (WCAG 2.2 AA)

Non-negotiables, checked in the axe e2e gate:

- Facets are a `<fieldset>`/`<legend>` group; chips are buttons ≥ 24×24 (aim 44×44).
- Result-count updates are announced; focus moves to the results heading on
  apply, not to the top of the page.
- Category colour always paired with a label + icon; availability badge carries
  text (`Open`, `Waitlist`, `Full`).
- Sticky header never obscures the focused control (2.4.11) — a
  `scroll-margin-top` on targets and a focus-visible ring with offset.
- Calendar grids: `role="grid"`, arrow-key navigation, `aria-live` period label;
  agenda view is a plain list.
- `prefers-reduced-motion` disables card entry and marker motion; no autoplay.
- Consistent help (3.2.6): `/help` in the footer, the filter drawer and every
  empty state.

---

## 10. Performance contract

- Catalogue page: LCP ≤ 2.0 s (4G), CLS ≤ 0.05, INP ≤ 200 ms; island JS ≤ 40 KB
  gzip; search index lazy.
- One request per page (no client fetch for the initial list); facet counts
  pre-computed at build.
- Images via `SmartImage`; hero `fetchpriority="high"`; every card image has
  explicit dimensions.
- Beyond ~2 000 activities: paginate the static build (`maxPages` already exists)
  and link to the SSR tier for full filtering.

---

## 11. API contracts

### 11.1 WordPress (source of truth)

Two options, both additive (no changes to existing behaviour):

1. **Expose the meta** — `register_post_meta()` for each `_eca_*` field with
   `show_in_rest: true`, `single: true`, `auth_callback` = `__return_true` for the
   read-only public fields (never `_eca_google_course_id`). Cheapest, but the
   client must still assemble the format.
2. **A read-only projection** — `GET /wp-json/nvoos-eca/v1/activities` returning
   `{ schema, items[], facets{day,category,stage,yearGroup,venue,availability},
   terms[], updatedAt }`; `?q&day&stage&cat&year&venue&avail&price&page`; `ETag`
   + `Cache-Control: public, max-age=300, stale-while-revalidate=86400`;
   `GET /nvoos-eca/v1/activities/<id>` for one record;
   `GET /nvoos-eca/v1/availability` (short TTL, enrolments-derived counts).
   **Recommended**: one contract, computed server-side, no client assembly.

Both options keep the `inferred` provenance the plugin knows and the client cannot
reconstruct (the plugin has the raw meta *and* the prose).

### 11.2 Laravel

The same `/nvoos-eca/v1/*` routes and JSON shape, backed by the ECA models. The
Astro loader is host-agnostic: `WP_SITE_URL` or `ECA_API_URL`, same normaliser.
A contract test (JSON Schema) runs against both.

### 11.3 Build-time loading

The forge already loads collections via `wp/v2`. For `nvoos-eca/v1` the same
`createWpLoader` pattern applies with a different path; the normaliser
(`lib/eca.ts`) is the single place the shape is validated, so a contract drift is
a build error, not a blank card.

---

## 12. Forge integration

1. **New preset `eca`** (`lib/presets.ts`): `suggestPreset()` returns it when the
   profile carries an `mcp_ai_eca` (or `nvoos-eca`) collection. It applies the
   `eca` profile block defaults, the route set and the facet module.
2. **`lib/eca.ts`** — the normaliser + facet engine + ICS builder (pure, no Astro,
   no fetch). Shared with the tests and the generated project
   (`src/forge/eca.ts`), like `semantic.ts`.
3. **Scaffold emissions** (only for the `eca` preset):
   - `src/lib/eca.generated.ts` — profile config (stages, terms, currency, days,
     category colours) + the derived base path.
   - routes: `activities/index.astro`, `activities/[slug].astro`,
     `stage/[stage].astro`, `day/[day].astro`, `category/[slug].astro`,
     `calendar.astro`, `timetable/[stage].astro`, `terms.astro`, `search.astro`,
     `shortlist.astro`, `feeds/[scope].ics.ts`.
   - `src/components/eca/` — `ActivityCard`, `FacetPanel`, `ActivityFacts`,
     `AvailabilityBadge`, `CalendarGrid`, `TimetableGrid`, `ShortlistButton`,
     `StageHero`.
   - `src/scripts/eca-facets.ts` — the island (facet state, URL sync, search).
   - `/search-index.json` endpoint.
4. **`verify --eca`** — a new gate:
   - every activity resolves to the format (no missing `slug`/`url`/`title`);
   - every facet value has a landing (category/day/stage) and a count;
   - `day` coverage ≥ 95 % of active activities (or the profile opts out);
   - price/currency consistency against `profile.eca.currency`;
   - every `links.ics` returns a valid `VCALENDAR` (parse + `UID` uniqueness);
   - JSON-LD present and schema-valid on detail/stage/category;
   - no filtered URL in the sitemap; canonical present on every indexable page.
5. **Existing gates still run** (text/geometry/colour/tracking/SEO/axe) — the new
   pages become their subjects, so a classification regression on a card is
   caught by `verify --text` as before.

---

## 13. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | `nvoos-eca/v1` is the contract, not `wp/v2` internals | host-agnostic (WP **and** Laravel), testable with a JSON Schema |
| D2 | `/activities/` is canonical; `/eca/*` 301s | product-friendly, decoupled from the CPT rewrite |
| D3 | Stages are config, not code | schools differ (Junior/Senior, Primary/Secondary, Lower/Upper) |
| D4 | Static-first, island filtering | SEO + no-JS + CDN cost; SSR is the managed upgrade for live availability |
| D5 | Unknown ⇒ omitted, inferred ⇒ labelled | a wrong time/price is a safeguarding and trust failure |
| D6 | Agenda is the calendar baseline | WCAG + no-JS; week/month are enhancements |
| D7 | No `FAQPage`/`HowTo` schema | Google removed both rich results (repo rule, Proposal 136) |
| D8 | Shortlist is local-only | no accounts in v1; sets up a future registration hand-off |

## 14. Open questions

1. **Registration hand-off**: does the Astro “Register” button open the toolkit's
   enrolment form (WP), a WooCommerce product, or the Laravel tenancy's own flow?
   Proposal D4 keeps it a link; the URL shape must be agreed with the toolkit.
2. **Live availability on the static tier**: build-time counts go stale within a
   term. Options: nightly rebuild, SSR tier, or a tiny availability endpoint the
   island calls (short TTL). Recommend the endpoint + graceful “check at
   registration” copy.
3. **Parent portal**: out of scope for v1, but the shortlist and the `.ics` feeds
   are the natural bridge.
4. **Multi-school (multi-tenant)**: the toolkit is tenant-aware; a district/multi-
   campus site needs a campus facet + per-campus stage config. Deferred.
