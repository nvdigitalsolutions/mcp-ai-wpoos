# Profession Knowledge Base System - Complete Guide

## Overview

The WP oOS profession system uses **three interconnected knowledge base directories** to provide AI assistants with comprehensive professional guidance. This document explains how each directory works, what it contains, and how they integrate together.

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
  - Knowledge base notes (markdown text)
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
      "knowledge_base": "### Accounting Practice\n- Follow GAAP...",
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

### 2. **profession-documents/** - Base Knowledge Reference (191 TXT Files)

**Location:** `includes/knowledge-base/profession-documents/`

**Purpose:** Detailed reference material exported/generated from profession posts. Provides **foundational knowledge** about each profession.

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
- Knowledge base notes (from JSON)
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
You assist with accounting principles, financial reporting, bookkeeping...

Core Expertise
-------------
- Accounting principles (GAAP/IFRS)
- Financial statement preparation
- Bookkeeping and record-keeping
...

Knowledge Base Notes
-------------------
### Accounting Practice
- Follow GAAP or IFRS standards
- Maintain accurate and timely records
...

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

**How It's Created:**
- `WP_MCP_AI_Profession_Base_Knowledge_Seeder` reads these files (if they exist)
- Creates attachment files in `uploads/wp-mcp-ai/base-knowledge/`
- Attachments are linked to profession posts via `META_MEMORY_FILES`

**How It's Used:**
1. Seeder looks for matching .txt file by slug
2. If found, uses file content directly
3. If not found, generates content from profession post meta
4. Creates WordPress attachment (MIME: text/plain)
5. Adds attachment ID to profession's `_wp_mcp_ai_profession_memory_files` meta
6. When creating assistants from this profession, these files become part of the assistant's memory

**Triggers:**
- Runs after profession seeding (automatically)
- Can be triggered via "Reseed Professions" → automatically calls `seed_base_knowledge(true)`

**Attachment Storage:**
- Path: `wp-content/uploads/wp-mcp-ai/base-knowledge/profession-{ID}-{slug}-base-knowledge.txt`
- Post type: `attachment`
- MIME type: `text/plain`
- Meta: `_wp_mcp_ai_seeded_profession_slug`, `_wp_mcp_ai_seeded_profession_doc_type` = `base_knowledge`

---

### 3. **profession-playbooks/** - Actionable Instructions (Modular TXT Files)

**Location:** `includes/knowledge-base/profession-playbooks/`

**Purpose:** **Authorable playbooks** with specific instructions, SOPs, checklists, and templates. These are **action-oriented** vs. the reference nature of profession-documents.

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

**Example (`categories/financial.txt`):**
```
Financial Professions — Category Playbook

This applies to: accountants, financial advisors, bookkeepers, etc.

## Category Philosophy
- Emphasize diagnosis before prescription
- Present options with clear tradeoffs
- Provide concrete recommendations
- Focus on actionable execution plans

## Financial Compliance Framework
...
```

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
5. Creates/updates WordPress attachment in `uploads/wp-mcp-ai/profession-playbooks/`
6. Adds attachment ID to profession's `_wp_mcp_ai_profession_memory_files`

**Change Detection:**
- Uses SHA256 hash stored in attachment meta `_wp_mcp_ai_playbook_hash`
- Only regenerates if content hash changes (idempotent)
- Force regeneration available via admin UI or sync methods

**Attachment Storage:**
- Path: `wp-content/uploads/wp-mcp-ai/profession-playbooks/profession-{ID}-{slug}-playbook.txt`
- Post type: `attachment`
- MIME type: `text/plain`
- Meta: `_wp_mcp_ai_playbook_profession_id`, `_wp_mcp_ai_playbook_hash`

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
   - Populates meta fields from JSON

2. **Base Knowledge Attachment**
   - `WP_MCP_AI_Profession_Base_Knowledge_Seeder` runs after profession seeding
   - Looks for matching `.txt` file in `profession-documents/`
   - Creates attachment from file content (or generates from post meta)
   - **Adds attachment ID to profession's `META_MEMORY_FILES`**

