# Utility Scripts - NV oOS

This directory contains utility scripts for development, testing, and maintenance of the NV oOS plugin.

## Migration Scripts

### `migrate-settings-to-connections.php`

Migrates API credentials from plugin settings to Remote Site Connections.

**Usage:**
```bash
# Dry run (shows what would be migrated)
php bin/migrate-settings-to-connections.php --dry-run

# Run migration
php bin/migrate-settings-to-connections.php

# With verbose output
php bin/migrate-settings-to-connections.php --verbose
```

**Supported Services:**
- iSAMS (School Management)
- Flowhub (POS/Retail)
- PayHere (Payment Gateway)
- QuickBooks (Accounting)

**Documentation:** See [docs/REMOTE_CONNECTION_MIGRATION.md](../docs/REMOTE_CONNECTION_MIGRATION.md) for detailed migration guide.

---

## Other Utility Scripts

For information about screenshot capture tools, see [README-SCREENSHOT-TOOLS.md](README-SCREENSHOT-TOOLS.md).

For other development utilities, run individual scripts with `--help` flag where available.
