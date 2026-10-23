# Proposal 082 — Schedule Anything Platform: Single-Site (Sole Tenant) Mode

- **Date:** 2026-10-19
- **Status:** Implemented (platform 0.1.1; open questions resolved as proposed)
- **Surfaces:** `addons/schedule-anything-platform` (activation gate, provisioner, REST controller, usage tracker), `addons/pro` (capability seam filter only — no new Pro code), `docs` + `CHANGELOG`
- **Tracks:** schedule-anything SaaS, multi-tenancy, developer experience
- **Follows:** Proposal 081 (presets/toolkits API), PR #7132 (merged — drop slug-specific `Requires Plugins` header so the platform activates alongside the Complete bundle), the single-site activation report that triggered this proposal

## 1. Problem Statement

The Schedule Anything Platform plugin hard-refuses single-site installs. Three
gates shipped in the initial commit (`731219a8cd`):

1. **G1 — Activation `wp_die`.** `SA_Plugin::on_activate()` calls
   `deactivate_plugins()` + `wp_die()` when `! is_multisite()`. This breaks
   WP-CLI/bulk activation flows and makes the plugin impossible to activate
   outside a network.
2. **G2 — Error notice.** `maybe_render_multisite_notice()` shows
   "This plugin requires WordPress Multisite to function" whenever the plugin
   is active on a single-site install.
3. **G3 — Provisioner refusal.** `SA_Multisite_Provisioner::provision()`
   returns `sa_not_multisite` on single-site.

This was a correct guard for the production SaaS deployment (Decision D1 of
the implementation plan chose Multisite as the tenancy primitive), but it
blocks two real use cases:

- **Local/dev demo.** Developers and sales demos want the full Schedule
  Anything surface — SPA, presets, toolkits, tier entitlements — on a plain
  single-site WP without standing up a network and subdomain routing.
- **Complete-bundle installs.** PR #7132 made the platform plugin activatable
  alongside `nvdigital-open-operator-system-oos-complete`; bundle users who
  run single-site hit G1/G2 and cannot use the bundled addon at all.

Goals:

- On a single-site install, the plugin **activates normally** and runs in
  **sole-tenant mode**: the single blog *is* the tenant.
- Provisioning degrades to an **idempotent self-registration** (no subsite
  creation) so the Cloud Worker signup flow remains E2E-testable.
- The tenant admin surface (SPA, presets, toolkit toggles) is fully usable:
  tier defaults seeded, entitlement checks resolve to a real tier, and the
  REST capability seam grants access to site admins.
- Multisite behavior is **unchanged** — every change is gated on
  `! is_multisite()`.

