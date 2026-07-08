# Implementation Plan 012: RabbitMQ Wiring — Closing the Gaps

**Date:** 2026-07-07  
**Status:** Draft  
**Branch:** (to be created)  
**Related:** [Proposal 009](./009-rabbitmq-integration-proposal.md) · [Proposal 010](./010-infrastructure-scaling-mitigation.md) · [Proposal 011](./011-queue-worker-implementation-plan.md)  
**Predecessor PR:** `feat/infrastructure-scaling-queue-worker` (merged — DB tables, health endpoint, queue worker CLI)

---

## Executive Summary

The RabbitMQ integration is ~40% complete. A fully coded `WP_MCP_AI_RabbitMQ_Client`
(622 lines) with 4 exchanges, 6 queues, dead-letter routing, and priority queues
exists — but it is **never loaded** by the bootstrap, so none of the code executes
at runtime. A `WP_MCP_AI_Queue_Manager` (~600 lines) with sync/async routing logic
also sits dormant. This plan closes the bootstrap gap, fixes two architectural
bugs in the tool-interception hook, wires RabbitMQ consumption into the queue
worker, and adds comprehensive test coverage.

**Total estimated effort: 5–7 working days.**

---

## 1. Audit Findings

### 1.1 Files Not Loaded (Blocker)

`includes/bootstrap/loader.php` never requires:

| File | Class | Status |
|------|-------|--------|
| `includes/class-wp-mcp-ai-rabbitmq-client.php` | `WP_MCP_AI_RabbitMQ_Client` | **Never loaded** |
| `includes/class-wp-mcp-ai-queue-manager.php` | `WP_MCP_AI_Queue_Manager` | **Never loaded** |

The existing test file `tests/test-queue-manager.php` works around this with
`class_exists()` guards — every test is skipped because the class is never loaded.

### 1.2 Hook Pattern Mismatch (Bug)

The `WP_MCP_AI_Queue_Manager` hooks into `wp_mcp_ai_before_tool_execute` via
`add_filter()`, expecting to return a deferred-result array to intercept tool
execution:

```php
// includes/class-wp-mcp-ai-queue-manager.php:93
add_filter( 'wp_mcp_ai_before_tool_execute', array( $this, 'maybe_queue_tool_execution' ), 5, 3 );
```

But the hook is fired as `do_action()` in the trait, **not** `apply_filters()`:

```php
// includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php:193
do_action( 'wp_mcp_ai_before_tool_execute', $arguments, $context, $this->get_slug() );
```

In WordPress, `do_action()` calls all callbacks but **discards return values**.
The queue manager's deferred-result interception can never work — the tool
always executes inline regardless.

### 1.3 Parameter Order Mismatch (Bug)

The trait fires the hook with signature `($arguments, $context, $tool_slug)`.
If it were `apply_filters()`, the first argument (`$arguments`) becomes the
"filter value" that callbacks modify. But `maybe_queue_tool_execution()` expects
`($result, $tool_name, $arguments)` — a completely different signature:

| Position | Trait passes | Queue Manager expects |
|----------|-------------|----------------------|
| 1st | `$arguments` (tool args array) | `$result` (current filter value) |
| 2nd | `$context` (execution context) | `$tool_name` (string) |
| 3rd | `$tool_slug` (string) | `$arguments` (array) |

The `WP_MCP_AI_Agentic_Workflow_Optimizer::check_tool_cache()` callback has the
same incorrect signature. It works only because `do_action()` discards the return
value and the callback operates via side effects on `$this`.

### 1.4 Queue Worker Only Processes DB Queue

`bin/queue-worker.php` only calls `WP_MCP_AI_Job_Queue_Manager::process_queue()` —
it has no RabbitMQ consumer mode. The CLI `--queue` flag described in Proposal
011 is not implemented; the worker only reads from the custom DB table.

### 1.5 Missing Test Coverage

- `tests/test-queue-manager.php` — all 7 tests `markTestSkipped()` because the class is never loaded
- `tests/test-rabbitmq-client.php` — does not exist
- No integration test verifies the end-to-end tool-dispatch → queue → worker path

---

## 2. What Already Works (No Changes Needed)

