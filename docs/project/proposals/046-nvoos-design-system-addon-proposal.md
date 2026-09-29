# NV oOS Design System — Addon Rename & Email Template Module Proposal

**Date:** September 29, 2026
**Status:** ✅ IMPLEMENTED (v0.2.0 — all phases delivered in one session; see [`046-nvoos-design-system-implementation-plan.md`](./046-nvoos-design-system-implementation-plan.md))
**Proposal number:** 046
**Target:** Rename & enhance `addons/crocoblock-ds/` → `addons/nvoos-design-system/`
**Estimated Duration:** 8 weeks (24 stories across 5 phases)
**Research companion:** [`046-nvoos-design-system-research.md`](./046-nvoos-design-system-research.md)
**Predecessors:** [`crocoblock-design-system-addon.md`](./crocoblock-design-system-addon.md) · [`crocoblock-design-system-enhancement-research.md`](./crocoblock-design-system-enhancement-research.md) · [`crocoblock-design-system-implementation-plan.md`](./crocoblock-design-system-implementation-plan.md)

---

## Executive Summary

The **NV oOS Crocoblock Design System** addon (`addons/crocoblock-ds/`,
implemented July 2026, all 5 phases) is a design token system for the
Crocoblock suite — ~55 tokens, CSS custom properties, DTCG export,
accessibility tokens, and integrations with JetEngine, JetSmartFilters,
JetFormBuilder, and Elementor.

This proposal renames the addon to **NV oOS Design System**
(`nvoos-design-system`) — reflecting that it is a general design system, not
a Crocoblock-only utility — and adds a second pillar: an **email template
module**.

The email module:

1. **Picks from a set of built-in email templates** (Letterhead — the Aerlinn
   pattern — Minimal, Transactional, Newsletter, Ecommerce Receipt) in the
   admin, and wraps all outgoing WordPress emails in the selected template
   via the `wp_mail` filter — no plugin dependencies, works on any site.
2. **Is token-driven**: email templates consume a new `--nds-email-*` token
   group from the same registry, so one palette change rebrands the website
   **and** every email.
3. **Ships AI tools** registered with the oOS tool registry
   (`wp_mcp_ai_register_tools`): generate new email templates from a prompt,
   list/preview/test-send templates, and audit generated templates against
   WCAG 2.2 AA, EMC, size, and rendering constraints before activation.

Research (companion doc) confirms this is aligned with 2025–2026 industry
standards: WCAG 2.2 AA + the European Accessibility Act (June 2025), the
Email Markup Consortium compliance standards (99.97% of real emails fail
accessibility), Cerberus email patterns, Gmail's 102 KB clip limit, and the
WordPress `wp_mail` pipeline contract.

---

## Problem Statement

### 1. The name no longer matches the product

| Evidence | Detail |
|---|---|
| Name is Crocoblock-scoped | `nvoos-crocoblock-ds`, `NV_oOS_Crocoblock_DS_`, `--cds-*`, `.cds-*` |
| Product is general | Phase 5 already added Elementor sync, DTCG export, accessibility + animation tokens — none Crocoblock-specific |
| Next pillar is email | Email templates have zero Crocoblock dependency; "Crocoblock DS" branding is actively confusing there |
| Discoverability | A design system usable on any WordPress site (tokens + email) is harder to market under a vendor name |

### 2. Email is the unbranded channel

- The plugin ecosystem sends dozens of `wp_mail()` messages (Pro
  notifications, calendar bookings, result delivery, maintenance alerts —
  verified across `addons/pro/`) — all plain text or ad-hoc HTML, none
  consistent with the site brand.
- The Aerlinn letterhead reference demonstrates the demand: a single
  well-designed wrapper lifts every outgoing email, but it is hardcoded
  (colors inline, one template, no admin UI, no token integration).
- No template picker exists; changing brand = editing PHP.
- AI assistants cannot help: there are no tools to generate, preview, or
  audit email templates.

### 3. Industry pressure (from research doc)

