# Production Deployment Verification

## Quick Start

Before deploying the plugin to production, run the verification script:

```bash
php bin/check-production-deployment.php
```

## What It Checks

The script performs comprehensive verification of your production deployment:

### 1. Vendor Directory
- ✓ Exists and is accessible
- ✓ Contains all required packages

### 2. Composer Autoloader
- ✓ `vendor/autoload.php` exists
- ✓ Loads without errors
- ✓ Optimized for production (`classmap-authoritative`)

### 3. Critical Packages
Verifies presence of all required dependencies:
- ralouphie/getallheaders
- symfony/http-client
- symfony/validator
- symfony/cache
- symfony/filesystem
- symfony/process
- rahul900day/tiktoken-php
- nyholm/psr7
- league/oauth2-client

### 4. Production Readiness
- ✓ No dev dependencies present (phpunit, phpcs, etc.)
- ✓ File permissions are correct
- ✓ Plugin structure is valid

## Exit Codes

- **0** = All checks passed, ready for production deployment
- **1** = One or more checks failed, review output for fixes

## Common Issues and Fixes

### Missing Vendor Directory

**Error:**
```
✗ Vendor directory exists
```

**Fix:**
```bash
composer install --no-dev --prefer-dist --classmap-authoritative
```

### Dev Dependencies Found

**Error:**
```
✗ No dev dependencies present
  Found dev package: vendor/phpunit
```

**Fix:**
```bash
rm -rf vendor/
composer install --no-dev --prefer-dist --classmap-authoritative
```

### Autoloader Not Optimized

**Error:**
```
✗ Autoloader is optimized for production (classmap-authoritative)
```

**Fix:**
```bash
composer install --no-dev --prefer-dist --classmap-authoritative
```

## Direct Cloning for Production

The repository is **production-ready** and can be cloned directly:

```bash
# Clone the repository
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git

# Verify it's ready
cd mcp-ai-wpoos
php bin/check-production-deployment.php

# If all checks pass, deploy to WordPress
cp -r . /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
```

**No build step required!** The repository includes:
- ✓ Optimized production autoloader
- ✓ All vendor dependencies (production only)
- ✓ Minified assets
- ✓ Complete plugin code

## Integration with CI/CD

Add the verification script to your deployment pipeline:

### GitHub Actions Example

```yaml
- name: Verify Production Deployment
  run: php bin/check-production-deployment.php
```

### GitLab CI Example

```yaml
verify-deployment:
  script:
    - php bin/check-production-deployment.php
```

### Manual Deployment Checklist

1. ☐ Pull latest code from repository
2. ☐ Run `php bin/check-production-deployment.php`
3. ☐ Verify all checks pass (exit code 0)
4. ☐ Deploy to staging environment
5. ☐ Test critical functionality
6. ☐ Deploy to production
7. ☐ Verify plugin activation in WordPress admin

## Additional Resources

- **BUILD.md** - Complete build and deployment documentation
- **docs/troubleshooting/deployment/** - Deployment troubleshooting guides
- **RELEASE_CHECKLIST.md** - Release preparation checklist

## Support

If you encounter issues with deployment verification:

1. Check the error messages and suggested fixes
2. Review BUILD.md troubleshooting section
3. Verify your PHP and Composer versions meet requirements
4. Ensure file permissions are correct (755 for directories, 644 for files)

For persistent issues, please open an issue on GitHub with:
- Complete output from `php bin/check-production-deployment.php`
- Your PHP version (`php -v`)
- Your Composer version (`composer -V`)
- Your deployment method (direct clone, ZIP, composer, etc.)
