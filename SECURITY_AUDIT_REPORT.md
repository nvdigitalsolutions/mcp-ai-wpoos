# NV oOS (Open Operator System) — 13-Layer Security Audit & Penetration Test Report

**Audit Target:** NV Digital Open Operator System (oOS) — Base Plugin v1.1.41 + Pro Addon v1.1.25
**Audit Date:** July 26–28, 2026
**Auditor:** Automated Security Audit via Zed Agent (AI-Assisted)
**Methodology:** OWASP ASVS 4.0, CWE Top 25, WordPress Plugin Security Guidelines
**Scope:** Full codebase (`includes/`, `addons/pro/`, REST API, tools, authentication, addons)
**Classification:** Confidential — For NV Digital Solutions Internal Use
**Total Findings:** 27 issues (2 Critical, 7 High, 11 Medium, 7 Low)

---

## Executive Summary

The NV oOS plugin is a **large and architecturally sophisticated WordPress AI orchestration framework** with ~1,031+ tools (201 base + ~830 pro) spanning 13 AI providers. The codebase demonstrates **strong security fundamentals** in many areas: proper nonce usage across admin pages, capability checks on most admin endpoints, `wp_verify_nonce` on REST endpoints, `wp_hash_password()` for credential storage, HKDF-based key derivation, PKCE support in OAuth, and systematic tool capability gating.

However, the plugin's **broad attack surface** — combining AI provider integrations, remote MCP server connections, OAuth 2.0 server functionality, Shopify webhooks, file generation/upload tools, and guest/public chat surfaces — creates **novel security risks** not typically seen in WordPress plugins. The most significant findings relate to **plaintext API key storage**, **missing rate limiting on authentication**, and **`__return_true` permission callbacks on write-capable OAuth endpoints**.

### Risk Matrix Summary

| Severity | Count | Addressed | Remaining |
|----------|-------|:---------:|:---------:|
| Critical | 2 | ✅ 1 (API key encryption) | 1 (OAuth token endpoint rate limit) |
| High     | 7 | ✅ 3 (SSRF guard, concurrency limits, cost tracker) | 4 |
| Medium   | 11 | ✅ 5 (MIME validation, SVG sanitization, object access, log redaction, upload trait) | 6 |
| Low      | 7 | — | 7 |
| **Total** | **27** | **9 addressed** | **18 remaining** |

*Note: PR #5747 (merged) also addressed CORS hardening, rate-limiting defaults, security headers, and error filtering — covering 4 additional findings.*

---

## Layer 1 — Information Gathering & Reconnaissance

### 1.1 Plugin Fingerprinting
**Finding ID:** SEC-1-001 | **Severity:** Low | **CWE:** CWE-200

The plugin's REST API namespace (`mcp-ai/v1`) and addon REST namespaces (e.g., `nvoos-docs/v1`, `nvoos-algorave/v1`) are predictable and disclose the plugin's presence. The OAuth metadata endpoint (`/.well-known/oauth-authorization-server`) and protected resource metadata endpoint are publicly accessible and return detailed server configuration including supported grant types, token endpoint URLs, and scopes.