- **EAA (June 28, 2025)**: B2C email in the EU must be accessible.
- **EMC 2026 report**: 99.97% of emails contain serious accessibility
  issues — compliant-by-default templates are a real differentiator.
- **No WordPress plugin** combines token-driven design + accessible email
  templates + AI template generation today (WP Email Template has the
  wrapper but none of the tokens, accessibility guarantees, or AI).

---

## Proposed Solution

Three workstreams, delivered as **`addons/nvoos-design-system/`**:

1. **Rename & rebrand** (Phase 0) — mechanical rename with back-compat shims.
2. **Email template module** (Phases 1–2) — template registry, `wp_mail`
   wrapper, admin picker, token-driven rendering, 5 built-in templates.
3. **AI email-template tools** (Phases 3–4) — generation, audit, preview,
   test-send, export/import via the oOS tool registry.

### 1. Rename & Rebrand Scope

| Dimension | Current | New |
|---|---|---|
| Folder | `addons/crocoblock-ds/` | `addons/nvoos-design-system/` |
| Entry file | `nvoos-crocoblock-ds.php` | `nvoos-design-system.php` |
| Plugin name | NV oOS Crocoblock Design System | NV oOS Design System |
| Text domain | `nvoos-crocoblock-ds` | `nvoos-design-system` |
| Constants | `NVOOS_CROCOBLOCK_DS_*` | `NVOOS_DESIGN_SYSTEM_*` |
| Class prefix | `NV_oOS_Crocoblock_DS_` | `NV_oOS_Design_System_` |
| File prefix | `class-nvoos-cds-*` | `class-nvoos-nds-*` |
| CSS variables | `--cds-*` | `--nds-*` |
| Utility classes | `.cds-card` etc. | `.nds-card` etc. |
| Option keys | `nvoos_cds_*` | `nvoos_nds_*` |
| Tool/hook prefix | — (none yet) | `nds_*` |

**Back-compat contract (transition release v0.2.0):**

- CSS: emit `--cds-*` as aliases of `--nds-*` (default **on** for existing
  installs, toggleable in admin, removed in v1.0.0).
- Classes: components.css ships `.cds-*` selectors alongside `.nds-*`.
- Options: `nvoos_cds_settings` is migrated to `nvoos_nds_settings` on
  first load (one-way, non-destructive; old key deleted only on uninstall).
- PHP API: `NV_oOS_Crocoblock_DS_Plugin` remains as a thin deprecated
  shim (`class_alias`) through v0.x.

**Unchanged:** token registry semantics, the 4 integrations (JSF,
JetEngine, JFB, Elementor), presets, DTCG exporter, `@property` output,
a11y media queries, animation tokens.

### 2. Email Template Module

#### 2.1 Template registry

- **Storage:** custom post type `nds_email_template` (private, not public).
  Post content = template HTML. Built-in templates are seeded on activation;
  AI-generated templates are created as **drafts** pending review.
  Rationale: revisions for free, REST-ready for the picker UI, trivially
  exportable — versus option-array blobs.
- **Template meta:** `scope` (`full` document vs `body-only`),
  `description`, `version`, `source` (`builtin` | `ai` | `custom`),
  `settings` (logo URL, sub-brand, confidential marker, admin email
  override), `status` gate.
- **Built-in set (5):**

| # | Template | Use case | Notes |
|---|---|---|---|
| 1 | **Letterhead** | General brand emails | Aerlinn pattern: dark letterhead, gold rule, serif, confidentiality footer |
| 2 | **Minimal** | Clean single-column | Neutral; safest across clients |
| 3 | **Transactional** | Notifications, receipts, alerts | Compact, no letterhead, high-contrast |
| 4 | **Newsletter** | Digests, campaigns | Multi-section, image-friendly |
| 5 | **Ecommerce Receipt** | Order confirmations | Totals table, WooCommerce-aware |

All five ship accessibility-correct by construction (research §1.4):
`role="presentation"` tables, `lang`, alt text, ≥16px body, 4.5:1 token
contrast, dark-mode overrides, ≤ 100 KB.

