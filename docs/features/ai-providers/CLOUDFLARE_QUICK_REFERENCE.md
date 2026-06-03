# Cloudflare Function Calling - Quick Reference

## TL;DR

Cloudflare Workers AI now **automatically** chooses the best function calling approach:

- **Tools with executables** → Embedded (automatic, handles XML)
- **Tools without executables** → Traditional (manual execution)

## Problem Solved

**Before:** Models returned XML like `<name>tool_name</name><arguments>{}</arguments>` to users instead of executing tools.

**Now:** XML is automatically detected, tools are executed, and users get final answers.

## How to Use

### Option 1: Embedded (Recommended for Cloudflare)

```php
$tools = array(
    array(
        'name'     => 'get_weather',
        'function' => function( $args ) {
            return get_weather( $args['city'] );
        }
    )
);

// Automatically uses embedded approach
$response = $client->create_chat_completion( $messages, array( 'tools' => $tools ) );
```

### Option 2: Traditional (WordPress Integration)

```php
$tools = array(
    array(
        'type'     => 'function',
        'function' => array(
            'name' => 'get_weather'
            // No executable - chat service handles it
        )
    )
);

// Automatically uses traditional approach
$response = $client->create_chat_completion( $messages, array( 'tools' => $tools ) );
```

## Key Files

- `includes/class-wp-mcp-ai-cloudflare-client.php` - Smart routing
- `docs/CLOUDFLARE_ROUTING_LOGIC.md` - Complete guide
- `docs/CLOUDFLARE_TRADITIONAL_FUNCTION_CALLING.md` - Deep dive

## Decision Tree

```
create_chat_completion() called
    ↓
Has tools with executables?
    ↓
YES → run_with_tools() [EMBEDDED]
    • Handles XML tool calls
    • Executes tools automatically
    • Returns final answer
    
NO → Traditional single request
    • Returns tool_calls array
    • You execute externally
    • Send results back
```

## Benefits

✅ **No XML output** - Users see answers, not `<name>tool</name>`  
✅ **Auto-execution** - Tools run when needed  
✅ **Smart routing** - Best approach chosen automatically  
✅ **Backwards compatible** - Existing code still works  
✅ **Flexible** - Both approaches available  

## Debugging

```bash
# Enable logging
define( 'WP_MCP_AI_DEBUG', true );

# Check routing decisions
wp option get wp_mcp_ai_recent_activity --format=json | \
  jq '.[] | select(.event | contains("cloudflare_routing"))'
```

## That's It!

Just add `'function' => callable` to your tools and Cloudflare will handle the rest.

For more details, see:
- [Full Routing Logic](./CLOUDFLARE_ROUTING_LOGIC.md)
- [Traditional Function Calling Guide](./CLOUDFLARE_TRADITIONAL_FUNCTION_CALLING.md)
