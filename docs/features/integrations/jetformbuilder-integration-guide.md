# JetFormBuilder Integration Guide

## Overview

NV oOS integrates with JetFormBuilder to provide AI-powered form management tools. The integration supports listing forms, retrieving form fields, fetching submissions, and creating new submissions — all dispatched through a handler layer that normalises responses for MCP tools.

## Architecture

### Tool Files (`includes/tools/`)

| File | Tool Slug | Purpose |
|------|----------|---------|
| `class-wp-mcp-ai-tool-get-jetformbuilder-forms.php` | `get_jetformbuilder_forms` | List all JetFormBuilder forms |
| `class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php` | `get_jetformbuilder_submissions` | Fetch submissions for a form |
| `class-wp-mcp-ai-tool-get-all-form-submissions.php` | `get_all_form_submissions` | Cross-plugin unified submissions |

### Handler Layer (`includes/class-wp-mcp-ai-jetformbuilder-tool-handlers.php`)

The `WP_MCP_AI_JetFormBuilder_Tool_Handlers` class is the central dispatch hub. Tools call `dispatch()` with an operation name and payload; the handler translates these into REST API requests against JetFormBuilder's endpoints.

**Dispatch chain:**
```
Tool::execute()
  → WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch()
    → dispatch_internal()  (via rest_do_request)
    → dispatch_remote()    (via wp_remote_request + proxy token)
    → dispatch_via_connection()  (via Remote Site Manager)
```

## JetFormBuilder REST API

### Namespaces and Routes

JetFormBuilder uses two REST namespaces:

| Namespace | Used for |
|-----------|----------|
| `wp/v2` | CPT listing (jet-form-builder post type) |
| `jet-form-builder/v1` | Form fields, records, submissions |

### Mapped Operations

| Handler Operation | REST Route | Namespace | Method | Form ID Location |
|-------------------|-----------|-----------|--------|-----------------|
| `list_forms` | `jet-form-builder` | `wp/v2` | GET | N/A (CPT listing) |
| `get_form_fields` | `%s/fields` | `jet-form-builder/v1` | GET | URL segment (form ID) |
| `fetch_submissions` | `records/fetch-page` | `jet-form-builder/v1` | GET | `filters[form]` query param |
| `create_submission` | Unregistered route | `jet-form-builder/v1` | POST | N/A (not used by current tools) |

### `records/fetch-page` Endpoint

The JetFormBuilder submissions listing endpoint (`jet-form-builder/v1/records/fetch-page`) accepts:

| Parameter | Type | Description |
|-----------|------|-------------|
| `limit` | int | Maximum records per page |
| `page` | int | Page number |
| `sort` | object | Sort configuration |
| `filters[form]` | int | Form ID filter |
| `filters[status]` | string | Submission status filter |

**Response format:** `{ list: [...], total: N }`

**Capability required:** `manage_options`

> ⚠️ The `records/fetch-page` endpoint requires `manage_options` capability. Tools bypass this for local queries by reading the `jet_fb_records` table directly, which only needs `edit_posts`.

## Database Schema

JetFormBuilder stores submissions in custom tables:

| Table | Purpose |
|-------|---------|
| `{$wpdb->prefix}jet_fb_records` | Submission records (form_id, status, dates, user_id) |
| `{$wpdb->prefix}jet_fb_records_fields` | Individual field values per submission |

### Table Prefix

JetFormBuilder uses the prefix `jet_fb_` for all custom tables, defined in `Base_Db_Model::DB_TABLE_PREFIX`.

### Submission Storage

Submissions are only stored in these tables when the **Save Record** action is added to the form. Without this action, form submissions will not appear in the database.

## Custom Post Type

### Slug

JetFormBuilder registers its forms as a custom post type with the slug `jet-form-builder` (hyphen, not underscore).

Declared in `modules/post-type/module.php`:
```php
const SLUG = 'jet-form-builder';
```

### Capabilities

| Capability | Purpose |
|-----------|---------|
| `edit_jet_fb_forms` | Edit own forms |
| `edit_others_jet_fb_forms` | Edit others' forms |
| `publish_jet_fb_forms` | Publish forms |

### Shortcode

Forms are rendered using:
```
[jet_form_builder id="123"]
```

### REST Exposure

The CPT is exposed through the standard WordPress REST API at `wp/v2/jet-form-builder`. Access is gated by `edit_jet_fb_forms` capability (enforced by forcing `context=edit`).

## Capability Requirements

### Tool-Level Capabilities

| Tool | Required Capability |
|------|-------------------|
| `get_jetformbuilder_forms` | `edit_posts` (tool), `edit_jet_fb_forms` or `manage_options` (user check) |
| `get_jetformbuilder_submissions` | `edit_posts` (tool), `manage_options` (REST path) |
| `get_all_form_submissions` | `edit_posts` |

### Local vs REST Paths

The submissions tool uses a two-tier capability model:

1. **Local DB path** (default for local transport): Queries `jet_fb_records` directly. Requires `edit_posts`.
2. **REST dispatch path** (http transport or connection_id): Uses `records/fetch-page` endpoint. Requires `manage_options`.

The tool prefers the local DB path when available to avoid the stricter capability check.

## Form Discovery

### `get_all_form_submissions` Discovery Strategy

The unified submissions tool discovers forms using a priority-ordered approach:

1. **Primary:** Query `jet_fb_records` table directly for form IDs that have stored submissions. This is the authoritative source — it finds every form with records, including forms whose CPT posts may have been deleted.

2. **Secondary:** Supplement with forms from the REST API (`wp/v2/jet-form-builder`) to include forms that exist as CPT posts but have no submissions yet.

## Known Limitations

1. **`create_submission` endpoint:** JetFormBuilder does not expose a public REST endpoint for programmatic form submission. The handler maps this to `forms/%s/submit/` but this route is not registered by JetFormBuilder. This operation is retained for future compatibility but is not currently called by any tool.

2. **`manage_options` requirement:** The `records/fetch-page` REST endpoint requires `manage_options`. Tools work around this by querying the database directly, but remote or HTTP-transport requests will fail for users without this capability.

3. **Save Record action:** Form submissions only appear in the `jet_fb_records` table if the form includes the "Save Record" post-submit action. Forms without this action will have no discoverable submissions.

4. **CPT slug `jet-form-builder`:** Uses a hyphen, not an underscore. Code that constructs post type queries must use the exact slug.

## Version Compatibility

- **JetFormBuilder 3.x+:** Full support
- **Source reference:** JetFormBuilder v3.6.1 source code (publicly available on GitHub)
