# Profession Knowledge Base System - Complete Guide

## Overview

The WP oOS profession system uses **three interconnected knowledge base directories** to provide AI assistants with comprehensive professional guidance. This document explains how each directory works, what it contains, and how they integrate together.

**Important:** This system has been recently corrected. profession-documents now properly populates the Knowledge Base Content field instead of creating attachments.

## Three Knowledge Base Systems

### 1. **professions/** - JSON Metadata Files (12 Category Files → 191 Professions)

**Location:** `includes/knowledge-base/professions/`

**Purpose:** Source of truth for profession metadata that populates the WordPress profession custom post type (CPT).

**Structure:**
```
professions/
├── agriculture-natural-resources.json  (10 professions)
├── art-media-entertainment.json        (24 professions)
├── business-finance.json               (16 professions)
├── education.json                      (10 professions)
├── healthcare-medicine.json            (25 professions)
├── law-public-safety.json              (11 professions)
├── miscellaneous-professions.json      (22 professions)
├── science-engineering.json            (25 professions)
├── service-industry.json               (12 professions)
├── technology.json                     (13 professions)
├── trades-manual-labor.json            (13 professions)
└── transportation.json                 (10 professions)
```

**Total:** 12 category files containing 191 professions

**Content:** Each JSON file contains:
- Category metadata
- Array of professions with:
  - Title, slug, description
  - Category (advisory, creative, technical, healthcare, legal, financial, other)
  - Role description (for AI prompts)
  - Expertise areas (array)
  - Warnings/disclaimers (array)
  - Knowledge base notes (markdown text) - **This is SHORT summary text, full content comes from profession-documents/**
  - Default tools (array of tool slugs)
  - Optional: region specification

**Example:**
```json
{
  "category": "business-finance",
  "description": "Business and finance professionals...",
  "professions": [
    {
      "title": "Accountant",
      "slug": "accountant",
      "description": "Expert in accounting principles...",
      "category": "financial",
      "role_description": "You assist with accounting principles...",
      "expertise": ["GAAP/IFRS", "Financial reporting", ...],
      "warnings": ["Complex matters require certified accountant", ...],
      "knowledge_base": "### Accounting Practice\n- Follow GAAP...", // SHORT
      "default_tools": ["web_search", "search_content", ...]
    },
    ...
  ]
}
```

**How It's Used:**
1. **Initial Seeding:** On plugin activation, `WP_MCP_AI_Profession_Seeder` reads these JSON files
2. **Creates CPT Posts:** For each profession, creates a `mcp_ai_profession` post with meta fields
3. **Reseed Action:** Admin can trigger "Reseed Professions" to update/replace from JSON

**Triggers:**
- Plugin activation (once)
- Admin Settings → Advanced → "Update Professions" button
- Admin Settings → Advanced → "Replace All Professions" button

---

### 2. **profession-documents/** - Knowledge Base Content (191 TXT Files)

**Location:** `includes/knowledge-base/profession-documents/`

**Purpose:** Rich reference material that populates the **Knowledge Base Content** field in the profession CPT. This is what assistants use as foundational knowledge.

**Structure:**
```
profession-documents/
├── accountant.txt
├── software_engineer.txt
├── registered_nurse.txt
└── ... (191 total .txt files, one per profession)
```

**Content Format:** Plain text structured document with:
- Profession title, slug, category
- Overview and description
- Role description
- Core expertise list
- Knowledge base notes (detailed, not just from JSON)
- Warnings and disclaimers
- Default tools list

**Example (`accountant.txt`):**
```
Accountant — Profession Knowledge Pack

Slug: accountant
Category: financial

Overview
--------
Expert in accounting principles, financial reporting, and bookkeeping

Role Description
---------------
You assist with accounting principles, financial reporting, bookkeeping, and financial management.

Core Expertise
-------------
- Accounting principles (GAAP/IFRS)
- Financial statement preparation
- Bookkeeping and record-keeping
- Financial analysis and reporting
- Budgeting and forecasting
- Tax compliance

Knowledge Base Notes
-------------------
### Accounting Practice
- Follow GAAP or IFRS standards
- Maintain accurate and timely records
- Ensure internal controls and audit trails
- Prepare financial statements (Balance Sheet, Income Statement, Cash Flow)
- Support business decision-making with financial data
- Stay current with accounting standards updates

### Financial Reporting Standards
[... detailed content ...]

### Best Practices
[... detailed content ...]

Warnings & Disclaimers
---------------------
- Complex accounting matters should be reviewed by a certified accountant
- Tax regulations vary by jurisdiction

Default Tools
-------------
- web_search
- search_content
- save_post
```

**How It's Created & Used:**

1. **Seeding Process:**
   - `WP_MCP_AI_Profession_Base_Knowledge_Seeder` reads these .txt files
   - Looks for `profession-documents/{slug}.txt`
   - If found, reads the entire file content
   - **Populates the `META_KNOWLEDGE_BASE` field** directly (NOT an attachment!)
   - This field is the rich editor in the profession edit screen

2. **In the WordPress Admin:**
   - Navigate to profession edit screen
   - See "Expertise & Knowledge" metabox
   - Find "Knowledge Base Content" field (rich editor)
   - This field is populated from profession-documents/{slug}.txt
   - Admins can edit this content directly in WordPress

3. **When Creating Assistants:**
   - Assistant creation reads `META_KNOWLEDGE_BASE` from profession post
   - This content is incorporated into the system prompt
   - NO attachment is created - it's direct text in the prompt

**Triggers:**
- Runs after profession seeding (automatically)
- Can be triggered via "Reseed Professions" → automatically calls `seed_base_knowledge(true)`

**Storage:**
- **Field:** `_wp_mcp_ai_profession_knowledge_base` (post meta)
- **Type:** Text (can be quite long)
- **Editable:** Yes, via WordPress admin in profession edit screen
- **Used:** Directly in system prompt when creating assistants

---

### 3. **profession-playbooks/** - Actionable Instructions (Modular TXT Files)

**Location:** `includes/knowledge-base/profession-playbooks/`

**Purpose:** **Authorable playbooks** with specific instructions, SOPs, checklists, and templates. These are **action-oriented** vs. the reference nature of profession-documents. **Creates attachment files** that are included in assistant memory.

**Structure:**
```
profession-playbooks/
├── global.txt                      # Universal guidelines (all professions)
├── categories/                     # Category-specific workflows
│   ├── advisory.txt               (for advisory/consulting category)
│   ├── creative.txt               (for creative services)
│   ├── technical.txt              (for technical professions)
│   ├── healthcare.txt
│   ├── legal.txt
│   ├── financial.txt
│   └── other.txt
└── professions/                    # Profession-specific playbooks
    ├── accountant.txt             (191 files, one per profession)
    ├── software_engineer.txt
    ├── registered_nurse.txt
    └── ...
```

**Content - Profession Files (`professions/*.txt`):**

Structured with actionable guidance:
- Role boundaries (Do / Do NOT / Escalate when)
- Quick intake questions (what to ask users)
- Regional variations (standards by jurisdiction)
- Workflows and SOPs
- Quality rubrics
- Common challenges and solutions
- Best practices
- Templates and checklists

**Example (`professions/accountant.txt`):**
```
Profession Playbook — Accountant
Slug: accountant
Category: financial
Includes: global.txt + categories/financial.txt + this file

Intent
- This file contains actionable instructions, SOPs, checklists,
  and templates for high-quality accounting assistance.

1) Role boundaries
Do:
- Provide general accounting guidance on bookkeeping...
- Explain accounting principles (GAAP, IFRS)...
- Help prepare financial statements...

Do NOT:
- Provide specific tax advice or prepare official tax returns...
- Audit financial statements officially...
- Represent clients before IRS...

Escalate / refer out when:
- Tax controversy, IRS audits arise...
- Complex business valuations needed...
- Forensic accounting required...

2) Quick intake questions
Ask 3–8 questions that materially affect the answer:
- What is your region/country? (GAAP vs IFRS vs regional)
- What is the business entity type?
- What accounting method is used (cash, accrual)?
...

1.5) Regional Variations - Accounting Standards & Regulations

**United States:**
- Accounting Framework: US GAAP
- Standard-Setter: FASB
...

**United Kingdom:**
- Accounting Framework: UK GAAP or IFRS
...
```

**Content - Category Files (`categories/*.txt`):**

Shared guidelines for all professions in a category:
- Category-wide workflows
- Quality standards
- Common patterns
- Shared best practices
- Category-specific risks

**Content - Global File (`global.txt`):**

Universal guidelines that apply to ALL professions:
- Professional conduct principles
- Communication standards
- Safety guardrails
- Ethical boundaries
- Universal disclaimers

**How Playbooks Are Assembled:**

The `WP_MCP_AI_Profession_Playbook_Loader` class assembles playbooks in this order:

1. **Header** - Profession title, timestamp, region context
2. **Global Section** - Content from `global.txt`
3. **Category Section** - Content from `categories/{category}.txt`
4. **Profession Section** - Content from `professions/{slug}.txt`
5. **Region Context** - If region specified, adds region-specific guidance
6. **Tool Recommendations** - Intelligent tool mapping (auto-generated by `WP_MCP_AI_Profession_Tool_Recommender`)
7. **Footer** - Generation info

**Assembled Playbook Example:**
```
# Accountant - Professional Playbook
Generated: 2025-12-19 16:30:00 UTC
Primary Region/Jurisdiction: United States
---

## Global Guidelines
[Content from global.txt]
---

## Financial Category Guidelines
[Content from categories/financial.txt]
---

## Accountant Specific Guidelines
[Content from professions/accountant.txt]
---

## Region-Specific Context
This playbook is optimized for: **United States**

When providing guidance:
- Prioritize standards, regulations, and practices relevant to United States
- Reference US-appropriate frameworks (FASB, SEC, IRS)
- Note when practices differ in other regions
- Always ask about user's location if it affects the answer
---

## Recommended Tools & How to Use Them

This profession has access to 15 recommended tools...

### Core Tools
**web_search** - Search the web for current accounting standards...
Usage: Use for recent GAAP updates, tax law changes...

**save_post** - Create documentation and records...
Usage: Document financial processes, create audit trails...
...
---

This playbook is assembled from authorable text files in the WP oOS plugin.
To update this content, edit the relevant txt files in includes/knowledge-base/profession-playbooks/
```

**How Playbooks Are Created:**

1. `WP_MCP_AI_Profession_Playbook_Seeder` processes professions in batches (20 per admin_init)
2. For each profession, calls `WP_MCP_AI_Profession_Playbook_Loader->build_playbook()`
3. Loader reads and concatenates: global.txt + category txt + profession txt + tool recommendations
4. Calculates SHA256 hash of assembled content
5. **Creates WordPress attachment file** in `uploads/wp-mcp-ai/profession-playbooks/`
6. **Adds attachment ID to profession's `META_MEMORY_FILES`**

**Change Detection:**
- Uses SHA256 hash stored in attachment meta `_wp_mcp_ai_playbook_hash`
- Only regenerates if content hash changes (idempotent)
- Force regeneration available via admin UI or sync methods

**Attachment Storage:**
- Path: `wp-content/uploads/wp-mcp-ai/profession-playbooks/profession-{ID}-{slug}-playbook.txt`
- Post type: `attachment`
- MIME type: `text/plain`
- Meta: `_wp_mcp_ai_playbook_profession_id`, `_wp_mcp_ai_playbook_hash`
- **Linked to profession via:** `_wp_mcp_ai_profession_memory_files` meta array

**Triggers:**
- Automatic: First admin load after activation (incremental, 20 per cycle)
- Manual: "Reseed Professions" → automatically calls `sync_all(true)`
- Manual: "Sync Changed Playbooks" → calls `sync_all(false)`
- Manual: "Force Regenerate All Playbooks" → calls `sync_all(true)`
- Manual: Single profession "Regenerate Playbook" button in profession edit screen

---

## How They Work Together

### During Profession Seeding

1. **JSON → CPT Posts**
   - `WP_MCP_AI_Profession_Seeder` reads `professions/*.json`
   - Creates/updates `mcp_ai_profession` posts
   - Populates meta fields from JSON (including short knowledge_base text from JSON)

2. **Base Knowledge Content Population**
   - `WP_MCP_AI_Profession_Base_Knowledge_Seeder` runs after profession seeding
   - Looks for matching `.txt` file in `profession-documents/`
   - **Reads file content and updates `META_KNOWLEDGE_BASE` field**
   - **NO attachment created** - just updates the post meta field
   - This field is the rich editor in profession edit screen

3. **Playbook Attachment Creation**
   - `WP_MCP_AI_Profession_Playbook_Seeder` runs after base knowledge seeding
   - Assembles playbook from modular txt files (global + category + profession)
   - **Creates playbook attachment file**
   - **Adds attachment ID to `META_MEMORY_FILES`**

**Result:** Each profession post has:
- `META_KNOWLEDGE_BASE` field with rich reference content (from profession-documents/)
- `META_MEMORY_FILES` array with playbook attachment ID(s)

### When Creating Assistants

When an admin creates an assistant and selects a profession as a primary role:

1. **System Prompt Construction**
   - `WP_MCP_AI_Assistant_CPT::build_system_prompt_from_primary_roles()` is called
   - Reads profession's:
     - Role description
     - Expertise areas
     - Warnings
     - **`META_KNOWLEDGE_BASE` content** (the full text from profession-documents/)
   - Constructs comprehensive text-based system prompt
   - Knowledge base content is directly embedded in the prompt

2. **Memory Files (Playbooks Only)**
   - Reads profession's `_wp_mcp_ai_profession_memory_files` meta
   - Gets array of attachment IDs (playbook files only, NOT base knowledge)
   - Copies these attachment IDs to assistant's memory files
   - When assistant is used, playbook files are available to the AI as reference documents

3. **Tool Selection**
   - Reads profession's `_wp_mcp_ai_profession_default_tools` meta
   - Pre-selects these tools in the assistant creation UI
   - Admin can modify tool selection before saving

### Correct Data Flow Diagram

```
JSON Files (professions/*.json)
    ↓
[Profession Seeder] → Creates CPT Posts
    ↓                   ↓
    ↓              Post Meta Fields
    ↓              (role, expertise, warnings, etc.)
    ↓
profession-documents/*.txt (191 files)
    ↓
[Base Knowledge Seeder] → Populates META_KNOWLEDGE_BASE field
    ↓                       (NO attachment created)
    ↓
profession-playbooks/ (global + category + profession)
    ↓
[Playbook Seeder] → Creates Playbook Attachment
    ↓                  ↓
    ↓             META_MEMORY_FILES[]
    ↓
[Assistant Creation] → Uses profession data:
    ↓
    ├─ System Prompt = role + expertise + warnings + META_KNOWLEDGE_BASE (direct text)
    └─ Memory Files = Playbook attachment(s) from META_MEMORY_FILES
    
Assistant has:
  ✓ Knowledge Base Content embedded in system prompt (from profession-documents/)
  ✓ Playbook file(s) as memory attachments (from profession-playbooks/)
```

## Key Differences

| Aspect | profession-documents | profession-playbooks |
|--------|---------------------|---------------------|
| **Purpose** | Reference material | Actionable instructions |
| **Content** | What the profession is about | How to perform the work |
| **Tone** | Informational | Directive |
| **Storage** | `META_KNOWLEDGE_BASE` field (post meta) | Attachment file in uploads |
| **Integration** | Embedded in system prompt (direct text) | Attached as memory file |
| **Editing** | Via WordPress admin (rich editor) | Edit TXT files, sync playbooks |
| **Structure** | Single comprehensive document | Modular (global + category + specific) |
| **Examples** | "Financial reporting is...", "Core expertise includes..." | "DO: Explain GAAP standards", "DO NOT: Provide tax advice", "Escalate when..." |
| **Source** | TXT file → Post meta field | TXT files assembled → Attachment |
| **Assembly** | One file, used as-is | Three files assembled (global + category + profession) |
| **Updates** | Edit in WP admin OR reseed from TXT | Edit TXT files, sync playbooks |
| **Tool Recommendations** | None | Dynamically generated with usage guidance |
| **File Count** | 191 files (one per profession) | 191 profession files + 7 category files + 1 global = 199 files |

## Common Workflows

### Editing Base Knowledge Content

**Option 1: Via WordPress Admin (Recommended for minor edits)**
1. Navigate to profession edit screen
2. Find "Expertise & Knowledge" metabox
3. Edit "Knowledge Base Content" field (rich editor)
4. Save profession

**Option 2: Via TXT Files (Recommended for major updates)**
1. Navigate to `includes/knowledge-base/profession-documents/`
2. Edit `{slug}.txt` file
3. Go to WP Admin → Settings → WP oOS → Advanced
4. Click "Update Professions" (this triggers base knowledge sync)
5. Profession's `META_KNOWLEDGE_BASE` will be updated from file

### Editing Playbook Content

1. Navigate to `includes/knowledge-base/profession-playbooks/`
2. Edit appropriate file:
   - **All professions:** Edit `global.txt`
   - **Category-wide:** Edit `categories/{category}.txt`
   - **Single profession:** Edit `professions/{slug}.txt`
3. Test locally (optional)
4. Go to WP Admin → Settings → WP oOS → Advanced
5. Scroll to "Sync Profession Playbooks" section
6. Click "Sync Changed Playbooks" (fast) or "Force Regenerate All Playbooks" (thorough)
7. Wait for completion
8. Check a profession edit screen → "Professional Playbook" metabox to verify

### Adding a New Profession

1. Edit appropriate JSON file in `professions/` (e.g., `technology.json`)
2. Add new profession object with all required fields
3. Create `profession-documents/{slug}.txt` with detailed reference content
4. Create `profession-playbooks/professions/{slug}.txt` with actionable instructions
5. Go to WP Admin → Settings → WP oOS → Advanced
6. Click "Update Professions" (preserves existing) or "Replace All" (clean slate)
7. System will:
   - Create profession CPT post with metadata
   - Populate `META_KNOWLEDGE_BASE` from profession-documents/{slug}.txt
   - Generate playbook attachment from assembled playbook files
   - Add playbook attachment to `META_MEMORY_FILES`

### Regenerating Single Playbook

1. Navigate to profession edit screen (e.g., "Edit Accountant")
2. Find "Professional Playbook" metabox (right sidebar)
3. Click "Regenerate Playbook" button
4. Wait for success message
5. Click "View Playbook" to verify changes

### Bulk Playbook Sync

**After editing multiple playbook files:**

1. Go to WP Admin → Settings → WP oOS → Advanced
2. Scroll to "Sync Profession Playbooks" section
3. Choose:
   - **"Sync Changed Playbooks"** - Only updates files where content changed (uses hash detection, fast)
   - **"Force Regenerate All"** - Rebuilds all 191 playbooks regardless (slower, ~30-60 seconds)
4. Wait for completion message
5. Page will reload showing updated stats

## Technical Implementation

### Classes

- **WP_MCP_AI_Profession_Knowledge_Base_Loader** - Loads JSON files
- **WP_MCP_AI_Profession_Repository** - CRUD for profession CPT
- **WP_MCP_AI_Profession_Seeder** - Processes JSON → CPT
- **WP_MCP_AI_Profession_Base_Knowledge_Seeder** - Populates META_KNOWLEDGE_BASE from profession-documents/*.txt
- **WP_MCP_AI_Profession_Playbook_Loader** - Assembles playbooks from modular TXT files
- **WP_MCP_AI_Profession_Playbook_Seeder** - Creates playbook attachments
- **WP_MCP_AI_Profession_Tool_Recommender** - Generates tool recommendations

### Post Meta Fields

**Profession CPT (`mcp_ai_profession`):**
- `_wp_mcp_ai_profession_category` - Category (advisory, creative, etc.)
- `_wp_mcp_ai_profession_expertise` - Array of expertise areas
- `_wp_mcp_ai_profession_default_tools` - Array of tool slugs
- `_wp_mcp_ai_profession_role_description` - Role description text
- `_wp_mcp_ai_profession_warnings` - Array of warnings
- `_wp_mcp_ai_profession_knowledge_base` - **RICH TEXT from profession-documents/{slug}.txt**
- `_wp_mcp_ai_profession_memory_files` - Array of attachment IDs (playbooks only)
- `_wp_mcp_ai_profession_vector_store_id` - Optional vector store ID
- `_wp_mcp_ai_profession_supported_mime_types` - Array of MIME types
- `_wp_mcp_ai_profession_region` - Primary region/jurisdiction

### Hooks

```php
// After profession seeding
add_action( 'admin_init', array( 'WP_MCP_AI_Profession_Base_Knowledge_Seeder', 'seed_base_knowledge' ), 30 );

// After base knowledge seeding
add_action( 'admin_init', array( 'WP_MCP_AI_Profession_Playbook_Seeder', 'seed_playbooks_incremental' ), 30 );
```

### AJAX Actions

- `wp_ajax_wp_mcp_ai_reseed_professions` - Reseed from JSON (includes base knowledge + playbooks)
- `wp_ajax_wp_mcp_ai_regenerate_playbook` - Single playbook regeneration
- `wp_ajax_wp_mcp_ai_sync_all_playbooks` - Bulk playbook sync

### API Usage

```php
// Load profession-documents content into META_KNOWLEDGE_BASE
$slug = 'accountant';
$file_path = WP_MCP_AI_PATH . 'includes/knowledge-base/profession-documents/' . $slug . '.txt';
$content = file_get_contents( $file_path );
update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, $content );

// Load and assemble a playbook
$loader = new WP_MCP_AI_Profession_Playbook_Loader();
$playbook_content = $loader->build_playbook( $profession_id );

// Sync all playbooks (only changed)
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

// Force regenerate all playbooks
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );
```

## Troubleshooting

### Knowledge Base Content not updating

**Problem:** Edited a profession-documents/*.txt file but changes aren't in the profession CPT.

**Solution:**
1. Go to Settings → WP oOS → Advanced
2. Click "Update Professions" (this triggers base knowledge sync)
3. Check profession edit screen → "Knowledge Base Content" field
4. Verify file exists at `includes/knowledge-base/profession-documents/{slug}.txt`
5. Check file encoding is UTF-8

### Playbooks not updating after editing TXT files

**Problem:** Edited a playbook file but changes aren't reflected.

**Solution:**
1. Go to Settings → WP oOS → Advanced
2. Scroll to "Sync Profession Playbooks"
3. Click "Sync Changed Playbooks"
4. If still not updated, try "Force Regenerate All Playbooks"
5. Check that file encoding is UTF-8
6. Verify file exists at expected path

### Missing playbook attachments

**Problem:** Profession doesn't have playbook attachment in memory files.

**Solution:**
1. Check profession edit screen → "Professional Playbook" metabox
2. If shows "Not Generated", click "Regenerate Playbook"
3. OR go to Settings → Advanced → "Sync All Playbooks"
4. Check `wp-content/uploads/wp-mcp-ai/profession-playbooks/` for attachment files
5. Query database for attachments with profession ID in meta

### Old base knowledge attachments still exist

**Problem:** After upgrade, old base knowledge attachments are still in uploads/wp-mcp-ai/profession-knowledge/.

**Solution:**
- These are legacy from the old system
- They are no longer linked to professions (not in META_MEMORY_FILES)
- Safe to delete manually if desired
- Or leave them - they're not being used

## Migration Notes

**For existing installations upgrading to corrected system:**

The system has been updated to use profession-documents correctly:

**Before (Incorrect):**
- profession-documents/*.txt → Created attachments
- META_KNOWLEDGE_BASE had only short JSON content

**After (Correct):**
- profession-documents/*.txt → Populates META_KNOWLEDGE_BASE field
- No attachments created for base knowledge
- Only playbooks create attachments

**What happens on upgrade:**
1. Run "Reseed Professions" to populate META_KNOWLEDGE_BASE from profession-documents/*.txt
2. Old base knowledge attachments will remain in uploads but won't be linked
3. Playbooks continue to work as before (no changes to playbook system)
4. Assistants will now have richer knowledge base content in system prompts

**Optional cleanup:**
- Old attachments in `uploads/wp-mcp-ai/profession-knowledge/` can be deleted
- They're no longer linked to professions
- Query: `SELECT * FROM wp_postmeta WHERE meta_key = '_wp_mcp_ai_seeded_profession_doc_type' AND meta_value = 'base_knowledge'`

## Best Practices

1. **Editing Strategy**
   - Edit profession-documents/*.txt for foundational reference material
   - Edit playbook global.txt for universal changes
   - Edit playbook categories/*.txt for category-wide updates
   - Edit playbook professions/*.txt for profession-specific instructions
   - Minimize redundancy across all systems

2. **Content Guidelines**
   - **profession-documents**: Focus on "what" - definitions, concepts, knowledge
   - **playbooks**: Focus on "how" - procedures, checklists, workflows
   - Be specific and actionable in playbooks
   - Use active voice in playbooks
   - Provide concrete examples

3. **Version Control**
   - All TXT files are in git - use meaningful commit messages
   - Test changes locally before committing
   - Document significant changes in commit descriptions

4. **Testing**
   - After editing profession-documents, reseed professions
   - After editing playbooks, sync playbooks
   - Create test assistant to verify both knowledge base and playbook work correctly
   - Check that AI responses reflect updated guidance

5. **Performance**
   - Use "Sync Changed Playbooks" for routine updates (fast)
   - Use "Force Regenerate All" only after major structural changes
   - Batch processing handles 191 professions efficiently

## Future Enhancements

Potential improvements:
- [ ] Admin UI for editing profession-documents in WordPress (currently via TXT files)
- [ ] Visual diff showing changes between versions
- [ ] Preview assembled playbook before saving
- [ ] Multilingual support for both systems
- [ ] AI-powered tool recommendation refinement
- [ ] Export/import profession bundles
- [ ] Playbook templates for new professions
- [ ] Automated testing of profession → assistant workflow

## Related Documentation

- **Playbook System:** `includes/knowledge-base/profession-playbooks/README.md`
- **Tool Recommendations:** `docs/PROFESSION_TOOL_RECOMMENDATIONS.md`
- **Quick Reference:** `docs/QUICK_GUIDE_TOOL_MAPPINGS.md`
- **Tool Catalog:** `docs/tool-reference.md`
- **Architecture:** This document

---

**Last Updated:** December 2025
**Version:** 1.7.0
**Professions:** 191 across 12 categories
**System Status:** Corrected - profession-documents now properly populates Knowledge Base Content field
