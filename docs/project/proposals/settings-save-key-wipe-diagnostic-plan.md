# Settings Save Key Wipe — Diagnostic & Remediation Plan

**Status:** Proposal  
**Date:** 2026-07-15  
**Area:** admin-ui, core  
**Related PRs:** #5685, #5688, #5689 (original fixes), #5690, #5691, #5692 (reverts)

---

## 1. Problem Statement

Saving plugin settings from any tab in the NV oOS admin dashboard **periodically clears API keys and credential fields** (e.g., `openai_api_key`, `gemini_api_key`, `brave_search_api_key`). The wipe is inconsistent — sometimes keys survive, sometimes they don't — making it difficult to reproduce and debug.

---

## 2. Chronology of Attempted Fixes (All Reverted)

### 2.1 Original Architecture (Pre-#5685)

All settings — including API keys — stored in a single `wp_mcp_ai_settings` option with `autoload=yes`. The save flow:

```
POST → sanitize (section-based) → merge with existing → update_option(wp_mcp_ai_settings)
```

**Problem:** API keys in alloptions payload on every page request (WordPress loads all autoloaded options on every request by default).

### 2.2 PR #5685 — Credential Split (Reverted by #5692)

Introduced a two-option split:
- `wp_mcp_ai_settings` (autoload=yes) — non-sensitive configuration
- `wp_mcp_ai_credentials` (autoload=no) — API keys, tokens, secrets

Added `CREDENTIALS_OPTION_NAME` constant and `get_settings()` merge logic to `WP_MCP_AI_Admin_Settings_Base`. Replaced the simple `update_option` in `handle_save_settings()` with a split-and-save function that separated sensitive keys.

**Problem:** When a tab without credential fields (e.g., "General", "Advanced", "Chat") was saved, **no credential keys were in the POST payload**. The split logic would see zero credentials in `$sanitized_new`, call `delete_option('wp_mcp_ai_credentials')`, and **wipe all API keys**.

### 2.3 PR #5689 — Merge Fix (Reverted by #5690)

Attempted to fix #5685's data-loss bug by merging `wp_mcp_ai_credentials` back into `$existing_settings` **before** the split-and-save. This way, credentials stored in the separate option would survive saves from non-credential tabs.

**Problem:** Created a fragile dual-source-of-truth architecture. Credentials lived in one option but the merge had to happen at every read point (save, export, import, credential resolver). Any code path that forgot the merge would see missing keys.

### 2.4 PR #5688 — Class Reference Fix (Reverted by #5691)

Fixed a fatal error where the split code called `WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key()` — a method that only existed on `WP_MCP_AI_Admin_Settings`.

**Problem:** The fix was correct in isolation but depended on #5685's architecture. When all three PRs were reverted, the revert cascade temporarily left stale class references that #5692's revert subsequently removed.

### 2.5 Current State (After All Reverts)

Back to the original architecture: single `wp_mcp_ai_settings` option, no split. Save flow:

```
POST → sanitize (section-based) → sensitive-key-protection filter → merge → update_option
```

**The current code has built-in protection** at Step 4 (lines 648–699 of `class-wp-mcp-ai-settings-dashboard.php`):

```php
// Explicitly unset empty sensitive keys from $sanitized_new BEFORE merge.
foreach ( $sensitive_keys as $key ) {
    if ( isset( $sanitized_new[ $key ] ) && empty( $sanitized_new[ $key ] ) ) {
        unset( $sanitized_new[ $key ] );  // don't overwrite existing
    }
}
$merged_settings = array_merge( $existing_settings, $sanitized_new );
```

This **should** prevent key wipe — empty API keys in POST data get removed from `$sanitized_new` before the merge, so `array_merge` preserves the existing stored values.

---

## 3. Root Cause Analysis — Why Keys Still Get Cleared

Despite the protection in Step 4, keys can still be cleared through these vectors:

### 3.1 Hypothesis A: Section-Based Sanitizer Returns Null Keys

The `sanitize_settings()` override in `WP_MCP_AI_Settings_Dashboard` iterates over registered settings sections and their fields. **If a section's field registration returns a non-empty string for an API key field** (e.g., an empty string `""` from a text input), the sensitive-key protection at Step 4 will **not** catch it because `empty("")` is `true` — wait, `empty("")` IS `true`, so it WOULD be caught.

But what about the value `"0"`? `empty("0")` is also `true`. Or what about non-empty strings like the masked placeholder `"**************"`? The protection only checks `empty()`, so the placeholder would pass through. However, `sanitize_sensitive_setting_value()` handles the placeholder by preserving the existing value.

**Potential issue:** If the section sanitizer returns a key with value `null` (not empty string), `empty(null)` is `true`, so it would be caught. But what if it returns... actually, `isset()` would catch `null`.

### 3.2 Hypothesis B: Double-POST from Browser Auto-Fill or Password Managers

Password managers (Bitwarden, 1Password, browser built-in) may auto-fill the masked API key fields with **empty values** or their own stored values. When the form is submitted, these fields appear in `$_POST['wp_mcp_ai_settings']`.

