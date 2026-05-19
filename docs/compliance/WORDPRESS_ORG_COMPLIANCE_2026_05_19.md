# WordPress.org Compliance — May 19, 2026

**Plugin:** NV Digital Open Operator System (oOS) — slug `mcp-ai-wpoos`
**Prior audit:** [`WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](WORDPRESS_ORG_COMPLIANCE_2026_05_09.md)
**Review ID:** R nvdigital-open-operator-system-oos/vsamtani/25Dec25/T19 9May26/4.0.1B1

---

## What changed since v1.1.17

The WordPress.org Plugins Team re-reviewed v1.1.17 and noted that some issues from the previous review still needed attention. This pass addresses every in-scope finding the team highlighted.

### Scope reminder

`addons/` is **not** part of the WordPress.org submission. Findings whose path begins with `addons/` are out of scope. See [`SUBMISSION.md`](../../SUBMISSION.md) for the build-pipeline proof.

---

## Fixes applied

### 1. Inline `<script>` / `<style>` → proper WordPress APIs

The review specifically named four files. All four now use `wp_add_inline_style()` and `wp_print_inline_script_tag()` instead of raw tags:

| File | Style blocks | Script blocks | Method |
|------|:-----------:|:------------:|--------|
| `includes/admin/sections/class-wp-mcp-ai-section-tools.php` | 1 | 3 | `wp_add_inline_style` + `wp_print_inline_script_tag` |
| `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php` | 7 | 3 | `wp_add_inline_style` + `wp_print_inline_script_tag` |
| `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` | 3 | 10 | `wp_add_inline_style` + `wp_print_inline_script_tag` |
| `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` | 0 | 1 | `wp_print_inline_script_tag` |

Dynamic PHP values passed to JS now use `wp_json_encode()`. All `phpcs:ignore NonEnqueuedScript/NonEnqueuedStylesheet` annotations removed.

### 2. `require_once ABSPATH` — missing `function_exists()` guards

All 84 occurrences in `includes/` were audited. 80 were already guarded. **4 were unconditional** and have been fixed:

| File | Function guarded | 
|------|-----------------|
| `includes/slash-commands/class-wp-mcp-ai-slash-command-audit.php:72` | `dbDelta()` |
| `includes/measurement/class-wp-mcp-ai-metric-event-store.php:135` | `dbDelta()` |
| `includes/class-wp-mcp-ai-async-job-queue.php:161` | `dbDelta()` |
| `includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php:2217` | `wp_generate_attachment_metadata()` |

Pattern applied:
```php
// Before
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );

// After  
if ( ! function_exists( 'dbDelta' ) ) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
}
dbDelta( $sql );
```

### 3. `WP_CONTENT_DIR` / `WP_PLUGIN_DIR` — path validation

All 8 flagged locations now include `defined()`, `file_exists()`, and/or `is_dir()` guards before path construction:

| File | Guard added |
|------|------------|
| `toolkit-manager.php` (2 sites) | `defined()` + `file_exists()` |
| `cli-command.php` (2 sites) | `defined()` ternary |
| `sync-docs.php` | `defined()` + `is_dir()` |
| `get-system-logs.php` (3 sites) | `defined()` ternary; `WP_CONTENT_DIR` removed from whitelist |
| `scrape-product.php` | `defined()` + `is_dir()` |

### 4. `json_decode( sanitize_text_field( ... ) )` — sanitization order

**File:** `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` L4519

`sanitize_text_field()` was applied to a JSON string *before* `json_decode()`. This corrupts valid JSON (e.g. `&` → `&amp;`). The decoded array already passes through `sanitize_preferred_datasets_meta()` downstream, so the pre-decode sanitization was removed.

```php
// Before
$dataset = json_decode( sanitize_text_field( $dataset_json ), true );

// After
$dataset = json_decode( $dataset_json, true );
```

### 5. External services in readme.txt

All 36 external services are documented with Terms of Service and Privacy Policy links. Previously flagged broken URLs (`google.github.io/A2A/`, `ai.google.dev/privacy`) were already corrected in the May 9 pass.

---

## What is unchanged from May 9

- 333+ capability checks
- 147+ nonce verifications  
- 200+ sanitization instances
- 500+ output-escaping instances
- Single `mcp-ai-wpoos` text domain
- Zero HEREDOC syntax in base tree
- All 83 base REST routes have explicit `permission_callback`
- `wp_set_current_user()` hardened via `WP_MCP_AI_User_Context_Helper`
- Cache directory uses `wp_upload_dir()` not `WP_CONTENT_DIR`

---

## Cross-references

| Document | Purpose |
|----------|---------|
| [`WORDPRESS_ORG_COMPLIANCE_2026_05_09.md`](WORDPRESS_ORG_COMPLIANCE_2026_05_09.md) | May 9 audit — B3, B8, B10, B13, Build |
| [`SUBMISSION.md`](../../SUBMISSION.md) | Submission manifest and per-finding response table |
| `readme.txt` | External services documentation |