#### 2.2 Global wrapper (`wp_mail` integration)

Follows the WordPress pipeline contract (research §3):

```php
add_filter( 'wp_mail', array( NV_oOS_Design_System_Email_Wrapper::class, 'wrap' ), 99 );
// inside wrap():
//   1. sentinel guard  — strpos( $message, '<!-- nds-email-wrapper:' ) !== false → return as-is
//   2. content type    — add_filter( 'wp_mail_content_type', html ); ... remove_filter after send (scoped)
//   3. plain text      — wpautop( esc_html( $body ) ) when not HTML; skip entirely for text/plain sends
//   4. context         — {to_name from To header, subject, site info, tokens}
//   5. render          — template HTML with merge tags + --nds-email-* variables resolved
```

- **Sentinel:** `<!-- nds-email-wrapper:{template_slug} -->` — double-wrap
  guard and audit marker in one.
- **Opt-out filters:** `nds_email_skip` (bool, per message/context),
  `nds_email_active_template` (slug), `nds_email_context` (merge data).
- **Logo priority chain:** `nds_email_logo_url` filter →
  `woocommerce_email_header_image` (if WC active) → theme `custom_logo` →
  empty (matches the Aerlinn reference).
- **WooCommerce mode (Phase 4):** rebrand WC transactional emails via WC's
  own hooks instead of double-wrapping (WC emails already carry a wrapper).

#### 2.3 Admin picker

- New tab on the existing admin page: **Settings → NV oOS Design System →
  Emails**.
- Template cards with **live preview pane** (renders the template with
  current tokens + sample content) — same interaction model as the token
  editor.
- Per-template settings: logo URL, sub-brand tagline, confidential marker,
  admin email.
- **Test send** (admin-post, nonce + capability `manage_options`, sends to
  current admin email only) and **Apply** (activates template globally).

#### 2.4 Email token group

- New group `email` (~14 tokens, research §4.2): page/card/header/body/
  divider/muted colors, accent ×2, font stack, body size/height, dark-mode
  pairs.
- Rendered inside templates as inline `var(--nds-email-*)` resolved by the
  renderer (inline styles for client safety) — **no runtime CSS-variable
  dependency in the email itself** (Outlook strips custom properties).
- Included in JSON + DTCG exports (extends the existing exporter with the
  `email` group — no format change).
- Presets (Minimal/Ecommerce/Directory) gain matching email palettes.

### 3. AI Tools for Email Templates

Registered via `wp_mcp_ai_register_tools` (addon pattern verified against
`addons/algorave/`, `addons/graphify/`, `addons/page-agent/`). Tools
implement `WP_MCP_AI_Tool_Interface`, use `WP_MCP_AI_Tool_Default_Capability`,
return the canonical envelope (success array or `WP_Error`), obey the
two-gate sanitization rule.

| Tool slug | Purpose | Capability | Output |
|---|---|---|---|
| `nds_generate_email_template` | Generate a new template from a prompt via the configured AI provider | `manage_options` | Saved as `nds_email_template` **draft**; returns template ID, audit report, preview URL |
| `nds_list_email_templates` | List built-in + custom templates with status, source, audit scores | `manage_options` | Array of template summaries |
| `nds_preview_email_template` | Render a template with sample content + current tokens | `manage_options` | HTML string + token snapshot |
| `nds_test_send_email` | Send the active template to a specified (admin-approved) address | `manage_options` | Send result |
| `nds_audit_email_template` | Run the compliance audit on any template (draft or active) | `manage_options` | Scorecard: accessibility, structure, size, contrast, dark mode |
| `nds_set_active_email_template` | Activate a template (only if it passes required gates, or `force`) | `manage_options` | Activation result + warnings |
| `nds_export_email_template` / `nds_import_email_template` | JSON export/import (Phase 4) | `manage_options` | JSON payload / import result |

#### 3.1 `nds_generate_email_template` contract

