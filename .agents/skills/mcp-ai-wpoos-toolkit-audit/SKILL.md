---
type: Skill
name: mcp-ai-wpoos-toolkit-audit
description: "Per-toolkit hardening audit loop for NV oOS Pro tools. Systematically scans each addons/pro/includes/tools/<toolkit> (and its plugins/nvoos-content-graph-pro mirror) for the four recurring production failure classes — unguarded shell calls that fatal on exec-disabled hosts, calls to nonexistent client methods, string-assuming parsing of provider responses that may be arrays, and provider enum/service mismatches — then fixes, tests (Docker PHPUnit), and validates (phpcs both standards) before moving to the next toolkit. Use when asked to audit a toolkit, review the X tools for similar issues, do the same for the other toolkits, or continue the toolkit audit loop. The first case study (document-generation) found 3 fatal bugs + 1 broken regex."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.94"
  plugin-version-tested: "1.1.94"
  last-updated: "2026-10-04"
---

# NV oOS Toolkit Hardening Audit Loop

Operational playbook for auditing each of the 53 Pro toolkits
(`addons/pro/includes/tools/<toolkit>/`) for the failure classes below, fixing
them, and validating. The loop is toolkit-at-a-time so each audit lands as its
own reviewable cluster. First completed cluster: **document-generation**
(2026-10-04 session — see [Case study](#case-study-document-generation)).

## When to use this skill

- "Audit the `<toolkit>` tools" / "review the X tools for similar issues"
- "Continue the toolkit audit loop" / "do the next toolkit"
- A production site reports a fatal in a toolkit tool and you need to sweep
  the whole toolkit (and its CG Pro mirror) for the same class of bug
- Before opening a fix cluster PR touching a whole toolkit folder

## The four failure classes (scan for all four, every toolkit)

1. **Unguarded shell calls — fatal on exec-disabled hosts.** On PHP 8+,
   calling a function listed in `disable_functions` throws a fatal `Error`
   that `@` cannot suppress. Scan for `shell_exec`, `exec(`, `proc_open`,
   `passthru`, `system(`, `popen`. Every hit must be either:
   - removed in favour of `WP_MCP_AI\Services\WP_MCP_AI_Process_Service`
     (`is_command_available()` / `run_silent()`), or
   - gated by `function_exists( '<fn>' )` with a graceful fallback, or
   - behind `WP_MCP_AI_ALLOW_SHELL_TOOLS` **and** `wp_mcp_ai_find_binary()`
     (which itself guards `function_exists('proc_open')`) — the
     `html_to_pdf`/`merge_pdfs` pattern.
   Canonical helpers: `wp_mcp_ai_check_nodejs_available()` /
   `wp_mcp_ai_get_nodejs_version()` (`addons/pro/includes/npm-integration-filters.php`),
   and the private `is_cli_tool_available()` / `run_cli_command()` pair
   (exec-first + Process Service fallback, Windows-aware `where`/`which`) —
   see `class-wp-mcp-ai-ocr-service.php` and
   `class-wp-mcp-ai-tool-extract-pdf-text.php`. **Never recommend enabling
   `shell_exec` on the server** — the host hardening is correct; fix the code.

2. **Calls to nonexistent client methods — fatal `Call to undefined method`.** The
   canonical example: `WP_MCP_AI_Gemini_Client` has **no**
   `generate_content()` method — its public API is `create_chat_completion()`
   (plus `generate_image`, `edit_image`, `transcribe_audio`, `stream_chat_completion`,
   `count_tokens`, …). `WP_MCP_AI_Logger::log_activity()` does not exist either
   (use `log_event()`). Scan every `new WP_MCP_AI_*Client()` call site and
   verify each invoked method against the client class.

3. **String-assuming parsing of provider responses — fatal
   `preg_match(): Argument #2 ($subject) must be of type string, array given`.**
   `WP_MCP_AI_Gemini_Client::normalize_response()` returns
   `choices[0].message.content` as an **array of parts**; OpenAI-compatible
   gateways (DeepSeek, vLLM) can do the same. Any `trim( $response['choices'][0]['message']['content'] )`,
   `preg_match(...)`, `json_decode(...)` or `(string)` cast on that field is a
   bug: either a fatal or silent "Array" garbage. Flatten first with a helper
   (see Fix patterns). Also verify provider-side regexes actually compile —
   the document-generation tools carried `/```( ? ( :json)?…/s`, a pattern
   with an unbalanced parenthesis that never compiled and warned on every call.

4. **Provider enum / service mismatches.** A tool schema advertising a
   provider the backing service's `switch` does not handle → runtime
   `Unknown OCR provider: X` on the first call and a misleading
   "temporarily unavailable" cascade from the circuit breaker on subsequent
   calls. For every `'enum'` list in a toolkit's schemas, diff it against the
   service/client switch or registry it feeds.

## Scan procedure

Use the grep tool with the **full project-rooted glob** — a bare
`addons/pro/includes/tools/<toolkit>/*.php` include_pattern silently matches
**zero files** (false-negative scans!). Working forms:

```
mcp-ai-wpoos/addons/pro/includes/tools/<toolkit>/**/*.php
mcp-ai-wpoos/plugins/nvoos-content-graph-pro/src/tools/<toolkit>/**/*.php
```

Run these seven scans per toolkit (each covers classes 1–4):

```text
shell_exec|exec\(|proc_open|passthru|system\(|popen
->generate_content|->generate\(|->log_activity|log_activity\(
preg_match.*content|trim\( \$response|choices\[0\]|choices'\]\[0\]|candidates\[0\]
(\(string\) \$data|\(string\) \$response|->create_chat_completion|new WP_MCP_AI_[A-Za-z_]+Client
enum|provider
wp_remote_post|wp_remote_get|message'\]\['content
wp_mcp_ai_[a-z_]+\(   (then verify each global helper has a function definition somewhere)
```

Note: the class-3 grep must include BOTH `choices[0]` and `choices'][0]`
spellings — the crm toolkit wrote `$result['choices'][0]['message']['content']`
and a `choices\[0\]`-only scan produced a false negative.

Then read every hit. Do not trust "No matches found" until the glob is the
rooted `**/*.php` form. Tools in a toolkit delegate to services under
`addons/pro/includes/services/` and shared clients under `includes/` — the
failure usually lives there (e.g., the OCR service and the self-hosted OCR
client), so audit those when a toolkit routes through them.

## Fix patterns

- **Flatten helpers.** Add a small protected method next to the parsing site
  (used in `class-wp-mcp-ai-ocr-service.php` as `extract_text_from_response()`
  and in the pro_pdf/pro_word/pro_excel tools as
  `flatten_response_content()` / `flatten_content()`):

  ```php
  if ( is_string( $content ) ) { return $content; }
  if ( is_array( $content ) ) {
      $text = '';
      foreach ( $content as $part ) {
          if ( is_array( $part ) && isset( $part['text'] ) ) { $text .= $part['text']; }
          elseif ( is_string( $part ) ) { $text .= $part; }
      }
      return $text;
  }
  return '';
  ```

  Call it at the response boundary and inside the JSON/regex parser
  defensively (`if ( ! is_string( $content ) ) { … }`), never `(string)`
  cast an array.
- **Guarded CLI helpers.** Copy the `is_cli_tool_available()` /
  `run_cli_command()` pair into tools that need CLI binaries; probe
  `which`/`where` only under `function_exists( 'exec' )` and fall back to the
  Process Service, so locked-down hosts degrade to the pure-PHP path.
- **Credential gates.** `WP_MCP_AI_Credential_Resolver::has_credentials( $provider )`
  before instantiating a provider client; return a `no_api_key` WP_Error.
- **Circuit-breaker awareness.** In `WP_MCP_AI_OCR_Service`,
  validation errors (`no_api_key`, `no_endpoint`, `file_not_found`) do NOT
  open the circuit; other failures do, and subsequent calls then report
  `provider_unavailable` ("temporarily unavailable"). When a provider
  reports that message, the root cause is an earlier non-validation failure —
  check the logs for the first error in the page loop.
- **Enum honesty.** Align every advertised `enum` with the service switch —
  either implement the missing provider or remove it from the schema.

## Test conventions (per fixed toolkit)

Add regression tests that would have caught each bug:

- **Mock `pre_http_request`** with BOTH response shapes: string
  `message.content` AND array-of-parts content. Assert the flattened text
  comes out and never "Array".
- **Reflection** for protected `call_provider` / `extract_with_*` /
  `flatten_*` / `try_parse_json_response` methods.
- **Reset static state in `setUp()`** (circuit breakers via
  `ReflectionProperty::setValue( null, array() )`, credential caches via
  `WP_MCP_AI_Credential_Resolver::clear_cache()`,
  `WP_MCP_AI_Admin_Settings::reset_settings_cache()`).
- **End-to-end where the environment allows**: Imagick is loaded in the
  `oos-wp` container — build a 2-page PDF fixture and drive the whole
  convert → per-page OCR → concatenate flow through the mock.
- **Isolate provider keys**: `update_option( 'wp_mcp_ai_settings', … )` with
  only the key under test; `delete_option( 'wp_mcp_ai_credentials' )`.
- **Style gates**: expand multi-item arrays (inline `array( 'type' => 'text',
  'text' => … )` pairs are PHPCS errors), capitalize docblock descriptions
  (even for function-name subjects), `wp_delete_file()` instead of `@unlink`,
  and phpcs:ignore comments for fixture `base64_encode`/`file_put_contents`.
  `phpcbf` can fix the mechanical ones.

## Validation gates

```bash
php -l <each changed file>
# Docker PHPUnit (never two concurrent runs; same shared DB):
MSYS_NO_PATHCONV=1 docker exec -e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db -e WP_DB_NAME=wordpress_test -e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress oos-wp sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php -d memory_limit=1G vendor/bin/phpunit <paths> --no-coverage 2>&1'
# phpcs — the gate counts ERRORS, not warnings; two standards:
MSYS_NO_PATHCONV=1 docker exec oos-wp sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php vendor/bin/phpcs --standard=phpcs.xml.dist --error-severity=1 --report=summary <root paths>'
MSYS_NO_PATHCONV=1 docker exec oos-wp sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php vendor/bin/phpcs --standard=plugins/nvoos-content-graph-pro/phpcs.xml.dist --error-severity=1 --report=summary <cg-pro paths>'
```

- **Mirror discipline**: every production fix lands byte-identically in
  `plugins/nvoos-content-graph-pro/src/…` (its own text domain) — see the
  `mcp-ai-wpoos-ecosystem-port` skill. Verify both trees lint + phpcs clean.
- **UTF-8 integrity**: after editing, check `mb_check_encoding()` on every
  changed file (edit-tool CP1252 mangling passes `php -l` silently).
- **WP 7.1 matrix**: `/tmp/wp71` inside `oos-wp` is a container-local
  artifact lost on Docker restarts; when absent, note that CI covers it.
- **Watch for bonus finds**: good tests expose adjacent production bugs
  (the broken fence regex was found by a new test's warning) — fix and
  regression-test them in the same cluster.
- Expect `OK, but there were issues!` with only pre-existing warnings
  (`feeds` property notice) + deprecations = pass.

## Cluster → PR workflow

Follow the `mcp-ai-wpoos-test-suite` skill's conventions: branch per toolkit
from `origin/alpha-working`, stage explicit paths only (never `vendor/`),
imperative subject ≤ 50 chars, PR base `alpha-working`, user merges manually,
then move to the next toolkit.

## Toolkit board

53 Pro toolkits under `addons/pro/includes/tools/` (audited ✅ / not yet):

- [x] document-generation — 2026-10-04 (case study below)
- [ ] ai-tool-builder
- [ ] analytics
- [ ] architect-agent (known shell allowlist, R-S-02 migration in flight — treat separately)
- [ ] architectural-design
- [ ] automotive
- [ ] calendar-booking
- [ ] capture
- [ ] chat-channels
- [ ] cloudways
- [ ] comic-creation
- [ ] composio
- [ ] cre-debt
- [x] crm — 2026-10-04 (case study below)
- [ ] developer
- [ ] dietpi
- [ ] dj-management
- [x] eca-management — 2026-10-04 (case study below)
- [x] ecommerce — 2026-10-04 (case study below)
- [x] email-marketing — 2026-10-04 (verified clean — case study below)
- [ ] erp-ezuite
- [ ] extended-cognition
- [ ] fashion
- [ ] financial-planning
- [ ] flowhub
- [ ] google-workspace
- [x] healthcare — 2026-10-04 (case study below)
- [x] image-production — 2026-10-04 (case study below)
- [ ] infrastructure
- [ ] jetengine
- [ ] jev
- [ ] law-firm
- [ ] math
- [ ] media
- [ ] multilingual
- [ ] okf
- [ ] orchestration
- [ ] outbound-booking
- [ ] paper-store
- [ ] places
- [ ] project-management
- [ ] quiz-management
- [ ] regulatory-registration
- [ ] remote-connections
- [ ] research
- [x] shopify-sync — 2026-10-04 (case study below)
- [ ] site-creator-toolkit
- [ ] social-media
- [ ] vault
- [ ] vector-storage
- [ ] video-production
- [ ] vision-analysis
- [ ] wp-all-import-export

Also sweep the loose tools directly in `addons/pro/includes/tools/*.php`
(incident/maintenance tools) and the shared `addons/pro/includes/services/`
classes each toolkit routes through.

## Case study — shopify-sync

What the seventh audit found (a class-3-family error-path fatal and a
class-4 descriptor/wiring mismatch; everything else verified clean):

1. `shopify_sync_orders::handle_order_analytics()` — `$edges` was only
   assigned inside the `! is_wp_error( $orders_result )` branch, then
   `count( $edges )` ran unconditionally — `count(null)` **TypeError** on
   exactly the error path meant to degrade gracefully (API down, missing
   token, rate limit). Initialized `$edges = array()` before the guarded
   block; the error path now returns the canonical envelope with zeros.
2. `WP_MCP_AI_Shopify_Sync_MCP_Server` — three descriptor wiring
   mismatches fed by the shared `WP_MCP_AI_Scheduled_Toolkit_Server_Trait`:
   - `get_sync_hook_name()` advertised `wp_mcp_ai_shopify_sync_full_sync`,
     a hook the engine never schedules (it schedules per-connection
     `wp_mcp_ai_shopify_full_sync_{conn_id}`) → `get_sync_status()` always
     "unknown"/0;
   - the trait's `get_sync_interval()` read a slug-derived option
     (`wp_mcp_ai_shopify-sync_settings`) the toolkit never writes, in
     seconds, while the toolkit stores **minutes** in
     `wp_mcp_ai_shopify_sync_toolkit_settings` → advertised
     `sync_interval_seconds` stuck at the 300s default;
   - the trait's `get_connection_status()` read
     `wp_mcp_ai_remote_connections`, an option the toolkit never
     populates → always false.
   Overrode all three: per-connection last-sync option aggregation
   (`{last_sync, status, row_count}` shape preserved + the trait's
   `wp_mcp_ai_scheduled_toolkit_sync_status` filter), minutes→seconds
   interval conversion, and Remote-Sites-manager resolution of enabled
   synced Shopify connections. Mirrored byte-identically to the CG Pro
   MCP server file (text-domain deviation only); `class_exists` guards
   degrade gracefully there because the sync engine/CCT manager are not
   yet ported to CG Pro.

Verified clean this cluster: zero shell/exec in the toolkit and all
backing services; every client/service method call exists on its class
(`WP_MCP_AI_Shopify_Client` get_orders/get_order/get_shop_info/bulk_query/
catalog_request/get_api_mode/get_catalog_shop_id, CCT manager read/write/
sync methods, engine consts + dispatch pair, `WP_MCP_AI_Sync_Log_Manager`
start_run/log_item/end_run, `wp_mcp_ai_log()`, `WP_MCP_AI_Logger::log_event()`,
Remote Site Manager get_all_connections/get_connection/decrypt_value); HTTP
paths already hardened (client graphql/bulk_query/catalog_request carry
status+JSON+size validation via `wp_safe_remote_*`; webhook handler verifies
HMAC with `hash_equals` + decrypted secret before any state change, with
`__return_true` permission_callback as the documented webhook-auth pattern);
all advertised enums match their switches (5 action enums, stock_status,
product status, sync_direction, sync_interval diffed against the executing
switch statements).

Tests landed: `addons/pro/tests/test-shopify-sync-toolkit-hardening.php`
(new, 11 tests / 25 assertions — orders-analytics error-path regression +
happy-path aggregation through mocked `pre_http_request`, MCP server hook
prefix/interval-minutes/interval-default/status-aggregation/status-unknown/
status-stale/connection-status matrix). Validation: 126 tests / 1198
assertions green across the 9-suite shopify-sync cluster; CG Pro MCP server
matrix green (7 tests / 64 assertions, 3 pre-existing standalone skips);
phpcs 0 errors on both standards; UTF-8 clean. PR #6889.

## Case study — email-marketing

What the eighth audit found: **all four failure classes verified clean**.
This is a verified-clean cluster; the deliverable is the regression suite
that locks the verified contracts, because the Brevo trio plus the blueprint
importer previously had zero test coverage.

- Class 1: zero shell/exec/proc_open/popen anywhere in the toolkit. The
  loose incident/maintenance tools (`addons/pro/includes/tools/*.php`, 5
  files) also scanned clean on the same patterns.
- Class 2: no provider clients are instantiated at all; the only cross-class
  calls are `WP_MCP_AI_Logger::log_event()` / `log_error()` and
  `WP_MCP_AI_Blueprint_Installer::load_blueprint()` / `install()`, all of
  which exist.
- Class 3: no provider responses are parsed — every tool is a pure HTTP
  wrapper; all `json_decode()` sites are `is_array()`-guarded and every
  `wp_remote_*` call carries is_wp_error + status-code checks.
- Class 4: every enum matches its switch — brevo statistics
  `campaigns|transactional` vs the execute() branch, brevo contacts
  8-action enum vs the 8-case switch, mailjet contacts 4-action enum vs
  switch, mailjet statistics CounterSource/CounterTiming pass through to
  the Mailjet API vocabulary, blueprint enum vs `BLUEPRINT_SLUGS`.

Tests landed: `addons/pro/tests/test-email-marketing-toolkit-hardening.php`
(new, 15 tests / 91 assertions — credential/capability gates, payload
normalisation incl. recipient dedupe and tag truncation to 10, HTTP
status/transport/shape error paths, an enum-to-handler routing loop driven
from the live schema, per-type endpoint routing, blueprint slug rejection).
Mailgun/Mailjet were already covered by `tests/test-mailgun-tool.php` and
`tests/test-mailjet-tool.php`; Brevo had none. Validation: 15/15 green
standalone and 29/29 green combined with the mailgun/mailjet suites on the
isolated DB `wordpress_test_muted_moth`; phpcs 0 errors on the base
standard; UTF-8 clean (ASCII-only).

CG Pro note: the email-marketing toolkit has not been ported to
`plugins/nvoos-content-graph-pro/src/tools/` yet — no mirror exists to
sync, so this cluster is base-tree only (porting stays on the
ecosystem-port track).

## Case study — healthcare

What the sixth audit found (class 3; plus class-2 dead audit trail and two
bonus TypeErrors exposed by the new tests; everything else verified clean):

1. `interpret_imaging_study::action_interpret()` — concatenated
   `$result['choices'][0]['message']['content']` straight into the
   interpretation text. Gemini (and OpenAI-compatible gateways such as vLLM)
   return that field as an array of parts → literal "Array" text in every
   Gemini-served interpretation. Added a `flatten_response_content()` helper
   at the response boundary; empty content now returns the honest
   `imaging_ai_empty_response` WP_Error.
2. `export_fhir_data` + wellness `init.php` — called the nonexistent global
   `wp_mcp_ai_log_activity()` (no definition anywhere in the repo; guarded so
   it never fataled, but the HIPAA audit trail silently never fired).
   Rewired to `WP_MCP_AI_Healthcare_Audit::record()` (the unified PHI ledger
   every other healthcare tool writes to) and `WP_MCP_AI_Logger::log_event()`
   for the migration.
3. Bonus (tests exposed): `deidentify_health_record` + `extract_clinical_entities`
   passed `$user_id` in the `array $meta` slot of
   `WP_MCP_AI_Healthcare_Audit::record()` → `TypeError` on every execution once
   the audit class loads. Moved `user_id` into the meta array. Also guarded the
   OpenMed response boundary (`entities` non-array → empty list;
   `deidentified_text` missing → '' in the completion action).

Verified clean this cluster: zero shell/exec anywhere in the toolkit; all
client methods exist (create_chat_completion on OpenAI/Gemini/Anthropic,
create_embedding(s) on Gemini/OpenAI, OpenMed client methods, Media Worker
sidecar trait, imaging CPT/audit classes, batch iterator, migration class);
all advertised enums match their service switches (species human/canine/feline
in the engine reference tables, vitals units, DICOMweb auth types, EHR vendors,
FHIR formats, NER models, deidentify methods); HTTP paths already hardened
(DICOMweb status + JSON validation + SSRF guard on save, OpenMed client,
EHR connect SSRF guard + status + JSON).

Tests landed: `addons/pro/tests/test-healthcare-toolkit-hardening.php` (new,
7 tests / 27 assertions — flatten shape matrix, Gemini array-of-parts
end-to-end through `execute()`, OpenAI string passthrough, empty-content error,
FHIR export audit-trail entry, OpenMed non-array entities guard, missing
`deidentified_text` guard). Validation: 139 healthcare-cluster tests green
(isolated DB `wordpress_test_muted_moth`), 38 CG Pro healthcare tests green
(isolated DB `wordpress_test_muted_moth_cg`); phpcs 0 errors on both
standards; UTF-8 clean. PR #6887.

## Case study — ecommerce

What the fifth audit found (classes 1 and 3, plus two feature-dead Node paths):

1. `validate-image-for-product::validate_with_vision_ai()` and
   `validate-image-for-vehicle::validate_with_vision_ai()` —
   `trim( $response['choices'][0]['message']['content'] )` string-assuming
   (class 3): OpenAI-compatible gateways can return message.content as an
   array of parts, so `trim()` fatals on the first such response. Added a
   `flatten_response_content()` helper at the response boundary plus an
   honest empty-content `wp_mcp_ai_invalid_response` WP_Error.
2. `export_products_report::generate_excel_file()` and
   `generate_woocommerce_order_invoice_pdf::generate_pdf()` — unguarded
   `exec()` (class 1) AND doubly broken invocations: the script path was
   `WP_MCP_AI_PRO_PATH . 'addons/pro/scripts/...'` (doubled — `PRO_PATH`
   already ends in `addons/pro/`), the invoice tool pointed at
   `generate-invoice.js` which does not exist anywhere in the repo, and both
   passed inline JSON on the command line while the bundled scripts take
   `<json_file> <output_file>` argv. Both are now gated behind
   `WP_MCP_AI_ALLOW_SHELL_TOOLS` + Node availability via the shared
   `wp_mcp_ai_ecommerce_run_node_script()` helper (helpers file, both trees)
   and execute through the Process Service (`run_silent()` array form — no
   shell interpolation); the Excel tool writes a JSON input file and the
   invoice tool renders an escaped HTML invoice through the bundled
   `generate-pdf.js` (copied into the CG Pro scripts dir).
3. `lookup_product_price` — Crawl4AI `markdown`/`text` fields and
   submit_document_prompt `text`/`content`/`response` fields fed into
   `preg_match()`/`explode()`/`json_decode()` without a string guarantee
   (class 3, tool-result variant): `parse_crawl_result_for_product()`,
   `parse_search_results()`, `extract_text_from_document()`,
   `extract_line_items_from_text()` now flatten at every response boundary;
   `extract_price_from_content()` and `parse_json_from_text()` carry
   defensive `! is_string()` guards.
4. `product_actualization::generate_ai_integrated_image_openai()` — the
   OpenAI-`url` fallback branch downloaded the image without checking the
   HTTP status (error HTML saved as image bytes); added a status gate.

Verified clean this cluster: all Shopify/Printful/Roboflow client method
calls exist on their classes (class 2); every advertised enum matches its
service switch — Printful action enum ↔ switch, product-actualization
provider enum ↔ `detect_preferred_provider()`, QuickBooks accounting-method
and import-duty country enums validated against their maps (class 4);
get-import-duty / quickbooks-report / quickbooks-desktop-sync HTTP paths
carry is_wp_error + status + JSON validation; zero remaining shell calls in
the toolkit.

Tests landed: `addons/pro/tests/test-ecommerce-toolkit-hardening.php` (new,
14 tests / 43 assertions — flatten shape matrix for both vision tools via
mocked `pre_http_request` with string AND array-of-parts content, empty
content honest-error paths, crawl-markdown flatten, price/JSON parse guards,
shell-tools gate for the Node runner + both generation methods with temp
file cleanup assertions, invoice HTML escaping, JSON input roundtrip).
Validation: 250 ecommerce-cluster tests green; phpcs 0 errors on both
standards; UTF-8 clean. Note: the test never defines
\`WP_MCP_AI_ALLOW_SHELL_TOOLS\` — defining it would pollute every later suite
in the shared process.

## Case study — eca-management

What the fourth audit found (class 3; everything else verified clean):

1. `research_eca::perform_ai_research()` — passed
   `$result['choices'][0]['message']['content']` through raw; Gemini (and
   OpenAI-compatible gateways such as vLLM) return that field as an array of
   parts, and `parse_research_results()` fed it straight into `preg_match()` —
   `preg_match(): Argument #2 ($subject) must be of type string, array given`
   on the first Gemini-served research call. Added a
   `flatten_response_content()` helper at the response boundary plus a
   defensive flatten in `parse_research_results()`; empty content now returns
   the honest `wp_mcp_ai_invalid_response` WP_Error.

Verified clean this cluster: zero shell/exec; all 14 provider client classes
in `get_ai_client()` exist with matching `get_research_provider()` selection
(class 4 clean — no advertised enum, provider auto-selected from credentials);
Google Classroom client methods (`list_courses`, `list_students`,
`list_coursework`, `list_student_submissions`, `create_course`,
`create_coursework`, `get_course`, `list_guardians`, `paginate`) and
`Google_Classroom_Credentials::require_scope` / `Google_Classroom_Push`
all exist in `includes/google/`; iSAMS/SOCS/Gmail HTTP paths carry
is_wp_error + status + JSON validation; CSV import gates on `manage_options`.

Port-level note (tracked as issue #6884): the CG Pro mirror's
`get_research_provider()` calls `WP_MCP_AI_Credential_Resolver::has_credentials()`
and `WP_MCP_AI_Tool_Registry::get_instance()` unguarded, while the crm upwork
mirror carries the wave-proof `class_exists` guard deviation. The unguarded
pattern predates this audit and spans many CG Pro toolkits
(ai-tool-builder, chat-channels, crm, quiz-management, site-creator-toolkit) —
queued as a port-wide sweep rather than a per-toolkit fix.

Tests landed: `addons/pro/tests/test-eca-toolkit-hardening.php` (new, 6 tests
/ 19 assertions — flatten shape matrix, Gemini array-of-parts end-to-end
through the real client, fenced-JSON parse, empty-content error). Validation:
44 eca-cluster tests green; phpcs 0 errors on both standards; UTF-8 clean.

## Case study — crm

What the third audit found (classes 2 and 3; everything else verified clean):

1. `draft_upwork_proposal::generate_proposal()` —
   `trim( $result['choices'][0]['message']['content'] )` string-assuming
   (class 3): Gemini `normalize_response()` returns `message.content` as an
   array of `{type,text}` parts, so the `isset()` guard passes and `trim()`
   fatals on the first Gemini-served proposal. Added a
   `flatten_response_content()` helper at the response boundary; empty
   content now returns the honest `wp_mcp_ai_invalid_ai_response` WP_Error
   instead of an empty-string “success”.
2. `draft_lead_reply::generate_ai_draft()` — called the nonexistent global
   `wp_mcp_ai_chat_completion()` (class 2, function variant): no such
   function exists anywhere in the repo, so the AI path was permanently dead
   (silent template fallback on every call). Rewrote around the
   OpenAI/Gemini/Anthropic provider clients (Credential_Resolver →
   provider/model/client plumbing, same as the upwork tool) with the same
   flatten helper; template fallback preserved on any WP_Error.
3. Scan-regression note: the standard class-3 grep `choices[0]` missed this
   toolkit's hits because the code writes `['choices'][0]` — also scan for
   `choices'\]\[0\]`. And scan for `wp_mcp_ai_*()` global-helper calls with no
   function definition anywhere in the repo (dead-code smell).

Verified clean this cluster: zero shell/exec anywhere in the toolkit; all
client methods exist (Gmail service `search_leads`, LinkedIn
`get_me`/`search_jobs`, Upwork `graphql`, MCP App clients,
`WP_MCP_AI_Validator_Service::is_email`/`is_phone_number`) with hardened
HTTP paths (is_wp_error + status + JSON validation); IMAP listener is
pure-PHP socket with guarded ext-imap fallback; classifiers (intent/support)
are heuristic; enum/service surfaces match.

Follow-up queue for later clusters (same `['choices'][0]['message']['content']`
string-assumption found globally): eca-management research-eca ✅ (done, this
file), ecommerce validate-image-for-product/vehicle ✅ (trim + json_decode,
done 2026-10-04), healthcare interpret-imaging-study, multilingual
auto-translate-content (also raw `wp_remote_post`), orchestration
generate-research-report, places research-place.

Tests landed: `addons/pro/tests/test-crm-toolkit-hardening.php` (new, 7 tests
/ 19 assertions — response-shape matrix for both tools via mocked
`pre_http_request`, Gemini array-of-parts end-to-end through the real
clients, template-fallback regression). Validation: 7/7 green + 354
crm/docgen cluster green; phpcs 0 errors on both standards; UTF-8 clean.

## Case study — image-production

What the second audit found (classes 1, 3, 4; plus defensive fixes):

1. `text_to_image_prompt_optimizer` — raw `wp_remote_post()` to a hardcoded
   `api.openai.com/v1/chat/completions` URL bypassing the OpenAI client
   (custom `openai_base_url`, org/project headers, Credential_Resolver all
   ignored), `trim( $data['choices'][0]['message']['content'] )`
   string-assuming (fatal on array-of-parts content from OpenAI-compatible
   gateways), no HTTP status check, and fenced ```json``` payloads never
   parsed (the model is asked for JSON; fences are common). Rewrote around
   `WP_MCP_AI_OpenAI_Client::create_chat_completion()` with a
   `flatten_response_content()` helper, a `get_api_key()` credential gate,
   and fence-stripping in `parse_optimization_response()`.
2. `generate_image_ai` Stability path — `list( $w, $h ) = explode( 'x', $size )`
   left `$height` undefined (→ 0) for malformed sizes, and non-200
   responses fell through to the generic missing-artifacts error. Fixed with
   `array_pad` + absint guards and a status check that surfaces Stability's
   own `message` field.
3. `optimize_image_sharp::optimize_via_sidecar()` —
   `(int) $sidecar['optimized_size']` undefined-index notice + 0 when the
   worker omits the field. Guarded with a `filesize()` fallback.
4. Harmonization `ai_edit_image()` / `generate_background()` — the
   OpenAI-`url` fallback branch downloaded the image without checking the
   HTTP status (error HTML would be saved as image bytes), and a
   neither-b64-nor-url response fell through to the misleading
   "no supported provider" error. Added a status gate + an honest
   `wp_mcp_ai_empty_result`.

Verified-clean this cluster: the `WP_MCP_AI_NodeJS_Subprocess` trait
(already Process Service-only), the Media Worker sidecar trait, the remove.bg
helper (upload-dir containment + status-aware error parsing), the
harmonization URL downloader (`wp_safe_remote_get` + image content-type
check), and all Gemini/OpenAI `generate_image`/`edit_image` call sites
(signatures and return shapes match the real client APIs).

Documented, out of scope: `upscale_image_ai` / `colorize_image` /
`enhance_image_quality` / `apply_artistic_style` are placeholder
implementations (no actual AI processing — they re-save the source and claim
success); `enhance_image_quality`'s sharpen/color/contrast/denoise helpers
are no-ops. Not failure-class bugs; a feature-completeness track.
**Wave 1 landed (2026-10-04):** `enhance_image_quality` + `upscale_image_ai`
now run real Sharp processing via the dual-path
`WP_MCP_AI_Sharp_Image_Processing` trait (local subprocess → worker sidecar
→ honest error). **Wave 2 landed (2026-10-04):** `colorize_image` +
`apply_artistic_style` now run real AI edits via the worker
`/api/image/edit` route (Gemini/OpenAI/Replicate) or the PHP provider
clients through the `WP_MCP_AI_Provider_Image_Edit` trait — see issue #6877
and
`docs/project/plans/image-production-sidecar-cluster-plan.md`. Wave 3
(Real-ESRGAN) remains deferred.

Tests landed: `addons/pro/tests/test-image-production-hardening.php` (new,
12 tests / 39 assertions — prompt-optimizer response-shape matrix,
fence-stripping, Stability dimension/error paths, Gemini+OpenAI image-edit
round-trips through mocked HTTP, sidecar missing-field guard). Validation:
12+22 green across the image-production cluster; phpcs 0 errors on both
standards; UTF-8 clean.

## Case study — document-generation

What the first audit found (all four classes, one toolkit):

1. `extract_pdf_text` — unguarded `shell_exec('which pdftotext…')` + raw
   `exec()` → fatal on the exec-disabled production host (missed by the
   v1.1.70 PR #6295 hardening wave). Fixed with guarded CLI helpers +
   Process Service fallback; pure-PHP pdfparser remains the last-resort path.
2. `pro_document_ocr` / `ocr_pdf_text` — `$client->generate_content()` on
   `WP_MCP_AI_Gemini_Client` (method does not exist) → fatal. Rewrote
   `extract_with_gemini()` around `create_chat_completion()` with an
   inline-base64 image segment (added `data` key support to
   `format_image_part()` in the base Gemini client).
3. `pro_pdf` / `pro_word` / `pro_excel_document` — Gemini array-of-parts
   `message.content` fed into `preg_match()` → fatal; plus the broken
   markdown-fence JSON regex (`missing closing parenthesis`, warned on every
   call, fenced JSON never parsed). Fixed with `flatten_response_content()`
   + defensive flatten in `try_parse_json_response()` + a compilable
   `/```(?:json)?\s*(\{.*?\})\s*```/s`.
4. `pro_document_ocr` schema advertised `anthropic` while the OCR service
   switch had no `anthropic` case → `Unknown OCR provider` then the
   circuit-breaker "temporarily unavailable" cascade. Implemented
   `extract_with_anthropic()` (data-URL image block via
   `WP_MCP_AI_Anthropic_Client::create_chat_completion`).
5. `WP_MCP_AI_Self_Hosted_OCR_Client` — `(string)` cast of possibly-array
   `message.content` → literal "Array" OCR text. Added `flatten_content()`.

Tests landed: `addons/pro/tests/test-ocr-service.php` (+6),
`addons/pro/tests/test-extract-pdf-text-tool.php` (new),
`addons/pro/tests/test-docgen-provider-content-flattening.php` (new),
`tests/test-self-hosted-ocr-client.php` (new),
`tests/test-gemini-client.php` (+1). Validation: 126 tests / 532 assertions
green in the 9-suite cluster; phpcs 0 errors on both standards; UTF-8 clean.

## References

- Test environment + cluster workflow: `mcp-ai-wpoos-test-suite` skill
- CG Pro byte-identity mirroring: `mcp-ai-wpoos-ecosystem-port` skill
- v1.1.70 hardening precedent: PR #6295, `addons/pro/includes/npm-integration-filters.php`
- Array-content normalization precedent: test-suite skill pattern 56
- Production evidence: nirmanawellness `get_system_logs` failures
  (2026-10-04) — `sse_tool_fatal_error` entries from these exact bugs

## Related non-toolkit hardening (stream hygiene)

Toolkit fatals are one half of the nirmanawellness story — the other was the
chat SSE stream being reset by Cloudflare/nginx during long tool runs
(`net::ERR_HTTP2_PROTOCOL_ERROR`). That fix (inter-step SSE keepalive comment
frames) and its test contract are documented as **test-suite skill pattern
64**; the durable follow-up (deadline-triggered offload to the job stream) is
proposed in
`docs/project/proposals/sse-stream-hardening-long-run-offload-proposal.md`.
When a toolkit audit surfaces tools that block for tens of seconds (OCR,
document generation, video), the chat-stream impact of those tools is the
same class of production risk — keep the two loops linked.
