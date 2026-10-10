# NV oOS Quick Reference Guide

**Version:** 1.2.3
**Last Updated:** October 10, 2026

This quick reference provides fast access to the most common tasks and commands for Open Operator System.

## Recent Updates (October 2026)

- **v1.2.3** (October 10): CORS same-origin enforcement, WordPress login for the standalone SPA & the SPA REST auth fixes. **CORS guard (PR #6986)** — core `rest_send_cors_headers` reflects any Origin back with credentials, silently no-op'ing the "Same Origin" setting; the 12th security class `WP_MCP_AI_CORS_Guard` (priority 20) replaces the reflection in `site` mode (exact-allowlisted origins echoed — site origin + the new `cors_allowed_origins` textarea + the `wp_mcp_ai_cors_allowed_origins` filter; everything else gets the site origin with credentials off; scope `/mcp-ai/v1` by default, widen via `wp_mcp_ai_cors_guard_route_prefixes`). **Standalone SPA WordPress login (#6993, Proposal 064)** — the fourth auth mode via an Application Password (Basic auth over REST; `wp/v2/users/me?context=edit` validation; real user + capabilities mounted; the Basic header attached only to the site origin). **Fixed** — the OOS SSE stream's missing CORS headers (every cross-origin browser chat stream was blocked — `emit_stream_cors_headers()`; `lib/core` stays framework-agnostic) (#6993); transcripts/approvals accept a pure assistant credential (reads scoped to the issuing assistant; `approve`/`deny` stay `manage_options`) + the session nonce re-binds the auth cookie (#6990 — closes #6987, #6985); Pro Schedule Manager multi-recipient `notify_email` (comma/space/semicolon, per-address validation — #6991, closes #6642; the tool-status CRM slugs + pagination fixture fix fold in); the SPA's cookie-mode proxy detection + JSON guards, zero-dependency `scripts/serve.mjs` production proxy, and `React.lazy` chunk splitting (501 KB → 196 KB entry) (#6994); `.env` loading with shell > `.env` > docker-default precedence (#6996); three npm package dists were invalid ESM — all 23 rebuilt + `examples/nvoos-vite-demo/` (#6984; the alpha.4 publishes deferred pending npm auth). **Docs** — the CORS/Velocity deploy guide (#6989) + the open-issues triage snapshot (#6992). **Build** — spa-standalone `git init -b main` (#6988) + the deploy tree under `examples/` (#6995). Tool count: ~353 base + ~1,339 Pro (~1,692 total; unchanged). Security classes: 11 → 12. Stale 1.2.1 build ZIPs removed (30 files). See `docs/project/plans/1.2.3-docs-catch-up.md`.

- **v1.2.2** (October 10): chat profiles read-only mode, F&B assistant packs & Google OAuth production readiness. **Chat profiles (PR #6977, Proposal 015)** — `write` + `read-only` built-ins resolved server-side per request (user meta → site default → write fail-safe — a client can never widen its own grant; client-sent profiles honoured only for `wp_mcp_ai_change_chat_profile` holders; guests default to read-only); the 11th security class `WP_MCP_AI_Read_Only_Profile_Gate` (priority 0, before the destructive-ops gate — deny → ask → allow; 403 envelope + audit events); `GET/POST /mcp-ai/v1/chat-profile` deliberately not a tool (no mid-run self-elevation); `wp mcp-ai chat-profile` WP-CLI + Pro SPA v2 selectors; Phase D gaps documented. **F&B assistant packs A1–A4 (#6982, Proposal 062 Phase 4)** — four importable `nvoos-assistant` bundles + `wp mcp-ai pro fnb seed-assistants` (READ + DRAFT tiers, ACT off, `--include-image-tools` opt-in). **Google OAuth production readiness (#6981)** — `drive.file` unification behind a filter (off the CASA surface), Calendar Minimal default, upstream revocation on disconnect. **Fixed** — Pro SPA v2 default-assistant dead reference + non-admin visibility (#6974), embedded sidebar toggle + live session storage (#6976), workflow-builder TS parity + full JS rebuild (#6979), F&B oracle drift (#6978), CG-AI evolved-prompt isolation (#6980). **Build** — SPA tag-release flow (#6970) + demo-videos manual-only (#6972). **Sub-projects** — docs-hub 0.5.2 → 0.5.3 (PCP 0 blocking errors; live on the wp.org directory) and content-graph 1.0.9 → 1.0.10 (excluded sources now leave the graph; run Rebuild Graph once). **Docs** — Proposal 063 (PhreshOS + Cloudways Velocity) added; five coding-time skills updated in place. Tool count: ~353 base + ~1,339 Pro (~1,692 total; unchanged). Stale 1.1.99 build ZIPs removed (34 files). See `docs/project/plans/1.2.2-docs-catch-up.md`.

- **v1.1.99** (October 7): SPA UI stack, gateway OAuth 2.1 & the Docs Hub Playground. **SPA UI stack enhancement (#6952, Proposal 060)** — toolkit-shell 0.2.0 → 0.3.0 (Radix primitives, TanStack TableView, @dnd-kit Kanban, RHF+zod FormView, sonner, 14 `--nds-*` tokens, dual-React guard) and schedule-anything-spa → 0.2.0 (Tailwind v4, shadcn-style kit, @xyflow/react v12, code splitting, i18n, a11y gate); shipped suites 31/31 + 23/23. **Gateway OAuth 2.1 (#6945, Phase 1)** — RFC 9728 metadata + `WWW-Authenticate` challenges, zero-dep JWT validation (fail-closed), scope-based site binding, no token passthrough; inert until `GATEWAY_OAUTH_ISSUER`; Phases 2–5 deferred. **Docs Hub Playground (#6944/#6946–#6949)** — local-first blueprints, wp.org Live Preview with the self-activate fix, the 10-page wiki seed, the full-page `/docs/` SPA demo, and the badge. **Gateway listing prep + Claude Code plugin (#6941–#6943)** — GPL LICENSE + directory-submission doc, the icon, `.claude-plugin` + `/connect`. **Figma skills catalogue (#6938)** — `figma/mcp-server-guide` default source + name-dedupe. **Fixed** — Gmail body extraction (#6939); the Complete ZIP's 0-of-65 blueprint stripping (#6936/#6940, root-anchored + CI guard); worker synthetic external targets + split-brain fix (#6941); Fleet Operator token masking (#6950); the docs-hub rsync build abort (#6951). Tool count: ~352 base + ~1,313 Pro (~1,665 total; unchanged). Stale 1.1.97 build ZIPs removed (6 files + the superseded toolkit-shell v0.2.0 ZIP). See `docs/project/plans/v1.1.99-docs-catch-up.md`.

- **v1.1.98** (October 6): Figma-to-Elementor pipeline, Unified Blueprints fixes & the dependabot sweep. **Figma-to-Elementor design-to-build pipeline (#6934, Proposals 057–059 — docs + skill, no PHP)** — the 7-stage agentic pipeline (Scope → Read → Plan → Tokenize → Build → Verify → Handoff) rides the shipped MCP Apps / OAuth / A2A / per-toolkit MCP server machinery (Figma MCP read side, Elementor MCP write side; single-assistant + split topologies; normative rules — `get_design_context` first, tokenize-before-build, drafts only, never publish, Figma read-only; build-manifest contract + JSON Schema). The new bundled skill `design-figma-to-elementor` ships with it (**coding-time 62 → 63, base bundled 75 → 76**) + the user guide; Proposal 058 (DTCG token import) is the follow-up, 057 parked as reference, Phase 3 demo/verify deferred on live endpoints. **Fixed (#6933)** — the Unified Blueprints page no longer ships empty (root-anchored `examples` exclusions — all 65 blueprint JSONs were being stripped from release builds) and now gates blueprints on toolkit enablement (AJAX handlers reject disabled toolkits). **Gateway sync (#6927)** — `workflow_dispatch` trigger + version guard against stale promotions; `addons/mcp-gateway/CHANGELOG.md` tracks 0.1.0 → 0.1.1. **Dependabot sweep (#6931)** — 138 of 166 open npm alerts resolved via bounded in-major bumps across 17 trees (axios, brace-expansion@^1, source-map-js, dompurify, proxy-addr, compression, joi, katex, moment, simple-git + argv-parser, basic-ftp, postcss-selector-parser@^7, webpack-dev-middleware); 28 deferred in #6930; dashboard dismissed (138 `fix_started` + 28 `tolerable_risk` → 0 open). Tool count: ~352 base + ~1,313 Pro (~1,665 total; unchanged). Stale 1.1.96 build ZIPs removed (30 files). See `docs/project/plans/v1.1.98-docs-catch-up.md`.

- **v1.1.97** (October 6): ECC-inspired harness enhancements, OOS parity closure & gateway protocol negotiation. **Agent-harness enhancements (#6912, Proposal 056 — inert by default)** — cascade model routing on both engines (framework-core `CascadeRouter` + legacy `WP_MCP_AI_Cascade_Executor` gate, one switch + Pro Jev classifier), the `run_assistant_eval` + `scan_assistant_security` base tools, hook profiles (minimal/standard/strict), the session distiller's canonical `wp_mcp_ai_memory_stored` event, and the Pro `suggest_workflows_from_history` trace-store miner. **OOS parity gaps closed (#6913)** — the destructive-ops gate no longer 500s on the OOS path (canonical 428/429 envelopes), the Output Guardrail + Citation Verifier apply at the final payload on all three surfaces, and `bin/check-chat-parity.php` (35 → 38 features) gains a `parity-check` CI job. **Fixed (#6914)** — the MCP Gateway negotiates protocol versions (`2024-11-05` fallback), so Zed and other strict clients connect. **npm (#6898/#6904–#6909/#6911)** — all 23 `packages/` live on the public registry (bridge `latest` = 0.1.0-alpha.3; nvoos-api/nvoos-sse-client 0.1.0-alpha.4 with valid JS). Tool count: ~352 base + ~1,313 Pro (~1,665 total; +2 base +1 Pro). Stale 1.1.95 build ZIPs removed (30 files). See `docs/project/plans/v1.1.97-docs-catch-up.md`.

- **v1.1.96** (October 5): NV oOS MCP bridge npx package, MCP Gateway addon & per-request memory cut. **nvoos-mcp-bridge (#6897, Proposals 054/055)** — the zero-dependency `@nvdigitalsolutions/nvoos-mcp-bridge` npm package connects Zed / Claude Desktop / Cursor / Codex to any NV oOS site with one `npx` command (`nvoos-mcp` stdio↔HTTP relay + `nvoos-mcp-ssh` for SSH-only sites; byte-identity CI sync from `bin/`); the Fleet Operator addon's `generate_zed_json()`/`generate_claude_json()` emit copy-paste `context_servers`/`mcpServers` blocks alongside the Hermes YAML. **MCP Gateway addon (#6897, 0.1.0)** — a public fleet MCP endpoint (streamable HTTP, MCP 2026-07-28) with public API-key auth + rotation, per-key rate limits, `<site-slug>.<tool>` namespacing, and fail-closed env config. **Per-request memory cut + bootstrap hardening (#6896)** — plain front-end requests no longer load ~350 tool classes (lazy registry via `wp_mcp_ai_is_plugin_runtime_context()`, third-party-wins slug conflicts), the OOS engine pre-warm is gated off page views, the model catalog is read on `filemtime` change only, Pro blob options write `autoload=false` with a one-time repair, and the bootstrap-integrity guard + fail-soft loader make partial updates degrade instead of fataling (A/B probe: 42 MB vs 74 MB per front-end render). **Fixed (#6895)** — the media worker's runtime version strings report 3.4.0 again. Tool count: ~350 base + ~1,312 Pro (~1,662 total; unchanged). Stale 1.1.94 build ZIPs removed (30 files). See `docs/project/plans/v1.1.96-docs-catch-up.md`.

- **v1.1.95** (October 4): Toolkit hardening wave, real image processing, toolkit labels & SSE keepalives. **Real image processing (#6881, issue #6877 — Media Worker 3.3.0 → 3.4.0)** — the four placeholder image tools now run real processing through the worker sidecar (Wave 1 Sharp-native `POST /api/image/enhance` + `/api/image/upscale` behind the new Pro `WP_MCP_AI_Sharp_Image_Processing` trait; Wave 2 `POST /api/image/edit` colorize/style_transfer behind the new `WP_MCP_AI_Provider_Image_Edit` trait) — success meta written only on real success. **Nine-toolkit hardening wave (#6875–#6893)** — confirmed fatals fixed across document-generation, image-production, CRM, ECA, ecommerce, media (**`year_month` traversal closed**), healthcare, shopify-sync, orchestration, and the loose incident tools + shared services (all mirrors byte-identical to CG Pro where they exist); email-marketing verified clean. **Toolkit labels (#6874)** — `list_mcp_tools` derives toolkits via the new `get_tool_toolkit()` resolver + `wp_mcp_ai_tool_toolkit` filter; the Newsletter bootstrap fatal fixed. **SSE keepalives (#6879)** — inter-step comment frames keep long agentic chat streams alive through proxies. **New skill `mcp-ai-wpoos-toolkit-audit`** (62 skills, #6875). Tool count: ~350 base + ~1,312 Pro (~1,662 total; unchanged). Stale 1.1.93 build ZIPs removed (30 files). See `docs/project/plans/v1.1.95-docs-catch-up.md`.

- **v1.1.93** (October 3): Fleet status monitoring, WP-CLI parity, FlowHub MCP Apps & the October model catalog. **Media worker fleet status monitoring (#6849, worker 3.2.0 → 3.3.0)** — opt-in `STATUS_ENABLED=1` status module (heartbeat validation, `at_risk` → `major_outage` state machine, dead man's switch sweeper, synthetic checks through the SSRF guard, HMAC-signed alerts, OpenMetrics, public status page) plus the plugin half: heartbeat emitter, alert poller, `remote_monitor` source, **2 new base tools** (`get_fleet_status`, `get_site_uptime`), `GET /mcp-ai/v1/status/sites`, `/status fleet` slash sub-command, and the Pro Status Dashboard fleet section. **WP-CLI parity & hardening (#6852, Proposal 050)** — `includes/cli/` extraction, `manage_options` gating on every mutating subcommand, `wp mcp-ai tool call <slug>`, new `security`/`model` Base commands + nine Pro command trees (`crm`, `incident`, `maintenance`, `schedule`, `workflow`, `vault`, `remote-site`, `communication`, `media-studio`), canonical `mcp-ai` namespaces with legacy aliases, and `docs/operations/wp-cli.md`. **FlowHub MCP Apps (#6854/#6861)** — FlowHub MCP connections join the assistant MCP Apps dropdown with OAuth login and their connection proxy now carries into every MCP Apps gateway request. **Financial Planner OpenStock parity (#6857, Proposal 051)** — Finnhub BYO-key provider at the yfinance filter seam, **+3 Pro tools** (`watchlist_sync`, `market_overview_widget`, the orphaned `import_financial_planning_blueprint` registered), price-alert data-mode parity, analyst blueprint. **October model catalog (#6863)** — v2026.10.03, 238 models, **18 providers — Z.AI joins**, new GPT-6/Claude-5.5/Gemini-3.8 models, refreshed defaults. **Fixed (#6853/#6855/#6856/#6858/#6859)** — Media Studio `/ai/generate` 500 (transforms pass the resolved model; validation failures now HTTP 400; Media Studio 0.6.1), schedules no longer trigger on create/save, `aspect_ratio: 'auto'` passes the validated Gemini tools. **Advisories (#6850)** — undici 7.30.0 across 8 addon lockfiles. Tool count: ~349 base + ~1,312 Pro (~1,661 total; +2 base +3 Pro). Stale 1.1.91 build ZIPs removed (6 files) + superseded media-studio/saas-controller addon ZIPs (5 files). See `docs/project/plans/v1.1.93-docs-catch-up.md`.

- **v1.1.91** (October 1): FlowHub MCP mode, MCP App OAuth discovery & OAuth redirect allowlists. **FlowHub Connection MCP mode (#6836)** — a `flowhub_mode` selector on FlowHub Remote Sites connections (MCP mode designates the connection as the FlowHub toolkit MCP server backend) with proxy inheritance: `get_mcp_connection_id()` resolves the first enabled MCP-mode connection and the toolkit MCP REST controller injects its ID only when the caller passes none — MCP-triggered refresh/sync calls route through the explicit-connection path (decrypted credentials + the connection's encrypted proxy via `http_api_curl`); explicit `connection_id` always wins; generic seam via `WP_MCP_AI_Toolkit_Server_Base::get_mcp_connection_id()`. **MCP App OAuth discovery per MCP spec (#6835)** — the full discovery chain (RFC 8414 + §3.2 path insertion, RFC 9728 protected-resource metadata trying every `authorization_servers` entry, 401 `WWW-Authenticate` probe, OIDC + WordPress REST fallbacks) with both-endpoints validation, per-attempt diagnostics surfacing real transport errors, `default_scope` honored, and 10 s per-probe caps. **OAuth redirect allowlists (#6831/#6832)** — LinkedIn, QuickBooks, Mailjet, and Yahoo connect buttons no longer bounce to wp-admin. **JSON envelope protection (#6827)** — orchestration CCTs gate on the physical table (`is_storage_ready()`: table + every required column) and the new `WP_MCP_AI_Db_Output_Guard` wraps central tool dispatch so no surface leaks DB error HTML into a JSON response. **Fixed (#6829/#6830)** — the Security events table + CSV exporter read canonical `event_type`/`ip_address` keys; the orchestration dashboard no longer renders twice. **Dependency advisories (#6833/#6834)** — nodemailer ≥10.0.9 (pro + media-worker) and the fast-uri ≥4.1.5 override floor (4 trees each). **Ecosystem port (#6825)** — the RF-DETR cluster ports byte-identical into `nvoos-content-graph-pro` (Wave F3 sub-cluster 1). Tool count: ~347 base + ~1,301 Pro (~1,648 total; unchanged). Stale 1.1.89 build ZIPs removed (30 files). See `docs/project/plans/v1.1.91-docs-catch-up.md`.

- **v1.1.90** (September 30): RF-DETR vision cognition, strict MCP scope & memory identity closure. **RF-DETR cognition enhancement (Proposal 049, #6824)** — a self-hostable Apache-2.0 SOTA detector behind one Pro HTTP client (`WP_MCP_AI_Roboflow_Inference_Service`, three trust tiers: key-less self-host loopback/private, dedicated, Serverless Cloud API with fail-closed raw-Authorization credentials and SSRF-guarded endpoints); **two new Pro tools** (`rfdetr_detect` boxes/masks/keypoints, `rfdetr_catalog_search` fine-tuned catalog models with a per-model + dHash 5-min cache); the `roboflow` provider joins `analyze_image_objects` (no new slug); `identify_image` rung 3c reports `rfdetr_detections` (class-guarded, Base skips cleanly); XL/2XL behind a PML consent toggle. **Upwork MCP as first-class MCP Apps references (#6823)** — Upwork-in-MCP-mode connections appear in the assistant "Add from Remote Sites" dropdown with the full OAuth login flow, resolve at chat time via the encrypted central `mcp_oauth` store, and survive the post-login reload. **Strict MCP assistant-scope toggle (#6819)** — opt-in `mcp_require_assistant_scope` (default off) fails `tools/list` + `tools/call` closed with HTTP 403 when no assistant resolves. **Memory identity + IDOR closure (#6815)** — the eight memory tools resolve the caller's `agent_id` from the execution context, cross-agent access is gated behind `manage_options` (403), stored contexts gain a credential-pattern scan, retrievals carry expiry signalling. **Letterhead personalization (#6816)** — `Dear {{to_name}}` + conditional blocks; template reads prefer the direct filesystem transport. **Dependency advisories (#6817)** — js-yaml ≥5.4.1 + webpack-dev-middleware ≥8.3.0 across 13 trees (15 of 17 alerts; AI SDK migration tracked as #6818). New coding-time skill `mcp-ai-wpoos-dependabot-loop` (61 skills); the plugin skill slims under 100KB with a RELEASE-NOTES.md companion. Tool count: ~347 base + ~1,301 Pro (~1,648 total; +2 Pro). Stale 1.1.88 build ZIPs removed (30 files). See `docs/project/plans/v1.1.90-docs-catch-up.md`.

- **v1.1.89** (September 29): Google Classroom ECA, Design System rename + email templates, and Upwork MCP mode. **Google Classroom integration (Proposal 046, #6809)** — a shared `includes/google/` Classroom foundation mirroring the Calendar stack (restricted scopes, `RESOURCE_EXHAUSTED` backoff, `@MissingGrant`, Pub/Sub push with shared-secret verification), a `google_classroom` Remote Sites connection type, **12 new ECA tools** (courses, rosters, announcements, coursework, submissions, guardians, analytics, push watch) with a nightly jittered sync engine — all behind `enable_eca_classroom_integration` (default off); new base REST route `mcp-ai/v1/google-classroom/webhook`. **Design System addon rename + token-driven email templates (#6810)** — `addons/crocoblock-ds/` → `addons/nvoos-design-system/` (**0.1.0 → 0.3.0**, zero-breakage shims), 5 built-in accessible templates, global `wp_mail` wrapper, WCAG/EMC audit gates, multipart `AltBody`, opt-in WooCommerce rebrand, Paper Store bridge, **8 admin-gated `nds_*` tools** (addon-provided, not counted), and two latent addon bugs fixed. **Upwork MCP mode (#6804)** — third `upwork_mode` against the official Upwork MCP gateway via a sessionful bridge, `org_uid` resolution, encrypted-token OAuth (`connection_ref`). **Fixed (#6805)** — vision attachments via the `input_image` segment path with native DeepSeek vision, canonical remote-connection slug mapping + actionable 404s, EZuite `item_code` enforcement, per-provider model-setting migration; **handshake cache (#6802)** — Upwork Test Connection ~24.9s → ~8.5s. **Security (#6807/#6806)** — 0 open CodeQL alerts + ip-address/undici/multer patches. Tool count: ~347 base + ~1,299 Pro (~1,646 total; +12 Pro). Stale 1.1.87 build ZIPs removed (30 files). See `docs/project/plans/v1.1.89-docs-catch-up.md`.

- **v1.1.86** (September 25): MCP Server connections, Higgsfield media, log filters & delivery templates. **MCP Apps as a Remote Sites connection type (#6761, Proposal 041)** — new `mcp_server` connection type with AES-256-CBC-encrypted central credentials, a real JSON-RPC handshake Test Connection, tool discovery with persisted snapshots, restricted-host enforcement, and auth mapping onto MCP (Elementor application passwords → `basic`); per-assistant `connection_ref` reference mode resolves credentials decrypt-on-use at chat time (never written back to post meta) with auto-disable on missing import refs; assistant export/import redacts MCP App `token`/`oauth_data` by default. **Four new Higgsfield base tools (#6772, Proposal 042)** — `generate_higgsfield_video` (Cinema Studio 4.0, Seedance 2.5/2.0, Wan 3.0, Kling 3.0 behind one `model` param), `generate_higgsfield_image` (SOUL V2/SOUL Cinema), `check_higgsfield_request`, `cancel_higgsfield_request` — on a shared submit/status/cancel client (two-part `Key ID:SECRET` auth, backoff+jitter polling, immediate download); also fixes the TypeSafe tool schemas + Pro coverage manifest. **`get_system_logs` filters (#6768)** — optional `since`/`levels`/`search` filters over the structured buffers + file logs with a filters summary and per-file `filtered_out` counts; `parse_since()` is the shared canonical parser. **Action-items template + smart excerpts (#6773)** — scheduled digests send only the actionable section (`action_items`) and prefer the response's own Summary/TL;DR/action sections over blind 80-word trims. **Tool costs in final labels (#6771)** — top-level `toolResult.cost` envelope on the agentic loop + SSE streaming with tool-bubble badges. **Environment status fixed (#6767)** — always-on "no assistants published" warning + dead default-assistant branch repaired; `plugin.default_provider_model` resolves the effective per-provider model. Tool count: ~312 base + ~1,282 Pro (~1,594 total; +4 base). Model catalog: v2026.09.22 (unchanged). Stale 1.1.84 build ZIPs removed (6 files). See `docs/project/plans/v1.1.86-docs-catch-up.md`.

- **v1.1.84** (September 22): TypeSafe Jev enhancement wave. **API fidelity (Phase 0, #6747)** — noul criteria + structured EntryType fields with a recursive two-gate sanitisation walk; bounded 429/5xx retries honouring `retry-after` on the native client + OpenRouter decisions bridge (4xx/transport never retried; the bridge defaults to `typesafe/jev-1.13`); an opt-in advisory decision cache (`enable_typesafe_cache`, zeroed usage on hits, endpoint/base/model/payload keying); a `typesafe_endpoint` override; the `jev-preview` alias; `min_confidence`/`weights`/advisory token warnings + usage aliases on `typesafe_decide`. **New base tool `typesafe_guardrail` (Phase 1, #6747)** — one noul question per hazard category → advisory pass/review/block verdicts; new bundled skill `mcp-ai-wpoos-jev-decisions` (bundled skills 74 → 75 base). **Pro Jev integrations (Phase 2, #6747)** — opt-in fail-open guest-chat guardrail (`enable_jev_guest_guardrail` on the Layer I pre-chat filter), advisory citation checking on `generate_research_report`/`research_eca`, and three new `manage_options`-gated tools `typesafe_rerank`/`typesafe_eval`/`typesafe_skill_select`. **Capability fix (#6745)** — `typesafe_decide`'s declared capability matches its enforced admin gate (metadata-only, CI-pinned). **Proposal + plan 040** ship with the wave (#6746); extraction tools, the CG port cluster, and NV Cloud passthrough deferred. Tool count: ~308 base + ~1,282 Pro (~1,590 total; +1 base +3 Pro). Model catalog: v2026.09.22 (+`jev-preview`). Stale build ZIPs: none removed this pass (1.1.83 set retained as current). See `docs/project/plans/v1.1.84-docs-catch-up.md`.

- **v1.1.83** (September 21): Tool guidance, Jev decisions, Pro bootstrap hardening, and Upwork-workflow release. **Tool Description Engineering** — every base+Pro tool class (~1,487 tools; 1,584/1,584 files clean) now carries model-facing usage guidance: a new `WP_MCP_AI_Tool_Usage_Guidance_Interface` (when_to_use / when_not_to_use / related_tools / notes) assembles a compact `[Usage: …]` suffix on the model-facing payload, legacy-format classes opt in through the legacy tool wrapper, and the `WPMCPAI.Tools.ToolDescriptionGuidance` sniff is enforced at severity 5 so new tool classes without guidance surface on PRs (#6686, #6695, #6687–#6723; post-window close-out for webchat + CG Pro member mirrors #6740). **TypeSafe Jev decision provider** — a first-class decision provider deliberately separate from chat: typed choice/score/noul decisions in 70–500 ms via a new decision-client contract + OpenRouter Decisions bridge + the new base tool `typesafe_decide` (canonical envelope, adversarial-state caveat); Pro cascade routing (`jev_routing`) + opt-in research source filter (#6728). **"The Assistant Builder" meta-assistant** seeds on activation (default roster 6 → 7, #6727). **ID-handoff data contracts** — every ID-bearing tool family (post, cron, term, assistant, vector store, batch, Pro schedule, toolkit_cpt, medical record, plan, calendar, WPCode, session, member, webchat room) declares produces/consumes handoffs with permanent manifest-driven honesty + round-trip suites (#6729–#6739). **Opt-in adaptive tool cap** (`wp_mcp_ai_adaptive_tool_cap` + per-assistant override) + **lazy schema loading** (`tool_slug`/`include_schemas` on `list_mcp_tools`, #6686). **Pro bootstrap incomplete-install guard** — partial Pro deploys degrade to an admin notice instead of a site-wide fatal (#6677). **Telegram auto-chunking** — `send_telegram_message` splits >4,096-char messages at paragraph → line → hard boundaries (`chunk=false` restores legacy single-send, #6677). **Upwork pipeline** — web_search-mode + API credential gating (#6678), category-page dropping + SERP budget/type/recency extraction + always-on broad second pass (#6679/#6680), `sort`/`location` args, and workflow deliveries ship the **full 50-item result set** with per-item URLs rendered as one properly numbered list (#6682/#6684); steps render as a compact execution log (#6679). **Gmail `connection_id: "settings"`** resolves to the settings fallback; skill/OKF errors append the available options (#6677). **Usage Monitor sub-tab saves again** — dashboard handler applies the monitor bridge filter (#6726). **Webchat fixes** — `log_activity()` latent fatal → `log_event` (#6738); SQL prepare warnings fixed at the root (#6740). **Playground Ollama demo** — permalink seed fix ends the fresh-install landing 404; local `npx` is the primary test path; capture harness rewritten (#6683). **npm advisories closed** (adm-zip 0.6.1, js-yaml 4.3.2, colord 2.10.0, #6681). **Canonical envelope migrations** — five regulatory tools (#6689) + `format_code_prettier` (#6737). Tool count: ~307 base + ~1,279 Pro (~1,586 total; +1 base — `typesafe_decide`). Stale build ZIPs removed: the 1.1.81 set (30 files), then the 1.1.82 set (30 files) after the 1.1.83 packages rebuilt. See `docs/project/plans/v1.1.83-docs-catch-up.md` + `docs/project/plans/v1.1.83-post-docs-catch-up.md`.

- **v1.1.82** (September 18): Playground demos, Pro SPA, and hardening release. **Two one-click WordPress Playground demo blueprints** — the Content Graph demo ("Project Asteria": seeded 26 posts / 5 pages / 3 true orphans, deterministic build via the public `nvoos_content_graph/initial_build` hook, 49 nodes / 255 edges / 5 communities, #6662) and the **NV oOS Complete × local Ollama** demo (Playground runs WordPress in the browser so the plugin's `localhost:11434` endpoint is the user's machine — provider pre-wired, Oma assistant, Test Lab page with the `[ollama_status]` banner + embedded Pro SPA, #6663). **Blueprint pin auto-discovery** — the generator picks the newest Complete bundle ZIP and the build workflow regenerates the blueprint with every build (#6674); `cron_monitor="0"` keeps the demo's Pro SPA worker-safe (#6666); README demo button (#6673); new `docs/user-guides/playground-demo.md` walkthrough (#6671). **Pro SPA fixes** — `[nvoos_pro_spa cron_monitor="0"]` no-ops the blocking SSE cron-status stream + REST poll for constrained hosts (#6665), and embedded mode seeds the model store from the assistant's real config instead of hardcoded `gpt-4o` (#6672). **Token-tracking table hardening** — verify-then-version, hourly retry backoff, quiet failure, graceful reads for SQLite-backed environments; ported 1:1 to nvoos-content-graph-ai (#6669). **Result-delivery dedupe** — `delivery_safe_data()` strips the duplicated response copy + `assistant_id`/`is_agentic` metadata; summary/SMS prefix dedupe (#6661). **`[ollama_status]` banner unfrozen** — footer-enqueued checker replaces the texturize-mangled inline script (#6668). **Portability coverage guards repaired** — preset entries + 9 AJAX tests + regenerated manifests (#6645). **Skills** — new 59th coding-time skill `mcp-ai-wpoos-playground-demos` (#6664); updates skill gains the PR deferred-item sweep Track C (#6656). **Docs Hub 0.4.7** — third wp.org reviewer pass, 0 blocking Plugin Check errors (#6659/#6667). Tool count unchanged: ~306 base + ~1,279 Pro (~1,585 total). Stale build ZIPs removed: the 1.1.80 set (30 files) + the superseded docs-hub 0.4.6 ZIP. See `docs/project/plans/v1.1.82-docs-catch-up.md`.

- **v1.1.81** (September 17): Shopify-UCP, FlowHub, CRM & financial-resilience release. **Shopify tools are UCP catalog mode-aware** — storefront/global connections drive live `search_catalog`/`lookup_catalog`/`get_product` queries (buyer `context`, cursor passthrough, clamps 250/50/10, zero caching) and admin-only tools refuse catalog connections with an actionable hint (#6634); every product-returning path ships **image cards** (`images[]` + markdown, 10-card cap) via a shared normalizers trait (#6638). **FlowHub resolves Remote Sites connections** — a shared resolver chain (explicit `connection_id` → toolkit settings → sync connections → first enabled) ends the "credentials are not configured" failure (#6635), and live requests honor the connection proxy (#6637). **JobNavigator CRM adoption** — 5 new Pro tools (`bulk_move_deal_stages`, `record_crm_reply`, `get_crm_handover`, `get_pipeline_digest`, `create_tracked_link`) + deal stage history, lead dedup + canonical companies, reply signals, won-deal lead release (#6636; CG Pro port #6640). **Gmail reply poller** classifies inbound replies on cron with optional stage advancement + a pipeline-digest scheduling recipe (#6641). **OpenTerminal financial resilience** — 8 new Pro tools (`market_screener`, `macro_data_fetcher`, `economic_calendar_fetcher`, `earnings_calendar_fetcher`, `options_chain_fetcher`, `crypto_market_data`, `portfolio_transaction_log`, `price_alerts`), fallback chains + stale-while-revalidate caching, keyless auth, technical indicators (#6639). **Result Delivery email accepts multiple recipients** — sanitized, deduped, fanned out via Nodemailer + `wp_mail` (#6643). Tool count: ~306 base + ~1,279 Pro (~1,585 total; +13 Pro). Stale build ZIPs removed: the 1.1.79 set (30 files) + superseded docs-hub 0.4.3/0.4.4/0.4.5 ZIPs. See `docs/project/plans/v1.1.81-docs-catch-up.md`. **Assistant export/import across all surfaces** — a canonical engine powers `nvoos-assistant` JSON bundles (v1) from WP-CLI, REST (`POST /mcp-ai/v1/assistants/export|import`), an admin Import/Export page, and 3 new base tools (`export_assistant`, `import_assistant`, `duplicate_assistant`) + a Pro `export_assistant_blueprint`; credential hashes never exported, stripped on import (#6628). **Security Center Usage Monitor sub-tab** — violation triage log, editable monitor config, REST clear routes; the admin notice deep-links and shows the latest violation; sanitize-clobber bug + malformed-pattern hardening fixed (#6632). **Shopify UCP modes** — keyless Storefront + Global Catalog replace the deprecated REST Catalog API (public `/ucp/agent-profile` route, CCT sync rejected) on Pro + CG Pro (#6624/#6630); REST Catalog 401s fixed (60-min token cap, scope validation, purge-and-retry) + JetEngine sync gate unified with System Status (#6623). **WP-CLI repaired + streaming** — `provider list`/`chat` no longer fatal on PHP 8+, `chat --stream` streams token-by-token (#6625/#6626). **WhatsApp webhook self-tests** on Remote Sites (#6622). **OKF editor keeps context on save** (#6631). **Skills** — agent skills verified against the real plugin surface + new `design-brand-assistant-provisioning` skill (#6627); template WPCS clean (#6629); docs-hub syntax/anchor colors (#6621). Tool count: ~306 base + ~1,266 Pro (~1,572 total; +3 base +1 Pro). Stale 1.1.78 build ZIPs removed (30 files). See `docs/project/plans/v1.1.80-docs-catch-up.md`.

- **v1.1.79** (September 13): Checkout-hardening-tail & security release. **Content Graph 1.0.8** — Stripe Payment Element billing-address mode switched from `never` to `auto`, so non-EU purchases no longer die client-side with `IntegrationError`; `/payments/session` refuses chargeable sessions when the site is already licensed (double charges impossible). **Checkout API 0.1.2** — buyers now receive their license by email (key/product/site/amount) from both the webhook and `/verify` paths, guarded by an `email_sent_at` column (DB v4 → v5); statement-descriptor 424 fixed via `statement_descriptor_suffix` (#6613); Stripe account-switch create-product fix (#6611). **Imaging symlink hardening** — study deletion removes links as links and never follows them, with realpath containment per entry and new audit events (#6616). **Docs Hub 0.4.5 → 0.4.6** — second wp.org reviewer pass + full 18-guideline pass, 0 blocking PCP errors (#6615/#6617). **Content-graph wp.org readiness** — seller-of-record copy, price note, packaging tri-sync (#6609/#6612/#6619). **Toolkit slash test repair** (#6618). Tool count unchanged: ~303 base + ~1,265 Pro (~1,568 total). Stale 1.1.77 build ZIPs removed. See `docs/project/plans/v1.1.79-docs-catch-up.md`.

- **v1.1.78** (September 12): Slash-command, model-restoration, checkout-hardening & legal-consolidation release. **Slash commands reworked as declarative tool wrappers** — ~76 placeholders purged, 36 commands across 15 toolkits execute through the real tool registry via a new tool adapter (capability gates + canonical envelope live in the tool layer) and a new prompts bridge exposes every command as a `slash.*` MCP prompt template; all 19 built-in workflows re-chained (#6604). **DeepSeek V4 Pro restored** — active again across all tracks ($0.66/$1.98, migration map unmapped); Content Graph AI mirror bumps to catalog v2026.09.10 with corrected v4-pro pricing (#6608). **Checkout hardening** — assets cache-bust by file mtime (`Schema::assetVersion()`, #6598); modal price syncs from the vendor session with a $34.99 fallback default (#6603); minimalist modal restyle (#6597). **Docs Hub 0.4.3 → 0.4.4** — all wp.org review findings fixed, 0 blocking PCP errors (#6606). **Legal consolidation** — unified ToS (#6599), aligned API-LICENSES (#6605), two-entity seller model (NV Digital Unlocked LLC sells; NV Digital Solutions develops, #6607). **Content Graph 1.0.7 released** with wp.org 18-point sign-off (direct commits). Tool count unchanged: ~303 base + ~1,265 Pro (~1,568 total). Stale 1.1.76 build ZIPs removed (30 files). See `docs/project/plans/v1.1.78-docs-catch-up.md`.

- **v1.1.76** (September 10): Scheduled-delivery, checkout-launch & ecosystem-port release. **Chat delivery full report + per-channel formats** — all chat channels support the `full` template with a per-channel `format` selector (Telegram `html`/`markdown`/`markdown_v2`/`plain` via `send_telegram_message` + Bot API parse mode; WhatsApp/Slack/Discord/Teams `markdown`/`plain`; Messenger/Google Chat `plain`); `full` templates stop printing the summary twice (`response_starts_with_summary()`, #6525/#6548). **Comic Creation toolkit toggle registered** — the 12-tool toolkit can finally be enabled (#6512). **Checkout launch series** — required ToS consent + buyer email (`receipt_email`), EU billing-address block (`buyer_country`), Stripe Product/Price metadata + statement descriptor, manual-install-first, and the full `docs/legal/` set (privacy, AUP, clickwrap, compliance checklist, updated ToS, #6507/#6520/#6523/#6550). **Playbook seeder idempotency** — content hash ignores the per-second `Generated:` header (direct commit). **Security sweeps** — 11 Dependabot alerts closed across four PRs (tiptap, multer, csv-parse, react-router-dom, SVGO CVE-2026-84370 → `svgo >=4.1.0`, hono, vitest) + schedule-anything-spa build unblocked (#6515/#6532/#6546); docs-hub wp.org ZIP stops shipping dev Markdown (#6504). **Wave F2 completes ten `nvoos-content-graph-pro` clusters** — site-creator (33 tools), document-generation, regulatory-registration, healthcare (9 batches), law-firm, image-production, comic-creation, dj-management, ai-tool-builder, architect-agent, architectural-design (#6505–#6549). **New `mcp-ai-wpoos-ecosystem-port` skill** — coding-time skills 55 → 56. Tool count unchanged: ~303 base + ~1,265 Pro (~1,568 total). Stale 1.1.74 build ZIPs removed (30 files). See `docs/project/plans/v1.1.76-docs-catch-up.md`.

- **v1.1.75** (September 9): Scheduled-delivery & ecosystem-port release. **Telegram broadcast credentials fix** — inline credentials stored as JSON strings no longer fatal the array-typed broadcast tool (`normalize_channel_credentials()` decodes/rejects; per-channel `failures` repair saved schedules; real Remote Sites schema mapping with decrypted `api_key`/`token`, #6482). **Scheduled delivery credential fallback** — new tier-4 fallback resolves the first enabled Remote Sites connection of the channel type (assistant-assigned preferred) for cron delivery; broadcast capability waived for the internal `pro_schedule_manager_result_delivery` context only; `create/update_pro_schedule` + `POST /mcp-ai-pro/v1/schedules` accept `result_delivery`; diagnostics never carry secrets (#6488). **Content Graph memory bridge + NV oOS Complete checkout** — memories project into the standalone graph behind a new `wp_mcp_ai_wake_up_context_graph_retriever` filter seam; the checkout sells the Complete bundle with a conflict guard; nvoos-content-graph **1.0.4 → 1.0.6** (#6486). **Wave F2 completes the financial-planning, social-media, and mcp-servers toolkit ports** in `nvoos-content-graph-pro` (16 + 32 tools, all 33 MCP servers) plus remote-sites/video-production/analytics/multilingual/cloudways/dj-management/image-production slices (#6476–#6502). Tool count unchanged: ~303 base + ~1,265 Pro (~1,568 total). Stale 1.1.73 + content-graph 1.0.4 build ZIPs removed. See `docs/project/plans/v1.1.75-docs-catch-up.md`.

- **v1.1.74** (September 8): Calendar, scheduling & ecosystem-port release. **Google Calendar date-query fix** — every query value is `rawurlencode()`d before `add_query_arg()` (the raw `+` in RFC3339 offsets was decoded as a space, 400-ing all `time_min`/`time_max` queries); `calendar.freebusy` joins the Standard scope profile (new grants only, #6460). **Result Delivery email formats** — new `WP_MCP_AI_Markdown_Converter` (escaped + `wp_kses`-allowlisted + protocol-allowlisted links; raw assistant HTML neutralized) + per-channel `format` setting (`both` default | `html` | `markdown`), Nodemailer multipart + `wp_mail` fallback (#6465). **Schedule Manager assistant-prompt editing** — edit modal shows/updates the prompt for `assistant_run` schedules; `update_schedule()` merges `assistant_config` with stored config; `update_pro_schedule` accepts it via MCP (#6469). **Wave F2 completes the PM + calendar-booking toolkit ports** in `nvoos-content-graph-pro` (data layer → admin slices, #6450–#6472). **Test/CI** — perf-suite MCP-abilities failure fixed process-wide in `tests/bootstrap.php` (#6470, supersedes #6464); content-graph-pro excluded from the root WPCS gate (#6457). Tool count unchanged: ~303 base + ~1,265 Pro (~1,568 total). Stale 1.1.72 build ZIPs removed. See `docs/project/plans/v1.1.74-docs-catch-up.md`.

- **v1.1.73** (September 8): Woo tool upgrades & ecosystem-port release. **`bulk_update_products` variable-scope expansion** — new `scope` arg (`all` default | `product` legacy) expands price/stock fields from variable parents to variations and grouped parents to children via `resolve_update_targets()`, re-syncs `WC_Product_Variable` parents, and reports `targets[]` per input ID (+ `scope`/`updated_targets` response keys; status/featured/category/tag always apply to the selected product, #6447). **`update_woo_product_qty` `notify` flag** — default `true`; `false` suppresses the low-stock/no-stock emails for the write via `finally`-scoped `woocommerce_should_send_*` filters while the `woocommerce_*_stock` actions still fire (#6448). **Async job queue table bootstrap fix** — the class now boots in time to create its table (activation + first-load self-heal; `get_queue_stats()` fails soft), ending the missing-table SQL floods (#6423). **Comic Reader 0.2.0 → 0.5.0** Komga-parity upgrade (archive validation, Range serving, vertical/webtoon modes, ComicInfo.xml RTL, per-user progress, settings page, #6402). **Ecosystem Wave F2** — new `nvoos-content-graph-pro` standalone addon (v1.0.0) with the byte-identical Pro CRM + e-commerce ports (43 e-commerce tools); **Docs Hub 0.4.3** wp.org prep (#6397, #6403, #6436–#6445, #6449). **Docs/tooling** — new `mcp-ai-wpoos-wporg-submission` skill (skills → 55) + plugin-check gate repair (#6418); use-cases Rev 3.0 (#6443); broken-link fixes (#6446); sync workflows (#6439). Tool count unchanged: ~303 base + ~1,265 Pro (~1,568 total). Stale comic-reader 0.2.0 + docs-hub 0.4.2 build ZIPs removed. See `docs/project/plans/v1.1.73-docs-catch-up.md`.

- **v1.1.72** (September 7): E-commerce & ecosystem-port release. **Two new Woo tools** — `update_woo_product_price` (regular/sale, all product types) + `update_woo_product_qty` (stock + management) on a shared `WP_MCP_AI_Woo_Price_Qty_Updater` trait; `bulk_update_products` shares it (#6388). **Scheduled sync fix** — EZuite/FlowHub scheduled syncs deliver their assigned connection ID (the scheduled action was dropping it, #6386). **Pro update vendor integrity** — "Update Pro Now" verifies the Pro package's `vendor/` before/after updating, no white-screens (#6338). **Container binding fix** — `tool_registry` resolves the live singleton on every `get()` (#6339). **Deps** — `browserslist` + `qs` patched across all seven lockfiles (12 Dependabot alerts, #6365). **gpt-image-2 everywhere** — OpenAI image default aligned across all three settings layers (#6332). **Ecosystem port waves** — Wave D8 closes the standalone tool-execution gap in Content Graph AI (pre-ported core tools, `tools/call`, hardened adapter tools); Wave E6 ports shadow/markup/Paper Store/OKF/crawler/OOS-bridge into the AI addon (#6340–#6342, #6362–#6364, #6367–#6369); the platform addon closes Waves E2/E3/E5/E1/E4 + E-UI-1/2/3 admin screens (#6333–#6335, #6337, #6344, #6346–#6361, #6370–#6378, #6381–#6385, #6387); `nvoos-content-graph` hardened for wp.org (#6330). Tool count: ~303 base + ~1,265 Pro (~1,568 total). Stale 1.1.70 + 1.1.71 build ZIPs removed. See `docs/project/plans/v1.1.72-docs-catch-up.md`.

- **v1.1.71** (September 5): Rate-limit, model-catalog & ecosystem release. **REST rate-limit unlock** — `check_rate_limit()` moves to fixed-window accounting (honest remaining-time `retry_after`, no infinite slide) and fires `wp_mcp_ai_rest_request_rate_limit_exceeded` so the restriction registry flags blocked users into the Command Center Restrictions tab with the Lift button (lift clears the request window; guest IP-keyed blocks expire on their own, #6322). **MemPalace wing-scope enforcement** — `matches_wake_filters()` now applies `wing`/`room` exclusions; the Graphify graph anchors only boost and never excluded, leaking cross-wing memories into wing-scoped blocks (#6327). **Checkout API into the pipeline** — `nvoos-checkout-api` ZIP + main-suite tests; token/crypto classes derive `wp_salt()` instead of raw `AUTH_KEY . SECURE_AUTH_KEY` (#6315). **Connectors links** → `options-connectors.php` (#6314). **September 2026 model catalog** — 228 models (gpt-5.6 family, gpt-6-astra, gpt-image-2, claude-opus-5, gemini-3.6/3.7/3.8-flash, kimi-k3), retired DeepSeek/gemini-3.1-flash/imagen-4 IDs with migration-map successors, pricing drift fixes, new defaults (`gemini-3.6-flash`, `gpt-image-2`, `kimi-k3`, #6328). **Content Graph ecosystem** — standalone plugin 1.0.4 visual experience (themes, appearance tab, SVG glyphs, explorer chrome, edges route, export, checkout fallback, #6318); Content Graph AI assistant-builder blocks + settings shell (#6316, #6317); platform Wave E2 queue layer (AsyncJobQueue → QueueManager → JobQueueManager → DeadLetterQueue, #6319–#6321, #6325). **New `mcp-ai-wpoos-updates` skill** — coding-time skills 53 → 54 (#6323, #6324). Test-suite skill → 40 patterns. Stale 1.1.68 + 1.1.69 build ZIPs removed. Tool count unchanged: ~303 base + ~1,263 Pro (~1,566 total). See `docs/project/plans/v1.1.71-docs-catch-up.md`.

- **v1.1.70** (September 5): Host-hardening & test-suite stability. **exec-disabled host hardening** — canonical `wp_mcp_ai_check_nodejs_available()` / `wp_mcp_ai_get_nodejs_version()` helpers (exec-first, `WP_MCP_AI_Process_Service` fallback) replace unguarded `@exec()` in six Pro admin pages; OCR routes through `is_cli_tool_available()` / `run_cli_command()` with guarded `proc_*` calls; `max(0, …)` clamps replace `absint()` in seven CPT/settings sanitizers (#6295). **Fifth PHPUnit repair wave** (~29 test PRs, CI runs 91006542428 + 91771001271) closed every remaining cluster and carried grouped production fixes: transcript `attachments` metadata preserved + model-window-capped `max_tokens` (#6280); dashboard event-slug tolerance (#6281); validated `web_search` profession tags (#6282); preset fixes (#6283); mesh URL pre-validation (#6285); guest memory bucket (#6286); JetEngine `JET_ENGINE_VERSION` gates + physical CCT-table probe + JFB params + cached Pro tool map (#6296, #6300); complexity routing restored + provider-prefixed slug limits (#6298, #6306); structural Auth0 audience validation + acting-user permission checks (#6301, #6305); markup REST 400s + `wp_mcp_ai_legacy_sse_enabled` filter + skill-pack slug enforcement (#6302); clamp sanitizers + terminate seam (#6303); Elementor editor detection (#6304); agentic log events + ms trace durations (#6308); webhook IDs + settings-blob fallback + acting-user chart gating + social-publish date reset + PSO inflections + h1–h6 allowlist + schema `required` (#6311); curriculum exporter empty-structure skips (#6293). New triage docs: `CI-TRIAGE-91006542428.md`, `CI-TRIAGE-91771001271.md`. Tool count unchanged: ~303 base + ~1,263 Pro (~1,566 total). See `docs/project/plans/v1.1.70-docs-catch-up.md`.

- **v1.1.69** (September 4): AI-vision & admin-compat release. **Pro Vision Analysis toolkit** — new `analyze_image_objects` tool detects and counts objects per category (HuggingFace OWLv2 / Ollama `detection`, JSON-enforced VLM `vlm` mode, hybrid label normalization; `annotate=true` returns a GD-drawn labeled bounding-box copy as a media attachment) behind a new NV oOS → Vision Analysis settings page (`enable_vision_analysis_toolkit`, off by default, SSRF-guarded). **tagDiv Newspaper admin compat** — the four SiteKit tools crashed the assistant tools metabox via the non-existent `CAPABILITY_CAN_USE_IF_ADMIN` constant (string flag arrays now), the sortable shim prints a bundled jQuery UI 1.14.2 copy whenever `td_wp_admin` is enqueued, the media script chain is forced as direct head tags, and the tools metabox CSS is enqueued in the head (#6266, #6278). **DeepSeek 400s fixed** — argument-less tools emit `properties: {}` (never `[]`) across 29 files + `LegacyToolAdapter` object-map preservation (#6272). **ZipSlip guard revived** — `count( $zip )` replaces the PHP 8.x-nonexistent `ZipArchive::$num_files` in OKF bundle import + four Pro admin pages (#6270). Also: rate-limiter classification by the dispatching request's real HTTP verb with a separate nefarious-monitor counter and dual-shape chat hook tolerance (#6265); assistant edit screen shortcut fatal containment (#6271); model catalog fixes — gpt-4o 128k context, `gemini-2.0-flash` video-capable typo, active status for claude-sonnet-4-6/gpt-4o, two Qwen entries (#6274); settings save no longer leaves object-cache additions suspended (WP 7.1 inline-async tick locks, #6277). Fourth PHPUnit repair wave (~9 test PRs); `mcp-ai-wpoos-test-suite` skill now distills **37** patterns. Tool count: ~303 base + ~1,263 Pro (~1,566 total). See `docs/project/plans/v1.1.69-docs-catch-up.md`, `docs/toolkits/vision-analysis-toolkit.md`, `docs/proposals/vision-analysis-object-counting-tool.md`.

- **v1.1.68** (September 3): Front-end surfaces & stability. **Pro SPA v2 shortcode** `[nvoos_pro_spa]` embeds the Pro chat surface on the front end (chat-first embedded mode: threads, drawers, tool shortcuts, OKF drawer; router-free; optional guest mode behind the guest-token machinery). **Hermes WebUI extensions** land in a new top-level `extensions/` tree — fleet monitoring + control plane (`nv-oos-fleet`, Python plugin API over ~35 sites) plus `backup-download`, `external-app-tab`, `mcp-tool-shortcuts` with installer + smoke tests. **Chat surfaces self-heal stale nonces** — new `GET /mcp-ai/v1/session/nonce` endpoint mints a fresh session-bound nonce (no-cache), ending "Cookie check failed" 403s from full-page caching or session-token rotation. **Docs Hub 0.4.2** — local-page links resolve to in-app hash routes, github-slugger-exact TOC anchors, directory-relative "Accept fix" suggestions, `../` validation + skip reasons, sync failure surfacing, and the emoji loader no longer crashes the React SPA. **Fresh installs disable cloud providers by default** (dropdowns list enabled + credentialed providers; the onboarding wizard auto-enables the provider whose key you enter). Fixes: Google Calendar granted-scopes `%20` corruption, Tiptap `mergeAttributes` prototype pollution (3.30.4 pins), `fast-uri` >=4.1.4 across npm trees, assistant untrash restores the pre-trash status, presets gain missing Google/Gmail/Drive/OKF/git tools, task-template seeding AJAX wired, and a jQuery UI sortable shim for post screens. Third PHPUnit repair wave (~21 PRs, #6224–#6257, plus the late-merged #6259 adding the `wp_mcp_ai_attachment_segment_provider` filter so providers without a remote file API resolve attachments locally). Stale 1.1.67 build ZIPs removed. Tool count unchanged: ~303 base + ~1,262 Pro (~1,565 total). See `docs/project/plans/v1.1.68-docs-catch-up.md`, `docs/project/proposals/033-pro-spa-v2-shortcode-proposal.md`.

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

- **Harmonization Sub-Toolkit** 🎨 — 14 new Pro tools under `addons/pro/includes/tools/image-production/harmonization/` that complement the end-to-end `product_actualization` tool with composable AI-compositing primitives (color harmonization, relighting, shadow synthesis, reflection, boundary refinement, AI-assisted background generation, outpainting, placement suggestion, lighting analysis, and an end-to-end orchestrator). See [`harmonization-architecture.md`](features/harmonization-architecture.md). Example LLM prompts:
  - *"Place this product photo on an AI-generated kitchen counter."*
  - *"Drop the attached subject onto this uploaded background, lower-center, with a soft contact shadow."*
  - *"Rebuild this catalog page with consistent harmonization across all eight products."*
- **April 2026 Security Audit Summary** 🛡️ (v1.1.10) — New [`SECURITY_AUDIT_2026_04.md`](operations/compliance/SECURITY_AUDIT_2026_04.md) consolidates the nine deliverables under [`audits/2026-04/`](project/audits/2026-04/). No Critical findings; 5 High (3 Fixed, 2 Partially Fixed); 14 Medium (all Fixed); 21 Low (14 closed); 10 Informational; 50 total. Standards: WP Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 / API Top 10, WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA, MCP/SSE.
- **Production-Ready Vendor Autoload** (v1.1.10) — `vendor/` regenerated with `composer install --no-dev --classmap-authoritative`; plugin is deployable from a clean clone (PR #4733).
- **Veo 3.1 `generate_veo_video` Fix** (v1.1.10) — `seed` parameter now sent only to Veo 2.0 (`veo-2.0-generate-001`); Veo 3.1 (`veo-3.1-generate-preview`) rejects it (PR #4735).
- **Measurement Subsystem GA** ⭐ (v1.1.9) — 12 sequenced PRs delivered the full measurement / evals / reward stack: stock metrics for tool-execution, chat-loop, agentic-loop, and SSE; persistent `{prefix}mcp_ai_metric_events` table with retention cron (`wp_mcp_ai_metric_retention_days`, default 30 days); eval harness with verifier-independence enforcement; Pro rubric presets (`prompt_adherence`, `json_schema`, `citation_presence`) and counterfactual runner; OTel JSON exporter; Measurement dashboard under **Tools → Measurement** with time-range + sparkline; `wp mcp-ai measurement run|alert-check|list-runs` WP-CLI runner with regression-aware exit codes. See [`measurement/README.md`](reference/measurement/README.md).
- **PHPUnit 11 Upgrade (CVE Fix)** 🔒 (v1.1.9) — PHPUnit upgraded to 11.x with WordPress-compatibility patches to resolve the argument-injection vulnerability **GHSA-qrr6-mg7r-m243**. CI PHP bumped 8.1 → 8.2.
- **Chart.js Handle Normalization** (v1.1.9) — All admin dashboards now enqueue a single `wp-mcp-ai-chartjs` handle to eliminate duplicate registrations and version drift.
- **Graphify Knowledge Graph Addon v0.5.0** (v1.1.9) — Optional WordPress Knowledge Graph addon restored under `addons/graphify/`.
- **Orchestration Reference Doc** (v1.1.9) — New [`ORCHESTRATION_REFERENCE.md`](reference/orchestration/ORCHESTRATION_REFERENCE.md) documents every workflow preset, resource preset, the PSO algorithm, and all orchestration hooks / filters / storage keys in one place.
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

Per-assistant opt-in: Edit Assistant → **LLM Harness** metabox → Enable → check the layers you want (A–H). All layers are off by default. Reference: [llm-harness.md](features/llm-harness.md).

---

## ✅ HITL Approval Queue

Admin: **NV oOS → Orchestration → Approvals**. Tool: `request_user_approval`. REST: `GET/POST/PATCH /wp-json/mcp-ai/v1/approvals/*`. Pending → Publish = approved; Private = denied.

---

## 🔗 Toolkit MCP Discovery

Discovery endpoint: `GET /.well-known/mcp` (returns JSON array of all enabled toolkit server URLs). Credentials: **NV oOS → Orchestration → Toolkit MCP → {Toolkit} → Credentials**. CLI: `wp mcp-ai mcp-server token-generate {slug}`. Reference: [mcp-servers.md](features/mcp-servers.md).

---

## 📚 Additional Resources

### Full Documentation
- [Complete README](../README.md) - 1,027 lines of comprehensive docs
- [Documentation Index](DOCUMENTATION_INDEX.md) - All 39 documentation files
- [Tool Reference](reference/tools/tool-reference.md) - All ~1,692 tools detailed (~353 base + ~1,339 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
- [REST API Guide](reference/api/rest-api.md) - Complete API documentation
- [Orchestration Budget Enforcement](developer/architecture/orchestration/orchestration-budget-enforcement.md) - Budget prediction and adjustment

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
- **[Use Cases & Quickstart Guides](getting-started/USE_CASES_AND_QUICKSTARTS.md)** - 14+ use cases with step-by-step guides (Rev 3.0)
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
