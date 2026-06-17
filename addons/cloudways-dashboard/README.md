# NV oOS Cloudways Dashboard

**SaaS operator dashboard** for managing Cloudways servers, WordPress sites, and NV oOS toolkits — powered by a Velzon-themed React SPA.

- **WP-Admin** → oOS Cloudways menu
- **Shortcode** → `[nvoos_cloudways_dashboard]`
- **Block** → `nvoos/cloudways-dashboard`
- **REST** → `/wp-json/nvoos-cloudways-dashboard/v1/`

## Features

### Phase 1 — Core Dashboard (✅ v0.1.0)
- Dashboard overview with server/site/toolkit aggregate stats
- Server list with status badges, IP, cloud, region, resources
- Server detail with apps table and RAM usage gauge
- Sites (apps) list across all servers with Create Site wizard
- Toolkit catalog (reads from toolkit-shell manifest registry)
- Settings page for Cloudways API credentials
- Collapsible sidebar navigation (Velzon dark sidebar style)
- WordPress admin menu integration + admin notices

### Phase 2 — Provisioning Pipeline (✅ v0.1.0)
- **Async provisioning job** via Action Scheduler (30s poll, 60 retry max)
- Live provisioning progress in Create Site wizard (Step 4)
- Real-time status polling on Site Detail page
- Auto-records plugin install intents once site is running
- Auto-records toolkit application intents post-provisioning
- `nvoos_cloudways_dashboard_app_ready` action hook

### Phase 3 — Toolkit Management (✅ v0.1.0)
- Apply/remove toolkits on existing sites
- Per-site toolkit dashboard (`/sites/{id}/toolkits`)
- Global toolkit usage summary across all sites
- Duplicate detection (already-active toolkits)
- Assistant pre-configuration intent recording
- Action hooks: `nvoos_cloudways_dashboard_toolkit_applied`, `_removed`

### Phase 4 — Polish & Release (✅ v0.1.0)
- React Error Boundary with user-friendly fallback
- Skeleton loading animations (shimmer effect)
- `focus-visible` outlines for keyboard navigation
- Comprehensive uninstall cleanup (5 option prefixes + Action Scheduler)
- PHPUnit tests for REST permissions + Toolkit Manager (26 test cases)
- Masked credentials display + form validation

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS base plugin (active)
- NV oOS Pro addon (active — provides Cloudways API client)
- Node.js 18.17+ (build-time only)

## Installation

1. Upload the `cloudways-dashboard` folder to `/wp-content/plugins/`.
2. Activate through the WordPress admin.
3. Navigate to **NV oOS → oOS Cloudways** to open the dashboard.
4. Configure Cloudways API credentials under **Settings**.

## Build

```bash
cd addons/cloudways-dashboard
npm ci               # Install dependencies
npm run build        # Production build (minified)
npm run build:dev    # Development build with sourcemaps
npm run watch        # Watch mode for development
npm run typecheck    # TypeScript type-checking
npm test             # Run PHPUnit tests (requires composer install --dev)
```

## REST API Reference

All endpoints require `manage_options` capability + WordPress REST nonce.

### Health & Summary
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/health` | Addon version + Cloudways connection status |
| GET | `/summary` | Dashboard aggregate counts (servers, apps, toolkits) |

### Servers
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/servers` | List Cloudways servers |
| GET | `/servers/{id}` | Server detail + health |
| GET | `/servers/{id}/apps` | Apps on a server |
| GET | `/projects` | List Cloudways projects |

### Apps / Sites
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/apps` | List all apps across servers |
| POST | `/apps` | Create a new WordPress app |
| GET | `/apps/{id}` | App detail + pending toolkits |
| GET | `/apps/{id}/provisioning` | Real-time provisioning status |

### Toolkits
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/toolkits` | List available toolkits (manifest registry) |
| GET | `/toolkits/summary` | Global toolkit usage across all sites |
| GET | `/apps/{id}/toolkits` | List toolkits on a specific site |
| POST | `/apps/{id}/toolkits` | Apply toolkits to a site |
| PUT | `/apps/{id}/toolkits` | Remove toolkits from a site |

### Settings
| Method | Route | Description |
|--------|-------|-------------|
| GET | `/settings` | Cloudways credentials status (masked) |
| PUT | `/settings` | Update Cloudways credentials |

## Architecture

