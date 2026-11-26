# Template variants — the contract

A variant is an **additive overlay** on a built-in template. `templates/blog`
stays the single source of truth; a variant adds only the files its capability
needs, so a base-template fix reaches every variant for free.

## The six rules a variant must clear

A variant is **not** a preset and **not** a kind. If a proposed variant fails any
of these, it belongs somewhere else — and the "elsewhere" is named in the last
column.

| # | Rule | If it fails |
| --- | --- | --- |
| 1 | It is an **overlay** (files), not an archetype (collections + surfaces + shape). | It is a **preset** (`lib/presets.ts`). |
| 2 | It adds **no runtime dependency** beyond a vendored, self-hosted asset — or none at all. | Reject: the forge owns every origin the page loads from. |
| 3 | It fits **≥2 presets**. | It belongs **inside** that one preset. |
| 4 | It has a **pure, testable core** (a `src/lib/*.ts` resolver with no DOM and no network). | Reject: an untestable variant rots silently. |
| 5 | It **degrades to the base template** when its asset is absent. | Reject: a variant must never turn a missing file into a broken page. |
| 6 | It does **not duplicate** a component the template already ships. | Extend the shipped component instead. |

## The matrix

Every pair below is a claim someone has to keep true. Keep this table and
`PRESETS[*].variants` in sync — `presets.test.ts` pins every declared id to
`TEMPLATE_VARIANTS`, and `scaffold()` refuses an incompatible pair before any
write.

| Variant | `blog` | `storefront` | `portfolio` | `landing` | `docs` | `minimal` |
| --- | --- | --- | --- | --- | --- | --- |
| `product-3d` | — | ✅ | — | — | — | — |
| `video-hero` | — | ✅ | ✅ | ✅ | — | — |

`product-3d` is commerce-specific (it renders a *product's* model), so it fits
exactly one preset. `video-hero` is a marketing surface, so it fits the three
presets whose job is to persuade.

## Screened and rejected

These come up often and are already covered — adding them would be duplication
(rule 6) or a second implementation of one idea:

| Candidate | Where it already lives |
| --- | --- |
| lightbox / accordion / carousel | `BuilderLightbox`, `BuilderAccordion`, `BuilderCarousel` in the base template |
| pricing toggle | a behaviour of the `pricing` **kind** |
| docs sidebar / TOC | preset chrome — see "Deferred" below (rule 3) |
| command palette | `Pagefind` search surface |
| product 360° spin | a **prop** of `product-3d`, not a variant |
| i18n | a project-wide setting, not an overlay |
| case-studies lane | the `stats` + `testimonial` kinds |

## Deferred — and why

- **`docs` chrome (sidebar + in-page TOC).** It fits one preset, so rule 3 sends
  it into the template layer. The right home is a `docs` template concern driven
  by the page hierarchy, not a file overlay duplicated per site. Until then the
  `docs` preset pins `shape: 'multi-page'` and keeps the search surface.
- **One-pager route pruning.** `landing` sets `shape: 'one-pager'`, and the
  template already renders anchor nav for it. The `posts/` routes are
  deliberately **kept**: `sitemap.xml.ts` enumerates the collections, so pruning
  the routes without pruning the posts *collection* would ship dead sitemap
  URLs. Nav shape is the part that matters; the routes are inert deep links.

## Adding a variant

1. Create `templates/variants/<id>/` with a `variant.json` manifest
   (`id`, `version`, `description`, `adds[]`, `requires{}`).
2. Put the logic in `src/lib/<name>.ts` — pure, exported, unit-tested against
   the *shipped* file (import it from `templates/variants/…` in the test).
3. Ship the component in `src/components/`. No `client:` directive, no framework
   runtime, no CDN.
4. Add the id to `TEMPLATE_VARIANTS` (`lib/scaffold.ts`) and to the presets that
   can carry it (`PRESETS[*].variants`).
5. Regenerate `presets.json` (`serializePresets()`) — a test pins it byte-for-byte.
6. Write the `VARIANT.md` and add a row to the matrix above.
