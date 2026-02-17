# Mesh Peer CPT Synchronization - Visual Guide

## What Changes for Users

### Before This Feature
❌ Users added mesh peers in settings, but saw nothing in AI Peers menu  
❌ No way to verify mesh connections were successful  
❌ Confusion about whether peer was properly configured  

### After This Feature
✅ Mesh peers automatically appear in AI Peers menu  
✅ Clear visual distinction between mesh and federation peers  
✅ Easy to manage and monitor all peer connections in one place  

---

## Admin Interface Changes

### 1. AI Peers List Page
**Location**: `/wp-admin/edit.php?post_type=ai_peer`

**New Column Added**: "Type"

```
┌────────────────────────────────────────────────────────────────────┐
│ AI Peers                                                    Add New │
├────────────────────────────────────────────────────────────────────┤
│ ☐  Title              Type         Health    Capabilities  Regions │
├────────────────────────────────────────────────────────────────────┤
│ ☐  Production Site    [MESH]       ● Healthy   2 tools     us-east│
│                       (purple)                                      │
│                                                                     │
│ ☐  Partner Network    [FEDERATION] ● Healthy   15 tools    global │
│                       (blue)                                        │
└────────────────────────────────────────────────────────────────────┘
```

**Badge Colors**:
- **MESH** = Purple background (#7e57c2) - Manually configured via settings
- **FEDERATION** = Blue background (#2196f3) - Auto-registered via directory

### 2. Edit AI Peer Page
**Location**: `/wp-admin/post.php?post={id}&action=edit&post_type=ai_peer`

**New Field in Peer Information Box**:

```
┌─────────────────────────────────────────────────────────┐
│ Peer Information                                        │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ Connection Type:  [MESH]                                │
│                   (purple badge)                        │
│                   This peer was configured manually     │
│                   via mesh networking settings.         │
│                                                         │
│ Site URL:         https://production.example.com        │
│                                                         │
│ Well-Known URL:   —                                     │
│                   (Not set for mesh peers)              │
└─────────────────────────────────────────────────────────┘
```

### 3. Settings Page - Mesh Peer Sites
**Location**: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`

**Behavior Changes**:

**Before**:
```
Mesh Peer Sites
┌─────────────────────────────────────────────────────────┐
│ Name          Site URL                    API Key       │
├─────────────────────────────────────────────────────────┤
│ Prod Server   https://prod.example.com    mesh_xyz123   │
└─────────────────────────────────────────────────────────┘

[Add Peer Site]
```
→ Saving this created NO CPT entry ❌

**After**:
```
Mesh Peer Sites
┌─────────────────────────────────────────────────────────┐
│ Name          Site URL                    API Key       │
├─────────────────────────────────────────────────────────┤
│ Prod Server   https://prod.example.com    mesh_xyz123   │
└─────────────────────────────────────────────────────────┘

[Add Peer Site]
```
→ Saving this now CREATES ai_peer CPT automatically ✅

---

## User Flow Example

### Adding a Mesh Peer

1. **Navigate to Settings**
   ```
   WordPress Admin → Settings → Advanced → Federation & Mesh
   ```

2. **Enable Required Settings**
   - ☑ Enable Mesh Computing
   - ☑ Enable Federation Directory
   - Click "Save Changes"

3. **Add Mesh Peer**
   - Scroll to "Mesh Peer Sites" section
   - Click "Add Peer Site"
   - Fill in:
     - Name: `Production Server`
     - Site URL: `https://production.example.com`
     - API Key: `mesh_xxxxxxxxxxxxxxxx` (from remote site's inbound key)
   - Click "Save Changes"

4. **View in AI Peers Menu**
   ```
   WordPress Admin → AI Peers
   ```
   
   You will now see:
   ```
   Production Server    [MESH]    ● Unknown    2 tools    global
                       (purple)
   ```

5. **Edit Peer Details**
   - Click on "Production Server"
   - See connection type badge
   - View all peer metadata
   - (Note: Mesh peers show limited metadata compared to federation peers)

### Updating a Mesh Peer

1. Go back to Settings → Advanced → Federation & Mesh
2. Modify the mesh peer name or URL
3. Click "Save Changes"
4. The AI Peer CPT is automatically updated ✅

### Removing a Mesh Peer

1. Go to Settings → Advanced → Federation & Mesh
2. Click "Remove" next to the mesh peer
3. Click "Save Changes"
4. The AI Peer CPT is automatically deleted ✅

---

## Key Visual Indicators

### Connection Type Badges

| Badge | Color | Meaning |
|-------|-------|---------|
| **MESH** | Purple (#7e57c2) | Manually configured peer via mesh settings |
| **FEDERATION** | Blue (#2196f3) | Auto-discovered peer via federation directory |

### Health Status Indicators

| Symbol | Color | Meaning |
|--------|-------|---------|
| ● Healthy | Green (#46b450) | Peer is responding normally |
| ● Degraded | Yellow (#ffb900) | Peer has connectivity issues |
| ● Down | Red (#dc3232) | Peer is not reachable |
| ● Unknown | Gray (#999) | Not yet tested (typical for new mesh peers) |

---

## Troubleshooting Visuals

### Problem: Mesh peer not appearing in AI Peers list

**Check 1**: Are both required settings enabled?
```
Settings → Advanced → Federation & Mesh
☑ Enable Mesh Computing         ← Must be checked
☑ Enable Federation Directory   ← Must be checked
```

**Check 2**: Did you save the mesh peer properly?
```
Mesh Peer Sites section:
- Name field is filled ✓
- Site URL field is filled ✓
- Clicked "Save Changes" ✓
```

**Check 3**: Is the AI Peers menu visible?
```
WordPress Admin sidebar → AI Peers
If not visible, federation directory may not be enabled.
```

### Problem: Duplicate AI Peer entries

If you see:
```
Production Server (1)    [MESH]
Production Server (2)    [MESH]
```

**Cause**: Changed the URL of a mesh peer, creating a new entry

**Fix**: 
1. Delete the old entry manually from AI Peers menu
2. Or use Settings → Mesh Peer Sites to remove old peer

---

## Technical Notes for Developers

### CPT Metadata Structure

Mesh peers store these meta fields:

```php
_wp_mcp_ai_connection_type       = 'mesh'
_wp_mcp_ai_mesh_peer_id          = 'mesh_abc123...'
_wp_mcp_ai_peer_site_url         = 'https://example.com'
_wp_mcp_ai_peer_site_name        = 'Peer Name'
_wp_mcp_ai_peer_health_status    = 'unknown'
_wp_mcp_ai_peer_last_verified    = '2025-01-15 10:30:00'
_wp_mcp_ai_peer_capabilities     = '["query_remote_site"]'
```

### Query for Mesh Peers Only

```php
$mesh_peers = get_posts( array(
    'post_type'  => 'ai_peer',
    'meta_query' => array(
        array(
            'key'   => '_wp_mcp_ai_connection_type',
            'value' => 'mesh',
        ),
    ),
) );
```

### Query for Federation Peers Only

```php
$federation_peers = get_posts( array(
    'post_type'  => 'ai_peer',
    'meta_query' => array(
        array(
            'key'     => '_wp_mcp_ai_connection_type',
            'value'   => 'mesh',
            'compare' => '!=',
        ),
    ),
) );
```

---

## Summary

This feature bridges the gap between mesh networking configuration and the AI Peers admin interface, providing:

✅ **Visibility**: All peer connections visible in one place  
✅ **Consistency**: Mesh and federation peers managed similarly  
✅ **Automation**: No manual CPT creation required  
✅ **Distinction**: Clear visual indicators for connection type  
✅ **Management**: Easy to monitor and maintain peer connections  

The purple MESH badge makes it immediately clear which peers are manually configured, while the blue FEDERATION badge indicates auto-discovered peers.
