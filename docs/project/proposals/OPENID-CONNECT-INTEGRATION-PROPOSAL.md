# OpenID Connect (OIDC) Integration Proposal

**Last Updated:** June 11, 2026 (v1.1.29 status refresh)
**Status:** 📋 Not Started — Generic OIDC provider not yet built. OIDC token validation exists only in Google Chat webhook context (`class-wp-mcp-ai-google-chat-webhook-controller.php`). Auth0 integration ships but no configurable IdP support.
**Recommendation:** ✅ YES — Implement Native Generic OIDC Provider Support
**Estimated Effort:** 6–10 weeks for core + admin UI; 2–3 additional weeks for Pro tools

---

## Executive Summary

This proposal evaluates whether it makes sense to integrate a **native, provider-agnostic OpenID Connect (OIDC) authentication layer** into **NV oOS (Open Operator System)**. The plugin already ships with an Auth0 integration (which speaks OIDC under the hood), a Simple JWT Login bridge, a WordPress.com/Gravatar identity bridge, and OAuth 2.0 flows for Gmail, Google Drive, GitHub, Mailjet, QuickBooks, and Cloudways. Despite this breadth, there is no single, configurable OIDC client that site owners can point at *any* OIDC-compliant Identity Provider (IdP)—such as Keycloak, Okta, Microsoft Entra ID (Azure AD), AWS Cognito, Authentik, Dex, or a self-hosted server—without writing custom code.

**Recommendation:** Add a first-class Generic OIDC Provider to the base plugin. The implementation leverages the already-vendored `league/oauth2-client` library and adds lightweight ID Token (JWT) validation on top. This closes the last major authentication gap, enables enterprise SSO use-cases that customers are already requesting, and is achievable with minimal new dependencies.

---

## Quick Status

| Approach | Status | Effort | Recommendation |
|----------|--------|--------|----------------|
| **Generic OIDC Provider (new)** | ✅ RECOMMENDED | 6–10 weeks | Closes gap; works with every IdP |
| **Auth0 only (current)** | ⚠️ PARTIAL | Already done | Vendor lock-in; Auth0-hosted only |
| **Simple JWT Login bridge (current)** | ⚠️ PARTIAL | Already done | Requires separate plugin; no discovery |
| **Per-provider OAuth handlers (current)** | ⚠️ PARTIAL | Already done | Does not scale; duplication per IdP |
| **Third-party WP OIDC plugin** | ❌ NOT RECOMMENDED | N/A | Creates plugin dependency; less control |

---

## Problem Statement

### What NV oOS Already Has

The plugin has accumulated rich authentication infrastructure:

| Layer | What Exists | Location |
|-------|-------------|----------|
| **Auth0** | Full OIDC via Auth0 JWKS validation, required scopes, audience claim | `includes/admin/class-wp-mcp-ai-auth0-setup.php` |
| **Simple JWT Login** | JWT bridge; shares OIDC-style token flows with a 3rd-party plugin | `includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php` |
| **WordPress.com/Gravatar** | Subject-prefix detection (`wordpress.com|`, `gravatar|`), profile enrichment, auto-user creation | `includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php` |
| **GitHub OAuth** | OAuth 2.0 + Auth0→GitHub identity bridge | `includes/integrations/class-wp-mcp-ai-github-oauth-handler.php` |
| **Google OAuth** | Gmail + Google Drive (Authorization Code + refresh token) | `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` |
| **league/oauth2-client** | Already vendored; provides PKCE, state, refresh tokens | `vendor/league/oauth2-client/` |
| **Bearer token pipeline** | Pluggable `wp_mcp_ai_pre_validate_bearer_token` filter chain | `includes/rest/class-wp-mcp-ai-rest-authenticator.php` |

### The Gap

None of the above lets a site administrator enter an **OIDC Discovery Document URL** (e.g. `https://accounts.google.com/.well-known/openid-configuration`) and immediately have:

