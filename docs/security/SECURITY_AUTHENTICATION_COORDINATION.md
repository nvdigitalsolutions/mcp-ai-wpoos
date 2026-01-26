# Security & Authentication Settings Coordination Guide

## Overview

The NV oOS plugin has **two complementary settings pages** for access control:

1. **Authentication Tab** (`tab=authentication`) - Configures authentication **methods**
2. **Security Tab** (`tab=security`) - Configures security **policies**

This document explains how they work together and provides configuration examples.

---

## Settings Architecture

### Authentication Tab: "HOW to Authenticate"

**Location**: `admin.php?page=wp-mcp-ai-dashboard&tab=authentication`

**Purpose**: Configure authentication methods and identity providers

**Subtabs**:
- **Auth0 Configuration**: Enterprise SSO via Auth0
- **GitHub Bridge**: GitHub user mapping
- **WordPress.com/Gravatar**: WordPress.com identity
- **Simple JWT Login**: JWT token configuration
- **Guest Access**: Guest token lifetime
- **REST API Capabilities**: Enable/disable specific REST operations

**Key Settings**:
```
├── auth0_domain                      (Auth0 tenant)
├── auth0_audience                    (API identifier)
├── guest_token_lifetime              (How long guest tokens are valid)
├── rest_enable_assistant_list        (Enable GET /assistants)
├── rest_enable_assistant_create      (Enable POST /assistants)
├── rest_enable_assistant_delete      (Enable DELETE /assistants)
└── sse_enable_post_method            (Allow POST on SSE)
```

---

### Security Tab: "WHEN Authentication is Required"

**Location**: `admin.php?page=wp-mcp-ai-dashboard&tab=security`

**Purpose**: Enforce security policies and access controls

**Sections**:
- **Global Access Control**: Master authentication switches
- **REST API Endpoint Protection**: Per-endpoint auth requirements
- **Media & File Protection**: Protect direct file access
- **Role & Capability Controls**: RBAC restrictions
- **Network Security**: IP filtering, HTTPS
- **Rate Limiting**: Abuse prevention
- **Audit Logging**: Compliance logging
- **Security Headers**: OWASP headers
- **Advanced Security**: Root key, 2FA, SSL bypass

**Key Settings**:
```
├── require_authentication_all        (MASTER: Block all unauthenticated access)
├── allow_guest_access                (Whether guest tokens work)
├── require_auth_chat_endpoints       (Require auth for /chat)
├── require_auth_tool_execution       (Require auth for tools)
├── require_auth_assistant_management (Require auth for assistants)
├── protect_media_urls                (Require auth for uploads)
├── enable_ip_whitelist               (IP filtering)
├── require_https                     (HTTPS enforcement)
└── enable_security_audit_log         (Track all access)
```

---

## How They Work Together

### Relationship Model

```
┌─────────────────────────────────────────────────────────────┐
│                    Incoming Request                          │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  SECURITY TAB: Policy Enforcement                            │
│  ├─ IP Filtering (Whitelist/Blacklist)                      │
│  ├─ HTTPS Requirement Check                                 │
│  └─ Is authentication required for this endpoint?            │
└───────────────────────────┬─────────────────────────────────┘
                            │
                   ┌────────┴────────┐
                   │   Auth Required? │
                   └────────┬────────┘
                            │
            ┌───────────────┴───────────────┐
            │ NO                            │ YES
            ▼                               ▼
    ┌──────────────┐          ┌─────────────────────────────┐
    │ Allow Access │          │ AUTHENTICATION TAB: Methods  │
    └──────────────┘          │ ├─ Try WordPress nonce       │
                              │ ├─ Try Bearer token           │
                              │ ├─ Try Auth0 token            │
                              │ ├─ Try JWT token              │
                              │ └─ Try Guest token            │
                              └──────────┬──────────────────┘
                                         │
                              ┌──────────┴──────────┐
                              │  Authentication OK?  │
                              └──────────┬──────────┘
                                         │
                         ┌───────────────┴───────────────┐
                         │ NO                            │ YES
                         ▼                               ▼
                  ┌───────────┐          ┌────────────────────────┐
                  │ 401/403   │          │ SECURITY TAB: RBAC     │
                  │ Denied    │          │ ├─ Role restrictions   │
                  │           │          │ └─ Capability check    │
                  └───────────┘          └──────────┬─────────────┘
                                                    │
                                         ┌──────────┴──────────┐
                                         │  RBAC Check Pass?    │
                                         └──────────┬──────────┘
                                                    │
                                    ┌───────────────┴──────────────┐
                                    │ NO                           │ YES
                                    ▼                              ▼
                             ┌───────────┐          ┌──────────────────────┐
                             │ 403       │          │ AUTHENTICATION TAB:  │
                             │ Forbidden │          │ REST API Controls    │
                             │           │          │ - CRUD permissions   │
                             └───────────┘          └──────────┬───────────┘
                                                               │
                                                    ┌──────────┴──────────┐
                                                    │ Operation Allowed?   │
                                                    └──────────┬──────────┘
                                                               │
                                                ┌──────────────┴──────────────┐
                                                │ NO                          │ YES
                                                ▼                             ▼
                                         ┌───────────┐          ┌─────────────────┐
                                         │ 403       │          │ Process Request │
                                         │ Forbidden │          │ + Audit Log     │
                                         └───────────┘          └─────────────────┘
```

