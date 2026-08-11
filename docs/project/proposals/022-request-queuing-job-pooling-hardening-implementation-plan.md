# Implementation Plan: Request Queuing, Job Pooling & Concurrency Hardening

**Based on:** Proposal 022 (`docs/project/proposals/022-request-queuing-job-pooling-hardening.md`)
**Date:** 2026-08-11
**Status:** Draft
**Target releases:** v1.1.44 (Wave 1), v1.2.0 (Wave 2)

---

## Executive Summary

Ten findings from the 2026-08-11 request-handling architecture review, remediated in two waves:

- **Wave 1 (v1.1.44, patch):** Wire the two fully-implemented-but-unused safety mechanisms (ConcurrencyGuard, CostTracker) into the production execution path. Unify async behavior across the two tool-execution call sites. These are surgical changes — the classes, hooks, and infrastructure already exist; we are only adding hook subscribers and a single `do_action` call.
- **Wave 2 (v1.2.0, minor):** Add backpressure, queue depth limits, unified SSE tracking, request-level priority, provider circuit-breaking in base, and execution-path consolidation.

**Files modified:** ~15 base + 2 pro.
**New files:** ~10 (2 hook subscriber classes, 1 circuit breaker base class, 1 guard helper, 6 test files).
**Estimated LOC:** ~800 changed/added + ~600 test LOC.

---

## Wave 1 — v1.1.44 (patch)

### Task C1-1 — Create ConcurrencyGuard Hook Subscriber

**New file:** `includes/security/class-wp-mcp-ai-concurrency-guard-subscriber.php`

This is a thin subscriber that bridges `WP_MCP_AI_Concurrency_Guard` into the `wp_mcp_ai_before_tool_execution` / `wp_mcp_ai_after_tool_execution` hook pair. It does not duplicate the guard's logic — it only maps tool capability flags to operation types and calls acquire/release.

```php
<?php
/**
 * Concurrency Guard Hook Subscriber
 *
 * Wires WP_MCP_AI_Concurrency_Guard into the tool execution lifecycle
 * via wp_mcp_ai_before_tool_execution / wp_mcp_ai_after_tool_execution.
 *
 * @package WP_MCP_AI
 * @since 1.1.44
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_MCP_AI_Concurrency_Guard_Subscriber {

    /**
     * Map of capability flags → concurrency operation type.
     *
     * @var array<string, string>
     */
    const FLAG_TO_OPERATION = array(
        'image-generation'   => 'image_generation',
        'video-generation'   => 'video_generation',
        'music-generation'   => 'music_generation',
        'audio-generation'   => 'music_generation',
        'deep-research'      => 'deep_research',
        'model-download'     => 'model_download',
        'document-ocr'       => 'document_ocr',
        'pdf-generation'     => 'pdf_generation',
        'embeddings-batch'   => 'embeddings_batch',
        'video-frame-extract'=> 'video_frame_extract',
    );

    /**
     * Register hooks.
     *
     * Priority 3: after DestructiveOpsGate (0) and CoSAI boundary (1),
     * but before token limiter and observers (5).
     */
    public static function register() {
        if ( ! class_exists( 'WP_MCP_AI_Concurrency_Guard' ) ) {
            return;
        }
        add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before' ), 3, 3 );
        add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'on_after' ), 3, 4 );
    }

    /**
     * Acquire a concurrency slot before tool execution.
     *
     * If at capacity, throws WP_Error via wp_die() so the REST handler's
     * try/catch converts it to a proper 429 response.
     *
     * @param string $tool_slug Tool identifier.
     * @param array  $arguments Tool arguments.
     * @param array  $context   Execution context.
     */
    public static function on_before( $tool_slug, $arguments, $context ) {
        $tool = self::resolve_tool( $tool_slug );
        if ( ! $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
            return;
        }

        $operation = self::map_to_operation( $tool );
        if ( null === $operation ) {
            return; // Not a concurrency-relevant tool.
        }

        $result = WP_MCP_AI_Concurrency_Guard::acquire( $operation );
        if ( is_wp_error( $result ) ) {
            // Store the operation type so on_after() can release it.
            // Use a static stack since WordPress is single-threaded per request.
            self::push_active( $tool_slug, $operation );

            wp_die(
                new WP_Error(
                    'concurrency_limit',
                    $result->get_error_message(),
                    array( 'status' => 429 )
                ),
                429
            );
        }

        self::push_active( $tool_slug, $operation );
    }

    /**
     * Release the concurrency slot after tool execution.
     *
     * @param string $tool_slug  Tool identifier.
     * @param array  $arguments  Tool arguments.
     * @param array  $context    Execution context.
     * @param mixed  $result     Tool result.
     */
    public static function on_after( $tool_slug, $arguments, $context, $result ) {
        $operation = self::pop_active( $tool_slug );
        if ( null === $operation ) {
            return;
        }
        WP_MCP_AI_Concurrency_Guard::release( $operation );
    }

    /**
     * Map a tool's capability flags to a concurrency operation type.
     *
     * @param WP_MCP_AI_Tool_Capability_Flags_Interface $tool
     * @return string|null Operation type or null if not relevant.
     */
    private static function map_to_operation( $tool ) {
        $flags = (array) $tool->get_capability_flags();
        foreach ( self::FLAG_TO_OPERATION as $flag => $operation ) {
            if ( in_array( $flag, $flags, true ) ) {
                return $operation;
            }
        }
        return null;
    }

    /**
     * Resolve a tool instance by slug.
     *
     * @param string $tool_slug
     * @return WP_MCP_AI_Tool_Interface|null
     */
    private static function resolve_tool( $tool_slug ) {
        if ( ! function_exists( 'wp_mcp_ai_container' ) ) {
            return null;
        }
        $container = wp_mcp_ai_container();
        if ( ! $container || ! method_exists( $container, 'get' ) ) {
            return null;
        }
        try {
            $registry = $container->get( 'tool.registry' );
            if ( ! $registry instanceof WP_MCP_AI_Tool_Registry ) {
                return null;
            }
            return $registry->get_tool( $tool_slug );
        } catch ( Exception $e ) {
            return null;
        }
    }

    /**
     * Per-request stack of (tool_slug → operation_type) for release tracking.
     *
     * @var array<string, string>
     */
    private static $active = array();

    private static function push_active( $tool_slug, $operation ) {
        self::$active[ $tool_slug ] = $operation;
    }

    private static function pop_active( $tool_slug ) {
        $op = isset( self::$active[ $tool_slug ] ) ? self::$active[ $tool_slug ] : null;
        unset( self::$active[ $tool_slug ] );
        return $op;
    }
}
```