1. A working **Authorization Code + PKCE** login flow for the WordPress admin or the chat front-end.
2. **ID Token validation** (signature, issuer, audience, expiry, nonce) against JWKS from the discovery document.
3. **User provisioning** (create or map WordPress users) from standard OIDC claims (`sub`, `email`, `name`, `given_name`, `family_name`, `picture`).
4. **Access-token passthrough** so the plugin's REST API accepts tokens from *any* configured OIDC IdP.
5. A unified **admin UI** for all of the above—no coding required.

### Use Cases Customers Are Requesting

- **Enterprise SSO**: Teams using Keycloak, Okta, or Entra ID want employees to log in to NV oOS–powered dashboards with their corporate identity, not a separate WordPress password.
- **Healthcare / HIPAA**: Clients using AWS Cognito or Okta for patient identity want AI assistants to authenticate patients before surfacing sensitive data (health metrics, medical reports).
- **Multi-Tenant SaaS**: Platform operators running multiple WordPress sites want a single Authentik/Dex instance as the IdP, mapping tenants to WordPress users automatically.
- **Telegram Mini App**: The existing TMA controller handles per-user sessions; an OIDC layer would let operators issue OIDC tokens from Telegram's identity to the TMA without bespoke code.
- **B2B Integrations**: Companies using QuickBooks Online, Shopify, or Monday.com already authenticate their staff via corporate IdPs; OIDC lets those same identities flow into the AI assistant.

---

## What Is OpenID Connect?

OpenID Connect 1.0 is an identity layer on top of OAuth 2.0. It standardizes:

- **Discovery**: `/.well-known/openid-configuration` provides all endpoints and key sets automatically.
- **ID Token**: A signed JWT containing `iss`, `sub`, `aud`, `exp`, `iat`, and optional claims (`email`, `name`, `picture`, `nonce`, etc.).
- **PKCE**: Proof Key for Code Exchange — mandatory for public clients (Single Page Apps, native apps) and best practice for server-side flows.
- **UserInfo Endpoint**: Optional endpoint for fetching richer profile data post-authentication.
- **JWKS**: JSON Web Key Sets let the relying party validate ID Token signatures without trusting a shared secret.

Key IdPs that support OIDC out of the box: Google, Microsoft Entra ID, Okta, Auth0, AWS Cognito, Keycloak, Authentik, Dex, GitLab, GitHub (partial), Ping Identity, OneLogin.

---

## Current Architecture Analysis

### Authentication Pipeline

```
HTTP Request
    │
    ▼
WP_MCP_AI_REST_Authenticator::authenticate()
    │
    ├── 1. WordPress Nonce (X-WP-Nonce)
    ├── 2. Local Credential (cred_*.* bearer)
    ├── 3. Mesh Network API Key (mesh_* bearer)
    ├── 4. Auth0 OIDC Bearer (JWT validated via Auth0 JWKS)   ← OIDC already here, Auth0-only
    ├── 5. Simple JWT Login Bearer (delegated to SJL plugin)
    ├── 6. WordPress.com/Gravatar Bearer (sub-prefix detection)
    └── 7. Guest Token (X-WP-MCP-AI-Guest)
```

### What a Generic OIDC Step Would Add

```
    ├── 4a. Generic OIDC Bearer (JWT validated via any OIDC IdP's JWKS)   ← NEW
    ├── 4b. Auth0 OIDC Bearer (existing — unchanged)
```

The existing filter hook `wp_mcp_ai_pre_validate_bearer_token` already provides the correct extension point for adding a new validation step without modifying core REST code.

### Existing League OAuth2 Client Usage

The `league/oauth2-client` library (already vendored) handles:
- Authorization URL construction with `state` and PKCE `code_challenge`
- Authorization Code exchange for Access + Refresh Tokens
- Token refresh
- HTTP requests to token endpoints

**What it does NOT handle natively:**
- OIDC Discovery Document fetching and caching
- ID Token JWT validation (signature, issuer, audience, nonce, expiry)
- JWKS key set fetching and caching
- Standard OIDC claims mapping

These are the pieces the new integration would add.

---

## Proposed Solution

### Architecture Overview

