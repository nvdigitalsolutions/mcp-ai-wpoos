# Testing Guide: Federation Mesh Fix

This guide will help you verify that the federation mesh fix is working correctly on your site.

## Before You Start

1. Make sure you've deployed the latest code from the `copilot/fix-mesh-enablement-issue` branch
2. Clear your browser cache (Ctrl+Shift+R or Cmd+Shift+R)
3. You may need to manually flush permalinks: Go to Settings → Permalinks → Click "Save Changes"

## Important: All Settings in One Place! 

**All three federation/mesh checkboxes are now in Advanced → Federation & Mesh:**
- ✅ Enable Mesh Computing (moved from Tools → Features)
- ✅ Enable Federation
- ✅ Enable Federation Directory

This makes it easier to manage all related settings in one location.

## Test Scenario 1: Enable Mesh Computing Only

**Purpose:** Verify that mesh computing works independently.

### Steps:

1. Go to **WP Admin → NV oOS → Settings → Advanced → Federation & Mesh**

2. **Check** "Enable Mesh Computing"

3. **Uncheck** "Enable Federation" (if checked)

4. **Uncheck** "Enable Federation Directory" (if checked)

5. Click **Save Settings**

6. Refresh the page and verify:
   - ✅ The "Enable Mesh Computing" checkbox stays checked
   - ✅ Status section shows "Mesh Computing: Enabled"
   - ✅ The "Mesh Inbound API Key" section appears with a generated key
   - ❌ Well-known endpoints should NOT be accessible
   - ❌ The "AI Peers" menu should NOT appear in WordPress admin sidebar

7. Copy the mesh inbound API key from the "Mesh Inbound API Key" section - you'll need it later

## Test Scenario 2: Enable Federation Only

**Purpose:** Verify that well-known endpoints are loaded when only `enable_federation` is enabled.

### Steps:

1. Still on **Advanced → Federation & Mesh** page

2. **Uncheck** "Enable Mesh Computing"

3. **Check** "Enable Federation"

4. **Uncheck** "Enable Federation Directory"

5. Click **Save Settings**

6. Refresh the page and verify:
   - ✅ The "Enable Federation" checkbox stays checked
   - ✅ Status section shows "Federation (Well-Known Endpoints): Enabled"
   - ✅ The "Mesh Inbound API Key" section appears with a generated key
   - ❌ The "AI Peers" menu should NOT appear in WordPress admin sidebar

7. Test well-known endpoints in your browser:
   - Visit: `https://bots.nvdigital.solutions/.well-known/ai-peer`
   - Should see JSON output with site capabilities
   - Visit: `https://bots.nvdigital.solutions/.well-known/jwks.json`
   - Should see JSON output (may be empty if no keys configured)

## Test Scenario 3: Enable Federation Directory Only

**Purpose:** Verify that full directory features work when only `enable_federation_directory` is enabled.

### Steps:

1. Still on **Advanced → Federation & Mesh** page

2. **Uncheck** "Enable Mesh Computing"

3. **Uncheck** "Enable Federation"

4. **Check** "Enable Federation Directory"

5. Click **Save Settings**

6. Refresh the page and verify:
   - ✅ The "Enable Federation Directory" checkbox stays checked
   - ✅ Status section shows "Federation Directory: Enabled"
   - ✅ The "Mesh Inbound API Key" section appears (key should be preserved)
   - ✅ The "AI Peers" menu SHOULD appear in WordPress admin sidebar
   - ✅ Well-known endpoints still work (because directory enables them)

7. Click on **AI Peers** in the admin sidebar
   - Should see the AI Peers management page
   - You can add a new AI Peer if you want to test

## Test Scenario 4: Enable All Three Settings (Recommended)

**Purpose:** Verify that all features work when all settings are enabled.

### Steps:

1. Still on **Advanced → Federation & Mesh** page

2. **Check** all three checkboxes:
   - Enable Mesh Computing
   - Enable Federation
   - Enable Federation Directory

3. Click **Save Settings**

4. Refresh the page and verify:
   - ✅ All three checkboxes stay checked
   - ✅ Status section shows:
     - Mesh Computing: Enabled
     - Federation (Well-Known Endpoints): Enabled
     - Federation Directory: Enabled
   - ✅ The "Mesh Inbound API Key" section appears (key preserved)
   - ✅ The "AI Peers" menu appears in WordPress admin sidebar

5. Test all endpoints:
   - `/.well-known/ai-peer` - Should work
   - `/.well-known/jwks.json` - Should work
   - AI Peers admin page - Should work

## Test Scenario 5: Uncheck and Verify It Saves

**Purpose:** Verify that you CAN uncheck any checkbox and it will save properly.

### Steps:

1. Still on **Advanced → Federation & Mesh** page

2. **Uncheck** "Enable Federation"

3. Click **Save Settings**

4. Refresh the page and verify:
   - ✅ "Enable Federation" is UNCHECKED (saved correctly!)
   - ✅ "Enable Mesh Computing" is still CHECKED (preserved!)
   - ✅ "Enable Federation Directory" is still CHECKED (preserved!)

5. Now **uncheck** all three checkboxes

6. Click **Save Settings**

7. Refresh the page and verify:
   - ✅ All three checkboxes are UNCHECKED (saved correctly!)
   - ✅ Mesh inbound API key is hidden (no longer generated)

## Expected Results Summary

| enable_mesh | enable_federation | enable_federation_directory | Well-Known | AI Peers CPT | API Key | Mesh Computing |
|------------|-------------------|----------------------------|------------|--------------|---------|----------------|
| ❌         | ❌               | ❌                         | ❌         | ❌           | ❌      | ❌             |
| ✅         | ❌               | ❌                         | ❌         | ❌           | ✅      | ✅             |
| ❌         | ✅               | ❌                         | ✅         | ❌           | ✅      | ❌             |
| ❌         | ❌               | ✅                         | ✅         | ✅           | ✅      | ❌             |
| ✅         | ✅               | ❌                         | ✅         | ❌           | ✅      | ✅             |
| ✅         | ❌               | ✅                         | ✅         | ✅           | ✅      | ✅             |
| ❌         | ✅               | ✅                         | ✅         | ✅           | ✅      | ❌             |
| ✅         | ✅               | ✅                         | ✅         | ✅           | ✅      | ✅             |

**Recommended configurations:**
- **Full federation:** All three enabled
- **Federation discovery only:** `enable_federation` only
- **Mesh computing only:** `enable_mesh` only
- **Lightweight federation:** `enable_mesh` + `enable_federation`

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
- Make sure at least ONE of the three checkboxes is enabled
- Click "Save Settings" again to trigger key generation

### AI Peers menu doesn't appear
- Only appears when "Enable Federation Directory" is checked
- Make sure you saved the settings
- Refresh the WordPress admin page

### Setting won't save (keeps reverting)
- This should be FIXED now with the latest code
- If still occurring, check browser console for errors
- Clear all caches and try again

## Need Help?

If you're still seeing issues:

1. Take screenshots showing:
   - The Advanced → Federation & Mesh settings page (with all three checkboxes)
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

All three checkboxes should:
- ✅ Be in the same location (Advanced → Federation & Mesh)
- ✅ Stay checked when you save them
- ✅ Stay unchecked when you save them
- ✅ Generate API keys automatically when any one is enabled
- ✅ Enable well-known endpoints when needed
- ✅ Show correct status in the dashboard

You can now use the federation features as intended, all from one convenient location!