**Registration** in `includes/agents-init.php` (adjacent to `WP_MCP_AI_Destructive_Ops_Gate::register()` and `WP_MCP_AI_Request_Guard::register()`):

```php
// Register concurrency guard subscriber (1.1.44) — enforces per-operation-type
// concurrent execution limits (image=3, video=1, music=2, etc.).
WP_MCP_AI_Concurrency_Guard_Subscriber::register();
```

**Tests:** new `tests/security/test-concurrency-guard-subscriber.php`:
- Mock a tool with `image-generation` capability flag, execute twice concurrently in same process (stack tracks correctly), assert third acquire returns 429.
- Tool without capability flags passes through without acquiring.
- `on_after` releases the slot even when result is `WP_Error`.
- Deep research tool maps to `deep_research` operation type.

**Acceptance:** 429 response when image-generation pool (limit: 3) is exhausted. `composer run lint:errors-only` clean on new file.

---

### Task C1-2 — Add `wp_die()` Handler Compatibility in REST Execute Path

**File:** `includes/class-wp-mcp-ai-rest.php` (both `handle_tool_request` and `execute_tool_call_internal`)

The ConcurrencyGuard subscriber (C1-1) uses `wp_die()` to short-circuit when at capacity — matching the pattern already used by the DestructiveOpsGate (see Proposal 016, finding H2). Since Proposal 016 recommends replacing `wp_die()` with exceptions, we implement the subscriber to throw an exception instead, avoiding the `wp_die()` issue entirely.

**Change:** Instead of `wp_die()` in the subscriber, throw a custom exception that the existing try/catch blocks in the REST handler already handle:

```php
// In the subscriber's on_before(), replace wp_die() with:
throw new WP_MCP_AI_Concurrency_Limit_Reached( $operation, $result->get_error_message() );
```

**New file:** `includes/exceptions/class-wp-mcp-ai-concurrency-limit-reached.php`

```php
<?php
class WP_MCP_AI_Concurrency_Limit_Reached extends Exception {
    private $operation_type;
    public function __construct( $operation_type, $message ) {
        parent::__construct( $message, 429 );
        $this->operation_type = $operation_type;
    }
    public function get_operation_type() { return $this->operation_type; }
    public function to_wp_error() {
        return new WP_Error(
            'concurrency_limit',
            $this->getMessage(),
            array(
                'status'          => 429,
                'operation_type'  => $this->operation_type,
                'retry_after'     => 30,
            )
        );
    }
}
```

**Catch block** already exists in both `handle_tool_request()` (L5928–5934) and `execute_tool_call_internal()` (L11848–11853) — add the new exception type:

```php
} catch ( WP_MCP_AI_Concurrency_Limit_Reached $e ) {
    return $e->to_wp_error();
}
```

---

### Task C2-1 — Create CostTracker Hook Subscriber

**New file:** `includes/security/class-wp-mcp-ai-cost-tracker-subscriber.php`

Bridges `WP_MCP_AI_Cost_Tracker` into the execution lifecycle. Estimates cost before execution, checks budget, records actual cost after.