```
NV oOS WordPress Plugin
    │
    ├── includes/integrations/
    │   ├── class-wp-mcp-ai-oidc-provider.php          # Core OIDC client (discovery, PKCE, token validation)
    │   ├── class-wp-mcp-ai-oidc-user-mapper.php       # Claims → WP_User mapping + provisioning
    │   └── class-wp-mcp-ai-oidc-jwks-cache.php        # JWKS key set fetcher + transient cache
    │
    ├── includes/admin/
    │   └── class-wp-mcp-ai-admin-oidc-settings.php    # Admin UI: discovery URL, client ID/secret, scopes
    │
    ├── includes/rest/
    │   └── (hook into existing authenticator via filter)
    │
    └── addons/pro/includes/
        ├── tools/class-wp-mcp-ai-tool-oidc-token-exchange.php   # PRO: OIDC token exchange tool
        └── rest/class-wp-mcp-ai-oidc-callback-controller.php     # PRO: per-connection OIDC callbacks (consistent with existing rest/ controllers such as class-wp-mcp-ai-slack-event-controller.php)
```

### Core Components

#### 1. `WP_MCP_AI_OIDC_Provider` — Core OIDC Client

Responsibilities:
- Fetch and cache the OIDC Discovery Document (`/.well-known/openid-configuration`) using WordPress Transients (TTL: 1 hour).
- Build the Authorization URL with PKCE `code_verifier` / `code_challenge` and store the `state` + `nonce` in a signed cookie or WordPress transient.
- Handle the Authorization Code callback: exchange code for tokens, validate the ID Token JWT.
- Expose `get_authorization_url()`, `handle_callback()`, `validate_id_token()`, and `get_user_info()` methods.

ID Token validation checklist (per OIDC Core 1.0 § 3.1.3.7):
- Signature verified against JWKS (RS256 / ES256 / RS384; support multiple algorithms).
- `iss` matches the issuer from the discovery document.
- `aud` contains the configured `client_id`.
- `exp` is in the future (with ≤ 5-minute clock skew tolerance).
- `iat` is not in the distant past (optional configurable window).
- `nonce` matches the value stored during the authorization request (replay protection).
- `sub` is non-empty.

#### 2. `WP_MCP_AI_OIDC_JWKS_Cache` — Key Set Cache

Responsibilities:
- Fetch `jwks_uri` from the discovery document.
- Cache key sets as WordPress Transients (TTL: configurable, default 1 hour).
- Support key rotation: if signature validation fails for a cached key, bust the cache and retry once.
- Support multiple key types: RSA (`RS256`, `RS384`, `RS512`) and EC (`ES256`, `ES384`).

#### 3. `WP_MCP_AI_OIDC_User_Mapper` — Claims → WordPress User

Responsibilities:
- Find an existing user by `sub` claim stored in user meta (`wp_mcp_ai_oidc_sub`).
- Fall back to email match if no `sub` match found.
- Optionally auto-provision new subscribers from OIDC claims: `email`, `name`, `given_name`, `family_name`, `picture`.
- Apply the `wp_mcp_ai_oidc_user_provisioned` action hook so Pro add-ons can extend provisioning logic (e.g. assign roles based on OIDC groups/roles claims).
- Map OIDC `roles` or `groups` claims to WordPress roles via configurable claim-to-role mappings.

#### 4. Admin Settings UI

New section under `Settings → NV oOS → Authentication → OpenID Connect`:

| Setting | Type | Description |
|---------|------|-------------|
| Enable OIDC | Toggle | Master switch |
| Discovery URL | Text | `https://…/.well-known/openid-configuration` |
| Client ID | Text | Registered client ID with IdP |
| Client Secret | Password (encrypted) | Registered client secret (AES-256-CBC, same encryption as remote connections) |
| Additional Scopes | Text | Extra scopes beyond `openid profile email` |
| Redirect URI | Read-only | Auto-generated WordPress callback URL |
| User Provisioning | Select | Disabled / Map existing / Auto-create subscribers |
| Role Claim | Text | OIDC claim to read for roles (e.g. `roles`, `groups`) |
| Claim → Role Map | Repeater | Map IdP role strings to WP role slugs |
| JWKS Cache TTL | Number | Seconds (default 3600) |
| API Bearer Auth | Toggle | Accept OIDC access tokens on REST endpoints |

