# PHPUnit Test Framework Packaging - Quick Reference

## Problem
The PHPUnit test framework and development dependencies (~140 MB) are needed for testing but shouldn't be committed to the main repository, making it difficult to set up testing in offline or air-gapped environments.

## Solution
Two scripts that package and extract development dependencies separately from the main plugin:

### 1. Package Script: `bin/package-vendor-dev.sh`
Creates a `vendor-dev.zip` archive (~140 MB) containing all development dependencies.

**Requirements:**
- Composer installed
- Internet access
- `zip` command available

**Usage:**
```bash
./bin/package-vendor-dev.sh
```

**Output:** `vendor-dev.zip` in the root directory

**Contains:**
- PHPUnit test framework
- PHP_CodeSniffer & WordPress Coding Standards  
- WordPress stubs for IDE support
- PHP compatibility checker
- Sebastian testing tools
- Yoast PHPUnit polyfills
- Other development dependencies

### 2. Install Script: `bin/install-vendor-dev.sh`
Extracts `vendor-dev.zip` and makes development tools available.

**Requirements:**
- `vendor-dev.zip` file in root directory
- `unzip` command available

**Usage:**
```bash
./bin/install-vendor-dev.sh
```

**Result:** Development dependencies installed in `vendor/` directory

## Workflow

### For Developers with Internet Access
Use composer normally:
```bash
composer install
composer run test
```

### For Air-Gapped/Offline Environments

**Step 1:** On a machine with internet, create the package:
```bash
git clone [repository]
cd wp-mcp-ai
composer install
./bin/package-vendor-dev.sh
```

**Step 2:** Transfer `vendor-dev.zip` to target environment

**Step 3:** On target machine, extract the package:
```bash
./bin/install-vendor-dev.sh
```

**Step 4:** Run tests:
```bash
composer run test
```

## Key Points

- ✅ Production dependencies (~5.6 MB) remain committed to repository
- ✅ Dev dependencies (~140 MB) available as optional download
- ✅ No composer or internet needed on target machine
- ✅ Scripts validated with shellcheck
- ✅ .gitignore excludes vendor-dev.zip automatically

## File Sizes

| Component | Size | Committed? |
|-----------|------|------------|
| Production vendor | ~5.6 MB | Yes |
| Dev vendor (vendor-dev.zip) | ~140 MB | No |
| Plugin files (PHP/JS/CSS) | ~7 MB | Yes |

## Documentation

- **[TESTING.md](TESTING.md)** - Complete testing guide
- **[BUILD.md](BUILD.md)** - Build and dependency management
- **[README.md](README.md)** - Main plugin documentation

## Troubleshooting

**Q: Package script fails with "Composer is required"**
A: Install composer first: https://getcomposer.org/

**Q: Install script fails with "vendor-dev.zip not found"**
A: Run `./bin/package-vendor-dev.sh` first or obtain the zip file from your distribution source

**Q: Tests fail after installing vendor-dev**
A: Run `composer run test:install` to set up WordPress test environment

**Q: Package size seems wrong**
A: Run `du -sh vendor-dev.zip` to check actual size

## Security

- Scripts use `set -euo pipefail` for safe error handling
- Temporary directories cleaned up on exit
- No secrets or credentials stored
- Shellcheck validated (no warnings)

## License

Same as main plugin: GPLv3 or later
