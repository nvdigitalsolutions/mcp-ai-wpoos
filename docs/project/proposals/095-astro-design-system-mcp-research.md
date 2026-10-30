# Proposal 095 — Research Notes: Astro Design System + MCP Server (Verified 2026-10-30)

Sources verified live on 2026-10-30 (fetched, not recalled). Companion to `095-astro-design-system-mcp-proposal.md` §2.

---

## 1. Astro's official AI story — and what it does NOT cover

### 1.1 The Astro Docs MCP server (official)

- Astro ships an official MCP server: **"Astro Docs"**, endpoint `https://mcp.docs.astro.build/mcp`, transport **Streamable HTTP**, free, open-source, runs remotely ("nothing to install locally").
- It is backed by the **kapa.ai API** maintaining an up-to-date index of the Astro documentation. Its tools answer documentation questions (`search_astro_docs` is the tool name shown in the GitHub Copilot config example in the official guide).
- Official install recipes exist for Claude Code (CLI + GitHub Action), Codex CLI, Cursor, VS Code Copilot, Warp, Claude.ai/Desktop, Windsurf (via `mcp-remote` local proxy because it lacks streaming HTTP), Gemini CLI, Google Antigravity, **Zed** (`context_servers` entry with `url`), ChatGPT, Raycast, Opencode, GitHub Copilot Coding Agent.
- Source: <https://docs.astro.build/en/guides/build-with-ai/>

**Conclusion:** Proposal 092 §2.5's claim ("Astro ships an official MCP server … agents can scaffold, edit, and audit Astro projects") is **half right**. The official server supplies *documentation knowledge* to coding agents; it cannot read, edit, theme, or operate any Astro site. The 092 §3.9 "Astro MCP server — agent-driven storefront edits" node therefore remains unbuilt — that is the gap Proposal 095 fills. The official server is complementary: coding agents should use *both* (Astro Docs MCP for framework knowledge + our `nvoos-astro-mcp` for storefront control).

### 1.2 Astro 7 agent ergonomics (first-party features)

- **Background mode** (`astro@7.0.0`): when an AI coding agent is detected (macOS/Linux), `astro dev` and (since 7.2.0) `astro preview` start as detached background processes; lock files `.astro/dev.json` / `.astro/preview.json` record URL, port, PID; opt-out via `ASTRO_DEV_BACKGROUND` / `ASTRO_PREVIEW_BACKGROUND`.
- **Health endpoint** `/_astro/status` returns `{"ok": true}` — dev server only, absent in production builds. Purpose: agents can programmatically wait for the server.
- Official AI tips: start from templates, use `astro add` for integrations, verify current APIs (sessions/actions/content collections changed recently), use project rules files.
- Source: same guide.

**Conclusion:** the Astro team is actively building for agentic workflows — our design (agent-visible status tool, hermetic dev loop, project rules) is aligned with the framework's own direction.

### 1.3 Astro docs inventory (from the live sitemap)

- There is **no "design tokens" recipe** in the current Astro docs (confirmed against `docs.astro.build/sitemap-0.xml`). The relevant first-party surface is:
  - `en/guides/styling/` — styling guide (custom properties, scoped styles, global CSS)
  - `en/guides/content-collections/` + `en/reference/content-loader-reference/` — content layer
  - `en/reference/integrations-reference/` — integration authoring API (relevant if the MCP mount ever becomes an Astro integration instead of a BFF route)
  - `en/guides/actions/` — type-safe server functions (the CMS mutation primitive)
  - `en/guides/cms/` — ~40 CMS guides incl. decap-cms, keystatic, pages-cms, payload, wordpress, tina-cms, studiocms
- The Astro-team's own design-token practice is observable in **Starlight** (their docs theme, Starlight v0.42.7 live on the docs site): `--sl-*` token families, `data-theme` attribute switching (`light`/`dark`/`auto`), `color-scheme` awareness, a no-FOUC inline script that sets the theme before first paint, and `prefers-color-scheme` fallback. This is the exact pattern Proposal 095 §3.2 adopts for the storefront.

## 2. MCP protocol & SDK state (2026-10)

### 2.1 Transports (spec 2026-07-28, current)

