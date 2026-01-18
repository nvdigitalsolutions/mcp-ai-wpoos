# Media Toolkit Architecture & Workflows

## System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                      Media Toolkit System                        │
│                                                                   │
│  ┌─────────────────┐         ┌──────────────────┐              │
│  │  Media          │         │  Media           │              │
│  │  Templates      │◄────────┤  Collections     │              │
│  │  (CPT)          │         │  (CPT)           │              │
│  └────────┬────────┘         └────────┬─────────┘              │
│           │                            │                         │
│           │  ┌─────────────────────────┼──────────┐            │
│           │  │                         │          │            │
│           ▼  ▼                         ▼          ▼            │
│  ┌─────────────────┐         ┌──────────────────────┐         │
│  │  AI Tools (5)   │         │  Admin UI            │         │
│  │  - list         │         │  - Bulk Actions      │         │
│  │  - create       │         │  - Quick Apply       │         │
│  │  - apply        │         │  - Preview           │         │
│  │  - process      │         │  - Export            │         │
│  │  - assign       │         │                      │         │
│  └────────┬────────┘         └──────────┬───────────┘         │
│           │                              │                      │
│           └──────────┬───────────────────┘                      │
│                      ▼                                           │
│           ┌─────────────────────┐                               │
│           │  Graphic Editor     │                               │
│           │  Plus Tool          │                               │
│           │  (Image Processing) │                               │
│           └─────────────────────┘                               │
└─────────────────────────────────────────────────────────────────┘
```

## Data Flow

### Template Application Flow

```
User Request
     │
     ▼
┌─────────────┐
│ Select      │
│ Template    │ ─────────┐
└─────────────┘          │
                         ▼
┌─────────────┐    ┌──────────────┐
│ Select      │───►│ Apply Tool   │
│ Image       │    │ (AI or Admin)│
└─────────────┘    └──────┬───────┘
                          │
                          ▼
                   ┌──────────────┐
                   │ Get Template │
                   │ Config       │
                   └──────┬───────┘
                          │
                          ▼
                   ┌──────────────┐
                   │ Graphic      │
                   │ Editor Plus  │
                   └──────┬───────┘
                          │
                          ▼
                   ┌──────────────┐
                   │ Update Stats │
                   │ Return Image │
                   └──────────────┘
```

### Collection Processing Flow

```
Collection Created
     │
     ▼
┌─────────────┐
│ Add Items   │
│ (Images)    │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Assign      │
│ Templates   │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Process     │
│ Collection  │
└──────┬──────┘
       │
       ▼
For Each Item:
   For Each Template:
       Apply Template
       │
       ▼
   ┌──────────────┐
   │ Processed    │
   │ Images       │
   └──────────────┘
```

## Component Relationships

### Template → Image (One-to-Many)

```
Template "Instagram Square"
    │
    ├─ Image 1 (processed)
    ├─ Image 2 (processed)
    ├─ Image 3 (processed)
    └─ Image N (processed)
```

### Collection → Templates (Many-to-Many)

```
Collection "Campaign Q1"
    │
    ├─ Template "Resize 1080x1080"
    ├─ Template "Add Logo"
    └─ Template "AI Enhance"

Each Item × Each Template = Outputs
3 Items × 3 Templates = 9 Outputs
```

## Operation Types Hierarchy

```
Media Toolkit Operations
│
├─ Geometric Operations
│  ├─ resize_graphic
│  └─ expand_scene
│
├─ Overlay Operations
│  └─ add_logo
│
└─ AI Operations
   ├─ ai_enhance
   ├─ ai_style
   ├─ ai_background
   └─ ai_retouch
```

## User Interaction Patterns

### Pattern 1: Quick Single Image

```
User Path:
Admin List → Hover Template → Quick Apply → Select Image → Done

Time: 10 seconds
Clicks: 3
```

### Pattern 2: Batch Processing

```
User Path:
Create Collection → Add Items → Assign Templates → Process → Review

Time: 2-5 minutes
Setup: One-time
Reusable: Yes
```

### Pattern 3: AI Automation

```
AI Workflow:
Chat Request → Tool Selection → Execute → Report Results

Time: Instant
Human Interaction: Minimal
```

## Data Storage

### Template Storage

```
wp_posts (post_type: mcp_ai_media_tpl)
├─ post_title        : Template name
├─ post_content      : Description
└─ post_meta
   ├─ _mcp_ai_template_operation    : Operation type
   ├─ _mcp_ai_template_parameters   : JSON config
   ├─ _mcp_ai_template_usage_count  : Usage counter
   └─ _mcp_ai_template_last_used    : Timestamp
```

### Collection Storage

```
wp_posts (post_type: mcp_ai_media_coll)
├─ post_title        : Collection name
├─ post_content      : Description
└─ post_meta
   ├─ _mcp_ai_collection_items         : Array of attachment IDs
   ├─ _mcp_ai_collection_templates     : Array of template IDs
   ├─ _mcp_ai_collection_process_count : Counter
   └─ _mcp_ai_collection_last_processed: Timestamp
```

## Tool Dependencies

```
AI Tools Dependency Chain:

list_media_templates
    │
    └─► (Independent)

create_media_template
    │
    └─► (Independent)

apply_media_template
    │
    ├─► Requires: Graphic Editor Plus Tool
    └─► Updates: Template statistics

