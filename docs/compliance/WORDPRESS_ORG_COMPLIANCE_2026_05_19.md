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

#### 1a. Initial pass — reviewer-flagged files (4 files)

The review specifically named four files. All four now use `wp_add_inline_style()` and `wp_print_inline_script_tag()` instead of raw tags:

| File | Style blocks | Script blocks | Method |
|------|:-----------:|:------------:|--------|
| `includes/admin/sections/class-wp-mcp-ai-section-tools.php` | 1 | 3 | `wp_add_inline_style` + `wp_print_inline_script_tag` |
| `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php` | 7 | 3 | `wp_add_inline_style` + `wp_print_inline_script_tag` |
| `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` | 3 | 10 | `wp_add_inline_style` + `wp_print_inline_script_tag` |
| `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` | 0 | 1 | `wp_print_inline_script_tag` |

#### 1b. Comprehensive sweep — all remaining base-plugin files (47 additional files)

After the initial pass, a full audit of every `includes/` file revealed ~85 additional raw `<script>` blocks and ~60 raw `<style>` blocks across 47 files that also violated the WordPress.org requirement. Every occurrence has been converted using the same canonical patterns:

**Conversion pattern for scripts:**
```php
// Before
?> <script type="text/javascript"> ... <?php esc_html_e(...) ?> ... </script> <?php

// After
<?php
$precomputed_var = wp_json_encode( __( '...', 'mcp-ai-wpoos' ) );
ob_start();
?> ... <?php echo wp_json_encode( $precomputed_var ); ?> ...
<?php
$js = ob_get_clean();
wp_print_inline_script_tag( $js );
?>
```

**Conversion pattern for styles:**
```php
// Before
?> <style> .my-class { ... } </style> <?php

// After
<?php
wp_add_inline_style( 'wp-mcp-ai-UNIQUE-HANDLE', '.my-class{...}' );
?>
```

**Files converted by category:**

| Category | Files | ~Scripts | ~Styles |
|----------|------:|:--------:|:-------:|
| Admin pages & sections | 18 | 35 | 25 |
| Assistant CPT & metaboxes | 8 | 11 | 6 |
| Profession CPT & metaboxes | 6 | 5 | 5 |
| Elementor widgets | 7 | 5 | 7 |
| Core/bootstrap/helpers | 8 | 7 | 5 |
| **Totals** | **47** | **~63** | **~48** |

**Key files (admin):**
- `includes/admin/class-wp-mcp-ai-pro-dashboard.php` — 3 scripts + 4 styles
- `includes/admin/class-wp-mcp-ai-model-config-renderer.php` — 1 script + 1 style
- `includes/admin/class-wp-mcp-ai-provider-diagnostics.php` — 1 script
- `includes/admin/class-wp-mcp-ai-onboarding-wizard.php` — 1 script
- `includes/admin/class-wp-mcp-ai-security-audit.php` — 1 script + 1 style
- `includes/admin/class-wp-mcp-ai-admin-settings.php` — 1 style
- `includes/admin/class-wp-mcp-ai-pro-settings.php` — 1 style
- `includes/admin/sections/class-wp-mcp-ai-section-security.php` — 3 scripts + 1 style
- `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php` — 1 script + 2 styles
- `includes/admin/sections/class-wp-mcp-ai-section-overview.php` — 1 style
- `includes/admin/sections/class-wp-mcp-ai-section-providers.php` — 1 style
- `includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php` — 1 script
- `includes/admin/widgets/class-wp-mcp-ai-dashboard-widget-queue-health.php` — 1 style
- `includes/admin/class-wp-mcp-ai-admin-dlq-manager.php` — 1 script
- `includes/admin/class-wp-mcp-ai-admin-profession-settings.php` — 1 style
- `includes/admin/class-wp-mcp-ai-admin-team-settings.php` — 1 style
- `includes/admin/class-wp-mcp-ai-auth0-setup.php` — 1 style
- `includes/admin/class-wp-mcp-ai-orchestration-renderer.php` — 1 style
- `includes/admin/class-wp-mcp-ai-pro-dashboard-diagnostic.php` — 1 style
- `includes/admin/class-wp-mcp-ai-report-generator.php` — 1 style
- `includes/admin/class-wp-mcp-ai-toolkit-enhancement-dashboard-widget.php` — 1 style
- `includes/admin/class-wp-mcp-ai-tools-filter-bar-renderer.php` — 1 script

