# Production Installation Instructions

After cloning this repository for production use, run:

```bash
composer install --no-dev --classmap-authoritative
```

This will:
- Install only production dependencies (excludes dev packages like PHPUnit)
- Generate an optimized autoloader with class maps
- Prepare the plugin for deployment

## Dependencies Added

This PR adds `phpoffice/phpspreadsheet` (^1.29|^2.0) as a production dependency for Excel import functionality in the regulatory registration toolkit.
