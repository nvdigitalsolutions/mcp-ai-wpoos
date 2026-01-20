# Media Toolkit Complete Tutorial Guide (Phase 6)

## Table of Contents

1. [Getting Started](#getting-started)
2. [Tutorial 1: Creating Your First Template](#tutorial-1-creating-your-first-template)
3. [Tutorial 2: Social Media Campaign Workflow](#tutorial-2-social-media-campaign-workflow)
4. [Tutorial 3: E-commerce Product Image Pipeline](#tutorial-3-e-commerce-product-image-pipeline)
5. [Tutorial 4: Batch Processing with Collections](#tutorial-4-batch-processing-with-collections)
6. [Tutorial 5: Using AI Tools Programmatically](#tutorial-5-using-ai-tools-programmatically)
7. [Advanced Workflows](#advanced-workflows)
8. [Troubleshooting Guide](#troubleshooting-guide)
9. [Best Practices](#best-practices)

---

## Getting Started

### Prerequisites

- WordPress 6.0 or higher
- Open Operator System (NV oOS) Pro addon active
- Media Toolkit feature enabled in Settings → NV oOS → Tools & Features

### Initial Setup

1. **Enable Media Toolkit:**
   - Navigate to **Settings → NV oOS → Tools & Features**
   - Check "Enable Media Toolkit"
   - Click "Save Changes"

2. **Access Media Templates:**
   - Go to **Media → Media Templates**
   - You'll see 15+ preset templates automatically created

3. **Access Collections:**
   - Go to **Media → Collections**
   - Create your first collection

---

## Tutorial 1: Creating Your First Template

### Objective
Create a custom template to resize images for Instagram square posts.

### Step-by-Step Instructions

**Step 1: Create New Template**
1. Go to **Media → Media Templates → Add New**
2. Enter title: "Instagram Square Post 1080x1080"
3. Add description: "Perfect square format for Instagram feed posts"

**Step 2: Configure Operation**
1. In the **Operation Configuration** metabox:
   - Select Operation: **Resize Graphic**
   
2. Enter parameters (JSON):
```json
{
  "target_width": 1080,
  "target_height": 1080,
  "output_format": "jpg",
  "maintain_ratio": false,
  "quality": 90
}
```

**Step 3: Assign Categories**
1. In the **Template Categories** box:
   - Check "social-media"
   - Optionally add "instagram"

**Step 4: Publish Template**
1. Click **Publish**
2. Your template is now ready to use!

### Testing Your Template

**Option A: Using AI Assistant**
```json
{
  "tool": "apply_media_template",
  "arguments": {
    "template_id": 123,
    "attachment_id": 456
  }
}
```

**Option B: Using Quick Apply (Admin)**
1. Go to **Media → Media Templates**
2. Hover over your template
3. Click **Quick Apply**
4. Select an image from the Media Library
5. Template applies automatically!

---

## Tutorial 2: Social Media Campaign Workflow

### Objective
Create a complete workflow to generate images for Instagram, Facebook, and Twitter from a single source image.

### Step 1: Create Platform Templates

**Instagram Square (1080x1080)**
```json
{
  "title": "Instagram Square",
  "operation": "resize_graphic",
  "parameters": {
    "target_width": 1080,
    "target_height": 1080,
    "output_format": "jpg",
    "quality": 85
  },
  "categories": ["social-media", "instagram"]
}
```

**Facebook Cover (820x312)**
```json
{
  "title": "Facebook Cover",
  "operation": "resize_graphic",
  "parameters": {
    "target_width": 820,
    "target_height": 312,
    "output_format": "jpg",
    "quality": 85
  },
  "categories": ["social-media", "facebook"]
}
```

**Twitter Header (1500x500)**
```json
{
  "title": "Twitter Header",
  "operation": "resize_graphic",
  "parameters": {
    "target_width": 1500,
    "target_height": 500,
    "output_format": "jpg",
    "quality": 85
  },
  "categories": ["social-media", "twitter"]
}
```

### Step 2: Create Campaign Collection

1. Go to **Media → Collections → Add New**
2. Title: "Spring Campaign 2026"
3. Add your campaign images in the **Collection Media Items** metabox
4. In **Batch Operations & Templates**, select all three templates created above
5. Click **Publish**

### Step 3: Process Collection

**Option A: Admin Quick Process**
1. Go to **Media → Collections**
2. Click **Quick Process** on your collection
3. Confirm the action
4. Wait for processing to complete

**Option B: Using AI Tool**
```json
{
  "tool": "process_collection",
  "arguments": {
    "collection_id": 101
  }
}
```

### Step 4: Review Results

After processing, you'll have:
- 3 images × 3 templates = 9 optimized outputs
- All available in your Media Library
- Usage statistics updated for each template

---

## Tutorial 3: E-commerce Product Image Pipeline

### Objective
Create a standardized pipeline for product photography with watermark and multiple sizes.

### Step 1: Create Logo Watermark Template

1. **Upload Your Logo:**
   - Go to Media Library and upload your logo (transparent PNG recommended)
   - Note the attachment ID (e.g., 789)

2. **Create Watermark Template:**
```json
{
  "title": "Product Watermark - Bottom Right",
  "operation": "add_logo",
  "parameters": {
    "logo_attachment_id": 789,
    "logo_position": "bottom-right",
    "logo_scale": 0.12,
    "logo_margin": 25
  },
  "categories": ["e-commerce", "branding"]
}
```

### Step 2: Create Size Templates

**Large Product Image (1200x1200)**
```json
{
  "title": "Product Large",
  "operation": "resize_graphic",
  "parameters": {
    "target_width": 1200,
    "target_height": 1200,
    "output_format": "jpg",
    "maintain_ratio": true,
    "quality": 95
  }
}
```

**Thumbnail (300x300)**
```json
{
  "title": "Product Thumbnail",
  "operation": "resize_graphic",
  "parameters": {
    "target_width": 300,
    "target_height": 300,
    "output_format": "jpg",
    "maintain_ratio": true,
    "quality": 85
  }
}
```

### Step 3: Create Product Collection

1. Create collection: "New Product Batch - January 2026"
2. Add all raw product photos
3. Assign templates:
   - Product Watermark
   - Product Large
   - Product Thumbnail
4. Process collection

### Step 4: Automation with AI

**Automated Processing Script:**
```json
{
  "tool": "apply_collection_template",
  "arguments": {
    "collection_id": 201,
    "template_ids": [45, 46, 47],
    "process": true
  }
}
```

**Result:** Each product photo gets watermarked and resized to multiple sizes automatically!

---

## Tutorial 4: Batch Processing with Collections

### Objective
Learn advanced collection management and bulk operations.

### Scenario: Event Photography

You have 50 photos from a corporate event that need:
1. Company logo in corner
2. Resized for web (1920x1080)
3. Thumbnails for gallery (400x300)

### Implementation

**Step 1: Bulk Create Collection**

1. Upload all 50 photos to Media Library
2. Create collection: "Corporate Event Jan 2026"
3. Use the visual selector to add all event photos

**Step 2: Apply Templates in Sequence**

Create a processing pipeline:
```json
[
  {
    "tool": "create_media_template",
    "arguments": {
      "title": "Event Logo Overlay",
      "operation": "add_logo",
      "parameters": {
        "logo_attachment_id": 999,
        "logo_position": "top-right",
        "logo_scale": 0.1
      }
    }
  },
  {
    "tool": "create_media_template",
    "arguments": {
      "title": "Event Web Size",
      "operation": "resize_graphic",
      "parameters": {
        "target_width": 1920,
        "target_height": 1080,
        "maintain_ratio": true
      }
    }
  },
  {
    "tool": "create_media_template",
    "arguments": {
      "title": "Event Thumbnail",
      "operation": "resize_graphic",
      "parameters": {
        "target_width": 400,
        "target_height": 300
      }
    }
  }
]
```

**Step 3: Bulk Process**

1. Go to **Media → Collections**
2. Select your event collection
3. Choose **Process Collections** from Bulk Actions
4. Click **Apply**
5. Monitor processing (50 photos × 3 templates = 150 outputs)

**Step 4: Download Results**

After processing:
- 150 processed images available
- Organized by collection
- Usage statistics tracked
- Ready for delivery!

---

## Tutorial 5: Using AI Tools Programmatically

### Objective
Integrate Media Toolkit into your AI assistant workflows.

### Use Case 1: Dynamic Template Creation

**Ask AI to create templates on demand:**

```
User: "Create a template for LinkedIn posts"

AI uses tool:
{
  "tool": "create_media_template",
  "arguments": {
    "title": "LinkedIn Post Image",
    "operation": "resize_graphic",
    "parameters": {
      "target_width": 1200,
      "target_height": 627,
      "output_format": "png",
      "quality": 90
    },
    "categories": ["social-media", "linkedin"]
  }
}
```

### Use Case 2: Smart Template Discovery

**Ask AI to find relevant templates:**

```
User: "What templates do we have for social media?"

AI uses tool:
{
  "tool": "list_media_templates",
  "arguments": {
    "category": "social-media",
    "per_page": 10
  }
}

AI responds with:
- Instagram Square (1080x1080)
- Facebook Cover (820x312)
- Twitter Header (1500x500)
- LinkedIn Post (1200x627)
[etc...]
```

### Use Case 3: Automated Campaign Processing

**Complete workflow automation:**

```
User: "Process the summer campaign images for all social platforms"

AI workflow:
1. List templates (category: "social-media")
2. Create collection or find existing
3. Apply templates to collection
4. Process and report results
```

**Tool sequence:**
```json
[
  {
    "tool": "list_media_templates",
    "arguments": {
      "category": "social-media"
    }
  },
  {
    "tool": "apply_collection_template",
    "arguments": {
      "collection_id": 301,
      "template_ids": [123, 124, 125, 126],
      "process": true
    }
  }
]
```

### Use Case 4: Quality Assurance with AI

**AI-powered template validation:**

```
User: "Check if my Instagram template settings are correct"

AI uses:
1. list_media_templates (search: "instagram")
2. Reviews parameters
3. Compares against Instagram specs
4. Suggests improvements
```

---

## Advanced Workflows

### Workflow 1: Template Inheritance

Create a base template and variations:

**Base Logo Template:**
```json
{
  "title": "Base Logo - Bottom Right",
  "operation": "add_logo",
  "parameters": {
    "logo_position": "bottom-right",
    "logo_scale": 0.15,
    "logo_margin": 20
  }
}
```

**Create variations:**
1. Duplicate template
2. Modify: "Base Logo - Top Left"
3. Change `logo_position` to "top-left"
4. Repeat for each corner

### Workflow 2: Multi-Stage Processing

Process images through multiple templates in sequence:

```json
{
  "tool": "apply_media_template",
  "arguments": {
    "template_id": 1,
    "attachment_id": 500
  }
}
// Then use the output_id from result as input to next template
{
  "tool": "apply_media_template",
  "arguments": {
    "template_id": 2,
    "attachment_id": [output_id_from_previous]
  }
}
```

**Example:** Resize → Add Logo → AI Enhance

### Workflow 3: Conditional Processing

Use AI to decide which templates to apply:

```
User: "Process these images appropriately"

AI logic:
- If portrait → use portrait templates
- If landscape → use landscape templates
- If square → use square templates
```

### Workflow 4: Template Export/Import

**Export templates for backup:**
1. Select templates in admin
2. Bulk Actions → Export
3. Download JSON file

**Import to another site:**
1. Copy JSON content
2. Use AI tool to recreate:
```json
{
  "tool": "create_media_template",
  "arguments": [/* paste from JSON */]
}
```

---

## Troubleshooting Guide

### Issue: Template Not Applying

**Symptoms:**
- "Failed to apply template" error
- No output generated

**Solutions:**
1. **Check Media Toolkit is enabled:**
   - Settings → NV oOS → Tools & Features
   
2. **Verify attachment exists:**
   - Attachment ID must be valid image
   - Check Media Library

3. **Validate template configuration:**
   - Check JSON parameters are valid
   - Ensure required parameters are present

4. **Check capabilities:**
   - User must have `upload_files` capability

### Issue: Collection Processing Fails

**Symptoms:**
- Collection shows errors
- Only some items processed

**Solutions:**
1. **Check collection has items:**
   - Open collection edit page
   - Verify items in Collection Media Items metabox

2. **Verify templates assigned:**
   - Check Batch Operations metabox
   - Ensure at least one template selected

3. **Check for invalid items:**
   - Some attachments may be deleted
   - Remove invalid items from collection

### Issue: Bulk Actions Not Working

**Symptoms:**
- Bulk action dropdown empty
- Action doesn't execute

**Solutions:**
1. **Ensure JavaScript is enabled:**
   - Admin scripts must load

2. **Check for plugin conflicts:**
   - Disable other plugins temporarily

3. **Clear browser cache:**
   - Hard refresh (Ctrl+Shift+R)

### Issue: Export Download Not Working

**Symptoms:**
- Export completes but no download link
- Download link gives 404

**Solutions:**
1. **Check transient storage:**
   - Export uses temporary storage (1 hour)
   - Download within time limit

2. **Verify AJAX is working:**
   - Check browser console for errors

3. **Check permalink settings:**
   - May need to flush rewrite rules

---

## Best Practices

### Template Organization

**1. Use Descriptive Names:**
- ✅ Good: "Instagram Square 1080 - High Quality"
- ❌ Bad: "Template 1"

**2. Categorize Consistently:**
- Use categories: social-media, e-commerce, branding, content
- Create subcategories as needed

**3. Document Parameters:**
- Add descriptions explaining why parameters chosen
- Note optimal use cases

### Collection Management

**1. Group by Purpose:**
- Campaign-based: "Q1 2026 Campaign"
- Project-based: "Website Redesign Images"
- Client-based: "Client XYZ Assets"

**2. Limit Collection Size:**
- Keep under 50 items for best performance
- Create multiple collections for large batches

**3. Track Processing:**
- Review statistics after each process
- Monitor success/failure rates

### Performance Optimization

**1. Process During Off-Peak:**
- Large collections can be resource-intensive
- Schedule bulk operations wisely

**2. Use Appropriate Quality Settings:**
- Don't use quality: 100 unnecessarily
- Balance quality vs file size

**3. Clean Up Processed Files:**
- Archive old collections
- Delete unused templates

### Security Considerations

**1. Logo Assets:**
- Use high-resolution source logos
- Keep original files backed up separately

**2. Template Parameters:**
- Validate JSON before saving
- Test templates with sample images first

**3. Capability Management:**
- Only give `upload_files` to trusted users
- Monitor template usage via statistics

### Workflow Efficiency

**1. Create Template Library:**
- Build standard templates for common tasks
- Share across team members

**2. Use Presets as Starting Point:**
- Customize preset templates
- Don't start from scratch

**3. Automate Repetitive Tasks:**
- Use collections for recurring workflows
- Integrate with AI tools for automation

**4. Monitor Usage Statistics:**
- Identify most-used templates
- Optimize or improve popular templates
- Archive unused templates

---

## Real-World Examples

### Example 1: News Website

**Scenario:** Daily news images need consistent formatting

**Solution:**
- Template: "News Featured Image" (1200x630, watermark)
- Template: "News Thumbnail" (300x200)
- Collection: "Daily News - [Date]"
- Process: Automatic via AI each morning

### Example 2: Real Estate Agency

**Scenario:** Property photos need professional formatting

**Solution:**
- Template: "Property Main Photo" (1920x1080, logo overlay)
- Template: "Property Thumbnail" (400x300)
- Template: "Property Instagram" (1080x1080, logo)
- Collection per property
- Quick Process on each new listing

### Example 3: Marketing Agency

**Scenario:** Client campaigns across multiple platforms

**Solution:**
- Template set for each platform (Instagram, Facebook, LinkedIn, Twitter)
- One collection per client campaign
- Bulk process all clients monthly
- Export templates for client approval

### Example 4: E-learning Platform

**Scenario:** Course thumbnails and promotional images

**Solution:**
- Template: "Course Hero Image" (1920x1080)
- Template: "Course Card" (600x400)
- Template: "Course Social" (1200x628)
- Collections organized by course category
- Automated processing via AI tools

---

## Keyboard Shortcuts & Tips

### Admin Interface

- **Quick Duplicate:** Hover → Click Duplicate (one action)
- **Quick Apply:** Hover → Quick Apply → Select image
- **Bulk Select:** Shift+Click for range selection
- **Search:** Use search bar above templates list

### AI Integration

**Template Discovery:**
```
"Show me all resize templates"
"Find templates for e-commerce"
"List recently used templates"
```

**Quick Actions:**
```
"Apply Instagram template to image 456"
"Process collection 101"
"Create a Facebook cover template"
```

**Batch Operations:**
```
"Process all collections tagged 'campaign'"
"Export social media templates"
"Duplicate template 123 and modify for Twitter"
```

---

## Next Steps

After completing these tutorials:

1. **Explore Preset Templates:**
   - Review 15+ included presets
   - Customize for your needs

2. **Build Your Template Library:**
   - Create templates for common tasks
   - Organize with categories

3. **Integrate with Workflows:**
   - Use AI tools for automation
   - Create repeatable processes

4. **Share Knowledge:**
   - Document custom templates
   - Train team members

5. **Monitor & Optimize:**
   - Review usage statistics
   - Refine templates based on results

---

## Additional Resources

- **Main Documentation:** `media-toolkit.md`
- **API Reference:** `media-toolkit-tools-guide.md`
- **Tool Documentation:** See AI Tools section in main docs

## Support

For issues or questions:
1. Check Troubleshooting Guide above
2. Review Known Limitations in main documentation
3. Submit support request with:
   - Template/Collection ID
   - Error messages
   - Steps to reproduce

---

**Version:** 1.2.0 (Phase 6 Complete)  
**Last Updated:** January 2026  
**Author:** NV Digital Solutions