process_collection
    │
    └─► Uses: apply_media_template
              └─► Requires: Graphic Editor Plus Tool

apply_collection_template
    │
    └─► Uses: process_collection
              └─► Uses: apply_media_template
```

## Bulk Operations Matrix

| Action | Template | Collection | Result |
|--------|----------|------------|--------|
| Duplicate | ✅ Yes | ❌ No | Creates draft copies |
| Export | ✅ Yes | ✅ Yes | JSON download |
| Process | ❌ N/A | ✅ Yes | Batch processing |
| Delete | ✅ Default | ✅ Default | WordPress default |

## Admin Interface Map

```
WordPress Admin
│
└─ Media
   ├─ Library (Default)
   ├─ Media Templates ◄─── New
   │  ├─ All Templates
   │  │  ├─ Bulk Actions
   │  │  │  ├─ Duplicate
   │  │  │  └─ Export
   │  │  └─ Row Actions
   │  │     ├─ Edit
   │  │     ├─ Quick Apply ◄─── Phase 4
   │  │     ├─ Duplicate ◄─── Phase 4
   │  │     └─ Trash
   │  ├─ Add New
   │  └─ Categories
   │
   └─ Collections ◄─── New
      ├─ All Collections
      │  ├─ Bulk Actions
      │  │  ├─ Process ◄─── Phase 4
      │  │  └─ Export ◄─── Phase 4
      │  └─ Row Actions
      │     ├─ Edit
      │     ├─ Quick Process ◄─── Phase 4
      │     └─ Trash
      ├─ Add New
      └─ Categories
```

## Permission Model

```
Capability: upload_files
    │
    ├─► Can use AI tools
    ├─► Can apply templates
    ├─► Can process collections
    └─► Can quick apply/process

Capability: edit_posts
    │
    ├─► Can create templates
    ├─► Can create collections
    ├─► Can use bulk actions
    └─► Can duplicate/export
```

## Processing Pipeline Example

### Real-World: Social Media Campaign

```
Input: 10 Campaign Photos

Step 1: Create Templates
    ├─ Instagram Square (1080x1080)
    ├─ Facebook Cover (820x312)
    └─ Twitter Header (1500x500)

Step 2: Create Collection
    └─ Add 10 photos

Step 3: Assign Templates
    └─ Link all 3 templates

Step 4: Process
    └─ 10 photos × 3 templates = 30 outputs

Output: 30 Optimized Images
    ├─ 10 Instagram-ready
    ├─ 10 Facebook-ready
    └─ 10 Twitter-ready

Time Saved: 
    Manual: 30 images × 5 min = 2.5 hours
    Automated: 5 min setup + 10 min processing = 15 min
    Savings: 2 hours 15 minutes (90% faster)
```

## Integration Points

### With WordPress Core

```
Media Toolkit
    ├─► wp_posts (CPT storage)
    ├─► wp_postmeta (Configuration)
    ├─► wp_term_relationships (Categories)
    ├─► Media Library (Image selection)
    └─► Admin UI (Custom pages)
```

### With Pro Addon

```
Media Toolkit
    ├─► Tool Registry (AI tools)
    ├─► REST API (MCP protocol)
    ├─► Graphic Editor Plus (Processing)
    └─► Settings System (Feature toggle)
```

### With AI Assistants

```
Chat Interface
    │
    ▼
AI Assistant
    │
    ▼
MCP Protocol
    │
    ▼
Media Toolkit Tools
    │
    ▼
WordPress Processing
```

## Performance Characteristics

### Single Template Application

```
Request → Validate → Process → Update → Response
   10ms      20ms      500ms     10ms     540ms total
```

### Bulk Collection Processing

```
For 50 items × 3 templates = 150 operations:
    Setup: 100ms
    Processing: 150 × 500ms = 75 seconds
    Updates: 150 × 10ms = 1.5 seconds
    Total: ~77 seconds (1.3 minutes)
```

### Optimal Performance

- Items per collection: 20-50
- Templates per collection: 2-5
- Concurrent processing: Sequential (safe)
- Batch size recommendation: Under 200 operations

## Security Model

```
Request
    ├─► Nonce verification
    ├─► Capability check
    ├─► Input sanitization
    ├─► Template validation
    ├─► Attachment verification
    └─► Execute with monitoring
```

## Error Handling Flow

```
Operation Request
    │
    ▼
┌─────────────┐
│ Validate    │──► Error → Return error message
└──────┬──────┘
       │ OK
       ▼
┌─────────────┐
│ Execute     │──► Error → Log + Return error
└──────┬──────┘
       │ Success
       ▼
┌─────────────┐
│ Update Stats│──► Error → Log (non-critical)
└──────┬──────┘
       │
       ▼
Return Success
```

## Scalability Considerations

### Template Management

- **Small scale:** < 50 templates
- **Medium scale:** 50-200 templates
- **Large scale:** 200+ templates (use categories)

### Collection Processing

- **Small batch:** < 10 items
- **Medium batch:** 10-50 items
- **Large batch:** 50+ items (process during off-peak)

### Resource Usage

- **Memory:** ~2MB per template
- **Processing:** Depends on Graphic Editor Plus
- **Storage:** Minimal (metadata only)

---

**Next:** [Read the Tutorials](media-toolkit-tutorials.md) to see these workflows in action!
