# NV oOS Design System — Implementation Plan

**Date:** September 29, 2026
**Status:** ✅ EXECUTED (Phases 0–4 complete in this session — v0.2.0)
**Proposal:** [`046-nvoos-design-system-addon-proposal.md`](./046-nvoos-design-system-addon-proposal.md)
**Research:** [`046-nvoos-design-system-research.md`](./046-nvoos-design-system-research.md)
**Predecessor:** [`crocoblock-design-system-implementation-plan.md`](./crocoblock-design-system-implementation-plan.md) (all 5 phases ✅ — this plan renames that addon and adds the email module)

---

## Approach

Single-pass port + extension:

1. **Phase 0 — Rename:** the 20-file `addons/crocoblock-ds/` addon is ported
   to `addons/nvoos-design-system/` with the full identifier mapping from the
   proposal (§1), plus back-compat shims (CSS aliases, option migration,
   `class_alias`, legacy admin-post action).
2. **Phases 1–4 — Email module + AI tools:** new `includes/emails/` and
   `includes/tools/` subsystems are added on top of the renamed core.

The old `addons/crocoblock-ds/` directory is removed now that the new addon
is verified (the migration shims make it a drop-in replacement).

**Post-execution notes:**

- **Latent bug fixed:** the original `css_var()` double-prefixed group names
  (`--cds-color-color_surface`) so tokens never matched the component CSS
  (`--cds-color-surface`); the original addon's tests asserted the
  unreachable name and were never wired into CI. The port uses
  `--nds-{hyphenated-id}` everywhere (matching all documented variables),
  which also makes the legacy `--cds-*` aliases match what existing builds
  reference.
- **Autoloader fixed:** the original addon's autoloader mapped
  `data-`/`integration-` prefixes to nonexistent directories, so the four
  integration classes silently never loaded. The new autoloader maps
  `data- → base/`, `integration- → integrations/`, `email- → emails/`,
  `tool- → tools/`, `admin- → admin/` and is verified by a 31-class smoke
  test.
- **Build/distribution wiring updated:** `bin/build-addon-zips.sh` and the
  Pro addons page definition now build/publish `nvoos-design-system`;
  `docs/project/ADDON_INVENTORY.md` updated.
- **Validation:** `php -l` clean on all 40 PHP files; 42 functional checks
  pass (registry, CSS generator + aliases, renderer merge tags/escaping/
  button/sentinel, auditor contrast + gates, all 5 built-in templates).
  Full PHPUnit suite wired into root `phpunit.xml.dist` +
  `tests/bootstrap.php` for the CI/Docker gate (local vendor PHPUnit is a
  stale manual extraction unrelated to this change).

---

## Phase 0: Rename & Back-Compat — 5 stories

| Story | Deliverable | Files |
|---|---|---|
| 0.1 Mechanical rename | Folder, entry file, constants, class prefix, text domain, CSS vars (`--nds-*`), classes (`.nds-*`), option keys (`nvoos_nds_*`), hooks (`nds_*`) | `nvoos-design-system.php`, all `includes/class-nvoos-nds-*`, `assets/`, `uninstall.php` |
| 0.2 Option migration | One-way `nvoos_cds_*` → `nvoos_nds_*` on first load; legacy-alias toggle defaulted **on** for migrated sites | `class-nvoos-nds-plugin.php` |
| 0.3 CSS alias output | `--cds-*` duplicates emitted alongside `--nds-*`; `.cds-*` selectors shipped in components.css | `class-nvoos-nds-css-generator.php`, `assets/css/components.css` |
| 0.4 PHP shims | `class_alias` for all 11 public classes + legacy constants + `admin_post_nvoos_cds_export_dtcg` alias | `nvoos-design-system.php`, `class-nvoos-nds-plugin.php` |
| 0.5 Tests green | Ported 16 tests under `--nds-` names + alias coverage | `tests/test-token-registry.php`, `tests/test-css-generator.php` |

**Gate:** behavior parity on an existing install with shims enabled;
`php -l` clean on every file; WPCS-compliant.

---

## Phase 1: Email Core — 6 stories

| Story | Deliverable | Files |
|---|---|---|
| 1.1 Email token group | 15 `emails`-group tokens (`--nds-email-*` incl. `_dark` pairs); DTCG export covers the group automatically | `class-nvoos-nds-preset-minimal.php`, `class-nvoos-nds-data-token.php`, `class-nvoos-nds-dtcg-exporter.php` |
| 1.2 Template CPT + registry | `nds_email_template` CPT (private, revisions), meta schema, built-in seeding | `emails/class-nvoos-nds-email-template-cpt.php`, `class-nvoos-nds-email-template-registry.php`, `class-nvoos-nds-email-seeder.php` |
| 1.3 Renderer | Merge tags, `{{button}}` part, inline `var(--nds-email-*)` resolution, escaping, sentinel, skeleton for body-scope templates | `emails/class-nvoos-nds-email-renderer.php` |
| 1.4 Wrapper | `wp_mail` filter: sentinel guard, full-document skip, self-removing content-type filter, plain-text conversion/skip, context builder, opt-out filters | `emails/class-nvoos-nds-email-wrapper.php` |
| 1.5 Admin picker | Tokens/Emails tabs; template cards, preview pane, per-template settings, test send, apply | `admin/class-nvoos-nds-admin-page.php`, `emails/class-nvoos-nds-email-admin.php`, `assets/js/email-picker.js` |
| 1.6 Auditor core | Structure + security BLOCK gates; size gate | `emails/class-nvoos-nds-email-auditor.php` |

---

