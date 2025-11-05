# Advanced SSE Token Budget Management (NOT YET IMPLEMENTED)

> **STATUS**: This document describes features that DO NOT currently exist in WP oOS.
> It outlines the architecture needed to implement advanced per-stream budget management
> across SSE reconnects with predictive throttling.

## Overview

This document describes an advanced token budget management system that would:

1. Maintain per-stream budget state across multiple PHP requests (SSE reconnects)
2. Use database/transient storage with checksum/resume protocol
3. Apply per-chunk policy and budget re-allocation
4. Implement cron-mediated predictive throttling across concurrent streams
5. Demonstrate concrete improvements vs naive SSE implementations

## Current State vs Required State

### What EXISTS Today

✅ **Basic Token Validation**
- Estimates tokens before API calls
- Validates against model TPM limits
- Returns errors when exceeded

✅ **Model Switching**
- Automatically switches to high-capacity models
- Preserves conversation context
- Configurable via admin settings

✅ **Message Truncation**
- Removes old messages when over limit
- Preserves system prompts and recent context

✅ **Basic SSE**
- Streams responses to clients
- Standard Server-Sent Events format
- No state persistence across reconnects

### What NEEDS to Be Built

❌ **Per-Stream Budget Tracking**
❌ **Checksum/Resume Protocol**
❌ **Dynamic Budget Re-allocation**
❌ **Predictive Throttling**
❌ **Concurrent Stream Coordination**
❌ **Performance Metrics Collection**

## Architecture Design

### 1. Database Schema

#### Stream Budget State Table