```json
{
  "prompt":        "A warm welcome email for new subscribers with a single CTA button",
  "base_template": "letterhead | minimal | transactional | newsletter | ecommerce | none",
  "palette":       "use_current_tokens | override:{...}",
  "subject_hint":  "Welcome to {site_name}",
  "save_as_draft": true
}
```

Pipeline (research §6.4): provider call with the email-constraints system
prompt (table layout, bulletproof buttons, no grid/JS/external CSS, semantic
headings, merge tags, token references, sentinel) → `wp_kses` sanitize →
structural lint → token-conformance pass (replace palette literals with
`var(--nds-email-*)` when they match) → contrast computation → 100 KB size
gate → save as **draft** → return audit report. Human (or assistant)
activation is always the last step.

#### 3.2 Audit gates (shared by tool + admin)

| Gate | Fail = |
|---|---|
| Structure: doctype, single `<body>`, sentinel present, tables have `role="presentation"` | BLOCK |
| Security: `wp_kses` allowlist clean (no `<script>`, `on*`, external CSS) | BLOCK |
| Accessibility: alt text, `lang`, heading order, ≥16px body | WARN (block activation) |
| Contrast: 4.5:1 on token pairs | WARN (block activation) |
| Dark mode: `prefers-color-scheme` block present | WARN |
| Size: ≤ 100 KB | WARN (block activation) |

### 4. Scope Boundaries (what this is NOT)

- **Not an SMTP/mailer plugin** (no deliverability layer; coexists with
  PostSMTP/FluentSMTP per the WP Email Template precedent).
- **Not a campaign/automation engine** (no lists, sequences, or sending
  queues — those belong to existing Pro toolkits; the `design-email-marketing`
  skill covers copywriting and stays untouched).
- **Not a theme.json replacement** (tokens remain CSS-variable-first).
- **Not a breaking rename**: v0.2.0 keeps `--cds-*` and `.cds-*` working.

---

## Architecture

```
addons/nvoos-design-system/
├── nvoos-design-system.php                    # entry point (ABSPATH guard, constants, autoloader)
├── uninstall.php                              # cleanup (both old + new option keys)
├── README.md                                  # full documentation
├── includes/
│   ├── class-nvoos-nds-plugin.php             # composition root (renamed)
│   ├── class-nvoos-nds-token-registry.php     # token CRUD + persist (renamed, + email group)
│   ├── class-nvoos-nds-css-generator.php      # :root + @property + a11y (renamed, + --cds-* aliases)
│   ├── class-nvoos-nds-assets.php             # component CSS enqueuing (renamed)
│   ├── class-nvoos-nds-dtcg-exporter.php      # DTCG export (renamed, + email group)
│   ├── class-nvoos-nds-preset-*.php           # 3 presets (renamed, + email palettes)
│   ├── base/
│   │   ├── class-nvoos-nds-data-token.php     # value object (renamed)
│   │   └── class-nvoos-nds-data-preset.php    # preset interface (renamed)
│   ├── admin/
│   │   ├── class-nvoos-nds-admin-page.php     # settings page (renamed, + Emails tab)
│   │   └── class-nvoos-nds-admin-email-page.php
│   ├── integrations/
│   │   ├── class-nvoos-nds-integration-jsf.php        # (renamed)
│   │   ├── class-nvoos-nds-integration-jetengine.php  # (renamed)
│   │   ├── class-nvoos-nds-integration-jfb.php        # (renamed)
│   │   └── class-nvoos-nds-integration-elementor.php  # (renamed)
│   ├── emails/
│   │   ├── class-nvoos-nds-email-template-cpt.php     # nds_email_template CPT + meta
│   │   ├── class-nvoos-nds-email-template-registry.php# built-in seeding + lookup
│   │   ├── class-nvoos-nds-email-wrapper.php          # wp_mail filter integration
│   │   ├── class-nvoos-nds-email-renderer.php         # merge tags + token resolution
│   │   ├── class-nvoos-nds-email-auditor.php          # shared validation gates
│   │   ├── class-nvoos-nds-email-seeder.php           # 5 built-in templates
│   │   └── templates/
│   │       ├── letterhead.html
│   │       ├── minimal.html
│   │       ├── transactional.html
│   │       ├── newsletter.html
│   │       └── ecommerce-receipt.html
│   └── tools/
│       ├── class-nvoos-nds-tool-generate-email-template.php
│       ├── class-nvoos-nds-tool-list-email-templates.php
│       ├── class-nvoos-nds-tool-preview-email-template.php
│       ├── class-nvoos-nds-tool-test-send-email.php
│       ├── class-nvoos-nds-tool-audit-email-template.php
│       ├── class-nvoos-nds-tool-set-active-email-template.php
│       ├── class-nvoos-nds-tool-export-email-template.php
│       └── class-nvoos-nds-tool-import-email-template.php
├── assets/
│   ├── css/ (admin.css, components.css — renamed, + email preview styles)
│   └── js/ (token-preview.js — renamed, + email-picker.js)
├── languages/
└── tests/
    ├── test-token-registry.php        (renamed, + email group tests)
    ├── test-css-generator.php         (renamed, + alias output tests)
    ├── test-email-wrapper.php         (sentinel, content-type, plain-text skip)
    ├── test-email-renderer.php        (merge tags, token resolution, escaping)
    ├── test-email-auditor.php         (gates matrix)
    └── test-email-tools.php           (tool contracts, canonical envelope, capability checks)
```

