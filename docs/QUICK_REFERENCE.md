# NV oOS Quick Reference Guide

**Version:** 1.1.68
**Last Updated:** September 3, 2026

This quick reference provides fast access to the most common tasks and commands for Open Operator System.

## Recent Updates (September 2026)

- **v1.1.68** (September 3): Front-end surfaces & stability. **Pro SPA v2 shortcode** `[nvoos_pro_spa]` embeds the Pro chat surface on the front end (chat-first embedded mode: threads, drawers, tool shortcuts, OKF drawer; router-free; optional guest mode behind the guest-token machinery). **Hermes WebUI extensions** land in a new top-level `extensions/` tree — fleet monitoring + control plane (`nv-oos-fleet`, Python plugin API over ~35 sites) plus `backup-download`, `external-app-tab`, `mcp-tool-shortcuts` with installer + smoke tests. **Chat surfaces self-heal stale nonces** — new `GET /mcp-ai/v1/session/nonce` endpoint mints a fresh session-bound nonce (no-cache), ending "Cookie check failed" 403s from full-page caching or session-token rotation. **Docs Hub 0.4.2** — local-page links resolve to in-app hash routes, github-slugger-exact TOC anchors, directory-relative "Accept fix" suggestions, `../` validation + skip reasons, sync failure surfacing, and the emoji loader no longer crashes the React SPA. **Fresh installs disable cloud providers by default** (dropdowns list enabled + credentialed providers; the onboarding wizard auto-enables the provider whose key you enter). Fixes: Google Calendar granted-scopes `%20` corruption, Tiptap `mergeAttributes` prototype pollution (3.30.4 pins), `fast-uri` >=4.1.4 across npm trees, assistant untrash restores the pre-trash status, presets gain missing Google/Gmail/Drive/OKF/git tools, task-template seeding AJAX wired, and a jQuery UI sortable shim for post screens. Third PHPUnit repair wave (~21 PRs). Stale 1.1.67 build ZIPs removed. Tool count unchanged: ~303 base + ~1,262 Pro (~1,565 total). See `docs/project/plans/v1.1.68-docs-catch-up.md`, `docs/project/proposals/033-pro-spa-v2-shortcode-proposal.md`.

- **v1.1.67** (September 2): Ecosystem-extraction release. The **Content Graph AI Platform** addon ships standalone at **v2.0.0** (`plugins/nvoos-content-graph-ai-platform/`) — extraction Waves A–C + Blueprints move the platform's own business logic out of the base plugin (namespace-bridged admin UI, skill/slash-command/agent bridges, platform dashboard + settings registry, harness router, 74-skill bundled-skills pack, knowledge base, dedicated PHPUnit matrix). The additive **Base+Pro → Content Graph ecosystem port** lands Wave D + D-UI in `nvoos-content-graph-ai`: chat runtime core (prompt optimizer, response cache, SSE rate limiter, semantic cache, summarizer, thread manager, attachments, transcript recorder/retention, ChatKit), providers beyond the 13 (Zai, Google Maps, OpenAI Realtime ×3, RabbitMQ, STDIO transport, file services), model management + analytics/token tracking, security guards, and the assistant admin pages (Add/Build/Test) plus chat blocks, Elementor widgets, guest tokens, agent memory, WP-CLI, chat compat route, MCP JSON-RPC controller — Content Graph AI bumps **1.0.3 → 1.0.4**. Pro gains **six Google Workspace read tools** with two new clients: Gmail (`get_gmail_message`, `get_gmail_thread`, `list_gmail_connections`, `modify_gmail_message` — destructive-ops gated) and Drive (`get_drive_file`, `list_drive_connections`). A second PHPUnit repair campaign wave (~100 PRs, #6114–#6208, #6209–#6222) carried grouped production fixes: crawler job-contract hardening (`sanitize_task_id()`, `base_url` URL validation) + `wp_mcp_ai_crawl4ai_auto_spawn_cron` filter, assistant-directory REST guard, null-safe tool-error reporting, LLM sanitization delegated to the validator, `display`-metadata persistence, create-post taxonomy guards, toolkit-registry live-singleton resolution, URL-encoded external-API queries (LinkedIn/Shopify/Google Maps/ReliefWeb/web-search), Veo 5-second floor, token-tier caching guards, credential-resolver cache invalidation, cache-helper option-cache eviction, settings dashboard/registry fixes, tool-multiplier visibility, content-format filter seams, CRM workflow-preset canonical schema, Pro Composer class-ambiguity rename, destructive-ops gate reading the canonical combined-settings array, WhatsApp webhook signature rejection without an app secret, Graphify/Content Graph HTTP 304 cached-body re-serve, Veo async-job completion ordering, token-budget display fixes, Shopify client-availability filter seam, slash-command workflow guards (metrics + parallel/conditional block rendering), and Paper Store array-field query matching (#6209–#6222 merged upstream after the main pass). Test-suite skill refreshed to 26 root-cause patterns (#6154). Stale 1.1.66 build ZIPs removed. Tool count: ~303 base + ~1,262 Pro (~1,565 total). See `docs/project/plans/v1.1.67-docs-catch-up.md`, `docs/project/ecosystem-port-tracker.md`, `docs/project/plans/content-graph-platform-extraction-plan.md`.

