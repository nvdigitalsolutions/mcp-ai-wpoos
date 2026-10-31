# Proposal 098 — Research Notes: Design-Language Extraction & Inspired-By Recreation

Companion to `098-design-language-import-proposal.md` §2. Compiled 2026-10-31 from a 20-search sub-agent sweep (full report in session) plus a supplementary verified search pass. All external sources fetched/verified 2026-10-31.

---

## 1. The legal boundary: what "recreate" means

| Source | Load-bearing line |
|---|---|
| [CopyWeb — Is Website Cloning Legal (2026)](https://copyweb.net/blog/is-website-cloning-legal) | "AI-assisted rewriting on top of capture is what moves a project from 'clone' toward 'inspired by.'" |
| [Koder.ai — Make My Website Like This](https://koder.ai/blog/make-my-website-like-this-clone-style-without-copying-content) | Recreate the look and feel without copying text, images, or layouts — copy the style, not the content. |
| [CJ&CO — How To Copy A Website Design Without Getting Sued](https://www.cjco.com.au/article/how-to-copy-a-website-design/) | Never copy chunks of code or CSS directly; check licensing before using images/video/text; don't copy layouts outright — take inspiration and design your own look. |
| [Law StackExchange — copying a website design](https://law.stackexchange.com/questions/56820/is-it-allowed-to-copy-a-website-design) | Imitating a competitor's look and feel to deceive customers about origin is the genuine risk (unfair competition); inspiration without deception is generally acceptable. |
| [askailawyer — Is It Legal to Copy a Website's Design](https://www.askailawyer.com/legal-general/is-it-legal-to-copy-a-websites-design) | Taking inspiration and creating something new that reflects your own brand is acceptable. |

**Design implication:** the pipeline must be *structurally incapable* of emitting source CSS/HTML/copy/assets — tokens + prose only — and must always ship adaptation guidance. The 098 contract encodes this (proposal §3.2).

## 2. Extraction craft (how good tools do it)

| Source | Load-bearing line |
|---|---|
| [uiscanner — extract design tokens from any website](https://uiscanner.com/blog/extract-design-tokens-from-any-website) | "Describe and tokenize the page, rebuild in your own stack from the tokens and structure, and never re-host the original's images or fonts. Use what you pull as a starting system and make the design your own." — the *transformative posture* every reputable tool takes. |
| [dev.to/hugonaili — extracting design tokens from a site you don't control](https://dev.to/hugonaili/extracting-design-tokens-from-a-site-you-dont-control-16b8) | "A wrong role assignment is worse than a missing one… The unassigned pool is fail-loudly design, and most extraction tools get it exactly backwards by force-assigning everything." — the fail-loud pool rule, adopted verbatim. |
| [Superdesign — extract a design system from a website](https://superdesign.dev/blog/extract-design-system-from-website) | Read rendered styles off the live page (colors, type scale, spacing, radius, shadows, component patterns) and record them as reusable tokens — not screenshots. |
| [ExportToFigma — extract design tokens](https://www.exporttofigma.com/blogs/extract-design-tokens-from-any-website) | Cleanest extractions come from sites with deliberate, consistent systems and semantic CSS custom properties. |
| [Element Armory — design system extraction](https://elementarmory.com/topics/component-reuse-libraries/design-system-extraction/extract-design-tokens-from-ui) | Re-run extraction quarterly or when major design updates ship — extraction is a recurring sync concern → 098's `design_compare`. |
| [Sitegeist — extract design tokens from a website](https://sitegeist.designbuff.co/guides/extract-design-tokens-from-website/) | Extraction only pays off when tokens land in a usable format (CSS variables / JSON tokens for build pipelines) — the DTCG emission target. |

## 3. Existing tooling (build on vs skip)

- **design-extract** (GitHub, MIT, ~4.4k★) — Playwright crawl → DTCG tokens → Tailwind v4/shadcn emitters + MCP mode. Closest OSS implementation of the extract step; candidate for Phase 2 reference/fork.
- **dembrandt** — one-command computed-style token extractor; good heuristics reference (color-confidence weighting, typography clustering).
- **Sitegeist / ExportToFigma / uiscanner / CSS Peeper / CSS Scan** — commercial/browser-side extractors; prove demand, but none emit into a DTCG pipeline we control. **The differentiator is adaptation + first-class consumers (095/096), not extraction.**
- **Mootassam/sitecloner, ajmaluk/site-mirror-skill, kar722/Website-Cloning** — Playwright/wget clone utilities; useful as crawl-orchestration references only; the "clone" posture is exactly what 098 rejects.

## 4. Source-mode research (from the 2026-10-31 sub-agent sweep)

- **Astro themes:** distributed as GitHub templates (`npm create astro --template`); universal conventions (`src/layouts`, `src/pages`, `src/content`, `astro.config.mjs`); styling via Tailwind v4 `@theme` (emits native CSS variables) or plain CSS custom properties — both map 1:1 into DTCG. Licensing skew: popular free themes are MIT (notice-preserving fork OK), some GPL-3.0 (derivative obligations), paid themes proprietary → **clone-don't-scrape + license-verified modes only**.
- **WordPress:** `theme.json` IS a design-token layer (presets emitted as CSS custom properties on frontend + editor); `/wp-json/wp/v2/themes/{stylesheet}` is a public read-only self-description; Simply Static proves static export but yields no design model; WP2Static is stalled; Frontity deprecated (Faust.js is the current WP headless framework, WPGraphQL-based — not needed for read-only capture).
- **Generic sites:** wget/HTTrack mirrors break on JS-heavy sites; sitemap discovery chain (robots.txt → sitemap.xml → in-page links) is the crawl convention — v1 avoids crawling entirely (single document + bounded stylesheet fan-out).

## 5. Repo assets this builds on (verified locations)

| Asset | Role |
|---|---|
| `examples/nvoos-astro-velocity/src/lib/tokens/` + `scripts/lib/tokens-build.mjs` | The DTCG 2025.10 pipeline every import feeds (cross-file `{core.x}` refs, drift-gated generated artifacts) |
| `examples/nvoos-astro-velocity/mcp/` (auth, bff, context, ratelimit) | The fail-closed MCP surface the new tool joins (per-IP rate limit, env snapshot discipline, write-gate) |
| Proposal 058 (`nvoos-design-system-dtcg-token-import-proposal.md`) + `addons/nvoos-design-system` importer | The NDS import precedent for DTCG documents (256 KB cap, all-or-nothing, rejects `{core.x}` refs) |
| Proposal 096 (`astro-forge-wp-generator-mcp-*`) | The consumer: `tokens_apply`/`ads_generate` take 098's output for generated sites |
| `.agents/skills/mcp-ai-wpoos-astro-storefront/SKILL.md` | The `/mcp` surface this proposal extends (tool catalog, annotations, env) |
| `.agents/skills/wp-security-deep/SKILL.md` + OWASP SSRF cheat sheet | The fetcher's security discipline (scheme allowlist, private-IP rejection, DNS-rebinding awareness, redirect-hop re-validation) |

## 6. Corrections / clarifications to prior repo statements

- The astro-storefront skill's "openWorldHint stays false everywhere" becomes conditional: 098's `design_analyze` is the first open-world tool (proposal Q2), and the handshake test's all-false pin gains a per-tool allowlist.
- The skill's tool count (21) becomes 22 with `design_analyze`.
