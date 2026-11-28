# NV oOS Design System

A standalone WordPress **brand-ops** plugin: design tokens in, brand everywhere
out. It publishes `--nds-*` CSS custom properties to the front end, optionally
bridges the same tokens into the block editor's Global Styles, checks WCAG 2.2
contrast, and can brand outgoing email with an accessible template.

- **Slug:** `nvoos-design-system`
- **Version:** 1.0.1
- **Requires:** WordPress 6.0+, PHP 8.1+
- **License:** GPLv3 or later
- **Standalone:** no API keys, no external service, no theme dependency

Previously released as the **Crocoblock Design System**. Version 1.0.1 removes
the `--cds-*` CSS variables and the `NV_oOS_Crocoblock_DS_*` PHP class aliases.
The `.cds-form` wrapper class remains as a markup compatibility alias.

## What it does

### Design tokens

- ~70 tokens across eight groups: colours, typography, spacing, borders,
  shadows, sizing, transitions, and email.
- Emitted as `:root { --nds-* }` on every page.
- Optional typed `@property` declarations (animatable, DevTools-friendly).
- Dark-mode (`prefers-color-scheme`), high-contrast (`prefers-contrast`), and
  reduced-motion (`prefers-reduced-motion`) variants.
- Three presets: Minimal (default), Ecommerce, Directory.
- Visual editor with live preview and per-token reset.
- Strict per-type validation: a token value can never inject `{ } < > ;` into
  the stylesheet.

### W3C DTCG 2025.10 interchange

- **Export** a spec-shaped document for Tokens Studio for Figma, Style
  Dictionary, and Terrazzo. Colours, dimensions, font weights, numbers,
  durations, and shadows are emitted as DTCG values, not raw CSS strings.
- **Import** documents with group `$extends`, `$root` tokens, aliases (chained
  and circular-reference detection), `$deprecated`, `$description`, and
  `$extensions`.
- Imports are transactional: if any token fails validation, nothing changes.
- Legacy string values from pre-1.0 exports still import.
- Unrepresentable CSS values are emitted as `$type: "string"` with an explicit
  `$extensions` marker — never silently downgraded.

### Global Styles bridge

- Opt-in `wp_theme_json_data_theme` filter adds palette, font families, font
  sizes, spacing sizes, and shadow presets to the block editor.
- Runs entirely in memory; no theme files are written.
- Requires WordPress 6.1+; uses theme.json schema v3 on WordPress 6.6+.
- Download a `theme.json` partial for version control.

### Brand kit

- Identity record: brand name, tagline, sub-brand, contact email, logo (light
  and dark), icon, favicon, social links, and voice notes.
- References tokens by id, so one token edit updates web, editor, and email.
- Export as JSON (identity + tokens + `theme.json` + active email template) or
  as a printable HTML brand guide with palette and contrast ratios.

### WCAG 2.2 contrast

- One contrast engine (`NV_oOS_Design_System_Contrast_Auditor`) checks the
  standard text, button, border, and focus-ring pairs.
- Text pairs are evaluated at 4.5:1; large text, borders, and focus rings at
  3:1.
- Custom-property values are reported as unresolved rather than guessed.
- Focus-ring and minimum target-size tokens ship in every preset.

### Email templates

- Five accessible templates: Letterhead, Minimal, Transactional, Newsletter,
  Ecommerce Receipt.
- The wrapper is **off on fresh installs**. Four modes: `off`, `text_only`,
  `fragments`, `all`.
- Guards prevent double-wrapping, complete HTML documents from other plugins,
  and explicit `text/plain` sends.
- Multipart plain-text alternatives, dark-mode overrides, `color-scheme`
  metadata, hidden preheaders, and a 102 KB Gmail clip budget.
- Auditor checks security, structure, accessibility, contrast, dark mode, VML
  duplication, image-only CTAs, and size before activation.
- Optional WooCommerce rebrand via WooCommerce's own hooks.
- A local send log records the last 50 wrapped or skipped messages — never the
  body or recipient address.

### Optional AI generation