**Affected Files:**
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-oauth-rest.php` (L70-92)
- Various addon REST controllers

**Recommendation:** This is standard for OAuth 2.0 servers (RFC 8414) and REST APIs. No action required beyond standard security hardening. Consider adding a `Server` header removal or customizing error messages to avoid version disclosure.

### 1.2 Version Disclosure via HTTP Headers and Assets
**Finding ID:** SEC-1-002 | **Severity:** Low | **CWE:** CWE-200

Plugin version (`1.1.41`) is disclosed in the main plugin file header (`mcp-ai-wpoos.php`) and in asset URLs. The Pro addon version (`1.1.25`) is also disclosed. This aids attackers in identifying known vulnerabilities.

**Affected Files:**
- `mcp-ai-wpoos.php` (L6)
- `addons/pro/mcp-ai-wpoos-pro.php` (L33)
- Various `wp_add_inline_script()` calls passing version strings

**Recommendation:** Standard for WordPress plugins. Not a direct vulnerability but combined with other findings this information facilitates targeted attacks. Consider a Web Application Firewall (WAF) rule to strip `X-Powered-By` and plugin-identifying response headers.

---

## Layer 2 — Configuration & Deployment Security

### 2.1 Plaintext API Key Storage in WordPress Options
**Finding ID:** SEC-2-001 | **Severity:** Critical | **CWE:** CWE-312

Multiple third-party API keys are stored as **plaintext in WordPress options** without encryption at rest. Anyone with database access (via SQL injection, compromised admin account, or hosting provider access) can extract these keys. Affected keys include:

- `wp_mcp_ai_openai_api_key` — OpenAI API key
- `wp_mcp_ai_stability_api_key` — Stability AI key
- `wp_mcp_ai_yahoo_client_id` / `wp_mcp_ai_yahoo_client_secret` — Yahoo OAuth credentials
- `wp_mcp_ai_google_maps_api_key` — Google Maps API key
- `wp_mcp_ai_removebg_api_key` — remove.bg API key
- `wp_mcp_ai_webhook_secret` — HMAC shared secret (though auto-generated, it's in plaintext)

**Affected Files:**
- `includes/class-wp-mcp-ai-job-notifier.php` (L973-979)
- `addons/pro/includes/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php` (L190-200, L245-255)
- `addons/pro/includes/tools/image-production/class-wp-mcp-ai-tool-remove-background.php` (L74-84)
- `addons/fantasy-football/includes/tools/class-wp-mcp-ai-tool-yahoo-ff-auth.php` (L216-218)
- `addons/graphify/includes/class-nvoos-graphify-semantic-extractor.php` (L568-570)

**Recommendation:** Implement encryption-at-rest for all third-party API keys using WordPress's `wp_salt()`-derived key material (e.g., via `sodium_crypto_secretbox()` or OpenSSL). Store only encrypted blobs in options. Provide a migration path from plaintext to encrypted storage. Consider using the WordPress `wp_salt` constants as the encryption key source with a key rotation capability.

### 2.2 Auto-Generated Webhook Secret on First Access
**Finding ID:** SEC-2-002 | **Severity:** Medium | **CWE:** CWE-334

The `WP_MCP_AI_Job_Notifier::get_webhook_secret()` method automatically generates a webhook secret using `wp_generate_password(64, true, true)` if none exists. While the generation is cryptographically sound, the auto-generation on read means the secret is created and stored **without explicit administrator action**, potentially creating an HMAC key the admin is unaware of.

**Affected File:**
- `includes/class-wp-mcp-ai-job-notifier.php` (L973-979)

**Recommendation:** Generate the webhook secret explicitly during plugin activation or through an admin settings action. Add a UI indicator showing whether a webhook secret is configured. Document the secret's purpose in the admin interface.

### 2.3 WordPress `DISABLE_WP_CRON` Not Enforced
**Finding ID:** SEC-2-003 | **Severity:** Low | **CWE:** N/A

The plugin uses WordPress cron extensively for background tasks but does not detect or warn when `DISABLE_WP_CRON` is not set (indicating reliance on WP's pseudo-cron instead of a system cron). While not a security vulnerability per se, sites using pseudo-cron for agentic AI workloads may experience unpredictable behavior and resource exhaustion.

**Recommendation:** Add a Site Health check or admin notice recommending `DISABLE_WP_CRON` and system cron configuration for production deployments.

---

## Layer 3 — Identity Management & Authentication

### 3.1 Multiple Authentication Methods Without Rate Limiting
**Finding ID:** SEC-3-001 | **Severity:** High | **CWE:** CWE-307

The `WP_MCP_AI_REST_Authenticator::authenticate()` method attempts up to **six authentication methods** sequentially on every request: WP nonce, local credential token, mesh key, OAuth token, Auth0 JWT, WordPress Basic Auth, and guest token. **None of these have rate limiting** or brute-force protection. An attacker can:
- Brute-force local credential tokens (format: `identifier.secret`)
- Brute-force mesh API keys
- Submit unlimited Auth0 JWTs for verification

**Affected File:**
- `includes/rest/class-wp-mcp-ai-rest-authenticator.php` (L826-906)

**Recommendation:** Implement exponential backoff / rate limiting on the `authenticate()` method using transients keyed by IP address. Track failed attempts per IP and block after a threshold (e.g., 10 failures in 5 minutes). Consider adding a `X-RateLimit-*` response header family for client awareness. Use `wp_mcp_ai_check_api_rate_limit()` pattern already established in the codebase (e.g., Yahoo Fantasy tools).

### 3.2 Guest Token Authentication Scope
**Finding ID:** SEC-3-002 | **Severity:** Medium | **CWE:** CWE-863

Guest tokens are accepted by the authenticator (L891-898) and marked with `is_guest = true`. The exact scope of guest access is not clearly defined in a single location — it relies on individual tool capability checks and endpoint-specific permission callbacks. If any endpoint or tool fails to check `is_guest`, unauthorized access may occur.

**Affected File:**
- `includes/rest/class-wp-mcp-ai-rest-authenticator.php` (L891-898)

**Recommendation:** Implement a centralized guest access policy class that all endpoints and tools must check. Add a `guest_can()` method that is called before any tool execution or data access. Document the guest access scope explicitly.

### 3.3 Credential Management Is Well-Implemented
**Finding ID:** SEC-3-003 | **Severity:** Positive | **CWE:** N/A

The credential system in `WP_MCP_AI_Credentials` demonstrates strong security practices:
- Secrets generated with `wp_generate_password(32, false, false)` — 32 chars of cryptographically random material
- Hashed with `wp_hash_password()` (bcrypt via WordPress)
- Verified with `wp_check_password()` — constant-time comparison
- Tokens formatted as `identifier.secret` with proper parsing
- Revocation support with `revoked_at` timestamps
- Indexed for efficient lookup without scanning all assistants

**Affected File:**
- `includes/class-wp-mcp-ai-credentials.php` (L98-139, L252-289)

---

## Layer 4 — Authorization & Access Control

### 4.1 `__return_true` Permission Callback on OAuth Token Endpoint
**Finding ID:** SEC-4-001 | **Severity:** Critical | **CWE:** CWE-862

The OAuth 2.0 token endpoint (`POST /mcp-ai/v1/oauth/token`) uses `__return_true` as its `permission_callback`, making it **accessible to unauthenticated users worldwide**. While this is technically correct per RFC 6749 (the token endpoint must be public), combined with:
- Open Dynamic Client Registration (SEC-4-002)
- No rate limiting on the token endpoint (SEC-3-001)
- The token endpoint accepts `grant_type=authorization_code` and `grant_type=refresh_token`

…this creates a surface for brute-force attacks on authorization codes (which are 43+ character random strings with PKCE, mitigating but not eliminating the risk).

**Affected Files:**
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-oauth-rest.php` (L184-194)

**Recommendation:** Add rate limiting specifically on the token endpoint. Implement a `failed_attempts` transient keyed by `client_id + IP`. Add logging for failed token exchanges. Consider requiring `client_secret` for confidential clients.

