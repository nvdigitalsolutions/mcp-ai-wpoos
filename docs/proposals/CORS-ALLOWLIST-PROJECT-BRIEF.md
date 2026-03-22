# CORS Origin Allowlist Settings — Project Brief

**Date:** March 2026
**Phase:** 1 — Discovery
**Author:** NV Digital Solutions / Agent: nv-oos-analyst
**Status:** Approved
**Proposal File:** `docs/proposals/CORS-ALLOWLIST-PROJECT-BRIEF.md`

---

## Problem Statement

NV oOS currently sends `Access-Control-Allow-Origin: *` on all MCP/REST/SSE
responses. Although authentication protects the data, the wildcard header allows
any web origin to initiate requests and read responses, which is a security
concern for installations that serve sensitive data or operate in regulated
environments.

A `wp_mcp_ai_cors_allow_origin` filter already exists and is applied in six
places across the plugin, but there is no admin UI to configure it; operators
must modify code or write a custom `add_filter` snippet in a child theme.

---

## Target Users

- **Site administrators** who need to lock NV oOS endpoints to specific front-end
  origins (e.g., a headless React app, a Telegram Mini App, a mobile client).
- **Developers** who want a settings-based approach instead of a code snippet.
- **Security / compliance teams** who need to document a restricted origin policy.

---

## WordPress Ecosystem Context

### Related Plugins/Solutions

| Solution | Approach | Limitation |
|---------|---------|-----------|
| WP CORS (plugin) | Site-wide CORS control | Covers all REST routes; not scoped to NV oOS |
| Custom `add_filter` snippet | Maximum flexibility | Requires code editing, no UI |

### WordPress Core Features Leveraged

- `get_option( 'wp_mcp_ai_settings' )` — existing plugin settings store
- `apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' )` — existing filter,
  already present in 6 handlers

### NV oOS Components Affected

- [x] Admin settings — new CORS sub-section in **Security** tab
- [ ] Tool registry
- [ ] REST API (existing filter points, no new endpoints)
- [ ] Chat UI
- [ ] Database schema

---

## Feasibility Assessment

| Dimension | Assessment | Notes |
|-----------|-----------|-------|
| Technical complexity | Low | Filter already exists; only UI + hook needed |
| Security considerations | Low–Medium | Validation of URLs required; wildcard `*` as safe default |
| Third-party dependencies | None | Pure WordPress settings |
| Base vs Pro placement | **Base** | Security settings belong in the base plugin |
| Estimated stories | 3 | Settings field, filter hook, tests |

---

## Security Implications

- [x] Handles user credentials or API keys: **No**
- [x] Accesses external services: **No**
- [ ] Processes user-uploaded content: No
- [x] Exposes new REST endpoints: **No** (modifies headers on existing endpoints)
- [x] Requires new capabilities: **No** — `manage_options` (existing security section)

Input (the comma/newline-separated URL list) must be sanitized with
`esc_url_raw` per line. The resolved `Access-Control-Allow-Origin` value must be
a single URL string or `*`; multi-value headers are not valid.

---

## Competitive Alternatives

| Alternative | Approach | Why NV oOS Is Different |
|------------|---------|------------------------|
| WP CORS plugin | Blanket site CORS | NV oOS needs scope-specific control |
| `wp_mcp_ai_cors_allow_origin` filter | Code-level | Requires developer, no UI |

---

## Recommendations

**Proceed to PRD:** Yes

**Key risks:**
1. Multi-origin support — HTTP spec allows only one `Access-Control-Allow-Origin`
   value per response; the setting must enforce single-origin semantics (either a
   URL or `*`). For truly multi-origin scenarios, a request-origin-matching
   approach should be documented.
2. Wildcard default must be preserved for backward compatibility.

**Key assumptions:**
1. The existing `wp_mcp_ai_cors_allow_origin` filter is already applied in all
   relevant handlers and does not need new call sites.
2. No change is needed to authentication logic — this is a headers-only change.

---

## Analyst Sign-off Checklist

- [x] Problem statement is clear and specific
- [x] Target users identified with concrete use cases
- [x] WordPress ecosystem context researched
- [x] Feasibility assessment complete (complexity, security, dependencies)
- [x] Base vs Pro placement recommended with rationale
- [x] Security implications enumerated
- [x] Recommendation to proceed is stated
- [x] All factual claims verified

---

*Next step: Product Manager (nv-oos-product-manager) creates the PRD →
`docs/proposals/CORS-ALLOWLIST-PRD.md`*
