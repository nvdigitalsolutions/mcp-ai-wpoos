# Profession Playbooks

This directory contains authorable playbooks for the WP oOS profession system. Playbooks are assembled from modular text files and automatically converted into memory file attachments for AI assistants.

## Directory Structure

```
profession-playbooks/
├── global.txt                    # Universal guidelines for all professions
├── categories/                   # Category-specific guidelines
│   ├── advisory.txt
│   ├── creative.txt
│   ├── technical.txt
│   ├── healthcare.txt
│   ├── legal.txt
│   ├── financial.txt
│   └── other.txt
└── professions/                  # Profession-specific guidelines
    ├── accountant.txt
    ├── software_engineer.txt
    └── ... (181 total)
```

## How It Works

### Assembly Process

When a profession playbook is generated, the system assembles content in this order:

1. **Header**: Profession title and timestamp
2. **Global Section**: Content from `global.txt`
3. **Category Section**: Content from `categories/{category}.txt`
4. **Profession Section**: Content from `professions/{slug}.txt`
5. **Tool Recommendations**: Intelligent tool mapping and usage guidance (NEW in 2025-12)
6. **Footer**: Generation info and instructions

Each section is separated by `---` dividers for clarity.

### Automatic Syncing

Playbooks are automatically generated:
- On first admin load after plugin activation (incremental, 20 professions per cycle)
- When using the "Reseed Professions" admin action
- Manually via `WP_MCP_AI_Profession_Playbook_Seeder::sync_all(true)`

### Storage

Generated playbooks are stored as:
- **File Location**: `uploads/wp-mcp-ai/profession-playbooks/profession-{ID}-{slug}-playbook.txt`
- **WordPress**: Attachment posts with meta `_wp_mcp_ai_playbook_profession_id` and `_wp_mcp_ai_playbook_hash`
- **Profession Link**: Added to profession's `_wp_mcp_ai_profession_memory_files` meta

## Editing Guidelines

### Global Guidelines (`global.txt`)

Use for:
- Universal professional conduct principles
- Safety disclaimers applicable to all professions
- General communication standards
- Core ethical boundaries
- Tool usage principles

Avoid:
- Profession-specific technical details
- Category-specific workflows
- Domain-specific terminology

### Category Guidelines (`categories/*.txt`)

Use for:
- Workflows common to the category (e.g., all technical professions)
- Quality rubrics specific to the domain
- Best practices shared across professions in the category
- Risk patterns common to the category

Example: `technical.txt` covers testing, security, documentation - relevant to all technical professions.

### Profession Guidelines (`professions/*.txt`)

Use for:
- Specific tools, software, or frameworks unique to this profession
- Specialized workflows and methodologies
- Industry-specific best practices
- Common challenges specific to this role
- When to escalate to specialists

Example: `software_engineer.txt` covers specific languages, version control, CI/CD, code review practices.

## Content Structure

Each txt file should follow this pattern:

```markdown
# Section Title

## Subsection

- Clear, actionable bullet points
- Use active voice
- Keep language professional yet accessible
- Provide concrete examples when helpful

## Another Subsection

Paragraphs of explanatory text when needed.

- More bullet points
- Specific guidance
```

## Best Practices

### Writing Style

1. **Be Specific**: "Use industry-standard design patterns" → "Use the Factory pattern for object creation"
2. **Be Actionable**: "Consider security" → "Validate all user input before processing"
3. **Be Contextual**: Explain why, not just what
4. **Be Current**: Keep up with industry changes (update txt files, then reseed)

### Organization

1. **Avoid Redundancy**: If something applies to all technical professions, put it in `categories/technical.txt`, not in each profession file
2. **Maintain Hierarchy**: Global → Category → Profession (most general to most specific)
3. **Keep It Focused**: Each file should be clear about its scope

### Maintenance

1. **Version Control**: All txt files are in git - track changes with meaningful commits
2. **Testing Changes**: After editing, use "Reseed Professions" to regenerate playbooks
3. **Review Impact**: Check a few generated playbook attachments to verify assembly
4. **Document Decisions**: Use commit messages to explain significant changes

