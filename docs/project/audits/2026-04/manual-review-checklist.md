# Phase 3 — Manual Security Review Checklist

> Walkthrough of OWASP- and WP-aligned checklist items A–L from the audit plan. Each row is **Pass / Partial / Fail / N/A**, with file:line evidence and a forward link to the findings register where remediation is needed.

| | Base | Pro | Algorave | Canvas | C3D | Embedded | FF | Graphify | Finding |
|---|---|---|---|---|---|---|---|---|---|
| **A.1** Superglobals wrapped with `wp_unslash()` + sanitiser | ✅ | ✅ (sample) | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **A.2** `json_decode` payloads schema-validated | Partial | Partial | n/a | n/a | n/a | Partial | Partial | Partial | F-INPUT-01 |
| **A.3** File uploads validate MIME via `wp_check_filetype_and_ext` + size + safe path | ✅ (image tools) | Partial (DICOM, PDF) | n/a | n/a | ⚠️ DICOM untrusted binary | ✅ | n/a | n/a | F-UPLOAD-01 |
| **B.1** Echo / template uses correct escaper | ✅ | ✅ (sample) | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **B.2** No raw HTML concat from AI response | ✅ DOMPurify in chat-markdown-service | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **B.3** SSE event payloads escape user-controlled fields | ✅ `wp_json_encode` in REST chat controller | ✅ | n/a | n/a | n/a | n/a | n/a | n/a | — |
| **C.1** Every REST `permission_callback` is real (no `__return_true` without verification) | ⚠️ 3 unauth routes (mcp-controller :140, a2a-controller :104,116) | ⚠️ 11 unauth webhook routes | n/a | n/a | n/a | n/a | n/a | n/a | F-AUTHZ-01 |
| **C.2** AJAX handlers check capability + nonce | ✅ (313 handlers, 289 nonce checks) | ✅ | ✅ | n/a | n/a | ✅ | ✅ | n/a | — |
| **C.3** `wp_ajax_nopriv_*` reviewed individually (6 total) | ⚠️ | ⚠️ | n/a | n/a | n/a | n/a | n/a | n/a | F-AUTHZ-02 |
| **C.4** Bearer-token validation is constant-time + hashed | ✅ `hash_equals` used in credentials class | ✅ | n/a | n/a | n/a | n/a | n/a | n/a | — |
| **C.5** Auth0 / guest-token paths cannot escalate | ✅ `guest_request` flag honoured in rest-tools-controller | ✅ | n/a | n/a | n/a | n/a | n/a | n/a | — |
| **C.6** Multisite super-admin gates (`is_super_admin()` / `manage_network`) | Partial | Partial | n/a | n/a | n/a | n/a | n/a | n/a | F-AUTHZ-03 |
| **D.1** State-changing forms / AJAX have nonces | ✅ 368 nonce creations / 289 verifications | ✅ | ✅ | n/a | n/a | ✅ | ✅ | n/a | — |
| **D.2** Nonce action names follow `wp_mcp_ai_{ctx}_{action}` | ✅ majority | Partial — some legacy `mcp_ai_*` in pro | ✅ | n/a | n/a | ✅ | Partial | n/a | F-CMP-04 |
| **E.1** All `$wpdb` calls use `prepare()` with placeholders | ✅ | ✅ (sample) | ✅ | n/a | n/a | ✅ | ✅ | ❌ — 7 sites | **F-SQL-01**, **F-SQL-02** |
| **E.2** `meta_query`/`tax_query` inputs typed/whitelisted | ✅ (sample) | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **E.3** Custom tables follow `dbDelta` + uninstall cleanup | ✅ | ✅ | n/a | n/a | n/a | ⚠️ uninstall.php missing ABSPATH | n/a | ✅ | F-CMP-02 |
| **F.1** No `eval`, dynamic `include`/`require` from user input | ✅ | ✅ | ⚠️ `new Function()` | n/a | n/a | ✅ | ✅ | ✅ | F-AI-01 |
| **F.2** No `shell_exec`/`exec`/`system`/`passthru` from user input | ✅ | ❌ **11 calls** in pro tool classes | ✅ | n/a | n/a | ✅ | ✅ | ✅ | **F-EXEC-01** |
| **F.3** Filesystem writes go through `WP_Filesystem`/`wp_upload_dir` | ✅ (sample) | Partial — some `file_put_contents` in document-generation tools | ✅ | n/a | n/a | ✅ | ✅ | ✅ | F-FS-01 |
| **F.4** Path traversal: user paths validated against allowlist | Partial | Partial | n/a | n/a | n/a | ✅ | n/a | n/a | F-FS-02 |
| **G.1** All outbound HTTP via `wp_remote_*` | ✅ 507 calls, 0 raw `curl_exec` | ✅ | ✅ | n/a | n/a | ✅ | ✅ | n/a | — |
| **G.2** SSRF allowlist + private IP block for tool-driven fetches | ❌ **Not implemented** for crawler / web-search / MCP-server tools | ❌ | n/a | n/a | n/a | n/a | n/a | n/a | **F-SSRF-01** |
| **G.3** TLS verification not disabled | ❌ 4 `sslverify => false` sites | ❌ 2 of those 4 are in pro | ✅ | n/a | n/a | ✅ | ✅ | ✅ | **F-TLS-01** |
| **H.1** API keys encrypted at rest in WP options | ✅ `wp_mcp_ai_encrypt` symmetric AES via `class-wp-mcp-ai-encryption.php` | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | F-CRYPTO-01 (key derivation review) |
| **H.2** No keys in JS / error messages / logs | ✅ (sample) | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **H.3** `.env`, build artefacts free of credentials | ✅ none found | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **I.1** Tool execution requires capability check even when invoked by LLM | ✅ enforced at base tool class | ✅ enforced | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **I.2** Tool results length-limited and escaped before re-entering prompt | Partial | Partial | n/a | n/a | n/a | n/a | n/a | n/a | F-AI-02 |
| **I.3** Agentic loop bounded (`wp_mcp_ai_max_agentic_iterations`) and TPM-budgeted | ✅ filterable max iterations + TPM budget validator | ✅ | n/a | n/a | n/a | n/a | n/a | n/a | — |
| **I.4** MCP server allowlist | Partial | Partial | n/a | n/a | n/a | n/a | n/a | n/a | F-AI-03 |
| **I.5** A2A signature/HMAC verification on inbound | ⚠️ `permission_callback => __return_true` on `/a2a/*` routes — verifier exists in `class-wp-mcp-ai-federation-peer-verifier.php` but called inside endpoint, not in permission callback | n/a | n/a | n/a | n/a | n/a | n/a | n/a | F-AUTHZ-01 |
| **J.1** AI provider data-sharing disclosed in `readme.txt` | Partial — providers listed but data-flow / what is sent to OpenAI / Gemini etc. not enumerated | n/a | n/a | n/a | n/a | n/a | n/a | n/a | **F-PRIV-02** |
| **J.2** WP Privacy API exporters/erasers cover all PII | ⚠️ `class-wp-mcp-ai-privacy.php` registers but doesn't cover pro CCT (channel messages, vault, autonomous sessions, health data) | ❌ | n/a | n/a | n/a | n/a | n/a | n/a | **F-PRIV-01** |
| **J.3** Healthcare addon HIPAA posture | ❌ no documented BAA story / PHI flow / audit log requirement | n/a | n/a | n/a | n/a | n/a | n/a | n/a | **F-PRIV-03** |
| **J.4** Logging redacts PII and tokens | ✅ logger has redaction list (sample reviewed) | ✅ | n/a | n/a | n/a | ✅ | ✅ | ✅ | — |
| **K.1** No tracking/telemetry without explicit opt-in | ✅ `class-wp-mcp-ai-activation-tracker.php` reviewed — opt-in flow exists | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **K.2** No "phone home" on activation | ✅ activation hooks reviewed | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **K.3** No external script/CSS at runtime | ✅ all assets are local under `assets/` | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **K.4** Bundled-code licences GPL-compatible | ✅ verified — see [`inventory.md`](./inventory.md) §10 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| **K.5** No trademark conflicts in plugin name | ✅ "NV Digital Open Operator System" / "NV oOS" — original | n/a | n/a | n/a | n/a | n/a | n/a | n/a | — |
| **K.6** `readme.txt` headers, `Tested up to`, `Stable tag` accurate | ✅ Tested up to 6.9, Stable tag 1.1.9 — matches `mcp-ai-wpoos.php` | n/a | n/a | n/a | n/a | n/a | n/a | n/a | F-CMP-03 (auto-bump CI) |
| **K.7** No minified-only/obfuscated source without source | ⚠️ several `.min.js` files without sibling un-minified or source map | ⚠️ pro `build/` is webpack-minified | n/a | n/a | n/a | n/a | n/a | n/a | F-CMP-05 |
| **K.8** ABSPATH guard on every PHP file | ✅ except 4 non-test files | ⚠️ 3 files | ✅ | ✅ | ✅ | ❌ uninstall.php | ✅ | ✅ | F-CMP-02 |
| **L.1** `wp.i18n` for all strings | ✅ majority | ✅ | ✅ | n/a | n/a | ✅ | ✅ | ✅ | — |
| **L.2** No `eval` / `new Function` in product JS | ✅ except algorave (by design) | ✅ | ❌ `new Function('Tone', code)` line 917 | n/a | n/a | ✅ | ✅ | ✅ | F-AI-01 |
| **L.3** Chat output through DOMPurify | ✅ `chat-markdown-service.js` | n/a | n/a | n/a | n/a | n/a | n/a | n/a | — |
| **L.4** localStorage doesn't store credentials; 24h expiry honoured | ✅ chat transcript only; session token kept in cookie set by WP | ✅ | ✅ | n/a | n/a | ✅ | n/a | n/a | — |
| **L.5** No inline event handlers from user data | ✅ (sample) | ✅ | ⚠️ live-coding emits inline handlers | n/a | n/a | ✅ | ✅ | ✅ | F-AI-01 |

## Legend

- ✅ Pass — sampled and conformant
- ⚠️ Review — sampled with concerns; see linked finding
- ❌ Fail — confirmed gap; see linked finding
- Partial — pass on sampled paths but not exhaustively verified
- n/a — feature not present in this addon

> The samples used for "✅ (sample)" entries cover at least one representative file from the named subsystem. Exhaustive audit of every file in `addons/pro/` (1,141 PHP files) is a follow-on activity tracked under **R-A-01** (Phase 7 remediation roadmap item).