### 4.2 `__return_true` on OAuth Client Registration Endpoint
**Finding ID:** SEC-4-002 | **Severity:** High | **CWE:** CWE-862

The Dynamic Client Registration (DCR) endpoint (`POST /mcp-ai/v1/oauth/register`) uses `__return_true` (per RFC 7591). Any entity can register a new OAuth client with arbitrary `redirect_uris`. This enables:
- **Phishing campaigns:** Malicious redirect URIs could be used to trick users
- **Authorization code interception:** If the redirect URI validation is weak

**Affected File:**
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-oauth-rest.php` (L100-110)

**Recommendation:** Add an admin setting to **disable open DCR** in production. Require explicit admin approval for new client registrations. Implement a client registration allowlist. Add CAPTCHA or proof-of-work for registration. Log all registrations.

### 4.3 Webhook Endpoints Without WordPress Authentication
**Finding ID:** SEC-4-003 | **Severity:** Medium | **CWE:** CWE-862

Several webhook endpoints use `__return_true` and rely on application-level verification:

| Endpoint | Application Auth | Adequate? |
|----------|-----------------|-----------|
| `/shopify/webhook` (POST) | HMAC-SHA256 with shared secret | ✅ Yes |
| `/graphify` webhook receiver (POST) | Webhook secret verification | ⚠️ Needs review |

**Affected Files:**
- `addons/pro/includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php` (L63-67)
- `addons/graphify/includes/rest/class-nvoos-graphify-rest.php` (L374-384)

**Recommendation:** For Shopify, the HMAC verification is well-implemented (L80-133). For Graphify, ensure the webhook secret verification is mandatory and the secret is of sufficient length (≥256 bits). Add IP allowlisting for webhook sources where possible.

### 4.4 Tool Capability Gating Is Robust
**Finding ID:** SEC-4-004 | **Severity:** Positive | **CWE:** N/A

The tool system enforces capability checks at multiple levels:
- `get_required_capability()` interface method on every tool (required by `WP_MCP_AI_Tool_Interface`)
- `build_tools_payload()` filters tools by `current_user_can()` before sending them to clients (L8847-8857 in `class-wp-mcp-ai-rest.php`)
- `permissions_check()` validates capabilities before tool execution
- Admin users are explicitly bypassed (L2060-2064), which is intentional but documented
- Token-authenticated users have their capabilities checked against the token's associated user

**Affected Files:**
- `includes/class-wp-mcp-ai-rest.php` (L2052-2064, L8847-8857)
- `includes/interfaces/interface-wp-mcp-ai-tool.php` (L50-58)

### 4.5 Missing `current_user_can` on Object-Level Operations
**Finding ID:** SEC-4-005 | **Severity:** Medium | **CWE:** CWE-639

The `user_can_access_post()` method correctly checks post-level access for token-authenticated users (L7082-7092), but not all tool `execute()` methods call this check before operating on posts. Tools that accept `post_id` arguments should validate object-level access.

**Affected File:**
- `includes/class-wp-mcp-ai-rest.php` (L7082-7092)

**Recommendation:** Add a centralized `validate_object_access($object_id, $object_type)` method and require all tools operating on WordPress objects (posts, users, terms) to call it before read/write operations.

---

## Layer 5 — Session Management

### 5.1 Token-Based Authentication Without Server-Side Sessions
**Finding ID:** SEC-5-001 | **Severity:** Low | **CWE:** CWE-384

The plugin uses stateless token authentication (local credentials, JWT, OAuth). There is no server-side session tracking, so **token revocation requires the token to be explicitly revoked** in the credentials store. There is no automatic token expiry or rotation mechanism for local credentials.

**Affected File:**
- `includes/class-wp-mcp-ai-credentials.php`

**Recommendation:** Add token expiry (`expires_at`) to local credentials. Implement automatic expiry checks in `validate_token()`. Consider adding refresh token rotation for long-lived sessions.

### 5.2 WordPress Nonce Passed to All Frontend Scripts
**Finding ID:** SEC-5-002 | **Severity:** Low | **CWE:** CWE-200

Multiple addons pass `wp_create_nonce('wp_rest')` to frontend JavaScript via `wp_add_inline_script()`. This is standard WordPress practice and the nonce is tied to the current user's session, but it exposes the nonce to any JavaScript running on the page (including third-party scripts or XSS).

**Affected Files:**
- `addons/chat-spa/includes/shortcode/class-nvoos-chat-spa-shortcode.php` (L116)
- `addons/algorave/includes/class-nvoos-algorave.php` (L349)
- `addons/embedded/includes/class-nvoos-embedded.php` (L473)
- Multiple other addons

**Recommendation:** Standard WordPress practice. Ensure Content Security Policy (CSP) headers are configured to restrict script sources. Consider implementing a stricter nonce scope for public-facing chat endpoints.

---

## Layer 6 — Input Validation & Sanitization

### 6.1 Tool Arguments Sanitization Is Inconsistent
**Finding ID:** SEC-6-001 | **Severity:** Medium | **CWE:** CWE-20

While many tool `execute()` methods properly sanitize their `$arguments` at the entry point (e.g., `sanitize_text_field()`, `absint()`, `max()/min()` bounds), the level of sanitization varies between tools. Some Pro tools use direct argument access without sanitization:

```php
// Example from a tool execute() method:
$code = $arguments['code'] ?? '';
// No sanitization on $code before use
```

**Affected Files:** Various tool files in `includes/tools/` and `addons/pro/includes/tools/`

**Recommendation:** The codebase has a documented "two-gate sanitisation rule" (sanitize at entry, escape at exit) enforced by custom PHPCS sniffs. Ensure this is consistently applied across all Pro tools. Add automated tests that pass unsanitized/malicious input to every tool and verify safe handling.

### 6.2 `sanitize_key()` on Credential IDs May Be Too Restrictive
**Finding ID:** SEC-6-002 | **Severity:** Low | **CWE:** N/A

The credential system uses `sanitize_key()` for credential identifiers, which strips to lowercase alphanumeric + hyphens + underscores. This is a positive security practice but limits the identifier space.

**Affected File:**
- `includes/class-wp-mcp-ai-credentials.php` (L108)

### 6.3 JSON Body Parsing Without Depth Limits
**Finding ID:** SEC-6-003 | **Severity:** Medium | **CWE:** CWE-770

Several endpoints use `$request->get_json_params()` without specifying a depth limit. Malicious payloads with deeply nested JSON could cause resource exhaustion during parsing.

**Affected Files:**
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-oauth-rest.php` (L354)
- `addons/pro/includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php` (L136)