```php
<?php
/**
 * Cost Tracker Hook Subscriber
 *
 * Wires WP_MCP_AI_Cost_Tracker into the tool execution lifecycle
 * via wp_mcp_ai_before_tool_execution / wp_mcp_ai_after_tool_execution.
 *
 * @package WP_MCP_AI
 * @since 1.1.44
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_MCP_AI_Cost_Tracker_Subscriber {

    /**
     * Register hooks.
     *
     * Priority 2: after DestructiveOpsGate (0) and CoSAI (1),
     * before ConcurrencyGuard (3).
     */
    public static function register() {
        if ( ! class_exists( 'WP_MCP_AI_Cost_Tracker' ) ) {
            return;
        }
        add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'on_before' ), 2, 3 );
        add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'on_after' ), 2, 4 );
    }

    /**
     * Check budget before execution.
     *
     * @param string $tool_slug Tool identifier.
     * @param array  $arguments Tool arguments.
     * @param array  $context   Execution context.
     */
    public static function on_before( $tool_slug, $arguments, $context ) {
        $assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
        if ( $assistant_id <= 0 ) {
            return;
        }

        $estimate = WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $arguments );
        $check    = WP_MCP_AI_Cost_Tracker::check_budget( $assistant_id, $estimate );

        if ( is_wp_error( $check ) ) {
            wp_die(
                new WP_Error(
                    'cost_budget_exceeded',
                    $check->get_error_message(),
                    array( 'status' => 429 )
                ),
                429
            );
        }
    }

    /**
     * Record actual cost after execution.
     *
     * @param string $tool_slug  Tool identifier.
     * @param array  $arguments  Tool arguments.
     * @param array  $context    Execution context.
     * @param mixed  $result     Tool result (may be array or WP_Error).
     */
    public static function on_after( $tool_slug, $arguments, $context, $result ) {
        $assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
        if ( $assistant_id <= 0 ) {
            return;
        }

        // Only record successful executions (not budget-rejected calls).
        if ( is_wp_error( $result ) ) {
            return;
        }

        // Re-estimate: for simplicity use the same estimation as before.
        // In a future enhancement, tools could report actual token usage.
        $estimate = WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $arguments );
        WP_MCP_AI_Cost_Tracker::record( $assistant_id, $estimate );
    }
}
```

**Registration** in `includes/agents-init.php`:

```php
WP_MCP_AI_Cost_Tracker_Subscriber::register();
```

**Note on `wp_die()` usage:** Like C1, the subscriber should use the exception pattern to be consistent with the Proposal 016 direction. Use `WP_MCP_AI_Cost_Budget_Exceeded` exception and catch in the REST handler.

**Tests:** new `tests/security/test-cost-tracker-subscriber.php`:
- Set assistant budget to $0.01, execute a DALL-E image generation tool → assert 429.
- Execute a text-generation tool within budget → assert passes.
- Verify `record()` was called on successful execution.
- No assistant_id in context → subscriber is a no-op (no crash).

**Acceptance:** Budget enforcement active; admin-configured cost limits actually block over-budget tool calls.

---

### Task H3-1 — Unify Async Behavior in `handle_tool_request()`

**File:** `includes/class-wp-mcp-ai-rest.php` — `handle_tool_request()` method (~L5860–6019)

Currently `handle_tool_request()` executes synchronously without consulting the async orchestrator. Add the async decision path that `execute_tool_call_internal()` already uses.

**Change — insert after context array construction (~L5893) and before the `do_action('wp_mcp_ai_before_tool_execution')` call (~L5928):**

```php
// Orchestration Layer: Check if tool should execute asynchronously.
// Mirrors the async decision path in execute_tool_call_internal().
$orchestrator = wp_mcp_ai_get_async_tool_orchestrator();
$should_async = $orchestrator->should_execute_async( $tool, $prepared_arguments, $context );

if ( $should_async ) {
    $executor = wp_mcp_ai_get_async_tool_executor();
    $job_id   = $executor->queue_tool( $tool_slug, $prepared_arguments, $context );

    if ( ! is_wp_error( $job_id ) ) {
        return rest_ensure_response( array(
            'assistant_id' => $assistant_id,
            'tool'         => $tool_slug,
            'status'       => 'pending',
            'job_id'       => $job_id,
            'message'      => sprintf(
                /* translators: 1: tool name, 2: job ID */
                __( 'Tool "%1$s" is processing in the background (Job ID: %2$s).', 'mcp-ai-wpoos' ),
                $tool->get_name(),
                $job_id
            ),
            'async'        => true,
            'capability_flags' => $this->extract_capability_flags_from_tool( $tool ),
        ) );
    }
    // Fall through: queueing failed → execute synchronously.
}
```

**Note:** The `$context` array must include `agentic_loop => false` for direct `tools/call` requests so the orchestrator doesn't force-sync (Priority 5 in its decision hierarchy).

**Tests:** update `tests/rest/test-tools-endpoint.php`:
- Call a `background-only` tool via `tools/call` → assert response has `status: pending` and `job_id`.
- Call a sync-only tool via `tools/call` → assert normal synchronous response.
- Verify tool with `wait_for_completion=true` parameter executes synchronously (Priority 4 override).

