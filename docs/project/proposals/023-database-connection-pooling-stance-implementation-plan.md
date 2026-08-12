# Implementation Plan: Database Connection Pooling & Service Connection Hardening

**Based on:** Proposal 023 (`docs/project/proposals/023-database-connection-pooling-stance.md`)
**Date:** 2026-08-12
**Status:** Draft
**Target releases:** v1.2.1 (Wave 1), v1.3.0 (Wave 2)

---

## Executive Summary

Six findings from the 2026-08-12 service connection audit, remediated in two waves:

- **Wave 1 (v1.2.1, patch):** Eliminate dual-enqueue write amplification (C1), fix concurrency guard race condition (H2). These are the highest-impact, lowest-risk changes — both fix existing code rather than adding new features.
- **Wave 2 (v1.3.0, minor):** Add PDO persistence, gate polling cron behind transport preference, make batch size configurable, add connection pool health monitoring.

**Files modified:** ~10 base + 2 addon.
**New files:** 1 (Site Health check class).
**Estimated LOC:** ~300 changed/added + ~200 test LOC.

---

## Wave 1 — v1.2.1 (patch)

### Task C1-1 — Gate Action Scheduler Fallback Enqueue Behind Queue Worker Check

**File:** `lib/wordpress-adapter/src/Adapter/QueueClient.php`
**Lines:** 42–70
**Risk:** Low — only affects behaviour when RabbitMQ is active.
**Test:** Verify that `enqueue()` creates exactly 1 transport record (RabbitMQ publish OR AS enqueue, not both).

#### Current code (lines 42–70, simplified):

```php
// 2. If RabbitMQ is available, publish to broker for distributed processing.
if ( $this->isRabbitMqAvailable() ) {
    \WP_MCP_AI_RabbitMQ_Client::get_instance()->publish(
        'tools', 'execute.normal',
        array( 'job_id' => $jobId, 'handler' => $handler, 'payload' => $payload, 'user_id' => \get_current_user_id() )
    );

    // ALWAYS enqueues to AS even when RabbitMQ is active
    if ( \function_exists( 'as_enqueue_async_action' ) ) {
        \as_enqueue_async_action( $handler, \array_merge( $payload, array( '_job_id' => $jobId ) ), $groupId, $unique, $options['priority'] ?? 10 );
    }

    return $jobId;
}
```

#### Target code:

```php
// 2. If RabbitMQ is available, publish to broker for distributed processing.
if ( $this->isRabbitMqAvailable() ) {
    \WP_MCP_AI_RabbitMQ_Client::get_instance()->publish(
        'tools', 'execute.normal',
        array( 'job_id' => $jobId, 'handler' => $handler, 'payload' => $payload, 'user_id' => \get_current_user_id() )
    );

    // Only enqueue to AS as fallback when no dedicated queue worker
    // is configured to consume from RabbitMQ.
    if ( ! $this->isDedicatedQueueWorkerActive() && \function_exists( 'as_enqueue_async_action' ) ) {
        \as_enqueue_async_action(
            $handler,
            \array_merge( $payload, array( '_job_id' => $jobId ) ),
            $groupId,
            $unique,
            $options['priority'] ?? 10,
        );
    }

    return $jobId;
}
```

#### New method to add:

```php
/**
 * Check whether a dedicated queue worker (binary or daemon) is configured
 * to consume jobs from RabbitMQ, making the Action Scheduler fallback
 * unnecessary.
 *
 * @since 1.2.1
 *
 * @return bool True when AS fallback enqueue should be suppressed.
 */
private function isDedicatedQueueWorkerActive(): bool {
    // Gate 1: RabbitMQ must be enabled.
    if ( ! \class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
        return false;
    }
    try {
        if ( ! \WP_MCP_AI_RabbitMQ_Client::get_instance()->is_available() ) {
            return false;
        }
    } catch ( \Exception $e ) {
        return false;
    }

    // Gate 2: A dedicated queue worker must be explicitly opted into.
    // This is set by the site operator when they deploy bin/queue-worker.php
    // via cron, systemd, or K8s.
    $dedicated_worker = \get_option( 'wp_mcp_ai_queue_worker_dedicated', false );

    /**
     * Filter whether a dedicated queue worker is active.
     *
     * Allows programmatic control (e.g., via wp-config.php constant) for
     * sites that deploy the worker through infrastructure-as-code.
     *
     * @since 1.2.1
     *
     * @param bool $dedicated_worker Whether a dedicated worker is active.
     */
    return (bool) \apply_filters( 'wp_mcp_ai_queue_worker_dedicated', $dedicated_worker );
}
```

#### New admin setting to add (in `includes/admin/class-wp-mcp-ai-admin-settings-base.php`):

```php
'queue_worker_dedicated' => false,  // Whether a dedicated queue worker binary/daemon is deployed
```

#### Activation guard (in `bin/queue-worker.php`):

