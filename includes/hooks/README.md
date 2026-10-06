# Hooks — Assistant Hook Profiles

## Purpose

Runtime advisory gates over the plugin's 60+ public lifecycle hooks
(`wp_mcp_ai_before_tool_execution`, `wp_mcp_ai_after_chat_response`, …).

`WP_MCP_AI_Hook_Profiles` gives subscribers an opt-in discipline for running
heavier observers (audit logging, trace capture, cost tracking,
distillation) only when the assistant's hook profile permits it, plus
runtime toggles for ad-hoc disablement. Inspired by ECC's hook-profile
controls (`ECC_HOOK_PROFILE`, `ECC_DISABLED_HOOKS`). No existing hook's
timing, arguments, or default behavior changes.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (no enums, no readonly, no union types) |
| **Loaded by** | `includes/bootstrap/loader.php` (autoload-aware require) |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Hook_Profiles` | `class-wp-mcp-ai-hook-profiles.php` | any lifecycle-hook subscriber that wants profile-aware gating |

Key methods (all static):

- `allows( $hook, $assistant_id, $required_profile )` — combined gate:
  master switch on AND hook not disabled AND resolved profile rank ≥
  required rank.
- `is_disabled( $hook, $assistant_id )` — raw per-hook kill-list lookup
  (does NOT consult the master switch; subscribers should prefer `allows()`).
- `is_enabled()` — master switch.
- `get_profile( $assistant_id )` — resolved profile slug.
- `normalize_profile( $raw )` — coerce any input to a valid profile.

Profile ranks: `minimal (0) < standard (1) < strict (2)`. A subscriber that
requires `standard` runs under `standard` and `strict` but not under
`minimal`.

Resolution order (first match wins): assistant meta
`_wp_mcp_ai_hook_profile` → filter `wp_mcp_ai_hook_profile` → site option
`wp_mcp_ai_default_hook_profile` → constant `WP_MCP_AI_HOOK_PROFILE` →
default `standard`.

Runtime toggles: constant `WP_MCP_AI_HOOK_PROFILES_ENABLED` or filter
`wp_mcp_ai_hook_profiles_enabled` (default on); per-hook kill list via
constant `WP_MCP_AI_DISABLED_HOOKS` (comma-separated) or filter
`wp_mcp_ai_disabled_hooks` (array).

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta (`_wp_mcp_ai_hook_profile`), site
  option (`wp_mcp_ai_default_hook_profile`), the constants listed above.
- **Writes to:** nothing — pure advisory lookups.
- **Upstream callers:** lifecycle-hook subscribers in
  `includes/security/`, `includes/harness/`, `includes/services/`, Pro
  addons (opt-in adoption over time).
- **Downstream collaborators:** none — consultative only.
- **Events fired:** none. **Filters fired:**
  `wp_mcp_ai_hook_profile`, `wp_mcp_ai_hook_profiles_enabled`,
  `wp_mcp_ai_disabled_hooks`.
- **Events listened to:** none — hooks are consulted inline by callers.

## Conventions

- **Subscribers opt in.** The class never disables anything by itself; a
  subscriber must call `allows()` before doing heavy work.
- **`allows()` is the gate; `is_disabled()` is the raw lookup.** Do not use
  `is_disabled()` alone to decide whether to run — it bypasses the master
  switch by design.
- **Profiles are advisory, not security.** A profile must never be the only
  line of defense for a capability or nonce check.
- **Unknown profile values normalize to `standard`** — a bad meta value can
  never silently widen or narrow the profile.

## Tests

PHPUnit coverage lives in the root `tests/` directory:

```bash
vendor/bin/phpunit tests/test-hook-profiles.php
```

Covers rank ordering, resolution order (meta → filter → option → default),
master-switch semantics, kill-list behavior, and normalization.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming and style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability + nonce rules (always)
- [`.context/testing.md`](../../.context/testing.md) — test-writing patterns
- [`docs/reference/hooks/hooks-reference.md`](../../docs/reference/hooks/hooks-reference.md) — the 60+ hooks this profiles
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat policy
