# CDN Optimization for Pro Plugin

## ⚠️ Important: Pro Plugin Only

**This CDN optimization applies ONLY to the Pro addon.** The base plugin remains completely local with all dependencies bundled. This ensures:

- Base plugin users have fully self-contained installation
- No CDN dependencies required for core functionality  
- Pro plugin benefits from size reduction while maintaining compatibility

## Overview

The Pro addon now uses a CDN-first approach for popular JavaScript libraries to significantly reduce plugin size. Libraries are loaded from jsDelivr CDN with automatic fallback to local copies for offline/intranet installations.

**Base Plugin:** All dependencies remain bundled locally (no changes)  
**Pro Addon:** Selected libraries loaded from CDN with local fallback

## Size Savings

**Before Optimization:** 97MB vendor directory  
**After Optimization:** ~77MB vendor directory  
**Savings:** ~20MB (20.6% reduction)

### CDN-Loaded Libraries

| Library | Size Saved | Purpose | CDN URL |
|---------|------------|---------|---------|
| mathjs | 17MB | Mathematical computations | jsDelivr |
| KaTeX | 3.1MB | LaTeX math rendering | jsDelivr |
| axios | 1.6MB | HTTP client | jsDelivr |
| d3 | 864KB | Data visualization | jsDelivr |
| Chart.js | 420KB | Chart generation | jsDelivr |
| Prettier | ~500KB | Code formatting | jsDelivr |

**Total Saved:** ~23.5MB

## How It Works

### 1. Automatic CDN Loading

The `WP_MCP_AI_Pro_CDN_Loader` class automatically registers and loads libraries from CDN:

```php
// Libraries are registered on wp_enqueue_scripts
add_action( 'wp_enqueue_scripts', array( 'WP_MCP_AI_Pro_CDN_Loader', 'register_libraries' ), 5 );

// Enqueue a specific library
WP_MCP_AI_Pro_CDN_Loader::enqueue( 'katex' );
```

### 2. Fallback Mechanism

If CDN fails or is disabled, local copies are loaded automatically:

```php
// CDN URL (production)
https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js

// Fallback URL (offline/intranet)
/wp-content/plugins/mcp-ai-wpoos-pro/assets/vendor/katex/dist/katex.min.js
```

### 3. Build-Time Control

During build, CDN packages are skipped by default:

```bash
# Standard build (CDN-first, ~77MB)
npm run build

# Offline build (includes all packages, ~97MB)
WP_MCP_AI_BUILD_OFFLINE=true npm run build
```

## Disabling CDN Loading

### Method 1: WordPress Constant

Add to `wp-config.php`:

```php
define( 'WP_MCP_AI_PRO_DISABLE_CDN', true );
```

### Method 2: Filter Hook

```php
add_filter( 'wp_mcp_ai_pro_use_cdn', '__return_false' );
```

### Method 3: Plugin Settings

Navigate to **NV oOS → Settings → Advanced** and enable "Disable CDN Loading".

## For Developers

### Adding a New CDN Library

1. **Edit `includes/class-wp-mcp-ai-pro-cdn-loader.php`:**

```php
private static $libraries = array(
    'new-library' => array(
        'cdn_url'       => 'https://cdn.jsdelivr.net/npm/new-library@1.0.0/dist/lib.min.js',
        'fallback_url'  => 'assets/vendor/new-library/dist/lib.min.js',
        'version'       => '1.0.0',
        'handle'        => 'new-library',
        'dependencies'  => array(),
        'in_footer'     => true,
    ),
);
```

2. **Edit `addons/pro/scripts/copy-dependencies.js`:**

```javascript
// Add to cdnPackages array
const cdnPackages = [
    'chart.js',
    'katex',
    // ... existing packages
    'new-library', // Add here
];

// Mark in dependencies array
{
    name: 'new-library',
    cdnPackage: true, // Mark as CDN package
    files: [
        { src: 'dist/lib.min.js', dest: 'new-library/dist/lib.min.js' },
    ],
},
```

3. **Use in code:**

```php
// Register and enqueue
WP_MCP_AI_Pro_CDN_Loader::enqueue( 'new-library' );

// Check availability
if ( WP_MCP_AI_Pro_CDN_Loader::is_available( 'new-library' ) ) {
    // Use library
}
```

### Testing CDN Fallback

