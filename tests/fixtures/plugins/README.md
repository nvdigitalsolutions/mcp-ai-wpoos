# Test Plugin Fixtures

Place premium/non-wp.org plugin ZIP files here to enable integration
testing against them in CI and local Docker environments.

## Supported plugins

| Plugin | Expected filename pattern | Required for |
|---|---|---|
| JetEngine (Crocoblock) | `jet-engine*.zip` | `get_jetengine_items`, `invoke_jetengine_route`, `list_jetengine_routes` |
| JetSmartFilters (Crocoblock) | `jet-smart-filters*.zip` | Crocoblock DS addon integration tests |
| WP All Export Pro | `wp-all-export*.zip` | Export toolkit tools |

## How it works

The `bin/install-test-plugins.sh` script, when run with the `--premium` flag,
scans this directory for matching ZIP files and installs them via
`wp plugin install <zip> --activate`.

## Adding a new plugin

1. Download the latest ZIP from your account / license portal.
2. Place it in this directory using the expected pattern (e.g. `jet-engine-3.5.0.zip`).
3. Run `bin/install-test-plugins.sh --premium` to install it.

## CI / GitHub Actions

This directory is gitignored by default. For CI, store the ZIP files
as GitHub repository secrets or in a private artifact store, then download
them during the workflow before running `--premium`.

For free plugins available on wp.org, no fixture files are needed —
they are installed directly from the WordPress plugin repository.
