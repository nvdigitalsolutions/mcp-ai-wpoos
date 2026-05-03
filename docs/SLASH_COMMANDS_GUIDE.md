# Slash Commands Implementation Guide

**Status:** Phase 1 Complete  
**Version:** 1.2.0  
**Date:** February 3, 2026

---

## Overview

The NV oOS plugin now includes a comprehensive slash command system inspired by OpenClaw and modern AI assistant frameworks. This system provides users with powerful, user-friendly commands for content management, optimization, and quality assurance.

## Architecture

### Core Components

1. **Parser** (`class-wp-mcp-ai-slash-command-parser.php`)
   - Parses slash command syntax
   - Extracts command name, positional arguments, and flags
   - Supports long flags (`--flag=value`) and short flags (`-f value`)
   - Handles quoted strings with spaces

2. **Handler** (`class-wp-mcp-ai-slash-command-handler.php`)
   - Routes commands to appropriate handlers
   - Manages command registration
   - Enforces authorization and rate limiting
   - Logs all command executions

3. **Command Classes** (`commands/`)
   - Individual command implementations
   - Follow consistent patterns and interfaces
   - Return markdown-formatted output
   - Support dry-run mode

4. **Integration Layer**
   - JavaScript integration (`assets/js/slash-commands.js`)
   - Command autocomplete (`assets/js/command-autocomplete.js`)
   - REST API endpoint (`/wp-json/mcp-ai/v1/slash-command`)
   - WP-CLI support (`wp mcp-ai slash <command>`)

---

## Implemented Commands

### 1. `/help` - Command Discovery

**Purpose:** Display help information about available commands

**Usage:**
```bash
/help
/help [command]
/help --detailed
```

**Aliases:** `/h`, `/?`

**Capability Required:** `read`

**Examples:**
```bash
/help                  # Show all commands
/help ship            # Show help for /ship command
/help --detailed      # Show detailed help for all commands
```

---

### 2. `/next-task` - Autonomous Task Manager

**Purpose:** Complete task-to-production automation for WordPress content

**Usage:**
```bash
/next-task [--filter=<type>] [--type=<task-type>] [--limit=<number>] [--dry-run|-n] [--auto|-a]
```

**Aliases:** `/next`

**Capability Required:** `edit_posts`

**Workflow:**
1. **Task Discovery** - Scans site for:
   - Draft posts ready to publish
   - Posts missing meta descriptions
   - Outdated content needing updates (1+ year old)

2. **Context Analysis** - Gathers:
   - Site information (name, URL, theme)
   - Post statistics
   - Active SEO plugin (Yoast/Rank Math)

3. **Planning** - Creates execution plan with:
   - Task prioritization (80=drafts, 60=SEO, 40=updates)
   - Time estimates (low=5min, medium=15min, high=30min)
   - Required tools list

4. **User Approval** - Human-in-the-Loop checkpoint
   - Shows plan and awaits confirmation
   - Bypassed with `--auto` flag

5. **Implementation** - Executes planned actions
6. **Quality Check** - Validates results

**Flags:**
- `--filter=<type>` - Filter by: all, drafts, seo, updates (default: all)
- `--type=<task-type>` - Specific task type to focus on
- `--limit=<number>` - Max tasks to process (default: 5)
- `--dry-run`, `-n` - Show plan without executing
- `--auto`, `-a` - Execute without approval prompt

**Examples:**
```bash
/next-task --dry-run              # Preview available tasks
/next-task --type=drafts --limit=3  # Find 3 draft posts to publish
/next-task --auto                 # Execute tasks automatically
```

**Output Example:**
```markdown
## Discovered Tasks

### Tasks
- **Draft Post Title** (Priority: 80)
  Publish draft post: Draft Post Title

### Execution Plan
**Total Tasks:** 3
**Estimated Time:** 45 minutes

### Results
✅ Step 1: Success
✅ Step 2: Success
✅ Step 3: Success

### Quality Check
**Passed:** 3 / 3
**Failed:** 0
```

---

### 3. `/ship` - Content Publishing Workflow

**Purpose:** Automated content review, optimization, and publishing

**Usage:**
```bash
/ship [post_id...] [--dry-run|-n] [--publish|-p] [--schedule=<date>] [--skip-checks|-s] [--skip-seo] [--skip-images] [--skip-links]
```

**Capability Required:** `publish_posts`

**Workflow:**
1. **Pre-flight Checks** (30% weight)
   - Featured image present
   - Categories assigned
   - Excerpt exists
   - Word count ≥ 300

2. **SEO Verification** (30% weight)
   - Meta title (Yoast/Rank Math)
   - Meta description
   - Focus keyword (optional)

3. **Quality Review** (25% weight)
   - Heading structure (H2-H6)
   - Images in content
   - Sentence length (avg < 20 words)
   - Readability score

4. **Image Optimization** (10% weight)
   - Featured image alt text
   - Content image alt text

