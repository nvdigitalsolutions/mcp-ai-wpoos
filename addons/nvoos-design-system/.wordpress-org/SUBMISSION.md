# WordPress.org Submission Pack — NV oOS Design System 1.0.1

Use this file when uploading the plugin at <https://wordpress.org/plugins/developers/add/>.
The same content is the cover note for the review ticket email.

- **Artifact:** `dist/nvoos-design-system-1.0.1.zip`
- **Size:** 142,047 bytes (53 files)
- **SHA-256:** `2f4e1b2f29beec1a2483b9ee3f7f89e37a40523c13069ecd047dcf808f6d3b4d`
- **ZIP root folder:** `nvoos-design-system/` (matches the requested slug)
- **Rebuild:** `python bin/build-zip.py` (honours `.distignore`, forward-slash entries only)

---

## 1. Add-plugin form

| Field | Value |
|---|---|
| Plugin Name | `NV oOS Design System` |
| Plugin ZIP | `dist/nvoos-design-system-1.0.1.zip` |
| Requested slug | `nvoos-design-system` |
| Version | `1.0.1` |
| Author | `NV Digital Solutions` — wordpress.org user `nvdigitalsolutions` |
| Author URI | `https://nvdigitalsolutions.com` |
| Plugin URI | `https://nvdigitalsolutions.com/wpoos` |
| License | GPLv3 or later |
| Requires WordPress | 6.0 |
| Tested up to | 7.1 |
| Requires PHP | 8.1 |
| Category | Design |
| Tags | design tokens, brand kit, theme.json, accessibility, email templates |

**Short description (≤150 chars):**

> One source of truth for your brand: design tokens, Global Styles, WCAG contrast checks, and branded email templates.

**Long description (opening paragraphs):**

> NV oOS Design System turns your brand into a set of design tokens and publishes them everywhere WordPress needs them: the front end, the block editor's Global Styles, and every outgoing email you choose to brand.
>
> It is standalone by design. No other plugin is required. The plugin ships around 70 tokens across eight groups, W3C DTCG 2025.10 import/export for Tokens Studio, Style Dictionary and Terrazzo, an opt-in `theme.json` Global Styles bridge, a brand kit with JSON and printable HTML export, a WCAG 2.2 contrast auditor, and five accessible email templates with a wrapper that is **off by default**.

---

## 2. Reviewer cover note