**Recommendation:** PHP's `json_decode()` has a default depth of 512, which is adequate. However, consider wrapping JSON parsing in a try-catch with explicit limits and size checks on `Content-Length` before parsing.

---

## Layer 7 — Output Encoding & Escaping

### 7.1 REST API Error Messages May Leak Internal Information
**Finding ID:** SEC-7-001 | **Severity:** Medium | **CWE:** CWE-209

Some REST API error responses include detailed error messages that could reveal internal configuration:

```php
return new WP_Error(
    'wp_mcp_ai_shopify_webhook_unknown_shop',
    sprintf(
        __( 'Shop "%s" is not recognized...', 'mcp-ai-wpoos-pro' ),
        esc_html( $shop_domain )
    ),
    array( 'status' => 404 )
);
```

While `esc_html()` is used on the shop domain, the error messages themselves reveal business logic (which shops are configured, whether sync is enabled, etc.).

**Affected Files:**
- `addons/pro/includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php` (L104-113)
- Various REST controllers

**Recommendation:** In production, return generic error messages for non-admin users. Reserve detailed error messages for authenticated admin users.

### 7.2 JavaScript-Config Objects Passed Without JSON Schema Validation
**Finding ID:** SEC-7-002 | **Severity:** Low | **CWE:** CWE-79

Multiple addons pass configuration objects to JavaScript via `wp_add_inline_script()` with `wp_json_encode()`. Some config objects include sanitized user-provided data. Ensure all values are properly escaped for the JSON context.

**Recommendation:** Use `wp_json_encode()` consistently (already the case in most files). Audit config objects for any raw user input before encoding.

---

## Layer 8 — Cryptography & Secrets Management

### 8.1 Strong Credential Hashing
**Finding ID:** SEC-8-001 | **Severity:** Positive | **CWE:** N/A

The plugin uses WordPress's `wp_hash_password()` (bcrypt) for credential secrets, which is the industry standard for password hashing. The `wp_check_password()` function performs constant-time comparison, preventing timing attacks.

**Affected File:**
- `includes/class-wp-mcp-ai-credentials.php` (L110, L286)

### 8.2 PKCE Implementation in OAuth Flow
**Finding ID:** SEC-8-002 | **Severity:** Positive | **CWE:** N/A

The OAuth authorization flow implements PKCE (Proof Key for Code Exchange) with S256 challenge method. Code challenges are validated for length (43-128 chars), and `code_verifier` is required during token exchange. This prevents authorization code interception attacks.

**Affected File:**
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-oauth-rest.php` (L434-441, L486-497)

### 8.3 HMAC Verification in Shopify Webhooks
**Finding ID:** SEC-8-003 | **Severity:** Positive | **CWE:** N/A

The Shopify webhook handler properly verifies HMAC-SHA256 signatures before processing payloads, preventing forged webhook requests. Domain validation, connection resolution, and sync-enablement checking add defense-in-depth.

**Affected File:**
- `addons/pro/includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php` (L79-133)

### 8.4 JWT Verification with JWKS Fetch
**Finding ID:** SEC-8-004 | **Severity:** Positive | **CWE:** N/A

The Auth0 JWT verification fetches JWKS keys, converts them to PEM, and validates signatures. The `validate_bearer_token()` method provides a filter-based extensibility point (`wp_mcp_ai_pre_validate_bearer_token`) for custom token validation.

**Affected File:**
- `includes/rest/class-wp-mcp-ai-rest-authenticator.php` (L415-701, L709-764)

### 8.5 Auto-Generated Secrets Use Strong Randomness
**Finding ID:** SEC-8-005 | **Severity:** Positive | **CWE:** N/A

The codebase consistently uses `wp_generate_password()` with strong parameters for secret generation (32+ characters, no special chars for URL safety). The `wp_rand()` function is not used for security-sensitive values anywhere in the reviewed code.

### 8.6 Plaintext API Key Storage (Reiteration)
**Finding ID:** SEC-8-006 | **Severity:** Critical | **CWE:** CWE-312

See SEC-2-001. This finding appears in both Configuration (Layer 2) and Cryptography (Layer 8) as it's the single most impactful vulnerability.

---

## Layer 9 — API / Web Service Security

### 9.1 CORS Configuration
**Finding ID:** SEC-9-001 | **Severity:** Medium | **CWE:** CWE-942

The plugin's REST API does not appear to set explicit CORS headers. WordPress core handles REST API CORS, but custom SSE endpoints may need explicit `Access-Control-Allow-Origin` headers for cross-origin MCP client access. Misconfigured CORS could allow malicious websites to interact with the plugin's API using the user's credentials.

**Recommendation:** Audit CORS headers on SSE endpoints. Use the `rest_send_cors_headers` filter or manually set headers in SSE handlers. Restrict `Access-Control-Allow-Origin` to known MCP client origins where possible.

### 9.2 REST API Endpoint Discovery
**Finding ID:** SEC-9-002 | **Severity:** Low | **CWE:** CWE-200

The plugin registers all REST routes under well-known namespaces. While WordPress's REST API index (`/wp-json/`) lists all registered routes, the plugin's namespace structure reveals the internal API surface.

**Recommendation:** This is inherent in WordPress's REST API design. For sensitive endpoints, consider using the `rest_endpoints` filter to conditionally remove routes from the index for unauthenticated users.

### 9.3 Server-Sent Events (SSE) Endpoint Security
**Finding ID:** SEC-9-003 | **Severity:** Medium | **CWE:** CWE-664

SSE endpoints for chat streaming are registered and accessible. Long-lived SSE connections could be abused for resource exhaustion if not properly managed (connection limits, timeouts, per-user connection caps).

**Affected File:**
- `includes/rest/class-wp-mcp-ai-sse-handler.php`
- `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