- **If auto-filled with the masked placeholder:** The `sanitize_sensitive_setting_value()` preserves the existing encrypted value — SAFE.
- **If auto-filled with empty string:** Step 4 removes it from `$sanitized_new` — SAFE.
- **If auto-filled with a different value:** The new value replaces the old — DATA LOSS (but this is user error, not a code bug).

### 3.3 Hypothesis C: Tab Routing with `save_all_tabs=1`

When `save_all_tabs=1` (e.g., from the "Save All Settings" button or Simple Settings page), `$tab_to_sanitize = ''`, which triggers **all sections across all tabs**. This includes provider sections (OpenAI, Gemini, etc.) even if the user did not intend to modify credentials.

**In this case:** Provider section fields like `openai_api_key` appear in the POST data as masked placeholders. The sanitization preserves the existing values. SAFE in theory.

**But:** If the user clicks "Save" on a tab that includes provider subsections rendered inline (not via a separate subtab URL), and those fields render with empty inputs instead of masked placeholders, the POST would contain empty API keys.

### 3.4 Hypothesis D: Cache Poisoning / Race Condition

The save flow invalidates caches **before** reading `$existing_settings`:

```php
WP_MCP_AI_Admin_Settings::reset_settings_cache();
wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
delete_transient( 'wp_mcp_ai_settings_cache' );
$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
```

If WordPress's object cache (Redis/Memcached) has a delayed write or replication lag, `get_option()` could return **stale data** — specifically, data from a previous save that already had keys cleared. Then `array_merge($stale_without_keys, $sanitized_new)` would permanently lose them.

**This is the most likely root cause for inconsistent/intermittent key wipes** in production environments with persistent object caches.

### 3.5 Hypothesis E: `get_settings()` Static Cache Poisoning

`WP_MCP_AI_Admin_Settings_Base::get_settings()` uses a static cache (`$settings_cache`). If a previous request set this cache with settings that lacked API keys (due to a prior wipe), subsequent reads from the static cache would return key-less settings. The `reset_settings_cache()` call clears this, but **only if** the code path that triggers the save calls `reset_settings_cache()` before `get_settings()`.

Any code path that calls `get_settings()` between `reset_settings_cache()` and the final `get_option()` re-populates the static cache with potentially stale object-cache data.

### 3.6 Hypothesis F: Concurrent Saves

Two admin users (or two browser tabs) saving different settings tabs simultaneously could create a race condition:

```
User A saves "General" tab → reads existing (with keys) → merges → writes
User B saves "Providers" tab → reads existing (with keys) → merges → writes
```

If User A's write completes after User B's read but before User B's write:
- User B's write overwrites User A's changes (classic lost-update problem)
- If User B is saving a non-provider tab, their `$sanitized_new` lacks API keys
- User B's write may or may not preserve keys depending on merge behavior

---

## 4. Investigation Plan

### Phase 1 — Reproduce the Bug

1. **Enable logging** (`enable_logging` = on) in plugin settings.
2. **Instrument the save path** with additional `error_log()` calls at these checkpoints:
   - `$_POST['wp_mcp_ai_settings']` keys before any processing
   - `$sanitized_new` keys after section-based sanitization
   - `$sanitized_new` keys after Step 4 sensitive-key protection
   - `$existing_settings` keys read from database (with values masked)
   - `$merged_settings` keys after `array_merge`
   - `update_option()` result and verification read-back
3. **Test save from each tab** in sequence: General → Providers → Advanced → Chat → Tools → Integrations
4. **Test with persistent object cache enabled** (Redis/Memcached) and disabled.
5. **Test concurrent saves** from two browser tabs.

### Phase 2 — Identify the Exact Vector

Based on Phase 1 findings, determine which hypothesis (A–F) is the actual cause:

| Finding | Likely Hypothesis |
|---------|-------------------|
| Keys present in `$existing_settings` but missing after merge | D, E (cache poisoning) |
| Keys missing from `$existing_settings` already | D, F (prior wipe or concurrent save) |
| Keys in `$sanitized_new` as empty strings | A, B, C (sanitizer returning empty fields) |
| Keys in `$sanitized_new` as masked placeholder | B (safe, should be preserved) |
| Intermittent — works sometimes, fails others | D, F (timing-dependent race) |

### Phase 3 — Check Section Registrations

Audit all settings section registrations (likely in `class-wp-mcp-ai-admin-settings-renderer.php` or similar) to identify any section that:
- Is registered for a non-provider tab
- Includes an API key / secret / token field in its field list
- Renders that field as a visible input (not a masked placeholder)

This would explain how API keys appear in POST data from non-provider tabs.

---

## 5. Remediation Options

### Option A: Defensive Merge (Minimal Fix)

Keep the single-option architecture. Before `array_merge`, explicitly **filter out all sensitive keys from `$sanitized_new`** regardless of whether they are empty — i.e., only accept sensitive key values when the user explicitly typed them (non-empty, non-placeholder).

```php
// Stripped-down protection: never overwrite sensitive keys unless explicitly provided.
foreach ( $sensitive_keys as $key ) {
    if ( ! isset( $sanitized_new[ $key ] ) ) {
        continue; // not in POST at all — safe
    }
    $val = $sanitized_new[ $key ];
    if ( empty( $val ) || '**************' === $val ) {
        unset( $sanitized_new[ $key ] ); // preserve existing
    }
}
```

