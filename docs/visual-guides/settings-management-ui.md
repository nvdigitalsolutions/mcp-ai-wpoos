# Settings Management Visual Guide

**Visual walkthrough of NV oOS Settings Management features**

> **Note**: This guide describes the UI elements. For actual screenshots, navigate to the Settings Management section in your WordPress admin.

## Navigation Path

```
WordPress Admin Dashboard
  └─ NV oOS (sidebar menu)
      └─ Advanced (tab at top)
          └─ Settings Management (subtab)
```

## Main Interface

### Settings Management Dashboard Layout

```
┌────────────────────────────────────────────────────────────┐
│ Advanced Settings                                          │
│ Performance tuning, debugging options, and advanced config │
├────────────────────────────────────────────────────────────┤
│                                                            │
│ [Performance] [Logging] [Federation] [System] [Settings]  │
│                              [Data] [Settings Management]  │
│                                                            │
├────────────────────────────────────────────────────────────┤
│ Settings Management                                        │
│                                                            │
│ Manage plugin settings: export for backup, import from    │
│ file, clear caches, check system health, or reset to      │
│ defaults.                                                  │
│                                                            │
│ ┌────────────────────────────────────────────────────┐    │
│ │ ❤️  Settings Health                                 │    │
│ │                                                     │    │
│ │ Click "Check Health" to run diagnostics...         │    │
│ │                                                     │    │
│ │ [🔍 Check Settings Health]                          │    │
│ └────────────────────────────────────────────────────┘    │
│                                                            │
│ ┌────────────────────────────────────────────────────┐    │
│ │ 💾 Backup & Restore                                 │    │
│ │                                                     │    │
│ │ Current Settings: 247 fields configured            │    │
│ │ Backups Available: 5 (automatic backups from       │    │
│ │                      recent saves)                  │    │
│ │                                                     │    │
│ │ [⬇️ Export Settings (JSON)]                         │    │
│ │ Download all plugin settings as a JSON file for    │    │
│ │ backup or migration to another site.               │    │
│ │                                                     │    │
│ │ ⬆️ Import Settings:                                 │    │
│ │ [Choose File] No file chosen                       │    │
│ │ [Upload & Import]                                  │    │
│ │ Import settings from a previously exported JSON    │    │
│ │ file. Current settings will be backed up before    │    │
│ │ import.                                            │    │
│ └────────────────────────────────────────────────────┘    │
│                                                            │
│ ┌────────────────────────────────────────────────────┐    │
│ │ 🔄 Cache Management                                 │    │
│ │                                                     │    │
│ │ If settings changes are not taking effect, clear   │    │
│ │ all settings-related caches.                       │    │
│ │                                                     │    │
│ │ [🗑️ Clear All Caches]                               │    │
│ └────────────────────────────────────────────────────┘    │
│                                                            │
│ ┌────────────────────────────────────────────────────┐    │
│ │ ⚠️  Reset to Defaults                               │    │
│ │                                                     │    │
│ │ Warning: This will reset ALL settings to their     │    │
│ │ default values. Current settings will be backed up │    │
│ │ before reset.                                      │    │
│ │                                                     │    │
│ │ [↩️ Reset All Settings]                             │    │
│ └────────────────────────────────────────────────────┘    │
└────────────────────────────────────────────────────────────┘
```

## Feature-by-Feature Visual Guide

### 1. Settings Health Check

**Before Check**:
```
┌─────────────────────────────────────────┐
│ ❤️  Settings Health                     │
│                                         │
│ Click "Check Health" to run            │
│ diagnostics...                          │
│                                         │
│ [🔍 Check Settings Health]              │
└─────────────────────────────────────────┘
```

**During Check** (Button changes):
```
[⏳ Checking...]  (disabled, gray)
```

**After Check - GOOD Status**:
```
┌─────────────────────────────────────────┐
│ Health check complete. Status: GOOD     │
│                                         │
│ ℹ️ Info                                  │
│ • Total settings fields: 247            │
│ • Configured providers: 3               │
│ • Object cache status: Active           │
│ • Settings backups available: 5         │
│                                         │
│ [🔍 Check Settings Health]              │
└─────────────────────────────────────────┘
```

**After Check - WARNING Status**:
```
┌─────────────────────────────────────────┐
│ Health check complete. Status: WARNING  │
│                                         │
│ ⚠️  Warning                              │
│ • Critical field "default_provider" is  │
│   missing or empty.                     │
│                                         │
│ ℹ️ Info                                  │
│ • Total settings fields: 247            │
│ • Configured providers: 0               │
│ • Object cache status: Not cached       │
│ • Settings backups available: 5         │
│                                         │
│ [🔍 Check Settings Health]              │
└─────────────────────────────────────────┘
```

**After Check - CRITICAL Status**:
```
┌─────────────────────────────────────────┐
│ Health check complete. Status: CRITICAL │
│                                         │
│ 🔴 Issue                                 │
│ • No settings found in database.        │
│   Settings may need to be initialized.  │
│                                         │
│ ⚠️  Warning                              │
│ • No AI providers configured. At least  │
│   one provider is required.             │
│                                         │
│ [🔍 Check Settings Health]              │
└─────────────────────────────────────────┘
```

### 2. Export Settings

**Normal State**:
```
[⬇️ Export Settings (JSON)]
```

**On Click**:
- Browser download prompt appears
- File: `nv-oos-settings-2025-01-20-19-30-00.json`
- No visual change in UI (instant download)