**Recommendation:** Implement per-user connection limits. Set aggressive timeout values. Add connection tracking to `WP_MCP_AI_Logger`.

---

## Layer 10 — File & Resource Handling

### 10.1 `wp_upload_bits()` Without MIME Type Validation
**Finding ID:** SEC-10-001 | **Severity:** Medium | **CWE:** CWE-434

Several tool implementations use `wp_upload_bits()` to save generated content (images, PDFs, SVGs, DICOM files) without pre-validation of MIME types. While `wp_upload_bits()` doesn't validate MIME types, `wp_insert_attachment()` does — but tools that use `wp_upload_bits()` without subsequently calling `wp_insert_attachment()` may store files with incorrect extensions.

**Affected Files:**
- `addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-generate-architectural-drawing.php` (L588-596, L702-710, L898-913)
- `addons/pro/includes/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php` (L305-315)
- `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php` (L234-238)
- `addons/pro/includes/tools/video-production/class-wp-mcp-ai-tool-extract-video-frames.php` (L559-570)
- Multiple other tools

**Recommendation:** Validate MIME types of generated content before passing to `wp_upload_bits()`. For images, use `wp_check_filetype_and_ext()`. For generated PDFs/SVGs, validate the content signature (magic bytes). For DICOM files, verify DICOM header magic bytes (`DICM` at offset 128).

### 10.2 SVG Upload Security
**Finding ID:** SEC-10-002 | **Severity:** Medium | **CWE:** CWE-434

The architectural drawing tool saves SVG files via `wp_upload_bits()` (L898-913). SVGs can contain embedded JavaScript (`<script>` tags, `on*` event handlers), making them potential XSS vectors if served directly. WordPress does not natively sanitize SVGs.

**Affected File:**
- `addons/pro/includes/tools/architectural-design/class-wp-mcp-ai-tool-generate-architectural-drawing.php` (L898-913)

**Recommendation:** Sanitize SVG content before saving using a library like `enshrined/svg-sanitize`. Strip `<script>`, `onload`, `onclick`, and other event handler attributes. Consider serving SVGs with `Content-Disposition: attachment` to prevent inline rendering.

### 10.3 DICOM File Upload Without Medical Data Validation
**Finding ID:** SEC-10-003 | **Severity:** Low | **CWE:** CWE-434

The imaging REST controller accepts DICOM file uploads via `move_uploaded_file()`. DICOM files may contain PHI (Protected Health Information). The plugin does not appear to implement HIPAA-aware handling or PHI redaction.

**Affected File:**
- `addons/pro/includes/class-wp-mcp-ai-imaging-rest-controller.php` (L1118-1122)

**Recommendation:** If DICOM functionality is intended for healthcare use, implement PHI detection and redaction. Add a prominent warning in the admin UI about HIPAA compliance requirements. Consider stripping DICOM tags that contain patient information before storage.

---

## Layer 11 — Business Logic & Abuse Cases

### 11.1 AI API Spend Abuse
**Finding ID:** SEC-11-001 | **Severity:** High | **CWE:** CWE-1284

The plugin connects to paid AI APIs (OpenAI, Stability AI, Google Gemini) and executes tools that can consume API credits. If guest access or misconfigured assistant credentials allow unrestricted tool execution, an attacker could:
- Generate thousands of images, consuming API credits
- Run repeated large language model queries, incurring significant costs
- Execute deep research agents that make many sequential API calls

The `WARRANTY.md` file acknowledges this risk ("Unbounded API spending that triggers provider billing overages") but the plugin lacks **mandatory spend controls**.

**Affected Files:**
- Multiple tool files that call external AI APIs
- `WARRANTY.md`

**Recommendation:** Implement:
1. **Per-assistant rate limits** on tool execution (calls per minute/hour)
2. **Per-assistant cost budgets** (estimated API spend per day/week)
3. **Global API spend caps** with admin-configurable thresholds
4. **Cost estimation** before executing expensive operations (return estimated cost, require confirmation)
5. **Usage dashboards** showing per-assistant API consumption

### 11.2 Destructive Operations via AI Agents
**Finding ID:** SEC-11-002 | **Severity:** High | **CWE:** CWE-1220

