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

### Step 2: System Prompt (Enhanced for GitHub Copilot CLI Parity)

```
You are an Architect Agent - a specialized AI assistant with GitHub Copilot CLI-like capabilities for WordPress plugin development.

Your tools (similar to GitHub Copilot CLI):
1. **manage_files**: Read, write, and list files within the plugin directory
2. **execute_shell_command**: Run shell commands (builds, tests, git operations, etc.)
3. **git_operations**: Git version control (status, diff, log, commit, branch, etc.)
4. **search_codebase**: Search for code patterns, functions, classes, and files

Your capabilities:
1. Read and analyze plugin code to understand current implementation
2. Execute development commands (npm, composer, git, phpunit, etc.)
3. Perform git operations (commits, branches, diffs, history)
4. Search codebase for functions, classes, patterns, and files
5. Write new files or update existing files with improvements

Your discovery approach:
1. **Search first**: Use search_codebase to find relevant code patterns
2. **Read context**: Use manage_files to examine files found by search
3. **Check git state**: Use git_operations to understand recent changes
4. **Execute commands**: Use execute_shell_command for builds/tests
5. **Make changes**: Write modifications after thorough analysis

Your responsibilities:
1. Maintain code quality and follow WordPress coding standards
2. Add proper PHPDoc documentation for all changes
3. Ensure backward compatibility unless explicitly instructed otherwise
4. Test changes (run linters, builds, tests via execute_shell_command)
5. Commit changes with clear messages (via git_operations)
6. Follow security best practices (sanitize input, escape output, validate data)

Your constraints:
1. All operations restricted to plugin directory (WP_MCP_AI_PATH)
2. Preview commands before execution (use preview=true parameter)
3. Always explain what you're about to change and why
4. Never execute dangerous commands (automatically blocked)
5. Respect timeout limits (default 30s, max 300s for shell commands)

Workflow example (GitHub Copilot CLI style):
User: "Add a new tool for generating PDFs"

You: Let me search for similar tools first...
[search_codebase: query="generate", search_type="function", file_pattern="class-wp-mcp-ai-tool-generate-*.php"]

You: I found 15 generator tools. Let me examine one...
[manage_files: action="read", path="includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php"]

You: Now I'll check what's changed recently...
[git_operations: operation="log", limit=5]

You: Let me create the new PDF tool...
[manage_files: action="write", path="includes/tools/class-wp-mcp-ai-tool-generate-pdf.php", content="..."]

You: Adding it to the registry...
[git_operations: operation="diff", file_path="includes/class-wp-mcp-ai-tool-registry.php"]
[manage_files: action="write", path="includes/class-wp-mcp-ai-tool-registry.php", content="..."]

You: Running tests to verify...
[execute_shell_command: command="vendor/bin/phpunit tests/test-generate-pdf-tool.php", timeout=60]

You: All tests pass! Committing changes...
[git_operations: operation="add", file_path="includes/tools/class-wp-mcp-ai-tool-generate-pdf.php"]
[git_operations: operation="commit", message="Add PDF generation tool"]

Safety features (GitHub Copilot CLI inspired):
- Use preview=true to show shell commands before execution
- Dangerous commands are automatically blocked
- All write operations are logged
- Timeouts prevent runaway processes
- Git operations allow easy rollback
```

### Step 3: Enable the Tool

1. In the assistant editor, scroll to **Available Tools**
2. Find and enable `manage_files`
3. Review the tool description to understand its parameters
4. Save the assistant

## GitHub Copilot CLI-Inspired Tools

The Architect Agent now includes tools inspired by GitHub Copilot CLI's capabilities:

### Available Tools

1. **manage_files** - Read, write, and list files
   - Read file contents
   - Create/update files with automatic directory creation
   - List directory contents
   - [Original self-editing capability]

2. **execute_shell_command** - Run shell commands safely
   - Execute git, build, test, and development commands
   - Preview mode shows command before execution
   - Timeout protection (1-300 seconds)
   - Dangerous command blocking (rm -rf /, fork bombs, etc.)
   - Logs all executions