---

## Implementation Phases

### Phase 0: Rename & Back-Compat (Week 1) — 5 stories

| Story | Deliverable |
|---|---|
| 0.1 Mechanical rename | Folder, files, classes, constants, text domain, CSS vars, classes, option keys per §1 table; WPCS clean |
| 0.2 Option migration | `nvoos_cds_settings` → `nvoos_nds_settings` one-way migration on load |
| 0.3 CSS alias output | `--cds-*` aliases + `.cds-*` selectors behind `nvoos_nds_legacy_aliases` (default on for upgrades) |
| 0.4 PHP shim | `class_alias` for `NV_oOS_Crocoblock_DS_Plugin` and friends (deprecated notices in v1.0.0) |
| 0.5 Tests green | Existing 16 tests pass under new namespace + alias coverage |

**Gate:** byte-identical behavior on an existing install with the shims on.

### Phase 1: Email Core (Weeks 2–3) — 6 stories

| Story | Deliverable |
|---|---|
| 1.1 Email token group | ~14 `email` tokens + preset palettes; DTCG/JSON exports include the group |
| 1.2 Template CPT + registry | `nds_email_template` CPT, meta schema, built-in seeding (Letterhead + Minimal first) |
| 1.3 Renderer | Merge tags, inline token resolution, escaping (two-gate), sentinel |
| 1.4 Wrapper | `wp_mail` filter integration: sentinel guard, scoped content-type, plain-text skip, context builder, opt-out filters |
| 1.5 Admin picker | Emails tab: cards, live preview, per-template settings, test send, Apply |
| 1.6 Auditor core | Structural + security gates (BLOCK), size gate |

### Phase 2: Built-in Templates & Accessibility (Week 4) — 4 stories

| Story | Deliverable |
|---|---|
| 2.1 Transactional + Newsletter + Ecommerce Receipt templates | Seeded, token-driven, dark-mode ready |
| 2.2 Accessibility gates | Contrast, alt text, heading order, dark-mode checks in auditor + admin badge |
| 2.3 Template parts | `{{button}}`, `{{divider}}`, `{{footer}}` shared parts |
| 2.4 WooCommerce mode | Rebrand WC emails via WC hooks, no double-wrap |

### Phase 3: AI Tools (Weeks 5–6) — 5 stories

