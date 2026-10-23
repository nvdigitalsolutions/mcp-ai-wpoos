# Schedule Anything Platform

**WordPress companion plugin** for the Schedule Anything SaaS — handles tenant provisioning, cross-tenant security, toolkit management, usage tracking, and platform REST endpoints.

## Requirements

- WordPress 6.0+ — Multisite is required for the SaaS deployment; single-site installs run in sole-tenant demo mode (see below)
- PHP 7.4+
- NV oOS Base plugin (active)
- NV oOS Pro addon (active)
- Cloud Worker (for billing + usage heartbeat)

## Installation

1. Upload to `/wp-content/plugins/`.
2. SaaS deployment: network-activate through the WordPress admin.
   Single-site: activate normally — the plugin runs in sole-tenant mode.
3. Configure `SA_SAAS_API_KEY` in `wp-config.php` for Cloud Worker communication.

## Single-Site Mode (Sole Tenant)

On a non-multisite install the platform runs in **sole-tenant mode**: the
single blog is the tenant (Proposal 082). Activation succeeds normally and
seeds the tenant meta (`sa_tenant_meta`) and toolkit defaults with the
`enterprise` tier (filter `sa_single_site_tier` to demo another tier).

- The SPA surface is usable: the REST capability seam resolves to
  `manage_options`, so site admins can manage presets and toolkits.
- `POST /tenants/provision` is an idempotent self-registration — it returns
  the current site as the tenant (`single_site: true`) instead of creating
  a subsite.
- `POST /tenants/{id}/offboard` for the sole tenant is refused with
  `409 sa_cannot_offboard_sole_tenant` (offboarding would delete the only
  site).
- `GET /tenants/lookup` matches the provisioned subdomain or the site's
  host label only.
- The usage heartbeat is a no-op unless `SA_SAAS_API_KEY` is defined.

## REST API

All state-changing routes require `X-SaaS-API-Key` header.

| Method | Route | Purpose | Auth |
|---|---|---|---|
| GET | `/nvoos-saas/v1/healthz` | Platform health | API Key |
| GET | `/nvoos-saas/v1/auth/nonce` | SPA nonce | Public |
| POST | `/nvoos-saas/v1/tenants/provision` | Create tenant workspace | API Key |
| GET | `/nvoos-saas/v1/tenants/lookup?slug=` | Lookup tenant by subdomain | API Key |
| GET | `/nvoos-saas/v1/tenants/{id}/status` | Tenant status | API Key |
| POST | `/nvoos-saas/v1/tenants/{id}/offboard` | Offboard tenant | API Key |

## Architecture

```
schedule-anything-platform/
├── schedule-anything-platform.php    # Plugin entry + bootstrap
├── uninstall.php                    # Cleanup handler
├── includes/
│   ├── class-sa-plugin.php                      # Core singleton
│   ├── class-sa-cross-tenant-security.php        # MU-plugin session validator
│   ├── class-sa-multisite-provisioner.php        # wpmu_create_blog() + seeding
│   ├── class-sa-toolkit-manager.php              # Per-tenant feature flags
│   ├── class-sa-usage-tracker.php                # 15-min heartbeat
│   └── rest/
│       └── class-sa-rest-controller.php          # Platform REST endpoints
└── tests/
```

## Tests

```bash
vendor/bin/phpunit addons/schedule-anything-platform/tests/
```

## License

Proprietary — © 2025-2026 NV Digital Solutions, all rights reserved.
