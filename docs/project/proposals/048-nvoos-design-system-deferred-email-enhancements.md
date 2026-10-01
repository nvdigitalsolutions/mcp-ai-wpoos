# NV oOS Design System — Deferred Email Enhancements (Multipart · WooCommerce Rebrand · Paper Store) — Research & Implementation Plan

**Date:** September 29, 2026
**Status:** ✅ EXECUTED (all three items implemented in v0.3.0)
**Proposal number:** 048
**Parent:** [`047-nvoos-design-system-addon-proposal.md`](./047-nvoos-design-system-addon-proposal.md) · [`047-nvoos-design-system-implementation-plan.md`](./047-nvoos-design-system-implementation-plan.md) (Deferred Items table)

---

## Executive Summary

Three follow-ups deferred from the v0.2.0 ship are implemented here:

1. **Multipart plain-text pairing** — wrapped HTML emails gain a plain-text
   alternative via PHPMailer's `AltBody` (the WordPress-sanctioned path), so
   plain-text clients and spam filters get a clean text body.
2. **WooCommerce rebrand mode** — an opt-in integration rebrands WC
   transactional emails using WC's own hooks (`woocommerce_email_styles`,
   `woocommerce_email_header`/`footer`, `woocommerce_email_get_option`) —
   no double-wrapping, no template overrides.
3. **Paper Store template storage** — templates mirror into (and import
   from) the Paper Store knowledge base (`email-templates` collection),
   giving cross-site reuse via the existing remote proxy.

---

## Research Synthesis (web + internal API verification)

### 1. Multipart / plain-text pairing