| Story | Deliverable |
|---|---|
| 3.1 Tool bootstrap | `wp_mcp_ai_register_tools` registration + base tool class |
| 3.2 `nds_list_email_templates` / `nds_preview_email_template` | Read-only tools with canonical envelopes |
| 3.3 `nds_generate_email_template` | Provider-call pipeline: system prompt → sanitize → lint → conformance → contrast → size → draft |
| 3.4 `nds_audit_email_template` / `nds_set_active_email_template` | Full scorecard; activation gates |
| 3.5 `nds_test_send_email` | Scoped test send (admin-approved recipient list, rate-limited) |

### Phase 4: Ecosystem & Polish (Weeks 7–8) — 4 stories

| Story | Deliverable |
|---|---|
| 4.1 Export/import tools | `nds_export_email_template` / `nds_import_email_template` (JSON: HTML + meta + settings) |
| 4.2 Plain-text pairing | `multipart/alternative` with template-stripped plain part |
| 4.3 Docs & skills | README, hooks reference, bundled skill update (`design-email-marketing` cross-ref) |
| 4.4 wp.org readiness + tests | Plugin Check clean, uninstall cleanup, full test suite (target 60+ tests) |

---

## Key Design Decisions

| Decision | Rationale |
|---|---|
| **CPT for templates, not options** | Revisions, drafts for AI output, REST-ready picker, trivial export; email templates are content, tokens are configuration |
| **Inline styles as email baseline; `<style>` only for dark mode** | Outlook strips `<style>`; dark-mode overrides degrade gracefully |
| **Token resolution at render time, not CSS variables in the email** | Outlook does not support custom properties; inline resolution keeps the token source-of-truth without client risk |
| **Sentinel `<!-- nds-email-wrapper:{slug} -->`** | Single mechanism for double-wrap guard + auditability (proven by the Aerlinn reference) |
| **AI saves drafts, humans/assistants activate** | Industry norm (Litmus/Validity); gates can BLOCK but never silently ship |
| **`manage_options` on all email tools** | Template generation/activation/send are admin-surface operations; aligns with existing tool capability patterns |
| **`--cds-*` aliases default-on through v0.x** | Zero-breakage rename for existing Crocoblock builds; removal is a scheduled v1.0.0 cleanup |
| **PHP 7.4 floor, WP 6.0+** | Carried from the current addon; no reason to raise for this scope |
| **Single option key for tokens, meta for templates** | Token read stays one DB fetch; template content stays in `wp_posts` |

---

## Before / After

### Before (today)

```php
// Pro notification manager — plain text, unbranded
$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
wp_mail( $user->user_email, $subject, $message, $headers );
```

### After (email module active, Letterhead template)

```
[600px branded card — dark letterhead + logo + gold rule]
[token-resolved body from the original message]
[sign-off, footer: site domain · admin email · CONFIDENTIAL]
[-- dark-mode variant automatically applied in Gmail/Apple Mail --]
```

Same one-line `wp_mail()` call site — zero changes to the 15+ existing
notification code paths. One admin toggle brands every outgoing email;
one token change rebrands web + email together.

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Rename breaks existing Crocoblock sites | `--cds-*`/`.cds-*` aliases default-on; option migration one-way + non-destructive; PHP `class_alias` shims; Phase 0 gate requires behavior parity |
| Double-wrapping with WooCommerce / SMTP plugins | Sentinel guard + WooCommerce mode via WC hooks; skip filter `nds_email_skip`; WP Email Template coexistence test in Phase 4 |
| `wp_mail_content_type` leakage to other plugins | Scoped add/remove inside the wrapper (wp.org documented pattern), not a global persistent filter |
| AI-generated HTML breaks in Outlook (grid/flexbox/variables) | Constraint system prompt + structural lint allowlist; BLOCK on unsupported structure; token conformance pass replaces literals |
| AI-generated HTML is inaccessible | Audit gates (alt, contrast, headings, dark mode) WARN + block activation; drafts only |
| Generated template too large (Gmail clip) | 100 KB size gate at generation and activation |
| Template XSS / injection via email HTML | `wp_kses` allowlist at save; escape-on-exit at render; drafts until reviewed |
| Scope creep into full email marketing | Explicit scope boundaries (§4); campaigns/sequences remain Pro-toolkit territory |

