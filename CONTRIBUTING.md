# Contributing to WP MCP AI

Thanks for taking the time to contribute! This guide summarises the steps you need to get a local environment running, configure API access, run automated checks, and extend the tool registry.

## Prerequisites

* PHP 8.1 or newer
* Composer 2.5+
* Node.js 18+ (optional, only required if you add build tooling)
* A WordPress environment where you can install custom plugins
* An OpenAI API key with access to the chat completions endpoint

## Getting Started

1. Fork the repository and clone it locally.
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Start your local WordPress environment and install/activate the plugin.
4. Copy the sample assistant export if you would like quick seed data:
   ```bash
   wp post create assets/examples/assistant-sample.json --post_type=mcp_ai_assistant
   ```
   The JSON file matches the structure expected by `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()` so you can also import it manually through the REST API or the block editor.

## Configuring API Keys

1. In the WordPress admin dashboard, navigate to **Settings → WP MCP AI**.
2. Paste your OpenAI API key into the **OpenAI API Key** field.
3. (Optional) Update the default model, timeout, or choose a default assistant post.
4. Save the settings. They are stored in the `wp_mcp_ai_settings` option. You can also script this step via WP-CLI:
   ```bash
   wp option patch update wp_mcp_ai_settings openai_api_key "sk-your-key"
   ```

## Running Tests and Quality Checks

Composer provides helper scripts aligned with WordPress coding standards:

```bash
composer lint        # Runs phpcs with the WordPress ruleset
composer lint:compat # Runs PHPCompatibilityWP against PHP 7.4–8.3
composer format      # Attempts to autofix coding standards violations
composer pot         # Generates/updates languages/wp-mcp-ai.pot
```

These commands assume that `phpcs` and `wp` are available via Composer and WP-CLI respectively. If you are missing dependencies, run `composer install` and ensure WP-CLI is installed on your machine. When contributing, please make sure `composer lint` and `composer lint:compat` pass before opening a pull request and include any updates to the `.pot` file when strings change.

## Extending the Tool Registry

The plugin exposes a registry of MCP-compatible tools through the `WP_MCP_AI_Tool_Registry` singleton (`includes/class-wp-mcp-ai-tool-registry.php`). You can register additional tools by hooking into `wp_mcp_ai_register_tools`:

```php
add_action( 'wp_mcp_ai_register_tools', function ( WP_MCP_AI_Tool_Registry $registry ) {
    require_once __DIR__ . '/includes/tools/class-my-custom-tool.php';
    $registry->register_tool( 'My_Custom_Tool_Class' );
} );
```

Each tool must implement `WP_MCP_AI_Tool_Interface` (`includes/tools/class-wp-mcp-ai-tool-interface.php`) and return a unique slug, a human-readable name, a description, and the JSON schema used to describe the tool to the model. Review the existing tools in `includes/tools/` for reference implementations.

## Submitting Changes

1. Create a feature branch from `main`.
2. Make your changes and commit with clear messages.
3. Run the Composer quality checks listed above.
4. Submit a pull request that explains the motivation for the change and any testing details.
5. Respond to review feedback – we appreciate collaborative iteration!

Thank you for helping improve WP MCP AI. 🚀
