# WordPress.org Plugin Assets

This directory contains assets for the WordPress.org plugin directory. These files are used to display your plugin's branding on wordpress.org/plugins/wp-mcp-ai/.

## Official Brand Assets

**Logo Sources (for creating WordPress.org assets):**
- Favicon/Icon: https://bots.nvdigital.solutions/wp-content/uploads/2025/11/cropped-WPOOS-FAVICON.png
- SVG Logo: https://bots.nvdigital.solutions/wp-content/uploads/2025/11/wpoos-logo.svg
- Product Page: https://nvdigitalsolutions.com/wpoos

## Required Assets

### Banner Images

These appear at the top of your plugin page:

| File | Dimensions | Purpose |
|------|------------|---------|
| `banner-772x250.png` | 772×250 px | Standard banner |
| `banner-1544x500.png` | 1544×500 px | High-DPI (Retina) banner |

**Banner Guidelines:**
- Use PNG or JPG format
- Show your plugin's core functionality
- Include your plugin name/logo
- Use brand colors consistently
- Avoid small text (won't be readable)

### Icon Images

These appear in search results and plugin cards:

| File | Dimensions | Purpose |
|------|------------|---------|
| `icon-128x128.png` | 128×128 px | Standard icon |
| `icon-256x256.png` | 256×256 px | High-DPI (Retina) icon |

**Icon Guidelines:**
- Square aspect ratio
- Clear, recognizable design
- Works at small sizes
- Consistent with banner branding
- Use the WP oOS favicon as the base

### Screenshots

Screenshots are referenced in `readme.txt` and stored here:

| File | Purpose |
|------|---------|
| `screenshot-1.png` | Assistant Editor interface |
| `screenshot-2.png` | Chat Interface |
| `screenshot-3.png` | Settings Dashboard |
| `screenshot-4.png` | Tool Registry |
| `screenshot-5.png` | Profession Templates |
| `screenshot-6.png` | MCP Server connection |

**Screenshot Guidelines:**
- Max width: 1280 px (will be scaled)
- PNG format preferred
- Show actual plugin UI
- Highlight key features
- Use consistent styling

## Creating Assets from Source Files

### Icon (from favicon)

```bash
# Download and resize the favicon for WordPress.org icons
curl -o source-favicon.png "https://bots.nvdigital.solutions/wp-content/uploads/2025/11/cropped-WPOOS-FAVICON.png"

# Create icon sizes (using ImageMagick)
convert source-favicon.png -resize 128x128 icon-128x128.png
convert source-favicon.png -resize 256x256 icon-256x256.png
```

### Banner (from SVG logo)

```bash
# Download SVG logo
curl -o wpoos-logo.svg "https://bots.nvdigital.solutions/wp-content/uploads/2025/11/wpoos-logo.svg"

# Create banner with background (using ImageMagick)
# Standard banner
convert -size 772x250 xc:'#1e3a5f' \
  \( wpoos-logo.svg -resize 400x \) -gravity center -composite \
  banner-772x250.png

# Retina banner  
convert -size 1544x500 xc:'#1e3a5f' \
  \( wpoos-logo.svg -resize 800x \) -gravity center -composite \
  banner-1544x500.png
```

## Deployment

These assets are deployed to WordPress.org SVN repository:

```
# SVN structure for assets
/assets/
├── banner-772x250.png
├── banner-1544x500.png
├── icon-128x128.png
├── icon-256x256.png
├── screenshot-1.png
├── screenshot-2.png
└── ...
```

### Deploying with SVN

```bash
# Check out assets directory only
svn co https://plugins.svn.wordpress.org/wp-mcp-ai/assets/ ./svn-assets

# Copy new assets
cp .wordpress-org/*.png ./svn-assets/

# Commit changes
cd svn-assets
svn add --force .
svn commit -m "Update plugin assets"
```

## Creating Assets

### Recommended Tools

- **Figma** - Banner and icon design
- **Canva** - Quick banner creation
- **ImageMagick** - Command-line image processing
- **Screenshot tools** - macOS/Windows built-in

### WP oOS Brand Guidelines

- **Primary Color:** #1e3a5f (Deep Blue)
- **Accent Color:** #0073aa (WordPress Blue)
- **Logo:** Use wpoos-logo.svg for all branding
- **Icon:** Use cropped-WPOOS-FAVICON.png as icon base

## Placeholder Sizes Reference

Until final assets are created, use these dimensions:

```
Banner (standard):    772 × 250 px
Banner (HiDPI):      1544 × 500 px
Icon (standard):      128 × 128 px
Icon (HiDPI):         256 × 256 px
Screenshots:         1280 × 800 px (recommended)
```

## Automation

The GitHub release workflow automatically deploys assets:

```yaml
# In .github/workflows/release.yml
- name: Deploy assets to WordPress.org
  uses: 10up/action-wordpress-plugin-asset-update@stable
  env:
    SVN_USERNAME: ${{ secrets.SVN_USERNAME }}
    SVN_PASSWORD: ${{ secrets.SVN_PASSWORD }}
    SLUG: wp-mcp-ai
```

## Notes

- Assets are separate from the plugin ZIP
- They can be updated without a new plugin release
- Changes appear on wordpress.org within minutes
- Use consistent branding across all assets

## Links

- **Product Page:** https://nvdigitalsolutions.com/wpoos
- **GitHub:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- **NV Digital Solutions:** https://nvdigitalsolutions.com/
