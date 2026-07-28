# Production Hardening Guide

> **Since:** 1.2.0 · **Audience:** Site administrators, DevOps

A quick-start checklist for hardening your NV oOS installation for production deployment.

---

## 1. Encrypt API Keys (Automatic)

NV oOS 1.2.0 automatically encrypts all third-party API keys at rest. No action needed. Keys are migrated from plaintext on first read.

**Verify:**
```
wp option get wp_mcp_ai_master_key
```
If this returns a base64 string, encryption is active.

See: [`api-key-encryption.md`](api-key-encryption.md)

---

## 2. Configure Security Settings

Navigate to **NV oOS → Settings → Security** and configure:

### Network tab (`?tab=security&subtab=network`)

| Setting | Production | Why |
|---------|:----------:|-----|
| Require HTTPS | ✅ ON | Encrypts all API traffic |
| Enable Rate Limiting | ✅ ON | Prevents abuse |
| Enable Auth Rate Limiting | ✅ ON | Blocks credential brute-forcing |
| Rate Limit OAuth Token Endpoint | ✅ ON | Protects OAuth endpoints |
| Max SSE Connections per User | 3–5 | Prevents connection exhaustion |
| Max Request Body Size (KB) | 512–1024 | Limits resource use |
| Max JSON Nesting Depth | 16–32 | Prevents stack attacks |
| API Error Detail Level | **Safe** | Hides internal details from clients |
| Strip Plugin Version from Assets | ✅ ON | Prevents version fingerprinting |
| Enable Security Headers | ✅ ON | X-Content-Type-Options, X-Frame-Options |
| Enable HSTS | ✅ ON* | Force HTTPS (only if SSL is working) |
| CSP frame-ancestors | `'none'` | Blocks clickjacking |

*\* Test HSTS with a low max-age (300) first, then raise to 31536000.*

### Access & Identity tab (`?tab=security&subtab=access`)

| Setting | Production | Why |
|---------|:----------:|-----|
| Require Authentication for All Access | ✅ ON | Blocks anonymous API access |
| Allow Guest Access | Per-use case | OFF unless you need public chat |
| Protect Chat Endpoints | ✅ ON | |
| Protect Tool Execution | ✅ ON | |

### AI Safety tab (`?tab=security&subtab=ai_safety`)

| Setting | Production | Why |
|---------|:----------:|-----|
| Enable Prompt-Injection Detector | ✅ ON | Catches jailbreak attempts |
| Enable PII Filter | ✅ ON | Redacts personal data |
| Require Human Approval for Write Tools | ✅ ON* | Prevents accidental destructive ops |
| Enable AI Cost Tracking | ✅ ON | Prevents runaway API spend |
| Default Daily Budget (USD) | $10–50 | Caps per-assistant spend |

*\* Start with threshold "State-changing" then tighten to "Any write".*

---

## 3. Lock Down Authentication

Navigate to **NV oOS → Settings → Authentication**:

### REST API tab (`?tab=authentication&subtab=rest_api`)

| Setting | Production | Why |
|---------|:----------:|-----|
| Enable REST Assistant Listing | ON | Needed for MCP clients |
| Enable REST Assistant Creation | OFF | Create via admin UI only |
| Enable REST Assistant Deletion | OFF | Delete via admin UI only |
| Disable Open OAuth Client Registration | ✅ ON | Prevents unauthorized clients |
| Require Confirmation for Destructive Tools | ✅ ON | Guards against AI mistakes |

### Guest Access tab (`?tab=authentication&subtab=guest`)

| Setting | Production | Why |
|---------|:----------:|-----|
| Guest Token Lifetime | 3600 (1h) | Short-lived tokens |
| Assistant Credential Lifetime (days) | 30–90 | Rotate credentials periodically |

---

## 4. Server-Level Hardening

These are **not plugin settings** — configure them at the web server or WAF level:

### Recommended WAF Rules
```
# Block plugin version disclosure
Header unset X-Powered-By
Header always unset X-Powered-By

# CSP for chat SPA pages
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self' https://api.openai.com

# Limit request body size (nginx)
client_max_body_size 1m;

# Limit request body size (Apache)
LimitRequestBody 1048576
```

### Cloudflare WAF
- Enable **Bot Fight Mode** to block credential stuffing
- Create a rate limiting rule for `/wp-json/mcp-ai/v1/oauth/token` (10 req/5 min)
- Enable **Security Level: High** for the `/wp-json/mcp-ai/` path

---

## 5. Monitor and Audit

### Enable Audit Logging
**Security → Audit** tab:
- `enable_security_audit_log`: ✅ ON
- `log_successful_auth`: ON (for SOC 2/GDPR)
- `log_file_access`: ON (if handling sensitive uploads)
- `audit_log_retention_days`: 90–365

### Review Logs
```bash
wp option get wp_mcp_ai_recent_errors --format=json
wp option get wp_mcp_ai_recent_activity --format=json
```

### Set Up Alerts
NV oOS can send webhook notifications for security events. Configure the webhook URL in **Settings → General → Webhooks** and ensure the webhook secret is set (verified on **Security → Network** page).

---

## 6. DICOM / Healthcare Deployments

If you upload DICOM medical imaging files:

1. **Always** attach a PHI redaction callback to `wp_mcp_ai_dicom_strip_phi`
2. See: [`dicom-phi-handling.md`](dicom-phi-handling.md)
3. Enable `log_file_access` for audit trail
4. Set `api_error_verbosity` to **Safe** (prevents PHI in error messages)
5. Configure short retention for uploaded files

---

## 7. Regular Maintenance

| Frequency | Task |
|:---------:|------|
| Weekly | Review audit logs for anomalies |
| Monthly | Rotate assistant credentials (re-issue expired tokens) |
| Quarterly | Review and update API key permissions (least privilege) |
| Bi-annually | Rotate the encryption master key: `WP_MCP_AI_Encryption::rotate_master_key()` |

---

## Quick Security Score

Check your current posture:
```
wp mcp-ai security-posture
```
Or visit: **NV oOS → Dashboard → Security → Overview**

Aim for a score of **80+** (grade A or B).
