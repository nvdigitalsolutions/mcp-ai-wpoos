# CORS Origin Allowlist Settings — Active Context

> **GSD Context File** — Initialized at Phase 0 from the Project Brief.
> Keep this file under **500 lines** (GSD conciseness rule).
> Archive to `.context/archive/cors-allowlist-v1.1.5.md` during Phase 9.

---

## Feature Overview

Adds a **"CORS Allowed Origin"** admin settings field to the Security tab that
controls the `Access-Control-Allow-Origin` header returned on all NV oOS MCP,
REST, and SSE responses. The `wp_mcp_ai_cors_allow_origin` filter already exists
at 6 call sites; this feature wires it to a settings value instead of always
returning `*`.

**Current Phase:** 5 — Implementation (Stories 1.1 → 2.1 → 2.2 → 3.1)
**Feature Version:** v1.1.5
**Brief:** `docs/proposals/CORS-ALLOWLIST-PROJECT-BRIEF.md`
**PRD:** `docs/proposals/CORS-ALLOWLIST-PRD.md`
**Architecture:** `docs/proposals/CORS-ALLOWLIST-ARCHITECTURE.md`

---

## Context Loading Strategy

```
Always:
  .context/conventions.md
  .context/security-checklist.md
  .context/active/cors-allowlist.md    ← this file

Subsystem (today's story):
  .context/testing.md                  # Story 3.1 (tests)
```

---

## Component Map

- [x] Admin settings — `includes/admin/sections/class-wp-mcp-ai-section-security.php`
- [x] Security manager — `includes/class-wp-mcp-ai-security-manager.php`
- [x] Tests — `tests/security/test-cors-allowlist.php`
- [ ] Tool registry — not affected
- [ ] REST API endpoints — not affected (filter consumers exist, no changes)
- [ ] Chat UI — not affected

---

## Architectural Decisions

| Decision | Rationale | Date |
|----------|-----------|------|
| Single URL field (not multi-origin textarea) | HTTP spec requires a single `Access-Control-Allow-Origin` value; list semantics would require request-origin matching (out of scope) | March 2026 |
| `esc_url_raw` sanitization on save, silent fallback to `*` on invalid input | Prevents lockout if admin saves an invalid URL; wildcard is the safe default | March 2026 |
| Localhost bypass only when `WP_DEBUG = true` | Dev convenience without affecting production security | March 2026 |
| Filter registered in `WP_MCP_AI_Security_Manager::__construct` | Security Manager owns CORS logic; consistent with existing auth/rate-limiting responsibilities | March 2026 |

---

## Known Issues / Gotchas

- **Multi-origin**: If multiple origins need to be allowed, the recommended
  approach is a custom `add_filter` snippet that performs request-origin
  matching — outside the scope of this UI field. Document this in the
  field description.
- **HTTP_ORIGIN**: `$_SERVER['HTTP_ORIGIN']` is set only for cross-origin
  requests; same-origin requests won't have it. The localhost logic only
  runs when the header is present.

---

## Story Status

| Story ID | Title | Status | Notes |
|----------|-------|--------|-------|
| 1.1 | Settings field (CORS heading + `cors_allowed_origin`) | Complete | Security section |
| 2.1 | Filter hook returning saved URL or `*` | Complete | Security manager |
| 2.2 | WP_DEBUG localhost bypass | Complete | Security manager |
| 3.1 | PHPUnit tests | Complete | `tests/security/test-cors-allowlist.php` |

---

## Security Notes

- Sanitize with `esc_url_raw()` on save (strips dangerous characters from URLs).
- Output escaped by the settings renderer via `esc_attr()` (base class).
- No new capabilities — `manage_options` inherited from Security tab.
- Nonce handled by WordPress Settings API (existing flow).
- `WP_DEBUG` localhost bypass is safe: only applies when debug is enabled and
  only when the HTTP_ORIGIN header explicitly matches `localhost`.

---

## Next Step

Phase 9 (Retrospective): Archive this file to `.context/archive/` and run
`batch_manage_memory` to persist learnings. Update
`docs/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md` Phase 2 checkboxes.