5. **Internal Linking** (5% weight)
   - Link count (recommend 2-3)
   - Related post suggestions

6. **Publishing**
   - Auto-publish if score ≥ 70% (with --publish flag)
   - Schedule for future date

**Readiness Scoring:**
- **80-100%** = ✅ Ready to publish
- **60-79%** = ⚠️ Needs review
- **0-59%** = ❌ Not ready

**Flags:**
- `--dry-run`, `-n` - Preview checks without publishing
- `--publish`, `-p` - Auto-publish posts scoring 70%+
- `--schedule=<date>` - Schedule for future (YYYY-MM-DD HH:MM)
- `--skip-checks`, `-s` - Skip all checks
- `--skip-seo` - Skip SEO verification
- `--skip-images` - Skip image checks
- `--skip-links` - Skip linking suggestions

**Examples:**
```bash
/ship --dry-run                     # Check draft posts
/ship 123 --dry-run                 # Check specific post
/ship 123 --publish                 # Publish if ready
/ship 123 --schedule="2026-02-10 09:00"  # Schedule publication
```

**Output Example:**
```markdown
## Processed 1 post(s) for shipping

### Post Title

**Status:** ✅ Ready

**Readiness Score:** 85%

**Issues to Address:**
- Missing internal links (recommend 2-3)

[View Post](https://example.com/post-url)

**Note:** This was a dry run. Use --publish to publish posts.
```

---

### 4. `/clean-content` - Content Quality Assurance

**Purpose:** 3-phase content quality detection with auto-fix capability

**Usage:**
```bash
/clean-content [post_id|recent|all] [--phase=<1-3>] [--limit=<number>] [--dry-run|-n] [--auto-fix|-a] [--post-type=<type>] [--verbose|-v]
```

**Aliases:** `/clean`

**Capability Required:** `edit_posts`

**Detection Phases:**

#### Phase 1: Regex Patterns (HIGH Certainty - Auto-fixable)
🔴 High confidence issues that can be safely fixed:
- Lorem ipsum placeholder text
- Draft markers ([TODO], [DRAFT], [TBD], [FIXME], [XXX])
- Broken/unclosed shortcodes
- Empty HTML tags
- Multiple consecutive spaces
- Default WordPress content

#### Phase 2: Content Analysis (MEDIUM Certainty - Reportable)
🟡 Issues requiring human judgment:
- Thin content (< 300 words)
- Poor readability (sentences > 30 words)
- Broken internal links
- Missing SEO meta descriptions
- Duplicate content (title/excerpt similarity > 80%)

#### Phase 3: AI Review (LOW Certainty - Suggestions)
🟢 Stylistic suggestions for improvement:
- Brand voice consistency (informal language)
- Engagement quality (questions in long content)
- Tone analysis (negative word frequency)

**Flags:**
- `[target]` - Post ID, "recent" (default), or "all"
- `--phase=<1-3>` - Run specific phase only (default: all)
- `--limit=<number>` - Max posts to check (default: 10)
- `--dry-run`, `-n` - Show issues without fixing
- `--auto-fix`, `-a` - Auto-fix HIGH certainty issues
- `--post-type=<type>` - Post type (default: post)
- `--verbose`, `-v` - Show detailed output

**Examples:**
```bash
/clean-content --dry-run              # Check recent 10 posts
/clean-content 123 --auto-fix         # Clean specific post
/clean-content all --phase=1 --auto-fix  # Fix HIGH certainty issues site-wide
/clean-content recent --limit=5 --verbose  # Detailed check of 5 posts
```

**Output Example:**
```markdown
## Checked 5 post(s), found 12 issue(s)

**Summary:**
- Posts checked: 5
- Posts cleaned: 2
- Total issues found: 12
- Issues fixed: 4

### Details

#### ✅ Clean Post Title
No issues found.
[View Post](https://example.com/post-1)

#### ⚠️ Post with Issues

**Auto-fixed:**
- ✓ Removed draft markers
- ✓ Normalized spacing

**🔴 High Certainty Issues:**
- Contains "Lorem ipsum" placeholder text (auto-fixable)

**🟡 Medium Certainty Issues:**
- Content is thin (187 words, minimum 300 recommended)
- Missing SEO meta description

**🟢 Low Certainty Suggestions:**
- Long content without questions may reduce engagement

[View Post](https://example.com/post-2)
```

---

## Integration Examples

### Using in Chat

Simply type slash commands in the chat interface:
```
/help
/next-task --dry-run
/ship 123 --publish
/clean-content recent --auto-fix
```

### Using via WP-CLI

Execute commands from the command line:
```bash
wp mcp-ai slash next-task --dry-run
wp mcp-ai slash ship 123 --publish
wp mcp-ai slash clean-content all --phase=1 --auto-fix
```

### Using via REST API