- Two standard transports: **stdio** (client launches server as subprocess, newline-delimited JSON-RPC, stderr for logs only) and **Streamable HTTP** (replaces HTTP+SSE from 2024-11-05).
- Streamable HTTP rules that shape the implementation:
  - Single MCP endpoint supporting POST and GET (e.g. `https://example.com/mcp`).
  - Every client message = new HTTP POST; client MUST send `Accept: application/json, text/event-stream`; response is either JSON or an SSE stream.
  - Notifications/responses → server returns **202 Accepted** with no body.
  - Session management via `Mcp-Session-Id` header (set at initialization; 404 terminates; DELETE ends a session).
  - `MCP-Protocol-Version` header on every request after initialization.
  - **Security MUSTs:** validate `Origin` against DNS rebinding; bind local servers to 127.0.0.1; authenticate all connections.
- Source: <https://modelcontextprotocol.io/specification/2026-07-28/basic/transports>

### 2.2 TypeScript SDK

- **SDK v2 is the stable line** (released 2026-07-27, implements the 2026-07-28 spec): packages `@modelcontextprotocol/server` and `@modelcontextprotocol/client`; runs on Node/Bun/Deno; tool/prompt schemas use **Standard Schema** (Zod v4, Valibot, ArkType…); includes **auth helpers** and **middleware packages** — notably `@modelcontextprotocol/node`, a Streamable-HTTP wrapper for plain Node `IncomingMessage`/`ServerResponse` (drop-in for the storefront BFF's `createServer`), plus express/fastify/hono wrappers with Host-header validation.
- v1.x (`@modelcontextprotocol/sdk`) remains on a long-lived branch with bug/security fixes ≥6 months after v2's release.
- License: Apache-2.0 (new code) / MIT (existing).
- Source: <https://github.com/modelcontextprotocol/typescript-sdk>

**Alignment note:** the NV oOS MCP Apps client speaks `PROTOCOL_VERSION = '2026-07-28'` (see the `design-elementor-mcp-connection` skill), i.e. **SDK v2 is the version-matched server stack** for NV oOS assistants, and `@modelcontextprotocol/node` mounts cleanly into `examples/nvoos-astro-velocity/scripts/serve.mjs` (plain Node `http` server that already routes before the Astro handler).

## 3. Precedent survey — assistant-controlled sites & Astro MCP projects

### 3.1 Elementor MCP (the established "assistant controls a site" pattern)

- `msrbuilds/elementor-mcp` — 759★, GPL-2.0, active (pushed 2026-10-29): "WordPress MCP server with 234 Free tools and 547 total with Pro for building pages, managing content, and operating your site." Per-tool WordPress capability checks; application-password auth; availability depends on installed integrations.
- This is the pattern the NV oOS MCP Apps subsystem already connects to (Remote Sites `mcp_server` type → assistant `connection_ref` → bridged `mcp_app_*` tools). Proposal 095's Astro server mirrors its shape: server on the site it controls, least-privilege credentials, per-tool gating.
- Complementary: `emersimeon/claude-elementor-kit` (85★) — "Build WordPress sites with Claude Code via the Elementor MCP — kit with skill, setup wizard, installers": the industry is packaging **skills + MCP servers together** for site-building agents. (Repo-side parallel: the `mcp-ai-wpoos-astro-storefront` skill gaining an MCP section in Phase 4.)
- Source: <https://github.com/msrbuilds/elementor-mcp>, <https://github.com/emersimeon/claude-elementor-kit>

### 3.2 Astro-specific MCP projects

- `vapvarun/wp-astro-mcp` (12★, TypeScript, MIT, 57 tools, 113 tests, pushed 2026-09-13): **"Headless WordPress MCP server — adds an Astro frontend to any WordPress site."** Full README reviewed 2026-10-30. What it actually is: a dev-time **stdio** server for Claude Code that (a) registers WordPress sites (application passwords), (b) **extracts** posts/pages/CPTs/taxonomies/media/menus/SEO/ACF, (c) runs a **13-step HTML→Markdown pipeline** (DOMPurify, shortcode resolution, Gutenberg cleanup, link/media rewriting), (d) **scaffolds a new Astro 6 project** (content collections, Pagefind, RSS, JSON-LD, sitemap, SSR draft preview), (e) tracks export state in **SQLite** (resumable jobs, per-post state), (f) keeps the content layer **in sync** (modified_gmt change detection, incremental pull, deletion handling, webhook/GitHub-Actions scheduling), and (g) publishes to **GitHub** + generates Vercel/Netlify/Cloudflare deploy configs. An optional `wp-astro-bridge` WordPress plugin adds HMAC-signed publish webhooks, single-use draft-preview tokens, and a normalized SEO REST field.
- `morinokami/astro-mcp` (61★, MIT, 13 open issues, last pushed 2026-05): "MCP server to support Astro project development" — dev-time helper, not a site-control server.
- `midudev/mcp-higgsfield-landing` (191★): an Astro + GSAP + Lenis landing site **built with Higgsfield MCP + Claude Code** — real-world proof of the MCP-driven Astro build loop that 092 §2.5 anticipated.
- `starwind-ui/starwind-ui` (759★, MIT): "55 framework-portable UI components for Astro, React, Vue, Svelte … backed by a shared framework-neutral Runtime" — evidence that Astro component libraries are trending toward framework-portable primitives; supports Proposal 095's decision that our Astro components + React islands share tokens via CSS variables rather than duplicated styles. (Notably, wp-astro-mcp's scaffolder uses starwind as one of its component-library options.)
- Sources: <https://github.com/vapvarun/wp-astro-mcp>, <https://github.com/morinokami/astro-mcp>, <https://github.com/midudev/mcp-higgsfield-landing>, <https://github.com/starwind-ui/starwind-ui>

