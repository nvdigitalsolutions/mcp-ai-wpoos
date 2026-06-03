# PARA Methodology

The Open Operator System Pro plugin includes an opt-in implementation of
Tiago Forte's **PARA** organizational framework
(*Projects, Areas, Resources, Archives*) for the Project Management
Toolkit.

## Enabling

1. Enable the **Project Management Toolkit** under *Settings → NV oOS*.
2. Enable **PARA Organization** in the same panel
   (`enable_para_organization = true`).

PARA is gated by the Pro feature flag `WP_MCP_AI_PRO_VERSION`.

## Buckets

| Bucket | Definition |
|--------|------------|
| **Projects** | Short-term efforts with a goal and deadline. |
| **Areas** | Ongoing responsibilities with a standard to maintain. |
| **Resources** | Topical reference material useful to current/future projects. |
| **Archives** | Inactive items from any of the other three. |

The four root terms (`projects`, `areas`, `resources`, `archives`) are
locked: they cannot be deleted or renamed. User-created sub-buckets are
allowed (e.g. `Areas → Health`).

## Data model

- Hierarchical taxonomy: `mcp_ai_para`.
- Attached to: `mcp_ai_project`, `mcp_ai_task`, `mcp_ai_event`, `mcp_ai_area`,
  `mcp_ai_doc_tpl`, `mcp_ai_doc_record` (filterable via
  `wp_mcp_ai_para_object_types`).
- New CPT: `mcp_ai_area` for ongoing responsibilities, with meta:
  `_para_standard`, `_para_owner`, `_para_review_cadence`, `_para_last_reviewed`.

## Lifecycle automation

The `WP_MCP_AI_PARA_Lifecycle` service handles:

- **Auto-archive on project completion**: when a project's `_project_status`
  becomes `completed` or `cancelled`, it is reassigned to the Archives bucket.
- **Daily sweep** (`wp_mcp_ai_para_lifecycle_sweep`): produces a transient
  cache of dormant Areas, dormant Resources, and archive candidates.
- **QMS → PARA**: documents marked obsolete in QMS are auto-moved to Archives.

Dormancy thresholds are filterable:

```php
add_filter( 'wp_mcp_ai_para_dormancy_days', fn() => 14 );           // Areas
add_filter( 'wp_mcp_ai_para_resource_dormancy_days', fn() => 60 ); // Resources
```

## Tools

| Tool | Purpose |
|------|---------|
| `para_classify_item` | Assign any post to a PARA bucket. |
| `para_move_to_archives` | Archive with reason (audited). |
| `para_create_area` | Create an Area with standard, owner, cadence. |
| `para_update_area` | Update an Area; mark reviewed. |
| `para_list_areas` | List Areas, filterable by owner/cadence. |
| `para_weekly_review` | Returns dashboard summary as structured data. |
| `para_promote_resource_to_project` | Common workflow: reference → actionable. |

## Hooks

### Actions

- `wp_mcp_ai_para_item_classified( int $post_id, string $new_bucket, string $previous_bucket, WP_Term $term, string $reason )`
- `wp_mcp_ai_para_archived( int $post_id, string $reason )`
- `wp_mcp_ai_para_unarchived( int $post_id, string $reason )`
- `wp_mcp_ai_para_sweep_complete( array $summary )`

### Filters

- `wp_mcp_ai_para_object_types` — which post types support PARA.
- `wp_mcp_ai_para_dormancy_days` — days before an Area is considered dormant.
- `wp_mcp_ai_para_resource_dormancy_days` — days before a Resource is considered stale.

## Operating rules

- One bucket per item (single-select metabox).
- Items move between buckets as their status changes — never by deletion.
- Archives are never destroyed by automation; manual purge requires capability checks.
- Weekly review surfaces dormant items but never auto-archives Areas.
