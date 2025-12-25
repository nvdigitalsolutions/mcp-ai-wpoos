# Dataset Resync Instructions

## How to Force Resync of Profession Datasets

After adding new dataset mappings to `profession-dataset-mappings.php`, existing professions in the database won't automatically receive these datasets until you force a resync.

### Option 1: Via WP-CLI (Recommended)

```bash
# Delete the sync completion flag to trigger resync on next admin page load
wp option delete wp_mcp_ai_professions_datasets_synced

# The resync will happen automatically next time you visit the WordPress admin
```

### Option 2: Via MySQL/Database

```sql
DELETE FROM wp_options WHERE option_name = 'wp_mcp_ai_professions_datasets_synced';
```

### Option 3: Via PHP/WordPress Admin

Add this temporary code to your theme's `functions.php` or use a plugin like "Code Snippets":

```php
// Run once to force dataset resync
add_action('admin_init', function() {
    if (current_user_can('manage_options') && isset($_GET['force_dataset_resync'])) {
        delete_option('wp_mcp_ai_professions_datasets_synced');
        wp_redirect(admin_url('edit.php?post_type=mcp_ai_profession&resynced=1'));
        exit;
    }
});
```

Then visit: `yoursite.com/wp-admin/?force_dataset_resync=1`

## How the Resync Works

1. The `resync_profession_datasets()` function runs on every `admin_init` hook
2. It checks if the `wp_mcp_ai_professions_datasets_synced` option exists
3. If not, it loops through all professions:
   - Checks if the profession slug has dataset mappings
   - If yes, and the profession has no datasets assigned, it assigns them
4. Once all professions with mappings have datasets, it sets the option to prevent future runs

## Verify Resync Worked

1. Go to WordPress Admin → Professions
2. Edit a profession like "Chef"
3. Scroll to the "Preferred Datasets" metabox
4. You should see datasets pre-selected (e.g., Food-101 and Yelp Reviews for Chef)

## Professions That Will Receive Datasets

After this update, the following 89 professions will have datasets auto-assigned:
- Chef, Restaurant Manager, Bartender (food-related)
- Teachers, Tutors, IGCSE Tutors (education)
- Journalist, Writer, PR Specialist (journalism/writing)
- Physicians, Nurses, Healthcare professionals (medical)
- And 50+ more...

See `includes/professions/profession-dataset-mappings.php` for the complete list.
