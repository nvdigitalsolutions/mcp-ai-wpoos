# NV oOS Design System — Brand Ops & WordPress.org Readiness — Research

**Date:** November 28, 2026
**Status:** Research complete — feeds Proposal 169
**Proposal number:** 169 (research companion)
**Target:** `addons/nvoos-design-system/` (v0.3.0)
**Main document:** [`169-nvoos-design-system-wporg-brand-ops-proposal.md`](./169-nvoos-design-system-wporg-brand-ops-proposal.md)
**Predecessors:** [047](./047-nvoos-design-system-addon-proposal.md) · [048](./048-nvoos-design-system-deferred-email-enhancements.md) · [058](./058-nvoos-design-system-dtcg-token-import-proposal.md)

---

## 1. Scope and method

This research answers three questions:

1. **What does the industry require** of a design-token / brand-ops plugin in 2026 — format, accessibility, email, and WordPress integration?
2. **Where does `nvoos-design-system` v0.3.0 actually stand** against those requirements (code-verified, not README-verified)?
3. **What must change** to ship a credible 1.0.0 to WordPress.org without trialware, external-service ambiguity, or a review rejection?

Method: read the plugin source and tests in `addons/nvoos-design-system/`; read the DTCG 2025.10 format and color modules; the `theme.json` v3 reference and `wp_theme_json_data_theme` hook; WCAG 2.2; current email-client guidance; the WordPress.org detailed guidelines and readme mechanics; and snapshot the relevant wp.org tag/search pages.

---

## 2. Standards inventory

### 2.1 DTCG 2025.10 — the format the plugin claims

The **Design Tokens Format Module 2025.10** is a Final Community Group Report published **28 October 2025** and marked **stable**. Its normative requirements that matter here:

| Area | Requirement | Source |
|---|---|---|
| Token shape | `$value` is the only required property; `$type`, `$description`, `$extensions`, `$deprecated` optional | §5 |
| Types | `color`, `dimension`, `fontFamily`, `fontWeight`, `duration`, `cubicBezier`, `number`, plus composite `strokeStyle`, `border`, `transition`, `shadow`, `gradient`, `typography` | §8–9 |
| Type inference | Tools **MUST NOT** guess a type from the value; an untyped, uninherited token is invalid | §5.2.2, §8 |
| Color value | Object with required `colorSpace` + `components[]`; optional `alpha` and `hex` fallback | Color module §4.1 |
| Dimension value | Object with numeric `value` + `unit` restricted to `px` or `rem` | §8.2 |
| Aliases | `{group.token}` references; chained references and cycle detection required | §7 |
| JSON Pointer | Tools **MUST** support `$ref: "#/…"` for property-level references | §7.1.2, §7.5.1 |
| Groups | `$type` inheritance, `$root` token, `$extends` (deep merge, JSON-Schema-like), `$deprecated`, `$extensions` | §6 |
| Extensions | Tools **MUST** preserve unknown `$extensions` data on round-trip | §5.2.3 |
| File format | JSON; recommended extensions `.tokens` / `.tokens.json`; MIME `application/design-tokens+json` | §4.1–4.2 |

**Implication:** a file that emits `"$type": "color", "$value": "#ffffff"` is *not* a conformant DTCG 2025.10 color token. It may be tolerated by a lenient importer, but it is not round-trip-safe across Tokens Studio, Style Dictionary, or Terrazzo.

### 2.2 Tokens Studio

Tokens Studio exposes a **Token Format** setting with **legacy** and **W3C DTCG** modes, and supports DTCG import/export (`docs.tokens.studio/manage-settings/token-format`). Design-token round-trips in 2026 assume the DTCG shape across Figma, Tokens Studio, Style Dictionary and Terrazzo (industry summaries converge on this). For the plugin's "demo DTCG export into Tokens Studio" goal, the export must be **spec-shaped**, not hex-string-shaped.

### 2.3 `theme.json` v3

Version 3 works with **WordPress 6.6+**. The relevant settings for a token bridge:

| Tokens | `theme.json` target |
|---|---|
| Colors | `settings.color.palette[]` (`{name, slug, color}`) |
| Font families | `settings.typography.fontFamilies[]` |
| Font sizes | `settings.typography.fontSizes[]` |
| Spacing | `settings.spacing.spacingSizes[]` |
| Shadows | `settings.shadow.presets[]` |
| Radius | `settings.border.radiusSizes[]` |
| Everything else | `settings.custom` (emits `--wp--custom--{key}--…`) |
| Applied styles | `styles.color`, `styles.typography`, `styles.elements.link/button` |