AI template generation is optional and never runs on its own. It goes through
the WordPress AI Client, so it reuses the provider and credentials the site
owner configured there; the plugin stores no API key and makes no direct
provider call. Sites that need a different provider can supply one through the
`nvoos_nds_email_generation_provider` filter. The result is saved as a draft
with an audit report and must be reviewed before activation. No AI request is
made otherwise, and no other plugin is required.

## Quick start

1. Activate the plugin.
2. Open **Settings → Design System → Tokens** and choose a preset or edit tokens.
3. Optional: enable **Publish tokens to Global Styles** on a block theme.
4. Optional: open the **Emails** tab, choose a template, then select a delivery
   mode.
5. Optional: fill in the **Brand** tab and export a brand guide.

## Architecture

```
addons/nvoos-design-system/
├── nvoos-design-system.php                  ← entry point, constants, autoloader
├── includes/
│   ├── class-nvoos-nds-plugin.php           ← composition root, admin-post handlers
│   ├── class-nvoos-nds-token-registry.php   ← token store + strict validators + meta
│   ├── class-nvoos-nds-css-generator.php    ← :root, @property, a11y media queries
│   ├── class-nvoos-nds-assets.php           ← component stylesheet registration
│   ├── class-nvoos-nds-dtcg-codec.php       ← DTCG 2025.10 value codec + resolver
│   ├── class-nvoos-nds-dtcg-exporter.php
│   ├── class-nvoos-nds-dtcg-importer.php
│   ├── class-nvoos-nds-contrast-auditor.php ← WCAG 2.2 engine
│   ├── class-nvoos-nds-global-styles.php    ← theme.json bridge
│   ├── class-nvoos-nds-brand-kit.php        ← identity + JSON/HTML export
│   ├── class-nvoos-nds-preset-*.php         ← Minimal, Ecommerce, Directory
│   ├── base/                                ← Data_Token, Data_Preset
│   ├── admin/                               ← Tokens / Emails / Brand tabs
│   ├── integrations/                        ← JSF, JetEngine, JFB, Elementor, Woo
│   ├── emails/                              ← CPT, registry, renderer, wrapper,
│   │   │                                       sanitizer, auditor, generator,
│   │   │                                       seeder, admin UI
│   │   └── templates/*.html                 ← 5 built-in email templates
│   └── tools/                               ← 8 AI tools (nds_*)
├── assets/css/ · assets/js/
├── bin/generate-wporg-assets.py             ← Pillow icon/banner generator
├── languages/
├── tests/                                   ← 15 test files, 154 tests
├── .wordpress-org/                          ← icon, banner, screenshot checklist
├── readme.txt                               ← WordPress.org readme
└── uninstall.php                            ← multisite-aware cleanup
```

## Stored data

| Option | Contents |
|---|---|
| `nvoos_nds_settings` | Token id → CSS value map |
| `nvoos_nds_token_meta` | Per-token DTCG metadata (`aliasOf`, `dtcgType`, `deprecated`, …) |
| `nvoos_nds_use_typed_properties` | `@property` opt-in |
| `nvoos_nds_global_styles` | Global Styles bridge opt-in |
| `nvoos_nds_brand` | Brand kit record |
| `nvoos_nds_active_email_template` | Active template slug |
| `nvoos_nds_wrap_mode` | `off` / `text_only` / `fragments` / `all` |
| `nvoos_nds_email_log` | Last 50 send records (no body, no recipient) |
| `nvoos_nds_email_settings` | Per-template settings (logo, sub-brand, confidentiality) |
| `nvoos_nds_compiled_css` | CSS cache (invalidated on token save) |

Email templates are stored as a private `nvoos_nds_email` custom post type
with `_nvoos_nds_template_*` meta keys. Pre-1.0 installs that used the
unprefixed `nds_email_template` type are migrated automatically on upgrade.
Nothing is transmitted to NV Digital Solutions.

## Hooks

### Filters

| Hook | Description |
|---|---|
| `nvoos_nds_email_skip` | Return `true` to skip wrapping a specific email (bool, `$atts`) |
| `nvoos_nds_email_should_wrap` | Veto wrapping after mode/guard checks (bool, `$atts`, `$mode`) |
| `nvoos_nds_email_active_template` | Override the active template slug |
| `nvoos_nds_email_context` | Filter the merge context before rendering |
| `nvoos_nds_email_logo_url` | Override the logo URL (bypasses the WC/theme fallback chain) |
| `nvoos_nds_email_plain_text` | Filter the plain-text alternative paired with a wrapped email (string, `$atts`) |
| `nvoos_nds_email_wc_rebrand` | Per-email WooCommerce rebrand override (bool, `$email`) |
| `nvoos_nds_email_generation_provider` | Replace the AI generation provider with a callable `( $system, $user ) => html\|WP_Error` |

