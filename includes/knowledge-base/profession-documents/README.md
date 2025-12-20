# Profession Base Knowledge Documents

This directory contains **reference material documents** that populate the Knowledge Base Content field for each profession in the WP oOS system. These documents provide foundational knowledge that AI assistants use to understand their professional role.

## Purpose

Profession documents serve as **comprehensive reference material** that defines:
- What the profession is about
- Core areas of expertise
- Key knowledge domains
- Professional standards and principles
- Warnings and disclaimers
- Recommended default tools

This content is **informational and descriptive** in nature, answering "What does this profession do?" and "What should the AI know about this field?"

## Relationship with Profession Playbooks

The WP oOS profession system uses **two complementary knowledge systems**:

### 1. profession-documents/ (THIS DIRECTORY) - "WHAT"
- **Content Type**: Reference material and foundational knowledge
- **Purpose**: Define what the profession is about
- **Tone**: Informational, descriptive
- **Storage**: Populates `META_KNOWLEDGE_BASE` post meta field
- **Integration**: Embedded directly in assistant system prompt as text
- **Examples**: 
  - "Accountants follow GAAP or IFRS standards"
  - "Core expertise includes financial reporting, bookkeeping..."
  - "Software engineers design, develop, and maintain applications"

### 2. profession-playbooks/ (SIBLING DIRECTORY) - "HOW"
- **Content Type**: Actionable instructions, SOPs, checklists, templates
- **Purpose**: Define how to perform professional work
- **Tone**: Directive, action-oriented
- **Storage**: Assembled into attachment files stored in uploads
- **Integration**: Attached as memory files to assistants
- **Examples**:
  - "DO: Provide general accounting guidance"
  - "DO NOT: Prepare official tax returns"
  - "Ask these intake questions: What is your region?..."
  - "Use this template for financial statements..."

### Visual Distinction

```
profession-documents (Reference)     profession-playbooks (Instructions)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
"Software engineers work with..."   "1) Role boundaries
                                      DO: Design software solutions
"Core expertise includes:            DO NOT: Deploy to production
 - Software architecture               without approval
 - Programming languages             
 - Algorithm design"                  2) Quick intake questions
                                      - What region?
"Follow SOLID principles and         - What tech stack?
 design patterns"                     - What are the requirements?

                                     3) Default workflow
                                      1. Requirements Analysis
                                      2. Design & Planning
                                      3. Implementation..."
```

## Directory Structure

```
profession-documents/
├── README.md (this file)
├── accountant.txt
├── software_engineer.txt
├── registered_nurse.txt
└── ... (191 total profession documents)
```

**File Naming Convention**: Files must match profession slugs exactly (e.g., `software_engineer.txt` for the software_engineer profession).

## Document Format

Each profession document follows this standardized structure:

```
[Profession Title] — Profession Knowledge Pack

Slug: [profession_slug]
Category: [advisory|creative|technical|healthcare|legal|financial|other]

Overview
--------
[Brief description of what this profession does]

Role Description
---------------
[How the AI should describe its role when assisting]

Core Expertise
-------------
- [Expertise area 1]
- [Expertise area 2]
- [Expertise area 3]
...

Knowledge Base Notes
-------------------
### [Topic 1]
- [Key point about this topic]
- [Best practice or principle]
- [Important standard or framework]

### [Topic 2]
[Detailed knowledge content...]

Warnings & Disclaimers
---------------------
- [Important limitation or boundary]
- [When to escalate to licensed professional]
- [Compliance or legal considerations]

Default Tools
-------------
- [tool_slug_1]
- [tool_slug_2]
- [tool_slug_3]

Suggested Allowed File Types (MIME)
-------------------------------
- [MIME type 1]
- [MIME type 2]
```

### Example: accountant.txt

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

Warnings & Disclaimers
---------------------
- Complex accounting matters should be reviewed by a certified accountant
- Tax regulations vary by jurisdiction

Default Tools
-------------
- web_search
- search_content
- save_post
- get_quickbooks_report

