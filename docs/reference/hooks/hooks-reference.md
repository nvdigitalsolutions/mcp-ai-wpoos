# NV oOS Developer Hooks Reference

> **Comprehensive reference for all action and filter hooks in the NV oOS plugin.**
> Use hooks to extend, customize, or integrate with the plugin without modifying core code.
> Last reviewed: May 2026.

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
14. [Erlang C & Queue Operations Hooks](#erlang-c--queue-operations-hooks)
15. [Markup Subsystem Hooks](#markup-subsystem-hooks)
16. [LLM Harnessing Hooks](#llm-harnessing-subsystem-hooks)
17. [Chat Memory Bridge Hooks](#chat-memory-bridge-hooks)
18. [Transcript Mining Hooks](#transcript-mining-hooks)
19. [Async Chat Continuation Hooks](#async-chat-continuation-hooks)

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
- `array $descriptor` *(since v1.2.1, optional)* — Normalised lifecycle
  descriptor pre-derived from `$result`. Shape:
  `{ success: bool, error_code: ?string, data_type: ?string, duration_ms: ?float }`.
  Subscribers registered with `accepted_args = 4` ignore this parameter and
  continue to work unchanged. Subscribers that bump to `accepted_args = 5`
  receive the descriptor.

  - `success` — `true` for non-`WP_Error` results.
  - `error_code` — `WP_Error::get_error_code()` when failed, else `null`.
  - `data_type` — A coarse type label (`array`, `string`, `int`, `bool`,
    `float`, `null`, `object`, `generic`) for success results; if the
    tool's success array carries a `produces` field (see
    [Phase P3](proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md#3-implementation-phases)),
    that field is used instead.
  - `duration_ms` — Milliseconds elapsed between the `before_tool_execution`
    and `after_tool_execution` hooks, when the firing site captures a
    start timestamp; `null` otherwise (e.g. async-job completion fired
    from a different process than start).

  Build the descriptor via `WP_MCP_AI_Tool_Lifecycle_Descriptor::build()`
  if you fire this action from custom code. Filter the descriptor before
  dispatch via `wp_mcp_ai_tool_lifecycle_descriptor`.

**Fired in:** `class-wp-mcp-ai-rest.php` (sync tool dispatch + agentic loop),
`class-wp-mcp-ai-rest-tools-controller.php`,
`class-wp-mcp-ai-tool-async-executor.php` (async completion),
`class-wp-mcp-ai-gemini-video-generation-service.php` (Veo job completion).

**Example (legacy 4-arg subscriber — still supported):**
```php
add_action( 'wp_mcp_ai_after_tool_execution', function( $tool_slug, $arguments, $context, $result ) {
    if ( is_wp_error( $result ) ) {
        // Alert on tool failures
        error_log( sprintf( 'Tool %s failed: %s', $tool_slug, $result->get_error_message() ) );
    }
}, 10, 4 );
```

**Example (new 5-arg subscriber using the descriptor):**
```php
add_action( 'wp_mcp_ai_after_tool_execution', function( $tool_slug, $arguments, $context, $result, $descriptor ) {
    if ( ! $descriptor['success'] ) {
        my_metrics_counter( 'tool.failure', 1, array(
            'tool'  => $tool_slug,
            'error' => $descriptor['error_code'],
        ) );
        return;
    }
    if ( isset( $descriptor['duration_ms'] ) ) {
        my_metrics_histogram( 'tool.duration_ms', $descriptor['duration_ms'], array(
            'tool'      => $tool_slug,
            'data_type' => $descriptor['data_type'],
        ) );
    }
}, 10, 5 );
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

## Erlang C & Queue Operations Hooks

### Action: `wp_mcp_ai_queue_alert`

Fires when the `erlang_c_queue_health` tool detects that the live service-level percentage has dropped below the configured threshold. Use this to send Slack/Teams notifications, trigger auto-scaling, or log SLA-breach events.

**Parameters:**
- `array $snapshot` — Current queue snapshot including:
  - `float $snapshot['service_level_pct']` — Achieved service level (0–100).
  - `int $snapshot['agents_available']` — Current available agents.
  - `int $snapshot['queue_depth']` — Current calls/chats waiting.
  - `float $snapshot['avg_wait_time_seconds']` — Predicted average wait.
  - `float $snapshot['threshold_pct']` — The SLA threshold that was breached.
  - `int $snapshot['assistant_id']` — Assistant post ID that triggered the check.

**Fired in:** `class-wp-mcp-ai-tool-erlang-c-queue-health.php` (when `service_level_pct < threshold_pct`)

**Example:**
```php
add_action( 'wp_mcp_ai_queue_alert', function( $snapshot ) {
    $msg = sprintf(
        'Queue SLA alert: %.0f%% (threshold %.0f%%). Queue depth: %d. Avg wait: %.0fs.',
        $snapshot['service_level_pct'],
        $snapshot['threshold_pct'],
        $snapshot['queue_depth'],
        $snapshot['avg_wait_time_seconds']
    );
    // Send to Slack, Teams, PagerDuty, etc.
    wp_remote_post( get_option( 'my_slack_webhook' ), array( 'body' => wp_json_encode( array( 'text' => $msg ) ) ) );
}, 10, 1 );
```

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

**Total hooks:** 80+ actions, 460+ filters across the base plugin. Includes `wp_mcp_ai_queue_alert` (Erlang C SLA breach) added in v1.1.8.
For Pro-specific hooks, see the Pro addon documentation.

---

## Model Catalog Hooks (April 2026)

### Filter: `wp_mcp_ai_model_catalog`
Modifies the array of model entries loaded from `includes/data/model-catalog.json`. Each entry is an associative array with keys: `model_name`, `provider`, `name`, `tpm`, `rpm`, `tpd`, `rpd`, `context_window`, `max_output_tokens`, `supports_function_calling`, `supports_vision`, `cost_per_1k_input_tokens`, `cost_per_1k_output_tokens`, `fallback_model`, `status` (`active`/`deprecated`/`legacy`), `sunset_date`, `notes`.

```php
add_filter( 'wp_mcp_ai_model_catalog', function ( $catalog ) {
    $catalog[] = array(
        'model_name'                => 'my-custom-llm',
        'provider'                  => 'openai',
        'name'                      => 'My Custom LLM',
        'cost_per_1k_input_tokens'  => 0.001,
        'cost_per_1k_output_tokens' => 0.002,
        'status'                    => 'active',
    );
    return $catalog;
} );
```

After modifying, call `WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache()` to invalidate the cache, or click "Reload model catalog" in the Models settings page.

### Filter: `wp_mcp_ai_model_catalog_source_path`
Override the on-disk JSON path the loader reads. Defaults to `includes/data/model-catalog.json` (with auto-detection of `wp-content/uploads/mcp-ai/model-catalog.json` if present).

### Filter: `wp_mcp_ai_model_discovery_enabled`
Default `true`. Return `false` to suppress the daily discovery cron.

### Filter: `wp_mcp_ai_model_discovery_interval`
Default `'daily'`. Accepts any registered schedule slug (`'hourly'`, `'twicedaily'`, `'daily'`, `'weekly'`).

### Action: `wp_mcp_ai_model_catalog_suggestions_updated`
Fires after `wp_mcp_ai_model_catalog_discovery` produces a fresh diff. Receives the diff payload (`additions`, `sunsets`, `price_changes`, `errors`, `status`, `generated_at`).

```php
add_action( 'wp_mcp_ai_model_catalog_suggestions_updated', function ( $diff ) {
    // Notify ops channel, auto-apply additions, etc.
} );
```

---

## Markup Subsystem Hooks

The markup subsystem (introduced in 1.3.0) lets tools pause the agentic loop, ask the user to draw on an image / region, and resume with the rasterised result. See `docs/markup-subsystem.md` for the end-to-end flow.

### Filter: `wp_mcp_ai_markup_enabled`
Master kill-switch, evaluated by `WP_MCP_AI_Markup_Loop_Interceptor::is_enabled()`. Default `true`. Return `false` to disable the loop interceptor entirely (existing pending requests still resolve through the REST controller, but no new ones are created).

```php
add_filter( 'wp_mcp_ai_markup_enabled', function ( $enabled ) {
    if ( wp_is_maintenance_mode() ) {
        return false;
    }
    return $enabled;
} );
```

### Action: `wp_mcp_ai_markup_request_created`
Fires when the loop interceptor has persisted a fresh request and is about to short-circuit the tool call back to the chat surface as a `markup_elicitation` SSE event.

| Argument | Type | Description |
|----------|------|-------------|
| `$request` | `WP_MCP_AI_Markup_Request` | Immutable request value object — `request_id`, `tool_slug`, `mode`, `target_type`, `target`, `context`, `expires_at`. |
| `$tool` | `object\|null` | The tool instance that triggered the request, or `null` for non-class invocations. |

```php
add_action( 'wp_mcp_ai_markup_request_created', function ( $request, $tool ) {
    error_log( 'Markup request created for tool: ' . $request->get_tool_slug() );
}, 10, 2 );
```

### Action: `wp_mcp_ai_markup_submitted`
Fires inside `POST /markup/{request_id}` after the request has been loaded but before annotation validation runs. Use this for audit logging — the payload is still untrusted at this point.

| Argument | Type | Description |
|----------|------|-------------|
| `$record` | `WP_MCP_AI_Markup_Request` | The pending request being resolved. |

### Action: `wp_mcp_ai_markup_validated`
Fires once the W3C Web Annotation envelope has passed `WP_MCP_AI_Markup_Validator::validate()` and is safe to consume. Receives the validator's normalised output.

| Argument | Type | Description |
|----------|------|-------------|
| `$record` | `WP_MCP_AI_Markup_Request` | The request being resolved. |
| `$cleaned` | `array` | Validator-normalised annotation array. |

### Action: `wp_mcp_ai_markup_resolved`
Fires exactly once per request lifecycle to indicate terminal state. The recorder + slash command + admin dashboard all subscribe to this hook via the `WP_MCP_AI_Markup_Telemetry` aggregator.

| Argument | Type | Description |
|----------|------|-------------|
| `$record` | `WP_MCP_AI_Markup_Request` | The request being resolved. |
| `$outcome` | `string` | One of `completed`, `cancelled`, `invalid`, `tool_error`. |

```php
add_action( 'wp_mcp_ai_markup_resolved', function ( $record, $outcome ) {
    if ( 'completed' !== $outcome ) {
        my_alerting_service_notify( $record->get_tool_slug(), $outcome );
    }
}, 10, 2 );
```

### Filter: `wp_mcp_ai_markup_widget_payload`
Mutates the SSE payload sent to the chat canvas widget. Useful for adding tenant-specific defaults, branding strings, or extra widget hints.

| Argument | Type | Description |
|----------|------|-------------|
| `$payload` | `array` | Widget payload — `request_id`, `mode`, `target_type`, `target`, `widget`, `expires_at`. |
| `$request` | `WP_MCP_AI_Markup_Request` | The request being broadcast. |

### Filter: `wp_mcp_ai_markup_mcp_elicitation`
Mutates the MCP `elicitation/create` envelope sent to external MCP clients. Mirrors `wp_mcp_ai_markup_widget_payload` but for the over-the-wire MCP shape.

| Argument | Type | Description |
|----------|------|-------------|
| `$elicitation` | `array` | MCP elicitation payload. |
| `$request` | `WP_MCP_AI_Markup_Request` | The request being broadcast. |

### Filter: `wp_mcp_ai_markup_rasterized_artifacts`
Mutates the artifacts produced by `WP_MCP_AI_Markup_Rasterizer::rasterize()` before they are passed to the tool's `execute()` method as the `markup_artifacts` context entry. Use this to attach extra files (e.g. provenance manifests) or strip mask attachments before tool consumption.

| Argument | Type | Description |
|----------|------|-------------|
| `$artifacts` | `array` | Rasteriser output — `mask_attachment_id`, `crop_box`, `region_polygon`, etc. |
| `$annotation` | `array` | Validated annotation that produced the artifacts. |
| `$request` | `WP_MCP_AI_Markup_Request` | The request being resolved. |

### Filter: `wp_mcp_ai_recent_activity_types`
Not markup-specific, but the markup-init bootstrap appends `markup_created`, `markup_submitted`, `markup_validated`, `markup_completed`, `markup_cancelled`, `markup_invalid`, and `markup_tool_error` to this list so the activity feed surfaces markup events when logging is enabled.

---

## LLM Harnessing Subsystem Hooks

The LLM harnessing subsystem (`includes/harness/`) exposes hooks for every layer so addons can override prompt selection, scoring, retrieval ranking, and PII filtering without touching the base implementation.

### Action: `wp_mcp_ai_register_prompt_cues`

Fires the first time the `WP_MCP_AI_Prompt_Cue_Library` is touched, after the seven default cues are seeded. Register additional cue templates here.

| Argument | Type | Description |
|----------|------|-------------|
| `$library` | `WP_MCP_AI_Prompt_Cue_Library` | Library singleton. Call `register()` to add cues. |

```php
add_action( 'wp_mcp_ai_register_prompt_cues', function ( $library ) {
    $library->register( array(
        'slug'         => 'show_your_work',
        'label'        => 'Show Your Work',
        'description'  => 'Require explicit derivation for math problems.',
        'template'     => 'For every numeric step, show the derivation, not just the result.',
        'task_classes' => array( 'math' ),
    ) );
} );
```

### Filter: `wp_mcp_ai_select_prompt_cue`

Selects the cue slug applied for a given task class. Return an empty string to apply no cue.

| Argument | Type | Description |
|----------|------|-------------|
| `$cue_slug` | `string` | Default cue slug (first registered cue for the task class). |
| `$task_class` | `string` | Task class slug (`math`, `code`, `qa`, `rag`, `research`, `agentic`, `general`). |
| `$assistant_id` | `int` | Assistant post ID, or `0` for global. |
| `$model` | `string` | Model identifier (e.g. `gpt-4o`). |

### Filter: `wp_mcp_ai_harness_profile`

Mutates the resolved per-assistant harness profile after sanitization. Use this to enforce site-wide policy (e.g. force `cost_ceiling_usd` to a fixed value).

| Argument | Type | Description |
|----------|------|-------------|
| `$profile` | `array` | Sanitized harness profile. |
| `$assistant_id` | `int` | Assistant post ID (`0` = global). |

### Filter: `wp_mcp_ai_harness_tool_score`

Mutates the score the Tool Router computes for a candidate tool against a task class. Pro overrides this with a learned model.

| Argument | Type | Description |
|----------|------|-------------|
| `$score` | `float` | Default score from the base scoring rules. |
| `$tool` | `WP_MCP_AI_Tool_Interface` | Tool instance. |
| `$task_class` | `string` | Task class. |
| `$assistant_prefs` | `array` | Per-assistant tool preferences (slug → weight). |
| `$preset_weights` | `array` | Resolved preset-family weight map (`preset_slug → float [-5, 5]`). |

### Filter: `wp_mcp_ai_harness_eval_generator`

Supplies the eval generator callable used by the eval scheduler to produce test cases for a given eval suite. Return `null` (the default) to skip the suite without logging an error.

| Argument | Type | Description |
|----------|------|-------------|
| `$generator` | `callable\|null` | Default `null` — no built-in generator; Pro registers its own. |
| `$suite_slug` | `string` | Eval suite slug. |
| `$assistant_id` | `int` | Assistant post ID. |

### Action: `wp_mcp_ai_harness_eval_tick`

WP-Cron action hook fired by `WP_MCP_AI_Harness_Eval_Scheduler` on its daily schedule. Receives no arguments; the scheduler discovers eligible assistants internally and dispatches suites via `wp_mcp_ai_harness_eval_generator`.

---

## Chat Memory Bridge Hooks

The chat-client memory bridge (`WP_MCP_AI_REST_Chat_Memory_Controller` + `assets/js/chat-memory-drawer.js`) exposes hooks so developers can gate or modify memory bridge behaviour.

### Filter: `wp_mcp_ai_chat_memory_enabled`

Site-wide kill-switch for the chat-client memory bridge. Return `false` to disable all `/mcp-ai/v1/chat-memory/*` routes and the Memory Drawer for every user.

| Argument | Type | Description |
|----------|------|-------------|
| `$enabled` | `bool` | Default `true`. |
| `$user_id` | `int` | The requesting user ID (available from v1.x context). |

```php
// Disable chat memory on GDPR opt-out.
add_filter( 'wp_mcp_ai_chat_memory_enabled', function ( $enabled, $user_id ) {
    return ! user_has_opted_out_of_memory( $user_id );
}, 10, 2 );
```

---

## Transcript Mining Hooks

The retroactive transcript mining subsystem (`WP_MCP_AI_Transcript_Mining_Job` + `mine_agent_memory` `transcripts` source) exposes filters for session selection, message processing, and de-duplication.

### Filter: `wp_mcp_ai_mine_transcripts_sessions`

Mutates the session list before retroactive transcript mining begins. Useful for excluding specific session types (e.g. guest sessions) or injecting additional keys.

| Argument | Type | Description |
|----------|------|-------------|
| `$sessions` | `array` | Session key list (may include `'__auto__'` sentinel). |
| `$query_args` | `array` | Raw `transcript_query` args from the job or tool call. |

### Filter: `wp_mcp_ai_mine_transcripts_session_messages`

Mutates the raw message array for a session before item extraction. Use this for redaction or filtering.

| Argument | Type | Description |
|----------|------|-------------|
| `$messages` | `array` | Message objects for the session. |
| `$session_key` | `string` | Session key. |

### Filter: `wp_mcp_ai_mine_transcripts_dedupe_scan_limit`

Overrides the number of most-recent memories scanned for de-duplication during `mine_agent_memory` with `source=transcripts`.

| Argument | Type | Description |
|----------|------|-------------|
| `$limit` | `int` | Default `1000`. |

### Filter: `wp_mcp_ai_pro_curriculum_per_case_char_cap`

(Pro) Overrides the maximum character count for a single fine-tune curriculum case exported by `WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum`.

| Argument | Type | Description |
|----------|------|-------------|
| `$cap` | `int` | Default `16000`. |
| `$suite_slug` | `string` | Eval suite slug. |

### Filter: `wp_mcp_ai_retrieval_passages`

Mutates the final ranked passages produced by `WP_MCP_AI_Retrieval_Harness::retrieve()` before they leave the harness. Useful for deduplicating against an external index or enforcing per-passage redaction.

| Argument | Type | Description |
|----------|------|-------------|
| `$passages` | `array` | Top-k passages (each with `text`, `source`, `score`, `freshness`, `citation`). |
| `$query` | `string` | Original query string. |
| `$scope` | `array` | Scope hash (`wing`, `room`, `assistant_id`, `task_class`). |
| `$context` | `array` | Tool execution context. |

### Filter: `wp_mcp_ai_retrieval_claim_supported`

Per-sentence predicate used by `WP_MCP_AI_Retrieval_Harness::verify_citations()`. Default implementation requires a shared 5-gram between the sentence and at least one passage. Override to substitute a semantic check.

| Argument | Type | Description |
|----------|------|-------------|
| `$is_supported` | `bool` | Default decision. |
| `$sentence` | `string` | Sentence under test. |
| `$passages` | `array` | Passage list. |

### Filter: `wp_mcp_ai_pii_filter_patterns`

Adds or replaces the regex patterns used by `WP_MCP_AI_Pii_Filter::scrub()`. Each entry is an array of `[ regex, replacement_token ]`. Defaults cover emails, phones, SSNs, credit cards, and common API key prefixes.

| Argument | Type | Description |
|----------|------|-------------|
| `$patterns` | `array` | Default patterns. |

```php
add_filter( 'wp_mcp_ai_pii_filter_patterns', function ( $patterns ) {
    // Redact internal ticket IDs before persistence.
    $patterns[] = array( '/JIRA-\d{3,}/', '[REDACTED_TICKET]' );
    return $patterns;
} );
```

## Scheduled Result Widget (Pro)

### Filter: `wp_mcp_ai_pro_schedule_result_envelope`

Last chance to shape the structured result envelope produced by a Pro
schedule run before it is persisted. Useful to e.g. coerce an assistant_run
response into `data.items` so the Scheduled Result widget renders a list.

| Argument | Type | Description |
|----------|------|-------------|
| `$envelope` | `array` | `{ summary, data, render, status, error, generated_at }`. |
| `$schedule` | `array` | Schedule record. |
| `$action_log` | `array` | Dispatcher's structured action log. |
| `$success` | `bool` | Whether the run succeeded. |

### Filter: `wp_mcp_ai_pro_schedule_public_result`

Last chance to redact the envelope returned to **unauthenticated** REST
callers / front-end renders. Runs after the built-in
`public_fields` allow-list has been applied.

| Argument | Type | Description |
|----------|------|-------------|
| `$redacted` | `array` | The redacted envelope. |
| `$envelope` | `array` | The full envelope. |
| `$schedule` | `array` | Schedule record. |

### Filter: `wp_mcp_ai_pro_schedule_result_retention`

Override the per-schedule retention count for stored result envelopes.

| Argument | Type | Description |
|----------|------|-------------|
| `$retention` | `int` | Default retention (clamped 1–100). |
| `$schedule` | `array` | Schedule record. |

### Filter: `wp_mcp_ai_pro_schedule_result_capability`

Override the WordPress capability required by the authenticated
`/mcp-ai-pro/v1/schedules/{id}/latest-result` and `/results` routes.
Default is `read_private_posts`.

### Action: `wp_mcp_ai_pro_schedule_result_recorded`

Fires immediately after a structured result envelope is stored.

| Argument | Type | Description |
|----------|------|-------------|
| `$schedule_id` | `string` | Schedule ID. |
| `$envelope` | `array` | The persisted envelope. |

---

## Async Chat Continuation Hooks

The chat continuation subsystem (`WP_MCP_AI_Chat_Continuation_Store` +
`WP_MCP_AI_Chat_Continuation_Dispatcher`) lets the chat session that
started an async tool job receive a fresh LLM follow-up when the job
finishes. See `docs/features/chat/async-continuation.md` for the full
architecture.

### Filter: `wp_mcp_ai_chat_session_id_generated`

Override the UUID v4 generator used to mint a chat session identifier
when no session_key is supplied by the client.

| Argument | Type | Description |
|----------|------|-------------|
| `$session_id` | `string` | Default UUID v4. |
| `$context` | `array` | `{ assistant_id, user_id }`. |

### Filter: `wp_mcp_ai_chat_continuation_enabled`

Site-wide kill switch. Return `false` to disable storing snapshots and
dispatching continuations on job completion.

| Argument | Type | Description |
|----------|------|-------------|
| `$enabled` | `bool` | Default `true`. |
| `$job_id` | `string` | Async job identifier. |
| `$terminal_status` | `string` | `completed`, `failed`, or `cancelled`. |

### Filter: `wp_mcp_ai_chat_continuation_should_dispatch`

Late opt-out for a specific continuation (useful for HITL approvals or
sub-agent dispatchers that wish to handle the resume themselves).

| Argument | Type | Description |
|----------|------|-------------|
| `$should_dispatch` | `bool` | Default `true`. |
| `$snapshot` | `array` | Continuation snapshot. |
| `$terminal_status` | `string` | `completed`, `failed`, `cancelled`. |
| `$result` | `array` | Job result data. |

### Filter: `wp_mcp_ai_chat_continuation_message`

Rewrite the tool-result message that the dispatcher appends to the
conversation before LLM re-entry.

| Argument | Type | Description |
|----------|------|-------------|
| `$message` | `array` | Constructed OpenAI tool message. |
| `$snapshot` | `array` | Continuation snapshot. |
| `$terminal_status` | `string` | `completed`, `failed`, `cancelled`. |
| `$result` | `array` | Job result data. |

### Filter: `wp_mcp_ai_chat_continuation_ttl`

Override snapshot TTL (default `DAY_IN_SECONDS`).

### Filter: `wp_mcp_ai_chat_continuation_max_total`

Override the site-wide LRU cap (default `500`).

### Filter: `wp_mcp_ai_chat_continuation_max_per_session`

Override the per-session continuation cap (default `32`).

### Filter: `wp_mcp_ai_chat_continuation_max_messages_size`

Override the maximum serialized size (bytes) of `messages[]` in a
snapshot (default `524288`).

### Filter: `wp_mcp_ai_chat_continuation_cron_delay`

Override the delay (seconds) before the cron worker is scheduled
(default `1`).

### Action: `wp_mcp_ai_chat_continuation_stored`

Fires after a continuation snapshot has been persisted by the REST
chat handler at the `async_pending` exit point.

| Argument | Type | Description |
|----------|------|-------------|
| `$job_id` | `string` | Async job identifier. |
| `$snapshot` | `array` | Normalized snapshot payload. |

### Action: `wp_mcp_ai_chat_continuation_ready`

Fires from the cron worker after the tool-result message has been
appended to the conversation history. This is the seam where the
forthcoming LLM re-entry path (`WP_MCP_AI_REST::resume_chat_after_job()`)
will attach.

| Argument | Type | Description |
|----------|------|-------------|
| `$snapshot` | `array` | Continuation snapshot (with `terminal_*` fields and the appended tool message). |
| `$terminal_status` | `string` | `completed`, `failed`, `cancelled`. |
| `$terminal_result` | `array` | Job result data. |

### Action: `wp_mcp_ai_chat_continuation_dispatched`

Fires after the continuation has been driven to completion. Canonical
observability hook (parity with the OTel signal pattern used in
`cron-status`).

| Argument | Type | Description |
|----------|------|-------------|
| `$job_id` | `string` | Async job identifier. |
| `$snapshot` | `array` | Continuation snapshot. |
| `$terminal_status` | `string` | `completed`, `failed`, `cancelled`. |
