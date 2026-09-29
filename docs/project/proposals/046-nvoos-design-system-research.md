# NV oOS Design System — Industry Standards Research (Email Templates, Design Tokens & AI Generation)

**Date:** September 29, 2026
**Status:** 🔍 RESEARCH (companion to [`046-nvoos-design-system-addon-proposal.md`](./046-nvoos-design-system-addon-proposal.md))
**Based on:** Web research into email accessibility standards (WCAG, EAA, EMC), responsive email frameworks, the WordPress `wp_mail()` pipeline, design-token-driven branding, and AI-assisted email template generation.
**Predecessor research:** [`crocoblock-design-system-enhancement-research.md`](./crocoblock-design-system-enhancement-research.md) (July 2026 — DTCG, tiered tokens, `@property`, a11y tokens).

---

## Executive Summary

This research underpins the **rename + email-module enhancement** of the
`nvoos-crocoblock-ds` addon into **`nvoos-design-system`**. Six research
domains produce 9 concrete standards the new email module should encode:

1. **WCAG 2.2 AA** is the operative accessibility standard for email (not
   WCAG 3, which is still a draft) — and the **European Accessibility Act
   (EAA)** has made it a legal requirement for B2C email in the EU since
   **June 28, 2025**.
2. The **Email Markup Consortium (EMC)** publishes the only email-specific
   markup compliance standard; its **2026 accessibility report** found
   **99.97%** of 443,585 tested emails contain serious accessibility issues —
   a built-in, standards-compliant template set is a differentiator, not a
   nicety.
3. **Cerberus** remains the reference pattern library for responsive,
   accessible email HTML; **bulletproof buttons** (table + `<a>` + VML) are
   the industry-standard CTA pattern.
4. **Gmail clips messages over 102 KB** — email templates must enforce a
   size budget, and AI-generated templates need a size gate.
5. WordPress's `wp_mail` / `wp_mail_content_type` filter order and the
   reset-after-send rule define exactly how a global email wrapper must
   behave — including the **double-wrap guard** pattern demonstrated in the
   Aerlinn letterhead reference.
6. Email branding should be **token-driven** (the same DTCG-style token
   registry that powers the web design system should power email) — colors,
   fonts, spacing, and radii stay in one source of truth.
7. **Dark mode in email** is table-stakes in 2026; templates must ship
   `prefers-color-scheme` overrides and dark-mode-safe tokens.
8. AI-generated email templates are industry-standard (Jasper, Litmus AI,
   Validity, Stripo) but every credible implementation pairs generation
   with **automated validation gates** — rendering checks, accessibility
   scoring, code linting, and human review before activation.
9. The **DTCG 2025.10** spec (researched July 2026) should be extended with
   an email token group so email palettes export/import alongside web tokens.

---

## 1. Email Accessibility Standards

### 1.1 WCAG 2.2 AA (build target — not WCAG 3)

- The W3C's WCAG 2.2 Level AA is the accepted conformance bar for email
  (WCAG 3 is draft-only). Litmus, Dyspatch, Stripo, and the EMC all frame
  their guidance against WCAG 2.2.
- **POUR principles** apply in email code as: `role="presentation"` on every
  layout table, descriptive `alt` text, a `lang` attribute on `<html>`,
  semantic heading order, and real text instead of images.
- **EN 301 549** (EU accessibility standard for ICT) is met by targeting
  WCAG 2.2 AA — relevant because of the EAA (below).

### 1.2 European Accessibility Act (EAA) — legal driver

- The **EAA took effect June 28, 2025**. It applies to B2C brands
  communicating with users in the EU — transactional and marketing email
  included.
- Practical consequence: an email template system that ships
  accessibility-correct defaults (contrast, semantics, dark mode) removes a
  compliance burden that most WordPress sites currently carry invisibly.

### 1.3 Email Markup Consortium (EMC)

