# Proposal 136 — section vocabulary + agent surfaces (2026-11-09)

Full operational notes for the `## Proposal 136` summary in `SKILL.md`.
Shipped: six section kinds, `data-section`, band-level JSON-LD, the
`--structured` SEO gate, and the deterministic agent surfaces.

## Kinds

`lib/semantic.ts` → `structuralSection()`. The vocabulary is `pricing`,
`stats`, `steps`, `team`, `marquee`, `comparison` (`SECTION_KINDS` in
`lib/types.ts`, guarded by `isSectionKind()`).

Every kind requires **structural evidence**:

- a band that merely *mentions* a price stays `feature-grid`
- a two-column table is **not** a `comparison`
- a row of three numbers with no labels is **not** `stats`

Detection order is fixed: `comparison → steps → team → pricing → stats →
marquee`. No evidence → the old shape ladder runs unchanged. That ladder
is the form-less byte-identity pin, so a regression there shows up as a
fixture diff, not a test failure — check the byte pin first.

A class token is never evidence. Do not add a class-name shortcut.

## `data-section` vs `data-section-id`

- `data-section-id` is **identity** and stays the only key for
  `builtBandsOf`, `sectionHtml`, `measureGeometry`, `scopedPaddingOf`.
- `data-section` is the **kind**, and it is advisory. Nothing may depend on
  it for correctness.
- `lib/fact-audit.ts` audits the kind: every built band must carry an
  in-vocabulary value, and a band declaring `kind` must render that kind.
- **Fixtures or builds without the attribute now fail the gate.** That is
  intended. A "new" failure on an old fixture is the gate working.

## Why shared interfaces live in `semantic.ts`, not `types.ts`

`semantic.ts` is vendored into generated projects as
`src/forge/semantic.ts` and must stay dependency-free.

`types.ts` does `import type {…}` at the top plus a local `export type {…}`.

A bare re-export — `export type { X } from './semantic.ts'` — does **not**
bind `X` locally, so any later use of `X` in `types.ts` fails with ts2304.
`astro check` catches it; a unit run does not.

## Band JSON-LD

`templates/blog/src/lib/seo.ts`:

| Builder | Contract |
|---|---|
| `offerCatalogNode` | returns `undefined` if **any** plan lacks a visible price — never emit a partial catalogue |
| `personNodes` | one node per team member with a rendered name |
| `bandNodes` | band-scoped nodes for pricing / stats / steps |
| `jsonLdGraph(…, extraNodes)` | order is site → bands → page |

The homepage classifies **once** via `src/lib/classify.ts` and feeds both
the rendered markup and the structured data, so the two cannot diverge.
Classifying twice is the bug this exists to prevent.

## The `--structured` gate

`verify <profile> --seo --structured` fails when:

- a JSON-LD price is absent from a `data-section="pricing"` band, or
- a number or date is absent from the page text.

Prices are **band-scoped**; every other fact is **page-scoped**. That
asymmetry is deliberate — a plan's price belongs to its plan band, while a
"12,000 customers" claim is a page-level assertion.

## Agent surfaces

| Surface | Module | Route |
|---|---|---|
| `llms.txt` | `lib/llms.ts` → `composeLlmsTxt` | `/llms.txt` |
| HTML→markdown | `lib/llms.ts` → `markdownFromHtml` | — |
| Page markdown | — | `/<slug>/index.md`, `/posts/.../index.md` |
| Discovery links | `Base.astro` | `rel="describedby"`, `rel="alternate" type="text/markdown"` |
| Motion doc | `renderMotionDoc` | `MOTION.md` |
| Presets | `serializePresets` | `presets.json` — drift-pinned |

Products are excluded from the `.md` variants: `getCollection('products')`
on a pruned storefront breaks `astro check`.

## `system-export.ts`

Ships the **core** only: `scopeCss`, `composeSystemReadme`. The
`export-system` CLI and the `_system/specimen.astro` route are deferred,
not broken.

## Never add `FAQPage` or `HowTo`

Google removed the FAQ rich result on **2026-05-07** (docs deleted
2026-06-15) and HowTo in **2023**. The template never emitted either. The
visible bands (`<details>` for FAQ, `<ol>` for steps) stay exactly as they
are — they are still the correct markup for humans and for every
non-Google consumer.

The dated comment beside the band builders exists to stop a future session
"fixing" this by re-adding dead schema.

## See also

- Storefront counterpart: `mcp-ai-wpoos-astro-storefront` → `## Proposal 136`
- Dependabot / post-merge lint triage: `mcp-ai-wpoos-git-ops` →
  `## Post-merge CI triage`