| Component | File | Status |
|-----------|------|--------|
| RabbitMQ Client (exchanges, queues, pub, health check) | `includes/class-wp-mcp-ai-rabbitmq-client.php` | ✅ Complete |
| Queue Manager (sync/async routing, parallel exec, stats) | `includes/class-wp-mcp-ai-queue-manager.php` | ✅ Complete |
| Admin Settings Section (UI, AJAX, status widget) | `includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php` | ✅ Complete |
| WP-CLI Commands (status, test, setup, list, send, worker) | `includes/class-wp-mcp-ai-cli-command.php` | ✅ Complete |
| Settings defaults (13 `rabbitmq_*` keys) | `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | ✅ Complete |
| Simple Settings Saver (field type mappings) | `includes/admin/class-wp-mcp-ai-simple-settings-saver.php` | ✅ Complete |
| REST Health Endpoint (includes rabbitmq check) | `includes/rest/class-wp-mcp-ai-rest-health.php` | ✅ Complete |
| DB-backed Job Queue (custom table, SKIP LOCKED) | `includes/class-wp-mcp-ai-job-queue-manager.php` | ✅ Complete |
| Dead Letter Queue (custom table) | `includes/class-wp-mcp-ai-dead-letter-queue.php` | ✅ Complete |
| SLA Manager | `includes/class-wp-mcp-ai-sla-manager.php` | ✅ Complete |
| Queue Worker CLI (DB-only mode) | `bin/queue-worker.php` | ✅ Complete |
| Graceful Degradation (DB fallback when RabbitMQ unavailable) | `class-wp-mcp-ai-queue-manager.php` | ✅ Complete |

---

## 3. Implementation Steps

### Step 1: Load the Core Classes (15 min)

**File:** `includes/bootstrap/loader.php`

Add two `require_once` lines after the existing queue/sla loading:

```php
// After: require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sla-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rabbitmq-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-queue-manager.php';
```

**Rationale:** The client has no side effects on load (pure class definition + constants).
The queue manager's constructor calls `init_hooks()` which registers the filter
and AJAX handler. These hooks are safe to register even when RabbitMQ is
unavailable — `is_queue_available()` gates all queue operations.

### Step 2: Fix the Tool-Interception Hook (1 day)

#### 2a. Convert to `apply_filters()` in the Trait

**File:** `includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php`

Change the `do_before_execute()` method to use `apply_filters()` and return
the result so that filter callbacks can intercept execution:

```php
protected function do_before_execute( $arguments, $context ) {
    /**
     * Filter before any tool execution. Return a non-null value to
     * intercept and skip the normal execute() call.
     *
     * @since 1.0.0
     *
     * @param mixed  $pre       Null to continue, or a result to return early.
     * @param string $tool_slug Tool slug identifier.
     * @param array  $arguments Tool arguments.
     * @param array  $context   Execution context.
     */
    $pre = apply_filters(
        'wp_mcp_ai_before_tool_execute',
        null,
        $this->get_slug(),
        $arguments,
        $context
    );

    if ( null !== $pre ) {
        return $pre;
    }

    /** ... existing dynamic tool-specific action ... */
    do_action( "wp_mcp_ai_before_tool_execute_{$this->get_slug()}", $arguments, $context );

    return null;
}
```

**Important design notes:**

- The first parameter is `null` (not `$arguments`). This makes it a proper
  "pre-filter" pattern: return non-null to intercept.
- The **parameter order** is `($pre, $tool_slug, $arguments, $context)` —
  tool slug comes second so callbacks know which tool is being intercepted.
- `$arguments` comes third so callbacks that need to modify arguments can
  still do so.
- The existing `do_action()` for the dynamic `_{tool_slug}` variant is
  preserved for backward compatibility (other listeners like the harness
  necessity gate use it for side-effect logging, not interception).

#### 2b. Update All Filter Callbacks

Three callers need signature updates:

**`WP_MCP_AI_Queue_Manager::maybe_queue_tool_execution()`**  
*Current:* `($result, $tool_name, $arguments)`  
*New:* `($pre, $tool_name, $arguments, $context)`

**`WP_MCP_AI_Agentic_Workflow_Optimizer::check_tool_cache()`**  
*Current:* `($result, $tool_slug, $arguments)`  
*New:* `($pre, $tool_slug, $arguments, $context)`

**`WP_MCP_AI_Necessity_Gate::evaluate()`**  
*Current:* hooked at priority 5 with 3 params  
*Needs:* update to accept 4 params (the first being `$pre`)

Each must also update the `add_filter()` call's `$accepted_args` parameter:

```php
// Before:
add_filter( 'wp_mcp_ai_before_tool_execute', array( $this, 'maybe_queue_tool_execution' ), 5, 3 );
// After:
add_filter( 'wp_mcp_ai_before_tool_execute', array( $this, 'maybe_queue_tool_execution' ), 5, 4 );
```

#### 2c. Update `execute()` Methods in All Tools

Each tool's `execute()` method currently calls:

```php
$this->do_before_execute( $arguments, $context );
```

It must now check the return value:

```php
$intercepted = $this->do_before_execute( $arguments, $context );
if ( null !== $intercepted ) {
    return $intercepted;
}
```

**Affected files** (16 tools using the trait, grep shows each calls
`do_before_execute()` explicitly):

- `class-wp-mcp-ai-tool-2fa-setup-assistant.php`
- `class-wp-mcp-ai-tool-auto-categorize-content.php`
- `class-wp-mcp-ai-tool-content-freshness-checker.php`
- `class-wp-mcp-ai-tool-content-recommendation-engine.php`
- `class-wp-mcp-ai-tool-generate-post-excerpt.php`
- `class-wp-mcp-ai-tool-gutenberg-block-pattern-generator.php`
- `class-wp-mcp-ai-tool-image-alt-text-optimizer.php`
- `class-wp-mcp-ai-tool-image-format-batch-converter.php`
- `class-wp-mcp-ai-tool-login-security-monitor.php`
- `class-wp-mcp-ai-tool-media-library-optimizer.php`
- `class-wp-mcp-ai-tool-password-strength-analyzer.php`
- `class-wp-mcp-ai-tool-performance-optimizer-assistant.php`
- `class-wp-mcp-ai-tool-responsive-image-validator.php`
- `class-wp-mcp-ai-tool-seo-meta-optimizer.php`
- `class-wp-mcp-ai-tool-suggest-internal-links.php`
- `class-wp-mcp-ai-tool-user-activity-auditor.php`

**Mitigation:** Create a helper trait method or base-class pattern to reduce
the per-file change surface. A static helper in the trait could encapsulate
the `do_before_execute()` → check → early-return pattern:

```php
/**
 * In each tool's execute():
 * $early = $this->maybe_intercept( $arguments, $context );
 * if ( null !== $early ) { return $early; }
 */