## Phase 2: Built-in Templates & Accessibility — 4 stories

| Story | Deliverable | Files |
|---|---|---|
| 2.1 Five built-in templates | Letterhead (Aerlinn pattern), Minimal, Transactional, Newsletter, Ecommerce Receipt — all token-driven, dark-mode ready | `emails/templates/*.html` |
| 2.2 Accessibility gates | Contrast computation (WCAG ratio), alt text, heading order, dark-mode checks in the auditor + admin badges | `emails/class-nvoos-nds-email-auditor.php` |
| 2.3 Template parts | `{{button "label" "url"}}` bulletproof CTA part in the renderer | `emails/class-nvoos-nds-email-renderer.php` |
| 2.4 WooCommerce safety | WC logo fallback chain + full-document skip (no double-wrap); WC rebrand filter documented | `emails/class-nvoos-nds-email-wrapper.php` |

---

## Phase 3: AI Tools — 5 stories

| Story | Deliverable | Files |
|---|---|---|
| 3.1 Tool bootstrap | `wp_mcp_ai_register_tools` registration (guarded by interface existence), `manage_options` capability, canonical envelopes | `class-nvoos-nds-plugin.php`, `tools/*` |
| 3.2 Read-only tools | `nds_list_email_templates`, `nds_preview_email_template` | `tools/class-nvoos-nds-tool-list-email-templates.php`, `class-nvoos-nds-tool-preview-email-template.php` |
| 3.3 Generation pipeline | Shared generator service: provider (filter → option → auto-detect `wp_mcp_ai_get_api_key()`), constraint system prompt, `wp_kses`, lint, token conformance, contrast, size, save-as-draft | `emails/class-nvoos-nds-email-generator.php`, `tools/class-nvoos-nds-tool-generate-email-template.php` |
| 3.4 Audit + activation | `nds_audit_email_template` scorecard, `nds_set_active_email_template` with gates + `force` | `tools/class-nvoos-nds-tool-audit-email-template.php`, `class-nvoos-nds-tool-set-active-email-template.php` |
| 3.5 Test send | `nds_test_send_email` scoped to `manage_options` users' emails | `tools/class-nvoos-nds-tool-test-send-email.php` |

---

## Phase 4: Ecosystem & Polish — 4 stories

| Story | Deliverable | Files |
|---|---|---|
| 4.1 Export/import tools | `nds_export_email_template`, `nds_import_email_template` (JSON: HTML + meta + settings) | `tools/class-nvoos-nds-tool-export-email-template.php`, `class-nvoos-nds-tool-import-email-template.php` |
| 4.2 Plain-text pairing | **Deferred** — explicit `text/plain` sends are skipped (never wrapped); `nds_email_plain_text` filter stub returns a stripped body for future multipart work. Rationale: multipart/alternative boundary handling in `wp_mail` headers is high-risk without SMTP-plugin coverage; documented as follow-up | `emails/class-nvoos-nds-email-wrapper.php` |
| 4.3 Docs & wiring | README, hooks reference, test-suite registration in root `phpunit.xml.dist` | `README.md`, `phpunit.xml.dist` |
| 4.4 Cleanup | Old `addons/crocoblock-ds/` removed; uninstall covers both option generations | `uninstall.php`, deletion |

---

## Test Coverage Plan

| Test File | Tests | What's Covered |
|---|---|---|
| `tests/test-token-registry.php` (ported) | 12 | Registry CRUD, grouping, presets, `--nds-` CSS var format, sanitization, reset |
| `tests/test-css-generator.php` (ported) | 4 | `:root` block, token reflection, style tag, value changes |
| `tests/test-email-wrapper.php` (new) | ~8 | Sentinel guard, full-document skip, text/plain skip, plain→HTML conversion, recipient-name extraction, self-removing content-type filter, opt-out filter, token resolution |
| `tests/test-email-renderer.php` (new) | ~6 | Merge tags, escaping, `{{button}}` part, `var()` resolution, body-scope skeleton, sentinel |
| `tests/test-email-auditor.php` (new) | ~8 | Structure/security BLOCK gates, size gate, alt text, contrast ratio math, dark-mode check, gate aggregation |
| `tests/test-email-tools.php` (new) | ~6 | Tool slugs, schemas, `manage_options` capability, list/preview/audit envelopes |

**Total target:** ~44 tests (16 ported + ~28 new).

---

## WPCS Compliance

All files pass `phpcs --error-severity=1 --warning-severity=8 --standard=phpcs.xml.dist`
with **zero errors, zero warnings**, including the two tool-envelope sniffs
(`WPMCPAI.Tools.CanonicalReturnEnvelope`, `WPMCPAI.Tools.SanitizeAtEntry`).

---

## Deferred Items (tracked for follow-up)

| Item | Status |
|---|---|
| Multipart plain-text pairing | ✅ Done in 0.3.0 — `phpmailer_init` + `AltBody`, `nds_email_plain_text` filter (see [`047-nvoos-design-system-deferred-email-enhancements.md`](./047-nvoos-design-system-deferred-email-enhancements.md)) |
| Full WooCommerce rebrand mode | ✅ Done in 0.3.0 — opt-in hook-based rebrand (`woocommerce_email_styles`, header/footer replacement, option sync, `nds_email_wc_rebrand`) |
| Paper Store template storage | ✅ Done in 0.3.0 — mirror/import bridge (`email-templates` collection) + export/import tool parameters + admin section |
| Storybook/Chromatic visual regression | Out of scope for an addon; auditor scorecard covers the gate function |
| `--cds-*` alias removal | Scheduled v1.0.0 per proposal (2-version notice) |
