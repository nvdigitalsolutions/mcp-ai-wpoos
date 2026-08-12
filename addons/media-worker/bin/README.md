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