Suggested Allowed File Types (MIME)
-------------------------------
- application/msword
- application/pdf
- application/vnd.openxmlformats-officedocument.wordprocessingml.document
- text/plain
```

## How It Works

### Seeding Process

1. **Initial Seeding** (Plugin Activation)
   - `WP_MCP_AI_Profession_Seeder` creates profession CPT posts from JSON files
   - `WP_MCP_AI_Profession_Base_Knowledge_Seeder` runs automatically afterward
   - For each profession, looks for `profession-documents/{slug}.txt`
   - If found, reads the entire file content
   - **Populates the profession's `META_KNOWLEDGE_BASE` field** with this content

2. **In WordPress Admin**
   - Navigate to profession edit screen
   - See "Expertise & Knowledge" metabox
   - "Knowledge Base Content" field (rich editor) contains this content
   - Admins can edit directly in WordPress

3. **When Creating Assistants**
   - `WP_MCP_AI_Assistant_CPT::build_system_prompt_from_primary_roles()` reads profession data
   - **Knowledge Base content is embedded directly in the system prompt**
   - AI receives this as core foundational knowledge
   - Combined with playbook attachment files for comprehensive guidance

### Change Detection

The system is **idempotent** and **non-destructive**:
- If `META_KNOWLEDGE_BASE` already has content, it won't be overwritten (unless forced)
- Edits made in WordPress admin are preserved
- To update from files, use "Reseed Professions" action in admin settings

## Editing Workflows

### Option 1: Via WordPress Admin (Minor Edits)

**Recommended for**: Small updates, quick fixes, content refinements

1. Go to WordPress Admin → Professions
2. Click on profession to edit
3. Find "Expertise & Knowledge" metabox
4. Edit "Knowledge Base Content" field (rich editor)
5. Click "Update" to save

**Pros**: Quick, no file access needed, WYSIWYG editor
**Cons**: Changes not version controlled, doesn't update source TXT file

### Option 2: Via TXT Files (Major Updates)

**Recommended for**: Major content updates, maintaining version control

1. Navigate to `includes/knowledge-base/profession-documents/`
2. Edit `{slug}.txt` file with your preferred text editor
3. Commit changes to version control
4. Go to WP Admin → Settings → WP oOS → Advanced
5. Click "Update Professions" button
6. System automatically calls `seed_base_knowledge(true)` to sync changes

**Pros**: Version controlled, batch updates possible, reproducible
**Cons**: Requires file system access, overwrites WordPress admin edits

### Best Practice Workflow

For production systems:
1. **Maintain source of truth in TXT files** (version controlled)
2. **Test changes locally** before deploying
3. **Document significant changes** in commit messages
4. **Use "Update Professions"** to sync from files to database
5. **Avoid mixing editing methods** (pick one approach per environment)

## Content Guidelines

### What to Include

✅ **DO Include:**
- Core professional knowledge and principles
- Industry standards and frameworks (with versions when relevant)
- Essential terminology and concepts
- Professional boundaries and limitations
- Common knowledge domains
- Foundational best practices
- Disclaimers about licensing/certification requirements
- Tools that are commonly used in this profession

✅ **Examples:**
- "Accountants follow GAAP (US) or IFRS (international) standards"
- "Registered nurses must maintain licensure and follow scope of practice"
- "Software engineers use version control systems like Git"

### What NOT to Include

❌ **DON'T Include:**
- Specific step-by-step instructions (→ put in playbooks)
- Detailed workflows and SOPs (→ put in playbooks)
- Intake questions (→ put in playbooks)
- Templates and checklists (→ put in playbooks)
- Role boundaries (DO/DON'T lists) (→ put in playbooks)
- Regional workflow variations (→ put in playbooks)
- Quality rubrics (→ put in playbooks)

❌ **Bad Examples:**
- "Ask the user: What is your region?" (instruction → playbook)
- "DO NOT provide tax advice" (role boundary → playbook)
- "Use this template: [template]" (template → playbook)
- "Step 1: Gather requirements" (workflow → playbook)

### Writing Style

- **Be Clear and Concise**: Use straightforward language
- **Be Factual**: Stick to established knowledge and standards
- **Be Professional**: Maintain appropriate tone for the profession
- **Be Current**: Keep content up-to-date with industry standards
- **Be Comprehensive**: Cover all major knowledge domains
- **Avoid Redundancy**: Don't repeat what's in JSON metadata or playbooks

### Content Organization

Organize Knowledge Base Notes by major topic areas:

```
Knowledge Base Notes
-------------------
### [Primary Domain 1]
- Key principles
- Standards to follow
- Important frameworks

### [Primary Domain 2]
- Core concepts
- Best practices
- Common approaches

### [Professional Standards]
- Licensing requirements
- Industry regulations
- Ethical guidelines
```

## Integration with Profession Playbooks

These two systems work together to provide comprehensive professional guidance:

### Profession Documents (Reference)
- **Provides**: Foundational knowledge about the profession
- **Used**: As embedded text in system prompt
- **Updated**: Via WordPress admin OR file sync
- **Example**: "Financial reporting standards include GAAP and IFRS..."

### Profession Playbooks (Instructions)
- **Provides**: Actionable guidance on how to perform work
- **Used**: As attached memory files
- **Updated**: Edit TXT files → sync playbooks
- **Example**: "DO: Explain GAAP standards. DO NOT: Prepare tax returns..."

### Data Flow

```
profession-documents/{slug}.txt
    ↓
[Base Knowledge Seeder]
    ↓
META_KNOWLEDGE_BASE field (post meta)
    ↓
[Assistant Creation]
    ↓
Embedded in System Prompt (direct text)

profession-playbooks/ (global + category + profession)
    ↓
[Playbook Seeder]
    ↓
Attachment file in uploads/
    ↓
META_MEMORY_FILES array
    ↓
[Assistant Creation]
    ↓