Non-goals: single-site *multi*-tenancy (multiple tenants on one install is
the shared-DB model, explicitly rejected by the plan's Decision D1), billing
changes, and subdomain routing.

## 2. Research & Industry Standards

### 2.1 Sole-tenant / demo degradation patterns

- **Degrade, don't refuse** — multi-tenant platforms commonly ship a
  "single-tenant mode" for development and small deployments where the
  tenancy layer collapses to one tenant rather than erroring (Auth0
  [multi-tenant docs](https://auth0.com/docs/get-started/tenant-settings);
  Clerk's single-instance mode). The platform surface should behave
  identically; only the *provisioning* primitive changes.
- **Guard the irreversible operations** — in a sole-tenant deployment,
  offboarding the only tenant is equivalent to deleting the platform. The
  safe pattern is an explicit refusal with a machine-readable error code
  (Stripe's [deletion safeguards](https://stripe.com/blog/designing-robust-and-predictable-apis-with-idempotency) —
  destructive ops require explicit confirmation/refusal signals).

### 2.2 WordPress plugin activation hygiene

- Activation hooks must **not** `wp_die()` or self-deactivate; failures should
  be surfaced as dismissible admin notices instead. `wp_die` on activation
  breaks WP-CLI (`wp plugin activate`), bulk activation, and installer flows
  (WordPress Plugin Handbook,
  [Activation/Deactivation Hooks](https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/);
  the wp.org [Plugin Review Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) —
  "execution of arbitrary code on activation").
- State changes on activation must be idempotent and must not overwrite
  existing settings (mirrors `SA_Toolkit_Manager::ensure_defaults()`, which
  already refuses to overwrite non-empty `wp_mcp_ai_settings`).

### 2.3 Server-side entitlements stay server-side

- Proposal 081 §2.1: the SPA never decides what's allowed. Single-site mode
  must preserve this — the tier stored in `sa_tenant_meta` remains the single
  source of truth the Pro adapter (`WP_MCP_AI_SA_Toolkit_Adapter::get_tier()`)
  already consults. No SPA change.

## 3. Design Decisions

| # | Decision | Alternatives | Rationale |
|---|---|---|---|
| D1 | Single-site mode is **automatic** (`! is_multisite()`), not opt-in | `SA_PLATFORM_SINGLE_SITE` constant gate | Zero-config demo; matches "it should just work". The user activating a proprietary SaaS-platform addon is already an explicit choice. |
| D2 | Sole tenant = the main site. `provision()` on single-site returns the main blog as the tenant (idempotent self-registration) with the **same response contract** plus `single_site: true` | 501 on provision; no-op returning `{}` | Keeps the Cloud Worker → provision → login flow E2E-testable on one box; additive field is backwards-safe for consumers. |
| D3 | Offboarding the sole tenant is **refused** with `sa_cannot_offboard_sole_tenant` (409) | Run the normal 72-hour deletion flow | Offboarding blog 1 would delete the only site. Explicit refusal is the safe demo behavior. |
| D4 | On single-site activation, seed `sa_tenant_meta.tier = 'enterprise'` (filter `sa_single_site_tier`) and `SA_Toolkit_Manager::ensure_defaults( $tier )` | Leave meta unset; seed starter | The Pro adapter falls back to tier `starter` without meta → 29/30 toolkit toggles 403 in the demo. Enterprise shows the full product; the filter allows demos of lower tiers. |
| D5 | Capability seam: on single-site the platform filters `wp_mcp_ai_pro_schedule_rest_capability` → `manage_options` | Grant `sa_manage_workspace` to the `administrator` role on activation | Pro defaults to `sa_manage_workspace` when the platform is active, which site admins don't hold → every SPA REST call 403s. Mutating core roles on a user's site is invasive; the documented filter seam is the intended hook. |
| D6 | Usage heartbeat early-returns when `SA_SAAS_API_KEY` is undefined (both modes) | Skip cron scheduling on single-site | Fixes the latent empty-key POST that fires today on any install without the key; keeps cron registration uniform and side-effect-free. |
| D7 | Activation gate (G1) removed; replaced by single-site boot logic + a dismissible `notice-info` | Keep the `wp_die` | See §2.2. The notice tells admins what mode they're in instead of refusing to run. |
| D8 | `SA_Cross_Tenant_Security` unchanged (already no-ops on single-site) | — | Its `init()` returns early on `! is_multisite()`. Nothing to do. |

## 4. Behavior Contract (single-site semantics, exact)

All changes gated on `! is_multisite()`. Multisite paths are byte-for-byte
unchanged.

### 4.1 Activation (`SA_Plugin::on_activate`)

- Never deactivates, never dies. On single-site:
  1. Seed `sa_tenant_meta` (tier from `apply_filters( 'sa_single_site_tier', 'enterprise' )`, `stripe_customer_id => ''`, `created_at`, `provisioned_version`) — **only if absent**.
  2. `SA_Toolkit_Manager::ensure_defaults( $tier )` (no-op when settings exist).
  3. `flush_rewrite_rules()` as today.

### 4.2 Admin notice (`maybe_render_multisite_notice`)

- Multisite: no notice (as today).
- Single-site, `manage_options` user: dismissible `notice-info` —
  "Schedule Anything Platform is running in single-site mode. This site is
  the sole tenant; tenant provisioning is limited to this workspace."
  Stored as a one-time notice (option `sa_single_site_notice_dismissed`).
- The error copy "requires WordPress Multisite" is deleted.

### 4.3 Provisioner (`SA_Multisite_Provisioner`)

| Method | Single-site behavior |
|---|---|
| `provision()` | Validates required fields + tier exactly as today, then returns the main site as tenant: `blog_id` = current blog, `site_url` = `home_url()`, `subdomain` = requested slug (normalized), `login_token`/`login_url` via the existing `sa_otl_*` transient (works single-site), `admin_user_id` from the provided `admin_email` (create with `wp_create_user` if missing; `wpmu_create_user`/`add_user_to_blog` branches are multisite-only), `installed_presets` seeded for the requested tier, plus `single_site: true`. Idempotent: replaying updates tier/meta, never duplicates. |
| `offboard()` | Returns `WP_Error( 'sa_cannot_offboard_sole_tenant', …, 409 )` for the main blog; other IDs → `sa_blog_not_found` as today. |
| `generate_subdomain()` | Not called on the single-site path. Its uniqueness loop calls `get_blog_id_from_url()` (a multisite-only function) — a latent fatal if invoked on single-site. Phase 2 wraps the loop in an `is_multisite()` guard so the pure helpers remain unit-testable in the standard (single-site) suite. |
| `get_network_domain()` | Unchanged — `get_network()` exists on single-site. |

### 4.4 REST controller (`SA_REST_Controller`)

- `healthz`: already reports `multisite: false, blog_count: 1` — no change.
- `GET /tenants/lookup?slug=` on single-site: matches the slug against the
  site's own host label (from `home_url()`); match → the main tenant record;
  no match → `sa_tenant_not_found` 404 (never fabricates tenants).
- `GET /tenants/{id}/status`: blog 1 → status built from main-site options
  (same shape); other IDs → 404.
- Auth is **unchanged**: provision/offboard/lookup/status stay
  API-key-gated (`X-SaaS-API-Key`). Single-site mode does not relax
  platform auth.

### 4.5 Capability seam (platform side)

```php
add_filter( 'wp_mcp_ai_pro_schedule_rest_capability', function ( $cap ) {
    return is_multisite() ? $cap : 'manage_options';
} );
```

Pro keeps its current default logic untouched; the platform overrides the
seam only on single-site.

### 4.6 Usage tracker (`SA_Usage_Tracker::send_heartbeat`)

```php
if ( ! defined( 'SA_SAAS_API_KEY' ) || ! SA_SAAS_API_KEY ) {
    return false; // No worker configured — nothing to report.
}
```

Applies in both modes (fixes the current empty-key POST).

## 5. Implementation Plan (phased, each independently shippable)

### Phase 1 — Mode helper + activation + notice (`class-sa-plugin.php`)

- Add `SA_Plugin::is_single_site_mode()` → `! is_multisite()`.
- Rework `on_activate()` per §4.1; delete the `wp_die`/`deactivate_plugins`.
- Replace the error notice with the dismissible single-site info notice (§4.2).
- Version bump `0.1.0` → `0.1.1` in the main file + `SA_PLATFORM_VERSION`.

### Phase 2 — Provisioner sole-tenant semantics (`class-sa-multisite-provisioner.php`)

- Branch `provision()`/`offboard()` per §4.3; add `single_site` to the result.
- Guard `generate_subdomain()`'s uniqueness loop with `is_multisite()`.
- REST `lookup`/`status` single-site branches (§4.4).

### Phase 3 — Capability seam + heartbeat gate

- Platform filters `wp_mcp_ai_pro_schedule_rest_capability` (§4.5).
- `SA_Usage_Tracker::send_heartbeat()` key gate (§4.6).

### Phase 4 — Tests + docs

- New `addons/schedule-anything-platform/tests/test-single-site-mode.php`
  (runs in the standard single-site PHPUnit suite — this *is* the natural
  environment for these paths):
  - `test_provision_returns_sole_tenant_contract` — single-site `provision()`
    returns blog 1, `single_site: true`, seeds tier + toolkit defaults;
    idempotent replay.
  - `test_offboard_refuses_sole_tenant` — 409 `sa_cannot_offboard_sole_tenant`.
  - `test_generate_subdomain_safe_on_single_site` — no fatal (guards the
    multisite-only function call).
  - `test_lookup_matches_own_host_only` — own slug → tenant; other slug → 404.
  - `test_activation_does_not_die_and_seeds_tier` — `on_activate()` on
    single-site leaves the plugin active, seeds `sa_tenant_meta`, default
    tier `enterprise`, filter `sa_single_site_tier` overrides.
  - `test_heartbeat_skips_without_api_key` — `send_heartbeat()` returns false
    and makes zero HTTP requests without `SA_SAAS_API_KEY`.
  - `test_capability_seam_returns_manage_options_on_single_site`.
- Update `addons/schedule-anything-platform/README.md` requirements
  ("Multisite required **for the SaaS deployment**; single-site installs run
  in sole-tenant demo mode") and the §4 surface notes.
- `CHANGELOG.md` entry under Unreleased.
- Update the `mcp-ai-schedule-anything-ops` skill with the single-site mode
  contract.

### Phase 5 — Release bookkeeping

- Rebuild the platform zip (`bin/build-addon-zips.sh` step 13 — unchanged).
- E2E smoke on a plain single-site WP: activate base + Pro + platform →
  notice appears once → SPA nonce → `/mcp-ai-pro/v1/toolkits` non-empty →
  toggle a toolkit → install a preset → provision via REST with a test API
  key returns the sole tenant.
- Regression smoke on the `sa-e2e` multisite stack: provisioning, offboarding,
  and cross-tenant security behave exactly as before.

## 6. Testing Strategy

- **PHPUnit (standard suite, single-site):** the Phase 4 file plus the
  existing `test-tenant-provisioning.php` / `test-tenant-isolation.php` must
  pass unchanged (the latter's multisite-specific cases remain multisite-only).
- **phpcs:** both standards (`WordPress` + `PHPCompatibilityWP` 7.4–8.3) on
  all touched files; new strings translatable, escaped output on the notice.
- **E2E:** Phase 5 smoke (single-site) + sa-e2e regression (multisite).
  Reuse the harness from Proposal 081 — the single-site smoke runs the same
  SPA build against a plain WP container with per-site (not network)
  activation.

## 7. Security Checklist

- [ ] Provision/lookup/offboard/status stay API-key-gated on single-site — no
      auth relaxation (§4.4).
- [ ] Offboarding the sole tenant is impossible via the REST surface (409).
- [ ] `lookup` on single-site never returns a fabricated tenant for unknown
      slugs (404).
- [ ] Single-site seeding never overwrites existing `wp_mcp_ai_settings` /
      `sa_tenant_meta` (idempotent, §2.2).
- [ ] New error codes are namespaced (`sa_*`) and messages pass through
      `__()`/`esc_html__()` with translators comments where needed.
- [ ] `wp_create_user` fallback path sanitizes email/name inputs (two-gate
      rule as elsewhere in the addon).
- [ ] Heartbeat gate removes the empty-key egress POST (defense-in-depth:
      no third-party calls without explicit worker configuration).

## 8. Risks & Open Questions

- **Silent behavior change for existing single-site activations** — anyone
  who previously saw the refusal now gets demo mode. Mitigation: they
  activated a proprietary addon deliberately; the one-time info notice
  states the mode explicitly.
- **Enterprise seeding on production single-sites** — enables all 30 toolkit
  flags by default. Mitigation: `sa_single_site_tier` filter; settings are
  only seeded when `wp_mcp_ai_settings` is empty.
- **Open question:** should `sa_tenant_meta` seeding also run when the
  plugin is *already active* on a single-site that predates this change
  (upgrade path)? Proposed: yes — seed on `plugins_loaded` bootstrap when
  single-site and meta is absent (idempotent), so existing installs converge
  without a reactivation.
  **Resolved:** implemented as the `init` priority-6 hook
  (`SA_Plugin::seed_single_site_defaults()`), idempotent.
- **Open question:** the SPA's `TenantContext` derives the tenant from the
  subdomain and is a no-op on the main domain (tier falls back to
  `unknown`). The SPA is unaffected functionally (REST decides entitlements),
  but a cosmetic tenant-name fallback (site title) could be a follow-up SPA
  tweak — out of scope here.

## 9. Deployment Checklist (production readiness, not built in this proposal)

- [ ] SaaS production stays on Multisite; nothing in this proposal changes
      that deployment.
- [ ] Cloud Worker demo runbook: single-site install + `SA_SAAS_API_KEY` +
      Stripe test clock → provision returns the sole tenant → OTL login works.
- [ ] Complete-bundle build ships platform 0.1.1+ (no activation refusal).
- [ ] Docs Hub / sales-demo wiki updated with the single-site demo path.

## 10. References

- [Proposal 081 — Backend API Completion](../../proposals/081-schedule-anything-api-completion-proposal.md)
- [Schedule Anything SaaS Implementation Plan](../../plans/schedule-anything-saas-implementation.md) (Decision D1 — Multisite tenancy)
- PR #7132 — Drop slug-specific `Requires Plugins` header
- `addons/schedule-anything-platform/` — `class-sa-plugin.php`, `class-sa-multisite-provisioner.php`, `class-sa-usage-tracker.php`, `rest/class-sa-rest-controller.php`
- `addons/pro/includes/schedule-anything/class-wp-mcp-ai-pro-sa-toolkit-adapter.php` — tier resolution + entitlement checks
- `.agents/skills/mcp-ai-schedule-anything-ops/SKILL.md` — operational guide (to be updated in Phase 4)