- **v1.1.65** (August 28): Hardening & stability. OpenAI reasoning models (o-series/gpt-5) no longer receive `max_tokens`/`temperature` — `OpenAiCompatibleClient` strips unsupported parameters and retries 400 rejections with corrected payloads (`applyModelConstraints()`/`sendWithParameterCorrection()`). Content Graph AI embeddings stop 500ing (deliberate provider resolution — OpenAI preferred when keyed — honored `embeddings_model`, `\Throwable` guards) and graph context falls back to keyword search without an embeddings index (`graph_context_mode`). Media Worker ships the optional full-Crawl4AI proxy — env-gated `POST /api/crawl/full` + `GET /api/crawl/full/task/:id` with SSRF-validated targets and 503/502 envelopes (proposal 031 Phase 3) plus a strict-path `TEMP_ROOT` allowlist (028 Q5); worker stays v3.2.0. Security-posture findings closed (issue #5972): Algorave Tone.js eval confirmation gate + warning banner, TMA source-map removal, webhook `__return_true` justification comments. Chat/REST hardening: legacy `attachments` parameters tolerated, custom message roles work again, orphaned tool messages silently discarded, attachment prep errors propagated, sign-preserving transcript pagination. Webhook/shortcode fixes: Google Chat `verification_token` shared-secret auth, Slack link/italic conversion ordering, paper-store `collection` guards, idempotent scheduled-result block. Slash-command handler re-resolution + CSV list parsing; idempotent assistant-builder/Pro toolkit blocks; workflow AJAX double-output fix; TPM fallback to the bundled model catalog without JetEngine; legacy admin-settings cleanup + WP 7.0 connector registry guard; Graphify `*_key` sensitive fields + canonical `WP_Error`; `remote_wp_connection` list_connections guidance; Content Graph wp.org assets refreshed (icons v5, screenshot, page preview). Tool count unchanged: ~303 base + ~1,256 Pro (~1,559 total). See `docs/project/proposals/031-media-worker-crawl4ai-integration-plan.md`, `docs/operations/security/SECURITY_POSTURE.md`.

- **v1.1.64** (August 26): Google Calendar connection & shared Google services — a new shared foundation in `includes/google/` (OAuth service, Calendar API v3 client, scope registry, credential resolver, sync + push) replaces four drifted Google OAuth copies, and a `google_calendar` connection type lands on both connection surfaces (base Settings → Integrations + Pro Remote Sites). Six new Pro tools in `addons/pro/includes/tools/google-workspace/` (`list_google_calendars`, `list_google_calendar_events`, `update_google_calendar_event`, `delete_google_calendar_event`, `check_google_calendar_availability` freeBusy, `quick_add_google_calendar_event`) join a reworked `create_google_calendar_event` (scope-enforced writes, Meet conferencing) and a real `sync_google_calendar`. Composio Connect hardening: verified account-health engine with live catalog-discovered probes, new `composio_manage_accounts` lifecycle tool (validate/reconnect/delete/prune), Health column + Verify/Reconnect in Remote Sites, identity-bound execution, nonce-gated app removal, deterministic zero-argument probes, proxied provider failures surfaced as real errors with reconnect guidance. Log hygiene: tools can declare non-loggable result fields (`WP_MCP_AI_Tool_Sensitive_Result_Interface`), credential-bearing URL query params are redacted from every logged string, and rolling log buffers get per-entry byte budgets + Data Management Compact/Delete. Fixed: validated-tool argument validation restored on Symfony 5.4, MCP JSON-RPC error envelopes + diagnostics page re-wired, Pro SPA v2 conversation/assistant sync, vision tools accept a 5–300s `timeout`, WP_Error envelope drift + cron/memory/elementor bugs. Tool count: ~303 base + ~1,256 Pro (~1,559 total). See `docs/developer/architecture/integrations/google-calendar-connection.md`, `docs/composio-connect.md`.

- **v1.1.63** (August 23): Artifact Evolution Phases A–G — the Continual Harness Evolver + Meta-Harness become a gated Darwinian self-improvement loop for skills, prompts, and roles: artifact populations with fitness-weighted parent selection, failure-case replay + post-mutation verification, pre-commit admission gate (three critics), holdout-gated deployment with shadow A/B + drift rollback, evolution governor with human approval queue + lineage graphs + assistant-screen metabox; `evolve_harness` ↔ Evolver contract repaired; all opt-in, switches in Settings → Orchestration Layer. Pro: new Addons admin page (NV oOS Pro Dashboard → Addons) with one-click install/activate for standalone addons (nonce + `install_plugins` + allowlist). Chat saves offload JSON stringify to a browser storage worker above a 10,000-char threshold (kill-switch filter, sync fallback). Fixed: DeepSeek 400s on empty tool schema properties (`{}` never `[]`), OKF skill-knowledge conformance (`type: Skill` frontmatter on all 91 bundled SKILL.md files + restored reference files), Pro SPA v2 slash-command composer, and test-suite exit traps (`bin/sweep-tests.php`, AJAX test contracts, bare-exit test seams, toolkit MCP scope/URI fixes, SSE/Veo polling filters). Tool count unchanged: ~303 base + ~1,249 Pro (~1,552 total). See `docs/project/proposals/007-artifact-evolution.md`, `docs/project/proposals/032-chat-web-workers-wiring-implementation-plan.md`.

- **v1.1.62** (August 22): OKF bundle management (Base) — new `WP_MCP_AI_OKF_Bundle_Manager` bundle lifecycle (create/list/rename/archive/delete, ZipSlip-safe ZIP import/export, health stats) with `skill-knowledge` protected; three new tools (`okf_list_bundles`, `okf_validate_bundle`, `okf_import_bundle`) + `okf_write_concept` provenance schema (`resource`/`sources`/`usage_window`/`verified`) bring the OKF tool surface to 10; new Bundle Manager admin screen (Bundles/Browser/Editor/Import-Export/Validate). Pro: OKF-to-Skill Bridge (`load_skill` `bundle:concept_id`, per-assistant grants + trust gating), auto-enrichment agent (`okf_enrich_site_content`), hybrid knowledge router (`route_knowledge_query`), and an OKF Skills Drawer in the Pro SPA v2 backed by the read-only `mcp-ai-pro/v1/okf` REST surface. Vector store tools migrated to the Responses API (headerless, `file_batches` ingestion + fallback) ahead of OpenAI's 2026-08-26 Assistants API removal. Fixed: 404 on percent-encoded OKF concept routes (`%2F`). Tool count: ~303 base + ~1,249 Pro (~1,552 total). See `docs/features/okf-integration.md`, `docs/project/plans/OKF-BUNDLE-MANAGEMENT-IMPLEMENTATION-PLAN.md`.

- **v1.1.61** (August 21): Agent identity bridging in memory store & recall — new `WP_MCP_AI_Agent_Identity_Resolver` resolves virtual agent keys (SPA drawer aliases, virtual planners) to the canonical assistant post ID; `store_agent_context` saves into the drawer's bucket and echoes `original_agent_id`/`agent_id_resolved`; chat-memory recall merges alias buckets with per-record `stored_under` stamps + `merged_sources` (default limit 25); memory drawers (base, chat-spa, pro-spa) show wing/room/stored-under chips, an agent-ID diagnostic, a show-all-scopes toggle, and store-triggered refresh; graph-bridge + scoped-recall failures degrade gracefully. OKF skill-knowledge bundle auto-generated from bundled skills on bootstrap + reinstall (fixes "OKF bundle not found" on okf_* tools). Fixed: undici pinned to ^7.29.0 (jsdom compat, CVE fixes retained) + content-graph CI checksum drift. nvoos-content-graph wp.org review reply + report (excluded from ZIPs). Tool count unchanged: ~300 base + ~1,247 Pro (~1,547 total). See `docs/features/memory/chat-client-integration.md`, `docs/features/okf-integration.md`.

- **v1.1.60** (August 21): Restricted-user flagging & unblocking — restriction registry (`WP_MCP_AI_Restriction_Registry`) converts ephemeral rate-limit/token-budget blocks into persistent, reviewable records with auto-expiry and audit logging; admin surfaces (Token Manager "Restricted Users" panel + Pro Command Center Restrictions tab), REST routes (`GET /restrictions`, `GET|POST /users/{id}/restrictions`, `DELETE /users/{id}/restrictions/{type}`), AJAX lift actions, and `wp mcp-ai restrictions list|lift|add` CLI; IETF rate-limit response headers; chat rate limits filterable via `wp_mcp_ai_chat_rate_limit` / `wp_mcp_ai_chat_rate_limit_window`; new `restriction_registry_on` posture signal. Conversation import to transcript CCT (Full, JetEngine) — new `includes/conversation-import/` subsystem imports ChatGPT / Gemini Takeout / Claude / ShareGPT / OpenAI JSONL into the `ai_chat_transcripts` CCT with 4 new tools (`conversation_import_detect|run|status|delete`), admin page, and `wp mcp-ai conversation-import` CLI. Tool schemas normalized before provider payloads (DeepSeek/REST/Tool Service/ChatOrchestrator). Fixed WP_Error fatals in memory/REST paths. `nvoos-content-graph` v1.0.3 (wp.org resubmission). Tool count: ~300 base + ~1,247 Pro (~1,547 total). See `docs/features/security/user-restrictions.md`, `docs/user-guides/conversation-import.md`.

- **v1.1.59** (August 19): Media Worker v3.2.0 — native `/api/crawl/*` endpoints (single-URL Markdown, batched crawling, link scans) with a static-first two-tier extraction pipeline and SSRF-guarded URLs, Crawl4AI-compatible facade for `run_crawl4ai_job` remote mode, LLM-based page extraction; toolkit memory estimate accounts for the worker sidecar. Research tools hardened — `semantic_content_search` embeddings via the shared provider abstraction (OpenAI, Gemini, Ollama, DigitalOcean; new Gemini embedding provider) with keyword fallback + model-mismatch skipping; `deep_research` provider-chain retries, reasoning-content fallback, no empty-report caching; new read-only base tools `list_terms` + `list_taxonomies`. Fixes: Docs Hub rebuild/broken-link staleness after in-place plugin updates (new `wp_mcp_ai_plugin_updated` action + version-mismatch rebuild guard; slug-map link resolution + clamped suggestions) and tool registration gaps (~32 orphaned base tools registered, legacy-format classes auto-wrapped, new `wp_mcp_ai_tools_init` hook). Tool count: ~300 base + ~1,243 Pro (~1,543 total).

- **v1.1.58** (August 18): Composio Connect integration (Pro) — new `addons/pro/includes/composio/` subsystem (OAuth auth handler with state nonce, API client, trigger bridge, signed webhook controller) and six new beta tools (`composio_list_tools`, `composio_get_tool_schema`, `composio_list_connected_accounts`, `composio_create_connect_link`, `composio_execute_tool`, `composio_manage_triggers`) plus remote-sites admin/metabox panels; see `docs/composio-connect.md`. OOS runtime consolidation Phases 0–5.8 — parity foundations, tool-surface + security-gate parity, event-sourced session log, opt-in shadow mode with `wp mcp-ai oos parity` CLI, canary routing, scoped tools + compaction seam, Pro composition & child binding, telemetry single-path; see proposal 029. Standalone Graphify plugins renamed to Content Graph (`nvoos-content-graph`, `-ai`, `-ai-platform` v1.0.2); `addons/graphify/` unchanged. Fixes: Security Center `wp.apiRequest` error (`wp-api` enqueued on security tab) and deepmerge-ts CVE-2026-40345. Tool count: ~265 base + ~1,243 Pro (~1,508 total).

- **v1.1.56** (August 14): Media Worker v3.0.0 — multi-tenant shared worker mode v2.4.0 (`SITE_TOKENS` per-site isolation, per-site rate limits, `SITE_TOKENS_PREVIOUS` rotation); Phase 2 per-site provider keys (`SITE_PROVIDER_KEYS`, `PROVIDER_KEYS_STRICT`) with per-site usage counters, grouped temp TTLs (`TEMP_TTL_UPLOAD/VIDEO/BROWSER/DOC`), cluster-mode warnings + k6 load-test kit; Phase 3 operational scale (multisite per-blog tokens, usage reporter cron, `SITE_TOKEN_<SLUG>`/`SITE_PROVIDER_KEYS_<SLUG>` env merges, opt-in Redis rate-limit store `RATE_LIMIT_REDIS=1`, `PROVIDER_KEYS_FILE` hot-reload). Zero-downtime token rotation (`WORKER_API_TOKEN_PREVIOUS`), Canvas v3 napi prebuilds, Cloudways readiness, live route fixes. Worker routing expansion: document generation, OCR, video frames, health charts, email, QR/translate/PDF, vectorization — all with local fallbacks; Pro settings lists worker-routed packages. Hermes tooling: WebUI MCP server (`bin/hermes-mcp-server.js`), SSH bridge (`bin/mcp-bridge-ssh.js`), skill sync (`bin/hermes-skill-sync.js`), Zed Console profile. Proposals 026/027/028. Addon count: 26. Bundled skills: 74 base + 41 Pro. Coding-time skills: 51.
- **v1.1.55** (August 13): MCP agent compatibility — JSON-RPC errors return HTTP 200 (SDK compat), legacy HTTP+SSE transport with credential-bound session store, tool rate limiter settings + credential-token exemption, GET/HEAD exempt from request quota, raw `cred_*` authorization headers, bounded async tool polling (~45s). New Hermes Fleet Operator addon (scoped `op_` operator credentials, admin page, WP-CLI, config generator, skills pack, runbook). Media Worker v2.2.0 security hardening (timing-safe token auth, SSRF guard, sandboxed Puppeteer, rate limiting, Helmet, health endpoints) + `WP_MEDIA_WORKER_TOKEN` constant + Velocity cloud setup guide. RabbitMQ status widget fix (settings registry read + AJAX handler registration). Database connection pooling stance (Proposal 023): RabbitMQ gating, atomic concurrency slots, PDO persistent connections, Site Health checks. PostCSS >=8.5.26 (GHSA-6g55-p6wh-862q). Media worker subtree sync workflow. Addon count: 26. Bundled skills: 74 base + 41 Pro. Coding-time skills: 51.
- **v1.1.54** (August 12): PostCSS CVE-2026-69153 fix (bumped to 8.5.23). MCP async tool response handling fix — tools/call now correctly awaits async results. Plugin updater integrity check v2 — fixed phantom bridge file false-positive from stale stat cache, added clearstatcache(). API key merged-settings fix — 20 research tools now use get_merged_credentials() honoring per-assistant/provider overrides. 29 design-* skills enhanced/created — 7 new pro-toolkit skills (ai-assistant-admin, crm, project-management, communications, services, team-management, vault, security-ops), ~8,000 lines. OKF YAML frontmatter compliance — added missing type: Skill to all 22 design-* skills, fixed spec violations in 9. README TOC anchor fixes for VS16 emojis and U+26xx/U+27xx symbols. Stale v1.1.52 build artifacts removed.
- **v1.1.53** (August 12): Shared Analytics Service (7 platform adapters, 5 DTOs, cross-platform normalization). Circuit breaker protection on all 15 AI provider clients. Concurrency guard, cost tracker, and backpressure wired into execution pipeline. 22 new design-* agent skills synced (bundled skills: 45→67). SSE backoff reset and rate-limit fixes. Load Guard fatal error fix. Documentation catch-up: CHANGELOG, README, CLAUDE.md, AGENTS.md, 6 .context/ files updated.
- **v1.1.52** (August 11): Paper Store remote site support — 8 tools + REST API (697 lines) + remote trait, `list_mcp_tools` discovery tool. Remote connection CPT auto-discovery. Design System tool preset (72 tools, 13 categories). Post-install integrity check (15 critical paths). MCP protocol version negotiation for Zed/Claude Desktop/Cursor. Pro update visibility fix. Docker chmod suppression. Security bumps (multer, nodemailer, sharp). 3 new feature docs: Paper Store, Remote Sites, MCP Protocol Version Negotiation.
- **v1.1.51** (August 11): Documentation audit & gap-fill — 12 gaps resolved across P0/P1/P2 tiers. DOCUMENTATION_INDEX updated with August 2026 section (7 versions, ~30 proposals). 6 new feature reference docs: Backup & Restore, Plugin Updater, Abilities API, Self-Hosted OCR, SGI Transparency, Embedded v0.2.0. FOR_REVIEWERS and ADDON_INVENTORY counts updated. README v1.1.46 gap filled.
- **v1.1.50** (August 10): Media Worker Sidecar — Docker-based Node.js sidecar with 11 route handlers (browser, code, data, document, email, image, ocr, pdf, social, video, workflow). Queue module with concurrent processing. Pro integration (settings + client trait). Docker DNS-to-IP loopback resolution. Site Health redeclaration fix. NPM security fixes (nanoid, js-yaml, dompurify across 11+ addons). BMAD agent editing conventions. WPCS formatting cleanup.
- **v1.1.49** (August 8): Gemini model resolution fix, update reactivation + release ZIP cleanup, OCR & tool cleanup.
- **v1.1.48** (August 8): Shopify Sync toolkit fixes (7 fixes), PHPCS CVE-2026-67434, 6 new default skill catalogues (Brave Search, WordPress Agent Skills, Cloudflare Agent Skills, Google Workspace CLI, OpenAI Agent Skills, Google Agent Skills).
- **v1.1.47** (August 7): MySQL Connection Exhaustion fix for Cloudways — cron system overhaul with concurrency limits + memory caps + staggered scheduling. Activation bootstrap connection throttling. Service status registry hardening. Update checker cache bust for manual refresh. Mermaid npm audit fix (5 CVEs resolved). Pro status page improvements (JS, AJAX, dashboard).
- **v1.1.46** (August 6): Comprehensive Backup & Restore with 11 modular export providers (8 base + 3 Pro). GitHub-based Plugin Updater (772 lines) with base-to-complete upgrade path. Abilities API selective adoption (includes/abilities/ framework, 5 classes, 5 test files) for AI agent discovery. Status Page fixes (fatal error in REST, JS errors, i18n). Knowledge base auto-build CI. PHPCS cleanup across 100+ files (parse errors, text domain, WPCS formatting).
- **v1.1.45** (August 5): Self-hosted OCR (Unlimited-OCR + DeepSeek-OCR) — 17 files, +4,087 lines. New unified vLLM client, Pro tools (`pro_unlimited_ocr`, `pro_batch_ocr`), structured extraction service, Embedded OCR backend + health dashboard, admin settings UI. Embedded addon v0.2.0 (voice, OpenMed, MCP abilities). AI transparency & SGI compliance. Comic Reader v0.2.0. Graphify standalone plugins v1.0.1. Build/release automation.
- **v1.1.44** (August 4): CCT stability (mutex lock, FlowHub guard, base-plugin fatal w/out lib/core, Veo async context). API key fixes (Gemini video + Veo fallback). Proposal 016 architecture hardening (277 autoload optimizations, phpcs sweep, 8 findings). Proposal 017 polling/queue/load-balancing (12 weaknesses). Deferred security items #5755. npm security: undici >=8.10.0, fast-uri >=3.1.4, ip-address >=10.4.0 across 11 pkg. Docs: FOR_REVIEWERS v1.1.43 (~1,500 tools), 16 broken links fixed, Graphify ecosystem audit.
- **v1.1.43** (August 1): MCP 2026-07-28 stateless core upgrade. Security v1.1.43 hardening (SSRF/CSRF/SQL/XSS across 16 files). OKF v0.2 trust-signal support (recursive descent parser, trust tiers, new validation tool). ICP System (Pro CRM Phase G, 7-dimension scoring). Pro Module Registry PSR-4. Hexagonal architecture purity (PlatformFlushInterface). 7 playbook/profession sync fixes. Phase 3 operational security hardening. WPCS 3.4.1 (CVE-2026-45293). Addon count: 27. Knowledge base: 311 professions.
- **v1.1.42** (July 29): Security infrastructure (7 classes: Request Guard, Security Posture with 21 signals, Destructive Ops Gate, URL Guard, Concurrency Guard, Cost Tracker, API Key Store). Site Health checks. Production hardening guide. CORS/rate limiting/error verbosity/body size enforcement with dashboard posture signals. nvoos/core framework-agnostic engine (32 contracts + 21 WP adapters, 109 tools migrated, 5 parity gaps closed). Status page & incident communication (Pro) with 4 AI tools. 21 coding-time agent skills + 6 BMAD agent definitions. Algorave addon (9 tools). Critical bug fixes (request guard param order, nonce query param auth). 13 new security unit tests. Addon count: 27.
- **v1.1.40** (July 15): Content Format Awareness helper (Markdown/HTML/plain text detection). Research to Paper Store to WordPress Draft pipeline with new `create_post_from_research` tool. Settings credential split (two-option isolation with transparent merge). Demo video pipeline complete (Phases 0-5). Kimi & DeepSeek client parity. Model catalog update (24 files, July 2026 defaults). OOS Engine SchemaStoreInterface + 45 tests. SSE HTTP/2 fixes (`ob_clean`, 524 timeout). Vector store sync no polling. Settings import/export batch fixes (4 PRs). **Phase 8 MCP servers: 33 total (4 new — Pro Scheduler, FlowHub, Shopify Sync, EZuite). OAuth 2.0 MCP authentication (PKCE, hierarchical scopes, token management UI, browser-based login). Per-toolkit MCP settings slug fix.** Validated tool slug allowlist fix.
- **v1.1.41** (July 22): OKF Integration (Open Knowledge Format v0.1 engine + 6 MCP tools, 41 bundled skills OKF-conformant). Security compliance (11 HIGH/P0 fixes: HMAC policy tokens, health auth-gating, ZIP validation, CSRF nonces, SRI hashes). Playbook sync fixes (duplicate AJAX handler resolved, silent failures reported, CPT class guards). Model provider credential resolution (all 4 key sources). Dependency bumps (adm-zip, axios, brace-expansion — 18 alerts, 0 audit vulns).
- **v1.1.39** (July 13): Meta-Harness auto-optimization system (all 7 phases). Agent delegation rework (inline execution, REST dispatch, cron resilience, spawn_cron, name-based resolution). Pro SPA v2 polish (20+ PRs: vector store/autocomplete fixes, cost badges, allowSensitiveTools, tool result rendering, auto-save transcripts, attachments/save/storage, tasks drawer toolbar, speech/audio, capability flags, usage badges, sidebar, media, system prompt, layout). Tool presets refactor (essentials layers, auto-upgrade, SSE fix, tool_call_id fallback). CRM fixes (cache loop, Upwork rate limiting, freelance sourcing). Infrastructure (Veo 2.0 to Gemini Omni Flash with deprecation detection, workflow auth, ZAP scan, npm rebuild).
- **v1.1.38** (July 10): Page Agent addon v0.1.0 (AI browser page control copilot). Pro SPA v2 major parity update (voice pipeline, tasks drawer, workflow tracker, file attachments, tool shortcuts, slash commands, mobile hamburger, autoscroll/viewport fixes, cache-busting, assistant preloading). Per-user chat memory toggle. create_post/save_post Markdown-to-HTML conversion + smart taxonomy suggestions. Workflow blueprint existing-content awareness. SPA accessibility: annotation pills.
- **v1.1.37** (July 8): JetEngine Meta Helper universal (25 CPTs, REST, ECA), Places enrichment tools, RabbitMQ + queue infrastructure, Multi-tenant DB isolation Phase 0–4, DSpark admin UI + orchestration, Crocoblock DS addon, Test coverage: 329 tools/28 toolkits, Docs Hub broken link engine, OWASP ZAP DAST, 30+ bug fixes.
- **v1.1.36** (July 4): EZuite Inventory Sync Pro Toolkit, Ralph Loop CCT migration + circuit breaker, JetBooking/JetAppointment (8 tools), Moonshot AI (Kimi) & Z.AI (GLM) → DeepSeek parity, unified sync log manager, tool presets auto-select + chips bar, HTTrack cache + Place-to-Service bridge, Generate Default Mapping + read-only sync, 429 web search retry, 45+ bug fixes across CCT/sync/tools/infrastructure.
- **v1.1.35** (June 29): FlowHub Inventory Sync Pro Toolkit (6 tools), Shopify Sync Pro Toolkit (5 tools), Necessity Gate Layer J (irreversibility-weighted safety), Local Voice Embedded STT (3 backends, offline-first), Remote Site Administrator blueprint (22 tools), Places & Calendar bulk import, CLI site-import subcommand, voice realtime auto-detect, 7 bug fixes.
- **v1.1.34** (June 27): GPT-Realtime-2 voice models with WebRTC transport + Translate/Whisper clients + reasoning. Multi-channel result delivery UI (Telegram, Discord, WhatsApp, Google Chat). Pro scheduler AI/workflow response delivery. Graphify ecosystem: remote drivers, WP 7.0 Connectors, wp.org compliance. 3 reasoning-tool fatal bugs fixed. CRM deal import, multi-source auto-import, Upwork/LinkedIn toggle. Docs Hub REST + settings sync fixes. CVE-2026-55602, Gemini cache fix, GPT image routing fix. FastAPI porting plan.
- **v1.1.29**

- **v1.1.29** (June 12): **Pro Toolkit Optimizations Phase 1–3** across 6 toolkits; **Chat Transcript & Agent Memory Retention**; **DietPi Pro Toolkit** (19+ tools, MCP server, SSH proxy); **Layer I Guardrails** (jailbreak prevention); **Context Window Management** (13-provider validation, tiktoken, token capping); **LibreChat Addon**; **Schedule Anything SaaS**; **Vector Search** (HNSW, hybrid); **CRM Enhancements** (email import, lead pruning, inline tags, duplicates); **25+ bug fixes**. Full docs: [`docs/features/`](features/).
- **v1.1.28** (June 8): CRM Phase C (IMAP/Twilio/WhatsApp/Gmail inbound), Customer CPT + 360, Support Ticket Module (10 tools + SLA), TF-IDF + BM25, Attention Routing (QKV 5-head), Funiq Bridge, NVOOS Graphify.
- **v1.1.27** (June 5): Real-Time SSE Streaming, 35 OOS Core Tools, Extended Cognition Vision, JFB fixes, Graphify compliance, June 2026 model pricing.
- **v1.1.26** (June 3): Cross-Platform Extraction Engine, Site-Builder Pipeline, SPA a11y Hardening, Cloudways Dashboard SPA.
- **v1.1.25** (May 31): Unified Blueprint System (55 blueprints), Cloudways Toolkit (60 tools), CRM Toolkit A–E (70+ tools), Chat UI Enhancements.

### Previous Updates (April 2026)

- **Harmonization Sub-Toolkit** 🎨 — 14 new Pro tools under `addons/pro/includes/tools/image-production/harmonization/` that complement the end-to-end `product_actualization` tool with composable AI-compositing primitives (color harmonization, relighting, shadow synthesis, reflection, boundary refinement, AI-assisted background generation, outpainting, placement suggestion, lighting analysis, and an end-to-end orchestrator). See [`docs/harmonization-architecture.md`](harmonization-architecture.md). Example LLM prompts:
  - *"Place this product photo on an AI-generated kitchen counter."*
  - *"Drop the attached subject onto this uploaded background, lower-center, with a soft contact shadow."*
  - *"Rebuild this catalog page with consistent harmonization across all eight products."*
- **April 2026 Security Audit Summary** 🛡️ (v1.1.10) — New [`SECURITY_AUDIT_2026_04.md`](operations/compliance/SECURITY_AUDIT_2026_04.md) consolidates the nine deliverables under [`audits/2026-04/`](project/audits/2026-04/). No Critical findings; 5 High (3 Fixed, 2 Partially Fixed); 14 Medium (all Fixed); 21 Low (14 closed); 10 Informational; 50 total. Standards: WP Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 / API Top 10, WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA, MCP/SSE.
- **Production-Ready Vendor Autoload** (v1.1.10) — `vendor/` regenerated with `composer install --no-dev --classmap-authoritative`; plugin is deployable from a clean clone (PR #4733).
- **Veo 3.1 `generate_veo_video` Fix** (v1.1.10) — `seed` parameter now sent only to Veo 2.0 (`veo-2.0-generate-001`); Veo 3.1 (`veo-3.1-generate-preview`) rejects it (PR #4735).
- **Measurement Subsystem GA** ⭐ (v1.1.9) — 12 sequenced PRs delivered the full measurement / evals / reward stack: stock metrics for tool-execution, chat-loop, agentic-loop, and SSE; persistent `{prefix}mcp_ai_metric_events` table with retention cron (`wp_mcp_ai_metric_retention_days`, default 30 days); eval harness with verifier-independence enforcement; Pro rubric presets (`prompt_adherence`, `json_schema`, `citation_presence`) and counterfactual runner; OTel JSON exporter; Measurement dashboard under **Tools → Measurement** with time-range + sparkline; `wp mcp-ai measurement run|alert-check|list-runs` WP-CLI runner with regression-aware exit codes. See [`docs/measurement/README.md`](measurement/README.md).
- **PHPUnit 11 Upgrade (CVE Fix)** 🔒 (v1.1.9) — PHPUnit upgraded to 11.x with WordPress-compatibility patches to resolve the argument-injection vulnerability **GHSA-qrr6-mg7r-m243**. CI PHP bumped 8.1 → 8.2.
- **Chart.js Handle Normalization** (v1.1.9) — All admin dashboards now enqueue a single `wp-mcp-ai-chartjs` handle to eliminate duplicate registrations and version drift.
- **Graphify Knowledge Graph Addon v0.5.0** (v1.1.9) — Optional WordPress Knowledge Graph addon restored under `addons/graphify/`.
- **Orchestration Reference Doc** (v1.1.9) — New [`docs/ORCHESTRATION_REFERENCE.md`](ORCHESTRATION_REFERENCE.md) documents every workflow preset, resource preset, the PSO algorithm, and all orchestration hooks / filters / storage keys in one place.
- **Erlang C Queuing Theory Tools** (v1.1.8) – 4 workforce-management tools built on the Erlang C formula. `calculate_erlang_c` (general staffing solver), `erlang_c_concurrency_advisor` (AI session tuning), `erlang_c_staffing_advisor` (multi-channel with bot-deflection and WFM endpoint), `erlang_c_queue_health` (real-time SLA monitoring with `wp_mcp_ai_queue_alert` action hook). All four ship in the base plugin with no external dependencies. See [`docs/features/erlang-c-staffing-tools.md`](features/erlang-c-staffing-tools.md).
- **tool-reference.md fully updated** – historical April audit superseded by current ~830-tool framing; use `WP_MCP_AI_Tool_Registry::get_tools()` for live counts. Added 14 new sections covering: OpenAI file/model management, text embeddings & vector stores, multi-agent orchestration, agent memory management, reasoning & code analysis, deep research, browser-native AI (client-side NLP), Yahoo Fantasy Football toolkit, Newsletter plugin integration, WP All Import/Export integration, Flowhub cannabis dispensary, PayHere payment gateway, and Erlang C queue tools.
- **MCP Protocol Completion** ⭐ (v1.1.7) – Full MCP 2024-11-05 spec compliance: `resources/read`, `prompts/get`, `ping`, `completion/complete`, `logging/setLevel`, `notifications/cancelled`, JSON-RPC batching (up to 20 messages), tool annotations, `Mcp-Session-Id` management.
- **MCP Apps (SEP-1865)** ⭐ (v1.1.7) – Per-assistant remote MCP server connections (up to 10) with JSON-RPC 2.0 tool bridging, transient-cached discovery, admin metabox.
- **CRE Debt & Securitization Pro Toolkit** ⭐ (v1.1.7) – 57 new tools across 5 modules (Originations, Underwriting, CMBS, Debt Fund, Asset Management). 36 new professions, 17 new team configurations.

### Previous Updates (April 2026 — v1.1.6)

- **Getting Started Wizard** ⭐ NEW – 4-step onboarding wizard with 8 use-case presets (Content Creator, Customer Support, E-commerce, SEO & Research, Developer Copilot, Media & Creative Studio, Site Administrator, General Purpose). Selecting a preset creates a fully-configured assistant with tools, system prompt, and tuned temperature — working out of the box. WCAG 2.1 accessible with keyboard navigation. Access via **NV oOS → Getting Started**.
- **Quick Tool Selection Presets** ⭐ NEW – broad preset coverage on the assistant CPT edit page; verify exact live coverage against the registry (~830 current framing). New `📋 Registration & Compliance` preset (44 tools). Expanded 20+ existing presets with Shopify, full cross-platform messaging, tool scaffolding, cloud storage, site builder sections, appointment management, and more.
- **Security Hardening** ⭐ NEW – AES-256-GCM encryption upgrade, finfo fail-closed MIME detection, Discord replay attack protection, HTTPS enforcement, ZIP bomb protection, OCR error info-disclosure fix.
- **Chat Channels** – Fixed Slack @mentions, Google Chat OIDC/route issues, Teams multi-connection with OAuth one-click, Telegram typing indicator and slash-command integration.
- **Telegram Mini App** – Doctor tab now uses connection-assigned assistant; AI replies rendered as Markdown HTML; vitals log import improved.
- **AI Providers** – Gemini embedding-001 model, output_dimensionality, 9 new task types. Product actualization tool defaults to AI-powered mode (Gemini/OpenAI).
- **PDF Generation** – pdfkit/cheerio/docx/exceljs bundled into generate-*.bundle.js; no runtime node_modules needed.
- **WordPress.org Compliance** – .gitattributes excluded from ZIPs; composer.json now ships with vendor/; languages/ directory created.

### Previous Updates (February – early March 2026)

- **WordPress.org Compliance** - Removed hardcoded admin menu positions (v1.1.2)
- **JetEngine CPT/Taxonomy AI Integration** - AI metaboxes and Research & Add pages for all JetEngine CPTs
- **Package Pre-Bundling** - Critical npm packages pre-bundled in vendor directory (no npm install required)
- **Product Research Fixes** - Fixed CSS/JS loading and tab system issues
- **Pro Workflow Builder** - Fixed React initialization and stability issues
- **OAuth Improvements** - Fixed Google, Yahoo, and Mailjet authentication flows
- **Telegram Mini App CMS Overhaul** – Full WordPress CMS interface in Telegram WebView (CPTs, tools, media)
- **Discord/Telegram Reactions** – `add_discord_message_reaction`, `add_telegram_message_reaction`, `get_discord_voice_channel_members`
- **WhatsApp & Messenger Fixes** – Group routing, auto-reply error #133010, webhook processing, Messenger test connection
- **Google Chat Fixes** – HTTP 404 test connection fix, auto-reply thread routing, OAuth improvements

---

## 🚀 Quick Start

### Requirements

**Minimum:**
- WordPress 6.0+
- PHP 7.4+ (PHP 8.0+ recommended)
- MySQL 5.7+ or MariaDB 10.3+

**Optional (for enhanced features):**
- **Node.js 14+**: For image vectorization (`vectorize_image` tool)
- **PHP Functions**: `proc_open`, `proc_close`, `proc_terminate`
  - Required for Node.js integration and Process Service
  - Often disabled on shared hosting
  - **Can be enabled on Cloudways**: Settings & Packages → Application Settings → PHP FPM → Remove from `disable_functions`
- **JetEngine**: For CCT storage and content tools
- **WooCommerce**: For e-commerce tools
- **[Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor)**: For page builder widgets

**Note**: Plugin works without optional requirements, but some features will be unavailable. See [deployment troubleshooting](getting-started/installation-setup/deployment-troubleshooting.md) for details.

### Installation (30 seconds)
```bash
# 1. Upload plugin
# 2. Activate from WordPress admin
# 3. Complete the Getting Started wizard (auto-redirects on first activation)
#    → Step 1: Welcome
#    → Step 2: Connect your AI provider (OpenAI, Gemini, NVIDIA NIM, Ollama, etc.)
#    → Step 3: Choose a use-case preset (creates a ready-to-use assistant)
#    → Step 4: You're all set — copy the [mcp_ai_chat] shortcode
```

### Developer Installation (GitHub Clone)

> **Note:** The plugin is production-ready after cloning or installing from ZIP — no `npm install` or `composer install` is required for normal use. Built assets are already included. Only run the commands below if you need to **rebuild JavaScript/CSS assets** (development workflow).

**For Cloudways (Recommended):**
```bash
# SSH into your server and clone directly into plugins directory
cd /home/master/applications/YOURAPP/public_html/wp-content/plugins/
# Use --depth 1 for a fast shallow clone (repo is very large)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
# Activate the plugin in WordPress admin — it is ready to use.
```

**For Local/VPS (Development asset rebuild only):**
```bash
# Option 1: Clone directly into WordPress (recommended)
cd /path/to/wordpress/wp-content/plugins/
# Use --depth 1 for a fast shallow clone (repo is very large)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
# Activate the plugin — pre-built assets included, no npm needed.

# Option 2 (development): Clone, rebuild assets, then copy
# Use --depth 1 for a fast shallow clone (repo is very large)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
# Only on a machine with proper write access and Node.js installed:
npm install && npm run build
composer install --no-dev
cp -r . /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
```

**⚠️ Important:** 
- On Cloudways: Clone directly into the plugins directory to avoid errors
- **Do NOT run `npm install` in a WordPress plugins directory on managed hosting** — npm will fail with `EACCES: permission denied` when trying to create `package-lock.json`. This is expected; the plugin does not need npm on the server.
- If you must run npm in a restricted directory: use `npm install --no-package-lock`
- **Note:** Autoloader optimization is configured by default in composer.json

### First Chat (2 minutes)
```php
// Add to any page/post
[mcp_ai_chat assistant="123"]

// With guest access
[mcp_ai_chat assistant="123" allow_guests="true"]
```

---

## 🔑 Essential Settings

### Required Configuration
| Setting | Location | Default | Notes |
|---------|----------|---------|-------|
| OpenAI API Key | Settings → NV oOS | None | **Required** |
| Default Model | Settings → NV oOS | gpt-4o-mini | Cost-effective |
| Request Timeout | Settings → NV oOS | 30s | Min 5s |
| Enable Logging | Settings → NV oOS | Off | Use for debugging |

### Optional Integration Keys
- **Gemini API Key** - For Gemini provider support
- **NVIDIA API Key** - For NVIDIA NIM provider support (get from [build.nvidia.com](https://build.nvidia.com/))
- **Crawl4AI URL** - For web crawling capabilities
- **Mailjet API** - For email automation
- **QuickBooks API** - For financial reporting
- **OpenRouter API Key** — For OpenRouter unified gateway (OpenAI/Anthropic/Google/Meta via one key)
- **DeepSeek API Key** — For DeepSeek provider (reasoning_content passthrough)

---

## 👥 Common User Tasks

### Creating an Assistant

![Create Assistant](screenshots/admin/61-create-assistant.png)
*Create Assistant page with 204 profession templates*

```
1. Navigate to AI Assistants → Add New
2. Enter title and description
3. Select available tools
4. Configure model defaults (optional)
5. Add base knowledge files (optional)
6. Publish assistant
```

### Using Chat Interface
```
1. Add [mcp_ai_chat assistant="ID"] to page
2. Type message in chat box
3. Press Enter or click Send
4. View assistant response with tool feedback
5. Continue conversation naturally
```

### Uploading Files to Chat
```
1. Click attachment icon in chat
2. Select file (images, PDFs, documents)
3. Add message describing what to do with file
4. Send message
5. Assistant processes file and responds
```

### Creating Prompt Shortcuts
```
1. Edit assistant
2. Find "Prompt Shortcuts" meta box
3. Click "Add Shortcut"
4. Enter label and prompt
5. Optionally select target tool
6. Save assistant
```

---

## 👨‍💻 Developer Commands

### WP-CLI Commands
```bash
# Check plugin status
wp mcp-ai status

# Test remote connection
wp mcp-ai remote https://example.com/wp-json/mcp-ai/v1 --token=YOUR_TOKEN

# List optional plugins
wp mcp-ai plugins list

# Activate plugin
wp mcp-ai plugins activate woocommerce
```

### Composer Commands
```bash
# Install dependencies
composer install

# Run linting
composer run lint

# Auto-fix code standards
composer run format

# Run tests
composer run test

# Check PHP compatibility
composer run lint:compat

# Base plugin certification checks (excludes pro/examples/tests)
composer run lint:base
composer run lint:base:compat
```

### npm Commands
```bash
# Install JavaScript dependencies
npm install

# Lint JavaScript
npm run lint:js

# Auto-fix JavaScript
npm run lint:js:fix
```

---

## 🛠 Tool Categories & Common Tools

### Content Management
```
- search_content - Search posts/pages
- save_post - Create/update content
- get_recent_posts - List latest posts
- search_attachments - Find media files
```

### AI Generation
```
- generate_openai_image - Create images
- generate_openai_speech - Text to speech
- transcribe_openai_audio - Audio to text
- submit_document_prompt - Process documents
```

### Research
```
- web_search - Search DuckDuckGo/Brave
- run_crawl4ai_job - Crawl websites
- get_open_meteo_forecast - Weather data
- reliefweb_reports - Humanitarian alerts
```

### Operations
```
- get_site_summary - Site overview
- get_site_health - Health checks
- get_system_logs - View logs
- check_wp_cli - WP-CLI status
- count_tokens - Estimate token counts
```

---

## 🔐 Security & Authentication

### Generating Assistant Credentials
```
1. Edit assistant
2. Find "API Credentials" meta box
3. Click "Generate Credential"
4. Copy token (shown once!)
5. Use in Authorization header: Bearer cred_xxxxx.SECRET
```

### Guest Access Configuration
```php
// Shortcode with guest access
[mcp_ai_chat assistant="123" allow_guests="true"]

// Filter chat capability
add_filter( 'wp_mcp_ai_chat_capability', function( $cap ) {
    return 'public'; // Allow all visitors
} );
```

### Auth0 Setup (ChatGPT)
```
1. Settings → NV oOS
2. Add Auth0 Domain
3. Add Auth0 Audience
4. Add Auth0 Scope
5. Generate Auth0 token
6. Test with token
```

### WordPress.com/Gravatar Bridge
```
1. Settings → NV oOS → Authentication
2. Enable WordPress.com/Gravatar identity bridge
3. (Optional) Configure userinfo endpoint
4. Save settings
5. Use OAuth tokens with wordpress.com|* or gravatar|* subjects
```

---

## 🧮 Token Counting & Budget Management

### Using count_tokens Tool
```javascript
// Count tokens for text (automatic - tries tiktoken, falls back to heuristic)
{
  "text": "This is a message to count tokens for.",
  "model": "gpt-4o-mini"
}

// Count tokens for messages array
{
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Hello, how are you?"}
  ],
  "model": "gpt-4o-mini",
  "method": "tiktoken"  // Options: tiktoken, heuristic, auto (default)
}

// Response includes:
// - estimated_tokens: Accurate token count
// - counting_method: Which method was used (tiktoken or heuristic)
// - model_info: Context limits, TPM/RPM limits, usage percentage
// - budget_info: Safe limits, remaining tokens, recommendations
```

### Token Counting Methods
| Method | Accuracy | Speed | Requirements |
|--------|----------|-------|--------------|
| `tiktoken` | Exact (uses OpenAI's BPE) | Fast | Composer install required |
| `heuristic` | ~4 chars/token estimate | Very Fast | No dependencies |
| `auto` (default) | Tries tiktoken, falls back | Fast | Works always |

### Installation for Accurate Counting
```bash
# Install tiktoken-php library
composer install

# Verify installation
composer show rahul900day/tiktoken-php
```

---

## 🌐 REST API Quick Reference

### Base URL
```
https://your-site.com/wp-json/mcp-ai/v1
```

### Key Endpoints
```bash
# List assistants
GET /assistants

# Start chat
POST /chat
{
  "assistant_id": 123,
  "messages": [
    {"role": "user", "content": "Hello"}
  ]
}

# Execute tool
POST /tools
{
  "assistant_id": 123,
  "tool": "get_site_summary",
  "arguments": {}
}

# SSE stream (Server-Sent Events)
GET /sse
Accept: text/event-stream

# SSE job status
GET /jobs/{job_id}/stream?max_duration=300&poll_interval=2
```

### SSE Streaming Examples
```javascript
// Stream assistant directory
const eventSource = new EventSource('/wp-json/mcp-ai/v1/sse');
eventSource.addEventListener('directory', (e) => {
  const data = JSON.parse(e.data);
  console.log('Assistants:', data.assistants);
});

// Stream job status
const jobStream = new EventSource(`/wp-json/mcp-ai/v1/jobs/${jobId}/stream`);
jobStream.addEventListener('status', (e) => {
  const status = JSON.parse(e.data);
  console.log('Progress:', status.progress + '%');
});
```

### Authentication Headers
```bash
# WordPress nonce (same-origin)
X-WP-Nonce: abc123

# Bearer token (remote)
Authorization: Bearer cred_xxxxx.SECRET

# Guest token
X-WP-MCP-AI-Guest: guest_token_here
```

---

## 🐛 Troubleshooting Quick Fixes

### Chat Not Working
```
1. Check OpenAI API key in settings
2. Verify assistant is published
3. Check user has edit_posts capability (or use allow_guests="true")
4. Enable logging to see errors
5. Check browser console for JavaScript errors
```

### Tool Execution Fails
```
1. Verify tool is enabled for assistant
2. Check user has required capability
3. Ensure dependencies are installed (WooCommerce, JetEngine, etc.)
4. Enable logging to see tool errors
5. Test tool individually via REST API
```

### API Rate Limiting
```
1. Check OpenAI account limits
2. Review request timeout settings
3. Enable rate limit protection in settings
4. Consider caching frequently requested data
5. Upgrade OpenAI plan if needed
```

### File Upload Issues
```
1. Check file MIME type is allowed
2. Verify file size < 5MB (default)
3. Check WordPress upload_max_filesize
4. Ensure proper permissions on uploads folder
5. Review attachment settings in NV oOS
```

---

## 📊 Monitoring & Logs

### Viewing Logs
```bash
# Via WP-CLI
wp option get wp_mcp_ai_recent_errors --format=json
wp option get wp_mcp_ai_recent_activity --format=json

# Via PHP
$errors = get_option( 'wp_mcp_ai_recent_errors', [] );
$activity = get_option( 'wp_mcp_ai_recent_activity', [] );
```

### Usage Tracking
```php
// Get user usage
$tracker = WP_MCP_AI_Usage_Tracker::get_instance();
$usage = $tracker->get_usage( $user_id );

// Usage structure
[
  'openai' => [
    'gpt-4o-mini' => ['tokens' => 1000, 'requests' => 5]
  ]
]
```

### Performance Monitoring
```
1. Enable logging temporarily
2. Review response times in logs
3. Check database query counts
4. Monitor memory usage
5. Profile with Query Monitor plugin
```

---

## 🔧 Configuration Snippets

### wp-config.php Constants
```php
// Base version mode (fewer tools)
define( 'WP_MCP_AI_BASE_VERSION', true );

// Crawl4AI endpoint
define( 'WP_MCP_AI_CRAWL4AI_BASE_URL', 'http://localhost:8000' );

// Custom capability
define( 'WP_MCP_AI_DEFAULT_CAPABILITY', 'edit_posts' );
```

### Custom Tool Registration
```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    $registry->register( 'my_tool', new My_Custom_Tool() );
} );
```

### Filter Chat Messages
```php
add_filter( 'wp_mcp_ai_chat_options', function( $options, $assistant, $request ) {
    // Modify temperature
    $options['temperature'] = 0.7;
    return $options;
}, 10, 3 );
```

### Hook Into Tool Execution
```php
add_action( 'wp_mcp_ai_before_tool_execution', function( $tool, $args, $context ) {
    error_log( "Executing tool: {$tool}" );
}, 10, 3 );
```

---

## 📱 Mobile & Responsive

### Chat Widget Sizing
```css
/* Custom chat width */
.mcp-ai-chat-container {
    max-width: 600px;
    margin: 0 auto;
}

/* Mobile optimization */
@media (max-width: 768px) {
    .mcp-ai-chat-container {
        max-width: 100%;
        padding: 10px;
    }
}
```

---

## 🎨 Customization

### Chat Theme Colors
```
Settings → NV oOS → Chat Theme
- Primary Color
- Secondary Color
- User Message Background
- Assistant Message Background
- Border Color
- Text Color
```

### Custom CSS
```css
/* Add to theme */
.mcp-ai-chat-message.user {
    background: #007cba;
    color: white;
}

.mcp-ai-chat-message.assistant {
    background: #f0f0f0;
    color: #333;
}
```

---

## 🧠 LLM Harness Quick Toggle

Per-assistant opt-in: Edit Assistant → **LLM Harness** metabox → Enable → check the layers you want (A–H). All layers are off by default. Reference: [docs/llm-harness.md](llm-harness.md).

---

## ✅ HITL Approval Queue

Admin: **NV oOS → Orchestration → Approvals**. Tool: `request_user_approval`. REST: `GET/POST/PATCH /wp-json/mcp-ai/v1/approvals/*`. Pending → Publish = approved; Private = denied.

---

## 🔗 Toolkit MCP Discovery

Discovery endpoint: `GET /.well-known/mcp` (returns JSON array of all enabled toolkit server URLs). Credentials: **NV oOS → Orchestration → Toolkit MCP → {Toolkit} → Credentials**. CLI: `wp mcp-ai mcp-server token-generate {slug}`. Reference: [docs/mcp-servers.md](mcp-servers.md).

---

## 📚 Additional Resources

### Full Documentation
- [Complete README](../README.md) - 1,027 lines of comprehensive docs
- [Documentation Index](DOCUMENTATION_INDEX.md) - All 39 documentation files
- [Tool Reference](reference/tools/tool-reference.md) - All ~830 tools detailed (~195 base + ~635 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
- [REST API Guide](reference/api/rest-api.md) - Complete API documentation
- [Orchestration Budget Enforcement](architecture/orchestration/orchestration-budget-enforcement.md) - Budget prediction and adjustment

### External Links
- [OpenAI Platform](https://platform.openai.com/)
- [WordPress Codex](https://codex.wordpress.org/)
- [JetEngine Docs](https://crocoblock.com/knowledge-base/jetengine/)
- [Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor)
- [Elementor Developers](https://developers.elementor.com/)

---

## 💡 Pro Tips

### Performance Optimization
```
- Enable object caching (Redis, Memcached)
- Use transients for expensive operations
- Limit tool selection per assistant
- Optimize base knowledge files
- Monitor API usage and costs
```

### Security Best Practices
```
- Never commit API keys to version control
- Use environment variables for secrets
- Limit guest access to specific assistants
- Review and rotate credentials regularly
- Enable rate limiting for public endpoints
```

### Cost Management
```
- Start with gpt-4o-mini model
- Monitor token usage via dashboard
- Set up usage alerts in OpenAI
- Cache responses where appropriate
- Use prompt shortcuts to reduce typing
```

---

## 🆘 Getting Help

### Quick Start Resources
- **Getting Started Wizard** ⭐ NEW — Activate and follow the 4-step setup at **NV oOS → Getting Started** to create your first assistant in under 2 minutes
- **[Use Cases & Quickstart Guides](getting-started/USE_CASES_AND_QUICKSTARTS.md) ⭐ NEW** - 7 major use cases with step-by-step guides
- **[5-Minute Quick Start](getting-started/QUICK_START_5_MINUTES.md)** - Get started immediately
- **[Documentation Index](DOCUMENTATION_INDEX.md)** - Complete documentation map

### Support Channels
1. **Documentation** - Check [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)
2. **Troubleshooting** - See [deployment-troubleshooting.md](getting-started/installation-setup/deployment-troubleshooting.md)
3. **GitHub Issues** - https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
4. **Community** - Follow contribution guidelines

### Before Asking for Help
- [ ] Check documentation
- [ ] Enable logging and review errors
- [ ] Test with default assistant
- [ ] Verify API keys are correct
- [ ] Check plugin/theme conflicts
- [ ] Review GitHub issues for similar problems

---

**Need more detail?** See [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) for complete documentation map.

**Maintained by:** NV Digital Solutions  
**License:** GPLv3 or later