```

Or better: make `do_before_execute()` throw a special exception that the
tool registry catches — but this defeats the purpose of the filter pattern.

**Industry precedent:** Laravel's `Dispatchable` trait and `ShouldQueue`
interface use a similar pattern — the `dispatch()` helper checks if the job
implements `ShouldQueue` and routes it to the queue driver instead of
executing immediately. Our approach mirrors this: the trait-level filter
allows the queue manager to declare "queue this, don't execute inline."

### Step 3: Wire RabbitMQ Consumer into Queue Worker (1 day)

**File:** `bin/queue-worker.php`

Add a RabbitMQ consumer mode gated by a `--rabbitmq` CLI flag:

```php
// New CLI flag:
$use_rabbitmq = isset( $options['rabbitmq'] );

// In the worker loop:
if ( $use_rabbitmq ) {
    $result = self::process_rabbitmq_queue();
} else {
    $result = WP_MCP_AI_Job_Queue_Manager::process_queue( 3 );
}
```

**RabbitMQ consumer loop pattern** (industry standard, following the PHP AMQP
tutorials and Laravel Horizon patterns):

```php
private static function process_rabbitmq_queue() {
    if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
        fwrite( STDERR, "RabbitMQ client not available.\n" );
        return array( 'processed' => 0 );
    }

    $client = WP_MCP_AI_RabbitMQ_Client::get_instance();

    if ( ! $client->is_available() ) {
        fwrite( STDERR, "RabbitMQ is not available.\n" );
        return array( 'processed' => 0 );
    }

    $channel  = $client->get_channel();
    $processed = 0;

    // Consume from the tool.execution queue (normal priority).
    $queue = new AMQPQueue( $channel );
    $queue->setName( $client->get_queue_name( 'tool.execution' ) );

    // Consume one message at a time with manual ack.
    $queue->consume( function ( AMQPEnvelope $envelope, AMQPQueue $queue ) use ( &$processed, &$should_exit ) {
        $message = json_decode( $envelope->getBody(), true );

        if ( ! $message || ! isset( $message['tool_name'] ) ) {
            $queue->nack( $envelope->getDeliveryTag(), AMQP_REQUEUE );
            return;
        }

        try {
            $registry = WP_MCP_AI_Tool_Registry::get_instance();
            $result   = $registry->execute_tool(
                $message['tool_name'],
                $message['arguments'],
                $message['context']
            );

            // Store the result so the agentic loop can poll it.
            $client->store_job_result(
                $message['job_id'],
                $result,
                is_wp_error( $result ) ? 'error' : 'success'
            );

            $queue->ack( $envelope->getDeliveryTag() );
            $processed++;

        } catch ( Exception $e ) {
            WP_MCP_AI_Logger::log_error(
                'Queue worker tool execution failed',
                array(
                    'job_id'    => $message['job_id'],
                    'tool_name' => $message['tool_name'],
                    'error'     => $e->getMessage(),
                )
            );

            // Don't requeue — let TTL push to dead-letter exchange.
            $queue->nack( $envelope->getDeliveryTag(), AMQP_NOPARAM );
        }

        // Stop consuming if signal received or memory limit approaching.
        if ( $should_exit ) {
            $queue->cancelConsumer();
        }
    } );

    return array( 'processed' => $processed );
}
```

**Design decisions (aligned with RabbitMQ best practices):**

| Decision | Industry Rationale |
|----------|-------------------|
| Manual ACK (`AMQP_AUTOACK = false`) | Only ACK after successful tool execution. If worker crashes mid-job, message redelivers to another consumer. Standard work-queue pattern per [RabbitMQ Tutorial 2](https://www.rabbitmq.com/tutorials/tutorial-two-php.html). |
| NACK without requeue on failure | Failed tools go to dead-letter exchange via per-queue TTL. Avoids infinite retry loops. This is the recommended pattern for transient failures that shouldn't be retried immediately. |
| JSON message envelope | Language-agnostic format. Allows future non-PHP workers (Node.js, Go) to consume the same queues. Industry standard for cross-service messaging. |
| `delivery_mode=2` (persistent) in publisher | Messages survive RabbitMQ restart. Already implemented in `WP_MCP_AI_RabbitMQ_Client::publish()`. |
| QoS prefetch=1 | Each worker processes one message at a time. Prevents a slow worker from hoarding messages while faster workers idle. Standard fairness pattern. Already set in `connect()` at line 284. |

### Step 4: Add RabbitMQ Mode to Queue Worker Deployment Docs (30 min)

**File:** `bin/queue-worker.php` (docblock)  
**File:** `docs/operations/queue-worker-systemd.md` (if it exists)

Update the Cloudways Cron examples to show both modes:

```bash
# DB-only mode (default, works everywhere):
php bin/queue-worker.php --timeout=55

