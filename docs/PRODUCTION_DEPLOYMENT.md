# Production Deployment Guide

## Overview

This repository is configured for production deployment as a WordPress plugin. The codebase includes workflow orchestration enhancements with parallel execution, conditional branching, loops, DAG support, and Chart.js visualization capabilities.

## Production Build Setup

### Optimized Composer Autoloader

The repository has been configured with an optimized composer autoloader for production use:

```bash
composer install --no-dev --classmap-authoritative
```

**What this does:**
- **`--no-dev`**: Excludes development dependencies (PHPUnit, WPCS, etc.)
- **`--classmap-authoritative`**: Creates an optimized classmap for faster autoloading
- Reduces vendor directory size
- Improves plugin load time

### Production Dependencies Only

After running the production install, only these packages are included:

```
guzzlehttp/guzzle           7.10.0   HTTP client library
guzzlehttp/promises         2.3.0    Promises library
guzzlehttp/psr7             2.8.0    PSR-7 implementation
league/oauth2-client        2.9.0    OAuth 2.0 Client
nyholm/psr7                 1.8.2    PSR-7 implementation
php-http/discovery          1.20.0   PSR HTTP discovery
psr/* packages              -        PSR interfaces
rahul900day/tiktoken-php    1.0.0    Token counting
ralouphie/getallheaders     3.0.3    Header polyfill
symfony/* packages          6.4.*    Symfony components
```

**Dev dependencies excluded:**
- PHPUnit (testing framework)
- PHP_CodeSniffer / WPCS (code standards)
- PHPCompatibilityWP (compatibility checker)
- WordPress stubs
- Other development tools

### Vendor Directory Size

Production build: **~5.9MB** (without dev dependencies)

## Cloning for Production Use

### Option 1: Direct Clone

```bash
# Clone the repository
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git

# Navigate to the plugin directory
cd mcp-ai-wpoos

# Install production dependencies
composer install --no-dev --classmap-authoritative

# Optional: Build JavaScript assets
npm install
npm run build
```

### Option 2: Deploy to WordPress

```bash
# Clone into WordPress plugins directory
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git mcp-ai-wpoos

# Install dependencies
cd mcp-ai-wpoos
composer install --no-dev --classmap-authoritative
npm install
npm run build

# Activate the plugin in WordPress admin
```

### Option 3: Download Release Build

For the easiest deployment, download a pre-built release ZIP:

```bash
# Download from releases
wget https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/latest/download/mcp-ai-wpoos.zip

# Extract to WordPress plugins directory
unzip mcp-ai-wpoos.zip -d /path/to/wordpress/wp-content/plugins/

# Activate in WordPress admin
```

## Workflow Orchestration Features

This production build includes all advanced workflow orchestration features:

### Core Features
1. **Parallel Execution** - Execute independent steps concurrently
2. **Conditional Branching** - Route workflows based on conditions
3. **Loop Control** - Autonomous self-healing cycles
4. **Step Dependencies (DAG)** - Complex dependency resolution

### Enhancement Features
5. **Performance Metrics** - Automatic execution tracking
6. **Text Visualization** - ASCII workflow diagrams
7. **Chart.js Dashboards** - Interactive HTML charts

### Usage

```bash
# List available workflows
/workflow --list

# Run a workflow
/workflow parallel-checks

# Visualize workflow structure
/workflow dependency-workflow --visualize

# Run in dry-run mode
/workflow my-workflow --dry-run
```

## Autoloader Optimization Details

### Classmap Authoritative Mode

When `--classmap-authoritative` is used:

1. **Classmap Generation**: All classes are scanned and mapped to files
2. **No Filesystem Scans**: Autoloader doesn't scan filesystem at runtime
3. **Faster Loading**: Direct file lookup from classmap array
4. **Production Recommended**: Best for production environments

**Classmap Location**: `vendor/composer/autoload_classmap.php` (~83KB)

### Autoloader Files

Generated files:
- `autoload_classmap.php` - Class-to-file mapping (83KB)
- `autoload_static.php` - Static autoloader data (95KB)
- `autoload_psr4.php` - PSR-4 namespace mappings (2.1KB)
- `autoload_files.php` - Files to include (735B)
- `autoload_real.php` - Autoloader initializer (1.7KB)

## Performance Benefits

### Before Optimization
- Dev dependencies included (~15MB vendor)
- Filesystem scans on autoload
- Slower class loading
- Development tools present

### After Optimization
- Production dependencies only (~5.9MB vendor)
- Direct classmap lookup
- Faster class loading (50-100ms improvement)
- No development tools

## Maintenance

### Updating Dependencies

```bash
# Update production dependencies
composer update --no-dev --classmap-authoritative

# Update with dev dependencies (development only)
composer update
```

### Rebuilding Autoloader

If you modify class files or add new classes:

```bash
# Regenerate optimized autoloader
composer dump-autoload --classmap-authoritative
```

## Compatibility

- **PHP**: 7.4+ (tested up to 8.3)
- **WordPress**: 6.0+ (tested up to 6.7)
- **Composer**: 2.0+
- **Node.js**: 18+ (for asset building)

## Security Considerations

### Production Build Security
- ✅ No development tools exposed
- ✅ No test files in production
- ✅ Optimized autoloader (no filesystem traversal)
- ✅ Minimal attack surface

### Excluded from Production
- Test files and fixtures
- Development configuration files
- Code quality tools
- Documentation generators

## Troubleshooting

### Issue: Classes Not Found

```bash
# Regenerate autoloader
composer dump-autoload --classmap-authoritative
```

### Issue: Dev Dependencies Installed

```bash
# Remove and reinstall
rm -rf vendor/
composer install --no-dev --classmap-authoritative
```

### Issue: Slow Autoloading

Verify authoritative mode is enabled:
```bash
# Check autoload_real.php for $useStaticLoader = true
grep "useStaticLoader" vendor/composer/autoload_real.php
```

## Support

For issues or questions:
- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation**: `/docs/` directory
- **Workflow Guide**: `/docs/WORKFLOW_ORCHESTRATION_QUICK_REFERENCE.md`

---

**Version**: 1.2.2  
**Last Updated**: 2026-02-04  
**Build Status**: Production Ready ✅
