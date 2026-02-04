# npm Build Guide for Visual Workflow Builder

## Overview

This guide explains how npm dependencies work in this WordPress plugin and how to build the React-based visual workflow builder.

## Important Concepts

### npm Packages Are Build Tools Only

**Key Point:** npm packages (node_modules) are **NEVER** included in the WordPress plugin distribution.

- ✅ **Used during development:** For compiling, bundling, and building
- ❌ **NOT included in plugin:** Only the compiled output is included
- 📦 **Size impact:** ~200-300KB (compiled bundle), not 380MB (node_modules)

### What Gets Included Where

| Item | Development | Git Repository | Plugin Distribution |
|------|-------------|----------------|---------------------|
| node_modules/ | ✅ 380MB | ❌ .gitignore | ❌ .distignore |
| src/ (React source) | ✅ Small | ✅ Yes | ❌ .distignore |
| build/ (compiled) | ✅ ~200KB | Partial | ✅ Yes |
| package.json | ✅ Yes | ✅ Yes | ❌ .distignore |
| webpack.config.js | ✅ Yes | ✅ Yes | ❌ .distignore |

## Setup Instructions

### First Time Setup

1. **Install Node.js** (version 18 or higher recommended)
   ```bash
   node --version  # Should be v18.x or higher
   npm --version   # Should be 9.x or higher
   ```

2. **Install Dependencies**
   ```bash
   cd /path/to/mcp-ai-wpoos
   npm install
   ```
   
   This will:
   - Download ~380MB to node_modules/
   - Create/update package-lock.json
   - Take 2-3 minutes on first run
   - **This is normal and expected!**

3. **Verify Installation**
   ```bash
   ls node_modules/  # Should see react, reactflow, @wordpress/scripts, etc.
   ```

## Building the Visual Workflow Builder

### Development Mode (Hot Reload)

For active development with instant updates:

```bash
npm run start:workflow
```

- Starts webpack dev server
- Watches for file changes
- Auto-recompiles on save
- Outputs to `addons/pro/build/workflow-builder/`
- Keep this running while coding

### Production Build

For final production-ready bundle:

```bash
npm run build:pro
```

- Compiles React components
- Minifies JavaScript
- Optimizes for production
- Tree-shakes unused code
- Outputs to `addons/pro/build/workflow-builder/`
- Size: ~200-300KB

### Build Scripts Explained

```json
{
  "build:workflow": "Build visual workflow builder for production",
  "start:workflow": "Start development server with hot reload",
  "build:pro": "Build all Pro features including workflow builder",
  "build": "Build base features only (no workflow builder)"
}
```

## File Structure

```
mcp-ai-wpoos/
├── node_modules/           # 380MB - Never committed or distributed
├── package.json            # Lists dependencies - Development only
├── package-lock.json       # Locks versions - Development only
├── webpack.config.js       # Build configuration - Development only
├── src/                    # React source code - Committed but not distributed
│   └── workflow-builder/
│       ├── index.jsx       # Entry point
│       └── components/     # React components
└── addons/pro/build/       # Compiled output - THIS is included in plugin
    └── workflow-builder/
        ├── workflow-builder.js      # ~200KB minified bundle
        ├── workflow-builder.js.map  # Source map
        └── workflow-builder.asset.php  # WordPress asset file
```

## Plugin Distribution

### What's Excluded (via .distignore)

- node_modules/ ❌
- src/ ❌
- package.json ❌
- package-lock.json ❌
- webpack.config.js ❌
- All development files ❌

### What's Included