**Pros:** One-line change, low risk.  
**Cons:** Doesn't fix cache poisoning or race conditions.

### Option B: Two-Option Split (Revisited with Guardrails)

Re-implement the two-option split (#5685's approach) but with **always-merge-on-read** semantics:

1. `wp_mcp_ai_settings` — autoloaded non-sensitive settings
2. `wp_mcp_ai_credentials` — non-autoloaded sensitive keys

**Critical difference from #5685:** The merge happens in `get_settings()` (the single read point), not at every save/export/import boundary. The save flow becomes:

```php
// Pre-save: read existing credentials separately
$existing_credentials = get_option( 'wp_mcp_ai_credentials', array() );

// Sanitize incoming: split sensitive from non-sensitive
// IMPORTANT: only include a sensitive key from POST if non-empty AND non-placeholder
$incoming_credentials = [];
$incoming_non_sensitive = [];
foreach ( $sanitized_new as $key => $value ) {
    if ( $this->is_sensitive( $key ) ) {
        if ( ! empty( $value ) && '**************' !== $value ) {
            $incoming_credentials[ $key ] = $value;
        }
    } else {
        $incoming_non_sensitive[ $key ] = $value;
    }
}

// Merge incoming credentials with existing (don't wipe!)
$final_credentials = array_merge( $existing_credentials, $incoming_credentials );

// Save both
update_option( 'wp_mcp_ai_settings', $incoming_non_sensitive, true );
update_option( 'wp_mcp_ai_credentials', $final_credentials, false );
```

**Pros:** Proper separation of concerns, keeps secrets out of alloptions.  
**Cons:** Migration path needed for existing installs, more complex.

### Option C: Atomic Save with Optimistic Locking

Add a `_settings_version` or `_settings_hash` field to detect concurrent modifications:

```php
$existing = get_option( 'wp_mcp_ai_settings', array() );
$stored_hash = md5( serialize( $existing ) );

if ( $stored_hash !== $submitted_hash ) {
    // Another save happened between read and write — re-read and re-merge.
    $existing = get_option( 'wp_mcp_ai_settings', array() );
}

$merged = array_merge( $existing, $sanitized_new );
```

**Pros:** Fixes race condition (Hypothesis F).  
**Cons:** Adds complexity, doesn't fix cache poisoning.

### Option D: Recommended Approach — Layered Defense

Combine all three:

1. **Defensive merge** (Option A) — filter sensitive keys from non-provider tabs
2. **Cache-busting** — use `wp_cache_delete()` + `wp_cache_flush()` before read, add `wp_suspend_cache_addition(true)` during the save critical section
3. **Atomic save** (Option C) — detect and recover from concurrent writes
4. **Verification read-back** — after `update_option()`, immediately `get_option()` + `wp_cache_delete()` + `get_option()` again to verify keys survived

---

## 6. Implementation Steps

### Step 1: Instrumentation & Diagnosis (This Plan)
- [ ] Add `error_log()` instrumentation at all save-path checkpoints (list in §4.1)
- [ ] Add `wp_mcp_ai_save_diagnostics` option to store the last N save events with before/after key state
- [ ] Run reproduction tests and capture logs

### Step 2: Apply Defensive Fix
- [ ] Strengthen Step 4 sensitive-key filter: unset ALL sensitive keys from `$sanitized_new` that weren't explicitly user-entered
- [ ] Add `wp_suspend_cache_addition(true)` around the critical save section to prevent object-cache interference
- [ ] Add verification read-back after save

### Step 3: Long-Term Architecture
- [ ] Re-evaluate two-option split with proper guardrails (Option B)
- [ ] Add `_settings_version` optimistic lock (Option C)
- [ ] Write integration tests for the save flow

---

## 7. Affected Files

| File | Role |
|------|------|
| `includes/admin/class-wp-mcp-ai-settings-dashboard.php` | Save handler, Step 4 protection, merge logic |
| `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | `get_settings()`, `sanitize_settings()`, `is_sensitive_setting_key()` |
| `includes/admin/class-wp-mcp-ai-admin-settings.php` | `get_settings()` delegation, `reset_settings_cache()` |
| `includes/bridge/class-wp-mcp-ai-credential-resolver.php` | Credential resolution (reads from `wp_mcp_ai_settings`) |
| `assets/js/settings-dashboard.js` | Form submission, checkbox hidden fields, subtab handling |

---

## 8. Success Criteria

- [ ] Saving settings from any non-provider tab (General, Advanced, Chat, Tools, Integrations) does NOT clear existing API keys
- [ ] Saving settings from the Providers tab with masked placeholders preserves existing API keys
- [ ] Saving settings from the Providers tab with new API key values updates them correctly
- [ ] Concurrent saves from two browser tabs do not cause data loss
- [ ] Keys survive when object cache (Redis/Memcached) is enabled
- [ ] Export/Import round-trips preserve all keys
- [ ] Plugin activation/deactivation does not affect stored keys