Attached as Memory File
```

### When Editing Content, Choose the Right File

| Content Type | Goes In | Example |
|--------------|---------|---------|
| Professional knowledge | `profession-documents/` | "GAAP principles include..." |
| Industry standards | `profession-documents/` | "Follow IBC building codes" |
| Core expertise | `profession-documents/` | "Financial analysis techniques" |
| Role boundaries | `profession-playbooks/` | "DO: Provide guidance DO NOT: Give tax advice" |
| Intake questions | `profession-playbooks/` | "What is your region?" |
| Workflows | `profession-playbooks/` | "1. Discovery 2. Planning 3. Implementation" |
| Templates | `profession-playbooks/` | "=== Budget Template ===" |
| Quality checklists | `profession-playbooks/` | "- [ ] All tests pass" |

## API Usage

### For Developers

```php
// Load base knowledge seeder
require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-base-knowledge-seeder.php';

// Seed all professions (idempotent - won't overwrite existing content)
WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge();

// Force refresh all base knowledge from TXT files
WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );

// Get profession's knowledge base content
$profession_id = 123;
$knowledge_base = get_post_meta( 
    $profession_id, 
    WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, 
    true 
);
```

## File Encoding

All TXT files **must be UTF-8 encoded** without BOM (Byte Order Mark).

To verify encoding:
```bash
file -i profession-documents/accountant.txt
# Should output: text/plain; charset=utf-8
```

## Troubleshooting

### Knowledge base content not updating

**Symptom**: Edited TXT file but content not reflected in WordPress

**Solution**:
1. Go to WP Admin → Settings → WP oOS → Advanced
2. Click "Update Professions" button
3. This triggers force refresh of base knowledge from files
4. Verify in profession edit screen

### Missing content for new profession

**Symptom**: New profession has no knowledge base content

**Possible causes**:
1. No matching TXT file in profession-documents/
2. Filename doesn't match profession slug exactly
3. TXT file is empty or has encoding issues

**Solution**:
1. Create `profession-documents/{slug}.txt` file
2. Follow the document format structure
3. Ensure UTF-8 encoding
4. Run "Update Professions" to sync

### Content overwritten after editing in WordPress

**Symptom**: Made changes in WordPress admin, then they disappeared

**Cause**: "Update Professions" action overwrites admin edits with TXT file content

**Solution**:
1. **Prevention**: Choose one editing method (admin OR files), don't mix
2. **Recovery**: Edit the TXT file to include your changes
3. **Best Practice**: Keep TXT files as source of truth

### Special characters not displaying correctly

**Symptom**: Accents, symbols, or non-ASCII characters display as gibberish

**Cause**: File not saved in UTF-8 encoding

**Solution**:
1. Re-save file with UTF-8 encoding (no BOM)
2. In most editors: File → Save As → Encoding: UTF-8
3. Re-run "Update Professions" to sync

## Contributing

When contributing profession document content:

1. **Research thoroughly**: Ensure information is accurate and current
2. **Follow format**: Use the standardized document structure
3. **Be comprehensive**: Cover all major knowledge domains
4. **Verify encoding**: Always use UTF-8 without BOM
5. **Test locally**: Sync and verify content appears correctly
6. **Write clear commits**: Describe what knowledge was added/updated
7. **Reference sources**: Cite standards, frameworks, regulations when applicable

### Commit Message Examples

Good commit messages:
- `docs: Update accountant.txt with IFRS 18 revenue standard`
- `docs: Add cybersecurity frameworks to security_specialist.txt`
- `docs: Enhance software_engineer.txt with design patterns`

## Related Documentation

- **Profession Playbooks System**: `../profession-playbooks/README.md`
- **Complete Knowledge Base System Guide**: `../../docs/PROFESSION_KNOWLEDGE_BASE_SYSTEM.md`
- **Profession Reseeding Guide**: `../../docs/profession-reseeding.md`
- **Tool Recommendations**: `../../docs/PROFESSION_TOOL_RECOMMENDATIONS.md`

## Testing

Test base knowledge seeding:

```bash
# Run PHPUnit tests
vendor/bin/phpunit tests/test-profession-base-knowledge-seeder.php
```

Test coverage includes:
- Reading TXT files and populating META_KNOWLEDGE_BASE
- Idempotency (no overwrites without force)
- MIME type assignment by category
- Force mode refresh
- File encoding validation

## File Statistics

- **Total Files**: 191 profession documents
- **Format**: Plain text (.txt)
- **Encoding**: UTF-8
- **Average Size**: ~1-2 KB per file
- **Total Storage**: ~200-400 KB

## Future Enhancements

Potential improvements:
- [ ] Admin UI for editing base knowledge inline
- [ ] Visual diff view showing changes between versions
- [ ] Validation checks for required sections
- [ ] AI-powered content suggestions
- [ ] Multi-language support
- [ ] Export/import bundles
- [ ] Content versioning and rollback
- [ ] Automated industry standard updates

---

**Last Updated**: December 2024
**Maintained By**: WP oOS Development Team
**Questions?** See `../../docs/PROFESSION_KNOWLEDGE_BASE_SYSTEM.md` or open an issue.
