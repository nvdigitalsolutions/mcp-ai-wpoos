# WordPress.org Assets (SVN)

This folder holds the plugin listing assets for WordPress.org. It is **not**
shipped inside the plugin ZIP — it maps to the `assets/` directory of the
plugin's WordPress.org SVN repository.

## Files

| File | Purpose |
|---|---|
| `assets/icon-128x128.png` | Plugin icon (search results / plugin cards) |
| `assets/icon-256x256.png` | Plugin icon, HiDPI (Retina) |
| `assets/banner-772x250.png` | Listing banner |
| `assets/banner-1544x500.png` | Listing banner, HiDPI (Retina) |
| `assets/screenshot-1.png` | Graph Explorer |
| `assets/screenshot-2.png` | Settings — build schedule, auto-rebuild, display options |
| `assets/screenshot-3.png` | Remote Sources |
| `assets/screenshot-4.png` | Sources (post types / content types) |
| `assets/screenshot-5.png` | Frontend `[nvoos_graph]` embed |

Screenshots are captured from a running QA site (Playwright) at 1440×900
viewport. Regenerate with `bin/capture-nvoos-content-graph-screenshots.js`
(requires the QA WordPress container running on localhost:8000 with the
plugin active and a populated graph).

Source files for the icon and banner live in `source/`:

| File | Purpose |
|---|---|
| `source/NVOOS-CONTENT-GRAPH-v2.png` | 1024×1024 icon master (RGBA) |
| `source/nvoos-banner-master-1344x768.png` | 1344×768 banner master |

The banner targets are produced by center-cropping the master to a
1344×435 band (the artwork occupies the middle of the canvas) and resizing
to 772×250 / 1544×500. The icon targets are straight 128/256 downscales.

## Uploading to WordPress.org SVN

The slug must be approved/reserved on WordPress.org first. Then, with your
WordPress.org SVN credentials:

```bash
svn co https://plugins.svn.wordpress.org/nvoos-content-graph /tmp/nvoos-content-graph-svn
cd /tmp/nvoos-content-graph-svn

# Replace the assets directory and commit.
rm -rf assets
cp -r /path/to/mcp-ai-wpoos/plugins/nvoos-content-graph/.wordpress-org/assets assets
svn add --force assets
svn ci -m "Add plugin listing assets (icons, banners, screenshots) for v1.0.3"

# The plugin code itself goes into trunk/ (built from the distribution ZIP):
# cp nvoos-content-graph-v1.0.3.zip ... unzip into trunk/
```

Note: `svn` and wp.org SVN credentials are required — these are never
committed to the repository.
