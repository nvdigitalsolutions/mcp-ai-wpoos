# Toolkit Settings Consolidation Guide

## Overview

This document describes the consolidation of toolkit settings pages from the Pro Dashboard to CPT-specific menus, and the creation of new Custom Post Types for Image Production and Document Generation toolkits.

## What Changed

### Old Architecture (Before)

All pro toolkits had comprehensive settings pages located under the Pro Dashboard menu at URLs like:
- `/wp-admin/admin.php?page=wp-mcp-ai-media-toolkit-settings`
- `/wp-admin/admin.php?page=wp-mcp-ai-project-management-toolkit-settings`
- `/wp-admin/admin.php?page=wp-mcp-ai-image-production-toolkit-settings`
- `/wp-admin/admin.php?page=wp-mcp-ai-document-generation-toolkit-settings`

These pages included tabs for Overview, Configuration, Tools Management, Research & Add, Remote Sites, and Help.

### New Architecture (After)

Each toolkit now has:
1. A Custom Post Type for managing templates/content
2. A simplified settings page located in the relevant WordPress menu
3. Separate Research & Add functionality (not part of settings page)

## New Toolkit Structure

### Media Toolkit

**CPT**: `mcp_ai_media_tpl` (already existed)
**Location**: Media menu → Media Templates
**Settings**: Media menu → Settings
**URL**: `/wp-admin/upload.php?page=media-toolkit-settings`

**Configuration Options**:
- Assistant selection
- Enable Design & Add page
- Enable AI Design Generation
- Default Template Category (Social Media, Blog Graphics, Marketing, Presentations)
- Enable Smart Tagging
- Max Collection Size

### Project Management Toolkit

**CPT**: `mcp_ai_project` (already existed)
**Location**: Projects menu
**Settings**: Projects → Settings
**URL**: `/wp-admin/edit.php?post_type=mcp_ai_project&page=project-settings`

**Configuration Options**:
- Assistant selection
- Enable Research & Add

**Related CPTs**:
- `mcp_ai_task` - Tasks
- `mcp_ai_event` - Events
- `mcp_task_plan` - Task Plans
- `mcp_task_template` - Task Templates

### Image Production Toolkit (NEW)

**CPT**: `mcp_ai_image_tpl` (newly created)
**Location**: Media menu → Image Templates
**Settings**: Media menu → Settings (image production)
**URL**: `/wp-admin/upload.php?page=image-production-settings`

**CPT Features**:
- AI Provider selection (DALL-E, Midjourney, Stable Diffusion)
- Generation prompt storage
- Dimension presets (1024x1024, 1024x1792, 1792x1024)
- Art style customization
- Output format (PNG, JPEG, WebP)
- Preview thumbnails
- Template categories (Product Photos, Social Media, Marketing, Backgrounds, Illustrations, Photography, Abstract Art)

**Configuration Options**:
- Assistant selection
- Default Image Generator
- Default Output Format
- Max Image Dimensions (width × height)

### Document Generation Toolkit (NEW)

**CPT**: `mcp_ai_doc_tpl` (newly created)
**Location**: Document Templates menu (top-level)
**Settings**: Document Templates → Settings
**URL**: `/wp-admin/edit.php?post_type=mcp_ai_doc_tpl&page=document-generation-settings`

**CPT Features**:
- Document type (PDF, DOCX, XLSX)
- Output format (Download, Media Library, Email)
- Page size (A4, Letter, Legal)
- Orientation (Portrait, Landscape)
- Branding options (logo, watermark)
- Template categories (Invoices, Reports, Contracts, Receipts, Proposals, Spreadsheets, Presentations, Certificates)

**Configuration Options**:
- Assistant selection
- Default Page Size
- Default Orientation
- Enable Branding
- Node.js Status (availability check)
- NPM Packages Status (pdfkit, docx, exceljs)

## Migration Notes

### What Was Migrated

From the old toolkit settings pages, the following were migrated to the new settings pages:

1. **Media Toolkit**:
   - Enable AI Design Generation setting
   - Default Template Category selector
   - Enable Smart Tagging option
   - Max Collection Size limit

2. **Project Management**:
   - Enable Research & Add option (already existed)

3. **Image Production**:
   - Default Image Generator
   - Default Output Format
   - Max Image Dimensions

4. **Document Generation**:
   - Default Page Size
   - Default Orientation
   - Enable Branding
   - Node.js/NPM status indicators

### What Was NOT Migrated