```php
// Test script to verify fallback works
add_action( 'wp_footer', function() {
    ?>
    <script>
    // Try loading from CDN
    if (typeof katex === 'undefined') {
        console.warn('KaTeX CDN failed, loading fallback...');
        // Fallback is automatically loaded by WP_MCP_AI_Pro_CDN_Loader
    } else {
        console.log('KaTeX loaded from CDN successfully');
    }
    </script>
    <?php
} );
```

## Security Considerations

### Subresource Integrity (SRI)

Add SRI hashes for CDN resources to prevent tampering:

```php
'katex' => array(
    'cdn_url' => 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js',
    'sri'     => 'sha384-BdGj8xC2eNjInNd4iF8kUZLd+rpMaFGlH2LIzJ0V4CQ6Zc2xyYZOOx1v5qiDfUJv',
    // ...
),
```

### Privacy Considerations

- jsDelivr CDN is GDPR-compliant
- No user tracking or analytics
- Open source and transparent
- Geographic distribution reduces latency

### For Offline/Intranet Deployments

Always build with offline mode:

```bash
# Build for offline deployment
WP_MCP_AI_BUILD_OFFLINE=true npm run build

# Or define in environment
export WP_MCP_AI_BUILD_OFFLINE=true
npm run build
```

## Performance Benefits

### 1. Reduced Download Size

- Plugin ZIP: 97MB → 77MB (-20.6%)
- Update downloads: Significantly faster
- Storage requirements: Lower on both server and client

### 2. Improved Cache Hit Rate

Popular libraries loaded from CDN are often already cached by browsers from other sites.

### 3. Geographic Distribution

jsDelivr provides edge servers worldwide, reducing latency for international users.

### 4. Bandwidth Savings

CDN bandwidth is free (for open source projects), reducing hosting costs.

## Monitoring

### Check CDN Status

```php
// Get library configuration
$config = WP_MCP_AI_Pro_CDN_Loader::get_library_config( 'katex' );
echo 'Using CDN: ' . ( WP_MCP_AI_Pro_CDN_Loader::should_use_cdn() ? 'Yes' : 'No' );
echo 'URL: ' . $config['cdn_url'];
```

### Debug Logging

Enable WP_DEBUG to see CDN-related warnings:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Check debug.log for messages like:
```
WP MCP AI Pro: Fallback file not found: /path/to/vendor/katex/dist/katex.min.js
```

## Compatibility

### Browser Support

All CDN libraries support:
- Chrome/Edge: Last 2 versions
- Firefox: Last 2 versions
- Safari: Last 2 versions
- Mobile browsers: iOS 12+, Android 5+

### WordPress Compatibility

- WordPress 6.0+
- PHP 7.4+
- Works with all major caching plugins (WP Rocket, W3 Total Cache, etc.)

## Troubleshooting

### CDN Not Loading

1. **Check if CDN is enabled:**
   ```php
   var_dump( WP_MCP_AI_Pro_CDN_Loader::should_use_cdn() );
   ```

2. **Verify library registration:**
   ```php
   var_dump( wp_script_is( 'katex', 'registered' ) );
   ```

3. **Check browser console** for loading errors

### Fallback Not Working

1. **Verify fallback files exist:**
   ```bash
   ls -lh addons/pro/assets/vendor/katex/dist/
   ```

2. **Rebuild with offline mode:**
   ```bash
   cd addons/pro
   WP_MCP_AI_BUILD_OFFLINE=true npm run build
   ```

### Mixed Content Errors (HTTPS)

CDN URLs use HTTPS by default. If you see mixed content warnings:

1. Ensure WordPress is using HTTPS
2. Check WP_SITEURL and WP_HOME use https://
3. Use Really Simple SSL or similar plugin

## Future Enhancements

Potential additional CDN candidates:

- **exceljs** (16MB) - Would need browser-compatible build
- **pdf-lib** (6.6MB) - Already has browser build, good candidate
- **pdfkit** (5.9MB) - Would need browser-compatible build

These are currently kept bundled due to:
- Server-side usage requirements
- No official browser CDN builds
- Complex binary dependencies

## References

- [jsDelivr Documentation](https://www.jsdelivr.com/documentation)
- [Subresource Integrity](https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity)
- [WordPress wp_enqueue_script()](https://developer.wordpress.org/reference/functions/wp_enqueue_script/)

## Support

For issues or questions about CDN optimization:

1. Check this documentation
2. Review debug logs with WP_DEBUG enabled
3. Open an issue at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
4. Contact support@nvdigitalsolutions.com

---

**Last Updated:** 2026-02-12  
**Version:** 1.1.1
