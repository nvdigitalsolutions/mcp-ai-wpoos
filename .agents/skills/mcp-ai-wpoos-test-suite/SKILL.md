---
type: Skill
name: mcp-ai-wpoos-test-suite
description: Repair and triage guide for the NV oOS PHPUnit test suite — Docker test environment (incl. cross-worktree one-off runners), CI log triage, 37 recurring root-cause patterns (hook resets, singleton interference, zombie mocks, WP_Error envelope drift, SSE blocking-emitter contract, sub-tab sanitizer routing, rest_api_init DDL commits, cron-array lookups, Pro autoload gaps, three-layer settings defaults, capability-gated renders, rate-limiter contracts, dual-shape action emitters, Docs Hub addon contracts), cluster-by-cluster PR workflow against alpha-working, and validation gates. Use when fixing failing PHPUnit tests, triaging CI logs, repairing test drift, deciding between a production fix and a test fix, or starting a new fix cluster.
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  last-updated: "2026-09-03"
---

# NV oOS Test Suite — Repair & Triage Guide

Operational playbook for keeping the single-process PHPUnit suite green,
one cluster (suite) at a time. Distilled from ~70 cluster PRs (#6084–#6153)
against the `alpha-working` branch. Complements `.context/testing.md` (how to
*write* tests) — this skill covers how to *fix* a failing suite and how the
repair loop runs.

## When to use this skill

- A PHPUnit suite is failing in CI, locally, or both
- Triaging a fresh CI log zip (`logs_*.zip`)
- "Continue the test-suite cluster" / "fix the next cluster" requests
- Deciding whether a failure is test drift or a production bug
- Opening the cluster PR (branch/commit/validation conventions)

## Test environment (Docker)

The test suite runs inside the `oos-wp` container (plugin bind-mounted at
`/var/www/html/wp-content/plugins/mcp-ai-wpoos`, DB in `oos-wp-db`). Run from
the repo root on the Windows host with `MSYS_NO_PATHCONV=1`.

**WP 6.9 (primary):**

```bash
MSYS_NO_PATHCONV=1 docker exec -e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db -e WP_DB_NAME=wordpress_test -e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress oos-wp sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php -d memory_limit=1G vendor/bin/phpunit <paths> --no-coverage 2>&1'
```

**WP 7.1 (second validation):** swap `WP_CORE_DIR=/tmp/wp71` and
`WP_DB_NAME=wordpress_test_wp71`.

**phpcs (the CI gate counts ERRORS, not warnings):**

```bash
MSYS_NO_PATHCONV=1 docker exec oos-wp sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php vendor/bin/phpcs --standard=phpcs.xml.dist --error-severity=1 --warning-severity=1 --report=summary <paths> 2>&1'
```

Rules:

- **Never run two `docker exec phpunit` concurrently** — they collide on the
  shared `wordpress_test` DB.
- Validate every cluster on **both** WP 6.9 and WP 7.1 before opening the PR.
- The local bootstrap lacks JetEngine and Graphify; the CI bootstrap loads
  them. A suite skipped locally can still fail in CI — and vice versa.
- `phpcbf` can auto-fix (e.g. array alignment): run it inside the container,
  it edits the bind-mounted file directly.

### Cross-worktree runs (when `oos-wp` mounts a different worktree)

The `oos-wp` plugin path bind-mounts **one** worktree. Check which one:

```bash
docker inspect oos-wp --format "{{json .Mounts}}"
```

If the mounted path is not the worktree you are editing, `docker exec oos-wp`
runs the wrong code. Instead run a one-off container on the same network
sharing the WP-core volume, with your worktree mounted over the plugin path:

```bash
# 1. Build a Linux vendor into a named volume (the Windows-host vendor/
#    breaks inside Linux — classmap paths with backslashes →
#    'Class "PHPUnit\\TextUI\\Application" not found').
docker volume create <worktree>-vendor
MSYS_NO_PATHCONV=1 docker run --rm \
  -v F:/GITHUB/worktrees/mcp-ai-wpoos/<worktree>/mcp-ai-wpoos:/app:ro \
  -v <worktree>-vendor:/app/vendor \
  composer:2 sh -c 'cd /app && composer install --no-interaction --prefer-dist --no-progress'

# 2. Run the tests (nested mounts: volume over the worktree's vendor/ works).
MSYS_NO_PATHCONV=1 docker run --rm \
  -e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db -e WP_DB_NAME=wordpress_test \
  -e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress \
  -v oos-wp_wp_core:/var/www/html \
  -v F:/GITHUB/worktrees/mcp-ai-wpoos/<worktree>/mcp-ai-wpoos:/var/www/html/wp-content/plugins/mcp-ai-wpoos \
  -v <worktree>-vendor:/var/www/html/wp-content/plugins/mcp-ai-wpoos/vendor \
  --network oos-wp_default \
  wordpress:6.9-php8.2-apache \
  sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php -d memory_limit=1G vendor/bin/phpunit <paths> --no-coverage'
```

**WP 7.1 cross-worktree:** the wp71 core lives in the `oos-wp` container's own
`/tmp/wp71` (NOT in the shared `oos-wp_wp_core` volume). Copy it out, mount it
at `/tmp/wp71` in the one-off run, and swap `WP_CORE_DIR=/tmp/wp71` +
`WP_DB_NAME=wordpress_test_wp71`:

```bash
docker cp oos-wp:/tmp/wp71 ./docker-tmp-wp71
# add: -v F:/GITHUB/worktrees/mcp-ai-wpoos/<worktree>/mcp-ai-wpoos/docker-tmp-wp71:/tmp/wp71
```

Clean up afterwards: `rm -rf docker-tmp-wp71` and `docker volume rm <worktree>-vendor`
— never stage either artifact. The no-concurrent-phpunit rule applies to
one-off runners too (same shared DB).

## Cluster → PR workflow

1. `git fetch origin alpha-working` (auto-gc may make this time out — verify
   with `git --no-pager log -1 --oneline origin/alpha-working` and retry).
2. Create a branch per cluster from `origin/alpha-working`:
   `git switch -c fix/<cluster-slug> origin/alpha-working`. Never stack on a
   previous branch — uncommitted worktree edits carry over on switch.
3. Reproduce locally, fix, validate (both WP versions + phpcs).
4. Stage explicit paths only (`git add <file>` — **never `git add -A`**, and
   **never stage `vendor/`**; the worktree carries local vendor edits that
   must not be committed). On Windows Git Bash `2>nul` creates a literal
   `nul` file in the repo — delete stray artifacts before staging.
5. Commit with imperative subject ≤ 50 chars; PR base is `alpha-working`.
6. The user merges manually; move to the next candidate regardless.

## CI log triage (`logs_*.zip`)

1. Extract to `logs_<id>/` and parse `0_test.txt`. Every line has a timestamp
   prefix; failures are numbered blocks `NN) Suite::method` followed by the
   assertion message.
2. Group by suite:

   ```bash
   sed -n '<first-failure-line>,<last-failure-line>p' logs_<id>/0_test.txt \
     | grep -oE "[0-9]+\) [A-Za-z_0-9]+::[A-Za-z_0-9]+" \
     | sed 's/^[0-9]*) //' | awk -F'::' '{print $1}' | sort | uniq -c | sort -rn
   ```

3. Identify which code the run tested: grep `refs/remotes/pull/NNNN/merge` —
   the run is the merge of PR NNNN into its base at checkout time.
4. A suite with **zero occurrences** in the log passed.
5. Standalone-pass-but-full-run-fail ⇒ order-dependent interference
   (shared singletons, leaked filters) — see patterns below. Reproduce by
   running the suspect suites in **one** phpunit invocation.

## Recurring root-cause patterns (symptom → fix)

1. **Hook-table restore between tests.** wp-phpunit restores `$wp_filter`
   between tests, dropping hooks registered by other suites or by
   `is_admin()`-gated bootstrap code. Symptom: `$submenu` null, enqueue hooks
   dead, admin pages missing. Fix: re-register in `setUp()` — instantiate the
   class, `require` the admin file, or re-invoke private init hooks via
   `ReflectionClass` (see `Test_Admin_Hook_Suffixes` for the
   `init_hooks()`-re-invoke pattern).
2. **`do_action( 'init' )` in tests re-fires WooCommerce/block registrations**
   → "…is already registered" incorrect-usage notices fail the test. Fix:
   call the specific init function directly
   (e.g. `wp_mcp_ai_init_slash_commands()`, `$shortcode->register_assets()`).
3. **WP_Error vs array envelope drift.** Tools were swept from
   `array('success'=>false, …)` to `WP_Error`. Tests assert
   `assertWPError()` + `get_error_code()`. Coordination tools wrap results in
   nested envelopes (`result['team']`, `result['delegation']`,
   `result['aggregation']`) — check the tool's `execute()` return before
   asserting. The REST server converts a controller `WP_Error` into a
   **400 `WP_REST_Response`** (`error_to_response`): assert status +
   `$data['code']`, not `is_wp_error( $response )`.
4. **Nonces bind to the current user ID.** `wp_create_nonce()` must run
   *after* `wp_set_current_user()`. Symptom: save/metabox tests silently bail
   and meta stays empty.
5. **WooCommerce `get_current_page()` doing-it-wrong** when
   `admin_enqueue_scripts` fires before `current_screen`. Fix:
   `set_current_screen( '...' )` then
   `do_action( 'current_screen', get_current_screen() )` — pass the
   `WP_Screen` object, not nothing.
6. **Script/style queue leaks + WP 6.9 `all_queued_deps` memoization.**
   `wp_script_is( $h, 'enqueued' )` falls back to a cached recursive dep set
   that is NOT invalidated by direct array assignment. Reset via the public
   API so the memo invalidates:

   ```php
   global $wp_scripts;
   foreach ( (array) $wp_scripts->queue as $handle ) {
       wp_dequeue_script( $handle );
   }
   foreach ( (array) wp_styles()->queue as $handle ) {
       wp_dequeue_style( $handle );
   }
   ```

7. **Shared singleton state.** `WP_MCP_AI_Tool_Registry` (and friends) persist
   across tests; suites like `test-tool-registry.php` and
   `test-hooks-registry.php` call `clear_tools()` in their teardown. A later
   suite's `init()` then no-ops on a partially bootstrapped state. Fix in the
   consuming suite's `setUp()`:

   ```php
   $registry = WP_MCP_AI_Tool_Registry::get_instance();
   $registry->clear_tools();
   $registry->init();
   ```

   Likewise, filters added at bootstrap (e.g. the `tools-init.php` side-loader
   on `wp_mcp_ai_default_tools`) pollute filter-contract tests — isolate with
   `remove_all_filters( $hook )` inside the test.
8. **Anonymous classes cannot access `protected` members of the enclosing
   class** (they do not inherit its scope). And production `catch ( Throwable )`
   swallows the resulting `Error`, so the request returns 200 with **zero side
   effects** — the failure shows up as "0 saved records", not an exception.
   Fix: make the captured property `public` (test-only), or store records on
   the mock itself (`public $records = array()`), like
   `test-chat-transcript-cct-author-id.php`.
9. **Message content is stored as segments.** The REST validator stores
   message `content` as `array( array( 'type' => 'text', 'text' => '…' ) )`.
   Tests asserting `$msg['content']` as a string must extract
   `$msg['content'][0]['text']`.
10. **Registered meta sanitization applies on `update_post_meta`.**
    `wp_kses_post` HTML-escapes (`&` → `&amp;`);
    `sanitize_text_field` strips `<script>` elements **together with their
    content** (so `'<script>alert(1)</script>'` → `''`, not `'alert(1)'`).
    Assert against the sanitized value.
11. **`absint()` flips negatives to positives** (`absint(-999)` → `999`).
    When the contract is "drop/clamp negatives", use
    `max( 0, (int) $value )` — the codebase documents this exact anti-pattern
    in `sanitize_associated_assistant_meta()`.
12. **`wp-admin/menu.php` never runs in PHPUnit**, so `add_submenu_page()`
    falls back to the generic `admin_page_*` hook suffix instead of the
    production `{post_type}_page_{slug}` suffix. Register the parent CPT in
    `setUp()` and, if needed, inject the production suffix via reflection on
    the page object's `page_hook`.
13. **Validated-tool auto-upgrade.** `WP_MCP_AI_Tool_Registry::get_tool(
    'base_slug' )` resolves to the `_validated` variant when registered;
    validation rejects out-of-range args with `validation_failed` before
    execution. Expect that when asserting tool behavior.
14. **Risky tests (zero assertions).** Environment-dependent `if` branches
    skip all assertions when vendor packages/Node/JetEngine exist on the host.
    Risky tests don't fail CI (`phpunit.xml.dist` has no `failOnRisky`) but
    they mask dead tests. Fix by adding a production filter seam
    (e.g. `wp_mcp_ai_vendor_package_paths`) and forcing deterministic state in
    the test.
15. **Obsolete skip gates hide failures.** A suite that mocks its
    dependencies doesn't need the JetEngine skip gate — the gate only makes it
    pass locally and fail in CI. Remove it when the mock makes the suite
    self-contained.
16. **Test drift.** Handles renamed (`mcp-ai-*` → `wp-mcp-ai-*`), POST field
    names changed, per-metabox nonces added, REST response shapes changed.
    Check `git --no-pager log -1 -- <test-file>` and align the test with the
    *current* production contract — but verify the production behavior is
    intentional (see "Production fix vs test fix" below).
17. **Zombie mocks — PHPUnit clears mock stubs after each test.** A mock
    stored in a static/global registry (`WP_MCP_AI_Settings_Registry`
    sections) survives into later suites with its stubs wiped, so stubbed
    methods return null. Symptom: "Section should have an ID" / "Failed
    asserting that null is not null" while *iterating a later suite's*
    sections. Fix: add an `unregister_*()` API to production and have the
    registering test clean up after itself (#6139, #6144).
18. **`has_action()` / `has_filter()` return the priority (int), not bool.**
    Assert `assertSame( 10, has_action( ... ) )`. Reverse failure: a
    lazily-loaded self-instantiating class registers its hooks *after* the
    process-wide hook-table backup, so wp-phpunit's per-test restore wipes
    them — re-register in `setUp()` when the load happened out of order
    (#6143).
19. **Sub-tabbed / view-routed settings sanitizers return `array()` when the
    routing POST field is absent** (so nothing is saved). Set
    `$_POST['subtab_<section_id>']` for sub-tabbed pages (#6147) or
    `$_POST['view']` for orchestration views (#6148) before calling
    `sanitize()` (#6139).
20. **SSE blocking-emitter contract.** Streaming paths echo SSE frames
    directly during dispatch and return a response with no `Content-Type`/
    CORS headers. Assert emitted frames (`retry:`, `event: …`, `data: {`,
    `data: [DONE]`) via an output-capture helper — never response headers
    (#6141, #6149).
21. **`rest_api_init` re-fire triggers third-party temp-table DDL**
    (Elementor `e_events`), and DDL implicitly commits the per-test
    transaction, leaking fixtures across tests. Bootstrap the REST controller
    *before* creating fixtures. Probe DDL with a temp-table create/drop; the
    DDL fires during `rest_api_init`, not during dispatch (#6141).
22. **`wp_next_scheduled()` defaults to empty `$args`**, so args-carrying
    jobs (`wp_schedule_single_event( $hook, $job_args )`) are invisible to it.
    Scan `_get_cron_array()` by hook name instead (#6153).
23. **Pro autoload gap.** CI runs `composer install` in `addons/pro/` so it
    executes Pro-dependent suites that local runs silently skip (the committed
    classmap does not map `includes/`). Close with a bootstrap fallback
    autoloader (slug → `class-wp-mcp-ai-<slug>.php` glob). Note the slug
    offset: `strlen( 'WP_MCP_AI_' )` is **10**, not 11 — an 11-char cut drops
    the first slug char (`ro-agent-command-center`) (#6143).
24. **`get_post_meta()` returns strings** — registered `integer` meta
    sanitizes on *write*, not read. Production itself `absint()`s on read;
    assert via `absint( get_post_meta( ... ) )` (#6150).
25. **Settings feature-flag splits.** `enable_federation` no longer implies
    `enable_federation_directory`; enable the specific flag and call the
    feature loader directly (`maybe_load_federation_features()`) because
    `init` already fired (#6150). After `update_option()` on
    `WP_MCP_AI_Admin_Settings::OPTION_NAME`, call `reset_settings_cache()` to
    drop the static settings cache (#6146).
26. **Addon custom tables never get installed under PHPUnit** — activation
    hooks don't run. Per-addon suite bootstraps (`tests/*/bootstrap.php`)
    must call the schema installer directly
    (`NV_oOS_Graphify_DB::install()`) so CI tables exist (#6145).
27. **Settings defaults live in three layers** — the section field
    `'default'` in `get_fields()` (drives checkbox render state via
    `WP_MCP_AI_Settings_Section::render_field()`), the base defaults in
    `WP_MCP_AI_Admin_Settings_Base::get_default_settings()` (merged into
    `get_settings()`), and runtime fallbacks like
    `isset( $settings[ $key ] ) ? $settings[ $key ] : false`. Fixing only one
    layer leaves fresh installs inconsistent: #6255 flipped the runtime
    fallback in `get_available_providers()` but the section fields still had
    `'default' => true`, so the OpenAI/Anthropic/Gemini enable checkboxes
    rendered checked on fresh installs and any Providers-page save persisted
    them. When changing a default, update all three layers and the test that
    codifies the field default (e.g. `test_provider_enable_field_defaults`).
28. **Capability-gated render paths.** The chat shortcode/block render
    enforces the chat capability (`wp_mcp_ai_chat_capability` filter, default
    `edit_posts`) — without a capable current user the render silently
    produces no chat markup. Fix: create an administrator and
    `wp_set_current_user()` in `setUp()` (reset to 0 in `tear_down()`),
    like `test-chat-template-selector.php`.
29. **Rate-limiter test contract: non-429 means allowed through.** Any
    status other than 429 means the limiter passed the request through to the
    handler; without a configured provider the handler returns 400
    `wp_mcp_ai_missing_api_key`, which is unrelated to the limiter. Don't
    count 200/500 as "success". Also: `WP_MCP_AI_REST::check_rate_limit()`
    accepts the dispatching request's HTTP method so internal dispatches
    (`rest_do_request()`, WP-CLI) are classified by their real verb instead
    of the ambient `$_SERVER['REQUEST_METHOD']`; GET/HEAD stay exempt.
30. **Dual-shape action emitters.** `wp_mcp_ai_before_chat_request` has a
    canonical `( $assistant_id, $messages, $options, $request )` shape, but
    legacy/custom emitters (and unit tests) fire the 2-arg
    `( $messages, $request_data )` shape. Subscribers (nefarious monitor,
    OOS shadow runner, harness trace capture) must default every parameter
    and detect the legacy shape when the first argument is an array — never
    give a subscriber a strict signature.
31. **Shared rate-limit counters halve budgets.** The nefarious-usage
    monitor deliberately namespaces its transient
    (`wp_mcp_ai_nefarious_rate_limit_`) away from the chat REST limiter
    (`wp_mcp_ai_rate_limit_`) — sharing one counter halves the configured
    chat budget and entangles the two enforcement paths in tests.
32. **Transcript payload includes the assistant reply.** The chat service
    appends the final assistant response to the conversation before
    persisting the transcript, so `$request_payload['messages']` carries the
    user message plus the assistant reply. Assert both (role `user` then
    `assistant`).
33. **Docs Hub TOC anchors must mirror github-slugger** (the library behind
    rehype-slug): slug the Markdown-*stripped* text — not the raw line
    (`### [Core Architecture](core/)` otherwise gets an anchor that doesn't
    exist) — preserve Unicode letters, do NOT collapse hyphen runs, and
    dedupe repeats with `-1`, `-2`, … suffixes.
34. **Docs Hub link fixer: resolve-then-contain path guard.** Link targets
    must be plain relative paths — no URL schemes, absolute paths,
    backslash separators, or NUL bytes. Relative `../` is legitimate and NOT
    rejected; safety comes from `realpath()` containment of the resolved
    destination against the filterable `nvoos_docs_hub_fixer_allowed_roots`
    list (extend it with your temp dir in tests). Remote-sourced pages are
    never fixable server-side (flat content-hash cache) — the admin UI shows
    "Remote source" rows. Source resolution prefers the page `slug` because
    relative paths like `README.md` collide across addons.
35. **Docs Hub rebuild job contracts.** The sync rebuild honours the
    aggregate file cap (`nvoos_docs_hub_max_files_total`, default
    `NV_oOS_Docs_Hub_Rebuild_State::DEFAULT_MAX_FILES_TOTAL` = 5000).
    `promote_staging()` failure throws — never report "Rebuilt N pages"
    with nothing persisted. The search REST envelope is identical with or
    without an index (`results`, `total`, `query`).
36. **Core emoji loader corrupts React-managed SPAs.**
    `_print_emoji_detection_script()` installs a MutationObserver that
    replaces emoji text nodes with `<img>` elements anywhere in the
    document; React keeps direct text-node references, so the next commit
    throws `NotFoundError: Failed to execute 'removeChild'/'insertBefore' on
    'Node'` and unmounts the app ("left panel links stopped working"). The
    Docs Hub shortcode render removes that action (plus the legacy detection
    and emoji styles) before output; tests assert the removals. No static
    done-guard — it would break cross-suite test isolation.
37. **Addon test bootstrap without activation.** PHPUnit never runs
    activation hooks, so addon suites define the addon's constants
    (`NVOOS_DOCS_HUB_VERSION/PATH/URL/FILE`) and `require` the classes they
    need directly in `setUp()`; filters like
    `nvoos_docs_hub_fixer_allowed_roots` provide the temp-dir seams.

## Production fix vs test fix

- **Fix production** when the test exposes a genuine bug: unsafe coercion
  (pattern 11), a latent fatal on a real code path (Graphify admin classes
  required only under bootstrap `is_admin()`, #6106), or a missing filter seam
  that blocks deterministic testing (#6097). Keep production edits minimal and
  behavior-compatible; document the contract change in the PR.
- **Fix the test** when it documents an outdated contract or an environment
  assumption. Never weaken a security assertion (nonce/capability) to make a
  test pass; prefer structural fixes over `setExpectedIncorrectUsage()`
  (ineffective on WP 7.1).

## Debugging workflow

Scratch test files under `tests/` with `fwrite( STDERR, … )` are the fastest
instrument; `spl_object_hash( $this )` distinguishes instance identity when a
mock seems to write to the "wrong" object. **Delete scratch files before
committing.**

```php
fwrite( STDERR, 'STATE: ' . wp_json_encode( $data ) . PHP_EOL );
```

## Enumerating the suite

Canonical scan dirs: `tests`, `addons/pro/tests`,
`addons/canvas-toolkit/tests`, `addons/media-studio/tests`,
`addons/saas-controller/tests`. Exclude: `tests/manual`, `tests/fixtures`,
`tests/regression`, `tests/helpers`, all `bootstrap.php` /
`wp-tests-config.php` / `wp-cli-smoke.php` files, the abilities mock tool and
bootstrap trait, the paper-store helpers trait, the graphify and
saas-controller bootstraps, and
`addons/pro/tests/class-wp-mcp-ai-workflow-log-context-recorder-tool.php`.
Batched runs sort the remaining files alphabetically and resume from a
suffix index (chunk manifests list files, they are not inputs).

## Cluster state board

Completed clusters (merged or open against `alpha-working`): #6084 CRM
toolkit, #6085 CRM data store, #6086 Huggingface, #6087 Veo, #6088 transcript
mining, #6089 quiz admin pages, #6090 document template pages, #6091
multi-agent AJAX, #6092 chart JS enqueue, #6093 admin hook suffixes, #6094
orchestration modes, #6095 REST tools controller, #6096 multi-agent
orchestration, #6097 NPM notice, #6098 multi-agent dashboard, #6099 slash
command integration, #6100 profession media vector, #6101 base knowledge
seeder, #6102 pro dashboard diagnostic, #6103 chat conversation CCT, #6104
admin test model, #6105 profession team CPT sanitization, #6106 Graphify admin
classes, #6107 toolkit + hooks registry, #6108 test-suite skill, #6109 Shopify
sync CCT manager, #6110 semantic compressor, #6111 usage tracker pricing
drift, #6112 docs catch-up, #6113 Content Graph AI credential encryption,
#6114 WP 7.1 Woo role resync, #6115 create post taxonomy application, #6116
site creator tools drift, #6117 toolkit registry singleton drift, #6118
PHPUnit loader excludes, #6119 credential resolver cache invalidation, #6120
capability flags model drift, #6121 settings suite test drift, #6122 slash
command tool mode contract, #6124 crawler job contract, #6125 Crawl4AI tool
failures, #6126 transcript mining job cluster, #6127 save post content drift,
#6128 tool slug integrity drift, #6129 tool coverage manifest, #6130 bulk
auto dispatch inline, #6131 TPM fallback model drift, #6132 async registry
construct errors, #6133 base version polluters, #6134 cache helper cluster,
#6135 transcript recorder repository, #6136 translation loading timing,
#6137 profession playbook seeder, #6138 site health tool, #6139 settings
dashboard, #6140 slash command sync docs, #6141 REST assistant directory,
#6143 Pro test autoload, #6144 new regressions, #6145 Graphify connectors,
#6146 rate limit backoff, #6147 provider subtab settings, #6148 orchestration
slider settings, #6149 MCP client configuration, #6150 federation test,
#6153 Google Chat fields (open).

Newer merged clusters (post-#6153, discovered via the PR merge log):
#6249 Hermes dashboard fleet extensions, #6251 Auth0 toggle, #6252 Auth0
menu, #6253 docs-hub emoji DOM crash, #6254 auto-categorize, #6255 provider
defaults, #6256 Pro SPA v2 shortcode, #6257 chart tiers, #6258 theme
sortable compat, #6259 chat attachments, #6260 count-tokens params, #6262
continuation seam, #6263 chat SSE handler, #6264 cache helper, #6265
nefarious rate limiter + chat hook tolerance (open).

Session 2026-09-04/05 wave-5 clusters (runs 91006542428 + 91771001271,
all merged): #6280 chat transcripts + attachments, #6281 charts + Pro
dashboard, #6282 professions/teams, #6283 presets/remote connections,
#6284 Cloudflare, #6285 mesh networking, #6286 memory auto-capture,
#6287 AI CPT management integration, #6288 PayHere vs Remote
Connections, #6289 Mubert music contract, #6290 OpenAI classic Images
API, #6291/#6297 composer metadata drift, #6292 Content Graph AI
ecosystem, #6293 pro schedule AJAX + curriculum exporter, #6294
cluster-board docs, #6295 exec-disabled host hardening, #6296
conversation-import CCT gate, #6298 model routing/complexity routing,
#6299 Gemini, #6300 JetEngine/Graphify stub isolation + gates, #6301
OAuth/credentials encryption, #6302 REST + skill-pack registry, #6303
admin/settings drift, #6304 K10 Elementor, #6305 K11 permission gates
(acting-user capability checks), #6306 K12 HTTP/provider clients, #6307
K13 orchestration budget, #6308 K14 logging/events (recent-activity
allowlist + trace durations), #6311 K16 misc singles (webhook
round-trip, settings-repository blob fallback, chart HTML attachment
gating, site-builder heading tags, PSO keyword inflections,
scheduled-post publish date reset + 16 suite contract updates), #6312
K15 skill-suite upload isolation (pack normalisation + install action
landed via direct commit d452a125bf).

Remaining candidates change quickly; re-triage from the latest CI log rather
than trusting an old list.

## References

- Test-writing patterns & coverage policy: `.context/testing.md`
- Remaining-fixes tracker: `docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`
- Plugin operational guide: `.agents/skills/mcp-ai-wpoos-plugin/SKILL.md`