---

## Success Metrics

- **Adoption:** existing Crocoblock builds upgrade to v0.2.0 with zero manual
  changes (alias shims cover 100% of legacy selectors in the Phase 0 gate).
- **Coverage:** every built-in template scores 100/100 on the internal audit
  scorecard (WCAG 2.2 AA-relevant checks + EMC structure + dark mode).
- **Tool contract:** all 8 tools return canonical envelopes; 2 PHPCS sniffs
  (`WPMCPAI.Tools.CanonicalReturnEnvelope`, `WPMCPAI.Tools.SanitizeAtEntry`)
  pass at severity 5.
- **Quality gate:** 0 AI-generated templates reach "active" without passing
  required gates (or an explicit `force` with logged justification).
- **Test coverage:** ≥60 tests including wrapper sentinel/content-type/
  plain-text behavior, renderer escaping, auditor gate matrix, and tool
  capability checks.
- **Standards:** DTCG export includes the email group; Plugin Check clean
  for future wp.org submission.

---

## Open Questions

1. **Rename mechanics:** hard rename with shims (proposed) vs. new plugin +
   data-import flow? (Proposed: shims — cheapest zero-breakage path.)
2. **Email template storage:** CPT (proposed) vs. option arrays vs. Paper
   Store records — should Paper Store be the long-term store for
   AI-generated templates (reuse + search across sites)?
3. **Provider routing:** should `nds_generate_email_template` use the
   assistant's active provider via the shared client layer, or a dedicated
   connector with its own settings?
4. **Test-send recipients:** allow any address (with rate limit + audit
   log) or restrict to `manage_options` users' addresses?
5. **Multisite:** email templates site-specific (CPT is per-site — natural)
   or network-synced?
6. **Timing of `--cds-*` alias removal:** v1.0.0 with a 2-version notice
   (proposed) or keep indefinitely behind the toggle?

---

## Decision Required

1. ~~Approve the rename to `nvoos-design-system` with the Phase 0 back-compat
   contract (§1).~~ **Done — shipped in v0.2.0.**
2. ~~Approve the email module scope: 5 built-in templates, global `wp_mail`
   wrapper, token-driven rendering, admin picker (§2).~~ **Done.**
3. ~~Approve the AI tool set: 8 tools with draft-first + gated activation
   (§3).~~ **Done.**
4. ~~Confirm the scope boundaries (no SMTP, no campaign engine) (§4).~~
   **Upheld — full-document skip + WC logo fallback ship instead of a WC
   rebrand mode.**
5. **Open for follow-up:** Open Questions 2–5 — Paper Store storage for
   templates, dedicated provider connector, test-send recipient policy, and
   multisite template sync — Paper Store storage is now shipped (047); the
   remainder stay open.

---

## Implementation Notes (post-ship)

- **Latent bug fixed during the port:** the original addon's `css_var()`
  double-prefixed the group name (`--cds-color-color_surface`) while its
  component CSS referenced `--cds-color-surface` — token values never reached
  the widget selectors, and the addon's tests (never wired into CI) asserted
  the unreachable name. The renamed addon uses the self-describing ID as the
  variable name (`--nds-{hyphenated-id}`), which matches every documented
  variable and makes the legacy `--cds-*` aliases line up with the variables
  existing Crocoblock builds actually reference.
- **Validation:** `php -l` clean on all 40 PHP files; autoloader smoke test
  resolves all 31 classes + legacy aliases; 42 functional checks (registry,
  CSS generator + aliases, renderer merge tags/escaping/button/sentinel,
  auditor contrast + gates, all 5 built-in templates) pass. The full PHPUnit
  suite is wired into the root `phpunit.xml.dist` + `tests/bootstrap.php` for
  the CI/Docker gate (local vendor PHPUnit is a stale manual extraction).
