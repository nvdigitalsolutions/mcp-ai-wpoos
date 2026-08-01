# Security Policy

## Project

**NV Digital Open Operator System (NV oOS)**
Maintained by [NV Digital Solutions](https://nvdigitalsolutions.com)
Copyright © 2025-2026 NV Digital Solutions. All rights reserved.

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.1.x   | ✅ Active support  |
| 1.0.x   | ⚠️ Security fixes only |
| < 1.0   | ❌ Not supported   |

## Reporting a Vulnerability

If you discover a security vulnerability in NV oOS, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email us at: **security@nvdigitalsolutions.com**

Include the following details:

1. A description of the vulnerability
2. Steps to reproduce the issue
3. The potential impact
4. Any suggested fixes (optional)

## Response Timeline

- **Acknowledgment:** Within 48 hours of receiving your report
- **Initial assessment:** Within 5 business days
- **Fix timeline:** Depends on severity; critical issues are prioritized

## Scope

This policy covers:

- The NV oOS base plugin (`mcp-ai-wpoos`)
- The NV oOS Pro addon (`addons/pro/`)
- The NV oOS Canvas addon (`addons/canvas/`)
- The NV oOS Core plugin (`core/`)
- REST API endpoints exposed by the plugin
- Authentication and credential handling
- Third-party API key storage and transmission

## Out of Scope

- Vulnerabilities in third-party dependencies (report to the upstream project)
- WordPress core vulnerabilities
- Server misconfiguration issues
- Social engineering attacks

## Security Best Practices

When deploying NV oOS:

1. Keep WordPress and PHP updated to the latest supported versions
2. Use HTTPS for all API communications
3. Store API keys securely using WordPress options (encrypted at rest since 1.2.0 — see [`docs/developer/api-key-encryption.md`](docs/developer/api-key-encryption.md))
4. Restrict admin access to trusted users
5. Regularly review assistant configurations and tool permissions
6. Enable rate limiting for public-facing chat endpoints
7. Monitor the plugin's activity logs for unusual behavior

### Production Hardening

See the complete [Production Hardening Guide](docs/operations/production-hardening-guide.md) for a step-by-step checklist covering:
- Recommended security settings for production
- WAF and server-level hardening
- OAuth and authentication lockdown
- DICOM/healthcare deployment requirements
- Regular maintenance tasks

### Security Documentation Index

| Document | Audience |
|----------|----------|
| [Production Hardening Guide](docs/operations/production-hardening-guide.md) | Site admins, DevOps |
| [API Key Encryption](docs/developer/api-key-encryption.md) | Developers |
| [DICOM PHI Handling](docs/developer/dicom-phi-handling.md) | Healthcare deployments |
| [Security Settings Reference](docs/reference/admin/security-settings.md) | Admins, developers |
| [Security Audit Report](SECURITY_AUDIT_REPORT.md) | Security auditors |

## Destructive Operations and Resource Consumption

NV oOS is designed to grant AI assistants broad access to WordPress operations. **By design, it can be destructive and resource-intensive when not properly configured.**

Examples of risks when misconfigured:
- Bulk deletion or modification of posts, media, and users
- Mass email dispatch to your user list
- Unbounded API spending that triggers provider billing overages
- Server CPU/memory exhaustion from concurrent agentic loops

See [`WARRANTY.md`](WARRANTY.md) for a full breakdown of destructive operations, resource consumption risks, and mitigation recommendations aligned with industry standards (OWASP, NIST SP 800-53, ISO/IEC 27001, WordPress Plugin Handbook).

## Intellectual Property Notice

NV oOS is developed and maintained by NV Digital Solutions (https://nvdigitalsolutions.com).

- **Base Plugin:** Licensed under GPLv3 or later
- **Pro Addon:** Proprietary commercial software — All rights reserved
- **Patent Status:** Patent Pending (Application #19/410,504)

Unauthorized distribution of the Pro addon or violation of the patent is prohibited.

## Acknowledgments

We appreciate the security research community's efforts in helping keep NV oOS safe. Responsible disclosure contributors will be acknowledged (with permission) in our release notes.

---

*This security policy is maintained by NV Digital Solutions.*  
*Last updated: July 2026*
