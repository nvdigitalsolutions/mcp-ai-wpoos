# NV oOS Testing Patterns

> **GSD Context File** — Load this when writing or reviewing PHPUnit tests.
> Last reviewed: September 22, 2026 (v1.1.84).
>
> **New in v1.1.84 (PRs #6745–#6747):** TypeSafe Jev enhancement wave. Phase 0/1 (PR #6747): `test-typesafe-client.php` + `test-typesafe-decide-tool.php` extended (noul criteria, structured EntryType fields, bounded retry, cache-hit zeroed usage, `min_confidence`/`weights`, usage aliases) + new `test-typesafe-guardrail-tool.php` (hazard batching, pass/review/block thresholds, default + custom hazard maps) + `test-openrouter-client.php` extended (model normalisation + bridge defaults). Phase 2: new Pro `addons/pro/tests/test-pro-jev-phase2.php` (474 lines — guest-chat guardrail veto/fail-open, citation checking, the three new tools). Capability fix (PR #6745): `test_tool_metadata` pins `typesafe_decide`'s `manage_options` declaration (drift fails CI). PR #6747 validation totals: 145 tests / 3,510 assertions green across `test-typesafe-client`, `test-typesafe-decide-tool`, `test-typesafe-guardrail-tool`, `test-openrouter-client`, `test-assistant-tool-presets`, `test-model-catalog`, and the Pro suites (`test-pro-jev-phase2`, `test-pro-jev-classifier`, `test-research-jev-filter`, `test-pro-parallel-model-dispatcher`) — the Docker full suite was not run in-session (Docker Desktop unavailable locally). The `mcp-ai-wpoos-test-suite` skill stays at **50** patterns.
>
> **New in v1.1.83 post (PRs #6726–#6743):** usage-monitor settings save (PR #6726): new `tests/test-usage-monitor-settings-save.php` regression suite + neighbors (`test-nefarious-usage-monitor`, `test-simple-settings-save`, `test-security-center`, `test-security-hardening`) — 75 tests / 631 assertions on WP 6.9 + 7.1. Assistant Builder (PR #6727): `test-assistant-builder-assistant.php` 5/5 (config structure, roster inclusion, prompt standards, install metadata, idempotency) + 10/10 combined with `test-architect-agent-assistant-creation.php`. TypeSafe Jev (PR #6728): `test-typesafe-client.php` + `test-typesafe-decide-tool.php` + Pro `test-pro-jev-classifier.php` / `test-pro-parallel-model-dispatcher.php` / `test-research-jev-filter.php` (61 base + 27 Phase-1 + 146 mixed on WP 6.9; 58 on WP 7.1). P3 ID-handoff contracts (PRs #6729–#6738, closure #6739): the **permanent L1 honesty suite** (`test-tool-id-handoff-contract.php`, manifest-driven, same-PR rule for manifest entries) + **L2 round-trip suite** (`test-tool-id-handoff-round-trip.php`, deterministic drivers, no LLM; per-dataset `markTestSkipped` gates — no silent masking); wave totals 123/123 → 129/129 → 107/107 → 121/121 → 17/17 (498) → 17/17 (556) on both WP 6.9 + 7.1; CG Pro dual matrix for the mirrored families (monolith + standalone). Webchat fixes (PRs #6738/#6740): L1+L2 17/17, 556 assertions; CG wellness CRUD dual matrix 10+2 skips / 12/12 standalone. Coverage repair (PR #6743): `typesafe_decide` joins the `ai_ml` preset + coverage manifest; default-assistant tests 6 → 7 (with the `assistant-builder` slug); `setUp()` resets install-tracking state (order-dependent "Post 4311 should exist" fixes). The `mcp-ai-wpoos-test-suite` skill moves **48 → 50 patterns** (pattern 49 — `log_activity()` does not exist, use `log_event( 'activity', … )`; pattern 50 — `$wpdb->prepare()` placeholder-count warnings from interpolated WHERE fragments + array-spread args — done in-window by #6742).
>
> **New in v1.1.83:** Tool Description Engineering (PRs #6686/#6695): `tests/helpers/test-tool-payload-advisor.php` + `tests/test-tool-description-engineering.php` + `tests/test-assistant-adaptive-tool-cap.php` 25/25 (guidance assembly, `[Usage]` suffix, adaptive cap tiers, per-assistant override); #6695 guidance passthrough + description-engineering + registry + slug-integrity + logger suites 45/45. Phase 2 clusters (#6687–#6723) run the registry smoke suites per cluster (33/33 — 3,269 assertions typical; b1 56/56; p2 67/67; p3 162/162; p5 110/110). Pro hardening (PR #6677): new `tests/test-pro-telegram-message-chunking.php` (6 — paragraph/line/hard splits, `chunk=false`, mid-sequence error) + extended `test-tool-load-skill.php`/`test-okf-bundle-manager.php`; 41/41 changed + 241/241 adjacent + 84/84 OKF/skill. Upwork series (PRs #6678–#6680/#6682/#6684): new `tests/test-upwork-job-search-fallback.php` (12 incl. end-to-end API-mode with stubbed token) + 4 delivery-format tests 83/83 on WP 6.9 + 7.1; 150/150 then 152/152 across affected suites both versions; `test-pro-result-delivery-email-format.php` 30/30 (116 assertions) with the ordered-list regression. CG Pro upwork characterization green in both matrices. The `mcp-ai-wpoos-test-suite` skill is now at **48** patterns (pattern 48 — `pro_schedules` cache interference + `save_connection()` `url` requirement, #6679).
>
> **New in v1.1.82:** token-tracking hardening (PR #6669): `test-token-tracking-database.php` + `test-enhanced-token-tracking.php` + `test-service-cost-tracking.php` + `test-usage-tracker.php` — 61 tests / 200 assertions on WP 6.9 + 16/16 on WP 7.1 + the content-graph-ai Ecosystem matrix 13/13 (fast-path contract, backoff gate, real-table recovery, silent `record_usage()`, graceful reads; the real-table harness pattern from `test-async-job-queue-table-resilience.php`). Coverage guards (PR #6645): `test-messaging-channels-ajax.php` 69/69 incl. +9 (nonce/capability/missing-connection per WhatsApp handler), `test-assistant-tool-presets.php` 9/9, both coverage manifests + the AJAX inventory regenerated. Result-delivery (PR #6661): delivery-format 45/45 on WP 6.9 + 7.1 (+5 dedupe/redaction tests). Pro SPA (PRs #6665/#6672): `test-pro-spa-shortcode.php` 10/10 on both versions (+2); Vitest 133 → 134 (embedded-mode zero-cron-status-fetch + model-store seeding). Docs Hub 0.4.7 (PRs #6659/#6667): 107 tests / 0 failures on both versions (+3 rebuild-job round-trips). The `mcp-ai-wpoos-test-suite` skill stays at **47** patterns.
>
> **New in v1.1.81:** Shopify UCP mode-awareness (PR #6634): 20 tests in `addons/pro/tests/test-shopify-ucp-mode-awareness.php` + the matrix-aware CG Pro mirror (monolith + standalone, WP 6.9 + 7.1). Image cards (PR #6638): `test-shopify-product-image-cards.php` 8/8 + both CG Pro matrices (10-card cap, raw-payload preservation, media-to-images mapping). CRM JobNavigator adoption (PR #6636): `test-crm-jobnavigator-adoption.php` WP1–WP9 + CG Pro 11 characterization tests (#6640); the Gmail poller extends the same suite +113 tests (#6641). Financial resilience (PR #6639): `test-financial-market-data-tools.php` (505), `test-financial-portfolio-transactions.php` (361), `test-financial-price-alerts.php` (338), `test-financial-resilience.php` (657), `test-technical-indicators.php` (255) + market-analysis extensions. FlowHub (PRs #6635/#6637): `test-flowhub-connection-helper.php` (290 + 95), `test-flowhub-client.php` +120, `test-flowhub-tools.php` +70. Multi-recipient email (PR #6643): `test-pro-result-delivery-email-format.php` +115. The `mcp-ai-wpoos-test-suite` skill stays at **47** patterns.
>
> **New in v1.1.80:** assistant portability (PR #6628) adds 34 tests (engine round-trip fidelity incl. array meta + real attachments, credential redaction export/import/backup-provider, all three import modes, dry-run, legacy/blueprint compatibility, blueprint mapping, A2A export, REST permission boundary + payload validation, tool registry + `execute()`) + 105 neighbouring regression tests green on **WP 6.9 and 7.1**. Usage-monitor sub-tab (PR #6632): 80 tests / 435 assertions across `test-nefarious-usage-monitor`, `test-nefarious-usage-monitor-prompt-injection`, `test-security-center`, `tests/security/test-break-glass` (new REST clear routes, pattern sanitization, type-label/severity helpers, malformed-pattern scan skip). Shopify: `test-shopify-sync-*` + resolver + remote-sites 161 green (#6623); `test-shopify-storefront-catalog.php` 14 → 22 tests with CG Pro mirror 10 → 15 (#6624/#6630). WhatsApp remote-sites: 45/45 WP 6.9 + 8/8 WP 7.1; CG Pro characterization `test-remote-admin-slice.php` +2 (#6622). OKF admin suites 5/14 + 15/42 assertions (#6631). WP-CLI fixes (#6625/#6626) were live-validated in the Design Stack (provider list, chat, `--stream` native + simulated); native callback contracts covered by existing client tests (`test-lm-studio-client.php` et al). The `mcp-ai-wpoos-test-suite` skill stays at **47** patterns.
>
> **New in v1.1.79:** the imaging symlink-hardening PR (#6616) adds regression tests to `addons/pro/tests/test-healthcare-imaging-toolkit.php` + `plugins/nvoos-content-graph-pro/tests/test-privacy.php` (52/52 monolith imaging; 14/14 Pro dual-matrix; skip gracefully where symlinks are unavailable). Checkout-api: account-switch suite 37/37 on WP 6.9 (#6611) + descriptor suite 42/42 (216 assertions) on WP 6.9 + 7.1 (#6613) + 7 new mailer tests (full addon suite 83/349, 0.1.2). Docs Hub 0.4.5/0.4.6 (#6615/#6617): 2 new symlink regression tests; suite 104 tests / 6,552 assertions (1 known Windows-only failure). Toolkit slash repair (#6618): 12/12 on WP 6.9 + 7.1; all 41 slash suites in one invocation 400/400. Content Graph in-session 1.0.8: Unit 100/524 + Integration 18/57 (2 new CommerceTest cases assert zero vendor HTTP calls). The `mcp-ai-wpoos-test-suite` skill stays at **47** patterns.
>
> **New in v1.1.78:** the slash-command suites were reworked for the declarative pipeline (#6604) — toolkit declarative registry + adapter + purge 26/26, workflow orchestrator suites 169/169, new prompts bridge 5/5, broad slash suite + dashboards 201/201, Pro slash + toolkit MCP server suites 109/109. The DeepSeek V4 Pro restoration (#6608) re-ran the model batches: `lib/core` standalone 447 tests / 1,400 assertions; content-graph-ai full Ecosystem suite 854 / 7,361 on both WP 6.9 and 7.1; base model batch (catalog, config, cost-calculator, deepseek-client, token-budget-manager, usage-tracker, rate-limits, config-renderer) 175 / 6,671 on both. Docs Hub 0.4.4 (#6606) adds `test-cache-staging.php` (4 tests) + 3 search-context tests in `test-rest-manifest.php` (one pre-existing Windows-only realpath failure noted). Content Graph unit suite 98 / 513 (mtime cache-busting #6598 + price sync #6603, incl. `CommerceTest::defaultPriceIs3499Cents`); the content-graph-ai model-management version test now derives from the bundled JSON (no future drift). The `mcp-ai-wpoos-test-suite` skill stays at **47** patterns.
>
> **New in v1.1.77:** the **base+pro regression matrix** lands — `tests/basepro/` (15 contract tests) + `phpunit-basepro.xml.dist` boots `WP_MCP_AI_BASE_VERSION=true` + `WP_ADMIN` + Pro loaded and pins the toolkit init-gate side effects (boot-time listener loads, inline CPT registrations, admin-menu hooks at 25/28), 12 representative tool availability checks, Cloudways/DietPi reason strings, webchat availability, Shopify Sync, vision/ext-cog helpers, and the Telegram Mini App media listing; a new `base-pro` CI job + `composer run test:basepro` make it the permanent pin. The DeepSeek V4.1 Flash refresh (#6555) aligned the model suites (catalog dropdown keys, removed-ids list, client `DEFAULT_MODEL`/payload, deep-research harness fixtures, composition-service seeded model) — model batch 170 tests / 6,677 assertions green. The onboarding-wizard suite gained 8 tests for the Knowledge Graph Companion preset (31/31 on WP 6.9 + 7.1, #6570). The Graphify bridge suite moved to the canonical `ai_agent_memories` slug (17/27 OK, #6591). Checkout-api suite grew through the launch series (51 → 63 → 67 tests; health + admin-page + boolean-serialization + 424/502 contracts, #6568/#6573/#6587/#6589). The `mcp-ai-wpoos-test-suite` skill grew to **47** patterns (42–47: standalone-plugin vendor shadowing, `get_routes()` list shape, `register_rest_route()` outside `rest_api_init`, `rest_url()` query form, Pro CLI include-time fatal, edit-tool non-ASCII mangling — #6586 + direct commit).
>
> **New in v1.1.76:** the scheduled-delivery suites grew again — `tests/test-pro-result-delivery-chat-format.php` (new in #6525: full template + per-channel format allowlists, Telegram parse-mode routing, reserved-char escaping, group-mention skip logging) and `tests/test-pro-result-delivery-email-format.php` extended with the duplicate-summary skip (#6548). Mempalace recall fixtures backdated one minute + fixture filters at `PHP_INT_MAX` (clock-skew flake, #6529); privacy-export order-independence via `assertEqualsCanonicalizing` (#6527); playbook-seeder hash regression ignores the `Generated:` timestamp (direct commit `fe4d0ee880`). Each Wave F2 port PR (#6505–#6549) carries its own `tests/` suite. The `mcp-ai-wpoos-test-suite` skill stays at **40** patterns.
>
> **New in v1.1.75:** the two scheduled-delivery fix PRs extended the delivery suites — `test-chat-channels.php` (string credentials fail gracefully: no TypeError, correct per-channel `failures` summary, #6482) and `test-pro-result-delivery-email-format.php` (sanitizer credential coercion + `normalize_channel_credentials()` decode/reject, #6482; fallback resolution from an enabled Remote Sites connection, assistant-assigned-connection preference, disabled-connection skip, and diagnostics shape with no secret leakage, #6488). The memory-bridge PR added `tests/test-mempalace-phase4a-graphify-bridge.php` (new, bridge projection + retriever-filter seams) and extended `test-orchestration-dashboard-memory-phase4a.php` (any-bridge detection). Each of the 24 Wave F2 port PRs (#6476–#6502) carries its own `tests/` suite (e.g. `test-financial-tools-b.php`, `test-social-tools-a.php`, `test-mcp-servers-framework.php`, `test-mcp-servers-batch-a/b.php`). The `mcp-ai-wpoos-test-suite` skill stays at **40** patterns.
>
> **New in v1.1.74:** the perf-suite MCP-abilities CI failure (#6464 → #6470) taught the **process-wide bootstrap guard** lesson: the WordPress Abilities registry is a lazy once-per-process singleton (`wp_abilities_api_init` fires exactly once), so third-party-plugin kill-switches (`mcp_adapter_create_default_server`, `wpmedia_mcp_oauth_server_enabled`) belong in `tests/bootstrap.php` before any suite runs — per-suite `setUp()` guards break the moment a stale PR base reorders suites; #6470 replaced #6464's suite-local guards with a pointer comment. New/extended suites: `test-pro-result-delivery-email-format.php` (14 tests — converter escaping, link-protocol rejection, `format_email()`/`build_email_html()`, `send_email()` routing for all three formats via `pre_wp_mail` + Nodemailer filter seams, sanitizer allowlisting, #6465); `test-pro-schedule-manager.php` extended (assistant_config merge — message update preserves untouched config fields; empty message rejected with stored value intact, #6469); `test-google-calendar-foundation.php` + platform `test-google-client.php` regressions assert the `%2B05%3A30` / `Asia%2FColombo` query encoding and the Standard profile's `SCOPE_FREEBUSY` (#6460). The `mcp-ai-wpoos-test-suite` skill stays at **40** patterns.
>
> **New in v1.1.73:** two new/extended Pro tool suites and a queue-resilience suite landed. `addons/pro/tests/test-bulk-update-products-tool.php` (18 tests, #6447) covers the new `scope` argument — schema enum/default, simple/variable/grouped round-trips, legacy `scope=product`, non-price/stock no-expansion, status-to-parent routing, per-target price adjustment, zero-stock status sync, dry-run, childless-parent per-ID failure, unknown-ID errors, invalid-scope fallback — and pins the acknowledged response-shape change (`targets[]` per input ID + `scope`/`updated_targets` keys). `addons/pro/tests/test-update-woo-product-qty-tool.php` (extended, #6448) asserts the `notify` schema default `true` and uses a recorder on `woocommerce_no_stock` to prove the `should_send` gate resolves `false` inside a `quantity => 0` call and back to `true` after — the scoped `woocommerce_should_send_*` filters must be removed in a `finally` (no global leak); stock writes + response shape unchanged under suppression. `tests/test-async-job-queue-table-resilience.php` (4 tests, #6423) uses the established real-DDL filter-lift pattern for the `maybe_create_table()`/`use_custom_table()`/fail-soft `get_queue_stats()` contract. The comic-reader addon's PHPUnit suites are now executed via a self-guarding `addons/comic-reader/tests/bootstrap.php` wired into the root bootstrap (#6402). The `mcp-ai-wpoos-test-suite` skill stays at **40** patterns.
>
> **New in v1.1.72:** the post-catalog drift trio (#6331, #6332, #6336) aligned the `gpt-image-2` OpenAI image default across all three settings layers — the lesson restated: defaults live in three layers (settings-base defaults, section field, client constant) and tests pin each layer, so change all three together. #6339 registered the container's `tool_registry` binding `transient` (every `get()` resolves the live singleton — safe against per-test instance swaps; the cached binding broke transcript-mining job logging). #6343 fixed the platform standalone CPT-wiring tests that failed whenever WooCommerce was active in the test environment. #6330 added the content-graph wp.org resubmission audit suites. The `mcp-ai-wpoos-test-suite` skill stays at **40** patterns.
>
> **New in v1.1.71:** the post-K16 singles PR (#6327, CI run 91942465749) closed the remaining order-dependent failures: the chat-transcript display-metadata suite restores the transcript repository mock **unconditionally** in tearDown (the repository is lazily created — the old null-guard left the mock installed for the rest of the process); `test-hooks-registry.php` tearDown restores the shared tool-registry instance **untouched** (calling `clear_tools()` on it wiped tools registered by one-shot `wp_mcp_ai_bootstrapped` actions — e.g. the Pro OKF tools — for every later suite); Site Health connectivity tests `unset` the other provider keys before asserting `good`; transcript-mining logging calls `WP_MCP_AI_Admin_Settings::reset_settings_cache()` after enabling logging. The checkout-api addon's 6 suites joined `phpunit.xml.dist` + `tests/bootstrap.php` (#6315). The `mcp-ai-wpoos-test-suite` skill grew to **40** patterns (38–40: Graphify graph-mode wing leak — production fix in `matches_wake_filters()`, PHP 8 static-callable rule, opt-in logging cache gates).
>
> **New in v1.1.70:** the **fifth** PHPUnit repair wave (~29 test-only PRs, #6280–#6312) was driven by two CI triage runs — `CI-TRIAGE-91006542428.md` and `CI-TRIAGE-91771001271.md` in `docs/developer/testing-docs/` — and closed every remaining single-process cluster (K1–K16). Production seams the suites now depend on: JetEngine availability gates require `JET_ENGINE_VERSION` (the real plugin's load marker) so file-scope stubs can't flip them, and `WP_MCP_AI_JetEngine_CCT::is_storage_available()` probes the physical CCT table — environments defining the `Jet_Engine` class without JetEngine installed must return `wp_mcp_ai_import_jetengine_missing`, not success (#6296, #6300); `cct_table_exists()` uses a direct `SELECT 1 … LIMIT 1` (transactional-DDL safe on MySQL 8.0, #6300); `WP_MCP_AI_Pro_CPT_AI_Integration::reset_for_tests()` (`@internal`) unhooks + drops the singleton for test fixtures (#6287); `wp_mcp_ai_legacy_sse_enabled` filters the blocking legacy SSE handshake (default unchanged — strict machine auth, PR #6302); `wp_mcp_ai_github_oauth_redirect_terminate` (#6301) and `wp_mcp_ai_plugins_integration_redirect_terminate` (#6303) filter seams suppress the handlers' terminating `exit`; `wp_mcp_ai_pro_get_tool_map()` caches the Pro tool map and the token-manager test asserts the superset contract (#6300); trace durations come from `started_at_ms` with a 1ms floor — same-second runs record non-zero durations (#6308); shared JetEngine stubs moved to `tests/helpers/jetengine-stubs.php` with per-suite `wp_mcp_ai_jetengine_stub_set_instance()` / `wp_mcp_ai_jetengine_stub_reset()` (#6300); a pinned test-clock helper landed in `tests/helpers/` for timeout tests (#6311); `pre_http_request` guards preempt the wp-cron loopback so transcript-mining ticks only run in-process (#6308); `$_REQUEST` must be populated (not just `$_POST`) when `check_admin_referer()` is involved (#6303). The `mcp-ai-wpoos-test-suite` skill keeps its **37** patterns and its cluster board is compacted to one completed wave-5 block; residual findings (skill-manager-ajax order-dependent TypeError, WP 7.1-only chat-transcript failures, unhealthy `oos-wp` container lock-waits) are tracked in `docs/project/plans/docs-catch-up-open-items.md` OI-4.
>
> **Repair workflow:** when a suite is *failing* (rather than being written),
> use the [`.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md`](../.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md)
> skill — Docker test commands (WP 6.9/7.1), CI-log triage, recurring
> root-cause patterns, and the cluster-by-cluster PR conventions live there.
> This file stays focused on test-writing patterns and the coverage policy.
>
> **New in v1.1.69:** a fourth, smaller PHPUnit repair wave (~9 test PRs: #6260, #6262–#6264, #6268, #6269, #6273, #6275, #6276) kept the suite aligned with current contracts; the `mcp-ai-wpoos-test-suite` skill grew to **37** patterns (#6265). Production seams the suites now depend on: `WP_MCP_AI_REST::check_rate_limit()` accepts the dispatching request's HTTP method so internal dispatches are classified by their real verb (#6265); the nefarious monitor keeps its own `wp_mcp_ai_nefarious_rate_limit_` counter (never shared with the chat REST limiter) (#6265); `wp_mcp_ai_before_chat_request` subscribers default every parameter and tolerate the legacy 2-arg `( $messages, $request_data )` shape (#6265); `WP_MCP_AI_Tool_Count_Tokens` rejects passing both `text` and `messages` (the filtering test passes a non-schema extra parameter instead, #6260); `WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::reset_for_tests()` clears the resettable static init guard (#6262); argument-less tool schemas encode `properties: {}` (never `[]`) — tests assert `wp_json_encode(...) === '{}'` (#6272); `WP_MCP_AI_Token_Budget_Manager::$model_limits` has a `gpt-4o` 128k entry and `is_video_capable_gemini_model()` includes `gemini-2.0-flash` (#6274); `handle_save_settings()` restores the pre-save cache-suspension state (read before suspending — `wp_suspend_cache_addition()` returns the new state) so inline-async tick locks keep working on WP 7.1 (#6277); SiteKit tools return string capability-flag arrays (#6278); `ensure_sortable_compatibility()` prints the bundled sortable when the core handle will not load and whenever `td_wp_admin` is enqueued (#6266, #6278); `collect_prebuilt_shortcut_tasks()` catches `\Throwable` per tool and coerces non-array filter results (#6271). Keep those seams when touching the corresponding production paths.
>
> **New in v1.1.68:** a third, smaller PHPUnit repair wave (~21 test PRs, #6224–#6257 plus the CI alpha-log refresh #6223) kept the suite aligned with current contracts. Production seams the suites now depend on: `WP_MCP_AI_Agent_Identity_Resolver::resolve()` casts alias-table canonical IDs to int (#6232); `WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit()` restored the `wp_mcp_ai_model_tpm_limit` filter seam across the CCT → catalog fallback (#6233); untrashing an assistant restores its pre-trash status via the `wp_untrash_post_status` filter (#6239); `WP_MCP_AI_Tool_Presets_Helper` excludes test-only doubles and includes the missing tools (#6242); `wp_mcp_ai_seed_task_templates` AJAX is registered on the settings dashboard (#6243); provider enable flags default to false on fresh installs with wizard auto-enable (#6255); post-edit admin scripts enqueue the core `jquery-ui-sortable` shim (#6258); the chat client self-heals stale nonces against `GET /mcp-ai/v1/session/nonce` (#6225); the `mcp-ai-wpoos-test-suite` skill grew to **27** patterns (#6255). Keep those seams when touching the corresponding production paths.
>
> **New in v1.1.67:** the Sep 1–2 repair-campaign continuation (~70 suite PRs, #6143–#6208) kept the suite green through the Content Graph platform extraction and ecosystem-port work plus PHPUnit 11 / WP 7.1 environment drift; the `mcp-ai-wpoos-test-suite` skill was refreshed to **26** root-cause patterns distilled from ~70 cluster PRs (#6154). Two new CI matrices landed with the extraction/port features: `.github/workflows/phpunit-platform.yml` (platform addon) and `.github/workflows/phpunit-ai.yml` (Content Graph AI monolith + standalone). Production seams the tests now depend on: the settings registry exposes `unregister_section()` (#6144) and settings-section tests clean up after themselves; `WP_MCP_AI_Tool_Token_Limits::get_tool_multiplier()` is public (#6189); REST tool-error reporting tolerates a null request (#6186); the WhatsApp webhook signature validator rejects a missing app secret (#6192); assistant pages render their modal structure on permission/disabled notices (#6174). Keep those seams when touching the corresponding production paths.
>
> **New in v1.1.66:** the Aug 28–31 repair campaign (~95 suite PRs, #6012–#6107) brought the single-process suite green cluster-by-cluster — REST endpoint clusters, AJAX handlers, provider/client suites, admin pages, chat/channel integrations, CRM, professions/teams, multi-agent orchestration, cron/notifier, federation, healthcare interop, security, logger, transcripts. The recurring root causes are catalogued in the new `mcp-ai-wpoos-test-suite` skill (16 patterns) and the standing work tracker is [`docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`](../developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md). Production seams the tests now depend on: `validate_assistant_access()` caches `WP_Error` results and the cache-disable path is a `wp_mcp_ai_assistant_access_cache_enabled` filter (never a persisted define in a test, #6008); attachment-segment validation errors carry `status => 400` (#6009); `WP_MCP_AI_Job_Notifier::update_status()` exists again and async job IDs preserve dots (#6036, #6037); progress events promote cached job status to running (#6039). Keep those seams when touching the corresponding production paths.
>
> **New in v1.1.65:** A cluster of suite-alignment PRs (mostly test-only): SSE streaming suites assert the explicit `stream` parameter contract (#5995); WP 7.1 icon-init replay is neutralised in the test bootstrap (#5996); transcript suite follow-ups handle CI environment differences (#5998); Phase 4 slash-command workflow tests run against the current handler API (#5999); SSE tool-result text-extraction tests use the REST constructor (#6001); the transcript retrieval roundtrip is skipped when the CCT table is missing (#6002). Production-side seams that tests depend on: assistant-builder + Pro toolkit blocks skip already-registered names so re-firing `init` doesn't raise notices (#5997); workflow-execution AJAX moved `wp_send_json_*` out of the try block so exceptions can't double-output (#6004). Keep those seams when touching block registration or the orchestration dashboard AJAX.
>
> **New in v1.1.64:** PRs #5931/#5935 — Pro admin classes load directly in AJAX suites, AJAX dispatch is hardened against leaked state, WordPress.com staging APIs are registered, WP All Import no longer kills the test bootstrap, and moved-file `require`s point at their current paths. PR #5960 — validated-tool tests now actually validate: constraint loading was silently skipped on Symfony 5.4 (`enableAnnotationMapping( true )`, not `enableAttributeMapping`), so suites that should fail on invalid input were running against unvalidated paths; keep constraint coverage real when touching the validator service. PR #5965 — envelope assertions must expect the canonical success array or `WP_Error` (never `success => false`).
>
> **New in v1.1.63 (PR #5929):** `bin/sweep-tests.php` sweeps every test file in parallel (6 workers, 180s/file cap, `--report` output) to surface exit-trap and drift failures. AJAX test contracts live in `tests/bootstrap.php` (see `tests/AJAX_TESTS_README.md`): admin AJAX hooks are re-registered per test, and handlers that `die()`/`exit()` directly are guarded with `WP_MCP_AI_TESTS_RUNNING` seams that throw a catchable exception instead. SSE and Veo polling loops honor `wp_mcp_ai_sse_job_max_polls` / `wp_mcp_ai_sse_job_poll_interval` / `wp_mcp_ai_veo_poll_max_attempts` / `wp_mcp_ai_veo_poll_interval` filters so tests can bound them. Remaining suite work is tracked in [`docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`](../docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md) — prefer `markTestSkipped()` with an explicit reason over deleting assertions, and never weaken security assertions to make a test pass.

---

## Test Framework

- **PHPUnit** via Composer: `composer run test`
- **Base class:** `WP_UnitTestCase` (WordPress test utilities)
- **Test directory:** `tests/` (base plugin), `addons/pro/tests/` (pro tests)
- **Config:** `phpunit.xml.dist`

---

## Test File Naming

| Test Type | File Location | Class Name |
|-----------|--------------|-----------|
| Feature/unit test | `tests/test-{feature}.php` | `Test_{Feature}` |
| REST API test | `tests/rest/test-{endpoint}.php` | `Test_{Endpoint}_REST` |
| REST API integration | `tests/rest-api/test-{name}.php` | `Test_{Name}_API` |
| Helper tests | `tests/helpers/test-{helper}.php` | `Test_{Helper}` |
| Memory tests | `tests/memory/test-{name}.php` | `Test_{Name}_Memory` |
| Pro feature test | `addons/pro/tests/test-{feature}.php` | `Test_Pro_{Feature}` |

---

## Minimal Test Class

```php
<?php
/**
 * Tests for {FeatureName}.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for {FeatureName}.
 */
class Test_{FeatureName} extends WP_UnitTestCase {

    /**
     * Set up test environment.
     */
    public function setUp(): void {
        parent::setUp();
        // Test setup: create users, posts, set options, etc.
    }

    /**
     * Tear down test environment.
     */
    public function tearDown(): void {
        // Cleanup: delete test data, restore options
        parent::tearDown();
    }

    /**
     * Test that {feature} works correctly with valid input.
     */
    public function test_{feature}_with_valid_input() {
        // Arrange
        $input = 'valid input';

        // Act
        $result = wp_mcp_ai_function( $input );

        // Assert
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
        $this->assertTrue( $result['success'] );
    }

    /**
     * Test that {feature} returns WP_Error with invalid input.
     */
    public function test_{feature}_returns_error_with_invalid_input() {
        $result = wp_mcp_ai_function( '' );
        $this->assertWPError( $result );
    }

    /**
     * Test that {feature} requires correct capability.
     */
    public function test_{feature}_requires_capability() {
        // Set unprivileged user
        wp_set_current_user(
            $this->factory->user->create( array( 'role' => 'subscriber' ) )
        );

        $result = wp_mcp_ai_function( 'input' );
        $this->assertWPError( $result );
        $this->assertEquals( 'forbidden', $result->get_error_code() );
    }
}
```

---

## Tool Testing Pattern

```php
/**
 * Tests for WP_MCP_AI_Tool_{Name}.
 */
class Test_Tool_{Name} extends WP_UnitTestCase {

    /**
     * @var WP_MCP_AI_Tool_{Name}
     */
    private $tool;

    public function setUp(): void {
        parent::setUp();
        $this->tool = new WP_MCP_AI_Tool_{Name}();

        // Create an admin user for privileged operations:
        $admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );
    }

    public function test_get_slug_returns_correct_slug() {
        $this->assertEquals( '{expected_slug}', $this->tool->get_slug() );
    }

    public function test_execute_create_action() {
        $result = $this->tool->execute(
            array( 'action' => 'create', 'name' => 'Test Item' ),
            array()
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }

    public function test_execute_requires_capability() {
        wp_set_current_user(
            $this->factory->user->create( array( 'role' => 'subscriber' ) )
        );

        $result = $this->tool->execute(
            array( 'action' => 'create' ),
            array()
        );

        $this->assertWPError( $result );
        $this->assertEquals( 'forbidden', $result->get_error_code() );
    }

    public function test_execute_returns_error_for_invalid_action() {
        $result = $this->tool->execute(
            array( 'action' => 'nonexistent_action' ),
            array()
        );

        $this->assertWPError( $result );
    }
}
```

---

## REST API Testing Pattern

```php
/**
 * Tests for /mcp-ai/v1/{endpoint}.
 */
class Test_{Endpoint}_REST extends WP_Test_REST_TestCase {

    public function setUp(): void {
        parent::setUp();
        $this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
    }

    public function test_get_items_authenticated() {
        wp_set_current_user( $this->user_id );

        $request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/{endpoint}' );
        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );
        $this->assertArrayHasKey( 'data', $response->get_data() );
    }

    public function test_get_items_unauthenticated_returns_401() {
        wp_set_current_user( 0 );

        $request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/{endpoint}' );
        $response = rest_do_request( $request );

        $this->assertEquals( 401, $response->get_status() );
    }
}
```

---

## Common Test Utilities

```php
// Create test user with role:
$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
wp_set_current_user( $user_id );

// Create test post:
$post_id = $this->factory->post->create( array(
    'post_type'   => 'mcp_ai_assistant',
    'post_status' => 'publish',
    'post_title'  => 'Test Assistant',
) );

// Assert WP_Error:
$this->assertWPError( $result );
$this->assertEquals( 'expected_code', $result->get_error_code() );

// Assert array structure:
$this->assertArrayHasKey( 'success', $result );
$this->assertArrayHasKey( 'data', $result );
$this->assertIsArray( $result['data'] );

// Assert hook fired:
$this->assertFalse( has_action( 'wp_mcp_ai_hook', 'my_callback' ) );
do_action( 'wp_mcp_ai_hook' );
// ...
```

---

## Minimum Test Coverage Per Story

Every story that modifies or creates a tool must have **at least 3 test methods**:
1. Happy path (valid input, expected result)
2. Error path (invalid input, returns `WP_Error`)
3. Capability check (unprivileged user is rejected)

REST endpoint stories need at minimum:
1. Authenticated GET/POST returns expected status code
2. Unauthenticated request returns 401
3. Invalid input returns 400

---

## Running Tests

### Option A: Docker (recommended for Windows / no local PHP)

```bash
# Prerequisites (once):
composer install                        # install dev dependencies
docker compose up -d                    # start WP + MySQL containers

# Run tests:
bash bin/run-tests-docker.sh                                   # all tests
bash bin/run-tests-docker.sh tests/test-admin-settings.php     # single file
bash bin/run-tests-docker.sh --filter='test_default_provider' tests/test-admin-settings.php
```

The script automatically:
- Creates the `wordpress_test` database if missing
- Refreshes the Composer autoloader (fixes stale bind-mount caches)
- Handles Git Bash path mangling (`MSYS_NO_PATHCONV`)
- Passes all arguments through to PHPUnit

> **Note:** After `composer install` or `composer dump-autoload`, restart Docker
> if tests fail with class-not-found errors — the bind mount can cache stale files.
> Shortcut: `docker compose down && docker compose up -d`

### Option B: Local PHP + MySQL

```bash
# Install test dependencies (first time only):
composer run test:install

# Run all tests:
composer run test

# Run a specific test file:
vendor/bin/phpunit tests/test-{name}.php

# Run tests with verbose output:
vendor/bin/phpunit --verbose

# Run with coverage (requires Xdebug):
vendor/bin/phpunit --coverage-html coverage/
```

### Option C: Codex / SQLite (self-contained, no Docker)

```bash
bash bin/codex-startup.sh               # download WP + SQLite, start server
composer run test                       # run tests against the Codex WP
```

---

## Coverage Policy (PHPUnit Test Coverage Gap-Filling Plan)

**Every PR that adds new code must add at least one PHPUnit test in the same PR.** Specifically:

| Change | Required tests |
|---|---|
| New base tool (`includes/tools/class-*.php`) | `tests/test-tool-{slug}.php` covering at minimum the unauthorised-user case + one happy path |
| New pro tool (`addons/pro/includes/tools/class-*.php`) | Test under `addons/pro/tests/` referencing the tool class name |
| New REST controller / route | Permission callback + schema + at least one happy path under `tests/rest/` or `tests/rest-api/` |
| New slash command (`includes/slash-commands/commands/class-*.php`) | `tests/test-slash-command-{name}.php` with output shape + capability gate + alias resolution |
| New harness layer (`includes/harness/`, `addons/pro/includes/harness/`) | Layer enable/disable + documented filter (`wp_mcp_ai_harness_*`) firing |
| New service class | Either a direct unit test or coverage via the REST/tool surface that consumes it |

### Baseline & non-regression gate

- The per-subsystem coverage floors live in [`tests/.coverage-baseline.json`](../tests/.coverage-baseline.json).
- The `PHPUnit` GitHub workflow runs `bin/find-untested-classes.sh --check` and fails any PR that drops the count of covered classes for a subsystem below its baseline.
- Floors **must only ratchet upward**. Never lower a floor without an explicit justification in the PR description.
- Locally, run `composer run test:gaps` to see the full list of untested classes per subsystem, or `composer run test:gaps:check` to verify the baseline before opening a PR.

### Recomputing the baseline after coverage improves

```bash
# Print current covered counts for all subsystems
bin/find-untested-classes.sh --check
# Then update tests/.coverage-baseline.json so subsystem_floors.<name>.covered_classes_min
# matches the new (higher) covered count, and bump test_file_floor.min_count if it grew.
```

The baseline file's `subsystem_floors` keys correspond 1:1 to the categories in §2 of the PHPUnit Test Coverage Gap-Filling Plan.

---

## Tool registry coverage smoke test

`tests/test-tool-registry-coverage.php` is a single data-driven smoke test that locks the contract for every registered tool in one place. It asserts:

1. `get_slug()` returns a non-empty string that survives `sanitize_key()` unchanged.
2. The parameter schema (from `get_parameters_schema()` or `get_definition()['parameters']`) contains no `'mixed'` types and every `type:'array'` declares `items`.
3. `get_required_capability()` (when present) resolves to a non-empty string or array of strings.
4. `execute()` does not throw when invoked by an unauthenticated caller (a logged-out user with no capabilities).

It is paired with two manifest files that list every tool-class file basename so `bin/find-untested-classes.sh` recognises the smoke test as covering the entire tool registry:

- `tests/tools/.coverage-manifest.txt` — base tools under `includes/tools/`
- `addons/pro/tests/tools/.coverage-manifest.txt` — pro tools under `addons/pro/includes/tools/`

**Whenever you add, remove or rename a tool class, regenerate the manifest:**

```bash
bin/generate-tool-coverage-manifest.sh
```

The smoke test itself includes an assertion that fails when the manifest is stale, so CI catches drift even if a contributor forgets the regen step.

When you want to add behavioural coverage for a high-risk tool (write/state-changing, external API, file/upload), add a dedicated test under `tests/tools/` (base) or `addons/pro/tests/tools/` (pro) — those tests stack on top of the smoke test rather than replacing it.

### Coverage matcher: kebab → PascalCase

`bin/find-untested-classes.sh` recognises a class as "covered" if any test file under `tests/` or `addons/*/tests/` references **either**:

1. The kebab-case file basename (e.g. `wp-mcp-ai-harness-profile`), as appears in the coverage manifests, or
2. The PascalCase class name derived from that basename (e.g. `WP_MCP_AI_Harness_Profile`), as appears in normal PHPUnit `use`/instance-of references.

The acronyms `WP`, `MCP`, and `AI` stay fully uppercase; every other segment is title-cased. So `wp-mcp-ai-pii-filter` matches `WP_MCP_AI_Pii_Filter`, and `wp-mcp-ai-tool-router-harness` matches `WP_MCP_AI_Tool_Router_Harness`.

Tests therefore do not need to mention class file basenames; referencing the class symbol naturally is enough to credit coverage.
