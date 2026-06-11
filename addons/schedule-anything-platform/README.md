# Schedule Anything Platform

**WordPress companion plugin** for the Schedule Anything SaaS — handles tenant provisioning, cross-tenant security, toolkit management, usage tracking, and platform REST endpoints.

## Requirements

- WordPress 6.0+ (Multisite required)
- PHP 7.4+
- NV oOS Base plugin (active)
- NV oOS Pro addon (active)
- Cloud Worker (for billing + usage heartbeat)

## Installation

1. Upload to `/wp-content/plugins/`.
2. Network-activate through the WordPress admin.
3. Configure `SA_SAAS_API_KEY` in `wp-config.php` for Cloud Worker communication.

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