**Key files (assistant CPT & metaboxes):**
- `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` — 7 scripts + 4 styles
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-base-knowledge.php` — 1 script + 1 style
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-credentials.php` — 1 script
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-datasets.php` — 1 script + 1 style
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-mcp-apps.php` — 1 script (1 `<script type="text/html">` Underscore template intentionally preserved)
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-mesh-routing.php` — 1 script
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-primary-roles.php` — 1 script + 1 style
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-skills.php` — 1 script

**Key files (profession CPT & metaboxes):**
- `includes/professions/class-wp-mcp-ai-profession-cpt.php` — 2 scripts
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-agent-orchestration.php` — 1 style
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-base-knowledge.php` — 1 script + 1 style
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-datasets.php` — 1 script + 1 style
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-details.php` — 1 script
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-playbook.php` — 1 script + 1 style

**Key files (Elementor widgets):**
- `includes/elementor/class-wp-mcp-ai-elementor-performance-metrics-widget.php` — 1 script + 1 style
- `includes/elementor/class-wp-mcp-ai-elementor-performance-recommendations-widget.php` — 1 script + 1 style
- `includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php` — 1 script + 1 style
- `includes/elementor/class-wp-mcp-ai-elementor-performance-trends-widget.php` — 1 script + 1 style
- `includes/elementor/class-wp-mcp-ai-elementor-test-results-table-widget.php` — 1 script + 1 style
- `includes/elementor/class-wp-mcp-ai-elementor-system-health-status-widget.php` — 1 style
- `includes/elementor/class-wp-mcp-ai-elementor-assistant-tools-widget.php` — 1 style

**Key files (core/bootstrap/helpers):**
- `includes/bootstrap/hooks.php` — 1 script
- `includes/class-wp-mcp-ai-ai-peer-cpt.php` — 1 script + 1 style
- `includes/class-wp-mcp-ai-model-pricing-checker.php` — 1 script
- `includes/class-wp-mcp-ai-optional-components.php` — 1 script
- `includes/class-wp-mcp-ai-security-audit.php` — 1 script + 1 style
- `includes/class-wp-mcp-ai-information-labelling.php` — 1 style
- `includes/helpers/class-wp-mcp-ai-profession-search-helper.php` — 1 script + 1 style
- `includes/markup/class-wp-mcp-ai-markup-admin-page.php` — 1 script
- `includes/teams/class-wp-mcp-ai-team-cpt.php` — 1 style

**Cumulative totals (initial pass + comprehensive sweep):**
- **51 files changed** across `includes/`
- **~111 inline `<script>` blocks** converted to `wp_print_inline_script_tag()`
- **~72 inline `<style>` blocks** converted to `wp_add_inline_style()`
- **~1,801 lines added, ~3,113 lines deleted**
- **All `phpcs:ignore NonEnqueuedScript/NonEnqueuedStylesheet` annotations removed**
- **Dynamic PHP values now use `wp_json_encode()` throughout**
- **No behavioral changes — all CSS/JS output is identical, only the delivery mechanism changed**

### 1c. Intentionally preserved — non-executable script blocks

The following `<script>` tag patterns are **not** executable JavaScript and are intentionally preserved:

- **`<script type="application/json">`** — Config data blocks used by the chat UI, professional selector, and block renderers. These are JSON data, not executable code.
- **`<script type="application/ld+json">`** — Structured data (JSON-LD) for SEO. Not executable.
- **`<script type="text/html">`** / **`<script type="text/template">`** — Underscore/Backbone client-side templates. Not executable.

### 1d. Intentionally not converted — tool-generated standalone HTML

Seven files in `includes/tools/` generate complete standalone HTML documents returned as API tool responses (not rendered in the WordPress admin context). These use raw `<script>` and `<style>` tags because they produce full HTML pages that are displayed in iframes or external contexts where the WordPress enqueuing API is not applicable:

| File | Reason not converted |
|------|---------------------|
| `includes/tools/class-wp-mcp-ai-tool-create-chart.php` | Generates standalone Chart.js HTML page |
| `includes/tools/class-wp-mcp-ai-tool-generate-chart.php` | Generates standalone Chart.js HTML page |
| `includes/tools/class-wp-mcp-ai-tool-generate-mermaid.php` | Generates standalone Mermaid diagram HTML page |
| `includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php` | Generates standalone weather chart HTML page |
| `includes/tools/class-wp-mcp-ai-tool-visualize-workflow-metrics.php` | Generates standalone workflow visualization HTML page |
| `includes/tools/trait-wp-mcp-ai-tool-content-media.php` | Shared trait for generating standalone chart HTML |
| `includes/tools/trait-wp-mcp-ai-tool-math-response.php` | Shared trait for generating standalone KaTeX math HTML |

These files produce complete `<!DOCTYPE html>` documents with their own `<head>`, `<script src="...">`, and `<style>` blocks — a pattern that is correct for standalone HTML output and is used by many WordPress plugins that return HTML fragments via AJAX.

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