### 3.3 Gap statement

No upstream project provides: (a) DTCG-sourced token/theme control, (b) CMS operations over a commerce contract, and (c) an MCP server mounted on a live Astro storefront's BFF — with assistant-facing auth and rollback. The combination is NV-original, built entirely from already-shipped repo assets (contract, MCP Apps, NDS DTCG pipeline) plus one SDK dependency.

### 3.4 Scope boundary vs `wp-astro-mcp` (deliberate non-adoption)

`wp-astro-mcp` is the closest existing project, so its feature list is the natural question ("is the plan to have features like this?"). Answer: **no — 095 is the inverse lifecycle stage**, and the overlaps are already covered by shipped repo assets.

| wp-astro-mcp feature | 095 position | Why |
|---|---|---|
| Scaffold a **new** Astro project from WordPress | **Out of scope.** The storefront already exists (`nvoos-astro-velocity`) | Generator work belongs to 092 Phase 4 (`nvoos-astro-starter`) if ever wanted — a different proposal |
| 13-step HTML→Markdown content layer | **Out of scope, deliberately.** | 092 §2.2 research: structured REST with caching is the production-proven pattern; converting to Markdown files would fork the content model and lose the contract's type safety. 095's `cms_*` tools read/write the contract, not files |
| SQLite export state machine / resumable jobs / sync engine | **Out of scope.** | No file content layer → no export state. Freshness is the contract itself (SSR pockets + cache headers); rebuilds ride the shipped bridge webhook (`nvoos-storefront-content-changed` → mirror workflow) |
| GitHub repo creation/push tools + deploy configs | **Out of scope — already shipped differently.** | `sync-nvoos-astro-velocity.yml` already builds, tests, and publishes the public mirror (zero-licensed-bytes tree); no assistant-held GitHub PAT is wanted |
| Multi-site management (12 sites, per-site credentials) | **Out of scope.** | 095 themes = multi-*tenant* theming on one deploy. Multi-*site* storefront operations is a different product axis (fleet management); brand themes are its future hook, not its implementation |
| `wp-astro-bridge` plugin (HMAC webhooks, draft preview, SEO field) | **Out of scope — equivalents shipped.** | `addons/funiq-bridge/` already fires the rebuild webhook and serves the SEO-shaped envelope; `/manage` is the admin/preview surface |
| Application-password auth | **Adopted in spirit.** | Same least-privilege + revocable-credential discipline, mapped onto our contract manager auth + `NVOOS_MCP_TOKEN` bearer (see proposal §3.3) |
| **Craft patterns adopted** | — | Tool annotations (read-only/destructive/open-world), single-source-of-truth tool registry (Zod schemas drive both validation and discovery, coverage test fails CI on schema-less tools), `structuredContent` alongside text results, router-vs-full tool-list modes for context budgets, per-site token-bucket rate limiting — all folded into the 095 implementation plan (§7/§8 checklists) |

