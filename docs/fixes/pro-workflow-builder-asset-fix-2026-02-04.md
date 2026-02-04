# Pro Workflow Builder Asset Loading Fix

**Date:** 2026-02-04  
**Issue:** Pro Workflow Builder page not loading correctly due to asset filename mismatch

## Problem

The Pro Workflow Builder page at `/wp-admin/admin.php?page=nvoos-pro-workflow-builder` was not loading the React application correctly because there was a mismatch between the built asset filenames and what the PHP class was attempting to load.

### Root Cause

When using `wp-scripts build` with the entry point `src/workflow-builder/index.jsx`, the build system generates files named after the entry point directory/file:

- **Built files**: `workflow-builder.js`, `workflow-builder.css`, `workflow-builder.asset.php`
- **PHP was looking for**: `index.js`, `index.css`, `index.asset.php`

This mismatch meant that:
1. The asset file check would fail: `file_exists( 'index.asset.php' )` returned `false`
2. The fallback to development source would be used instead
3. The React application would not load correctly in production

## Files Changed

### 1. Primary Fix: Pro Workflow Builder Page Class

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`

**Changes:**
- Line 75: `index.asset.php` → `workflow-builder.asset.php`
- Line 82: `index.js` → `workflow-builder.js`
- Line 90: `index.css` → `workflow-builder.css`

### 2. Test File Updates

**File:** `tests/test-pro-settings-react-packages.php`

**Changes:**
- Line 104-105: Updated comments to reference `workflow-builder.js`
- Line 158: `index.js` → `workflow-builder.js`
- Line 162: `index.js` → `workflow-builder.js`

### 3. Verification Script Updates

**File:** `bin/verify-react-packages.php`

**Changes:**
- Line 52: `index.js` → `workflow-builder.js`
- Line 59: `index.js` → `workflow-builder.js`

### 4. Documentation Updates

**Files:** 
- `docs/REACT_PACKAGE_FIX.md`
- `REACT_DEPENDENCIES_FIX.md`

**Changes:** Updated all references from `index.js` to `workflow-builder.js` for consistency

## Solution Details

### Before (Incorrect)

```php
$asset_file = WP_MCP_AI_PATH . 'addons/pro/build/workflow-builder/index.asset.php';

if ( file_exists( $asset_file ) ) {
    $asset = require $asset_file;
    
    wp_enqueue_script(
        'mcp-ai-pro-workflow-builder',
        WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/index.js',
        // ...
    );
    
    wp_enqueue_style(
        'mcp-ai-pro-workflow-builder',
        WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/index.css',
        // ...
    );
}
```

### After (Correct)

```php
$asset_file = WP_MCP_AI_PATH . 'addons/pro/build/workflow-builder/workflow-builder.asset.php';

if ( file_exists( $asset_file ) ) {
    $asset = require $asset_file;
    
    wp_enqueue_script(
        'mcp-ai-pro-workflow-builder',
        WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/workflow-builder.js',
        // ...
    );
    
    wp_enqueue_style(
        'mcp-ai-pro-workflow-builder',
        WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/workflow-builder.css',
        // ...
    );
}
```

## How wp-scripts Naming Works

The `wp-scripts build` command generates output files based on the entry point name:

```json
{
  "scripts": {
    "build:workflow": "wp-scripts build src/workflow-builder/index.jsx --output-path=addons/pro/build/workflow-builder"
  }
}
```

With entry point `src/workflow-builder/index.jsx`:
- The last directory name (`workflow-builder`) becomes the output filename prefix
- This generates: `workflow-builder.js`, `workflow-builder.css`, `workflow-builder.asset.php`

## Testing

### Verification Steps

1. **Check that asset files exist:**
   ```bash
   ls -la addons/pro/build/workflow-builder/
   # Should show:
   # workflow-builder.asset.php
   # workflow-builder.js
   # workflow-builder.css
   ```

2. **Verify PHP syntax:**
   ```bash
   php -l addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php
   # Output: No syntax errors detected
   ```

3. **Test in WordPress:**
   - Navigate to: **WP Admin → NV oOS Pro → Pro Workflows**
   - The React application should load correctly
   - No 404 errors in browser console for JavaScript/CSS files

### Expected Behavior

- **Before fix**: Page loads but React app doesn't initialize (blank page or fallback content)
- **After fix**: Pro Workflow Builder React application loads correctly with full UI

## Impact

### User-Facing Changes
- Pro Workflow Builder page now loads correctly with the React-based UI
- No more fallback to development source in production environments

### Technical Changes
- Corrects asset loading to match actual build output filenames
- Aligns PHP expectations with webpack/wp-scripts build conventions
- Updates tests and documentation for consistency

## Related Files

- **Main fix**: `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`
- **Tests**: `tests/test-pro-settings-react-packages.php`, `tests/test-pro-workflow-builder-menu.php`
- **Scripts**: `bin/verify-react-packages.php`
- **Build config**: `package.json` (no changes needed)
- **Related docs**: `docs/REACT_PACKAGE_FIX.md`, `REACT_DEPENDENCIES_FIX.md`

## Prevention

To prevent similar issues in the future:

1. **Always verify build output**: Check what files are actually generated by the build process
2. **Match PHP expectations to build output**: Ensure PHP code references the correct filenames
3. **Test in production mode**: Verify that built assets load correctly, not just development sources
4. **Document build conventions**: Make naming conventions clear in documentation

## References

- WordPress Scripts Documentation: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
- Webpack Output Naming: https://webpack.js.org/configuration/output/#outputfilename
- Related PR: Pro Workflow Builder URL Fix (2026-02-04)
