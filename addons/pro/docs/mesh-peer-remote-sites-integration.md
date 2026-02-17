# Mesh Peer Management in Remote Sites (Pro)

## Overview

Pro users can manage mesh peer connections through the **Remote Sites** interface, providing a unified location for all remote connections including mesh peers, WordPress sites, WooCommerce, chat channels, and more.

## Key Features

### Unified Management
- Manage all remote connections in one place
- Consistent UI/UX across connection types
- Test any connection with a single click
- View connection status at a glance

### Bidirectional Sync
- Add mesh peers in Remote Sites OR Advanced Settings
- Changes automatically sync between both locations
- Delete from either location - reflected everywhere
- No duplication or data loss

### Visual Integration
- Purple "Mesh Peer" badge in connection list
- Matches AI Peers CPT purple MESH badge
- Clear distinction from other connection types
- Health status indicators

## Getting Started

### Prerequisites

1. **Pro Addon Active**: NV oOS Pro must be installed and activated
2. **Base Plugin Updated**: Base plugin must include mesh peer testing features
3. **Federation Enabled**: Both mesh computing and federation directory must be enabled

### Enabling Mesh & Federation

1. Navigate to **Settings → Advanced → Federation & Mesh**
2. Enable **Mesh Computing**
3. Enable **Federation Directory**
4. Click **Save Changes**

## Adding a Mesh Peer in Remote Sites

### Step-by-Step

1. **Navigate to Remote Sites**
   - Go to **NV oOS Pro → Remote Sites**

2. **Click "Add Connection"**
   - Click the "Add Connection" button at the top

3. **Select Connection Type**
   - In the "Connection Type" dropdown
   - Select **"Mesh Peer (Distributed AI)"**

4. **Enter Connection Details**
   - **Name**: Friendly name (e.g., "Production Server")
   - **Site URL**: Full URL of remote site (e.g., `https://prod.example.com`)
   - **Mesh Inbound API Key**: Copy from remote site's mesh settings

5. **Test Connection** (Optional but Recommended)
   - Click the "Test Connection" button
   - Wait for results (5-15 seconds)
   - ✅ Green success = Connection working
   - ❌ Red error = Check URL and API key

6. **Save Connection**
   - Click "Save Connection"
   - Connection added to list

### What Happens Behind the Scenes

When you save a mesh peer connection:

1. **Remote Sites Storage**: Connection saved with encrypted API key
2. **Mesh Settings Sync**: Automatically added to `mesh_peer_sites` setting
3. **AI Peer CPT Creation**: Entry created and visible in AI Peers menu
4. **Test Results**: Health status updated with last verified timestamp

## Testing Mesh Peer Connections

The connection test performs three checks:

1. **Reachability** - Verifies site is accessible
2. **Federation Discovery** - Confirms plugin installed and federation enabled
3. **MCP Authentication** - Validates API key

See [base documentation](../features/federation/mesh-peer-connection-testing.md) for full details.

## Bidirectional Sync

Changes sync automatically between:
- Remote Sites (Pro interface)
- Advanced Settings → Federation & Mesh (base interface)
- AI Peers CPT (read-only view)

**Add anywhere, visible everywhere!**

## Best Practices

1. **Use descriptive names** for easy identification
2. **Test before deploying** to production
3. **Secure API keys** - never commit to version control
4. **Monitor health status** regularly
5. **URL changes create new peer** - delete old, add new instead

## Troubleshooting

### Sync Issues
- Verify mesh computing and federation directory enabled
- Check Pro addon is active
- Re-save the connection

### Connection Failures
- Verify URL is correct
- Check API key from remote site
- Ensure remote site has mesh enabled
- Test network connectivity

## Related Documentation

- [Mesh Peer Testing](../features/federation/mesh-peer-connection-testing.md)
- [Mesh Peer CPT Sync](../features/federation/mesh-peer-cpt-sync.md)
- [Federation Setup](../guides/admin/FEDERATION_SETUP_GUIDE.md)

## Summary

Pro users get unified mesh peer management with:
- ✅ All connections in one interface
- ✅ Automatic sync to base settings  
- ✅ Visual consistency with purple badges
- ✅ Same test workflow as other connections
- ✅ Flexible management from either location

Choose the interface that works best for you - changes sync automatically!