The supported runtime injection point is the **`wp_theme_json_data_theme`** filter (since WP 6.1), which receives a `WP_Theme_JSON_Data` object and supports `update_with()`. This lets the plugin publish tokens into Global Styles **without writing to theme files** — the correct approach for a wp.org plugin (no filesystem writes, no theme-lockin).

### 2.4 WCAG 2.2

WCAG 2.2 became a W3C Recommendation on **5 October 2023** and adds **9 success criteria**. The ones a token/email plugin must respect:

| SC | Level | Relevance |
|---|---|---|
| 1.4.3 Contrast (Minimum) | AA | 4.5:1 for normal text — already checked in the email auditor |
| 1.4.11 Non-text Contrast | AA | 3:1 for UI components and focus indicators — **not yet checked** |
| 1.4.1 Use of Color | A | never encode meaning by colour alone |
| 2.4.11 Focus Not Obscured (Minimum) | AA | sticky headers/footers must not hide focus |
| 2.4.13 Focus Appearance | AAA | focus indicator area + 3:1 change — suggests dedicated focus tokens |
| 2.5.8 Target Size (Minimum) | AA | 24×24 CSS px — suggests a `size_target_min` token |
| 2.5.7 Dragging Movements | AA | provide a single-pointer alternative |
| 2.3.3 Animation from Interactions | AAA | `prefers-reduced-motion` — already emitted |
| 1.4.12 Text Spacing / 1.4.4 Resize Text | AA | tokens must survive 200% zoom and spacing overrides |

**Implication:** the plugin has the right primitive (`prefers-reduced-motion`, dark and high-contrast variants) but lacks **focus**, **target-size**, and **non-text contrast** as first-class token concerns.

### 2.5 Email-client constraints

| Constraint | Evidence | Consequence |
|---|---|---|
| Gmail clips HTML at ~**102 KB** | 2026 client-rendering guides; Sender, Cufinder | The footer/unsubscribe can be cut — a deliverability and CAN-SPAM risk. The plugin already budgets 102,400 bytes with an 80 KB warning. |
| Dark mode affects a large share of opens | 2026 email-design guides | Templates need `color-scheme` metadata and dark-mode overrides. The plugin has dark-mode checks. |
| Outlook VML buttons can double-render | 2026 VML/Outlook analyses | The bulletproof button must live in a single conditional block; verify no duplicate VML + HTML anchor. |
| Images are blocked by default in many corporate clients | 2026 email design guides | CTAs must be HTML/text with VML fallback; alt text and background colours are mandatory. |
| Accessibility failures are near-universal | Email Markup Consortium research cited in Proposal 047 (99.97% of real emails) | "Accessible by construction" is a genuine differentiator, but it must be verifiable. |
| EAA applies to EU B2C from 28 June 2025 | Proposal 047 research | Accessibility is now a compliance issue, not a nice-to-have. |

### 2.6 WordPress.org submission mechanics

Key rules from the **Detailed Plugin Guidelines** (last updated 11 March 2026) and the **Plugin Readmes** handbook:

- **Guideline 5 — no trialware.** All code in the wp.org plugin must be functional; premium code belongs in a **separate add-on hosted outside wp.org** (the guideline explicitly recommends this).
- **Guideline 6 — serviceware allowed.** External services are permitted with disclosure; a service that only validates licences is not.
- **Guideline 7 — no tracking without consent.** No telemetry by default.
- **Guideline 8 — no third-party CDNs** for JS/CSS (fonts excepted); no loading executable code from third-party servers.
- **Guideline 9 — no legal-compliance guarantees.** Copy must say "helps you meet…", never "makes you compliant".
- **Guideline 10 — credits/links on the public site must be opt-in and default off.**
- **Guideline 11 — no dashboard hijacking;** notices must be scoped and dismissible.
- **Guideline 12 — max 5 tags;** no competitor names; readmes written for humans.
- **Guideline 14/15 — release discipline;** stable tag must match the PHP version; bump for each release.
- **Guideline 17 — trademarks.** The slug must not begin with another product's term.
- **Readme mechanics:** `readme.txt` controls the directory page; the **short description is capped at ~150 characters**; `Requires PHP` and `Requires at least` are parsed from the **main PHP file** since WP 5.8; readmes over ~10 KB risk errors; the current release changelog belongs in the readme, older history in `changelog.txt`.
- **Plugin Check (PCP)** is the practical pre-submission gate; the repo already uses it for Docs Hub, Content Graph and Forge Bridge.

### 2.7 Competitive landscape (wp.org snapshots, November 2026)

