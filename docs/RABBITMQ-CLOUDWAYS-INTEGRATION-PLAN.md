# RabbitMQ Integration Plan for WP oOS on Cloudways

**Version:** 1.0.0  
**Last Updated:** November 27, 2025  
**Status:** Planning Document

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Background & Motivation](#background--motivation)
3. [RabbitMQ on Cloudways](#rabbitmq-on-cloudways)
4. [Architecture Overview](#architecture-overview)
5. [Agentic Workflow Enhancements](#agentic-workflow-enhancements)
6. [Tool Management Enhancements](#tool-management-enhancements)
7. [Implementation Plan](#implementation-plan)
8. [Configuration](#configuration)
9. [Security Considerations](#security-considerations)
10. [Monitoring & Debugging](#monitoring--debugging)
11. [Migration Strategy](#migration-strategy)
12. [Future Considerations](#future-considerations)

---

## Executive Summary

This document outlines the plan to integrate RabbitMQ message queuing into WP Open Operator System (WP oOS) when deployed on Cloudways hosting. RabbitMQ provides enterprise-grade message queuing that will significantly enhance:

1. **Agentic Workflow Processing** - Async tool execution, parallel processing, and improved resilience
2. **Tool Management** - Decoupled tool execution, retry logic, and workload distribution

### Key Benefits

| Feature | Current State | With RabbitMQ |
|---------|--------------|---------------|
| Tool Execution | Synchronous, blocks PHP | Asynchronous, non-blocking |
| Long-running Tasks | Timeout risk | Background processing |
| Parallel Tools | Sequential execution | Parallel execution |
| Error Recovery | Single attempt | Retry queues with backoff |
| Scaling | Single process | Multiple workers |
| Monitoring | PHP logs only | Full message observability |

---

## Background & Motivation

### Current Limitations

WP oOS currently relies on WordPress's synchronous request model and WP-Cron for async operations. This creates challenges:

1. **PHP Execution Limits** - Long-running tool executions risk timeouts
2. **Sequential Processing** - Tools execute one-by-one, even when independent
3. **Resource Contention** - High-traffic scenarios cause API bottlenecks
4. **Limited Retry Logic** - Failed tool calls require manual intervention
5. **WP-Cron Limitations** - Virtual cron is unreliable without traffic

### Why RabbitMQ?

RabbitMQ is an enterprise message broker that solves these challenges:

- **Persistent Queues** - Messages survive server restarts
- **Acknowledgments** - Guaranteed delivery and processing
- **Dead Letter Queues** - Failed messages are preserved for debugging
- **Multiple Exchange Types** - Flexible routing patterns
- **Management UI** - Visual monitoring and debugging
- **Cloudways Native** - Pre-configured and optimized for Cloudways

---

## RabbitMQ on Cloudways

### Enabling RabbitMQ

According to [Cloudways documentation](https://support.cloudways.com/en/articles/8680154-how-to-enable-rabbitmq-on-cloudways):

1. Navigate to **Server Management → Settings & Packages**
2. Enable **RabbitMQ** under Advanced Settings
3. Note the credentials:
   - Host: `localhost` (internal to server)
   - Port: `5672` (AMQP protocol)
   - Management Port: `15672`
   - Virtual Host: `/`
   - Default user/password provided by Cloudways

### CLI Commands

From [RabbitMQ CLI documentation](https://www.rabbitmq.com/docs/cli):

```bash
# Status and health
rabbitmqctl status
rabbitmqctl list_queues
rabbitmqctl list_exchanges

# Queue management
rabbitmqctl purge_queue queue_name
rabbitmqctl delete_queue queue_name

# User management
rabbitmqctl add_user username password
rabbitmqctl set_permissions -p / username ".*" ".*" ".*"
```

---

## Architecture Overview

### System Components

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              WP oOS Plugin                                   │
│                                                                              │
│  ┌──────────────────┐     ┌──────────────────┐     ┌─────────────────────┐ │
│  │   REST API       │────▶│   Chat Service   │────▶│   Tool Registry     │ │
│  │   /chat-client   │     │   (Agentic Loop) │     │   (65+ Tools)       │ │
│  └──────────────────┘     └──────────────────┘     └─────────────────────┘ │
│                                   │                          │              │
│                                   ▼                          ▼              │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                     RabbitMQ Message Manager                          │  │
│  │  - Queue Management                                                    │  │
│  │  - Message Publishing                                                  │  │
│  │  - Connection Pooling                                                  │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            RabbitMQ Server                                   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │  Exchanges                                                               ││
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────┐ ││
│  │  │ wp_mcp_ai.tools │  │ wp_mcp_ai.chat  │  │ wp_mcp_ai.deadletter    │ ││
│  │  │ (direct)        │  │ (topic)         │  │ (fanout)                │ ││
│  │  └─────────────────┘  └─────────────────┘  └─────────────────────────┘ ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │  Queues                                                                  ││
│  │  ┌───────────────────────┐  ┌────────────────────────────────────────┐ ││
│  │  │ tool.execution        │  │ tool.execution.priority.high           │ ││
│  │  │ (default priority)    │  │ (real-time tools)                      │ ││
│  │  └───────────────────────┘  └────────────────────────────────────────┘ ││
│  │  ┌───────────────────────┐  ┌────────────────────────────────────────┐ ││
│  │  │ tool.execution.async  │  │ tool.results                           │ ││
│  │  │ (background tasks)    │  │ (tool response collection)             │ ││
│  │  └───────────────────────┘  └────────────────────────────────────────┘ ││
│  │  ┌───────────────────────┐  ┌────────────────────────────────────────┐ ││
│  │  │ agentic.workflow      │  │ deadletter.queue                       │ ││
│  │  │ (workflow tracking)   │  │ (failed messages)                      │ ││
│  │  └───────────────────────┘  └────────────────────────────────────────┘ ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Background Workers                                   │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────┐ │
│  │  Tool Worker    │  │  Async Worker   │  │  Retry Worker               │ │
│  │  (WP-CLI based) │  │  (Long-running) │  │  (Dead letter processing)   │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Message Flow

```
User Request ──▶ REST API ──▶ Chat Service
                                    │
                                    ▼
                           Agentic Loop Iteration
                                    │
                              Tool Call Needed?
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               │               ▼
              Quick Tools           │          Async Tools
              (< 2 sec)             │          (> 2 sec)
                    │               │               │
                    ▼               │               ▼
              Direct Exec           │       Publish to Queue
                    │               │               │
                    ▼               │               ▼
               Response             │        Worker Processes
                    │               │               │
                    └───────────────┼───────────────┘
                                    ▼
                           Collect Results
                                    │
                                    ▼
                           Continue Loop or Respond
```

---

## Agentic Workflow Enhancements

### 1. Parallel Tool Execution

**Current Behavior:** Tools execute sequentially in the agentic loop.

**Enhanced Behavior:** Independent tools execute in parallel via RabbitMQ.

```php
// Before: Sequential execution
foreach ( $tool_calls as $tool_call ) {
    $result = $this->execute_tool( $tool_call );
    $results[] = $result;
}

// After: Parallel execution via RabbitMQ
$job_ids = array();
foreach ( $tool_calls as $tool_call ) {
    if ( $this->can_parallelize( $tool_call ) ) {
        $job_ids[] = $this->queue_tool_execution( $tool_call );
    }
}
$results = $this->await_results( $job_ids, $timeout );
```

### 2. Priority-Based Tool Routing

Different tools have different latency requirements:

| Priority | Queue | Tools | Timeout |
|----------|-------|-------|---------|
| High | `tool.execution.priority.high` | get_current_time, get_site_summary | 2s |
| Normal | `tool.execution` | search_content, get_recent_posts | 10s |
| Low | `tool.execution.async` | run_crawl4ai_job, generate_openai_image | 300s |

### 3. Workflow State Persistence

Track agentic workflow state in RabbitMQ for resilience:

```php
$workflow_message = array(
    'workflow_id'     => wp_generate_uuid4(),
    'assistant_id'    => $assistant_id,
    'iteration'       => $current_iteration,
    'max_iterations'  => $max_iterations,
    'messages'        => $messages,
    'pending_tools'   => $pending_tool_calls,
    'completed_tools' => $completed_results,
    'created_at'      => current_time( 'mysql' ),
    'user_id'         => get_current_user_id(),
);
```

### 4. Retry Logic with Exponential Backoff

```php
$retry_config = array(
    'max_attempts'    => 3,
    'initial_delay'   => 1000,  // 1 second
    'multiplier'      => 2,     // Exponential backoff
    'max_delay'       => 30000, // 30 seconds max
    'dead_letter_ttl' => 86400, // 24 hours
);
```

### 5. Real-time Progress Updates

Use RabbitMQ + SSE for real-time workflow progress:

```
User                Chat Service           RabbitMQ              Workers
 │                       │                     │                     │
 │   Send Message        │                     │                     │
 │─────────────────────▶│                     │                     │
 │                       │   Publish Tools     │                     │
 │                       │───────────────────▶│                     │
 │                       │                     │   Consume & Execute │
 │                       │                     │───────────────────▶│
 │   SSE: Tool Started   │                     │                     │
 │◀─────────────────────│◀─ ─ ─ ─ ─ ─ ─ ─ ─ ─ │◀─ ─ ─ ─ ─ ─ ─ ─ ─ ─│
 │                       │                     │   Publish Result    │
 │                       │                     │◀────────────────────│
 │   SSE: Tool Complete  │                     │                     │
 │◀─────────────────────│◀────────────────────│                     │
 │                       │   Collect Results   │                     │
 │   Final Response      │                     │                     │
 │◀─────────────────────│                     │                     │
```

---

## Tool Management Enhancements

### 1. Tool Registration with Queue Metadata

Extend tool interface to include queue preferences:

```php
interface WP_MCP_AI_Tool_Queue_Interface {
    /**
     * Get queue configuration for this tool.
     *
     * @return array Queue configuration.
     */
    public function get_queue_config();
}

// Example implementation
class WP_MCP_AI_Tool_Run_Crawl4AI_Job implements WP_MCP_AI_Tool_Queue_Interface {
    public function get_queue_config() {
        return array(
            'queue'          => 'tool.execution.async',
            'priority'       => 'low',
            'timeout'        => 300,
            'max_retries'    => 3,
            'requires_queue' => true,  // Must use queue, not sync
        );
    }
}
```

### 2. Tool Capability Flags Extension

Add new capability flags for queue behavior:

```php
public function get_capability_flags() {
    return array(
        'async',              // Existing: Can run asynchronously
        'queue-required',     // NEW: Must use message queue
        'queue-preferred',    // NEW: Prefer queue if available
        'parallelizable',     // NEW: Can run in parallel with others
        'idempotent',         // NEW: Safe to retry on failure
        'stateless',          // NEW: No side effects
    );
}
```

### 3. Tool Result Caching with RabbitMQ

Cache idempotent tool results in RabbitMQ:

```php
// Check cache before execution
$cache_key = $this->get_tool_cache_key( $tool_name, $arguments );
$cached_result = $this->rabbitmq->get_cached_result( $cache_key );

if ( $cached_result && ! $this->is_expired( $cached_result ) ) {
    return $cached_result['data'];
}

// Execute and cache
$result = $this->execute_tool( $tool_name, $arguments );
$this->rabbitmq->cache_result( $cache_key, $result, $ttl );
```

### 4. Tool Execution Orchestrator

Central orchestrator for tool execution decisions:

```php
class WP_MCP_AI_Tool_Execution_Orchestrator {
    /**
     * Decide execution strategy for a tool.
     */
    public function get_execution_strategy( $tool, $context ) {
        // Check if RabbitMQ is available
        if ( ! $this->rabbitmq->is_available() ) {
            return 'sync';
        }

        // Check tool requirements
        if ( $tool->has_flag( 'queue-required' ) ) {
            return 'queue';
        }

        // Check estimated execution time
        $estimated_time = $this->estimate_execution_time( $tool, $context );
        
        if ( $estimated_time > 2000 ) { // > 2 seconds
            return 'queue';
        }

        // Check current server load
        if ( $this->is_server_under_load() && $tool->has_flag( 'queue-preferred' ) ) {
            return 'queue';
        }

        return 'sync';
    }
}
```

### 5. Tool Usage Analytics

Track tool execution metrics via RabbitMQ:

```php
$analytics_message = array(
    'event'          => 'tool_execution',
    'tool_name'      => $tool_name,
    'assistant_id'   => $assistant_id,
    'user_id'        => $user_id,
    'execution_time' => $execution_time_ms,
    'status'         => $status, // success, error, timeout
    'queue_time'     => $queue_wait_time_ms,
    'retry_count'    => $retry_count,
    'timestamp'      => current_time( 'mysql' ),
);

$this->rabbitmq->publish( 'analytics', $analytics_message );
```

---

## Implementation Plan

### Phase 1: Foundation (Week 1-2)

| Task | Priority | Files |
|------|----------|-------|
| Create RabbitMQ client class | High | `includes/class-wp-mcp-ai-rabbitmq-client.php` |
| Add admin settings section | High | `includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php` |
| Connection pooling | High | `includes/class-wp-mcp-ai-rabbitmq-connection-pool.php` |
| Health check endpoint | Medium | `includes/class-wp-mcp-ai-rest.php` |
| Basic queue management | High | `includes/class-wp-mcp-ai-queue-manager.php` |

### Phase 2: Tool Integration (Week 3-4)

| Task | Priority | Files |
|------|----------|-------|
| Tool queue interface | High | `includes/interfaces/interface-wp-mcp-ai-tool-queue.php` |
| Update tool registry | High | `includes/class-wp-mcp-ai-tool-registry.php` |
| Tool execution orchestrator | High | `includes/class-wp-mcp-ai-tool-execution-orchestrator.php` |
| Async tool worker | High | `includes/class-wp-mcp-ai-rabbitmq-worker.php` |
| WP-CLI worker command | Medium | `includes/class-wp-mcp-ai-cli-command.php` |

### Phase 3: Agentic Workflow (Week 5-6)

| Task | Priority | Files |
|------|----------|-------|
| Parallel tool execution | High | `includes/services/class-wp-mcp-ai-chat-service.php` |
| Workflow state persistence | Medium | `includes/class-wp-mcp-ai-workflow-state.php` |
| Real-time progress updates | Medium | `includes/class-wp-mcp-ai-sse-stream.php` |
| Retry logic implementation | High | `includes/class-wp-mcp-ai-retry-handler.php` |
| Dead letter handling | Medium | `includes/class-wp-mcp-ai-dead-letter-handler.php` |

### Phase 4: Monitoring & Polish (Week 7-8)

| Task | Priority | Files |
|------|----------|-------|
| Admin dashboard widgets | Medium | `includes/admin/class-wp-mcp-ai-rabbitmq-dashboard.php` |
| Analytics integration | Low | `includes/class-wp-mcp-ai-rabbitmq-analytics.php` |
| Documentation | High | `docs/rabbitmq-integration.md` |
| Unit tests | High | `tests/test-rabbitmq-*.php` |
| Performance testing | Medium | `tests/performance/` |

---

## Configuration

### Admin Settings

New settings section at **Settings → WP oOS → RabbitMQ**:

```php
$rabbitmq_settings = array(
    // Connection
    'rabbitmq_enabled'       => false,
    'rabbitmq_host'          => 'localhost',
    'rabbitmq_port'          => 5672,
    'rabbitmq_username'      => '',
    'rabbitmq_password'      => '',
    'rabbitmq_vhost'         => '/',
    
    // Queues
    'rabbitmq_queue_prefix'  => 'wp_mcp_ai',
    'rabbitmq_priority_queues' => true,
    
    // Workers
    'rabbitmq_worker_count'  => 2,
    'rabbitmq_worker_timeout' => 300,
    
    // Retry
    'rabbitmq_max_retries'   => 3,
    'rabbitmq_retry_delay'   => 1000,
    
    // Dead Letter
    'rabbitmq_dead_letter_enabled' => true,
    'rabbitmq_dead_letter_ttl'     => 86400,
);
```

### wp-config.php Constants

```php
// Connection (alternative to admin settings)
define( 'WP_MCP_AI_RABBITMQ_HOST', 'localhost' );
define( 'WP_MCP_AI_RABBITMQ_PORT', 5672 );
define( 'WP_MCP_AI_RABBITMQ_USER', 'your_user' );
define( 'WP_MCP_AI_RABBITMQ_PASS', 'your_password' );
define( 'WP_MCP_AI_RABBITMQ_VHOST', '/' );

// Features
define( 'WP_MCP_AI_RABBITMQ_ENABLED', true );
define( 'WP_MCP_AI_RABBITMQ_PARALLEL_TOOLS', true );
```

---

## Security Considerations

### 1. Credential Protection

```php
// Never log credentials
$redacted_config = $this->redact_sensitive_data( $config );
WP_MCP_AI_Logger::log_event( 'rabbitmq_connection', 'Connecting', $redacted_config );

// Store encrypted in database
$encrypted_password = wp_mcp_ai_encrypt( $password );
update_option( 'wp_mcp_ai_rabbitmq_password', $encrypted_password );
```

### 2. Message Validation

```php
// Validate incoming messages
public function validate_message( $message ) {
    $required_fields = array( 'type', 'payload', 'timestamp', 'signature' );
    
    foreach ( $required_fields as $field ) {
        if ( ! isset( $message[ $field ] ) ) {
            throw new InvalidMessageException( "Missing field: $field" );
        }
    }
    
    // Verify signature
    if ( ! $this->verify_signature( $message ) ) {
        throw new SecurityException( 'Invalid message signature' );
    }
    
    return true;
}
```

### 3. Queue Access Control

```php
// Define queue permissions per capability
$queue_permissions = array(
    'tool.execution'              => 'edit_posts',
    'tool.execution.priority.high' => 'edit_posts',
    'tool.execution.async'        => 'manage_options',
    'admin.operations'            => 'manage_options',
);
```

---

## Monitoring & Debugging

### 1. Health Check Endpoint

```
GET /wp-json/mcp-ai/v1/rabbitmq/health

Response:
{
    "status": "healthy",
    "connection": {
        "connected": true,
        "host": "localhost",
        "port": 5672,
        "vhost": "/"
    },
    "queues": {
        "tool.execution": {
            "messages": 12,
            "consumers": 2,
            "rate": 5.2
        }
    },
    "workers": {
        "active": 2,
        "idle": 0
    }
}
```

### 2. WP-CLI Commands

```bash
# Status
wp mcp-ai rabbitmq status

# Queue management
wp mcp-ai rabbitmq list-queues
wp mcp-ai rabbitmq purge-queue tool.execution
wp mcp-ai rabbitmq consume-dead-letter

# Worker management
wp mcp-ai rabbitmq worker start --count=2
wp mcp-ai rabbitmq worker stop
wp mcp-ai rabbitmq worker status

# Diagnostics
wp mcp-ai rabbitmq test-connection
wp mcp-ai rabbitmq send-test-message
```

### 3. Admin Dashboard Widget

New Elementor widget and dashboard widget showing:

- Connection status
- Queue depths
- Message rates
- Worker status
- Failed message count
- Average processing time

---

## Migration Strategy

### Graceful Degradation

The integration must work when RabbitMQ is unavailable:

```php
public function execute_tool( $tool_name, $arguments, $context ) {
    // Try queue execution
    if ( $this->rabbitmq->is_available() && $this->should_use_queue( $tool_name ) ) {
        try {
            return $this->queue_tool_execution( $tool_name, $arguments, $context );
        } catch ( RabbitMQException $e ) {
            WP_MCP_AI_Logger::log_error( 'RabbitMQ unavailable, falling back to sync', array(
                'tool'  => $tool_name,
                'error' => $e->getMessage(),
            ) );
        }
    }
    
    // Fallback to synchronous execution
    return $this->execute_tool_sync( $tool_name, $arguments, $context );
}
```

### Feature Flags

```php
// Enable features progressively
$features = array(
    'rabbitmq_tool_execution' => array(
        'enabled'     => true,
        'percentage'  => 100,  // Rollout percentage
        'tools'       => array( 'run_crawl4ai_job', 'generate_openai_image' ),
    ),
    'rabbitmq_parallel_execution' => array(
        'enabled'     => false, // Not yet enabled
        'percentage'  => 0,
    ),
);
```

---

## Future Considerations

### 1. Multi-Site Queue Sharing

Share RabbitMQ queues across WordPress multisite installations:

```php
// Site-specific queue names
$queue_name = sprintf( 'wp_mcp_ai.%d.tool.execution', get_current_blog_id() );
```

### 2. External Worker Pools

Run workers outside WordPress for better resource isolation:

```bash
# Python worker for heavy processing
python wp_mcp_ai_worker.py --queue=tool.execution.async --concurrency=4
```

### 3. Event Sourcing

Use RabbitMQ for event sourcing of all tool executions:

```php
$event = array(
    'event_type' => 'ToolExecutionRequested',
    'aggregate_id' => $workflow_id,
    'sequence' => $sequence_number,
    'payload' => $tool_call,
    'metadata' => array(
        'user_id' => $user_id,
        'timestamp' => microtime( true ),
    ),
);
```

### 4. Cross-Plugin Integration

Expose RabbitMQ infrastructure to other plugins:

```php
// Hook for other plugins
do_action( 'wp_mcp_ai_rabbitmq_available', $rabbitmq_client );

// Filter for custom queues
$queues = apply_filters( 'wp_mcp_ai_rabbitmq_queues', $default_queues );
```

---

## Dependencies

### PHP Extension

Requires `php-amqp` extension (Cloudways includes this when RabbitMQ is enabled).

```php
// Check at runtime
if ( ! extension_loaded( 'amqp' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'WP oOS RabbitMQ integration requires the php-amqp extension.', 'wp-mcp-ai' );
        echo '</p></div>';
    } );
}
```

### Composer Package (Alternative)

If php-amqp is not available, use php-amqplib:

```json
{
    "require": {
        "php-amqplib/php-amqplib": "^3.5"
    }
}
```

---

## Conclusion

Integrating RabbitMQ into WP oOS on Cloudways will transform the plugin's agentic workflow capabilities from a synchronous, single-threaded model to an enterprise-grade, asynchronous, parallel processing system. The phased implementation approach ensures stability while progressively enabling more advanced features.

Key deliverables:
1. **RabbitMQ client with connection pooling**
2. **Queue-aware tool execution orchestrator**
3. **Parallel agentic tool processing**
4. **Comprehensive monitoring and debugging tools**
5. **Graceful degradation when RabbitMQ unavailable**

---

**Document Status:** Draft  
**Next Review:** December 15, 2025  
**Maintainer:** NV Digital Solutions