# RabbitMQ mode (Cloudways with RabbitMQ enabled):
php bin/queue-worker.php --rabbitmq --daemon --memory-limit=256M
```

### Step 5: Add Test Coverage (1.5 days)

#### 5a. Unit Tests for `WP_MCP_AI_RabbitMQ_Client`

**New file:** `tests/test-rabbitmq-client.php`

```php
/**
 * @group rabbitmq
 */
class Test_RabbitMQ_Client extends WP_UnitTestCase {

    public function test_get_instance_returns_singleton() { ... }
    public function test_is_available_returns_false_when_disabled() { ... }
    public function test_is_available_returns_false_without_amqp_extension() { ... }
    public function test_config_loads_from_settings() { ... }
    public function test_config_prefers_constants_over_settings() { ... }
    public function test_health_check_returns_disabled_when_not_enabled() { ... }
    public function test_health_check_returns_extension_missing_without_amqp() { ... }
    public function test_get_queue_name_prepends_prefix() { ... }
    public function test_queue_tool_execution_sanitizes_tool_name() { ... }
    public function test_queue_tool_execution_returns_false_when_unavailable() { ... }
    public function test_publish_returns_false_when_unavailable() { ... }
    public function test_get_job_result_returns_null_for_unknown_job() { ... }
    public function test_exchange_definitions_have_required_keys() { ... }
    public function test_queue_definitions_have_required_keys() { ... }
}
```

#### 5b. Fix and Expand Queue Manager Tests

**File:** `tests/test-queue-manager.php` (existing)

Remove `class_exists()` guards (the class will be loaded after Step 1).
Add:

```php
public function test_get_execution_mode_returns_async_for_known_async_tools() { ... }
public function test_get_execution_mode_returns_sync_when_queue_unavailable() { ... }
public function test_queue_tool_returns_false_when_rabbitmq_unavailable() { ... }
public function test_maybe_queue_tool_execution_returns_null_for_sync_mode() { ... }
public function test_can_parallelize_returns_true_for_stateless_tools() { ... }
```

#### 5c. Hook Integration Test

**New file:** `tests/test-rabbitmq-tool-interception.php`

Test the end-to-end interception path:

```php
public function test_filter_can_intercept_tool_execution() {
    // Register a filter callback that returns a deferred result.
    add_filter( 'wp_mcp_ai_before_tool_execute', function( $pre, $tool_slug, $arguments, $context ) {
        return array( '_deferred' => true, 'tool' => $tool_slug );
    }, 10, 4 );

    // Create a tool instance and call execute().
    // Assert that the intercepted result is returned instead of normal execution.
}
```

### Step 6: PHPCS, Lint, and Static Analysis (30 min)

```bash
# Lint the modified files
composer run lint

