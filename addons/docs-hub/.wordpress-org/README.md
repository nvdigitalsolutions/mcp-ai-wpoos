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
| `assets/screenshot-5.png` | Settings — Save button + "Get NV oOS Complete" upsell card |

### Playground blueprints

`blueprints/` (dev-only, excluded from the ZIP) holds the WordPress Playground
demo:

| File | Purpose |
|---|---|
| `../blueprints/seed-content.php` | Demo seed — 6 Markdown files + the `[nvoos_docs]` page |
| `../blueprints/demo.json` | Standalone demo link (installs the newest `build/nvoos-docs-hub-v*.zip`) |
| `blueprints/blueprint.json` | **wp.org Live Preview** — no self-install (the preview loader pre-installs the plugin). Mirrors SVN `assets/blueprints/blueprint.json`; the "Live Preview" button on the plugin page appears automatically once it is uploaded |

Regenerate both JSONs after seed edits:
`php bin/generate-docs-hub-blueprint.php`.

Source masters (committed, alongside the assets) live in `source/`:

| File | Source artwork |
|---|---|
| `source/nvoos-docs-hub-icon-master-1024x1024.png` | `nvoos-wordmark-lockup-20260825-163201-02.webp` (1024×1024 wordmark lockup) |
| `source/nvoos-docs-hub-banner-gemini-v1-1584x672.jpg` | Gemini-generated banner (gemini-3.1-flash-image, raw provider output, 1584×672 ≈ 21:9) |
| `source/nvoos-docs-hub-banner-master-1376x768.png` | `docs-hub-banner-option2-stylish-a-mdleft-20261006-edited-20261006-203055.jpg` (1376×768 banner artwork, text at middle-left — superseded as the banner source, kept as the artwork master) |

The layout follows `plugins/nvoos-content-graph/.wordpress-org/`.

## Capturing screenshots

Screenshots are captured from a running QA site at 1440×900 viewport with the
plugin active and a configured remote repository (so the browser shows real
docs). The capture script is `bin/capture-nvoos-docs-hub-screenshots.js`
(Playwright, modeled on `bin/capture-nvoos-content-graph-screenshots.js`).
The five PNGs below are already generated and committed here.

Capture workflow (if the assets ever need refreshing):

1. Spin up the QA stack (`docker compose up -d`, site on http://localhost:8000,
   admin `admin` / `password`).
2. Activate NV oOS Docs Hub, configure a public GitHub repository under
   **Settings → NV oOS Docs Hub**, and run **Rebuild Documentation Index**
   (`wp nvoos-docs rebuild --sync`).
3. Publish a page containing the `[nvoos_docs]` shortcode (the script defaults
   to `/docs-hub-test/`; override via `DOCS_PAGE_PATH`).
4. Run `node bin/capture-nvoos-docs-hub-screenshots.js` and review the five
   PNGs (the script logs in as admin for the settings shots and captures the
   frontend as a guest; it widens the theme's content CSS so the three-pane
   embed renders at listing width).

Icons and banners are exported from the `source/` masters:

- **Icons**: downscale the 1024×1024 icon master (Lanczos) to
  `assets/icon-256x256.png` and `assets/icon-128x128.png`.
- **Banners**: the current banners derive from the Gemini-generated master
  `source/nvoos-docs-hub-banner-gemini-v1-1584x672.jpg` (~21:9). It is fitted
  to the wp.org banner ratio (1544:500 ≈ 3.088:1) **without cropping**: scale
  to 500px height, center it, and blur-extend the side margins to the full
  1544px width (edge strips blurred and faded into the canvas edge color;
  the left strip is taken below the top-left badge so it never smears it).
  The 772×250 banner is a Lanczos downscale of the 1544×500 result.

  An earlier version center-cropped the 1376×768 artwork master (crop box
  `0,161,1376,607`) — replaced because the crop trimmed the top-left badge
  and bottom footer.

  Regeneration recipe (via the NV oOS console assistant's
  `generate_gemini_image_validated` tool): prompt for an ultra-wide ~21:9
  banner with all copy (`NV oOS Docs Hub` headline, subtitle
  `Markdown documentation, beautifully rendered in WordPress`, trust line
  `Free · No API keys · Runs 100% on your server`, `NV` badge top-left, flat
  ivory-cream background, muted teal/navy document-node illustration on the
  right), then run the no-crop fit described above.

PNG assets in this folder are locally gitignored via `.git/info/exclude`
(`*.png`) — stage them with `git add -f`.

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
svn ci -m "Add plugin listing assets (icons, banners, screenshots) for v0.5.2"

# The plugin code itself goes into trunk/ (built from the distribution ZIP):
# unzip nvoos-docs-hub-v0.5.2.zip -d trunk/
```

Note: `svn` and wp.org SVN credentials are required — these are never
committed to the repository.
