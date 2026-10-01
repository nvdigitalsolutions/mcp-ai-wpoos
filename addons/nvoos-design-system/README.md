# NV oOS Design System

Design token system with DTCG export, accessibility tokens, and a
token-driven **email template module** for WordPress. Previously released as
the **Crocoblock Design System** (`addons/crocoblock-ds/`) — this is the same
addon, renamed and extended with email templates + AI tools.

## What It Does

### Design tokens (renamed from Crocoblock DS)

- **~70 design tokens** across 8 groups: colors, typography, spacing, borders, shadows, sizing, transitions, **email**
- **CSS custom properties** injected as `:root { --nds-* }` on every page
- **DTCG export** (W3C Design Tokens 2025.10 format) for Tokens Studio for Figma, Style Dictionary, Terrazzo
- **Accessibility tokens**: dark mode (`prefers-color-scheme`), high contrast (`prefers-contrast`), reduced motion (`prefers-reduced-motion`)
- **Typed CSS custom properties** (`@property`, opt-in) and **animation tokens** (easing/duration)
- **Admin settings page** (Settings → Design System) with visual token editor and live preview
- **3 built-in presets**: Minimal (default), Ecommerce, Directory — each with a matching email palette
- **Crocoblock + Elementor integrations**: JetEngine, JetSmartFilters, JetFormBuilder, Elementor

### Email templates (new)

- **5 built-in templates**: Letterhead, Minimal, Transactional, Newsletter, Ecommerce Receipt
- **Global wrapper**: every outgoing `wp_mail()` email is wrapped in the active template — no plugin dependencies, works on any site
- **Multipart pairing**: wrapped emails carry a plain-text alternative (`multipart/alternative` via PHPMailer `AltBody`) for deliverability and plain-text clients; overridable via the `nds_email_plain_text` filter
- **WooCommerce rebrand (opt-in)**: rebrands WC transactional emails via WC's own hooks (`woocommerce_email_styles`, header/footer actions, option sync) — no template overrides, no double-wrapping
- **Paper Store reuse**: mirror templates into the Paper Store (`email-templates` collection) and import them back as drafts — cross-site reuse via Paper Store's remote proxy
- **Token-driven**: templates consume the `--nds-email-*` token group, so one palette change rebrands web + email
- **Accessible by construction**: `role="presentation"` tables, `lang`, alt text, ≥16px body, WCAG AA contrast pairs, dark-mode overrides, <100 KB
- **Safe by construction**: sentinel double-wrap guard, full-document skip (WooCommerce/SMTP templates), explicit `text/plain` skip, self-removing content-type filter
- **Admin picker** (Emails tab): template library with audit scores, per-template settings, live preview, test send, AI generation, JSON import

### AI tools (with the NV oOS base plugin)

8 MCP tools, all admin-gated (`manage_options`):

| Tool | Purpose |
|---|---|
| `nds_generate_email_template` | Generate a new template from a prompt (saved as a **draft** with an audit report) |
| `nds_list_email_templates` | List templates with status, source, and audit scores |
| `nds_preview_email_template` | Render any template with sample content + current tokens |
| `nds_audit_email_template` | Run the compliance audit (structure, security, WCAG, dark mode, size) |
| `nds_set_active_email_template` | Activate a template (gated; `force` override available) |
| `nds_test_send_email` | Send a test email through the active template |
| `nds_export_email_template` / `nds_import_email_template` | JSON export/import |

## Quick Start

1. Activate the plugin.
2. Go to **Settings → Design System → Tokens** and choose a preset or customise tokens.
3. Go to **Settings → Design System → Emails**, pick a template, and send yourself a test email.
4. (Optional, with NV oOS active) ask an assistant to generate a new template:
   *"Generate a welcome email template with a single call-to-action button."*

## Template Reference

| Template | Best for |
|---|---|
| **Letterhead** | General brand emails — dark letterhead band, gold rule, serif, confidentiality footer |
| **Minimal** | Clean single-column; safest across clients |
| **Transactional** | Notifications, receipts, alerts — compact, high-contrast |
| **Newsletter** | Digests and campaigns — multi-section with CTA |
| **Ecommerce Receipt** | Order confirmations — WooCommerce-aware logo fallback |

### Merge tags

```
{{subject}} {{body}} {{to_name}} {{site_name}} {{site_url}} {{site_domain}}
{{logo_url}} {{admin_email}} {{sub_brand}} {{confidential}} {{year}}
```

Template parts: `{{button "Label" "https://example.com"}}` (bulletproof CTA with VML fallback), and `{{#to_name}}…{{/to_name}}` conditional blocks (rendered only when the recipient name is known).

## Architecture

```
addons/nvoos-design-system/
├── nvoos-design-system.php              ← entry point, autoloader, legacy shims
├── includes/
│   ├── class-nvoos-nds-plugin.php       ← composition root + migration + tool registration
│   ├── class-nvoos-nds-token-registry.php
│   ├── class-nvoos-nds-css-generator.php   ← :root + @property + a11y + legacy aliases
│   ├── class-nvoos-nds-assets.php
│   ├── class-nvoos-nds-dtcg-exporter.php
│   ├── class-nvoos-nds-preset-*.php     ← 3 presets (token + email palettes)
│   ├── base/                            ← Data_Token, Data_Preset
│   ├── admin/                           ← settings page (Tokens/Emails tabs)
│   ├── integrations/                    ← JSF, JetEngine, JFB, Elementor
│   ├── emails/                          ← CPT, registry, renderer, wrapper,
│   │   │                                   auditor, generator, seeder, admin UI
│   │   └── templates/*.html             ← 5 built-in email templates
│   └── tools/                           ← 8 AI tools (nds_*)
├── assets/css/ · assets/js/
├── languages/
├── tests/                               ← 6 test files (~45 tests)
└── uninstall.php
```

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Crocoblock plugins (JetEngine, JetSmartFilters, JetFormBuilder) and Elementor — optional
- NV oOS base plugin — optional (enables the 8 AI tools; provider keys auto-detected)

## Hooks

### Filters

| Hook | Description |
|---|---|
| `nds_email_skip` | Return `true` to skip wrapping a specific email (bool, `$atts`) |
| `nds_email_active_template` | Override the active template slug |
| `nds_email_context` | Filter the merge context before rendering |
| `nds_email_logo_url` | Override the logo URL (bypasses the WC/theme fallback chain) |
| `nds_email_plain_text` | Filter the plain-text alternative paired with a wrapped email (string, `$atts`) |
| `nds_email_wc_rebrand` | Per-email WooCommerce rebrand override (bool, `$email`) |
| `nds_email_generation_provider` | Replace the AI generation provider with a callable `( $system, $user ) => html\|WP_Error` |

## Legacy compatibility (v0.x transition)

The addon was renamed from `nvoos-crocoblock-ds` → `nvoos-design-system` in
v0.2.0. Existing builds keep working via:

- `--cds-*` CSS variable aliases (default on, toggle in admin)
- `.cds-*` component classes alongside `.nds-*`
- One-way option migration (`nvoos_cds_*` → `nvoos_nds_*`)
- `NV_oOS_Crocoblock_DS_*` class aliases + legacy constants

These shims are removed in v1.0.0.

## Changelog highlights

- **0.2.0** — rename from Crocoblock DS; email template module (5 templates, global wrapper, admin picker, audit gates, AI generation) + 8 AI tools.
- **0.3.0** — multipart plain-text pairing, opt-in WooCommerce rebrand mode, Paper Store mirror/import, `nds_email_wc_rebrand` + real `nds_email_plain_text` filters.

## License

GPLv3 or later — see [LICENSE](../../LICENSE).
