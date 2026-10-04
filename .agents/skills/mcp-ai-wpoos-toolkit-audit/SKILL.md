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

Run these five scans per toolkit (each covers classes 1–4):

```text
shell_exec|exec\(|proc_open|passthru|system\(|popen
->generate_content|->generate\(|->log_activity|log_activity\(
preg_match.*content|trim\( \$response|choices\[0\]|candidates\[0\]
(\(string\) \$data|\(string\) \$response|->create_chat_completion|new WP_MCP_AI_[A-Za-z_]+Client
enum|provider
```

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
- [ ] crm
- [ ] developer
- [ ] dietpi
- [ ] dj-management
- [ ] eca-management
- [ ] ecommerce
- [ ] email-marketing
- [ ] erp-ezuite
- [ ] extended-cognition
- [ ] fashion
- [ ] financial-planning
- [ ] flowhub
- [ ] google-workspace
- [ ] healthcare
- [ ] image-production
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
- [ ] shopify-sync
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
