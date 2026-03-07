# System Prompt and Assistant Defaults Propagation

## Overview

This document explains how assistant defaults (system instructions, roles/professionals, base knowledge, etc.) are propagated from assistant configuration through the REST API to LLM providers.

## Architecture

The system uses a **layered approach** to ensure assistant defaults always reach the LLM:

```
Assistant Configuration (Database)
         ↓
REST API Handler
         ↓
Options Validator (Merge Request + Config)
         ↓
Language Model Router
         ↓
Provider-Specific Client
         ↓
LLM API (OpenAI, Gemini, Ollama, etc.)
```

## Layer 1: Assistant Configuration

### Storage
Assistant defaults are stored as WordPress post metadata:

- `_wp_mcp_ai_system_prompt` - Custom system instructions
- `_wp_mcp_ai_provider` - LLM provider (openai, gemini, ollama, etc.)
- `_wp_mcp_ai_model` - Model identifier
- `_wp_mcp_ai_temperature` - Temperature setting (0-2)
- `_wp_mcp_ai_tools` - Available tools array
- `_wp_mcp_ai_memory_files` - Knowledge base file IDs
- `_wp_mcp_ai_vector_store_id` - OpenAI vector store ID
- `_wp_mcp_ai_primary_roles` - Professional roles assigned

### Retrieval
**File:** `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`
**Method:** `get_assistant_configuration( $assistant_id )`

```php
$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
// Returns: array(
//     'system_prompt' => 'You are a helpful assistant...',
//     'provider'      => 'openai',
//     'model'         => 'gpt-4',
//     'temperature'   => 0.7,
//     'tools'         => array( ... ),
//     ...
// )
```

### Primary Roles Integration
**Method:** `build_prompt_from_primary_roles( $primary_roles )`

When an assistant has assigned professional roles, the system:
1. Loads each role's metadata (description, knowledge base, expertise, warnings)
2. Builds a structured prompt with role information
3. **Prepends** the role prompt to the existing system_prompt

Example output:
```
# Your Roles and Capabilities

You are an AI assistant with the following professional roles:

## Role: WordPress Developer

Role description and expertise...

### Knowledge Base for WordPress Developer

Detailed knowledge...

---

# Additional Instructions

[Original system_prompt from assistant config]
```

## Layer 2: REST API Handler

**File:** `includes/class-wp-mcp-ai-rest.php`
**Method:** `handle_chat_request( WP_REST_Request $request )`

The REST handler:
1. Validates assistant access
2. Loads assistant configuration
3. Merges professional_prompt if provided in request
4. **Auto-injects vector store context for OpenAI** (see below)
5. Passes configuration to validator

```php
// Load assistant config
$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

// Merge professional prompt if provided
$professional_prompt = $request->get_param( 'professional_prompt' );
if ( ! empty( $professional_prompt ) ) {
    $assistant_config['system_prompt'] = $professional_prompt . "\n\n---\n\n" 
        . $assistant_config['system_prompt'];
}

// Sanitize options with assistant config
$options = $this->validator->sanitize_options( 
    $request->get_param( 'options' ), 
    $assistant_config 
);
```

### Vector Store Context Auto-Injection (OpenAI)

When the provider is `openai` and the assistant has a `vector_store_id` configured, the REST handler automatically:

1. Ensures the `get_vector_store` tool is available in the assistant's tool list
2. Executes `get_vector_store` server-side to fetch current store metadata
3. Appends a structured summary to the system prompt so the LLM has immediate awareness of the vector store's state

This injection happens on **every chat request**, covering:
- Chat-client initialization (first message or page load)
- Channel auto-reply workflows (Telegram, WhatsApp, Messenger, Google Chat, etc.)

**Method:** `build_vector_store_context( array $assistant_config )`

```php
// Auto-inject vector store context for OpenAI provider
if ( 'openai' === $options['provider'] && ! empty( $assistant_config['vector_store_id'] ) ) {
    $assistant_config = $this->ensure_tool_in_config( $assistant_config, 'get_vector_store' );
    $vs_context       = $this->build_vector_store_context( $assistant_config );
    if ( ! empty( $vs_context ) ) {
        $options['system_prompt'] = ! empty( $options['system_prompt'] )
            ? $options['system_prompt'] . "\n\n" . $vs_context
            : $vs_context;
    }
}
```