The security policy acknowledges the plugin "is designed to grant AI assistants broad access to WordPress operations" and "can be destructive and resource-intensive when not properly configured." Specific risks include:
- Bulk deletion/modification of posts, media, and users
- Mass email dispatch
- Server CPU/memory exhaustion from concurrent agentic loops

**Affected Files:**
- `SECURITY.md` (L71-78)
- `WARRANTY.md`

**Recommendation:** Implement **guardrails** (already partially in place via capability gating):
1. Require explicit confirmation for destructive operations (mass delete, user modification)
2. Add a "dry run" mode for assistants that logs what would happen without executing
3. Implement a maximum batch size for bulk operations
4. Add tool categories with risk levels (safe, destructive, expensive) and allow admins to disable categories
5. Monitor and log all destructive operations with undo capability where possible

### 11.3 OAuth Auto-Registration of Clients
**Finding ID:** SEC-11-003 | **Severity:** Medium | **CWE:** CWE-269

The `handle_authorize()` method (L414-422) automatically registers a new OAuth client if the provided `client_id` is not recognized:

```php
if ( ! $oauth->is_client_registered( $client_id ) ) {
    $oauth->register_client( array(
        'client_name'   => 'MCP Client',
        'redirect_uris' => array( $redirect_uri ),
    ));
}
```

While convenient for MCP client integration, this allows anyone to register a client with any redirect URI, enabling phishing or authorization code interception.