#### 5. REST API Bearer Integration

Hook into the existing `wp_mcp_ai_pre_validate_bearer_token` filter (same mechanism as Simple JWT Login bridge) to accept access tokens or ID tokens issued by configured OIDC providers on all MCP REST endpoints:

```php
add_filter( 'wp_mcp_ai_pre_validate_bearer_token', array( $this, 'validate_oidc_bearer' ), 15, 2 );
```

The filter validates the token's `iss` against configured providers, fetches the correct JWKS, validates signature and claims, and maps `sub` to a WordPress user—following the exact same pattern as the Auth0 and Simple JWT Login bridges.

---

## Implementation Plan

### Phase 1 — Core OIDC Client (Weeks 1–3)

- [ ] `class-wp-mcp-ai-oidc-jwks-cache.php` — JWKS fetch + transient cache + key rotation
- [ ] `class-wp-mcp-ai-oidc-provider.php` — Discovery, PKCE, ID Token validation
- [ ] Unit tests: `tests/test-oidc-provider.php` — validate ID Token rejection scenarios (bad sig, wrong iss, expired, missing nonce, wrong aud)
- [ ] Integration test: mock OIDC server exchange with known key pair

### Phase 2 — User Mapping + REST Bearer (Weeks 4–5)

- [ ] `class-wp-mcp-ai-oidc-user-mapper.php` — `sub` / email lookup, auto-provision, roles claim mapping
- [ ] Hook into `WP_MCP_AI_REST_Authenticator` via `wp_mcp_ai_pre_validate_bearer_token` filter
- [ ] Update `docs/reference/api/authentication.md` with OIDC bearer section
- [ ] Unit tests for user mapping edge cases (no user, multiple matches, provisioning disabled)

### Phase 3 — Admin Settings UI (Weeks 6–7)

- [ ] `class-wp-mcp-ai-admin-oidc-settings.php` — Settings section with all fields listed above
- [ ] Encrypt client secret using existing AES-256-CBC encryption layer
- [ ] Auto-generate and display the Redirect URI
- [ ] Test discovery URL fetch + validation from admin screen ("Test Connection" button)
- [ ] Show configured scopes and discovered endpoints in read-only status section

### Phase 4 — Login Flow (Week 8)

- [ ] WordPress login page integration: "Log in with [IdP name]" button using `login_form` action hook
- [ ] Authorization Code + PKCE initiation endpoint (WordPress admin-ajax or REST)
- [ ] Callback handler: validate state + nonce, exchange code, map user, set WordPress auth cookies
- [ ] Session hardening: rotate nonce on each login attempt; single-use state tokens stored as transients
- [ ] Documentation: `docs/reference/api/authentication.md` — OIDC login flow section

### Phase 5 (Pro) — Advanced Features (Weeks 9–10)

- [ ] `class-wp-mcp-ai-tool-oidc-token-exchange.php` — Pro tool: exchange OIDC token for plugin credential (for Telegram Mini App and chat surfaces)
- [ ] Multi-provider support: allow configuring more than one OIDC provider (e.g. primary + fallback)
- [ ] Per-assistant OIDC scope restrictions: require specific OIDC `scope` or `roles` claim to access a given assistant
- [ ] `docs/proposals/OPENID-CONNECT-INTEGRATION-PROPOSAL.md` status → IN PROGRESS

---

## Technical Decisions

### JWT Validation Library

**Option A — Roll our own (Recommended for base plugin)**

Implement RS256/ES256 validation in ~150 lines of PHP using `openssl_verify()` and base64url decode—no new Composer dependency. The codebase already validates Auth0 JWTs this way in `class-wp-mcp-ai-auth0-setup.php`. Extending that pattern to generic OIDC keeps the vendor footprint at zero new packages.