- addons/pro/build/workflow-builder/*.js ✅ (~200KB)
- PHP files ✅
- Existing assets ✅

### Size Impact

**Base Plugin (WordPress.org):**
- Before: 8.8MB
- After: 8.8MB (no change)
- Visual builder not included

**Pro Plugin:**
- Before: 19MB
- After: 19.2-19.3MB (+200-300KB for visual builder)

## Common Issues & Solutions

### Issue: "npm ci can only install packages when your package.json and package-lock.json are in sync"

**Solution:** Run `npm install` instead of `npm ci`

```bash
npm install
```

This updates package-lock.json to match package.json.

### Issue: "Unsupported engine" warnings

These are warnings, not errors. The build will still work. If you want to update Node.js:

```bash
nvm install 20
nvm use 20
npm install
```

### Issue: "node_modules is too large"

**This is normal!** node_modules is 380MB but:
- ✅ Ignored by git (.gitignore)
- ✅ Excluded from distribution (.distignore)
- ✅ Only used during development
- ✅ Compiles down to ~200KB

### Issue: "Build is slow"

First build is slow (~2-3 minutes). Subsequent builds are faster:
- Development mode (start:workflow): < 10 seconds
- Production build (build:pro): ~30 seconds

## Development Workflow

### Typical Development Session

1. **Start development server:**
   ```bash
   npm run start:workflow
   ```

2. **Edit React components:**
   - Edit files in `src/workflow-builder/`
   - Save file
   - Browser auto-refreshes

3. **Test in WordPress:**
   - Navigate to NV oOS → Workflows
   - See changes immediately

4. **Build for production:**
   ```bash
   npm run build:pro
   ```

5. **Test production build:**
   - Reload WordPress admin
   - Verify everything works

### Adding New Dependencies

If you need a new npm package:

```bash
npm install package-name --save
```

Examples:
```bash
npm install lodash --save              # Add utility library
npm install @wordpress/icons --save    # Add WordPress icons
```

Then rebuild:
```bash
npm run build:pro
```

## CI/CD Integration

### GitHub Actions

If you use GitHub Actions for automated builds:

```yaml
- name: Install npm dependencies
  run: npm install

- name: Build Pro features
  run: npm run build:pro

- name: Build plugin ZIP
  run: ./bin/build-plugin-zip.sh --combined
```

### Local Build Script

For manual plugin builds:

```bash
# Full build process
npm install              # Install dependencies
npm run build:pro        # Build visual builder
./bin/build-plugin-zip.sh --combined  # Create ZIP

# Output: build/mcp-ai-wpoos-x.x.x.zip
```

## Pro-Only Feature Configuration

The visual workflow builder is a Pro-only feature. It's conditionally loaded:

```php
// In Pro addon loader
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) {
    // Load visual workflow builder
    wp_enqueue_script(
        'mcp-ai-workflow-builder-visual',
        WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/workflow-builder.js',
        $asset['dependencies'],
        $asset['version'],
        true
    );
}
```

## Troubleshooting

### Clear Everything and Start Fresh

If you encounter persistent issues:

```bash
# Remove all generated files
rm -rf node_modules/
rm -rf addons/pro/build/
rm package-lock.json

# Reinstall
npm install

# Rebuild
npm run build:pro
```

### Check Build Output

Verify the build worked:

```bash
ls -lh addons/pro/build/workflow-builder/

# Should see:
# workflow-builder.js        (~200KB)
# workflow-builder.js.map    (source map)
# workflow-builder.asset.php (WordPress metadata)
```

### Verify Plugin Size

Check the final plugin doesn't include node_modules:

```bash
./bin/build-plugin-zip.sh --combined
unzip -l build/mcp-ai-wpoos-x.x.x.zip | grep node_modules

# Should return nothing (node_modules excluded)
```

## Summary

✅ npm packages are development tools only
✅ Only compiled bundles (~200KB) are included in plugin
✅ Base plugin stays ~9MB for WordPress.org
✅ Pro plugin adds ~200KB for visual builder
✅ node_modules (380MB) never committed or distributed
✅ This is standard practice for modern WordPress plugins

## Further Reading

- [WordPress Scripts Documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/)
- [React Flow Documentation](https://reactflow.dev/learn)
- [dnd-kit Documentation](https://docs.dndkit.com/)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
