# 107 — Implementation Plan: Storefront Content Surface (Content Slice + Render Pipeline)

**Date:** 2026-11-03
**Companion to:** `107-storefront-content-surface-gap-analysis.md` (research + decisions + gap matrix — this plan implements its recommendations)
**Scope:** the Astro storefront (`examples/nvoos-astro-velocity`), the bridge addon (`addons/nvoos-astro-bridge`), and the storefront `/mcp` surface. **No Pro-toolkit code changes** — reuse is pattern-level only (gap analysis §8).
**Status:** **IMPLEMENTED** on `feat/storefront-content-slice` (PR against `alpha-working`, the code companion to #7405) — the 8-tool content slice, the Portable Text render pipeline, the preview-token exchange, the bridge approval ladder + revisions/restore + media `alt`, and the fixture/test parity. Deviations recorded while building: (1) `publishAt` with an explicit `publish` status resolves to `future` (WP core flips any future-dated post — never publish early); (2) the raw-HTML `content` field is sanitized at BOTH gates (bridge `wp_kses_post` on write, `sanitize-html` on render); (3) blocks are stored natively as serialized arrays (not a JSON string); (4) content detail routes cache only RENDERED responses — a 404 never enters the route cache (preview-poisoning fix); (5) the MCP bundle gains a `createRequire` banner (CJS deps under ESM import).

Each phase carries its own exit gate. P1 is the only P0 phase; P2 and P3 are independent and may land as separate PR clusters after P1.

---

## 0. Locked decisions (from the gap analysis)

- **DECISION-1:** an 8-tool `content_*` slice on `/mcp` — `content_list`, `content_get`, `content_create`, `content_update`, `content_delete`, `content_preview_url`, `content_restore`, `content_validate`. Reads open; **writes require the manager session** (fail-closed, same split as `cms_*`). No code-writing tools; structural pages stay repo-owned.
- **DECISION-2:** keep the 104 render stack (on-demand SSR + tag-based route cache + purge) and render agent-authored content from a **structured block model** — concretely **Portable Text** (see D1) — with an allowlisted HTML sanitizer for any raw-HTML fallback. Never generated `.astro` files.
- **Approval model:** WP-native statuses — `draft` / `pending` / `publish` / `future` — with `publish_posts` (manager session) as the **unflippable publish authority** on the bridge. Agent-originated writes land `draft` or `pending`; they cannot self-publish (research: the approval state must live where the agent cannot argue with it).
- **Open owner questions** (gap analysis §9) proceed with the recommended defaults so implementation is not blocked; each is flagged inline.

## 1. Dependency decisions (D-series)

Every adoption was verified against the live ecosystem 2026-11-03. Rule: adopt maintained, single-purpose packages that remove real risk; hand-roll where the zero-dependency property is the feature.

| # | Piece (phase) | Decision | Package | Rationale |
|---|---|---|---|---|
| D1 | Block content format (P2) | **Adopt** | **Portable Text** — the open spec (`portabletext.org`); serializer `@portabletext/astro` (official, Astro-native: singular `type`/`mark` component map) | Open standard, JSON-native, and *explicitly agent-friendly* — "the semantic intent of each block is explicit, not implied by markup conventions" (Sanity). Custom block types carry the commerce components (hero, productGrid, promotion…). No bespoke block schema to maintain |
| D2 | Raw-HTML sanitization (P1) | **Adopt** | `sanitize-html` — **already an app dependency** | Explicit tag/attribute allowlist server-side; strip `script`, inline `on*`, `data:` URIs. Industry default for server-side bulk sanitizing (PkgPulse comparison) |
| D3 | Preview tokens (P2) | **Keep hand-rolled** | — (`node:crypto` HMAC, ~30 lines) | The Next.js Draft Mode pattern (signed token → enable httpOnly cookie → redirect; token exchanged once, never persisted in URLs) needs no package; hand-rolling keeps the zero-dep BFF property and the token format testable |
| D4 | Editorial workflow (P1) | **Adopt WP core** | — (no plugin; PublishPress noted and rejected) | WP's built-in `draft`/`pending`/`publish`/`future` + native revisions **already implement** the researched governance model; mapping the envelope onto core statuses avoids a workflow plugin on the backend |
| D5 | Rich-text in `content` field (P1) | **Keep plain + Markdown** | (optional `marked` later) | P1 ships `content` as plain text/Markdown rendered through the sanitizer; P2's Portable Text `blocks[]` replaces rich needs. Avoids a Markdown dependency decision before the block model lands |
| D6 | Revisions/rollback (P2) | **Adopt WP core** | — (`wp/v2/posts/{id}/revisions`, `wp_restore_post_revision`) | Native revisions are the audit-grade rollback; the envelope exposes them read-only + restore |

## 2. Phase 1 (P0) — Content write surface with governance

Goal: an agent can draft, a manager can publish, nothing else. All writes flow through the bridge envelope; the app never touches WordPress directly.

### 2.1 Bridge — `ContentController` write endpoints

New routes on the existing `nvoos-commerce/v1` namespace (`addons/nvoos-astro-bridge/includes/REST/`):

| Method | Route | Auth | Behaviour |
|---|---|---|---|
| `POST` | `/content/pages`, `/content/posts` | `manage_astro` | Create. Body: `title, excerpt, content, seoTitle, seoDescription, categoryId?, publishAt?, status?` — status may only be `draft` (default) or `pending` |
| `PATCH` | `/content/{type}/{id}` | `manage_astro` | Field updates + status transitions (table below). `status: 'publish'` additionally requires `publish_posts` — the unflippable gate; assistant sessions fail with 403 and a fixed code `content_publish_forbidden` |
| `DELETE` | `/content/{type}/{id}` | `manage_astro` | Trash first (WP trash), permanent delete on repeat |
| `GET` | `/content/{type}/{id}?status=any` | `manage_astro` (else publish-only) | Manager preview read incl. drafts/pending |
| `GET` | `/content/{type}/{id}/revisions` | `manage_astro` | Revision list (id, author, date) |
| `POST` | `/content/{type}/{id}/restore` | `manage_astro` | `wp_restore_post_revision` to the given revision id |

**Status transition table (bridge-enforced):**

| From → To | Actor | Result |
|---|---|---|
| (create) → `draft`/`pending` | any manager session (incl. assistant acting for one) | ok |
| `draft` → `pending` → `draft` | any manager session | ok |
| `draft`/`pending` → `publish` | **`publish_posts` only** | ok; `approvedBy` recorded |
| `publish` → `draft` (unpublish) | `publish_posts` only | ok |
| any → `future` (`publishAt` set) | `publish_posts` only | scheduled; WP flips to `publish` at the time |
| `publish` via assistant actor without `publish_posts` | — | **403, no state change** (open question 1 default: publish is a separate transition, never a create flag) |

**Actor audit fields:** `submittedBy` (assistant slug or `manager:<uid>`) set on create by the bridge from the session; `approvedBy` set on the publish transition. Both ride the envelope `$extensions`-style additive fields so older clients ignore them. Every write goes through the existing audit logger (same line the `/mcp` audit uses).

### 2.2 Envelope — `ContentDoc` extensions (app side)

`src/lib/commerce/types.ts`:

```ts
export interface ContentDoc {
	// …existing 106 fields…
	blocks?: PortableTextBlock[]; // P2-populated; P1 validates-and-passes-through
	submittedBy?: string;
	approvedBy?: string;
	publishAt?: string;
}
```

zod schemas (D3 discipline — loud failure) in `src/lib/content/schema.ts`: `contentCreateSchema`, `contentUpdateSchema` (status enum, publishAt ISO 8601, title length caps), `portableTextSchema` (P2).

### 2.3 App `/mcp` — the `content_*` slice

`mcp/tools/content.ts` (+ registration in `mcp/tools/*` index, `SERVER_VERSION` bump to 0.3.0):

- All 8 tools carry `title` + annotations from `mcp/tools/util.ts` — `READ_ONLY_TOOL` for list/get, `WRITE_TOOL` for create/update, `DESTRUCTIVE_WRITE_TOOL` for delete, `IDEMPOTENT_WRITE_TOOL` for restore/validate.
- Write auth: manager session **required** (mirror `cms_*`); reads open.
- **In-process cache purge:** after a successful create/update/delete/publish, the tool purges the doc's route-cache tags (`content:{type}:{id}`, `content:pages`, `content:posts`, `blog`) via the existing route-cache purger — the MCP handler lives in the BFF process (same as the 104 revalidate endpoint), no HTTP self-call, no rebuild webhook needed for content (the webhook stays for code deploys).
- `content_preview_url` returns `${origin}/preview/{type}/{id}?token=<hmac>` (P2 wires the route; P1 can ship the tool returning 501-with-message until P2, or the tool ships in P2 — implementation choice: **ship the tool in P1 returning a documented `preview_unavailable` error**, unlock in P2).
- `content_validate` is a dry-run: schema parse + sanitizer pass + title/desc length + link-safety lint; returns the normalized payload it *would* write.

### 2.4 Fixture server parity

`scripts/fixture-server.mjs` gains the same write endpoints over an in-memory `contentPages/contentPosts` array + status transition enforcement + a synthetic manager seam (`nvoos_fixture_manager=1` cookie already exists). Goldens stay byte-identical (additive only).

### 2.5 Phase 1 gate (all must pass)

- Bridge: `php -l` + phpcs (addon ruleset) + PHPUnit in Docker (`oos-wp`, isolated DB) — new `addons/nvoos-astro-bridge/tests/phpunit/REST/TestContentWriteSurface.php`: create-draft, pending flow, **assistant-cannot-publish 403**, publish-records-approver, trash, revisions, sanitizer rejection (XSS payload), scheduled-future.
- App: vitest for the zod schemas + tool argument validation + auth matrix (read open / write 401 without manager cookie / delete destructive annotation) — extend `tests/unit/mcp-tools.test.ts` style coverage; MCP conformance additions in `tests/mcp/handshake.test.ts` (new tools appear in `tools/list` with correct annotations).
- e2e (`tests/e2e/content-write.spec.ts`, fixture seam): manager session creates draft → `/blog/` does NOT show it → preview-any read shows it → manager publishes → blog shows it and the cache purged within the TTL window → delete returns it to absent. axe pass on the published page.
- Hermetic build + `npm run lint` + `npm run check` clean.

## 3. Phase 2 (P1) — Blocks renderer, preview, revisions, SEO

### 3.1 Portable Text adoption (D1)

- `src/lib/content/portable-text.ts`: schema + type exports (`PortableTextBlock` union: standard `block` + custom types).
- **Custom block types (the commerce components):** `hero`, `image`, `productGrid` (`{ ids: number[] }` — renders via the existing product client + `ProductGrid`), `promotion` (`{ promotionId }`), `testimonial`, `faq`, `stat`. Naming mirrors the site-creator toolkit's section taxonomy (gap analysis §8) — hero/feature/testimonial/CTA/gallery.
- `src/components/blocks/` — one Astro component per custom type + the `@portabletext/astro` serializer composition in `src/components/Blocks.astro` (singular `type`/`mark` map; marks: link, strong, em; unknown-type fallback renders a visible warning, matching the serializer default).
- `src/pages/pages/[id]/[slug].astro` and blog templates render `blocks[]` when present, else `contentRendered` through the sanitizer (D2) — legacy WP content keeps rendering exactly as today.
- **Security:** Portable Text is data, not HTML — serializer components escape by construction; the only dynamic HTML paths are `sanitizeHtml()`-wrapped. Link marks get URL-scheme validation (reject `javascript:`). No `set:html` on agent-authored content anywhere.

### 3.2 Preview route (D3)

- `src/pages/preview/[type]/[id].astro`: validates `?token=` (HMAC-SHA256 over `type:id:expiry` with `NVOOS_PREVIEW_SECRET`; TTL 30 min; single-use counter not required — the cookie is the session), sets httpOnly `nvoos-preview` cookie, redirects to the canonical route. Preview token minting lives in `content_preview_url`.
- SSR reads the cookie: content fetches add `status=any`, cache tags skip the cache (`Astro.cache` bypass via custom headers), pages render a visible "Preview — unpublished" bar. Token never appears in the URL after the redirect (the researched pattern).
- **Open question 4 default:** app-side HMAC (stateless) — no bridge round-trip, one env secret; centralized revocation can be added later without changing the tool surface.

### 3.3 Revisions + restore

- Bridge `GET …/revisions` + `POST …/restore` (2.1) surface WP revisions; `content_restore` tool wraps them. Restore creates a new revision (WP semantics — history preserved, nothing destructive).

### 3.4 SEO write-through (G7)

- `content_create/update` accept `seoTitle`/`seoDescription`; bridge maps to Rank Math meta when active, Yoast fallback, then plain title/excerpt — reusing the exact 106 read-side mapping in reverse.

### 3.5 Phase 2 gate

- vitest: portable-text schema (valid/invalid blocks, unknown custom types fail loudly), sanitizer matrix (script/onerror/javascript:/style whitelist), preview token HMAC (tamper → 403, expiry → 403).
- e2e: draft with blocks → preview link renders hero+productGrid → publish → canonical route renders blocks, `Product`/`WebPage` JSON-LD intact, axe zero violations (extend `a11y.spec.ts` with the blocks page), crawlability spec on a published blocks page.
- LHCI + smoke unchanged.

## 4. Phase 3 (P2) — Scheduling, media alt, quotas

- **Scheduling (G8):** `publishAt` → WP `future` status (bridge); the content listing gains a `status=future` filter; no app-side cron needed (WP core handles the flip; the bridge's existing content-changed hook purges tags when WP transitions).
- **Media alt (G11):** `media_upload` gains an `alt` field (stored in WP alt text); `featuredImage` on content docs carries alt through; the blocks `image` type requires `alt` or explicit `alt: ''` for decorative images (a11y gate enforced in `content_validate`).
- **Quotas (G14):** extend the existing `/mcp` per-IP limiter with a per-session write window (e.g. 30 content writes/10 min, env-tunable) — protects the LLM loop from runaway generation; 429 with the existing `-32029` code.
- **Tool-surface budget:** no new tools this phase; if the surface later exceeds ~35 tools, evaluate the Code Mode `search/execute` pattern (gap analysis §3.6).

### 4.1 Phase 3 gate

- Bridge PHPUnit: future-status scheduling, publish-flip simulation, alt persistence.
- App e2e: quota 429 path; scheduled post invisible before `publishAt`, visible after (fixture clock seam).

## 5. Cross-cutting rules (apply to every phase)

- **Security checklist** (repo `.context/security-checklist.md`): sanitize at entry, escape at exit, caps before privileged ops, nonces for the bridge's admin-adjacent surfaces, no raw DB rows in responses, rate limits on write tools, audit line per write.
- **Contract rules (CI-enforced):** the app binds only to the envelope; "funiq" stays banned from `src/`; goldens stay semantically identical (fixture extensions are additive).
- **Repo conventions:** tools return the canonical envelope; new MCP tools are annotated + pinned by `tests/mcp/handshake.test.ts`; new app modules get vitest coverage; bridge PHP gets phpcs + PHPUnit on WP 6.9 **and** 7.1 matrices.
- **Docs:** `addons/nvoos-astro-bridge/README.md` (new routes), app `README.md` (blocks + preview env), skill `mcp-ai-wpoos-astro-storefront` (content slice section replacing the "cannot build pages" note), `docs/reference/` tool listing if the surface is mirrored there.

## 6. Rollback & risk register

| Risk | Likelihood | Mitigation |
|---|---|---|
| WP status semantics drift between 6.9/7.1 matrices | Low | Transition table pinned by bridge PHPUnit on both |
| Assistant publishes despite gates (regression) | Low | The 403 path is the first PHPUnit + e2e assertion; cap check lives in the bridge callback (server-side, not tool-side) |
| Portable Text serializer unknowns render wrong | Med | Unknown-type fallback is visible-by-design; `content_validate` rejects unknown types before write |
| Preview token leaks into logs | Low | Token only in the redirect query once; audit-log masking; TTL 30 min |
| Cache purge misses a tag (stale page) | Med | Tag matrix below is asserted by the P1 e2e purge test; `/internal/revalidate/` remains the manual escape hatch |
| Mirror deploy drift (public/ additions) | Low | `sync-dist.mjs` already ships every runtime import — no new deploy surface beyond source |

**Rollback:** P1 is additive (new routes/tools) — revert = remove the route registration + tool registration commits; no data migration. P2 blocks are additive on `ContentDoc` — old clients ignore `blocks[]`; rendering falls back to `contentRendered`. No schema migrations anywhere in this plan.

## 7. Cache-tag matrix (content writes)

| Change | Tags purged (in-process, tool-side) |
|---|---|
| page/post create/update/delete/publish | `content:pages` / `content:posts`, `content:{type}:{id}`, `blog` (posts), `pages:{slug}` |
| publish transition | same as update + `sitemap` |
| revision restore | `content:{type}:{id}` |
| media upload | `media:{id}`, and any content referencing it |

## 8. Effort sketch (for planning)

| Phase | Bridge | App/MCP | Tests | Rough size |
|---|---|---|---|---|
| P1 | ~600 LOC | ~450 LOC | ~15 new tests | 1–2 sessions |
| P2 | ~250 LOC | ~700 LOC | ~20 new tests | 2 sessions |
| P3 | ~150 LOC | ~200 LOC | ~8 new tests | 1 session |

## 9. Open decisions carried forward (with working defaults)

1. Publish as separate transition (default: **yes** — `content_update` status change by a manager, never a create flag).
2. Approval authority = existing `manage_astro` + `publish_posts` (default: **yes**; a dedicated `content_approve` role only if a non-manager editor role is ever needed).
3. Block set v1 ships: **hero, image, productGrid, promotion, testimonial, faq, stat** (default per gap analysis §8; trimmable).
4. Preview tokens: app-side HMAC (default; see 3.2).
5. Tool prefix: new `content_*` (default; avoids `cms_*` auth-model confusion).

---

**Sources (incremental to the gap analysis):** Portable Text spec + rendering (`portabletext.org/specification`, `portabletext.org/rendering/astro`, Sanity "Why structured text is awesome" — agent-friendly rationale, Netlify "Sanity Portable Text with Astro") · Next.js Draft Mode + headless preview-token patterns (`nextjs.org/docs/app/guides/draft-mode`, `headlesscms.guide`, `headless-cms-integration.com/preview-draft-workflow-patterns`) · WordPress post statuses + revisions (`wordpress.org/documentation/article/post-status/`, PublishPress pending-review docs — rejected as a dependency, core covers the model).