**Acceptance:** Direct `tools/call` and chat agentic loop paths have consistent async behavior.

---

### Task H3-2 — Add Exception Catch for New Exception Types in Both Execution Paths

**Files:** `includes/class-wp-mcp-ai-rest.php`

Both `handle_tool_request()` and `execute_tool_call_internal()` have try/catch blocks around `do_action('wp_mcp_ai_before_tool_execution')`. Extend the catch to handle the new exception types from C1-1 and C2-1:

```php
try {
    do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $prepared_arguments, $context );
} catch ( WP_MCP_AI_Destructive_Confirmation_Required $e ) {
    return $e->to_wp_error();
} catch ( WP_MCP_AI_Concurrency_Limit_Reached $e ) {
    return $e->to_wp_error();
} catch ( WP_MCP_AI_Cost_Budget_Exceeded $e ) {
    return $e->to_wp_error();
}
```

**Acceptance:** All three new gate exceptions produce proper JSON error envelopes via REST.

---

## Wave 2 — v1.2.0 (minor)

### Task H4-1 — Add Queue Depth Backpressure to Async Executor

**File:** `includes/services/class-wp-mcp-ai-tool-async-executor.php`

Add a maximum-queue-size check to `queue_tool()` (before L183):

```php
/**
 * Maximum pending jobs allowed system-wide.
 *
 * @var int
 */
const MAX_PENDING_JOBS = 500;

/**
 * Maximum pending jobs per user.
 *
 * @var int
 */
const MAX_PENDING_JOBS_PER_USER = 20;
```

Add checks after the duplicate-detection logic (~L192):

```php
// Check global queue depth.
$pending_count = $this->count_pending_jobs();
if ( $pending_count >= self::MAX_PENDING_JOBS ) {
    return new WP_Error(
        'wp_mcp_ai_queue_full',
        __( 'The job queue is currently at capacity. Please try again later.', 'mcp-ai-wpoos' ),
        array( 'status' => 429, 'retry_after' => 60 )
    );
}

// Check per-user queue depth.
$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
if ( $user_id > 0 ) {
    $user_pending = $this->count_pending_jobs_for_user( $user_id );
    if ( $user_pending >= self::MAX_PENDING_JOBS_PER_USER ) {
        return new WP_Error(
            'wp_mcp_ai_user_queue_full',
            sprintf(
                /* translators: %d: maximum pending jobs per user */
                __( 'You have %d pending jobs. Please wait for some to complete before submitting more.', 'mcp-ai-wpoos' ),
                self::MAX_PENDING_JOBS_PER_USER
            ),
            array( 'status' => 429, 'retry_after' => 30 )
        );
    }
}
```

Implement `count_pending_jobs()` and `count_pending_jobs_for_user()` by scanning metadata transients (prefix-scan with `$wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE '..._async_meta_%'")` and filtering). Add a lightweight index: a single transient key `wp_mcp_ai_async_pending_count` incremented/decremented atomically.

**Tests:** `tests/services/test-async-executor-backpressure.php`:
- Enqueue 501 jobs, assert 501st returns `WP_Error` with 429.
- Enqueue 21 jobs for one user, assert 21st returns per-user limit error.
- Different users don't affect each other's per-user limits.

**Acceptance:** Queue won't grow unbounded. Callers receive `429 Retry-After` when at capacity.

---

### Task H4-2 — Add System-Load Backpressure at REST Layer

**New file:** `includes/security/class-wp-mcp-ai-load-guard.php`

A lightweight guard that checks aggregate system load before processing REST requests:

```php
class WP_MCP_AI_Load_Guard {
    const TRANSIENT_KEY = 'wp_mcp_ai_system_load';

    public static function register() {
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'check_load' ), 5, 3 );
    }

    public static function check_load( $result, $server, $request ) {
        // Only validate plugin routes (same pattern as RequestGuard).
        if ( ! self::is_plugin_route( $request ) ) {
            return $result;
        }

        $max_concurrent = self::get_max_concurrent();
        $active_jobs    = self::count_all_active_jobs();

        if ( $active_jobs >= $max_concurrent ) {
            return new WP_Error(
                'system_overloaded',
                __( 'The system is currently under heavy load. Please try again later.', 'mcp-ai-wpoos' ),
                array( 'status' => 429, 'retry_after' => 30 )
            );
        }

        return $result;
    }

    private static function count_all_active_jobs() {
        $count = 0;
        // Sum active jobs across all three queuing systems.
        if ( class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
            $count += WP_MCP_AI_Job_Queue_Manager::count_active_jobs();
        }
        if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
            $stats = WP_MCP_AI_Async_Job_Queue::get_queue_stats();
            $count += isset( $stats['running'] ) ? (int) $stats['running'] : 0;
        }
        // Tool_Async_Executor: count 'running' metadata entries.
        $count += self::count_running_async_tools();
        return $count;
    }
    // ... (is_plugin_route, get_max_concurrent, count_running_async_tools)
}
```

