# Optional Components Development Guide

## Overview

The Open Operator System (oOS) plugin uses optional components to reduce the base plugin size. These components are downloaded on-demand when the plugin is activated or when specific features are used.

## Optional Components

### 1. Neplex Vectorizer
- **File**: `neplex-vectorizer.zip`
- **Size**: ~12 KB
- **Location**: `assets/js/vendor/neplex-vectorizer/`
- **Purpose**: Image vectorization library for the `vectorize_image` tool
- **When Downloaded**: On plugin activation or first use of vectorize_image tool

### 2. Knowledge Base
- **File**: `knowledge-base.zip`
- **Size**: ~2.3 MB
- **Location**: `includes/knowledge-base/profession-playbooks/`
- **Purpose**: Complete profession playbooks (205+ professions)
- **When Downloaded**: On plugin activation
- **Note**: Base plugin includes 20 most common professions by default

## Download Locations

### Production (Default)
Components are downloaded from GitHub releases:
```
https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/v{VERSION}/neplex-vectorizer.zip
https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/v{VERSION}/knowledge-base.zip
```

### Development (dev-working branch)
For testing unreleased changes, components can be downloaded from the dev-working branch:
```
https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/dev-working/build/optional-components/neplex-vectorizer.zip
https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/dev-working/build/optional-components/knowledge-base.zip
```

## Using Dev-Working Location

### Enable Dev Components Mode

Add this constant to your `wp-config.php` file:

```php
define( 'WP_MCP_AI_DEV_COMPONENTS', true );
```

This will make the plugin download optional components from the dev-working branch instead of GitHub releases.

### When to Use Dev-Working Location

- **Testing unreleased changes** to optional components before creating a release
- **Development and debugging** of the download mechanism
- **Continuous integration** testing with the latest component builds

### When NOT to Use Dev-Working Location

- **Production websites** - Always use the default GitHub release location
- **Stable testing** - Use tagged releases for predictable behavior
- **WordPress.org submissions** - Must use GitHub releases

## Building Optional Components

### Generate ZIPs Locally

Run the build script to create the optional component ZIPs:

```bash
./bin/build-optional-components.sh
```

Output files:
- `build/optional-components/neplex-vectorizer.zip`
- `build/optional-components/knowledge-base.zip`

### Commit ZIPs to dev-working Branch

For development testing:

```bash
# Build the ZIPs
./bin/build-optional-components.sh

# Commit to dev-working branch
git checkout dev-working
git add build/optional-components/*.zip
git commit -m "Update optional component ZIPs"
git push origin dev-working
```

### Upload to GitHub Releases

For production releases:

1. Build the ZIPs: `./bin/build-optional-components.sh`
2. Create a new release on GitHub
3. Upload both ZIPs as release assets:
   - `neplex-vectorizer.zip`
   - `knowledge-base.zip`

## Testing the Download Process

### Test with Dev-Working Location

1. Enable dev-working mode in `wp-config.php`:
   ```php
   define( 'WP_MCP_AI_DEV_COMPONENTS', true );
   ```

2. Delete existing components (if any):
   ```bash
   rm -rf wp-content/plugins/mcp-ai-wpoos/assets/js/vendor/neplex-vectorizer
   rm -rf wp-content/plugins/mcp-ai-wpoos/includes/knowledge-base/profession-playbooks/professions/*.txt
   ```

3. Deactivate and reactivate the plugin in WordPress admin

4. Check that components download from dev-working branch

### Verify Download Logs

Check WordPress admin notices for download status:
- **Success**: "Optional components downloaded successfully!"
- **In Progress**: "Downloading optional components in the background..."
- **Error**: Specific error message with details

### Manual Download

Use the AJAX endpoint for manual downloads:

```javascript
// In browser console on any plugin admin page
jQuery.post(ajaxurl, {
    action: 'wp_mcp_ai_download_component',
    component: 'vectorizer', // or 'knowledge_base'
    nonce: wpMcpAi.downloadNonce // must be available
});
```

## Architecture Notes

### Class: `WP_MCP_AI_Optional_Components`

**Constants**:
- `GITHUB_RELEASE_BASE` - Production download URL
- `DEV_WORKING_BASE` - Development download URL
- `DOWNLOAD_IN_PROGRESS` - Transient key for download status

**Methods**:
- `get_download_base_url()` - Returns appropriate base URL (dev or production)
- `download_vectorizer()` - Downloads and extracts vectorizer ZIP
- `download_knowledge_base()` - Downloads and extracts knowledge base ZIP
- `is_vectorizer_installed()` - Checks if vectorizer is present
- `is_knowledge_base_complete()` - Checks if knowledge base has 200+ professions

### Download Flow

1. Plugin activation triggers `download_on_activation()`
2. Schedules background cron job: `wp_mcp_ai_download_optional_components`
3. Job runs `background_download()` which calls download methods
4. Each download method:
   - Gets base URL via `get_download_base_url()`
   - Downloads ZIP to temp file
   - Extracts to target directory
   - Updates status in options table
5. Admin notices display download status to users

## Troubleshooting

### Components Won't Download

1. **Check PHP version**: Requires PHP 7.4+
2. **Check file permissions**: WordPress must be able to write to plugin directory
3. **Check allow_url_fopen**: Must be enabled in PHP
4. **Check firewall**: GitHub URLs must be accessible
5. **Check download URL**: View HTML source of admin page for actual URLs used

### Wrong Download Location

1. **Check constant**: Verify `WP_MCP_AI_DEV_COMPONENTS` in `wp-config.php`
2. **Clear cache**: Clear any WordPress object cache
3. **Check version**: Ensure `WP_MCP_AI_VERSION` matches expected value
4. **Debug URL**: Add this to see actual URL:
   ```php
   error_log( 'Download URL: ' . $download_url );
   ```

### ZIPs Not Found (404 Error)

1. **Dev-working branch**: Ensure ZIPs are committed to `build/optional-components/`
2. **GitHub release**: Ensure ZIPs are uploaded as release assets
3. **Version mismatch**: Ensure version in code matches release tag
4. **File names**: Must be exactly `neplex-vectorizer.zip` and `knowledge-base.zip`

## Best Practices

1. **Always test with dev-working** before creating a release
2. **Keep ZIPs up-to-date** on dev-working branch for CI/CD
3. **Document changes** when modifying optional components
4. **Test extraction** to ensure directory structure is correct
5. **Monitor download logs** in production for errors

## Related Files

- `includes/class-wp-mcp-ai-optional-components.php` - Download manager class
- `bin/build-optional-components.sh` - ZIP generation script
- `bin/build-plugin-zip.sh` - Main plugin build script (excludes optional components)
- `.github/workflows/build-assets.yml` - CI/CD build workflow