```javascript
fetch('/wp-json/mcp-ai/v1/slash-command', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    command: '/ship 123 --dry-run'
  })
});
```

---

## Security & Rate Limiting

### Authorization
- All commands require appropriate capabilities
- `/help` - `read` (all users)
- `/next-task`, `/clean-content` - `edit_posts` (contributors+)
- `/ship` - `publish_posts` (authors+)

### Rate Limiting
- Default: 10 commands per minute per user
- Tracked per command
- Prevents abuse

### Logging
All command executions are logged with:
- Command name and arguments
- User ID and timestamp
- Execution status (started, completed, failed)
- Result or error message

---

## Testing

### PHPUnit Test Suite

```bash
# Run all slash command tests
composer run test tests/test-slash-commands.php
composer run test tests/test-slash-command-next-task.php
composer run test tests/test-slash-command-ship.php
composer run test tests/test-slash-command-clean-content.php
```

### Test Coverage
- **Total Test Cases:** 45+
- **Parser Tests:** 12 tests
- **Next-Task Tests:** 13 tests
- **Ship Tests:** 14 tests
- **Clean-Content Tests:** 18 tests

**Coverage Areas:**
- Capability validation
- Flag parsing
- Dry-run mode
- Auto-fix functionality
- Error handling
- Output formatting
- Edge cases

---

## Extension & Customization

### Adding New Commands

1. Create command class in `includes/slash-commands/commands/`:

```php
class WP_MCP_AI_Slash_Command_My_Command {
    public function execute( $args, $flags, $context ) {
        // Implementation
        return $formatted_output;
    }
}
```

2. Register in `slash-commands-init.php`:

```php
require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-my-command.php';

$my_command = new WP_MCP_AI_Slash_Command_My_Command();
$wp_mcp_ai_slash_command_handler->register(
    'my-command',
    array(
        'handler'     => array( $my_command, 'execute' ),
        'description' => __( 'My command description', 'mcp-ai-wpoos' ),
        'usage'       => '/my-command [args] [--flags]',
        'capability'  => 'edit_posts',
    )
);
```

3. Add tests in `tests/test-slash-command-my-command.php`

### Custom Hooks

```php
// After commands initialized
add_action( 'wp_mcp_ai_slash_commands_initialized', function( $handler ) {
    // Register additional commands
} );

// After default commands loaded
add_action( 'wp_mcp_ai_default_slash_commands_loaded', function( $handler ) {
    // Extend default commands
} );

// Command execution logging
add_action( 'wp_mcp_ai_slash_command_logged', function( $log_entry ) {
    // Custom logging
} );
```

---

## Future Enhancements (Phase 2+)

### Planned Commands

1. **`/optimize-perf`** - Performance Analysis
   - Database query analysis
   - Plugin audit
   - Asset optimization
   - Cache strategy recommendations

2. **`/sync-docs`** - Documentation Synchronization
   - Code-documentation drift detection
   - Auto-update documentation
   - Version consistency checks

3. **`/audit-site`** - Comprehensive Site Audit
   - Security scan
   - Performance metrics
   - SEO analysis
   - Accessibility check

4. **`/workflow`** - Custom Workflow Execution
   - YAML workflow definitions
   - Multi-agent orchestration
   - Scheduled execution

---

## Troubleshooting

### Command Not Found
**Issue:** `/command` returns "Command not found"
**Solution:** Check command is registered and user has required capability

### Permission Denied
**Issue:** "Insufficient capability" error
**Solution:** User needs proper role (Editor for most commands, Author for /ship)

### Auto-fix Not Working
**Issue:** `--auto-fix` flag doesn't fix issues
**Solution:** 
- Remove `--dry-run` flag (it prevents changes)
- Only HIGH certainty issues are auto-fixed
- Check user has `edit_posts` capability

### Rate Limit Exceeded
**Issue:** "Rate limit exceeded" error
**Solution:** Wait 1 minute or contact admin to adjust rate limits

---

## Performance Considerations

### Bulk Operations
- Use `--limit` flag to control batch size
- For site-wide operations, run during off-peak hours
- Consider using WP-CLI for large batches

### Resource Usage
- `/next-task` - Moderate (database queries)
- `/ship` - Low-Moderate (mostly checks)
- `/clean-content` - High (content parsing, regex operations)

### Optimization Tips
1. Use `--phase=1` for quick HIGH certainty checks
2. Use `--dry-run` to preview before execution
3. Start with small `--limit` values for testing
4. Use `--skip-*` flags to skip unnecessary checks

---

## Support & Resources

- **Documentation:** `docs/integrations/openclaw/`
- **Proposals:** `docs/proposals/PRO_PLUGIN_ENHANCEMENT_SLASH_COMMANDS.md`
- **Issues:** GitHub Issues
- **Tests:** `tests/test-slash-command-*.php`

---

**Last Updated:** February 3, 2026  
**Status:** Phase 1 Complete  
**License:** GPLv3 or later
