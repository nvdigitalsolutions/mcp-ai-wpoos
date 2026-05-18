# Required-Capability Assignment Audit (Phase P2b)

**Date:** May 18, 2026
**Branch:** `copilot/set-required-capability-tools`
**Proposal:** [Unix Theory Compliance Enhancement Proposal §2.3 / Phase P2](../UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#23-capability-fence-for-optional-dependencies)
**Outcome:** ✅ **Pass** — central capability map closes the payload-filter leak for all ~290 previously-`Missing!` slugs without per-class edits.

---

## 1. The leak

The capability fence in [`WP_MCP_AI_REST::build_tools_payload()`](../../../includes/class-wp-mcp-ai-rest.php) silently drops a tool from the model's payload when the current user lacks `get_required_capability()`. The original implementation used `method_exists()` as the gate:

```php
// Before P2b — historical implementation
if ( method_exists( $tool, 'get_required_capability' ) ) {
    $required_capability = $tool->get_required_capability();
    if ( ! empty( $required_capability ) && ! current_user_can( $required_capability ) ) {
        continue; // filter the tool out
    }
}
```

The Capability Fence Audit identified **~290 registered slugs** for which `method_exists()` returned `false` — they declared neither the method nor a `'required_capability'` key in `get_definition()`. Those slugs **fell through with no fence at all**, so they were exposed to every user the assistant was reachable to, including low-privilege roles and `*_validated` wrapper slugs that don't have a single backing class.

For destructive / admin-class slugs (`delete_post`, `create_cron_job`, `purge_*_cache`, `install_and_activate_plugin`, `send_*`, `update_option`, `site_creator`, `remote_wp_connection`, the diagnostics surfaces, …) this is a privilege-escalation hole, not just metadata polish.

## 2. Why a central map instead of 290 per-class edits

Per-class `public function get_required_capability()` methods are still the long-term preferred shape (and remain the highest-priority resolution step). But shipping them for ~290 tools across six PRs takes time, leaves the security gap open during the transition, and **cannot reach the slugs that have no single backing class**:

- `*_validated` wrappers (~22 slugs) — registered through the validation pipeline, not as their own classes.
- Slugs registered through dynamic discovery (toolkit auto-discovery, JetEngine route registration, etc.) where the source class is selected at runtime.

A central authoritative map closes the leak for every slug at once, gives reviewers one auditable file, and lets per-class methods land incrementally as straightforward refactoring.

## 3. Implementation

### 3.1 The map

[`includes/class-wp-mcp-ai-tool-capability-map.php`](../../../includes/class-wp-mcp-ai-tool-capability-map.php) declares `WP_MCP_AI_Tool_Capability_Map` with:

| Method | Purpose |
|---|---|
| `get_map()` | Returns the slug → capability array. Filterable via `wp_mcp_ai_tool_capability_map`. |
| `get_capability( $slug )` | Single-slug lookup. Returns `null` for unmapped slugs. |
| `resolve( $tool, $slug )` | The authoritative resolver. Honours per-class method → map → safe default (`edit_posts`). Filterable via `wp_mcp_ai_tool_required_capability`. |
| `reset_cache()` | Test-only cache buster. |

`DEFAULT_CAPABILITY = 'edit_posts'` — the lowest WordPress capability that still excludes Subscribers and unauthenticated users. Tools that should be readable by Subscribers must opt down to `'read'` (either in the map or via a per-class method).

### 3.2 The resolver wired into the payload filter

[`includes/class-wp-mcp-ai-rest.php::build_tools_payload()`](../../../includes/class-wp-mcp-ai-rest.php) was updated to:

1. Honour the per-class `get_required_capability()` method when present (unchanged behaviour).
2. **For non-guest requests only**, consult `WP_MCP_AI_Tool_Capability_Map::resolve()` as a fallback when the method is missing — this closes the leak.
3. **For guest requests** (`$auth_context['is_guest']`), skip the central-map fallback so existing guest assistants are not broken when their tools default to `edit_posts`. Per-class methods continue to apply for guests, matching prior behaviour.

The guest bypass matches the Risk note in the original plan: *"the guest-request bypass already lives in `class-wp-mcp-ai-rest.php` (`$context['guest_request']`), so guest assistants are unaffected by payload filtering."*

### 3.3 Decision tree applied to the audit

| Flag pattern on the tool | Assigned capability | Rationale |
|---|---|---|
| `client-side, offline` (in-browser, no server work) | `read` | Just gate on "is a logged-in user". |
| `read-only, local-only` (read WP data only) | `read` | Same gate WP REST uses for `GET /wp/v2/posts`. |
| `read-only, external-api` (3rd-party reads, no writes) | `read` | External read still fenced because of API spend, but doesn't require write privs. |
| `write` / `state-changing` on posts / terms / postmeta | `edit_posts` | Matches the existing pattern for `save_post`, `create_post`. |
| `destructive` on posts (e.g. `delete_post`) | `delete_posts` | Distinguish destructive from creative writes. |
| Cron / `wp_options` / plugins / themes / system logs / cache / site-creator / `update_option` | `manage_options` | Admin-only operations. |
| File writes into `uploads/` (image / document / video generation) | `upload_files` | Matches `analyze_image`, `edit_openai_image`, media-templates pattern. |
| User-management reads / writes | `list_users` / `edit_users` | Standard WP user-mgmt caps. |
| WooCommerce reads (no PII) | `read` | Public catalog surface. |
| WooCommerce reads/writes touching orders, customers, PII | `manage_woocommerce` | Standard Woo cap. |
| JetEngine reads/writes | `edit_posts` | CCT-equivalent (matches `get_jetengine_items`). |
| Channel broadcasts (Slack / Discord / Teams / WhatsApp / email sends) | `manage_options` | Sends on the site's behalf — admin-level by default. |
| External-API content generation (image / video / audio / chart) | `edit_posts` | Creates assets the editor would create. |
| Document generation (`generate_pdf`, `generate_word`, OCR, …) | `upload_files` | Output is a file written to the media library. |
| Orchestration / agent-team / memory / context tools | `edit_posts` | Lowest cap that still excludes Subscribers; sessions write CPTs. |
| Reasoning / prompt cues (read-only meta) | `read` | Read-only with no external calls. |
| Plugin / theme installs | `install_plugins` / `install_themes` | Standard WP install caps. |
| Site builder / `update_option` / `site_creator` | `manage_options` | Already implied by the action. |
| `*_validated` wrappers | **Same cap as the un-validated peer** | Wrappers must never widen access. |

Explicit per-slug overrides for tools the decision tree doesn't capture cleanly (e.g. `load_skill` → `read`, `count_tokens` → `read`, `web_search` → `edit_posts`, the channel broadcasters → `manage_options`, install_plugins/themes specifically) are listed inline in the map file with grouped section comments.

## 4. Test coverage

[`tests/test-tool-required-capability-coverage.php`](../../../tests/test-tool-required-capability-coverage.php) asserts:

1. The resolver always returns a non-empty string (default-falls-back guarantee).
2. Map slugs resolve to their declared capability when the tool itself declares nothing.
3. A tool's own `get_required_capability()` method wins over the map.
4. `*_validated` wrappers resolve to the same capability as their un-validated peers (the security invariant).
5. Sensitive destructive / admin-only slugs are locked to `manage_options` / `install_plugins` / `install_themes` / `delete_posts` and never fall back to `edit_posts`.
6. A representative cross-section of ~80 previously-`Missing!` slugs all now resolve to non-empty capabilities.
7. The `wp_mcp_ai_tool_required_capability` and `wp_mcp_ai_tool_capability_map` filters can override the resolver.

## 5. Verification

| Check | Result |
|---|---|
| `php -l` on changed files | ✅ Clean |
| `composer run lint:base` | Unchanged (no new rules added in this PR) |
| Guest assistants unaffected | ✅ Bypass preserved in `build_tools_payload` |
| Per-class methods still win | ✅ Resolver tier 1 |
| New slugs flagged at runtime | ✅ Default `edit_posts` prevents leaks even when audit misses a slug |

## 6. Follow-up work (out of scope for this PR)

- **PR-A through PR-F** (per the original plan) — add `public function get_required_capability()` to each of the ~290 classes that still rely on the map. Each PR groups ~30–60 files by domain. Security gap is already closed by the map, so these PRs become pure refactoring.
- **Phase 4 hardening** — once per-class methods are widespread, make `get_required_capability()` a required member of `WP_MCP_AI_Tool_Interface` and add a PHPCS sniff (`WPMCPAI.Tools.RequiredCapabilityDeclared`) that warns on missing declarations. The map continues to act as the safety net.
- **Guest-token policy** — decide whether guest requests should be evaluated against the assistant's "guest capability set" rather than bypassing the central-map fallback. Track separately.

## 7. Files changed

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-tool-capability-map.php` | **New** — central authoritative slug → capability map and resolver |
| `includes/class-wp-mcp-ai-tool-registry.php` | Require the capability-map file alongside the existing tool traits |
| `includes/class-wp-mcp-ai-rest.php` | `build_tools_payload()` now uses the resolver (non-guest only); guest bypass preserved |
| `tests/test-tool-required-capability-coverage.php` | **New** — 8 PHPUnit cases covering resolver, validated-wrapper mirroring, sensitive-slug locks, filters |
| `docs/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md` | New P2b row added to Implementation Phases table |
| `docs/proposals/audits/P2b-required-capability-assignment-2026-05.md` | **This document** |
