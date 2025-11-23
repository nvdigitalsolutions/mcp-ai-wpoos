# GitHub Integration for Custom Tool Development

## Overview

The GitHub integration feature allows WordPress administrators to connect their GitHub account and develop custom tools for WP MCP AI directly from GitHub Codespaces, without risk of breaking the live site.

## Key Features

### Safe Development Environment
- **Isolated custom tools directory**: All custom tools are stored in `wp-content/uploads/wp-mcp-ai-custom-tools/`
- **Core plugin protection**: Users cannot modify core plugin files
- **Automatic security files**: `.htaccess` and `index.php` prevent direct access
- **PHP syntax validation**: All code is validated before being accepted
- **Dangerous function blocking**: Functions like `eval()`, `exec()`, `system()` are blocked

### GitHub Integration
- **OAuth 2.0 authentication**: Secure connection to GitHub
- **Repository operations**: List, browse, and manage repositories
- **Branch management**: Create branches for custom tool development
- **File operations**: Read and write files in the `custom-tools/` directory
- **GitHub Codespaces**: Launch development environments directly from WordPress

## Setup Instructions

### 1. Create a GitHub OAuth Application

1. Go to [GitHub Developer Settings](https://github.com/settings/developers)
2. Click "New OAuth App"
3. Fill in the application details:
   - **Application name**: WP MCP AI - Your Site Name
   - **Homepage URL**: `https://yoursite.com`
   - **Authorization callback URL**: `https://yoursite.com/wp-admin/admin-post.php?action=wp_mcp_ai_github_oauth_callback`
4. Click "Register application"
5. Copy the **Client ID** and generate a **Client Secret**

### 2. Configure WP MCP AI Settings

1. In WordPress admin, go to **Settings → WP MCP AI**
2. Find the GitHub Integration section
3. Enter your **GitHub Client ID**
4. Enter your **GitHub Client Secret**
5. Click **Save Changes**

### 3. Connect Your GitHub Account

1. Click the **Connect GitHub** button
2. You'll be redirected to GitHub to authorize the application
3. Grant the requested permissions:
   - `repo`: Full control of private repositories
   - `user`: Read user profile data
   - `codespace`: Manage GitHub Codespaces
4. After authorization, you'll be redirected back to WordPress

## Using the GitHub Tools

The integration provides three main tools that can be used by AI assistants:

### 1. List GitHub Repositories

**Tool slug**: `list_github_repositories`

Lists all repositories accessible to the authenticated user.

**Parameters**:
- `type` (optional): Filter by repository type (all, owner, public, private, member)
- `sort` (optional): Sort by (created, updated, pushed, full_name)
- `direction` (optional): Sort direction (asc, desc)
- `per_page` (optional): Results per page (1-100)
- `page` (optional): Page number

**Example usage**:
```json
{
  "type": "owner",
  "sort": "updated",
  "direction": "desc",
  "per_page": 10
}
```

### 2. Manage GitHub Codespace

**Tool slug**: `manage_github_codespace`

Create, start, stop, or list GitHub Codespaces for development.

**Parameters**:
- `action` (required): Action to perform (create, list, start, stop, get, delete)
- `owner` (required for create): Repository owner
- `repo` (required for create): Repository name
- `ref` (optional): Branch to open (default: main)
- `machine` (optional): Machine type (default: basicLinux32gb)
- `codespace_name` (required for start/stop/get/delete): Codespace name

**Example usage (create)**:
```json
{
  "action": "create",
  "owner": "nvdigitalsolutions",
  "repo": "wp-mcp-ai",
  "ref": "feature/custom-tool",
  "machine": "basicLinux32gb"
}
```

**Example usage (list)**:
```json
{
  "action": "list"
}
```

### 3. GitHub Repository Operations

**Tool slug**: `github_repository_operations`

Perform safe operations on repository files in the `custom-tools/` directory.

**Parameters**:
- `action` (required): Action to perform (list_branches, create_branch, get_file, update_file)
- `owner` (required): Repository owner
- `repo` (required): Repository name
- `branch_name` (for create_branch): New branch name
- `source_branch` (for create_branch): Branch to branch from
- `file_path` (for get_file/update_file): File path (must be in `custom-tools/`)
- `file_content` (for update_file): File content
- `commit_message` (for update_file): Commit message

**Safety restrictions**:
- ✅ File path must start with `custom-tools/`
- ✅ Only `.php` files are allowed
- ✅ No directory traversal (`..`) permitted
- ✅ PHP syntax validation required
- ✅ Dangerous functions blocked

**Example usage (create branch)**:
```json
{
  "action": "create_branch",
  "owner": "nvdigitalsolutions",
  "repo": "wp-mcp-ai",
  "branch_name": "feature/my-custom-tool",
  "source_branch": "main"
}
```

**Example usage (get file)**:
```json
{
  "action": "get_file",
  "owner": "nvdigitalsolutions",
  "repo": "wp-mcp-ai",
  "file_path": "custom-tools/class-wp-mcp-ai-tool-custom-example.php",
  "branch": "feature/my-custom-tool"
}
```

**Example usage (update file)**:
```json
{
  "action": "update_file",
  "owner": "nvdigitalsolutions",
  "repo": "wp-mcp-ai",
  "file_path": "custom-tools/class-wp-mcp-ai-tool-custom-my-tool.php",
  "file_content": "<?php\n// Tool implementation...",
  "commit_message": "Add my custom tool",
  "branch": "feature/my-custom-tool"
}
```

## Custom Tool Development Workflow

### Recommended Workflow

1. **Create a feature branch**:
   ```
   Use github_repository_operations with action: create_branch
   Branch name: feature/my-custom-tool
   ```

2. **Create a Codespace**:
   ```
   Use manage_github_codespace with action: create
   Repository: your repo
   Ref: feature/my-custom-tool
   ```

3. **Develop your tool in Codespace**:
   - Use GitHub Copilot for assistance
   - Create your tool file in `custom-tools/` directory
   - Follow the naming convention: `class-wp-mcp-ai-tool-custom-{name}.php`
   - Implement the `WP_MCP_AI_Tool_Interface`

4. **Test locally** (if needed):
   ```
   Use github_repository_operations with action: get_file
   Copy content to local test environment
   ```

5. **Commit changes**:
   ```
   Use github_repository_operations with action: update_file
   Provide commit message
   ```

6. **Create pull request** (via GitHub UI)

7. **Deploy to production**:
   - Merge PR to main branch
   - Custom tools are automatically loaded from the custom-tools/ directory

### Tool Template

When creating a new custom tool, use this template:

```php
<?php
/**
 * Custom Tool: My Tool Name
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Custom tool implementation.
 */
class WP_MCP_AI_Tool_Custom_My_Tool implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'custom_my_tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'My Custom Tool', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Description of what my tool does.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'param1' => array(
					'type'        => 'string',
					'description' => __( 'Description of parameter.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'param1' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check capabilities.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Insufficient permissions.', 'wp-mcp-ai' ) );
		}

		// Implement your tool logic here.
		
		return array(
			'success' => true,
			'message' => __( 'Tool executed successfully.', 'wp-mcp-ai' ),
			// Add your result data here
		);
	}
}
```

## Security Best Practices

### For Administrators

1. **Use a dedicated GitHub OAuth app** for each WordPress site
2. **Rotate client secrets regularly**
3. **Review custom tools** before deploying to production
4. **Use branch protection rules** on your repository
5. **Enable two-factor authentication** on your GitHub account

### For Developers

1. **Never hardcode secrets** in custom tools
2. **Always validate user input**
3. **Check capabilities** before executing privileged operations
4. **Use WordPress functions** instead of raw PHP functions
5. **Follow WordPress coding standards**

### Blocked Operations

The following are explicitly blocked for safety:
- ❌ Modifying core plugin files
- ❌ Accessing files outside `custom-tools/` directory
- ❌ Using dangerous PHP functions (eval, exec, system, etc.)
- ❌ Directory traversal attempts
- ❌ Files larger than 100MB

## Troubleshooting

### "GitHub access token is not configured"

**Solution**: Complete the OAuth setup and connect your GitHub account.

### "For safety, only files in the custom-tools/ directory can be accessed"

**Solution**: Ensure your file path starts with `custom-tools/` and doesn't contain `..`

### "Dangerous function 'xyz' is not allowed in custom tools"

**Solution**: Remove the blocked function from your code. Use WordPress alternatives instead.

### "PHP syntax error"

**Solution**: Fix the syntax errors in your PHP code. The error message will indicate what's wrong.

### OAuth connection fails

**Solution**: 
1. Verify callback URL matches exactly (including trailing slash)
2. Check client ID and secret are correct
3. Ensure your site is accessible via HTTPS
4. Check WordPress error logs for detailed error messages

## API Reference

### Custom Tool Loader API

**Create tool template**:
```php
$loader = new WP_MCP_AI_Custom_Tool_Loader();
$result = $loader->create_tool_template( 'my_tool_name' );
```

**List custom tools**:
```php
$loader = new WP_MCP_AI_Custom_Tool_Loader();
$tools  = $loader->list_custom_tools();
```

**Delete custom tool**:
```php
$loader = new WP_MCP_AI_Custom_Tool_Loader();
$result = $loader->delete_custom_tool( 'my_tool_name' );
```

### GitHub Client API

**List repositories**:
```php
$client = new WP_MCP_AI_Github_Client();
$repos  = $client->list_repositories( array( 'type' => 'owner' ) );
```

**Create Codespace**:
```php
$client    = new WP_MCP_AI_Github_Client();
$codespace = $client->create_codespace( 'owner', 'repo', array( 'ref' => 'main' ) );
```

## FAQ

**Q: Can I modify existing core plugin tools?**
A: No, for security reasons, only files in the `custom-tools/` directory can be modified.

**Q: Do custom tools persist across plugin updates?**
A: Yes, custom tools are stored in `wp-content/uploads/` which is not affected by plugin updates.

**Q: Can multiple users create custom tools?**
A: Only users with `manage_options` capability can create and manage custom tools.

**Q: Are custom tools automatically loaded?**
A: Yes, all properly formatted tools in the `custom-tools/` directory are automatically loaded and registered.

**Q: Can I use Composer packages in custom tools?**
A: Custom tools can only use WordPress core functions and the WP MCP AI plugin APIs. External dependencies should be included via the main plugin.

**Q: How do I share custom tools with other sites?**
A: Custom tools are just PHP files, so you can copy them to other sites' `custom-tools/` directories or use GitHub to share them.

## Support

For issues or questions:
- [GitHub Issues](https://github.com/nvdigitalsolutions/wp-mcp-ai/issues)
- [Documentation](https://github.com/nvdigitalsolutions/wp-mcp-ai/tree/main/docs)
- [Contributing Guide](https://github.com/nvdigitalsolutions/wp-mcp-ai/blob/main/CONTRIBUTING.md)