| Finding | Source | Decision |
|---|---|---|
| The canonical WordPress pattern is `phpmailer_init` → set `$phpmailer->AltBody`; PHPMailer then builds `multipart/alternative` itself. Setting `Content-Type: multipart/alternative` manually in headers is the **wrong** path (duplicate Content-Type bug — WP Trac #15448). | Stack Overflow ×3, WP Trac #15448, WordPress SE | Use `phpmailer_init` + `AltBody`, never manual boundaries |
| Guard the callback: only attach `AltBody` when `$phpmailer->ContentType === 'text/html'` and `AltBody` is still empty. | WordPress SE ("set_alt_mail_body" example) | One-shot callback with content-type guard |
| Plain-text hygiene: strip `<style>` first, convert block/`<br>`/`<td>` to newlines/tabs **before** stripping tags, decode entities, collapse 3+ blank lines to 2. | Litmus/plaintextemail guidance (carried), Mailgun deliverability notes | `plain_text_from_html()` helper |
| Deliverability: HTML-only emails score worse with filters; pairing plain text is a standing best practice. | Mailgun | Default-on for wrapped emails |

### 2. WooCommerce email rebranding

| Finding | Source | Decision |
|---|---|---|
| `woocommerce_email_styles` filter is the officially documented way to restyle WC emails without template overrides ("modify the email styles used by both WooCommerce and AutomateWoo"). | WooCommerce.com docs | Inject token-resolved palette CSS |
| `woocommerce_email_header` / `woocommerce_email_footer` actions let plugins add content; the default markup handlers are bound by `WC_Emails::__construct()`, so a plugin can `remove_action()` them and replace them with its own branded header/footer. | WooCommerce.com customization guide | Remove WC defaults, render NDS fragments |
| Hooks can add/change but not reword template text — content edits still need overrides; we only touch chrome, which is exactly hook territory. | Codeable guide | Scope: palette + header/footer + option sync |
| `woocommerce_email_get_option` filters each email option (`base_color`, `body_text_color`, `body_background_color`, `bg_color`, `footer_text_color`). | WC core | Sync the colour options from email tokens |
| WC emails are complete HTML documents — our wrapper's full-document guard already prevents double-wrapping, so the rebrand path must be hook-based, not wrapper-based. | Our own wrapper (verified) | No `wp_mail` involvement |

### 3. Paper Store storage (internal API verified)

| Finding | Source | Decision |
|---|---|---|
| `WP_MCP_AI_Paper_Store_Manager::get_instance()->get_repository( $collection )` returns a repository with `exists()`, `save()`, `find()`, `update()`, `all()`. Records: `id, type, title, description, tags, status, body, meta` (body/meta accept any JSON). | `includes/paper-store/class-wp-mcp-ai-paper-store-manager.php`, `class-wp-mcp-ai-paper-repository.php` | Mirror = upsert a record; import = `find()` → draft CPT post |
| Collections are directories under `uploads/mcp-ai-wpoos/paper-store` (filterable via `wp_mcp_ai_paper_store_root`); `paper_store_write` is the existing MCP write tool. | Manager source | `email-templates` collection; test isolation via the root filter |
| The bundled `design-email-marketing` skill already instructs assistants to save email templates via `paper_store_write` — the workflow exists but is not wired to the design system. | `.agents/skills/design-email-marketing/SKILL.md` | Bridge, don't duplicate |
| The addon must stay standalone — every Paper Store call is gated on `class_exists( 'WP_MCP_AI_Paper_Store_Manager' )`. | Addon design constraint | `is_available()` guard + WP_Error fallback |

---

## Implementation Plan

### A. Multipart plain-text pairing — `emails/class-nvoos-nds-email-wrapper.php`

1. New public static `plain_text_from_html( $html )` — the hygiene pipeline from
   the research table (style-strip → newline mapping → strip tags → entity
   decode → whitespace collapse → trim).
2. In `wrap()`: after rendering, derive the plain text and pass it through a
   **new, real `nds_email_plain_text` filter** (README already reserves the
   name); store it in a static pending slot; register a one-shot
   `phpmailer_init` callback (priority 20) that attaches it as
   `$phpmailer->AltBody` only when `ContentType === 'text/html'` and
   `AltBody` is empty, then clears the slot.
3. Make `resolve_logo_url()` public so the WooCommerce integration reuses the
   same logo chain (no duplication).
4. Tests: conversion pipeline cases, filter override, content-type guard,
   empty-AltBody guard, slot consumption.

### B. WooCommerce rebrand mode — NEW `integrations/class-nvoos-nds-integration-woocommerce.php`

1. Option `nvoos_nds_wc_rebrand` (default off) + per-email filter
   `nds_email_wc_rebrand( $enabled, $email )`.
2. `init()` guards on `class_exists( 'WooCommerce' )`; binds real work on
   `woocommerce_init`: removes WC's default header/footer handlers, adds NDS
   fragments, registers the styles + option-sync filters.
3. `inject_styles( $css, $email )` — appends token-resolved overrides for
   `#template_header`, `#template_footer_html`, `#body_content`, headings,
   links, and table borders (all values esc_html'd).
4. `render_header( $heading, $email )` / `render_footer( $email )` — branded
   fragments built from tokens + logo chain + site info, `role="presentation"`.
5. `sync_option( $value, $email, $key )` — maps `base_color` →
   `email_header_bg`, `body_text_color` → `email_body_text`,
   `body_background_color` → `email_page_bg`, `bg_color` → `email_card_bg`,
   `footer_text_color` → `email_muted`.
6. Admin: a "WooCommerce Rebrand" checkbox section on the Emails tab
   (admin-post action `wc_rebrand`).
7. Tests: stubbed `WooCommerce`/`WC()`; assert hook wiring, default-handler
   removal, fragment output, styles injection, option mapping, and the
   per-email filter.

### C. Paper Store storage — NEW `emails/class-nvoos-nds-email-paper-store.php`

1. `is_available()` guard on `WP_MCP_AI_Paper_Store_Manager`.
2. `mirror( $template, $collection = 'email-templates' )` — upserts a record
   (`nds-{slug}` id, tags `email-template` + source + status, body = payload
   html/scope/settings, meta = audit score + source). Handles create vs update.
3. `import_record( $collection, $id )` — `find()` → materialize as a **draft**
   `nds_email_template` post.
4. Tool surface:
   - `nds_export_email_template` gains `mirror_to_paper_store` +
     `paper_store_collection` parameters.
   - `nds_import_email_template` gains `paper_store_collection` +
     `paper_store_record_id` (take precedence over `json`; `json` becomes
     non-required — validated in `execute()`).
5. Admin: a "Paper Store" section (mirror active template; import by
   record ID) with an availability notice when the base plugin is absent.
6. Tests: point `wp_mcp_ai_paper_store_root` at a temp dir, mirror/import/
   unavailable-gate round trip.

### D. Wiring & docs

- `Plugin::init()` registers the WC integration; `uninstall.php` removes the
  new option; `README.md` hook/feature tables updated; the 046 plan's
  Deferred Items table and proposal notes marked done; proposals README entry
  updated.

---

## Validation

- `php -l` on all touched files; autoloader + functional stub-harness checks
  for the new classes; `bash -n` unaffected.
- PHPUnit additions wired into the existing addon suite (CI/Docker gate).

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| `phpmailer_init` clobbers another plugin's `AltBody` | Only set when empty; one-shot self-removal |
| WC default-handler removal breaks if WC re-binds later | Bind on `woocommerce_init` (after `WC_Emails` construction); guard `WC()->mailer()` instance type |
| WC rebrand clashes with user's WC settings | Palette sync only overrides colour options when the rebrand is enabled; `nds_email_wc_rebrand` per-email escape hatch |
| Paper Store absent (standalone addon) | `is_available()` guard + descriptive WP_Error; admin shows an availability notice |
| Paper Store write fails mid-flow | Mirror returns the repository's WP_Error verbatim; import never touches the CPT on failure |