# PHP compatibility check
composer run lint:compat

# Run the affected test groups
vendor/bin/phpunit --group rabbitmq

# Full test suite
composer run test
```

---

## 4. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Changing `do_action` → `apply_filters` breaks existing tool-side-effect listeners | Medium | High | Preserve old `do_action` for `_{tool_slug}` variant; only the generic hook changes. Audit all 3 filter subscribers. |
| 16-tool per-file changes introduce merge conflicts | Medium | Medium | Use a helper trait method to centralize the check. Or introduce a `Tool_Execute_Pipeline` orchestrator class. |
| RabbitMQ consumer mode memory leak in long-running daemon | Medium | Medium | The existing `memory_limit` watchdog (90% threshold) already handles this. Consumer loop exits and systemd/cron restarts. |
| `AMQPQueue::consume()` blocks forever, preventing signal handling | Low | Medium | Use `pcntl_signal_dispatch()` inside the consume callback. Set a `$should_exit` flag on SIGTERM and call `cancelConsumer()`. Laravel Horizon uses the same pattern. |
| Test environment needs AMQP extension | Low | Low | Tests mock the `WP_MCP_AI_RabbitMQ_Client` singleton or use `@runInSeparateProcess` with extension checks. PHPUnit `@requires` annotation skips when `ext-amqp` is absent. |

---

## 5. Execution Order

```
Step 1: Load classes in bootstrap/loader.php         ← 15 min, no test impact
   ↓
Step 2a: Convert do_action → apply_filters in trait  ← core API change
Step 2b: Update 3 filter callbacks                    ← dependent on 2a
Step 2c: Update 16 tool execute() methods             ← dependent on 2a
   ↓
Step 3: Add RabbitMQ consumer to queue-worker.php     ← independent of Step 2
   ↓
Step 4: Update deployment docs                        ← independent
   ↓
Step 5a: Write RabbitMQ client unit tests             ← depends on Step 1
Step 5b: Fix & expand queue manager tests             ← depends on Steps 1, 2
Step 5c: Write hook integration test                  ← depends on Step 2
   ↓
Step 6: Run lint, phpcs, compat, and test suite       ← final gate
```

---

## 6. Industry Best Practices Applied

### 6.1 PHP AMQP Extension vs php-amqplib

The codebase correctly targets `ext-amqp` (the PECL C extension), which is
the right choice for Cloudways: it's **pre-installed** when RabbitMQ is
enabled, is **2–5× faster** than pure-PHP `php-amqplib` for message throughput,
and has **native connection pooling** in persistent-connection mode. Adding
a `php-amqplib` fallback is unnecessary — graceful degradation to the DB queue
already handles environments without the extension.

### 6.2 Worker Lifecycle Pattern

Following the **Laravel Horizon** worker lifecycle pattern:

1. **Bootstrap → Consume Loop → Graceful Shutdown**
2. Memory watchdog at 90% of limit
3. SIGTERM → finish current job → `cancelConsumer()` → exit
4. `flock()`-based mutual exclusion prevents duplicate workers

The `bin/queue-worker.php` already implements #1, #2, and #4. The RabbitMQ
consumer mode (Step 3) adds #3 via `pcntl_signal` inside the consume callback.

### 6.3 Dead Letter Queue Pattern

Following the **RabbitMQ dead-letter** pattern (not the custom DB DLQ):

- Per-queue `x-message-ttl` → expired messages auto-route to `deadletter` exchange
- `x-dead-letter-exchange` on each queue forwards nack'd messages
- `deadletter.queue` (24h TTL) holds failed messages for inspection
- Separate from the existing `WP_MCP_AI_Dead_Letter_Queue` (which handles
  non-RabbitMQ failures like webhook retries)

When RabbitMQ is active, the DB-backed DLQ is **not** the primary failure
sink — failed tool messages go to the RabbitMQ dead-letter exchange. The DB
DLQ remains the fallback for non-RabbitMQ paths.

### 6.4 Retry with Exponential Backoff (Future)

The current design uses **TTL-based dead-lettering** (message expires from
queue → DLQ). A more sophisticated approach (deferred to a follow-up PR)
would add **intermediate retry queues with increasing TTLs**:

```
Main Queue (TTL 5m)
  → Retry Queue 1 (TTL 30s)
    → Retry Queue 2 (TTL 2m)
      → Retry Queue 3 (TTL 10m)
        → Dead Letter Queue (TTL 24h)