3. **Playbook Attachment**
   - `WP_MCP_AI_Profession_Playbook_Seeder` runs after base knowledge seeding
   - Assembles playbook from modular txt files
   - Creates playbook attachment
   - **Also adds attachment ID to profession's `META_MEMORY_FILES`**

**Result:** Each profession post has 2 attachments in its `_wp_mcp_ai_profession_memory_files` meta:
- One base knowledge file (reference material)
- One playbook file (actionable instructions)

### When Creating Assistants

When an admin creates an assistant and selects a profession as a primary role:

1. **System Prompt Construction**
   - `WP_MCP_AI_Assistant_CPT::build_system_prompt_from_primary_roles()` is called
   - Reads profession's role description, expertise, warnings from post meta
   - Constructs text-based system prompt

2. **Memory Files**
   - Reads profession's `_wp_mcp_ai_profession_memory_files` meta
   - Gets array of attachment IDs (includes both base knowledge AND playbook)
   - Copies these attachment IDs to assistant's memory files
   - When assistant is used, both files are available to the AI:
     - **Base knowledge** provides foundational understanding
     - **Playbook** provides step-by-step guidance for tasks

3. **Tool Selection**
   - Reads profession's `_wp_mcp_ai_profession_default_tools` meta
   - Pre-selects these tools in the assistant creation UI
   - Admin can modify tool selection before saving

### Data Flow Diagram

```
JSON Files (professions/*.json)
    ↓
[Profession Seeder] → Creates CPT Posts
    ↓
profession-documents/*.txt
    ↓
[Base Knowledge Seeder] → Creates Attachment 1
    ↓                       ↓
    ↓                  META_MEMORY_FILES[0]
    ↓
profession-playbooks/ (global + category + profession)
    ↓
[Playbook Seeder] → Creates Attachment 2
    ↓                  ↓
    ↓             META_MEMORY_FILES[1]
    ↓
[Assistant Creation] → Copies both attachments
    ↓
Assistant has BOTH files available:
- Base Knowledge (reference)
- Playbook (instructions)
```

## Key Differences

| Aspect | profession-documents | profession-playbooks |
|--------|---------------------|---------------------|
| **Purpose** | Reference material | Actionable instructions |
| **Content** | What the profession is | How to perform the work |
| **Tone** | Informational | Directive |
| **Structure** | Flat text export | Modular (global + category + specific) |
| **Editing** | Typically auto-generated | Hand-authored for quality |
| **Examples** | "Financial reporting is...", "Core expertise includes..." | "DO: Explain GAAP standards", "DO NOT: Provide tax advice", "Escalate when..." |
| **Source** | JSON → Post Meta → TXT | Hand-written TXT files |
| **Assembly** | One file per profession | Three files assembled (global + category + profession) |
| **Updates** | Via JSON reseed | Edit TXT files, sync playbooks |
| **Tool Recommendations** | Listed in JSON | Dynamically generated with usage guidance |

## Common Workflows

### Editing Playbook Content

1. Navigate to `includes/knowledge-base/profession-playbooks/`
2. Edit appropriate file:
   - **All professions:** Edit `global.txt`
   - **Category-wide:** Edit `categories/{category}.txt`
   - **Single profession:** Edit `professions/{slug}.txt`
3. Test locally (optional)
4. Go to WP Admin → Settings → WP oOS → Advanced
5. Click "Sync Changed Playbooks" (fast) or "Force Regenerate All Playbooks" (thorough)
6. Wait for completion
7. Check a profession edit screen to verify playbook was regenerated

### Adding a New Profession

1. Edit appropriate JSON file in `professions/` (e.g., `technology.json`)
2. Add new profession object with all required fields
3. Create `profession-documents/{slug}.txt` (optional, can be auto-generated)
4. Create `profession-playbooks/professions/{slug}.txt` (recommended for quality)
5. Go to WP Admin → Settings → WP oOS → Advanced
6. Click "Update Professions" (preserves existing) or "Replace All" (clean slate)
7. System will:
   - Create profession CPT post
   - Generate base knowledge attachment
   - Generate playbook attachment
   - Both attached to profession's memory files

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
- **WP_MCP_AI_Profession_Base_Knowledge_Seeder** - Creates base knowledge attachments
- **WP_MCP_AI_Profession_Playbook_Loader** - Assembles playbooks from modular TXT files
- **WP_MCP_AI_Profession_Playbook_Seeder** - Creates playbook attachments
- **WP_MCP_AI_Profession_Tool_Recommender** - Generates tool recommendations