**Example system prompt addition:**
```
Vector Store:
- ID: vs_abc123
- Name: Product Knowledge Base
- Status: completed
- Total files: 42
- Completed: 42
```

**Caching:** Results are cached with `set_transient()` for **5 minutes** per vector store ID to avoid repeated OpenAI API round-trips on high-traffic sites.

**Availability condition:** The injection only fires if `WP_MCP_AI_Tool_Get_Vector_Store` class is loaded. On the base plugin this class is always available; it is silently skipped if the class is absent (e.g. before the tool registry initialises).

### Logging
```
Event: rest_chat_assistant_config_loaded
Data:
  - assistant_id
  - has_system_prompt
  - system_prompt_length
  - system_prompt_preview (first 200 chars)
  - provider
  - model
  - tools_count
```

## Layer 3: Options Validator

**File:** `includes/rest/class-wp-mcp-ai-rest-validator.php`
**Method:** `sanitize_options( $options, array $assistant_config )`

The validator implements a **smart merge strategy**:

### Merge Logic

```php
// Priority order:
// 1. Request options (if provided and non-empty)
// 2. Assistant config defaults (if request is empty/missing)
// 3. Global settings (if both above are empty)

// System Prompt
if ( isset( $options['system_prompt'] ) && '' !== $options['system_prompt'] ) {
    // Use request value (allows per-request override)
    $options['system_prompt'] = wp_kses_post( $options['system_prompt'] );
} elseif ( ! empty( $assistant_config['system_prompt'] ) ) {
    // Use assistant default
    $options['system_prompt'] = wp_kses_post( $assistant_config['system_prompt'] );
}

// Same pattern for: provider, model, temperature, memory_files, vector_store_id
```

### Logging
```
Event: sanitize_options_system_prompt
Data:
  - provider
  - system_prompt_source ('request', 'assistant_config', or 'none')
  - has_system_prompt
  - system_prompt_length
  - system_prompt_preview
  - assistant_id
  - has_assistant_config_prompt
  - config_prompt_length
  - warning (if propagation fails)
```

## Layer 4: Language Model Router

**File:** `includes/class-wp-mcp-ai-language-model-router.php`
**Method:** `create_chat_completion( array $messages, array $options )`

The router:
1. **Validates options before routing** (NEW)
2. Routes to appropriate provider client
3. Handles provider fallbacks

### Logging
```
Event: router_before_llm_call
Data:
  - provider
  - has_system_prompt
  - system_prompt_length
  - system_prompt_preview
  - model
  - has_temperature
  - tools_count
  - message_count
```

## Layer 5: Provider-Specific Clients

Each LLM provider has different expectations for how system instructions should be formatted:

### OpenAI / LM Studio
**File:** `includes/class-wp-mcp-ai-openai-client.php`
**Format:** System messages in messages array

```php
$system_messages = array();

if ( ! empty( $options['system_prompt'] ) ) {
    $system_messages[] = array(
        'role'    => 'system',
        'content' => array(
            array(
                'type' => 'text',
                'text' => (string) $options['system_prompt'],
            ),
        ),
    );
}

// Prepend to messages array
$payload['messages'] = array_merge( $system_messages, $payload['messages'] );
```

### Google Gemini
**File:** `includes/class-wp-mcp-ai-gemini-client.php`
**Format:** Separate `system_instruction` field

```php
// Extract system messages from messages array
foreach ( $messages as $message ) {
    if ( 'system' === $message['role'] ) {
        $system_fragments[] = $message['content'];
    }
}

// Add from options
if ( ! empty( $options['system_prompt'] ) ) {
    $system_fragments[] = wp_kses_post( $options['system_prompt'] );
}

// Build Gemini-specific structure
if ( ! empty( $system_fragments ) ) {
    $payload['system_instruction'] = array(
        'parts' => array_map(function($text) {
            return array( 'text' => $text );
        }, $system_fragments)
    );
}
```

### Ollama
**File:** `includes/class-wp-mcp-ai-ollama-client.php`
**Format:** Top-level `system` field