When the worker starts in `--rabbitmq` mode, set a heartbeat transient:

```php
// After bootstrap, set heartbeat so QueueClient knows a worker is active.
set_transient( 'wp_mcp_ai_queue_worker_heartbeat', time(), 120 );
```

The `isDedicatedQueueWorkerActive()` method can optionally check this heartbeat.

**Acceptance criteria:**
- [ ] When no RabbitMQ: `enqueue()` behaves identically to current (AS or WP-Cron fallback)
- [ ] When RabbitMQ is active AND `queue_worker_dedicated` is true: only RabbitMQ publish, no AS enqueue
- [ ] When RabbitMQ is active AND `queue_worker_dedicated` is false: both RabbitMQ publish AND AS fallback (backward compatible)
- [ ] `queue_worker_dedicated` option is false by default (no breaking change for existing installs)
- [ ] The queue worker binary sets a heartbeat transient on startup

---

### Task H2-1 — Make Concurrency Guard Slot Tracking Atomic

**File:** `includes/security/class-wp-mcp-ai-concurrency-guard.php`
**Lines:** 63–102
**Risk:** Medium — changes concurrency slot acquisition mechanism. Test thoroughly with concurrent requests.

#### Problem

The current `get_transient()` → `set_transient()` pattern is not atomic:

```php
public static function acquire( $operation_type ) {
    $key     = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );
    $current = absint( get_transient( $key ) );   // READ
    if ( $current >= $max ) { return WP_Error; }
    set_transient( $key, $current + 1, self::LOCK_TTL );  // WRITE (non-atomic)
    return true;
}
```

Under concurrent execution, two workers can both read $current=2, both conclude $max=3 is not exceeded, and both write $current=3 — resulting in 4 concurrent operations.

#### Solution: Atomic increment with Redis-aware fallback

```php
/**
 * Acquire a concurrency slot for an operation type.
 *
 * Uses wp_cache_incr() when a persistent object cache (Redis) is
 * available — this is atomic. Falls back to a database-level atomic
 * UPDATE for sites without a persistent cache.
 *
 * @since 1.2.1
 *
 * @param string $operation_type Type from LIMITS (e.g. 'image_generation').
 * @return true|WP_Error True if slot acquired, WP_Error if at capacity.
 */
public static function acquire( $operation_type ) {
    $max     = self::get_limit( $operation_type );
    $key     = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );

    if ( wp_using_ext_object_cache() ) {
        return self::acquire_atomic_cache( $key, $max, $operation_type );
    }

    return self::acquire_atomic_db( $key, $max, $operation_type );
}

/**
 * Atomic acquire via persistent object cache (Redis).
 *
 * wp_cache_incr() is atomic when backed by Redis, Memcached,
 * or any cache backend that supports atomic increment.
 *
 * @since 1.2.1
 *
 * @param string $key            Cache key.
 * @param int    $max            Maximum concurrent slots.
 * @param string $operation_type Operation name for error messages.
 * @return true|WP_Error
 */
private static function acquire_atomic_cache( $key, $max, $operation_type ) {
    $cache_group = 'wp_mcp_ai_concurrency';

    // Initialise if not set (wp_cache_incr returns false for non-existent keys).
    $current = wp_cache_get( $key, $cache_group );
    if ( false === $current ) {
        wp_cache_set( $key, 0, $cache_group, self::LOCK_TTL );
        $current = 0;
    }

    $new_value = wp_cache_incr( $key, 1, $cache_group );

    if ( false === $new_value || (int) $new_value > $max ) {
        // Roll back the increment — we're over capacity.
        wp_cache_decr( $key, 1, $cache_group );
        return new WP_Error(
            'concurrency_limit',
            sprintf(
                /* translators: 1=operation, 2=max count */
                __( 'Maximum %2$d concurrent %1$s operations reached. Please try again later.', 'mcp-ai-wpoos' ),
                esc_html( $operation_type ),
                esc_html( (string) $max )
            )
        );
    }

    return true;
}

/**
 * Atomic acquire via database (InnoDB row-level locking).
 *
 * Uses a dedicated table for sites without persistent object cache.
 * The table uses InnoDB row-level locking which makes the
 * INSERT ... ON DUPLICATE KEY UPDATE pattern atomic.
 *
 * @since 1.2.1
 *
 * @param string $key            Cache key.
 * @param int    $max            Maximum concurrent slots.
 * @param string $operation_type Operation name for error messages.
 * @return true|WP_Error
 */
private static function acquire_atomic_db( $key, $max, $operation_type ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mcp_ai_concurrency_slots';

    // Ensure table exists (idempotent — checked once per request).
    self::ensure_slots_table();

    // Atomic: INSERT with ON DUPLICATE KEY UPDATE checks current < max
    // and increments in a single atomic statement.
    $result = $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table} (slot_key, current_count, expires_at)
         VALUES (%s, 1, DATE_ADD(NOW(), INTERVAL %d SECOND))
         ON DUPLICATE KEY UPDATE
             current_count = IF(current_count < %d, current_count + 1, current_count),
             expires_at = IF(current_count < %d, DATE_ADD(NOW(), INTERVAL %d SECOND), expires_at)",
        $key, self::LOCK_TTL,
        $max,
        $max, self::LOCK_TTL
    ) );

    if ( false === $result ) {
        return new WP_Error(
            'concurrency_db_error',
            __( 'Failed to acquire concurrency slot due to database error.', 'mcp-ai-wpoos' )
        );
    }

    // Check whether we actually got the slot.
    $current = $wpdb->get_var( $wpdb->prepare(
        "SELECT current_count FROM {$table} WHERE slot_key = %s",
        $key
    ) );

    if ( (int) $current > $max ) {
        // We were the unlucky one — release.
        self::release_atomic_db( $key );
        return new WP_Error(
            'concurrency_limit',
            sprintf(
                __( 'Maximum %2$d concurrent %1$s operations reached. Please try again later.', 'mcp-ai-wpoos' ),
                esc_html( $operation_type ),
                esc_html( (string) $max )
            )
        );
    }

    return true;
}

/**
 * Release a concurrency slot.
 *
 * @since 1.0.0
 *
 * @param string $operation_type Operation type.
 * @return void
 */
public static function release( $operation_type ) {
    $key = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );

    if ( wp_using_ext_object_cache() ) {
        $cache_group = 'wp_mcp_ai_concurrency';
        $current     = wp_cache_get( $key, $cache_group );
        if ( false !== $current && (int) $current > 0 ) {
            wp_cache_decr( $key, 1, $cache_group );
        }
        return;
    }

    self::release_atomic_db( $key );
}

/**
 * Release via database.
 *
 * @since 1.2.1
 *
 * @param string $key Slot key.
 * @return void
 */
private static function release_atomic_db( $key ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mcp_ai_concurrency_slots';

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table}
         SET current_count = GREATEST(current_count - 1, 0)
         WHERE slot_key = %s AND current_count > 0",
        $key
    ) );
}
```

