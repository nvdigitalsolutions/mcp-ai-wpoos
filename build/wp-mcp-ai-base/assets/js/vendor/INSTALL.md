# Chart.js Installation Required

Chart.js library needs to be installed for the Analytics Dashboard to work properly.

## Installation Options

### Option 1: Using npm (Recommended)
```bash
npm install
# Chart.js will be copied from node_modules to this directory
```

### Option 2: Manual Download
```bash
cd assets/js/vendor/
curl -o chart.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
```

### Option 3: CDN (Development/Testing Only)
Modify `includes/admin/class-wp-mcp-ai-chart-js-helper.php` to use CDN URL instead of local file.

## Note
The chart.min.js file is gitignored. After cloning, run one of the installation options above.