```php
if ( ! empty( $options['system_prompt'] ) ) {
    $payload['system'] = wp_kses_post( $options['system_prompt'] );
    
    // Log inclusion
    WP_MCP_AI_Logger::log_event(
        'ollama_system_prompt_included',
        'Ollama: System prompt added to payload',
        array(
            'model'                => $model,
            'system_prompt_length' => strlen( $payload['system'] ),
            'system_preview'       => substr( $payload['system'], 0, 100 ) . '...',
        )
    );
}
```

### Cloudflare Workers AI
**File:** `includes/class-wp-mcp-ai-cloudflare-client.php`
**Format:** System messages in messages array (OpenAI-compatible)

Following [Cloudflare's documentation](https://developers.cloudflare.com/workers-ai/features/prompting/):

```php
// System messages are kept in the messages array, NOT extracted
// Cloudflare follows OpenAI's chat completions format

$payload = array(
    'messages' => $normalized_messages, // Includes system role messages
    'max_tokens' => $max_tokens,
);

// System messages should be FIRST in the array
```

### Hugging Face
**File:** `includes/class-wp-mcp-ai-huggingface-client.php`
**Format:** System messages prepended to messages array

Following [Hugging Face's chat template format](https://huggingface.co/docs/transformers/chat_templating):

```php
$system_messages = array();

if ( ! empty( $options['system_prompt'] ) ) {
    $system_messages[] = array(
        'role'    => 'system',
        'content' => wp_kses_post( (string) $options['system_prompt'] ),
    );
}

// Prepend system messages to formatted messages
if ( ! empty( $system_messages ) ) {
    $formatted_messages = array_merge( $system_messages, $formatted_messages );
}

$payload = array(
    'model'    => $model,
    'messages' => $formatted_messages,
);
```

### Anthropic Claude
**File:** `includes/class-wp-mcp-ai-anthropic-client.php`
**Format:** Separate `system` field (top-level)

```php
// Extract system messages from messages array
foreach ( $messages as $message ) {
    if ( 'system' === $message['role'] ) {
        $system_parts[] = $message['content'];
    }
}

// Add from options
if ( ! empty( $options['system_prompt'] ) ) {
    $system_prompt = wp_kses_post( $options['system_prompt'] );
    if ( ! empty( $payload['system'] ) ) {
        $payload['system'] .= "\n\n" . $system_prompt;
    } else {
        $payload['system'] = $system_prompt;
    }
}
```

### Embedded LLM (WebLLM)
**Files:** 
- `includes/class-wp-mcp-ai-shortcode.php` (PHP configuration)
- `assets/js/chat.js` (JavaScript client-side execution)
- `assets/js/embedded-llm-client.js` (WebLLM wrapper)

**Format:** System messages in messages array (OpenAI-compatible)

The embedded provider runs 100% client-side in the browser using WebLLM. System prompts are passed through the widget configuration and prepended to messages:

**PHP Side (Shortcode):**
```php
// Pass assistant configuration to JavaScript
if ( ! empty( $assistant_config_for_provider['system_prompt'] ) ) {
    $config['systemPrompt'] = $assistant_config_for_provider['system_prompt'];
}
if ( isset( $assistant_config_for_provider['temperature'] ) ) {
    $config['temperature'] = floatval( $assistant_config_for_provider['temperature'] );
}
```

**JavaScript Side (chat.js):**
```javascript
// Prepend system prompt from assistant configuration if available
if (state.config.systemPrompt && !formattedMessages.some(msg => msg.role === 'system')) {
    formattedMessages.unshift({
        role: 'system',
        content: state.config.systemPrompt
    });
}

// Pass to WebLLM with temperature from config
embeddedClient.generateStreamingCompletion(
    formattedMessages,
    {
        temperature: state.config.temperature || 0.7,
        max_tokens: maxTokens
    },
    onChunk
);
```

**How It Works:**
1. Assistant configuration is loaded server-side
2. System prompt and temperature are passed to JavaScript via `wp_localize_script`
3. Chat widget prepends system prompt to messages array before calling WebLLM
4. WebLLM processes the system message using the browser-based LLM (e.g., Llama, Phi)

**Note:** Embedded provider never makes server-side API calls. All processing happens in the user's browser using WebGPU/WebAssembly.

## Verification and Debugging

### Enable Logging

1. In WordPress admin: **Settings → NV oOS → Enable Logging**
2. Or via constant: `define( 'WP_MCP_AI_DEBUG', true );`

### Retrieve Logs

Via WP-CLI:
```bash
wp option get wp_mcp_ai_recent_activity --format=json | jq '.[] | select(.event_type | contains("system_prompt"))'
```

Via WordPress admin:
**Settings → NV oOS → Activity Log** (filter by event type)

### Expected Log Sequence

For a successful chat request with system prompt:

1. `rest_chat_assistant_config_loaded`
   - Confirms assistant config loaded
   - Shows system_prompt_length > 0

2. `sanitize_options_system_prompt`
   - Shows system_prompt_source = 'assistant_config' or 'request'
   - Confirms has_system_prompt = true

3. `router_before_llm_call`
   - Confirms system_prompt present in options before LLM call
   - Shows system_prompt_preview

4. `ollama_system_prompt_included` (for Ollama) or similar for other providers
   - Confirms system prompt added to final payload

### Common Issues

#### System Prompt Not Appearing

**Symptom:** LLM responses don't follow instructions
**Check:**
1. Verify assistant has `_wp_mcp_ai_system_prompt` in post meta
2. Check logs for `system_prompt_source = 'none'`
3. Verify `has_system_prompt = true` in router logs

**Solution:**
- Ensure assistant configuration is saved properly
- Check if request is overriding with empty value
- Review sanitization logic

#### Roles Not Included

**Symptom:** Professional role expertise not reflected in responses
**Check:**
1. Verify assistant has `_wp_mcp_ai_primary_roles` with valid role IDs
2. Check `build_prompt_from_primary_roles()` output in logs
3. Verify role metadata exists (description, knowledge_base, etc.)

**Solution:**
- Ensure roles are properly assigned to assistant
- Verify role posts exist and have required metadata
- Check that role prompts are being prepended

## Testing

### Unit Tests
```bash
vendor/bin/phpunit tests/test-system-prompt-propagation.php
```

### Manual Testing

1. Create an assistant with system instructions
2. Send a chat request via REST API
3. Check activity logs for propagation sequence
4. Verify LLM response follows instructions

### Example Request
```bash
curl -X POST "https://yoursite.com/wp-json/mcp-ai/v1/chat" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "assistant_id": 123,
    "messages": [
      {
        "role": "user",
        "content": "Hello"
      }
    ],
    "options": {
      "provider": "openai",
      "model": "gpt-4"
      // Note: system_prompt not provided - should use assistant default
    }
  }'
```

Check logs to verify system_prompt from assistant config is used.

## Best Practices

### For Plugin Developers

1. **Always use assistant configuration**: Don't hardcode system prompts in requests
2. **Use professional roles**: Leverage the roles system for reusable expertise
3. **Test propagation**: Enable logging during development
4. **Handle overrides carefully**: Only override system_prompt when necessary

### For Administrators

1. **Configure assistants properly**: Set system_prompt, provider, model
2. **Use professional roles**: Create roles for common expertise areas
3. **Monitor logs**: Check for missing system prompts in activity log
4. **Test configurations**: Use probe mode to verify settings

### For End Users

1. **Trust assistant defaults**: The system is designed to use configured instructions
2. **Provide context**: Add user messages to clarify your needs
3. **Report issues**: If assistant behavior is inconsistent, check with admin

## References

- [Cloudflare Workers AI Prompting Guide](https://developers.cloudflare.com/workers-ai/features/prompting/)
- [Hugging Face Chat Templates](https://huggingface.co/docs/transformers/chat_templating)
- [OpenAI Chat Completions API](https://platform.openai.com/docs/api-reference/chat)
- [Anthropic Messages API](https://docs.anthropic.com/claude/reference/messages_post)
- [Google Gemini API](https://ai.google.dev/api/rest/v1/models/generateContent)

## Changelog

### Version 1.1.5
- Auto-inject vector store context into the system prompt for OpenAI assistants with a configured `vector_store_id`
- Context is fetched server-side via `get_vector_store` tool and cached for 5 minutes
- Applies to all request paths: chat-client requests and channel auto-reply workflows

### Version 1.0.0
- Enhanced logging across all providers
- Added system_prompt_source tracking
- Improved validation and error reporting
- Comprehensive documentation
