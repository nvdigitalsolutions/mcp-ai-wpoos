=== NV oOS Design System ===
Contributors: nvdigitalsolutions
Donate link: https://nvdigitalsolutions.com/wpoos
Tags: design tokens, brand kit, theme.json, accessibility, email templates
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

One source of truth for your brand: design tokens, Global Styles, WCAG contrast checks, and branded email templates.

== Description ==

NV oOS Design System turns your brand into a set of design tokens and publishes them everywhere WordPress needs them: the front end, the block editor's Global Styles, and every outgoing email you choose to brand.

**Standalone by design.** No other plugin is required. NV oOS, JetEngine, JetSmartFilters, JetFormBuilder, Elementor and WooCommerce are optional integrations that load only when those plugins are present.

**No API key is required for the core plugin. No external service is contacted unless you explicitly generate a template with AI.**

= Design tokens =

* Around 70 tokens across eight groups: colours, typography, spacing, borders, shadows, sizing, transitions and email.
* CSS custom properties (`--nds-*`) injected on every page.
* Optional typed `@property` output.
* Dark-mode, high-contrast and reduced-motion token variants.
* Three presets: Minimal, Ecommerce and Directory.
* Visual editor with a live preview and per-token reset.

= W3C DTCG 2025.10 interchange =

* Export a spec-shaped design-tokens document for Tokens Studio for Figma, Style Dictionary and Terrazzo.
* Colour, dimension, font-weight, number, duration and shadow values are emitted as DTCG values, not raw CSS strings.
* Import DTCG documents with group `$extends` support, `$root` tokens, aliases (including chained and circular-reference detection), `$deprecated`, `$description` and `$extensions`.
* Imports are transactional: if any token fails validation, nothing is changed.
* Legacy string values from earlier versions still import.

= Global Styles (theme.json) =

* Optional runtime bridge that adds your palette, font families, font sizes, spacing and shadow presets to the block editor's Global Styles.
* Runs through `wp_theme_json_data_theme` and never writes theme files.
* Works with block themes on WordPress 6.1+; schema v3 is used on WordPress 6.6+.
* Download a `theme.json` partial for version control.

= Brand kit =

* Brand name, tagline, sub-brand, contact email, logo (light and dark), icon, favicon, social links and voice notes.
* The kit references tokens by id, so one token edit updates web, editor and email.
* Export the kit as JSON (identity + tokens + `theme.json` + active email template) or as a printable HTML brand guide with palette and contrast ratios.

= WCAG 2.2 contrast =

* A single contrast engine checks the standard text, button, border and focus-ring pairs.
* Text pairs are evaluated at 4.5:1; large text, borders and focus rings at 3:1.
* Custom-property values are reported as unresolved rather than guessed.
* Focus-ring and minimum target-size tokens are included so the design starts accessible.

= Email templates =

* Five accessible templates: Letterhead, Minimal, Transactional, Newsletter and Ecommerce Receipt.
* The wrapper is **off on fresh installs**. Choose Plain text only, HTML fragments, or All eligible emails.
* Guards prevent double-wrapping, complete HTML documents from other plugins, and explicit text/plain sends.
* Multipart plain-text alternatives, dark-mode overrides, `color-scheme` metadata, hidden preheaders, and a 102 KB Gmail clip budget.
* An auditor checks security, structure, accessibility, contrast, dark mode, VML duplication, image-only CTAs and size before activation.
* Optional WooCommerce rebrand via WooCommerce's own hooks.
* A local send log records the last 50 wrapped or skipped messages — never the body.

= Optional AI generation =

AI template generation is optional and reuses the site's AI provider through the WordPress AI Client (WordPress 7.0+). The plugin never stores an API key and never calls a provider directly; sites that need a different provider can supply one with the `nvoos_nds_email_generation_provider` filter. The generated template is saved as a draft with an audit report and must be reviewed before activation. No AI request is made until an administrator explicitly generates a draft.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the plugin directory.
2. Activate **NV oOS Design System**.
3. Go to **Settings → Design System → Tokens** and choose a preset or edit the tokens.
4. Optional: enable **Publish tokens to Global Styles** on a block theme.
5. Optional: go to the **Emails** tab, choose a template, then select a delivery mode.

== Frequently Asked Questions ==

= Does this plugin require a specific theme? =

No. It outputs CSS custom properties that any theme or page builder can consume. The Global Styles bridge requires WordPress 6.1+ and is most useful on block themes.

= Will activating the plugin change my outgoing email? =

