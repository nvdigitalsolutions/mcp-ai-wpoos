# Vectorizer Module Fix - Summary

**Date:** January 1, 2026  
**Issue:** `vectorize_image` tool failing on cloned production repos  
**Status:** ✅ Fixed

## Problem

When the plugin was cloned to a production server (without `node_modules`), the `vectorize_image` tool failed with:

```
⚠️ Tool "vectorize_image" execution failed: Node.js script failed with code 5: 
Failed to load @neplex/vectorizer: Cannot find module '@neplex/vectorizer-linux-x64-gnu'
```

### Root Cause

The `@neplex/vectorizer` npm package uses a **platform-specific native module architecture**:

1. Main package in `node_modules/@neplex/vectorizer/` contains platform detection logic
2. Native `.node` binaries are in separate packages like `@neplex/vectorizer-linux-x64-gnu/`
3. The main `index.js` first checks for local `.node` files in its own directory
4. If not found locally, it tries to `require('@neplex/vectorizer-linux-x64-gnu')` from npm

**In production (cloned repo):**
- ❌ No local `.node` files in the main vectorizer directory
- ❌ No `node_modules` available for fallback require
- ❌ Module loading fails

## Solution

### What Was Fixed

1. **Updated `package.json` postinstall script** to copy native `.node` files from platform-specific subdirectories to the main vectorizer directory:

   ```json
   "install:vectorizer:fix": "find assets/js/vendor/neplex-vectorizer -maxdepth 2 -name '*.node' -type f -exec sh -c 'cp \"$1\" assets/js/vendor/neplex-vectorizer/vectorizer/' _ {} \\;"
   ```

2. **Created `bin/fix-vectorizer-vendor.sh`** - Standalone script to fix existing cloned repos without running `npm install`

3. **Committed native `.node` files to git** in `assets/js/vendor/neplex-vectorizer/vectorizer/`:
   - `vectorizer.linux-x64-gnu.node` (2.3 MB)
   - `vectorizer.linux-x64-musl.node` (2.2 MB)

4. **Updated documentation** in:
   - `BUILD.md` - Added troubleshooting section
   - `assets/js/vendor/INSTALL.md` - Added vectorizer installation instructions
   - `assets/js/vendor/neplex-vectorizer/README.md` - Added detailed troubleshooting
   - `VECTORIZE_IMAGE_IMPLEMENTATION_SUMMARY.md` - Added module loading error section

### How It Works Now

**For new installations:**
```bash
npm install
# Postinstall automatically copies .node files to correct location
```

**For cloned repos (production):**
```bash
./bin/fix-vectorizer-vendor.sh
# Copies .node files from subdirectories to main vectorizer directory
```

**Result:** The vectorizer `index.js` finds the local `.node` files and loads successfully without needing to require from npm packages.

## Files Changed

### Modified Files
- `package.json` - Added `install:vectorizer:fix` script to postinstall
- `BUILD.md` - Added vectorizer troubleshooting section
- `assets/js/vendor/INSTALL.md` - Added vectorizer installation and troubleshooting
- `assets/js/vendor/neplex-vectorizer/README.md` - Added detailed error explanation and fix
- `VECTORIZE_IMAGE_IMPLEMENTATION_SUMMARY.md` - Added module loading error documentation

### New Files
- `bin/fix-vectorizer-vendor.sh` - Standalone fix script for cloned repos (executable)
- `assets/js/vendor/neplex-vectorizer/vectorizer/vectorizer.linux-x64-gnu.node` (committed to git)
- `assets/js/vendor/neplex-vectorizer/vectorizer/vectorizer.linux-x64-musl.node` (committed to git)

## Verification

The fix has been tested and verified:

✅ Module loads successfully from vendor directory  
✅ `bin/vectorize-image.js` script runs without module errors  
✅ Native `.node` files exist in correct location  
✅ Fix script works correctly  
✅ No regressions in existing functionality  

## Usage

### For Users Seeing the Error

If you see:
```
Cannot find module '@neplex/vectorizer-linux-x64-gnu'
```

**Quick Fix:**
```bash
./bin/fix-vectorizer-vendor.sh
```

### For Developers

When updating the plugin or reinstalling dependencies:
```bash
npm install
# .node files are automatically copied via postinstall
```

## Technical Details

### Native Module Loading Strategy

The `@neplex/vectorizer` package uses NAPI-RS architecture:

```javascript
// vectorizer/index.js (simplified)
switch (platform) {
  case 'linux':
    if (isMusl()) {
      // First check: local file
      if (existsSync('./vectorizer.linux-x64-musl.node')) {
        nativeBinding = require('./vectorizer.linux-x64-musl.node');
      } else {
        // Second check: npm package (requires node_modules)
        nativeBinding = require('@neplex/vectorizer-linux-x64-musl');
      }
    } else {
      // Similar for glibc
      if (existsSync('./vectorizer.linux-x64-gnu.node')) {
        nativeBinding = require('./vectorizer.linux-x64-gnu.node');
      } else {
        nativeBinding = require('@neplex/vectorizer-linux-x64-gnu');
      }
    }
}
```

**Our fix ensures the first check (local file) succeeds**, avoiding the need for npm packages.

### Vendor Directory Structure

**Before fix:**
```
assets/js/vendor/neplex-vectorizer/
├── vectorizer/               # Main package
│   ├── index.js             # Platform detection (looks for .node files HERE)
│   └── package.json
├── vectorizer-linux-x64-gnu/
│   └── vectorizer.linux-x64-gnu.node  # Native binary HERE
└── vectorizer-linux-x64-musl/
    └── vectorizer.linux-x64-musl.node  # Native binary HERE
```

**After fix:**
```
assets/js/vendor/neplex-vectorizer/
├── vectorizer/                        # Main package
│   ├── index.js                      # Platform detection
│   ├── vectorizer.linux-x64-gnu.node  # ✅ NOW HERE (copied)
│   └── vectorizer.linux-x64-musl.node # ✅ NOW HERE (copied)
├── vectorizer-linux-x64-gnu/
│   └── vectorizer.linux-x64-gnu.node  # Original (kept)
└── vectorizer-linux-x64-musl/
    └── vectorizer.linux-x64-musl.node  # Original (kept)
```

## Impact

- ✅ Plugin now works immediately on cloned production repos
- ✅ No npm or node_modules required in production (for this feature)
- ✅ Easy fix script available for existing installations
- ✅ Consistent with Chart.js vendor integration pattern
- ⚠️ Plugin size increased by ~4.5 MB (native binaries)

## References

- **Issue reported:** Error message in production environment
- **Fix implemented:** January 1, 2026
- **Related documentation:**
  - `BUILD.md` - Build and troubleshooting guide
  - `assets/js/vendor/neplex-vectorizer/README.md` - Vectorizer-specific documentation
  - `VECTORIZE_IMAGE_IMPLEMENTATION_SUMMARY.md` - Original implementation details
  - `bin/fix-vectorizer-vendor.sh` - Fix script source code

## Future Considerations

1. **Platform Support:** Currently includes Linux x64 binaries. Other platforms (macOS, Windows) can be added if needed.

2. **Alternative Approaches:**
   - Could use native PHP image processing instead of Node.js (would require different library)
   - Could make vectorizer an optional addon that requires npm
   - Current approach prioritizes ease of deployment

3. **Build Process:** Consider adding automated checks to CI/CD to verify native modules are present.

## Conclusion

The `vectorize_image` tool now works reliably on cloned production repos without requiring `node_modules`. The fix is simple, well-documented, and includes a standalone repair script for existing installations.

**Status:** ✅ **Complete and Production Ready**