### Hooks

```php
// After profession seeding
add_action( 'admin_init', array( 'WP_MCP_AI_Profession_Base_Knowledge_Seeder', 'seed_base_knowledge' ), 30 );

// After base knowledge seeding
add_action( 'admin_init', array( 'WP_MCP_AI_Profession_Playbook_Seeder', 'seed_playbooks_incremental' ), 30 );
```

### AJAX Actions

- `wp_ajax_wp_mcp_ai_reseed_professions` - Reseed from JSON
- `wp_ajax_wp_mcp_ai_regenerate_playbook` - Single playbook regeneration
- `wp_ajax_wp_mcp_ai_sync_all_playbooks` - Bulk playbook sync

### API Usage

```php
// Load and assemble a playbook
$loader = new WP_MCP_AI_Profession_Playbook_Loader();
$playbook_content = $loader->build_playbook( $profession_id );

// Sync all playbooks (only changed)
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );

// Force regenerate all playbooks
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );

// Load base knowledge from documents
$doc_path = WP_MCP_AI_PATH . 'includes/knowledge-base/profession-documents/' . $slug . '.txt';
$base_knowledge = file_get_contents( $doc_path );
```

## Troubleshooting

### Playbooks not updating after editing TXT files

**Problem:** Edited a playbook file but changes aren't reflected.

**Solution:**
1. Go to Settings → WP oOS → Advanced
2. Click "Sync Changed Playbooks"
3. If still not updated, try "Force Regenerate All Playbooks"
4. Check that file encoding is UTF-8
5. Verify file exists at expected path

### Missing attachments in profession memory files

**Problem:** Profession doesn't have both attachments.

**Solution:**
1. Go to Settings → WP oOS → Advanced
2. Click "Update Professions" (triggers full reseed pipeline)
3. Check `wp-content/uploads/wp-mcp-ai/` for attachment files
4. Query database for attachments with profession ID in meta

### Profession not found in JSON

**Problem:** Want to add custom profession not in JSON files.

**Solution:**
1. Create profession manually in WP Admin (Add New Profession)
2. OR edit appropriate JSON category file and add profession object
3. Create matching playbook file in `profession-playbooks/professions/{slug}.txt`
4. If created manually, playbook will be generated but may be empty
5. Consider contributing profession back to JSON files for future users

## Best Practices

1. **Editing Strategy**
   - Edit `global.txt` for universal changes affecting all professions
   - Edit `categories/*.txt` for category-wide updates
   - Edit `professions/{slug}.txt` for profession-specific refinements
   - Minimize redundancy - don't repeat content across layers

2. **Version Control**
   - All TXT files are in git - use meaningful commit messages
   - Test changes locally before committing
   - Document significant changes in commit descriptions

3. **Content Quality**
   - Be specific and actionable
   - Use active voice
   - Provide concrete examples
   - Explain why, not just what
   - Keep current with industry changes

4. **Testing**
   - After editing, regenerate affected playbooks
   - Test with actual assistant creation
   - Verify both base knowledge and playbook are attached
   - Check that AI responses reflect playbook guidance

5. **Performance**
   - Use "Sync Changed" for routine updates (fast)
   - Use "Force Regenerate All" only after major structural changes
   - Batch processing handles 191 professions efficiently

## Future Enhancements

Potential improvements:
- [ ] Admin UI for editing playbooks directly in WordPress
- [ ] Visual diff showing changes between versions
- [ ] Preview assembled playbook before saving
- [ ] Multilingual playbook support
- [ ] AI-powered tool recommendation refinement
- [ ] Export/import playbook bundles
- [ ] Playbook templates for new professions

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
