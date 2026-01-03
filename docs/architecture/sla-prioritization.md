# SLA-based Prioritization with Little's Law

**Last Updated:** January 2026  
**Version:** 1.1.0  
**Status:** Implemented

## Overview

The SLA Manager implements Service Level Agreement-based prioritization for the WordPress-native cron manager and orchestration layer. It uses **Little's Law** to calculate optimal queue capacity and assigns jobs to three priority tiers based on latency requirements.

## Purpose

Not all jobs are created equal:
- **UI interactions** need sub-second response times
- **API integrations** can tolerate a few seconds of latency
- **Background tasks** can take minutes without user impact

SLA-based prioritization ensures critical operations get resources first while batch jobs don't block the queue.

## The Three SLA Tiers

### Tier 1: Real-time (< 1 second)

**Use Cases:**
- Live chat responses
- Interactive UI updates
- Real-time data lookups
- Immediate user feedback

**Configuration:**
- **Priority:** 100
- **SLA Target:** < 1s
- **Default Concurrent:** 5 jobs
- **Tool Capabilities:** `realtime`, `interactive`, `ui-blocking`

**Example Tools:**
- Quick database lookups
- Simple calculations
- Cache reads
- Status checks

### Tier 2: Near Real-time (1-30 seconds)

**Use Cases:**
- Async API calls
- External service integrations
- Non-blocking operations
- Background API requests

**Configuration:**
- **Priority:** 50
- **SLA Target:** 1-30s
- **Default Concurrent:** 3 jobs
- **Tool Capabilities:** `async`, `may-timeout`

**Example Tools:**
- Webhook deliveries
- Third-party API calls
- Medium-complexity processing
- Non-critical updates

### Tier 3: Batch (> 30 seconds)

**Use Cases:**
- Data imports/exports
- Large file processing
- Scheduled tasks
- Heavy computations

**Configuration:**
- **Priority:** 10
- **SLA Target:** 30-300s (5 min)
- **Default Concurrent:** 2 jobs
- **Tool Capabilities:** `background-only`, `long-running`

**Example Tools:**
- Crawl4AI jobs (web scraping)
- Video generation
- Bulk data operations
- Report generation

## Little's Law: Queue Capacity Planning

### The Formula

```
L = λ × W

Where:
- L = Average number of items in system (queue + being processed)
- λ (lambda) = Average arrival rate (jobs per second)
- W = Average time in system (seconds)
```

### Application

The SLA Manager uses Little's Law to:

1. **Calculate required capacity:**
   ```php
   $required_workers = ceil( $arrival_rate * $service_time );
   ```

2. **Estimate queue length:**
   ```php
   $wait_time = max( 0, $sla_target - $service_time );
   $queue_length = $arrival_rate * $wait_time;
   ```

3. **Measure utilization:**
   ```php
   $utilization = $arrival_rate * $service_time;
   // Utilization > 1.0 means system is overloaded
   ```

### Example Calculation

**Scenario:** Real-time tier handling interactive UI operations

- **Arrival Rate (λ):** 2 jobs/second
- **Service Time:** 0.5 seconds per job
- **SLA Target:** 1.0 second

**Calculations:**

```php
// Utilization
ρ = λ × service_time = 2.0 × 0.5 = 1.0 (100%)

// Queue wait time
W = SLA_target - service_time = 1.0 - 0.5 = 0.5s

// Queue length
L = λ × W = 2.0 × 0.5 = 1.0 job waiting

// System capacity (queue + processing)
L_total = λ × SLA_target = 2.0 × 1.0 = 2.0 jobs

// Required workers
workers = ceil(ρ) = ceil(1.0) = 1 worker minimum
```

