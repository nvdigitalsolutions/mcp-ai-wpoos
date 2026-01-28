# Architect Agent Setup Guide

## Overview

The **Architect Agent** is a specialized AI assistant with self-editing capabilities, allowing it to read, modify, and improve the plugin's own code. This guide explains how to safely configure and use the Architect Agent with the `manage_files` tool.

## ⚠️ Important Security Considerations

The `manage_files` tool provides powerful capabilities that must be used responsibly:

- **Requires `edit_plugins` capability**: Only administrators with plugin editing permissions can use this tool
- **Restricted to plugin directory**: All file operations are confined to the plugin's directory (WP_MCP_AI_PATH)
- **Directory traversal prevention**: Path validation prevents access to files outside the plugin
- **Audit logging**: All write operations are logged for security auditing
- **Version control recommended**: Use Git or similar to track and revert changes

### Who Should Use This?

The Architect Agent is designed for:
- Plugin developers working on self-improvement features
- Advanced administrators implementing automated maintenance
- Development teams testing AI-assisted code generation
- Research projects exploring self-modifying systems

**Do NOT use in production** environments without proper testing and backup procedures.

## Prerequisites

1. **User Permissions**: You must have the `edit_plugins` capability (typically Super Admin or Administrator)
2. **Pro Version**: The `manage_files` tool is a Pro feature
3. **Version Control**: Strongly recommended to use Git for tracking changes
4. **Backup Strategy**: Maintain regular backups of your plugin files

## Creating an Architect Agent

### Step 1: Create the Assistant

1. Navigate to **MCP AI → Assistants** in WordPress admin
2. Click **Add New**
3. Configure the assistant:
   - **Name**: "Architect Agent" (or your preferred name)
   - **Description**: "Self-editing agent for plugin maintenance and improvements"
   - **AI Model**: Select a capable model (e.g., `gpt-4`, `claude-3-opus`, `gemini-pro`)
   - **Instructions**: Use the system prompt below

### Step 2: System Prompt

```
You are an Architect Agent - a specialized AI assistant with the ability to read, analyze, and modify the WordPress plugin code you are running within.

Your capabilities:
1. Read plugin files to understand current implementation
2. List directory contents to explore the codebase structure
3. Write new files or update existing files to implement improvements

Your responsibilities:
1. Maintain code quality and follow WordPress coding standards
2. Add proper PHPDoc documentation for all changes
3. Ensure backward compatibility unless explicitly instructed otherwise
4. Test changes mentally before implementing (consider edge cases)
5. Log all significant changes with clear explanations
6. Follow security best practices (sanitize input, escape output, validate data)

Your constraints:
1. Only modify files within the plugin directory (WP_MCP_AI_PATH)
2. Never modify critical files without explicit user approval (.htaccess, wp-config.php, etc.)
3. Always explain what you're about to change and why
4. If uncertain, ask for clarification rather than making assumptions

When using the manage_files tool:
- Use action="list" to explore directory structure
- Use action="read" to examine existing code
- Use action="write" only after careful analysis and user confirmation
- Provide relative paths from the plugin root (e.g., "includes/tools/new-tool.php")
```

### Step 3: Enable the Tool

1. In the assistant editor, scroll to **Available Tools**
2. Find and enable `manage_files`
3. Review the tool description to understand its parameters
4. Save the assistant

## Using the Architect Agent

### Example Workflows

#### 1. Exploring the Codebase

```
User: Show me the structure of the includes/tools directory

Agent: [Uses manage_files with action="list", path="includes/tools"]
```

#### 2. Reading Existing Code

```
User: Read the manage_files tool implementation

Agent: [Uses manage_files with action="read", path="includes/tools/class-wp-mcp-ai-tool-manage-files.php"]
```

#### 3. Creating a New File

```
User: Create a new tool for generating documentation

Agent: I'll create a new documentation generator tool. Let me write the file...
[Uses manage_files with action="write", path="includes/tools/class-wp-mcp-ai-tool-generate-docs.php", content="<?php..."]
```

#### 4. Modifying Existing Code

```
User: Add input validation to the search_content tool

Agent: I'll first read the current implementation, then suggest improvements...
[Reads file, analyzes, proposes changes, then writes updates with user approval]
```

### Best Practices

1. **Start Small**: Begin with read and list operations to understand the codebase
2. **Request Explanations**: Ask the agent to explain what it's doing before making changes
3. **Review Changes**: Examine the code diff before deploying to production
4. **Test Thoroughly**: Test all changes in a development environment first
5. **Use Version Control**: Commit changes to Git after each successful modification
6. **Regular Backups**: Maintain automated backups of your plugin directory

### Safety Guidelines

```bash
# Before starting, create a backup
cp -r wp-content/plugins/mcp-ai-wpoos wp-content/plugins/mcp-ai-wpoos.backup

# Initialize git if not already done
cd wp-content/plugins/mcp-ai-wpoos
git init
git add .
git commit -m "Baseline before Architect Agent modifications"
```

## Tool Parameters

### `manage_files` Tool Schema

```json
{
  "action": "read|write|list",  // Required
  "path": "relative/path/to/file",  // Required
  "content": "file contents",  // Required for action="write"
  "create_dirs": true  // Optional, default true
}
```

### Action Types

