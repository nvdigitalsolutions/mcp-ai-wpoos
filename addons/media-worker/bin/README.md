# Media Worker — Test Scripts

## test-endpoints.sh
Tests all sidecar API endpoints directly via curl.

```bash
bash bin/test-endpoints.sh http://localhost:3100
```

## test-wordpress.php
Tests the WordPress → sidecar cascade (requires WordPress bootstrap).

> Monorepo only — assumes the plugin layout under
> `wp-content/plugins/mcp-ai-wpoos/`.

```bash
wsl docker compose exec -T wordpress php /var/www/html/wp-content/plugins/mcp-ai-wpoos/addons/media-worker/bin/test-wordpress.php
```

## probe-wordpress.php
Diagnostic probe proving whether the site routes operations to the connected
Media Worker instead of running the plugin's bundled JS locally. It performs
the authenticated call the admin "Test Connection" button skips, runs real
service classes, and prints a verdict (exit 0 = worker confirmed).

```bash
php probe-wordpress.php                     # auto-detects wp-load.php
php probe-wordpress.php --wp-root=/var/www/html
```

CLI-only — delete it from public-facing hosts after use.
