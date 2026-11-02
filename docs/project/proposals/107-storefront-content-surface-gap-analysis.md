# 107 — Storefront Content Surface Gap Analysis

**Status:** Research complete — owner decisions requested (marked **DECISION**)
**Date:** 2026-11-03
**Scope:** The NV oOS Astro Storefront (`examples/nvoos-astro-velocity`) and its
assistant-control surface (`/mcp`)
**Related:** 092 (storefront), 095 (ADS + nvoos-astro-mcp), 098 (design import),
104 (runtime hardening, route cache + `/internal/revalidate/`), 105 (theme
policy), 106 (CMS content via WordPress), 096 (Astro Forge generator)

---

## 1. Executive summary

The storefront's `/mcp` surface today can operate the **catalog** (products,
categories, brands, colors, promotions, promocodes, media) and the **design
system** (tokens, themes, contrast, design-language import), but it **cannot
create or edit the site's pages and editorial content** — that is repo work
(the homepage in this session) or WordPress wp-admin work (`content/pages`,
`content/posts` via Proposal 106). The same gap applies to charts and
dashboards, which exist nowhere in the app.

Industry research (2026) confirms the storefront already follows the dominant
render-pipeline standard (on-demand SSR + tag-based cache invalidation +
stale-while-revalidate — the Astro route-cache/ISR pattern). The real gap is
the **agent content workflow**: every major headless CMS now ships an MCP
server, and the consistent industry rule is that **agent output lands as
draft/pending content behind a human approval state the agent cannot flip,
with per-actor audit trails** — plus structured content ("content as data")
rather than free-form page HTML, and sanitization of anything that does render
as HTML.

This document: (a) records the research, (b) lists every gap found, (c) makes
the two requested decisions — the **content/page tool slice** for `/mcp` and
the **render pipeline** for agent-authored content — and (d) proposes a
phased roadmap.

## 2. Current state (grounded in code)

- **`/mcp` — 25 tools**, three slices:
  - Design (14): `tokens_*`, `theme_*`, `contrast_check`,
    `design_analyze/compare/plan/theme`.
  - CMS (6): `cms_list/get/create/update/delete`, `media_upload` — collection
    allowlist: `products, categories, brands, colors, media, promotions,
    promocodes`. **No `content/pages`, no `content/posts`, no globals.**
  - Ops (5): `site_status`, `preview_url`, `rebuild_trigger`, `screenshot`,
    `nds_export` (screenshot/rebuild opt-in and disabled on prod).
- **Content envelope (106):** `content/pages` + `content/posts` are served
  read-only from WordPress by the bridge's `PostTypeController`. The doc shape
  (`src/lib/commerce/types.ts` `ContentDoc`) is flat: `id, slug, title,
  excerpt, content` (raw WP), `contentRendered` (`the_content` output),
  `type: 'page'|'post'`, `featuredImage`, `seoTitle/seoDescription`,
  `category`, `createdAt/updatedAt`, `status`. **No blocks, no sections, no
  version history, no locale, no preview token.**
- **Routes (106):** `/pages/{id}/{slug}`, `/blog/`, `/blog/{id}/{slug}` with
  JSON-LD, canonicals and `['content', …]` cache tags. Hybrid local layer:
  `src/content/pages|posts/*.md` (L2 fallback, envelope wins on slug
  collision). Drafts are excluded from listings; a synthetic draft (id 9902)
  pins the guard.
- **Render pipeline (104):** on-demand SSR for commerce pages, Astro route
  cache with `Astro.cache` + tags, `POST /internal/revalidate/` (bearer
  `NVOOS_REVALIDATE_TOKEN`) for tag-based purge, `Cache-Control:
  public, s-maxage=300, stale-while-revalidate=60` on the contract and pages.
- **Structural pages** (home, about, terms, 404) are **Astro source files**
  shipped through the repo → mirror workflow. This is deliberate (092) and
  unchanged by this analysis.
- **The bridge's PHP side owns writes** to WordPress content (`manage_astro`
  cap); the app never writes WordPress data directly — the app binds only to
  the Payload envelope.

## 3. Industry research summary (2026 sources)

### 3.1 Every major CMS now exposes MCP — content models decide usefulness

