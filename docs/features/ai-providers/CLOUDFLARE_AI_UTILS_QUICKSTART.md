# Cloudflare AI Utils - Quick Start Guide

Get started with embedded function calling in 5 minutes!

## Prerequisites

✅ WordPress 6.0+  
✅ PHP 7.4+  
✅ WP MCP AI plugin activated  
✅ Cloudflare Workers AI account  

## Step 1: Configure Cloudflare (2 minutes)

1. Go to **WordPress Admin → Settings → NV oOS → Providers**
2. Enter your **Cloudflare API Token** ([Get one here](https://dash.cloudflare.com/profile/api-tokens))
3. Enter your **Cloudflare Account ID** ([Find it here](https://dash.cloudflare.com/))
4. Select a **Model** (e.g., `@cf/meta/llama-3.1-8b-instruct`)
5. Click **Save Settings**

## Step 2: Create Your First Tool (2 minutes)

```php
// Define a simple greeting tool
$greeting_tool = array(
    'name'        => 'greet-user',
    'description' => 'Greets a user by name',
    'parameters'  => array(
        'type'       => 'object',
        'properties' => array(
            'name' => array(
                'type'        => 'string',
                'description' => 'The user\'s name',
            ),
        ),
        'required'   => array( 'name' ),
    ),
    'function'    => function( $args ) {
        $name = sanitize_text_field( $args['name'] );
        return "Hello, {$name}! Welcome to WordPress AI!";
    },
);
```

## Step 3: Execute with AI (1 minute)

```php
// Initialize client
$client = new WP_MCP_AI_Cloudflare_Client();

// Define conversation
$messages = array(
    array(
        'role'    => 'user',
        'content' => 'Please greet John Smith',
    ),
);

// Execute with tool
$response = $client->run_with_tools(
    $messages,
    array( $greeting_tool ),
    array( 'verbose' => true )  // Enable logging for debugging
);

// Check result
if ( is_wp_error( $response ) ) {
    echo 'Error: ' . $response->get_error_message();
} else {
    echo $response['choices'][0]['message']['content'];
    // Output: "Hello, John Smith! Welcome to WordPress AI!"
}
```

## That's It! 🎉

You now have AI that can execute custom PHP functions. The AI will:
1. Recognize when it needs to call your tool
2. Extract the required parameters from the user's message
3. Execute your function with the parameters
4. Use the result to formulate a response

## Next Steps

### Add More Tools
```php
$tools = array( $greeting_tool, $weather_tool, $calculator_tool );
$response = $client->run_with_tools( $messages, $tools );
```

### Enable Auto-Trimming (for many tools)
```php
$options = array(
    'autoTrimTools' => true,   // Auto-select relevant tools
    'maxTools'      => 5,      // Limit to 5 most relevant
);
$response = $client->run_with_tools( $messages, $tools, $options );
```

### Add Validation
```php
$options = array(
    'strictValidation' => true,  // Validate all parameters
    'verbose'          => true,  // Log validation steps
);
```

## Common Patterns

### WordPress Database Query
```php
$query_tool = array(
    'name'        => 'search-posts',
    'description' => 'Search WordPress posts',
    'parameters'  => array(
        'type'       => 'object',
        'properties' => array(
            'keyword' => array( 'type' => 'string' ),
        ),
        'required'   => array( 'keyword' ),
    ),
    'function'    => function( $args ) {
        $posts = get_posts( array( 's' => $args['keyword'] ) );
        return count( $posts ) . ' posts found';
    },
);
```

### External API Call
```php
$api_tool = array(
    'name'        => 'fetch-data',
    'description' => 'Fetch data from external API',
    'parameters'  => array(
        'type'       => 'object',
        'properties' => array(
            'endpoint' => array( 'type' => 'string' ),
        ),
        'required'   => array( 'endpoint' ),
    ),
    'function'    => function( $args ) {
        $response = wp_remote_get( $args['endpoint'] );
        return wp_remote_retrieve_body( $response );
    },
);
```

### User Capability Check
```php
'function' => function( $args ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return 'Permission denied';
    }
    // Execute privileged operation
}
```

## Troubleshooting

### "No Cloudflare API token configured"
→ Configure credentials in WordPress Admin → Settings → NV oOS

### "Tool function not found"
→ Verify your tool array includes the `function` key

### "Maximum recursion reached"
→ Increase `maxRecursiveToolRuns` or improve tool descriptions

### Need More Help?
- **Examples**: See `/examples/cloudflare-ai-utils-examples.php`
- **Full Docs**: See `/docs/CLOUDFLARE_AI_UTILS.md`
- **Tests**: See `/tests/test-cloudflare-ai-utils.php`

## Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `strictValidation` | `true` | Validate tool arguments |
| `maxRecursiveToolRuns` | `5` | Max tool execution depth |
| `verbose` | `false` | Enable detailed logging |
| `autoTrimTools` | `false` | Auto-select relevant tools |
| `maxTools` | `10` | Max tools when trimming |

## Tips for Success

1. **Start Simple**: Begin with one tool, add more as needed
2. **Use Verbose Mode**: Enable `verbose: true` during development
3. **Validate Inputs**: Always sanitize tool arguments
4. **Check Capabilities**: Verify user permissions in tools
5. **Handle Errors**: Use try-catch in tool functions
6. **Test Independently**: Test tool functions before AI integration

## Learn More

- **@cloudflare/ai-utils**: https://www.npmjs.com/package/@cloudflare/ai-utils
- **Cloudflare Workers AI**: https://developers.cloudflare.com/workers-ai/
- **WordPress Standards**: https://developer.wordpress.org/coding-standards/

---

**Ready to build AI agents?** Start with the examples in `/examples/` directory! 🚀
