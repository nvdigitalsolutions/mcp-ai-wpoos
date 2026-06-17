# Federation Directory Setup Guide

## Quick Answer: Where to Create Keys

You don't need to manually create any keys! The `mesh_inbound_api_key` is **automatically generated** when you enable Mesh Computing. Here's the proper order:

### Step 1: Enable Mesh Computing
1. Go to **WP Admin → NV oOS → Settings → Tools → Features**
2. Check **"Enable Mesh Computing"**
3. Click **Save Settings**
4. ✅ The `mesh_inbound_api_key` will be auto-generated

### Step 2: Enable Federation Directory
1. Go to **WP Admin → NV oOS → Settings → Advanced → Federation & Mesh**
2. Check **"Enable Federation Directory"**
3. Configure other federation settings (regions, data tags, QPS, etc.)
4. Click **Save Settings**
5. ✅ The AI Peers post type will be registered

### Step 3: View Your Mesh Inbound API Key
1. Stay on **Advanced → Federation & Mesh**
2. Scroll down to see **"Mesh Inbound API Key"** section
3. ✅ Copy this key to share with peer sites

## Current Issue: Checkbox Won't Save

Your test site shows that both features are enabled in the status section, but the "Enable Federation Directory" checkbox won't stay checked. This is the main problem we're solving.

### What I've Done

1. **Added Better UI Guidance**
   - Warning message when mesh is enabled but key is missing
   - Step-by-step instructions on how to use the mesh_inbound_api_key
   - Helpful info when no AI peers exist yet

2. **Added Debug Logging**
   - JavaScript console logs to track form submission
   - Logs show checkbox states before submission
   - Logs show subtab hidden field updates

3. **Created Debug Tools**
   - `FEDERATION_DIRECTORY_DEBUG.md` - Complete debugging guide
   - `bin/check-federation-settings.sh` - Script to check database state

### What You Need to Do

To help me fix the checkbox save issue, please:

1. **Deploy the changes:**
   ```bash
   cd /path/to/mcp-ai-wpoos
   git fetch origin
   git checkout copilot/enable-federation-directory
   ```

2. **Clear your browser cache** (very important!)
   - Press `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac)
   - Or use incognito/private mode

3. **Test with debug logging:**
   - Open browser DevTools (press F12)
   - Go to Console tab
   - Navigate to: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`
   - Try checking the "Enable Federation Directory" checkbox
   - Click "Save Settings"
   - **Take a screenshot of the Console tab**

4. **Check database state:**
   ```bash
   cd /path/to/mcp-ai-wpoos
   ./bin/check-federation-settings.sh
   ```
   **Send me the output**

## Understanding the Settings

### Enable Mesh Computing (Tools → Features)
- **Purpose**: Allows this site to participate in distributed AI workload processing
- **Effect**: Generates the `mesh_inbound_api_key` automatically
- **Used for**: Other sites connecting TO this site for mesh computing

### Enable Federation Directory (Advanced → Federation & Mesh)
- **Purpose**: Allows this site to discover and register other AI peers
- **Effect**: Registers the `ai_peer` Custom Post Type
- **Used for**: This site connecting to OTHER sites for federated AI

### Mesh Inbound API Key
- **Auto-generated when**: Mesh Computing is enabled
- **Format**: `mesh_` followed by random characters (e.g., `mesh_abc123def456...`)
- **Purpose**: Other sites use this key to authenticate when connecting to YOUR site
- **Where to use it**: Give this key to administrators of peer sites who want to add your site to their mesh network

### Federation Settings
All these fields are optional and have sensible defaults:

- **Federation Regions**: Default is `global`
- **Federation Data Tags**: Optional tags like `public`, `internal`
- **Federation QPS Limit**: Default is `5` queries per second
- **Federation Burst Capacity**: Default is `10`
- **Federation JWKS Keys**: Advanced - usually leave empty
- **Federation Price Hints**: Advanced - usually leave empty

### Mesh Peer Sites Configuration
JSON array of sites you want to connect TO:
```json
[
  {
    "url": "https://peer-site.com",
    "api_key": "mesh_xyz789...",
    "name": "Peer Site Name",
    "enabled": true
  }
]
```

**Where to get the `api_key`**: Ask the admin of the peer site for THEIR `mesh_inbound_api_key`

## Expected End State

Once everything is working, you should have:

### On the Advanced → Federation & Mesh page:
✅ Status section shows:
- Mesh Computing: Enabled
- Federation Directory: Enabled
- Registered AI Peers: 0 (or more if you've added some)

✅ Mesh Inbound API Key section shows:
- Your auto-generated key in a copyable text box
- Instructions on how to use it

✅ Mesh Peer Sites section (if configured):
- Table of peer sites you've configured
- Add/Remove buttons

✅ AI Peers section:
- Button to add new AI peers
- List of registered peers (if any)
- Link to AI Peers admin page

### In WordPress Admin Sidebar:
✅ New menu item: **AI Peers** (between Posts and Media)
- Clicking it shows all registered AI peers
- You can add/edit/delete peers like regular posts

## Troubleshooting

### "Mesh Inbound API Key Not Generated" warning appears
**Cause**: Mesh Computing is enabled but the key generation failed  
**Solution**: Click "Save Settings" again to trigger key generation

### Checkbox won't stay enabled
**Cause**: JavaScript or server-side save issue  
**Solution**: Follow the debug steps above and send me the console output

### AI Peers menu doesn't appear
**Cause**: Federation Directory not actually enabled (checkbox not saving)  
**Solution**: Fix the checkbox save issue first

### Can't add peer sites
**Cause**: Need the other site's mesh_inbound_api_key  
**Solution**: Contact the other site's admin and ask them for their key from Advanced → Federation & Mesh

## Need More Help?

See `FEDERATION_DIRECTORY_DEBUG.md` for:
- Detailed architecture explanation
- Step-by-step debugging procedures
- Common issues and solutions
- Complete testing checklist

Or run the debug script:
```bash
./bin/check-federation-settings.sh
```

This will show you the current state of all federation settings in your database.
