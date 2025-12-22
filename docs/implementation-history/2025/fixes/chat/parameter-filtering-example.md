# Visual Example: Parameter Filtering in Action

## Scenario

The AI model is in a chat-client agentic workflow and decides to call the `count_tokens` tool to estimate token usage. However, the AI provider mistakenly includes extra context parameters.

## Before the Fix ❌

```php
// AI provider sends this to the chat-client endpoint:
$tool_call = [
    'function' => [
        'name' => 'count_tokens',
        'arguments' => json_encode([
            'text' => 'Hello world, this is a test.',
            'method' => 'heuristic',
            // Extra parameters from chat context:
            'conversation_id' => 'abc123',
            'timestamp' => 1699999999,
            'user_context' => ['role' => 'admin']
        ])
    ]
];

// In execute_tool_call_internal():
$arguments = json_decode($tool_call['function']['arguments'], true);
// $arguments = [
//     'text' => 'Hello world, this is a test.',
//     'method' => 'heuristic',
//     'conversation_id' => 'abc123',      // ❌ NOT in schema
//     'timestamp' => 1699999999,          // ❌ NOT in schema  
//     'user_context' => [...]             // ❌ NOT in schema
// ]

$result = $tool->execute($arguments, $context);
// ❌ RESULT: May cause errors or unexpected behavior
// The tool's execute() method receives invalid parameters
```

## After the Fix ✅

```php
// Same AI provider call with extra parameters:
$tool_call = [
    'function' => [
        'name' => 'count_tokens',
        'arguments' => json_encode([
            'text' => 'Hello world, this is a test.',
            'method' => 'heuristic',
            // Extra parameters from chat context:
            'conversation_id' => 'abc123',
            'timestamp' => 1699999999,
            'user_context' => ['role' => 'admin']
        ])
    ]
];

// In execute_tool_call_internal():
$arguments = json_decode($tool_call['function']['arguments'], true);
// $arguments = [
//     'text' => 'Hello world, this is a test.',
//     'method' => 'heuristic',
//     'conversation_id' => 'abc123',
//     'timestamp' => 1699999999,
//     'user_context' => [...]
// ]

// NEW: Filter arguments based on schema
$arguments = $this->filter_tool_arguments_by_schema($tool, $arguments);
// $arguments = [
//     'text' => 'Hello world, this is a test.',  // ✅ KEPT (in schema)
//     'method' => 'heuristic',                  // ✅ KEPT (in schema)
//     // 'conversation_id' => FILTERED OUT
//     // 'timestamp' => FILTERED OUT
//     // 'user_context' => FILTERED OUT
// ]

$result = $tool->execute($arguments, $context);
// ✅ RESULT: Works correctly!
// {
//     'estimated_tokens': 8,
//     'method_used': 'heuristic',
//     'model_info': {...}
// }
```

## Logging Output

When parameters are filtered, the system logs them for debugging:

```php
WP_MCP_AI_Logger::log_event(
    'tool_argument_filtered',
    'Dropped extra parameter not in tool schema',
    [
        'tool_slug' => 'count_tokens',
        'parameter' => 'conversation_id',
        'allowed' => ['text', 'messages', 'model', 'method']
    ]
);

WP_MCP_AI_Logger::log_event(
    'tool_argument_filtered',
    'Dropped extra parameter not in tool schema',
    [
        'tool_slug' => 'count_tokens',
        'parameter' => 'timestamp',
        'allowed' => ['text', 'messages', 'model', 'method']
    ]
);

// etc...
```

## Schema Comparison

### count_tokens (strict schema - filtering enabled)
```php
'properties' => [
    'text' => [...],
    'messages' => [...],
    'model' => [...],
    'method' => [...]
],
'additionalProperties' => false  // ← Filtering ENABLED
```

### get_user_info (flexible schema - no filtering)
```php
'properties' => [
    'user_id' => [...]
],
// No 'additionalProperties' or set to true
// ← Filtering DISABLED, all parameters pass through
```

## Benefits

1. ✅ **Prevents Errors**: Tools with strict schemas no longer receive invalid parameters
2. ✅ **Maintains Flexibility**: Tools without strict schemas still get all parameters
3. ✅ **Better Debugging**: Filtered parameters are logged for troubleshooting
4. ✅ **No Breaking Changes**: Existing tools continue to work as before
5. ✅ **Cleaner Code**: Tools don't need to handle unexpected parameters