**Option B — Add `firebase/php-jwt`**

Well-maintained, widely used (180M+ downloads). Supports RS256, RS384, RS512, ES256, ES384, HS256. Would require adding one new Composer dependency; no known vulnerabilities in current versions.

**Option C — Add `lcobucci/jwt`**

Strict PSR-7 compliant library, immutable value objects, full RFC 7519 coverage. Heavier than `firebase/php-jwt`; better for enterprise validation requirements.

**Recommendation:** Start with Option A (roll our own, consistent with existing Auth0 implementation) for the base plugin. Gate Option B or C behind a filter (`wp_mcp_ai_oidc_jwt_validator`) so Pro or 3rd-party code can swap the validator.

### PKCE

Always enabled (S256 method). Store `code_verifier` exclusively as a server-side WordPress transient keyed by the `state` parameter (TTL: 5 minutes, single-use). Delete the transient immediately on callback consumption. Storing the verifier server-side (rather than in a cookie) removes the risk of cookie theft or manipulation and aligns with OAuth 2.0 security best practices for confidential clients.

### State Parameter

Generate using `wp_generate_password( 32, false )`, store as a 5-minute transient keyed by state value. Validate on callback before any token exchange. This matches the existing pattern used by the Gmail and Google Drive OAuth handlers.

### Nonce

Generate a per-request nonce, include in the Authorization URL, store alongside state in the transient, validate in ID Token. Prevents token replay attacks.

### User Auto-Provisioning

Default: **off** (admin must explicitly enable). When enabled: create users as `subscriber` role unless the role claim map overrides. This matches WordPress best practices and reduces the attack surface for misconfigured IdPs.

### Encryption

Store `client_secret` using the existing `wp_mcp_ai_encrypt_credential()` / `wp_mcp_ai_decrypt_credential()` helpers (AES-256-CBC, v2 prefix)—consistent with how Remote Connection secrets are stored.

---

## Security Analysis

### Risks and Mitigations

| Risk | Severity | Mitigation |
|------|----------|------------|
| ID Token replay | High | Nonce validation (single-use, 5-min transient) |
| CSRF on callback | High | State parameter (single-use, 5-min transient) |
| Misconfigured JWKS endpoint (SSRF) | Medium | Validate `jwks_uri` is HTTPS; use `wp_safe_remote_get()` (blocks private IPs); allowlist hostname if needed |
| Algorithm confusion attack (alg=none, HS256 downgrade) | High | Allowlist RS256/RS384/RS512/ES256/ES384 only; reject `alg=none` explicitly |
| Insecure `client_secret` storage | Medium | AES-256-CBC encryption at rest (existing helper) |
| Overly broad user provisioning | Medium | Provisioning off by default; require explicit admin opt-in |
| Expired JWKS cache after key rotation | Low | Cache-bust + single retry on validation failure |
| Sub claim collision across IdPs | Medium | Namespace `sub` meta key with IdP `iss`: `wp_mcp_ai_oidc_{hash('sha256',$iss)}_sub` (SHA-256; avoids md5 collision risks) |
| Open redirect after login | Medium | Validate `redirect_to` against `wp_validate_redirect()` before use |

### Threat Model Alignment

The plugin already handles:
- Timing-safe token comparison (`hash_equals`) for mesh keys and local credentials.
- HTTPS enforcement for external API calls.
- Input sanitization via `sanitize_text_field()`, `esc_url_raw()`, `absint()`.

The OIDC implementation will follow these same patterns.

---

## Comparison: Auth0 vs. Generic OIDC

| Factor | Auth0 (Current) | Generic OIDC (Proposed) |
|--------|----------------|------------------------|
| IdP support | Auth0 only | Any OIDC-compliant IdP |
| Self-hosted | ❌ No | ✅ Yes (Keycloak, Authentik, Dex, etc.) |
| Cost | Auth0 has a free tier; paid plans for scale | Depends on IdP choice; many are free/open-source |
| Setup complexity | Medium (Auth0 dashboard + plugin settings) | Low–Medium (discovery URL + client ID/secret) |
| JWKS validation | ✅ Yes (Auth0-specific) | ✅ Yes (generic) |
| User provisioning | ✅ Via Auth0→GitHub bridge | ✅ Native claim mapping |
| Maintenance | Low (Auth0 handles IdP quirks) | Medium (must handle IdP variations) |
| Enterprise control | ❌ External vendor | ✅ Full control |