| Query/tag | Result |
|---|---|
| `design tokens` tag | **9 plugins, all under 100 active installs** — Theme.json Design Manager (<10), TokenForge (<10), YAOTW Stylo (10+), Utility for GeneratePress (40+), Leitvia Studio (<10), Image to Design Tokens (<10), and four more |
| `brand kit` search | Dominated by page-builder "kits" (Royal Addons 600k, Style Kits 10k, etc.); **no brand-operations leader**. `EMCP Tools` lists "brand kits" as one feature. |
| Design-token + email | **No plugin combines standards-based tokens, Global Styles, WCAG auditing, and a `wp_mail` brand wrapper.** |
| Design-token + theme.json | Only micro-install plugins; none export DTCG or audit contrast |

**Implication:** the category is small but genuinely open. The plugin's differentiator is not "another token editor" — it is **one source of truth for web + email + design-tool interchange**.

---

## 3. Code-verified current state

### 3.1 What works

| Capability | Evidence |
|---|---|
| ~70 tokens, 8 groups | `class-nvoos-nds-preset-minimal.php`, registry |
| CSS `:root` output | `class-nvoos-nds-css-generator.php` |
| `@property` typed output (opt-in) | `generate_property_blocks()` |
| Dark / high-contrast / reduced-motion blocks | `generate_a11y_blocks()` |
| DTCG export | `class-nvoos-nds-dtcg-exporter.php` |
| DTCG import (all-or-nothing, 256 KB cap) | `class-nvoos-nds-dtcg-importer.php` + `test-dtcg-importer.php` |
| 5 email templates + global `wp_mail` wrapper | `includes/emails/` |
| Multipart plain-text pairing | `Email_Wrapper::pair_plain_text()` |
| WooCommerce rebrand (opt-in) | `class-nvoos-nds-integration-woocommerce.php` |
| Email auditor (security, structure, contrast, dark mode, 102 KB) | `class-nvoos-nds-email-auditor.php` |
| 8 AI tools | `includes/tools/` |
| Nonce + `manage_options` on admin actions | `class-nvoos-nds-admin-page.php`, `class-nvoos-nds-plugin.php` |
| Uninstall cleanup | `uninstall.php` |
| 11 PHPUnit files | `tests/` |

### 3.2 Gaps and defects that block a 1.0.0

| # | Finding | Severity | Evidence |
|---|---|---|---|
| R1 | **Colour tokens export as hex strings**, not DTCG colour objects (`colorSpace`/`components`) | High | `DTCG_Exporter::export()` writes `$token->value` verbatim |
| R2 | **Dimension tokens export as strings**, not `{value, unit}` | High | same |
| R3 | **Shadow/transition tokens export as CSS strings** | Medium | same |
| R4 | **Aliases are rejected on import** (`{core.x}`) and never exported | High | Importer docblock; no alias field on `Data_Token` |
| R5 | **Only 6 NDS types map to 5 DTCG types**; `fontWeight`, `number`, `cubicBezier`, `strokeStyle`, `border`, `transition`, `gradient`, `typography` unsupported | High | `type_map` / `reverse_type_map` |
| R6 | **Unknown types silently become `string`** — violates "MUST NOT guess" | Medium | `map_type()` fallback |
| R7 | **No `$root`, `$extends`, `$deprecated`, `$extensions`, JSON Pointer** | Medium | Importer has no handling |
| R8 | **CSS values are escaped with `esc_html()` inside `<style>`** — quotes in font stacks become `&quot;` | High (correctness) | `build_root_block()`, `generate_property_blocks()` |
| R9 | **CSS sanitisation is prefix-based** (`rgb`, `hsl`, `var(`) and permits `;`/`{`/`}` in the remainder | Medium (defence-in-depth) | `sanitize_color()` |
| R10 | **Email wrapper is ON by default** — `get_active()` defaults to `letterhead`, so activation silently rebrands all outgoing mail | High (UX/review) | `Email_Template_Registry::get_active()` |
| R11 | **PHP floor mismatch** — PHP header says 8.1, README says 7.4 | Medium | `nvoos-design-system.php` vs `README.md` |
| R12 | **No `readme.txt`, `.distignore`, or `.wordpress-org/` assets** | Blocker | directory listing |
| R13 | **No `theme.json` / Global Styles integration** | High (opportunity) | no `wp_theme_json_data_theme` reference |
| R14 | **No web-token contrast audit** — only the email auditor checks contrast | High (differentiator) | no contrast service for tokens |
| R15 | **No focus / target-size / non-text-contrast tokens** | Medium | presets lack them |
| R16 | **README is stale** — claims DTCG export only, though the importer exists | Low | `README.md` |
| R17 | **Uninstall does not loop multisite sites** | Low | `uninstall.php` |
| R18 | **No `load_plugin_textdomain()`** — fine on wp.org, but GitHub ZIPs need it | Low | no call |
| R19 | **AI generation only works with the NV oOS base plugin** (`wp_mcp_ai_get_api_key()`), despite "auto-detect" wording | Medium (positioning) | `Email_Generator` |
| R20 | **No external-services disclosure** for OpenAI/Gemini calls | Blocker | no readme yet |