#### New helper method:

```php
/**
 * Ensure the concurrency slots table exists.
 *
 * Created once per request lifetime. Uses dbDelta for idempotent schema.
 *
 * @since 1.2.1
 * @return void
 */
private static function ensure_slots_table() {
    static $ensured = false;
    if ( $ensured ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mcp_ai_concurrency_slots';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        slot_key VARCHAR(100) NOT NULL,
        current_count INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (slot_key),
        KEY expires_at (expires_at)
    ) {$charset_collate}";

    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    dbDelta( $sql );

    $ensured = true;
}
```

#### Activation registration:

Add table creation to `includes/bootstrap/activation.php`:

```php
// Create concurrency slots table for atomic slot tracking.
$slots_file = WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-concurrency-guard.php';
if ( file_exists( $slots_file ) ) {
    require_once $slots_file;
    if ( method_exists( 'WP_MCP_AI_Concurrency_Guard', 'create_slots_table' ) ) {
        WP_MCP_AI_Concurrency_Guard::create_slots_table();
    }
}
```

And add the static `create_slots_table()` method to the Concurrency Guard.

#### Expired slot cleanup:

Add a cleanup method wired to the daily cron:

```php
/**
 * Clean up expired concurrency slots.
 *
 * Hooked to wp_mcp_ai_daily cron. Prevents orphaned slots from
 * permanently consuming capacity if a process crashes without releasing.
 *
 * @since 1.2.1
 * @return void
 */
public static function cleanup_expired_slots() {
    global $wpdb;
    $table = $wpdb->prefix . 'mcp_ai_concurrency_slots';

    $wpdb->query(
        "DELETE FROM {$table} WHERE expires_at < NOW()"
    );
}
```

Register in `includes/bootstrap/cron.php` or the consolidated five-minute tick handler.

**Acceptance criteria:**
- [ ] When Redis/object cache is available, acquire/release use atomic `wp_cache_incr`/`wp_cache_decr`
- [ ] When no object cache, acquire/release use InnoDB atomic INSERT ... ON DUPLICATE KEY UPDATE
- [ ] Concurrent test: 10 simultaneous `acquire('image_generation')` with limit=3 — exactly 3 succeed, 7 get WP_Error
- [ ] Expired slots are cleaned up by daily cron
- [ ] Backward compatible: `get_limit()`, `get_usage()`, `release()` signatures unchanged
- [ ] `mcp_ai_concurrency_slots` table created on activation

---

## Wave 2 — v1.3.0 (minor)

### Task H3-1 — Add PDO Persistent Connection Support to Graphify Remote SQL Driver

**File:** `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php`
**Lines:** 344–351 (open_pdo method)
**Risk:** Very low — PDO::ATTR_PERSISTENT is well-tested in PHP-FPM environments. Gated to non-CLI SAPIs.