| Action | Purpose | Parameters | Returns |
|--------|---------|------------|---------|
| `read` | Read file contents | `path` (file) | File content as string |
| `write` | Create/update file | `path` (file), `content`, `create_dirs` | Success status, bytes written |
| `list` | List directory contents | `path` (directory) | Arrays of files and subdirectories |

### Path Examples

✅ **Valid paths** (relative to plugin root):
- `"includes/tools/class-new-tool.php"`
- `"docs/guides/new-guide.md"`
- `"assets/js/custom-script.js"`
- `"tests/test-new-feature.php"`

❌ **Invalid paths** (security violations):
- `"../../wp-config.php"` (directory traversal)
- `"/etc/passwd"` (absolute path outside plugin)
- `"../other-plugin/file.php"` (outside plugin directory)

## Troubleshooting

### Permission Denied

**Error**: "You do not have permission to use the Manage Files tool"

**Solution**: Ensure your WordPress user has the `edit_plugins` capability. Check with:
```php
current_user_can( 'edit_plugins' )
```

### Path Outside Plugin

**Error**: "Access denied. Path must be within the plugin directory"

**Solution**: Use relative paths from the plugin root. Remove any `..` or absolute paths.

### File Not Found

**Error**: "File not found: includes/tools/missing.php"

**Solution**: 
- Use `action="list"` to verify the file exists
- Check spelling and capitalization
- Ensure the path is relative to plugin root

### Write Failed

**Error**: "Unable to write file contents"

**Solution**:
- Check file permissions (should be writable by web server)
- Ensure parent directory exists (or use `create_dirs: true`)
- Verify sufficient disk space

## Advanced Configuration

### Custom System Prompts

You can customize the Architect Agent's behavior by modifying its system prompt:

```
# For a more conservative agent:
"You are a careful code reviewer. Always ask for explicit permission before modifying any file."

# For a documentation-focused agent:
"You specialize in generating comprehensive documentation. Focus on creating clear, helpful docs for all code."

# For a testing-focused agent:
"You create thorough PHPUnit tests for all functionality. Prioritize test coverage and edge cases."
```

### Limiting Scope

To restrict the agent to specific directories:

```
"You are restricted to working only within the `includes/tools` directory. 
Do not modify files in other locations."
```

### Integration with Workflows

The Architect Agent can be integrated into automated workflows:

1. **Code Review**: Agent reviews pull requests and suggests improvements
2. **Documentation Generation**: Automatically creates docs for new code
3. **Test Generation**: Generates PHPUnit tests for new tools
4. **Refactoring**: Improves code quality across the codebase
5. **Migration Assistance**: Helps upgrade deprecated WordPress APIs

## Monitoring and Auditing

### Viewing Activity Logs

All file write operations are logged. To view:

```php
// Via admin interface
Settings → NV oOS → Recent Activity

// Via WP-CLI
wp option get wp_mcp_ai_recent_activity --format=json

// Via code
$activity = get_option( 'wp_mcp_ai_recent_activity', array() );
```

### Log Format

```php
array(
  'timestamp' => '2026-01-28 17:30:00',
  'message' => 'Architect Agent wrote file: includes/tools/new-tool.php (User: 1, Assistant: 42)',
  'level' => 'info',
)
```

## Security Hardening

### Additional Restrictions

For production environments, consider these additional safeguards:

```php
// In wp-config.php - Disable file modifications entirely
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );

// Or use a filter to restrict specific paths
add_filter( 'wp_mcp_ai_manage_files_allowed_paths', function( $allowed_paths ) {
    // Only allow modifications in a specific subdirectory
    return array( 'includes/custom' );
} );
```

### Capability Management

```php
// Remove edit_plugins from specific users
$user = get_user_by( 'id', 42 );
$user->remove_cap( 'edit_plugins' );

// Create a custom capability for Architect Agent
add_filter( 'user_has_cap', function( $allcaps, $caps, $args, $user ) {
    if ( isset( $allcaps['architect_agent'] ) && $allcaps['architect_agent'] ) {
        $allcaps['edit_plugins'] = true;
    }
    return $allcaps;
}, 10, 4 );
```

## FAQ

**Q: Is this safe to use in production?**  
A: Only with proper precautions: backups, version control, thorough testing, and limited user access.

**Q: Can the agent delete files?**  
A: No. The current implementation only supports read, write, and list operations. Deletion is not supported for safety.

**Q: What happens if the agent writes invalid code?**  
A: WordPress may show PHP errors. Use version control to revert changes. The plugin includes error handling to minimize crashes.

**Q: Can multiple agents use this tool simultaneously?**  
A: Yes, but this can lead to conflicts. Use WordPress file locking or implement your own concurrency control.

**Q: Does this work in multisite?**  
A: Yes, with proper capability checks per site. Each site must grant edit_plugins permission independently.

## Support and Contributions

For issues, questions, or feature requests:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://nvdigitalsolutions.com/wpoos/docs
- Community: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/discussions

## Changelog

### Version 1.1.0
- Initial release of `manage_files` tool
- Support for read, write, and list actions
- Security restrictions and audit logging
- Integration with Architect Agent concept

## License

This feature is part of the NV Digital Open Operator System (NV oOS) Pro addon.  
Copyright (c) 2025 NV Digital Solutions. All rights reserved.
