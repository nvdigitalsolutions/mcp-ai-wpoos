# Vendor Libraries Installation

This directory contains third-party JavaScript libraries that are required for the plugin to function properly.

## Chart.js

Chart.js library needs to be installed for the Analytics Dashboard to work properly.

### Installation Options

#### Option 1: Using npm (Recommended)
```bash
npm install
# Chart.js will be copied from node_modules to this directory
```

#### Option 2: Manual Download
```bash
cd assets/js/vendor/
curl -o chart.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
```

#### Option 3: CDN (Development/Testing Only)
Modify `includes/admin/class-wp-mcp-ai-chart-js-helper.php` to use CDN URL instead of local file.

### Note
The chart.min.js file is gitignored. After cloning, run one of the installation options above.

## @neplex/vectorizer

The vectorizer library is required for the `vectorize_image` tool to convert raster images to SVG format.

### Installation

#### Option 1: Using npm (Recommended)
```bash
npm install
# Vectorizer and native binaries will be copied automatically
```

#### Option 2: Fix Existing Vendor Directory
If you cloned the repository and the vendor files are present but not working:
```bash
./bin/fix-vectorizer-vendor.sh
```

### Troubleshooting

If you see the error:
```
Cannot find module '@neplex/vectorizer-linux-x64-gnu'
```

Run the fix script:
```bash
./bin/fix-vectorizer-vendor.sh
```

See `neplex-vectorizer/README.md` for more details.