#### Changes:

```php
/**
 * Cached PDO instances keyed by DSN.
 *
 * @since 1.3.0
 * @var array<string, PDO>
 */
private static $pdo_instances = array();

/**
 * Open (or reuse) a PDO connection to the remote database.
 *
 * Caches the PDO instance per DSN to avoid opening a new TCP connection
 * on every query. Uses ATTR_PERSISTENT on PHP-FPM for connection reuse
 * across requests.
 *
 * @since 1.3.0 Added connection caching and persistence.
 *
 * @return PDO|null PDO instance or null if configuration is missing.
 */
private function open_pdo() {
    $dsn  = $this->build_dsn();
    $user = isset( $this->config['username'] ) ? (string) $this->config['username'] : '';
    $pass = isset( $this->config['password'] ) ? (string) $this->config['password'] : '';

    if ( '' === $dsn ) {
        return null;
    }

    $timeout   = isset( $this->config['connection_timeout'] ) ? max( 1, (int) $this->config['connection_timeout'] ) : 5;
    $cache_key = md5( $dsn . '|' . $user . '|' . $pass );

    // Reuse existing connection if still alive.
    if ( isset( self::$pdo_instances[ $cache_key ] ) ) {
        try {
            self::$pdo_instances[ $cache_key ]->query( 'SELECT 1' );
            return self::$pdo_instances[ $cache_key ];
        } catch ( \PDOException $e ) {
            // Connection lost — remove from cache and recreate.
            unset( self::$pdo_instances[ $cache_key ] );
        }
    }

    $options = array(
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES   => false,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_TIMEOUT            => $timeout,
        // Persistent connections work well with PHP-FPM (Cloudways).
        // Disable in CLI mode (queue worker daemon) to avoid connection
        // leakage across long-running processes.
        \PDO::ATTR_PERSISTENT         => ( \PHP_SAPI !== 'cli' ),
    );

    self::$pdo_instances[ $cache_key ] = new \PDO( $dsn, $user, $pass, $options );
    return self::$pdo_instances[ $cache_key ];
}

/**
 * Force-close all cached PDO connections.
 *
 * Useful in queue worker daemon mode to release connections
 * after a batch completes.
 *
 * @since 1.3.0
 * @return void
 */
public static function close_all_connections() {
    self::$pdo_instances = array();
}
```

**Acceptance criteria:**
- [ ] PDO connections are cached per DSN within a single PHP process
- [ ] `PDO::ATTR_PERSISTENT` is set when running under PHP-FPM (or Apache)
- [ ] `PDO::ATTR_PERSISTENT` is NOT set when running in CLI (queue worker daemon)
- [ ] Dead connections are detected (SELECT 1 ping) and recreated
- [ ] `close_all_connections()` releases all cached PDO instances
- [ ] Existing Graphify functionality is unaffected (SELECT-only queries)

---

### Task H4-1 — Gate Polling Cron Behind Transport Preference

**File:** `includes/class-wp-mcp-ai-async-job-queue.php`
**Lines:** 171–181
**Risk:** Low — cron is merely unscheduled when RabbitMQ is the active transport.

#### Changes:

In `schedule_cron_jobs()`:

```php
public static function schedule_cron_jobs() {
    // If RabbitMQ is the active transport with a dedicated worker,
    // the DB polling cron is unnecessary and wasteful.
    if ( self::is_rabbitmq_primary_transport() ) {
        // Clear any existing polling cron.
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        return;
    }

    // Process queue every minute (DB polling fallback).
    if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
        wp_schedule_event( time(), 'minute', self::CRON_HOOK );
    }

    // Cleanup old jobs daily.
    if ( ! wp_next_scheduled( self::CRON_CLEANUP_HOOK ) ) {
        wp_schedule_event( time(), 'daily', self::CRON_CLEANUP_HOOK );
    }
}

/**
 * Check whether RabbitMQ is the primary job transport.
 *
 * @since 1.3.0
 *
 * @return bool True when RabbitMQ is enabled AND a dedicated worker is configured.
 */
private static function is_rabbitmq_primary_transport() {
    if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
        return false;
    }

    try {
        if ( ! WP_MCP_AI_RabbitMQ_Client::get_instance()->is_available() ) {
            return false;
        }
    } catch ( \Exception $e ) {
        return false;
    }

    return (bool) get_option( 'wp_mcp_ai_queue_worker_dedicated', false );
}
```

**Re-schedule when transport changes:** Add a hook on option update:

```php
// In new subscriber or init hook:
add_action( 'update_option_wp_mcp_ai_settings', array( __CLASS__, 'on_settings_updated' ), 10, 2 );

public static function on_settings_updated( $old_value, $new_value ) {
    $old_rmq     = ! empty( $old_value['rabbitmq_enabled'] );
    $new_rmq     = ! empty( $new_value['rabbitmq_enabled'] );
    $old_worker  = ! empty( $old_value['queue_worker_dedicated'] );
    $new_worker  = ! empty( $new_value['queue_worker_dedicated'] );

    if ( $old_rmq !== $new_rmq || $old_worker !== $new_worker ) {
        // Clear and re-schedule — schedule_cron_jobs() will decide.
        wp_clear_scheduled_hook( self::CRON_HOOK );
        self::schedule_cron_jobs();
    }
}
```

**Acceptance criteria:**
- [ ] When RabbitMQ is enabled AND `queue_worker_dedicated` is true, the `wp_mcp_ai_process_job_queue` cron is unscheduled
- [ ] When RabbitMQ is disabled, the cron is scheduled as before (backward compatible)
- [ ] When settings change (RabbitMQ enabled/disabled), cron is re-evaluated immediately
- [ ] The daily cleanup cron (`wp_mcp_ai_cleanup_job_queue`) always runs regardless of transport

---

### Task M5-1 — Make Queue Worker Batch Size Configurable

**File:** `bin/queue-worker.php`
**Line:** 236
**Risk:** Trivial — filterable value, backward compatible.

#### Change:

```php
// Line 236 — before:
$result = WP_MCP_AI_Job_Queue_Manager::process_queue( 3 );

// After:
$batch_size = (int) apply_filters( 'wp_mcp_ai_queue_worker_batch_size', 3 );
$result     = WP_MCP_AI_Job_Queue_Manager::process_queue( $batch_size );
```

Also add a `--batch-size=N` CLI option for operational control:

```php
$options = getopt( '', array(
    'daemon',
    'rabbitmq',
    'memory-limit:',
    'max-jobs:',
    'timeout:',
    'batch-size:',   // NEW
    'help',
) );

$batch_size = isset( $options['batch-size'] )
    ? absint( $options['batch-size'] )
    : (int) apply_filters( 'wp_mcp_ai_queue_worker_batch_size', 3 );
```

Add to help text:
```
echo "  --batch-size=N      Process N jobs per batch (default: 3)\n";
```

**Acceptance criteria:**
- [ ] Default batch size remains 3 (backward compatible)
- [ ] `--batch-size=N` CLI flag overrides default
- [ ] `wp_mcp_ai_queue_worker_batch_size` filter overrides default
- [ ] Batch size validated as positive integer

---

### Task M6-1 — Add Connection Pool Health Monitoring

**New file:** `includes/admin/class-wp-mcp-ai-site-health-connection-pool.php`

**Risk:** Low — read-only Site Health check.

#### Implementation:

