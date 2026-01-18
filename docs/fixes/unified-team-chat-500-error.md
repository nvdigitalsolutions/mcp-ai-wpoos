# Unified Team Chat Fix

## Issue
When attempting to chat with a unified team (e.g., `unified_team_8863`), the server returned a 500 error.

## Root Cause
The `WP_MCP_AI_REST::invoke_team_member()` method was calling:
```php
$response = $ai_provider->request( $ai_request_options );
```

However, AI provider instances (OpenAI_Client, Gemini_Client, etc.) do not have a `request()` method. They have `create_chat_completion()` method. This caused a fatal PHP error when trying to call an undefined method.

## Solution
Changed `invoke_team_member()` to use the correct method:
```php
$response = $this->client->create_chat_completion( $messages, $options );
```

### Key Changes
1. **Method Call**: Changed from `$ai_provider->request()` to `$this->client->create_chat_completion()`
2. **Parameters**: Split options from messages - `create_chat_completion()` takes two parameters: `$messages` and `$options`
3. **System Prompts**: System prompts are now properly prepended to the messages array as a system role message
4. **Tools**: Tools are passed in the options array, not in a separate parameter

## Files Changed
- `includes/class-wp-mcp-ai-rest.php` (lines 5204-5263)
- `tests/test-unified-team-chat-fix.php` (new test file)

## Testing
To test unified team chat:
1. Create a team with at least one profession member
2. Send a chat request to `/wp-json/mcp-ai/v1/chat-client` with:
   ```json
   {
     "assistant_id": "unified_team_<TEAM_ID>",
     "messages": [
       {
         "role": "user",
         "content": "What can you do?"
       }
     ]
   }
   ```
3. Should receive a successful response (200) instead of 500 error

## Related Code
- Team orchestration: `WP_MCP_AI_REST::handle_unified_team_request()`
- Orchestration modes: `execute_sequential_orchestration()`, `execute_parallel_orchestration()`, `execute_swarm_orchestration()`
- Language Model Router: `WP_MCP_AI_Language_Model_Router::create_chat_completion()`