**Verdict:** Both should coexist. Auth0 remains the simplest path for teams already on Auth0. Generic OIDC unlocks the much larger market of self-hosted and enterprise IdP users.

---

## Effort Estimate

| Phase | Tasks | Estimated Weeks |
|-------|-------|----------------|
| Phase 1 — Core OIDC Client | JWKS cache, discovery, PKCE, ID Token validation, tests | 3 weeks |
| Phase 2 — User Mapping + REST Bearer | User mapper, REST authenticator hook, docs, tests | 2 weeks |
| Phase 3 — Admin Settings UI | Settings page, encryption, test connection button | 2 weeks |
| Phase 4 — Login Flow | WP login button, callback handler, hardening | 1 week |
| Phase 5 (Pro) — Advanced Features | Token exchange tool, multi-provider, per-assistant scopes | 2 weeks |
| **Total (base)** | | **~8 weeks** |
| **Total (base + Pro)** | | **~10 weeks** |

---

## Recommended Next Steps

1. **Review and approve this proposal.** Assign a milestone (e.g. v1.3.0 or Pro 1.3.0).
2. **Create a GitHub tracking issue** linking to this document and listing the Phase 1–5 tasks as checkboxes.
3. **Spike the JWKS validation** (~1 day): confirm `openssl_verify()` works cleanly with RS256 keys from Google, Keycloak, and Okta discovery documents.
4. **Begin Phase 1 implementation** once the spike validates the no-new-dependency approach.
5. **Update this document** status to `🚧 IN PROGRESS` when implementation starts.

---

## Alternatives Considered and Rejected

### Use an existing WordPress OIDC Plugin as a Dependency

Plugins like **OpenID Connect Generic Client** (daggerhart) or **miniOrange OAuth 2.0** are popular but:
- Add an uncontrolled plugin dependency (updates, breaking changes, support burden).
- Cannot be deeply integrated into the plugin's REST authenticator pipeline.
- Do not expose the hook surface needed for per-assistant OIDC scope enforcement.
- **Rejected.**

### Extend Auth0 Integration to Support Additional Issuers

Auth0 natively supports social connections and enterprise SAML/OIDC as upstream IdPs, so technically a site could route all identity through Auth0 even for Keycloak or Okta users. However:
- Requires customers to have and pay for an Auth0 account.
- Adds latency (double hop: client → Auth0 → enterprise IdP).
- Locks customers into Auth0 as a broker.
- **Rejected for core; still valid as an optional pattern for Auth0 customers.**

### Add Full SAML 2.0 Support

SAML is common in enterprise environments but significantly more complex than OIDC:
- XML signatures, assertions, metadata exchange.
- No good lightweight PHP library without heavy dependencies (onelogin/php-saml).
- Many enterprises are actively migrating from SAML to OIDC.
- **Deferred: evaluate after OIDC is stable; OIDC first covers the majority of use cases.**

---

## References

- [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html)
- [RFC 7636 — PKCE](https://datatracker.ietf.org/doc/html/rfc7636)
- [RFC 7517 — JSON Web Key Sets](https://datatracker.ietf.org/doc/html/rfc7517)
- [RFC 7519 — JSON Web Tokens](https://datatracker.ietf.org/doc/html/rfc7519)
- [OpenID Connect Discovery 1.0](https://openid.net/specs/openid-connect-discovery-1_0.html)
- [league/oauth2-client](https://github.com/thephpleague/oauth2-client) — already vendored
- [Existing Auth0 integration](../reference/api/mcp-server-authentication.md)
- [Existing authentication docs](../reference/api/authentication.md)
- [OAuth compliance audit](../oauth-compliance.md)
