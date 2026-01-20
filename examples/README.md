# Examples Directory

This directory contains practical examples demonstrating how to use the WP MCP AI plugin features.

## Cloudflare AI Utils Examples

The `cloudflare-ai-utils-examples.php` file demonstrates the embedded function calling feature (PHP equivalent to @cloudflare/ai-utils).

### Running the Examples

1. **Requirements**:
   - WordPress 6.0 or higher
   - PHP 7.4 or higher
   - WP MCP AI plugin installed and activated
   - Valid Cloudflare Workers AI credentials configured

2. **Setup Cloudflare Credentials**:
   - Go to WordPress Admin → Settings → NV oOS → Providers
   - Enter your Cloudflare API Token
   - Enter your Cloudflare Account ID
   - Select a Cloudflare model (e.g., `@cf/meta/llama-3.1-8b-instruct`)
   - Save settings

3. **Running Examples**:

   **Option A: Via WordPress Admin (recommended)**
   - Create a new PHP file in your theme or use a plugin like Code Snippets
   - Include the example file:
     ```php
     require_once WP_PLUGIN_DIR . '/mcp-ai-wpoos/examples/cloudflare-ai-utils-examples.php';
     ```
   - Uncomment the example you want to run
   - Execute the file

   **Option B: Via WP-CLI**
   ```bash
   wp eval-file /path/to/wp-content/plugins/mcp-ai-wpoos/examples/cloudflare-ai-utils-examples.php
   ```

   **Option C: In a Custom Plugin or Theme**
   ```php
   add_action( 'init', function() {
       if ( ! current_user_can( 'manage_options' ) ) {
           return;
       }
       
       require_once WP_PLUGIN_DIR . '/mcp-ai-wpoos/examples/cloudflare-ai-utils-examples.php';
       
       // Run example 1
       $result = wp_mcp_ai_example_basic_weather_tool();
       var_dump( $result );
   });
   ```

### Available Examples

#### Example 1: Basic Weather Tool
Demonstrates a simple tool that returns weather information for a city.

**Features**:
- Basic tool definition
- Parameter validation
- Mock data responses

**Usage**:
```php
$result = wp_mcp_ai_example_basic_weather_tool();
print_r( $result );
```

#### Example 2: WordPress Database Query Tool
Shows how to create a tool that queries the WordPress database.

**Features**:
- WordPress post search
- Capability checking
- Database interaction

**Usage**:
```php
$result = wp_mcp_ai_example_database_query_tool();
print_r( $result );
```

#### Example 3: Multiple Tools with Auto-Trimming
Demonstrates using multiple tools with automatic relevance-based selection.

**Features**:
- Multiple tool definitions
- Auto-trimming based on context
- Relevance scoring

**Usage**:
```php
$result = wp_mcp_ai_example_multiple_tools_with_trimming();
print_r( $result );
```

#### Example 4: Calculator Tool with Complex Validation
Shows complex parameter validation and error handling.

**Features**:
- Enum parameter validation
- Multiple operation types
- Error handling for edge cases (division by zero)
- Multi-turn tool execution

**Usage**:
```php
$result = wp_mcp_ai_example_calculator_tool();
print_r( $result );
```

## Security Considerations

### Important Notes for Production

1. **Capability Checks**: Always verify user capabilities before executing privileged operations:
   ```php
   if ( ! current_user_can( 'manage_options' ) ) {
       return 'Permission denied';
   }
   ```

2. **Input Sanitization**: Sanitize all tool arguments:
   ```php
   $city = sanitize_text_field( $args['city'] );
   $user_id = absint( $args['user_id'] );
   ```

3. **Output Escaping**: Escape output when displaying to users:
   ```php
   echo esc_html( $result );
   ```

4. **Rate Limiting**: Implement rate limiting for expensive operations.

5. **Logging**: Enable verbose logging during development, but disable in production for performance.

## Troubleshooting

### "No Cloudflare API token has been configured"
**Solution**: Configure your Cloudflare credentials in WordPress Admin → Settings → NV oOS → Providers

### "Maximum recursive tool runs reached"
**Solution**: Increase `maxRecursiveToolRuns` in options or improve tool descriptions to help the AI understand when to stop

### "Tool function not found"
**Solution**: Verify your tool array includes the `function` key with a valid callable

### "Permission denied"
**Solution**: Ensure you have the required WordPress capabilities to execute the tool

## Additional Resources

- **Documentation**: See `/docs/CLOUDFLARE_AI_UTILS.md` for complete API documentation
- **Tests**: See `/tests/test-cloudflare-ai-utils.php` for test examples
- **Cloudflare Docs**: https://developers.cloudflare.com/workers-ai/
- **Plugin Docs**: See `/docs/` directory for comprehensive documentation

## Contributing Examples

To contribute new examples:

1. Create a new function following the naming pattern: `wp_mcp_ai_example_[description]()`
2. Add comprehensive PHPDoc comments
3. Include error handling
4. Test thoroughly
5. Submit a pull request

## Support

For questions or issues:
- Check the troubleshooting section above
- Review the main documentation in `/docs/`
- File an issue on the GitHub repository