```php
<?php
/**
 * Site Health: Connection Pool Health Check
 *
 * Reports MySQL connection pool saturation, queue depth across all
 * transports, and RabbitMQ connection status.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_MCP_AI_Site_Health_Connection_Pool {

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function register() {
        add_filter( 'site_health_tests', array( __CLASS__, 'add_tests' ) );
    }

    /**
     * Add connection pool tests to Site Health.
     *
     * @param array $tests Existing tests.
     * @return array Modified tests.
     */
    public static function add_tests( $tests ) {
        $tests['direct']['wp_mcp_ai_mysql_connections'] = array(
            'label' => __( 'NV oOS — MySQL Connection Pool', 'mcp-ai-wpoos' ),
            'test'  => array( __CLASS__, 'test_mysql_connections' ),
        );

        $tests['direct']['wp_mcp_ai_queue_depth'] = array(
            'label' => __( 'NV oOS — Queue Depth', 'mcp-ai-wpoos' ),
            'test'  => array( __CLASS__, 'test_queue_depth' ),
        );

        if ( class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
            $tests['direct']['wp_mcp_ai_rabbitmq_health'] = array(
                'label' => __( 'NV oOS — RabbitMQ Health', 'mcp-ai-wpoos' ),
                'test'  => array( __CLASS__, 'test_rabbitmq_health' ),
            );
        }

        return $tests;
    }

    /**
     * Test: MySQL connection pool saturation.
     *
     * @return array Site Health test result.
     */
    public static function test_mysql_connections() {
        global $wpdb;

        // Get current connection count.
        $threads_connected = $wpdb->get_var( "SHOW STATUS LIKE 'Threads_connected'" );
        // The VALUE column is returned by SHOW STATUS.
        $threads = $wpdb->get_var( "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Threads_connected'" );

        if ( null === $threads ) {
            // Fallback: try SHOW STATUS directly.
            $result = $wpdb->get_row( "SHOW STATUS LIKE 'Threads_connected'" );
            $threads = $result ? (int) $result->Value : null;
        }

        $max_connections = $wpdb->get_var( "SELECT @@max_connections" );
        $max_connections = $max_connections ? (int) $max_connections : 151;

        if ( null === $threads ) {
            return array(
                'label'       => __( 'MySQL connection pool status cannot be determined', 'mcp-ai-wpoos' ),
                'status'      => 'recommended',
                'badge'       => array(
                    'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                    'color' => 'orange',
                ),
                'description' => sprintf(
                    '<p>%s</p>',
                    __( 'Could not query MySQL connection status. Ensure the database user has PROCESS or performance_schema privileges.', 'mcp-ai-wpoos' )
                ),
                'test'        => 'wp_mcp_ai_mysql_connections',
            );
        }

        $threads    = (int) $threads;
        $usage_pct  = $max_connections > 0 ? round( ( $threads / $max_connections ) * 100, 1 ) : 0;

        if ( $usage_pct > 80 ) {
            $status = 'critical';
            $color  = 'red';
        } elseif ( $usage_pct > 50 ) {
            $status = 'recommended';
            $color  = 'orange';
        } else {
            $status = 'good';
            $color  = 'blue';
        }

        return array(
            'label'       => sprintf(
                /* translators: 1=current, 2=max, 3=percent */
                __( 'MySQL connections: %1$d of %2$d (%3$s%%)', 'mcp-ai-wpoos' ),
                $threads,
                $max_connections,
                $usage_pct
            ),
            'status'      => $status,
            'badge'       => array(
                'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                'color' => $color,
            ),
            'description' => self::render_connection_advice( $threads, $max_connections, $usage_pct ),
            'test'        => 'wp_mcp_ai_mysql_connections',
        );
    }

    /**
     * Test: Queue depth across all transports.
     *
     * @return array Site Health test result.
     */
    public static function test_queue_depth() {
        global $wpdb;

        $issues = array();

        // DB queue depth.
        $db_table   = $wpdb->prefix . 'mcp_ai_concurrent_jobs';
        $db_pending = $wpdb->get_var( "SELECT COUNT(*) FROM {$db_table} WHERE status = 'pending'" );
        $db_pending = $db_pending ? (int) $db_pending : 0;

        if ( $db_pending > 50 ) {
            $issues[] = sprintf(
                /* translators: %d: number of pending jobs */
                __( '%d pending jobs in DB queue — consider enabling RabbitMQ or increasing batch size.', 'mcp-ai-wpoos' ),
                $db_pending
            );
        }

        // Action Scheduler depth.
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $as_pending = as_get_scheduled_actions( array(
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
                'group'    => 'wp_mcp_ai',
            ) );
            $as_count   = count( $as_pending );

            if ( $as_count > 100 ) {
                $issues[] = sprintf(
                    /* translators: %d: number of pending actions */
                    __( '%d pending Action Scheduler jobs in wp_mcp_ai group.', 'mcp-ai-wpoos' ),
                    $as_count
                );
            }
        }

        // RabbitMQ queue depth (if available).
        if ( class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
            try {
                $rmq  = WP_MCP_AI_RabbitMQ_Client::get_instance();
                if ( $rmq->is_available() ) {
                    $stats = $rmq->get_queue_stats();
                    foreach ( $stats['queues'] ?? array() as $name => $info ) {
                        if ( ! empty( $info['messages'] ) && $info['messages'] > 100 ) {
                            $issues[] = sprintf(
                                /* translators: 1=queue name, 2=message count */
                                __( 'RabbitMQ queue "%1$s" has %2$d messages.', 'mcp-ai-wpoos' ),
                                $name,
                                $info['messages']
                            );
                        }
                    }
                }
            } catch ( \Exception $e ) {
                // Silently skip — not critical.
            }
        }

        if ( empty( $issues ) ) {
            return array(
                'label'       => __( 'Queue depths are healthy', 'mcp-ai-wpoos' ),
                'status'      => 'good',
                'badge'       => array(
                    'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                    'color' => 'blue',
                ),
                'description' => sprintf(
                    '<p>%s</p><p>%s</p>',
                    sprintf(
                        /* translators: %d: pending DB jobs */
                        __( 'DB queue: %d pending jobs.', 'mcp-ai-wpoos' ),
                        $db_pending
                    ),
                    __( 'No transport queues are backed up. The system is processing jobs promptly.', 'mcp-ai-wpoos' )
                ),
                'test'        => 'wp_mcp_ai_queue_depth',
            );
        }

        return array(
            'label'       => __( 'Queue depth requires attention', 'mcp-ai-wpoos' ),
            'status'      => 'recommended',
            'badge'       => array(
                'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                'color' => 'orange',
            ),
            'description' => '<p>' . implode( '</p><p>', array_map( 'esc_html', $issues ) ) . '</p>',
            'test'        => 'wp_mcp_ai_queue_depth',
        );
    }

    /**
     * Test: RabbitMQ connection health.
     *
     * @return array Site Health test result.
     */
    public static function test_rabbitmq_health() {
        try {
            $client = \WP_MCP_AI_RabbitMQ_Client::get_instance();
            $health = $client->health_check();

            if ( 'disabled' === $health['status'] ) {
                return array(
                    'label'       => __( 'RabbitMQ is disabled', 'mcp-ai-wpoos' ),
                    'status'      => 'good',
                    'badge'       => array(
                        'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                        'color' => 'gray',
                    ),
                    'description' => '<p>' . __( 'RabbitMQ integration is not enabled. Consider enabling it for better async job processing on Cloudways.', 'mcp-ai-wpoos' ) . '</p>',
                    'test'        => 'wp_mcp_ai_rabbitmq_health',
                );
            }

            if ( 'healthy' === $health['status'] ) {
                return array(
                    'label'       => __( 'RabbitMQ is connected and healthy', 'mcp-ai-wpoos' ),
                    'status'      => 'good',
                    'badge'       => array(
                        'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                        'color' => 'blue',
                    ),
                    'description' => '<p>' . sprintf(
                        /* translators: %s: host:port */
                        __( 'Connected to RabbitMQ at %s.', 'mcp-ai-wpoos' ),
                        esc_html( $health['connection']['host'] . ':' . $health['connection']['port'] )
                    ) . '</p>',
                    'test'        => 'wp_mcp_ai_rabbitmq_health',
                );
            }

            return array(
                'label'       => __( 'RabbitMQ connection issue', 'mcp-ai-wpoos' ),
                'status'      => 'critical',
                'badge'       => array(
                    'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                    'color' => 'red',
                ),
                'description' => '<p>' . sprintf(
                    /* translators: %s: error message */
                    __( 'RabbitMQ status: %s. Check RabbitMQ settings in NV oOS → Settings.', 'mcp-ai-wpoos' ),
                    esc_html( $health['status'] )
                ) . '</p>',
                'test'        => 'wp_mcp_ai_rabbitmq_health',
            );
        } catch ( \Exception $e ) {
            return array(
                'label'       => __( 'RabbitMQ health check failed', 'mcp-ai-wpoos' ),
                'status'      => 'recommended',
                'badge'       => array(
                    'label' => __( 'Performance', 'mcp-ai-wpoos' ),
                    'color' => 'orange',
                ),
                'description' => '<p>' . esc_html( $e->getMessage() ) . '</p>',
                'test'        => 'wp_mcp_ai_rabbitmq_health',
            );
        }
    }

    /**
     * Render connection pool advice.
     *
     * @param int   $threads    Current thread count.
     * @param int   $max        Max connections.
     * @param float $usage_pct  Usage percentage.
     * @return string HTML description.
     */
    private static function render_connection_advice( $threads, $max, $usage_pct ) {
        $lines = array();

        $lines[] = sprintf(
            '<p>%s</p>',
            sprintf(
                /* translators: 1=current threads, 2=max, 3=percent */
                __( 'Your MySQL server has %1$d active connections out of %2$d maximum (%3$s%%).', 'mcp-ai-wpoos' ),
                $threads,
                $max,
                $usage_pct
            )
        );

        if ( $usage_pct > 80 ) {
            $lines[] = sprintf(
                '<p><strong>%s</strong></p>',
                __( 'Connection pool is critically saturated. Recommended actions:', 'mcp-ai-wpoos' )
            );
            $lines[] = '<ul>';
            $lines[] = '<li>' . __( 'Enable RabbitMQ to offload job processing from the database.', 'mcp-ai-wpoos' ) . '</li>';
            $lines[] = '<li>' . __( 'Install Redis Object Cache to reduce database read load.', 'mcp-ai-wpoos' ) . '</li>';
            $lines[] = '<li>' . __( 'Increase MySQL max_connections in your Cloudways server settings.', 'mcp-ai-wpoos' ) . '</li>';
            $lines[] = '<li>' . __( 'Enable the dedicated queue worker daemon (--daemon mode) to reduce per-request connection overhead.', 'mcp-ai-wpoos' ) . '</li>';
            $lines[] = '</ul>';
        } elseif ( $usage_pct > 50 ) {
            $lines[] = sprintf(
                '<p>%s</p>',
                __( 'Connection pool usage is moderate. Monitor for growth, especially during peak traffic.', 'mcp-ai-wpoos' )
            );
        }

        return implode( "\n", $lines );
    }
}
```

