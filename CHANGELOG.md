# oOS – Changelog

## [1.1.27] - 2026-06-05

### Added — Funiq Bridge Addon (`addons/funiq-bridge/`, v1.0.0)

- New standalone addon bridging the Funiq React PWA frontend (built for Payload CMS) to WordPress.
- **Payload-compatible REST API** at `/wp-json/funiq/v1/` — 7 collections (products, categories, brands, colors, statuses, promotions, promocodes) plus 2 globals (banner, carousel) with Payload-paginated response shapes.
- **Custom Post Types** (`funiq_product`, `funiq_promotion`, `funiq_promocode`) and **Custom Taxonomies** (`funiq_category`, `funiq_brand`, `funiq_color`, `funiq_status`).
- **React SPA Admin Panel** embedded in WP Admin (`Funiq CMS` menu) — config-driven list/create/edit/delete for all collections, WordPress Media Library image picker, relationship selectors. Ships pre-compiled; no build step required.
- Public GET endpoints; `manage_funiq` capability gate on writes (granted to Admin + Editor on activation).
- Full `uninstall.php` cleanup (posts, terms, options, capabilities).
- PHP 8.1+, WordPress 6.7+.

### Added — Real-Time SSE Streaming (PRs #5240, #5243, #5244)

- Real-time SSE streaming enabled for OpenAI, DeepSeek, and all OpenAI-compatible providers.
- "Disable Native Streaming" setting added to Advanced → System tab, controllable per-site.
- `wp_mcp_ai_disable_native_streaming` filter for programmatic control.
- WPCS violations fixed in streaming provider clients.

### Added — OOS Core Tool Migration: 35 New Tools (PR #5246)

35 new OOS core tools migrated with full test coverage and documentation:
- **Data Tools:** GetPostTaxonomies, CountPosts, GetPostMeta, TruncateText, MergeArrays.
- **Format Tools:** FormatDate, TimeAgo, ParseCsv, MathEval, ColorConvert.
- **Infrastructure Tools:** EventDispatcher (5 tools), Queue tools (5 tools, 5 tests).
- **Cache Tools:** 5 cache-management tools + DeleteSettingTool (6 tests).
- OOS/core test infrastructure established with 20 migrated base tools.

### Added — Extended Cognition Vision Recognition (PR #5237)

- Visual product/brand recognition added to Extended Cognition toolkit.
- Camera viewfinder UI with real-time detection overlays and consent gate.
- Viewfinder enhanced with camera switcher, torch control, scan region, and file upload.

### Fixed — Graphify Tools (PRs #5237, #5238)

- Added missing `WP_MCP_AI_Tool_Default_Capability` trait to all Graphify tools.
- Added explicit `get_required_capability()` method to all Graphify tools for Capability Fence compliance.

### Fixed — JetFormBuilder Submission Tools (PRs #5244, #5245, #5247, #5248, #5249, #5250, #5251, #5253)

- Fixed `get_all_form_submissions` returning empty results for JetFormBuilder forms.
- Fixed JFB submissions returning empty for non-admin users — direct DB fallback query.
- Fixed `get_all_form_submissions` falling back to direct DB query for local form discovery.
- Fixed JFB submission tools: capability ordering and form discovery pipeline.
- Fixed PHPCS warnings across all JFB tool files.
- Fixed JFB REST routes to match actual JetFormBuilder plugin endpoints.
- Fixed form-type auto-detection for JetFormBuilder vs Elementor submissions.
- Fixed JetFormBuilder plugin detection: use namespaced class `Jet_Form_Builder\Plugin` and add to status list.
- Added JetFormBuilder integration reference documentation (`docs/features/integrations/jetformbuilder-integration-guide.md`).

### Fixed — DeepSeek Agentic Tool Result Handling (PR #5247)

- Added tool message filtering and payload normalisation to DeepSeek client for agentic multi-turn workflows.

### Fixed — Documentation Links (PR #5239)

- Fixed broken documentation links across the codebase after Unix-theory `docs/` reorganization.

### Changed — Model Pricing Update (PR #5256)

- Updated model pricing across all 13 providers to reflect June 2026 rates.

### Changed — Plugin Restructuring Proposals (PRs #5252, #5255)

- Added graphify-core specification and base plugin restructuring roadmap.
- Updated restructuring proposals to v3.0 Graphify-centric architecture.

## [1.1.26] - 2026-06-03

### Added — Cross-Platform Extraction Engine Phases 0–2 (PRs #5193–#5201)

Framework-agnostic OOS core extracted from WordPress into a standalone PHP library (`lib/`):

- **Phase 0** — Monorepo foundations: `composer.json` with PSR-4 `OOS\` namespace, library structure, `lib/` excluded from plugin builds.
- **Phase 1** — WordPress adapters for all 8 domain interfaces: `WpAiServiceRepository`, `WpConfigRepository`, `WpEventDispatcher`, `WpHttpClient`, `WpLogger`, `WpSanitizer`, `WpToolRepository`, `WpUserRepository`.
- **Phase 2** — Core application layer: `AiService` orchestrator, `ConfigService`, `ToolExecutionPipeline`, `ChatSessionManager`, `SkillRegistry`, `AbstractTool` base class. All 12 AI provider clients migrated with domain injection. 33 tools migrated across 3 tiers.
- OOS bridge wired into WordPress with feature flag (`WP_MCP_AI_OOS_ENGINE_ENABLED`).

### Added — Site-Builder Node-Graph Pipeline Phases 1–4 (PR #5231)

Visual site construction subsystem with node-graph architecture for building complete WordPress sites programmatically through AI tool chains. SPA blueprint v3.0 generated from pipeline output.

### Added — SPA a11y Hardening Phase 5 (PRs #5232, #5234)

axe-core accessibility testing integrated across all 7 SPA addons. Keyboard navigation, ARIA labels, focus management, and screen-reader support reviewed and hardened. CI type errors fixed (`@types/node`, comic-reader TS casts).

### Added — Screenshot Overhaul

137 automated Playwright screenshots captured across base + Pro. Screenshot inventory (79 tracked pages), maintenance plan, and coverage checker added.

### Added — Form Submissions Data Source (PRs #5206, #5207)

JetFormBuilder + Elementor forms integration for AI-powered submission analysis. Admin dashboard, PHPUnit tests, and PHPStan fixes.

### Added — Cloudways Dashboard SPA Addon v0.1.0 (PR #5214)

New React SPA addon for Cloudways server/app management with real-time status dashboard.

### Added — Laravel & Craft CMS Adapters (PR #5205)

OOS core extraction adapter packages for Laravel and Craft CMS, enabling standalone operation outside WordPress.

### Added — Reviewer Onboarding Documentation (PR #5226)

Complete reviewer documentation suite: `docs/project/FOR_REVIEWERS.md`, audit data cross-reference, stale data updates.

### Added — Blueprint Profession Roles (PR #5204)

6 missing profession definitions added. Professional roles assigned to CRM and healthcare-style blueprint assistants.

### Fixed — Pro Toolkits Security Audit Phase 1 (PR #5212)

9 HIGH-severity security findings resolved across pro toolkits: input validation, authorization checks, and output escaping.

### Fixed — OOS Engine Stability (PRs #5196–#5201, #5211, #5213)

PSR-4 event classes extracted from `DomainEvents.php`. Missing `ErrorFactoryInterface` import in 8 ported tools. Provider client constructor interface imports fixed. `psr/event-dispatcher` bundled. Parse errors and `CacheStore` bool cast `TypeError` resolved. OOS Gemini chat client tools string format error fixed. Team/profession layer integrated into chat handler.

### Fixed — Docker Dev Environments (PR #5216)

WordPress, Laravel, and Craft CMS Docker environments all fixed and operational. Docker directory excluded from PHPCS linting and plugin builds.

### Fixed — Test Infrastructure & PHPUnit Compat (PRs #5217, #5216)

95% of PHPUnit failures resolved across base, pro, and addon test suites. `class_exists` guards for Pro tool require paths. Removed nonexistent base path for WP-CLI tool test.

### Fixed — Infrastructure (PRs #5216, #5220–#5225, #5228, #5229)

TCPDF addon Composer autoloader class-name collision resolved. Pro vendor files committed with `classmap-authoritative` autoloader. `@puppeteer/browsers` detection path fixed. Pro vendor Composer install added to CI. NPM package status detection fixed. Pro addon vendor `.gitignore` unignored `symfony/yaml`. Duplicate build ZIPs removed. Shallow clone recommendation added.

### Changed — Documentation (PRs #5227, #5230, #5235)

`docs/` directory tree reorganized with Unix-theory separation of concerns. Per-folder READMEs restored and added to all active doc directories. README and docs updated for new organization and screenshot overhaul. Stale audit data cross-referenced against May 2026 compliance docs.

## [1.1.24] - 2026-05-28

### Fixed — Paper Store Pro Interface Load Order (PR #5160)

- Deferred Pro Paper Store class loading to the `wp_mcp_ai_bootstrapped` hook to resolve a race condition where Pro interfaces were loaded before the base plugin had fully initialised its autoloader and constants.

### Fixed — Assistant Tool Presets Coverage (PR #5159)

- Added 24 missing tools to assistant creation presets so new assistants get complete tool coverage by default.
- Fixed out-of-date tests that were asserting stale preset strings.

### Fixed — CVE: Tmp & Symfony Cache Dependencies (PR #5157)

- Bumped `tmp` to >=0.2.6 and `symfony/cache` to ^6.4.40 to resolve upstream CVEs.
- Fixed `composer.lock` source reference and committed production vendor state.
- Rebuilt all release ZIP artifacts.

### Fixed — Chat SPA Duplicate Messages & Markdown Rendering (PR #5155)

- Fixed a bug where the React chat SPA could show duplicate message bubbles under rapid SSE streaming.
- Added proper markdown rendering via the `marked` library for assistant responses in the SPA chat surface.
- Removed non-existent marked v9 options from `setOptions` calls.

### Fixed — Chat SPA SSE Protocol Mismatch (PR #5153)

- Adapted the chat SPA frontend to the OpenAI-compatible SSE format emitted by the server, resolving a protocol mismatch that caused silent message drops.
- Rebuilt `nvoos-chat-spa.zip` artifact.

### Fixed — Skill Manager Canonical Return Envelope (PR #5154)

- **Unix Theory P0/P1 refinement.** Fixed the skill manager to return the canonical envelope (`WP_Error` on failure, success array on success) instead of the legacy `array('success' => false, ...)` pattern.
- Added skills sync endpoint for idempotent import/export.
- Fixed YAML frontmatter parsing by quoting description fields that contain colons.

### Added — Paper Store Admin CRUD (PR #5147)

- Full CRUD admin UI for Paper Store collections and records under the Assistants menu, following the Skills admin convention.

### Added — CLI Coverage Enhancements (PR #5151)

- Comprehensive WP-CLI command coverage enhancements across the plugin toolchain.

### Added — Build & CI Infrastructure (PRs #5148, #5150)

- Added `build-spa-addons` GitHub Actions workflow for automated SPA addon ZIP generation.
- Restored missing SPA addon ZIPs in the `build/` directory.
- Rebuilt all SPA addon bundles and ZIPs to current versions.

### Changed — Documentation (PRs #5149, #5152)

- **Unix Theory P7 completion.** Added folder READMEs for every PHP-bearing subdirectory across `includes/` and `addons/pro/includes/`, completing the Folder README Convention (P7).
- Synced `CLAUDE.md`, `AGENTS.md`, and agent context files with recent features (v2.4/v1.4 of the agent context system).

## [1.1.23] - 2026-05-26

### Added — Zed-Inspired SPA Architecture (Pro)

Comprehensive React Single Page Application admin interface inspired by the [Zed code editor](https://zed.dev/)'s design patterns. All new features are additive — the existing jQuery chat UI and all existing REST endpoints are untouched.

- **Threads Sidebar (Zed-equivalent: Threads Sidebar)** — Left-docked panel showing agent conversations grouped by scope. Create, select, archive, restore, and compact (summarize) threads. Multiple parallel agent threads supported.
- **Agent Panel (Zed-equivalent: Agent Panel)** — Full-height conversation view with SSE streaming chat responses, tool call cards, message editing, and follow-agent auto-scroll.
- **Command Palette (Zed-equivalent: Cmd+Shift+P)** — Universal action launcher (`Cmd+K` / `Ctrl+K`) with fuzzy search across 830+ tools, threads, navigation, and actions.
- **Agent Profiles (Zed-equivalent: Write/Ask/Minimal profiles)** — Built-in Write/Ask/Minimal tool permission profiles plus custom profiles with per-tool allow/deny/confirm patterns and glob-style pattern matching.
- **@-mention Context (Zed-equivalent: @-mention autocomplete)** — Type `@` in the message editor to mention posts, tools, skills, threads, files, users, terms, and settings. Autocomplete with debounced search and keyboard navigation.
- **Checkpoints & Diff Review (Zed-equivalent: Restore Checkpoint)** — Automatic state snapshots on every agentic turn. One-click restore to any checkpoint. Accept/reject individual change hunks with before/after diff visualization.
- **Inline Assistant (Zed-equivalent: Ctrl+Enter)** — Gutenberg sidebar plugin for inline AI text transformation. Select text in the block editor → describe transformation → model rewrites in place or inserts after.
- **Multi-Model Comparison (Zed-equivalent: inline_alternatives)** — Send the same prompt to multiple AI models simultaneously (GPT-4o, Claude, Gemini, etc.) and compare responses side-by-side with timing badges. Select the best response to populate the editor.
- **Collaborative Presence (Zed-equivalent: multiplayer indicators)** — Real-time user presence tracking via WordPress Heartbeat API and REST polling. Avatar stack showing other active editors with activity descriptions.

### Added — Base Plugin Thread Management Infrastructure (Base)

PHP infrastructure shared by both the existing jQuery chat UI and the new Pro React SPA:

- **Thread Manager** (`WP_MCP_AI_Thread_Manager`) — Full CRUD for agent conversation threads with ownership, scoping, archival, summarization, and message history. Both UIs consume the same REST API.
- **Profile Manager** (`WP_MCP_AI_Profile_Manager`) — Tool permission profiles with resolution algorithm (always_deny → always_allow → denylist → allowlist → default). Filter tools sent to LLM by profile.
- **Checkpoint Manager** (`WP_MCP_AI_Checkpoint_Manager`) — WordPress entity state snapshots (posts, options, terms, users, comments) with restore and diff computation.
- **Context Mention Resolver** (`WP_MCP_AI_Context_Mention_Resolver`) — @-mention type resolution and autocomplete for 8 entity types (post, tool, skill, thread, file, user, term, setting). Extensible via `register_type()`.
- **Command Registry** (`WP_MCP_AI_Command_Registry`) — Universal action palette with 830+ auto-registered tool commands. Extensible via `wp_mcp_ai_commands` filter.
- **Database Schema** (`WP_MCP_AI_Threads_Schema`) — 4 new tables: `wp_mcp_ai_threads`, `wp_mcp_ai_thread_messages`, `wp_mcp_ai_checkpoints`, `wp_mcp_ai_profiles`. Created via `dbDelta()` on plugin update.
- **5 REST Controllers** — Threads (9 routes), Profiles (9 routes), Checkpoints (4 routes), Context Mentions (2 routes), Commands (1 route).

### Added — Pro REST Endpoints

- `GET /mcp-ai-pro/v1/spa/bootstrap` — Single-request SPA initial data (threads, profiles, tools, commands, settings, user).
- `POST /mcp-ai-pro/v1/inline/transform` — Single-turn text transformation for Gutenberg Inline Assistant.
- `POST /mcp-ai-pro/v1/threads/{id}/compare-models` — Multi-model parallel dispatch with timing and error capture.
- `GET/POST /mcp-ai-pro/v1/collaboration/presence` — Real-time user presence tracking.
- `GET /mcp-ai-pro/v1/model-alternatives` — Available alternative models for comparison.

### Technical

- **~75 files, ~10,800 lines** across Base (PHP 7.4) and Pro (PHP 8.1 + React SPA).
- React SPA built with `@wordpress/scripts`, Zustand state management, hash-based routing.
- All existing functionality preserved — new admin page at `wp-mcp-ai-spa` coexists with original `wp-mcp-ai` page.
- PHPCS: 0 errors, 0 warnings across all 18 new PHP files.

### Changed — Antigravity Managed Agents API Rewrite

Rewrote the Gemini Managed Agent integration to use the actual Antigravity Interactions API (`POST /v1beta/interactions`) instead of the speculative pre-release agents API assumed at Google I/O 2026.

- **`WP_MCP_AI_Gemini_Managed_Agent_Service` (rewrite)** — Replaced speculative `/v1beta/agents` endpoints with the real Interactions API. New `send_interaction()` replaces the two-step create/run pattern. Added `continue_interaction()`, `download_environment_files()`, `list_environments()` / `forget_environment()`, and `create_managed_agent()` / `build_agent_environment()` with inline, GitHub, and GCS source support. Added `Api-Revision: 2026-05-20` header. Added SSE streaming via cURL-based SSE parser with callback support. Removed deprecated `resolve_tool_definitions()` — Antigravity uses its own built-in tools (`code_execution`, `google_search`, `url_context`).
- **`WP_MCP_AI_Gemini_Client`** — New endpoint constants: `API_INTERACTIONS_ENDPOINT`, `API_MANAGED_AGENTS_ENDPOINT`, `API_ENV_DOWNLOAD_ENDPOINT`.
- **`WP_MCP_AI_Tool_Run_Gemini_Managed_Agent` (rewrite)** — Operations changed to send/continue/stream/download/envs. Parameters: `input`, `interaction_id`, `environment_id`, `system_instruction`, `agent_tools`, `agent_id`, `save_path`. Token multiplier increased to 15x.
- **Admin UI** — `enable_managed_agents` toggle added to Settings → Providers → Gemini and Settings → Orchestration.
- **Documentation** — Updated `GEMINI_CAPABILITIES_MATRIX.md`: Managed Agents, Code Execution, and Grounding marked as ✅ Implemented via Antigravity.

### Added — TypeScript Upgrade & Orchestration Toggles

- **TypeScript Upgrade** — Shared types, services, admin screens, chat drawer, and React SPA builds compiled via TypeScript. Pre-built TS outputs under `assets/js/dist/`.
- **Orchestration Toggle** — "Use TypeScript-Compiled Assets" checkbox in Settings → Orchestration. Reads from `WP_MCP_AI_Settings_Registry` with `WP_MCP_AI_USE_TS_BUILD` constant fallback.
- **Antigravity Orchestration Toggle** — `enable_managed_agents` now accessible from both Settings → Providers → Gemini and Settings → Orchestration → Settings.

### Added — New Addons

- **Comic Reader (`addons/comic-reader/`)** — React-based comic book reader. Supports CBR, CBZ, CB7, CBT formats with dual reading modes, zoom controls, keyboard navigation, touch support, fullscreen, progress persistence, and drag-and-drop upload. Shortcode `[nvoos_comic_reader]` + Gutenberg block.
- **Media Studio v0.3.0 (`addons/media-studio/`)** — Zoom/pan controls, drawing tools (Konva canvas with brush, eraser, shapes, text, undo/redo), and save-to-WP-Media-Library integration. Image editor mode now feature-complete.

### Fixed — Reliability & Compliance

- **Cron Status Diagnostics** — REST fetch errors now include HTTP status code and up to 500 chars of response body (e.g. `HTTP 403: rest_cookie_invalid_nonce`).
- **PHP 8.2+ Compatibility** — Declared `$namespace` and `$rest_base` properties in `WP_MCP_AI_REST_Controller_Base` to prevent dynamic property deprecation warnings.
- **PHP Comments Leaking** — Moved `phpcs:ignore` and `translators` comments inside `<?php` tags to prevent leaking into HTML output.
- **WordPress.org Re-submission Compliance** — All May 26 re-audit findings resolved (section 14 added to compliance doc).
- **May 2026 Audit Findings** — Resolved F-AUTHZ-05, F-AUTHZ-06, F-AGENT-01.
- **PHPCS** — 353 errors resolved to 0 across 18 files.
- **PHPUnit 11 Compatibility** — 6 comprehensive fix batches across base, pro, and addon test suites. Resolved class-not-found errors, dynamic property warnings, and WPDieException constructor errors.
- **Docs Hub** — Fixed browse-repo critical error, added hierarchical folder tree picker, hardened DNS resolution, added `autocomplete="off"` to settings inputs.
- **Net Worth Calculator** — Removed stray `*/` causing parse errors in financial & CRM tools.
- **qs DoS Vulnerability** — Resolved CVE-2026-8723 by forcing `qs >=6.15.2` via npm overrides.
- **DeepSeek Provider Fallback** — Detects first enabled provider instead of hardcoding 'openai'.
- **Test Suite Stability** — wp_die handler at `PHP_INT_MAX`, `DOING_AJAX` defined in 9 test files, SQLite DB cleanup, trait/class collision resolution.
- **Dev Dependencies** — `wp-phpunit/wp-phpunit` 6.9.4→7.0.0, `php-stubs/wordpress-stubs` 6.9.1→7.0.0.

## [1.1.22] - 2026-05-23

Bumped to 1.1.22 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

### Added — Baseten AI Provider (11th First-Class Provider)

- **`WP_MCP_AI_Baseten_Client`** — full provider integration with OpenAI-compatible API at `https://api.baseten.co/v1` (PR #5067). Chat completions, tool/function calling, JSON mode, SSE streaming, reasoning content passthrough. Settings → Providers → Baseten subtab; Provider Diagnostics card; Model Discovery `baseten` branch.
- **Catalog entries + provider badges** — Baseten model entries seeded in `model-catalog.json`; provider logo badges registered; CCT options updated.
- **External service documentation** — terms/privacy URLs corrected to working paths; added to `docs/reference/EXTERNAL_SERVICES.md` and `readme.txt` service disclosures. Provider now listed as 11th first-class AI provider alongside OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, and Ollama.

### Added — CoSAI Secure-by-Design Agentic System (May 2026)

