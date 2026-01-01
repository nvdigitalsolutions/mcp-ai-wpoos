# @neplex/vectorizer Installation Required

The @neplex/vectorizer library and its platform-specific native binaries are required for the `vectorize_image` tool to work properly.

## Installation Options

### Option 1: Using npm (Recommended)
```bash
npm install
# @neplex/vectorizer will be automatically copied from node_modules to this directory
```

### Option 2: Manual Copy
```bash
npm run install:vectorizer
```

This executes:
```bash
rm -rf assets/js/vendor/neplex-vectorizer && \
mkdir -p assets/js/vendor/neplex-vectorizer && \
cp -r node_modules/@neplex/vectorizer* assets/js/vendor/neplex-vectorizer/
```

## Note

The vectorizer files in this directory are tracked in git (unlike node_modules). After cloning the repository, these files should already be present. Running `npm install` will refresh them to ensure they match the version specified in package.json.

## Verification

Check if the library is properly installed:

```bash
# Should list vectorizer directories
ls -la assets/js/vendor/neplex-vectorizer/

# Test the vectorizer script
node bin/vectorize-image.js
# Should output: {"success":false,"error":"Invalid arguments. Usage: vectorize-image.js <input-file> <output-file> [options-json]","code":"INVALID_ARGS"}
```

## Requirements

- Node.js 14.0.0 or higher
- One of the supported platforms (Linux, macOS, Windows, FreeBSD, Android)
- Supported architectures: x64, ARM64, ARM, ia32

## Support

For issues with @neplex/vectorizer itself, see:
- [npm package](https://www.npmjs.com/package/@neplex/vectorizer)
- [GitHub repository](https://github.com/neplextech/vectorizer)

For issues with the WP oOS integration, see the main plugin documentation.