**Registration** in `includes/agents-init.php` at priority 5 (before RequestGuard's priority 10 on the same filter).

**Tests:** `tests/security/test-load-guard.php`:
- Override `get_max_concurrent()` to 0, send REST request → assert 429.
- Normal load → request passes through.

---

### Task H5-1 — Extract Shared Execution Gate into a Trait or Helper

**File:** `includes/class-wp-mcp-ai-rest.php`

Currently the `wp_mcp_ai_before_tool_execution` try/catch + `wp_mcp_ai_pre_execute_tool` filter + async orchestrator decision logic is duplicated between `handle_tool_request()` and `execute_tool_call_internal()`. Extract into a private method:

```php
/**
 * Execute a tool through the unified execution pipeline.
 *
 * Handles: before/after hooks, gate exceptions, pre-execute filter,
 * async orchestrator decision, and budget adjustment.
 *
 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
 * @param array                    $arguments Sanitized arguments.
 * @param array                    $context   Execution context.
 * @return mixed Tool result, pending array, or WP_Error.
 */
private function execute_tool_unified( $tool, $arguments, $context ) {
    // 1. Before-execution hooks (with gate catches).
    try {
        do_action( 'wp_mcp_ai_before_tool_execution', $tool->get_slug(), $arguments, $context );
    } catch ( WP_MCP_AI_Destructive_Confirmation_Required $e ) {
        return $e->to_wp_error();
    } catch ( WP_MCP_AI_Concurrency_Limit_Reached $e ) {
        return $e->to_wp_error();
    } catch ( WP_MCP_AI_Cost_Budget_Exceeded $e ) {
        return $e->to_wp_error();
    }

    // 2. Async decision.
    $orchestrator = wp_mcp_ai_get_async_tool_orchestrator();
    $should_async = $orchestrator->should_execute_async( $tool, $arguments, $context );

    if ( $should_async && empty( $context['agentic_loop'] ) ) {
        $executor = wp_mcp_ai_get_async_tool_executor();
        $job_id   = $executor->queue_tool( $tool->get_slug(), $arguments, $context );
        if ( ! is_wp_error( $job_id ) ) {
            return array(
                'status'    => 'pending',
                'job_id'    => $job_id,
                'async'     => true,
                'tool_slug' => $tool->get_slug(),
                'message'   => sprintf(
                    __( 'Tool "%s" is processing in the background (Job ID: %s).', 'mcp-ai-wpoos' ),
                    $tool->get_name(),
                    $job_id
                ),
            );
        }
    }

    // 3. Pre-execute short-circuit filter.
    $short_circuit = apply_filters( 'wp_mcp_ai_pre_execute_tool', null, $tool, $arguments, $context );
    if ( null !== $short_circuit ) {
        return $short_circuit;
    }

    // 4. Execute.
    $result = $tool->execute( $arguments, $context );

    // 5. Budget adjustment.
    if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
        $result = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget( $result, $tool->get_slug(), $context );
    }

    // 6. After-execution hooks.
    do_action( 'wp_mcp_ai_after_tool_execution', $tool->get_slug(), $arguments, $context, $result );

    return $result;
}
```

Then replace the duplicated logic in both `handle_tool_request()` and `execute_tool_call_internal()` with a single call to `$this->execute_tool_unified( $tool, $arguments, $context )`.

**Note:** `execute_tool_call_internal()` has agentic-loop-specific logic (timeout extension, deduplication, pending-tool message formatting) that must be preserved *around* the unified call. The unified method handles the core execution; callers handle loop-specific concerns.

**Acceptance:** Both execution paths use the same gate logic. Adding a new gate subscriber (C1, C2) automatically applies to both paths.

---

### Task M7-1 — Unify SSE Connection Tracking

**Decision:** Deprecate the `Request_Guard` SSE slot tracking (`acquire_sse_slot` / `release_sse_slot`) and make `WP_MCP_AI_SSE_Rate_Limiter` the single source of truth. The `Request_Guard` hooks on `wp_mcp_ai_sse_stream_started` / `wp_mcp_ai_sse_stream_ended` become pass-through proxies that delegate to the `SSE_Rate_Limiter`.

**File:** `includes/security/class-wp-mcp-ai-request-guard.php`

```php
public static function acquire_sse_slot( $job_id, $params ) {
    if ( class_exists( 'WP_MCP_AI_SSE_Rate_Limiter' ) ) {
        $limiter = new WP_MCP_AI_SSE_Rate_Limiter();
        $allowed = $limiter->check_connection_allowed();
        if ( is_wp_error( $allowed ) ) {
            wp_die( $allowed, 429 );
        }
        $token = $limiter->register_connection();
        // Store token in a static so release_sse_slot can find it.
        // In a single-request model this is safe.
        set_transient( 'wp_mcp_ai_sse_slot_token_' . $job_id, $token, 3600 );
    }
}

public static function release_sse_slot( $job_id, ...$args ) {
    $token = get_transient( 'wp_mcp_ai_sse_slot_token_' . $job_id );
    delete_transient( 'wp_mcp_ai_sse_slot_token_' . $job_id );
    if ( $token && class_exists( 'WP_MCP_AI_SSE_Rate_Limiter' ) ) {
        $limiter = new WP_MCP_AI_SSE_Rate_Limiter();
        $limiter->release_connection( $token );
    }
    // Legacy cleanup: remove old counter keys if they exist.
    $legacy_key = self::get_sse_counter_key();
    $legacy_count = absint( get_transient( $legacy_key ) );
    if ( $legacy_count <= 1 ) {
        delete_transient( $legacy_key );
    } elseif ( $legacy_count > 0 ) {
        set_transient( $legacy_key, $legacy_count - 1, 300 );
    }
}
```

**Tests:** `tests/security/test-sse-rate-limiter.php`:
- 5 connections from one user → 6th returns 429.
- 100 global connections → 101st returns 429.
- Admin bypasses limits.
- Release decrements both per-user and global counters.

**Acceptance:** Single enforcement point for SSE limits; no double-counting.

---

### Task M8-1 — Document Transient Best-Effort Limitation

**File:** `includes/class-wp-mcp-ai-rest.php` — `check_tool_rate_limit()` docblock.

Add a prominent note:

```php
/**
 * Check if the user has exceeded the tool execution rate limit.
 *
 * NOTE: This is a BEST-EFFORT rate limiter using WordPress transients.
 * Under high concurrency (multiple simultaneous requests from the same
 * user), the transient read-increment-write cycle is not atomic, allowing
 * brief bursts above the configured maximum. For strict enforcement,
 * deploy a persistent object cache with atomic increment support
 * (Redis INCR, Memcached increment).
 *
 * ...
 */
```

No code change needed — the current implementation is acceptable for the install base.

---

### Task M9-1 — Add Feature Flag and Documentation for RabbitMQ Path

**File:** `includes/class-wp-mcp-ai-queue-manager.php`

Add a feature flag constant so the RMQ path is opt-in:

```php
/**
 * Whether the RabbitMQ-based queue manager is enabled.
 *
 * Default false. Set to true via wp-config.php or filter when RabbitMQ is
 * configured and the AMQP PHP extension is loaded.
 *
 * @since 1.2.0
 */
const FEATURE_FLAG = 'WP_MCP_AI_RABBITMQ_ENABLED';
```

In `init_hooks()`:

```php
private function init_hooks() {
    if ( ! defined( self::FEATURE_FLAG ) || ! constant( self::FEATURE_FLAG ) ) {
        // RabbitMQ path disabled by default. Only hook in when explicitly
        // enabled by the site operator. See docs/operations/rabbitmq-setup.md.
        return;
    }
    add_filter( 'wp_mcp_ai_before_tool_execute', array( $this, 'maybe_queue_tool_execution' ), 5, 4 );
    add_action( 'wp_ajax_wp_mcp_ai_queue_status', array( $this, 'ajax_queue_status' ) );
}
```

Also update `is_queue_available()` to check the feature flag first.

**Acceptance:** No hooks registered on default installations. No eager RabbitMQ client instantiation. Clear opt-in path for operators.

---

### Task M10-1 — Add `X-Priority` Header and `priority` Parameter to JSON-RPC

**File:** `includes/class-wp-mcp-ai-rest-mcp-methods.php`

In `process_single_mcp_message()` (~L169), after extracting `$method`, `$params`, `$id`:

```php
// Extract request-level priority from header or params.
$priority = self::PRIORITY_NORMAL; // Default.
$header_priority = $request->get_header( 'X-Priority' );
if ( null !== $header_priority ) {
    $priority = self::normalize_priority( $header_priority );
} elseif ( isset( $params['_meta']['priority'] ) ) {
    $priority = self::normalize_priority( $params['_meta']['priority'] );
}
```

Add helper:

```php
private static function normalize_priority( $raw ) {
    $valid = array( 'realtime', 'high', 'normal', 'low', 'batch' );
    $raw   = strtolower( trim( (string) $raw ) );
    return in_array( $raw, $valid, true ) ? $raw : 'normal';
}
```

Propagate `$priority` through the context array to `execute_tool_call_internal()`:

```php
$context['priority'] = $priority;
```

**In `execute_tool_call_internal()`** — pass `$context['priority']` to `$async_job_data` when queuing via the `Async_Job_Queue` or `Tool_Async_Executor`.

**File:** `includes/services/class-wp-mcp-ai-tool-async-executor.php` — `queue_tool()`:

```php
// Use request-level priority if provided, otherwise infer from tool flags.
$priority = isset( $context['priority'] ) ? $context['priority'] : null;
if ( null === $priority && class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
    $tool_obj = $this->registry->get_tool( $tool_slug );
    if ( $tool_obj ) {
        $tier     = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $tool_obj );
        $priority = WP_MCP_AI_SLA_Manager::get_priority( $tier );
    }
}
$metadata['priority'] = $priority ?? 50; // Default normal.
```

**Tests:** `tests/rest/test-mcp-priority.php`:
- Send JSON-RPC `tools/call` with `X-Priority: high` → verify job metadata shows appropriate priority.
- No priority header → defaults to tool-inferred priority.
- Invalid priority value → silently falls back to normal.

**Acceptance:** Callers can signal urgency. Time-sensitive operations can jump the queue ahead of batch jobs.

---

### Task NEW-1 — Port Circuit Breaker to Base Plugin for Provider-Level Protection

**New file:** `includes/security/class-wp-mcp-ai-provider-circuit-breaker.php`

A simplified circuit breaker for AI provider API calls (not session-scoped like the Pro `Circuit_Breaker`). Protects against cascading failures when a provider is returning 5xx errors.

```php
class WP_MCP_AI_Provider_Circuit_Breaker {
    const STATE_KEY_PREFIX = 'wp_mcp_ai_cb_';
    const DEFAULT_THRESHOLD = 5;    // 5 consecutive failures
    const DEFAULT_TIMEOUT   = 60;   // 60 seconds in OPEN state

    public static function is_allowed( $provider ) {
        $key   = self::STATE_KEY_PREFIX . sanitize_key( $provider );
        $state = get_transient( $key );

        if ( false === $state ) {
            return true; // No failures recorded.
        }

        $state = json_decode( $state, true );
        if ( ! is_array( $state ) ) {
            return true;
        }

        if ( 'open' === $state['state'] ) {
            if ( time() >= $state['retry_after'] ) {
                // Transition to half-open.
                set_transient( $key, wp_json_encode( array(
                    'state'       => 'half_open',
                    'failures'    => $state['failures'],
                    'retry_after' => 0,
                ) ), self::DEFAULT_TIMEOUT * 2 );
                return true; // Allow one trial.
            }
            return false; // Still open.
        }

        return true; // Closed or half-open.
    }

    public static function record_failure( $provider ) {
        $key   = self::STATE_KEY_PREFIX . sanitize_key( $provider );
        $state = get_transient( $key );
        $data  = array( 'state' => 'closed', 'failures' => 1, 'retry_after' => 0 );

        if ( false !== $state ) {
            $decoded = json_decode( $state, true );
            if ( is_array( $decoded ) ) {
                $data['failures'] = (int) $decoded['failures'] + 1;
            }
        }

        if ( $data['failures'] >= self::DEFAULT_THRESHOLD ) {
            $data['state']       = 'open';
            $data['retry_after'] = time() + self::DEFAULT_TIMEOUT;
        }

        set_transient( $key, wp_json_encode( $data ), self::DEFAULT_TIMEOUT * 2 );
    }

    public static function record_success( $provider ) {
        $key = self::STATE_KEY_PREFIX . sanitize_key( $provider );
        delete_transient( $key ); // Reset on success.
    }
}
```

**Integration point:** Hook into `wp_mcp_ai_before_chat_request` or create a wrapper around provider HTTP calls. For Wave 2, add a filter at the provider-client level:

```php
// In WP_MCP_AI_OpenAI_Client::chat_completion(), before wp_remote_post():
if ( ! WP_MCP_AI_Provider_Circuit_Breaker::is_allowed( 'openai' ) ) {
    return new WP_Error(
        'provider_circuit_open',
        __( 'OpenAI API is temporarily unavailable due to repeated failures.', 'mcp-ai-wpoos' ),
        array( 'status' => 503, 'retry_after' => 60 )
    );
}

$response = wp_remote_post( ... );

if ( is_wp_error( $response ) || 500 <= wp_remote_retrieve_response_code( $response ) ) {
    WP_MCP_AI_Provider_Circuit_Breaker::record_failure( 'openai' );
} else {
    WP_MCP_AI_Provider_Circuit_Breaker::record_success( 'openai' );
}
```

**Apply to:** `OpenAI_Client`, `Gemini_Client`, `Anthropic_Client` (the 3 primary providers). Other providers follow in a subsequent patch.

**Tests:** `tests/security/test-provider-circuit-breaker.php`:
- Record 5 failures → `is_allowed()` returns false.
- Wait past timeout → `is_allowed()` returns true (half-open trial).
- One success after half-open → resets to closed.
- Different providers have independent breakers.

**Acceptance:** Five consecutive 5xx errors from a provider → circuit opens for 60 seconds → all subsequent calls to that provider return 503 immediately without making HTTP requests. Saves resources during provider outages.

---

### Task NEW-2 — Bootstrap Registration of All New Subscribers

**File:** `includes/agents-init.php`

Add all new subscriber registrations adjacent to existing ones:

```php
// Register concurrency guard subscriber (1.1.44).
WP_MCP_AI_Concurrency_Guard_Subscriber::register();

// Register cost tracker subscriber (1.1.44).
WP_MCP_AI_Cost_Tracker_Subscriber::register();

// Register system load guard (1.2.0).
WP_MCP_AI_Load_Guard::register();
```

**Acceptance:** All new gates are active on plugin load.

---

## File Inventory

### Wave 1 — v1.1.44

| File | Action | LOC |
|---|---|---|
| `includes/security/class-wp-mcp-ai-concurrency-guard-subscriber.php` | **New** | ~120 |
| `includes/security/class-wp-mcp-ai-cost-tracker-subscriber.php` | **New** | ~90 |
| `includes/exceptions/class-wp-mcp-ai-concurrency-limit-reached.php` | **New** | ~30 |
| `includes/exceptions/class-wp-mcp-ai-cost-budget-exceeded.php` | **New** | ~30 |
| `includes/class-wp-mcp-ai-rest.php` | Modify (add catch blocks, async decision, unified method) | ~80 |
| `includes/agents-init.php` | Modify (register subscribers) | ~10 |
| `tests/security/test-concurrency-guard-subscriber.php` | **New** | ~100 |
| `tests/security/test-cost-tracker-subscriber.php` | **New** | ~100 |
| `tests/rest/test-tools-endpoint.php` | Modify | ~50 |

### Wave 2 — v1.2.0

| File | Action | LOC |
|---|---|---|
| `includes/security/class-wp-mcp-ai-load-guard.php` | **New** | ~80 |
| `includes/security/class-wp-mcp-ai-provider-circuit-breaker.php` | **New** | ~110 |
| `includes/services/class-wp-mcp-ai-tool-async-executor.php` | Modify (queue depth, priority propagation) | ~60 |
| `includes/class-wp-mcp-ai-rest.php` | Modify (unified execution method, priority propagation) | ~50 |
| `includes/class-wp-mcp-ai-rest-mcp-methods.php` | Modify (priority param) | ~20 |
| `includes/security/class-wp-mcp-ai-request-guard.php` | Modify (SSE delegation) | ~30 |
| `includes/class-wp-mcp-ai-queue-manager.php` | Modify (feature flag) | ~15 |
| `includes/class-wp-mcp-ai-openai-client.php` | Modify (circuit breaker) | ~15 |
| `includes/class-wp-mcp-ai-gemini-client.php` | Modify (circuit breaker) | ~15 |
| `includes/class-wp-mcp-ai-anthropic-client.php` | Modify (circuit breaker) | ~15 |
| `includes/agents-init.php` | Modify (register new subscribers) | ~5 |
| `tests/security/test-load-guard.php` | **New** | ~80 |
| `tests/security/test-provider-circuit-breaker.php` | **New** | ~120 |
| `tests/security/test-sse-rate-limiter.php` | **New** | ~100 |
| `tests/services/test-async-executor-backpressure.php` | **New** | ~100 |
| `tests/rest/test-mcp-priority.php` | **New** | ~80 |

---

## Testing Strategy

### Unit Tests
- Each new subscriber class gets dedicated unit tests exercising:
  - Happy path (tool executes, guard allows)
  - At-capacity path (returns 429)
  - Missing-dependency path (class not loaded → no-op)
  - Stack correctness (acquire/release paired correctly)
- Circuit breaker: state transitions (closed → open → half_open → closed)
- Load guard: capacity threshold logic
- Async executor: queue depth counting, priority propagation

### Integration Tests
- REST-level test using `WP_REST_Server`: send a `tools/call` request that triggers concurrency limit → assert JSON error envelope with 429 status.
- REST-level test: send `tools/call` over budget → assert 429 with budget details.
- SSE integration: connect 6 times from same user → 6th connection rejected.

### Regression Tests
- Existing tool execution tests continue to pass (no tool behavior changes — gates are additive).
- Existing chat/agentic-loop tests pass (unified method preserves loop-specific logic).
- `composer run lint:errors-only` clean on all changed files.

### Manual QA
- Enable cost budgets in admin → run a DALL-E tool → verify budget enforcement.
- Flood image generation with 4 concurrent requests → verify 4th receives 429.
- Configure RabbitMQ → verify feature flag prevents activation unless explicitly enabled.

---

## Rollout Plan

1. **Wave 1 (v1.1.44):** Merge C1, C2, H3 tasks. Release as patch. These are purely additive — they wire existing, tested classes into the execution path. Zero behavioral change for requests within limits.
2. **Wave 2 (v1.2.0):** Merge H4–M10 + circuit breaker. Release as minor. These add new enforcement points (backpressure, circuit breaking) that change behavior under overload — include feature flags for gradual rollout if desired.

## Rollback Plan

All Wave 1 changes are additive hook subscribers. To disable:
```php
remove_action( 'wp_mcp_ai_before_tool_execution', array( 'WP_MCP_AI_Concurrency_Guard_Subscriber', 'on_before' ), 3 );
remove_action( 'wp_mcp_ai_before_tool_execution', array( 'WP_MCP_AI_Cost_Tracker_Subscriber', 'on_before' ), 2 );
```

Or add a global feature flag:
```php
define( 'WP_MCP_AI_ENABLE_EXECUTION_GATES', false );
```
Checked at the top of each subscriber's `on_before()`.