---

## Configuration Examples

### Example 1: Public API with Guest Access

**Goal**: Allow guest users to chat but protect admin functions

**Authentication Tab**:
```
✅ guest_token_lifetime = 86400 (24 hours)
✅ rest_enable_assistant_list = true (allow listing)
❌ rest_enable_assistant_create = false (no creation)
❌ rest_enable_assistant_delete = false (no deletion)
```

**Security Tab**:
```
❌ require_authentication_all = false (not a master lock)
✅ allow_guest_access = true (guest tokens work)
❌ require_auth_chat_endpoints = false (chat is public)
✅ require_auth_tool_execution = true (protect tools)
✅ require_auth_assistant_management = true (protect admin)
✅ enable_rate_limiting = true (prevent abuse)
```

**Result**: Guests can chat and list assistants, but can't create/delete or execute tools.

---

### Example 2: Private Enterprise Deployment

**Goal**: Lock down everything, require Auth0 SSO

**Authentication Tab**:
```
✅ auth0_domain = "company.auth0.com"
✅ auth0_audience = "https://api.company.com"
❌ guest_token_lifetime = N/A (not used)
✅ rest_enable_assistant_list = true
✅ rest_enable_assistant_create = true (admins only via RBAC)
✅ rest_enable_assistant_delete = true (admins only via RBAC)
```

**Security Tab**:
```
✅ require_authentication_all = true (MASTER LOCK)
❌ allow_guest_access = false (no guest tokens)
✅ bypass_auth_for_logged_in = true (WordPress users OK)
✅ minimum_capability = "edit_posts" (Contributors+)
✅ require_https = true (HTTPS only)
✅ enable_security_audit_log = true (SOC 2 compliance)
```

**Result**: All access requires Auth0 SSO or WordPress login with edit_posts capability. Full audit trail.

---

### Example 3: Development Environment

**Goal**: Easy access for developers, minimal restrictions

**Authentication Tab**:
```
✅ guest_token_lifetime = 86400
✅ rest_enable_assistant_list = true
✅ rest_enable_assistant_create = true
✅ rest_enable_assistant_delete = true
✅ sse_enable_post_method = true (for buggy clients)
```

**Security Tab**:
```
❌ require_authentication_all = false
✅ allow_guest_access = true
❌ require_https = false (local dev)
✅ enable_loopback_ssl_bypass = true (Ollama/LM Studio)
✅ allow_private_network_requests = true
✅ enable_rate_limiting = true (but generous: 10000/hour)
```

**Result**: Maximum flexibility for local development while still preventing abuse.

---

### Example 4: Multi-Tenant SaaS

**Goal**: Separate tenants by role, strict security

**Authentication Tab**:
```
✅ auth0_domain = "saas.auth0.com"
✅ auth0_audience = "https://api.saas.com"
❌ guest_token_lifetime = N/A
✅ rest_enable_assistant_list = true
✅ rest_enable_assistant_create = true (per-tenant)
❌ rest_enable_assistant_delete = false (only via UI)
```

**Security Tab**:
```
✅ require_authentication_all = true
❌ allow_guest_access = false
✅ restrict_to_roles = ["administrator", "editor", "author"]
✅ minimum_capability = "edit_posts"
✅ require_https = true
✅ enable_ip_whitelist = true (office IPs only)
✅ enable_security_audit_log = true
✅ log_successful_auth = true (compliance)
✅ audit_log_retention_days = 365 (SOC 2)
✅ enable_security_headers = true
✅ enable_hsts = true
```

**Result**: Enterprise-grade security with role-based tenant isolation and full compliance.

---

## Priority and Override Rules

### Priority Order (Most to Least Restrictive)

1. **Security Tab: Global Auth** - `require_authentication_all = true` blocks ALL unauthenticated requests
2. **Security Tab: IP Filtering** - Whitelist/blacklist checked before authentication
3. **Security Tab: HTTPS Requirement** - Must use HTTPS if enabled
4. **Security Tab: Endpoint-Specific Auth** - Per-endpoint requirements
5. **Authentication Tab: Methods** - How to authenticate (if required)
6. **Security Tab: RBAC** - Role and capability restrictions
7. **Authentication Tab: REST API Controls** - Specific CRUD operation toggles

### Override Rules

- **Security always wins**: If Security blocks something, Authentication settings can't enable it
- **Guest access coordination**: Both tabs must allow guests for guest tokens to work
  - Security: `allow_guest_access = true`
  - Authentication: `guest_token_lifetime > 0`
