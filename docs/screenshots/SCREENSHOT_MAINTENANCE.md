# Screenshot Maintenance Plan

How to keep the screenshot documentation current as the plugin evolves.

## Quick Reference

| Task | Command |
|------|---------|
| Check coverage | `node bin/check-screenshot-coverage.js` |
| Recapture all | `docker compose up -d && node bin/capture-admin-screenshots.js` |
| Chat screenshots | Needs `OPENAI_API_KEY` + `bin/capture-chat-screenshots.sh` |

## How It Works

1. **Inventory** (`docs/screenshots/INVENTORY.md`) — the canonical list of every page
   slug mapped to its expected screenshot file. Update this when new pages are
   added.

2. **Capture script** (`bin/capture-admin-screenshots.js`) — Playwright automation
   that visits every page and takes full-page PNGs. Handles both base (30s timeout)
   and Pro (60s + 3s wait) pages.

3. **Coverage checker** (`bin/check-screenshot-coverage.js`) — compares the
   inventory against what's on disk. Reports missing screenshots, orphan files,
   and section-level coverage. Returns exit code 1 if anything is missing.

## When to Update Screenshots

### After adding a new admin page or settings tab

1. Add the new page slug + expected filename to `INVENTORY.md`
2. Recapture with the full script (Docker + fresh DB)
3. Verify with `node bin/check-screenshot-coverage.js`

### After a major UI redesign

1. Delete old screenshots in the affected category
2. Recapture with fresh Docker environment
3. Run the coverage checker

### Before a release

```bash
# Full refresh pipeline
docker compose down -v                        # Wipe everything
docker compose up -d                          # Fresh start
docker compose run --rm wp-cli wp core install --url=http://localhost:8000 \
  --title="NV oOS Test" --admin_user=admin --admin_password=password \
  --admin_email=admin@example.com
docker compose run --rm wp-cli wp plugin activate mcp-ai-wpoos

# Enable Pro toolkits
docker compose run --rm wp-cli wp eval '
  $s = get_option("wp_mcp_ai_settings", []);
  foreach (["enable_crm_toolkit","enable_ecommerce_toolkit","enable_analytics_toolkit",
            "enable_video_production_toolkit","enable_financial_planner_toolkit",
            "enable_image_production_toolkit","enable_ai_tool_builder_toolkit",
            "enable_multilingual_toolkit","enable_dj_management_toolkit",
            "enable_social_media_toolkit"] as $k) { $s[$k] = true; }
  update_option("wp_mcp_ai_settings", $s);
'

# Seed sample data (companies, deals, leads)
docker compose run --rm wp-cli wp eval '
  for ($i = 1; $i <= 3; $i++) {
    wp_insert_post(["post_type"=>"mcp_ai_company","post_title"=>"Acme Corp $i","post_status"=>"publish"]);
    $d = wp_insert_post(["post_type"=>"mcp_ai_deal","post_title"=>"Big Deal $i","post_status"=>"publish"]);
    update_post_meta($d, "_deal_stage", "negotiation");
    update_post_meta($d, "_deal_value", 50000 * $i);
    wp_insert_post(["post_type"=>"mcp_ai_lead","post_title"=>"Lead $i - John Doe","post_status"=>"publish"]);
  }
'

# Capture all screenshots
npm install playwright && npx playwright install chromium
node bin/capture-admin-screenshots.js

# Verify
node bin/check-screenshot-coverage.js
```

## File Organization

```
docs/screenshots/
├── INVENTORY.md              # Canonical page→screenshot map
├── SCREENSHOT_PROGRESS.md    # High-level status summary
├── SCREENSHOT_MAINTENANCE.md # This file
├── README.md                 # Overview and contribution guide
├── admin/                    # Base plugin admin pages (~94 PNGs)
├── dashboard/                # Pro dashboard & toolkit pages (~11 PNGs)
├── frontend/                 # Frontend/shortcode views (3 PNGs)
└── chat/                     # Chat interface (0 PNGs, needs API key)

bin/
├── capture-admin-screenshots.js  # Main capture script
├── capture-chat-screenshots.sh   # Chat setup (needs API key)
├── playwright-capture-screenshots.js # Chat automation (needs API key)
├── check-screenshot-coverage.js  # Coverage verification
└── test-screenshot-tools.sh      # Environment validation
```

## Adding to CI

Add a non-blocking check to `.github/workflows/`:

```yaml
screenshot-coverage:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4
    - run: node bin/check-screenshot-coverage.js
      continue-on-error: true  # Don't block merges, just report
```

## Responsibilities

| Role | Owns |
|------|------|
| **Release engineer** | Re-runs full capture pipeline before each release |
| **Feature developer** | Adds new page slugs to `INVENTORY.md` when adding admin pages |
| **Docs maintainer** | Reviews coverage report weekly, prunes orphans |