3. **git_operations** - Git version control
   - Read operations: status, diff, log, show, blame, branch
   - Write operations: commit, add, checkout, stash
   - All operations scoped to plugin directory
   - Logs all modifications

4. **search_codebase** - Search for code patterns
   - Text search (grep-style with regex)
   - Function search (find function definitions)
   - Class search (find class definitions)
   - File search (find files by name)
   - Symbol search (find any symbol)
   - Context lines around matches

### Tool Comparison with GitHub Copilot CLI

| Feature | GitHub Copilot CLI | Architect Agent | Notes |
|---------|-------------------|-----------------|-------|
| File operations | ✅ | ✅ manage_files | Read, write, list |
| Shell commands | ✅ | ✅ execute_shell_command | With safety controls |
| Git integration | ✅ | ✅ git_operations | Full git support |
| Code search | ✅ | ✅ search_codebase | Pattern + symbol search |
| Natural language | ✅ | ✅ | Via AI model |
| Safety confirmations | ✅ | ✅ preview mode | Show before execute |
| Workspace approval | ✅ | ✅ | Via WordPress capabilities |
| MCP Protocol | ✅ | ✅ | Native MCP tool support |

### Security Model

The Architect Agent follows GitHub Copilot CLI's security principles:

**1. Workspace Trust**
- All operations restricted to plugin directory (WP_MCP_AI_PATH)
- Requires `edit_plugins` WordPress capability
- No access to files outside plugin

**2. Preview Before Execute**
- Shell commands support `preview: true` parameter
- Shows what will be executed without running it
- User can review and approve before execution

**3. Dangerous Operation Blocking**
- Blocks known dangerous patterns:
  - `rm -rf /` and similar destructive commands
  - Fork bombs
  - Direct disk writes
  - Piping downloads to shell
  - Dangerous permissions (chmod 777)

**4. Audit Logging**
- All write operations logged
- Includes user ID, assistant ID, timestamp
- Viewable in Settings → NV oOS → Recent Activity

**5. Timeout Protection**
- Shell commands have configurable timeouts (1-300 seconds)
- Processes killed if timeout exceeded
- Prevents runaway processes

## Codebase Discovery

### Does the Agent Need to Know Everything?

**No.** The Architect Agent doesn't need pre-loaded knowledge of every function, class, and tool in the codebase. Instead, it discovers the codebase structure **dynamically** and **on-demand** using the `manage_files` tool.

### Discovery Process

The agent follows this pattern when working with unfamiliar code:

1. **Start Broad**: List directory contents to understand structure
2. **Narrow Focus**: Read specific files related to the task
3. **Understand Context**: Examine related files (base classes, interfaces, similar tools)
4. **Make Changes**: Write modifications based on discovered patterns

### Available Documentation

The agent can access comprehensive reference documentation:

- **Tool Reference** (`docs/reference/tools/tool-reference.md`): 398 tools documented with usage examples
- **Architecture Docs** (`docs/`): 100+ documentation files covering all aspects of the plugin
- **Code Comments**: PHPDoc blocks in all classes provide inline documentation
- **WordPress Codex**: Agent can reference WordPress standards and APIs

### Example Discovery Workflow

```
User: "Add a new tool for generating SVG diagrams"

Agent Process:
1. Lists includes/tools/ directory to see existing tool patterns
2. Reads a similar tool (e.g., class-wp-mcp-ai-tool-generate-mermaid.php)
3. Identifies base class and interfaces to implement
4. Examines tool registration in includes/class-wp-mcp-ai-tool-registry.php
5. Creates new tool following discovered patterns
6. Registers tool in appropriate array (base_tools or pro_tools)
```

### Smart Discovery Strategies

The agent learns efficiently by:

- **Pattern Recognition**: One or two tool examples reveal the common structure
- **Interface Documentation**: Reading interface files shows required methods
- **Registry Inspection**: Understanding the tool registry reveals registration requirements
- **Test Examination**: Looking at existing tests shows expected behavior
- **Documentation First**: Checking docs/ before diving into implementation details

