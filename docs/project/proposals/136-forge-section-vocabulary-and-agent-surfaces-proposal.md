# Proposal 136 — Forge section vocabulary, portable design systems, and agent-readable output

> Status: **Core shipped 2026-11-09.** P1 (`data-section` + the kind gate),
> P2 (the six kinds), P3 (band JSON-LD + `verify --seo --structured`), P5
> (`llms.txt`, `.md` variants, `MOTION.md`, `presets.json`) and the P4 core
> (`scopeCss` / `composeSystemReadme`) are implemented and green: **678 unit
> tests + 31 e2e** (astro check, axe gate, SSR variant, offline build).
> Remaining: the `export-system` CLI + specimen route, the two MCP tools, and
> the skill notes — see §12.
> Extends Proposal 096 (Astro Forge), 114 (design rebuild), 117 (section theming),
> 120/122/123/125 (fidelity facts), 126 (velocity cross-pollination: SmartImage,
> DTCG, axe gate), 130/131/132 (capture arc), and 135 (deploy tiers).
> Touches 095 (ADS tokens / `tokens_import_dtcg`), 104 (agent stubs, copy tables),
> 107 (storefront structured-block render pipeline).
>
> Companion: `136-forge-section-vocabulary-and-agent-surfaces-implementation-plan.md`.
>
> Origin: an assessment of [`RayVelez27/instalanding.ai`](https://github.com/RayVelez27/instalanding.ai)
> (MIT; 91 single-file landing pages, 20 extracted design systems, 10 motion
> studies, agent-first metadata) against what the forge actually emits today.
> That repo is a **vocabulary and packaging model**, not an engine — the forge
> already owns the engine (deterministic design-language extraction + parity
> gates). This proposal takes the vocabulary and the packaging, and explicitly
> refuses its delivery model.

---

## 1. Executive summary

The forge can rebuild a customer site faithfully — it extracts design
*language*, rebuilds it with the ADS, and proves the result with parity,
fact-consumption, geometry, vision and axe gates. Three things it cannot do:

1. **Name what it built.** Every forged band carries `data-section-id` (the
   source's Elementor identity) but never its *kind*. Downstream agents — and
   our own verifiers — have to re-infer "this is a pricing band" from markup.
2. **Render six section kinds real pages need.** Pricing, stats, steps, team,
   marquee and comparison are coerced into `feature-grid`, losing the structure
   that makes them those things (a price is not a caption).
3. **Hand the client a design system.** Tokens are captured and emitted as
   DTCG, components exist — but there is no specimen, no scoped component
   stylesheet, no conventions document. The most re-sellable artifact of a
   rebuild is thrown away.

And one correctness problem research surfaced: structured data is emitted per
*post type*, never per *band*, so a rebuilt pricing band ships no `Offer` while
a rebuilt FAQ band ships `FAQPage` markup whose Google rich result **no longer
exists**. Both are cheap to fix and one of them is an active liability.

Proposal answer, in one line:

> **Add a closed six-kind section vocabulary plus a `data-section` kind
> attribute to forged output; ship `forge export-system` to package any forged
> site as a reusable design system (tokens + scoped CSS + specimen +
> conventions); add deterministic agent surfaces (`llms.txt` v2, `.md` page
> variants, `MOTION.md`); and gate structured data against visible text —
> never emit data Google cannot see, and never emit a type whose feature is
> dead.**

Deliberately **not** adopted from the reference repo: the one-file/no-build
delivery model, CDN GSAP/Three.js, runtime JS motion, and its aesthetic
catalogue as a source of *design*. All four contradict shipped invariants
(130 consent-first tracking + CSP, 121 zero-JS CSS motion, 096 sanitisation,
098/119 rebuild-not-replicate).

---

## 2. Problem statement

### 2.1 The vocabulary gap

`DesignRenderer.astro` renders this set today:

`hero, feature-grid, icon-list, testimonial, logo-cloud, cta, gallery, text, form, catalog`
(authored-content blocks add `faq` and `tabs` via 126).

The reference library's 91 pages — and any browse of client marketing sites —
converge on a wider set. The six we lack are not cosmetic; each has a distinct
data shape and an accessibility obligation:

| Kind | Why coercion to `feature-grid` is lossy |
|---|---|
| `pricing` | A price is a number with a currency, a period and a plan identity. As a caption it becomes a string; no `Offer` can be derived, no toggle, no featured-plan emphasis. |
| `stats` | A metric is a value + a unit + a label. As body text it cannot be aligned, and "98%" becomes indistinguishable from prose. |
| `steps` | Order is the information. `feature-grid` is unordered; an `<ol>` was never available. |
| `team` | Person + role + portrait. `Person` JSON-LD depends on exactly this shape. |
| `marquee` | Repetition + loop + **a pause control** (WCAG 2.2.2). A grid has no motion contract at all. |
| `comparison` | Row/column relationships. Only a real `<table>` with `<th scope>` conveys that to assistive tech. |

### 2.2 Machine-identifiable, not machine-classifiable

`data-section-id="059421c"` says *which* band. Nothing says *what* it is. The
consequences compound:

- `lib/fact-audit.ts` can check that padding rendered, but must be told which
  facts to expect per band from the profile.
- `lib/geometry.ts` measures `[data-section-id]` boxes without knowing a
  pricing band should be taller than a logo strip.
- The `/mcp` surface can hand an agent a built site but not a liftable section.
- Any third-party agent reading a forged page must re-classify it from scratch.

### 2.3 The design system is captured and then discarded

Proposal 126 already emits `public/tokens.dtcg.json`. The component set exists
as `.astro` files. What is missing is the *deliverable package* that makes a
rebuilt site reusable by someone who was never in the build: a specimen page, a
single scoped stylesheet, and a conventions document. Today a client who asks
"can you give me the design system?" gets a repo.

### 2.4 Structured data is type-scoped, and partly wrong

`src/lib/seo.ts` emits kind-typed JSON-LD per page (`BlogPosting`, `Product`,
`WebPage`, `Article`). Two defects:

1. **Band-level facts are invisible to it.** A rebuilt pricing band ships no
   `Offer`/`OfferCatalog`; a rebuilt FAQ band ships nothing, because nothing
   inspects the band.
2. **Dead features are still in the shape.** Google removed the FAQ rich result
   on 2026-05-07 and deleted its documentation on 2026-06-15 (§3.2). `HowTo`
   went in 2023. Emitting either for a feature that no longer exists is noise
   at best and a "structured data does not match visible content" signal at
   worst.

There is also no gate that JSON-LD *matches the visible text* — the one rule
Google states explicitly for AI features (§3.2).

---

## 3. Findings

### 3.1 Repository facts (verified 2026-11-09)

| Fact | Where | Consequence |
|---|---|---|
| Bands carry identity, not kind | `templates/blog/src/components/design/DesignRenderer.astro` — `<div data-section-id={section.id} …>` | Adding `data-section` is a one-attribute change at a single site |
| `data-section-id` is load-bearing | `lib/design.ts#builtBandsOf`, `lib/fact-audit.ts#sectionHtml` + `#auditFacts`, `lib/geometry.ts#measureGeometry`, `lib/scoped-css.ts#scopedPaddingOf` | The new attribute must be additive; nothing may key off it that the old one already serves |
| Section kinds are a closed union in the classifier | `lib/semantic.ts` (Elementor widgets + `wp-block-*` → typed sections), `lib/design.ts#analyzeSections` | New kinds need classifier rules **and** a renderer branch **and** learned-vocabulary seeds (116) |
| `verify` already has a fact-markers table | `lib/fact-audit.ts#FACT_MARKERS` (+ `present` hook for out-of-slice markers) | A `data-section` gate rides the same mechanism |
| Structured data is centralised and typed | `templates/blog/src/lib/seo.ts` (over `schema-dts`), 131 | Band-level JSON-LD has one obvious home |
| DTCG emission exists | `renderTokensDtcg` → `public/tokens.dtcg.json` (126) | `export-system` reuses it; no new token work |
| Inert-then-renamed template files are an established pattern | `products/_ssr/` → `[id]/`, `_og/` → `og/` (096 gotchas) | The specimen page should follow it (`_system/` → `specimen/`) |
| A drift gate already hashes generated files | `nvoos.generated.json` + `scripts/check-generated.mjs` (126) | The specimen joins the generated set and cannot silently rot |
| axe runs in real Chromium in the e2e | `tests/forge-e2e.test.ts` (126 Cluster B) | New kinds can be a11y-gated with zero new infrastructure |
| Agent stubs already ship per scaffold | `AGENTS.md` + `.context/` (104) | `llms.txt`/`MOTION.md` have a precedent and a place |
| Presets are code-only | `lib/presets.ts` (`blog`/`storefront`/`minimal`), MCP `site_*` tools | Agents outside MCP cannot discover them |

### 3.2 Industry standards (researched 2026-11-09)

**Structured data — the rules changed twice and we are behind both.**

- **FAQ rich results are gone.** Google added the deprecation notice
  2026-05-08 ("this feature will no longer appear in Google Search starting
  May 7, 2026"), then **removed the FAQ rich-result documentation entirely on
  2026-06-15**. `FAQPage` markup "can be retained for other uses if it
  accurately describes the content", but must not be implemented *to obtain a
  rich result*.
- **`HowTo` is gone.** Documentation removed 2023-09-14 — "this rich result is
  no longer shown in search results, on both desktop and mobile devices."
- **The matching rule is explicit.** Google's *AI features and your website*
  guide (updated 2025-12-10) lists as SEO fundamentals: *"Making sure your
  structured data matches the visible text on the page"*, and *"You don't need
  to create new machine readable files, AI text files, or markup to appear in
  these features. There's also no special schema.org structured data that you
  need to add."*
- **Prices must be real and current.** Merchant-listing guidance (updated
  2026-07-07) uses `validFrom` / `validThrough` / `priceValidUntil` for sale
  windows and `priceType` for sale/member prices; a sale price shown must be
  present in the markup, and a price in markup must be payable on the page.
  Product markup is recommended in the **initial HTML**, not injected by JS —
  which is what a static forge build naturally produces.

**Agent surfaces — `llms.txt` v2 is a real specification now.**

- The [llms.txt proposal](https://llmstxt.org/) is at **v2** (modified
  2026-08-10). Additions that matter to us: *markdown variants of pages* at
  `page.md` (or `page.html.md`), and the link relations
  `rel="alternate" type="text/markdown"` (the markdown version) and
  `rel="describedby"` (the llms.txt covering that path) — expressible as
  `<link>` elements **or** an HTTP `Link:` header.
- Adoption is broad: Mintlify, GitBook, Wix and the WordPress plugins **Yoast
  SEO** and **AIOSEO** generate one automatically; OpenAI, Anthropic and Gemini
  publish them for their own docs.
- **Chrome Lighthouse audits sites for one as part of its agentic-browsing
  checks** (per the v2 text) — i.e. this is a *measurable* gate, not a vibe.
- **It is not an SEO play.** Google's changelog (2026-06-15) clarified that
  llms.txt files "aren't needed for Google Search (and won't negatively or
  positively impact your visibility or rankings)", while noting it is fine to
  maintain them "for other services or systems that use them".
- The spec's own guidance is that the file stays small and links out; the
  detail lives behind links. A deterministic emitter is trivial for a static
  build, and it must **not** be LLM-authored (determinism matters here exactly
  as it does for the rest of the forge).

**Design tokens — do not over-fit to the draft.**

- [DTCG Format Module 2025.10](https://www.designtokens.org/TR/2025.10/format/)
  is published (2026-09-08) as a *Draft Community Group Report* whose own
  status block says **"This is a preview draft… Do not attempt to implement
  this version"** and *"Do not reference this version as authoritative"*.
- What is stable enough to rely on and already matches our 126 emission:
  `$value`/`$type`/`$description`, grouped tokens, `{group.token}` aliases, the
  `$schema` URL, `application/design-tokens+json`, `.tokens`/`.tokens.json`.
- What we should **not** start emitting into customer artifacts: `$extends`,
  `$root`, property-level `$ref` JSON Pointers, and the full composite-type
  set (`typography`, `shadow`, `border`, `gradient`, `transition`,
  `strokeStyle`) — all live in the unstable part of the draft.
- `$extensions` with reverse-DNS vendor keys **is** the sanctioned place for
  our own metadata (source URL, extraction confidence, band id), and tools
  MUST preserve extensions they do not understand.
- The spec's own §3.5 names the documentation-tool category we are missing:
  Storybook, Zeroheight, Backlight, Supernova, Knapsack — i.e. a *specimen* is
  the standard deliverable, not an invention.

**Motion and accessibility — WCAG constrains the two new motion-bearing kinds.**

- WCAG 2.2 SC **2.2.2 Pause, Stop, Hide** (Level A; guidance updated
  2026-10-04): moving/scrolling content that starts automatically, lasts
  >5 seconds and sits in parallel with other content **needs a mechanism to
  pause, stop or hide**. Auto-updating content has **no 5-second exception**
  and may instead offer a frequency control.
- Critically: *"Moving, blinking, scrolling, auto-updating content is
  considered to start automatically either when it starts without direct user
  activation… or when it starts as a result of an indirect interaction (such
  as focusing/hovering over an element, or **scrolling an element into view**)."*
  Our 121 scroll-reveal/parallax pass is therefore in scope for 2.3.3
  (Animation from Interactions) and any auto-advancing band is in scope for
  2.2.2.
- A pause that only holds while focused "would not be considered a mechanism"
  — the control must be a real, persistent affordance.
- The axe gate we already run (126) cannot test motion (axe's own docs note
  `color-contrast` is JSDOM-incompatible; motion has no rule at all), so the
  marquee pause control must be asserted structurally in the e2e.

**Semantics beat data attributes.**

- `data-*` attributes are, per the HTML standard, for the page's own scripts —
  they carry no accessibility semantics and are not interpreted by assistive
  technology. `data-section` is therefore **advisory for agents**, and must
  ride alongside real semantics (`<section aria-labelledby>`, `<ol>`, `<table>`
  with `<th scope>`, `<figure>/<figcaption>`, `<dl>`), never replace them.
- Native `<details name>` gives an exclusive accordion with no JS — already the
  pattern in `FaqAccordion.astro`; `Tabs.astro` uses the radio technique. New
  kinds should prefer native elements before inventing ARIA.

---

## 4. Proposed architecture

### 4.1 Section kind taxonomy — v1 adds six kinds

Kinds stay a **closed enum**; an unrecognised band is `content` (the 116
"never enumerate widgets" rule generalises to kinds).

| Kind | Slots (profile shape) | Renders as | JSON-LD | A11y contract |
|---|---|---|---|---|
| `pricing` | `plans[{ name, price, currency, period?, features[], cta?, featured? }]`, `toggle?` | plan cards, optional monthly/annual `<fieldset>` | `OfferCatalog` of `Offer`s **only when every price is visible text** | `<table>` for feature matrices; toggle = radios; `aria-labelledby` per plan |
| `stats` | `items[{ value, unit?, label, caption? }]` | `<dl>` aligned value/label pairs | none | `<dl>`; value and unit in one node so screen readers do not split "98" / "%" |
| `steps` | `items[{ title, body?, media? }]` (ordered) | `<ol>` with counters | **none** (HowTo removed) | `<ol>`; step number in `aria-label`, not as decorative text only |
| `team` | `people[{ name, role, photo?, bio?, sameAs? }]` | `<figure>` grid | `Person` + `worksFor` the site `Organization` | `<figure>/<figcaption>`; photo `alt` = person name, never "team member" |
| `marquee` | `items[{ label, icon? }]`, `speed?`, `pauseControl?` (default on) | duplicated track, CSS-only loop | none | **WCAG 2.2.2**: persistent pause/play `<button aria-pressed>`; duplicate track `aria-hidden`; under `prefers-reduced-motion` the track is static and the control is not rendered |
| `comparison` | `columns[{ label }]`, `rows[{ label, values[] }]` | real `<table>` | none | `<caption>`, `<th scope="col">`, `<th scope="row">`; responsive via horizontal scroll container, never by changing semantics |

Classifier additions (`lib/semantic.ts`, plus `SEEDED_WIDGET_ROLES` /
`SEEDED_BLOCK_ROLES` seeds for 116):

| Signal | Source |
|---|---|
| `price-table`, `pricing`, `aux-price`, `elementor-widget-price-table`, `wp-block-*-pricing` | widget/block names + class tokens |
| `counter`, `odometer`, `stat`, `aux-counter` | widget names |
| `process`, `steps`, `timeline`, `aux-process` | widget names; strengthened by an `<ol>` descendant |
| `team`, `our-team`, `people`, `elementor-widget-team-member` | widget names |
| `marquee`, `logo-carousel`, `ticker`, `client-strip` | widget names + `animation`-bearing track |
| `compare`, `comparison`, `vs-table` | widget names; strengthened by a `<table>` descendant |

Ordering rule that prevents the 117 regression class: the *shape ladder* runs
after structured-item extraction, and `pricing`/`steps`/`comparison` require
their structural evidence (price tokens; an ordered list; a table) before the
kind wins. A band that merely *mentions* a price stays `feature-grid`.

### 4.2 `data-section` emission contract

One attribute, added at the single existing band wrapper:

```astro
<div data-section={ section.kind }        <!-- new: closed enum -->
     data-section-id={ section.id }       <!-- unchanged: identity -->
     id={ theme?.anchor }
     class:list={ ['ds-section-wrap', …] }>
  <section aria-labelledby={ headingId }>  <!-- real semantics, unchanged -->
```

Rules:

- `data-section` ∈ the enum; anything else emits `content`.
- It is **advisory**: no CSS selector, no layout rule, and no verifier may
  *depend* on it for correctness — that keeps it safe to omit in hand-authored
  content.
- `verify` gains a `sectionKind` fact: every built band's kind must match the
  profile's declared kind, and must be in the enum. Rides
  `lib/fact-audit.ts#FACT_MARKERS` (`present` hook not needed — the attribute is
  on the band element itself).
- `export-system` and the `/mcp` surface read it as the lift key (the
  reference repo's `data-section` idiom, minus the one-file delivery).

### 4.3 `forge export-system` — the portable package

New command: `forge export-system <profile.json> --target <dir> [--name <slug>]`.

Emits, from an already-scaffolded and built project:

```
<dir>/
  tokens.css          flat --forge-* custom properties (and --nvs-* aliases)
  tokens.dtcg.json    the 126 emission, + $extensions.nvoos.pro/* metadata
  <slug>.css          tokens + every component's CSS, scoped under .<slug>
  specimen.html       every kind instantiated with neutral fixture data
  README.md           conventions: what breaks when edited, motion contract,
                      a11y rules per kind, attribution/provenance block
```

Mechanics, chosen to avoid drift:

- The specimen is a **real route in the generated project**, not a second
  implementation: an inert `templates/blog/src/pages/_system/specimen.astro`
  renamed to `specimen/` at scaffold time only when `--system-export` is on
  (the `_ssr`/`_og` precedent). It imports `DesignRenderer` and instantiates
  each kind from fixture data.
- `export-system` therefore runs `astro build` and derives the bundle from
  `dist/`: `specimen.html` = built output; `<slug>.css` = the built CSS bundle
  with `[data-astro-cid-*]` attributes stripped and every selector prefixed by
  the root class; `tokens.css`/`tokens.dtcg.json` copied.
- The specimen links **only** `<slug>.css` (not the Astro bundle), so the e2e
  proves the scoped file is complete: build the bundle, serve `specimen.html`,
  assert no console errors, run axe, and assert N sampled computed styles match
  the in-project render.
- `README.md` is deterministic prose generated from the profile (kinds present,
  tokens in use, motion behaviours, known contrast fixes) — this is the
  "what breaks when you edit this" header the reference repo writes by hand,
  produced from facts we already hold.

Provenance: the README carries a **`## Extracted from`** block (source origin +
date + "design language only; no source markup, CSS or copy is reproduced"),
matching 098/119's standing rule. Attribution is a compliance artifact here,
not a nicety.

### 4.4 Agent surfaces

| Artifact | Route / location | Determinism |
|---|---|---|
| `llms.txt` (v2 shape) | `/llms.txt` | Built from the content model: H1 = site name, blockquote summary, `## Pages` / `## Products` / `## Sections` lists with one-line notes, `## Optional`. Hand-authored overrides via `profile.llms` merge on top |
| Markdown page variants | `/posts/<slug>.md`, `/pages/<slug>.md` | Rendered from the same sanitized HTML → a narrow, **our own** tag→markdown projection (no HTML→MD library, no LLM). Covers headings, paragraphs, lists, links, tables, images |
| Discovery links | `<link rel="alternate" type="text/markdown">` + `<link rel="describedby">` in `Base.astro`; the same as a `Link:` header in the managed tier's `server.js` | Static |
| `MOTION.md` | project root, written at scaffold | From `profile.design.behavior` + the 121 runtime: band → behaviour → range → reduced-motion fallback → the fact that the finished state is the default |
| `presets.json` | forge repo root, published with the package | From `lib/presets.ts`; the three descriptors plus `bestFor`/`detect` notes |

Explicit posture, stated in the docs and in the emitted README: **llms.txt is
for agents, not for Google.** Google says it needs no such file and gives it no
ranking effect (§3.2); the value is (a) coding/chat agents that consume the
site, and (b) Lighthouse's agentic-browsing check.

### 4.5 Structured-data ↔ visible-text gate

Two changes in `templates/blog/src/lib/seo.ts` + one new verify pass:

1. **Kill the dead types.** Remove `FAQPage` emission (keep the band; keep the
   HTML). Remove any `HowTo` path if a `steps` kind ever grows one. Document
   both removals with the Google dates so nobody re-adds them.
2. **Band-level facts become typed nodes.** `pricing` bands contribute
   `Offer`/`OfferCatalog`; `team` bands contribute `Person`; both are merged
   into the page's `@graph` with stable `@id` fragments (`#/pricing`,
   `#/person-<n>`). Only when the band's slots are complete enough to describe
   real, visible content.
3. **New gate — `verify --seo` extension `--structured`:** for every leaf value
   in the emitted JSON-LD that is a price, a rating, a date or a number,
   the same normalized string must appear in the built text of the band that
   contributed it. Failure modes that trip the gate: a price formatted in
   JSON-LD (`98.00`) but rendered as (`$98/mo`); an `Offer` whose plan is
   hidden on mobile; a `Person` whose name is only in an image `alt`.
   This is the mechanical form of Google's *"structured data must match the
   visible text"* rule, and it is the same discipline as 114's fact
   consumption — one gate per fact class.

---

## 5. Enhancement backlog (by area)

### 5.1 `examples/nvoos-astro-forge`

| Area | Change |
|---|---|
| `lib/types.ts` | `ForgeSectionKind` union (+6); `PricingPlan`, `StatItem`, `StepItem`, `TeamMember`, `MarqueeItem`, `ComparisonRow`; `profile.llms` |
| `lib/semantic.ts` | kind signals + seeds; ordering rules (§4.1) |
| `lib/design.ts` | `analyzeSections` recognises the six kinds; extraction of plans/stats/people when structured |
| `lib/fact-audit.ts` | `sectionKind` marker + enum check |
| `lib/scaffold.ts` | `--system-export` (renames `_system/`), emits `MOTION.md`, `llms.txt` route flag, `nvoos.generated.json` entries |
| `lib/system-export.ts` (new) | bundle derivation from `dist/` (CSS scope transform, specimen copy, README composer) |
| `lib/llms.ts` (new) | deterministic `llms.txt` v2 emitter + `.md` projection |
| `lib/presets.ts` | `presets.json` serializer |
| `scripts/forge.mjs` | `export-system` subcommand (commander; **plain JS — no TS syntax**) |
| `mcp/tools/` | `system_export` (write-gated), `sections_list` (read) |

### 5.2 `templates/blog`

| File | Change |
|---|---|
| `components/design/{Pricing,Stats,Steps,Team,Marquee,Comparison}.astro` (new) | one per kind, each a11y-complete at rest (the reference repo's rule: a component is complete without its page choreography) |
| `DesignRenderer.astro` | `data-section`; six render branches; motion guarded on `prefers-reduced-motion` |
| `src/lib/seo.ts` | drop `FAQPage`; band nodes; `@id` fragments |
| `src/pages/_system/specimen.astro` (new, inert) | every kind + fixture data |
| `src/pages/llms.txt.ts` (new) | route emitting the file |
| `src/pages/posts/[parts].md.ts`, `[slug].md.ts` (new) | markdown variants |
| `Base.astro` | `rel="alternate"` / `rel="describedby"` links |
| `src/styles/global.css` | marquee loop + pause, `@supports`-gated and reduced-motion-neutral |
| `scripts/check-generated.mjs` | include the new generated files |

### 5.3 `nvoos-forge-bridge`

No functional change required. Optional follow-up (not in scope here): a
`Cache-Tag` for `specimen`/`llms` is unnecessary — both change only on rebuild.

---

## 6. Decisions required (owner)

- **Q1 — pricing JSON-LD posture.** (a) Emit `OfferCatalog` whenever a pricing
  band has visible prices; (b) emit only on storefront profiles; (c) never.
  *Recommendation:* (a), behind the visible-text gate — it is the only version
  that cannot produce a mismatch.
- **Q2 — marquee default.** Ship `marquee` with the pause control always on and
  static-by-default under reduced motion (recommended), or ship it opt-in per
  profile. *Recommendation:* always render the pause control; it is a Level A
  obligation, not a preference.
- **Q3 — `export-system` surfaces.** CLI only, or CLI + `/mcp` `system_export`.
  *Recommendation:* both — the MCP tool is a thin wrapper over the same lib and
  the write gate is already solved.
- **Q4 — llms.txt scope.** Site-root only, or per-collection (`/products/llms.txt`).
  *Recommendation:* site root first; per-path only if a client content set
  exceeds a context budget.
- **Q5 — do we adopt the reference repo's CSS at all?** *Recommendation:* **no.**
  MIT permits it, but vendoring third-party page CSS contradicts the
  098/119 provenance line ("design language only, no markup") and complicates
  client deliverables. Take the contracts, not the CSS.

---

## 7. Success metrics

| Metric | Baseline | Target |
|---|---|---|
| Bands renderable as their true kind | 10 + 2 blocks | 16 + 2 |
| Bands coerced into `feature-grid` on a pilot rebuild | not measured → measure now | ≤ 1 per site after P2 |
| Forged bands carrying `data-section` | 0% | 100% |
| `data-section` ↔ profile kind mismatches caught by `verify` | n/a | gate blocks merge on 1 mismatch |
| Structured-data visible-text mismatches | not measured | 0 in the fixture + pilot |
| `FAQPage` emitted | present | removed, with a dated comment |
| Time to hand a client a design-system package | a repo + a meeting | one command, < 2 min |
| `llms.txt` present + valid shape | none | 100% of forged sites; Lighthouse agentic check passes |
| Specimen axe violations | n/a | 0 serious/critical |
| Specimen CSS completeness (computed-style sample) | n/a | 100% of sampled components match in-project render |

---

## 8. Effort

| Phase | Scope | Estimate |
|---|---|---|
| P1 | `data-section` + `sectionKind` fact gate + enum in types | 0.5 day |
| P2 | Six kinds: components + renderer branches + classifier + fixtures | 4–5 days |
| P3 | Structured-data rework (`seo.ts`) + `verify --structured` gate | 2 days |
| P4 | `_system/specimen` + `export-system` (scope transform, README composer) | 3–4 days |
| P5 | Agent surfaces (`llms.txt`, `.md` variants, `MOTION.md`, `presets.json`) | 2–3 days |
| P6 | MCP tools + docs + skill updates | 1–2 days |

Sequencing constraint: **P1 before P2** (the renderer change is shared), **P3
after P2** (band nodes need real slots), **P4 after P2** (the specimen
instantiates every kind). P5 is independent and can run in parallel with P3/P4.

---

## 9. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| New classifier rules misclassify, regressing 116/117 fidelity | Medium | Shape-ladder requirement per kind; e2e pins on the jayainc-shaped fixture; `analyze --sections` diff on a known-good profile before/after |
| `data-section` gets used as a correctness dependency and breaks hand-authored content | Medium | Documented as advisory; the enum fallback is `content`; no CSS keys off it |
| Scoped-CSS extraction loses Astro's scoping semantics (the riskiest piece) | High | Standalone-specimen e2e: build the bundle, serve it with only `<slug>.css`, assert computed styles + axe + no console errors. If it cannot be made faithful, fall back to shipping the built bundle **and** the tokens (still a valid deliverable) |
| Marquee breaches 2.2.2 | Low if specified | Persistent pause button, `aria-pressed`, static under reduced motion, e2e asserts the control exists |
| `Offer` markup drifts from visible prices | Medium | The `--structured` gate is the whole mitigation; it fails the merge, not the build |
| DTCG draft churn breaks the interchange doc | Low | Emit only the stable subset (126 already does); never emit `$extends`/`$root` |
| llms.txt mistaken for an SEO feature by a client | Medium | State the posture in the emitted README and in proposal docs; never sell it as rankings |
| Scope creep into "generate a whole new aesthetic" | Medium | Kinds render with the *client's* tokens only; no aesthetic catalogue, no style library |

---

## 10. Out of scope

- **The reference repo's delivery model** — one-file/no-build pages, CDN
  GSAP/Three.js, WebGL/canvas art. Incompatible with 130 (consent-first
  tracking, CSP derivation) and 121 (zero-JS CSS motion).
- **Runtime JS motion.** No JS choreography library in forged output.
- **A style catalogue.** Kinds are structure; the palette/type/motion always
  come from the client's extracted design language.
- **LLM-authored artifacts.** No model writes `llms.txt`, `.md` variants,
  `MOTION.md` or the export README — they are derived from facts.
- **Third-party CSS vendoring** (Q5).
- **Live collections, preview/draft, `forge_local` CPT** — owned by 135.
- **A visual screenshot pipeline for `export-system`** (poster/preview images).
  Useful later; needs a browser in the export path, which the current design
  deliberately avoids.

---

## 12. Implementation record (2026-11-09)

**Shipped.** Files are named in the companion plan §3; the gate results are
678 unit tests (49 files) and 31 e2e tests, plus a clean `astro check` on a
freshly scaffolded project.

| Area | Landed as |
|---|---|
| Section kinds | `SECTION_KINDS` + slot types in `lib/types.ts` (re-exported from `lib/semantic.ts`, which is vendored into generated projects); six components in `templates/blog/src/components/design/` + a shared `SectionHeading.astro`; six renderer branches; CSS in `global.css` |
| Kind classifier | `structuralSection()` in `lib/semantic.ts` — evidence-gated, ordered comparison → steps → team → pricing → stats → marquee, with the negative cases pinned (`tests/kinds.test.ts`) |
| `data-section` | Emitted on the band wrapper; audited by `sectionKind` in `lib/fact-audit.ts` (declared-vs-rendered match + closed-vocabulary check) |
| Structured data | `offerCatalogNode` / `personNodes` / `bandNodes` in the template's `seo.ts`; `jsonLdGraph` gained `extraNodes`; the homepage passes its classified bands |
| Visible-text gate | `auditStructuredData()` + `--structured` on `verify --seo` (`lib/seo-audit.ts`, `scripts/forge.mjs`) |
| Agent surfaces | `lib/llms.ts` (composer + HTML→markdown projection); `/llms.txt` and `<path>/index.md` routes; `rel="describedby"` / `rel="alternate"` in `Base.astro`; `MOTION.md` via `renderMotionDoc()`; `presets.json` via `serializePresets()` |
| Export core | `scopeCss()` (attribute stripping, depth-0 comma splitting, per-at-rule recursion) + `composeSystemReadme()` in `lib/system-export.ts` |

**Deviations from the plan, and why.**

1. **The marquee's pause control is a native checkbox, not a
   `<button aria-pressed>`.** A button needs a script; the checkbox works with
   zero JavaScript, is keyboard-reachable (Space), and its label text swaps with
   state so it never lies. WCAG 2.2.2 asks for a *mechanism*, not a button, and
   the no-JS floor is worth more here.
2. **`FAQPage` needed no removal.** `templates/blog/src/lib/seo.ts` never
   emitted it — the proposal's premise held for the *absence* of band-level
   data, not for a dead type. The dated comment now lives beside the band
   builders so nobody adds it back. `HowTo` was likewise never emitted.
3. **The structured gate is band-scoped for prices and page-scoped for other
   facts.** A price must appear in a `data-section="pricing"` band (a price in
   unrelated body copy is not the offer shown); dates and other numbers are
   checked against the whole page.
4. **`.md` variants cover pages and posts, not products.** `getCollection(
   'products')` on a pruned storefront breaks `astro check` (the 096 gotcha), so
   products are deliberately left out of v1 rather than gated with a cast.

**Velocity pass (2026-11-09).** The storefront got the subset that applies to it — 387 unit tests pass (42 files), `astro check` 0 errors / 0 warnings:

| Area | Landed as |
|---|---|
| Agent surfaces | `src/lib/llms.ts` (composer only — velocity content is Portable Text, so no HTML projection), `/llms.txt` route with fail-soft collection loads, `rel="describedby"` in `BaseLayout.astro` |
| Block kinds | `comparison` / `steps` / `team` added end to end: zod schema + allowlist + component + map entry (`comparison` uses a real `<table>` with `th[scope]` inside a focusable scroll region; `steps` an `<ol>`; `team` figures) |
| `data-section` | On the three new block roots plus `hero` / `image` / `testimonial` / `stat`, and (follow-up pass) `faq` / `tabs` / `productGrid` / `promotion` — all eleven block types now emit a kind |
| Carousel | Keyboard-pannable now (`tabindex="0"` + focus ring). It is user-scrolled, so WCAG 2.2.2 needs no pause control — the expected failure was not there |

Known divergences, both deliberate: velocity's `llms.txt` links the markdown variants of **products, pages and posts** only (categories/brands/collections have no `.md` route, so they stay in `## Optional` as HTML), and its `stat` Portable Text type emits `data-section="stats"` so the KIND vocabulary still matches the forge while the authoring type keeps its name. No `pricing` block (Woo owns prices) and no `marquee` (Carousel covers it).

**Markdown projection + e2e (same session).** `markdownFromPortableText()` covers every allowlisted block type (GFM tables, ordered steps, nested tabs, and an explicit `_[unrendered block: …]_` marker rather than a silent drop); legacy `contentRendered` HTML degrades through `textFromHtml()` (paragraph breaks kept, markup stripped). Three `.md` routes serve products, pages and posts, and the three pages advertise `rel="alternate" type="text/markdown"`. Velocity's Playwright suite passes **79/79** against a freshly built `dist/` — note that `scripts/serve.mjs` serves a prebuilt tree, so an e2e run without `npm run build` silently exercises the previous build.

**`data-section` completion + block gallery (follow-up, 2026-11-09).** The four remaining block types are tagged: `faq`, `tabs`, `productGrid` → `product-grid`, `promotion`. The blocker recorded above turned out not to exist — every `ui/` primitive (`UiAccordion`, `UiTabs`, `UiTabsList`, `UiTabsPanel`, `UiTabsTab`, `UiAccordionItem`) already ends with `{...rest}` on its root, so each was a one-line edit on the block, not a primitive change. Three additions make the coverage durable:

- **`src/lib/content/section-kinds.ts`** is the vocabulary of record (`SECTION_KINDS`, `isSectionKind`, `BLOCK_TYPE_TO_KIND`). It is storefront-local: the forge has no `faq` / `tabs` / `product-grid` / `promotion` kinds because it does not generate those bands.
- **`tests/unit/section-kinds.test.ts`** is the drift gate: every `src/components/blocks/*.astro` must declare exactly one `data-section` from the vocabulary, and every kind must be used. `Blocks.astro` (the Portable Text dispatcher) and `LinkMark` (an inline mark) are excluded by name. The regex anchors on `data-section="` so `data-section-id="` can never be counted as a kind.
- **`/components/blocks/`** is a fixture gallery of every non-live block, linked from `/components/`. It is a separate route on purpose: `/components/` is the Q6 component-library *adoption* record and cites Proposal 107 §10, so content fixtures there would corrupt an evaluation. `productGrid` and `promotion` are documented by mapping rather than rendered, because they call the commerce client and omit the band when the upstream does not resolve.

Validation: 417 unit tests (44 files), `astro check` 0 errors / 0 warnings (226 files), Playwright 82/82 against a fresh `dist/`, smoke 19/19.

**CSP + ClientRouter regression (same follow-up).** The gallery surfaced a latent, site-wide defect in Proposal 104 D12. The CSP hashes the inline scripts of the **rendered document**, but `ClientRouter` keeps the source document's policy across a client-side navigation, so a target page's inline script is blocked unless the source already allowed that exact hash. Home's CSP carried home's script (`0O7iB6…`); `/components/*` needed the tabs script (`kEpC0…`) — hence the console spam and a dead tabs pattern until reload. Fix: `vite.build.assetsInlineLimit: 0`, which keeps bundled scripts external (`'self'` covers them) and makes the hash list page-invariant — 6 inline hashes → 4, identical on `/`, `/components/`, `/components/blocks/` and `/cart/`. `tests/e2e/component-library.spec.ts` gained an in-app navigation spec asserting zero CSP violations, because every other e2e does a full `goto()` and never exercises the router.

**CSP page-invariance, part 2 (2026-11-09).** `assetsInlineLimit: 0` fixed the bundled scripts, but island directives still emit inline bootstraps per page: `client:visible` → IntersectionObserver, `client:idle` → `requestIdleCallback`. Only `client:load` adds nothing. All four lazy islands (`HomeStats`, `StackExplorer`, `GalleryLightbox`, `PromoCodeField`) moved to `client:load`, and `AddToCartButton` too — the first-party inline set is now **identical on every route** (four hashes; verified on eight routes, incl. a PDP). `tests/unit/csp-inline-invariance.test.ts` pins both rules (`assetsInlineLimit: 0`; no `client:visible`/`client:idle` in `src/`), and the e2e now round-trips home → gallery → home asserting zero CSP violations in both directions.

**Cloudflare's injected script cannot be allowlisted.** The live host's HTML carries Cloudflare's bot-detection injection (`window.__CF$cv$params={r:'<per-request ray>',…}` → `/cdn-cgi/challenge-platform/scripts/jsd/main.js`). The `ray` id makes its hash unique per response, so no hash list can ever contain it: 2 blocks per navigation remain, 3 into a lazy-island page. This is Bot Fight Mode / JS detections, which is already non-functional while blocked; the resolution is to disable that feature for the host (Security → Bots). Relaxing `script-src` with `'unsafe-inline'` is the only code-side alternative and 104 D12 forbids it.

Also fixed: the gallery's `image` demo pointed at `/favicon.svg`, which is **404 on the deployed host** even though it exists in `dist/client`. The demo now carries its own `data:` URI (`img-src 'self' data:` is allowed).

**Orphaned prerendered HTML (root cause of the nine-day-stale PDP).** After the CSP work, `/products/1/aurora-3-seater-sofa/` still rendered `client="visible"` islands and a chunk (`GalleryLightbox.0OTO-7vP.js`) no current build emits — while home, PLP, cart and checkout all matched the local build. It survived a service restart **and** a redeploy, and a cache-buster changed nothing (`cf-cache-status: DYNAMIC`, `Cache-Control: max-age=0`), so it was never caching. Velocity deploys the mirror **incrementally and never deletes files that leave the repo**; a prerendered `dist/client/products/…/index.html` from the Proposal 092 era (31 Oct) had been shadowing the SSR route for nine days — serving build-time content *and* build-time inline scripts, which is why the CSP violations persisted on that route alone. Fix: `scripts/lib/purge-prerendered.mjs` deletes every `*.html` under `dist/client` at boot (fully-SSR builds emit none), and `sync-dist.mjs` refuses to assemble a mirror tree containing any, so the condition cannot ship again. Tested by `tests/unit/purge-prerendered.test.ts` plus a seeded-tree boot check.

**Not shipped (deliberately deferred).**

- `forge export-system` CLI subcommand and the `_system/specimen.astro` route.
  The pure core is shipped and tested; the end-to-end command needs a built
  specimen to derive from, and adding an unbuilt route would have shipped an
  unverifiable surface. The e2e is the right gate for it — it belongs with the
  next scaffold run.
- The `system_export` / `sections_list` MCP tools (they wrap the deferred CLI).
- Skill + `AGENTS.md` inventory notes.

## 13. References
- Repository: `examples/nvoos-astro-forge` (forge), `templates/blog` (template),
  `plugins/nvoos-forge-bridge` (untouched here)
- Prior art in-repo: 096 §2.4 (sanitisation), 098/119 (rebuild-not-replicate),
  104 (agent stubs, presets), 107 (structured-block render pipeline — the
  authored-content sibling of `DesignRenderer`), 114 (`lib/fact-audit.ts`,
  `lib/scaffold.ts` markers), 116 (learned vocabulary), 117 (structured items,
  section theming), 120/122/123/125 (band facts, chrome, banded scales),
  121 (scroll behaviour, zero JS), 126 (DTCG, axe e2e, drift gate),
  130/131/132 (capture arc).
- External: [llms.txt v2](https://llmstxt.org/) (2026-08-10) ·
  [DTCG 2025.10](https://www.designtokens.org/TR/2025.10/format/) (preview draft)
  · Google Search Central *AI features and your website* (2025-12-10) and the
  documentation changelog entries for 2026-05-08 / 2026-06-15 (FAQ deprecation,
  FAQ docs removal, llms.txt clarification) · WCAG 2.2 SC 2.2.2 *Pause, Stop,
  Hide* (updated 2026-10-04) · [instalanding.ai](https://github.com/RayVelez27/instalanding.ai) (MIT).