### 3. Import Settings

**Step 1 - No File Selected**:
```
⬆️ Import Settings:
[Choose File] No file chosen
[Upload & Import] (disabled, gray)

Import settings from a previously exported JSON
file. Current settings will be backed up before
import.
```

**Step 2 - File Selected**:
```
⬆️ Import Settings:
[Choose File] nv-oos-settings-2025-01-20.json
[Upload & Import] (enabled, blue)

Import settings from a previously exported JSON
file. Current settings will be backed up before
import.
```

**Step 3 - Uploading**:
```
[⏳ Importing...] (disabled, gray)
```

**Step 4 - Success**:
```
┌─────────────────────────────────────────┐
│ ✅ Settings imported successfully!      │
│ (247 fields)                            │
└─────────────────────────────────────────┘

(Page auto-reloads after 2 seconds)
```

**Step 4 - Error**:
```
┌─────────────────────────────────────────┐
│ ❌ Settings validation failed:          │
│ Critical field "default_provider" is    │
│ missing                                 │
└─────────────────────────────────────────┘

[Upload & Import] (re-enabled)
```

### 4. Clear Cache

**Normal State**:
```
[🗑️ Clear All Caches]
```

**During Clear**:
```
[⏳ Clearing...] (disabled, gray)
```

**After Success**:
```
┌─────────────────────────────────────────┐
│ ✅ All settings caches cleared          │
│ successfully!                           │
└─────────────────────────────────────────┘

[🗑️ Clear All Caches] (re-enabled)
```

### 5. Reset to Defaults

**Normal State**:
```
[↩️ Reset All Settings]
```

**Confirmation Dialog**:
```
┌───────────────────────────────────────────┐
│ Confirmation Required                     │
├───────────────────────────────────────────┤
│                                           │
│ Reset ALL settings to defaults? This      │
│ cannot be undone! (Current settings will  │
│ be backed up)                             │
│                                           │
│              [Cancel]  [OK]               │
└───────────────────────────────────────────┘
```

**During Reset**:
```
[⏳ Resetting...] (disabled, gray)
```

**After Success**:
```
┌─────────────────────────────────────────┐
│ ✅ Settings reset to defaults           │
│ successfully!                           │
└─────────────────────────────────────────┘

(Page auto-reloads after 2 seconds)
```

## Color Coding

### Status Messages

**Success** (Green background):
```
✅ Settings imported successfully!
```

**Error** (Red background):
```
❌ Settings validation failed
```

**Info** (Blue background):
```
ℹ️ Total settings fields: 247
```

**Warning** (Orange background):
```
⚠️  Critical field "default_provider" is missing
```

### Buttons

**Primary Actions** (Blue):
- Export Settings (JSON)

**Secondary Actions** (Gray):
- Check Settings Health
- Upload & Import
- Clear All Caches
- Reset All Settings

**Disabled State** (Light gray):
- Upload & Import (when no file selected)
- Any button during processing

## Responsive Behavior

### Desktop (> 768px)
- Full-width cards
- Buttons full size
- All text visible

### Tablet (480px - 768px)
- Slightly narrower cards
- Buttons stack vertically
- Text wraps appropriately

### Mobile (< 480px)
- Full-width layout
- Buttons stack vertically
- Compact spacing
- Smaller font sizes

## Accessibility Features

### Visual
- High contrast colors
- Clear iconography
- Large click targets
- Status indicators

### Screen Readers
- ARIA labels on buttons
- Status announcements
- Error descriptions
- Progress indicators

### Keyboard Navigation
- Tab through all controls
- Enter to activate buttons
- Escape to close dialogs
- Focus indicators visible

## User Flow Diagrams

### Typical Backup Workflow

```
┌─────────────┐
│ Open Page   │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Click Export│
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ File        │
│ Downloads   │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Store       │
│ Securely    │
└─────────────┘
```

### Typical Import Workflow

```
┌─────────────┐
│ Choose File │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Click Import│
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Confirm     │
│ Dialog      │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Validate &  │
│ Save        │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Auto-Reload │
└─────────────┘
```

### Troubleshooting Workflow

```
┌──────────────┐
│ Problem?     │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ Check Health │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ Review       │
│ Diagnostics  │
└──────┬───────┘
       │
       ▼
  ┌────┴────┐
  │ Issues? │
  └────┬────┘
       │
   ┌───┴───┐
   │       │
  Yes      No
   │       │
   ▼       ▼
┌──────┐ ┌──────┐
│Clear │ │All   │
│Cache │ │Good! │
└──────┘ └──────┘
```

## Tips for Taking Screenshots

**Recommended Views**:
1. Full Settings Management page
2. Health Check - Good status
3. Health Check - With warnings
4. Export button (before click)
5. Import file selected
6. Import success message
7. Clear cache success
8. Reset confirmation dialog

**Capture Settings**:
- Resolution: 1920x1080 or higher
- Format: PNG for clarity
- Show full browser chrome
- Highlight active elements
- Include cursor on buttons

**Annotation Ideas**:
- Circle/highlight buttons
- Arrow to show navigation path
- Number steps sequentially
- Add callouts for key features

---

**For Actual Screenshots**: Navigate to NV oOS → Advanced → Settings Management and capture the interface as described above.

**Version**: 1.0.0  
**Last Updated**: 2025-01-20