### 3.3 Review-sensitive behaviours (not defects, but must be explained)

- The plugin hooks **`wp_mail` globally** at priority 99. This is legitimate and guarded (sentinel, full-document, text/plain), but a reviewer will ask why it is safe and why it is on by default.
- The plugin **renders admin forms outside the Settings API**; nonces and capability checks are present, so this is acceptable, but migrating to `register_setting()` would align with the handbook and simplify REST exposure.
- The CPT `nds_email_template` is correctly `public => false`, `show_ui => false` — no front-end URLs. Keep it that way.
- The email templates contain **no plugin credits or external URLs** — Guideline 10 is satisfied today; keep it that way.

---

## 4. Implications (findings R1–R20 → design decisions)

1. **Build a codec, not a rewrite.** Keep the flat registry (CSS generation depends on it); add a `DTCG_Codec` that converts token ↔ DTCG value per type, preserving lossy data in `$extensions` under a vendor key (`com.nvdigitalsolutions.nds`).
2. **Dual-format import is mandatory.** Accept legacy strings *and* spec objects; export spec objects by default, with a "legacy strings" toggle for older tools.
3. **Aliases are a 1.0 feature, not a 1.1 nice-to-have.** Without them, Figma-native semantic tokens cannot round-trip. Resolve on import; store the alias path for export.
4. **Publish to Global Styles at runtime.** Use `wp_theme_json_data_theme`; never write theme files. This is the WordPress-native moat.
5. **Make contrast a first-class service.** One contrast engine feeding both the token linter and the email auditor; add focus/target-size tokens so WCAG 2.2 is designed in, not audited after the fact.
6. **Default the email wrapper OFF.** Offer a "brand every email" opt-in with per-source modes (`text_only`, `fragments`, `all`) and a local audit log. This is both better UX and easier review.
7. **Ship 1.0.0 clean.** Remove the `--cds-*` shims, fix the PHP-floor mismatch, add readme/assets/PCP, and document the external AI service precisely.

---

## 5. Ecosystem scan — which addons belong on WordPress.org

### 5.1 Selection criteria (industry practice)

The WordPress.org directory rewards plugins that are **single-purpose, redistributable, and self-sufficient**. A repo addon is a good submission candidate only when all of the following hold:

| Criterion | Why it matters at review | Repo implication |
|---|---|---|
| GPL-compatible licence | Directory requires GPLv2+ or compatible | Excludes Proprietary addons; AGPL needs a compatibility argument |
| Standalone value | Reviewers reject wrappers that only work with a paid parent | The free build must do something useful with no NV oOS install |
| No vendor server / no account | Guideline 7 (no trialware) and guideline 6 (no undisclosed phoning home) | Excludes Checkout API, SaaS Controller, MCP Gateway, Cloudways Dashboard |
| Optional external services only | AI/API calls must be disclosed and opt-in | Direct AI calls trigger PCP `AIProvider.DirectIntegration` warnings |
| Low review surface | Uploads, remote code, native binaries, and OAuth flows extend review time | Media Worker, Canvas, Cornerstone3D are poor fits |
| One searchable job | Directory discovery is query-driven | "design tokens", "brand kit", "docs", "comic reader" beat "operator toolkit" |
| Bounded maintenance | A directory listing creates a support obligation | Prefer addons with no server component |

### 5.2 Portfolio assessment (November 2026 inventory)