One wp-astro-mcp idea worth stealing later, not now: its `setup_wizard` meta-tool (one command from zero to working). The 095 equivalent would be an NV oOS-side "connect assistant to this storefront" wizard (Remote Sites entry + allowlist + MCP App `connection_ref`), which today is a documented recipe rather than a tool.

## 4. Design-token industry standards

- **DTCG 2025.10** (W3C Design Tokens Community Group, published 2025-10-28) is the repo's token format already (Proposal 058 §3.3; exporter `class-nvoos-nds-dtcg-exporter.php` in `addons/nvoos-design-system/`). Reference syntax `{group.token}`, per-type `$value` validation, `$extensions`, `$description`. Validator ecosystem: `dtcg-validator`, `@styleframe/dtcg`, Style Dictionary 4 (058 §3.3).
- **Style Dictionary** (styledictionary.com): the standard build tool for DTCG → multi-platform output. 058 §3.3 flagged DTCG 2025.10 support still maturing in SD5 — Proposal 095's Phase 0 ships a zero-dependency generator for the single CSS/TS output pair, with SD5 as the documented upgrade path when multi-platform outputs (email templates, spa-v2 token feed) join.
- **Utopia** (utopia.fyi): fluid `clamp()` type/space scales — the 2026 standard for breakpoint-free scales; adopted for the ADS core layer.
- **Astro themes marketplace** (astro.build/themes — the user-provided reference): theme anatomy across the marketplace = folder-level composition (tokens/layouts/components/config), not CSS patches. Maps to Proposal 095's "brand theme = DTCG override file over the semantic layer."
- **WCAG 2.2 contrast** for the `contrast_check` tool and theme gates (repo standard from the `design-color-systems` skill).
- **Transactional import UX** (058 §3.4): validate → dry-run diff → preview → all-or-nothing apply → rollback, carried into every ADS/MCP bulk write.

## 5. Repo-side assets this builds on (verified locations)

| Asset | Location | Role |
|---|---|---|
| BFF server | `examples/nvoos-astro-velocity/scripts/serve.mjs` | `/mcp` mount point (routes `/api/*`, `/media/*`, then Astro handler) |
| Current style layer | `examples/nvoos-astro-velocity/src/styles/global.css` (~300 lines, 8 flat `--nv-*` tokens) | baseline to be tokenized behind legacy aliases |
| Commerce contract client | `examples/nvoos-astro-velocity/src/lib/commerce/client.ts` (base-resolution logic, fixtures) | CMS tools reuse it verbatim |
| Hermetic loop | `scripts/fixture-server.mjs` (`:8123`), vitest, Playwright, LHCI gates | test harness for all phases |
| NDS addon | `addons/nvoos-design-system/` (registry, admin Tokens page, presets, DTCG export via `admin-post.php?action=nvoos_nds_export_dtcg`, legacy aliases, `@property` typed CSS) | import source + Phase 4 round-trip target; UX template for the `/manage` Design tab |
| MCP Apps client | `addons/pro/includes/mcp-apps/` (registry, client, tool bridge, OAuth; `PROTOCOL_VERSION = '2026-07-28'`; Remote Sites `mcp_server` type) | the assistant side — already shipped; the Astro server is the missing half |
| Skills | `.agents/skills/design-elementor-mcp-connection/SKILL.md`, `.agents/skills/mcp-ai-wpoos-astro-storefront/SKILL.md` | connection recipe + storefront ops to be updated in Phase 4 |

## 6. Corrections to prior proposals

- **092 §2.5** ("Astro ships an official MCP server … can scaffold, edit, and audit Astro projects") — verified as the Astro Docs MCP: a documentation-knowledge server only (kapa.ai), no site-read/write capability. Proposal 095 builds the site-control counterpart; the two are complementary (docs MCP for agents writing code, nvoos-astro-mcp for assistants operating the live site).