- The **EMC** ([emailmarkup.org](https://emailmarkup.org/)) is the only
  email-specific markup standards body (members: Salesforce, SparkPost,
  Dyspatch, and individual email engineers).
- **EMC Compliant Standards** require: semantically logical structure that
  does not interfere with the client's own structure, supported attributes
  retained on unsupported elements, and neutral fallback elements.
- **EMC Accessibility Report 2026**: 99.97% of emails had serious
  accessibility issues; ARIA usage is still broken in most clients — so
  email code must rely on **native semantics** (headings, `alt`, table
  roles), not ARIA.

### 1.4 Checklist the template set must satisfy

| Requirement | Standard | Enforced how |
|---|---|---|
| 4.5:1 contrast (body text), 3:1 (large text/UI) | WCAG 2.2 AA 1.4.3 | Contrast computation on token pairs in admin + audit tool |
| `role="presentation"` on layout tables | EMC / WCAG 1.3.1 | Template lint (mandatory) |
| `lang` attribute + logical heading order | WCAG 3.1.1 / 2.4.6 | Template lint |
| Alt text on every image; decorative images empty `alt` | WCAG 1.1.1 | Template lint + audit tool |
| Body text ≥ 16px, line-height ≥ 1.4 | EMC guidance | Email token group defaults |
| Real text, not text-in-images | WCAG 1.4.5 | Template authoring rule for AI prompts |
| Dark-mode-safe colors (`prefers-color-scheme` overrides) | EAA-era practice | Built into every built-in template |
| Touch target ≥ 44px for CTA | WCAG 2.5.8 | Bulletproof button padding tokens |

---

## 2. Email HTML Rendering Standards

### 2.1 Cerberus — the reference pattern set

- [Cerberus](https://www.cerberusemail.com/) provides small, solid,
  well-tested responsive patterns for 50+ clients (Gmail, Outlook, Yahoo,
  AOL, Apple Mail). Smashing Magazine's guide to HTML email cites Cerberus
  and HTML Email (leemunroe) as the reliable starting sets.
- **Implication:** the addon's built-in templates should be Cerberus-derived
  patterns (hybrid/spongy layouts), not hand-rolled inventions — and the AI
  template generator should be prompted to emit Cerberus-style structure.

### 2.2 Bulletproof buttons

- Industry-standard CTA = `<table>`-wrapped `<a>` with **VML fallback** for
  Outlook (Windows) and `display:inline-block` progressive enhancement
  elsewhere. Styled `<button>` elements are not reliable in email.
- The Aerlinn reference uses a text link in the footer, which is safe; the
  enhanced system should ship a **`{{button}}` template part** built on the
  bulletproof pattern so AI templates inherit it instead of reinventing it.

### 2.3 Size budget (Gmail clipping)

- **Gmail clips messages larger than 102 KB.** Best practice: keep HTML
  under **100 KB**.
- Enforcement: a renderer-side size gate that warns (admin) and truncates
  only non-critical content (or blocks activation) when a template exceeds
  the budget; the AI audit tool reports size before saving.

### 2.4 Layout constraints AI must know

- No `<div>`-based grid, no `position:absolute`, no external stylesheets, no
  `<script>`, no web fonts beyond safe fallbacks (fonts are not downloaded
  in most clients), inline styles required (Outlook strips `<style>` in
  many contexts — use `<style>` blocks only for progressive enhancement
  like dark-mode overrides).
- These constraints become the **system prompt** for the AI generator
  (Section 6.3).

---

## 3. WordPress Email Pipeline (`wp_mail`) — Integration Contract

### 3.1 Hook order and the content-type rule

- The **`wp_mail` filter runs before `wp_mail_content_type`**. A wrapper
  that needs to force HTML must add `wp_mail_content_type` **inside the
  `wp_mail` filter callback** (or add/remove it around the send) — the
  WordPress.org docs explicitly warn: *"reset `wp_mail_content_type` back to
  `text/plain` after you send your message"*.
- The canonical pattern (Stack Exchange / wp.org docs):

```php
add_filter( 'wp_mail_content_type', 'set_html_content_type' );
wp_mail( $to, $subject, $message );
remove_filter( 'wp_mail_content_type', 'set_html_content_type' );
```

### 3.2 Double-wrap guard

- Multiple plugins wrapping `wp_mail` is the top failure mode of
  email-templating plugins. The Aerlinn reference solves it with a sentinel
  comment (`<!-- aerlinn-email-wrapper -->`) checked before wrapping.
- **The addon standardizes this:** a single sentinel
  `<!-- nds-email-wrapper:ID -->` (ID = template slug) is both the
  double-wrap guard and the audit marker.

### 3.3 Precedent plugin: WP Email Template

- The [WP Email Template](https://wordpress.org/plugins/wp-email-template/)
  plugin (100k+ installs) validates the demand: it wraps all outgoing
  `wp_mail` HTML with a selectable template, handles SMTP plugins
  (PostSMTP), and resets content type appropriately.
- **Gap it leaves:** no token integration, no accessibility guarantees, no
  AI assistance — the exact spaces `nvoos-design-system` would own.

### 3.4 Plain-text handling

- Industry practice: never wrap `text/plain` messages in HTML. The wrapper
  must skip when content type is not HTML **or** pair the HTML with a
  `multipart/alternative` plain-text part generated by stripping the
  template chrome (best deliverability practice).

### 3.5 WooCommerce compatibility

- WooCommerce emails already ship their own HTML wrapper and header-image
  setting. The Aerlinn reference's priority chain (custom filter →
  `woocommerce_email_header_image` → theme `custom_logo`) is the correct
  pattern: the addon should offer a **WooCommerce mode** that rebrands WC
  emails through WC's own filter hooks instead of double-wrapping.

---

## 4. Design-Token-Driven Email Branding

### 4.1 Why email should consume the same token registry

- Enterprise design systems (Salesforce Lightning, Sparkbox survey 2025)
  treat email as another **output format** of the token pipeline, alongside
  web CSS, mobile, and docs. Sparkbox's 2025 survey: teams with a design
  system ship 34% faster and cut design bugs 47% — email included.
- For `nvoos-design-system`, this means: **email templates reference
  `--nds-email-*` CSS variables**, and those variables resolve from the same
  registry (same admin editor, same presets, same DTCG export) as the web
  tokens. One palette change rebrands web **and** email.

### 4.2 Email token group (new)

| Token | DTCG `$type` | Maps to Aerlinn reference |
|---|---|---|
| `--nds-email-page-bg` | color | `page_bg` `#f0ede8` |
| `--nds-email-card-bg` | color | `card_bg` `#ffffff` |
| `--nds-email-header-bg` | color | `dark_green` `#0f1e18` |
| `--nds-email-accent` | color | `gold` `#c9b96e` |
| `--nds-email-accent-2` | color | `teal` `#4d8a7b` |
| `--nds-email-body-text` | color | `body_text` `#1a2420` |
| `--nds-email-divider` | color | `divider` `#e2ddd6` |
| `--nds-email-muted` | color | `muted_teal` `#2e4a3e` |
| `--nds-email-font` | fontFamily | Georgia serif stack |
| `--nds-email-body-size` | dimension | `15px` |
| `--nds-email-body-height` | number | `1.8` |
| `--nds-email-dark-*` | color | dark-mode overrides |

### 4.3 Merge tags (template language)

The wrapper injects a context into each template:

```
{{subject}} {{body}} {{to_name}} {{site_name}} {{site_url}} {{site_domain}}
{{logo_url}} {{admin_email}} {{sub_brand}} {{confidential}} {{year}}
```

Plus template parts: `{{button "label" "url"}}`, `{{divider}}`, `{{footer}}`.
All output escaped with `esc_html()`/`esc_url()`/`esc_attr()` per context
(two-gate rule: sanitize at entry, escape at exit).

---

## 5. Dark Mode in Email

- ~50%+ of Gmail/Apple Mail opens are in dark mode (Litmus Email Client
  Market Share 2026). Best practice: wrap dark overrides in
  `@media (prefers-color-scheme: dark)` inside a `<style>` block and use
  `color-scheme: light dark` meta — while keeping inline styles as the
  light-mode baseline for Outlook.
- The Aerlinn palette (light card on light page) needs explicit dark
  variants (`--nds-email-*` + `--nds-email-dark-*` tokens) — a requirement
  for every built-in template and a lint rule for AI-generated ones.

---

## 6. AI-Assisted Email Template Generation

### 6.1 Industry landscape

| Product | Pattern we adopt |
|---|---|
| **Jasper** | Prompt → full email template + copy, template library |
| **Litmus AI** | Auto-find and fix rendering/code/compliance risks before send |
| **Validity (Everest)** | Preflight checks across 40+ clients; network intelligence |
| **Stripo / Mailsoftly** | AI generator + automated HTML/CSS validation |
| **Migma review** | Preflight link/rending checks; visual (no-code) editors |

The consistent lesson: **generation and validation are inseparable.** No
credible tool ships raw LLM HTML to production.

### 6.2 Risks specific to LLM-generated email HTML

- **Hallucinated CSS** — flexbox/grid/CSS-variables unsupported in Outlook
  desktop; LLMs default to modern web CSS. Mitigation: system prompt
  constraints + structural lint (allowlist of tags/attributes).
- **Accessibility regression** — generated templates skip alt text and
  table roles. Mitigation: audit tool scores before activation.
- **Token leakage / non-conformance** — generated HTML hardcodes colors
  instead of using `--nds-email-*` variables. Mitigation: post-processor
  replaces palette literals with token references when they match.
- **Injection / XSS** — template HTML is rendered into outgoing email.
  Mitigation: `wp_kses` allowlist sanitization at save (with an email-safe
  tag allowlist), plus the two-gate escaping rule at render.

### 6.3 System-prompt contract for the generator tool

The `nds_generate_email_template` tool embeds these constraints in its
provider request:

1. Table-based layout, `role="presentation"`, width ≤ 600px, centered.
2. Bulletproof buttons (table + `<a>` + VML), 44px touch targets.
3. Inline styles as baseline; `<style>` only for dark-mode overrides.
4. No JS, no external stylesheets, no `<div>` grid, no absolute positioning.
5. Semantic headings (`h1`→`h3`), `lang="en"`, alt text on images.
6. Use `{{merge_tags}}` and `var(--nds-email-*)` tokens — never hardcode
   brand colors.
7. Include the `<!-- nds-email-wrapper:{slug} -->` sentinel.
8. Size budget ≤ 100 KB; 4.5:1 contrast on token pairs.
9. Emit only the template body (the renderer supplies doctype + skeleton)
   — or full document with sentinel, per the template `scope` setting.

### 6.4 Validation gates (pipeline order)

```
generate → wp_kses sanitize → structural lint → token conformance check
        → contrast computation → size gate → save as draft → admin preview
        → (optional) test send → human activation
```

---

## 7. Rename & Naming Standards

- WordPress.org / WPCS conventions: plugin slug, text domain, function
  prefix, and option keys should share one machine-readable prefix. The
  existing `nvoos-crocoblock-ds` / `NV_oOS_Crocoblock_DS_` / `--cds-` mix is
  functional but ties the identity to Crocoblock, while the system already
  covers Elementor, accessibility, and (soon) email — i.e., it is a general
  design system.
- **Chosen target:** `nvoos-design-system` (slug + text domain),
  `NV_oOS_Design_System_` (class prefix), `NVOOS_DESIGN_SYSTEM_*`
  (constants), `--nds-*` (CSS), `.nds-*` (utility classes),
  `nvoos_nds_*` (option keys), `nds_*` (tool slugs, hooks).
- **Back-compat shim:** the `--cds-*` variables, `.cds-*` classes, and
  `nvoos_cds_*` options are aliased during a transition release so existing
  Crocoblock builds do not break (details in the proposal, Section 6).

---

## 8. Prioritization Matrix

| # | Standard | Impact | Effort | Phase |
|---|---|---|---|---|
| 1 | WCAG 2.2 AA + EMC-conformant built-in templates | 🟢 High | 🟡 Medium | Email Phase 1–2 |
| 2 | Token-driven email branding (`--nds-email-*` group) | 🟢 High | 🟡 Medium | Email Phase 1 |
| 3 | `wp_mail` wrapper with sentinel double-wrap guard + content-type reset | 🟢 High | 🟢 Low | Email Phase 1 |
| 4 | Dark-mode overrides in every template | 🟢 High | 🟢 Low | Email Phase 1–2 |
| 5 | Bulletproof button + divider + footer template parts | 🟢 High | 🟢 Low | Email Phase 1 |
| 6 | AI generator with validation gates (lint, contrast, size, kses) | 🟢 High | 🟡 Medium | Phase 3 |
| 7 | Template audit tool (`nds_audit_email_template`) | 🟡 Medium | 🟡 Medium | Phase 3 |
| 8 | Size gate (100 KB budget) | 🟡 Medium | 🟢 Low | Email Phase 1 |
| 9 | WooCommerce rebrand mode (no double-wrap) | 🟡 Medium | 🟡 Medium | Phase 4 |
| 10 | Plain-text pairing (`multipart/alternative`) | 🟡 Medium | 🟡 Medium | Phase 4 |

---

## Sources

1. **W3C WCAG 2.2** — [Web Content Accessibility Guidelines](https://www.w3.org/TR/WCAG22/) — the conformance target for email (Litmus, Dyspatch, EMC all reference it; WCAG 3 is draft-only)
2. **European Accessibility Act (EAA)** — effective June 28, 2025; applies to B2C digital communications in the EU (via [debounce.com](https://debounce.com/blog/email-accessibility-guide/), [Dyspatch](https://www.dyspatch.io/blog/email-accessibility-ultimate-guide/))
3. **Email Markup Consortium** — [EMC Compliant Standards](https://emailmarkup.org/en/docs/compliant-standards/) and [Accessibility Report 2026](https://emailmarkup.org/en/reports/accessibility/2026/) — 99.97% of 443,585 tested emails contain serious accessibility issues
4. **Litmus** — [Ultimate Guide to Email Accessibility 2026](https://www.litmus.com/blog/ultimate-guide-accessible-emails) — WCAG framing, dark-mode share, client market share
5. **Cerberus** — [Patterns for Responsive HTML Email](https://www.cerberusemail.com/) — hybrid/spongy responsive patterns, accessibility-focused
6. **Smashing Magazine** — [A Complete Guide To HTML Email](https://www.smashingmagazine.com/2021/04/complete-guide-html-email-templates-tools/) — Cerberus/EmailFrame.work/MJML landscape, 50+ client testing
7. **Templates for Emails** — [Email Template Best Practices 2026](https://www.templatesforemails.com/blog/email-template-best-practices/) — 4.5:1 contrast, 100 KB budget (Gmail clips at 102 KB), client testing
8. **WordPress Developer Resources** — [`wp_mail()`](https://developer.wordpress.org/reference/functions/wp_mail/) and [`wp_mail_content_type`](https://developer.wordpress.org/reference/hooks/wp_mail_content_type/) — filter order, reset-after-send rule
9. **WP Email Template plugin** — [wordpress.org](https://wordpress.org/plugins/wp-email-template/) — market precedent for global `wp_mail` wrapping with template picker + PostSMTP handling
10. **Awesome Emails (jonathandion)** — [github.com](https://github.com/jonathandion/awesome-emails) — bulletproof email buttons (VML + CSS progressive enhancement)
11. **Migma** — [Top AI Tools for Generating Email Templates](https://migma.ai/blog/top-ai-tools-for-generating-email-templates) — generation + preflight validation landscape
12. **Validity** — [5 Ways AI Can Take Your Emails to the Next Level](https://www.validity.com/blog/5-ways-ai-can-take-your-emails-to-the-next-level/) — data quality and validation gates around AI email output
13. **W3C Design Tokens Community Group** — [DTCG Spec 2025.10](https://www.designtokens.org/tr/2025.10/) — carried forward from the July 2026 research; email token group extends the same format
14. **Sparkbox Design Systems Survey 2025** — teams with design systems ship 34% faster, 47% fewer design bugs (carried forward)