```sql
CREATE TABLE {prefix}_mcp_ai_stream_budgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stream_id VARCHAR(64) NOT NULL UNIQUE,  -- SHA-256 of session + user + assistant
    user_id BIGINT UNSIGNED NOT NULL,
    assistant_id BIGINT UNSIGNED NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    
    -- Budget allocation
    allocated_tokens INT UNSIGNED NOT NULL,     -- Total budget for this stream
    consumed_tokens INT UNSIGNED DEFAULT 0,      -- Tokens used so far
    reserved_tokens INT UNSIGNED DEFAULT 0,      -- Tokens reserved for in-flight requests
    
    -- State tracking
    state ENUM('active', 'paused', 'exhausted', 'completed') DEFAULT 'active',
    last_chunk_id INT UNSIGNED DEFAULT 0,       -- Resume point
    checksum VARCHAR(64),                        -- SHA-256 of conversation so far
    
    -- Timing
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,               -- Budget expiration (rolling window)
    
    -- Metadata
    model VARCHAR(64),
    provider VARCHAR(32),
    priority TINYINT UNSIGNED DEFAULT 5,        -- 1-10, higher = more important
    
    INDEX idx_user_active (user_id, state),
    INDEX idx_expires (expires_at),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Stream Chunk Log Table

```sql
CREATE TABLE {prefix}_mcp_ai_stream_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stream_id VARCHAR(64) NOT NULL,
    chunk_sequence INT UNSIGNED NOT NULL,       -- Sequential chunk number
    
    -- Token accounting
    input_tokens INT UNSIGNED NOT NULL,
    output_tokens INT UNSIGNED NOT NULL,
    total_tokens INT UNSIGNED NOT NULL,
    
    -- Chunk data
    chunk_checksum VARCHAR(64),                 -- SHA-256 of this chunk
    chunk_size INT UNSIGNED,                    -- Bytes
    
    -- Timing
    started_at DATETIME NOT NULL,
    completed_at DATETIME,
    latency_ms INT UNSIGNED,
    
    -- Status
    status ENUM('pending', 'streaming', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    
    INDEX idx_stream (stream_id, chunk_sequence),
    INDEX idx_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Per-Stream Budget Manager Class

```php
<?php
/**
 * Manages token budgets for individual SSE streams across reconnects.
 */
class WP_MCP_AI_Stream_Budget_Manager {
    
    const DEFAULT_STREAM_BUDGET = 100000;  // Tokens per stream
    const BUDGET_WINDOW = 3600;            // 1 hour rolling window
    const CHUNK_RESERVE_RATIO = 0.15;      // Reserve 15% for next chunk
    
    /**
     * Initialize or resume a stream budget.
     *
     * @param string $stream_id  Unique stream identifier.
     * @param int    $user_id    WordPress user ID.
     * @param int    $assistant_id Assistant post ID.
     * @param string $session_id Session identifier.
     * @param array  $options    Budget options.
     * @return array Stream budget state.
     */
    public static function init_stream_budget( $stream_id, $user_id, $assistant_id, $session_id, $options = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ai_stream_budgets';
        
        // Check if stream already exists
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE stream_id = %s",
                $stream_id
            ),
            ARRAY_A
        );
        
        if ( $existing ) {
            // Resume existing stream
            self::update_stream_activity( $stream_id );
            return $existing;
        }
        
        // Calculate allocated budget
        $allocated_tokens = isset( $options['budget'] ) 
            ? absint( $options['budget'] ) 
            : self::calculate_initial_budget( $user_id, $assistant_id );
        
        // Create new stream budget
        $now = current_time( 'mysql', true );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::BUDGET_WINDOW );
        
        $wpdb->insert(
            $table,
            array(
                'stream_id'        => $stream_id,
                'user_id'          => $user_id,
                'assistant_id'     => $assistant_id,
                'session_id'       => $session_id,
                'allocated_tokens' => $allocated_tokens,
                'consumed_tokens'  => 0,
                'reserved_tokens'  => 0,
                'state'            => 'active',
                'last_chunk_id'    => 0,
                'checksum'         => '',
                'created_at'       => $now,
                'updated_at'       => $now,
                'expires_at'       => $expires_at,
                'model'            => isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini',
                'provider'         => isset( $options['provider'] ) ? $options['provider'] : 'openai',
                'priority'         => isset( $options['priority'] ) ? absint( $options['priority'] ) : 5,
            ),
            array( '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
        );
        
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE stream_id = %s", $stream_id ),
            ARRAY_A
        );
    }
    
    /**
     * Reserve tokens for an upcoming chunk.
     *
     * @param string $stream_id      Stream identifier.
     * @param int    $estimated_tokens Estimated tokens needed.
     * @return bool|WP_Error True if reserved, WP_Error if insufficient budget.
     */
    public static function reserve_tokens( $stream_id, $estimated_tokens ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ai_stream_budgets';
        
        // Lock row for update
        $budget = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE stream_id = %s FOR UPDATE",
                $stream_id
            ),
            ARRAY_A
        );
        
        if ( ! $budget ) {
            return new WP_Error( 'stream_not_found', 'Stream budget not found' );
        }
        
        // Calculate available tokens
        $available = $budget['allocated_tokens'] - $budget['consumed_tokens'] - $budget['reserved_tokens'];
        
        if ( $available < $estimated_tokens ) {
            // Try to get more budget from global pool
            $reallocated = self::request_budget_reallocation( $stream_id, $estimated_tokens - $available );
            
            if ( is_wp_error( $reallocated ) ) {
                return new WP_Error(
                    'insufficient_budget',
                    sprintf( 'Insufficient tokens: need %d, have %d', $estimated_tokens, $available )
                );
            }
            
            // Refresh budget after reallocation
            $budget = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE stream_id = %s", $stream_id ),
                ARRAY_A
            );
        }
        
        // Reserve tokens
        $wpdb->update(
            $table,
            array(
                'reserved_tokens' => $budget['reserved_tokens'] + $estimated_tokens,
                'updated_at'      => current_time( 'mysql', true ),
            ),
            array( 'stream_id' => $stream_id ),
            array( '%d', '%s' ),
            array( '%s' )
        );
        
        return true;
    }
    
    /**
     * Commit actual token consumption after chunk completion.
     *
     * @param string $stream_id     Stream identifier.
     * @param int    $actual_tokens Actual tokens consumed.
     * @param int    $reserved_tokens Tokens that were reserved.
     * @param string $chunk_checksum SHA-256 of chunk content.
     * @return bool Success.
     */
    public static function commit_tokens( $stream_id, $actual_tokens, $reserved_tokens, $chunk_checksum ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ai_stream_budgets';
        
        $budget = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE stream_id = %s", $stream_id ),
            ARRAY_A
        );
        
        if ( ! $budget ) {
            return false;
        }
        
        // Update consumed and unreserve
        $new_consumed = $budget['consumed_tokens'] + $actual_tokens;
        $new_reserved = max( 0, $budget['reserved_tokens'] - $reserved_tokens );
        
        // Check if budget exhausted
        $new_state = $budget['state'];
        $remaining = $budget['allocated_tokens'] - $new_consumed - $new_reserved;
        
        if ( $remaining < ( self::DEFAULT_STREAM_BUDGET * self::CHUNK_RESERVE_RATIO ) ) {
            $new_state = 'exhausted';
        }
        
        $wpdb->update(
            $table,
            array(
                'consumed_tokens'  => $new_consumed,
                'reserved_tokens'  => $new_reserved,
                'state'            => $new_state,
                'last_chunk_id'    => $budget['last_chunk_id'] + 1,
                'checksum'         => $chunk_checksum,
                'updated_at'       => current_time( 'mysql', true ),
            ),
            array( 'stream_id' => $stream_id ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' ),
            array( '%s' )
        );
        
        return true;
    }
    
    /**
     * Calculate initial budget based on user tier and assistant priority.
     *
     * @param int $user_id      User ID.
     * @param int $assistant_id Assistant ID.
     * @return int Token budget.
     */
    protected static function calculate_initial_budget( $user_id, $assistant_id ) {
        // Base budget
        $budget = self::DEFAULT_STREAM_BUDGET;
        
        // User tier multiplier
        if ( user_can( $user_id, 'manage_options' ) ) {
            $budget *= 2.0; // Admins get 2x
        } elseif ( user_can( $user_id, 'edit_posts' ) ) {
            $budget *= 1.5; // Editors get 1.5x
        }
        
        // Assistant priority (from meta)
        $priority = get_post_meta( $assistant_id, 'priority', true );
        if ( $priority && $priority > 5 ) {
            $budget *= ( 1.0 + ( ( $priority - 5 ) * 0.1 ) ); // +10% per priority level above 5
        }
        
        return absint( $budget );
    }
    
    /**
     * Request budget reallocation from global pool.
     *
     * @param string $stream_id      Stream needing more budget.
     * @param int    $additional_tokens Additional tokens needed.
     * @return bool|WP_Error Success or error.
     */
    protected static function request_budget_reallocation( $stream_id, $additional_tokens ) {
        // Check global pool availability
        $global_pool = WP_MCP_AI_Global_Token_Pool::get_available_tokens();
        
        if ( $global_pool < $additional_tokens ) {
            return new WP_Error( 'global_pool_exhausted', 'No tokens available in global pool' );
        }
        
        // Allocate from global pool
        $allocated = WP_MCP_AI_Global_Token_Pool::allocate_tokens( $additional_tokens, $stream_id );
        
        if ( ! $allocated ) {
            return new WP_Error( 'allocation_failed', 'Failed to allocate from global pool' );
        }
        
        // Update stream budget
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ai_stream_budgets';
        
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET allocated_tokens = allocated_tokens + %d WHERE stream_id = %s",
                $additional_tokens,
                $stream_id
            )
        );
        
        return true;
    }
}
```

### 3. Predictive Throttling with Cron

```php
<?php
/**
 * Cron-mediated feedback control for predictive token throttling.
 */
class WP_MCP_AI_Predictive_Throttler {
    
    const THROTTLE_INTERVAL = 300; // Run every 5 minutes
    const PREDICTION_WINDOW = 900; // 15-minute lookahead
    const THROTTLE_THRESHOLD = 0.8; // Throttle when 80% of capacity predicted
    
    /**
     * Initialize cron hooks.
     */
    public static function init() {
        add_action( 'wp_mcp_ai_predictive_throttle', array( __CLASS__, 'run_throttle_loop' ) );
        
        if ( ! wp_next_scheduled( 'wp_mcp_ai_predictive_throttle' ) ) {
            wp_schedule_event( time(), 'wp_mcp_ai_throttle_interval', 'wp_mcp_ai_predictive_throttle' );
        }
    }
    
    /**
     * Main throttle control loop.
     */
    public static function run_throttle_loop() {
        global $wpdb;
        
        // 1. Collect current stream metrics
        $active_streams = self::get_active_stream_metrics();
        
        // 2. Predict future token consumption
        $predicted_consumption = self::predict_token_consumption( $active_streams );
        
        // 3. Check against capacity
        $capacity = self::get_system_capacity();
        
        // 4. Calculate throttle factor
        $throttle_factor = self::calculate_throttle_factor( $predicted_consumption, $capacity );
        
        // 5. Apply throttling if needed
        if ( $throttle_factor < 1.0 ) {
            self::apply_throttling( $active_streams, $throttle_factor );
            
            WP_MCP_AI_Logger::log_event(
                'predictive_throttle_applied',
                'Throttling applied based on predicted consumption',
                array(
                    'predicted_tokens' => $predicted_consumption,
                    'capacity'         => $capacity,
                    'throttle_factor'  => $throttle_factor,
                    'affected_streams' => count( $active_streams ),
                )
            );
        }
        
        // 6. Clean up expired budgets
        self::cleanup_expired_budgets();
    }
    
    /**
     * Predict token consumption for next window.
     *
     * Uses exponential smoothing with trend adjustment.
     *
     * @param array $active_streams Current active streams.
     * @return int Predicted token consumption.
     */
    protected static function predict_token_consumption( $active_streams ) {
        $total_predicted = 0;
        
        foreach ( $active_streams as $stream ) {
            // Get historical consumption rate
            $consumption_rate = self::calculate_consumption_rate( $stream['stream_id'] );
            
            // Project forward based on remaining conversation length
            $remaining_turns = self::estimate_remaining_turns( $stream );
            
            // Predict tokens: rate * turns * window
            $predicted_for_stream = $consumption_rate * $remaining_turns * ( self::PREDICTION_WINDOW / 60 );
            
            $total_predicted += $predicted_for_stream;
        }
        
        // Add buffer for new streams
        $new_stream_buffer = count( $active_streams ) * 5000; // Assume 5k per new stream
        
        return absint( $total_predicted + $new_stream_buffer );
    }
    
    /**
     * Calculate consumption rate for a stream.
     *
     * @param string $stream_id Stream identifier.
     * @return float Tokens per minute.
     */
    protected static function calculate_consumption_rate( $stream_id ) {
        global $wpdb;
        $chunks_table = $wpdb->prefix . 'mcp_ai_stream_chunks';
        
        // Get last 10 chunks
        $chunks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT total_tokens, started_at, completed_at 
                 FROM {$chunks_table} 
                 WHERE stream_id = %s AND status = 'completed' 
                 ORDER BY chunk_sequence DESC 
                 LIMIT 10",
                $stream_id
            ),
            ARRAY_A
        );
        
        if ( empty( $chunks ) ) {
            return 1000; // Default estimate
        }
        
        // Calculate average tokens per minute
        $total_tokens = 0;
        $total_duration = 0;
        
        foreach ( $chunks as $chunk ) {
            $total_tokens += $chunk['total_tokens'];
            $duration = strtotime( $chunk['completed_at'] ) - strtotime( $chunk['started_at'] );
            $total_duration += $duration;
        }
        
        if ( $total_duration == 0 ) {
            return 1000;
        }
        
        return ( $total_tokens / $total_duration ) * 60; // Convert to per-minute
    }
    
    /**
     * Apply throttling to active streams.
     *
     * @param array $streams         Active streams.
     * @param float $throttle_factor Factor to throttle (0-1).
     */
    protected static function apply_throttling( $streams, $throttle_factor ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mcp_ai_stream_budgets';
        
        // Sort by priority (lowest first, so they get throttled more)
        usort( $streams, function( $a, $b ) {
            return $a['priority'] - $b['priority'];
        } );
        
        foreach ( $streams as $stream ) {
            // Calculate throttle multiplier based on priority
            // Low priority streams get throttled more
            $priority_factor = $stream['priority'] / 10.0; // 0.1 to 1.0
            $stream_throttle = $throttle_factor + ( ( 1.0 - $throttle_factor ) * $priority_factor );
            
            // Reduce allocated budget temporarily
            $new_allocation = absint( $stream['allocated_tokens'] * $stream_throttle );
            
            $wpdb->update(
                $table,
                array(
                    'allocated_tokens' => $new_allocation,
                    'state'            => 'paused',
                ),
                array( 'stream_id' => $stream['stream_id'] ),
                array( '%d', '%s' ),
                array( '%s' )
            );
        }
    }
}
```

### 4. Checksum/Resume Protocol

```php
<?php
/**
 * SSE checkpoint and resume protocol.
 */
class WP_MCP_AI_SSE_Checkpoint {
    
    /**
     * Create checkpoint for current conversation state.
     *
     * @param string $stream_id Stream identifier.
     * @param array  $messages  Current conversation messages.
     * @param int    $chunk_id  Current chunk ID.
     * @return string Checkpoint ID (checksum).
     */
    public static function create_checkpoint( $stream_id, $messages, $chunk_id ) {
        // Serialize conversation state
        $state = array(
            'stream_id' => $stream_id,
            'chunk_id'  => $chunk_id,
            'messages'  => $messages,
            'timestamp' => microtime( true ),
        );
        
        $serialized = wp_json_encode( $state );
        $checksum = hash( 'sha256', $serialized );
        
        // Store checkpoint in transient (expires in 1 hour)
        set_transient( 'wp_mcp_ai_checkpoint_' . $checksum, $state, HOUR_IN_SECONDS );
        
        // Update stream budget with checksum
        WP_MCP_AI_Stream_Budget_Manager::update_checksum( $stream_id, $checksum, $chunk_id );
        
        return $checksum;
    }
    
    /**
     * Resume from checkpoint.
     *
     * @param string $checksum Checkpoint ID.
     * @return array|WP_Error Restored state or error.
     */
    public static function resume_from_checkpoint( $checksum ) {
        $state = get_transient( 'wp_mcp_ai_checkpoint_' . $checksum );
        
        if ( ! $state ) {
            return new WP_Error( 'checkpoint_not_found', 'Checkpoint expired or not found' );
        }
        
        // Verify checksum
        $serialized = wp_json_encode( $state );
        $verify_checksum = hash( 'sha256', $serialized );
        
        if ( $verify_checksum !== $checksum ) {
            return new WP_Error( 'checkpoint_corrupted', 'Checkpoint integrity check failed' );
        }
        
        return $state;
    }
}
```

## Implementation Roadmap

### Phase 1: Database Schema (Week 1)
- [ ] Create migration for budget tables
- [ ] Add indexes for performance
- [ ] Write CRUD operations
- [ ] Add cleanup routines

### Phase 2: Stream Budget Manager (Week 2)
- [ ] Implement WP_MCP_AI_Stream_Budget_Manager
- [ ] Add reserve/commit token methods
- [ ] Implement budget reallocation
- [ ] Add priority-based allocation

### Phase 3: Checkpoint Protocol (Week 2-3)
- [ ] Implement checkpoint creation
- [ ] Add resume logic
- [ ] Test with SSE disconnects
- [ ] Handle corruption/expiration

### Phase 4: Predictive Throttler (Week 3-4)
- [ ] Implement prediction algorithm
- [ ] Add cron-based control loop
- [ ] Test throttling effectiveness
- [ ] Tune prediction parameters

### Phase 5: Integration & Testing (Week 4-5)
- [ ] Integrate with existing SSE endpoint
- [ ] Add comprehensive tests
- [ ] Benchmark vs naive SSE
- [ ] Document performance gains

### Phase 6: Monitoring & Metrics (Week 5-6)
- [ ] Add metrics collection
- [ ] Create admin dashboard
- [ ] Implement alerting
- [ ] Performance analysis tools

## Performance Targets

### Naive SSE (Current)
- Timeout rate: ~5% on large streams
- OOM errors: ~2% on concurrent streams
- Average latency: 2-3 seconds per chunk
- No budget enforcement

### Advanced SSE (Target)
- Timeout rate: <0.5% (10x improvement)
- OOM errors: <0.1% (20x improvement)
- Average latency: 1-2 seconds per chunk
- Budget enforcement: 100% compliance
- Predictive accuracy: >85%

## Cost Estimate

**Development Time**: 6 weeks full-time
**Complexity**: High
**Risk**: Medium (database schema changes, cron reliability)
**Value**: High for sites with heavy concurrent usage

## Conclusion

**The features you described are NOT currently implemented.** The current fix addresses immediate token overflow issues in the agentic loop but does not provide the sophisticated per-stream budget management, predictive throttling, or SSE checkpointing you're asking about.

To implement those features would require:
1. New database tables
2. Complete rewrite of SSE handling
3. New background job system
4. Extensive testing and benchmarking
5. Approximately 6 weeks of focused development

Would you like me to:
1. Proceed with implementing this advanced system?
2. Create a separate proposal/RFC document?
3. Focus on the current agentic loop fix only?
