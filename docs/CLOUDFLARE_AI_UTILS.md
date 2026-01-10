# Cloudflare Workers AI Utilities Integration

## Overview

This document describes the integration of Cloudflare Workers AI utilities into the WordPress plugin, providing PHP equivalents to the [@cloudflare/ai-utils](https://www.npmjs.com/package/@cloudflare/ai-utils) npm package functionality.

## Features

The Cloudflare client (`WP_MCP_AI_Cloudflare_Client`) now includes embedded function calling support, enabling AI agents to execute custom PHP functions during inference.

### Key Capabilities

1. **Embedded Function Calling** - Execute PHP functions based on AI model decisions
2. **Strict Parameter Validation** - Validate function arguments against JSON Schema
3. **Recursive Tool Execution** - Support for multi-turn tool calling conversations
4. **Auto-Trim Tools** - Automatically select relevant tools based on context
5. **Comprehensive Error Handling** - Graceful error handling and logging
6. **Configurable Behavior** - Fine-grained control over execution parameters

## API Reference

### `run_with_tools()`

The main method for embedded function calling.

```php
public function run_with_tools( array $messages, array $tools = array(), array $options = array() )
```

#### Parameters

- **$messages** (array, required): Array of conversation messages
- **$tools** (array, required): Array of tool definitions with executable functions
- **$options** (array, optional): Configuration options

#### Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `tool_choice` | string\|array | `"auto"` | Control tool usage: "auto", "none", "required", "any", or specific tool object |
| `response_format` | array | `null` | JSON mode config: `{type: "json_object"}` or `{type: "json_schema", json_schema: {...}}` |
| `disable_auto_json` | bool | `false` | Disable automatic JSON mode for tool calling |
| `strictValidation` | bool | `true` | Validate tool arguments before execution |
| `maxRecursiveToolRuns` | int | `5` | Maximum recursive tool call depth |
| `streamFinalResponse` | bool | `false` | Return streaming response (PHP limitation: not truly streaming) |
| `verbose` | bool | `false` | Enable detailed logging via WP_MCP_AI_Logger |
| `autoTrimTools` | bool | `false` | Automatically trim tools based on relevance |
| `maxTools` | int | `10` | Maximum tools when auto-trimming |
| `model` | string | (configured) | Override the configured Cloudflare model |
| `temperature` | float | - | Temperature setting (0-1) |
| `max_tokens` | int | - | Maximum tokens to generate |
| `timeout` | int | `60` | Request timeout in seconds |

#### Returns

- **array**: Normalized response from Cloudflare API
- **WP_Error**: Error object if execution fails

## Usage Examples

### Basic Tool Definition and Execution

```php
// Initialize the Cloudflare client.
$client = new WP_MCP_AI_Cloudflare_Client();

// Define a weather tool.
$get_weather_tool = array(
	'name'        => 'get-weather',
	'description' => 'Gets weather information for a specific city',
	'parameters'  => array(
		'type'       => 'object',
		'properties' => array(
			'city' => array(
				'type'        => 'string',
				'description' => 'The city name',
			),
		),
		'required'   => array( 'city' ),
	),
	'function'    => function( $args ) {
		// In a real implementation, this would call a weather API.
		$city = $args['city'];
		return "The weather in {$city} is sunny with a temperature of 72°F.";
	},
);

// Define conversation messages.
$messages = array(
	array(
		'role'    => 'user',
		'content' => 'What is the weather like in Mumbai, India?',
	),
);

// Execute with tools.
$response = $client->run_with_tools(
	$messages,
	array( $get_weather_tool ),
	array(
		'verbose'         => true,
		'strictValidation' => true,
	)
);

if ( is_wp_error( $response ) ) {
	// Handle error.
	error_log( $response->get_error_message() );
} else {
	// Use the response.
	$content = $response['choices'][0]['message']['content'];
	echo $content;
}
```

### Multiple Tools with Auto-Trimming

```php
// Define multiple tools.
$tools = array(
	// Weather tool.
	array(
		'name'        => 'get-weather',
		'description' => 'Gets weather information',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'city' => array( 'type' => 'string' ),
			),
			'required'   => array( 'city' ),
		),
		'function'    => function( $args ) {
			return "Weather data for {$args['city']}";
		},
	),
	// Database search tool.
	array(
		'name'        => 'search-database',
		'description' => 'Search the WordPress database',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'query' => array( 'type' => 'string' ),
			),
			'required'   => array( 'query' ),
		),
		'function'    => function( $args ) {
			global $wpdb;
			// Sanitize and execute query.
			$query = sanitize_text_field( $args['query'] );
			// ... perform search ...
			return "Search results for: {$query}";
		},
	),
	// User info tool.
	array(
		'name'        => 'get-user-info',
		'description' => 'Get WordPress user information',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array( 'type' => 'number' ),
			),
			'required'   => array( 'user_id' ),
		),
		'function'    => function( $args ) {
			$user = get_userdata( $args['user_id'] );
			if ( ! $user ) {
				return 'User not found';
			}
			return "User: {$user->display_name}, Email: {$user->user_email}";
		},
	),
);

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'What is the weather like today?',
	),
);

// Execute with auto-trimming enabled.
$response = $client->run_with_tools(
	$messages,
	$tools,
	array(
		'autoTrimTools'        => true,
		'maxTools'             => 5,
		'maxRecursiveToolRuns' => 3,
		'verbose'              => true,
	)
);
```

### Complex Tool with Validation

```php
// Define a tool with complex parameter schema.
$calculate_tool = array(
	'name'        => 'calculate',
	'description' => 'Perform mathematical calculations',
	'parameters'  => array(
		'type'       => 'object',
		'properties' => array(
			'operation' => array(
				'type'        => 'string',
				'description' => 'The operation to perform',
				'enum'        => array( 'add', 'subtract', 'multiply', 'divide' ),
			),
			'a'         => array(
				'type'        => 'number',
				'description' => 'First operand',
			),
			'b'         => array(
				'type'        => 'number',
				'description' => 'Second operand',
			),
		),
		'required'   => array( 'operation', 'a', 'b' ),
	),
	'function'    => function( $args ) {
		$a   = floatval( $args['a'] );
		$b   = floatval( $args['b'] );
		$op  = $args['operation'];

		switch ( $op ) {
			case 'add':
				return $a + $b;
			case 'subtract':
				return $a - $b;
			case 'multiply':
				return $a * $b;
			case 'divide':
				if ( 0 == $b ) {
					return 'Error: Division by zero';
				}
				return $a / $b;
			default:
				return 'Error: Unknown operation';
		}
	},
);

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'What is 15 multiplied by 7?',
	),
);

$response = $client->run_with_tools(
	$messages,
	array( $calculate_tool ),
	array(
		'strictValidation' => true,  // Enable strict validation.
		'verbose'          => true,  // Log all validation steps.
	)
);
```

### Error Handling

```php
$messages = array(
	array(
		'role'    => 'user',
		'content' => 'Process this request',
	),
);

$tools = array(
	array(
		'name'        => 'process-data',
		'description' => 'Process data with possible errors',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'data' => array( 'type' => 'string' ),
			),
			'required'   => array( 'data' ),
		),
		'function'    => function( $args ) {
			// Simulate an error condition.
			if ( empty( $args['data'] ) ) {
				throw new Exception( 'Data parameter cannot be empty' );
			}

			// Process data.
			return "Processed: {$args['data']}";
		},
	),
);

$response = $client->run_with_tools(
	$messages,
	$tools,
	array(
		'verbose'              => true,
		'maxRecursiveToolRuns' => 3,
	)
);

if ( is_wp_error( $response ) ) {
	$error_code = $response->get_error_code();
	$error_message = $response->get_error_message();
	$error_data = $response->get_error_data();

	// Handle specific error codes.
	switch ( $error_code ) {
		case 'wp_mcp_ai_no_tools':
			echo 'No tools were provided.';
			break;

		case 'wp_mcp_ai_max_tool_recursion':
			echo 'Maximum tool recursion reached.';
			// Access final conversation state.
			$final_messages = $error_data['final_messages'];
			break;

		case 'wp_mcp_ai_missing_cloudflare_api_token':
			echo 'Cloudflare API token not configured.';
			break;

		default:
			echo "Error: {$error_message}";
			break;
	}
}
```

## Tool Definition Schema

### Required Fields

- **name** (string): Unique identifier for the tool
- **function** (callable): PHP callable that executes the tool logic

### Optional Fields

- **description** (string): Human-readable description of what the tool does
- **parameters** (array): JSON Schema object defining the tool's parameters

### Parameters Schema Format

The `parameters` field follows JSON Schema draft-07 specification:

```php
array(
	'type'       => 'object',
	'properties' => array(
		'param_name' => array(
			'type'        => 'string|number|boolean|array|object',
			'description' => 'Parameter description',
			'enum'        => array( 'optional', 'allowed', 'values' ),  // Optional.
		),
	),
	'required'   => array( 'param_name' ),  // List of required parameters.
)
```

## Advanced Features

### Tool Validation

The `validate_tool_arguments()` method performs:

1. **Required Parameter Checking** - Ensures all required parameters are present
2. **Type Validation** - Validates parameter types match schema definitions
3. **PHP to JSON Schema Type Mapping**:
   - `boolean` → `boolean`
   - `integer` / `double` → `number`
   - `string` → `string`
   - `array` → `array`
   - `object` → `object`
   - `NULL` → `null`

### Auto-Trim Tools Algorithm

The `auto_trim_tools()` method uses relevance scoring:

1. Extract the last user message
2. Score each tool based on:
   - **Name match** (weight: 3 points) - Keywords from tool name found in message
   - **Description match** (weight: 1 point) - Words from description found in message
3. Sort tools by relevance score (descending)
4. Keep top N tools (configurable via `maxTools` option)
5. Always keep minimum of 3 tools as fallback
6. If no tools score above 0, keep all original tools

### Recursive Tool Execution

The execution loop:

1. Send conversation with available tools to AI model
2. Check if model wants to call any tools
3. If no tool calls, return final response
4. Execute each requested tool function
5. Append tool results to conversation
6. Repeat from step 1 until:
   - Model returns without tool calls (success)
   - Maximum recursion depth reached (error)

## Security Considerations

### Input Sanitization

All user inputs and tool arguments are sanitized:
- String parameters: `sanitize_text_field()`
- Function names: `sanitize_text_field()`
- HTML content: `wp_kses_post()`

### Validation

- Tool schemas are validated before execution
- Type checking prevents type confusion attacks
- Required parameters are enforced

### Error Handling

- Exceptions in tool functions are caught and logged
- Tool execution errors don't crash the entire system
- Detailed error messages help debugging without exposing internals

### Capability Checks

When implementing tools, always check user capabilities:

```php
'function' => function( $args ) {
	// Check user capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		return 'Permission denied';
	}

	// Execute privileged operation.
	// ...
}
```

## Performance Optimization

### Token Usage Reduction

1. **Auto-Trim Tools**: Reduces number of tools sent in each request
2. **Relevant Tools Only**: Only relevant tools consume context window
3. **Early Termination**: Loop exits as soon as model completes

### Execution Efficiency

1. **Lazy Evaluation**: Tools only executed when called by model
2. **Caching**: Consider caching tool results for repeated calls
3. **Async Operations**: For long-running tools, consider background processing

## Logging and Debugging

Enable verbose logging for detailed execution traces:

```php
$response = $client->run_with_tools(
	$messages,
	$tools,
	array( 'verbose' => true )
);
```

### Log Events

With verbose mode enabled, the following events are logged via `WP_MCP_AI_Logger`:

- `cloudflare_run_with_tools_start` - Execution started
- `cloudflare_auto_trim_tools` - Auto-trimming performed
- `cloudflare_tool_run_iteration` - Each iteration of tool execution loop
- `cloudflare_tool_executed` - Successful tool execution
- `cloudflare_run_with_tools_complete` - Execution completed
- `cloudflare_max_recursion_reached` - Maximum recursion limit hit

### Error Logs

Errors are automatically logged:

- `Cloudflare tool function not found`
- `Cloudflare tool argument validation failed`
- `Cloudflare tool execution failed`

## Comparison with @cloudflare/ai-utils

### Features Implemented

✅ `runWithTools()` - Full PHP equivalent  
✅ Strict validation - Parameter and type checking  
✅ Recursive tool runs - Multi-turn tool calling  
✅ Auto-trim tools - Context-based tool selection  
✅ Error handling - Comprehensive error capture  
✅ Verbose logging - Detailed execution traces  

### PHP Limitations

❌ **Streaming** - PHP doesn't support true streaming like JavaScript ReadableStream. The `streamFinalResponse` option is accepted for API compatibility but returns a complete response.

❌ **Async/Await** - PHP functions are synchronous. Long-running tools will block execution.

### WordPress-Specific Enhancements

✅ **WP_Error Integration** - Standard WordPress error handling  
✅ **Sanitization** - All inputs sanitized per WordPress standards  
✅ **Logging** - Integration with WP_MCP_AI_Logger  
✅ **Capability Checks** - Easy integration with WordPress roles/capabilities  

## Troubleshooting

### Common Issues

#### "No tools were provided"
**Cause**: Empty tools array passed to `run_with_tools()`  
**Solution**: Ensure at least one valid tool is provided

#### "Maximum recursive tool runs reached"
**Cause**: Model keeps calling tools without completing  
**Solution**: Increase `maxRecursiveToolRuns` or improve tool descriptions

#### "Required parameter missing"
**Cause**: Tool function called without required parameters  
**Solution**: Check tool schema matches expected usage

#### "Tool function not found"
**Cause**: Tool definition missing `function` key or function name mismatch  
**Solution**: Verify tool array structure

#### "Parameter type mismatch"
**Cause**: Argument type doesn't match schema definition  
**Solution**: Update schema or validate argument types in tool function

### Debug Steps

1. Enable verbose logging: `'verbose' => true`
2. Check WordPress debug log for tool execution details
3. Verify tool schemas match model expectations
4. Test tools independently before integration
5. Reduce `maxRecursiveToolRuns` to identify infinite loops

## Best Practices

### Tool Design

1. **Single Responsibility**: Each tool should do one thing well
2. **Clear Descriptions**: Help the model understand when to use the tool
3. **Validate Inputs**: Don't trust model-provided arguments
4. **Handle Errors**: Return meaningful error messages
5. **Document Schema**: Provide clear parameter descriptions

### Performance

1. **Minimize Tool Count**: Start with fewer tools, add as needed
2. **Use Auto-Trim**: Enable for large tool sets
3. **Cache Results**: Cache expensive operations
4. **Set Reasonable Limits**: Adjust `maxRecursiveToolRuns` based on use case

### Security

1. **Check Capabilities**: Always verify user permissions
2. **Sanitize Outputs**: Sanitize any user-generated content
3. **Validate Schemas**: Ensure tool schemas are well-defined
4. **Log Securely**: Don't log sensitive information

## Further Reading

- [Cloudflare Workers AI Documentation](https://developers.cloudflare.com/workers-ai/)
- [@cloudflare/ai-utils npm package](https://www.npmjs.com/package/@cloudflare/ai-utils)
- [Embedded Function Calling Guide](https://developers.cloudflare.com/workers-ai/features/function-calling/embedded/get-started/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Review WordPress error logs
3. Enable verbose logging for detailed traces
4. File an issue on the plugin repository

## License

This feature is part of the WP MCP AI plugin and follows the same license.