The following sections from old toolkit settings pages were NOT migrated because they are now handled separately:

1. **Research & Add Tab**: This functionality exists in separate Research & Add pages for each toolkit. These are loaded by the respective `class-wp-mcp-ai-*-research-add.php` files.

2. **Overview Tab**: This was informational content about toolkit features and is not needed in the simplified settings pages.

3. **Tools Management Tab**: Tool lists and descriptions are available in the main documentation and are not needed in settings pages.

4. **Remote Sites Tab**: This functionality is handled separately for toolkits that support it.

5. **Help Tab**: General help content is available in the main documentation.

## File Changes

### New Files Created

1. `addons/pro/includes/class-wp-mcp-ai-image-template-cpt.php`
   - Image Template Custom Post Type implementation
   - 557 lines, manages AI image generation templates

2. `addons/pro/includes/class-wp-mcp-ai-document-template-cpt.php`
   - Document Template Custom Post Type implementation
   - 564 lines, manages PDF/Word/Excel templates

3. `addons/pro/includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php`
   - Image Production settings page (under Media menu)
   - 172 lines, configuration for image generation

4. `addons/pro/includes/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php`
   - Document Generation settings page
   - 214 lines, configuration for document generation

### Files Modified

1. `addons/pro/includes/media-toolkit-init.php`
   - Removed loading of old toolkit settings page
   - Now only loads new CPT-based settings page

2. `addons/pro/includes/project-management-init.php`
   - Removed loading of old toolkit settings page
   - Kept new CPT-based settings page

3. `addons/pro/includes/image-production-toolkit-init.php`
   - Added Image Template CPT loading
   - Changed to load new CPT-based settings page
   - Removed old toolkit settings page

4. `addons/pro/includes/document-generation-toolkit-init.php`
   - Added Document Template CPT loading
   - Changed to load new CPT-based settings page
   - Removed old toolkit settings page
   - Fixed admin styles enqueue function

5. `addons/pro/includes/admin/class-wp-mcp-ai-media-settings-page.php`
   - Enhanced with additional settings fields
   - Added AI design, template category, smart tagging, collection size options

### Files Deprecated (Not Deleted)

These files still exist but are no longer loaded:

1. `addons/pro/includes/admin/class-wp-mcp-ai-media-toolkit-settings-page.php`
2. `addons/pro/includes/admin/class-wp-mcp-ai-project-management-toolkit-settings-page.php`
3. `addons/pro/includes/admin/class-wp-mcp-ai-image-production-settings-page.php`
4. `addons/pro/includes/admin/class-wp-mcp-ai-document-generation-settings-page.php`

These can be safely deleted in a future cleanup if no issues are found.

## Benefits of New Architecture

1. **Consistency**: All toolkits follow the same CPT + Settings pattern
2. **Better Organization**: Settings appear in logical locations related to content type
3. **User Experience**: Users find toolkit settings where they manage the related content
4. **Scalability**: Template management works like other WordPress content
5. **Integration**: Research & Add pages appear alongside CPT management
6. **Simplified Settings**: Focused configuration options without overwhelming tabs

## Testing Checklist

- [ ] Verify Media Toolkit settings page loads at `/wp-admin/upload.php?page=media-toolkit-settings`
- [ ] Verify Project Management settings page loads
- [ ] Verify Image Production settings page loads at `/wp-admin/upload.php?page=image-production-settings`
- [ ] Verify Document Generation settings page loads
- [ ] Verify old toolkit settings pages return 404 or redirect
- [ ] Test creating image templates with the new CPT
- [ ] Test creating document templates with the new CPT
- [ ] Verify all settings save correctly
- [ ] Verify Research & Add functionality still works
- [ ] Check admin menu organization is logical
- [ ] Verify no PHP errors in error log

## Future Enhancements

Potential improvements for future releases:

1. Add bulk actions to CPT admin pages (duplicate, export, import)
2. Add template preview functionality
3. Add template usage tracking
4. Add template sharing between sites
5. Add template marketplace integration
6. Enhance metadata collection for better template organization
7. Add template versioning
8. Add template approval workflows

## Support

If you encounter any issues with the new settings pages or CPT management:

1. Check that the toolkit is enabled in Settings → NV oOS → Tools & Features
2. Verify you're using the Full Version (not Base Version)
3. Check PHP error logs for any issues
4. Clear browser cache and WordPress object cache
5. Deactivate and reactivate the plugin if needed

For additional support, refer to the main documentation or open an issue on GitHub.
