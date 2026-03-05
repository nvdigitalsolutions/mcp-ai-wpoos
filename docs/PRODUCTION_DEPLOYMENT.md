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

### Why is the Clone so Large?

When you run `git clone`, Git downloads the entire commit history stored in
`.git/objects/`.  For this repository that directory alone is ~140 MB because
it contains the full history of every file that has ever been committed.

The working-tree files (the actual plugin code) are only a fraction of that
size.  There are three strategies to keep your deployment lean:

---

### Option 1: Pre-Built Deployment ZIP (Recommended — no `.git` at all)

Every release automatically publishes a slim, production-ready ZIP that
contains **only** the plugin files needed to run in WordPress — no `.git`
directory, no development tools, no tests, no docs.

**Download from GitHub Releases:**

1. Go to [Releases](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases)
2. Download `mcp-ai-wpoos-deploy-<version>.zip`
3. Upload to your WordPress site via **Plugins → Add New → Upload Plugin**

Or from the command line / Cloudways SSH:

```bash
# Download the slim deployment ZIP (no .git, no dev files, ~production size)
# Replace <version> with the target version number, e.g. 1.2.3
wget https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/v<version>/mcp-ai-wpoos-deploy-<version>.zip

# Extract directly into the WordPress plugins directory
unzip mcp-ai-wpoos-deploy-<version>.zip -d /path/to/wordpress/wp-content/plugins/
```

This is the **recommended method for Cloudways** and any other host where
disk space or transfer bandwidth is a concern.

---

### Option 2: Shallow Clone — depth=1 (Recommended for git-based workflows)

A shallow clone fetches only the latest snapshot of the repository without
any historical commits.  This limits `.git/objects/` to a single commit's
worth of data instead of the full history, reducing the initial clone size
dramatically.

```bash
# Shallow clone — only the tip of the default branch
git clone --depth=1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git

cd mcp-ai-wpoos

# Install production-only PHP dependencies
composer install --no-dev --classmap-authoritative --no-interaction
```

**Cloudways Git integration:**  
In the Cloudways dashboard under **Application → Git Deployment**, look for
a **Git Clone Options** or **Custom Clone Flags** field and add `--depth=1`.
If Cloudways does not expose that option, use Option 1 (the pre-built ZIP)
instead.

To update an existing shallow clone later:

```bash
git pull --depth=1
```

---

### Option 3: Full Clone (Development Only)

Only recommended for local development where you need the full git history
(e.g. `git log`, `git blame`, bisecting bugs).

```bash
# Full clone — includes complete git history (~140 MB in .git/objects)
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git

cd mcp-ai-wpoos

# Install production dependencies
composer install --no-dev --classmap-authoritative

# Build JavaScript assets (only needed for development / customisation)
npm install
npm run build
```

---

### Cloudways-Specific Notes

| Deployment method | `.git/objects` size | Additional steps needed |
|---|---|---|
| Pre-built ZIP (Option 1) | **0 — no `.git` folder** | Upload via WP admin or SSH extract |
| Shallow clone `--depth=1` (Option 2) | **~5–10 MB** (one commit) | `composer install --no-dev` |
| Full clone (Option 3) | **~140 MB** | `composer install --no-dev` + `npm run build` |

**It is not possible to exclude `.git/objects/` from a regular `git clone`** —
that directory is the repository itself.  The two practical alternatives are
a shallow clone (depth=1) or using the pre-built deployment ZIP.

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