### Knowledge Persistence

While the agent discovers code on-demand, you can improve efficiency by:

1. **Including Context**: Provide relevant file paths in your request
   - ❌ "Add a tool" → Agent must search broadly
   - ✅ "Add a tool like the generate_mermaid tool" → Agent knows where to look

2. **Referencing Documentation**: Point to specific docs when available
   - "See docs/reference/tools/tool-reference.md for tool standards"

3. **Building Incrementally**: Each interaction builds understanding
   - Session 1: Agent learns tool structure
   - Session 2: Agent applies learned patterns faster

## Using the Architect Agent

### Example Workflows

#### 1. Discovering Codebase Structure

```
User: Show me the structure of the includes/tools directory

Agent: [Uses manage_files with action="list", path="includes/tools"]
```

#### 2. Learning from Existing Tools

```
User: Show me how image generation tools are implemented

Agent: [Lists includes/tools/, finds image tools, reads 1-2 examples to understand patterns]
```

#### 3. Reading Existing Code

```
User: Read the manage_files tool implementation

Agent: [Uses manage_files with action="read", path="includes/tools/class-wp-mcp-ai-tool-manage-files.php"]
```

#### 4. Creating a New File

```
User: Create a new tool for generating documentation

Agent: I'll create a new documentation generator tool. Let me write the file...
[Uses manage_files with action="write", path="includes/tools/class-wp-mcp-ai-tool-generate-docs.php", content="<?php..."]
```

#### 5. Modifying Existing Code

```
User: Add input validation to the search_content tool

Agent: I'll first read the current implementation, then suggest improvements...
[Reads file, analyzes, proposes changes, then writes updates with user approval]
```

#### 6. Git Stash Workflows

The `git_operations` tool supports comprehensive stash operations for managing work-in-progress changes:

**Saving Work in Progress:**
```
User: I need to switch branches but have uncommitted changes. Save them.

Agent: I'll stash your current changes...
[Uses git_operations with operation="stash", stash_subcommand="push", message="WIP: Feature X changes"]
```

**Viewing Stashed Changes:**
```
User: What changes do I have stashed?

Agent: Let me list your stashes...
[Uses git_operations with operation="stash", stash_subcommand="list"]
Response: 
{
  "stash_count": 3,
  "stash_entries": [
    {"ref": "stash@{0}", "index": 0, "message": "WIP: Feature X changes"},
    {"ref": "stash@{1}", "index": 1, "message": "WIP on main: 5981ee5"},
    {"ref": "stash@{2}", "index": 2, "message": "Experimental UI changes"}
  ]
}
```

**Applying Stashed Changes:**
```
User: Apply my most recent stash

Agent: Applying stash@{0}...
[Uses git_operations with operation="stash", stash_subcommand="apply", stash_ref="stash@{0}"]
```

**Complete Workflow Example:**
```
1. Save current work:
   operation="stash", stash_subcommand="push", message="Feature X in progress", include_untracked=true

2. Switch to hotfix branch:
   operation="checkout", branch_name="hotfix/urgent-fix"

3. Make and commit hotfix:
   [make changes, then commit]

4. Return to original branch:
   operation="checkout", branch_name="feature/x"

5. Restore work in progress:
   operation="stash", stash_subcommand="pop"
```

**Creating Branch from Stash:**
```
User: Create a new branch from my stashed experimental changes

Agent: I'll create a branch from stash@{2}...
[Uses git_operations with operation="stash", stash_subcommand="branch", 
 branch_name="experimental-ui", stash_ref="stash@{2}"]
```

**Stash Subcommands Available:**
- `list` - Show all stashed changes with metadata
- `push` - Save current changes (supports `include_untracked`, `keep_index`, `message`)
- `pop` - Apply and remove most recent stash
- `apply` - Apply stash without removing it
- `drop` - Delete a specific stash by reference
- `clear` - Remove all stashes (use with caution)
- `show` - Display diff of stashed changes
- `branch` - Create new branch from stash

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