| Addon | Licence | Standalone? | wp.org fit | Verdict |
|---|---|---|---|---|
| **Design System** (`nvoos-design-system`) | GPL-3.0 | Yes | **High** — open "design tokens" category, brand-kit search, DTCG + theme.json + email moat | **Ship now (1.0.0)** |
| **Content Graph AI** (`plugins/nvoos-content-graph`) | GPL-3.0 | Yes (inherits live Core installs) | **High** — established install base, no new market risk; needs a free read-only graph explorer + disclosed optional AI | **Ship next (Q1)** |
| **Docs Hub** (`nvoos-docs-hub`) | GPL-3.0 | Yes | Proven — already live on WordPress.org at 0.5.3 | Already shipped; keep as the ecosystem anchor |
| **Page Agent** (`addons/page-agent`) | GPL-3.0 | Needs base plugin | Medium-high — client-side, no keys, novel "AI copilot for a page" story; experimental | **Evaluate after Content Graph** |
| **Comic Reader** (`addons/comic-reader`) | GPL-3.0 | Needs base plugin | Medium — distinct audience (CBR/CBZ reader), but a different brand from brand-ops | Candidate, separate brand |
| **Media Studio** (`addons/media-studio`) | GPL-3.0 | Needs base plugin | Medium — crowded media category, heavier upload/AI review surface | Defer |
| **Chat SPA** (`addons/chat-spa`) | GPL-3.0 | Needs base plugin | Low-medium — saturated chatbot category, parent-plugin dependency | Defer |
| **NV oOS WiFi** (`addons/nvoos-wifi`) | GPL-3.0 | Needs base plugin | Low — B2B venue analytics, not a directory search | Not a wp.org product |
| **Algorave** (`addons/algorave`) | AGPL-3.0 | Needs base plugin | Low-medium — licence compatibility argument + niche | Defer |
| **Fleet Operator**, **SaaS Controller**, **MCP Gateway**, **Checkout API**, **Cloudways Dashboard** | Mixed / proprietary | Server or operator scope | None — infrastructure, not end-user plugins | Never submit |
| **Pro**, **Graphify**, **Schedule Anything**, **Fantasy Football**, **Embedded** | Proprietary | — | None — licence incompatible | Never submit |

### 5.3 What the two lead products can do now

**Content Graph AI** (already built, live Core installs):

- Ship a free WordPress.org edition that is a genuine graph explorer: import posts/pages/terms, visualise relationships, export JSON/CSV — no AI key required.
- Keep AI enrichment as an optional, disclosed service (ideally through the WordPress AI Client, or via the NV oOS connector) so the free build passes `AIProvider.DirectIntegration` review.
- Reuse the Design System token layer for the explorer UI, so the two plugins reinforce one visual identity.

**Design System 1.0.0** (this release):

- Standalone brand-ops: tokens → CSS, Global Styles, contrast audit, brand kit, optional email branding.
- The email wrapper is a defensible standalone feature ("brand every outgoing email") with zero dependencies.
- DTCG import/export is the design-tool bridge: a Figma/Tokens Studio round-trip is the demo that sells the plugin.
- It is also the **shared token substrate** for every future SPA addon (Toolkit Shell and Schedule Anything SPA already consume `--nds-*`), so shipping it first compounds.

### 5.4 Recommended sequence

1. **Q4 (now):** Design System 1.0.0 — complete the submission package, capture screenshots, submit.
2. **Q1:** Content Graph AI free edition — leverage the existing install base; keep AI optional and disclosed.
3. **Q1–Q2:** Page Agent, if the experimental surface stabilises; it is the lowest-key, most novel consumer story after the first two.
4. **Opportunistic:** Comic Reader as a separate brand; Media Studio only if the media category strategy is funded.
5. **Never:** proprietary or server-side addons — they belong in the commercial/self-hosted channel, not the directory.

---

## 6. Sources

- DTCG, *Design Tokens Format Module 2025.10* — https://www.designtokens.org/tr/2025.10/format/
- DTCG, *Design Tokens Color Module 2025.10* — https://www.designtokens.org/tr/2025.10/color/
- Tokens Studio, *Token Format — W3C DTCG vs Legacy* — https://docs.tokens.studio/manage-settings/token-format
- WordPress, *Theme.json Version 3 Reference* — https://developer.wordpress.org/block-editor/reference-guides/theme-json-reference/theme-json-living/
- WordPress, *`wp_theme_json_data_theme`* — https://developer.wordpress.org/reference/hooks/wp_theme_json_data_theme/
- WordPress, *Plugin Readmes* — https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- WordPress, *Detailed Plugin Guidelines* — https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- WordPress, *Settings API* — https://developer.wordpress.org/plugins/settings/
- W3C WAI, *What's New in WCAG 2.2* — https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/
- Litmus, *Bulletproof email buttons* — https://www.litmus.com/blog/a-guide-to-bulletproof-buttons-in-email-design
- 2026 email-client rendering guidance (Gmail 102 KB clip, dark mode, VML): Sender, Cufinder, Digital Applied, DEV Community client-rendering guides
- wp.org snapshots: `/plugins/tags/design-tokens/`, `/plugins/search/brand+kit/`, `/plugins/search/ai+governance/`, `/plugins/tags/mcp/` (November 2026)
- Repo prior research: Proposal 047 (email standards), 048 (multipart/Woo/Paper Store), 058 (DTCG import)
