# Testing Guide: Federation Mesh Fix

This guide will help you verify that the federation mesh fix is working correctly on your site.

## Before You Start

1. Make sure you've deployed the latest code from the `copilot/fix-mesh-enablement-issue` branch
2. Clear your browser cache (Ctrl+Shift+R or Cmd+Shift+R)
3. You may need to manually flush permalinks: Go to Settings → Permalinks → Click "Save Changes"

## Test Scenario 1: Enable Federation Only

**Purpose:** Verify that well-known endpoints are loaded when only `enable_federation` is enabled.

### Steps:

1. Go to **WP Admin → NV oOS → Settings → Advanced → Federation & Mesh**

2. **Uncheck** "Enable Federation Directory" (if checked)

3. **Check** "Enable Federation"

4. Click **Save Settings**

5. Refresh the page and verify:
   - ✅ The "Enable Federation" checkbox stays checked
   - ✅ Status section shows "Federation (Well-Known Endpoints): Enabled"
   - ✅ The "Mesh Inbound API Key" section appears with a generated key
   - ❌ The "AI Peers" menu should NOT appear in WordPress admin sidebar

6. Test well-known endpoints in your browser:
   - Visit: `https://bots.nvdigital.solutions/.well-known/ai-peer`
   - Should see JSON output with site capabilities
   - Visit: `https://bots.nvdigital.solutions/.well-known/jwks.json`
   - Should see JSON output (may be empty if no keys configured)

7. Copy the mesh inbound API key from the "Mesh Inbound API Key" section - you'll need it later

## Test Scenario 2: Enable Federation Directory Only

**Purpose:** Verify that full directory features work when only `enable_federation_directory` is enabled.

### Steps:

1. Still on **Advanced → Federation & Mesh** page

2. **Uncheck** "Enable Federation"

3. **Check** "Enable Federation Directory"

4. Click **Save Settings**

5. Refresh the page and verify:
   - ✅ The "Enable Federation Directory" checkbox stays checked
   - ✅ Status section shows "Federation Directory: Enabled"
   - ✅ The "Mesh Inbound API Key" section appears (key should be preserved)
   - ✅ The "AI Peers" menu SHOULD appear in WordPress admin sidebar

6. Test well-known endpoints again:
   - Visit: `https://bots.nvdigital.solutions/.well-known/ai-peer`
   - Should still see JSON output (still works!)

7. Click on **AI Peers** in the admin sidebar
   - Should see the AI Peers management page
   - You can add a new AI Peer if you want to test

## Test Scenario 3: Enable Both Settings (Recommended)

**Purpose:** Verify that all features work when both settings are enabled.

### Steps:

1. Still on **Advanced → Federation & Mesh** page

2. **Check** both "Enable Federation" and "Enable Federation Directory"

3. Click **Save Settings**

4. Refresh the page and verify:
   - ✅ Both checkboxes stay checked
   - ✅ Status section shows:
     - Federation (Well-Known Endpoints): Enabled
     - Federation Directory: Enabled
   - ✅ The "Mesh Inbound API Key" section appears (key preserved)
   - ✅ The "AI Peers" menu appears in WordPress admin sidebar

5. Test all endpoints:
   - `/.well-known/ai-peer` - Should work
   - `/.well-known/jwks.json` - Should work
   - AI Peers admin page - Should work

## Test Scenario 4: Enable Mesh Computing

**Purpose:** Verify that mesh computing still generates the API key.

### Steps:

1. Go to **WP Admin → NV oOS → Settings → Tools → Features**

2. Find "Enable Mesh Computing" checkbox

3. **Check** it

4. Click **Save Settings**

5. Go back to **Advanced → Federation & Mesh**

6. Verify:
   - ✅ Status section shows "Mesh Computing: Enabled"
   - ✅ The "Mesh Inbound API Key" is still present (not regenerated)

## Expected Results Summary

| Setting                      | Well-Known Endpoints | AI Peers CPT | API Key Generated | Directory REST API |
|-----------------------------|---------------------|--------------|-------------------|-------------------|
| enable_federation only       | ✅ YES              | ❌ NO        | ✅ YES            | ❌ NO             |
| enable_federation_directory  | ✅ YES              | ✅ YES       | ✅ YES            | ✅ YES            |
| Both enabled                 | ✅ YES              | ✅ YES       | ✅ YES            | ✅ YES            |
| enable_mesh only             | ❌ NO               | ❌ NO        | ✅ YES            | ❌ NO             |

## Troubleshooting

### Checkbox won't stay enabled
- Clear browser cache completely
- Try in incognito/private mode
- Check browser console for JavaScript errors (F12)

### Well-known endpoints return 404
- Go to **Settings → Permalinks** → Click "Save Changes"
- This flushes rewrite rules
- Try accessing the endpoints again

### API key not generated
- Make sure at least ONE of these is enabled:
  - Enable Mesh Computing (Tools → Features)
  - Enable Federation (Advanced → Federation & Mesh)
  - Enable Federation Directory (Advanced → Federation & Mesh)
- Click "Save Settings" again to trigger key generation

### AI Peers menu doesn't appear
- Only appears when "Enable Federation Directory" is checked
- Make sure you saved the settings
- Refresh the WordPress admin page

## Need Help?

If you're still seeing issues:

1. Take screenshots showing:
   - The Advanced → Federation & Mesh settings page
   - The status section showing what's enabled
   - The browser console (F12 → Console tab)

2. Run this command on your server:
   ```bash
   wp option get wp_mcp_ai_settings --format=json | jq '{
     enable_mesh: .enable_mesh,
     enable_federation: .enable_federation, 
     enable_federation_directory: .enable_federation_directory,
     mesh_inbound_api_key: (.mesh_inbound_api_key // "NOT SET")
   }'
   ```

3. Share the output with the development team

## Success!

If all test scenarios pass, the federation mesh fix is working correctly! 

Both checkboxes should:
- ✅ Stay checked when you save them
- ✅ Generate API keys automatically
- ✅ Enable well-known endpoints
- ✅ Show correct status in the dashboard

You can now use the federation features as intended.
