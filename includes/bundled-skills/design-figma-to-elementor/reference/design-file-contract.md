# Design-File Contract (design-for-the-pipeline)

Conventions that make a Figma file pipeline-ready. The **Scope** stage of the
Figma → Elementor pipeline checks the file against this contract and reports
violations; a badly non-conforming file gets a remediation report instead of
a build. This contract is owned by the pipeline (proposal 059); the Figma
design-system work (proposal 057), when built, adopts and extends it.

## Frame naming (maps to WP pages)

- Each page of the site is one **top-level frame**, named after the target
  WordPress page, e.g. `Home`, `About`, `Contact`, `Pricing`.
- Multi-viewport variants use a suffix: `Home — Desktop`, `Home — Mobile`.
  The pipeline builds from the Desktop frame and applies responsive notes
  from the Mobile frame.
- **No `Frame 47`.** Unnamed/auto-named frames are a Scope violation; the
  agent must ask, not guess.

## Section structure (maps to Elementor sections)

- Inside each page frame, the direct children are **sections** in render
  order: `01 Hero`, `02 Features`, `03 Testimonials`, `04 CTA`.
- Sections are self-contained groups (frame or auto-layout group), never
  loose layers stacked at page level.
- Column intent is expressed by structure: three side-by-side groups inside
  a section = three-column Elementor section.

## Tokens (maps to globals, never raw values)

- Every color, font family/size, and spacing value **must come from
  variables** (Figma variables, published from a collection). Detached or
  raw values are reported at Scope and fail Tokenize.
- Variable naming should be semantic where possible
  (`color/primary/500`, `color/surface/primary`, `font/heading`); the
  pipeline maps variable names to Elementor globals and `--nds-*` tokens.
- Text styles on every text layer; at minimum one heading + one body style.

## Auto-layout & spacing

- Sections and cards use **auto-layout** with explicit gaps — the pipeline
  reads gaps/padding directly as Elementor spacing values. Absolute
  positioning inside sections is a violation (it has no Elementor
  equivalent).

## Assets

- Images are real image fills or exportable components, not screenshots
  pasted into frames. Meaningful images carry a name describing their use
  (`hero-product`); the pipeline uses these as media filenames and alt-text
  hints.

## What the pipeline tolerates

- Minor deviations (one raw value, one unnamed sub-frame) → reported, the
  agent asks the human how to resolve.
- Structural violations (no sections, page-level loose layers, everything
  absolute) → remediation report; no build until fixed.

## Why this matters

Structured, well-named, tokenized files are the single biggest predictor of
agentic design-to-build accuracy (industry: Figma MCP guides, Monday
Engineering's pipeline, seamgen's file-structure guidance). The contract is
short on purpose — it is the minimum that makes `get_design_context` output
reliably mappable to Elementor structure.
