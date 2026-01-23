# Quick Start: Enabling Project Management

## Problem: I don't see the Projects menu or any project-related pages

If you don't see the Projects menu in your WordPress admin sidebar, it's because **Project Management is disabled by default** and needs to be enabled first.

## Solution: Enable Project Management

### Step 1: Navigate to Settings
1. In WordPress admin, go to **Settings → NV oOS**
2. Click the **Tools** tab

### Step 2: Enable Project Management
1. Scroll down to find the checkbox: **"Enable Project Management"**
   - Full text: "Enable AI-powered project, task, and event management (Pro Version only)"
2. **Check the box** to enable it
3. Click **Save Changes** at the bottom of the page

### Step 3: Verify
1. Refresh your WordPress admin page (press F5 or reload the browser)
2. Look for a new **Projects** menu item in the left sidebar (with a portfolio/briefcase icon 📁)

## What You'll See After Enabling

Once enabled, the **Projects** menu will appear with these submenu items:

### Under "Projects" Menu:
1. **All Projects** - List of all projects
2. **Add New** - Create a new project
3. **Research & Add** ⭐ - AI-powered project research page
4. **Settings** ⭐ - Project-specific settings
5. **Project Management Toolkit** ⭐ - Comprehensive toolkit settings

The three pages marked with ⭐ are the ones referenced in the issue - they're all now consolidated under the Projects menu.

## Requirements

For this feature to work, you need:

- ✅ **Pro Addon Active**: The Pro addon must be installed and active
- ✅ **User Capabilities**: 
  - `edit_posts` capability to see "Research & Add"
  - `manage_options` capability to see "Settings" pages
- ✅ **Project Management Enabled**: The checkbox must be checked in Settings → NV oOS → Tools

## Still Not Seeing the Menu?

If you've enabled Project Management but still don't see the menu, check:

1. **Is Pro Addon Active?**
   - Go to **Plugins** and verify "NV oOS Pro" or "Open Operator System Pro" is activated

2. **Do You Have Permissions?**
   - You need to be an **Administrator** or have appropriate capabilities
   - Contact your site administrator if you're not sure

3. **Clear Cache**
   - If using a caching plugin, clear the cache
   - Try logging out and back in

4. **Check for Conflicts**
   - Temporarily disable other plugins to see if there's a conflict

## Summary

**The fix in this PR moves all project-related settings under the Projects menu for better organization. To see these changes, you must first enable Project Management in Settings → NV oOS → Tools.**
