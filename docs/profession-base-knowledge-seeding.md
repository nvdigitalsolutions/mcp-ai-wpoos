# Profession Base Knowledge Seeding

## Overview

The profession base knowledge seeding feature automatically generates knowledge documents and sets supported MIME types for all professions in the system. This happens once after initial profession seeding and can be refreshed when professions are re-seeded from settings.

## How It Works

### Automatic Seeding

1. **On Plugin Activation**: After professions are seeded (at `admin_init` priority 20), the base knowledge seeder runs (at priority 30)
2. **Creates Knowledge Documents**: For each profession:
   - **First**: Checks for existing knowledge document at `includes/knowledge-base/profession-documents/{slug}.txt`
   - **If found**: Uses the existing document (e.g., `tax_advisor.txt`, `accountant.txt`)
   - **If not found**: Generates a Markdown-formatted text file from profession metadata containing:
     - Profession title and overview
     - Role description
     - Expertise areas
     - Warnings/disclaimers
     - Knowledge base content
3. **Stores Attachments**: Files are created as WordPress attachments in `wp-content/uploads/wp-mcp-ai/profession-knowledge/`
4. **Updates Metadata**:
   - Adds attachment IDs to `META_MEMORY_FILES`
   - Populates `META_SUPPORTED_MIME_TYPES` based on profession category

### Supported MIME Types by Category

The system assigns appropriate MIME types based on profession category:

- **Advisory/Financial/Legal**: text/plain, application/pdf, DOCX
- **Creative**: text/plain, image/jpeg, image/png, image/webp, application/pdf
- **Technical**: text/plain, application/pdf, text/csv
- **Healthcare**: text/plain, application/pdf, image/jpeg, image/png
- **Other**: text/plain, application/pdf

### Idempotency

The seeding process is idempotent:
- Each attachment has meta markers (`_wp_mcp_ai_seeded_profession_slug` and `_wp_mcp_ai_seeded_profession_doc_type`)
- Running the seeder multiple times won't create duplicate attachments
- Existing attachments are reused unless force mode is enabled

### Force Mode

When professions are re-seeded from the admin settings page, the base knowledge seeder runs in force mode (`seed_base_knowledge(true)`), which:
- Re-creates knowledge documents with updated content
- Refreshes supported MIME types
- Updates all profession metadata

## Source Documents

The seeder first looks for existing knowledge documents in:
```
includes/knowledge-base/profession-documents/
├── tax_advisor.txt
├── accountant.txt
├── lawyer.txt
└── ... (181 profession documents)
```

These pre-written documents contain curated knowledge content for each profession. If a matching file exists (based on profession slug), it will be used as the source content.

## Files Created

Knowledge documents are stored at:
```
wp-content/uploads/wp-mcp-ai/profession-knowledge/profession-{slug}-base-knowledge.txt
```

Example filename:
```
profession-tax_advisor-base-knowledge.txt
```

## Database Schema

### Post Meta Fields

| Meta Key | Type | Description |
|----------|------|-------------|
| `_wp_mcp_ai_profession_memory_files` | array | Array of attachment IDs |
| `_wp_mcp_ai_profession_supported_mime_types` | array | Array of MIME type strings |

### Attachment Meta Markers

| Meta Key | Value | Description |
|----------|-------|-------------|
| `_wp_mcp_ai_seeded_profession_slug` | string | Profession slug (e.g., "tax_advisor") |
| `_wp_mcp_ai_seeded_profession_doc_type` | string | Always "base_knowledge" |

## Developer API

### Main Class

`WP_MCP_AI_Profession_Base_Knowledge_Seeder`

### Public Methods

#### `init()`
Initializes the seeder. Called once during plugin initialization.

```php
WP_MCP_AI_Profession_Base_Knowledge_Seeder::init();
```

#### `seed_base_knowledge( $force = false )`
Seeds base knowledge for all professions.

```php
// Seed normally (idempotent)
WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge();

// Force refresh
WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );
```

**Parameters:**
- `$force` (bool): If true, recreates documents even if already seeded

## Testing

Run the test suite to verify functionality:

```bash
vendor/bin/phpunit tests/test-profession-base-knowledge-seeder.php
```

### Test Coverage

- Attachment creation and metadata population
- MIME type assignment by category
- Idempotency (no duplicates)
- Force mode refresh
- File system verification

## Troubleshooting

### No attachments created

**Possible causes:**
1. Professions not seeded yet (check `wp_mcp_ai_professions_seeded` option)
2. Upload directory permissions issue
3. WP_DEBUG enabled - check error logs

**Solution:**
```php
// Verify professions are seeded
if ( get_option( 'wp_mcp_ai_professions_seeded' ) ) {
    // Force base knowledge seeding
    WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );
}
```

### Duplicate attachments

This shouldn't happen due to idempotency checks, but if it does:
1. Delete all profession knowledge attachments
2. Delete the `wp_mcp_ai_profession_base_knowledge_seeded` option
3. Re-run the seeder

```php
// Clean up and re-seed
delete_option( 'wp_mcp_ai_profession_base_knowledge_seeded' );
WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );
```

### MIME types not updating

Use force mode to refresh:
```php
WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );
```

## Integration Points

### Admin Settings Page

The "Reseed Professions" button in the admin settings automatically calls the base knowledge seeder in force mode after updating professions.

**Location:** Settings → WP oOS → Professions

### AJAX Handler

`WP_MCP_AI_Admin_AJAX_Handlers::handle_reseed_professions()`

After successfully reseeding professions, calls:
```php
WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );
```

## Security Considerations

- All content is sanitized using `wp_strip_all_tags()` before being written to files
- Files are created using WordPress's `wp_upload_bits()` function
- Attachments are properly associated with parent profession posts
- Only admins can trigger reseeding (`manage_options` capability required)

## Performance

- Seeding runs once on initial activation
- Each profession generates ~1-3KB text file
- For 60 professions: ~60-180KB total storage
- Minimal performance impact after initial seeding

## Future Enhancements

Possible improvements for future versions:
- Support for multiple knowledge documents per profession
- Custom MIME type configuration per profession
- Automatic update when profession content changes
- Export/import of knowledge documents
- Vector store integration for semantic search