```

This is the industry-standard pattern described in
[OneUptime's RabbitMQ DLQ Guide](https://oneuptime.com/blog/post/2026-02-20-rabbitmq-dead-letter-queues/view)
and the [RabbitMQ exponential backoff gist](https://gist.github.com/mpskovvang/6f48b60338d08781b476785455436080).

### 6.5 Graceful Degradation

Following the **two-tier fallback** pattern (industry standard for
plugin ecosystems where external services are optional):

```
Tool Execution Request
  │
  ├── RabbitMQ available?
  │   ├── YES → queue_tool_execution() → RabbitMQ exchange → worker consumes
  │   └── NO  ↓
  ├── Custom DB table available?
  │   ├── YES → enqueue_job() → cron-processed queue worker
  │   └── NO  ↓
  └── Execute inline (synchronous fallback)
```

This is already architected in `WP_MCP_AI_Queue_Manager::get_execution_mode()`
(returns `MODE_SYNC` when `is_queue_available()` returns false).

---

## 7. Files Changed Summary

```
MODIFIED:
  includes/bootstrap/loader.php                                     (+2 lines)
  includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php        (~15 lines changed)
  includes/class-wp-mcp-ai-queue-manager.php                        (~5 lines, signature fix)
  includes/class-wp-mcp-ai-agentic-workflow-optimizer.php           (~5 lines, signature fix)
  includes/harness/class-wp-mcp-ai-necessity-gate.php               (~5 lines, signature fix)
  includes/tools/class-wp-mcp-ai-tool-*.php                         (16 files, ~3 lines each)
  bin/queue-worker.php                                              (~80 lines added)

NEW:
  tests/test-rabbitmq-client.php                                    (~200 lines)
  tests/test-rabbitmq-tool-interception.php                         (~100 lines)
  docs/project/proposals/012-rabbitmq-wiring-implementation-plan.md (this file)

NO CHANGES NEEDED:
  includes/class-wp-mcp-ai-rabbitmq-client.php
  includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php
  includes/class-wp-mcp-ai-job-queue-manager.php
  includes/class-wp-mcp-ai-dead-letter-queue.php
  includes/class-wp-mcp-ai-sla-manager.php
  All 13 settings-default entries
```

---

## 8. Acceptance Criteria

1. **Classes loaded at runtime:** `class_exists('WP_MCP_AI_RabbitMQ_Client')` returns `true` after plugin load.
2. **Tool interception works:** When a tool flagged `async` is called, the queue manager returns a `{_deferred: true, job_id: ...}` envelope instead of executing inline.
3. **Queue worker consumes RabbitMQ:** `php bin/queue-worker.php --rabbitmq` connects to RabbitMQ, consumes from `tool.execution`, executes tools, acks/nacks correctly.
4. **Graceful fallback unchanged:** When RabbitMQ is disabled/unavailable, tools execute inline or via DB queue — no errors, no warnings.
5. **All existing tool tests pass:** No regressions from the `do_before_execute()` signature change.
6. **PHPCS passes:** Zero errors on all modified files.
7. **New tests pass:** `composer run test -- --group rabbitmq` shows green.

---

## 9. Deferred to Follow-Up PRs

| Item | Proposal Reference |
|------|-------------------|
| SSE real-time result delivery via Redis pub/sub | Proposal 009, Phase 4 |
| Admin dashboard queue-depth widgets | Proposal 009, Phase 5 |
| DLQ browser in admin UI | Proposal 009, Phase 5 |
| Exponential backoff retry queues | Section 6.4 above |
| WP-CLI `wp nvoos queue migrate` for historical data | Proposal 011 |
| Docker Compose production config with RabbitMQ | Proposal 010 |

---

**Author:** AI Agent (DeepSeek V4 Pro)  
**Reviewers:** (pending)  
**Last Updated:** 2026-07-07
