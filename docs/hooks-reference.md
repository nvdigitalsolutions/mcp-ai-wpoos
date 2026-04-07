# NV oOS Developer Hooks Reference

> **Comprehensive reference for all action and filter hooks in the NV oOS plugin.**
> Use hooks to extend, customize, or integrate with the plugin without modifying core code.
> Last reviewed: April 2026.

---

## Table of Contents

1. [Chat & Messaging Hooks](#chat--messaging-hooks)
2. [Tool Execution Hooks](#tool-execution-hooks)
3. [Agentic Loop Hooks](#agentic-loop-hooks)
4. [Slash Command Hooks](#slash-command-hooks)
5. [Settings & Admin Hooks](#settings--admin-hooks)
6. [Authentication Hooks](#authentication-hooks)
7. [Token & Cost Management Hooks](#token--cost-management-hooks)
8. [A2A & Orchestration Hooks](#a2a--orchestration-hooks)
9. [Performance & Caching Hooks](#performance--caching-hooks)
10. [Plugin Lifecycle Hooks](#plugin-lifecycle-hooks)
11. [Email & Newsletter Hooks](#email--newsletter-hooks)
12. [Crawler & Search Hooks](#crawler--search-hooks)
13. [Video Generation Hooks](#video-generation-hooks)

---

## Chat & Messaging Hooks

### Action: `wp_mcp_ai_before_chat_request`

Fires before a chat request is processed. Use this to inject additional context, validate input, or log requests.

**Parameters:**
- `int $assistant_id` — Assistant post ID.
- `array $messages` — Conversation messages array.
- `array $options` — Chat request options.
- `WP_REST_Request|null $request` — REST request object (null when called from service).

**Fired in:** `class-wp-mcp-ai-rest.php:2574`, `class-wp-mcp-ai-chat-service.php:174`

**Example:**
```php
add_action( 'wp_mcp_ai_before_chat_request', function( $assistant_id, $messages, $options, $request ) {
    // Inject site-wide context into every conversation
    error_log( sprintf( 'Chat request to assistant %d with %d messages', $assistant_id, count( $messages ) ) );
}, 10, 4 );
```

---

### Action: `wp_mcp_ai_after_chat_response`

Fires after a chat response is prepared and ready to return.

**Parameters:**
- `int $assistant_id` — Assistant post ID.
- `array $response` — AI response data.
- `WP_REST_Request $request` — REST request object.

**Fired in:** `class-wp-mcp-ai-rest.php:3000`, `class-wp-mcp-ai-rest.php:3749`

**Example:**
```php
add_action( 'wp_mcp_ai_after_chat_response', function( $assistant_id, $response, $request ) {
    // Log response for analytics
    do_action( 'my_analytics_track', 'chat_response', array(
        'assistant_id' => $assistant_id,
        'model'        => $response['model'] ?? 'unknown',
    ) );
}, 10, 3 );
```

---

### Action: `wp_mcp_ai_cost_calculated`

Fires when token cost is calculated for a chat request.

**Parameters:**
- `array $cost_data` — Cost breakdown (input_tokens, output_tokens, cost_usd, model).
- `int $assistant_id` — Assistant post ID.
- `int $user_id` — WordPress user ID.
- `array $response` — AI response data.
- `WP_REST_Request $request` — REST request object.

**Fired in:** `class-wp-mcp-ai-rest.php:3058`, `class-wp-mcp-ai-rest.php:3909`

**Example:**
```php
add_action( 'wp_mcp_ai_cost_calculated', function( $cost_data, $assistant_id, $user_id ) {
    // Track costs per user
    $total = get_user_meta( $user_id, '_mcp_ai_total_cost', true ) ?: 0;
    update_user_meta( $user_id, '_mcp_ai_total_cost', $total + ( $cost_data['cost_usd'] ?? 0 ) );
}, 10, 3 );
```

---

### Filter: `wp_mcp_ai_chat_options`

Filters chat request options before sending to the AI provider.

**Parameters:**
- `array $options` — Chat options (model, temperature, max_tokens, etc.).
- `int $assistant_id` — Assistant post ID.
- `array $messages` — Conversation messages.
- `WP_REST_Request $request` — REST request.

**Returns:** `array` — Modified options.

**Example:**
```php
add_filter( 'wp_mcp_ai_chat_options', function( $options, $assistant_id ) {
    // Force a specific model for a particular assistant
    if ( 42 === $assistant_id ) {
        $options['model'] = 'gpt-4o';
    }
    return $options;
}, 10, 2 );
```

---

### Filter: `wp_mcp_ai_max_history_messages`

Filters the maximum number of conversation history messages sent to the AI.

**Parameters:**
- `int $max_count` — Maximum message count.
- `int $assistant_id` — Assistant post ID.
- `string $model` — AI model name.

**Returns:** `int` — Modified maximum.

**Fired in:** `class-wp-mcp-ai-rest.php:6251`

**Example:**
```php
add_filter( 'wp_mcp_ai_max_history_messages', function( $max_count, $assistant_id, $model ) {
    // Allow more history for models with large context windows
    if ( str_contains( $model, 'gpt-4.1' ) ) {
        return 100;
    }
    return $max_count;
}, 10, 3 );
```

---

### Filter: `wp_mcp_ai_chat_request_token_limit`

Filters the token limit for a chat request.

**Parameters:**
- `int $limit_tokens` — Token limit.
- `int $assistant_id` — Assistant post ID.
- `string $model` — AI model name.

**Returns:** `int` — Modified token limit.

**Fired in:** `class-wp-mcp-ai-rest.php:6303`

---

## Tool Execution Hooks

### Action: `wp_mcp_ai_register_tools`

Fires when the tool registry is ready for tool registration. Use this to register custom tools.

**Parameters:**
- `WP_MCP_AI_Tool_Registry $registry` — Tool registry instance.

**Fired in:** `class-wp-mcp-ai-tool-registry.php:102`

**Example:**
```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    require_once __DIR__ . '/class-my-custom-tool.php';
    $registry->register_tool( 'My_Custom_Tool' );
} );
```

---

### Action: `wp_mcp_ai_before_tool_execution`

Fires before a tool is executed in the REST API or agentic loop.

**Parameters:**
- `string $tool_slug` — Tool identifier.
- `array $arguments` — Prepared tool arguments.
- `array $context` — Execution context (user_id, assistant_id, etc.).

**Fired in:** `class-wp-mcp-ai-rest.php:4713`, `class-wp-mcp-ai-rest.php:9681`, `class-wp-mcp-ai-rest-tools-controller.php:651`

**Example:**
```php
add_action( 'wp_mcp_ai_before_tool_execution', function( $tool_slug, $arguments, $context ) {
    // Log all tool executions
    error_log( sprintf( 'Executing tool: %s for user %d', $tool_slug, $context['user_id'] ?? 0 ) );
}, 10, 3 );
```

---

### Action: `wp_mcp_ai_after_tool_execution`

Fires after a tool completes execution.

**Parameters:**
- `string $tool_slug` — Tool identifier.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.
- `mixed $result` — Tool execution result (array or WP_Error).

**Fired in:** `class-wp-mcp-ai-rest.php:4739`, `class-wp-mcp-ai-rest.php:9731`, `class-wp-mcp-ai-rest-tools-controller.php:677`

**Example:**
```php
add_action( 'wp_mcp_ai_after_tool_execution', function( $tool_slug, $arguments, $context, $result ) {
    if ( is_wp_error( $result ) ) {
        // Alert on tool failures
        error_log( sprintf( 'Tool %s failed: %s', $tool_slug, $result->get_error_message() ) );
    }
}, 10, 4 );
```

---

### Action: `wp_mcp_ai_before_tool_execute` (Trait-level)

Fires before tool execution within the WordPress native tool trait. More granular than `wp_mcp_ai_before_tool_execution`.

**Parameters:**
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.
- `string $tool_slug` — Tool identifier.

**Fired in:** `trait-wp-mcp-ai-tool-wordpress-native.php:193`

---

### Action: `wp_mcp_ai_after_tool_execute` (Trait-level)

Fires after tool execution within the WordPress native tool trait.

**Parameters:**
- `mixed $result` — Execution result.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.
- `string $tool_slug` — Tool identifier.

**Fired in:** `trait-wp-mcp-ai-tool-wordpress-native.php:229`

---

### Action: `wp_mcp_ai_tool_performance`

Fires after tool execution with performance timing data.

**Parameters:**
- `string $tool_slug` — Tool identifier.
- `float $execution_time` — Time in seconds.
- `array $arguments` — Tool arguments.

**Fired in:** `trait-wp-mcp-ai-tool-wordpress-native.php:380`

**Example:**
```php
add_action( 'wp_mcp_ai_tool_performance', function( $tool_slug, $execution_time ) {
    if ( $execution_time > 5.0 ) {
        error_log( sprintf( 'Slow tool: %s took %.2fs', $tool_slug, $execution_time ) );
    }
}, 10, 2 );
```

---

### Filter: `wp_mcp_ai_tool_output`

Filters tool output before it is returned to the AI.

**Parameters:**
- `mixed $result` — Tool execution result.
- `string $tool_slug` — Tool identifier.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.

**Returns:** `mixed` — Modified result.

**Fired in:** `class-wp-mcp-ai-rest.php:4722`

---

### Filter: `wp_mcp_ai_can_run_tool`

Filters whether a specific tool can be executed.

**Parameters:**
- `bool $can_run` — Whether the tool can run.
- `string $tool_slug` — Tool identifier.
- `array $context` — Execution context.

**Returns:** `bool` — Whether to allow execution.

---

## Agentic Loop Hooks

### Filter: `wp_mcp_ai_max_agentic_iterations`

Filters the maximum number of iterations in the agentic tool-calling loop.

**Parameters:**
- `int $max_iterations` — Default maximum iterations.
- `array $assistant_config` — Assistant configuration.

**Returns:** `int` — Modified maximum.

**Fired in:** `class-wp-mcp-ai-rest.php:2585`

**Example:**
```php
add_filter( 'wp_mcp_ai_max_agentic_iterations', function( $max, $config ) {
    // Allow more iterations for complex assistants
    if ( ! empty( $config['complex_tasks'] ) ) {
        return 20;
    }
    return $max;
}, 10, 2 );
```

---

### Filter: `wp_mcp_ai_agentic_tool_timeout`

Filters the timeout for tool execution during agentic iterations.

**Parameters:**
- `int $timeout` — Timeout in seconds.
- `string $tool_slug` — Tool being executed.

**Returns:** `int` — Modified timeout.

---

## Slash Command Hooks

### Action: `wp_mcp_ai_slash_commands_initialized`

Fires after the slash command system is fully initialized.

**Parameters:**
- `WP_MCP_AI_Slash_Command_Handler $handler` — Command handler instance.

**Fired in:** `slash-commands-init.php:54`

**Example:**
```php
add_action( 'wp_mcp_ai_slash_commands_initialized', function( $handler ) {
    // Register a custom slash command
    $handler->register( 'my-command', array(
        'handler'     => 'my_command_handler',
        'description' => __( 'My custom command', 'my-plugin' ),
        'usage'       => '/my-command [args]',
        'capability'  => 'edit_posts',
    ) );
} );
```

---

### Action: `wp_mcp_ai_slash_command_registered`

Fires when a slash command is registered.

**Parameters:**
- `string $command` — Command name.
- `array $config` — Command configuration.

**Fired in:** `class-wp-mcp-ai-slash-command-handler.php:130`

---

### Action: `wp_mcp_ai_default_slash_commands_loaded`

Fires after all default slash commands are loaded. Use this to register commands that should appear after built-in ones.

**Parameters:**
- `WP_MCP_AI_Slash_Command_Handler $handler` — Command handler instance.

**Fired in:** `slash-commands-init.php:351`

---

### Filter: `wp_mcp_ai_slash_command_authorized`

Filters whether a user is authorized to execute a slash command.

**Parameters:**
- `bool $authorized` — Whether authorized (default: result of capability check).
- `string $command` — Command name.
- `int $user_id` — WordPress user ID.
- `array $context` — Execution context.

**Returns:** `bool` — Whether to allow execution.

**Fired in:** `class-wp-mcp-ai-slash-command-handler.php:250`

---

### Filter: `wp_mcp_ai_slash_command_rate_limit`

Filters the rate limit for slash command execution.

**Parameters:**
- `int $limit` — Commands per minute (default: 10).
- `string $command` — Command name.
- `int $user_id` — WordPress user ID.

**Returns:** `int` — Modified limit.

**Fired in:** `class-wp-mcp-ai-slash-command-handler.php:297`

---

## Settings & Admin Hooks

### Action: `wp_mcp_ai_settings_saved`

Fires after plugin settings are saved.

**Parameters:**
- `array $merged_settings` — Final merged settings.
- `array $existing_settings` — Previous settings.
- `array $sanitized_new` — Newly sanitized settings.

**Fired in:** `class-wp-mcp-ai-settings-dashboard.php:873`

---

### Action: `wp_mcp_ai_bootstrapped`

Fires after the plugin is fully bootstrapped and all components are initialized.

**Fired in:** `class-wp-mcp-ai-plugin.php:363`

**Example:**
```php
add_action( 'wp_mcp_ai_bootstrapped', function() {
    // Safe to use any plugin API at this point
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
} );
```

---

### Filter: `wp_mcp_ai_admin_settings_sanitize`

Filters settings during sanitization before save.

**Parameters:**
- `array $settings` — Settings being saved.
- `string $section` — Settings section identifier.

**Returns:** `array` — Sanitized settings.

---

## Authentication Hooks

### Action: `wp_mcp_ai_authenticated_with_credential`

Fires when a request is authenticated using plugin-issued credentials.

**Parameters:**
- `array $validated` — Validation result (user_id, assistant_id, scopes).
- `WP_REST_Request $request` — REST request.

**Fired in:** `class-wp-mcp-ai-rest-authenticator.php:158`

---

### Filter: `wp_mcp_ai_pre_validate_bearer_token`

Allows short-circuiting bearer token validation (e.g., for JWT integration).

**Parameters:**
- `null|array $pre` — Return non-null to short-circuit (array with user_id, etc.).
- `string $token` — Bearer token.
- `WP_REST_Request $request` — REST request.

**Returns:** `null|array` — Null to continue normal validation, or validation result.

**Fired in:** `class-wp-mcp-ai-rest-authenticator.php:238`

**Example:**
```php
add_filter( 'wp_mcp_ai_pre_validate_bearer_token', function( $pre, $token, $request ) {
    // Validate tokens from external IdP
    if ( str_starts_with( $token, 'ext_' ) ) {
        $user_id = my_validate_external_token( $token );
        if ( $user_id ) {
            return array( 'user_id' => $user_id );
        }
    }
    return $pre;
}, 10, 3 );
```

---

## Token & Cost Management Hooks

### Action: `wp_mcp_ai_tool_token_limit_exceeded`

Fires when a user exceeds their daily tool token limit.

**Parameters:**
- `int $user_id` — WordPress user ID.
- `string $tool_slug` — Tool that triggered the limit.
- `int $daily_usage` — Current daily usage.
- `int $limit` — Configured limit.
- `int $reset_time` — Timestamp when limit resets.
- `string $tier` — User's tier name.

**Fired in:** `class-wp-mcp-ai-tool-token-limits.php:1260`

---

### Action: `wp_mcp_ai_usage_anomaly_detected`

Fires when unusual usage patterns are detected.

**Parameters:**
- `int $user_id` — WordPress user ID.
- `string $tool_slug` — Tool with anomalous usage.
- `int $tokens` — Current token count.
- `float $avg_hourly` — Average hourly usage.

**Fired in:** `class-wp-mcp-ai-tool-token-limits.php:2364`

---

### Action: `wp_mcp_ai_user_tier_changed`

Fires when a user's token tier is changed.

**Parameters:**
- `int $user_id` — WordPress user ID.
- `string $old_tier` — Previous tier.
- `string $tier` — New tier.
- `int $expires` — Expiration timestamp.

**Fired in:** `class-wp-mcp-ai-tool-token-limits.php:673`

---

## A2A & Orchestration Hooks

### Action: `wp_mcp_ai_a2a_before_task_create`

Fires before an Agent-to-Agent task is created.

**Parameters:**
- `array $task` — Task definition.

**Fired in:** `class-wp-mcp-ai-a2a-task-manager.php:115`

---

### Action: `wp_mcp_ai_a2a_task_state_change`

Fires when an A2A task changes state.

**Parameters:**
- `array $task` — Task data.
- `string $current_state` — Previous state.
- `string $new_state` — New state.

**Fired in:** `class-wp-mcp-ai-a2a-task-manager.php:186`

---

### Action: `wp_mcp_ai_a2a_webhook_task_update`

Fires when an A2A webhook receives a task update.

**Parameters:**
- `array $task` — Updated task data.

**Fired in:** `class-wp-mcp-ai-a2a-webhook-handler.php:73`

---

## Performance & Caching Hooks

### Action: `wp_mcp_ai_warm_cache`

Fires when cache warming is initiated.

**Parameters:**
- `string $cache_helper` — Cache helper class name.

**Fired in:** `class-wp-mcp-ai-cache-helper.php:421`

---

### Action: `wp_mcp_ai_performance_threshold_exceeded`

Fires when a performance benchmark exceeds its threshold.

**Parameters:**
- `string $identifier` — Benchmark identifier.
- `array $results` — Benchmark results.
- `float $threshold` — Exceeded threshold.

**Fired in:** `class-wp-mcp-ai-performance-benchmark.php:161`

---

### Filter: `wp_mcp_ai_cache_enabled`

Filters whether caching is enabled globally.

**Parameters:**
- `bool $enabled` — Whether caching is enabled.

**Returns:** `bool`

**Example:**
```php
add_filter( 'wp_mcp_ai_cache_enabled', function( $enabled ) {
    // Disable caching in development
    return ! ( defined( 'WP_DEBUG' ) && WP_DEBUG );
} );
```

---

## Plugin Lifecycle Hooks

### Action: `wp_mcp_ai_after_activation`

Fires after the plugin is activated.

**Fired in:** `bootstrap/activation.php:259`

---

### Action: `wp_mcp_ai_before_uninstall_cleanup`

Fires before plugin data is cleaned up during uninstall.

**Fired in:** `bootstrap/activation.php:367`

---

### Action: `wp_mcp_ai_toolkit_enhancement_initialized`

Fires after toolkit enhancement integration is initialized.

**Parameters:**
- `WP_MCP_AI_Toolkit_Enhancement_Integration $integration` — Integration instance.

**Fired in:** `class-wp-mcp-ai-toolkit-enhancement-integration.php:284`

---

## Email & Newsletter Hooks

### Action: `wp_mcp_ai_newsletter_subscriber_added`

Fires when a newsletter subscriber is added.

**Parameters:**
- `int $subscriber_id` — New subscriber ID.
- `array $subscriber_data` — Subscriber data.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.

**Fired in:** `class-wp-mcp-ai-tool-newsletter-add-subscriber.php:213`

---

### Action: `wp_mcp_ai_newsletter_email_created`

Fires when a newsletter email is created.

**Parameters:**
- `int $email_id` — Email ID.
- `array $email_data` — Email data.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.

**Fired in:** `class-wp-mcp-ai-tool-newsletter-create-email.php:241`

---

### Action: `wp_mcp_ai_send_group_email_after_send`

Fires after a group email is sent.

**Parameters:**
- `array $mail_args` — WordPress mail arguments.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.
- `array $email_request` — Original email request.
- `object $tool` — Tool instance.

**Fired in:** `class-wp-mcp-ai-tool-send-group-email.php:238`

---

## Crawler & Search Hooks

### Action: `wp_mcp_ai_crawl4ai_job_registered`

Fires when a Crawl4AI crawl job is registered.

**Parameters:**
- `string $task_id` — Task identifier.
- `array $job` — Job configuration.

**Fired in:** `class-wp-mcp-ai-crawler.php:163`

---

### Action: `wp_mcp_ai_crawl4ai_job_completed`

Fires when a crawl job completes successfully.

**Parameters:**
- `string $task_id` — Task identifier.
- `array $filtered` — Filtered crawl results.
- `array $job` — Job configuration.

**Fired in:** `class-wp-mcp-ai-crawler.php:255`

---

### Action: `wp_mcp_ai_web_search_completed`

Fires after a web search tool completes.

**Parameters:**
- `array $result` — Search results.
- `array $arguments` — Search arguments.
- `array $context` — Execution context.

**Fired in:** `class-wp-mcp-ai-tool-web-search.php:326`

---

## Video Generation Hooks

### Action: `wp_mcp_ai_sora_video_completed`

Fires when a Sora video generation completes.

**Parameters:**
- `array $final_result` — Generation result.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.

**Fired in:** `class-wp-mcp-ai-tool-generate-sora-video.php:638`

---

### Action: `wp_mcp_ai_veo_video_completed`

Fires when a Veo video generation completes.

**Parameters:**
- `array $final_result` — Generation result.
- `array $arguments` — Tool arguments.
- `array $context` — Execution context.

**Fired in:** `class-wp-mcp-ai-tool-generate-veo-video.php:362`

---

## Quick Reference: Common Patterns

### Injecting Context Into Every Chat Request

```php
add_action( 'wp_mcp_ai_before_chat_request', function( $assistant_id, $messages, $options ) {
    // This is the equivalent of Claude Code's "UserPromptSubmit" hook
    // You can modify messages, validate input, or log requests here
}, 10, 3 );
```

### Auto-Running Tests After Tool Edits

```php
add_action( 'wp_mcp_ai_after_tool_execution', function( $tool_slug, $args, $context, $result ) {
    if ( 'create_post' === $tool_slug && ! is_wp_error( $result ) ) {
        // Trigger content validation after post creation
        wp_schedule_single_event( time(), 'my_validate_post', array( $result['post_id'] ?? 0 ) );
    }
}, 10, 4 );
```

### Custom Tool Registration

```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    require_once __DIR__ . '/my-tools/class-my-tool.php';
    $registry->register_tool( 'My_Custom_Tool' );
} );
```

### Rate Limiting Specific Commands

```php
add_filter( 'wp_mcp_ai_slash_command_rate_limit', function( $limit, $command ) {
    // Strict rate limit on expensive commands
    if ( 'ship' === $command ) {
        return 2; // 2 per minute
    }
    return $limit;
}, 10, 2 );
```

### Model Fallback via Filters

```php
add_filter( 'wp_mcp_ai_chat_options', function( $options ) {
    // If the preferred model is unavailable, fall back
    if ( 'gpt-4o' === ( $options['model'] ?? '' ) ) {
        $options['fallback_model'] = 'gpt-4o-mini';
    }
    return $options;
} );
```

---

## Hook Naming Conventions

All hooks follow the pattern `wp_mcp_ai_{descriptive_name}`:

- **Actions** signal that something happened: `wp_mcp_ai_after_chat_response`
- **Filters** allow modification of data: `wp_mcp_ai_chat_options`
- **Dynamic hooks** include variable slugs: `wp_mcp_ai_tool_result_{$tool_slug}`

## Finding More Hooks

Search the codebase:
```bash
# Find all action hooks
grep -rn "do_action( 'wp_mcp_ai_" includes/

# Find all filter hooks
grep -rn "apply_filters( 'wp_mcp_ai_" includes/
```

---

**Total hooks:** 80+ actions, 460+ filters across the base plugin.
For Pro-specific hooks, see the Pro addon documentation.