## Technical Details

### Change Detection

The system uses SHA256 hashing to detect content changes:
- When content hasn't changed: Skips regeneration (idempotent)
- When content has changed: Overwrites the attachment file and updates hash

### Batch Processing

To handle 181 professions without timeouts:
- Processes 20 professions per admin_init cycle
- Tracks progress with `wp_mcp_ai_playbook_seed_offset` option
- Completes incrementally over multiple page loads
- Sets `wp_mcp_ai_playbooks_seeded = true` when finished

### File Encoding

All txt files must be UTF-8 encoded. The loader validates encoding during assembly.

## API Usage

### For Developers

```php
// Load a playbook
$loader = new WP_MCP_AI_Profession_Playbook_Loader();
$playbook = $loader->build_playbook( $profession_id );

// Sync all playbooks (force regeneration)
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );

// Sync without forcing (only updates changed content)
WP_MCP_AI_Profession_Playbook_Seeder::sync_all( false );
```

### AJAX Integration

The reseed professions AJAX action automatically:
1. Reseeds professions from JSON
2. Updates base knowledge documents
3. **Syncs playbooks from txt files** ← Added in this system

## Tool Recommendations System (NEW)

As of December 2025, playbooks now include intelligent tool recommendations:

### Features
- **Automatic Tool Mapping**: 100+ tools intelligently mapped to professions
- **Three-Tier System**: Core tools → Category tools → Profession-specific tools
- **Contextual Guidance**: Profession-specific usage advice for each tool
- **Availability Filtering**: Respects base vs. full version configurations
- **Organized by Category**: Tools grouped into Core, Media, Admin, etc.

### Example Output
```markdown
## Recommended Tools & How to Use Them

This profession has access to 15 recommended tools...

### Core Tools
**web_search** - Search the web for current information...
**save_post** - Create new posts or update existing content...

### System Administration
**check_site_security** - Essential for security audits...

### Tool Usage Best Practices
1. Verify permissions...
```

### Documentation
- **Full Guide**: `docs/PROFESSION_TOOL_RECOMMENDATIONS.md`
- **Quick Reference**: `docs/QUICK_GUIDE_TOOL_MAPPINGS.md`
- **Tool Catalog**: `docs/tool-reference.md`

### Customization
Edit `includes/services/class-wp-mcp-ai-profession-tool-recommender.php` to:
- Add tools to specific professions
- Add tools to entire categories
- Provide custom tool guidance
- Create new tool categories

## Future Enhancements

Potential improvements:
- [ ] Admin UI for editing playbooks directly in WordPress
- [ ] Visual tool selector for profession configuration
- [ ] Preview playbook before saving
- [ ] Diff view showing changes between versions
- [ ] Export/import playbook bundles
- [ ] Multilingual playbook support
- [ ] AI-powered tool recommendation refinement

## Troubleshooting

### Playbooks not updating after editing txt file
1. Go to WP Admin → Settings → WP oOS → Advanced
2. Click "Reseed Professions"
3. Choose "Update" (not "Replace")
4. Wait for completion message

### Missing profession playbook
- Check if profession exists in profession CPT
- Verify profession has a valid slug matching a txt filename
- Check `_wp_mcp_ai_profession_memory_files` meta on profession post
- Look for attachment with meta `_wp_mcp_ai_playbook_profession_id`

### Timeout during seeding
- This shouldn't happen due to incremental processing
- If it does, increase `max_execution_time` PHP setting
- Or reduce `BATCH_SIZE` constant in seeder class (currently 20)

## Contributing

When contributing playbook content:
1. Edit the appropriate txt file(s)
2. Test locally with "Reseed Professions"
3. Commit with descriptive message: "docs: Enhance software_engineer.txt with CI/CD guidance"
4. Create PR with explanation of changes and why they improve the playbook

---

For technical implementation details, see:
- `includes/services/class-wp-mcp-ai-profession-playbook-loader.php`
- `includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php`
- `tests/test-profession-playbook-loader.php`
- `tests/test-profession-playbook-seeder.php`