Industry-standard secure agent infrastructure aligned with the [CoSAI Principles for Secure-by-Design Agentic Systems](https://www.coalitionforsecureai.org/announcing-the-cosai-principles-for-secure-by-design-agentic-systems/) (July 2025) and the [MCP Security paper](https://github.com/cosai-oasis/ws4-secure-design-agentic-systems) (January 2026). Part of the Gemini I/O 2026 feature drop:

- **Principle 2 — Bounded & Resilient**: `WP_MCP_AI_Agent_Capability_Boundary` (`includes/agents/class-wp-mcp-ai-agent-capability-boundary.php`). Immutable per-session tool allow-lists with rate limiting (sliding window), iteration caps via existing `wp_mcp_ai_max_agentic_iterations` filter, budget exhaustion detection, and transient-backed execution tracking. Filters: `wp_mcp_ai_capability_boundary_allow_tool`, `wp_mcp_ai_capability_boundary_rate_limit`.
- **Principle 3 — Transparent & Verifiable**: `WP_MCP_AI_Agent_Audit_Trail` (`includes/agents/class-wp-mcp-ai-agent-audit-trail.php`). Cryptographic chain-of-custody audit trails (SHA-256 linked events), dual storage (CPT `mcp_ai_audit_event` + options fallback), immutable events (closed trails reject writes), auto-pruning (configurable retention), OpenTelemetry-compatible schema (`trail_id` ↔ trace_id), session/assistant indexing for Agent Command Center feeds. Hooks: `wp_mcp_ai_audit_trail_store_event` (external forwarding), `wp_mcp_ai_audit_trail_event_stored`.
- **Principle 1 — Human-Governed**: `WP_MCP_AI_Agent_Approval_Gate` (`includes/agents/class-wp-mcp-ai-agent-approval-gate.php`). Risk-tiered approval gate (low/medium/high/critical) with auto-approval for low-risk, pre-approved pattern matching for medium, pending-approval queue for high, and explicit override for critical. Integrates with existing `WP_MCP_AI_Pro_Agent_Command_Center` via `wp_mcp_ai_approval_decided` action. Filters: `wp_mcp_ai_agent_approval_auto_approve_risk`, `wp_mcp_ai_agent_approval_critical_override`.
- **MCP-T3/T5 Sandbox**: `WP_MCP_AI_Agent_Code_Sandbox` (`includes/agents/class-wp-mcp-ai-agent-code-sandbox.php`). Isolated code execution for Python, Node.js, Bash, and PHP (restricted). `proc_open`-based with non-blocking I/O, timeout enforcement (`SIGKILL`), output size caps (1MB stdout / 256KB stderr), `open_basedir`-aware temp directories, marker-gated cleanup, stripped environment (no network access by default). Filters: `wp_mcp_ai_sandbox_allowed_languages`, `wp_mcp_ai_sandbox_max_timeout`, `wp_mcp_ai_sandbox_execution_env`.

All four CoSAI components are **provider-agnostic** — they work with OpenAI, Anthropic, Gemini, Ollama, or any future provider. The existing Gemini-only `WP_MCP_AI_Gemini_Managed_Agent_Service` remains for Gemini-native Managed Agents API access. The agent roles system (`planner`/`executor`/`critic`/`base`) already exists in `includes/agents/` — the new classes extend this architecture without duplicating it.

### Added — Continual Harness — Self-Improving Agent System (P5)

New self-improving agent infrastructure as part of the Gemini I/O 2026 feature drop. Enables agents to learn from execution history, refine strategies over successive runs, and improve tool selection accuracy through feedback loops. Integrated with the CoSAI audit trail for transparent improvement tracking.

### Added — SaaS Controller Phase 2 & 4 (PR #5068)

- **Phase 2 — Stripe deployment editor.** Operator-side UI for managing Stripe products, prices, and webhook configurations from WP-Admin.
- **Phase 4 — OpenRouter deployment editor.** Operator-side UI for managing OpenRouter API key provisioning and model routing configurations.
- Completed the SaaS Controller Phase 2–4 roadmap alongside the existing Phase 1 (Credentials Wizard) and Phase 3 (Cloudflare topology editor).

### Added — npm Packages: nvoos-vad, nvoos-chat-bubble, nvoos-chat-memory-ui (PR #5063)

- **nvoos-vad** — Voice Activity Detection package. Browser-based VAD with configurable sensitivity, silence detection, and speech-segment callbacks. `dist/nvoos-vad.js` + TypeScript declaration.
- **nvoos-chat-bubble** — Floating chat bubble widget for standalone embedding. Self-contained CSS/JS with `nvoos-chat-bubble` custom element. Includes toggle, minimize, and position controls.
- **nvoos-chat-memory-ui** — Chat memory drawer UI component. Standalone React component for memory browsing, search, and audit views. Published alongside `nvoos-audio` peer dependency update.
- All packages published to npm under the `@nvdigitalsolutions` scope with adapt-for-npm.js build scripts, `dist/` TypeScript declarations, and separate `package.json` manifests.

### Added — WordPress Studio Test Environment Support (PR #5072)

- New `wp-tests-config.php` with WordPress Studio detection and configuration.
- Updated `tests/bootstrap.php` to auto-detect Studio's database credentials, ABSPATH, and site URL from environment.
- Complements the existing Local by Flywheel, wp-env, and Codex environment configurations.

### Added — Optional Components Banner + Release Build Pipeline (PR #5065)

- Fixed the optional components admin banner to correctly display available addon components.
- Created release build pipeline for generating distribution-ready plugin artifacts.

### Added — Gemini I/O 2026 Model Refresh

- **Gemini 3.5 Flash** added to `model-catalog.json` as the new recommended Gemini model (May 2026 GA). Outperforms Gemini 3.1 Pro on coding and agentic benchmarks, 4x faster output, dynamic thinking enabled by default. 1M context, 65K output tokens. Pricing: $1.50/$9.00 per 1M tokens.
- **Gemini Omni Flash** added as the new video generation model (May 2026 GA), replacing Veo in the Gemini app. Any-to-any multimodal: text/images/audio/video → video with 10s duration, native audio, multi-turn conversational editing, and AI avatars.
- **Gemini 3.1 Flash** marked as deprecated with sunset date 2026-09-01; fallback updated to `gemini-3.5-flash`.

### Changed — Provider & Admin Settings

- Provider section fallback model list updated — `gemini-3.5-flash` now recommended; `gemini-3.1-flash` removed from dropdown.
- Video model settings: `gemini-omni-flash` added as default; Veo options marked as Legacy. Duration extended to 10s for Omni.
- Onboarding wizard default Gemini model changed from `gemini-3.1-flash` to `gemini-3.5-flash`.
- Ext Cog settings vision model options updated to `gemini-3.5-flash`/`gemini-3.1-pro`.
- Model config renderer: Added `gemini-omni-*` capability flag detection (vision + multimodal + video-generation).
- Cost calculator: `gemini-3.5-flash` pricing added — $1.50/M input, $9.00/M output, $0.15/M cached input.

### Fixed — Security: UUID Buffer Bounds Check (PR #5074)

- Overrode `uuid` dependency to `^9.0.0` in saas-controller to resolve a buffer bounds check vulnerability.

### Fixed — Security: map_meta_cap=false for Audit Trail CPT (PR #5076)

- Set `map_meta_cap=false` for the `mcp_ai_audit_event` custom post type to prevent a `delete_post` `_doing_it_wrong` notice in WordPress 6.1+. Follows the same pattern as the workflow CPT fix in PR #4822.

### Fixed — Security: Antivirus False Positives in Test Suite (PR #5069)

- Replaced mock malware payloads in `tests/test-skill-registry.php` with benign test data to avoid triggering antivirus false positives during development and CI runs.

### Fixed — Allowed Providers List Expansion (PR #5077)

- Added DeepSeek, OpenRouter, DigitalOcean, Kimi, and Baseten to the allowed providers list. These providers were previously functional but not listed in the provider validation gate, causing them to be blocked in certain admin contexts.

### Fixed — LM Studio External Service URLs

- Replaced all `lmstudio.ai` URLs with the GitHub organization URL (`github.com/lmstudio-ai`) after all `lmstudio.ai` paths began returning HTTP 500 errors.
- Updated Terms URL in `readme.txt` (500 → homepage), `docs/reference/EXTERNAL_SERVICES.md`, and the provider configuration.

### Fixed — Semantic Compression Settings Location (PRs #5056, #5057)

- Moved semantic compression settings from the Advanced tab to the Orchestration tab (Settings view) alongside other prompt-optimization controls. Applied across two PRs for complete coverage of the admin UI and settings persistence layer.

### Fixed — Overview Dashboard Inline CSS Render-Time Output (PR #5079)

- Overview dashboard inline CSS was not being output after the inline-script conversion. Fixed by adopting the render-time register/enqueue/add/print pattern (matching the orchestration renderer) instead of relying on the `wp_enqueue_scripts` hook which fires before the dashboard renders.

### Fixed — Addons PHPCS Cleanup — 93% Reduction (PRs #5070, #5078)

- **Batch 1 (PR #5070):** Enabled and fixed PHPCS across all addons. Removed 855 invalid `* /` comment-closer fragments across 330 files. Expanded 87 short ternaries to full ternary syntax. Added 616 auto-generated docblocks for standard/custom tool methods. Fixed 39 Yoda conditions. Added `@param` tags for 444 `execute()` method parameters. Fixed 43 missing class docblocks. Added 12 batch-fix helper scripts in `bin/`. Reduced errors from 1,143 → 82.
- **Batch 2 (PR #5078):** Restored 6 files from `alpha-working` to fix parse errors introduced by batch 1 (ternary expansion broke regex patterns, inline comment period fix added `.` to code lines, multi-line expression ternary expansion broke complex logic). Restored `vendor/composer` files. Remaining errors: 82 (extra params 29, missing params 18, SQL 8, syntax 7, inline 6, Yoda 5, misc 9).

### Changed — Documentation

- Gemini Capabilities Matrix updated to May 2026 — Omni Flash, conversational video editing, AI avatars.
- Video Production Toolkit README and docs updated with Omni Flash integration roadmap.
- Design Professional Tools token multipliers updated for Omni.

## [1.1.21] - 2026-05-21

Bumped to 1.1.21 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

### Fixed — WordPress.org Compliance Multi-Sweep Hardening

- **Comprehensive inline JS/CSS removal.** Converted all remaining inline `<script>`/`<style>` echoes to `wp_add_inline_style()` and `wp_print_inline_script_tag()` across 53 base-plugin files — admin settings, profession settings, team settings, onboarding wizard, model config renderer, orchestration renderer, Pro dashboard, Pro settings, provider diagnostics, report generator, sections (overview, providers, RabbitMQ, security, token manager, tools, advanced, orchestration), assistant CPT, all metaboxes (base-knowledge, credentials, datasets, MCP apps, mesh routing, primary roles, skills), AI Peer CPT, information labelling, model pricing checker, optional components, security audit CPT, Elementor widgets (assistant tools, performance metrics, recommendations, test runner, trends, system health, test results), profession search helper, markup admin page, profession CPT, profession metaboxes (agent orchestration, base knowledge, datasets, details, playbook), team CPT, and model config renderer tests.
- **PHP parse error fixes.** Removed duplicate `<?php` tag in `section-tools.php`; removed spurious `?>` closing tags after `wp_add_inline_style()` conversions that caused method declarations to be treated as HTML output (admin profession settings, admin settings, admin team settings, Pro dashboard, Pro settings, section overview, section token manager).
- **Profession metabox parse errors.** Fixed syntax errors in three profession metabox files (`agent-orchestration`, `base-knowledge`, `playbook`) caused by the inline-CSS migration.
- **WordPress.org findings F3, F5, F6, F7b resolved.** Hardened `json_decode()` calls with sanitization wrappers; added `require_once` guards; replaced bare `WP_CONTENT_DIR` paths with `wp_upload_dir()`-based alternatives; added HTTP timeout guards to outgoing requests.
- **May 20 re-audit pass.** Added four new audit categories: dangerous-functions scan (zero `eval`/`exec`/`system`/`shell_exec`/`passthru` calls), superglobal-access audit (`$_GET`/`$_POST`/`$_SERVER` only accessed through sanitization gates), HTTP-timeout audit (all `wp_remote_*` calls have explicit `timeout`), inline-notice audit (zero `admin_notices`-hooked inline echoes). Updated F6 status to resolved.
- **Build-pipeline hardening.** Excluded `.codex-wordpress` and `phpcs` directories from distribution ZIPs via `bin/build-plugin-zip.sh` and `bin/strip-dev-files.sh`; added `.gitignore` rule.
- **Pro Settings CSS loading fix.** Restored Pro Settings admin CSS enqueue that was broken during the inline-to-external stylesheet migration.

### Added — Security: Capability Fence P2b (Full Rollout)

- **`get_required_capability()` on `WP_MCP_AI_Tool_Interface`.** Every tool must now declare its minimum capability requirement through this required interface method. The tool service enforces the check at execution time, closing the payload-filter capability leak.
- **`WP_MCP_AI_Tool_Default_Capability` trait.** Provides a default `get_required_capability()` returning `'edit_posts'` for all tool stubs that implement the interface — added to `WP_MCP_AI_Validated_Tool`, all test stubs, anonymous class implementations, and fixture classes.
- **Per-class capability declarations.** `get_required_capability()` deployed to all ~830 tool classes across base and Pro, including all addon tools (Algorave, Embedded, Fantasy Football, Graphify) and the MCP App tool bridge.
- **Central capability map.** `WP_MCP_AI_Tool_Capability_Map` with sanitized capability values and a resolver that gates payload-filter dispatch. Capability values are run through `sanitize_key()` at registration.
- **`WPMCPAI.Tools.RequiredCapabilityDeclared` PHPCS sniff.** Flags any tool class that implements `WP_MCP_AI_Tool_Interface` without declaring `get_required_capability()` — severity 5, enforced in CI.
- **Capability Fence Audit UI.** Admin security section now renders per-tool slug/capability/flags correctly in the Capability Fence Audit view.

### Added — Security Center (5 Sub-Tabs)

- **Security Center admin page** with 5 sub-tabs: Posture, Compliance Report, OTel Telemetry, Deprecated-Alias Tracking, and MCP Token Inventory.
- **Posture service** with live security posture scoring and REST endpoints.
- **Compliance report** generator with WordPress.org finding status tracking.
- **OTel fields** for deprecated-alias telemetry: `nvoos.tool.data_type` + `nvoos.tool.duration_ms` span attributes.
- **MCP token inventory** with per-assistant credential audit.
- Comprehensive PHPUnit test coverage for the Security Center.

### Changed — Model Catalog May 2026 Refresh

- **DeepSeek V4 support.** Model catalog, DeepSeek client, and cost calculator updated for the DeepSeek V4 model family (`deepseek-chat-v4`, `deepseek-reasoner-v4`).
- **Gemini consolidation.** Gemini model entries consolidated and deduplicated; legacy endpoint aliases preserved for backward compatibility.
- **Pricing updates.** All provider pricing refreshed to May 2026 rates across `model-catalog.json` and the cost calculator.
- **88 WPCS lint errors** resolved across model catalog files (provider diagnostics, section providers, cost calculator, DeepSeek client, model catalog migration, and DeepSeek client tests).
- Reverted accidental `vendor/composer` changes that were shipped with the catalog refresh.

### Changed — Domain Migration (nvoos.com → nvoos.pro / nvoos.cloud)

- Updated plain `nvoos.com` references to `nvoos.pro` across all documentation.
- Updated `nvoos.com` subdomains to `nvoos.pro` in ISO 27001 compliance documentation.
- Corrected cloud-worker domain from `cloud.nvoos.com` to `nvoos.cloud`.

### Added — Cloud Worker Local Development Setup

- New `addons/cloud-worker/README-LOCAL.md` — step-by-step local dev guide.
- New `addons/cloud-worker/scripts/seed-local.mjs` — seed script for local D1 database.
- New `addons/cloud-worker/wp-config-local.php` — WordPress config for local cloud-worker testing.
- CI workflow triggers configured.

### Fixed — Translation Loading Too Early

- Pre-populated `$l10n` global with `NOOP_Translations` instances to prevent "Too early to translate" warnings during bootstrap.
- Deferred Security Audit CPT registration from `plugins_loaded` to `init` hook to prevent early translation loading.
- Rebuilt all plugin ZIPs with the translation-loading fix.

### Changed — Unix Theory P5 Part 2

- Decomposed `git_operations` tool into `git_inspect` (read-only) + `git_change` (mutating), advancing the P5 back-compat alias decomposition roadmap.

### Fixed — Docs Hub REST Response Guard

- Added non-JSON response guard in the Docs Hub manifest client to prevent "Unexpected token '<'" errors caused by caching plugins returning HTML instead of JSON.

### Fixed — SaaS Controller Base-Plugin Detection

- Corrected base-plugin detection logic in the SaaS Controller admin status display.

### Added — Folder README Convention Phase P7

- **Base backfill.** Every PHP-bearing `includes/` subdirectory now ships a `README.md` declaring its purpose, public surface, and context-file links. Convention: `docs/developer/folder-readme-convention.md`; enforced by `composer run docs:check-folder-readmes`.
- **Pro reconciliation.** `addons/pro/includes/` subdirectories documented with the same README convention, completing P0–P7 for the full plugin tree.

### Fixed — Final WP.org Compliance Cleanup (F8–F10, PR #5058)

- **F8 — `$_GET` missing `wp_unslash()` (3 instances).** Fixed in `admin-approvals.php`, `admin-markup-telemetry-page.php`, and `section-advanced.php`. All now follow `absint(wp_unslash())` pattern.
- **F9 — Bare `phpcs:ignore` without justification (15 instances).** Annotated across 8 files: `admin-orchestration-dashboard.php` (4), `batch-iterator.php` (3), `metric-event-store.php` (2), `inline-async-tick.php` (2), `workflow-triggers.php`, `autoload.php`, `eval-scheduler.php`, `a2a-controller.php`. Zero bare ignores remain.
- **F10 — Unguarded `WP_CONTENT_DIR`/`WP_PLUGIN_DIR` in addons (11 instances).** `defined()` guards added across `ai-tool-builder/` (6), `docs-hub/` (1), `pro/admin/` (1), `pro/services/` (1) with early-return or `WP_Error` patterns.

### Added — Canonical Return Envelope Compliance (PR #5055)

- **Unix Theory P0/P1 complete.** Converted 191 non-canonical `array('success' => false, ...)` returns to `new WP_Error()` across 105 files (+1212/−1349 lines). 49 tool classes + 24 service/admin/rest/slash-command files.
- `WPMCPAI.Tools.CanonicalReturnEnvelope` PHPCS sniff now clean (5 justified non-tool exceptions).
- `WPMCPAI.Tools.SanitizeAtEntry` violation resolved: `$arguments['plan_name']` and `$arguments['goal']` now sanitized via `sanitize_text_field()` / `sanitize_textarea_field()` before string interpolation.
- Caller sites hardened: `is_wp_error()` checks replace `$result['success']` / `$result['ok']` tests in site-health, license handler, report generator, workflow orchestrator, and `wp_mcp_ai_find_binary()`.

### Added — Semantic Caveman Compression (PR #5053)

- New `WP_MCP_AI_Semantic_Compressor` service (1,988 lines + 1,156 test lines). Strips grammar, connectives, and filler words while preserving facts, numbers, and technical terms.
- Opt-in via admin setting (default: disabled). Protects code blocks, JSON, URLs, emails, and HTML from compression.
- 44 PHPUnit tests covering edge cases and input validation (`tests/test-semantic-compressor.php`).
- Settings subsequently moved from Advanced → Orchestration tab (PRs #5056, #5057).

### Added — AI Prompt Caching (PR #5050)

- New `WP_MCP_AI_Chat_Response_Cache` and `WP_MCP_AI_Prompt_Optimizer` classes. Comprehensive response caching across all five AI providers.
- Cache eligibility: non-streaming, temperature=0, `cache_system_prompt` enabled. Keys: `sanitize_key()` + `absint()` + `md5()`. TTL: 60s–3600s.
- Invalidation on `save_post_mcp_ai_assistant`; version-bump strategy prevents stale cache. `bypass_cache` flag respected.
- Cache Performance dashboard in Token Manager section with escaped output.

### Fixed — Memory CCT Migrator Disabled by Default (PR #5051)

- Flipped `wp_mcp_ai_memory_cct_migrator_enabled` filter default from `true` → `false` to stop infinite sanitize-loop log spam.
- When disabled, `bootstrap()` opportunistically advances stored schema version (guarded — never rolls backwards).
- Zero writes to JetEngine storage layer. Four regression tests prevent the sanitize loop from returning.

### Fixed — Addons/Pro Security Scan Fixes (PR #5059)

- Fixed remote-sites admin pagination warnings in `class-wp-mcp-ai-pro-remote-sites-admin.php`.
- Removed stale `AI_Assisted` tag from project management AI actions.
- Added `addons/pro/uninstall.php` for proper cleanup on uninstall.
- Rebuilt all distribution ZIP artifacts.

### Added — @wordpress/env Dev Dependency (PR #5048)

- Added `@wordpress/env` as dev dependency enabling zero-config local WordPress development environments via `wp-env start`.
- Updated `package-lock.json` with `@wordpress/env` entries.

## [1.1.20] - 2026-05-18

Bumped to 1.1.20 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

### Added — Memory Layer 2026 (Phase 7 UI/UX completion)

- **Phase 7a — Memory Health subtab:** Added an Orchestration Memory Health view with live status, threshold policy, budget posture, and chat-memory availability indicators.
- **Phase 7b — Retrieval Waterfall:** Added Memory Drawer retrieval waterfall panel (RRF + legacy breakdown + retrieval path metadata), preserving backward-compatible response keys.
- **Phase 7c — Session Replay:** Added read-only route `GET /mcp-ai/v1/chat-memory/sessions/{session_id}` and wired a Session Replay tab in the Memory Drawer via `memoryEndpoints.sessionBase` + `chat-memory-service.sessionReplay()`.
- Added/updated targeted coverage for the new route and UI behavior:
  - `tests/rest/test-rest-chat-memory-controller.php`
  - `tests/js/chat-memory-service.test.js`
  - `tests/js/chat-memory-drawer.test.js`

## [1.1.19] - 2026-05-18

Bumped to 1.1.19 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative. Provider count: 10 first-class language-model providers.

### Added — Kimi (Moonshot AI) provider (10th provider)

- New first-class AI provider `WP_MCP_AI_Kimi_Client` wrapping the OpenAI-compatible API at `https://api.moonshot.cn/v1`. Supports chat completions, tool/function calling, JSON mode, SSE streaming, native multi-turn context up to 256K tokens.
- Models: `kimi-k2.6` (256K context, multimodal, tool calling — default), `kimi-k2.5`, `kimi-k2` (base reasoning), `kimi-k2-thinking` (chain-of-thought), legacy `moonshot-v1-8k / -32k / -128k`.
- Settings UI: new **Kimi** subtab under **Settings → Providers** with `enable_kimi`, `kimi_api_key`, `kimi_model`, and `kimi_base_url` fields.
- `WP_MCP_AI_Model_Config::get_active_providers()` now includes `kimi` when the provider is enabled with an API key configured.
- Provider Diagnostics card with a lightweight model-list probe.
- WP.org compliance docs updated: `docs/reference/EXTERNAL_SERVICES.md` now documents Kimi (Moonshot AI), OpenRouter, and DigitalOcean API endpoints with service URLs, data-sharing disclosures, and ToS links.

### Added — Agent Client Protocol (ACP) Server

- Full ACP standard implementation enabling external AI clients (Zed, JetBrains, Neovim, Claude Desktop) to natively drive NV oOS assistants over JSON-RPC 2.0 and HTTP/SSE transport.
- Core classes: `WP_MCP_AI_ACP_Server` (bootstrap + DI wiring), `WP_MCP_AI_ACP_JSONRPC_Dispatcher` (JSON-RPC 2.0 routing), `WP_MCP_AI_ACP_Session_Manager` (session lifecycle + transient store), `WP_MCP_AI_ACP_Session_Bridge` (bridges ACP sessions to the existing agentic loop), `WP_MCP_AI_ACP_Transport_HTTP` (HTTP+SSE framing).
- `/.well-known/ai-peer` discovery endpoint extended to advertise `acp` protocol version, transports (`http+sse`), and supported `auth_methods`.
- Settings: ACP server toggle (`enable_acp_server`) and strict tool-approval toggle (`acp_require_approval`) rendered inline within **Orchestration → Settings** — matching the Agent Memory and Multi-Agent groupings.
- PHPUnit coverage scaffolding in `tests/acp/` for JSON-RPC mappings, session bridging, and transient persistence.
- Follow-up fixes: abstract-method fatal resolved (#4994); orphan ACP settings section, A2A empty `render()`, and orchestration view-save data loss resolved (#4995).

### Added — MCP Bridge (stdio-to-HTTP relay)

- New `bin/mcp-bridge.js` — lightweight Node.js relay that bridges the MCP stdio transport (used by Claude Desktop, Cursor, Zed) to the plugin's existing HTTP + SSE MCP endpoint. Enables local MCP clients to connect to a remote NV oOS installation without any server-side changes.
- Usage: `node bin/mcp-bridge.js --url https://yoursite.com/wp-json/mcp-ai/v1 --token <bearer>`.

### Added — Unix Theory Phase P7: Folder README Convention

- Every PHP-bearing subdirectory under `includes/` (base plugin) now ships a `README.md` declaring the folder's purpose, public surface, neighbor folders, and which `.context/*.md` files to load alongside it.
- Convention defined in `docs/developer/folder-readme-convention.md`; canonical template at `.context/templates/folder-readme-template.md`.
- Enforced by `composer run docs:check-folder-readmes` (part of `composer run ci:all`).
- Extends the Unix Theory Compliance series (P0–P7 now complete for base plugin).

### Added — GDPR: JetEngine Privacy Exporters

- New privacy exporter classes for JetEngine CCT data (chat transcripts, agent memory, approval queue entries) compliant with WordPress's `wp_privacy_personal_data_exporters` hook.
- Registered automatically when JetEngine is active.

### Fixed — Security Hardening (5 independent patches)

- **Settings key encryption** (#4990) — sensitive settings values (API keys, tokens, secrets) are now encrypted at rest in WordPress options and masked in the admin UI using `●●●●●●●●` placeholders.
- **Webhook secret enforcement** (#4988) — incoming webhook endpoints now reject requests with missing or invalid shared-secret header, preventing unauthenticated webhook execution.
- **SSRF hardening** (#4991) — all outgoing HTTP requests that accept a user-configurable URL now use `wp_safe_remote_get` / `wp_safe_remote_post` (which blocks private IP ranges) instead of raw `wp_remote_get`.
- **Attachment URL scheme validation** (#4975) — tool results that include attachment URLs are validated against an allowlist of schemes (`https`, `http`) before being included in chat output, preventing `javascript:` or `data:` URL injection.
- **Client-log gating** (#4984) — sensitive console log messages (raw API keys, full request payloads, assistant credentials) are now gated behind a debug flag (`WP_MCP_AI_DEBUG`) and an admin-only JavaScript toggle, preventing accidental exposure in browser DevTools on public-facing pages.

### Fixed — Chat Bubble / Test Model UI (sweep)

- Chat bubble now self-initializes via `window.wpMcpAiChatInit.init(scope)` matching the main chat widget pattern, fixing deferred-load race conditions.
- Panel CSS scoped to chat bubble panel context; panel-fit layout fixed for both shortcode and Elementor widget render paths.
- `WP_MCP_AI_Shortcode::kses_chat_output()` now preserves `<form>`, `<button>`, `<input>` tags and interactive attributes (`type`, `disabled`, `hidden`, etc.) so chat controls remain functional in AJAX-rendered surfaces.
- Test Model (Test AI Models with Professions) chat buttons and submission flow fully restored.
- Professional selector AJAX render now uses `kses_chat_output()` (fallback to `wp_kses_post`), preserving the interactive markup.
- Unified team chat response normalization for the Test Team modal.
- Chat bubble re-init isolated to its own bubble ID/panel; no longer re-initializes other chat widgets on the same page.
- Submit button re-enabled when chat bubble panel is rendered inside an outer page `<form>`.

### Fixed — Asset Inventory

- Discover Assets button on the Asset Inventory admin page re-enabled (was inert due to missing event binding after admin-page refactor).
- `discover_assets` flow now emits debug log events and is covered by Jest tests.

## [1.1.18] - 2026-05-14

Bumped to 1.1.18 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

The complete set of changes captured in this release is broken down below; the per-PR detail that was previously authored under `[Unreleased]` is preserved verbatim in the following sub-sections.

### Added — DigitalOcean Serverless Inference provider

- `feat(providers): add DigitalOcean Serverless Inference provider`.
- New concrete client `WP_MCP_AI_DigitalOcean_Client`
  (`includes/class-wp-mcp-ai-digitalocean-client.php`) wrapping the
  OpenAI-compatible REST API at `https://inference.do-ai.run/v1`. Supports
  chat completions, tool/function calling, JSON mode, SSE streaming, native
  embeddings, model listing, and reasoning passthrough. Authentication uses
  a model access key (`Authorization: Bearer …`) issued from
  Gradient Platform → Serverless Inference → Model access keys.
- New provider-interface adapter
  `WP_MCP_AI_DigitalOcean_Provider_Client`
  (`includes/infrastructure/providers/…`) implementing
  `Interface_WP_MCP_AI_Provider_Client` for the language-model router.
- New embedding provider `WP_MCP_AI_Embedding_Provider_DigitalOcean`
  (`includes/services/embedding/…`) registered alongside the OpenAI and
  Ollama embedding providers; default model `gte-large-en-v1.5`.
- DI container wiring: `client.digitalocean` singleton, injected into
  `WP_MCP_AI_Language_Model_Router` as a new optional constructor argument.
- Settings UI: new **DigitalOcean** subtab under **Settings → Providers**
  with `enable_digitalocean`, `digitalocean_api_key`, `digitalocean_model`,
  `digitalocean_base_url`, and `digitalocean_embedding_model` fields.
- `WP_MCP_AI_Model_Config::get_active_providers()` now includes
  `digitalocean` when the provider is enabled with an API key configured.
- `includes/data/model-catalog.json` seeded with `llama3.3-70b-instruct`,
  `llama3.1-8b-instruct`, `deepseek-r1-distill-llama-70b`,
  `openai-gpt-oss-120b`, and `gte-large-en-v1.5`. Pricing fields are
  zeroed — operators should update them via the Models admin page or the
  `wp_mcp_ai_model_catalog` filter to reflect their account's per-token
  billing.
- **Model Discovery Service**: new `digitalocean` branch refreshes the
  cached catalogue from `/v1/models` when an API key is configured.
- **Provider Diagnostics**: new **DigitalOcean Serverless Inference** card
  with a `GET /v1/models` connectivity probe that reports latency, model
  count, and the configured default model. The probe does not spend
  inference credits.
- New test `tests/test-digitalocean-client.php` (mock HTTP via
  `pre_http_request`): constants, accessors, chat completion success,
  tool-call passthrough, 401/429 error envelopes, malformed JSON,
  reasoning-content passthrough, `list_models()` normalisation, embedding
  round-trip, custom base URL override, token-count heuristic.
- New docs: `docs/features/ai-providers/digitalocean.md` (prerequisites,
  quick start, model access keys, available models, custom base URL,
  tool calling + streaming, embeddings, prompt caching/reasoning notes,
  diagnostics, troubleshooting).
- **Out of scope**: DigitalOcean Agent endpoints
  (`*.agents.do-ai.run/api/v1`) — they use a different per-agent URL
  scheme and auth flow. May be added as a separate provider entry in a
  future release.

### Added — Inline-async-tick fallback for Gemini Veo polling (Slice 6)

- `WP_MCP_AI_Gemini_Video_Generation_Service` now composes
  `WP_MCP_AI_Inline_Async_Tick_Trait` so that the first Gemini
  operation-status poll fires on the shutdown of the request that queued the
  video job, rather than waiting for the next WP-Cron loopback. On hosts with
  `DISABLE_WP_CRON` the loopback never fires; the cooperative tick lock
  prevents the inline kick and the rescheduled cron event from executing
  `poll_video_async()` for the same `job_id` simultaneously:
  - `queue_async_polling()` registers a `shutdown` action at priority 22 that
    calls `poll_video_async_static()` inline after the video-generation
    response is returned to the client (guarded by the
    `wp_mcp_ai_inline_kick_enabled` filter). The existing
    `wp_schedule_single_event(time() + 1, …)` + `spawn_cron()` calls are
    preserved as the cron fallback.
  - `poll_video_async()` now acquires the cooperative tick lock
    (`TICK_LOCK_PREFIX = 'wp_mcp_ai_veo_poll_lock_'`, group
    `wp_mcp_ai_veo_poll`, TTL 30 s) then delegates to the new protected
    `do_poll_video_async()` method, so shutdown kick and cron event cannot
    race for the same job.
- New class constants: `TICK_LOCK_PREFIX`, `TICK_LOCK_CACHE_GROUP`,
  `TICK_LOCK_TTL`.
- New test: `tests/test-veo-inline-kick.php` (4 cases: constant assertions,
  lock prevents double-poll, missing-metadata bail, filter disable).
- Architecture doc `docs/developer/architecture/inline-async-tick-pattern.md` updated:
  Slice 6 added to the Tier-1 consumer table; the "Future Tier-1 consumers"
  note removed (all planned slices are now complete).

### Added — Inline-async-tick fallback for Graphify reindex (Slice 5a)

- `NV_oOS_Graphify` now composes `WP_MCP_AI_Inline_Async_Tick_Trait`
  (conditional load from `WP_MCP_AI_PATH`, with a no-op stub for bare
  environments) so that the first incremental reindex after a post save fires
  on the shutdown of the save request instead of waiting 5+ seconds for the
  WP-Cron loopback:
  - `on_save_post()` registers a `shutdown` action at priority 22 that
    calls `run_scheduled_build()` inline after the save response is flushed
    (guarded by the `wp_mcp_ai_inline_kick_enabled` filter). The existing
    `wp_schedule_single_event(time() + 5, …)` call is preserved as the
    cron fallback.
  - `run_scheduled_build()` now acquires the cooperative tick lock
    (`TICK_LOCK_KEY = 'nvoos_graphify_build_tick_lock'`, group
    `nvoos_graphify`, TTL 60 s) then delegates to the new protected static
    `do_build()` method so that the shutdown kick and the cron loopback
    cannot run two concurrent builds simultaneously.
- New class constants: `TICK_LOCK_KEY`, `TICK_LOCK_CACHE_GROUP`, `TICK_LOCK_TTL`.
- New test: `tests/graphify/test-graphify-inline-kick.php` (4 cases:
  shutdown-kick registration on publish, lock prevents double-build, filter
  disables, draft post skips kick).

### Added — Inline-async-tick fallback for Harness Eval Scheduler (Slice 5b)

- `WP_MCP_AI_Harness_Eval_Scheduler` now composes
  `WP_MCP_AI_Inline_Async_Tick_Trait` so that the first eval batch fires on
  the shutdown of the request that first activates the scheduler:
  - `maybe_schedule_cron()` now adds a `shutdown` action (priority 22) the
    first time it schedules the daily cron event, firing `tick()` inline so
    opted-in assistants see an initial eval result within seconds rather than
    waiting until the next day.
  - `tick()` now acquires the cooperative tick lock
    (`TICK_LOCK_KEY = 'wp_mcp_ai_harness_eval_tick_lock'`, group
    `wp_mcp_ai_harness_eval`, TTL 120 s) and delegates to the new public
    static `do_tick()` method, preventing concurrent WP-Cron invocations from
    running two overlapping eval batches.
- New class constants: `TICK_LOCK_KEY`, `TICK_LOCK_CACHE_GROUP`, `TICK_LOCK_TTL`.
- New test: `tests/test-harness-eval-scheduler-inline-kick.php` (4 cases:
  first-schedule shutdown kick, lock contention, filter disables, do_tick
  no-op summary on empty site).

### Added — Inline-async-tick fallback for Crawl4AI background poller (Slice 3)

- `WP_MCP_AI_Crawler` now composes the base plugin's
  `WP_MCP_AI_Inline_Async_Tick_Trait` so that the first poll for a
  newly-queued Crawl4AI job fires on the shutdown of the request that
  registered it, rather than waiting up to 30 s (the default poll
  interval) for the WP-Cron loopback:
  - `register_remote_job()` registers a `shutdown` action at priority
    22 that calls `handle_poll_event($task_id)` inline after the REST
    response is flushed (guarded by the `wp_mcp_ai_inline_kick_enabled`
    filter escape hatch).
  - `handle_poll_event()` now acquires the two-layer cooperative tick
    lock (`TICK_LOCK_PREFIX . md5($task_id)`, group
    `wp_mcp_ai_crawl4ai`, TTL 30 s) before delegating to the new
    `do_poll_event()` method so that the inline kick and a concurrent
    WP-Cron loopback cannot both call `check_remote_task()` for the
    same task simultaneously.
  - The poll body has been extracted into the protected static
    `do_poll_event()` method so unit tests can exercise it without
    going through the lock.
- New class constants: `TICK_LOCK_PREFIX`, `TICK_LOCK_CACHE_GROUP`,
  `TICK_LOCK_TTL`, `STALE_QUEUED_THRESHOLD_SECONDS`.
- New test: `tests/test-crawl4ai-inline-kick.php` (4 cases:
  shutdown-kick registration, lock-prevents-double-poll, filter disables,
  skip-polling bail).

### Added — Inline-async-tick fallback for Docs Hub rebuild pipeline (Slice 4)

- `NV_oOS_Docs_Hub_Rebuild_Pipeline` (Docs Hub addon) now composes
  `WP_MCP_AI_Inline_Async_Tick_Trait` when the base NV oOS plugin is
  active. This fires the first rebuild chunk on the shutdown of the
  request that calls `enqueue()` instead of waiting for the next
  WP-Cron loopback, making rebuilds feel near-instant for operators:
  - The trait file is loaded from `WP_MCP_AI_PATH` with a guard;
    a no-op stub trait is defined for bare environments (unit tests
    running without the base plugin) so the class loads cleanly.
  - `enqueue()` registers a `shutdown` action at priority 22 that
    calls `tick()` inline after flushing (guarded by the filter).
  - `tick()` acquires the fixed-key cooperative tick lock
    (`TICK_LOCK_KEY = 'nvoos_docs_hub_rebuild_tick_lock'`, group
    `nvoos_docs_hub`, TTL 45 s) then delegates to the new static
    `do_tick()` method so that the inline kick and a WP-Cron loopback
    cannot run two concurrent chunks.
  - Tick body extracted to public static `do_tick()` (callable by
    tests directly without the lock).
- New class constants: `TICK_LOCK_KEY`, `TICK_LOCK_CACHE_GROUP`,
  `TICK_LOCK_TTL`.
- New test: `addons/docs-hub/tests/test-rebuild-pipeline-inline-kick.php`
  (4 cases: shutdown-kick registration, lock-prevents-double-tick, filter
  disables, idle/done bail).

### Added — Inline-async-tick fallback for SaaS Controller Apply Job

- `NVOOS_SaaS_Controller_Apply_Job` (the queued background-apply
  worker for the SaaS Controller addon's Cloudflare / Stripe /
  OpenRouter / Worker upload pipeline) now composes the base
  plugin's `WP_MCP_AI_Inline_Async_Tick_Trait`. On hosts where
  `DISABLE_WP_CRON` is true or the WP-Cron loopback is firewalled,
  apply jobs previously sat at `status: queued` forever even though
  `spawn_cron()` returned without error. The class now:
  - registers a `shutdown` action from `enqueue_plan()` that runs
    the first tick inline in the same PHP process once the
    `/apply/enqueue` REST response has been flushed (honours the
    shared `wp_mcp_ai_inline_kick_enabled` escape-hatch filter);
  - guards `handle_tick()` with the trait's two-layer cooperative
    tick lock (transient + `wp_cache_add`) so a delayed cron
    loopback firing concurrently with the inline shutdown kick is
    a no-op;
  - emits the unified `wp_mcp_ai_inline_kick_completed`
    observability action so the Pro OTel measurement bootstrap
    records `inline_kick.duration_ms` / `inline_kick.failure.count`
    for SaaS Apply on the same dashboard as Mine Memories and the
    Tool Async Executor;
  - recurses inline under `DISABLE_WP_CRON` within a 60-second
    wall-clock budget (`INLINE_LOOP_BUDGET_SECONDS = 60`) — larger
    than the 20s used by the much faster batch-oriented Mine
    Memories job because a single Apply row can include a
    multi-second Worker multipart upload to Cloudflare.
- REST `GET /nvoos-saas/v1/apply/jobs/{id}` now self-heals: when
  the admin UI polls and the job has sat in `queued` past
  `STALE_QUEUED_THRESHOLD_SECONDS = 5`, the controller schedules a
  one-shot shutdown kick on the way out so the very next poll
  observes progress. Mirrors the equivalent self-heal in the base
  plugin's Mine Memories and Tool Async Executor REST routes.
- New class constants `TICK_LOCK_PREFIX = 'nvoos_saas_apply_lock_'`,
  `TICK_LOCK_CACHE_GROUP = 'nvoos_saas_apply'`, `TICK_LOCK_TTL =
  120`, `STALE_QUEUED_THRESHOLD_SECONDS = 5`,
  `INLINE_LOOP_BUDGET_SECONDS = 60`. Existing constants
  (`CRON_HOOK`, `STATE_PREFIX`, `STATE_TTL`, `MAX_TOTAL_ROWS`) are
  unchanged. No public method signatures changed; existing PHPUnit
  tests against `enqueue_plan()`, `handle_tick()`, `cancel()`, and
  `get_progress()` pass unmodified.
- PHPUnit: 4 new tests covering inline-shutdown kick advancing a
  queued job, terminal-status short-circuit, the
  `wp_mcp_ai_inline_kick_enabled` filter disabling the registration,
  and the cooperative lock no-op behaviour under concurrent ticks.

### Changed — Transcript Mining job now consumes the inline-async-tick trait

- `WP_MCP_AI_Transcript_Mining_Job` now composes
  `WP_MCP_AI_Inline_Async_Tick_Trait` instead of carrying its own copies
  of the four primitives. Behaviour is unchanged on hosts that hit the
  existing fallback paths, but Mine Memories now:
  - emits the unified `wp_mcp_ai_inline_kick_completed` observability
    action on every shutdown kick (same `( $class, $job_id,
    $duration_ms, $success )` shape used by the Tool Async Executor),
    so Pro OTel subscribers can record `inline_kick.duration_ms` /
    `inline_kick.failure.count` for free; and
  - honours the global `wp_mcp_ai_inline_kick_enabled` escape-hatch
    filter — returning `false` from the filter (globally or per-job)
    now skips the shutdown action registration entirely for Mine
    Memories the same way it already did for the Tool Async Executor.
- New class constant `TICK_LOCK_CACHE_GROUP = 'wp_mcp_ai_tx_mine'`
  formalises the object-cache group that the lock helper consumes
  (previously inlined as a string literal). `TICK_LOCK_PREFIX`,
  `TICK_LOCK_TTL`, `INLINE_LOOP_BUDGET_SECONDS`, and
  `STALE_QUEUED_THRESHOLD_SECONDS` are unchanged.
- Net diff: ~80 LOC removed from the class; one trait `use` line
  added. No public method signatures changed; existing tests against
  `handle_tick()`, `kick_inline()`, and `TICK_LOCK_PREFIX` continue to
  pass without modification.

### Added — Inline-async-tick fallback for Tool Async Executor

- New reusable trait `WP_MCP_AI_Inline_Async_Tick_Trait` at
  `includes/traits/trait-wp-mcp-ai-inline-async-tick.php` consolidating
  the four inline-async primitives (worker detach, two-layer cooperative
  tick lock, `DISABLE_WP_CRON` loop decision, observability action)
  introduced for the Mine Memories job in PR #4916.
- `WP_MCP_AI_Tool_Async_Executor` now consumes the trait. Async tools
  scheduled via `queue_tool()` register a `shutdown` action that runs
  the tick inline once the response is flushed, so jobs no longer sit
  at `status: pending` forever on hosts where the WP-Cron loopback never
  fires (`DISABLE_WP_CRON = true`, firewalled `wp-cron.php`, etc.). A
  cooperative per-job lock around `execute_async_tool()` prevents
  double-execution when a delayed cron loopback fires after the inline
  worker has finished.
- The REST cron-status poll endpoint (`GET /mcp-ai/v1/cron-status/{job_id}`)
  now self-heals stuck async jobs: when status has been `pending` past
  5 seconds the controller schedules a shutdown kick. The response
  payload is unchanged.
- New filter `wp_mcp_ai_inline_kick_enabled` (default `true`, per-job
  filterable) — operator escape hatch.
- New action `wp_mcp_ai_inline_kick_completed` fires once per inline
  kick with `( $class, $job_id, $duration_ms, $success )` — Pro
  measurement bootstrap can record `inline_kick.duration_ms` and
  `inline_kick.failure.count` for OTel.
- Docs: new architecture page
  `docs/developer/architecture/inline-async-tick-pattern.md`; async-tool guide
  updated with the fallback section.

### Fixed — Transcript mining job stuck in `queued` on WP-Cron-disabled hosts

- The **Mine Memories from Transcripts** background job
  (`WP_MCP_AI_Transcript_Mining_Job`) now ships an industry-standard inline-async
  fallback so jobs progress on every WordPress host, including sites with
  `DISABLE_WP_CRON = true` or a firewalled `wp-cron.php` loopback. Previously,
  jobs would sit at `Status: queued, Progress: 0 / 1` indefinitely on these
  hosts because `spawn_cron()` cannot dispatch its loopback HTTP request.
- `enqueue()` now registers a `shutdown` action that flushes the REST response
  via `fastcgi_finish_request()` (when available), detaches the worker via
  `ignore_user_abort()`, and runs the first tick in-process when state is still
  `queued`. The cron path is unchanged for hosts where it already works.
- `handle_tick()` is guarded by a two-layer cooperative lock (object cache +
  transient) so the inline shutdown worker and a delayed cron loopback cannot
  double-process the same batch.
- `handle_tick()` also runs subsequent batches inline when `DISABLE_WP_CRON` is
  true, bounded by a 20 s wall-clock budget per request to stay clear of PHP
  `max_execution_time` limits.
- The REST poll endpoint `GET /mcp-ai/v1/transcript-mining/jobs/{id}` is now
  self-healing: when a job has been `queued` for longer than 5 s it queues an
  inline kick after the response is flushed. The admin UI's 2 s poll loop
  therefore drives forward progress automatically.
- Diagnostic logging: a `transcript_mining` event records when a tick is driven
  by the inline path (`source => inline_shutdown`) and a single warning is
  emitted when `spawn_cron()` returns `false`, pointing operators to the
  loopback or `DISABLE_WP_CRON` setting.
- PHPUnit coverage added in `tests/test-transcript-mining-job.php` (3 new
  cases): inline-shutdown completion, cooperative-lock guard, and
  `kick_inline()` no-op on non-queued states.

### Added — Scheduled Result widget + block

- New **Scheduled Result Display** as a Gutenberg dynamic block
  (`mcp-ai-wpoos/scheduled-result`) and Elementor widget
  (`WP_MCP_AI_Elementor_Scheduled_Result_Widget`). Both bind to any Pro
  Schedule and render its latest run output via a shared PHP renderer
  (`includes/renderers/class-wp-mcp-ai-scheduled-result-renderer.php`) with
  six canonical modes — `summary-card`, `list`, `table`, `metric`,
  `timeline`, `raw`.
- Pro: Schedule Manager now persists a typed result envelope
  (`{ summary, data, render, status, error, generated_at }`) in a separate
  `wp_mcp_ai_pro_schedule_results` option, independent of the run-history
  ring buffer. Per-schedule `result_retention` (default 10).
- Pro: New REST routes under `mcp-ai-pro/v1/schedules` — `?selectable=1`
  picker, `/{id}/latest-result` (with ETag), `/{id}/results`, and a
  nonce-protected `/{id}/preview`.
- Pro: Three new tools — `get_schedule_latest_result`,
  `render_schedule_result`, `configure_schedule_widget_defaults`.
- New filters/actions: `wp_mcp_ai_pro_schedule_result_envelope`,
  `wp_mcp_ai_pro_schedule_public_result`,
  `wp_mcp_ai_pro_schedule_result_retention`,
  `wp_mcp_ai_pro_schedule_result_capability`, and the
  `wp_mcp_ai_pro_schedule_result_recorded` action.
- Docs: `docs/features/scheduled-result-widget.md`.

### Added — UI/UX Pro Max Skill Bundle

- `feat(skills): bundle ui-ux-pro-max skill + UI/UX Design Pack`.
- New bundled skill `ui-ux-pro-max` under `includes/bundled-skills/ui-ux-pro-max/`.
- Comprehensive design system data including:
  - Color palettes (`data/colors.csv`)
  - Typography scales (`data/typography.csv`, `data/google-fonts.csv`)
  - Icon libraries (`data/icons.csv`)
  - UI components (`data/app-interface.csv`, `data/design.csv`)
  - Stack-specific guidelines for React, Vue, Angular, Laravel, Astro, Svelte, Flutter, SwiftUI, Jetpack Compose, and more (`data/stacks/*.csv`)
  - UX guidelines and reasoning (`data/ux-guidelines.csv`, `data/ui-reasoning.csv`)
- Python utility scripts (`scripts/core.py`, `scripts/design_system.py`, `scripts/search.py`) for design system operations.
- Updated `includes/bundled-skills/THIRD_PARTY_NOTICES.md` with attribution.
- New PHPUnit tests in `tests/test-skill-pack-registry.php`.
- Skill pack registry integration via `WP_MCP_AI_Skill_Pack_Registry` with new `ui-ux-design` pack.
- Base plugin skill count updated from 44 to 45.

## [1.1.17] - 2026-05-10

### May 10, 2026 — WP.org Compliance Hardening, Chat SPA Phases 1–7, Docs Hub v0.3.8, Toolkit SPA Blueprint Phases 5–12, PHPUnit + Vitest Coverage Campaign, Build-pipeline Split, Dependabot Security Sweep

#### Security — Dependabot Alert Sweep (33 alerts)

Resolved the full 33-alert Dependabot backlog across all five npm manifests; the Composer surface was already clean. Lockfiles refreshed and committed dist artifacts rebuilt where applicable.

- **Root (`/package.json`)** — bumped overrides: `axios → ^1.16.0`, `basic-ftp → >=6.0.1`, `ip-address → >=10.2.0`. Resolves 3 alerts (1 moderate / 2 high) including 13 axios advisories (prototype-pollution + SSRF + CRLF chain), `basic-ftp` DoS (`GHSA-rpmf-866q-6p89`), and `ip-address` XSS (`GHSA-v2v4-37r5-5v8g`).
- **`addons/pro/package.json`** — bumped direct `axios` to `^1.16.0`; added overrides for `basic-ftp` and `ip-address` mirroring root. Resolves 3 alerts.
- **`addons/saas-controller/package.json`** — bumped `@wordpress/scripts` (^30 → ^32.1.0), `diff` (^7 → ^9), `esbuild` (^0.24 → ^0.28), `miniflare` (→ ^4.20260504); added overrides for `minimatch`, `serialize-javascript`, `webpack-dev-server`. Resolves 17 alerts covering ReDoS, RCE, dev-server source-leak.
- **`addons/docs-hub/package.json`** — bumped `react-router-dom` (7.5.3 → ^7.15.0). Resolves 2 alerts (CSRF + XSS chain `GHSA-h5cw-625j-3rxh`, `GHSA-2w69-qvjg-hvjx`).
- **`addons/cloud-worker/package.json`** — bumped `@cloudflare/vitest-pool-workers` (^0.5 → ^0.16), `vitest` (^2 → ^4.1.5), `wrangler` (^3 → ^4.88). Resolves 10 alerts (devalue prototype pollution, esbuild dev-server, undici/miniflare chain).
- **Hardening (`.github/dependabot.yml`)** — extended coverage to all addon manifests: added 4 new npm watchers (`addons/pro`, `addons/saas-controller`, `addons/docs-hub`, `addons/cloud-worker`) and 4 new composer watchers (`addons/pro`, `addons/fantasy-football`, `addons/docs-hub`, `addons/algorave`).

#### Security — Additional Hardening

- **Docs Hub SSRF hardening** — `safe_get()` now uses `resolve_public_ip()` (DNS A/AAAA lookup) refusing on any private/reserved record. Defensive `remote_repos` coercion added in `get_settings()`.
- **canvas + cornerstone3d addons** — standalone `LICENSE` and `THIRD_PARTY_NOTICES` files added; proprietary banners added to all PHP headers.

#### Added — Chat SPA Addon (`addons/chat-spa/`, Phases 1–7, v0.6.0)

React replacement for the legacy `[mcp_ai_assistant]` chat shortcode, built on Vercel AI SDK UI with a custom SSE→Data Stream Protocol adapter. All 7 phases are now complete (bundle ~81.3 KB gzip, limit 350 KB):

- **Phase 1** — `@ai-sdk/react useChat` with custom fetch + client-side SSE adapter (`src/sse-adapter.ts`). Shortcode `[nvoos_chat_spa]` with `assistant_id`, `theme`, `height`, `guest` attrs.
- **Phase 2** — Collapsible tool-call cards (from `message.toolInvocations`), inline annotation pills (`memory_event`), admin embed page (`WP-Admin → NV oOS Chat`, `manage_options`).
- **Phase 3** — Transcripts sidebar (load/save/delete via `mcp-ai/v1/chat-transcripts`; `useTranscriptSession` hook; guest mounts skip sidebar).
- **Phase 4** — Memory drawer with three tabs (Memories / Scope / Audit); wing/room scope persisted in `localStorage`.
- **Phase 5** — HITL approval bar polling `/mcp-ai/v1/approvals` every 6 s during streaming; rendered only for `manage_options` users.
- **Phase 6** — File attachments via `useAttachments` hook (5 MB per file, 10 MB total, 10 files max) + thumbnail strip; `↺` regenerate via `reload()`; `✏` edit + re-submit via `setMessages` truncation.
- **Phase 7** — `WP_MCP_AI_LEGACY_CHAT_JS` constant in `includes/bootstrap/constants.php` (default `true`) gates the shortcode; blueprint §20 migration guide added.

#### Added — Docs Hub Addon (`addons/docs-hub/`, v0.1.0 → v0.3.8)

- **v0.3.0** — Remote-first defaults + tree-picker UX; chunked rebuild with progress API; CLI `rebuild` subcommand.
- **v0.3.1** — 404-on-rebuild resolved; tree-picker hint surfaced.
- **v0.3.2** — PHPCS lint errors fixed.
- **v0.3.3** — `RemoteAnchor` function named; heading anchors fixed.
- **v0.3.4** — Same-repo GitHub blob links routed through SPA; other external links open in new tab.
- **v0.3.5** — `#section` anchors no longer corrupt `HashRouter`; `scrollIntoView` added for in-page links.
- **v0.3.6** — Defensive `remote_repos` coercion (non-array rows filtered); SSRF hardening via `resolve_public_ip()` (DNS A/AAAA); `clear_local_cache_for_files()` on `force=true`.
- **v0.3.7** — a11y: ARIA root attrs, skip-link, `prefers-reduced-motion` support; de-duped `wp_localize_script`.
- **v0.3.8** — Syntax highlighting via rehype-highlight + lowlight; `PageFooter` component (last_modified + edit-on-GitHub); `NV_oOS_Docs_Hub_Sitemap_Provider` (`WP_Sitemaps_Provider`); admin `repo-picker.js` extracted from inline script; docs-hub added to `spa-bundle-size.yml` (250 KB limit, ~204 KB actual).

#### Added — Toolkit SPA Blueprint Phases 5–12

- **Phase 5** — a11y hardening: ARIA mount `<div>` attrs, `jsx-a11y` lint, `axe-core` in dev, CSP docs, CI workflow (`spa-a11y.yml`).
- **Phase 6** — i18n pass: `wp.i18n` as Webpack external, `__()` in all SPA components, `wp_set_script_translations()` registered.
- **Phase 7** — Expanded REST + shortcode PHPUnit tests for `canvas-toolkit` and `media-studio`.
- **Phase 8** — Bundle-size CI guardrail workflow (`spa-bundle-size.yml`) with per-addon limits.
- **Phase 9** — Scaffolder (`bin/scaffold-toolkit-spa.sh`) auto-patches `spa-a11y.yml` + `spa-bundle-size.yml` on new addon creation.
- **Phase 10** — All 10 Tier-A `toolkit-shell` manifests complete: crm, calendar-booking, financial-planner, analytics, regulatory-registration, law-firm, cre-debt, multilingual, ecommerce, social-media.
- **Phase 11 (`canvas-toolkit` v0.2.0)** — Four modes: flow (`@xyflow/react`), whiteboard (tldraw v5, react@19.2.6), bpmn (`bpmn-js NavigatedViewer`), mermaid live preview. Bundle ~1,495 KB gzip (limit 1,600 KB).
- **Phase 12 (`document-editor` v0.2.0)** — Site-creator mode via GrapesJS (grapesjs@0.22.16 BSD-3-Clause + @grapesjs/react@2.0.0 MIT) with built-in blocks + localStorage persistence. Bundle ~485 KB gzip (limit 500 KB).
- **`media-studio` Phase 4** — Three new modes: image-editor (Fabric.js), media-player, audio-waveform.

#### Added — CI / Build Pipeline

- **`bin/build-plugin-zip.sh --wp-org` flag** — produces a WP.org-compliant base-only ZIP; root `*.md`, `addons/`, and `.zed` excluded. Full GitHub Release ZIP built as a separate `--combined` artifact.
- **PHPUnit coverage baseline + non-regression CI gate** — `tests/coverage/baseline.xml` committed; CI fails if uncovered-class count regresses.
- **AJAX handler CI guard** — audit script confirms 0 uncovered handlers; allowlist committed with explanations.
- **`spa-bundle-size.yml`** — bundle-size guardrail for all SPA addons (per-addon KB limits).
- **`spa-a11y.yml`** — axe-core a11y audit CI for all SPA addons.
- **`link-check.yml`** — treats 4xx responses as warnings (not errors); skips template URLs and `gnu.org`.
- **`phpunit.yml`** — uses MySQL TCP host (`WP_DB_HOST` env var); sets `WP_CORE_DIR` + `WP_TESTS_DIR` directly (skips `codex-startup.sh`); paths filter so the job runs only on PHP/test/config changes.

#### Added — `WP_MCP_AI_User_Context_Helper`

New `includes/helpers/class-wp-mcp-ai-user-context-helper.php` centralises privileged-operation user-context switching:

- `safe_set_current_user( $user_id )` — validates `get_userdata()` before touching global state; multisite adds `is_user_member_of_blog()` check.
- Replaces ad-hoc `wp_set_current_user()` / `wp_update_user()` calls (B10 reviewer finding).
- PHPUnit suite: `tests/test-user-context-helper.php`.

#### Fixed — WordPress.org Reviewer Findings (PRs #4892, #4902)

- **B1** — Removed unescape‑before-output pattern; all admin output now uses `esc_*` functions exclusively.
- **B2** — Removed dead WP < 5.7 fallback branches.
- **B3** — Eliminated inline `<script>` / `<style>` echoes: config blocks converted to `wp_print_inline_script_tag()` hooked on `admin_enqueue_scripts`; telemetry CSS moved to `wp_add_inline_style()`.
- **B5** — Fixed all file-permission and path-traversal guard gaps flagged in the review.
- **B8** — Filesystem cache path corrected: moved from `WP_CONTENT_DIR/cache/wp-mcp-ai` to `wp_upload_dir()['basedir']/wp-mcp-ai-cache`.
- **B10** — `wp_set_current_user()` hardened via `WP_MCP_AI_User_Context_Helper::safe_set_current_user()`.
- **B11** — Sanitisation gaps on settings inputs closed.
- **B12** — All REST permission callbacks reviewed; missing `manage_options` gates added.
- **B13** — `$_POST['approval_id|resolution|note']` in approvals handler now wrapped with `wp_unslash()`; bare `phpcs:ignore NonceVerification.Recommended` lines in DAG builder replaced with explanatory comments.
- **49/49 base AJAX handlers** confirmed with `check_ajax_referer()`. Full evidence: `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`.

#### Fixed — Docs Hub

- Sitemap provider registered via `wp_sitemaps_init` hook (fixes fatal error on activation).
- `#section` anchors no longer corrupt `HashRouter` navigation.
- Heading anchors visible by default; sidebar strips markdown.
- Remote links resolved correctly; browse-repo crash hardened.
- Mobile sidebar toggle added.
- GitHub subtree path fetch corrected.

#### Fixed — Other Addons

- **Graphify** — detects CCTs whose slug only lives in `$type->args['slug']` (previously missed by the primary slug lookup).
- **SaaS Controller** — Cloudflare preflight endpoint switched from `/user/tokens/verify` to the correct API endpoint; React wizard pre-built and committed to dist.

#### Fixed — CI / Vendor

- **`phpcs.xml.dist`** — excludes `tests/fixtures/**` from PHPCS + PHPCompatibility scans; filename rule lifted for test files.
- **`composer install`** — preferred-install set to `dist` (fixes symfony git-dir error); `installed.json` installation-source corrected; 4 symfony packages upgraded to lock-file versions.
- **Production autoloader** — regenerated as `--no-dev --classmap-authoritative` (677 production-only classes; eliminates PSR-4 runtime fallback).

#### Changed

- **esbuild** pinned to `^0.27.0` with `safari15` target across all 6 SPA addons (esbuild 0.27.x with Safari 13/14 targets fails; `safari15` resolves correctly).
- **vitest** aligned to `^4.1.5` across all 6 SPA addons (vitest 4 requires vite 8 which requires esbuild ^0.28 — handled by peer-dependency override).
- **`addons/canvas`, `addons/cornerstone3d`** — standalone `LICENSE` and `THIRD_PARTY_NOTICES` files added; proprietary banner added to all PHP headers.

#### Tests — PHPUnit + Vitest Coverage Campaign (PRs #1–#11)

- **PR #2** — Tool registry coverage smoke test + manifests.
- **PR #3** — Harness tests via class-name matcher.
- **PR #4** — NVIDIA client + smarter matcher reads class declaration.
- **PR #5** — REST controller tests (approval, cost-manager, slash-command).
- **PR #6** — Slash-command tests (help, context, compact, memory).
- **PR #7** — 20 high-risk base tool tests (create-post, check-site-security, load-skill, …).
- **PR #8** — 20 highest-risk Pro tool tests (vault, schedules, ECA, medical, autonomous-session).
- **PR #9** — 10 security-sensitive service class tests (90 tests, 230 assertions).
- **PR #10** — Hooks and security regression suite (4 files, 52 tests: `test-hooks-tool-lifecycle.php`, `test-hooks-chat-lifecycle.php`, `test-hooks-registry.php`, `test-security-regression.php`).
- **PR #11** — Vitest scaffolding for all 6 SPA addons (~71 tests): `toolkit-shell`, `canvas-toolkit`, `document-editor`, `media-studio`, `chat-spa`, `docs-hub`.

#### Tests — AJAX Handler Coverage Campaign (Clusters 1–17)

All 271 AJAX handlers now have test coverage. Allowlist cleared to 0. CI guard added.

- Clusters 1–4: base testcase, workflow pilot, approvals, skill manager, settings utility.
- Clusters 5–7: wizard/dismiss, runtime control, embedded models + datasets.
- Clusters 8–9: provider connections, schedule manager.
- Clusters 10–11: healthcare, regulatory/ECA/CRE.
- Clusters 12–17: remaining 116 handlers.

#### Documentation

- **`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`** — full evidence table for all WP.org B-series findings.
- **`SUBMISSION.md`** — reviewer-response table cross-referencing each finding to the fix commit.
- **`docs/developer/addons/toolkit-spa-blueprint.md`** — updated to v2.5: §20 migration guide from legacy `chat.js` to Chat SPA; Tier B/C/D/E tables updated; status line updated.
- **`CREDITS.md` / `THIRD_PARTY_NOTICES.md`** — updated for GrapesJS, @grapesjs/react, tldraw, bpmn-js, rehype-highlight, lowlight; per-addon README Credits sections added.

## [1.1.16] - 2026-05-06

### May 6, 2026 — SaaS Controller Addon (v0.1.0) + Structured Logging Integration

#### Added — SaaS Controller Addon (`addons/saas-controller/`, v0.1.0)

Operator-side WordPress admin toolkit for provisioning and managing the NV oOS Cloud control plane (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter) from inside WP-Admin. The operator-facing counterpart to `addons/cloud-worker/` — where the cloud worker is the deployed runtime, the SaaS Controller lets a maintainer provision, plan/apply changes to, drift-check, and audit that runtime without leaving WP-Admin.

**Admin UI** — `WP-Admin → NV oOS SaaS` (`manage_options`), four tabs:

- **Overview** — React Credentials Wizard (Credentials → Validate → Save) with masked-credentials table fallback.
- **Deployment** — desired Cloudflare topology editor (Worker name, account ID, AI Gateway slug, D1 databases, KV namespaces) + read-only **Run Plan** button.
- **Operations** — HITL-gated Apply panel (Preview → sync or async background Apply), Drift Detector, Orphan Review, Webhook Events, Smoke Tests, and 50-entry audit-log tail with Clear.
- **Packages** — in-product credits surface (upstream homepage, license, copyright per npm package).

**Implemented phases:**

- **Phase 2** — WP-Admin & REST scaffolding, encrypted credential store (AES-256-CBC keyed from `AUTH_KEY + SECURE_AUTH_KEY`), deployment-config store (`nvoos_saas_controller_deployment`).
- **Phase 3** — Connection tester: read-only HTTPS preflights against Cloudflare, Stripe, and OpenRouter (10 s timeout, normalised result shape, never echoes secrets).
- **Phase 4** — Read-only Cloudflare client (`NVOOS_SaaS_Controller_Cloudflare_Client`) covering D1, KV, Workers scripts, AI Gateway.
- **Phase 5a–5d** — Reconcile-plan generator (`NVOOS_SaaS_Controller_Plan_Generator`): diffs desired config vs live Cloudflare state; emits `creates / updates / noops / orphans / errors` with summary counts. Phase 5d adds Worker multipart upload via mutating client (`NVOOS_SaaS_Controller_Cloudflare_Mutating_Client`).
- **Phase 5e** — Drift-manifest stamping (`scripts/stamp-drift-manifest.mjs`); auto-invoked by `npm run build:worker`.
- **Phase 6** — Stripe client (`NVOOS_SaaS_Controller_Stripe_Client`) + OpenRouter provisioning client (`NVOOS_SaaS_Controller_OpenRouter_Client`); plan rows for `stripe_product`, `stripe_price`, `openrouter_key`.
- **Phase 7** — Stripe webhook verifier (`NVOOS_SaaS_Controller_Stripe_Webhook_Verifier`, HMAC-SHA256, constant-time, 300 s replay window) + webhook event store (`NVOOS_SaaS_Controller_Webhook_Event_Store`, 200-entry ring buffer, idempotent by provider + event_id).
- **Phase 8** — Background async Apply (`NVOOS_SaaS_Controller_Apply_Job`): one-row-per-tick cron worker, 6 h state transient, 200-row ceiling, `MAX_TOTAL_ROWS` guard.
- **Phase 9** — Background-apply admin UI: progress card (`<progress>` bar + counters), 2 s polling, Cancel button.
- **Phase 10** — Orphan cleanup: separate single-use HITL token (`nvoos_saas_orphan_` namespace), `POST /apply/orphans/preview` + `POST /apply/orphans/run`.
- **Phase 11** — Webhook Events card under Operations tab (paginated table, Refresh, Clear).

**Audit log** (`NVOOS_SaaS_Controller_Audit_Log`) — append-only 200-entry ring buffer. Channels: `cloudflare` / `stripe` / `openrouter` / `internal`. Filterable via `nvoos_saas_controller_audit_log_max_entries` and `nvoos_saas_controller_audit_log_record`.

**REST namespace** `/wp-json/nvoos-saas/v1/` — all routes require `manage_options` except `POST /webhooks/stripe` (signature-gated). Full route list: `GET /healthz`, `GET|POST|DELETE /credentials`, `POST /connections/test`, `GET|POST /deployment`, `POST /plan`, `GET|DELETE /audit-log`, `POST /smoke-tests/run`, `GET /smoke-tests/last`, `POST /apply/preview`, `POST /apply/run`, `POST /apply/enqueue`, `GET /apply/jobs/{id}`, `POST /apply/jobs/{id}/cancel`, `POST /apply/orphans/preview`, `POST /apply/orphans/run`, `POST /drift/check`, `GET /drift/last`, `POST /webhooks/stripe`, `GET|DELETE /webhooks/events`.

**Key filters:** `nvoos_saas_controller_apply_token_ttl` (default 900 s), `nvoos_saas_controller_audit_log_max_entries` (default 200), `nvoos_saas_controller_audit_log_record` (suppress a log entry), `nvoos_saas_controller_webhook_events_max_entries` (default 200), `nvoos_saas_controller_apply_job_state_ttl` (default 6 h), `nvoos_saas_controller_worker_dist_path`, `nvoos_saas_controller_worker_compatibility_date`, `nvoos_saas_controller_worker_upload_metadata`.

See [`addons/saas-controller/README.md`](addons/saas-controller/README.md) for the full implementation detail, architecture diagram, and build instructions.

#### Added / Improved — Structured Logging Integration (PR #4849)

`WP_MCP_AI_Logger` calls added systematically across the plugin and all addons:

- **`WP_MCP_AI_Agent_Memory_CCT_Bridge`** (Phase 4b-2) — all bridge writes, CCT mirror failures, filter-suppressed writes, and deletions are now routed through `WP_MCP_AI_Logger` at the appropriate level (info / warning / error).
- **`WP_MCP_AI_Transcript_Mining_Job`** — structured logging throughout the entire job lifecycle: enqueue, each cron tick, per-session processing, job completion, cancellation, and all error paths.
- **Addons:** Algorave, Canvas, Webchat (`class-wp-mcp-ai-jetengine-webchat-messages-cct.php`, settings page, signaling REST, metaboxes, tools), Fantasy Football (ESPN client, CPTs, tools), Graphify (all nine classes including the NV oOS bridge), SaaS Controller (all admin, service, and REST classes).
- **Core / Admin:** Run Timeline admin page, Admin Approvals page, Settings Base class, cost calculator, DeepSeek client, encryption class, Erlang C class, JetEngine Agent Memories CCT, model catalog migration, Ollama client, outbound webhook, REST MCP methods, shortcode, tool registry, workflow CPT, workflow engine V2.
- **Harness:** eval scheduler, harness profile, prompt-cue library, retrieval harness, self-refine loop, tool-router harness.
- **Services:** transcript-mining REST controller, transcript-mining job, memory slash command, workflow slash command.

**New PHPUnit test classes:**
- `tests/test-agent-memory-cct-bridge-logging.php` — covers logging on store (success + CCT failure), delete, filter-suppressed store, and absent-class guard.
- `tests/test-transcript-mining-job-logging.php` — covers logging on enqueue, tick (with and without sessions), per-session success, per-session error, cancellation, and completion.

---

## [1.1.15] - 2026-05-05

### May 3–5, 2026 — New Providers (OpenRouter + DeepSeek), LM Studio Parity, Orchestration Phases 1–7, LLM Harnessing GA, 19 New Slash Commands, Memory Bridge G-series, Retroactive Transcript Mining, Graphify NV oOS Data-source Bridge, Observability UI, Stability Sweep
### May 5, 2026 — NV oOS Cloud Worker (SaaS backend, `addons/cloud-worker/`)

The Cloudflare-Worker counterpart to the Pro plugin module shipped on May 5.
Lives in this monorepo for review convenience and is deployed independently
to `nvoos.cloud`.

- **Stack:** TypeScript + Hono router + Stripe (HTTPS API, no Node SDK at request-time) + D1 + KV.
- **Endpoints:**
  - `POST /v1/chat/completions`, `POST /v1/embeddings`, `GET /v1/models` — OpenAI-compatible passthrough through Cloudflare AI Gateway → OpenRouter, with SSE streaming preserved.
  - `GET /v1/account/balance`, `POST /v1/account/topup`, `POST /v1/account/revoke`, `GET /v1/account/ledger` — wallet management.
  - `POST /connect/start` / `POST /connect/finish` — public connect flow that issues a Connect Token after the first paid Stripe Checkout session.
  - `POST /stripe/webhook` — signature-verified (`Stripe-Signature` t/v1 scheme, 5-minute tolerance, constant-time HMAC-SHA-256 compare), idempotent against `event.id`.
- **Money math:** all balances stored as integer micro-USD to eliminate float drift across many small per-request debits. Markup matches the plugin to the cent (`wholesale × 1.07`).
- **Security:**
  - Master OpenRouter key is a Wrangler secret, never exposed.
  - Connect tokens stored as SHA-256 hashes; plaintext shown to the user once.
  - Site-binding via `X-NV-Site-Url` verified on every request (HTTP 403 on mismatch).
  - No PII in ledger — token counts only, never message bodies.
- **Schema:** `addons/cloud-worker/schema.sql` with four tables (`wallets`, `connect_tokens`, `ledger`, `topup_sessions`) + indexes.
- **Tests:** 16 vitest tests in the `@cloudflare/vitest-pool-workers` Miniflare environment — pricing math, micro-USD round-trip, site-URL normalization, SHA-256 stability, token entropy, Stripe webhook signature accept/reject (signature mismatch, body tamper, timestamp out of tolerance, missing header).

### May 5, 2026 — NV oOS Cloud — hosted "Managed Tokens" service (Pro)

A new **Pro-only** subsystem that lets a site route inference through NV's
master OpenRouter account via a Cloudflare AI Gateway proxy — no per-provider
key management required. Sits alongside the existing free BYOK flow.

- **Brand:** NV oOS Cloud · **Hosting:** Cloudflare Worker → Cloudflare AI Gateway → OpenRouter · **Geographic scope:** worldwide (Stripe Tax handles VAT/GST/sales tax).
- **Pricing:** wholesale × **1.07** (7% service fee) + Stripe processor pass-through (2.9% + $0.30) shown as a transparent line item. Minimum top-up $25 USD.
- **New base filter** `wp_mcp_ai_route_to_provider` (in `WP_MCP_AI_Language_Model_Router::route_to_provider()`) that lets any add-on register a custom provider id without forking the router.
- **New Pro module** under `addons/pro/includes/`:
  - `services/class-wp-mcp-ai-nv-cloud-service.php` — singleton with encrypted Connect Token storage (AES-256-CBC keyed by `AUTH_KEY`+`SECURE_AUTH_KEY`), balance cache, prefs, markup math, ledger.
  - `providers/class-wp-mcp-ai-nv-cloud-client.php` — OpenAI-compatible HTTP client (subclass of the existing OpenRouter client) targeting `nvoos.cloud/v1`.
  - `providers/class-wp-mcp-ai-nv-cloud-provider-client.php` — `Interface_WP_MCP_AI_Provider_Client` adapter.
  - `services/class-wp-mcp-ai-nv-cloud-billing-observer.php` — hooks `wp_mcp_ai_cost_calculated`, writes wholesale + 7% + total ledger entries (200-entry cap).
  - `rest/class-wp-mcp-ai-nv-cloud-rest-controller.php` — `/mcp-ai-pro/v1/cloud/{status,connect,disconnect,refresh-balance,topup-url,ledger,prefs}` (all `manage_options`-gated).
  - `admin/class-wp-mcp-ai-nv-cloud-settings-page.php` — admin UI (Connect, Balance, Top-up via Stripe Checkout, Auto-top-up, Ledger, low-balance banner).
  - `nv-cloud-init.php` — bootstrap (router filter, REST registration, daily balance-refresh cron).
- **Documentation:** `docs/features/nv-cloud.md` (architecture, Worker contract, security model, Cloudways vs Cloudflare comparison).
- **Tests:** `addons/pro/tests/test-nv-cloud.php` — service round-trip, encryption-at-rest verification, markup math, Stripe pass-through math, ledger cap, billing-observer gating, REST permission gates, top-up minimum, router-filter wiring.

### May 4, 2026 — LM Studio provider brought to parity with May 2026 capabilities

A major capabilities + stability release building on 1.1.14. Headline additions: (1) **Three new AI providers** — OpenRouter (unified multi-provider gateway), DeepSeek, plus Kimi K2.6 + Qwen 3.6 in the model catalog; (2) **LM Studio parity** — native cURL SSE streaming plus seven phases of May 2026 capability alignment; (3) **Orchestration roadmap Phases 1–7** re-landed with JetEngine CCT init-priority compat — HITL approvals, prompt-injection guardrail, structured output, OTel, DAG builder, durable runs, triggers/webhooks, sub-agents, Pro vector-store adapter, and Pro team budget manager; (4) **LLM Harnessing Subsystem (Layers A–H)** ships GA; (5) **19 new slash commands** (11 base + 8 Pro); (6) **Chat-client Memory Bridge G-series** complete with site-wide toggle; (7) **Retroactive Transcript Mining** stuck-job root causes fixed; (8) **Graphify NV oOS data-source bridge** — private CPTs, CCT resolvers, MemPalace edges, external `$wpdb` tables; (9) **Observability UI** surfaced under the Orchestration tab with OTLP configuration; (10) stability sweep covering transcript-mining, credential nonces, JetEngine CCT prefix, workflow tab, multi-agent dashboard, and site-health polyfill.

#### Added — New AI providers

- **OpenRouter** (`WP_MCP_AI_OpenRouter_Client`, PR #4840): a unified, OpenAI-compatible gateway in front of OpenAI, Anthropic, Google, Meta, Mistral, and others, reachable through a single API key. Selectable from **Settings → NV oOS → Providers → OpenRouter**.
- **DeepSeek** (`WP_MCP_AI_DeepSeek_Client`, PR #4820): first-class provider with full model selection and `reasoning_content` / `<think>…</think>` passthrough matching the REST-layer handling already in place for DeepSeek-R1/Qwen-QwQ.
- **Kimi K2.6 + Qwen 3.6** (PR #4810): both models added to the model catalog and provider dropdowns.

#### Added — LM Studio — real-time token streaming + parity (PRs #4818, #4839)

- **Real-time SSE streaming via native cURL** (PR #4839): `create_chat_completion()` now sends `stream: true` and opens a cURL stream directly, invoking `$options['stream_callback']` per `data: {…}` chunk. Content tokens and tool-call argument deltas are accumulated; the final return value is a standard OpenAI-shaped response. New filter: `wp_mcp_ai_lm_studio_stream_request_args`.
- **Native `/api/v0` opt-in** (Phase 2): new setting `lm_studio_use_native_api`. Native responses include `stats` (tokens/sec, TTFT, generation_time) as `usage_stats` and via `wp_mcp_ai_lm_studio_provider_stats`. `list_models()` returns `arch`, `quantization`, `state`, `max_context_length`, `loaded_context_length`, and `capabilities`. New per-request filter: `wp_mcp_ai_lm_studio_native_endpoint`.
- **Embeddings** (Phase 3): new `create_embedding( $input, $options )` method calling `/v1/embeddings`.
- **Bearer-token auth** (Phase 4): new `lm_studio_api_key` setting. Keys are never logged.
- **Capability gating + reasoning + argument repair** (Phase 5): models without `tool_use` return `WP_Error( 'wp_mcp_ai_tools_unsupported_by_model' )`; `reasoning_content` preserved; `<think>…</think>` stripped into `reasoning_content`; malformed `arguments` auto-repaired.
- **TTL + structured outputs** (Phase 6): `$options['ttl']` and `$options['response_format']` forwarded in payload.
- **Improved `test_connection()`** (Phase 7): falls back to `/api/v0/models` on 404; includes `x-lm-studio-version` in success message. `CAPABILITIES_TRANSIENT` class constant exposed for external cache invalidation.

#### Added — Orchestration roadmap Phases 1–7 (PRs #4816, #4821)

Re-lands the full orchestration feature set with a JetEngine CCT init-priority fix: all NV oOS CCT bootstraps now register on `init` at priority 11+ to avoid racing JetEngine's table-cache hydration (priorities 1–10).

- **Phase 1 — Observability**: Run Timeline panel, Layer I Prompt-Injection Detector (`WP_MCP_AI_Prompt_Injection_Detector`, harness profile key `injection_detector.enabled`, action `wp_mcp_ai_prompt_injection_detected`), Structured Output Guardrail, OTel span exporter (OTLP/HTTP). Observability dashboard now surfaced under the **Orchestration** tab (PR #4833). OTLP endpoint + token configurable under **Tools → Connections** (PR #4837).
- **Phase 2 — HITL**: `WP_MCP_AI_Approval_Queue` (CPT `mcp_ai_approval` — `pending`→`publish`=approved, `private`=denied, `trash`=expired). REST at `/mcp-ai/v1/approvals/*`. `request_user_approval` tool. Admin **Approvals** page. Registered in `bootstrap/loader.php` after repositories-init.
- **Phase 3 — Structured output**: schema-validated response pass-through; guardrail enforces schema compliance before tool result is accepted.
- **Phase 4 — DAG builder**: visual DAG workflow builder UI and DAG execution engine.
- **Phase 5 — Durable runs**: `WP_MCP_AI_Durable_Run_Store` persists run state across HTTP boundaries; supports pause/resume and long-running async tasks.
- **Phase 6 — Triggers + webhooks**: `WP_MCP_AI_Workflow_Trigger_CPT` fires workflows from WordPress events or inbound webhooks.
- **Phase 7 — Sub-agents + Pro hardening**: sub-agent dispatch (`WP_MCP_AI_Sub_Agent_Dispatcher`), Pro `WP_MCP_AI_Vector_Store_Adapter` (openai / pgvector / qdrant backends), Pro `WP_MCP_AI_Team_Budget_Manager` (per-team daily caps + namespace prefix). Phase 7 Pro services loaded from `addons/pro/includes/services/services-init-phase6.php`.

#### Added — LLM Harnessing Subsystem (Base + Pro, `includes/harness/`)

Seven opt-in layers that improve response quality without modifying existing tool behaviour. Every layer is off by default; activation is per assistant via the **LLM Harness** metabox on the assistant edit screen. Full reference: [`docs/features/llm-harness.md`](docs/features/llm-harness.md).

- **Layer A — Prompt / Cue** (`WP_MCP_AI_Prompt_Cue_Library`): registry of seven named, versioned cue templates (Chain-of-Thought, Failure-Modes-First, Plan-Then-Solve, Cite-or-Abstain, Tool-or-Abstain, Clarify-First, State-Uncertainty) that prepend to — never replace — the assistant's existing system prompt. Extensible via `wp_mcp_ai_register_prompt_cues`. Selected per task class via `wp_mcp_ai_select_prompt_cue`.
- **Layer B — Reasoning / Rehearsal** (`WP_MCP_AI_Reasoning_Trace`): canonical trace schema (`assumptions → constraints → plan → intermediate_results → verification → answer`) plus a self-consistency vote primitive (best-of-N sampling with configurable N).
- **Layer C — Tool Routing** (`WP_MCP_AI_Tool_Router_Harness`): scores candidate tools by task-class-aware capability flags + per-assistant preferences. Supports a `preset_weights` map (preset slug → float `[-5, 5]`) for broad family biases. Filterable via `wp_mcp_ai_harness_tool_score` (now receives resolved preset weights as 5th argument). Renders a **Preferred tool families** disclosure under the Tool Router fieldset in the LLM Harness metabox.
- **Layer D — Retrieval** (`WP_MCP_AI_Retrieval_Harness`): single entry point that fans out to `recall_memory`, `semantic_context_search`, and `retrieve_agent_memory`; deduplicates by content hash, attaches provenance and freshness, and verifies citations. Filterable via `wp_mcp_ai_retrieval_passages` and `wp_mcp_ai_retrieval_claim_supported`.
- **Layer E — Feedback / Self-Refine** (`WP_MCP_AI_Self_Refine_Loop`): synchronous, bounded `generate → critique → revise` loop with hard caps on iterations and cost. Reflexion-style verbal reflections persisted via `record_reflection` after PII scrubbing (Layer F).
- **Layer F — Memory Scoping** (`WP_MCP_AI_Tool_Scope_Memory` + `WP_MCP_AI_Pii_Filter`): task-class buckets so reflections from one task don't pollute another; conservative regex sweep for emails, phones, SSNs, credit-card-shaped digits, and common API key prefixes before any reflection write. Patterns filterable via `wp_mcp_ai_pii_filter_patterns`.
- **Layer G — Evaluation** (`WP_MCP_AI_Harness_Eval_Scheduler`): profile-driven eval scheduler walks every assistant with `harness_profile.enabled` + non-empty `evals_enabled` on a daily cron (`wp_mcp_ai_harness_eval_tick`), runs each suite via `WP_MCP_AI_Eval_Runner` using a generator wired through `wp_mcp_ai_harness_eval_generator`, and records summaries to the `WP_MCP_AI_Eval_Run_Store` + per-assistant `_wp_mcp_ai_harness_last_evals` meta. No generator → run is skipped, never errored. Direct invocation available via `run_suite_for_assistant()`.
- **Layer H — Curriculum / Fine-tune Export (Pro)** (`WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum`): walks the assistant's `harness_profile.evals_enabled` eval suites and emits one OpenAI chat-format JSONL row per case. Supports `dry_run`, `max_cases` cap (hard ceiling 5000), and per-case character cap (filter `wp_mcp_ai_pro_curriculum_per_case_char_cap`, default 16 000). Output written to `wp-content/uploads/mcp-ai/harness-curriculum/` with `.htaccess`/`index.php` guards. Registered via `wp_mcp_ai_pro_tools` filter from `addons/pro/includes/harness-init.php`.
- **Harness profile schema** (stored in `_wp_mcp_ai_harness_profile` post meta): `enabled` (bool), `layers` (A–G flags), `cost_ceiling_usd` (float), `tools.router_mode` (`scored`|`default`), `tools.preset_weights` (map), `evals_enabled` (array of suite slugs), `pii_filter` (bool).
- **Hooks summary**: `wp_mcp_ai_register_prompt_cues` (action), `wp_mcp_ai_select_prompt_cue` (filter), `wp_mcp_ai_harness_profile` (filter), `wp_mcp_ai_harness_tool_score` (filter), `wp_mcp_ai_retrieval_passages` (filter), `wp_mcp_ai_retrieval_claim_supported` (filter), `wp_mcp_ai_pii_filter_patterns` (filter), `wp_mcp_ai_harness_eval_generator` (filter), `wp_mcp_ai_harness_eval_tick` (cron action).

#### Added — 19 new slash commands (11 base + 8 Pro, 32 total)

The slash command surface nearly doubled — from ~13 to 32 commands (24 base + 8 Pro). New base commands are registered in `includes/slash-commands/slash-commands-init.php`; Pro commands are registered via the `wp_mcp_ai_slash_commands_initialized` action from `addons/pro/includes/slash-commands/slash-commands-init.php`.

**New base commands (since v1.1.14):**

| Command | Aliases | Capability | Description |
|---------|---------|-----------|-------------|
| `/jobs` | — | `edit_posts` | List and manage async background jobs |
| `/status` | — | `edit_posts` | System health: async health, job counts, tool registry |
| `/cost` | — | `edit_posts` | Token usage and cost summary |
| `/diagnose` | `debug` | `manage_options` | Diagnostic bundle for support |
| `/tools` | — | `edit_posts` | Browse, filter, and inspect registered tools |
| `/skills` | — | `edit_posts` | List, inspect, and install agent skill packs |
| `/preset` | — | `edit_posts` | List, inspect, and apply orchestration presets |
| `/model` | — | `edit_posts` | List available models; view or set the model for an assistant |
| `/markup-stats` | `mstats` | `manage_options` | Show aggregate markup telemetry counters |
| `/remember` | `memorize` | `edit_posts` | Store verbatim long-term memory for the current assistant |
| `/forget` | — | `edit_posts` | Delete a stored memory by `context_id` |
| `/scope` | — | `edit_posts` | Set the active wing/room scope for memory operations |
| `/compact` | — | `read` | Proactive context compaction (summarize, trim-tools, keep-recent, full) |
| `/context` | `ctx` | `read` | Show context budget: token usage, message count, remaining capacity |
| `/clear` | — | `read` | Clear the chat window (front-end signal only) |
| `/reset` | — | `read` | Reset the current session context |
| `/resume` | — | `read` | Resume the most recent saved session transcript |
| `/workflow` | — | `edit_posts` | Execute and manage custom automation workflows |
| `/sync-docs` | `docs` | `edit_posts` | Documentation drift detection and synchronization |
| `/optimize-perf` | `perf` | `manage_options` | Automated performance analysis and optimization |

**Pre-existing base commands** (shipped in earlier versions): `/help`, `/next-task`, `/ship`, `/clean-content`, `/memory` (sub-commands: `remember`, `forget`, `scope`).

**New Pro commands:**

| Command | Aliases | Capability | Description |
|---------|---------|-----------|-------------|
| `/schedule` | `sched` | `edit_posts` | Manage Pro schedules: list, show, create, pause, resume, delete, run, history |
| `/schedule-preset` | `sched-preset` | `edit_posts` | Browse and install Pro schedule presets |
| `/workflow-preset` | — | `edit_posts` | Browse and install Pro workflow presets |
| `/run` | — | `edit_posts` | Trigger a Pro autonomous run or workflow |
| `/agent` | — | `edit_posts` | Agent-to-Agent (A2A) dispatch: call a peer assistant |
| `/mcp-app` | — | `edit_posts` | Manage per-assistant MCP App connections |
| `/persona` | — | `edit_posts` | Switch the active assistant persona |
| `/broadcast` | — | `manage_options` | Broadcast a message across configured channels |

- **Fixed — `/status` render bug** — the `/status` command could emit a PHP notice on sites where the async health check returns an unexpected shape; the output normalisation path now coerces all status values to strings before markdown rendering.

#### Added — Chat-client ⇄ Memory Bridge (G-series completion)

Completes the durable chat-client memory integration. REST proxy and JS service were first introduced as stubs; the G-series ships the full visible surface.

- **G2 — Audit tab** (`chat-memory-drawer.js` + `WP_MCP_AI_REST_Chat_Memory_Controller::audit()`): third tab inside the Memory Drawer; lazy-loads on first activation via `memoryService().audit()`; carries `data-testid` attributes for Jest.
- **G3 — Auto badge** — every assistant message bubble that touched a memory tool call automatically gains a 🧠 badge via `decorateMessageWithBadge()` in `chat.js`.
- **G6 — Pagehide auto-capture** — `pagehide` + `visibilitychange→hidden` event handler in the Memory Drawer auto-captures the current session state to the REST proxy; uses `mb_strcut` for UTF-8-safe truncation and falls back to an LLM summarisation pass when the payload exceeds the per-message cap.
- **G8 — SSE `memory_event` frame** — the agentic loop now emits a `memory_event` SSE frame (`{ action, tool_name, tool_id }`) whenever a memory tool call completes; the chat client shows a 🧠 toast in real time.
- **G11 — Drawer export** — the Memory Drawer "Export" button downloads the visible memory set as a JSON archive.
- **REST proxy** (`WP_MCP_AI_REST_Chat_Memory_Controller`): six routes under `/mcp-ai/v1/chat-memory/` — `preferences`, `wake-up`, `recall`, `store`, `audit`, `/{context_id}`. Delegating to base tools (`wake_up_context`, `recall_memory`, `store_agent_context`, `memory_audit_trail`, `manage_context_lifecycle`). Endpoints localized into `window.wpMcpAiChat.memoryEndpoints` via the shortcode inline script.
- **Three gates**: (1) site-wide filter `wp_mcp_ai_chat_memory_enabled` (return `false` to disable globally); (2) site-wide **Enable Chat-Client Memory** toggle in **Orchestration → Settings** (PR #4802); (3) per-user meta `wp_mcp_ai_chat_memory_enabled` (user can opt out from the drawer Preferences tab).
- Reference: [`docs/features/memory/chat-client-integration.md`](docs/features/memory/chat-client-integration.md).

#### Added — Retroactive Transcript Mining

- **Background job** (`WP_MCP_AI_Transcript_Mining_Job`): queued worker with transient state (prefix `wp_mcp_ai_tx_mine_job_`, 6h TTL), maximum 500 sessions per job, default 10 sessions/tick. Cron hook: `wp_mcp_ai_transcript_mining_tick`. Sentinel `__auto__` in the session queue means "let the underlying `mine_agent_memory` tool resolve its own session set per tick" — useful for broad-sweep jobs where the session list is not known in advance.
- **REST controller** (`WP_MCP_AI_REST_Transcript_Mining_Controller`): three admin-only (`manage_options`) endpoints:
  - `POST /mcp-ai/v1/transcript-mining/jobs` — enqueue a new job.
  - `GET /mcp-ai/v1/transcript-mining/jobs/{id}` — poll progress.
  - `POST /mcp-ai/v1/transcript-mining/jobs/{id}/cancel` — cancel a running job.
- **`mine_agent_memory` `transcripts` source** — new source value alongside existing `posts|urls|text`. Uses `transcript_query` (fields: `assistant_id`, `user_id`, `since`, `until`, `session_keys`, `min_messages`, `only_unextracted`, `posts_per_page` max 50). Emits items with provenance metadata: `transcript_session_key`, `assistant_id`, `message_range`, `content_hash`. Filters: `wp_mcp_ai_mine_transcripts_sessions`, `wp_mcp_ai_mine_transcripts_session_messages`, `wp_mcp_ai_mine_transcripts_dedupe_scan_limit` (default 1000).
- Reference: [`docs/features/memory/transcript-mining.md`](docs/features/memory/transcript-mining.md).

#### Added — Graphify NV oOS data-source bridge (PR #4834)

Full integration of NV oOS-owned data into the Graphify knowledge graph.

- **Private CPT resolver**: any CPT registered with `WP_MCP_AI_CPT_*` is automatically discovered and indexed as graph nodes.
- **JetEngine CCT resolvers**: CCT items are structured as `cct_{slug}` nodes with `AUTHORED_BY` edges, embedded end-to-end, and discoverable from the **Sources (CPT / CCT)** tab on the Knowledge Graph settings page (PR #4836).
- **MemPalace edges**: `ai_agent_memories` CCT items are linked to source content via `RECALLS` edges, enabling memory-aware graph traversal.
- **External `$wpdb` tables**: plugin-managed tables (e.g., transcript, approval, metric stores) can be registered as Graphify data sources via the `wp_mcp_ai_graphify_data_sources` filter.

#### Added — Pro Packages Tier 5 (Chat Service Utilities)

Five new browser-native NPM packages published under `@nvdigitalsolutions/` and surfaced on the **NV oOS → Pro Packages** admin screen:

| Package | Description | License |
|---------|-------------|---------|
| `@nvdigitalsolutions/nvoos-client-tools` | Browser-native AI tool registry (summarize, sentiment, translate, embed, image, audio) | MIT |
| `@nvdigitalsolutions/nvoos-chat-memory` | Promise-based REST client for the AI chat memory bridge (wake-up, recall, store, audit, preferences) | MIT |
| `@nvdigitalsolutions/nvoos-attachments` | File attachment helpers: type detection, validation, normalisation | MIT |
| `@nvdigitalsolutions/nvoos-cron-status` | SSE-first cron/job status monitor with REST polling fallback | MIT |
| `@nvdigitalsolutions/nvoos-transcription` | MediaRecorder-based audio recording + tool-call transcription pipeline | MIT |

#### Added — DX / infra

- **Custom devcontainer image** (PR #4811): `.devcontainer/` configuration with a WordPress plugin dev image, MySQL service, and VS Code extensions pre-configured for PHP + JS development.
- **Zed agent profiles** (PR #4808): the `examples/agents/` 12-agent roster is mirrored as native Zed agent profiles in `.zed/settings.json`, enabling one-click context switching in the Zed editor's Agent Panel.

#### Fixed

- **Transcript-mining stuck at "queued"** — three compounding root causes fixed in PRs #4804 and #4826: (1) `wp_schedule_single_event()` was called with a future timestamp making the cron fire one interval too late; (2) `spawn_cron()` was never called after scheduling, so the job sat in the queue until the next natural cron cycle; (3) the transient state key had a namespace collision that prevented the tick handler from reading the job record. All three fixed with a codebase-wide sweep.
- **"Workflow not found" on admin Workflows tab** for orchestrator-managed workflows (PR #4803).
- **`workflow-cpt` `map_meta_cap=false`** blocked JetEngine on WP 6.1 `delete_post` notice (PR #4822).
- **Graphify `get_settings()` infinite recursion** causing 502 on admin page (PR #4835).
- **Graphify Sources (CPT / CCT) tab missing** from Knowledge Graph settings page (PR #4836).
- **Graphify admin settings file PHP linting CI failure** (PR #4838).
- **Multi-agent dashboard TypeError** when `primary_roles` post meta is unset (PR #4823).
- **Credential nonce mismatches** — nonce field names corrected for Generate Credential (#4824), Revoke (#4825), and Delete (#4825) buttons.
- **JetEngine CCT table prefix** — corrected to `jet_cct_` (underscores) in the transcript repository, all direct SQL paths, and the `get_table_name()` method. Chat channel SQL queries now backtick-quote hyphenated table names to satisfy MySQL strict mode (PRs #4827, #4828, #4830).
- **Site-health polyfill** — `wp_is_auto_update_forced_for_item` polyfilled before the early-return guard in the site-health integration to prevent fatal errors on sites where the WP core function is not yet defined (PR #4832).
- **README TOC anchor links** corrected (`#--` → `#-`) (PR #4807).

#### Versioning

Bumped to 1.1.15 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at **~195 base / ~635 Pro / ~830 total** — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

## [1.1.14] - 2026-05-02

### May 1–2, 2026 — Agent Skills v2 (progressive disclosure + skill packs + remote catalogues), Markup Subsystem (Base), MemPalace Capture Framework Phases A + B1, Graphify CPT/CCT integration suite, follow-up fixes

A capabilities + stability + documentation pass on top of 1.1.13. Headline additions: (1) **Agent Skills Phases 1–4** — bundled WP-developer skills, a `load_skill` progressive-disclosure tool, curated skill packs, and remote skill catalogues (Pro); (2) the new in-the-loop **Markup Subsystem** that lets tools pause the agentic loop, render a Konva canvas in the chat UI for the user to draw on, and resume the same tool call with the rasterised mask / crop / region polygon; (3) **MemPalace Capture Framework Phases A + B1** — five high-leverage capture tools layered onto the Phase 4a/4b durable agent-memory bridge shipped in 1.1.13; (4) the **Graphify CPT/CCT integration suite** — JetEngine custom post types and Custom Content Types are now first-class citizens in the knowledge graph, the Graph Explorer type filter, the related-content widget, and the embeddings re-index path; plus follow-up fixes to the orchestration dashboard (JetEngine availability stale-cache), the Pro Mini App Builder enqueue path, the new skill-catalogue cURL fetcher, the Pro Medical Imaging Viewer bundle, the stored-embeddings admin display, and the agent-context layering (`.github/agents/` + `examples/agents/`).

### Added — Markup Subsystem (Base, 1.3.0)

A new in-the-loop image / document markup system that lets tools pause the agentic loop, surface a canvas widget in the chat UI for the user to draw on, and resume the same tool call with the rasterised mask / crop / region polygon.

- **Loop integration**: `WP_MCP_AI_Markup_Loop_Interceptor` short-circuits any tool that implements `WP_MCP_AI_Markup_Aware_Tool_Interface` and emits a `markup_elicitation` SSE frame instead of the tool result. The chat client persists a `request_id` and resumes the call once the user submits the markup. Master toggle: `wp_mcp_ai_markup_enabled` filter.
- **Chat canvas widget** auto-enqueued whenever the main chat bundle is on the page — supports `mask`, `crop`, and `region` modes against image targets.
- **Markup-aware tools**: `edit_openai_image` (`mask`), `crop_image` (`crop`), `edit_gemini_image` (`region`).
- **REST controller** (`/wp-json/mcp-ai/v1/markup/{request_id}`) accepts a W3C Web Annotation envelope, runs `WP_MCP_AI_Markup_Validator` + `WP_MCP_AI_Markup_Rasterizer`, and re-invokes the source tool with the resulting artifacts in the execution context.
- **Settings UI** toggle under **NV oOS → Settings → General**.
- **Telemetry**: bounded option `wp_mcp_ai_markup_telemetry` aggregates per-tool / per-mode counters and last-seen timestamps for seven outcome buckets.
- **Slash command** `/markup-stats` (alias `/markup`) renders the summary as Markdown with `--verbose`, `--json`, and `--reset` flags. Read access requires `edit_posts`; reset requires `manage_options`.
- **Admin dashboard** under **NV oOS → Markup Telemetry** renders the same summary as a server-rendered HTML table with a colour-coded completion-rate card, per-tool / per-mode breakdowns, relative `last_seen` timestamps, and a nonce-protected `Reset counters` form.
- **Hooks** (4 actions, 4 filters): `wp_mcp_ai_markup_request_created`, `wp_mcp_ai_markup_submitted`, `wp_mcp_ai_markup_validated`, `wp_mcp_ai_markup_resolved`, `wp_mcp_ai_markup_enabled`, `wp_mcp_ai_markup_widget_payload`, `wp_mcp_ai_markup_mcp_elicitation`, `wp_mcp_ai_markup_rasterized_artifacts`. Documented in `docs/reference/hooks/hooks-reference.md`.
- **Daily cleanup** cron (`wp_mcp_ai_markup_cleanup`) prunes expired markup transients and orphan mask attachments.
- **Docs**: `docs/features/markup-subsystem.md` walks through the end-to-end flow, REST contract, validator rules, rasteriser output shape, and observability surfaces.

### Added — QMS + PARA Methodology Integration (Pro)

Two opt-in subsystems layered onto existing Pro toolkits, both gated by feature flags so behavior is unchanged when off.

- **QMS — ISO 9001:2015 Clause 7.5 (Document Generation Toolkit)**
  - New `mcp_ai_doc_record` CPT for controlled-document instances with full per-record metadata (document_id, revision, owner/reviewer/approver IDs, effective/next-review dates, retention years, disposition, external origin, change reason/summary, signatures, content hash, supersede pointers).
  - New `mcp_ai_qms_doc_type` taxonomy seeded with Policy / Procedure / Work Instruction / Form / Record / External (clause 7.5.3 b).
  - `WP_MCP_AI_QMS_Workflow` state machine: `draft → in_review → approved → released → superseded/obsolete` with pre-condition gates.
  - `WP_MCP_AI_QMS_Audit_Log` immutable audit table (`{prefix}wp_mcp_ai_qms_audit`) with prepared queries, IP/UA capture, before/after content hashes, and a shared `subsystem` column (qms/para).
  - 21 CFR Part 11-friendly e-signatures: WP password re-prompt + SHA-256 hash binding signature → document content.
  - Daily retention cron auto-marks obsolete records once `effective_date + retention_years` has elapsed.
  - Daily review-due cron fires `wp_mcp_ai_qms_review_due_for_record` for owners.
  - New `manage_qms` capability (Editor/Admin by default; filterable).
  - 10 new Pro tools: `qms_create_controlled_document`, `qms_submit_for_review`, `qms_approve_document`, `qms_release_document`, `qms_supersede_document`, `qms_mark_obsolete`, `qms_sign_document`, `qms_list_controlled_documents`, `qms_get_audit_trail`, `qms_schedule_review`.
  - Hooks: `wp_mcp_ai_qms_before/after_state_transition`, `wp_mcp_ai_qms_document_signed`, `wp_mcp_ai_qms_audit_logged`. Filters: `wp_mcp_ai_qms_capability_roles`, `wp_mcp_ai_qms_require_release_signature`, `wp_mcp_ai_qms_grant_to_admins`.

- **PARA — Tiago Forte's Projects/Areas/Resources/Archives (Project Management Toolkit)**
  - New `mcp_ai_para` hierarchical taxonomy with four locked roots: `projects`, `areas`, `resources`, `archives` (cannot be deleted/renamed). Sub-buckets allowed.
  - New `mcp_ai_area` CPT for ongoing responsibilities, with `_para_standard`, `_para_owner`, `_para_review_cadence`, `_para_last_reviewed` meta.
  - `WP_MCP_AI_PARA_Lifecycle` daily sweep: auto-archives completed/cancelled projects, surfaces dormant Areas, dormant Resources, and archive candidates.
  - PARA admin column with color-coded badges on all PARA-classified post-type list tables.
  - Single-select PARA metabox on every supported CPT.
  - 7 new Pro tools: `para_classify_item`, `para_move_to_archives`, `para_create_area`, `para_update_area`, `para_list_areas`, `para_weekly_review`, `para_promote_resource_to_project`.
  - Hooks: `wp_mcp_ai_para_item_classified`, `wp_mcp_ai_para_archived`, `wp_mcp_ai_para_unarchived`, `wp_mcp_ai_para_sweep_complete`. Filters: `wp_mcp_ai_para_object_types`, `wp_mcp_ai_para_dormancy_days`, `wp_mcp_ai_para_resource_dormancy_days`.

- **Cross-toolkit bridge**
  - QMS-obsolete documents auto-move to PARA archives (when both subsystems enabled).
  - Released QMS documents linked to a PARA Area refresh that Area's `_para_last_reviewed` timestamp.
  - Both subsystems write to the same audit table (`subsystem` column).

- **Documentation**: `docs/features/qms-compliance.md`, `docs/project/para-methodology.md`.

- **Tests**: `tests/qms/test-qms-workflow.php`, `tests/qms/test-qms-audit-log.php`, `tests/para/test-para-taxonomy.php`, `tests/para/test-para-lifecycle.php`.

Feature flags (both default off; opt-in): `enable_qms_compliance`, `enable_para_organization`. All new behavior is additive — existing tools and toolkits continue to work unchanged when the flags are off.

### Added — Agent Skills Phases 1–4 (PR #4771)

The Agent Skills surface (per the [agentskills.io](https://agentskills.io/specification) specification) is now end-to-end across base + Pro:

- **Phase 1 — Bundled WP-developer skills + companion-file install**: 28+ new `SKILL.md` files curated from the MIT-licensed [`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills) catalogue, covering WooCommerce (HPOS, payment gateways, REST API v4, shipping, Stripe, variations, customer & sessions, classic emails, coupons, product search/select), WooCommerce Memberships (access discounts, subscriptions linkage, hooks), WooCommerce Subscriptions (renewal scheduler, switching/gifting data model, hooks), JetEngine (dynamic visibility, listings callbacks, query builder custom types), JetFormBuilder (action events, external API, item decorator, messages, form actions, sidebar panels, settings tabs), and WP Rocket (cache invalidation, rejection filters). Base plugin gains a `wp-abilities-api` skill. New `THIRD_PARTY_NOTICES.md` files in both `includes/bundled-skills/` and `addons/pro/includes/bundled-skills/` carry attribution and license text.
- **Phase 2 — Remote skill catalogues (Pro)**: new `WP_MCP_AI_Skill_Catalogue_Service` (`addons/pro/includes/services/class-wp-mcp-ai-skill-catalogue-service.php`) discovers `SKILL.md` files in registered public Git repositories using the GitHub trees API, supports `catalogue.json` manifests when present, caches manifests in 24-hour transients, and refreshes them daily via the `wp_mcp_ai_skill_catalogue_refresh` WP-Cron job. Pre-seeded with `Lonsdale201/wp-agent-skills` and `anthropics/skills`. New `WP_MCP_AI_Skill_Catalogue_REST_Controller` exposes admin-only endpoints under the `mcp-ai-pro/v1` namespace (`/catalogues`, `/catalogues/{id}/skills`, `/catalogues/{id}/install`, `/catalogues/{id}/refresh`). All catalogue fetches reuse the SSRF-safe HTTPS-only helper that protects `/skills/install-url`, the existing extension allowlist, and the decompression-bomb cap.
- **Phase 3 — Progressive disclosure (`load_skill` tool)**: each assistant gains a "Use progressive disclosure" checkbox on its Skills metabox. When enabled, the system prompt receives only a short `# Available Skills` catalogue (skill name + description) and the model calls the new base-plugin `load_skill({ name })` tool when it decides a skill applies — at which point the full SKILL.md is returned in the tool result. This dramatically reduces baseline context cost for skill-heavy assistants.
- **Phase 4 — Skill packs**: curated, named collections of related skills addressable as a single unit ("WordPress Developer", "Document Authoring", etc.). Skill manager admin UI gains tabs for browsing catalogues, managing packs, and editing individual skills.
- **Filters & hooks**: `wp_mcp_ai_skill_catalogue_manifest_ttl` (transient TTL), `wp_mcp_ai_skill_catalogue_refresh_cadence` (cron schedule).
- **Documentation**: `docs/features/agent-skills.md` updated end-to-end with the Phases 1–4 narrative.

### Added — MemPalace Capture Framework Phases A + B1

A foundation pass plus the five highest-leverage capture tools layered onto the Phase 4a/4b durable agent-memory bridge shipped in 1.1.13.

- **Phase A — foundation** (`#129d2610`, follow-up `#bb386022`): the MemPalace-inspired Capture Framework scaffolding — base capture interface, lifecycle hooks, and shared time-source / tier-logging utilities — with review fixes for time consistency and tier-logging payload shape.
- **Phase B1 — five highest-leverage capture tools** (`#1760c658`): the first batch of capture tools that write into the durable `ai_agent_memories` CCT through the Phase 4a/4b bridge.
- Cross-link: see `docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md` for the unified MemPalace/Letta/Zep/mem0/Cognee schema rationale that the capture tools target.

### Added — Graphify CPT/CCT integration suite

JetEngine custom post types and Custom Content Types are now first-class citizens across every Graphify surface.

- **Knowledge graph builds (#4779)** — the `Detector::detect_ccts()` pass enumerates each CCT type via `$type->db->query()`, the structural extractor emits `cct_{slug}` nodes with `AUTHORED_BY` edges, and the semantic extractor processes them through a dedicated cache prefix (`nvoos_graphify_semc_`) and cron action (`nvoos_graphify_cron_semantic_extract_ccts`).
- **Graph Explorer type filter (`#6f9aab91`)** — JetEngine CPTs and CCTs now appear in the Graph Explorer's type filter alongside core post types.
- **Related-content widget + recommendations expanded to all CPTs** (audit follow-up `#6cac03a6`) — the widget and recommendations engine no longer hard-code core `post`/`page`.
- **Semantic extractor extended to JetEngine CCT items (#4781)** — CCT records are now embedded and indexed end-to-end alongside CPT posts.
- **Re-index All Nodes button wired up on embeddings tab (`#3253cddd`)** — the previously inert button now triggers a full re-index of every Graphify node.
- **Per-source detection counts + CCT skip reason (`#6e16d3c3`)** — the embeddings/diagnostics screens surface per-source detection counts and explain why a CCT was skipped (e.g. no label field, no item rows).
- **Settings persistence on tabbed admin page (#4784)** — Graphify settings now persist correctly across tab switches under the new tabbed admin UI.
- **JS escaping fix (#4780)** — admin-side string escaping refactor: extracted constant, hoisted filter, added a label-field filter for downstream customisation.
- **`ucwords` for multi-word post-type slug fallback (`#471ef04b`)** — multi-word slugs (e.g. `case_study`) now produce a properly capitalised label fallback instead of `Case_study`.

### Added — Agent context system (`.github/agents/` + `examples/agents/`)

- **`.github/agents/` layered into the agent-context system (`#28c49b23`)** — GitHub Custom Agent files are now auto-discovered by GitHub's runtime and follow the new layering rule documented in [`AGENTS.md` §2](AGENTS.md): each `*.agent.md` carries only role-specific metadata + behavior and links to canonical sources (`AGENTS.md`, `CLAUDE.md`, `.context/`) for shared rules.
- **Slim `*.agent.md` template + worked examples (`#f0c0fe11`)** — canonical empty template at `.context/templates/agent-file-template.md` and copy-ready examples under `examples/agents/`.
- **10-agent roster covering the full NV oOS surface (`#e459c18c`)** — see [`examples/agents/README.md`](examples/agents/README.md) for the complete table covering REST reviewers, security reviewers, WordPress.org compliance auditors, PHP-compat reviewers, tool authors, slash-command authors, chat-UI authors, PHPUnit test authors, agent-skill curators, addon maintainers, release engineers, and docs maintainers.

### Fixed

- **Markup subsystem — server-side ownership check on admin fallback page (`#cd051ff6`)** — the admin fallback page that hosts the markup canvas now performs a server-side ownership check before rendering, so a markup `request_id` issued for one user can no longer be opened by another.
- **Markup subsystem — null-safe hex color, best-effort hardening file writes (`#9678fb13`)** — review-pass hardening on the rasteriser path.
- **Graphify Memory Bridge — stale "not installed" status (#4769)** — the orchestration dashboard's Phase 4a memory-bridge widget could report "not installed" even after the bridge had been activated, due to a stale-cache `bridge_active` recomputation path. Cache invalidation now runs on bridge activation/deactivation and the widget re-reads the live status. Regression covered by `tests/test-orchestration-dashboard-stale-cache.php`.
- **Orchestration dashboard — recompute JetEngine availability on cache hit (`#dabb3746`)** — `get_agent_memory_stats()` caches results for 5 min; both `bridge_active` (Graphify) and `persistent_storage.available` (JetEngine CCT) are now re-checked on cache hit so the dashboard no longer reports "not installed" for up to 5 minutes after the underlying plugin is activated.
- **Pro Mini App Builder — TMA bundle enqueue when `asset.php` is missing (`#53c64d49`)** — the Telegram Mini App bundle now enqueues correctly even when the build pipeline does not emit a sibling `asset.php` manifest, so the builder loads on a clean install.
- **cURL SSL error 60 fetching remote skill catalogues (#4772)** — the new catalogue-fetcher (Phase 2) could fail with `cURL error 60: SSL certificate problem` on hosts with outdated CA bundles when reaching `api.github.com` and `raw.githubusercontent.com`. The HTTP layer now uses WordPress's `wp_remote_get()` certificate bundle path consistently and surfaces a structured `WP_Error` instead of a fatal request failure when verification still fails.
- **"Dynamic require of dicom-parser" in Medical Imaging Viewer (#4773)** — the Pro Medical Imaging Viewer bundle could fail at runtime with `Dynamic require of "dicom-parser" is not supported` when loaded from the Pro `build/` directory. The viewer now imports `dicom-parser` statically so the esbuild output no longer relies on a runtime CommonJS shim.
- **Stored embeddings display fix (#4787)** — the stored-embeddings admin display rendered incorrectly under certain dataset shapes; it now degrades gracefully and surfaces accurate counts.

### Build

- **All distribution ZIPs rebuilt at v1.1.13 (#4775, #4782)** — `bin/rebuild-all-zips.sh` regenerated the four original (`mcp-ai-wpoos-base|pro|combined|core`) and four WordPress.org (`nvdigital-open-operator-system-oos-*`) packages plus the standalone toolkit add-on ZIPs (19 toolkit ZIPs in the v1.1.13 rebuild pass).
- **Production autoloader reaffirmed (#4774)** — `vendor/composer/installed.json` and the autoload classmap regenerated with `composer install --no-dev --classmap-authoritative` to confirm the production posture established in 1.1.13.

### Documentation

- `README.md`, `MAINTAINER_MAP.md`, `AGENTS.md`, `CLAUDE.md`, and `.github/copilot-instructions.md` refreshed with v1.1.14 narrative and reconciled tool counts (~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative).
- `docs/features/markup-subsystem.md` walks through the end-to-end markup flow, REST contract, validator rules, rasteriser output shape, and observability surfaces.
- `docs/reference/hooks/hooks-reference.md` extended with the 4 markup actions + 4 markup filters.
- `docs/features/agent-skills.md` updated end-to-end with the Phases 1–4 narrative.

### Versioning

- Bumped to 1.1.14 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

## [1.1.13] - 2026-05-01

### April 30 – May 1, 2026 — gpt-image-2 (Images 2.0), Phase 4a MemPalace-backed memory bridge, AI Harmonization toolkit, production-only Composer autoloader

A documentation, distribution, and capabilities pass on top of 1.1.12. The release covers (1) first-class support for OpenAI's new **`gpt-image-2`** ("Images 2.0") image model, (2) the Phase 4a/4b durable-storage bridge for the MemPalace-inspired agent memory subsystem, (3) the new **AI Harmonization** sub-toolkit (14 tools), and (4) hardening of the repo so it can be cloned and deployed as a production WordPress plugin without a separate `dump-autoload` step.

### Added — OpenAI Images 2.0 (`gpt-image-2`) support

OpenAI released **gpt-image-2** ("ChatGPT Images 2.0") in April 2026 with native 2K resolution, multi-image coherency, near-flawless multilingual text rendering, and broader aspect-ratio support. The plugin now supports it as a first-class image model and uses it as the default.

- New default image model is `gpt-image-2` across the base plugin and Pro image tools (was `gpt-image-1.5`). Existing sites with a saved `openai_image_model` setting are unaffected — the change only impacts fresh installs and tools that pinned a hardcoded `gpt-image-1` default.
- `WP_MCP_AI_OpenAI_Client::image_model_supports_response_format()` and the internal quality-normalization logic now recognise `gpt-image-2` as part of the `gpt-image` family (uses `low|medium|high|auto`, does not accept the `response_format` parameter).
- `WP_MCP_AI_Tool_Generate_OpenAI_Image` allows three new 2K aspect-ratio sizes for `gpt-image-2`: `2048x2048` (square 2K), `2048x1152` (16:9 widescreen), `1152x2048` (9:16 vertical). Cost and token-estimation tables updated accordingly.
- Admin settings (Providers section + standalone image settings page) expose `gpt-image-2` in the model dropdown labelled "Images 2.0 (Recommended)" and the new 2K size options.
- Pro tools updated: `generate_architectural_drawing`, `product_actualization`, harmonization base, and `generate_scene_background` now default to `gpt-image-2`.
- Filterable extension points unchanged: `wp_mcp_ai_openai_image_models`, `wp_mcp_ai_openai_image_sizes`, `wp_mcp_ai_image_model_supports_response_format`, `wp_mcp_ai_image_model_supports_style`.
- New PHPUnit coverage in `tests/test-openai-image-tool.php` verifies `gpt-image-2` is the new default, is treated as a `gpt-image` family member for quality remapping (e.g. `hd` → `high`), and that it never receives a `response_format` parameter on the wire.

### Added — Phase 4a/4b — durable agent-memory bridge (MemPalace-inspired)

The agent-memory subsystem was the only persistent surface in the plugin still backed solely by transients (cache-evictable). With JetEngine active, every transient memory write is now mirrored into a durable `ai_agent_memories` Custom Content Type with an industry-standard schema. Transients remain the primary fast read path; the CCT is the durable backing store.

- `WP_MCP_AI_JetEngine_Agent_Memories_CCT` (slug `ai_agent_memories`) registered automatically when the JetEngine CCT module is active. Wired into `includes/bootstrap/loader.php` for both base+JE and full-integration paths.
- Schema is aligned with industry-standard agent-memory architectures: **Letta / MemGPT** (memory tier, verbatim immutability flag, expires_at TTL anchor), **Zep** (bi-temporal validity, source provenance), **mem0** (importance, verbatim discipline, source tracking), **Cognee** + **MemPalace** (hierarchical scope via wing/room, verbatim-storage discipline). Vector and graph references (`embedding_id`, `graph_node_id`) are nullable forward-compatibility hooks.
- Dual-write hook on `wp_mcp_ai_memory_stored`; new `wp_mcp_ai_memory_deleted` action fired from `manage_context_lifecycle` delete path with subscriber-driven CCT cleanup.
- `get_agent_memory_stats()` now returns `persistent_storage` (CCT count + per-tier breakdown) when JetEngine is active. New "Persistent (CCT) / Cache only" stat card on the agent-memory dashboard.
- Tests: `tests/test-jetengine-agent-memories-cct.php` (slug/schema/REST args/required fields/field-id ranges) and `tests/test-agent-memory-cct-bridge.php` (tier classifier, record build, filter mutation, delete-event payload). 13 new tests pass; 24 existing regression tests still pass.
- Source files inspired by [MemPalace](https://github.com/MemPalace/mempalace) now cite the upstream URL in their file headers: `class-wp-mcp-ai-tool-store-agent-context.php`, `class-wp-mcp-ai-tool-wake-up-context.php`, `class-wp-mcp-ai-tool-mine-agent-memory.php`, `class-wp-mcp-ai-jetengine-agent-memories-cct.php`, `interface-wp-mcp-ai-embedding-provider.php`, `class-wp-mcp-ai-embedding-provider-openai.php`, `class-wp-mcp-ai-embedding-provider-ollama.php`, `class-wp-mcp-ai-vector-context-service.php`. Documentation in `docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md` already linked the upstream project; the source citations now match the documentation.
- Deferred to follow-up PRs (per the published plan): **4b-3** memory-tier parameter on `store_agent_context` / `wake_up_context` / `retrieve_agent_memory` (separate review window) and **4b-5** promote-on-pressure hygiene service.

### Added — AI Harmonization sub-toolkit (Pro)

A 14-tool sub-toolkit for cross-model output reconciliation. Tools land under the existing Pro orchestration toolkit with their own registry section and presets in the admin documentation page. Cross-references to the Architectural Design and other Pro toolkits are now in place via the doc-refresh pass.

### Changed — Production-only Composer autoloader

The repo can now be cloned and used as a production WordPress plugin without an extra build step.

- `composer install --no-dev --classmap-authoritative` (no separate `dump-autoload` invocation needed) is the canonical command. The autoloader is regenerated as part of `install`.
- `vendor/composer/installed.json` now reports `"dev": false` with an empty `dev-package-names` array — no dev references survive in the production tree.
- `vendor/composer/autoload_real.php` calls `setClassMapAuthoritative(true)`, so PSR-4 filesystem fallback lookups are skipped at runtime.
- Net classmap diff: −6,761 / +279 lines as `phpunit/`, `phpcs/`, `wp-phpunit/`, and other dev-only packages drop out of `vendor/`.

### Documentation

- README.md and readme.txt updated with a v1.1.13 section summarising the changes above. README's older "GPT-Image-1" mentions now note that `gpt-image-2` is the new default.
- File-header citations to the upstream MemPalace project added across the agent-memory subsystem so source attribution matches the documentation.

### Versioning

- Bumped to 1.1.13 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

### Deferred from the original `[Unreleased]` block

The QMS + PARA Methodology Integration items (Pro) remain feature-flagged and unreleased pending a separate review window. They are not part of 1.1.13 and stay opt-in / off by default. The corresponding bullets remain at the top of this changelog under `[Unreleased]`.


## [1.1.12] - 2026-04-29

### April 27–29, 2026 — Architectural Design Toolkit (Phases A–E), Graphify Federation/RAG, Tier 4 Browser-AI Runtime, Production Cleanup, Security Patches

A wide-reaching feature and hardening release. The headline is the new five-phase **Architectural Design Toolkit** (Phases A–E shipped end-to-end across April 28–29) plus a major **Graphify** push delivering remote sources, federation, and a vector/RAG layer. Two security patches resolve disclosed advisories in third-party dependencies, and a new admin **Production Cleanup** workflow plus **AV-clean deploy tooling** finalise the WordPress.org submission posture started in 1.1.11.

### Added

- **Architectural Design Toolkit (Phases A–E)** — refactored foundations (Phase A) and four cumulative content phases:
  - *Phase B* — regional-compliance + analysis tools, with a dedicated PHPUnit suite and regional fixtures.
  - *Phase C* — EDGE/LEED scoring, bill-of-quantities (BoQ), and value-engineering (VE) options.
  - *Phase D* — IFC and gbXML interop, BIM Execution Plan (BEP), and RFI / submittal logs.
  - *Phase E* — precedent library, semantic search across precedents, and curated regional examples.
- **Production Cleanup admin workflow** — new buttons under **Settings → Advanced → Data Management** clear test/runtime artefacts safely; code-review feedback on cleanup handlers has been incorporated.
- **`plan_schedules_from_workflow` tool + Research & Add Schedule page** — adds a base-plugin path from arbitrary workflow descriptions to staffing/schedule plans, with a new admin landing page.
- **Graphify — Phases 1–5 (federation, remote sources, vector/RAG, mapping UI)**:
  - Phase 1: connector foundations + Woo / CSV / Webhook drivers.
  - Phase 3: SaaS connectors — HubSpot, GitHub, Slack, Google Drive, Jira, Zendesk, M365 / SharePoint, ServiceNow (Pro).
  - Phase 4: Generic GraphQL, Generic SQL (read-only), and S3 (and S3-compatible) remote drivers (Pro).
  - Phase 5: schema.org auto-typing helper, embeddings-on-ingest helper, field-mapping admin UI with validator + live AJAX feedback.
  - Cross-cutting: remote sources, federation, vector embeddings, and RAG retrieval.
  - Algorave: safe guest access for the live coder shortcode.
- **Tier 4 browser-AI runtime packages** — three new NPM packages (`llm-worker`, `model-loader`, `transformers-client`) for in-browser AI; `nvoos-transformers-client` now guards against undefined `transformersUrl` in dynamic imports.
- **`WARRANTY.md`** — formal warranty, liability, and safe-use notice; `README.md` and `SECURITY.md` updated to reference it.

### Changed

- **TCPDF extracted into `oos-toolkit-tcpdf` addon** — removed from the combined ZIP with classmap cleanup; vendor-only supplement toolkits now require **PHP 8.1+**.
- **AV-clean deploy pipeline** — new `bin/strip-dev-files.sh` plus expanded `.gitattributes` `export-ignore` rules so deploy archives no longer trip antivirus scanners on test fixtures. Test files containing AV-triggering payload literals were obfuscated to keep the regression suite scannable.
- **Composer / vendor** — production autoloaders regenerated with `--no-dev --classmap-authoritative`; Pro vendor rebuilt for `phpoffice/phpspreadsheet` 5.7.0 + `symfony/polyfill-mbstring` v1.37.0.

### Fixed

- **Graphify** — `do_settings_sections_filtered()` calls now correctly prefixed with `self::` (PR for v1.1.11 follow-up).

### Security

- **`phpoffice/phpspreadsheet` bumped to ^5.7.0** to patch HTML Writer XSS (advisory `GHSA-3xx9-fc24-w62g` family).
- **`uuid` overridden to `>=14.0.0`** to fix `GHSA-w5hq-g745-h8pq`.

### Documentation

- `README.md` Latest Updates banner refreshed for v1.1.12; previous v1.1.10 / v1.1.11 banners demoted to "Previous Updates".
- `WARRANTY.md` referenced from the README and `SECURITY.md` Safe Use sections.

### Version

- **Version** bumped to 1.1.12 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.


## [1.1.11] - 2026-04-27

### April 27, 2026 — WordPress.org Compliance Hardening

A small but tightly-scoped compliance pass to clear the remaining WordPress.org Plugin Check items in the readme/build pipeline before re-submission. No runtime behavior changes.

### Fixed
- **Dead support-forum URL** — `readme.txt` now points to `https://wordpress.org/support/plugin/nvdigital-open-operator-system-oos/` (the canonical WordPress.org slug) instead of the obsolete `wp-mcp-ai` URL.
- **Inconsistent tool count in readme.txt** — Headline description and Base Plugin section both report `230+ tools`, matching the Tool Registry screenshot caption and the audited `tool-reference.md` figure.
- **Missing `mcp` tag** — `Tags:` line now includes `mcp` alongside `ai assistant`, `openai`, `chatbot`, `automation` (5 tags maximum, per WordPress.org guidelines). MCP protocol support was previously undiscoverable in WordPress.org plugin search.

### Changed
- **`bin/build-wordpress-org-from-base.sh`** — New per-package `Step 2b` rewrites the WordPress.org support-forum URL to match each transformed slug (`nvdigital-open-operator-system-oos`, `…-pro`, `…-core`). The build now also runs a verification grep at the end of `transform_package()` and exits non-zero if any legacy `wp-mcp-ai` slug or unrewritten `Text Domain: mcp-ai-wpoos` header survives in `readme.txt`. Prevents silent metadata regressions in future releases.
- **`bin/review-zips.sh`** — New `check_wporg_readme_slug()` helper asserts the same readme invariants when auditing already-built `.zip` packages, so a stale build can no longer pass review even if the build script is bypassed.

### Documentation
- **`docs/operations/compliance/WORDPRESS_ORG_PLUGIN_CHECK_REPORT.md`** and **`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`** updated with the 1.1.11 status. Both note that source-level identifier prefix migration (`wp_mcp_ai_*` / `WP_MCP_AI_*` → slug-derived prefix, ~14k identifiers across base + Pro) remains scheduled for v2.0 with a coordinated options/postmeta/cron migration; it is not a WordPress.org submission blocker.

### Version
- **Version** bumped to 1.1.11 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.


## [1.1.10] - 2026-04-27

### April 27, 2026 — April 2026 Security Audit Summary, Production-Ready Vendor Autoload, Veo 3.1 Seed-Parameter Fix

Documentation, distribution, and one targeted bug fix release. No source-code changes to plugin runtime behavior beyond the Veo 3.1 fix.

### Documentation
- **New `docs/operations/compliance/SECURITY_AUDIT_2026_04.md`** — Published summary of the April 2026 security & compliance code review covering the base plugin, the Pro addon, and the six minor addons (`algorave`, `canvas`, `cornerstone3d`, `embedded`, `fantasy-football`, `graphify`). Cross-references the nine deliverables under `docs/project/audits/2026-04/`. Headline verdict: **no Critical findings**; 5 High (3 Fixed, 2 Partially Fixed); 14 Medium (all Fixed); 21 Low (14 closed); 10 Informational; 50 total. Standards applied: WP Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 (2021), OWASP API Security Top 10 (2023), WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA, MCP/SSE conformance.
- `docs/DOCUMENTATION_INDEX.md`, `docs/operations/compliance/README.md`, `docs/QUICK_REFERENCE.md`, and `docs/project/audits/2026-04/README.md` cross-reference the new audit summary.
- `README.md` Latest Updates banner refreshed for v1.1.10; the **Active Security Monitoring** section now links to the published audit summary.

### Changed
- **Production-ready vendor autoload (PR #4733)** — `vendor/` regenerated with `composer install --no-dev --classmap-authoritative` (677 production classes). The plugin is now deployable from a clean clone without a separate `composer install` step. Local development still requires `composer install` to pull dev dependencies (PHPUnit, WPCS, etc.).

### Fixed
- **Veo 3.1 `generate_veo_video` `INVALID_ARGUMENT` when `seed` is supplied (PR #4735)** — the `seed` parameter is now sent only to Veo 2.0 (`veo-2.0-generate-001`). Veo 3.1 (`veo-3.1-generate-preview`) rejects the parameter and the tool now silently drops it on that model.

### Version
- **Version** bumped to 1.1.10 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.


## [1.1.9] - 2026-04-25

### April 16–25, 2026 — Measurement Subsystem GA, PHPUnit 11 Upgrade (CVE Fix), Chart.js Handle Normalization, Graphify Addon Restore

Major delivery of the end-to-end **Measurement subsystem** (12 sequenced PRs) plus a security-driven **PHPUnit 11** upgrade, Chart.js enqueue normalization across admin dashboards, restoration of the Graphify Knowledge Graph addon, and a comprehensive new Orchestration Reference document.

### Added
- **Measurement subsystem GA (PRs 1–12, April 24–25, 2026)** — Complete rollout of the measurement / evals / reward stack:
  - **PR 1–4 — Core** — `WP_MCP_AI_Measurement_Registry`, `WP_MCP_AI_Metric_Collector` (in-memory ring buffer, `wp_mcp_ai_metric_recorded`, `wp_mcp_ai_measurement_export`), verifier contract + base class + registry with independence enforcement, reward-function registry with anti-gaming safeguards, and reference verifiers (rule / schema / LLM-judge) + reference rewards.
  - **PR 5 — Eval harness** — case / suite / runner primitives, read-only Measurement dashboard under **Tools → Measurement**, budget envelopes, OTel JSON exporter, writable dashboard actions.
  - **PR 6 — Pro rewards** — Pro rubric verifier, budget-guarded reward, Pro measurement bootstrap.
  - **PR 7 / 7.1 — Chat & agentic loop** — Chat-loop token / cost instrumentation and agentic-loop iteration observer + emitter (`wp_mcp_ai_agentic_iteration` hook family).
  - **PR 8 — SSE / stream** — SSE/stream instrumentation with per-stream duration, TTFB, and cancellation counters.
  - **PR 9 / 9.1 — Persistent store** — `{prefix}mcp_ai_metric_events` table, per-request persister, retention cron, `wp_mcp_ai_metric_retention_days` filter (default 30 days), persisted-metric dashboard panel with time-range selector and sparkline; table dropped on uninstall when *Delete data on uninstall* is enabled.
  - **PR 10 — Rubric presets + counterfactual runner** — Pro presets (`prompt_adherence`, `json_schema`, `citation_presence`); `WP_MCP_AI_Eval_Runner::run_counterfactual()` flags measurement invalidity when the verifier fails to prefer the candidate over a degraded variant; eval-runner counterfactual mode; chat-turn observer & runner flake fixes.
  - **PR 11 — WP-CLI runner + regression alerting** — `wp mcp-ai measurement run <suite>`, `wp mcp-ai measurement alert-check <suite> [--window=N] [--webhook=<url>]` (exits 2 on regression, webhook failures never mask the exit code), `wp mcp-ai measurement list-runs <suite>` (`table|json|yaml|csv`); new stock metrics `eval.suite.pass_rate` (gauge) and `eval.suite.regression.count` (counter).
  - **PR 12 — GA polish** — Contextual help tabs on the dashboard (extensible via `wp_mcp_ai_measurement_help_tabs`), uninstall cleanup, reference snippets under `assets/examples/measurement/` (custom rule verifier, eval-suite registration, CLI generator), release notes.
  - Every signal carries a privacy tier, a direction (`higher_is_better` / `lower_is_better` / `neutral`), and a paired counter-metric so dashboards cannot Goodhart a single dimension.
  - New stock metrics include tool-execution, chat-loop, agentic-loop, and SSE/stream families, all emitted through a single `wp_mcp_ai_register_metrics` registry.
- **Graphify addon v0.5.0** — NV oOS Graphify (WordPress Knowledge Graph) restored under `addons/graphify/` after a short revert cycle.
- **`docs/reference/orchestration/ORCHESTRATION_REFERENCE.md`** (April 16, 2026) — New single authoritative reference for the orchestration layer: all 10 workflow presets, all 13 resource presets with full settings-comparison matrices, PSO algorithm documentation, tool-execution orchestrator, load balancer, reasoning controller, multi-agent system, health monitoring, budget enforcement, hooks/filters, data-storage keys, admin UI, and service-file index.
- **`docs/reference/measurement/`** — Full subsystem docs: `README.md`, `rollout-plan.md`, `goodhart-checklist.md`, `verifier-authoring.md`, `reward-authoring.md`, `eval-harness.md`, `persistent-store.md`, `dashboard.md`, `sse-stream.md`, `tool-execution.md`, `chat-turn.md`, `budgets.md`, `otel-exporter.md`, `privacy-matrix.md`, `conventions.md`.

### Security
- **PHPUnit upgraded to 11.x** with WordPress-compatibility patches (American-English `normalized` naming patch, wp-phpunit-phpunit10 compat) to resolve the argument-injection vulnerability **GHSA-qrr6-mg7r-m243**. A short PHPUnit 12 attempt was reverted because of wp-phpunit incompatibility; the final landing is on PHPUnit 11.
- **CI PHP minimum bumped 8.1 → 8.2** — PHPUnit 11 requires PHP ≥ 8.2.
- `patches.lock.json` regenerated to include the phpunit and wp-phpunit-phpunit10 patches.

### Changed
- **Chart.js enqueue handle normalized** to `wp-mcp-ai-chartjs` across ECA dashboard, Schedule Manager, Agent Command Center, and the new Measurement dashboard. Prevents duplicate registrations, version drift, and double-load warnings.
- **Test suite WPCS cleanup** — 83 lint errors resolved in `tests/test-provider-client-adapters.php`.
- **`@dataProvider` docblocks** removed where the PHP attribute alternative is required by PHPUnit 11/12's stricter parser.
- **Production autoload classmap** regenerated with `composer install --no-dev --classmap-authoritative` for optimized autoloading on production clones.
- **Version** bumped to 1.1.9 across plugin header, `WP_MCP_AI_VERSION` constant, readme.txt stable tag, and CHANGELOG.md.

### Fixed
- Chat-turn observer and eval-runner flakes (PR 10).
- `schedule-manager` and residual ECA/admin pages still enqueuing the old chartjs handle.
### April 25, 2026 — Model Catalog Refresh + JSON-Driven Catalog + Discovery Cron

### Added
- **Data-driven model catalog** — new `includes/data/model-catalog.json` is now the single source of truth for provider model lineups, rate limits, costs, and lifecycle status (156 entries across 11 providers/runtimes).
- **`wp_mcp_ai_model_catalog` filter** lets site admins and integrators add, override, or remove catalog entries without editing core files.
- **`wp_mcp_ai_model_catalog_source_path` filter** allows pointing the loader at an alternate JSON file (e.g., a child plugin or mu-plugins file). `wp-content/uploads/mcp-ai/model-catalog.json` is also auto-detected.
- **Cached loader** — `WP_MCP_AI_Model_Rate_Limits_CCT::load_catalog()` caches the JSON + filter result via `wp_cache_*` (group `wp_mcp_ai_model_catalog`). Call `flush_catalog_cache()` to invalidate.
- **Daily WP-Cron `wp_mcp_ai_model_catalog_discovery`** with new `WP_MCP_AI_Model_Discovery_Service` produces a reviewable diff (additions / sunsets / price changes) into the `wp_mcp_ai_model_catalog_suggestions` option. Suggestions are *never* auto-applied.
- **`wp_mcp_ai_model_discovery_enabled` filter** (default `true`) and **`wp_mcp_ai_model_discovery_interval` filter** (default `'daily'`) for opt-out / cadence control.
- **`wp_mcp_ai_model_catalog_suggestions_updated` action** fires with the diff payload after each cron run.
- **`WP_MCP_AI_FORCE_HARDCODED_CATALOG` debug constant** lets support reproduce issues without the JSON loader.
- **One-time migration** (`WP_MCP_AI_Model_Catalog_Migration`) rewrites stored configs and assistant `_wp_mcp_ai_model` post meta from removed ids to documented successors (e.g., `gpt-3.5-turbo` → `gpt-4o-mini`, `claude-3-opus-20240229` → `claude-opus-4-6`, `gemini-1.5-pro` → `gemini-2.5-pro`, `o1` → `o4-mini`).

### Changed
- **OpenAI catalog** — added GPT-5.5, GPT-5.4-mini, GPT-5.4-nano, GPT-5.4-codex with April 2026 pricing. GPT-5/4.1/4o re-priced. User-pinned `gpt-4.1`, `gpt-4o`, `gpt-4o-mini`, `gpt-4.1-mini`, `gpt-4.1-nano` remain ACTIVE as the cost-effective defaults.
- **Anthropic catalog** — added `claude-opus-4-7` flagship; legacy `claude-opus-4-5` / `claude-sonnet-4-5` / `claude-haiku-3-5` aliases marked `deprecated`.
- **Gemini catalog** — added `gemini-3.1-pro`, `gemini-3.1-flash`, `gemini-3.1-flash-lite` (April 2026 GA). All `gemini-1.5-*`, `gemini-2.0-*`, `gemini-pro`, `gemini-pro-vision`, and `imagen-3` removed.
- **NVIDIA NIM catalog** — added `nvidia/nemotron-4-340b-instruct`, `meta/llama-4-1-*`, `qwen/qwen3.5-coder-480b`, `microsoft/phi-4.1`. Deprecated `meta/llama-3.1-*` and `microsoft/phi-3-*` entries removed.
- **Cloudflare Workers AI** — added `@cf/meta/llama-4-scout-17b-16e-instruct`, `@cf/google/gemma-3-12b-it`.
- **Hugging Face** — added `meta-llama/Llama-4-8B-Instruct`, `Qwen/Qwen3-7B-Instruct`, `mistralai/Mistral-Small-3.1-24B-Instruct`. Phi-3-mini marked `deprecated`.
- **Ollama** — added `llama4`, `llama4:16x17b`, `qwen3`, `gemma3`, `phi4`, `deepseek-r1:7b`. `llama3:70b`, `mistral`, `gemma2` tagged `legacy`.
- **WebLLM (browser)** — added `Llama-3.2-3B-Instruct-q4f16_1-MLC`, `Qwen2.5-3B-Instruct-q4f16_1-MLC`, `gemma-3-2b-it-q4f16_1-MLC`.
- **Cost calculator** — pricing table aligned with April 2026 reality (GPT-5/4.1/4o re-priced; Gemini 1.5/2.0 removed; Claude Opus 4.7 added; retired audio/realtime dated snapshots removed). `avg_cost_per_million` baseline bumped to $0.50.
- **Provider dropdowns** — fallback option lists in admin Providers section + onboarding wizard refreshed to current April 2026 lineups.

### Removed
- **Dead orphan code block** in `class-wp-mcp-ai-model-config.php` (lines 332–3219, sitting inside an unclosed docblock comment after the live `return $configs;`). File shrank 3416 → 521 lines.
- **Sunset model ids** purged from the catalog: `gpt-4`, `gpt-4-turbo`, `gpt-3.5-turbo`, `o1`, `o1-mini`, `o1-preview`, `o1-pro`, `chatgpt-4o-latest`, dated `gpt-4o-realtime-preview-*` / `gpt-4o-audio-preview-*`, `gpt-4.1-2025-04-14`, `gpt-5.1-thinking/instant`, `gpt-5.2-thinking/instant`, `claude-3-opus-20240229`, `claude-3-haiku-20240307`, `claude-mythos-preview`, all `gemini-1.5-*`, `gemini-2.0-*`, `gemini-pro`, `gemini-pro-vision`, `gemini-3-pro-preview`, `gemini-3-flash-preview`, `imagen-3`, `meta/llama-3.1-*`, `microsoft/phi-3-*`.

### Migration
- Stored `wp_mcp_ai_model_configs` entries and assistant `_wp_mcp_ai_model` post meta referencing removed ids are auto-rewritten to documented successors on first load after the upgrade, keyed by the catalog `version` field so subsequent refreshes re-run the routine.


## [1.1.8] - 2026-04-15

### April 15, 2026 — Erlang C Queuing Tools, Full Tool-Reference Audit, WordPress.org Compliance Re-Audit

### Added
- **Erlang C Workforce Management Tools** — 4 new base plugin tools (`calculate_erlang_c`, `erlang_c_concurrency_advisor`, `erlang_c_staffing_advisor`, `erlang_c_queue_health`) with shared `WP_MCP_AI_Erlang_C` helper class
- **`wp_mcp_ai_queue_alert` action hook** for SLA breach notifications with full parameter schema
- **Tool Reference Audit** — `docs/reference/tools/tool-reference.md` fully audited with 14 new sections covering all 230+ base tools
- **Feature guide** — `docs/features/erlang-c-staffing-tools.md` with industry standards, usage scenarios, and API reference
- **Pro Addon External Services** documented in readme.txt (P1–P3: Replicate, ESPN Fantasy, Yahoo Fantasy) with Terms/Privacy links, clearly marked as not present in base plugin

### Changed
- **Version** bumped to 1.1.8 across plugin header, `WP_MCP_AI_VERSION` constant, readme.txt stable tag, and CHANGELOG.md
- **Production autoload classmap** regenerated with `composer install --no-dev --classmap-authoritative`

### Compliance
- **Full WordPress.org Plugin Guidelines re-audit** — all 13 guidelines pass
- **New compliance document** `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_15.md` with detailed evidence for each guideline, code statistics, and file references
- 333 capability checks, 147 nonce verifications, 200+ sanitization instances, 500+ output escaping instances confirmed across the base plugin


## [1.1.7] - 2026-04-11

### April 7–14, 2026 — MCP Protocol Completion, MCP Apps, CRE Debt Toolkit, Pro Professions/Teams, Compliance Hardening

Major additions including full MCP 2024-11-05 protocol compliance (all 11 methods), per-assistant remote MCP server connections, a complete CRE Debt & Securitization pro toolkit, expanded profession/team knowledge bases, and multiple rounds of WordPress.org compliance hardening.

### Added
- **MCP Protocol 2024-11-05 Completion (April 14, 2026)** (PR #4681): Full MCP 2024-11-05 spec compliance — all 11 protocol methods now implemented. New methods: `resources/read` (read resource content by URI with MIME-typed text/blob responses, `wp_mcp_ai_mcp_resources_read_contents` filter), `prompts/get` (full prompt with system instructions and argument values, `wp_mcp_ai_mcp_prompts_get_response` filter), `ping` (server liveness check), `completion/complete` (argument autocompletion for tools enum/boolean and prompt slug matching), `logging/setLevel` (client-controlled log verbosity, 8 standard levels, `wp_mcp_ai_mcp_logging_set_level` action), `notifications/cancelled` (request cancellation, `wp_mcp_ai_mcp_request_cancelled` action). JSON-RPC batching (max 20 messages, `wp_mcp_ai_max_batch_size` filter). Tool annotations mapping `WP_MCP_AI_Tool_Capability_Flags_Interface` to MCP hints (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`). `Mcp-Session-Id` session management with transient-backed state (1h TTL). Comprehensive test suites: `test-mcp-resources-read.php`, `test-mcp-prompts-get.php`, `test-mcp-protocol-completion.php`.
- **MCP Apps — Per-Assistant Remote MCP Server Connections (April 10, 2026)** (PR #4646, SEP-1865): Each assistant can connect to up to 10 remote MCP servers as "apps". JSON-RPC 2.0 client over Streamable HTTP transport (`initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`). Tool bridge wraps each remote tool as local `WP_MCP_AI_Tool_Interface` with `mcp_app_{label}_{tool}` slug. REST endpoints: `POST /mcp-apps/test`, `POST /mcp-apps/discover`, `GET /mcp-apps/{id}`. Admin metabox with repeater-style UI (label, server URL, auth, timeout, SSL verify). Transient-cached discovery (5min TTL). Remote tools registered via `wp_mcp_ai_register_tools` at priority 50.
- **CRE Debt & Securitization Pro Toolkit — 57 Tools (April 10–11, 2026)** (PRs #4647, #4650): Complete commercial real estate debt toolkit across five modules: Originations (11), Underwriting (13), CMBS/Securitization (10), Debt Fund (11), Asset Management (12). Shared `WP_MCP_AI_CRE_Debt_Calculator` with amortization, DSCR/LTV/debt yield, NPV, IRR, DCF, loan sizing, equity waterfall, defeasance/yield maintenance. CPT/CCT infrastructure with Chart.js admin dashboard. Settings toggle: `enable_cre_debt_toolkit`. All outputs include `ANALYSIS ONLY — Not investment advice.`
- **36 Pro Toolkit Professions + 17 Teams (April 11, 2026)** (PR #4652): 5 new profession knowledge bases (`pro-cre-debt.json`, `pro-financial-services.json`, `pro-digital-media.json`, `pro-business-operations.json`, `pro-specialized-services.json`) mapping 36 new roles to 275+ pro toolkit tool slugs. 2 new team configs (`cre-debt-teams.json` with 7 teams, `pro-toolkit-teams.json` with 10 teams). Total professions: 296 (was 259). Total teams: 100.
- **Compliance document** — `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_09.md` with full remediation details for all reviewer-flagged issues plus proactive audit results (PRs #4642, #4645, #4654, #4658)

### Fixed
- **AJAX capability checks (April 11, 2026)** (PR #4658) — `dismiss_directory_notice` and `dismiss_price_notice` handlers now require `manage_options` capability in addition to nonce verification
- **$_POST sanitisation (April 11, 2026)** (PR #4658) — Raw `$_POST` iteration in diagnostic logging now applies `sanitize_key()` on keys and `sanitize_text_field( wp_unslash() )` on values
- **404 URLs in readme.txt** — Trade.gov privacy URL and Mailjet terms URL corrected to working endpoints
- **Capability flag mismatches** — 13 base tools incorrectly declaring `'local-only'` while making external HTTP requests corrected to `'external-api'` (GDACS, NHC, Auth0, Crawl4AI, OpenAI, Cloudflare, Varnish, ReliefWeb, Query Remote Site, Store Agent Context, WooCommerce Product, Image Base)
- **CLI assistant export path** — `--file` parameter restricted to bare filename; all exports write exclusively to `uploads/mcp-ai/exports/` with `sanitize_file_name( basename() )`
- **sync-docs file write** — Removed `file_put_contents()` branch that wrote auto-fixed content to plugin/theme directories; auto-fix now only applies to post-type docs via `wp_update_post()`
- **Vision tool ParseError** — Missing closing class braces in vision-object-localization and vision-product-search tools
- **Algorave AudioContext resume (April 10, 2026)** (PR #4644) — Synchronous `getAudioContext().resume()` within user-gesture handler before any async operations fixes silent playback
- **Algorave channelCount=0 proxy (April 11, 2026)** (PRs #4648, #4655) — Data descriptor for `maxChannelCount` on proxy clamped to [1,32], verification + accessor fallback, eager `initializeAudioOutput()` after proxy install
- **Algorave visualizer analyser connection (April 8–9, 2026)** (PRs #4633, #4636, #4637, #4639) — AnalyserNode connection timing fixed; analyser connects to correct audio output node
- **Algorave async aliasBank (April 8, 2026)** (PR #4632) — CDN redirect and unhandled rejection fixes for sample loading
- **Research product JSON parse (April 9, 2026)** (PR #4640) — Fixed JSON parse error from invalid template and missing `response_format`

### Security
- **nodemailer** updated to 8.0.5 — SMTP CRLF injection fix (PR #4643)
- **basic-ftp** updated to 5.2.1 — CRLF command injection fix (PR #4634)
- **mathjs, langsmith** updated for security vulnerabilities (PR #4649)

### Changed
- **Production classmap** — `composer install --no-dev --classmap-authoritative` for optimized autoloading
- **Assistant tool presets** — Updated with new pro toolkit tool slugs and CRE debt tools (PR #4657)
- **All 30+ distribution ZIPs rebuilt** for v1.1.7 (PRs #4653, #4656)
- **CLAUDE.md excluded** from plugin ZIP builds via `.distignore` (PR #4651)
- **Compliance docs updated** — `WORDPRESS_ORG_COMPLIANCE_COMPLETE.md`, `README.md`, `03-wp-org-compliance.md` updated with v1.1.7 release history


## [1.1.6] - 2026-04-06

### April 2–6, 2026 — A2A Protocol, JetEngine MCP, Agent Command Center, Chat Bubble Widget

Major feature additions including inter-agent communication, JetEngine MCP bridging, unified agent management, and multiple Telegram Mini App improvements.

### Added
- **Gemma 4 Model Support (April 7, 2026)**: Added Google Gemma 4 (Apache 2.0) across all providers — 4 multimodal variants: 31B Dense (256K context), 26B MoE (256K, 3.8B active params), E4B (128K, edge/mobile), E2B (128K, edge/mobile). Providers updated: Gemini, Ollama (`gemma4`), NVIDIA NIM, LM Studio, Hugging Face, Cloudflare Workers AI. Model config, rate limits CCT, capability detection (vision + multimodal), usage tracker cost entries, and vision filter lists all updated.
- **JetEngine 3.8 MCP Server Integration (April 6, 2026)** (PR #4608): JSON-RPC 2.0 client bridging NV oOS into JetEngine's native MCP Server. 7 new Pro tools: `jetengine_mcp` (bridge), `jetengine_create_post_type`, `jetengine_create_taxonomy`, `jetengine_create_meta_field`, `jetengine_manage_relations`, `jetengine_site_context`, `jetengine_prompts`. MCP-first dispatch with silent REST v2 fallback. Admin status panel with 3 new settings. 5 new test files.
- **Agent-to-Agent (A2A) Protocol Integration (April 4, 2026)** (PR #4578): Full A2A protocol — Agent Card discovery via `/.well-known/agent.json`, JSON-RPC 2.0 server (`message/send`, `message/stream`, `tasks/get`, `tasks/list`, `tasks/cancel`), task state machine, A2A client for remote agents, `delegate_to_a2a_agent` tool, push notifications with exponential backoff. Admin settings for server/client enable. 60+ unit tests.
- **Agent Command Center Dashboard (April 4, 2026)** (PR #4575): New Pro admin page with 7 tabs: Overview (KPI cards, live agent status), Activity Log, Active Tasks, Approvals (human-in-the-loop), Analytics (Chart.js 4.4.7 with 7 charts), Uptime & Health, Strategy (efficiency scoring). Event tracking via `wp_mcp_ai_after_tool_execution` and `wp_mcp_ai_after_chat_response` hooks. 90-day metric retention.
- **Floating Chat Bubble Widget (April 3, 2026)** (PR #4566): Configurable floating chat bubble as Elementor widget and Gutenberg block. BEM CSS with 4 positions, 3 sizes, bounce/pulse animations, dark mode, full-screen mobile, `prefers-reduced-motion`, WCAG focus states. Vanilla JS with multi-instance registry, keyboard nav, sessionStorage persistence.
- **ECA Pro Toolkit — 24 New Tools (April 3, 2026)** (PR #4568): Attendance (3), waitlist/enrollment (3), scheduling/conflicts (3), notifications (3), reporting/analytics (3), integration (3), workflow/lifecycle (3), plus 3 new iSAMS/SOCS sync tools. 4 existing tools upgraded with consistent fields and audit trails.
- **Image Validation Tools (April 4, 2026)** (PR #4585): `validate_image_for_product` (9 product types, 10-category weighted rating) and `validate_image_for_vehicle` (cleaning/repair types with separate weight profiles). OpenAI Vision API–based, industry-standard weighted quality ratings (0–100, A–F). 29 tests.
- **Agent Workflow Presets (April 4, 2026)** (PR #4580): 5 new multi-agent orchestration presets (`agent_supervisor`, `agent_pipeline`, `agent_swarm`, `agent_hierarchical`, `agent_review_qa`). Chat UI sub-agent panel with agent cards, workflow tracker, and delegation notices.
- **Shopify Shop TMA (April 5, 2026)** (PR #4602): New general-purpose Shopify e-commerce mini app (24 files). Catalog with collection filters, product detail with variant selector, cart with `useReducer`, checkout via AI, orders with status badges, AI chat interface.
- **Per-Connection TMA URL Routing (April 5, 2026)** (PR #4588): Per-connection endpoints at `/telegram-mini-app/{connection_id}` for multi-bot Telegram setups. All 11 sub-endpoints mirrored. Global endpoint preserved for backward compatibility.
- **ECA Dashboard Page (April 3, 2026)** (PR #4570): Complete ECA tools list and dashboard page for the Pro admin.
- **Shopify Data Source Picker (April 5, 2026)** (PR #4605): Shopify data source picker added to Telegram channel connection settings.

### Changed
- **Anthropic & Gemini Subscription Tier Support (April 3, 2026)** (PR #4567): Centralized `build_request_headers()` and `resolve_endpoint()` for both providers. New settings: `anthropic_api_key_type`/`gemini_api_key_type` (standard/team/business/enterprise), `anthropic_base_url`/`gemini_base_url`. Filter hooks: `wp_mcp_ai_anthropic_request_headers`, `wp_mcp_ai_gemini_request_headers`. 20 tests.
- **Enterprise TMA Templates (April 4, 2026)** (PR #4586): 5 inline Telegram Mini App templates upgraded to enterprise quality with standardized 5-tab architecture (E-Commerce, CRM, Analytics, Booking, AI Chat). Unified `tmaToolHeaders()` auth, `slug`-based tool calls, localStorage helpers, Chart.js lazy loading.
- **Schedule Preset Install Overrides (April 5, 2026)** (PR #4603): `install_preset()` now accepts optional `$overrides` array for `assistant_id` and `credentials`. Frontend prompts for assistant selection on `assistant_run` presets. 4 new tests.
- **Analytics Tab Real Data (April 5, 2026)** (PR #4593): Hook names corrected to match actual `do_action()` calls. Per-agent metric tracking with `increment_agent_metric()`. Real data aggregation over selected time range.

### Fixed
- **`execute()` Signature Compatibility (April 6, 2026)** (PR #4609): All 7 JetEngine MCP tool classes now include `= array()` default parameter values matching `WP_MCP_AI_Tool_Interface`.
- **Activity Log Timestamp Parsing (April 4, 2026)** (PR #4579): Fixed empty activity tab in Agent Command Center.
- **Chart.js Height Bug (April 4, 2026)** (PR #4576): Fixed chart rendering in Command Center analytics.
- **TMA React Imports (April 5, 2026)** (PRs #4596, #4598): React SPAs now import from `react` directly instead of `@wordpress/element` to prevent crashes in Telegram WebView.
- **TMA E-Commerce Auth Race (April 5, 2026)** (PR #4590): Fixed woo-shop crash due to missing auth flow.
- **TMA Session Auth & Param Routing (April 5, 2026)** (PR #4599): Fixed session authentication and remote connection parameter routing.
- **TMA Haptic Feedback API (April 5, 2026)** (PRs #4592, #4595): Fixed haptic API misuse and tools/execute 500 errors.
- **Shopify TMA Fixes (April 5, 2026)** (PR #4602): `executeTool()` corrected to send `slug` not `tool`, response extraction fixed for `raw?.result?.products`, `TMAContext.jsx` auth flow added with `authReady` gate.
- **Shopify Data Source Config (April 5, 2026)** (PRs #4605, #4606): Toggle visibility and save persistence.
- **Shopify TMA White Screen (April 6, 2026)** (PR #4607): Fixed white screen in Shopify TMA templates.
- **TMA Subscriber Permissions (April 5, 2026)** (PR #4604): TMA subscriber users can now access remote WooCommerce products.
- **Dashboard Default Page (April 5, 2026)** (PR #4591): Dashboard is now the default page for ECA section.
- **Task Plans in Command Center (April 4, 2026)** (PR #4583): Fixed missing task plans from tasks tab.
- **Model Pricing Auto-Update (April 3, 2026)** (PR #4565): Fixed for models missing from CCT database.
- **JS Lint Fixes (April 5, 2026)** (PR #4600): Re-applied JS lint fixes with audit-informed unused var handling.

### Security
- **SQL Query Hardening (April 4, 2026)** (PR #4574): `$wpdb->dbname` interpolation replaced with `$wpdb->prepare('%s', DB_NAME)`. `esc_sql()` added to table name interpolations in 5 files. Pre-prepared `$where` fragment elimination in analytics engine.
- **Guest Token TTL Fix (April 4, 2026)** (PR #4574): `guest_token_lifetime` setting now wired to actual token system with absolute max TTL (7 days) and min TTL (60s) enforcement.
- **Output Escaping (April 4, 2026)** (PR #4574): Shortcode `echo $assistant_content` → `echo wp_kses_post($assistant_content)`. Removed unsafe `urldecode()` after `sanitize_text_field()`.
- **Lodash Vulnerability (April 3, 2026)** (PR #4564): Fixed lodash security vulnerabilities in pro addon.

---

## [1.1.6] - 2026-04-02

### WordPress.org Compliance — Final Pass Before Resubmission

This release addresses all issues identified by the WordPress.org automated review system
on April 2, 2026, plus the compliance work from March 24, 2026.

### Added
- **NVIDIA NIM Provider in Getting Started Wizard (April 2, 2026)**: Added NVIDIA NIM as the 8th AI provider in the onboarding wizard (Step 2). Users can now enter their NVIDIA API key and test the connection during initial setup. Saving the key automatically enables the provider. 40+ NVIDIA models available including Llama, Mistral, Nemotron, Gemma, and Qwen families via `integrate.api.nvidia.com` or self-hosted NIM containers.
- **Vehicle Estimation Tools (March 31, 2026)** (PR #4526): Three always-available Pro tools for automotive estimation.
  - `vin_decode` — ISO 3779 check-digit validation, NHTSA vPIC API decode with 24h transient cache, 28-field vehicle descriptor.
  - `vehicle_repair_estimate` — 5-step image-to-estimate pipeline: image intake → VIN identification → damage analysis → price-sheet mapping → estimate generation. Heuristic fallback costs for 20+ parts; ADAS calibration for 2018+ windshield replacements.
  - `vehicle_cleaning_estimate` — Car wash package & add-on pricing engine with LLM vision vehicle size classification, 4 packages, 7 add-ons, and `wp_mcp_ai_vehicle_cleaning_menu` filter.
  - 60 PHPUnit tests covering tool contracts, pricing scenarios, and permission checks.
- **Shopify Connection Auto-Resolve + `remote_shopify_connection` Tool (March 31, 2026)** (PR #4521): New `WP_MCP_AI_Shopify_Connection_Resolver` trait auto-resolves `connection_id` from assistant context for all 5 Shopify tools. New `remote_shopify_connection` tool for listing and testing Shopify connections. 14 PHPUnit tests.
- **Webhook Status Admin Page (March 31, 2026)** (PR #4517): New submenu under NV oOS Pro (`nvoos-pro-webhook-status`) for centralized webhook monitoring across all 9 webhook-capable connection types. Summary cards, live Telegram checks via `getMe` + `getWebhookInfo`, action buttons for set/remove webhook. 30 PHPUnit tests.
- **QuickBooks Desktop Sync Tool (March 30, 2026)** (PR #4507): New `quickbooks_desktop_sync` Pro tool connecting to QuickBooks Desktop via QODBC relay API on Windows. New `quickbooks_desktop` remote connection type with relay URL, API key, and DSN fields. 14 PHPUnit tests.
- **Listing Image Download Tools (March 29, 2026)** (PR #4503): Three new always-on Pro tools for bulk-downloading business listing images: `download_google_maps_images` (Places API), `download_facebook_page_images` (Graph API v21.0), `download_instagram_page_images` (Graph API v21.0). Shared `media_handle_sideload()` import, optional ZIP export. 39 PHPUnit tests.
- **15 Schedule Presets (March 30, 2026)** (PR #4509): CRM Email Correspondence (5), Document Management & Sharing (5), and Upwork Freelancer (5, new `upwork_freelancer` toolkit). Total presets: 100 → 115.
- **Registration Product Research Page Enhancement (March 31, 2026)** (PR #4519): Quick Import and Guided Entry tabs, 13 document processing tools added to AI chat, product selector sidebar, 3 new AJAX handlers for bulk import, document upload, and product preview.
- **Author/Copyright/License Attribution (March 30, 2026)** (PR #4510): Consistent `@author`, `@copyright`, `@license` tags added across 2,535 PHP files, 101 JS files, 58 CSS files. Base: GPL-3.0-or-later; Pro: Proprietary. Copyright year updated to 2025-2026.
- **dvdoug/boxpacker Pre-Packaged (March 31, 2026)** (PR #4527): `dvdoug/boxpacker` (`^3.12 || ^4.0`) v3.12.1 + psr/log 3.0.2 pre-packaged in `addons/pro/vendor/`. Production autoload docs aligned to `composer install --no-dev --classmap-authoritative`.
- **Onboarding Wizard Enhancement — Preset Assistant Seeding & Accessibility (March 28, 2026)**: Complete code review and enhancement of the Getting Started wizard (`/wp-admin/admin.php?page=wp-mcp-ai-getting-started`).
  - **8 use-case presets** with comprehensive tool lists, system prompts, and temperatures: Content Creator (12 tools), Customer Support (8 tools), E-commerce (11 tools), SEO & Research (12 tools), Developer Copilot (12 tools), Media & Creative Studio (11 tools), Site Administrator (13 tools), General Purpose (12 tools).
  - **Assistant seeding**: Selecting presets in Step 3 now creates fully-configured `mcp_ai_assistant` CPT posts with tools, system prompt, provider, model, and temperature — users get a working system out of the box.
  - **First assistant auto-default**: The first seeded assistant is automatically set as the site's default assistant.
  - **Copy-to-clipboard**: Shortcode display on Step 4 includes a copy button with accessible feedback.
  - **Explicit wizard completion**: Step 4 no longer marks the wizard as complete on page render; users must click "Mark Setup Complete" to finalize.
  - **External JavaScript**: All inline `<script>` blocks extracted to `assets/js/onboarding-wizard.js` with `wp_localize_script()` for i18n strings, improving CSP compliance and cacheability.
  - **WCAG 2.1 accessibility**: WAI-ARIA `tablist`/`tab`/`tabpanel` pattern for provider tabs with keyboard navigation (Arrow keys, Home, End), `aria-current="step"` progress indicators, `aria-live="polite"` regions for dynamic feedback, `focus-visible` outlines.
  - **New filter**: `wp_mcp_ai_onboarding_presets` — third-party addons can add or modify onboarding presets.
  - **New action**: `wp_mcp_ai_onboarding_presets_seeded` — fires after preset assistants are created.
  - **22 PHPUnit tests** covering presets structure, assistant seeding, duplicate prevention, model resolution, and masked key detection.

### Changed
- **Transformers.js Upgrade to v3.8.1 (March 30, 2026)** (PR #4514): CDN upgraded from deprecated `@xenova/transformers@2.17.2` to `@huggingface/transformers@3.8.1`. WebGPU auto-detect with WASM fallback. Quantization API migrated: `quantized: true` → `dtype: 'q8'`. 4 Qwen3 models added to embedded LLM catalog.
- **Medical Vitals Dashboard Enhancements (March 31, 2026)** (PR #4523): "Most Recent Reading" card with relative timestamps and colour-coded status dots. Configurable trend date range selector: 7D/14D/30D/90D.
- **Shopify Tools — `connection_id` Auto-Resolve (March 31, 2026)** (PR #4521): All 5 Shopify tools (`shopify_products`, `shopify_orders`, `shopify_customers`, `shopify_inventory`, `shopify_catalog`) now auto-resolve `connection_id` from assistant context. Manual `connection_id` parameter is optional.
- **Quick Tool Selection Presets — 8 Missing Tools Added (March 30, 2026)** (PR #4505): Added 8 missing tools to preset coverage.
- **Quick Tool Selection Presets – Full 760-Tool Coverage (March 16, 2026)**: Expanded the Quick Tool Selection Presets on the assistant CPT edit page from ~527 covered tools to all 760 available tools, ensuring every registered tool can be applied via a preset without requiring manual search.
  - **New preset**: `📋 Registration & Compliance` (44 tools) — end-to-end regulated product/permit workflow: registration lifecycle (create/approve/renew/submit), document expiry tracking, regulatory submissions, compliance certificates, authority submission, NMRA/MOHAP sync, import duty/HS code
  - **🛒 E-commerce**: Added Shopify (products/orders/customers/inventory), regulated product lifecycle (create/validate/duplicate/import-export Excel), inventory forecast/tracking, bulk order management, abandoned cart recovery, customer lifetime value
  - **💬 Communication & Messaging**: Full cross-platform coverage — Discord (reactions/channels/voice members), Slack (create/read channels), Teams (channels/messages), Apple Messages (send/group/interactive/read), Telegram (reactions/commands/webhooks), WhatsApp interactive/media/template, Messenger broadcasts, Google Chat spaces/members, Twitter DMs/webhook, Outlook email/messages, unified broadcast, email notification management
  - **🔐 Authentication & Security**: Added `vault_access`, `vault_manage`, `analyze_tool_security`, `check_tool_compliance`, `generate_password`
  - **💻 Development**: Added tool scaffolding suite (`generate_tool_scaffold`, `generate_tool_logic`, `generate_tool_parameters`, `generate_tool_documentation`, `generate_tool_tests`, `refactor_tool_code`, `validate_tool_schema`, `benchmark_tool_performance`), `git_operations`, `execute_shell_command`, `search_codebase`, `automate_development_workflow`
  - **📁 Files & Documents**: Added PDF tools (watermark/merge/OCR/extract), Excel import/export/validate, `generate_excel`/`generate_pdf`/`generate_word`, iCloud Drive + OneDrive CRUD, regulated document management, `track_document_version`
  - **📈 SEO & Marketing**: Added `analyze_competitor_sites`, `social_listening_trends`, `influencer_identification`, `monitor_mentions_replies`, `post_to_multiple_platforms`, `schedule_social_post`, `create_content_calendar`, `bulk_schedule_posts`, `get_cross_platform_analytics`, pipeline reports
  - **⚙️ Site Management**: Added page-section builder tools (hero/CTA/homepage/services/footer/navigation/testimonial/landing page/gallery/sidebar), site template import/export/save, `scaffold_theme_structure`
  - **✍️ Content Writing**: Added `generate_post_ideas`, `generate_cover_letter`, `get_post`, `delete_post`, `create_content_calendar`, `moderate_comments`
  - **⏰ Scheduling & Automation**: Added full appointment lifecycle (create/update/cancel/reschedule), availability rules, booking links, appointment reminders/confirmations, Google/Outlook calendar sync, `bulk_schedule_posts`
  - **⚕️ Healthcare**: Added `log_health_metrics`, `import_vitals`, `sync_with_mohap`, `sync_with_nmra`
  - **⚖️ Legal**: Added `add_regulatory_requirement`, regulatory requirements/updates retrieval, `check_document_expiry`, `check_product_compliance`, `generate_compliance_certificate`, `generate_compliance_report`
  - **💼 Sales & CRM**: Added `create_company`/`get_companies`/`research_company`, CRM email search (leads/correspondence/accounting), `client_communication_log`, `generate_invoice_pdf`, `send_client_invoice`, `sales_performance_dashboard`
  - **💼 Finance & Business**: Added `generate_invoice_pdf`, `generate_submission_pack`, `generate_compliance_report`, `generate_pdf_dossier`
  - **🎓 Education**: Added full registration lifecycle (create/approve/renew/submit, import/export Excel, expiry alerts/forecast)
  - **📊 Workflow Monitoring**: Added workflow rule CRUD (`create_workflow_rule`, `update_workflow_rule`, `delete_workflow_rule`, `list_workflow_rules`, `test_workflow_rule`, `get_workflow_execution_log`)
  - **📋 Project Management**: Added `add_task_dependency`, `remove_task_dependency`, `get_task_dependencies`, `manage_template_versions`
  - **🏗️ Architect**: Added `generate_site_plan`, `integrate_with_architect`, `generate_architectural_drawing`
  - **🎬 Media Templates**: Added `create_social_video`, `create_remotion_video`, `manage_template_versions`
  - **🧠 AI/ML**: Added `prepare_file_for_vector_store`
  - **⚖️ Legal & Policy**: Added regulatory CRUD, `check_authority_status`, `submit_to_authority`, `validate_document_checklist`
  - **📊 Business Analytics**: Added `segment_customers`, `export_customer_data`
  - **Result**: 61 presets covering all 760 tools (2,030 total tool references across presets) — up from 60 presets covering ~527 tools

### Fixed
- **Telegram Webhook 403 on Multi-Bot Setups (March 30–31, 2026)** (PRs #4512, #4513, #4516, #4518): Fixed webhook URL to include `connection_id` in test/status endpoints; added REST auth bypass and admin-ajax fallback; direct array-key lookup for connection resolution.
- **Chat Channels Inbox — Bot Name Display (March 31, 2026)** (PRs #4522, #4524): Telegram bot `@bot_username` now shown as primary display in conversations list, thread header, and contacts table.
- **Chat Channels Inbox — Message Pagination (March 31, 2026)** (PR #4525): Newest messages now appear on page 1 (was oldest-first).
- **Chat Channels Inbox — connection_id Scoping (March 29–30, 2026)** (PRs #4500, #4506): Message queries scoped by `connection_id` for Telegram to isolate multi-bot conversations; column migration and `SELECT *` fallback for backward compatibility.
- **Chat Channels Inbox — 404 on Messages Endpoint (March 29, 2026)** (PR #4489): Fixed CCT and CPT store merge for conversations and messages.
- **Chat Channels Inbox — CCT/CPT Store Merge (March 29, 2026)** (PR #4488): Merged CCT and CPT stores so conversations and messages from both backends display correctly.
- **Workflow Preset Data Mapping (March 30, 2026)** (PRs #4504, #4508): Fixed preset data so tools, arguments, and conditions populate correctly in the Pro Workflow Builder canvas.
- **Preset Browser AJAX Callbacks (March 29, 2026)** (PR #4501): Fixed wrong callback signatures in schedule manager preset browser.
- **Telegram Mini App – Member Loading (March 2026)**: Health & Wellness and Medical Vitals mini app templates no longer get stuck on "Loading…" when a subscriber opens the app for the first time.
  - Server-side member pre-selection: the current WordPress user's linked `mcp_ai_member` post is resolved at page-render time and injected as `SERVER_MEMBER_ID`/`SERVER_MEMBER_NAME` JS variables; the member picker is skipped when a match is found.
  - Auto-select single member: `hwFetchMembers()` / `mvFetchMembers()` now auto-select and close the picker when only one member is returned, eliminating the manual-tap requirement after first member creation.
  - Retry button: when the member list request fails (auth not yet established or network error) a **Retry** button is shown instead of leaving the user on an infinite "Loading…" state.

### Changed
- **`list_members` tool – role-scoped visibility**: Subscribers (`read` capability only) now receive only the `mcp_ai_member` posts they authored. Users with `edit_posts` or higher (Authors, Editors, Administrators) receive all members site-wide, enabling care-team management workflows.
- **`wp_mcp_ai_get_member_id_by_user_id()`**: Returns `0` for users with `edit_posts` or higher so the full member picker is shown for admin/editor roles rather than silently pre-selecting one of their own posts.

### Security
- **brace-expansion & serialize-javascript Vulnerabilities (March 29, 2026)** (PR #4487): Fixed brace-expansion zero-step sequence DoS (CVE-2026-33750) and serialize-javascript CPU exhaustion via crafted array-like objects (CVE-2026-34043). Upgraded brace-expansion v1.x to 1.1.13, v2.x to 2.0.3, serialize-javascript to 7.0.5.

### Added
- **NPM Packages – Zero-Config Publish for All 9 Packages (March 2026)** (PR #4364): All nine standalone NPM packages extracted from the oOS chat UI are now automatically published to the NPM registry via GitHub Actions.
  - **9 packages** under the `@nvdigitalsolutions` scope, all at `v0.1.0-alpha.1`:
    - `nvoos-storage` — Async JSON via Web Worker (zero dependencies)
    - `nvoos-markdown` — XSS-safe markdown renderer (peer deps: `marked`, `dompurify`)
    - `nvoos-events` — SSE client + job event bus (peer dep: `@microsoft/fetch-event-source`)
    - `nvoos-http-client` — HTTP client with automatic retry/backoff (peer dep: `ky`)
    - `nvoos-clipboard` — Clipboard copy with Clipboard API / `execCommand` fallback (zero dependencies)
    - `nvoos-offline-sync` — IndexedDB offline-first sync with auto server sync on reconnect (zero dependencies)
    - `nvoos-slash-commands` — Slash command system with fuzzy-search autocomplete (zero dependencies)
    - `nvoos-audio` — Browser audio I/O: TTS, STT, translation, voice chat with VAD (zero dependencies)
    - `nvoos-dom-batcher` — RAF DOM batcher, scroll batcher, and UI utilities for high-frequency streaming UIs (zero dependencies)
  - **Two GitHub Actions workflows**:
    - `.github/workflows/npm-publish.yml` — Publishes stable releases on `v*.*.*` tags or `workflow_dispatch`
    - `.github/workflows/npm-publish-alpha.yml` — Publishes alpha pre-releases on `v*.*.*-alpha.*` tags
  - **Single source of truth**: Both workflows share a single `PACKAGES` environment variable; adding a new package requires updating only that one line.
  - **CI steps per package**: version bump → `node adapt-for-npm.js` build → `node --check` syntax validation → publish (or dry-run)
  - **Setup**: requires only an `NPM_TOKEN` secret in repository settings; no per-package configuration needed.
  - See [`packages/README.md`](packages/README.md) and [`packages/QUICK_START.md`](packages/QUICK_START.md) for installation and usage.


## [1.1.5] - 2026-03-25

### Added
- **NV oOS Canvas Addon — Platform-Specific ZIP for Tesseract PDF OCR (March 25, 2026)** (PR #4441, #4442): The `canvas` npm package (Linux-only native binary, ~50 MB compressed) is now distributed as a separate, optional `nvoos-canvas` WordPress plugin rather than being bundled in the Pro ZIP.
  - New standalone plugin in `addons/canvas/` — installs the platform-specific binary alongside the Pro addon.
  - CI builds `nvoos-canvas-linux-x64.zip` and `nvoos-canvas-linux-arm64.zip` via `build-canvas-addon.yml` and commits them to `build/`.
  - OCR service auto-detects the Canvas Addon path via `NVOOS_CANVAS_PATH` environment variable; falls back to `node_modules` if the addon is absent.
  - Base + Pro ZIP remains unchanged at ~33 MB; canvas is a post-install optional step only needed for Tesseract PDF OCR on Linux servers.

- **Five New Pro WP-CLI Command Groups (March 23, 2026)** (PR #4418):
  - `wp mcp-ai pro status` — display Pro addon version, license, and active toolkit summary.
  - `wp mcp-ai toolkit list/enable/disable` — manage Pro toolkits from the command line.
  - `wp mcp-ai connection list/get/test/delete` — manage Chat Channel connection entries.
  - `wp mcp-ai project list/get/create/delete` — manage AI project CPT entries.
  - `wp mcp-ai task list/get/create/complete/delete` — manage AI task CPT entries.
  - Shared base class `WP_MCP_AI_Pro_CLI_Base_Command` with assertion helpers.
  - Tests added in `addons/pro/tests/test-wp-cli-pro-commands.php`.

- **Mailgun Email Integration (March 22, 2026)** (PR #4408):
  - New Pro tool `send_mailgun_email` — sends transactional emails via the Mailgun API.
  - Supports US and EU region endpoints (`api.mailgun.net` / `api.eu.mailgun.net`).
  - Tags passed as separate `o:tag` form fields (array, not comma-separated string) per Mailgun requirements.
  - Admin settings: `mailgun_api_key`, `mailgun_domain`, `mailgun_region`, `mailgun_from_email`, `mailgun_from_name`.

- **Brevo Email & CRM Integration (March 22, 2026)** (PR #4408):
  - Three new Pro tools under the `enable_email_toolkit` guard:
    - `send_brevo_email` — send transactional emails via Brevo `api-key` header auth.
    - `manage_brevo_contacts` — create, update, and list contacts in Brevo lists.
    - `get_brevo_statistics` — retrieve campaign and contact statistics.
  - Admin settings: `brevo_api_key`, `brevo_from_email`, `brevo_from_name`, `brevo_webhook_secret`.

### Changed
- **Embedded LLM Server-Side Client — Moved to Pro Addon (March 25, 2026)** (PR #4433, #4434): `WP_MCP_AI_Embedded_Client` and `WP_MCP_AI_Embedded_Model_Ajax` relocated from `includes/` to `addons/pro/includes/`. The base plugin's language model router uses a `class_exists()` guard and falls back gracefully when Pro is absent.
  - `enable_embedded` field in the Pro Providers section now shows `disabled = true` with the label "Auto-enabled with Pro" rather than a manual toggle.

- **Embedded LLM — Added Gemma 2B Instruct Model (March 24, 2026)** (PR #4428): Added `gemma-2-2b-it-q4_k_m` as the fourth server-side GGUF model and set `gemma-2-2b-it-q4f16_1-MLC` as the new client-side WebLLM default model. Fixed server-side chat routing that was silently dropping assistant global `embedded_server_model` settings.

- **WP.org Compliance — Pro Addon is a Genuine Extension (March 25, 2026)** (PR #4435): Resolved nine surface-level items that could give the impression that the Pro addon "unlocks" base plugin features. Confirmed architecture: all tools in `includes/tools/` register unconditionally and are never gated behind a license check.

- **Telemetry Opt-In (March 24, 2026)**: Activation tracking is now disabled by default (opt-in model). Users must explicitly enable it via Settings → NV oOS → General → Enable Activation Tracking. Setting renamed from `disable_activation_tracking` → `enable_activation_tracking`. Complies with WordPress.org Guideline 7 & 9.
- **Tool Registry (March 24, 2026)**: Removed Pro add-on license gating from base tool registry. All tools included in the plugin ZIP are now always registered; runtime availability is controlled by each tool's `is_available()` method (dependency check, not license gate). Complies with WordPress.org Guideline 5.
- **Settings Sanitization (March 24, 2026)**: `sanitize_settings_callback` now recursively sanitizes nested array settings using `sanitize_textarea_field()` for strings and `esc_url_raw()` for URL values. Complies with WordPress.org Guideline 6.
- **`WP_MCP_AI_BASE_VERSION` default (March 24, 2026)**: Changed from `true` to `false` so full base tool set loads by default without requiring any `wp-config.php` define.

### Fixed
- **Embedded LLM — Shared Library Loading Failures (March 22–23, 2026)** (PR #4414, #4416):
  - `extract_binary_from_archive()` now uses `sanitise_binary_filename()` (allowlist `[A-Za-z0-9._-]`) instead of `sanitize_file_name()`, preserving `.so.0.9.8`-style shared-library filenames.
  - `build_inference_command()` prepends `LD_LIBRARY_PATH` so co-located `.so` files are found at runtime.
  - `create_soname_symlinks()` creates `lib*.so.X → lib*.so.X.Y.Z` and `lib*.so → lib*.so.X` symlinks after extraction; falls back to `copy()` when `symlink()` is blocked (e.g., Cloudways).
  - `get_shared_libs_status()` calls `create_soname_symlinks()` on every status check, auto-repairing missing SONAMEs on existing installs.

- **Embedded LLM — Provider Diagnostic Page Enhancements (March 23, 2026)** (PR #4415, #4417):
  - Diagnostic page now shows the resolved llama-cli binary path and the names of all co-located shared libraries.
  - `get_shared_libs_status()` added: scans binary directory for `lib*.so*` files, returns `found`, `libs`, and `bin_dir`.
  - Fixed fatal `E_ERROR` when `symlink()` is listed in `disable_functions` — replaced `symlink()` call with `is_callable()` guard.

- **Embedded LLM — `test_connection()` False "No Output" Error (March 23, 2026)** (PR #4419): llama.cpp builds b8479+ write `--version` output to stderr instead of stdout. `run_binary()` now accepts `$use_stderr_fallback = true`; `test_connection()` uses it so the binary is correctly detected on modern builds.

- **Embedded LLM — SSE Streaming Fixes (March 22–24, 2026)** (PR #4420, #4421, #4422, #4423, #4425):
  - Client-side: `chat.js` no longer uses Ky for SSE requests; switches to native `fetch + ReadableStream` to avoid Ky's 30 s AbortController timeout killing slow llama-cli inference.
  - Server-side: `send_sse_headers()` now sets `zlib.output_compression Off`, calls `ob_end_clean()`, and uses `wp_die()` instead of bare `exit()` to avoid PHP-FPM/nginx HTTP/2 `RST_STREAM`.
  - Elementor widget: `enable_streaming` attribute is now always emitted (as `"true"` or `"false"`) so the shortcode correctly respects the toggle in both states.
  - `max_tokens` is now injected from `WP_MCP_AI_Resource_Manager` into the PHP-side shortcode config so the WebLLM path no longer falls back to a hardcoded `2048`.

- **Embedded LLM — WebLLM Function-Calling Client (March 24, 2026)** (PR #4427): Deferred `WebLLMFunctionCallingClient` class definition inside `waitForDependencies().then()` so `extends window.WP_MCP_AI_EmbeddedLLM` evaluates after the dependency is confirmed available.

- **Chat UI — Message Bubble Interactions During Streaming (March 24, 2026)** (PR #4426): `disableForm()` now scopes its disable/enable sweep to only the input area and send button, not all buttons in the widget — copy, speech, save, and delete buttons on already-rendered messages remain clickable during streaming.

- **Agentic Loop — Orphaned `tool_calls` Error (March 24, 2026)** (PR #4430): When `max_iterations` is reached while the LLM still has pending tool calls, the stored assistant message with `tool_calls` is now filtered out of the history before the next turn. This fixes the OpenAI error "An assistant message with 'tool_calls' must be followed by tool messages…" that appeared for `vision_object_localization` and any tool hitting the iteration limit.

- **Embedded LLM — Logger Integration for Ollama Client (March 24, 2026)** (PR #4429): Added `WP_MCP_AI_Logger` calls to all 5 previously-unlogged methods in `WP_MCP_AI_Ollama_Client` (`chat`, `create_embedding`, `list_models`, `generate`, `show_model_info`). All concrete chat clients now have full logging coverage.

- **DICOM Imaging — UID Filesystem Path Sanitization (March 22, 2026)** (PR #4406): DICOM UIDs are now sanitized with `sanitize_uid_for_path()` (`preg_replace('/[^0-9.]/', '_', $uid)`) instead of `sanitize_file_name()`. `sanitize_file_name()` applies a filterable hook that can strip dots, collapsing distinct UIDs to the same directory.

- **Pro Workflow Builder — Pre-packed Assets and CI Gaps (March 25, 2026)** (PR #4443):
  - `webpack.config.workflow.js` now writes output to `addons/pro/build/workflow-builder/` with the entry named `workflow-builder` (matching the PHP loader expectation).
  - `package.json` `build:workflow`/`start:workflow` scripts updated to use the config file instead of inline `--output-path`, preventing `@wordpress/scripts` from deriving filenames from the entry filename.
  - CI build workflow now commits freshly-built `workflow-builder` and `tma-woo-shop` artifacts on every run.

- **Re-install llama.cpp Binary Button (March 23, 2026)** (PR #4412): Added a **Re-install llama.cpp Binary** button to the embedded provider settings page for easy re-download after a failed or partial extraction.

- **15 Dead URLs in readme.txt (March 24, 2026)**: Fixed 15 broken external service documentation links (ReliefWeb, remove.bg, Plaid, Mubert, GDACS, NV Digital Terms, GitHub releases, Tavily, Exa.ai, GoQR privacy). All verified working after fix.

### Dependencies
- **symfony/cache** updated from v6.4.34 to v6.4.35
- **symfony/validator** updated from v6.4.34 to v6.4.35
- **addons/pro/vendor**: Removed stale gitlinks; populated all 6 previously-empty vendor directories with real package files
- Production classmap autoloader regenerated (686 entries)

### Documentation
- Added `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_03_24.md` — Pass 17 compliance verification report
- Added `docs/operations/compliance/03-wp-org-compliance.md` — compliance change log for this PR

## [1.1.4] - 2026-03-15

### Security
- **AES Encryption Upgrade (March 11, 2026)**: Upgraded stored credential encryption to AES-256-GCM for stronger security (was AES-256-CBC)
- **finfo Fail-Closed (March 11, 2026)**: MIME type detection now fails closed — uploads are denied when finfo cannot determine the type rather than falling through
- **OCR Error Info Disclosure (March 11, 2026)**: Fixed OCR error responses that could expose internal file paths or stack traces
- **Discord Replay Attack (March 11, 2026)**: Added webhook replay attack protection for Discord channel connections
- **HTTPS Enforcement (March 11, 2026)**: All external webhook registrations and remote site connections now require HTTPS
- **Backup Path Leak (March 11, 2026)**: Fixed file export handler leaking internal backup file paths in error responses
- **ZIP Bomb Protection (March 11, 2026)**: Added size-limit checks during ZIP file processing to prevent decompression bombs

### Added
- **Gemini Embedding Model Improvements (March 12, 2026)** (PR #4184): Added `gemini-embedding-001` model, `output_dimensionality` parameter, 9 new task types (RETRIEVAL_QUERY, RETRIEVAL_DOCUMENT, SEMANTIC_SIMILARITY, CLASSIFICATION, CLUSTERING, QUESTION_ANSWERING, FACT_VERIFICATION, CODE_RETRIEVAL_QUERY, IMAGE_SIMILARITY), and per-request model override in batch embedding calls
- **AI-Powered Product Actualization (March 12, 2026)** (PR #4186): Product actualization tool now defaults to AI-powered integration mode using Gemini (gemini-2.5-flash-image) or OpenAI (gpt-image-1); composite mode retained as legacy fallback; provider auto-detection based on configured API keys
- **Teams Declarative Agent Manifest (March 12, 2026)** (PR #4181): Added Teams declarative agent manifest generation to Chat Channels admin for easier Teams App Package deployment
- **Teams OAuth One-Click Connect (March 12, 2026)** (PR #4182): Microsoft OAuth 2.0 one-click connect for Teams — configure Azure AD client ID/secret, auto-refreshes access tokens, downloadable App Package ZIP
- **Telegram Slash Commands Integration (March 12, 2026)** (PR #4185): Dynamically integrates the mcp-ai-slash-commands plugin into the Telegram bot at runtime; added /vectorstore to /start message and admin slash command reference table
- **TMA Markdown Rendering (March 13, 2026)** (PR #4200): AI replies in Telegram Mini App doctor and coach chat tabs now rendered as formatted HTML via lazy-loaded Markdown renderer
- **Chat Channel Connection Docs (March 12, 2026)**: Improved in-admin setup documentation for Teams, Discord, and Google Chat connection types

### Fixed
- **Slack @Mentions (March 12, 2026)** (PR #4171): Fixed Slack bot not responding to channel @mentions
- **Slack Channel Settings (March 12, 2026)** (PR #4175): Slack auto-reply now uses mrkdwn formatting; channel-specific settings surfaced on the connection page; enhanced channel type handling per 2025 industry standards
- **Chat Channel Console Errors (March 12, 2026)** (PR #4170): Fixed settings page JavaScript console errors — removed duplicate event listener, added ajaxurl fallback
- **Google Chat Events (March 12, 2026)** (PR #4172): Fixed Google Chat channel events not being received even when connection tests passed (route conflict, OIDC bypass)
- **Google Chat Auto-Reply (March 12, 2026)** (PR #4179): Fixed auto-reply for DMs and @mentions; fixed connection test when OIDC verification is disabled
- **Google Chat Diagnostic Log (March 12, 2026)** (PR #4183): Added webhook diagnostic log to the Settings page for easier Google Chat connection troubleshooting
- **Teams Multi-Connection (March 12, 2026)** (PR #4182): Extended Teams to support multiple simultaneous connections with per-connection setup guide in admin UI
- **Teams Webhook (March 12, 2026)** (PR #4180): Enhanced Teams webhook handler; filled cross-channel consistency gaps (Slack/Telegram parity for rate limiting and retry logic)
- **Telegram Typing Indicator (March 12, 2026)** (PR #4173): Added typing indicator and rate-limiting enforcement for Telegram chat channel auto-replies
- **TMA Doctor Tab Assistant (March 13, 2026)** (PR #4198): Telegram Mini App doctor tab now uses the assistant assigned to the connection instead of a hardcoded fallback
- **Vitals Log Import (March 13, 2026)** (PR #4197, #4202): Fixed vitals_log import fallback for partial-row edge cases; fixed JetEngine list_types returning null slugs/names
- **wp_tempnam Guard (March 13, 2026)** (PR #4203): Fixed `wp_tempnam()` undefined function error in Pro tools by requiring `wp-admin/includes/file.php` before use
- **Consolidate & Add Page (March 13, 2026)** (PR #4199): Fixed consolidate & add page setup — singleton pattern, init hook registration, sanitize delegation
- **HTML-to-PDF Media Sideload (March 13, 2026)** (PR #4208): Fixed HTML-to-PDF tool by loading required wp-admin includes before calling `media_handle_sideload()`
- **PDF Bundle (March 13, 2026)** (PR #4206): Fixed PDF generation by bundling pdfkit, cheerio, docx, and exceljs into generate-*.bundle.js — no runtime node_modules needed on the server
- **WordPress Plugin Check (March 15, 2026)**: Fixed three plugin check errors — excluded `.gitattributes` hidden file from distribution ZIPs; included `composer.json` alongside `vendor/` directory; created `languages/` directory so `Domain Path: /languages` header resolves correctly


### Added - March 2026
- **Web Search: Tavily provider, geo/freshness params, snippet grounding (March 7, 2026)** (PR #4060): Enhanced the `web_search` tool with a new provider and richer result grounding
  - **Tavily Search provider**: AI-first search API purpose-built for LLM agent/RAG workflows; returns full page excerpts (`content`) and `published_date` per result; uses POST with `Authorization: Bearer`
  - **3 new tool schema parameters** (supported across all providers): `country` (ISO 3166-1 alpha-2), `language` (ISO 639-1), `freshness` (`pd`/`pw`/`pm`/`py`)
  - **Brave Search**: forwards `country` → `country`, `language` → `search_lang`, `freshness` → `freshness`; adds `extra_snippets=1` on every request; extra snippets now appended to primary description for richer context
  - **DuckDuckGo**: builds `kl` region param from `country` + `language` (e.g. `GB`+`en` → `kl=gb-en`)
  - **LLM grounding**: `sanitize_for_llm()` now includes a 40-word trimmed `snippet` alongside title+URL in the condensed payload
  - Admin: `tavily_api_key` wired into provider dropdown, settings defaults, field registration, simple-settings-saver password list, integrations section, tools configuration subtab, settings dashboard sensitive-keys masking, and overview connector count
  - 6 new unit tests in `tests/test-web-search-tool.php`; updated `test_sanitize_for_llm_condenses_results` to assert snippet presence
  - readme.txt: added External Service **#32 (Tavily Search API)** per WordPress.org Guideline 6
- **Gemini Corpus Native RAG (March 7, 2026)**: Added native Retrieval-Augmented Generation (RAG) to the Gemini chat client using the Google Semantic Retrieval API (PR #4082)
  - Injecting a `semanticRetriever` grounding tool into `generateContent` requests when `corpus_name` is set on an assistant
  - New `WP_MCP_AI_Gemini_Client` methods: `create_corpus()`, `list_corpora()`, `get_corpus()`, `delete_corpus()`, `query_corpus()`
  - New `build_corpus_request_args()` shared helper for all corpus HTTP requests
  - New constants: `API_CORPORA_ENDPOINT` and `API_BASE_URL` on `WP_MCP_AI_Gemini_Client`
  - New `sanitize_corpus_name_meta()` static method on `WP_MCP_AI_Assistant_CPT`; `META_CORPUS_NAME` stored in post meta key `_wp_mcp_ai_corpus_name`
  - REST validator (`class-wp-mcp-ai-rest-validator.php`) propagates `corpus_name` through sanitize_options()
  - New test suite: `tests/test-gemini-corpus-rag.php` (21 unit tests)
  - readme.txt updated: new External Services entry **2a** for the Gemini Semantic Retrieval API endpoint (`/v1beta/corpora`) per WordPress.org Guideline 6
- **Office 365 and iCloud Drive Connection Types (March 2026)**: Added new chat channel connection types for Office 365 and iCloud Drive (PR #3971)
  - **Office 365 – Outlook (2 tools)**:
    - `send_outlook_mail` - Send email via Microsoft Outlook using the Microsoft Graph API; supports plain text and HTML body, CC recipients
    - `get_outlook_messages` - Retrieve messages from any Outlook mail folder (inbox, sent, drafts, or custom) with OData filter support
  - **Office 365 – OneDrive (3 tools)**:
    - `list_onedrive_files` - List files and folders in a OneDrive drive via Microsoft Graph API
    - `get_onedrive_file` - Download a OneDrive file and return its contents
    - `upload_onedrive_file` - Upload a file to a OneDrive folder
  - **iCloud Drive (3 tools)** (via configurable gateway):
    - `list_icloud_drive_files` - List files and folders via an HTTPS iCloud gateway API
    - `get_icloud_drive_file` - Download a file from iCloud Drive via the gateway
    - `upload_icloud_drive_file` - Upload a file to iCloud Drive via the gateway
  - **New REST webhook controllers**:
    - `WP_MCP_AI_Outlook_Webhook_Controller` - Handles Office 365 Outlook subscription notifications
    - `WP_MCP_AI_iCloud_Webhook_Controller` - Handles iCloud Drive gateway push notifications
  - **Admin settings**: Office 365 and iCloud Drive configuration panels added to NV oOS → Chat Channels Toolkit settings page
  - **Chat Channels Toolkit**: Updated tool count from 39 to 47 tools across 11 platforms
  - **Security**: All tools validate bearer tokens, enforce HTTPS gateway URLs for iCloud, require `manage_options` capability (filterable), and log via `WP_MCP_AI_Logger`
- **Telegram Mini App Authentication Fix (March 1, 2026)**: Fixed Mini App stuck on "Authenticating" screen in Telegram WebView
  - Added TMA session token mechanism as auth fallback when `wp_set_auth_cookie()` cookies don't persist in Telegram WebView
  - Changed `check_permission()` from `edit_posts` to `read` for GET endpoints so subscriber-level Telegram users can access the app
  - Fixed `validateInitData()` infinite loop: function now rejects on failure and limits auto-retry attempts (PR #3971)

### Added - Late February 2026 (February 19 – March 1)
- **Telegram Mini App CMS Overhaul (February 28, 2026)**: Transformed Telegram Mini App from a basic chat shell into a full WordPress CMS interface (PR #3959)
  - Added REST endpoints for WordPress CPTs, tools, and media management within the Telegram WebView
  - Completely redesigned Mini App UI with navigation panels for content management
  - Users can now browse and manage WordPress content types directly from Telegram
- **Elementor Telegram Login Widget (February 27, 2026)**: New Elementor widget wrapping the `[mcp_ai_telegram_login]` shortcode (PR #3940)
  - Drag-and-drop Telegram Login button integration for Elementor-built pages
  - All shortcode settings exposed as Elementor widget controls
- **Discord/Telegram Reactions + Discord Voice Channel Members (February 27, 2026)**: OpenClaw Feb 2026 parity additions
  - Added `add_discord_message_reaction` tool – add emoji reactions to Discord messages
  - Added `add_telegram_message_reaction` tool – add emoji reactions to Telegram messages (Bot API 7.0+)
  - Added `get_discord_voice_channel_members` tool – list users currently in a Discord voice channel
  - Fixed `chat-channels-toolkit-init.php` to register all platform tools; added tests

### Fixed - Late February 2026 (February 19 – March 1)
- **WhatsApp Test Connection 403 Errors (February 20, 2026)**: Fixed multiple WhatsApp API 403 permission failures (PR #3818, #3819)
  - Isolated `quality_rating` field request into a non-fatal optional step; core connection test succeeds without it
  - Fixed field-permission error when verifying WhatsApp Business Account token
- **WhatsApp Auto-Reply Error #133010 + Messenger Enhancements (February 22, 2026)** (PR #3840)
  - Fixed WhatsApp Cloud API error `#133010` (message sending failure) in auto-reply flow
  - Enhanced Facebook Messenger chat channel settings: added App ID field, token generation, "Test Connection" button, API version dropdown, and more
- **WhatsApp/Messenger Webhook Processing Without App Secret (February 22, 2026)** (PR #3841)
  - Fixed assistant not responding to real messages when App Secret is not yet configured
  - Webhook processing now proceeds when `verify_webhook_signatures` is disabled or App Secret is blank
- **WhatsApp Group Routing (February 23, 2026)** (PR #3859)
  - AI auto-replies to WhatsApp group messages now route to the group thread instead of the individual sender
  - Implemented `group_id` routing logic in the webhook handler
- **Inbox CCT Registration Fix (February 23, 2026)** (PR #3860)
  - Fixed `channel_messages` and `channel_contacts` JetEngine Custom Content Types never being registered
  - Inbox messages now correctly stored and displayed after this fix
- **Embedded Chat Client System Prompt Fixes (February 23–25, 2026)** (PR #3878, #3880, #3899)
  - Fixed embedded LLM client not sending the system prompt and professional roles to the AI
  - Fixed stored system prompt not being injected when the caller omits it
  - Fixed system prompt silently dropped when `wp_kses_post()` preserves HTML tags inside the prompt; replaced `textarea.innerHTML` decode with `div.textContent` to correctly strip tags in all browsers
- **Google Chat HTTP 404 Test Connection + OAuth UX (February 24, 2026)** (PR #3879)
  - Fixed HTTP 404 error when testing Google Chat connection (wrong endpoint path)
  - Added missing service account key upload indicator in the admin UI
  - Improved OAuth button UX and feedback messaging
- **Google Chat Auto-Reply Improvements (February 25, 2026)** (PR #3898)
  - Bypassed native `@mention` check that was silently dropping AI auto-replies in Google Chat spaces
  - Added support for thread replies so bot responses appear in the correct conversation thread
  - Fixed OAuth welcome message not being sent on first bot installation
  - Added `send_reply` handler and OAuth support for test auto-reply scenarios
- **Google Chat Audience URL Clearable (February 27, 2026)**
  - Fixed Google Chat Audience URL field that could not be cleared due to the `verify_token` preservation logic
- **OpenAI File Attachment Errors (February 26, 2026)** (PR #3919)
  - Fixed `Unknown parameter: 'url'` and `Unknown parameter: 'file_name'` errors for file attachments in the OpenAI Responses API
  - Added full PDF file support in the attachment handling layer
  - Fixed `sse_tool_fatal_error` crash when using the Analyze Video tool with OpenAI provider
  - Removed unsupported `fps` parameter from the Sora video generation API payload
- **Facebook Messenger Connection Test Fix (February 28, 2026)** (PR #3958)
  - Fixed "Test Connection" button failure on the Messenger chat channel settings page

### Added - February 2026
- **JetEngine CPT/Taxonomy AI Integration (February 12, 2026)**: Comprehensive AI assistance for all JetEngine custom post types and taxonomies
  - **AI Assistant Metaboxes**: Automatically adds AI assistant metabox to all JetEngine CPT and taxonomy edit screens
  - **Research & Add Pages**: Creates dedicated Research & Add submenu pages for each JetEngine CPT with AI-powered content creation
  - **Automatic Field Mapping**: Dynamically maps all JetEngine meta fields (text, select, media, gallery, repeater, etc.) to form inputs
  - **Version Compatibility**: Full support for JetEngine 3.7+ with compatibility layer for different API versions
  - **Settings**: Two independent toggles - "Enable AI Assistant for JetEngine CPTs" and "Enable Research & Add Pages for JetEngine CPTs"
  - **Documentation**: Complete integration guide at [docs/features/integrations/jetengine-integration-guide.md](docs/features/integrations/jetengine-integration-guide.md)
  - **Testing**: Comprehensive test suite with 100% passing tests
  - Merge PR #3678
- **Package Pre-Bundling System (February 12, 2026)**: Enhanced vendor directory pre-bundling for critical npm packages
  - Added pdf-lib ^1.17.1 to vendor copy script for PDF manipulation capabilities
  - Added puppeteer-core ^21.0.0 to vendor copy script (optional) for advanced HTML rendering
  - Added core document generation packages: pdfkit, docx, exceljs, qrcode, turndown, cheerio
  - Updated package detection logic to check vendor directory before node_modules
  - Eliminates need for `npm install` on production servers, faster deployment
  - See [FEBRUARY_2026_UPDATES.md](docs/history/2026/implementations/FEBRUARY_2026_UPDATES.md)

### Fixed - February 2026
- **Product Research Page Rendering (February 10, 2026)**: Fixed admin hook detection pattern causing CSS/JS not to load on Product Consolidate page
  - Changed from CPT pattern `product_page_*` to custom menu pattern `wp-mcp-ai-ecommerce-toolkit_page_*`
  - See [docs/history/2026/fixes/product-page-admin-hook-detection-fix-2026-02-10.md](docs/history/2026/fixes/product-page-admin-hook-detection-fix-2026-02-10.md)
- **Product Research Tab System (February 11, 2026)**: Fixed all workflow tabs displaying simultaneously
  - Changed hook matching to flexible strpos() check for reliability
  - Added inline display:none styles for defensive fallback
  - Enhanced CSS specificity with !important rules to prevent override
  - See [docs/history/2026/fixes/product-research-tab-system-fix-2026-02-11.md](docs/history/2026/fixes/product-research-tab-system-fix-2026-02-11.md)
- **Product Research CSS/JS Loading (February 11, 2026)**: Improved asset enqueuing priority and hook detection
- **Duplicate Menu Item (February 10, 2026)**: Removed duplicate "Research & Add" tab from E-commerce Toolkit settings page
- **Pro Workflow Builder Stability (February 4-5, 2026)**: Multiple fixes for React-based workflow builder
  - Fixed React asset loading and initialization issues
  - Fixed double instantiation causing duplicate DOM elements
  - Fixed initialization timing race conditions
  - Fixed menu placement inconsistencies
  - Fixed empty page display issue
  - See quick reference: `docs/history/2026/fixes/pro-workflow-builder-fix-quick-reference-2026-02-05.md`
- **OAuth & API Connections (February 3, 2026)**:
  - Fixed Google OAuth approval prompt not displaying to users
  - Fixed Yahoo OAuth redirect URL construction issues
  - Fixed Mailjet API authentication credential handling
- **Admin Menu Priority (February 4, 2026)**: Adjusted menu priority values for consistent ordering across admin interface
- **E-commerce Toolkit (February 10, 2026)**: Now enabled by default for new installations to reduce setup friction

### Documentation - February 2026
- Added comprehensive February 2026 updates summary (`docs/history/2026/implementations/FEBRUARY_2026_UPDATES.md`)
- Added detailed fix documentation for all product research page issues in `docs/fixes/`
- Added Pro Workflow Builder fix quick reference guide with visual flow diagrams
- Archived completed fix summaries to `archive/2025/fixes/` (product research, tab system, variable products)

### Slash Commands & Workflow System
- **Slash Commands Implementation - Phase 1 Complete (February 3, 2026)**: Comprehensive slash command system for content management, optimization, and workflow automation
  - **Core Components**: Parser, Handler, Validator, Audit, Performance Optimizer, Workflow Orchestrator
  - **8 Implemented Commands**: `/help`, `/next-task`, `/ship`, `/clean-content`, `/optimize-perf`, `/sync-docs`, `/workflow`
  - **Features**: Command chaining, conditional logic, error handling, parameter validation, result passing between commands
  - **Workflow System**: Multi-step workflow execution with state management and human-in-the-loop checkpoints
  - **Integration**: JavaScript autocomplete, REST API endpoint (`/wp-json/mcp-ai/v1/slash-command`), WP-CLI support
  - **Security**: Capability-based authorization, rate limiting (10 commands/minute), comprehensive logging
  - **Test Coverage**: 45+ test cases covering all commands and workflow functionality
  - **Documentation**: Complete implementation guide with usage examples
  - See [SLASH_COMMANDS_GUIDE.md](docs/user-guides/slash-commands/SLASH_COMMANDS_GUIDE.md)

- **Pro Toolkit Slash Commands - Phase 2 Complete (February 4, 2026)**: Specialized commands for pro toolkits with automated workflows
  - **21 Commands Implemented**: 
    - E-commerce (6): `/upsell-suggest`, `/abandoned-recover`, `/ecom-analytics`, `/discount-optimize`, `/inventory-forecast`, `/customer-segment`
    - Social Media (6): `/hashtag-suggest`, `/social-analytics`, `/social-schedule`, `/content-calendar`, `/competitor-track`
    - Video Production (6): `/video-subtitle`, `/video-template`, `/video-analytics`, `/video-merge`, `/video-thumbnail`, `/video-compress`
  - **7 Automated Workflows**:
    - Abandoned Cart Recovery Campaign (3 steps)
    - Multi-Platform Social Media Campaign (3 steps)
    - Video Marketing Production (3 steps)
    - E-Commerce Upsell Optimization (2 steps)
    - E-Commerce Inventory Management (3 steps)
    - Social Content Planning (3 steps)
    - Video Post Production (3 steps)
  - **Tool Integration**: Seamless integration with existing pro toolkit tools
  - **Requirements**: WooCommerce for e-commerce commands; appropriate user capabilities
  - **Test Coverage**: 50+ test methods across 4 test files
  - **Documentation**: Complete command reference with workflow examples
  - See [PRO_TOOLKIT_SLASH_COMMANDS.md](docs/user-guides/slash-commands/PRO_TOOLKIT_SLASH_COMMANDS.md)

### Chat Channels & WebChat Integration
- **Chat Channels Toolkit - Production Ready (February 3, 2026)**: Comprehensive integration with 6 major chat platforms
  - **21 Tools Implemented**: 
    - Telegram (3 tools): `send_telegram_message`, `get_telegram_updates`, `manage_telegram_webhook`
    - WhatsApp (3 tools): `send_whatsapp_message`, `send_whatsapp_template`, `get_whatsapp_messages`
    - Slack (4 tools): `send_slack_message`, `get_slack_channels`, `get_slack_messages`, `create_slack_channel`
    - Discord (4 tools): `send_discord_message`, `get_discord_channels`, `get_discord_messages`, `create_discord_channel`
    - Microsoft Teams (3 tools): `send_teams_message`, `get_teams_channels`, `get_teams_messages`
    - Facebook Messenger (3 tools): `send_messenger_message`, `get_messenger_conversations`, `create_messenger_broadcast`
    - Unified Hub (1 tool): `unified_channel_broadcast` - Broadcast across multiple platforms simultaneously
  - **Admin Interface**: Comprehensive settings page at NV oOS → Chat Channels Toolkit with platform setup guides
  - **Authentication**: Secure API credential management with platform-specific configuration
  - **Testing**: PHP validation, PHPCS compliance, CodeQL security scan passed
  - **Documentation**: Complete implementation guides and troubleshooting
  - See [CHAT_CHANNELS_TOOLKIT.md](addons/pro/addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md) and [CHAT_CHANNELS_README.md](addons/pro/addons/pro/docs/CHAT_CHANNELS_README.md)

- **WebChat Rooms - Production Ready (February 2026)**: Real-time collaborative chat rooms with AI assistant integration
  - **Custom Post Type**: `mcp_ai_webchat` for room management
  - **AI Assistant Assignment**: Dedicated metabox for assigning assistants to specific rooms
  - **Message Persistence**: JetEngine Custom Content Types integration for permanent message storage
  - **WebRTC Support**: Self-hosted WebRTC signaling via WordPress REST API
  - **3 Core Tools**: 
    - `create_webchat_room` - Create new WebChat rooms
    - `get_webchat_messages` - Retrieve room message history
    - `save_webchat_message` - Save messages to room history
    - `send_webchat_message` - Send messages to WebChat rooms
  - **Admin Interface**: WebChat settings page with room management and configuration
  - **Security**: Capability-based access control, nonce verification, proper sanitization
  - **Documentation**: Complete setup guide, troubleshooting, and assistant assignment docs
  - See [WEBCHAT_ASSISTANT_ASSIGNMENT.md](addons/pro/addons/pro/docs/WEBCHAT_ASSISTANT_ASSIGNMENT.md) and [WEBCHAT_TROUBLESHOOTING.md](addons/pro/addons/pro/docs/WEBCHAT_TROUBLESHOOTING.md)

### Toolkit Enhancements
- **Pro Toolkit Infrastructure - Phase 3 Complete (January 22, 2026)**: Comprehensive settings infrastructure for all pro toolkits
  - **13 Active Toolkits**: E-commerce (20 tools), Social Media (19 tools), Analytics (12 tools), Financial Planner (24 tools), Calendar Booking (15 tools), DJ Management (18 tools), Image Production (15 tools), Document Generation (3 tools), Multilingual (10 tools), Video Production (12 tools), AI Tool Builder (10 tools), Architectural Design (16 tools), CRM (1 tool)
  - **Total Pro Tools**: 175 tools across 13 specialized domains
  - **Settings Features**: Overview tabs, configuration tabs, provider setup, research & add capabilities, remote sites support
  - **Multi-Agent Support**: Each toolkit can have dedicated AI assistant; domain-specific specialization
  - **Memory-Based Tracking**: Replaced hard toolkit limits with transparent memory usage tracking
  - **Test Coverage**: Comprehensive test suite for all toolkit features
  - **Documentation**: Complete toolkit architecture and implementation guides
  - See [TOOLKIT_ENHANCEMENT_FINAL_SUMMARY.md](docs/history/2026/implementations/TOOLKIT_ENHANCEMENT_FINAL_SUMMARY.md)

- **Toolkit Enhancement System - Complete (January 30, 2026)**: Advanced toolkit registry and pattern-based orchestration
  - **12 Toolkit Categories**: Comprehensive taxonomy system with metadata-driven tool discovery
  - **8 Multi-Agent Patterns**: Specialized patterns for research, content, e-commerce, development, customer service, analytics, creative, operations
  - **Pattern Workflow Templates**: 8 predefined workflow templates with customization support
  - **12 Core Classes**: ~10,000 LOC implementing registry, patterns, workflows, and integration layer
  - **Test Coverage**: 79 tests across 5 test files (100% passing)
  - **Documentation**: 150KB+ including technical specs, executive summaries, and visual guides
  - See [TOOLKIT_ARCHITECTURE_BEFORE_AFTER.md](docs/admin-guides/TOOLKIT_ARCHITECTURE_BEFORE_AFTER.md)

### Repository Organization
- **Repository Root Cleanup (February 2, 2026)**: Archived historical status files and reorganized structure
  - **Archive Created**: New `archive/` directory with three subdirectories for historical documentation
    - `archive/development-phases/` - Phase completion files (PHASE_2-4_COMPLETE.md, WPCS_RESTORATION_PLAN.md)
    - `archive/production-status/` - Production readiness files (PRODUCTION_READY.md, etc.)
    - `archive/wordpress-org-submission/` - Submission verification files
  - **Tool Status Moved**: `tool-status.txt` relocated from root to `docs/tool-status.txt`
    - Updated 2 PHP files to reference new location (class-wp-mcp-ai-section-tools.php, class-wp-mcp-ai-tool-registry.php)
    - Updated 5 documentation files with corrected paths
  - **Result**: Root directory now contains only 4 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, readme.txt)
  - **Benefits**: Cleaner repository presentation; historical context preserved; improved maintainability

### Security
- **Security Hardening (January 29, 2026)**: Fixed 4 critical and high severity vulnerabilities
  - **SSRF in Webhook Registration (Critical)**: Fixed webhook URL validation to block private IP ranges, AWS metadata endpoints, and restrict to http/https protocols only
    - File: `includes/class-wp-mcp-ai-job-notifier.php`
    - Impact: Prevents Server-Side Request Forgery attacks on internal networks and cloud metadata services
    - Added protocol validation, IP range filtering (RFC 1918, loopback, link-local), and WordPress `wp_http_validate_url()` checks
  - **Broken CSRF Protection (Critical)**: Fixed AJAX refresh rendering non-functional delete links in Cron Manager
    - File: `assets/js/admin-cron-manager.js`
    - Impact: Restores delete functionality with proper nonce-based CSRF protection after page refresh
    - Solution: Render complete HTML form with hidden nonce field instead of broken link
  - **XSS in Error Messages (High)**: Fixed unescaped error messages in admin AJAX handlers
    - Files: `assets/js/admin-cron-manager.js`, `assets/js/admin-crawl4ai-monitor.js`
    - Impact: Eliminates XSS attack vector in admin error message display
    - All error messages now escaped via `escapeHtml()` before DOM insertion
  - **Missing Authorization (High)**: Implemented comprehensive job authorization across multiple entity types
    - File: `includes/class-wp-mcp-ai-job-notifier-rest.php`
    - Impact: Prevents users from accessing other users' job data via ID enumeration
    - Added `is_user_authorized_for_job()` with 7 authorization paths (admin, user, assistant, team, profession, agent, virtual agent)
  - **Security Report**: Complete vulnerability analysis and remediation guide in `docs/operations/security/CODE_REVIEW_SECURITY_FINDINGS_2026-01-29.md`
  - **Files Changed**: 4 files modified, ~400 lines of security hardening code added
  - **Result**: 100% of critical/high vulnerabilities resolved; 2 medium severity issues remain (CORS policy, rate limiting)

### Added
- **Comprehensive Entity Tracking (January 29, 2026)**: Added tracking for 11 entity types in job metadata
  - **New Helper Method**: `ensure_tracking_ids()` automatically captures all relevant context IDs
  - **Tracked Entities**: user_id, assistant_id, team_id, profession_id, agent_id, agent_role, virtual_agent_id, virtual_id, workflow_id, parent_job_id, profession_slug
  - **Applied To**: All job event handlers (started, progress, completed, failed)
  - **Benefits**: Complete audit trail for multi-agent workflows; essential for debugging complex orchestrations; supports team collaboration and multi-tenant scenarios
  - **Files**: `includes/class-wp-mcp-ai-job-notifier.php` (tracking logic), `includes/class-wp-mcp-ai-job-notifier-rest.php` (authorization)

- **Multi-Level Job Authorization (January 29, 2026)**: Implemented flexible authorization system for job access
  - **Authorization Paths**: 7 different ways to authorize job access
    1. Admin capability (`manage_options`)
    2. Direct user ownership (`user_id` match)
    3. Assistant ownership (user owns the assistant)
    4. Team membership (user is team owner or member)
    5. Profession ownership (user owns the profession)
    6. Agent ownership (user owns the agent)
    7. Virtual agent (user is member of parent team)
  - **Enforced In**: `handle_job_status()` and `handle_job_stream()` REST API endpoints
  - **Benefits**: Proper multi-tenant isolation; team collaboration support; flexible access control for complex workflows

- **DeepSeek V4 Agent Memory Tools (January 29, 2026)**: Phase 4/5 state management and memory enhancements
  - **store_agent_context Tool**: Stores important context, learnings, or information for agents to remember across sessions
    - Supports 10 context types: learning, fact, preference, pattern, workflow, decision, result, insight, note, generic
    - Configurable TTL (1 hour to 1 year, default 30 days)
    - Importance levels: low, medium, high, critical
    - Tag-based categorization for easy retrieval
    - Uses WordPress transients for storage with automatic index maintenance
  - **retrieve_agent_memory Tool**: Retrieves previously stored agent context with advanced search
    - Specific context ID retrieval for exact lookup
    - Semantic search with query matching and relevance scoring
    - Advanced filtering: context types, tags, importance levels, date ranges
    - Results ranked by importance and relevance (0-1 scale)
    - Configurable result limits (1-50 contexts)
    - Optional inclusion of expired contexts
  - **Integration**: Added to `agentic_workflow`, `general_purpose`, and `operations_management` tool presets
  - **Test Coverage**: Comprehensive PHPUnit test suite with 12 test methods validating storage, retrieval, search, filtering, and expiration
  - **Documentation**: Complete tool documentation in DEEPSEEK-V4-README.md and DEEPSEEK-V4-USAGE-GUIDE.md
  - These tools complete the DeepSeek V4 Phase 4/5 implementation for persistent agent memory and state management

### Documentation
- **Root Directory Documentation Consolidation (January 29, 2026)**: Major cleanup of root directory documentation
  - **Implementation Summaries**: Moved 19 implementation summary files to `docs/history/2026/`
    - Admin pages enhancement summaries (3 files)
    - AJAX test implementation summary
    - Chat job status SSE implementation
    - DeepSeek V4 completion summary
    - Site Creator implementation summaries (9 files)
    - System status summaries (2 files)
    - Task summary
    - Generic enhancement and implementation summaries
  - **Deployment Documentation**: Moved 2 deployment guides to `docs/operations/deployment/`
    - PRODUCTION_DEPLOYMENT.md
    - PRODUCTION_READY.md
  - **Security Documentation**: Moved 1 security report to `docs/operations/security/`
    - SECURITY_COMPLIANCE_REPORT.md
  - **Root Directory**: Now contains only 6 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md, DEPENDENCIES_BUNDLING.md) plus 2 supporting files (readme.txt, tool-status.txt)
  - **Updated**: DOCUMENTATION.md to reflect new organization structure
  - **Result**: Cleaner root directory; implementation history properly organized by year; no information lost; improved maintainability

- **Documentation Consolidation (January 22, 2026)**: Organized and consolidated root-level documentation
  - **Menu Fixes**: Consolidated 6 menu-related documents into single comprehensive guide at `docs/history/2026/fixes/menu-fixes/MENU_FIXES_CONSOLIDATED.md`
    - Removed: `MENU_FIX_SUMMARY.md`, `MENU_REORGANIZATION_SUMMARY.md`, `MENU_STRUCTURE_VISUAL.md`, `REMOTE_SITES_MENU_FIX.md`, `REMOTE_SITES_MENU_FIX_VISUAL.md`, `PR_SUMMARY.md` (temporary)
    - Consolidated: All menu structure fixes, Remote Sites reorganization, visual diagrams, and testing guidelines
  - **Feature Documentation**: Moved `TOOLKIT_MEMORY_TRACKING.md` to `docs/features/` for better organization
  - **Result**: Cleaner root directory; all related documentation in appropriate docs/ subdirectories; no information lost

### Changed
- **Pro Toolkit Memory-Based Tracking System (January 22, 2026)**: Replaced hard toolkit count limit with transparent memory-based tracking
  - **Previous**: Hard limit of 5 pro toolkits; checkboxes disabled when limit reached; artificial restriction
  - **New**: Memory-based tracking showing estimated MB usage; no hard limits; all toolkits can be enabled
  - **UI Changes**: "Pro Toolkit Memory Usage" heading; displays "X MB estimated memory usage (Y toolkits enabled)"; status badges (Low/Moderate/High Usage)
  - **Memory Requirements**: 20 toolkits mapped to memory usage (24 MB - 256 MB range); total 1,844 MB if all enabled
  - **Status Thresholds**: Low (<500MB), Moderate (500-799MB), High (≥800MB) - informational only, no enforcement
  - **JavaScript**: Real-time memory calculation; dynamic counter updates; no checkbox disabling
  - **Benefits**: Transparency for resource planning; flexibility without artificial limits; informed decision-making
  - **Files Changed**: `includes/admin/sections/class-wp-mcp-ai-section-tools.php` (152 lines changed), `tests/test-section-tools.php` (70 lines added)
  - **Documentation**: Complete implementation guide in [TOOLKIT_MEMORY_TRACKING.md](docs/features/TOOLKIT_MEMORY_TRACKING.md)

### Added
- **DeepSeek V4 Multi-Agent Orchestration Enhancement (January 2026)**: Comprehensive multi-agent coordination framework inspired by DeepSeek V4's orchestration patterns
  - **Agent Role System**: Four specialized roles (Planner, Executor, Critic, Specialist) with role-specific capabilities and workflows
  - **Agent Team Orchestrator**: Manages team composition, coordinated workflow execution, and performance tracking (921 lines)
  - **Agent Communication Service**: Structured message passing and result aggregation with 5 aggregation strategies (consensus, weighted, hierarchical, first, best)
  - **Agent Coordination Tools**: Three new MCP-compliant tools (`create_agent_team`, `delegate_to_agent`, `aggregate_agent_results`)
  - **Profession CPT Integration**: 8 new orchestration meta fields for agent roles, capabilities, task patterns, and performance metrics
  - **Team CPT Integration**: 3 new orchestration meta fields for execution modes (single/sequential/parallel/swarm), workflow templates (JSON), and aggregation strategies
  - **Orchestration Seeder**: Intelligent agent role assignment for 200+ professions with WP-CLI commands (`wp profession seed-orchestration`, `wp profession orchestration-stats`)
  - **Multi-Agent Workflows**: Predefined team templates for research, content, e-commerce, and development workflows
  - **Implementation Status**: 85-90% complete with comprehensive test suite (12 PHPUnit tests, 9 integration tests)
  - **Documentation**: Complete documentation suite (55.3KB across 6 files) including usage guide, validation results, and workflow examples
  - **PHP Workaround Extension**: Extends core orchestration layer's "persistent-behavior illusion" to enable distributed agent coordination in stateless PHP environment
  - See [ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/developer/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md#-6-multi-agent-orchestration-deepseek-v4-inspired-enhancement) for complete technical documentation
  - See [DEEPSEEK-V4-README.md](docs/reference/models/DEEPSEEK-V4-README.md) for documentation suite overview

### Fixed
- **Token Manager Save Issue (January 21, 2026)**: Fixed tool settings not persisting despite success messages
  - **Root Cause**: Triple-sanitization in AJAX handler causing data loss (array structure lost after multiple sanitization passes)
  - **Solution**: Removed redundant sanitization in save loops; single sanitization point in setter methods
  - **Impact**: All tool limits, multipliers, and model preferences now save correctly
  - **Files Changed**: `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` (61 lines changed)
  - **Testing**: Manual testing verified all settings persist across page reloads
  - See [docs/history/2026/fixes/token-manager-save-issue-fix-2026-01-21.md](docs/history/2026/fixes/token-manager-save-issue-fix-2026-01-21.md)

- **Provider Keys Clearing on Tab Navigation (January 20, 2026)**: Fixed API keys being cleared when navigating between admin tabs
  - **Root Cause**: Double-sanitization via WordPress Settings API callback on `update_option()` clearing sensitive data
  - **Solution**: Removed `sanitize_callback` from `register_setting()`; manual sanitization only in save handler
  - **Impact**: Provider configurations persist across tab navigation; no data loss
  - **Files Changed**: `includes/admin/class-wp-mcp-ai-admin-settings.php`
  - See [docs/history/2026/fixes/provider-keys-clearing-fix-2026-01-20.md](docs/history/2026/fixes/provider-keys-clearing-fix-2026-01-20.md)

- **Unified Team Transcript Recording (January 18, 2026)**: Fixed transcripts failing to save for unified team chats and individual member chats
  - **Root Causes**: Missing pattern recognition for team member assistant IDs; endpoint validation only accepting integers
  - **Solution**: Updated `extract_profession_id()` to recognize both `profession_XXX` and `team_XXX_member_YYY` patterns; changed REST endpoint to accept string assistant IDs
  - **Impact**: Transcripts save correctly for all team chat types (unified_team_*, team_*_member_*)
  - **Files Changed**: `includes/class-wp-mcp-ai-transcript-manager.php`, REST endpoint registration
  - See [docs/history/2026/fixes/unified-team-transcript-recording-fix-2026-01-18.md](docs/history/2026/fixes/unified-team-transcript-recording-fix-2026-01-18.md)

- **Tool Preset Multiplier Application (January 18, 2026)**: Fixed broken "Apply Preset" button on Token Manager page (PR #2990)
  - **Root Cause**: `get_all_recommendations()` only queried tool registry which returned empty array during preset application
  - **Solution**: Modified method to iterate through `$tool_categories` static property first (200+ tools), then check registry for dynamic tools
  - **Impact**: Preset application now works correctly for Conservative, Balanced, Performance, and Aggressive presets
  - **Files Changed**: `includes/class-wp-mcp-ai-tool-recommendations.php` (refactored into 2 new private helper methods)
  - **Testing**: Comprehensive manual testing plan in `docs/history/2026/fixes/TOOL_PRESET_MULTIPLIER_TESTING_PLAN.md`
  - **Documentation**: Complete fix details in `docs/history/2026/fixes/TOOL_PRESET_MULTIPLIER_FIX.md`
  - Broke after PR #2984 which updated tool recommendations system
  - Zero security vulnerabilities introduced, maintains backward compatibility
  - Better code organization and maintainability

- **HuggingFace Model Max Completion Tokens (January 17, 2026)**: Fixed Qwen3-Coder model failing with "max_completion_tokens limited to 8192" error
  - **Root Cause**: Using old `max_tokens` parameter instead of OpenAI-compatible `max_completion_tokens`; Resource Manager could request up to 32,000 tokens
  - **Solution**: Updated `WP_MCP_AI_Huggingface_Client::build_payload()` to use `max_completion_tokens`; added model-specific limits in `WP_MCP_AI_Model_Config`
  - **Impact**: Qwen models now work correctly with proper token limits enforced
  - **Files Changed**: `includes/class-wp-mcp-ai-huggingface-client.php`, `includes/class-wp-mcp-ai-model-config.php` (added 4 Qwen models with limits)
  - **Tests**: 5 test cases added to verify the fix
  - See [docs/history/2026/fixes/huggingface-max-completion-tokens-fix-2026-01-17.md](docs/history/2026/fixes/huggingface-max-completion-tokens-fix-2026-01-17.md)

- **OAuth Redirect URI Mismatch (January 17, 2026)**: Fixed Gmail OAuth failing with `redirect_uri_mismatch` error
  - **Root Cause**: Inconsistent URL construction in OAuth flow (direct query string concatenation vs. WordPress URL helpers)
  - **Solution**: Standardized redirect URI generation using WordPress's `add_query_arg()` instead of direct concatenation
  - **Impact**: OAuth flows now consistent across all WordPress installations (subdirectory, subdomain, custom ports)
  - **Files Changed**: `includes/integrations/class-wp-mcp-ai-oauth-manager.php`, `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`
  - See [docs/history/2026/fixes/oauth-redirect-uri-mismatch-fix-2026-01-17.md](docs/history/2026/fixes/oauth-redirect-uri-mismatch-fix-2026-01-17.md)

- **Model Dropdown in Base + Pro Mode (January 16, 2026)**: Fixed model dropdown failing when both base and pro plugins active
  - **Root Cause**: Script localization lost when multiple metaboxes enqueued same script (two separate plugin instances)
  - **Solution**: Created `WP_MCP_AI_Admin_Scripts` class for global script registration with consistent localization (priority 5 on `admin_enqueue_scripts`)
  - **Impact**: Model dropdown works in all deployment modes (cloned repo, base+pro separate plugins, base only)
  - **Files Changed**: NEW `includes/admin/class-wp-mcp-ai-admin-scripts.php` (91 lines), updated 3 metabox files
  - **Code Improvement**: Simplified from 54 to 17 lines net reduction through centralization
  - See [docs/history/2026/fixes/model-dropdown-base-pro-mode-fix-2026-01-16.md](docs/history/2026/fixes/model-dropdown-base-pro-mode-fix-2026-01-16.md)

- **Audio Transcription MIME Type (January 11, 2026)**: Fixed transcription button creating video files instead of audio files
  - Added `getSupportedAudioMimeType()` helper function to check browser support
  - MediaRecorder now explicitly requests audio-only MIME types (audio/webm, audio/ogg, etc.)
  - Prefers audio formats over video container formats to avoid confusion
  - Maintains backward compatibility with fallback to video/webm if needed
  - Affects both transcribe and voice chat recording features
  - OpenAI Whisper API accepts both audio and video files with audio tracks

### Added
- **Pro Toolkit Infrastructure - Phase 3 Complete (January 15-22, 2026)**: Implemented comprehensive settings infrastructure for all 13 Pro toolkits
  - **13 Active Toolkits**: E-commerce (20 tools), Social Media (19 tools - updated January 2026 with 4 new analytics tools), Analytics (12 tools), Multilingual (10 tools), Video Production (12 tools), Financial Planner (24 tools), Document Generation (3 tools), Calendar Booking (15 tools), DJ Management (18 tools), Image Production (15 tools), AI Tool Builder (10 tools), Architectural Design (16 tools), CRM (1 tool)
  - **Total Pro Tools**: 175 tools across 13 specialized domains
  - **0 Planned Toolkits**: All previously "planned" toolkits (Calendar, DJ, Image Production, AI Tool Builder) are actually fully implemented
  - **Settings Features**: Overview tabs, configuration tabs, provider setup, research & add capabilities, remote sites support, WP-CLI integration
  - **Multi-Agent Functionality**: Each toolkit can have dedicated AI assistant; up to 5 concurrent agents (one per active toolkit)
  - **Specialization**: Domain-specific agents (product expert, content creator, translator, video editor, financial advisor, DJ, architect, etc.)
  - See [docs/history/2026/january/PHASE_3_IMPLEMENTATION_COMPLETE.md](docs/history/2026/january/PHASE_3_IMPLEMENTATION_COMPLETE.md)

- **Social Media Analytics Tools (January 15-22, 2026)**: Added 4 new analytics tools to Social Media Toolkit
  - **Get Cross-Platform Analytics** (`get_cross_platform_analytics`) - Unified metrics dashboard aggregating data from multiple platforms (623 lines)
  - **Track Hashtag Performance** (`track_hashtag_performance`) - Hashtag analysis with reach, engagement, and trend data (586 lines)
  - **Competitor Analysis** (`analyze_competitor_social`) - Track competitor metrics and compare performance (711 lines)
  - **Influencer Identification** (`identify_influencers`) - Find brand influencers based on reach and engagement criteria (759 lines)
  - All tools support Facebook, Instagram, Twitter, LinkedIn, and YouTube platforms
  - Built-in caching for performance (12-hour default)
  - Comprehensive error handling and validation
  - See Social Media Toolkit settings page for configuration

- **Cloudflare Image Generation Models (January 11, 2026)**: Added support for new Cloudflare Workers AI image generation models (PR #2785)
  - **Flux-2 Dev** (`@cf/black-forest-labs/flux-2-dev`) - Advanced image generation model
  - **Leonardo AI Models**: Lucid Origin (`@cf/leonardo/lucid-origin`) and Phoenix 1.0 (`@cf/leonardo/phoenix-1.0`)
  - All models support configurable dimensions (256-2048px), diffusion steps (1-20), and guidance parameters
  - Compatible with existing `cloudflareai_text_to_image` tool
  - Join Stable Diffusion XL Base/Lightning, Flux-1 Schnell, and Dreamshaper 8 LCM models
  - See [Cloudflare Image Generation Tool](includes/tools/class-wp-mcp-ai-tool-generate-cloudflareai-image.php)

- **ISO 27001/SOC 2/HIPAA Compliance - January 6, 2026**: Achieved 100% ISO 27001:2022 compliance (PR #2645, #2631, #2630)
  - ISO 27001: 100% (83 of 83 applicable controls) - up from 56%
  - SOC 2: 100% (54 of 54 Trust Services Criteria)
  - HIPAA: 98% (42 of 43 Security Rule safeguards)
  - ~90KB documentation across 14 comprehensive procedures
  - Dynamic compliance dashboard calculations
  - Complete control mappings for all three frameworks
  - See [Weekly Summary](docs/history/2026/WEEKLY_SUMMARY_2026-01-06.md)

- **Pro CPT Documentation - January 6, 2026**: Created comprehensive documentation for Pro custom post types
  - Events, Quizzes, and Places CPT overview (21 tools total)
  - Events: 5 tools including Google Calendar integration
  - Quizzes: 9 tools with JetEngine CCT integration
  - Places: 7 tools with Google Places API integration
  - See [PRO_CPT_OVERVIEW.md](docs/features/pro-cpt/PRO_CPT_OVERVIEW.md)

### Documentation
- **Code Review Documentation (January 18, 2026)**: Comprehensive review of January 11-18 changes
  - Reviewed 5 major changes: Token Manager fix, Provider Keys fix, OAuth fix, HuggingFace fix, Model Dropdown fix
  - All changes passed security and quality checks
  - Status: Production ready
  - See [docs/history/2026/CODE_REVIEW_DOCUMENTATION_UPDATE_2026-01-18.md](docs/history/2026/CODE_REVIEW_DOCUMENTATION_UPDATE_2026-01-18.md)

- **Root Directory Reorganization (January 13, 2026)**: Cleaned up root directory by moving documentation files
  - Moved 20+ markdown files to organized subdirectories
  - Root now contains only 5 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md)
  - Files organized into: `docs/fixes/`, `docs/history/2026/`, `docs/implementation-summaries/`
  - Zero information loss during reorganization
  - See [docs/history/2026/ROOT_DIRECTORY_ORGANIZATION_2026-01-13.md](docs/history/2026/ROOT_DIRECTORY_ORGANIZATION_2026-01-13.md)

- **Fix Documentation (January 15-21, 2026)**: Created comprehensive documentation for all recent fixes
  - 6 detailed fix documentation files created
  - Each includes root cause analysis, solution details, testing verification
  - Total documentation: ~12KB across fix documentation files
  - All fixes cross-referenced in CHANGELOG.md

### Changed
- **Pro Dashboard Modernization - January 6, 2026**: Refactored Pro Dashboard with industry-standard patterns (PR #2641)
  - Implemented Singleton pattern with lazy initialization
  - Added type-safe class constants for delegates
  - Centralized delegate management with configuration-driven approach
  - Enhanced error handling and observability
  - Public API for delegate access
  - 100% backward compatible
  - See [INDUSTRY_STANDARDS_ENHANCEMENTS.md](docs/history/2026/implementation-summaries/INDUSTRY_STANDARDS_ENHANCEMENTS.md)

- **Text Domain Migration - January 6, 2026**: Complete migration from wp-mcp-ai to mcp-ai-wpoos (PR #2635)
  - Updated 12,773 instances across PHP and JavaScript
  - Separate text domains: mcp-ai-wpoos (main), mcp-ai-wpoos-pro, mcp-ai-wpoos-core, mcp-ai-wpoos-base
  - Zero references to old domain remain
  - Enables proper POT file generation

- **Documentation Organization - January 6, 2026**: Root directory cleanup (PR #2644)
  - Moved 25 markdown files to organized subdirectories
  - Root now contains only 5 essential files (83% reduction)
  - Files organized into: implementation-summaries/, fixes/, visual-guides/, troubleshooting/
  - Fixed 2 incorrect local file paths in plugin code
  - Zero broken links

- **Production Deployment - January 6, 2026**: Removed dev dependencies from vendor (PR #2638)
  - Executed `composer install --no-dev`
  - Repository ready for production cloning
  - Dev tools reinstallable via `composer install` when needed

### Fixed
- **Hugging Face Model Pricing - January 8, 2026**: Fixed $0 cost display for Hugging Face models
  - Added DeepSeek-V3.2 pricing ($0.28 input / $0.42 output per 1M tokens)
  - Added default fallback pricing for unknown models ($0.50 per 1M tokens)
  - Updated `get_model_pricing()` to support default pricing for Huggingface (similar to ollama/lm_studio)
  - Included pricing for 6 additional models: Llama 3.3 70B, Llama 3.1 8B, Mistral 7B, Phi-3 Mini, Qwen 2.5 72B, Qwen 2.5 7B
  - Pricing ranges from $0.10 to $1.00 per 1M tokens (input/output)
  - Added comprehensive test coverage for Hugging Face cost calculations
  - Resolves issue where DeepSeek-V3.2 and other unknown models showed no cost information
  - See `includes/class-wp-mcp-ai-cost-calculator.php` (lines 295-337)

- **PM Assistant Fixes - January 6, 2026**: Six critical modal and chat fixes (PRs #2629, #2632, #2633, #2636, #2637, #2626)
  - Modal Rendering: Added missing CSS for proper overlay display
  - Chat Localization: Ensured wpMcpAiChat global availability
  - Nested Form Fix: Changed form structure for WordPress compatibility
  - Validation Blocking: Always render modal HTML with error messages
  - Diagnostics: Added version tracking and debug logging
  - HTML5 Validation: Removed conflicting required attributes

- **WordPress 6.7+ Translation Compatibility - January 6, 2026**: Fixed translation loading timing (PRs #2640, #2639)
  - Moved 4 registration functions from `init` to `admin_init`
  - Removed translation functions from plugin action links
  - Eliminated WordPress 6.7+ timing warnings

- **Code Review - January 2, 2026**: Comprehensive code review of all features and tools
  - Overall grade: A- (92/100) - Production ready
  - Security: 10/10 - Zero vulnerabilities found
  - JavaScript: 10/10 - ESLint passes cleanly (0 errors)
  - PHP Code Style: 7.5/10 - 1,083 errors, 1,294 warnings (235 auto-fixable)
  - Architecture: 9.5/10 - Clean design patterns maintained
  - Documentation: 9.5/10 - 659 comprehensive files
  - Test Coverage: 8.5/10 - 565 test files
  - Tool inventory verified: 217 tool files (151 base + 66 Pro)
  - See [CODE_REVIEW_2026-01-02.md](docs/history/2025/code-reviews/CODE_REVIEW_2026-01-02.md)

### Changed
- **Root Directory Organization**: Cleaned up root directory by moving troubleshooting documentation files (January 10, 2026)
  - Moved `CLOUDFLARE-SYSTEM-PROMPT-TEST.md` from root to `docs/operations/troubleshooting/common/`
  - Moved `MODEL-MANAGER-FIX-VERIFICATION.md` from root to `docs/operations/troubleshooting/common/`
  - Root directory now contains only 5 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md)
  - Zero information loss during reorganization

- **Root Directory Organization**: Cleaned up root directory by moving fix and implementation summary files (PR #XXXX)
  - Moved 6 remote connection fix files from root to `docs/fixes/`
  - Moved 2 vectorizer implementation summaries from root to `docs/implementation-summaries/`
  - Root directory now contains only 7 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md, readme.txt, tool-status.txt)
  - Updated `docs/history/2026/fixes/README.md` with sections for remote connection and vectorizer fixes
  - Added `docs/history/2026/implementation-summaries/README.md` to document implementation summaries
  - Updated all cross-references to point to new file locations
  - Zero information loss during reorganization

### Fixed
- **Chart Tool Display**: Fixed 3x3 pixel canvas issue in `create_chart` tool
  - Chart.js responsive mode was causing canvas to shrink to 3x3 pixels during iframe initialization
  - Added `responsive: false` and `maintainAspectRatio: false` as default Chart.js options
  - Reduced default chart dimensions from 800x400 to 600x350 to better fit chat interface (typical chat width ~720px)
  - Users can still override these defaults by explicitly providing responsive options or custom dimensions
  - Added comprehensive tests to verify the fix
  - Updated `CHART_FIX_TESTING.md` with testing guide for the 3x3 pixel issue
  - See `includes/tools/class-wp-mcp-ai-tool-create-chart.php` (lines 250-252, 261-268)

### Changed
- **Plugin Rename**: Updated plugin name from "Open Operator System (NV oOS)" to "NV Digital Open Operator System (oOS)"
  - Updated all plugin headers in main files and core/pro versions
  - Updated README.md, readme.txt, and all documentation references
  - Updated build scripts to generate correct plugin names
  - No breaking changes: text domains, function prefixes, and slugs remain unchanged
  - This is purely a branding update with no functionality changes



## [1.1.3] - 2026-03-03

### Fixed - WordPress.org Compliance Final Audit
- **Output Escaping (March 3, 2026)**: Added `esc_attr()` to 5 unescaped CSS class attribute echoes
  - `class-wp-mcp-ai-admin-profession-settings.php`: nav-tab active class conditional
  - `class-wp-mcp-ai-admin-team-settings.php`: nav-tab active class conditional
  - `class-wp-mcp-ai-admin-slash-commands-dashboard.php`: compact-view class conditional
  - `class-wp-mcp-ai-admin-orchestration-dashboard.php`: health-score-circle and warning class conditionals
  - Added `phpcs:ignore` with justification for safe `wp_json_encode()` output inside inline `<script>` block
- **ABSPATH Security Guards (March 3, 2026)**: Added `if ( ! defined( 'ABSPATH' ) ) { exit; }` to 4 files
  - `includes/toolkit-metadata-mapping.php`
  - `includes/filesystem/class-wp-mcp-ai-filesystem-service.php`
  - `includes/services/class-wp-mcp-ai-process-service.php`
  - `includes/validators/class-wp-mcp-ai-validator-service.php`
- **Hardcoded Admin Menu Position (March 3, 2026)**: Removed last hardcoded menu position from Pro Dashboard
  - Changed `add_menu_page()` position argument from `85` to `null` (automatic positioning)
  - Now consistent with v1.1.2 fix applied to all other menu registrations
- **PR #4004 Compliance Review**: Reviewed and confirmed Telegram Mini App media tab changes are fully compliant
  - PHP: `pathinfo( $full_url, PATHINFO_EXTENSION )` safely cast to string and lowercased
  - JS: `escHtml()` used for all user-derived badge content
  - CSS: Layout-only changes, no compliance concerns

### Added - Telegram Mini App
- **Media Tab Extension Badges (PR #4004, March 2, 2026)**: Non-renderable files now show extension badge
  - Adds `ext` field (lowercase extension) to all `handle_media()` REST responses
  - JS: `extBadge` computed from `item.ext`, rendered with `escHtml()` escaping
  - New CSS `.tma-media-icon-emoji`: base `line-height:1` rule for consistent icon sizing
  - New CSS `.tma-media-ext-badge`: monospace pill with `rgba(0,0,0,.35)` background and `var(--tma-section-bg,#fff)` text for WCAG-compliant contrast in Telegram light/dark themes; truncates long extensions with ellipsis
  - `.tma-media-icon` updated to `flex-direction:column` + `gap:4px` for vertical icon/badge stacking; font-size 36px → 32px



## [1.1.2] - 2026-02-16

### Fixed - WordPress.org Compliance
- **Hardcoded Admin Menu Positions (February 16, 2026)**: Removed hardcoded menu positions from 5 locations
  - Changed Assistant CPT menu_position from 56 to null for automatic positioning
  - Changed Team CPT menu_position from 58 to null for automatic positioning  
  - Changed Profession CPT menu_position from 57 to null for automatic positioning
  - Changed AI Peer CPT menu_position from 57 to null for automatic positioning
  - Changed Main Admin Menu position from 30 to null for automatic positioning
  - Prevents conflicts with other plugins per WordPress.org guidelines
  - Related to PR #3741 compliance fixes

- **Pro Integration Settings Architecture (February 16, 2026)**: Moved pro-only integration settings to pro addon
  - Moved Mailjet settings to pro addon (5 fields) - Tools exist in pro
  - Moved Google Analytics settings to pro addon (3 fields) - Tools exist in pro
  - Moved Yahoo Fantasy settings to pro addon (2 fields) - Tools exist in pro
  - Moved ESPN Fantasy settings to pro addon (2 fields) - Tools exist in pro
  - Created `addons/pro/includes/admin/sections/class-wp-mcp-ai-section-pro-integrations.php`
  - Base plugin now only includes settings for base tools
  - Pro addon adds its own settings when active
  - Better architecture: Settings match tool location
  - Still WordPress.org compliant: No gating, proper separation

### Changed
- Updated plugin version to 1.1.2 across all files
- Base plugin integration settings reduced to base-only features
- Pro addon integration settings added for pro-only features



## [1.1.0] - 2025-12-24

### Changed
- **Documentation Reorganization**: Completed comprehensive documentation restructuring (PR #2400)
  - Reorganized 40 files from root and docs/ directories into logical categories
  - Created clear category structure: archive/, features/, guides/, reference/, troubleshooting/
  - Maintained zero information loss during reorganization
  - Added `DOCUMENTATION_REORGANIZATION_SUMMARY.md` tracking document (now in `docs/history/2025/documentation/`)
  - Clean root directory maintained (6 essential MD files only)
  - Well-organized subdirectories with clear navigation via `docs/DOCUMENTATION_INDEX.md`
- **Tool Count Clarification**: Updated documentation to accurately reflect tool counts
  - 95 unique base tools (119 tool files including 24 validated variants)
  - 64 Pro tools (34 in src/Tools/ + 30 in tools/)
  - Total: 159 unique tools across base and Pro
  - Added clear note about validated variants being counted separately
- **Version Consistency**: Updated all documentation files to reflect current version 1.1.0
  - Updated `README.md`, `docs/README.md`, `docs/DOCUMENTATION_INDEX.md`
  - Ensured consistency across all version references

### Added
- **Code Review Documentation**: Added comprehensive code review for December 22-25, 2025
  - December 24: Complete analysis of recent changes and code quality
    - Security review (10/10 score - no vulnerabilities found)
    - Documentation quality assessment (9/10 score)
    - Architecture review and recommendations
    - See `docs/history/2025/code-reviews/CODE_REVIEW_2025-12-24.md`
  - December 25: Complete codebase analysis and comprehensive review
    - Full PHP linting with WordPress Coding Standards (470 files)
    - JavaScript linting with ESLint (52 files, all passed)
    - Security scan (10/10 - zero vulnerabilities found)
    - Architecture assessment (9.5/10)
    - Overall grade: A- (92/100) - Production Ready
    - See `docs/history/2025/code-reviews/COMPREHENSIVE_CODE_REVIEW_2025-12-25.md`
    - See `docs/history/2025/code-reviews/CODE_REVIEW_SUMMARY_2025-12-25.md`

### Fixed
- Version number inconsistencies across documentation files (1.0.0 → 1.1.0)
- Tool count discrepancies in README.md and other docs
- Last updated dates in documentation index files (now December 24, 2025)

#### Gemini Geospatial API Integration (December 22, 2025)
- **AI-Powered Location Queries**: Integrated Gemini Geospatial API for contextual, location-based queries with Google Maps grounding
  - **New Client Method**: Added `create_geospatial_query()` to `WP_MCP_AI_Gemini_Client`
    - Natural language queries about places, directions, and local information
    - Google Maps grounding with access to 250M+ places database
    - Optional location context (latitude/longitude) for better results
    - Returns `googleMapsWidgetContextToken` for frontend map visualization
  - **New Tool**: `gemini_geospatial_query` - Location-based AI queries for assistants
    - Ask about restaurants, attractions, routes, and area information
    - Supports multimodal responses with map context tokens
    - Configurable temperature and model selection
    - Proper capability checks and authentication
  - **Google Maps Integration**: Responses include context tokens for Google Maps JavaScript API
  - **Contextual View Component**: Enable interactive map visualizations in frontend
  - **Reduced Hallucinations**: Factual grounding with real-time Google Maps data
  - **Use Cases**: Location discovery, route planning, local recommendations, area exploration
  - **WordPress Integration**: User authentication, capability checks, multisite support
  - **Test Coverage**: 8 comprehensive test cases covering all functionality
  - **Comprehensive Documentation**: Complete usage guide with examples
  - See [Gemini Geospatial Documentation](docs/features/ai-providers/gemini/GEMINI_GEOSPATIAL.md)

#### OpenAI Batch API Integration (December 21, 2025)
- **Batch Processing for Cost Savings**: Integrated OpenAI Batch API for asynchronous bulk operations with 50% cost reduction
  - **New Client Methods**: Added 4 Batch API methods to `WP_MCP_AI_OpenAI_Client`
    - `create_batch()` - Create asynchronous batch processing jobs
    - `retrieve_batch()` - Get batch job status and results
    - `cancel_batch()` - Cancel running batch jobs
    - `list_batches()` - List and filter batch jobs with pagination
  - **New Tools**: 4 batch management tools for WordPress integration
    - `create_batch` - Create batch jobs via WordPress
    - `get_batch_status` - Monitor batch progress and completion
    - `list_batches` - List and manage batch jobs
    - `monitor_batch` - **NEW** Automatic batch monitoring with WordPress cron
      - Periodic status checking (hourly, twice daily, or daily)
      - Email notifications on completion/failure
      - Custom callback hooks for automation
      - Auto-download results option
      - Background processing via WordPress cron
  - **Supported Endpoints**: `/v1/chat/completions`, `/v1/embeddings`, `/v1/moderations`
  - **Cost Savings**: 50% reduced cost compared to synchronous API calls
  - **Higher Rate Limits**: Dedicated quota and much higher throughput
  - **24-Hour SLA**: Guaranteed completion within 24 hours
  - **Automated Monitoring**: Set-and-forget batch monitoring with cron integration
  - **Use Cases**: Bulk content generation, mass embeddings creation, large-scale moderation, dataset processing
  - **WordPress Integration**: Proper capability checks (requires `manage_options`)
  - **Comprehensive Results**: Status tracking, progress monitoring, output file IDs
  - **Test Coverage**: 15 comprehensive test cases covering all functionality
  - **Documentation**: Complete usage guide with examples
  - See [OpenAI Batch API Usage](docs/examples/openai-batch-api-usage.md)
  - See [OpenAI Batch API Documentation](https://platform.openai.com/docs/guides/batch)

#### OpenAI Moderation API Integration (December 21, 2025)
- **Content Safety & Compliance**: Integrated OpenAI Moderation API for automated content moderation
  - **New Tool**: `moderate_content` - Analyzes text and images for policy violations
  - **14 Violation Categories**: Checks for sexual content, hate speech, harassment, self-harm, violence, and illicit content
  - **Multimodal Support**: Works with both text and images via `omni-moderation-latest` model
  - **Batch Processing**: Can moderate multiple items in a single API call for efficiency
  - **Confidence Scores**: Returns probability scores (0-1) for each category
  - **Free API**: Moderation API is free to use with no token costs
  - **WordPress Integration**: Proper capability checks and error handling
  - **Comprehensive Results**: Includes formatted results, safety summaries, and actionable recommendations
  - **Client Method**: Added `moderate_content()` method to `WP_MCP_AI_OpenAI_Client`
  - **Test Coverage**: 9 comprehensive test cases covering all functionality
  - **Documentation**: Complete usage guide with WordPress integration examples
  - See [OpenAI Moderation API Usage](docs/examples/openai-moderation-api-usage.md)
  - See [OpenAI Moderation Documentation](https://platform.openai.com/docs/guides/moderation)

#### Gemini API Integration Gap Analysis (December 20, 2025, PR #2267)
- **Comprehensive Gemini Integration Review**: Complete gap analysis and documentation of Gemini API capabilities
  - **Analysis Documents**: 6 comprehensive documentation files created
    - `GEMINI_INTEGRATION_GAP_ANALYSIS.md` - Detailed 14-gap analysis across 5 categories
    - `GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md` - High-level overview with ROI analysis
    - `GEMINI_CAPABILITIES_MATRIX.md` - Feature comparison matrix
    - `GEMINI_INTEGRATION_ANALYSIS_INDEX.md` - Navigation guide
    - `GEMINI_OPENAI_TOOLS_ARCHITECTURE.md` - Tool architecture documentation
    - `GEMINI_TOOL_SANITIZATION_FIX.md` - Tool sanitization implementation
  - **Current State**: 15 of 30 major API endpoints implemented (50% coverage)
  - **Key Features Documented**: Chat, streaming, image generation/editing, video (Veo 3.1), music (Lyria), file API
  - **Enhancement Opportunities Identified**: Batch embeddings, context caching, thinking mode, masks, video analysis
  - **Cost Savings Potential**: Context caching can reduce costs by 68% for cached tokens
  - See [GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md](docs/features/ai-providers/gemini/GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md)

#### OpenAI GPT-Image-1.5 Model Support (December 20, 2025)
- **OpenAI GPT-Image-1.5 Image Generation**: Added support for the latest GPT-Image-1.5 model
  - **4× Faster**: Generation speed significantly improved compared to GPT-Image-1
  - **20% Cost Reduction**: New pricing structure with lower costs across all quality tiers
    - Low quality (1024×1024): $0.009 (was $0.011)
    - Medium quality (1024×1024): $0.034 (was $0.042)
    - High quality (1024×1024): $0.133 (was $0.167)
  - **Quality Parameters**: Supports low, medium, high, and auto quality settings
  - **Supported Sizes**: 1024×1024, 1024×1536, 1536×1024, and auto
  - **Default Model**: GPT-Image-1.5 is now the default image generation model
  - **Backward Compatible**: GPT-Image-1, DALL-E 3, and DALL-E 2 remain available
  - Updated cost estimation for accurate usage tracking
  - See [OpenAI Image Generation Documentation](https://platform.openai.com/docs/guides/image-generation)

#### GPT-5.2 Model Support (December 16, 2025)
- **OpenAI GPT-5.2 Model Family**: Added support for the latest GPT-5.2 models with 400K context window
  - **Base Model**: `gpt-5.2` - Standard flagship model ($0.00175 per 1K tokens)
  - **Pro Model**: `gpt-5.2-pro` - Advanced reasoning model with enhanced capabilities ($0.021 per 1K tokens)
  - **Instant Variant**: `gpt-5.2-instant` - High throughput optimized for volume work
  - **Thinking Variant**: `gpt-5.2-thinking` - Deeper analysis with reasoning time dial
  - **Dated Versions**: `gpt-5.2-2025-12-11` and `gpt-5.2-pro-2025-12-11` for version pinning
  - All models feature 400,000 token context window (2x larger than GPT-5.1)
  - Max output: 128,000 tokens per response
  - Knowledge cutoff: August 31, 2025
  - Properly configured rate limits (TPM, RPM, TPD, RPD)
  - Fallback chain configured for graceful degradation
  - Added comprehensive test coverage in `tests/test-model-config.php`
  - See [OpenAI GPT-5.2 Documentation](https://platform.openai.com/docs/models/gpt-5.2) and [GPT-5.2 Pro Documentation](https://platform.openai.com/docs/models/gpt-5.2-pro)

#### Symfony Process Component Integration (December 9, 2025, PR #2091)
- **Symfony Phase 2B Complete**: Migrated all Pro addon exec-based tools and services to Symfony Process component
  - **Process Service Created**: New `WP_MCP_AI_Process_Service` provides WordPress-friendly wrapper around Symfony Process【F:includes/services/class-wp-mcp-ai-process-service.php†L1-L220】
  - **6 Pro Tools Migrated**: 
    - `check_jukebox_status` - Meta AI Jukebox status checking
    - `check_wp_cli` - WP-CLI environment inspection  
    - `extract_video_frames` - FFmpeg frame extraction
    - `generate_jukebox_music` - Meta AI Jukebox music generation
    - `get_video_metadata` - FFmpeg metadata extraction
    - `remove_background` - Python rembg background removal
  - **2 Services Migrated**:
    - `WP_MCP_AI_Jukebox_Service` - Jukebox execution service
    - `WP_MCP_AI_Video_Frame_Extractor_Service` - FFmpeg operations service
  - **Benefits**: Enhanced security, proper timeout handling, better error reporting, process control
  - Replaced 14 direct `exec()` calls across Pro addon
  - All migrated tools maintain backward compatibility
  - See `docs/history/2025/implementations/symfony-phases/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md` for migration details

#### Settings UI Enhancements (December 8, 2025, PR #2072)
- **27 New Settings Exposed in Admin UI**: Made previously hidden settings accessible with proper UI organization
  - **Media**: MIME type allowlists for file and image uploads
  - **OpenAI TTS**: Text-to-speech model, voice, and format configuration
  - **High Token Fallback**: Auto-switch to fallback model when token limits exceeded
  - **Tool Configuration**: Web search provider selection, group email controls, Varnish cache toggle
  - **Cloudways**: Application and server ID fields for Cloudways integration
  - **Google Analytics 4**: Service account JSON credentials field
  - **Federation & Mesh Networking**: New subtab with 9 settings for distributed computing
    - Federation directory participation toggle
    - Regional routing configuration (geographic regions, data tags)
    - Rate limiting controls (QPS, burst capacity)
    - Mesh peer site configuration
    - Auto-generated inbound API key for peer authentication
  - Fixed naming inconsistencies between default settings and UI fields
  - Removed duplicate integration settings from Tools section
  - See `docs/history/2025/code-reviews/CODE_REVIEW_2025-12-08.md` for complete details

### Changed

#### Pro Tool Reorganization (December 8, 2025, PR #2073)
- **Moved 6 Exec Service Tools to Pro Addon**: Tools requiring external executables now properly designated as Pro-only
  - `check_wp_cli` - WP-CLI environment inspection
  - `extract_video_frames` - FFmpeg frame extraction
  - `get_video_metadata` - FFmpeg metadata reader
  - `remove_background` - Python rembg / remove.bg API background removal
  - `generate_jukebox_music` - OpenAI Jukebox audio generation
  - `check_jukebox_status` - Jukebox installation status checker
  - Added `'pro'` capability flag to all 6 tools
  - Registered tools in Pro addon instead of base plugin
  - Removed from base tool registry to prevent duplicate registration
  - **Note**: The Pro addon contains **38 total tools**, including these 6 exec-based tools plus 32 other Pro tools for social media, Google services, GitHub, WooCommerce, JetEngine, and more
  - **Breaking Change**: Base version users no longer have access to these 6 exec-based tools
  - Pro addon now required for exec-based media processing and WP-CLI tools
  - See `docs/history/2025/code-reviews/CODE_REVIEW_2025-12-08.md` for impact analysis

### Documentation
- **Documentation Status Updates (December 20, 2025)**: Systematic review and update of documentation completion status
  - Updated 8 major documentation files with accurate completion status
  - Quality scores updated: 95/100 → 98/100 (reflects December 2025 improvements)
  - High-priority gaps: 5 → 1 remaining (4 completed: output escaping, CI/CD gates, test env, integration tests)
  - Created `docs/history/2025/documentation/DOCUMENTATION_UPDATE_STATUS_2025-12-20.md` - Tracking document for systematic review of 549 documentation files
  - Updated `docs/history/2025/summaries/GAP_ANALYSIS_EXECUTIVE_SUMMARY.md` - Marked completed work, updated metrics
  - Updated `docs/history/2025/summaries/ACTION_ITEMS.md` - Security and JavaScript items marked complete
  - Updated `docs/history/2025/summaries/QUICK_WINS_GAP_FIXES.md` - CI/CD and error documentation sections completed
  - Updated `docs/history/2025/summaries/PLUGIN_GAP_ANALYSIS.md` - PHP and JavaScript sections marked resolved
  - Updated `docs/history/2025/summaries/REMAINING_ISSUES.md` - Current code quality score 98/100, ~40 issues remaining (97.5% reduction)
  - **Tool Count Correction**: Updated README.md from 71 → 95 core tools (total 109 → 133 tools)
  - Documented completion: Output escaping (66 fixes), CI/CD gates, security scanning (CodeQL), error documentation
- **Code Review December 8, 2025**: Comprehensive review of recent commits with recommendations
  - Created `docs/history/2025/code-reviews/CODE_REVIEW_2025-12-08.md` - Analysis of PR #2073 and PR #2072
  - Overall grade: A - Excellent code quality, thorough testing
  - Identified documentation updates needed for tool changes and new settings
- **Comprehensive Documentation Consolidation (December 7, 2025)**: Consolidated ALL bug reports, fixes, code reviews, and session summaries into master documents
  - Created `docs/history/2025/summaries/CONSOLIDATED_SESSION_SUMMARIES.md` - All development sessions from December 2025, November 2025, and archived sessions
  - Updated `docs/history/2025/summaries/CONSOLIDATED_BUGS_AND_FIXES.md` - Added output escaping work, site creator fix, December code review
  - Created `SESSION_SUMMARIES_ARCHIVE_NOTE.md` - Guide to consolidated documentation
  - Updated `docs/DOCUMENTATION_INDEX.md` - Added master documents section with ⭐ highlights
  - Updated `README.md` - Added new Documentation section with links to master documents
  - **Nothing lost**: All original session files preserved for reference, all content consolidated for better access
  - **Benefits**: Single source of truth, complete history, better organization, easier maintenance
  - See `CONSOLIDATION_COMPLETE_SUMMARY.md` for full details

### Fixed

#### Test Team Modal Feature Activation (December 8, 2025)
- **Test Team Page Initialization**: Activated the test team modal feature by adding missing initialization in main plugin file
  - Added `admin.test_team` service loading in `mcp-ai-wpoos.php` (lines 558-560)
  - Feature was 90% complete (all components built) but not initialized
  - Now accessible via **Teams → Test Team** admin menu
  - Allows testing AI teams with temporary assistants for each team member
  - All components verified: admin page, JavaScript, CSS, REST API, tests, documentation

#### Async Tool Execution & VEO Video Generation (November 26-27, 2025)
- **Async Tool Result Display Fix (PR #1739, #1755)**: Fixed async tool results (VEO video generation) not appearing in chat interface
  - Dynamically create assistant message when `tool_results` present but no LLM message exists
  - Fixed `handleChatResponse()` to process tool results even when no `choices` array is returned
  - Added `startAsyncToolPolling` helper function to reduce code duplication
  - See `docs/history/archive/2026/fixes/ASYNC_TOOL_RESULT_FIX.md` for technical details

- **Async Tool ID Mismatch Fix (PR #1772)**: Fixed subsequent API failures after async video generation
  - Skip pending async tool results when adding to conversation (will be added on completion)
  - Pass original `tool_call_id` through entire async polling chain
  - Update `displayAsyncToolResult` to use provided `tool_call_id`
  - Prevents duplicate tool messages with mismatched IDs causing API errors

- **Unified Job ID Handling (PR #1758, #1760)**: Fixed metadata overwrites in unified async job flow
  - VEO service now merges metadata with existing async executor metadata instead of overwriting
  - Preserves critical fields (tool_slug, context, arguments) needed for permission checks
  - Async executor refreshes metadata from transient before updating to preserve veo-specific fields

- **Delegation Chain Propagation (PR #1759)**: Fixed delegated async job failures not propagating
  - Added `handle_delegation_chain()` method for proper delegation chain handling
  - Failed delegated jobs now propagate failure to parent job
  - Completed delegated jobs propagate result to parent
  - Added comprehensive tests for delegation chain scenarios

- **Async Tool Timeout Configuration (PR #1761, #1763)**: Added configurable async tool timeout
  - New settings under Orchestration → Async Tool Timeout
  - Constants for default (120s) and maximum (600s) timeout values
  - Extracted timeout logic to helper method for DRY compliance

#### SSE Streaming Improvements (November 26-27, 2025)
- **SSE Message Handling Fix (PR #1768)**: Fixed choices structure overwriting content in final messages
  - Check for final response (`data.data`) FIRST before extracting streaming chunks
  - Only extract streaming chunks if NOT a final response
  - Follows OpenAI SSE streaming best practices

- **SSE Stream Completion Fix (PR #1746)**: Fixed network errors when final data already captured
  - Handle network errors gracefully during stream completion
  - Fixed `ob_flush/flush` order in SSE handler for proper buffer flushing
  - Added `ob_flush` to `send_sse_done` for consistent behavior

- **SSE Cron-Status Authentication (PR #1774)**: Fixed 401 Unauthorized error in SSE cron-status endpoint
  - Added authentication query parameters for EventSource connections
  - Fixed guest token and nonce passing for SSE endpoints

- **WP_Error Normalization (PR #1736, #1737)**: Fixed SSE streaming failures with tool errors
  - Added recursive `normalize_data_recursive()` method for WP_Error objects
  - Applied normalization across SSE streams, job status, and cron status service
  - Prevents JSON encoding failures when tool results contain WP_Error objects

#### Chat UI & Status Updates (November 26-27, 2025)
- **Tool Completion Status Fix (PR #1750, #1752, #1753)**: Fixed chat status stuck on "Tool is processing"
  - Updated PHP localized strings from "Tool response ready" to "Tool completed successfully"
  - Added 'success' type to UI utilities with checkmark icon
  - Status now correctly shows completion for finished tools, processing for async pending

- **Duplicate Assistant Messages Fix (PR #1738)**: Fixed duplicate messages in chat streaming
  - Move streaming message removal BEFORE `handleChatResponse` to prevent duplicates
  - Added missing delete button to fallback path

- **Truncated Tool Results Fix (PR #1756)**: Fixed truncated results not showing in chat
  - Handle string results in `normaliseToolResultForDisplay()`
  - Properly filter async pending results from display

#### Job Notification System (November 26-27, 2025)
- **Job Event Bus Integration (PR #1771)**: Unified cron-status and chat async tool coordination
  - Added `job-event-bus.js` with mitt-compatible API (on, off, emit, all)
  - Update cron-status-service to emit job updates through event bus
  - Chat.js now listens for job completions via event bus
  - Prevents SSE connection conflicts between job bar and chat interface

- **Cron Job Status Bar Filtering (PR #1744)**: Fixed multi-widget isolation
  - Filter job status by assistant ID for proper multi-widget support

#### Security & Authentication (November 27, 2025)
- **Job Notifier Auth Support (PR #1762)**: Comprehensive authentication for job notifier REST endpoints
  - Added mesh key, local token, guest token, bearer token, and nonce authentication
  - Return 503 when authenticator unavailable instead of allowing unvalidated tokens
  - Explicit success check for bearer token validation

- **Path Traversal Prevention**: Fixed regex for `sanitize_job_id`
  - Changed `/\\.\\.+/` to `/\\.{2,}/` to correctly match path traversal patterns
  - Applied fix to both job notifier REST and tools controller

#### Message Bundling (November 27, 2025)
- **Form State During Bundling (PR #1765)**: Fixed form remaining enabled during message bundling delay
  - Disable form when first message queued, re-enable on cancel
  - Provides consistent UI feedback during 800ms bundling window
  - Added comprehensive tests for message bundling behavior

#### Development Environment (November 27, 2025)
- **Codex Startup Script (PR #1773)**: Fixed dev dependency installation
  - Ensure dev dependencies installed when vendor was installed with `--no-dev`

- **Multisite Context Fix (PR #1767)**: Fixed async tool execution in multisite
  - Interface files moved to proper location
  - Fixed multisite context preservation for async tools

#### Async Tool Logging & Observability (November 27, 2025)
- **Improved Sync/Async Logging (PR #1769)**: Enhanced tool execution observability
  - Fixed misleading `hasChoices` logging for final SSE messages
  - Added observability logging for async tool detection and polling initiation
  - Improved tool_call matching debug output
  - Added JSDoc documentation for sync vs async tool execution flow

- **WP_LANG_DIR Constant Warning (November 24, 2025)**
  - Fixed PHP warning: "Constant WP_LANG_DIR already defined" during plugin activation and performance tests
  - Applied Composer patch to wp-phpunit package to add guard check before defining WP_LANG_DIR
  - Warning occurred when WordPress core had already defined the constant before wp-phpunit bootstrap ran
  - Affected scenarios: plugin activation via WP-CLI, performance tests via admin UI
  - Patch automatically applied during `composer install` via cweagans/composer-patches
  - See `patches/README.md` for technical details
  
- **Chat Client Attachment Visibility (PR #1630, November 24, 2025)**
  - Fixed issue where file attachments (images, PDFs, etc.) were appearing in the chat UI but not being passed to OpenAI
  - Added `input_image` and `input_file` segment types to REST validator's processable types
  - Users can now attach files in chat-client and AI providers will properly receive them
  - Preserves agentic workflow - no manual re-uploading needed
  - Added 2 comprehensive unit tests (`test_sanitize_messages_processes_input_image_segments` and `test_sanitize_messages_processes_input_file_segments`)
  - Backward compatible with all existing segment types
  - See `docs/history/archive/2026/fixes/CHAT_CLIENT_ATTACHMENT_FIX.md` for technical details

### Documentation
- **Documentation Organization (November 27, 2025)**: Continued documentation cleanup
  - Moved 13 additional fix documentation files from root to `docs/history/archive/fixes/`
  - Files moved: ASYNC_TOOL_RESULT_FIX.md, FILE_BASED_POLLING_IMPLEMENTATION.md, FIX_SUMMARY.md, IMAGE_ATTACHMENT_URL_FIX.md, IMAGE_EDIT_403_FIX.md, ISSUE_RESOLUTION_SUMMARY.md, PULL_REQUEST_SUMMARY.md, ROTATE_IMAGE_FIX.md, ROTATE_IMAGE_FIX_SUMMARY.md, VEO_FILENAME_FIX.md, VEO_NOTIFICATION_FLOW.md, VEO_TOOL_CALL_ID_AND_COST_FIX.md, VIDEO_EXTRACTION_FIX_SUMMARY.md
  - Root directory now contains only 5 essential files (README.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md, BUILD.md)

- **Documentation Organization (November 24, 2025)**: Completed initial documentation cleanup
  - Moved 62 non-essential markdown files from root to `docs/history/archive/` subdirectories
  - Root directory now contains only 5 essential files (README.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md, BUILD.md)
  - Organized files into logical categories:
    - `docs/history/archive/fixes/` - Bug fix summaries and technical details (31 files)
    - `docs/history/archive/features/` - Feature implementation documentation (14 files)
    - `docs/history/archive/implementations/` - Implementation summaries (5 files)
    - `docs/history/archive/phases/` - Phase documents (4 files)
    - `docs/history/archive/testing/` - Test guides and summaries (3 files)
    - `docs/history/archive/summaries/` - General summaries (1 file)
  - All documentation preserved (nothing deleted, only organized)
  - Easier navigation and discovery of relevant documentation
  - Completes the documentation reorganization initiated on November 18, 2025

### Added
- **Phase 2.1: File Management & Caching for Video Analysis (November 20, 2025)**
  - File caching to avoid re-uploading same videos (transient-based with 24-hour expiration)
  - File tracking in WordPress options for lifecycle management
  - Automated cleanup of old files via daily WordPress cron job (`wp_mcp_ai_cleanup_gemini_files`)
  - Cache key generation with file modification time detection for attachments
  - Support for both URL-based and attachment-based caching
  - Comprehensive test coverage (21 new unit tests in `tests/test-gemini-file-service-caching.php`)
  - All features follow proper Separation of Concerns (Service layer handles business logic)
  - Reduces API costs and improves performance by avoiding duplicate uploads
  - **Status**: Phase 2.1 Complete ✅ (see `docs/history/archive/2026/VIDEO_ANALYSIS_ROADMAP.md`)

### Documentation
- **Documentation Reorganization (November 18, 2025)**: Comprehensive cleanup and consolidation of documentation
  - Consolidated bug reports into single comprehensive `docs/developer/testing-docs/TESTING_AND_QUALITY_REPORT.md` (753 lines)
    - Merged BUG_REPORT.md and BUG_REPORT_SUMMARY.md
    - Includes test suite results (2,106 tests, 73.4% pass rate)
    - Code quality analysis (2,120 linting issues categorized)
    - Security audit findings and recommendations
    - Prioritized action items with time estimates
  - Reorganized 95+ documentation files into logical archive structure
    - Created `docs/history/archive/implementations/` for implementation summaries
    - Created `docs/history/archive/phases/` for phase documents
    - Created `docs/history/archive/fixes/` for fix summaries and issue resolutions
    - Created `docs/history/archive/features/` for feature documentation
    - Created `docs/history/archive/code-reviews/` for code review reports
    - Created `docs/history/archive/testing/` for test infrastructure docs
  - **Root directory now contains only 5 essential files**:
    - README.md - Main plugin documentation
    - CONTRIBUTING.md - Contribution guidelines
    - SECURITY.md - Security policy
    - CHANGELOG.md - This file
    - BUILD.md - Build and development instructions
  - All documentation preserved (nothing deleted, only organized)
  - Easier navigation and discovery of relevant documentation

### Added
- **LM Studio Function Calling Support**: Added OpenAI-compatible function calling to LM Studio provider (#1360)
  - Preserves OpenAI-compatible message structure for assistant messages with `tool_calls`
  - Maintains tool role messages with `tool_call_id` and `name` fields
  - Added `normalise_tools_for_payload()` method for consistent tool formatting
  - Streaming explicitly disabled when tools are present for reliable execution
  - Full backward compatibility - non-tool scenarios work exactly as before
  - Target model: qwen/qwen3-coder-30b
  - Comprehensive test coverage (4 new tests)

### Fixed
- **Code Quality Improvements**: Comprehensive code review and documentation update (November 16, 2025)
  - Performed complete code review confirming 95/100 code quality score (Excellent)
  - Verified security: No critical vulnerabilities identified, excellent input sanitization and output escaping
  - Verified SOC compliance: No new violations introduced, existing violations tracked in improvement plan
  - Verified documentation: All 69 documentation files current and comprehensive
  - JavaScript linting: Clean (only vendor file warning - expected)
  - PHP linting: Non-critical documentation and style warnings identified for future improvement
  - Test suite: Comprehensive 60+ test files ready for execution
  - Auto-fixed 362 PHP coding standard violations across 32 files (previous review)
  - Fixed critical security issue: added wp_unslash() to $_POST data sanitization in AJAX handlers (previous review)
  - Renamed admin integration files to match WordPress coding standards (previous review)
  - Fixed inline comment formatting to comply with WordPress standards (previous review)
  - Improved code consistency and readability across the plugin
- **Orchestration Capability Flags System**: Properly restored from PR #1142 with crash fix
  - Original implementation caused site crashes due to incomplete interface implementations
  - Verified all 21 tools with capability flags have complete method implementations
  - Restored 4 orchestration interfaces safely (Capability_Flags, Rules, Flow_Stage, Context_Restrictions)
  - No tools declare interfaces without implementing required methods (crash cause)
  - All syntax validations pass

### Added
- **MCP 2024-11-05 Specification Support**: Updated documentation to align with the latest Model Context Protocol specification
  - OAuth 2.1 security enhancements (PKCE, token rotation, mandatory HTTPS)
  - Streamable HTTP transport for better reconnection and bidirectional communication
  - Progress notifications with descriptive status messages during tool execution
  - Tool annotations for read-only, destructive, and permission-based operations
  - Session management via `Mcp-Session-Id` header for state recovery
  - JSON-RPC batching support for efficient parallel task processing
  - Multimodal content support (audio data streams)
  - Completions capability for argument autocompletion
- **Root Security Key**: Optional wp-config.php constant (`WP_MCP_AI_ROOT_SECURITY_KEY`) that can be enabled during emergency shutdown to require authentication before re-initializing the plugin. Includes rate limiting (5 attempts per 5 minutes), automatic lockout (15 minutes), and comprehensive audit logging. Provides additional protection against unauthorized reactivation after security incidents.【F:docs/features/security/root-security-key.md†L1-L511】【F:includes/class-wp-mcp-ai-root-security-key.php†L1-L360】
- **Token Usage Management Dashboard**: Admin settings page now displays comprehensive token usage statistics with per-user and global views, breakdown by provider and model, and reset capabilities for administrators
- **Job Notification System**: Real-time SSE streaming and webhook notifications for async operations
- **Message Bundling**: Client-side 800ms message bundling to reduce API calls and server load
- **Agentic Loop Token Management**: Three-tier intelligent handling (detection, auto-switching, truncation) for token overflow
- **MCP JSON-RPC 2.0 Endpoint**: Standards-compliant `/mcp` endpoint for remote MCP client communication (now supporting 2024-11-05 specification)
- **Async Crawl4AI Polling**: Server-side WP-Cron polling for long-running crawl jobs with job status tracking
- **LM Studio Model Fetching**: Fixed data structure mismatch for "Fetch Models" feature
- **CPT-CCT Synchronization**: Automatic bidirectional sync between WordPress CPT and JetEngine CCT for assistants
- Automatic activation of JetEngine Data Stores module when JetEngine is installed and active
- **JetEngine API Compatibility Layer**: Added backward-compatible query_items() implementation to support both JetEngine 3.3+ (new db->query() API) and older versions (Item_Handler->query_items())

### Changed
- **Documentation Updates**: Comprehensive updates to MCP-related documentation files
  - `docs/reference/api/mcp-endpoint.md`: Added 2024-11-05 features, implementation status, and upgrade recommendations
  - `docs/reference/api/MCP-AND-SSE.md`: Added Streamable HTTP transport info, protocol enhancements, and migration guide
  - `docs/reference/api/mcp-server-authentication.md`: Added OAuth 2.1 security enhancements section
  - `docs/DOCUMENTATION_INDEX.md`: Updated with MCP version and enhanced documentation references
  - `README.md`: Added MCP version badge and enhanced MCP section with 2024-11-05 features
  - `docs/developer/architecture/integrations/jetengine-api-compatibility.md`: New comprehensive guide for JetEngine API compatibility
  - `docs/getting-started/installation-setup/deployment-troubleshooting.md`: Added JetEngine v3.3+ compatibility troubleshooting
- Chat interface now provides visual feedback for message bundling ("Preparing to send…", "Sending…")
- Token overflow scenarios automatically switch to higher-capacity models (gpt-4o-mini → Gemini 2.0 Flash)
- SSE endpoint modernized with automatic reconnection, event IDs, and HTTP/2 compatibility

### Fixed
- **JetEngine API Compatibility**: Fixed fatal error "Call to undefined method Item_Handler::query_items()" when using JetEngine 3.3+
  - Updated Performance Monitor CCT to use new db->query() API with fallback to old Item_Handler API
  - Updated Performance Reporter to use compatibility layer
  - Updated Elementor performance widgets to use compatibility layer
  - Added comprehensive test suite (12 tests) for query compatibility
- **PHP Version Compatibility**: Added defensive PHP version checks to all WooCommerce-related files to prevent parse errors on PHP < 7.4. This ensures that if any WooCommerce logging or error handling mechanism attempts to load plugin files directly, they will gracefully exit instead of causing "unexpected token private/protected" fatal errors on older PHP versions.
- **LM Studio & Ollama Timeout Issues**: Fixed "WordPress timed out waiting for a response" errors for local AI providers
  - Increased minimum timeout for completion requests from 30s to 120s
  - Resource Manager now allows bypassing PHP `max_execution_time` constraint for external HTTP requests
  - Local AI models can now take the time they need (60-240s+) without timing out
  - Timeout can be further increased via Settings → NV oOS → Request Timeout if needed【F:includes/class-resource-manager.php†L189-L220】【F:includes/class-wp-mcp-ai-ollama-client.php†L111-L119】【F:includes/class-wp-mcp-ai-lm-studio-client.php†L253-L261】
- JavaScript lint errors: Fixed 6 linting errors including unused function parameters in admin-settings.js and camelcase identifier warnings in settings-dashboard.js
- JavaScript lint error for unused `waitForCrawl4aiTask` function (documented as reserved for future use)
- 164 PHP coding standard violations auto-fixed across 19 files
- Tool registry validation ensuring correct slug-to-class mappings

## [1.0.0] – 2025-10-23
### Changed
- Expanded chat interaction logging to keep structured message content while trimming oversized payloads.
- Front-end chat client now preserves assistant replies that contain non-text content like images or tool results.
- Bundled the ChatKit integration directly inside the core plugin, replacing the standalone add-on workflow.

## [0.9.0] – 2025-10-21
### Added
- Initial beta release
- AI Assistant custom post type
- OpenAI GPT-4o-mini integration
- REST chat endpoint `/wp-json/mcp-ai/v1/chat`
- Tool registry with default tools
- WooCommerce & JetEngine conditional tools
- Admin settings for API key
- Shortcode `[mcp_ai_chat assistant="ID"]`

### Notes
- Stable for development & testing.
- Production hardening will follow post-feedback.
