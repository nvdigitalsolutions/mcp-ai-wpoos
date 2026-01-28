# Examples Directory

This directory contains practical examples demonstrating how to use the WP MCP AI plugin features.

## Embedded Function Calling Examples

The `embedded-function-calling-example.html` and `embedded-function-calling-example.js` files demonstrate how to use function calling (tool calling) with the embedded WebLLM client.

### What is Function Calling?

Function calling allows AI models to call external functions/tools during conversation. This enables:
- Real-time data access (weather, stock prices, etc.)
- External API interactions
- WordPress operations (create posts, query database, etc.)
- Custom business logic execution

### Files

- **`embedded-function-calling-example.html`** - Complete standalone HTML example with UI
- **`embedded-function-calling-example.js`** - JavaScript-only examples for integration
- **`docs/embedded-function-calling-guide.md`** - Comprehensive documentation

### Running the HTML Example

1. **Open in Browser**:
   ```bash
   # From project root
   open examples/embedded-function-calling-example.html
   # Or navigate to it in your browser
   ```

2. **Requirements**:
   - Modern browser with WebGPU support:
     - Chrome 113+ or Edge 113+ (Windows/Linux/macOS)
     - Safari 18+ (macOS only)
   - At least 4GB RAM
   - Fast internet for initial model download (~4.5GB for Hermes-2-Pro)

3. **Using the Example**:
   - Click "Initialize Model" to download and load the AI model
   - Once loaded, click "Test Function Calling" to see a weather query example
   - Watch the console for detailed logs of the function calling process

### Supported Models for Function Calling

Not all models support function calling. Use these models:

| Model | Size | Support | Recommended |
|-------|------|---------|-------------|
| Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC | ~4.5GB | ✅ Excellent | **Yes** |
| Qwen2.5-7B-Instruct-q4f16_1-MLC | ~4.5GB | ✅ Good | No |
| Phi-3.5-mini-instruct-q4f16_1-MLC | ~2.5GB | ✅ Fair | No |

### Quick Code Example

```javascript
// Define a tool
const tools = [{
    type: "function",
    function: {
        name: "get_current_weather",
        description: "Get weather for a location",
        parameters: {
            type: "object",
            properties: {
                location: { type: "string" },
                unit: { type: "string", enum: ["celsius", "fahrenheit"] }
            },
            required: ["location"]
        }
    }
}];

// Use with WebLLM
const engine = await webllm.CreateMLCEngine("Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC");

const response = await engine.chat.completions.create({
    messages: [{ role: "user", content: "What's the weather in Tokyo?" }],
    tools: tools,
    tool_choice: "auto",
    stream: true
});

// Handle tool calls in response
for await (const chunk of response) {
    if (chunk.choices[0]?.delta?.tool_calls) {
        // Model is calling a tool
        console.log('Tool call:', chunk.choices[0].delta.tool_calls);
    }
}
```

### Using with WordPress Plugin

```javascript
// Create client with tools
const client = new window.WP_MCP_AI_EmbeddedLLM('my-chat', {
    tools: [
        {
            slug: 'get_weather',
            description: 'Get current weather',
            parameters: { /* schema */ }
        }
    ]
});

// Load model
await client.loadModel('Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC');

// Chat with tool support
const result = await client.generateStreamingCompletion(messages, {
    tools: client.tools
});

// Check for tool calls
if (result.tool_calls) {
    // Execute tools and send results back
}
```

### Documentation

See `docs/embedded-function-calling-guide.md` for:
- Complete architecture overview
- Detailed API reference
- WordPress integration guide
- Security best practices
- Troubleshooting tips

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