- Sanity's MCP (hosted `mcp.sanity.io`) lets an agent "query content, create
  documents, patch fields, manage releases, and run semantic search"; Sanity's
  2026 position is **"structured content as data"** with the least predefined
  structure ([Monterail](https://www.monterail.com/blog/which-cms-to-choose),
  [Enterprise CMS Guide](https://www.enterprisecms.org/guides/top-5-cms-mcp-servers-in-2026)).
- Contentful's MCP (hosted `mcp.contentful.com`, 40+ tools) favors
  predictability/governance over flexibility
  ([ChatForest](https://chatforest.com/guides/best-cms-mcp-servers/)).
- The consensus: "MCP access alone does not demonstrate safe publication —
  agent capabilities and approval controls vary"
  ([Weaverse](https://weaverse.io/blogs/shopify-headless-cms)). An
  **agent-ready CMS** = "clean content models, predictable APIs, permissions,
  staging environments, workflow states, audit logs, and integration points"
  ([Vanitech](https://www.vanitech.com.au/blog/agent-ready-cms-mcp-content-workflows/)).

### 3.2 Structured content beats page-builder HTML

- "Headless content is structured as repeating blocks of content data, or
  'components' — hero banner, testimonial, blog entry, product attributes, SEO
  metadata" ([Hygraph](https://hygraph.com/blog/headless-website-builder)).
- "Model content based on meaning rather than presentation — 'Product Feature',
  not 'Homepage Block 3'" ([Webstacks](https://www.webstacks.com/blog/content-modeling-headless-cms-b2b-best-practices)).
- "Use a page-builder model only when you truly need layout flexibility"
  ([Afteractive](https://www.afteractive.com/blog/best-practices-for-structuring-content-in-a-headless-cms));
  a block/component model is the pragmatic middle (Storyblok's route).
- Gartner 2026: 67 % of enterprise retailers run headless/decoupled; only
  ~19 % full composable — **hybrid headless is the mainstream**, which matches
  this app's WordPress-content + Astro-frontend split
  ([Wise Hustlers](https://wise-hustlers.com/blog/headless-commerce-architecture-2026-guide)).

### 3.3 Render pipeline: the app already matches the standard

- ISR = stale-while-revalidate with time- or event-based regeneration;
  tag-based invalidation marks cached responses stale and revalidates in the
  background ([Vercel](https://vercel.com/docs/incremental-static-regeneration),
  [Astro route caching](https://docs.astro.build/en/guides/caching/),
  [Netlify Astro e-commerce guide](https://developers.netlify.com/guides/modern-ecommerce-caching-astro-tags/)).
- The app's 104 stack (on-demand SSR + `Astro.cache` tags + `/internal/revalidate/`)
  is exactly this pattern. The open question is only **what an agent-authored
  page renders from** (see DECISION-2).
- Astro-based agent-ready CMS examples exist (EmDash: structured content,
  typed APIs, MCP, "build me a theme" workflows)
  ([Joost](https://joost.blog/emdash-cms/)) — validating the direction, not
  mandating a copy.

### 3.4 AI content governance: draft-first, approval states, per-actor audit

- "Grant an AI service account permission to draft but never to publish;
  audit logs record the full chain — AI drafted 14:02, editor edited 14:40,
  legal approved 16:15, released 17:00"
  ([Enterprise CMS Guide](https://www.enterprisecms.org/guides/how-to-govern-ai-content-inside-an-enterprise-cms)).
- "The permission model has to live where the agent cannot argue with it…
  everything it creates lands as pending, and the approval is a state on the
  record that the submitting role cannot flip… every agent action is written
  under its own actor id"
  ([r/webdev](https://www.reddit.com/r/webdev/comments/1w4rhaz/cms_vendors_want_ai_agents_publishing_content_are/)).
- "It can is not it should — agent-originated changes land in a reviewable
  state; draft or pending, never direct-to-published, where a bad page has
  commercial consequence"
  ([C&EO](https://candeocreative.com/tools-platforms/agentic-ai-cms-agency-workflows/)).
- "Review has to be a stage in the workflow, not a habit"
  ([LLMCMS](https://www.llmcms.org/guides/the-coming-ai-audit-what-editors-will-need-to-prove));
  "RBAC + spend budgets; outputs write to proposed fields; review enforced
  before publish; all changes recorded as auditable events"
  ([Headless CMS Guide](https://headlesscms.guide/guides/guardrails-for-ai-generated-content)).
- WordPress VIP's AI CMS guidance: audit logs of AI activity for compliance
  verification ([wpvip](https://wpvip.com/blog/ai-cms/)).

### 3.5 Content security: treat model output as untrusted

- "Treat model output as untrusted text; render with textContent or a Markdown
  library that drops raw HTML, sanitize with an allowlist, add CSP"
  ([vibe-eval](https://vibe-eval.com/fix/ai-response-xss/),
  [OpenReplay](https://blog.openreplay.com/preventing-xss-user-generated-content),
  [nhimg](https://nhimg.org/faq/what-breaks-when-ai-generated-markdown-is-rendered-without-sanitization)).
- Server-side bulk sanitization: `sanitize-html` (already an app dependency)
  with an explicit tag/attribute allowlist; strip `script`, inline `on*`
  handlers, `data:` URIs by default
  ([PkgPulse](https://www.pkgpulse.com/guides/sanitize-html-vs-dompurify-vs-xss-xss-prevention-2026)).
- Adjust the sanitizer's allowlist rather than bypassing it
  ([HeyClaude](https://heyclau.de/entry/rules/ai-generated-xss-review-rules)).

### 3.6 Tool-surface scaling: Code Mode

- Cloudflare's Code Mode ships a whole API as ~two tools (`search()` +
  `execute()`) in ~1,000 tokens, so surfaces don't bloat as endpoints grow
  ([Cloudflare blog](https://blog.cloudflare.com/code-mode-mcp/)). Relevant if
  the content slice grows beyond a handful of tools later.

## 4. Gap analysis

Severity: **P0** blocks the stated goal · **P1** required for safe operation ·
**P2** completes the experience.

| # | Gap | Sev | Industry reference | Recommendation |
|---|---|---|---|---|
| G1 | **No content/page tool slice in `/mcp`** — agents cannot create or edit pages, posts, or globals; pages are repo work | **P0** | Sanity/Contentful MCP surfaces (§3.1) | Add a `content_*` slice per DECISION-1 |
| G2 | **No render-pipeline decision for agent-authored content** — repo `.astro` is not MCP-writable, WP HTML is untyped | **P0** | §3.2, §3.3 | Adopt the structured-content renderer per DECISION-2 |
| G3 | **Flat `ContentDoc`, no block/component model** — `contentRendered` is free-form `the_content` HTML; agents can't compose semantically, reuse, or target sections | P1 | §3.2 (Hygraph, Webstacks) | Add an optional `blocks[]` component model to the envelope; keep `contentRendered` for legacy WP content |
| G4 | **No approval workflow for agent-originated content** — the contract has `status: draft|publish` but no `pending` state and no submitter/approver distinction | **P0** | §3.4 (draft-first, approval as unflippable state, per-actor audit) | `pending` status + `submittedBy`/`approvedBy` actor fields; `/mcp` writes land as `draft`/`pending` unless the session is a human manager explicitly publishing |
| G5 | **No preview/visual-editing loop** — `preview_url` renders only a path; no preview tokens for unpublished revisions | P1 | Sanity Presentation/Visual Editing (§3.1), preview URLs are the standard review surface | Preview route `/preview/{type}/{id}?token=…` (token-gated, reads `status=any`), linked from tool responses |
| G6 | **No version history / rollback for content edits** | P1 | Contentful/Sanity versioning + releases (§3.1, §3.4) | Revision log (WP revisions already exist upstream; expose `revisions[]` + a `content_restore` tool) — rollback is the natural undo for agent edits |
| G7 | **SEO metadata not MCP-manageable** — `seoTitle/seoDescription` are read-only through the envelope | P1 | §3.2 (SEO metadata as first-class content) | Accept `seoTitle/seoDescription` (+ Rank Math → Yoast mapping already in 106) on the content slice |
| G8 | **No scheduled publishing / releases** | P2 | Sanity "manage releases" (§3.1); WP already has `post_date` scheduling | Expose `publishAt` on the slice; bridge maps to WP scheduled posts |
| G9 | **No charts/dashboards anywhere in the app** — analytics/chart tooling is a chat-surface feature, not a storefront one | P2 | n/a (no direct standard; see repo chart fences) | Out of scope for the storefront v1; revisit when the storefront needs merchandising dashboards. If wanted, prefer a read-only `analytics_*` slice over embedding chart-rendering into content |
| G10 | **No localization/locales** — single-language content model | P2 | Contentful/Sanity multi-locale (§3.1) | Defer; note the block model must not preclude locale fields later |
| G11 | **Media pipeline lacks content-side metadata tooling** — `media_upload` exists, but alt text/crops for editorial images aren't in the content tools | P2 | a11y + SEO standards | Add `alt` to media uploads and `featuredImage` alt passthrough |
| G12 | **No sanitization layer for AI-authored HTML** — any future HTML-typed content must be sanitized before render | **P0** (gates DECISION-2) | §3.5 | `sanitize-html` (in deps) with an explicit allowlist server-side; strict CSP already shipped (D12) |
| G13 | **No per-actor audit trail on the app side** — `/mcp` audits tool calls, but content writes need actor identity ("agent proposed, person approved") | P1 | §3.4 (Reddit, Enterprise CMS Guide) | `actor` (assistant vs manager) on every content write; surfaced in the envelope's `submittedBy`/`approvedBy` |
| G14 | **Rate-limit/spend control for content generation is implicit** — content tools will be the highest-volume agent path | P2 | §3.4 (spend budgets); existing `/mcp` rate limiter covers call volume | Extend the existing per-IP limiter with a per-session write quota; honor `dry_run`/`validate` modes |

## 5. DECISION-1 — the content/page tool slice for `/mcp`

**Decision:** add a `content_*` slice (read tools open, writes manager-gated
exactly like `cms_*` — fail-closed, bearer `NVOOS_MCP_TOKEN` or manager
session; **manager cookie required for contract writes**, matching the
existing split).

Proposed tool set (first cut — 8 tools, mirroring the `cms_*` conventions):

| Tool | Notes |
|---|---|
| `content_list` | `collection: pages\|posts`, `status` filter (`publish` default, `any` manager-only), `where[slug\|category]`, paginated envelope |
| `content_get` | single doc by id or slug (slug for the preview flow) |
| `content_create` | `{ type, title, excerpt, content (Markdown or plain), blocks[], seoTitle, seoDescription, status: draft\|pending\|publish, publishAt?, categoryId? }` — **defaults to `draft`; `publish` requires an explicit manager-session flag** |
| `content_update` | PATCH fields; status transitions enforced by the bridge (`pending→publish` only for manager actors; agents cannot flip their own `pending`) |
| `content_delete` | destructive — manager only, soft-delete first (trash) |
| `content_preview_url` | returns a token-gated `/preview/{type}/{id}` URL (30–60 min TTL token) |
| `content_restore` | revert to a revision (WP revisions upstream) |
| `content_validate` | dry-run schema + sanitizer + WCAG contrast/a11y lint pass on the payload before write |

Explicit non-goals for v1: repo-page generation (no code-writing tool —
deploys stay the mirror workflow), globals writes (banner/carousel/theme
policy stay bridge-admin or follow later), chart/dashboard generation (G9).

## 6. DECISION-2 — the render pipeline for agent-authored content

**Decision:** keep **on-demand SSR + tag-based cache invalidation** (the
existing 104 stack — it is the industry standard, §3.3), and render
agent-authored content from a **structured block model** (G3), not from raw
HTML and not from generated `.astro` files.

Concretely:

1. **Content model:** extend `ContentDoc` with an optional `blocks[]` —
   an allowlisted component set (hero, prose/rich-text, image, product-grid,
   promotion, testimonial, FAQ, chart-lite/stat). Each block = typed JSON
   (`{ _type, …fields }`), validated by a zod schema at the bridge and in the
   app's envelope parser (D3 loud-failure discipline).
2. **Rendering:** a `BlocksRenderer` in the app maps block types to Astro
   components. Rich-text fields are Markdown rendered by an allowlisted
   renderer with `sanitize-html` (G12). No raw `contentRendered` HTML from
   agents ever reaches a template without the sanitizer; legacy WP-authored
   `contentRendered` continues to render as today (trusted upstream content).
3. **Pages vs posts:** `content/pages` docs get an optional `route` (slug) but
   page **creation** targets only new editorial pages; structural pages
   (home/about/terms/404) stay repo-owned. Agent "new page" requests produce
   `content/pages` docs (reviewable, reversible) — not files, not deploys.
4. **Cache & revalidation:** new/updated docs purge `['content', …]` tags via
   the existing `/internal/revalidate/` (bridge already fires the rebuild
   webhook on content change); previews bypass the cache (token + `status=any`).
5. **Why not alternatives:** code-generation pipelines (agent writes `.astro`
   → PR → deploy) give maximum flexibility but break the fail-closed review
   model, need CI + merge latency, and blur the audit trail; full page-builder
   free-form HTML violates the structured-content standard (§3.2) and
   multiplies the sanitization surface. The block model is the industry
   middle that still lets an agent build a real landing page in one call
   (`content_create` with `blocks[]`).

## 7. Proposed roadmap

- **Phase 1 (P0 — the slice + safety):** G1 (content tools), G4 (draft/pending
  + actor states), G12 (sanitizer + blocks validation), G13 (audit trail).
  Gate: MCP conformance + e2e "agent drafts → manager publishes → cache
  purges" loop, mirroring the existing rebrand-loop test pattern.
- **Phase 2 (P1 — the editor experience):** G3 (blocks renderer), G5 (preview
  URLs), G6 (revisions/restore), G7 (SEO fields). Gate: axe + Lighthouse on a
  preview page; crawlability spec on a published page.
- **Phase 3 (P2 — polish):** G8 (scheduling), G11 (media alt), G14 (quotas).
- **Deferred/owner-gated:** G9 (charts/dashboards — separate scope), G10
  (locales), repo-page generation (explicit non-goal), the Code Mode tool
  pattern (§3.6) if the surface exceeds ~35 tools.

## 8. Open questions for the owner

1. Should `content_create` ever allow direct `publish` for a manager session,
   or is everything `pending`-by-construction with publication as a separate
   call? (Recommended: separate call — matches §3.4's "state the submitter
   cannot flip".)
2. Does the WordPress backend's `manage_astro` cap suffice as the human
   approval authority, or do we want a dedicated `content_approve` role?
3. Block set v1: which of (hero, prose, image, product-grid, promotion,
   testimonial, FAQ, stat/chart-lite) ship first? (Recommended: hero, prose,
   image, product-grid, promotion.)
4. Preview tokens: app-side HMAC token (stateless) vs bridge-issued token
   (centralized revocation)?
5. Should the content slice ride the existing `cms_*` prefix instead of a new
   `content_*` prefix? (Recommended: new prefix — different auth/actor model.)

## Sources

- Monterail — Best Headless CMS 2026: https://www.monterail.com/blog/which-cms-to-choose
- Enterprise CMS Guide — Top 5 CMS MCP servers 2026: https://www.enterprisecms.org/guides/top-5-cms-mcp-servers-in-2026
- ChatForest — CMS MCP servers: https://chatforest.com/guides/best-cms-mcp-servers/ · https://chatforest.com/reviews/cms-content-management-mcp-servers/
- Weaverse — Shopify headless CMS review (MCP/approval caution): https://weaverse.io/blogs/shopify-headless-cms
- Vanitech — Agent-Ready CMS: https://www.vanitech.com.au/blog/agent-ready-cms-mcp-content-workflows/
- Hygraph — Headless CMS vs page builder: https://hygraph.com/blog/headless-website-builder
- Webstacks — Content modeling B2B best practices: https://www.webstacks.com/blog/content-modeling-headless-cms-b2b-best-practices
- Afteractive — Structuring content in a headless CMS: https://www.afteractive.com/blog/best-practices-for-structuring-content-in-a-headless-cms
- Wise Hustlers — Headless commerce architecture 2026 (Gartner 67 % stat): https://wise-hustlers.com/blog/headless-commerce-architecture-2026-guide
- Vercel — ISR: https://vercel.com/docs/incremental-static-regeneration
- Astro Docs — Route caching: https://docs.astro.build/en/guides/caching/
- Netlify — E-commerce caching with Astro cache tags: https://developers.netlify.com/guides/modern-ecommerce-caching-astro-tags/
- Joost — EmDash CMS: https://joost.blog/emdash-cms/
- Enterprise CMS Guide — How to govern AI content: https://www.enterprisecms.org/guides/how-to-govern-ai-content-inside-an-enterprise-cms
- Reddit r/webdev — CMS vendors want AI agents publishing: https://www.reddit.com/r/webdev/comments/1w4rhaz/cms_vendors_want_ai_agents_publishing_content_are/
- Headless CMS Guide — Guardrails for AI-generated content: https://headlesscms.guide/guides/guardrails-for-ai-generated-content
- LLMCMS — The coming AI audit: https://www.llmcms.org/guides/the-coming-ai-audit-what-editors-will-need-to-prove
- C&EO — Agentic AI enters the CMS: https://candeocreative.com/tools-platforms/agentic-ai-cms-agency-workflows/
- WordPress VIP — AI CMS: https://wpvip.com/blog/ai-cms/
- vibe-eval — AI response XSS: https://vibe-eval.com/fix/ai-response-xss/
- OpenReplay — Preventing XSS in UGC: https://blog.openreplay.com/preventing-xss-user-generated-content
- PkgPulse — sanitize-html vs DOMPurify: https://www.pkgpulse.com/guides/sanitize-html-vs-dompurify-vs-xss-xss-prevention-2026
- Cloudflare — Code Mode MCP: https://blog.cloudflare.com/code-mode-mcp/