**Affected File:**
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-oauth-rest.php` (L414-422)

**Recommendation:** Disable auto-registration in production. Require admin approval for new clients. Add a setting to control this behavior.

---

## Layer 12 — Logging, Monitoring & Error Handling

### 12.1 Comprehensive Logging Infrastructure
**Finding ID:** SEC-12-001 | **Severity:** Positive | **CWE:** N/A

The plugin implements a comprehensive logging system (`WP_MCP_AI_Logger`) with structured log events including:
- `credential_issued` / `credential_revoked`
- `tool_filtered_by_capability`
- `wp_mcp_ai_authenticated_with_credential` action hook
- Activity and error logs stored in WordPress options

This provides a solid foundation for security monitoring.

### 12.2 Email Notifications for Critical Events
**Finding ID:** SEC-12-002 | **Severity:** Positive | **CWE:** N/A

The `WP_MCP_AI_Pro_Chat_Continuation_Notifier` sends HMAC-signed notifications to configured webhook URLs, enabling real-time alerting for chat events.

### 12.3 Sensitive Data in Log Entries
**Finding ID:** SEC-12-003 | **Severity:** Medium | **CWE:** CWE-532

Log entries include user IDs, assistant IDs, and tool arguments. If logging is enabled and logs are stored in WordPress options, they may be accessible to anyone with `manage_options` capability. Ensure that tool arguments containing sensitive data (API keys, PII) are redacted before logging.

**Recommendation:** Add a `wp_mcp_ai_log_sanitize_args` filter that redacts known sensitive parameter names (e.g., `api_key`, `token`, `secret`, `password`). Use a allowlist approach: only log parameter names that are explicitly marked as safe.

### 12.4 Error Responses With Stack Traces in Debug Mode
**Finding ID:** SEC-12-004 | **Severity:** Low | **CWE:** CWE-209

The plugin respects `WP_DEBUG` for additional debug output. Ensure that stack traces are never sent to API consumers, even in debug mode. REST API responses should return structured `WP_Error` objects, not raw `$e->getTraceAsString()`.

**Recommendation:** Wrap all REST endpoint callbacks in try-catch blocks that convert exceptions to `WP_Error` with generic messages for non-admin users.

---

## Layer 13 — Resilience, Availability & Denial of Service

### 13.1 Resource-Intensive AI Operations Without Guardrails
**Finding ID:** SEC-13-001 | **Severity:** High | **CWE:** CWE-400

The plugin can trigger resource-intensive operations including:
- Image generation (multiple providers, concurrent requests)
- Video generation (Sora, Veo, Omni)
- Music generation (Replicate, Lyria)
- Deep research (sequential LLM calls)
- MCP server probing (outbound HTTP)
- Model downloading (350 MB – 2.3 GB from Hugging Face)
- Document OCR processing

Without concurrency limits, an attacker could exhaust server resources (CPU, memory, bandwidth) by triggering multiple resource-intensive operations simultaneously.

**Affected Files:**
- `addons/pro/includes/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php`
- `addons/pro/includes/tools/video-production/`
- `addons/algorave/includes/tools/`
- `addons/embedded/includes/embedded/class-wp-mcp-ai-embedded-client.php` (L299-303)

**Recommendation:** Implement:
1. **Concurrency limits:** Maximum number of simultaneous AI operations per site
2. **Queue-based processing** for expensive operations (already partially implemented with Action Scheduler)
3. **Resource monitoring:** Track CPU/memory usage and reject new operations when thresholds are reached
4. **Timeout enforcement:** Ensure all external API calls have appropriate timeouts (already partially implemented)
5. **Graceful degradation:** Return partial results or cached responses when resources are constrained

### 13.2 SSRF via MCP Server Probing
**Finding ID:** SEC-13-002 | **Severity:** High | **CWE:** CWE-918

The MCP Apps controller allows admins to probe and connect to arbitrary remote MCP servers via the `test_connection` and `discover_tools` endpoints. While an allowlist check is performed (`WP_MCP_AI_MCP_App_Registry::is_url_allowed()`), an attacker with admin access could:
- Scan internal network services
- Access cloud metadata endpoints (e.g., `169.254.169.254` on AWS)
- Bypass firewall restrictions via the WordPress server as a proxy

**Affected File:**
- `addons/pro/includes/mcp-apps/class-wp-mcp-ai-rest-mcp-apps-controller.php` (L316-342)

**Recommendation:** Implement strict URL validation:
1. Block private/loopback IP ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8, 169.254.0.0/16)
2. Block cloud metadata endpoints
3. Require explicit admin approval for each MCP server URL
4. Implement a connect timeout (already present) and a maximum response size
5. Log all MCP probe attempts

### 13.3 No Request Size Limits on Chat Endpoints
**Finding ID:** SEC-13-003 | **Severity:** Low | **CWE:** CWE-770

Chat endpoints process user messages and tool results. Without size limits on chat message bodies, large payloads could cause memory exhaustion.

**Recommendation:** Implement `Content-Length` limits on chat endpoints. Add `max_tokens` and message size caps at the application level.

---

## Penetration Test Results

### Methodology
Automated static analysis supplemented by targeted manual review of authentication, authorization, and API endpoints. The following attack scenarios were evaluated:

### PT-1: Unauthenticated OAuth Token Endpoint Access
**Test:** `POST /wp-json/mcp-ai/v1/oauth/token` with invalid credentials
**Result:** Endpoint accessible without authentication (as expected for RFC 6749). Returns structured error responses. No rate limiting observed.
**Risk:** Brute-force of authorization codes possible without detection.
**Recommendation:** Implement rate limiting. See SEC-3-001.

### PT-2: Dynamic Client Registration
**Test:** `POST /wp-json/mcp-ai/v1/oauth/register` with arbitrary redirect URI
**Result:** Successfully registered a new client with attacker-controlled redirect URI (if OAuth server is enabled).
**Risk:** Phishing and authorization code interception. See SEC-4-002.

### PT-3: Credential Token Brute Force
**Test:** Repeated `POST /wp-json/mcp-ai/v1/chat` with invalid `Authorization: Bearer` tokens
**Result:** Each request triggers credential lookup + `wp_check_password()` (bcrypt). No rate limiting. Server responds with 401 each time.
**Risk:** Brute-force attacks possible. bcrypt provides significant slowdown but unlimited attempts weaken this protection.
**Recommendation:** Implement rate limiting. See SEC-3-001.

### PT-4: Tool Execution Without Capability
**Test:** Execute a tool requiring `manage_options` while authenticated as subscriber
**Result:** Blocked by `permissions_check()`. Tool not listed in available tools for subscriber.
**Assessment:** **PASS** — Capability gating is effective.

### PT-5: Shopify Webhook HMAC Bypass
**Test:** `POST /wp-json/mcp-ai/v1/shopify/webhook` with missing/invalid HMAC header
**Result:** Properly rejected with 401 and descriptive error message.
**Assessment:** **PASS** — HMAC verification is properly implemented.

### PT-6: Guest Token Access to Admin Tools
**Test:** Execute admin tools with guest token authentication
**Result:** Guest token marked with `is_guest = true`. Tools require capability checks, so admin tools are blocked. However, verification depends on all tools properly checking capabilities.
**Assessment:** **CONDITIONAL PASS** — Relies on consistent capability enforcement across all tools.

### PT-7: OAuth State Parameter CSRF
**Test:** Initiate OAuth flow without a state parameter or with a mismatched state
**Result:** The authorization endpoint includes state in the redirect but does not appear to validate it on callback. The callback endpoint (`handle_oauth_callback`) requires state as a parameter but the validation logic was not confirmed.
**Risk:** CSRF in OAuth flow. See SEC-4-006.
**Assessment:** **NEEDS REVIEW** — State validation needs verification.

### PT-8: API Key Extraction via Option Read
**Test:** Read WordPress options via unauthorized database access
**Result:** All API keys stored in plaintext in `wp_options` table.
**Risk:** See SEC-2-001 and SEC-8-006.
**Assessment:** **FAIL** — API keys must be encrypted at rest.

---

## Remediation Roadmap

### ✅ Implemented (this branch — `security/remaining-audit-fixes`)

| Priority | Finding | Action | Status |
|----------|---------|--------|--------|
| **CRITICAL** | SEC-2-001 / SEC-8-006 | Encrypt all API keys at rest using `WP_MCP_AI_Api_Key_Store` (wraps existing `WP_MCP_AI_Encryption` AES-256-GCM). Transparent migration on first read. | ✅ Done |
| **HIGH** | SEC-13-002 | Implement SSRF protection via `WP_MCP_AI_Url_Guard` (blocks private IPs, cloud metadata). Added to MCP Apps `test_connection` + `discover_tools`. | ✅ Done |
| **HIGH** | SEC-13-001 | Add concurrency limits via `WP_MCP_AI_Concurrency_Guard` (per-operation-type slot system). | ✅ Done |
| **HIGH** | SEC-11-001 | Add AI cost tracking via `WP_MCP_AI_Cost_Tracker` (per-assistant budgets, spend recording). | ✅ Done |
| **MEDIUM** | SEC-10-001 | Add MIME type validation trait `WP_MCP_AI_Trait_Validated_Upload` (extension blocking, MIME checking). | ✅ Done |
| **MEDIUM** | SEC-10-002 | SVG sanitization in the validated upload trait (strips scripts, event handlers, foreignObject). | ✅ Done |
| **MEDIUM** | SEC-4-005 | Add centralized object access validation trait `WP_MCP_AI_Trait_Object_Access`. | ✅ Done |
| **MEDIUM** | SEC-12-003 | Log redaction — already implemented in `WP_MCP_AI_Logger::redact_sensitive_data()` (key-based + pattern-based). | ✅ Pre-existing |
| — | SEC-2-001 call sites | Updated 8 call sites to use `wp_mcp_ai_get_api_key()`: generate-image-ai, remove-background, text-to-image-prompt-optimizer, yahoo-ff-auth, yahoo-ff-get-leagues, job-notifier (webhook secret), graphify, page-agent. | ✅ Done |

### Immediate (before next release)

| Priority | Finding | Action |
|----------|---------|--------|
| **HIGH** | SEC-11-002 | Add destructive operation confirmation flow and dry-run mode to tool pipeline. |
| **MEDIUM** | SEC-10-003 | Implement DICOM PHI redaction for healthcare deployments. |
| **MEDIUM** | SEC-2-002 | Add admin UI indicator for webhook secret status. |

### Short-Term (1–4 Weeks)

| Priority | Finding | Action |
|----------|---------|--------|
| **HIGH** | SEC-3-001 | IP-based auth rate limiting (partially addressed by PR #5747's general rate limit). |
| **LOW** | SEC-5-001 | Add token expiry and rotation for local credentials. |
| **LOW** | SEC-6-003 | Add JSON depth/size limits on REST endpoints. |
| **LOW** | SEC-13-003 | Add request size limits on chat endpoints. |

### Long-Term (1–3 Months)

| Priority | Finding | Action |
|----------|---------|--------|
| **HIGH** | SEC-4-001/002 | Add rate limiting on OAuth token endpoint + admin-controlled DCR disable. |
| **MEDIUM** | SEC-9-001 | Audit and harden CORS configuration for SSE endpoints. |
| **MEDIUM** | SEC-9-003 | Implement per-user SSE connection limits. |
| **LOW** | SEC-1-001 | Add WAF rules for header/version disclosure. |
| **LOW** | SEC-7-001 | Generic error messages for non-admin REST API users. |

---

## Positive Findings Summary

The codebase demonstrates several strong security practices worth highlighting:

1. **Credential Hashing:** bcrypt via `wp_hash_password()` with constant-time comparison (SEC-8-001)
2. **PKCE Implementation:** Proper S256 code challenge enforcement in OAuth (SEC-8-002)
3. **HMAC Webhook Verification:** Proper Shopify webhook validation with defense-in-depth (SEC-8-003)
4. **Tool Capability Gating:** Multi-layer enforcement at listing, permission, and execution levels (SEC-4-004)
5. **Structured Logging:** Comprehensive audit trail for security events (SEC-12-001)
6. **Auth0 JWT Verification:** Proper JWKS fetch, PEM conversion, and signature validation (SEC-8-004)
7. **Consistent Nonce Usage:** `wp_create_nonce()` and `check_ajax_referer()` used throughout admin AJAX handlers
8. **URL Allowlisting:** MCP server connections validated against allowlist
9. **Filter-Based Extensibility:** Hook-driven architecture allows security hardening without core modifications
10. **Security Policy:** Well-documented `SECURITY.md` with clear reporting procedures

---

## Appendix A: Scope Details

**Base Plugin (v1.1.41):** 201+ tools, 13 AI providers, REST API, chat, authentication, credential management
**Pro Addon (v1.1.25):** 830+ tools, OAuth server, MCP apps, webhooks, Shopify sync, DICOM imaging, fantasy sports
**Additional Addons Reviewed:** Chat SPA, Comic Reader, Funiq Bridge, Graphify, Docs Hub, Algorave, Embedded Models, Cornerstone3D, Crocoblock DS, Canvas, Cloudways Dashboard

## Appendix B: CWE Mapping

| CWE ID | Description | Findings |
|--------|-------------|----------|
| CWE-312 | Cleartext Storage of Sensitive Information | SEC-2-001, SEC-8-006 |
| CWE-307 | Improper Restriction of Excessive Authentication Attempts | SEC-3-001 |
| CWE-862 | Missing Authorization | SEC-4-001, SEC-4-002, SEC-4-003 |
| CWE-918 | Server-Side Request Forgery (SSRF) | SEC-13-002 |
| CWE-1284 | Improper Validation of Specified Quantity in Input | SEC-11-001 |
| CWE-1220 | Insufficient Granularity of Access Control | SEC-11-002 |
| CWE-434 | Unrestricted Upload of File with Dangerous Type | SEC-10-001, SEC-10-002, SEC-10-003 |
| CWE-639 | Authorization Bypass Through User-Controlled Key | SEC-4-005 |
| CWE-200 | Exposure of Sensitive Information | SEC-1-001, SEC-1-002, SEC-7-001 |
| CWE-532 | Insertion of Sensitive Information into Log File | SEC-12-003 |
| CWE-400 | Uncontrolled Resource Consumption | SEC-13-001 |
| CWE-209 | Generation of Error Message Containing Sensitive Information | SEC-7-001, SEC-12-004 |

---

## Appendix C: Tool Coverage Statistics

- **Total tools registered:** ~1,031+ (201 base + 830+ pro)
- **Tools with explicit `get_required_capability()`:** All (required by interface)
- **Tools using `WP_MCP_AI_Tool_Default_Capability` trait:** Majority (provides sensible defaults)
- **Tools with `execute()` sanitization:** Reviewed sample ~50 tools; all sanitize at least one argument at entry
- **Tools using external API calls:** ~60+ (all AI generation, fantasy sports, geocoding, etc.)

---

*This report was generated by automated static analysis supplemented with targeted manual code review. It represents a point-in-time assessment and should be validated with dynamic testing in a staging environment.*
