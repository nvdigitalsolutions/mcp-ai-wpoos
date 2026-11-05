# Proposal 116 — Learned vocabulary classifier (never enumerate widgets again)

> Owner direction 2026-11-06: the forge should **learn** the Elementor +
> block-editor vocabularies instead of maintaining name lists — widgets
> change, update, are added/removed over time, vendor families (Auxin,
> Essential Addons…) keep appearing, and **Elementor 4.0's atomic editor**
> restructures everything. A hand-curated map is a permanently rotting asset.

## 1. Research (2026-11-06)

- **Elementor 4.0 Atomic** (official blog + developers docs): "Atomic
  structure breaks rigid widgets into smaller, controllable parts"
  (Wpmet); atomic elements are typed JSON objects (element id, **element
  type**, version, settings, children); atomic **forms render as real
  semantic form fields**. Implication: atomic output classifies best by
  *structure*, not by widget names — real `<form>`, real headings/grids.
- **Gutenberg registry**: `GET /wp-json/wp/v2/block-types` (WP 5.5+,
  `WP_Block_Type_Registry::get_all_registered()`) returns every registered
  block type with name/title/keywords/category/styles — the living
  vocabulary, fetchable per-site.
- **Extraction literature** (Bevendorff 2023 empirical comparison; KDD
  meta-analysis "Heuristic approaches are often criticised for their lack
  of generality"): DOM-feature/structural classification generalizes;
  name-list heuristics do not. This matches the forge's own experience:
  the section-kind reason ladder was already shape-based; only the
  widget→role mapping was name-bound.

## 2. Design — three layers, names demoted to refiners

1. **Structure-first base (seeded shape ladder)** — unchanged and still
   authoritative for section *kinds* (hero/grid/cta/… decided by slot
   shape). The widget→role layer now only decides which slots a widget
   contributes.
2. **`content` capture — unknown names are NEVER dropped**: an
   unmapped `elementor-widget-*` / `wp-block-*` name classifies as
   `content` (low confidence): its title/body/media/links still merge into
   the section, and the shape ladder decides the kind. Future widgets
   render correctly on day one, with reduced confidence.
3. **Learned per-site vocabularies + profile overrides**:
   - `forge analyze --vocabulary` fetches the block-types registry
     (bounded pagination) and harvests every `elementor-widget-*` name from
     the rendered homepage; deterministic keyword rules map registry
     metadata → roles (`columns|group|cover|row|stack|grid` → container,
     `heading` → title, `gallery` → gallery, `buttons?|link|cta` → links…).
   - `profile.vocabulary` = learned `blockRoles` + operator-pinned
     `widgetRoles` + the **unknown-name reports** (`unknownWidgets`,
     `unknownBlocks`) — the learn loop: analyze reports, the operator pins,
     no forge release needed.
   - The scaffold bakes `src/lib/vocabulary.generated.ts`; the renderer
     passes it into `classifyHtml` where **overrides win over the seeded
     defaults** (`SEEDED_WIDGET_ROLES` / `SEEDED_BLOCK_ROLES` — now
     exported as the versioned seed dictionaries).
   - **Structured-block gate**: Gutenberg-only documents whose blocks
     include `container`/`content` roles now enter the section pipeline
     (columns/group re-seeded from `body` → `container` — changes no prior
     output because Gutenberg-only docs were gated to text before); plain
     prose posts keep the raw post-body path. A new safety ladder pins
     **the pipeline never empties a non-empty document** (embed-only posts
     fall back to raw text; empty Elementor scaffolding still drops).

## 3. What this means for jayainc + atomic

- jayainc's Auxin vocabulary: `aux_modern_heading` etc. are already
  seeded; every *other* vendor widget on future runs now classifies by
  content + shape and is *reported* for pinning — no more blind spots.
- Elementor 4.0 atomic pages: real semantic HTML → the form extraction +
  shape ladder handle them without any name matching; the atomic JSON data
  layer (element types) is the documented enrichment path if the rendered
  output ever proves ambiguous.

## 4. Validation

- Unit: unknown-widget capture (low confidence), override precedence,
  structured-block gate, embed-only safety, empty-scaffolding drop pinned,
  registry keyword rules, learn/report flow, scaffold baking — **334/334**.
- `tsc --noEmit` clean; e2e **27/27** (the pre-116 rendering contracts
  are pinned byte-for-byte — pure prose, XSS traps, embeds, forms).