**Interpretation:**
- System is at 100% utilization (fully loaded)
- Need at least 1 worker to meet SLA
- Recommended: 2-3 workers for safety margin
- Average 1 job in queue, 1 being processed

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Tool Registry                            │
│  Each tool declares capabilities:                                │
│  • realtime, interactive, ui-blocking → Real-time tier          │
│  • async, may-timeout → Near real-time tier                     │
│  • background-only, long-running → Batch tier                   │
└─────────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                    WP_MCP_AI_SLA_Manager                         │
│                                                                  │
│  get_tier_for_tool(tool) → Infers SLA tier                     │
│  get_priority(tier) → Returns priority value                    │
│  calculate_capacity(tier, λ, μ) → Little's Law                 │
│  analyze_queue_metrics(tier) → Current performance              │
│  get_tuning_recommendations() → Optimization suggestions         │
└─────────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              WP_MCP_AI_Job_Queue_Manager                         │
│                                                                  │
│  enqueue_job($job_id, $job_data):                              │
│    • Auto-assigns SLA tier from tool                            │
│    • Sets priority based on tier                                │
│    • Allows explicit priority override                          │
│                                                                  │
│  process_queue($max_concurrent):                                │
│    • Respects global concurrency limit                          │
│    • apply_sla_tier_limits() → Per-tier limits                 │
│    • Processes high priority (realtime) first                   │
│    • Falls back to near-realtime, then batch                    │
└─────────────────────────────────────────────────────────────────┘
```

## Tier Assignment Logic

### Automatic Tier Inference

```php
$capabilities = $tool->get_capabilities();

// Priority 1: Explicit SLA tier
if ( isset( $capabilities['sla_tier'] ) ) {
    return $capabilities['sla_tier'];
}

// Priority 2: Real-time indicators
if ( in_array( 'realtime', $capabilities ) ||
     in_array( 'interactive', $capabilities ) ||
     in_array( 'ui-blocking', $capabilities ) ) {
    return TIER_REALTIME;
}

// Priority 3: Background-only forces batch
if ( in_array( 'background-only', $capabilities ) ||
     in_array( 'long-running', $capabilities ) ) {
    return TIER_BATCH;
}

// Priority 4: Async tools default near-realtime
if ( in_array( 'async', $capabilities ) ||
     in_array( 'may-timeout', $capabilities ) ) {
    return TIER_NEAR_REALTIME;
}

// Default: Batch for safety
return TIER_BATCH;
```

### Manual Override

```php
// In tool definition
public function get_capabilities() {
    return array(
        'sla_tier' => 'realtime', // Explicit tier
        'async'    => false,
    );
}

// At job enqueue time
WP_MCP_AI_Job_Queue_Manager::enqueue_job( $job_id, array(
    'callable'  => $callable,
    'sla_tier'  => 'near_realtime', // Override
    // Or use legacy priority
    'priority'  => 75, // Custom value
) );
```

## Concurrency Control

### Per-Tier Limits

Each tier has independent concurrent job limits:

```php
// Real-time: 5 concurrent (fast, many slots)
// Near real-time: 3 concurrent (medium)
// Batch: 2 concurrent (slow, few slots)
```

### Algorithm

```php
protected static function apply_sla_tier_limits( $pending_jobs, $active_jobs ) {
    // Count active jobs per tier
    $active_by_tier = array();
    foreach ( $active_jobs as $job ) {
        $tier = $job['sla_tier'];
        $active_by_tier[ $tier ]++;
    }
    
    // Filter pending jobs that have room in their tier
    $filtered = array();
    foreach ( $pending_jobs as $job_id => $job ) {
        $tier = $job['sla_tier'];
        $tier_max = WP_MCP_AI_SLA_Manager::get_default_concurrent( $tier );
        $tier_active = $active_by_tier[ $tier ] ?? 0;
        
        if ( $tier_active < $tier_max ) {
            $filtered[ $job_id ] = $job;
            $active_by_tier[ $tier ]++;
        }
    }
    
    return $filtered;
}
```

### Example Scenario

**Queue State:**
- 10 batch jobs pending
- 3 near-realtime jobs pending  
- 2 realtime jobs pending

**Current Active:**
- 1 batch job running
- 2 near-realtime jobs running
- 4 realtime jobs running

**Processing Logic:**
1. Realtime tier: 4/5 slots used → Process 1 more (up to limit)
2. Near-realtime tier: 2/3 slots used → Process 1 more
3. Batch tier: 1/2 slots used → Process 1 more

**Result:** Processes 1 realtime, 1 near-realtime, 1 batch in priority order, respecting per-tier limits.

## Configuration

### Settings (Future Phase 3)

```php
// In wp_mcp_ai_settings option
$settings = array(
    // Enable/disable SLA mode
    'sla_prioritization_enabled' => true,
    
    // Per-tier concurrency
    'sla_realtime_concurrent'      => 5,
    'sla_near_realtime_concurrent' => 3,
    'sla_batch_concurrent'         => 2,
    
    // SLA targets (seconds)
    'sla_realtime_target'      => 1,
    'sla_near_realtime_target' => 30,
    'sla_batch_target'         => 300,
);
```

### Programmatic Control

```php
// Check if enabled
if ( WP_MCP_AI_SLA_Manager::is_enabled() ) {
    // Use SLA-based priorities
}

