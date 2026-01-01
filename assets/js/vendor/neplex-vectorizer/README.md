# @neplex/vectorizer for WP oOS

This directory contains the @neplex/vectorizer library for the `vectorize_image` tool.

## Installation

Files are automatically copied here via npm postinstall script:

```bash
npm install
```

See [VENDOR_FILES_INTEGRATION_SUMMARY.md](../../../../VENDOR_FILES_INTEGRATION_SUMMARY.md) for complete details.

## Troubleshooting

### Error: Cannot find module '@neplex/vectorizer-linux-x64-gnu'

If you cloned the repository and see this error, it means the native `.node` files need to be copied to the main vectorizer directory.

**Fix for cloned repositories:**
```bash
./bin/fix-vectorizer-vendor.sh
```

This happens because:
1. The `@neplex/vectorizer` package uses platform-specific native binaries
2. The main `vectorizer/index.js` first looks for local `.node` files
3. If not found locally, it tries to load from npm packages (which don't exist in production)
4. The postinstall script copies these files automatically, but cloned repos may not have them

**When running `npm install`:** This is done automatically by the postinstall script.

## Contents

- **vectorizer/** - Main package with platform detection and native `.node` files
- **vectorizer-linux-x64-gnu/** - Linux x64 with glibc binary (source)
- **vectorizer-linux-x64-musl/** - Linux x64 with musl binary (source)

Additional platform binaries may be present depending on your development platform.

The native `.node` files from platform-specific directories are copied into the `vectorizer/` directory for runtime loading.

## License

MIT - See vectorizer/LICENSE
