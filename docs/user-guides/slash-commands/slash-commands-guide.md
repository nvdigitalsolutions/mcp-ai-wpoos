# Slash Commands User Guide

## Overview
Slash commands provide a powerful way to execute special functions directly from the chat interface. Commands start with a forward slash (`/`) and can be used to perform various actions like getting help, managing content, running workflows, and more.

## Using Slash Commands

### In the Chat Interface
1. Type a slash (`/`) in the chat input field
2. Continue typing the command name (e.g., `/help`)
3. Autocomplete suggestions will appear (if available)
4. Press Enter to execute the command

### Syntax
```
/command [arguments] [--flags]
```

- **command**: The command name (required)
- **arguments**: Positional parameters (optional)
- **flags**: Named options starting with `--` (optional)

## Available Commands

### /help
Display information about available commands.

**Usage:**
- `/help` - Show all available commands
- `/help [command]` - Show detailed help for a specific command
- `/help --detailed` or `/help -d` - Show detailed information for all commands

**Examples:**
```
/help
/help ship
/help --detailed
```

### /next-task
Discover and execute the next recommended task for your WordPress site.

**Usage:**
```
/next-task [--filter=<type>] [--limit=<number>] [--dry-run] [--auto]
```

**Options:**
- `--filter` - Filter by task type (all, drafts, seo, updates)
- `--limit` - Maximum tasks to process (default: 5)
- `--dry-run` or `-n` - Preview without executing
- `--auto` or `-a` - Execute without confirmation

**Examples:**
```
/next-task
/next-task --filter=seo --limit=3
/next-task --dry-run
```

### /ship
Review, optimize, and publish content with automated quality checks.

**Usage:**
```
/ship [post_id...] [--publish] [--schedule=<date>] [--dry-run]
```

**Options:**
- `post_id` - One or more post IDs (finds drafts if omitted)
- `--publish` or `-p` - Auto-publish posts scoring 70%+
- `--schedule` - Schedule for future date (YYYY-MM-DD HH:MM)
- `--dry-run` or `-n` - Preview checks without publishing
- `--skip-checks` or `-s` - Skip all quality checks
- `--skip-seo` - Skip SEO verification
- `--skip-images` - Skip image optimization
- `--skip-links` - Skip internal linking

**Examples:**
```
/ship
/ship 123 --dry-run
/ship 456 789 --publish
/ship 123 --schedule="2024-12-25 09:00"
```

### /clean-content
Detect and fix content quality issues with 3-phase analysis.

**Usage:**
```
/clean-content [target] [--phase=<1-3>] [--auto-fix] [--dry-run]
```

**Options:**
- `target` - Post ID, "recent", or "all" (default: recent)
- `--phase` - Run specific phase: 1 (regex), 2 (analysis), 3 (AI)
- `--limit` - Max posts to check (default: 10)
- `--auto-fix` or `-a` - Fix high-certainty issues automatically
- `--post-type` - Post type to check (default: post)
- `--dry-run` or `-n` - Show issues without fixing
- `--verbose` or `-v` - Show detailed output

**Examples:**
```
/clean-content
/clean-content recent --auto-fix
/clean-content 123 --phase=2
/clean-content all --limit=20 --post-type=page
```

### /optimize-perf
Analyze and optimize WordPress site performance.

**Usage:**
```
/optimize-perf [--phases=<1-10>] [--url=<url>] [--auto-apply]
```

**Options:**
- `--phases` - Comma-separated phase numbers (1-10, default: all)
- `--url` - URL to analyze (default: home page)
- `--auto-apply` or `-a` - Apply safe optimizations automatically
- `--dry-run` or `-n` - Analyze without applying changes
- `--detailed` or `-v` - Show detailed analysis

**Examples:**
```
/optimize-perf
/optimize-perf --phases=1,2,3
/optimize-perf --url=https://example.com/contact --auto-apply
```

### /sync-docs
Check documentation for drift and synchronize with codebase.

**Usage:**
```
/sync-docs [--type=<all|posts|pages|readme>] [--auto-fix] [--dry-run]
```

**Options:**
- `--type` - Documentation type (all, posts, pages, readme)
- `--auto-fix` or `-a` - Fix detected issues automatically
- `--dry-run` or `-n` - Check without making changes
- `--skip-links` - Skip broken link checking
- `--skip-code` - Skip code example validation

**Examples:**
```
/sync-docs
/sync-docs --type=posts --auto-fix
/sync-docs --dry-run --skip-links
```

### /workflow
Execute custom automation workflows.

**Usage:**
```
/workflow [name] [--action=<run|list|show>] [--dry-run]
```

**Options:**
- `name` - Workflow name to execute
- `--action` - Action: run, list, show (default: run)
- `--list` or `-l` - List available workflows
- `--show` or `-s` - Show workflow definition
- `--dry-run` or `-n` - Preview without executing

**Examples:**
```
/workflow --list
/workflow content-review
/workflow backup --dry-run
/workflow custom-flow --show
```

## Tips and Best Practices

### 1. Use Help First
When unsure about a command, always start with `/help [command]` to see usage and options.

### 2. Test with --dry-run
For commands that make changes, use `--dry-run` first to preview the effects.

### 3. Start Small
Use `--limit` flags to test commands on a small set before running on all content.

### 4. Combine Flags
Most commands support multiple flags:
```
/clean-content recent --auto-fix --verbose --limit=5
```

### 5. Check Permissions
Some commands require specific user capabilities. If a command fails, check with your administrator.

### 6. Use Autocomplete
Start typing `/` to see autocomplete suggestions for available commands.

## Troubleshooting

### Commands Not Working
1. **Verify scripts are loaded**: Check browser console for JavaScript errors
2. **Check authentication**: Ensure you're logged in with appropriate permissions
3. **Try a simple command**: Test with `/help` first
4. **Check REST API**: Verify `/wp-json/mcp-ai/v1/slash-command` endpoint is accessible

### Autocomplete Not Showing
1. Check if CommandAutocomplete script is loaded
2. Verify the chat input has correct class names
3. Clear browser cache and reload

### Permission Errors
- Each command has minimum required capabilities
- Contact your administrator to adjust user roles
- Some commands require `edit_posts` or `manage_options`

## Technical Details

### Script Loading
Slash commands are automatically loaded when the chat interface is rendered:
- Scripts: `command-autocomplete.js` and `slash-commands.js`
- Localized data: REST URL and nonce for authentication
- Compatible with all AI providers (OpenAI, Gemini, Ollama)

### REST API
Commands execute via REST API endpoints:
- Execution: `POST /wp-json/mcp-ai/v1/slash-command`
- List: `GET /wp-json/mcp-ai/v1/slash-command/list`

### Authentication
Commands use WordPress nonce authentication by default. Bearer tokens are supported for programmatic access.

## Developer Resources

### Adding Custom Commands
Developers can register custom slash commands:

```php
add_action( 'wp_mcp_ai_default_slash_commands_loaded', function( $handler ) {
    $handler->register( 'my-command', array(
        'handler'     => function( $args, $flags, $context ) {
            return 'Command result';
        },
        'description' => 'My custom command',
        'usage'       => '/my-command [options]',
        'capability'  => 'edit_posts',
    ));
});
```

### Testing
Use the manual test file at `tests/manual/test-slash-commands-integration.html` to verify integration in a standalone environment.

## Support

For issues or questions:
1. Check documentation at `/docs/`
2. Review troubleshooting section above
3. Check GitHub issues
4. Contact support with error details from browser console