#### Registration in `includes/agents-init.php` or `includes/bootstrap/loader.php`:

```php
// Register Site Health connection pool checks (Pro).
if ( file_exists( WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-site-health-connection-pool.php' ) ) {
    require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-site-health-connection-pool.php';
    WP_MCP_AI_Site_Health_Connection_Pool::register();
}
```

**Acceptance criteria:**
- [ ] "MySQL Connection Pool" test appears in Site Health → Info → NV oOS section
- [ ] Reports Threads_connected vs max_connections with percentage
- [ ] Critical (red) when >80%, recommended (orange) when >50%, good (blue) otherwise
- [ ] "Queue Depth" test shows pending jobs across DB, Action Scheduler, and RabbitMQ
- [ ] "RabbitMQ Health" test shows connection status when RabbitMQ class is available
- [ ] All tests degrade gracefully when SQL permissions are insufficient

---

## File Manifest

### Wave 1 (v1.2.1)

| File | Action | Lines |
|---|---|---|
| `lib/wordpress-adapter/src/Adapter/QueueClient.php` | Modify — gate AS enqueue | +30, -3 |
| `includes/security/class-wp-mcp-ai-concurrency-guard.php` | Modify — atomic acquire/release | +120, -20 |
| `includes/bootstrap/activation.php` | Modify — add concurrency_slots table | +8 |
| `bin/queue-worker.php` | Modify — set heartbeat transient | +5 |
| `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | Modify — add queue_worker_dedicated setting | +1 |
| `tests/test-concurrency-guard-atomic.php` | New — concurrent slot tests | +100 |
| `tests/test-queue-client-transport-selection.php` | New — transport selection tests | +80 |

### Wave 2 (v1.3.0)

| File | Action | Lines |
|---|---|---|
| `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php` | Modify — PDO persistence | +50, -10 |
| `includes/class-wp-mcp-ai-async-job-queue.php` | Modify — gate polling cron | +40, -2 |
| `bin/queue-worker.php` | Modify — configurable batch size | +8, -1 |
| `includes/admin/class-wp-mcp-ai-site-health-connection-pool.php` | **New** — Site Health checks | +280 |
| `includes/bootstrap/loader.php` or `includes/agents-init.php` | Modify — register Site Health | +4 |

---

## Testing Strategy

### Unit Tests

1. **Transport selection:** Verify `enqueue()` creates exactly 1 transport record per scenario (no RMQ, RMQ+worker, RMQ+no worker, no AS, WP-Cron fallback)
2. **Concurrency guard atomicity:** Simulate 10 concurrent `acquire()` calls with limit=3, assert exactly 3 succeed
3. **PDO cache:** Verify `open_pdo()` returns same instance for identical DSN, different instance for different DSN
4. **Cron gating:** Verify `schedule_cron_jobs()` does not schedule `CRON_HOOK` when RabbitMQ is primary transport

### Integration Tests

1. **Queue flow:** Enqueue a job → verify RabbitMQ delivery → verify no AS action created → verify job store status
2. **Concurrency guard + Redis:** With Redis active, verify `wp_cache_incr` is used; without Redis, verify DB atomic path
3. **Site Health output:** Verify all three tests appear and return valid structure
4. **Settings transition:** Toggle `rabbitmq_enabled` settings, verify cron is re-scheduled accordingly

### Manual Smoke Tests

1. Enable RabbitMQ in settings, set `queue_worker_dedicated`, run `bin/queue-worker.php --rabbitmq`, verify jobs flow end-to-end
2. Trigger 10 simultaneous image generation requests, verify at most 3 execute concurrently
3. Check `wp_actionscheduler_actions` table — verify it does not grow with orphaned records when RabbitMQ is active
4. Visit Tools → Site Health → Info → verify NV oOS connection pool checks appear

---

## Rollback Plan

### Wave 1 rollback:
- Revert `QueueClient.php` changes — the old dual-enqueue behaviour is functionally correct (just wasteful)
- Revert concurrency guard changes — the old transient-based guard is functionally correct (just not atomic under extreme concurrency)
- The `mcp_ai_concurrency_slots` table is harmless if left behind; include in uninstall.php cleanup

### Wave 2 rollback:
- Revert PDO persistence changes — remove `ATTR_PERSISTENT` and static cache; connections revert to per-query
- Revert cron gating — DB polling resumes
- Site Health checks can remain — they are read-only and non-disruptive

---

## Dependencies

- Task C1-1 depends on H4-1 (both touch transport selection logic — implement together)
- Task H2-1 is independent of all other tasks
- Task H3-1 (PDO) is independent
- Task M5-1 (batch size) is independent
- Task M6-1 (Site Health) can reference C1-1 and H4-1 state for richer reports

---

## References

- Proposal: `docs/project/proposals/023-database-connection-pooling-stance.md`
- `includes/class-wp-mcp-ai-rabbitmq-client.php` — RabbitMQ client (connection management, QoS, health check)
- `lib/wordpress-adapter/src/Adapter/QueueClient.php` — QueueClient adapter (enqueue, getStatus, cancel)
- `includes/security/class-wp-mcp-ai-concurrency-guard.php` — Current concurrency guard (non-atomic transients)
- `bin/queue-worker.php` — CLI queue worker (DB and RabbitMQ modes)
- `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php` — Remote SQL PDO driver
- `includes/bootstrap/activation.php` — Table creation on plugin activation
- `docs/project/proposals/022-request-queuing-job-pooling-hardening.md` — Related queuing/pooling hardening
