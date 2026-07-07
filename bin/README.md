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

## Places Enrichment Tools

Scripts for importing, cleaning, and enriching Places CPT data from HTTrack mirrors and APIs.

### `enrich_places.php` — Batch Geocoding

Fills missing coordinates using Nominatim (free) or Google Geocoding API.

```bash
# Configure (optional)
echo '{"batch_size":10,"limit":200,"sleep":1,"provider":"nominatim","resume":true}' > wp-content/uploads/enrich_places_config.json

# Run
wp --user=1 eval-file bin/enrich_places.php
```

### `enrich_google.php` — Google Places Enrichment

Auto-fills ratings, Place IDs, phones, and websites via Google Places API.

```bash
wp --user=1 eval-file bin/enrich_google.php
```

### `enrich_social_timed.php` — Social Link Enrichment

Searches TripAdvisor, Booking.com, and Facebook with built-in rate limiting. Requires Brave Search API key in plugin settings.

```bash
# Configure
echo '{"batch_size":8,"search_delay":2,"max_searches":24,"targets":["tripadvisor","booking","facebook"]}' > wp-content/uploads/enrich_social_config.json

# Run (resumable)
wp --user=1 eval-file bin/enrich_social_timed.php
```

### `cleanup_places.php` — Reclassify & Clean

Fixes miscategorized places, deletes non-place content, creates new type taxonomies.

```bash
wp --user=1 eval-file bin/cleanup_places.php
```

### `link_place_children.php` — Parent-Child Linking

Links child places (attractions, hotels, tales) to their parent cities by URL matching.

```bash
wp --user=1 eval-file bin/link_place_children.php
```

### `enrich_contacts.php` — Manual Contact Data

Batch-applies known phone, email, website, and social links for key locations.

```bash
wp --user=1 eval-file bin/enrich_contacts.php
```

### `enhance_final.php` — Type Fixes & Metadata

Final pass for type corrections and known metadata (ratings, websites, Google Place IDs).

```bash
wp --user=1 eval-file bin/enhance_final.php
```
