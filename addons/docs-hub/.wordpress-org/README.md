# WordPress.org Assets (SVN)

This folder holds the plugin listing assets for WordPress.org. It is **not**
shipped inside the plugin ZIP — it maps to the `assets/` directory of the
plugin's WordPress.org SVN repository (excluded from distribution builds via
`.distignore`, `bin/build-addon-zips.sh`, and `build-spa-addons.yml`).

## Expected files

| File | Purpose |
|---|---|
| `assets/icon-128x128.png` | Plugin icon (search results / plugin cards) |
| `assets/icon-256x256.png` | Plugin icon, HiDPI (Retina) |
| `assets/banner-772x250.png` | Listing banner |
| `assets/banner-1544x500.png` | Listing banner, HiDPI (Retina) |
| `assets/screenshot-1.png` | Settings — documentation index status + rebuild panel |
| `assets/screenshot-2.png` | Settings — remote repositories + file/folder tree picker |
| `assets/screenshot-3.png` | Frontend `[nvoos_docs]` embed — sidebar, content, TOC |
| `assets/screenshot-4.png` | Frontend `[nvoos_docs]` embed — full-text search |

Source masters live in `source/` once artwork is produced (1024×1024 icon
master, 1344×768 banner master, 1440×900 screenshot captures), following the
`plugins/nvoos-content-graph/.wordpress-org/` layout.

## Capturing screenshots

Screenshots are captured from a running QA site at 1440×900 viewport with the
plugin active and a configured remote repository (so the browser shows real
docs). The capture script is `bin/capture-nvoos-docs-hub-screenshots.js`
(Playwright, modeled on `bin/capture-nvoos-content-graph-screenshots.js`).
The four PNGs below are already generated and committed here.

Capture workflow (if the assets ever need refreshing):

1. Spin up the QA stack (`docker compose up -d`, site on http://localhost:8000,
   admin `admin` / `password`).
2. Activate NV oOS Docs Hub, configure a public GitHub repository under
   **Settings → NV oOS Docs Hub**, and run **Rebuild Documentation Index**
   (`wp nvoos-docs rebuild --sync`).
3. Publish a page containing the `[nvoos_docs]` shortcode (the script defaults
   to `/docs-hub-test/`; override via `DOCS_PAGE_PATH`).
4. Run `node bin/capture-nvoos-docs-hub-screenshots.js` and review the four
   PNGs (the script logs in as admin for the settings shots and captures the
   frontend as a guest; it widens the theme's content CSS so the three-pane
   embed renders at listing width).

Icons (`icon-128x128.png`, `icon-256x256.png`) and banners
(`banner-772x250.png`, `banner-1544x500.png`) are manual artwork — downscale
1024×1024 icon and 1344×768 banner masters into `source/` and export the
listed targets.

Screenshot `alt` text used in `readme.txt` (the `== Screenshots ==` section is
added to `readme.txt` when the PNGs land in SVN `assets/`).

## Uploading to WordPress.org SVN

The slug must be approved/reserved on WordPress.org first. Then, with your
WordPress.org SVN credentials:

```bash
svn co https://plugins.svn.wordpress.org/nvoos-docs-hub /tmp/nvoos-docs-hub-svn
cd /tmp/nvoos-docs-hub-svn

# Replace the assets directory and commit.
rm -rf assets
cp -r /path/to/mcp-ai-wpoos/addons/docs-hub/.wordpress-org/assets assets
svn add --force assets
svn ci -m "Add plugin listing assets (icons, banners, screenshots) for v0.4.3"

# The plugin code itself goes into trunk/ (built from the distribution ZIP):
# unzip nvoos-docs-hub-v0.4.3.zip -d trunk/
```

Note: `svn` and wp.org SVN credentials are required — these are never
committed to the repository.
