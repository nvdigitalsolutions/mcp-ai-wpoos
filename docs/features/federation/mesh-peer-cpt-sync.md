# Mesh Peer Site CPT Synchronization

## Overview

When mesh peer sites are configured in the Advanced Settings → Federation & Mesh tab, the system now automatically creates corresponding `ai_peer` Custom Post Type (CPT) entries. This provides visibility and management of mesh connections through the AI Peers admin interface.

## How It Works

### Automatic Synchronization

1. **When you add a mesh peer** in Settings → Advanced → Federation & Mesh → Mesh Peer Sites:
   - A new `ai_peer` CPT post is automatically created
   - Connection type is set to "mesh"
   - Metadata includes site URL, name, and unique mesh peer ID

2. **When you update a mesh peer**:
   - The corresponding `ai_peer` CPT post is updated
   - Title and URL are refreshed
   - Last verified timestamp is updated

3. **When you remove a mesh peer**:
   - The corresponding `ai_peer` CPT post is automatically deleted

### Visual Indicators

**AI Peers List Page** (`/wp-admin/edit.php?post_type=ai_peer`):
- A "Type" column shows whether each peer is:
  - **MESH** (purple badge) - Manually configured via mesh settings
  - **FEDERATION** (blue badge) - Registered via federation directory

**AI Peer Edit Page**:
- Connection Type field shows the source of the peer
- Mesh peers include a note: "This peer was configured manually via mesh networking settings"
- Federation peers include: "This peer was registered through the federation directory service"

## Requirements

Both of these settings must be enabled for mesh peer CPT sync to work:

1. **Enable Mesh Computing** (`enable_mesh` setting)
2. **Enable Federation Directory** (`enable_federation_directory` setting)

The Federation Directory service is required because it manages the `ai_peer` CPT infrastructure.

## Configuration Example

### Via Admin UI

1. Navigate to **Settings → Advanced → Federation & Mesh**
2. Enable "Enable Mesh Computing"
3. Enable "Enable Federation Directory"
4. Scroll to "Mesh Peer Sites" section
5. Click "Add Peer Site"
6. Fill in:
   - Name: `Production Server`
   - Site URL: `https://production.example.com`
   - API Key: `mesh_xxxxxxxxxxxxxxxx`
7. Click "Save Changes"
8. Navigate to **AI Peers** menu
9. You should see your mesh peer listed with a purple "MESH" badge

### Programmatic Access

```php
// Manually trigger synchronization
WP_MCP_AI_Mesh_Peer_Sync::manual_sync();

// Check if a peer is mesh-connected
$connection_type = get_post_meta( $peer_id, '_wp_mcp_ai_connection_type', true );
if ( 'mesh' === $connection_type ) {
    // This is a mesh peer
}

// Get mesh peer ID
$mesh_peer_id = get_post_meta( $peer_id, '_wp_mcp_ai_mesh_peer_id', true );
```

## Metadata Stored

For mesh peers, the following metadata is stored in the `ai_peer` CPT:

| Meta Key | Description | Example |
|----------|-------------|---------|
| `_wp_mcp_ai_connection_type` | Connection type identifier | `mesh` |
| `_wp_mcp_ai_mesh_peer_id` | Unique mesh peer identifier | `mesh_abc123...` |
| `_wp_mcp_ai_peer_site_url` | Peer site URL | `https://example.com` |
| `_wp_mcp_ai_peer_site_name` | Peer display name | `Production Site` |
| `_wp_mcp_ai_peer_health_status` | Health status | `unknown` |
| `_wp_mcp_ai_peer_last_verified` | Last check timestamp | `2025-01-15 10:30:00` |
| `_wp_mcp_ai_peer_capabilities` | JSON array of capabilities | `["query_remote_site","distributed_processing"]` |

## Validation

The synchronization includes validation to ensure data integrity:

- **Name** must not be empty
- **URL** must not be empty
- **URL** must be a valid URL format
- Invalid peers are skipped during sync

## Technical Details

### Class: `WP_MCP_AI_Mesh_Peer_Sync`

Location: `/includes/class-wp-mcp-ai-mesh-peer-sync.php`

#### Key Methods

- `sync_mesh_peers( $mesh_peers )` - Main synchronization method
- `sync_mesh_peers_on_option_update()` - Hook callback for option updates
- `create_mesh_peer_post()` - Creates new ai_peer CPT for mesh connection
- `update_mesh_peer_post()` - Updates existing mesh peer CPT
- `cleanup_removed_mesh_peers()` - Removes CPT entries for deleted mesh peers
- `manual_sync()` - Static method for manual synchronization trigger

### Hooks

The synchronization is triggered by:
```php
add_action( 'update_option_wp_mcp_ai_settings', array( $this, 'sync_mesh_peers_on_option_update' ), 10, 3 );
```

### Initialization

The sync class is initialized in `WP_MCP_AI_Federation::maybe_load_federation_features()` when:
- Mesh computing is enabled
- Federation directory is enabled

## Troubleshooting

### Mesh peers not appearing in AI Peers list

1. Check that both required settings are enabled:
   - Settings → Advanced → Federation & Mesh → Enable Mesh Computing
   - Settings → Advanced → Federation & Mesh → Enable Federation Directory

2. Manually trigger sync:
   ```php
   WP_MCP_AI_Mesh_Peer_Sync::manual_sync();
   ```

3. Check WordPress error logs for any sync errors

### Duplicate entries

If you see duplicate AI Peer entries for the same mesh peer:
1. The mesh peer ID is based on the URL
2. Changing the URL creates a new peer
3. Remove old peer entries manually if needed

### CPT not updating when settings change

1. Verify the settings are being saved correctly
2. Check that the `update_option` hook is firing
3. Review error logs for sync failures

## Related Files

- `/includes/class-wp-mcp-ai-mesh-peer-sync.php` - Main synchronization class
- `/includes/class-wp-mcp-ai-ai-peer-cpt.php` - AI Peer CPT definition
- `/includes/class-wp-mcp-ai-federation.php` - Federation bootstrap
- `/includes/class-wp-mcp-ai-federation-settings.php` - Settings helper
- `/tests/test-mesh-peer-sync.php` - Unit tests
- `/includes/admin/sections/class-wp-mcp-ai-section-advanced.php` - Admin UI

## See Also

- [Federation Setup Guide](../guides/admin/FEDERATION_SETUP_GUIDE.md)
- [Mesh Compute Pooling](../features/federation/mesh-compute-pooling.md)
- [Federation Discovery](../features/federation/federation-discovery.md)