Paste this into the ticket email after the submission (or the form's description field if one is present).

**What the plugin does**

NV oOS Design System is a standalone brand-operations plugin:

- **Design tokens** — around 70 tokens in eight groups, emitted as `--nds-*` CSS custom properties, with optional typed `@property` output and dark/high-contrast/reduced-motion variants.
- **DTCG 2025.10 interchange** — spec-shaped export and a transactional, alias-aware import (`$extends`, `$root`, `$deprecated`, `$extensions`). Legacy string values still import.
- **Global Styles** — an opt-in `wp_theme_json_data_theme` bridge that adds the palette, font families, font sizes, spacing and shadows to the block editor. It runs in memory and never writes theme files.
- **Brand kit** — identity record plus JSON and printable HTML brand-guide export.
- **WCAG 2.2 contrast** — text pairs at 4.5:1; large text, borders and focus rings at 3:1; focus-ring and target-size tokens included.
- **Email templates** — five accessible templates, multipart plain-text alternatives, dark-mode overrides, preheaders and a 102 KB Gmail budget. The wrapper is **off on fresh installs**; the administrator explicitly selects a delivery mode.

**Compliance notes**

- **No companion plugin is required.** NV oOS, JetEngine, JetSmartFilters, JetFormBuilder, Elementor and WooCommerce are optional integrations, capability-gated at the call site. There is no `Requires Plugins` header, no activation dependency check, and no admin notice asking for another plugin.
- **No trialware, no licence server, no telemetry.** Every feature in the ZIP is fully functional.
- **Enqueued assets only.** Front-end, admin and integration CSS is registered and enqueued through `wp_register_style()` / `wp_enqueue_style()` / `wp_add_inline_style()` on `wp_enqueue_scripts` and `admin_enqueue_scripts`. The plugin prints no bare `<style>` or `<script>` tags and no inline event handlers; the one confirmation dialog is bound from an enqueued script via a `data-` attribute.
- **Sanitisation and escaping.** Settings go through the Settings API and nonce/capability-checked `admin-post.php` handlers. Stored identifiers, options, hooks and the email template post type are prefixed `nvoos_nds_` / `nvoos_nds_email`. Imported DTCG metadata is depth- and length-bounded and text-sanitised before storage.
- **Email HTML safety.** Template HTML is validated for active content (scripts, frames, forms, event handlers, `javascript:`/`data:text/html` URLs, CSS `expression()`/`behavior:`) before it is stored. The validator is deliberately non-destructive so `<style>`, VML and Outlook conditional comments survive. The admin preview renders inside an empty-sandbox iframe.
- **External services:** none by default. The plugin bundles no credentials and makes no direct provider call. The optional AI template generator hands the prompt to the WordPress AI Client (7.0+), which uses the provider the site owner configured; a site that needs a different provider can supply one through the `nvoos_nds_email_generation_provider` filter. This is disclosed in `== External Services ==`.
- **Data:** settings live in the options table; templates are a private custom post type. The send log stores timestamp, status, template, size and a truncated subject — never the body or recipient address. Nothing is sent to NV Digital Solutions. Uninstall removes the plugin's own options and templates on every site in a multisite network.
- **Third-party code:** none bundled. Pure PHP; no Composer runtime dependencies, no CDN assets, no remote JS/CSS, no remote update mechanism.
- **Accessibility:** the plugin ships focus-visible and target-size tokens and audits contrast before activation.

**Pre-submission validation**

| Gate | Result |
|---|---|
| Plugin Check (WP 7.1, PCP 2.1) | **0 errors, 0 warnings** (no `AIProvider.DirectIntegration` rows: there is no direct provider path) |
| PHPUnit | 163 tests across 16 files, 0 failures |
| WordPress Coding Standards | 0 errors |
| PHP compatibility | PHP 8.1–8.4 clean |
| Functional QA (Docker, WP 7.1, standalone) | 44/44 runtime checks and 15/15 email-delivery checks pass |
| Multisite QA | 14/14 checks, including per-site seeding, per-site options and network-wide uninstall |
| Version consistency | Header, constant, `Stable tag` all `1.0.1` |

**Reviewer question pre-answers**

- *Is this an addon for another plugin?* No. It is a standalone plugin. The “NV oOS” name is the author's product family; the base NV oOS plugin is not required.
- *Does it phone home?* No. There is no telemetry, no licence check and no CDN.
- *Does it call an AI provider directly?* No. There is no provider SDK, no API-key option and no hardcoded provider host in the plugin. Generation is delegated to the WordPress AI Client, or to a callback a site registers on `nvoos_nds_email_generation_provider`. This is why Plugin Check reports no `AIProvider.DirectIntegration` finding.
- *Does it require an API key?* No. Every non-AI feature works without one; AI generation is optional and off until explicitly invoked, and it uses the site's existing AI Client configuration.
- *Can the email wrapper change mail on activation?* No. It is off by default, no template is active on a fresh install, and it requires a template plus a delivery mode.
- *Why does the plugin lift KSES when saving templates?* Email templates legitimately contain `<style>`, VML and conditional comments, which core's KSES filter strips for users without `unfiltered_html` (every front-end request, cron and WP-CLI run). Each template is validated by the plugin's own active-content validator first, and KSES is restored immediately after the single write.
- *What happens on uninstall?* The plugin's own options, token metadata, brand kit, send log and email template posts are removed, per site on multisite.

---

## 3. Listing screenshots

| # | File | Caption |
|---|---|---|
| 1 | `screenshot-1.png` | The token editor with the live preview and generated CSS. |
| 2 | `screenshot-2.png` | The brand-kit form with the JSON and HTML export panel. |
| 3 | `screenshot-3.png` | The WCAG 2.2 contrast audit, showing passing and failing pairs. |
| 4 | `screenshot-4.png` | A DTCG import reporting the applied token count. |
| 5 | `screenshot-5.png` | The design tokens merged into the block editor's Global Styles palette. |
| 6 | `screenshot-6.png` | The email template library with audit scores and a live preview. |

Icons and banners are generated by `python bin/generate-wporg-assets.py`.

---

## 4. Post-submission checklist

1. Keep the uploaded ZIP unchanged for the review thread; note the ticket number from the confirmation email.
2. When approved, commit the plugin to SVN:
   - `trunk/` — the same files as the ZIP root (`nvoos-design-system/` contents, not the folder itself).
   - `tags/1.0.1/` — a copy of `trunk/`.
   - `assets/` — copy `.wordpress-org/*` (icon-128x128, icon-256x256, banner-772x250, banner-1544x500, screenshot-1…6). Do **not** add `SUBMISSION.md`.
3. Do not commit the ZIP to SVN; WordPress.org builds it from `tags/`.
4. If the slug `nvoos-design-system` is unavailable, stop and rename the plugin folder, `Text Domain`, and the `nvoos_nds_` prefixes before resubmitting.
5. After approval, verify the directory page renders the six screenshots and that the `== Screenshots ==` captions match their order.
