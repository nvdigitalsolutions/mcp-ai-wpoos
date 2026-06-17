# oOS Engine — Docker Integration Environments

Docker Compose environments for testing the oOS AI orchestration engine
with WordPress (existing), Laravel, and Craft CMS.

## Architecture

```
docker/
├── README.md                        ← this file
├── setup.sh                         ← one-command bootstrap for Laravel/Craft
├── docker-compose.laravel.yml       ← Laravel 11 + MySQL 8 + Redis
├── docker-compose.craft.yml         ← Craft CMS + MySQL 8 + Redis
├── laravel/
│   ├── Dockerfile                   ← PHP 8.2 CLI + extensions
│   ├── entrypoint.sh                ← runtime bootstrap (composer install, migrate, serve)
│   └── app/                         ← Laravel project (auto-created by setup.sh)
└── craft/
    ├── Dockerfile                   ← PHP 8.2 CLI + extensions (gd, imagick)
    ├── entrypoint.sh                ← runtime bootstrap (composer install, craft setup, serve)
    ├── app/                         ← Craft project (auto-created by setup.sh)
    └── app-skeleton/config/
        ├── oos.php                  ← Craft oOS config template
        └── app.php                  ← Craft app.php to register oOS module
```

## Quick Start

### Prerequisites

- Docker 24+ with `docker compose` plugin
- Bash (Git Bash, WSL, or macOS/Linux terminal)

### Laravel

```bash
bash docker/setup.sh laravel
# → http://localhost:8001
```

### Craft CMS

```bash
bash docker/setup.sh craft
# → http://localhost:8002
# Control Panel: http://localhost:8002/admin (admin / password)
```

### Useful commands

```bash
# Shell into container
docker compose -f docker/docker-compose.laravel.yml exec app bash
docker compose -f docker/docker-compose.craft.yml exec app bash

# Run artisan / craft CLI
docker compose -f docker/docker-compose.laravel.yml exec app php artisan list
docker compose -f docker/docker-compose.craft.yml exec app php craft

# Watch logs
docker compose -f docker/docker-compose.laravel.yml logs -f app
docker compose -f docker/docker-compose.craft.yml logs -f app

# Reset environment
bash docker/setup.sh laravel --reset
bash docker/setup.sh craft --reset

# Tear down
docker compose -f docker/docker-compose.laravel.yml down -v
docker compose -f docker/docker-compose.craft.yml down -v
```

## How local packages work

The oOS core and adapter packages live at `lib/core/`, `lib/laravel-adapter/`,
and `lib/craft-adapter/`. The Docker containers mount the whole project at
`/workspace/oos` (read-only). During `composer install`, Composer path
repositories symlink these into `vendor/`. You can edit adapter files on the
host and changes reflect immediately.

## Integration Pattern

```
┌──────────────────────────────────────────────────┐
│  nvoos/core (framework-agnostic)                   │
│  ┌────────────────────────────────────────────┐  │
│  │  ChatOrchestrator                           │  │
│  │  ToolRegistry    ProviderRouter             │  │
│  └─────────────────┬──────────────────────────┘  │
│                    │ 9 domain contracts           │
│                    ▼                              │
│  ErrorFactoryInterface   CacheStoreInterface      │
│  SettingsStoreInterface  EventDispatcherInterface │
│  FileStoreInterface      QueueClientInterface     │
│  AuthProviderInterface   ContentStoreInterface    │
└────────────────────┬─────────────────────────────┘
                     │
         ┌───────────┴───────────┐
         ▼                       ▼
┌───────────────────┐    ┌───────────────────┐
│ Laravel Adapter    │    │ Craft CMS Adapter  │
│ (illuminate/*)     │    │ (craftcms/cms)     │
└───────────────────┘    └───────────────────┘
```