```
addons/cloudways-dashboard/
├── nvoos-cloudways-dashboard.php       ← Plugin entry + constants
├── package.json                        ← npm deps
├── esbuild.config.cjs                  ← esbuild IIFE bundle config
├── uninstall.php                       ← Full cleanup (5 prefixes + AS)
├── includes/
│   ├── class-nvoos-cloudways-dashboard-plugin.php        ← Core singleton
│   ├── class-nvoos-cloudways-dashboard-provisioning-job.php ← Async poller
│   ├── class-nvoos-cloudways-dashboard-toolkit-manager.php  ← Curd service
│   ├── rest/class-nvoos-cloudways-dashboard-rest.php     ← 16 endpoints
│   ├── shortcode/class-nvoos-cloudways-dashboard-shortcode.php
│   └── block/
│       ├── class-nvoos-cloudways-dashboard-block.php
│       └── block.json
├── src/                                 ← React TypeScript SPA
│   ├── index.tsx                        ← DOM mount + auto-mount
│   ├── App.tsx                          ← HashRouter + ErrorBoundary
│   ├── contexts/AuthContext.tsx         ← WP nonce + apiFetch
│   ├── hooks/
│   │   ├── useApi.ts                    ← GET / POST hooks
│   │   └── usePollingApi.ts             ← Periodic polling hook
│   ├── components/
│   │   ├── Layout.tsx                   ← Sidebar + topbar
│   │   ├── ErrorBoundary.tsx            ← Error fallback
│   │   └── Skeleton.tsx                 ← Loading placeholders
│   ├── pages/                           ← 9 SPA pages
│   │   ├── DashboardPage.tsx
│   │   ├── ServersPage.tsx
│   │   ├── ServerDetailPage.tsx
│   │   ├── SitesPage.tsx
│   │   ├── CreateSiteWizard.tsx         ← 4-step wizard + provisioning
│   │   ├── SiteDetailPage.tsx           ← Live provisioning polling
│   │   ├── SiteToolkitsPage.tsx         ← Toolkit CRUD on site
│   │   ├── ToolkitsPage.tsx
│   │   └── SettingsPage.tsx
│   └── styles/dashboard.css            ← Velzon design system
├── tests/                               ← PHPUnit tests
│   ├── test-cloudways-dashboard-rest.php          ← 17 REST tests
│   └── test-cloudways-dashboard-toolkit-manager.php ← 9 TK tests
└── assets/dist/                         ← Built IIFE + CSS (committed)
    ├── cloudways-dashboard.js           (~241 KB minified)
    └── cloudways-dashboard.css          (~11 KB)
```

## Provisioning Flow

```
POST /apps (create WordPress app)
  → Cloudways API: create app
  → NV_oOS_CloudwaysDashboard_Provisioning_Job::enqueue()
  → Action Scheduler hooks registered

Every 30s (max 60 attempts):
  → Poll Cloudways GET /app/{id}
  → Status "provisioning" → reschedule
  → Status "running" →
      - Record plugin install intent (nvOS base plugin)
      - Record toolkit application intents
      - Mark as "ready"
      - Fire nvoos_cloudways_dashboard_app_ready
```

## Hooks

### Actions
| Hook | Args | Fires when |
|------|------|-----------|
| `nvoos_cloudways_dashboard_app_ready` | `($app_id, $results)` | App provisioning complete |
| `nvoos_cloudways_dashboard_toolkit_applied` | `($app_id, $slug)` | Toolkit applied to site |
| `nvoos_cloudways_dashboard_toolkit_removed` | `($app_id, $slug)` | Toolkit removed from site |

### Filters
| Hook | Args | Purpose |
|------|------|---------|
| `nvoos_cloudways_dashboard_can_render` | `($can, $atts)` | Gate shortcode rendering |
| `nvoos_cloudways_dashboard_toolkits` | `($toolkits)` | Modify toolkit list |
| `nvoos_cloudways_dashboard_app_created` | `($result, $server_id)` | Modify create-app response |
| `nvoos_cloudways_dashboard_assistant_defaults` | `($defaults, $app_id, $slug)` | Override assistant defaults |

## Credits

- **Velzon** by Themesbrand — design inspiration for the dashboard UI
- **React** — Facebook/Meta (MIT)
- **React Router** — Remix Software (MIT)
- **esbuild** — Evan Wallace (MIT)
- **Action Scheduler** — Automattic (GPLv2+)

## License

GPLv3 or later — see [LICENSE](../LICENSE).