- **CRUD operations**: Both must allow for operation to work
  - Security: `require_auth_assistant_management = false` OR user authenticated
  - Authentication: `rest_enable_assistant_create = true`

---

## Validation & Warnings

### Conflicting Settings Warnings

The plugin will warn you about potentially conflicting settings:

**Warning 1**: Guest Access Mismatch
```
Security allows guest access (allow_guest_access = true)
BUT guest_token_lifetime = 0 in Authentication
→ Guest tokens won't work (no lifetime)
```

**Warning 2**: CRUD Blocked by Global Auth
```
Authentication enables assistant creation (rest_enable_assistant_create = true)
BUT Security requires authentication for ALL (require_authentication_all = true)
→ Only authenticated users can create (expected behavior)
```

**Warning 3**: HTTPS Requirement Without SSL
```
Security requires HTTPS (require_https = true)
BUT site is not using HTTPS (is_ssl() = false)
→ All API requests will fail with 403
```

---

## Migration from Previous Versions

### If You Had Only Authentication Tab Before

Your existing settings are preserved:
- Auth0, JWT, guest token settings unchanged
- REST API CRUD controls still work
- New Security tab settings default to **OFF** (no breaking changes)

**Recommended**: Enable Security features incrementally:
1. Start with audit logging and security headers (low risk)
2. Add rate limiting (medium risk)
3. Add endpoint-specific auth requirements (test thoroughly)
4. Consider global auth requirement last (highest impact)

### If You're New to the Plugin

**Start here**:
1. Configure Authentication Tab first (how to authenticate)
2. Then configure Security Tab (when to require it)
3. Test with guest tokens or test user
4. Enable stricter controls as needed

---

## Troubleshooting

### "I can't access the API anymore"

**Check in order**:
1. Security Tab → `require_authentication_all` - Is it ON? Do you have credentials?
2. Security Tab → IP Whitelist - Is your IP allowed?
3. Security Tab → HTTPS Requirement - Are you using HTTP?
4. Security Tab → Role Restrictions - Does your user have the required role?
5. Authentication Tab → REST API Controls - Is the specific operation enabled?

### "Guest tokens don't work"

**Check both**:
- Security Tab → `allow_guest_access` must be **true**
- Authentication Tab → `guest_token_lifetime` must be **> 0**

### "I'm logged in but still blocked"

**Check**:
- Security Tab → `bypass_auth_for_logged_in` - Should be **true**
- Security Tab → `restrict_to_roles` - Must include your role
- Security Tab → `minimum_capability` - Must match your capability

---

## Best Practices

### ✅ DO

- Use Security Tab for **policy** (when to require auth)
- Use Authentication Tab for **methods** (how to authenticate)
- Enable audit logging before going live
- Test configuration changes in staging first
- Use IP whitelist for sensitive operations
- Enable HTTPS enforcement in production
- Set appropriate audit log retention for compliance
- Use role restrictions for multi-tenant scenarios

### ❌ DON'T

- Enable `require_authentication_all` without testing
- Use IP whitelist without backup access method
- Disable rate limiting (except in dev)
- Set guest token lifetime > 30 days (security risk)
- Enable HSTS without valid SSL certificate
- Ignore validation warnings
- Mix up the two tabs' purposes

---

## Compliance Mapping

### GDPR Requirements

**Authentication Tab**:
- Supports identity provider integrations for data controller compliance

**Security Tab**:
- Audit logging (`enable_security_audit_log`) - Article 30
- Access controls (RBAC) - Article 32
- Retention policies (`audit_log_retention_days`) - Article 5

### SOC 2 Trust Services Criteria

**Authentication Tab**:
- Multi-method authentication (CC6.2)
- JWT/Auth0 integration (CC6.2)

**Security Tab**:
- Access control policies (CC6.1)
- Logging and monitoring (CC6.6, CC7.2)
- Encryption enforcement (CC6.7 via HTTPS)
- Periodic access reviews (RBAC supports CC6.3)

### OWASP Top 10 (2024)

**Authentication Tab**:
- A07 Identification & Authentication Failures - Multiple methods

**Security Tab**:
- A01 Broken Access Control - RBAC, endpoint controls
- A05 Security Misconfiguration - Security headers
- A09 Security Logging and Monitoring Failures - Audit logs

---

## Summary

The two tabs work together as a **defense-in-depth** security model:

1. **Authentication Tab**: Identity and Permissions Layer
   - "Who can authenticate?"
   - "What methods are available?"
   - "What operations are enabled?"

2. **Security Tab**: Policy Enforcement Layer
   - "When is authentication required?"
   - "What additional restrictions apply?"
   - "How do we monitor and audit?"

**Remember**: Security policies (Security Tab) always take precedence over authentication configuration (Authentication Tab). Think of Security as the gatekeeper and Authentication as the ID checker.