// Get tier info
$tier_info = WP_MCP_AI_SLA_Manager::get_tier_info( 'realtime' );
// Returns: array( tier, priority, sla_target, concurrent, description )

// Get all tiers
$all_tiers = WP_MCP_AI_SLA_Manager::get_all_tiers_info();
```

## Monitoring & Tuning

### Queue Metrics Analysis

```php
$metrics = WP_MCP_AI_SLA_Manager::analyze_queue_metrics( 'realtime' );

// Returns:
array(
    'tier'            => 'realtime',
    'sla_target'      => 1.0,
    'arrival_rate'    => 2.5,        // jobs/sec
    'service_time'    => 0.4,        // sec/job
    'wait_time'       => 0.6,        // sec
    'queue_length'    => 1.5,        // avg jobs waiting
    'system_capacity' => 2.5,        // total capacity
    'utilization'     => 1.0,        // 100%
    'required_workers' => 1,
    'recommended_workers' => 5,
    'current_stats'   => array(
        'total'   => 10,
        'pending' => 6,
        'active'  => 4,
        'failed'  => 0,
    ),
    'over_capacity'   => true,       // Queue too full
    'meets_sla'       => false,      // SLA at risk
)
```

### Tuning Recommendations

```php
$recommendations = WP_MCP_AI_SLA_Manager::get_tuning_recommendations();

// Returns per-tier recommendations:
array(
    'realtime' => array(
        'tier'        => 'realtime',
        'current'     => 5,              // Current concurrent
        'recommended' => 8,              // Recommended concurrent
        'status'      => 'critical',     // ok, warning, critical
        'message'     => 'SLA target of 1s is at risk. Increase concurrent workers to 8...',
    ),
    'near_realtime' => array(
        'tier'        => 'near_realtime',
        'current'     => 3,
        'recommended' => 3,
        'status'      => 'ok',
        'message'     => '',
    ),
    'batch' => array(
        'tier'        => 'batch',
        'current'     => 2,
        'recommended' => 4,
        'status'      => 'warning',
        'message'     => 'Queue is over capacity. Consider increasing concurrent workers to 4.',
    ),
)
```

## API Reference

### Core Methods

```php
// Tier inference
$tier = WP_MCP_AI_SLA_Manager::get_tier_for_tool( $tool );

// Priority retrieval
$priority = WP_MCP_AI_SLA_Manager::get_priority( 'realtime' ); // 100

// Capacity calculation (Little's Law)
$capacity = WP_MCP_AI_SLA_Manager::calculate_capacity(
    'realtime',  // tier
    2.5,         // arrival rate (jobs/sec)
    0.4          // service time (sec/job)
);

// SLA targets
$target = WP_MCP_AI_SLA_Manager::get_sla_target( 'realtime' ); // 1.0

// Concurrent limits
$max = WP_MCP_AI_SLA_Manager::get_default_concurrent( 'realtime' ); // 5

// Tier information
$info = WP_MCP_AI_SLA_Manager::get_tier_info( 'realtime' );
$all_info = WP_MCP_AI_SLA_Manager::get_all_tiers_info();

// Valid tiers list
$tiers = WP_MCP_AI_SLA_Manager::get_valid_tiers();
// array( 'realtime', 'near_realtime', 'batch' )

// Enable/disable check
$enabled = WP_MCP_AI_SLA_Manager::is_enabled();
```

## Performance Impact

### CPU

- **Tier inference:** O(1) - simple capability lookup
- **Priority sorting:** O(n log n) - standard queue sort
- **Tier limit filtering:** O(n) - single pass through pending jobs

**Impact:** Negligible (< 1ms for typical queue sizes)

### Memory

- **No additional storage** - tiers computed on-the-fly
- **Job queue metadata:** +1 field per job (`sla_tier`)

**Impact:** ~50 bytes per job

### Throughput

- **Higher priority jobs processed first** - improves perceived performance
- **Per-tier concurrency** - prevents batch jobs from starving realtime
- **Better resource utilization** - matches job characteristics to execution strategy

**Impact:** Positive - reduces average latency for critical operations

## Best Practices

### 1. Choose Appropriate Tiers

```php
// ✅ Good: Web scraping is batch
public function get_capabilities() {
    return array( 'background-only', 'long-running' );
}