No. The email wrapper is off by default. Nothing is wrapped until you choose a template and a delivery mode.

= Does the plugin send data anywhere? =

No. There is no telemetry, no licence server and no CDN. The only external requests are AI calls made when an administrator explicitly generates an email template, and those go to whichever provider the site has configured in the WordPress AI Client. The plugin holds no credentials of its own.

= Do I need any other plugin? =

No. Tokens, DTCG exchange, Global Styles, the brand kit, contrast checks and email templates all work standalone. Optional integrations add convenience only: JetEngine, JetSmartFilters, JetFormBuilder and Elementor for component styling, and WooCommerce for email rebranding. AI template generation appears only when the site has a provider configured for the WordPress AI Client.

= Is it compatible with page builders? =

The token CSS is framework-agnostic. Optional integrations for JetEngine, JetSmartFilters, JetFormBuilder and Elementor are included and only load when those plugins are active.

= Can I use my Figma tokens? =

Yes. Export from Tokens Studio in W3C DTCG mode (or use any DTCG 2025.10 document), then paste it into **Import Tokens** on the Tokens tab. The importer reports every skipped token and refuses partial imports.

= What happens on uninstall? =

Options, token metadata, the brand kit, the send log and email template posts are removed. On multisite the cleanup runs for every site.

== Screenshots ==

1. The token editor with the live preview and generated CSS.
2. The brand-kit form and export panel.
3. The WCAG 2.2 contrast audit, showing passing and failing pairs.
4. The DTCG import report after a successful import.
5. The design tokens merged into the block editor's Global Styles palette.
6. The email template library with audit scores and a live preview.

== Changelog ==

= 1.0.0 =
* First WordPress.org release.
* DTCG 2025.10 codec: spec-shaped colour, dimension, font-weight, number, duration and shadow values; aliases, `$root`, `$extends`, `$deprecated` and `$extensions`.
* Global Styles bridge via `wp_theme_json_data_theme` (no file writes).
* Brand kit with JSON and printable HTML brand-guide export.
* WCAG 2.2 contrast audit, focus-ring and target-size tokens.
* Email wrapper is off by default with four delivery modes and a local send log.
* Email auditor adds VML duplication, image-only CTA, `color-scheme` and preheader checks.
* Email template HTML is validated for active content before storage, and the admin preview renders in a sandboxed iframe.
* All front-end, admin and integration CSS is registered and enqueued with the WordPress asset API; the plugin prints no bare `<style>` tags.
* Strict CSS value validators; CSS values are no longer HTML-escaped inside `<style>`.
* Stored identifiers are fully prefixed: the email template post type is `nvoos_nds_email` with `_nvoos_nds_template_*` meta keys, migrated automatically from pre-1.0 installs.
* AI template generation uses the WordPress AI Client only. Direct OpenAI/Gemini calls, the `nvoos_nds_ai_api_key` filter and the provider selector were removed in favour of the `nvoos_nds_email_generation_provider` filter.
* Legacy `--cds-*` CSS variables and `NV_oOS_Crocoblock_DS_*` PHP class aliases removed. The `.cds-form` wrapper class remains for markup compatibility.
* Public filter hooks now use the `nvoos_nds_` prefix (for example `nvoos_nds_email_skip`).
* Fully standalone: no other plugin is required; NV oOS, Crocoblock, Elementor and WooCommerce integrations are optional and capability-gated.
* Multisite-aware uninstall.

== Upgrade Notice ==

= 1.0.0 =
1.0.0 removes the legacy `--cds-*` variables and PHP class aliases; migrate custom CSS to `--nds-*`. Public hooks use the `nvoos_nds_` prefix, and email templates move to the prefixed `nvoos_nds_email` post type automatically. The email wrapper is off until you choose a template and delivery mode.

== External services ==

This plugin does not contact any external service by default and does not bundle any credentials.

When an administrator generates an email template with AI, the prompt is handed to the WordPress AI Client (WordPress 7.0+), which sends it to the provider the site owner configured there. The destination host, terms and privacy policy are therefore those of the site's chosen provider and are not chosen by this plugin.

No account is created with this plugin, no data is sent unless the generation action is explicitly triggered, and no usage data is returned to NV Digital Solutions.

== Privacy ==

The plugin stores its settings in the WordPress options table on your own server, and email templates as a private custom post type. The send log stores the timestamp, status, template, size and a truncated subject — never the message body or recipient address. Nothing is transmitted to NV Digital Solutions. Uninstall removes the stored data.