Global Styles uses the core `wp_theme_json_data_theme` filter internally; the
plugin does not add a second public filter on top of it.

## Template reference

| Template | Best for |
|---|---|
| **Letterhead** | General brand emails — dark header band, gold rule, serif, confidentiality footer |
| **Minimal** | Clean single-column; safest across clients |
| **Transactional** | Notifications, receipts, alerts — compact, high-contrast |
| **Newsletter** | Digests and campaigns — multi-section with CTA |
| **Ecommerce Receipt** | Order confirmations — WooCommerce-aware logo fallback |

### Merge tags

```
{{subject}} {{body}} {{to_name}} {{site_name}} {{site_url}} {{site_domain}}
{{logo_url}} {{admin_email}} {{sub_brand}} {{confidential}} {{year}} {{preheader}}
```

Template parts: `{{button "Label" "https://example.com"}}` (bulletproof CTA with
VML fallback) and `{{#to_name}}…{{/to_name}}` conditional blocks.

## AI tools

Eight MCP tools, all admin-gated (`manage_options`). They register only when the
NV oOS base plugin's tool contracts are present.

| Tool | Purpose |
|---|---|
| `nds_generate_email_template` | Generate a template from a prompt (saved as a draft with an audit) |
| `nds_list_email_templates` | List templates with status, source, and audit scores |
| `nds_preview_email_template` | Render any template with sample content and current tokens |
| `nds_audit_email_template` | Run the compliance audit |
| `nds_set_active_email_template` | Activate a template (gated; `force` override available) |
| `nds_test_send_email` | Send a test email through the active template |
| `nds_export_email_template` / `nds_import_email_template` | JSON export/import |

## Development

From the repository root:

```bash
# Lint PHP (WordPress Coding Standards)
composer run lint

# PHP 8.1–8.4 compatibility
composer run lint:compat

# Run the addon tests (from the repo root)
vendor/bin/phpunit --no-coverage addons/nvoos-design-system/tests/test-dtcg-codec.php
vendor/bin/phpunit --no-coverage addons/nvoos-design-system/tests/test-dtcg-importer.php
vendor/bin/phpunit --no-coverage addons/nvoos-design-system/tests/test-contrast-auditor.php
# …or run each test-*.php file; PHPUnit 11 does not auto-discover hyphenated
# file names passed as a directory in this repo.

# Regenerate WordPress.org icons/banners (pure PHP; no GD required)
php addons/nvoos-design-system/bin/generate-wporg-assets.php

# WordPress.org Plugin Check against a ZIP-shaped tree (requires Docker and the
# oos-wp-db container; see bin/run-design-system-plugin-check.sh)
sh bin/run-design-system-plugin-check.sh
```

Standalone plugin build:

```bash
# From the repo root, using the addon's .distignore
rsync -rc --exclude-from=addons/nvoos-design-system/.distignore \
  addons/nvoos-design-system/ /tmp/nvoos-design-system/
```

## Changelog highlights

- **1.0.1** — first WordPress.org release. DTCG 2025.10 codec with aliases,
  `$root`, `$extends`, `$deprecated`, and `$extensions`; Global Styles bridge;
  brand kit; WCAG 2.2 contrast audit; four email delivery modes with the wrapper
  off by default; auditor compatibility checks; strict CSS validators; multisite
  uninstall. Removes `--cds-*` and PHP class aliases.
- **0.3.0** — multipart plain-text pairing, opt-in WooCommerce rebrand, Paper
  Store mirror/import, `nvoos_nds_email_wc_rebrand` and `nvoos_nds_email_plain_text` filters.
- **0.2.0** — rename from Crocoblock DS; email template module (5 templates,
  global wrapper, admin picker, audit gates, AI generation) and 8 AI tools.

## License

GPLv3 or later — see [LICENSE](../../LICENSE).