// ❌ Bad: Claiming realtime for slow operation
public function get_capabilities() {
    return array( 'realtime' ); // But takes 30s to complete!
}
```

### 2. Monitor SLA Compliance

```php
// Regular health checks
$recommendations = WP_MCP_AI_SLA_Manager::get_tuning_recommendations();

foreach ( $recommendations as $tier => $rec ) {
    if ( 'critical' === $rec['status'] ) {
        // Alert administrators
        // Increase concurrency
        // Scale resources
    }
}
```

### 3. Tune Concurrency Limits

```php
// Start conservative
$settings['sla_realtime_concurrent'] = 3;

// Monitor utilization
$metrics = WP_MCP_AI_SLA_Manager::analyze_queue_metrics( 'realtime' );

// Scale up if utilization > 80%
if ( $metrics['utilization'] > 0.8 ) {
    $settings['sla_realtime_concurrent']++;
}
```

### 4. Use Explicit Tiers for Critical Jobs

```php
// Don't rely on inference for critical paths
WP_MCP_AI_Job_Queue_Manager::enqueue_job( 'critical_job', array(
    'callable' => $callable,
    'sla_tier' => 'realtime', // Explicit
) );
```

## Integration with Crawl4AI

Crawl4AI jobs are automatically assigned **batch tier**:

```php
$job = array(
    'task_id'       => $task_id,
    'status'        => 'pending',
    // ... other fields ...
    'sla_tier'      => 'batch', // Assigned by default
);
```

**Rationale:**
- Web scraping takes 30+ seconds
- Not user-facing (background operation)
- Can tolerate delays
- Should not block real-time operations

## Testing

See `tests/test-sla-manager.php` for comprehensive unit tests:
- Tier inference from capabilities
- Priority assignment
- Little's Law calculations
- High load scenarios
- Default concurrent limits
- Enable/disable functionality

## Future Enhancements

### Phase 3: Admin UI
- Visual tier assignment dashboard
- Real-time metrics display
- Queue depth visualization per tier
- SLA compliance reports

### Phase 4: Advanced Features
- **Dynamic scaling:** Auto-adjust concurrent limits based on load
- **Circuit breaker:** Temporarily disable tier if failure rate too high
- **Predictive analytics:** ML-based arrival rate forecasting
- **Multi-dimensional SLA:** Cost, latency, and throughput optimization

### Phase 5: WP-CLI
```bash
wp mcp-ai sla status
wp mcp-ai sla tune --tier=realtime --concurrent=10
wp mcp-ai sla analyze --period=1hour
```

## Related Documentation

- [Dead Letter Queue](./dead-letter-queue.md) - Failure handling
- [Orchestration Layer Architecture](./orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md)
- [Job Queue Manager](../reference/job-queue-manager.md)

## Troubleshooting

### Jobs stuck in queue

**Symptom:** High priority jobs not processing.

**Solutions:**
1. Check tier concurrent limits: `get_default_concurrent()`
2. Verify tier assignment: Tool may be incorrectly categorized
3. Check global concurrency limit in Resource Manager
4. Review WordPress cron status: `wp cron event list`

### SLA violations

**Symptom:** Jobs exceeding latency targets.

**Solutions:**
1. Analyze metrics: `analyze_queue_metrics()`
2. Check recommendations: `get_tuning_recommendations()`
3. Increase concurrent workers for affected tier
4. Optimize service time (job execution speed)
5. Consider moving some jobs to lower tier

### Tier imbalance

**Symptom:** One tier overloaded, others idle.

**Solutions:**
1. Review tool categorization (may be misassigned)
2. Adjust per-tier concurrent limits
3. Consider splitting heavy tier into sub-tiers
4. Reclassify border-case jobs

## Changelog

### v1.1.0 (January 2026)
- Initial SLA Manager implementation
- Three-tier system (realtime, near-realtime, batch)
- Little's Law capacity planning
- Per-tier concurrency limits
- Integration with Job Queue Manager
- Automatic tier inference from capabilities
- Queue metrics analysis
- Tuning recommendations
- Comprehensive unit tests

---

**Maintainer:** NV Digital Solutions  
**License:** GPLv3
