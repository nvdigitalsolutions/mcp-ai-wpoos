# NV oOS Plugin Skill - Release Notes (v1.1.66 to v1.1.92)

Moved out of SKILL.md to stay under the Zed 100KB skill-size limit.
Operational content stays in SKILL.md; append new per-version sections here.

---

## Media Studio Fashion Production, Inline Vision Data URLs & Provider-Content Fixes (v1.1.92)

- **Media Studio fashion production suite (PRs #6839/#6844 — addon 0.1.0 → 0.6.0)** — the `fashion-studio` AI mode lands in Media Studio: eight Gemini-driven transforms (on-model, model-swap, face-swap, background, recolor, packshot, detail-repair, try-on) via `edit_gemini_image`, REST `/ai/*` routes with the D-1 consent/acknowledgment gate + D-3 cost tripwires ($0.25 per-image / $10 per-job review, $100 hard cap), a lazy-loaded SPA mode, and forced disclosure watermarks on face outputs. Pro Phase 2 adds the fashion identity library (`mcp_ai_fashion_model` CPT + consent gating), the batch/review queue (`mcp_ai_fashion_job` CPT, approve/reject/reroll, WooCommerce + media collection export), and the `fashion_studio` Pro module; Phase 3 ships the marketplace output pipeline (amazon/woocommerce/social/web profiles); Phase 4 ships IPTC 2025.1 XMP + best-effort C2PA provenance; Phase 5 ships a sidecar-backed fashion `video` transform and **8 new Pro tools** (`fashion_onmodel_generate`, `fashion_model_swap`, `fashion_background_generate`, `fashion_recolor`, `fashion_packshot`, `fashion_virtual_tryon`, `fashion_batch_job`, `fashion_identity_manage` — self-gated via `is_available()` on the Media Studio AI service) plus the Workflow Builder `fashion` preset category (10th).
- **Inline vision data URLs + payload optimization (PR #6846)** — OpenAI/DeepSeek vision paths now inline server-fetched base64 bytes (local attachments read off disk, remote URLs downloaded by WordPress) instead of handing URLs to provider servers — fixes `Failed to download image from …` on hotlink-protected/CDN/staging media. Oversized JPEG/PNG downscaled to a 2048px vision-tile cap, opaque PNGs re-encoded JPEG q82, originals never modified. New **Chat Client → Features → Inline Image Optimization** toggle (default on) + `wp_mcp_ai_image_inline_*` filters. See SKILL.md Troubleshooting for the download-failure entry.
- **Provider content + credential fixes (PR #6845)** — the six research tools no longer fatal on array AI content (shared `WP_MCP_AI_Tool_Research_Content_Normalization` trait; `research_project` JSON regexes repaired); `WP_MCP_AI_DeepSeek_Client` flattens array content blocks; `create_post` matches tags on word boundaries (single-word tags suggested only); the vision tools resolve keys via the merged settings + `WP_MCP_AI_Credential_Resolver` (see SKILL.md Troubleshooting).
- **Coverage manifest (PR #6847)** — the Pro tool coverage manifest is regenerated with the 9 fashion-stack classes.
- **Tool counts** — ~347 base + ~1,309 Pro (~1,656 total; +8 Pro).

## FlowHub MCP Mode, OAuth Discovery & JSON Envelope Protection (v1.1.91)

- **FlowHub Connection MCP mode (PR #6836)** — FlowHub Remote Sites connections gain a `flowhub_mode` selector (`api` default | `mcp`); MCP mode designates the connection as the backend for the FlowHub toolkit MCP server. `WP_MCP_AI_FlowHub_Connection_Helper::get_mcp_connection_id()` resolves the first enabled MCP-mode connection; the toolkit MCP REST controller injects its `connection_id` only when the caller passes none — MCP-triggered refresh/sync calls route through the explicit-connection path (decrypted credentials + the connection's encrypted proxy via `http_api_curl`). Explicit `connection_id` always wins; the seam is generic (`WP_MCP_AI_Toolkit_Server_Base::get_mcp_connection_id()`).
- **MCP App OAuth discovery per MCP spec (PR #6835)** — `discover_metadata()` walks the full chain: RFC 8414 metadata (+ §3.2 path insertion) → RFC 9728 protected-resource metadata (every `authorization_servers` entry) → 401 `WWW-Authenticate` probe → OIDC → WordPress REST fallback. RFC 8414 docs accepted only with both endpoints; per-attempt diagnostics surface real transport errors; server-advertised `default_scope` honored; multi-challenge parsing; 10 s per-probe cap. See SKILL.md Troubleshooting for the discovery entry.
- **OAuth redirect allowlists (PRs #6831/#6832)** — LinkedIn, QuickBooks, Mailjet, and Yahoo Sports connect buttons no longer bounce to wp-admin (hosts added to `allowed_redirect_hosts` via per-provider filters). See SKILL.md Troubleshooting.
- **JSON envelope protection (PR #6827)** — orchestration CCTs gate on the physical table (`is_storage_ready()`: table + every required column → transients fallback); the new `WP_MCP_AI_Db_Output_Guard` wraps `execute_tool()` + both REST tool handlers — no surface leaks `$wpdb` error HTML into a JSON response.
- **Fixes (PRs #6829/#6830/#6828)** — Security events table + CSV exporter read canonical `event_type`/`ip_address` keys; the orchestration dashboard no longer renders twice (duplicate loader `new` removed); `rfdetr_catalog_search` joins the `ecommerce` preset + Pro coverage manifest regenerated.
- **Dependency advisories (PRs #6833/#6834)** — `nodemailer` `^10.0.9` in pro + media-worker (GHSA-g57g-f23g-4646; vendor bundle refreshed 8.0.5 → 10.0.9); `fast-uri` ≥4.1.5 across 4 trees (GHSA-jvvf-x445-j334).
- **Ecosystem port (PR #6825)** — the RF-DETR vision cluster ports byte-identical into `nvoos-content-graph-pro` (Wave F3 sub-cluster 1; tracker row appended).
- **Tool counts** — ~347 base + ~1,301 Pro (~1,648 total; unchanged).

## Telegram Delivery Fixes, Memory Bridge & Wave F2 Toolkit Completions (v1.1.75+)

- **Telegram broadcast credentials** (PR #6482) — inline credentials stored as
  JSON strings no longer fatal the array-typed broadcast tool;
  `normalize_channel_credentials()` decodes/rejects, and the Remote Sites
  schema mapping decrypts `api_key`/`token` for the real storage keys.
- **Scheduled delivery credential fallback** (PR #6488) — tier-4 fallback
  resolves the first enabled Remote Sites connection of the channel type for
  cron delivery; `create_pro_schedule`/`update_pro_schedule` and the schedules
  REST endpoint accept `result_delivery`; diagnostics never carry secrets.
- **Content Graph memory bridge + NV oOS Complete checkout** (PR #6486) —
  new `wp_mcp_ai_wake_up_context_graph_retriever` filter seam on
  `wake_up_context`; the checkout sells the Complete bundle (base + Pro) with
  a conflict guard; nvoos-content-graph **1.0.4 → 1.0.6**.
- **Wave F2 port completions** (PRs #6476–#6501) — `nvoos-content-graph-pro`
  (1.0.0) completes the financial-planning, social-media, and mcp-servers
  toolkit ports plus remote-sites/video/analytics/multilingual/cloudways/
  dj-management/image-production slices.
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## RF-DETR Vision Cognition, Strict MCP Scope & Memory Identity Closure (v1.1.90)

- **RF-DETR vision cognition (PR #6824, Proposal 049)** — Pro `WP_MCP_AI_Roboflow_Inference_Service` (one HTTP client, three trust tiers: key-less self-host loopback/private, dedicated, Serverless Cloud API — fail-closed credentials, SSRF-guarded URLs); **2 new Pro tools** (`rfdetr_detect` boxes/masks/keypoints, `rfdetr_catalog_search` fine-tuned catalog models); `roboflow` provider joins `analyze_image_objects` (no new slug); `identify_image` rung 3c reports `rfdetr_detections` (class-guarded — Base skips cleanly); XL/2XL behind a PML consent toggle.
- **Strict MCP assistant-scope toggle (PR #6819)** — opt-in `mcp_require_assistant_scope` (Security → Access & Identity, default OFF): `tools/list`/`tools/call` fail closed with HTTP 403 (`wp_mcp_ai_assistant_scope_required`) when no assistant resolves; the 403 special-case is scoped to this one error code (all other MCP errors keep the HTTP 200 JSON-RPC envelope). See SKILL.md Troubleshooting for the HTTP 403 entry.
- **Upwork MCP as first-class MCP Apps references (PR #6823)** — Upwork-in-MCP-mode connections appear in the assistant "Add from Remote Sites" dropdown with the full OAuth login UI; chat-time ref resolution via the official gateway + decrypted central `mcp_oauth`; post-login reference persistence.
- **Memory identity + IDOR closure (PR #6815)** — the eight memory tools resolve `agent_id` from the execution context; cross-agent access gated behind `manage_options` (403 `mcp_ai_memory_scope_denied`); credential-pattern scan + expiry signalling on the store/retrieve pair.
- **Letterhead personalization (PR #6816)** — `Dear {{to_name}}` + `{{#to_name}}…{{/to_name}}` conditional blocks; bundled template reads prefer the `direct` filesystem transport.
- **Dependency advisories (PR #6817)** — `js-yaml` ≥5.4.1 + `webpack-dev-middleware@^8` ≥8.3.0 across 13 trees (15/17 alerts; AI SDK migration tracked as #6818).
- **Tool counts** — ~347 base + ~1,301 Pro (~1,648 total; +2 Pro — the RF-DETR pair).

## Google Classroom ECA, Design System Rename + Email Templates & Upwork MCP Mode (v1.1.89)

- **Google Classroom integration for the ECA toolkit (PR #6809, Proposal 046)** — shared `includes/google/` Classroom foundation (restricted scopes, Pub/Sub push with shared-secret verification); `google_classroom` Remote Sites connection type; **12 new ECA tools** (`list_classroom_courses` … `manage_classroom_push_watch`) behind `enable_eca_classroom_integration` (default off); nightly sync engine; new base REST route `mcp-ai/v1/google-classroom/webhook`.
- **Design System addon rename + token-driven email templates (PR #6810, Proposals 047/048)** — `addons/crocoblock-ds/` → `addons/nvoos-design-system/` (**0.1.0 → 0.3.0**, zero-breakage shims); 5 built-in accessible email templates + global `wp_mail` wrapper + WCAG/EMC audit gates; **8 admin-gated `nds_*` tools** (addon-provided, not counted in base/Pro totals).
- **Upwork MCP connection mode (PR #6804)** — third `upwork_mode` (`mcp`) against the official Upwork MCP gateway via a sessionful bridge (`upwork__find_jobs`/`upwork__list_accounts`); `org_uid` resolution; OAuth via `connection_ref` with tokens in the encrypted central `mcp_oauth` store.
- **Vision + remote + EZuite fixes (PR #6805)** — `submit_document_prompt` routes images through the `input_image` vision segment path (DeepSeek `deepseek-flash` native vision); remote-connection canonical slug mapping; EZuite `item_code` enforcement; per-provider model-setting migration.
- **Handshake cache (PR #6802)** — 24h per-URL legacy-dialect hint skips the doomed `server/discover` probe (Upwork Test Connection ~24.9s → ~8.5s).
- **Security (PRs #6807/#6806)** — 0 open CodeQL alerts; ip-address/undici/multer advisory patches.
- **Tool counts** — ~347 base + ~1,299 Pro (~1,646 total; +12 Pro — the classroom tools; the 8 `nds_*` tools are addon-provided and not counted).

## Decision-Model Orchestration, MCP Apps Hardening & SaaS Controller 0.3.0 (v1.1.88)

- **Decision-model orchestration Phase A (PR #6792, Proposal 045)** — base `WP_MCP_AI_Verification_Cascade` (SDE-cascade battery, max aggregation, single-rung escalation, injectable decision client) + `wp_mcp_ai_execution_depth_confidence` seam; Pro opt-in Jev tier routing (`enable_jev_tier_routing`) + citation cascade (`enable_jev_citation_escalation`) in `research_eca`/`generate_research_report`; fail-open, off by default.
- **MCP Apps OAuth + credential hardening (PRs #6794/#6795/#6798)** — loopback-only redirect URIs via manual paste-back (`POST mcp-ai/v1/mcp-apps/oauth/complete`, 10-min state TTL); `client_id` in token exchange; inline MCP App secrets + Remote Sites verify tokens now encrypted at rest (AES-256-CBC) with persisted OAuth refresh rotation; `mcp_oauth_refresh` activity events.
- **Toolkit MCP grant gating (PR #6796)** — deny-by-default per-assistant grants (`-32601` for non-granted servers; `toolkitServers` always reflects the grant list). **Transport fixes (PRs #6799/#6800)** — legacy `initialize` fallback on bare HTTP 400; SSE response parsing.
- **Upwork job links (PRs #6790/#6793)** — canonical `/jobs/<slug>_~<jobId>/` form restored; **PayHere/Flowhub guarded requires (PR #6801)** — missing client files self-report instead of fataling.
- **SaaS Controller 0.1.0 → 0.3.0 (PRs #6791/#6797)** — production NV oOS Cloud worker + Phase 12 Plan/Apply Worker-secrets/D1-schema; gateway express 4.22.3 (PR #6787).
- **Tool counts** — unchanged: ~347 base + ~1,287 Pro (~1,634 total).

## Parity Suite, Image Identification & Outbound Booking (v1.1.87)

- **mcp-wordpress parity suite (PR #6777)** — 30 new base tools: comment/user CRUD, content/media/terms, site settings + application passwords (log-masked), 9-tool SEO toolkit — capability checks + multisite guards; gap matrix `docs/developer/mcp-wordpress-tool-parity.md`.
- **Image identification ladder (PR #6780 + #6785, Proposal 043)** — `identify_image` + 4 companions (`get_image_metadata`, `find_similar_media`, `detect_image_content`, `describe_image_layout`) in the Media Generation preset; Pro `ocr_image_classic` + `search_similar_images`; key-gated, fails closed, never a vision LLM.
- **Outbound appointment booking toolkit (PR #6786, Proposal 044)** — Pro toolkit gated by `enable_outbound_booking_toolkit`; 3 new Pro tools (`outbound_get_pipeline_stats`, `outbound_import_leads`, `outbound_manage_angle`); booking shortcode + public REST endpoint.
- **mcp-wordpress gateway addon (PR #6778)** — Streamable HTTP MCP server for Cloudways Velocity; connects via Remote Sites → MCP Server.
- **Session-budget warnings (PR #6776)** — blocked-session messages point at the reset path; `wp_mcp_ai_chat_messages` filter on both chat paths. **Restriction notice fix (PR #6779)** — distinct-user counting + render-time sweep.
- **Upwork refinements (PR #6784)** — `update_pro_schedule` edits `workflow_steps` in place; `search_upwork_jobs` gains `exclude_keywords`/tier/budget parsing.
- **Tool counts:** ~347 base + ~1,287 Pro (~1,634 total). Addons 27 → 28.

## MCP Server Connections, Higgsfield Media, Log Filters & Delivery Templates (v1.1.86)

- **MCP Apps as a Remote Sites connection type** (PR #6761, Proposal 041) — new `mcp_server` connection type with AES-256-CBC-encrypted central credentials (incl. `mcp_oauth`), real JSON-RPC handshake Test Connection, tool-discovery snapshots, restricted-host enforcement, activity logging; auth mapping (`basic_auth`/`application_password` → MCP `basic`, `custom_header` → `header`, `bearer`, `oauth`). Per-assistant `connection_ref` reference mode resolves decrypt-on-use at chat time (credentials never written back to post meta); missing refs skip with error snapshots; imported bundles with missing refs auto-disable. Export/import redacts MCP App `token`/`oauth_data` (opt-out filters). New coding-time skill `design-elementor-mcp-connection` (59 → 60).
- **Higgsfield video/image provider** (PR #6772, Proposal 042) — four new base tools (`generate_higgsfield_video`, `generate_higgsfield_image`, `check_higgsfield_request`, `cancel_higgsfield_request`) on the shared `WP_MCP_AI_Higgsfield_Client` (two-part `Key ID:SECRET` auth, submit/status/cancel lifecycle, backoff+jitter polling, immediate download; settings → env → constants credential chain); provider settings section; `check_video_status` resolves `async_*` job IDs; `lib/core` dual-layer wrappers via `oos-bridge` (not counted). Also fixes the TypeSafe tool schemas + Pro coverage manifest.
- **`get_system_logs` filters** (PR #6768) — optional `since`/`levels`/`search` filters over the structured buffers + file logs (filters summary + `filtered_out` counts; `parse_since()` is the shared canonical parser; CG AI ports base-identical).
- **Action-items template + smart excerpts** (PR #6773) — scheduled digests send only the actionable section (`action_items`, email + chat) and prefer the response's own Summary/TL;DR/action sections over blind 80-word trims.
- **Tool costs in final labels** (PR #6771) — top-level `toolResult.cost` envelope on the agentic loop + SSE streaming; client-side tool-bubble badges.
- **Environment status fixed** (PR #6767) — always-on "no assistants published" warning + dead default-assistant branch repaired; `plugin.default_provider_model` resolves the effective per-provider model.
- **Tool count** — +4 base → ~312 base + ~1,282 Pro (~1,594 total). Model catalog stays v2026.09.22. Stale 1.1.84 build ZIPs removed.

## MCP Apps Connection & Exposure Wave, Docs Hub 0.5.1, README Consolidation (v1.1.85)

- **MCP Apps connection diagnostics** (PR #6753) — per-row Test Connection / Discover Tools buttons with inline results (negotiated protocol, handshake type, server info, session state, latency, live tool count, verbatim errors), persisted per-app status badge + tool-count chip, Test All; basic auth end-to-end (raw `user:pass` auto-encoded or pre-encoded base64) with token masking (stored credentials never echoed; empty = keep); `Mcp-Session-Id` capture + echo; legacy-handshake fallback; mcpServers JSON import; loopback detection with a PHP-FPM deadlock warning; **Security Center → MCP App Allowed Hosts** setting (constant hard override → filter + saved setting merged).
- **Protocol + exposure** (PRs #6754/#6755/#6758) — the client advertises the **negotiated** `protocolVersion` post-initialize and suppresses the `_meta` envelope in legacy sessions; a new `wp_mcp_ai_chat_effective_tools` filter seam (after attention filtering, before capability checks) exposes `mcp_app_<label>_<tool>` bridge slugs to the chat payload, with the resolved assistant ID flowing through `handle_tools_list()`/`handle_tool_request()`/`execute_tool_call_internal()`/the `list_mcp_tools` catalogue.
- **In-process same-site bridge** (PRs #6756/#6757) — same-origin MCP endpoints dispatch via `rest_do_request()` (no outbound socket/TLS/extra PHP-FPM worker) with outbound-HTTP safety rails and `rest_post_dispatch` re-applied for response-side session headers.
- **Docs Hub 0.5.1** (PRs #6749/#6750/#6759) — local-first uploads source, opt-in remote import, `is_path_safe()` symlink hardening, slug-named uploads folder + one-time migration (0 blocking Plugin Check errors).
- **README anchors + consolidation** (PRs #6751/#6752) — VS16-fallback TOC anchors fixed; `bin/validate-readme-anchors.py` repaired to GitHub's real rules + CI enforcement; one 12-release Release History section + complete Previous Releases table.
- **Tool count** — unchanged: ~308 base + ~1,282 Pro (~1,590 total; bridge slugs are dynamic chat-time registrations). Model catalog stays v2026.09.22.

## TypeSafe Jev Enhancement Wave: Fidelity, Guardrails & Decision Tools (v1.1.84)

- **Plan 040 Phases 0–2** (PR #6747) — noul criteria + structured EntryType fields (recursive two-gate walk); bounded 429/5xx retries honouring `retry-after` (native client + OpenRouter bridge; 4xx/transport never retried); the bridge defaults to `typesafe/jev-1.13`; opt-in advisory decision cache (`enable_typesafe_cache`, `cached: true` + zeroed usage); `typesafe_endpoint` setting + filter; `jev-preview` alias; `min_confidence`/`weights`/token warnings + usage aliases on `typesafe_decide`. New base tool **`typesafe_guardrail`** (one noul per hazard category → advisory pass/review/block; `ai_ml` preset + coverage manifest) + bundled skill `mcp-ai-wpoos-jev-decisions` (bundled skills 74 → 75). Pro: fail-open guest-chat guardrail (`enable_jev_guest_guardrail` on the Layer I pre-chat filter), advisory `check_citations()` on `generate_research_report`/`research_eca`, and three new `manage_options`-gated tools — `typesafe_rerank`, `typesafe_eval`, `typesafe_skill_select`.
- **Capability fix** (PR #6745) — `typesafe_decide`'s declared `manage_options` matches the enforced gate (metadata-only, CI-pinned).
- **Tool count** — +1 base +3 Pro → ~308 base + ~1,282 Pro (~1,590 total). Model catalog → **v2026.09.22** (+`jev-preview`). Deferred: extraction tools, the CG port cluster, NV Cloud passthrough.

## TypeSafe Jev Decisions, Assistant Builder, ID-Handoff Contracts & Webchat Fixes (v1.1.83 post)

- **TypeSafe Jev decision provider** (PR #6728) — Jev joins as a first-class *decision* provider deliberately separate from chat: `Interface_WP_MCP_AI_Decision_Client` + `WP_MCP_AI_Typesafe_Client` (typed choice/score/noul over state; 429/`retry-after`; versioned-model logging; `test_connection()`), OpenRouter Decisions bridge (`create_decision()`, filterable endpoint, actionable 404), and the new base tool **`typesafe_decide`** (canonical envelope + two-gate sanitisation, adversarial-state caveat, `manage_options`-gated; `ai_ml` preset + coverage manifest via #6743). Pro: fail-open `WP_MCP_AI_Pro_Jev_Classifier` (`decide()`/`classify_prompt()`/`filter_sources_by_relevance()`), opt-in dispatcher `jev_routing` (REST `compare-models` passthrough), opt-in `enable_jev_research_filter`; fixes the pre-existing dispatcher `chat_completion()` bug. Model catalog → **v2026.09.21** (+3 Jev decision entries).
- **"The Assistant Builder" meta-assistant** (PR #6727) — pre-configured meta-assistant (7-phase build workflow, 10-component prompt framework) appended to `get_default_assistants()` (roster 6 → 7); idempotent install + one-shot `admin_init` backfill (`wp_mcp_ai_assistant_builder_backfilled`).
- **P3 ID-handoff data contracts** (PRs #6729–#6738, closure #6739) — every ID-bearing tool family declares `produces`/`consumes` handoffs (post, cron, term, assistant, vector store, batch, Pro schedule, toolkit_cpt, medical record, plan, calendar, WPCode, session, member, webchat room) with the coverage manifest as single source of truth + permanent L1 honesty / L2 round-trip suites. In-wave fixes: `format_code_prettier` envelopes → canonical `WP_Error` (#6737); webchat `log_activity()` → `log_event( 'activity', … )` (#6738).
- **Usage monitor save fix** (PR #6726) — `handle_save_settings()` now applies the `wp_mcp_ai_admin_settings_sanitize` bridge filter (raw input, pre-sanitize), so the Usage Monitor sub-tab persists again; handlers no-op when their fields are absent.
- **Webchat close-out** (PR #6740) — `get_webchat_messages` queries rebuilt with explicit placeholders (phpcs warnings gone); the last three webchat tools + CG Pro member mirrors gain usage guidance; the CG interface copy gains `WP_MCP_AI_Tool_Usage_Guidance_Interface` (standalone-resolution fatal fix). Residual CG mirror drift (~957 files) → issue #6741.
- **Tool count** — +1 base → ~307 base + ~1,279 Pro (~1,586 total). Coding-time skills: 59 (unchanged — the test-suite skill gained patterns 49–50 and the ecosystem-port skill gained the CG interface-port rule, #6742).

## Tool Guidance, Pro Bootstrap Guard, Telegram Chunking & Upwork-Workflow Delivery (v1.1.83)

- **Tool Description Engineering** (PRs #6686/#6695/#6687–#6723) — `WP_MCP_AI_Tool_Usage_Guidance_Interface` (`when_to_use`/`when_not_to_use`/`related_tools`/`notes`) + the assembled `[Usage: …]` suffix in `get_model_facing_description()` (REST chat path, Tool Service `/tools`, `list_mcp_tools`); legacy-format classes opt in via `get_usage_guidance()` or a `usage_guidance` definition key forwarded by `WP_MCP_AI_Legacy_Tool_Wrapper`. The Phase 2 sweep completes the full base+pro tree (~1,487 tools; 1,584/1,584 files) and the `WPMCPAI.Tools.ToolDescriptionGuidance` sniff is enforced at severity 5. Opt-in adaptive tool cap (`WP_MCP_AI_Tool_Payload_Advisor`, site option + per-assistant override) + lazy schema loading (`tool_slug`/`include_schemas` on `list_mcp_tools`).
- **Pro bootstrap incomplete-install guard** (PR #6677) — `mcp-ai-wpoos-pro.php` `file_exists`-checks the module-registry require and degrades to `wp_mcp_ai_pro_incomplete_install_notice` + a WP_DEBUG line (partial deploys no longer fatal the site). **Telegram auto-chunking** — `send_telegram_message` splits >4,096-char messages (paragraph → line → hard; hard splits drop `parse_mode`; `chunk=false` restores legacy; `wp_mcp_ai_telegram_chunk_error` carries the failed chunk index). **Gmail `connection_id="settings"`** resolves to the settings fallback across the Pro Gmail tools, the Drive client, and base `search_gmail`. **Skill/OKF self-correction** — `load_skill` not-assigned appends `Assigned skills: …`; `okf_bundle_not_found` appends `Available bundles: …` (new `list_bundle_names()`).
- **Upwork search + workflow delivery** (PRs #6678–#6680/#6682/#6684) — `search_upwork_jobs` gains web_search-mode + API credential gates, drops category landing pages, extracts SERP `job_type`/`budget`/`published`, always runs the broad second pass under `min(limit, 5)` jobs, and accepts `sort`/`location` args; workflow deliveries ship the full 50-item result set (filterable cap) with per-item URLs + budget/contract/recency; both markdown converters fold indented continuation lines into a single `<ol>` (digests render 1–10); `steps` render as a compact execution log.
- **Playground Ollama demo** (PR #6683) — the seed now sets `/%postname%/` permalinks (fresh-install `/ollama-test-lab/` 404 fixed); local `npx -y @wp-playground/cli server` is the primary test path; `bin/capture-real-page.sh` rewritten (REST-index wait + cookie jar).
- **npm advisories** (PR #6681) — adm-zip 0.6.1, js-yaml 4.3.2, colord 2.10.0. **Regulatory envelope** (PR #6689) — five regulatory tools migrate to the canonical `WP_Error` envelope.
- **Tool count** — unchanged: ~306 base + ~1,279 Pro (~1,585 total). Coding-time skills: 59 (unchanged — the test-suite skill gained pattern 48 in-window).

## WordPress Playground Demos, Pro SPA Fixes & Token-Tracking Hardening (v1.1.82)

- **WordPress Playground demo blueprints** (PRs #6662/#6663/#6666/#6670/#6673/#6674) — two one-click demos: Content Graph "Project Asteria" (seeded sci-fi universe, deterministic build via the public `nvoos_content_graph/initial_build` hook) and **NV oOS Complete × local Ollama** (the browser worker's `localhost:11434` is the user's machine — provider pre-wired, Oma assistant, Test Lab page with `[ollama_status]` + embedded Pro SPA). `bin/generate-ollama-blueprint.php` auto-discovers the newest Complete bundle ZIP; `build-assets.yml` regenerates the blueprint on every build. New skill `mcp-ai-wpoos-playground-demos` (59th) documents the authoring/CORS/PCP/crash-mode playbook; end-user walkthrough in `docs/user-guides/playground-demo.md`.
- **Pro SPA fixes** (PRs #6665/#6672) — `[nvoos_pro_spa cron_monitor="0"]` no-ops the blocking SSE cron-status stream + REST poll for constrained hosts (default `true`); embedded mode seeds the model store from the assistant's real config (no more hardcoded `gpt-4o` override).
- **Token-tracking table hardening** (PR #6669) — verify-then-version, hourly retry backoff, quiet failure, graceful reads for SQLite-backed environments; ported 1:1 to `nvoos-content-graph-ai`.
- **Result-delivery dedupe** (PR #6661) — `delivery_safe_data()` strips the duplicated response + `assistant_id`/`is_agentic` metadata; summary/SMS prefix dedupe. **`[ollama_status]` banner fixed** (PR #6668) — footer-enqueued checker (shortcodes render markup only). **Portability coverage guards repaired** (PR #6645). **Docs Hub 0.4.7** (PRs #6659/#6667) — third wp.org reviewer pass.
- **Tool count** — unchanged: ~306 base + ~1,279 Pro (~1,585 total). Coding-time skills: 58 → 59.

## Shopify UCP Tool Routing, FlowHub Connections, JobNavigator CRM & OpenTerminal Financial Resilience (v1.1.81)

- **Shopify tools are UCP catalog mode-aware** (PR #6634) — storefront/global
  catalog connections drive live `search_catalog`/`lookup_catalog`/
  `get_product` queries (no caching, `live: true`); admin-only tools refuse
  catalog connections with an actionable hint; `remote_shopify_connection`
  validates UCP modes via the `tools/list` handshake. **Product image cards**
  (PR #6638) — `images[]` + a chat-rendered markdown card (10-card cap) on
  every product-returning path via a shared normalizers trait. CG Pro ports
  byte-identical.
- **FlowHub Remote Sites resolution + proxy** (PRs #6635/#6637) — a shared
  resolver chain (explicit `connection_id` → toolkit settings → sync
  connections → first enabled FlowHub connection) ends the "credentials are
  not configured" failure; live requests honor the connection proxy
  (`http_api_curl`). New base helper `WP_MCP_AI_FlowHub_Connection_Helper`.
- **JobNavigator CRM adoption + Gmail reply poller** (PRs #6636/#6640/#6641)
  — 5 new Pro CRM tools (`bulk_move_deal_stages`, `record_crm_reply`,
  `get_crm_handover`, `get_pipeline_digest`, `create_tracked_link`), deal
  stage history + undo, lead dedup with canonical companies, reply signals,
  won-deal lead release; a cron-driven Gmail reply poller classifies inbound
  replies with sentiment + optional stage advancement; pipeline-digest
  scheduling recipe for Workflow Builder + Pro Schedule Manager.
- **OpenTerminal financial resilience** (PR #6639) — 8 new Pro financial
  tools (`market_screener`, `macro_data_fetcher`, `economic_calendar_fetcher`,
  `earnings_calendar_fetcher`, `options_chain_fetcher`, `crypto_market_data`,
  `portfolio_transaction_log`, `price_alerts`), provider fallback chains +
  stale-while-revalidate caching, keyless microservice auth, technical
  indicators, portfolio transaction ledger with P&L. CG Pro port
  byte-identical.
- **Multi-recipient result-delivery email** (PR #6643) — comma/semicolon/
  whitespace lists normalized on save + sanitized/deduped at the
  `sanitize_result_delivery()` boundary; fanned out via Nodemailer +
  `wp_mail()`; legacy `notify_email` stays single-address.
- **Tool count** — +13 Pro: ~306 base + ~1,279 Pro (~1,585 total).

## Assistant Portability, Shopify UCP Modes, Security Usage Monitor & WP-CLI Repairs (v1.1.80)

- **Assistant export/import across all surfaces** (PR #6628) — canonical
  `WP_MCP_AI_Assistant_Portability` engine with `nvoos-assistant` JSON
  bundles (format_version 1): `wp mcp-ai assistant export|import` rewritten
  (legacy files still import; old export lost `_wp_mcp_ai_*` config — fixed),
  REST `POST /mcp-ai/v1/assistants/export|import` (admin-only, nonce/bearer,
  schema-validated, 2 MB cap, dry-run), admin Import/Export page + row/bulk
  actions, and 3 new base tools (`export_assistant`, `import_assistant`,
  `duplicate_assistant`) + Pro `export_assistant_blueprint`. Credential hashes
  never exported, stripped from imports; the backup provider now shares the
  denylist (previously it exported credential hashes).
- **Security Center Usage Monitor sub-tab** (PR #6632) — new `usage_monitor`
  sub-tab (violation triage log, status cards, shutdown recovery, editable
  config); the admin notice deep-links to it and shows the latest violation;
  `POST /mcp-ai/v1/security/clear-violations` + `/clear-shutdown`
  (`manage_options` + nonce); monitor sanitize-clobber bug fixed (submitted
  keys only); malformed patterns dropped/skipped.
- **Shopify UCP modes** (PRs #6624/#6630) — keyless Storefront + Global
  Catalog modes replace the deprecated REST Catalog API on Pro + CG Pro
  (byte-identical ports; public `/ucp/agent-profile` route); REST catalog
  401s fixed with a 60-min token cap, scope validation, and purge-and-retry
  (PR #6623); JetEngine sync gate unified with the System Status row.
- **WP-CLI repaired + streaming** (PRs #6625/#6626) — `provider list` and
  every base-class command no longer fatal on PHP 8+ (by-reference Formatter
  constructor → by-value `format_items()`); `chat` uses `get_model_router()`;
  `chat --stream` streams natively (cURL SSE) or simulates chunks, honoring
  the shared streaming filters. OKF editor keeps context on save (#6631).
  WhatsApp webhook self-tests on Remote Sites (#6622).
- **Tool count** — +3 base +1 Pro: ~306 base + ~1,266 Pro (~1,572 total).

## Imaging Symlink Hardening, Checkout Hardening Tail & Sub-Project Bumps (v1.1.79)

- **Imaging study deletion hardened against symlink traversal** (PR #6616) —
  both recursive study-deletion paths now remove links as links and never
  follow them; every iterator entry is realpath-verified against the
  storage root; new `study_delete_link_failed` /
  `study_delete_outside_storage_blocked` audit events; the
  `nvoos-content-graph-pro` port ships the same two files byte-identically.
- **Checkout hardening tail** (PRs #6611/#6613, vendor-side) — the Stripe
  statement descriptor now ships as `statement_descriptor_suffix` (fixes the
  424 that failed every live session); product/price creation verifies
  stored Stripe IDs against the current key and recreates them after an
  account switch.
- **Content Graph 1.0.8** (in-session) — Stripe Payment Element
  billing-address mode `never` → `auto` (non-EU purchases no longer die
  client-side with `IntegrationError`); `/payments/session` refuses
  chargeable sessions when the site is already licensed (no double
  charges).
- **Checkout API 0.1.2** (in-session) — buyers are emailed their license
  once per license from both the webhook and `/verify` paths
  (`email_sent_at` DB v4 → v5).
- **Docs Hub 0.4.5 → 0.4.6** (PRs #6615/#6617) — second wp.org reviewer
  pass (external-services disclosure, symlinked cache-dir uninstall guard)
  + full 18-guideline pass (bundled GPLv3 license); PCP 0 blocking errors.
- **Content-graph wp.org readiness** (PRs #6609/#6612/#6619) —
  seller-of-record copy, price-subject-to-change note, packaging tri-sync
  (`node_modules` exclude).
- **Toolkit slash test repair** (test-only, PR #6618) — suite updated to the
  declarative adapter contract from #6604.
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Slash-Command Rework, DeepSeek V4 Pro Restoration & Checkout Hardening (v1.1.78)

- **Slash commands reworked as declarative tool wrappers** (PR #6604) — the
  toolkit manager is now ~1,000 declarative lines: 36 commands across 15
  toolkits, each mapped to a verified real tool slug; execution delegates to
  `WP_MCP_AI_Tool_Registry::execute_tool()` via the new tool adapter
  (`register_tool_command()`), and every command is exposed as a `slash.*`
  MCP prompt template (`prompts/list` / `prompts/get`). ~76 placeholder
  commands purged; removed outcomes stay reachable via plain chat. The
  Content Graph platform port still ships the old placeholder system —
  owned by the ecosystem-port loop.
- **DeepSeek V4 Pro restored across all tracks** (PR #6608) — DeepSeek's
  2026-09-10 changelog continues V4 Pro past Sep 14: `deepseek-v4-pro` is
  **active** again ($0.66/$1.98 off-peak; migration map deliberately
  unmapped). The Content Graph AI mirror bumps its catalog to v2026.09.10
  and its v4-pro pricing is corrected; `lib/core` mirrors align.
- **Checkout hardening** (PRs #6597/#6598/#6603) — Content Graph assets
  cache-bust by file mtime (`Schema::assetVersion()`; fixes the invisible
  1.0.7 purchase-modal hotfix — year-long `Cache-Control` on
  `?ver=1.0.7`); the purchase modal syncs its price from the vendor
  `/session` response (display-only, vendor re-verifies) with
  `DEFAULT_PRICE_CENTS` **4900 → 3499**; minimalist modal restyle.
- **Docs Hub 0.4.4** (PR #6606) — all wp.org review findings fixed (search
  context filtering, source-code disclosure, symlink-safe deletion,
  staging-transient isolation, sitemap slug leak); PCP gate 0 blocking
  errors.
- **Legal consolidation** (PRs #6599/#6605/#6607) — unified ToS, aligned
  API-LICENSES, two-entity seller model (NV Digital Unlocked LLC sells;
  NV Digital Solutions develops).
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## DeepSeek V4.1 Flash, Base+Pro Gating & Memory CCT Slug (v1.1.77)

- **DeepSeek V4.1 Flash refresh** (PR #6555, corrected 2026-09-12) — the
  catalog's DeepSeek lineup is now `deepseek-flash` (active, vision) +
  `deepseek-v4-pro` (active — DeepSeek announced it continues V4 Pro service
  past 2026-09-14 with unchanged billing; no new sunset date); V4 Flash +
  Vision Exp retired; stored references migrate on the catalog-version bump.
  Cost calculator gains peak/off-peak (`calculate_cost_at()` with a record
  timestamp; legacy `calculate_cost()` stays time-independent).
- **Base+pro gating** (PR #6561) — Pro toolkits now load in base+pro
  installs (gate escape `! $is_base || defined( 'WP_MCP_AI_PRO_VERSION' )`);
  new `tests/basepro/` matrix + `composer run test:basepro` pins it.
- **Pro WP-CLI load-order guard** (PR #6585) — no more
  `Undefined constant WP_MCP_AI_PATH` when Pro activates before the base
  plugin; the CLI loader defers to `plugins_loaded` 30.
- **Memory CCT canonical slug** (PR #6591) — Graphify bridge + Pro memory
  retention read `ai_agent_memories` (canonical) with a legacy-table
  fallback; dormancy sweeps, per-user caps, expiry pruning, and Memory
  Health stats start working.
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Delivery Formats, Checkout Legal Series & Wave F2 Completions (v1.1.76+)

- **Chat delivery full report + per-channel formats** (PR #6525) — chat
  channels support the `full` template (summary + substantive response +
  envelope data); per-channel `format` allowlists (Telegram
  `html`/`markdown`/`markdown_v2`/`plain`; WhatsApp/Slack/Discord/Teams
  `markdown`/`plain`; Messenger/Google Chat `plain`); Telegram delivery
  routes through `send_telegram_message` directly so `parse_mode` works;
  `MarkdownV2` reserved-char escaping; group-mention skips log
  `telegram_group_mention_required_ignored` instead of silently dropping.
- **Duplicate-summary skip** (PR #6548) — assistant-run delivery skips the
  derived summary when the response already opens with it
  (`response_starts_with_summary()` normalizes tags/whitespace + strips
  the trailing ellipsis).
- **Comic Creation toolkit toggle + playbook seeder idempotency** (#6512,
  direct commit) — the `enable_comic_creation_toolkit` toggle is now
  registered (Tools section, Pro Features subtab, memory estimator,
  WP-CLI maps); `hash_playbook_content()` strips the `Generated:`
  timestamp so playbook syncs no longer recreate attachments.
- **Checkout launch-complete series** (#6507/#6520/#6523/#6550) — required
  ToS consent + buyer email, EU country selector + billing-address block,
  Stripe product/price metadata, license DB v3 (`dbDelta`), manual install
  as primary path (`/verify` returns `download_url`), commercial legal
  docs (`docs/legal/`). Checkout API stays **0.1.0**.
- **Security sweeps** (#6515/#6532/#6546/#6504) — 9 Dependabot alerts,
  SVGO CVE-2026-84370 `>=4.1.0`, svgo/hono/vitest bumps, docs-hub ZIP
  excludes `*.md` (keep `readme.txt`).
- **Wave F2 port completions + new port skill** (PRs #6505–#6549) —
  `nvoos-content-graph-pro` (1.0.0) completes ten toolkit ports
  (comic-creation, dj-management, ai-tool-builder, architect-agent,
  architectural-design, site-creator, document-generation,
  regulatory-registration, healthcare, law-firm); new coding-time skill
  `mcp-ai-wpoos-ecosystem-port` (skill count 55 → 56).
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Checkout Connectivity Diagnostics & Pro CLI Load-Order Guard (v1.1.77+)

- **Checkout API addon `GET /health`** (PR #6573) — public, no Stripe, no
  rate-limit token, no writes; returns `status`/`service`/`version`/
  `configured`/`server_time`. Live on nvdigitalsolutions.com:
  `GET https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1/health`.
- **Checkout API admin "REST endpoints" section** (PR #6573) — live
  per-route registered/missing markers (from `rest_get_server()->get_routes()`
  endpoint lists) plus a nonce-protected loopback self-check of `GET /health`
  reporting HTTP status + latency.
- **Content Graph client diagnostics** (PR #6573) — `Vendor::health()` and
  the admin-only `GET /payments/health` route (deliberately NOT throttled,
  so diagnostics cannot trigger the "Too many checkout attempts" lockout);
  the purchase modal offers a "Test connection" action when session
  creation fails.
- **Checkout troubleshooting playbook** — two independent throttles: client
  (transients `nvoos_content_graph_commerce_{session,verify}_throttle_{uid}`,
  5 and 15 attempts per 10 min per user) and vendor (per server IP, 20
  session / 30 verify per 10 min on the checkout-api addon). Diagnose from
  the client site's server:

  ```bash
  # Outbound connectivity from the site's server (works even when other
  # plugins fatal under WP-CLI, see below):
  wp --skip-plugins eval '$main = glob( WP_PLUGIN_DIR . "/*nvoos-content-graph*/nvoos-content-graph.php" ); if ( empty( $main ) ) { exit; } require_once $main[0]; var_export( ( new \NvoosContentGraph\Commerce\Vendor( \NvoosContentGraph\Commerce\Payments::vendorApiUrl() ) )->health() );'

  # Clear the client throttles. If the remote shell mangles $variables,
  # ship the PHP base64-encoded instead:
  wp --skip-plugins eval 'for ( $i = 1; $i <= 200; $i++ ) { delete_transient( "nvoos_content_graph_commerce_session_throttle_" . $i ); delete_transient( "nvoos_content_graph_commerce_verify_throttle_" . $i ); }'
  ```

  The vendor-side per-IP bucket only clears with time (10 min).
- **Pro CLI load-order fatal** — when the Pro addon is activated before the
  base plugin, every `wp` command dies with `Undefined constant
  "WP_MCP_AI_PATH"` (Pro requires its CLI files at include time under
  WP_CLI; web requests are unaffected). Site fix: reorder `active_plugins`
  via `wp --skip-plugins eval` (move the `-pro` entry after the base
  entry). Code fix: PR #6585 defers the CLI require loop to
  `plugins_loaded` when the base constant is missing at include time.
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Checkout Purchase-Modal Redirect Bug & Release-Tag URL Fix (PR #6594)

Diagnosed live on victory.nvdigital.solutions (client) + nvdigitalsolutions.com
(vendor) when "the purchase modal says nothing and redirects to the GitHub
releases page" with everything seemingly configured correctly.

- **The silent-redirect failure mode.** `checkoutUnavailable()` — the only
  path that redirects to `fallback_url` — fires when the modal's
  `/payments/session` call returns 404/≥500 **or its `.catch` runs**. The
  `.catch` wraps the ENTIRE `.then` chain, so ANY throw after the session
  call (Stripe element setup included) is mislabelled as "checkout
  unavailable". Status-code errors (424/429/403) stay in-modal with a
  "Test connection" action; a redirect means the response was 404/5xx,
  non-JSON, or a post-session throw. The fallback note is shown only 1.2 s
  — users report it as "says nothing, just redirects".
- **Root cause found: invalid Stripe element name.** The modal called
  `elements.create( 'paymentElement', … )` — the core Stripe.js API name is
  **`payment`** (`paymentElement` is the React component name). Stripe threw
  `IntegrationError: A valid Element name must be provided … you passed:
  paymentElement` AFTER a 200 session, and the catch-all redirected. Fix
  (PR #6594): `create( 'payment' )` plus a try/catch around Stripe element
  setup that surfaces a new `stripe_setup_error` message in the modal
  instead of the misleading redirect. The no-browser contract verifier is
  `node plugins/nvoos-content-graph/scripts/verify-commerce-fallback.js`.
- **Release-tag convention mismatch (same PR).** GitHub releases moved to
  `nvdigital-oos-v*.*.*` tags (`build-nvdigital-oos-wporg.yml` is the
  active path; `release.yml` `v*` tags are the legacy wp.org path). The
  checkout-api `default_zip_source()` and the client `Payments::zipUrl()`
  fallback still built `releases/download/v{VERSION}/…` → post-payment
  downloads 404/502 while payment + license succeed. Symptom: buyer pays,
  then "Could not fetch the addon package: 404 Not Found" (vendor download
  server) or a broken fallback URL. Verify: `curl -I …/releases/download/
  nvdigital-oos-v1.1.76/nvdigital-open-operator-system-oos-complete-
  1.1.76.zip` (200) vs the `v1.1.76` tag shape (404). Live-site remedy
  without a release: edit the vendor's **ZIP source** setting to
  `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/nvdigital-oos-v{VERSION}/nvdigital-open-operator-system-oos-complete-{VERSION}.zip`
  (keep **Addon version** as the plain `1.1.76` — the tag prefix belongs in
  the ZIP source pattern, not the version field).
- **Browser-side diagnostics that worked** (config lives in
  `window.nvoosContentGraphCommerce` — `rest_url`, `nonce`, `fallback_url` —
  only on the content-graph admin page, admin-logged-in):
  1. Replicate the modal call from the console:
     `fetch( rest_url + '/payments/session', { POST, credentials 'same-origin', X-WP-Nonce: nonce, body '{}' } )`
     — 200 + `client_secret` proves vendor/keys/throttles all healthy
     (each call creates a real live PaymentIntent; cancel it via
     `POST api.stripe.com/v1/payment_intents/{id}/cancel`).
  2. Raw-body variant (`r.text()`) to catch **non-JSON** responses (PHP
     warnings/debug lines, Cloudflare challenge HTML) — those reject
     `response.json()` and trip the redirect.
  3. Monkey-patch `window.fetch` to log every `/payments/` request with
     `r.clone().text()` BEFORE clicking Buy — captures what the modal
     actually sends/receives regardless of the 1.2 s redirect.
  4. To isolate a throw, load the REAL `https://js.stripe.com/v3/` first,
     then wrap the real `window.Stripe` with logging at call → elements →
     create → mount. **Pitfall: replacing `window.Stripe` with a stub
     short-circuits `loadStripeJs` (`if ( window.Stripe )`), so the modal
     never downloads Stripe.js and the stub's undefined return throws — a
     self-inflicted false positive.**
  5. DevTools "pause on caught exceptions" first lands on Stripe's internal
     `ArrayBuffer()` bot-check `try/catch` — harmless noise. Press F8 until
     the pause is in `content-graph-commerce.js` or carries a non-Stripe
     message.
- **Server-side diagnostics from the client site's shell:**
  `curl -sS -X POST https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1/session -H 'Content-Type: application/json' -d '{"product":"nvoos-oos-complete","site_url":"https://victory.nvdigital.solutions"}'`
  tests the exact server→vendor path (outbound firewall/DNS/SSL issues the
  browser can't see); `wp eval '$v = new \NvoosContentGraph\Commerce\Vendor( \NvoosContentGraph\Commerce\Payments::vendorApiUrl() ); …'`
  runs the plugin's own `health()`/`createSession()` on the site's server.
- **Live config facts (verified):** vendor `GET /health` is public;
  `POST /session` requires `product` ∈ {`nvoos-oos-complete`,
  `nvoos-content-graph-ai`} + `site_url`; a webhook signature test =
  HMAC-SHA256 over `{ts}.{payload}` with the dashboard `whsec_` sent as
  `Stripe-Signature: t={ts},v1={hex}` — a benign `charge.succeeded` event
  answering `{"received":true}` proves the dashboard secret matches the
  plugin's stored one; the Stripe webhook endpoint must subscribe
  `payment_intent.succeeded` + `charge.refunded` (+ optional
  `charge.dispute.created`); the client's commerce REST routes are
  **admin-only by design** (anonymous 403/401 is normal); if the base NV oOS
  plugin is already active on the client site (`mcp-ai/v1` in `/wp-json/`),
  the post-payment install correctly 409s (conflict guard) with a
  manual-download link — purchases on such a site need no install.

## Calendar Query Fix, Email Formats & Wave F2 PM/Calendar (v1.1.74+)

- **Google Calendar date queries** (PR #6460) — calendar query values are
  now `rawurlencode()`d before `add_query_arg()` (the raw `+` in RFC3339
  offsets decoded as a space → 400); `calendar.freebusy` in the Standard
  scope profile (new grants only).
- **Result Delivery email formats** (PR #6465) — new
  `WP_MCP_AI_Markdown_Converter` (escaped + `wp_kses` allowlist +
  protocol-allowlisted links) + per-channel `format` setting (`both`
  default | `html` | `markdown`).
- **Schedule Manager assistant prompts** (PR #6469) — the edit modal shows
  and updates `assistant_run` prompts; `update_pro_schedule` accepts
  `assistant_config` via MCP.
- **Wave F2 PM + calendar-booking ports** (PRs #6450–#6472) —
  `nvoos-content-graph-pro` (1.0.0) completes both toolkit ports;
  perf-suite MCP-abilities fix process-wide in `tests/bootstrap.php`
  (#6470); content-graph-pro excluded from the root WPCS gate (#6457).
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Woo Tool Upgrades, Queue Bootstrap Fix & Wave F2 (v1.1.73+)

- **`bulk_update_products` variable scope** (PR #6447) — new `scope`
  argument (`all` default, `product` legacy) expands price/stock fields
  from variable parents to variations and grouped parents to children
  (`resolve_update_targets()` + `WC_Product_Variable::sync()`); the
  response reports `targets[]` per input ID + `scope`/`updated_targets`
  keys (acknowledged shape change).
- **`update_woo_product_qty` notify flag** (PR #6448) — `notify` (default
  `true`) suppresses low/no-stock emails for the write via scoped
  `woocommerce_should_send_*` filters removed in a `finally`;
  `woocommerce_*_stock` actions still fire.
- **Async job queue table bootstrap fix** (PR #6423) — the queue class now
  boots in time to create its table (activation + first-load self-heal;
  `get_queue_stats()` fails soft) — no more missing-table SQL floods.
- **Comic Reader 0.5.0** (PR #6402) — Komga-parity upgrade; **Docs Hub
  0.4.3** (PRs #6397/#6403) — wp.org prep; **Wave F2** (PRs #6397–#6445,
  #6449) — new `nvoos-content-graph-pro` v1.0.0 standalone addon (Pro CRM
  + e-commerce ports, 43 e-commerce tools).
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Woo Price/Qty Tools & Ecosystem Port Waves (v1.1.72+)

- **WooCommerce price & quantity tools** (PR #6388) —
  `update_woo_product_price` (regular/sale, all product types) and
  `update_woo_product_qty` (stock + management) share the new
  `WP_MCP_AI_Woo_Price_Qty_Updater` trait; `bulk_update_products` uses it;
  tool presets register the new slugs.
- **Scheduled sync connection ID** (PR #6386) — EZuite/FlowHub scheduled
  syncs deliver the assigned connection ID (the scheduled action was
  dropping it).
- **Pro update vendor integrity** (PR #6338) — "Update Pro Now" verifies the
  Pro package's `vendor/` before and after updating.
- **Container binding** (PR #6339) — `tool_registry` is registered
  `transient`; every `get()` resolves the live singleton (test-swap safe).
- **Deps + defaults** (PRs #6365, #6332) — browserslist/qs patched (12
  Dependabot alerts); `gpt-image-2` aligned across all three settings
  layers.
- **Ecosystem ports** (PRs #6330–#6387) — Wave D8 closes the standalone
  tool-execution gap in Content Graph AI; Wave E6 engine pieces fold into
  the AI addon; the platform addon closes Waves E2/E3/E5/E1/E4 +
  E-UI-1/2/3. Sub-project versions unchanged (1.0.4 / 1.0.4 / 2.0.0).
- **Tool count** — +2 Pro: ~303 base + ~1,265 Pro (~1,568 total).

## Rate-Limit Unlock, Model Catalog & Ecosystem (v1.1.71+)

- **REST rate-limit unlock** (PR #6322) — `check_rate_limit()` uses fixed-window
  accounting (`{count, first_seen}` payload; TTL never extended past window end)
  with honest remaining-time `retry_after`; the new
  `wp_mcp_ai_rest_request_rate_limit_exceeded` action flags the restriction
  registry, so blocked users appear in Command Center → Restrictions with the
  Lift button (lifting also clears the `wp_mcp_ai_rate_limit_user_{id}`
  transient; guest IP-keyed blocks expire on their own).
- **MemPalace wing scope** (PR #6327) — `wake_up_context` enforces
  `wing`/`room` exclusions (`matches_wake_filters()`); Graphify graph anchors
  only boost scores and never excluded out-of-scope memories.
- **September 2026 model catalog** (PR #6328) — 228 models; new defaults
  `default_gemini_model` → `gemini-3.6-flash`, `openai_image_model` →
  `gpt-image-2`, Kimi default → `kimi-k3`; retired DeepSeek chat/reasoner/coder,
  `gemini-3.1-flash`, `imagen-4` have migration-map successors.
- **Checkout API** (PR #6315) — addon joins the standard build + PHPUnit
  pipeline; token/crypto classes derive `wp_salt()` salts (never raw
  `AUTH_KEY . SECURE_AUTH_KEY`). Connectors links → `options-connectors.php`
  (PR #6314). Coding-time agent skills: 53 → **54** (`mcp-ai-wpoos-updates`).
- **Tool count** — unchanged: ~303 base + ~1,263 Pro (~1,566 total).

## Wave-5 Repair Campaign & Host-Hardening Fixes (v1.1.70+)

- **exec-disabled hosts** — on PHP 8+ a disabled function throws a fatal
  `Error` that `@` cannot suppress; never call `exec`/`shell_exec`/`proc_open`
  unguarded. Use `wp_mcp_ai_check_nodejs_available()` /
  `wp_mcp_ai_get_nodejs_version()` (exec-first, Process Service fallback) and
  the OCR service's `is_cli_tool_available()` / `run_cli_command()` helpers;
  sanitizers clamp with `max( 0, … )`, never `absint()` (it flips negatives).
- **Wave-5 fixes** (PRs #6280–#6312) — transcript `attachments` metadata
  preserved; complexity-based model routing restored; JetEngine gates require
  `JET_ENGINE_VERSION` + a physical CCT-table probe (`is_storage_available()`);
  mesh peer URLs validated before `esc_url_raw()`; Auth0 audiences validated
  structurally (no DNS); image/chart permission checks use the **acting user**;
  markup REST validation errors return `400`; the legacy SSE handshake is
  filterable (`wp_mcp_ai_legacy_sse_enabled`); `wp_mcp_ai_pro_get_tool_map()`
  caches the Pro tool map; settings-repository reads fall back to the canonical
  `wp_mcp_ai_settings` blob so runtime gates see dashboard-saved values;
  agentic events reach the recent-activity feed.
- **Tool count** — unchanged: ~303 base + ~1,263 Pro (~1,566 total).

## Vision Analysis, tagDiv Compat, DeepSeek Schemas & ZipSlip Revival (v1.1.69+)

- **Vision Analysis toolkit (Pro)** — new `analyze_image_objects` tool
  counts objects per category (HF OWLv2 / Ollama `detection`, VLM `vlm`,
  hybrid label normalization; `annotate=true` returns a GD box-annotated
  attachment). Gated by `enable_vision_analysis_toolkit` on the NV oOS →
  Vision Analysis settings page — **off by default**; remote image URLs go
  through the SSRF URL guard. Docs: `docs/toolkits/vision-analysis-toolkit.md`.
- **tagDiv Newspaper admin compat** — the four SiteKit tools return string
  capability-flag arrays (the old `CAPABILITY_CAN_USE_IF_ADMIN` constant
  never existed and fatalled the assistant tools metabox at
  `sitekit_get_adsense`); the sortable shim prints a bundled jQuery UI
  1.14.2 copy whenever `td_wp_admin` is enqueued (detected via the print
  queue — a registered+enqueued core handle can still fail to execute);
  tools metabox CSS is enqueued on `admin_enqueue_scripts` so it prints in
  the head.
- **Tool schemas: `properties` must be an object** — argument-less tools
  encode `"properties": {}` (never `[]`; DeepSeek returns 400 otherwise).
  `LegacyToolAdapter` preserves object maps and upgrades empty arrays; the
  AI Tool Builder scaffold emits `new stdClass()` for parameterless tools.
- **ZipSlip guard** — `ZipArchive::$num_files` does not exist on PHP 8.x;
  the entry loops must use `count( $zip )` (OKF bundle manager + four Pro
  admin pages). The guard was silently dead code.
- **Rate limiting** — `check_rate_limit()` classifies internal dispatches by
  the dispatching request's real HTTP verb; the nefarious monitor keeps its
  own `wp_mcp_ai_nefarious_rate_limit_` counter (never share the chat REST
  limiter's — it halves the chat budget). `wp_mcp_ai_before_chat_request`
  subscribers tolerate the legacy 2-arg emitter shape.
- **Settings save** — capture `wp_suspend_cache_addition()` state *before*
  suspending (the function returns the new state, so reading it after
  suspends re-applies `true` and jams inline-async tick locks on WP 7.1).
- **Tool count** — ~303 base + ~1,263 Pro (~1,566 total).

## Front-End Surfaces, Nonce Self-Heal & Provider Defaults (v1.1.68+)

- **Pro SPA v2 shortcode** — `[nvoos_pro_spa]` embeds the Pro SPA v2 chat
  surface on the front end (chat-first embedded mode: threads, drawers,
  tool shortcuts, OKF drawer; router-free; optional guest mode behind the
  "Allow Guest Access" setting). Proposal 033.
- **Hermes dashboard fleet extensions** — new top-level `extensions/` tree
  (fleet monitoring + control plane, backup-download, external-app-tab,
  mcp-tool-shortcuts; `install.sh` + smoke tests). Plans in
  `docs/developer/integration/`.
- **Stale REST nonce self-heal** — `GET /mcp-ai/v1/session/nonce` mints a
  fresh session-bound nonce from the request's own auth cookie (`no-cache`/
  `no-store`); chat surfaces retry `403 rest_cookie_invalid_nonce` failures
  instead of showing "Cookie check failed" (full-page caching / SPA session
  rotation).
- **Docs Hub 0.4.2** — local-page links resolve to in-app `#/slug` routes,
  TOC anchors match github-slugger, "Accept fix" suggestions are
  directory-relative with `../` validation + skip reasons, sync failures
  surface "Atomic swap failed", and the emoji loader no longer crashes the
  React SPA on docs-browser pages.
- **Provider enable defaults (fresh installs)** — OpenAI/Anthropic/Gemini
  now default to **disabled**; provider dropdowns list only enabled +
  credentialed providers (`get_available_providers()`); the onboarding
  wizard auto-enables the provider whose key you enter. When helping users
  with "no providers available", check both the key **and** the enable
  checkbox.
- **Grouped fixes (third test wave, ~21 PRs)** — calendar granted-scopes
  `%20` normalization; agent-identity canonical-ID int cast; token-budget
  catalog dedup + `wp_mcp_ai_model_tpm_limit` filter seam; assistant
  untrash restores the pre-trash status; presets gain missing tools;
  `wp_mcp_ai_seed_task_templates` AJAX; `fast-uri` >=4.1.4; Tiptap 3.30.4
  pins; jQuery UI sortable shim on post edit screens. Tool count unchanged:
  ~303 base + ~1,262 Pro (~1,565 total).

## Platform Extraction v2.0.0, Ecosystem Port Wave D + D-UI & Google Workspace Read Tools (v1.1.67+)

- **Content Graph platform extraction (v2.0.0)** — the
  `nvoos-content-graph-ai-platform` addon now carries its own business
  logic (Waves A–C + Blueprints: namespace-bridged admin UI, skill/slash-
  command/agent bridges, harness router, 74-skill bundled-skills pack,
  knowledge base, `.github/workflows/phpunit-platform.yml`). Plan:
  `docs/project/plans/content-graph-platform-extraction-plan.md`.
- **Base+Pro → Content Graph ecosystem port (Wave D + D-UI)** — the AI
  runtime lands in `nvoos-content-graph-ai` (chat core, providers beyond
  the 13, model management + analytics/token tracking, security guards,
  assistant admin pages, blocks/widgets/guest tokens/memory/CLI/MCP
  JSON-RPC); **Content Graph AI bumps 1.0.3 → 1.0.4**. Tracker:
  `docs/project/ecosystem-port-tracker.md`.
- **Google Workspace Gmail + Drive read tools (Pro)** — six new Pro tools
  (`get_gmail_message`, `get_gmail_thread`, `list_gmail_connections`,
  `modify_gmail_message` — destructive-ops gated — and `get_drive_file`,
  `list_drive_connections`) with new `WP_MCP_AI_Pro_Gmail_Client` /
  `WP_MCP_AI_Pro_Google_Drive_Client` clients on the shared
  `includes/google/` foundation.
- **PHPUnit repair campaign continuation (~95 PRs, Aug 31 – Sep 2)** — a
  second cluster wave kept the suite green through the extraction/port
  work; the `mcp-ai-wpoos-test-suite` skill now distills 26 root-cause
  patterns. Production seams to know about: `unregister_section()` on the
  settings registry; public `get_tool_multiplier()`; null-safe REST
  tool-error reporting; WhatsApp webhook signature rejection without an
  app secret; destructive-ops gate reads the canonical combined-settings
  array; crawler `base_url` validation + `wp_mcp_ai_crawl4ai_auto_spawn_cron`
  filter. Tool count: ~303 base + ~1,262 Pro (~1,565 total).

## Test-Suite Campaign, REST Hardening & Content Graph AI (v1.1.66+)

- **PHPUnit repair campaign (Aug 28–31, ~100 PRs)** — the single-process
  suite was repaired cluster-by-cluster; the recurring root causes and the
  cluster-PR workflow are catalogued in the sibling
  `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md` skill, with the
  standing tracker `docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`.
  Coding-time agent skills: 53.
- **Assistant-access caching** — `validate_assistant_access()` caches
  `WP_Error` results like successes; the cache-disable path is the
  `wp_mcp_ai_assistant_access_cache_enabled` filter (never a persisted
  `WP_MCP_AI_DISABLE_CACHE` define from a test).
- **REST/auth hardening** — attachment-segment validation errors return
  explicit HTTP 400s; token-tier endpoint + tier-change audit logging
  fixed; REST permission-callback allowlist refreshed; bearer-auth context
  synced across Simple JWT + assistant-access paths; guest tokens are
  origin-bound.
- **Job queue & notifier** — closure serialization fixed in legacy option
  storage; custom-table queries guard against missing schema (Graphify DB,
  job store, tenant DB); `update_status()` restored with dot-preserving
  job IDs and owner-scoped REST routes; progress events promote cached
  status to running.
- **Tool contract fixes** — Media Toolkit tools return canonical `WP_Error`
  envelopes; Remove Background gained a path guard; memory-capture failure
  envelope restored; web-search result building fixed for Exa/Perplexity;
  auto-categorize router/client fixed.
- **Content Graph AI 1.0.3** — `nvoos-content-graph-ai` standalone plugin
  bumped to 1.0.3 with a tool permission-check fix; `nvoos-content-graph`
  stays 1.0.3, Media Worker stays v3.2.0.

## Google Calendar Connection & Composio Hardening (v1.1.64+)

- **Google Calendar connection (Base + Pro)** — new shared foundation in
  `includes/google/` (OAuth service, Calendar v3 client, scope registry,
  credential resolver, sync + push) replaces four drifted Google OAuth
  start/callback copies; a `google_calendar` connection type exists on both
  surfaces (base Settings → Integrations, Pro Remote Sites). Six new Pro
  tools in `addons/pro/includes/tools/google-workspace/`:
  `list_google_calendars`, `list_google_calendar_events`,
  `update_google_calendar_event`, `delete_google_calendar_event`,
  `check_google_calendar_availability`, `quick_add_google_calendar_event` —
  credentials resolve from an optional `connection_id` or site-level settings,
  and every write passes scope enforcement. Docs:
  `docs/developer/architecture/integrations/google-calendar-connection.md`.
- **Composio account health + hardening (Pro)** — verified account-health
  engine (`WP_MCP_AI_Composio_Account_Health`) with live catalog-discovered
  probes; new seventh tool `composio_manage_accounts`
  (validate/reconnect/delete/prune); proxied provider 401/403 → reconnect
  guidance; zero-argument calls send `arguments: {}`; Health column +
  Verify/Reconnect in Remote Sites.
- **Log hygiene** — tools declare non-loggable result fields via
  `WP_MCP_AI_Tool_Sensitive_Result_Interface` (`get_sensitive_result_fields()`,
  logging-only masking); credential-bearing URL query params are redacted
  from every logged string; rolling log buffers get per-entry byte budgets
  plus Data Management Compact/Delete.
- **Vision tools timeout** — `analyze_image`, `extract_image_text`,
  `generate_image_alt_text`, `generate_image_caption` accept a 5–300s
  `timeout` and inherit the global `request_timeout` (fixes cURL error 28 on
  large images).

