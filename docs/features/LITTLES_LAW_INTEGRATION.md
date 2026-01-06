# Little's Law Integration Guide

**Last Updated:** January 6, 2026  
**Version:** 1.2.0  
**Status:** Implemented

## Overview

Little's Law has been integrated across three core systems to provide intelligent capacity planning, load prediction, and SLA optimization:

1. **Mesh Router** - Peer selection and capacity estimation
2. **Job Notifier** - Completion time prediction and SLA tracking
3. **SLA Manager** - Performance analytics and compliance monitoring

## Little's Law Fundamentals

**Formula:** `L = λ × W`

Where:
- **L** = Average number of items in system (queue + being processed)
- **λ** (lambda) = Average arrival rate (items per second)
- **W** = Average time in system (seconds)

This simple relationship enables powerful predictions about system behavior under load.

## 1. Mesh Router Integration

### Peer Capacity Scoring

The mesh router now uses Little's Law to calculate a capacity score (0-100) for each peer before routing decisions.

#### Algorithm

```php
// 1. Estimate arrival rate from recent activity
$arrival_rate = $current_load / 60.0;  // Jobs per second

// 2. Calculate utilization (ρ = λ × service_time)
$utilization = $arrival_rate * $avg_response_time;

// 3. Calculate queue length (L = λ × wait_time)
$wait_time = max(0, $avg_response_time - $service_time);
$queue_length = $arrival_rate * $wait_time;

// 4. Score based on utilization and queue depth
$utilization_score = max(0, 100 - ($utilization * 100));
$queue_score = max(0, 100 - ($queue_length * 20));

// 5. Combined capacity score
$capacity_score = ($utilization_score * 0.6) + ($queue_score * 0.4);
```

#### AI-Optimized Peer Selection

Enhanced with 20% weight for capacity scoring:

```php
$score_breakdown = array(
    'response_time' => 25%,  // Lower is better
    'current_load'  => 20%,  // Lower is better
    'success_rate'  => 20%,  // Higher is better
    'capacity'      => 20%,  // Little's Law based
    'compute_hub'   => 15%,  // Priority boost
);
```

### Predicted Wait Time

Estimate how long a new request would wait before processing:

```php
$wait_time = WP_MCP_AI_Mesh_Router::get_predicted_wait_time($health, $service_time);
```

**Example:**
- Current load: 10 jobs
- Avg response time: 5s
- Expected service time: 2s
- **Predicted wait:** ~3s

### Mesh-Wide Capacity Metrics

Get comprehensive mesh health using Little's Law:

```php
$metrics = WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();

// Returns:
array(
    'total_peers'        => 4,
    'healthy_peers'      => 3,
    'degraded_peers'     => 1,
    'down_peers'         => 0,
    'avg_capacity_score' => 72.5,
    'avg_utilization'    => 0.45,     // 45% utilized
    'total_queue_length' => 2.3,      // 2.3 jobs waiting across mesh
    'mesh_health'        => 'good',   // excellent, good, warning, critical
    'recommended_action' => 'Mesh network is healthy...',
)
```

### Use Cases

**1. Smart Load Balancing**
```php
// Before routing, check capacity
$peer = WP_MCP_AI_Mesh_Router::get_optimal_peer($assistant_id, $prompt, $context);

// Decision is now informed by:
// - Current queue depth
// - Predicted wait time
// - Utilization level
// - Historical response times
```

**2. Capacity Planning**
```php
// Monitor mesh health
$metrics = WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();

if ($metrics['avg_utilization'] > 0.8) {
    // Alert: Mesh is at 80%+ capacity
    // Recommendation: Add more peers
}
```

**3. Preventive Scaling**
```php
// Check predicted wait times
foreach ($peer_sites as $peer) {
    $health = get_peer_health($peer['name']);
    $wait = WP_MCP_AI_Mesh_Router::get_predicted_wait_time($health, 2.0);
    
    if ($wait > 10.0) {
        // This peer has 10+ second queue
        // Consider redirecting traffic
    }
}
```

### Dead Letter Queue Integration

Failed mesh queries that exhaust all retry attempts are automatically moved to the Dead Letter Queue for manual intervention.

**Automatic DLQ Integration:**
```php
// After 3 failed attempts, mesh queries are moved to DLQ
$result = WP_MCP_AI_Mesh_Router::query_with_retry($assistant_id, $prompt, $context);

if (is_wp_error($result)) {
    // Query failed and has been logged to DLQ
    // Check DLQ for failed mesh queries
}
```

**View Failed Mesh Queries:**
- **Admin UI:** `wp-admin/admin.php?page=wp-mcp-ai-dlq-manager`
- **Filter by type:** `mesh_query`
- **Actions:** Retry, Dismiss, Delete

**WP-CLI Management:**
```bash
# List all failed mesh queries
wp mcp-ai dlq list --type=mesh_query

# Retry a specific failed query
wp mcp-ai dlq retry <item-id>

# View detailed statistics
wp mcp-ai dlq stats --format=json
```

**DLQ Item Structure:**
```json
{
  "id": "abc123",
  "type": "mesh_query",
  "identifier": "unique-query-hash",
  "failure_reason": "Mesh query failed after 3 attempts: Connection timeout",
  "data": {
    "assistant_id": 123,
    "peer_name": "peer1",
    "peer_url": "https://peer1.example.com",
    "prompt": "Query text...",
    "context": {}
  },
  "retry_history": [
    {"attempt": 1, "timestamp": "...", "result": "failed"},
    {"attempt": 2, "timestamp": "...", "result": "failed"},
    {"attempt": 3, "timestamp": "...", "result": "failed"}
  ]
}
```

## 2. Job Notifier Integration

### Real-Time Completion Prediction

Running jobs now include Little's Law-based completion estimates in their status.

#### Enhanced Status Response

```json
{
  "job_id": "crawl_abc123",
  "status": "running",
  "progress": 50.0,
  "started_at": "2026-01-06T10:00:00Z",
  "littles_law": {
    "sla_tier": "batch",
    "sla_target": 300,
    "elapsed_time": 45,
    "estimated_remaining": 45,
    "estimated_total": 90,
    "sla_compliance": "on_track",
    "predicted_completion": "2026-01-06T10:01:30Z"
  }
}
```

#### SLA Compliance States

- **`on_track`** - Will complete within SLA target
- **`at_risk`** - Projected to exceed SLA
- **`violated`** - Already exceeded SLA

### Tool-to-Tier Mapping

Automatic SLA tier inference:

| Tool | Tier | Target |
|------|------|--------|
| `save_post`, `get_user_info` | realtime | 1s |
| `web_search`, `generate_image`, `transcribe_audio` | near_realtime | 30s |
| `generate_veo_video`, `crawl4ai`, `analyze_video` | batch | 300s |

### Frontend Integration

```javascript
const eventSource = new EventSource(
    `/wp-json/mcp-ai/v1/jobs/${jobId}/stream`
);

eventSource.addEventListener('status', (e) => {
    const status = JSON.parse(e.data);
    
    if (status.littles_law) {
        const ll = status.littles_law;
        
        // Show predicted completion
        document.querySelector('.eta').textContent = 
            `ETA: ${new Date(ll.predicted_completion).toLocaleTimeString()}`;
        
        // Show SLA compliance
        const compliance = ll.sla_compliance;
        if (compliance === 'violated') {
            showWarning('Job exceeded SLA target');
        } else if (compliance === 'at_risk') {
            showWarning('Job may exceed SLA target');
        }
        
        // Show time remaining
        document.querySelector('.remaining').textContent = 
            `${ll.estimated_remaining}s remaining`;
    }
});
```

### Use Cases

**1. User Experience**
```javascript
// Show realistic ETAs to users
const eta = status.littles_law.predicted_completion;
showNotification(`Video will be ready at ${formatTime(eta)}`);
```

**2. Timeout Management**
```javascript
// Adjust timeout based on SLA tier
const slaTarget = status.littles_law.sla_target;
const timeout = slaTarget * 1.5;  // 50% buffer
```

**3. Progress Monitoring**
```javascript
// Alert if job falls behind
if (status.littles_law.sla_compliance === 'at_risk') {
    console.warn('Job may not complete on time');
    // Consider retry or escalation
}
```

## 3. SLA Manager Integration

### Compliance Tracking

Every job completion is now logged for analytics:

```php
WP_MCP_AI_SLA_Manager::track_sla_compliance(
    $job_id,
    'near_realtime',  // tier
    25.0,             // actual time (seconds)
    30.0,             // target time
    true              // success
);
```

### Performance Statistics

Get detailed compliance metrics:

```php
$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics('near_realtime', 24);

// Returns:
array(
    'total_jobs'      => 150,
    'compliant_jobs'  => 142,
    'violated_jobs'   => 8,
    'compliance_rate' => 94.67,         // %
    'avg_actual_time' => 22.3,          // seconds
    'avg_target_time' => 30.0,
    'p50_actual_time' => 20.5,          // Median
    'p95_actual_time' => 28.8,          // 95th percentile
    'p99_actual_time' => 29.5,          // 99th percentile
)
```

### Dashboard Data

Comprehensive view across all tiers:

```php
$dashboard = WP_MCP_AI_SLA_Manager::get_dashboard_data();

// Structure:
array(
    'tiers' => array(
        'realtime' => array(
            'tier_info'     => [...],
            'statistics'    => [...],
            'queue_metrics' => [...],
        ),
        'near_realtime' => [...],
        'batch'         => [...],
    ),
    'overall' => array(
        'total_jobs'      => 450,
        'compliant_jobs'  => 432,
        'violated_jobs'   => 18,
        'compliance_rate' => 96.0,
        'health_status'   => 'good',  // excellent, good, warning, critical
    ),
    'recommendations' => array(
        'realtime'      => array('status' => 'ok', ...),
        'near_realtime' => array('status' => 'warning', ...),
        'batch'         => array('status' => 'critical', ...),
    ),
)
```

### Use Cases

**1. SLA Monitoring**
```php
// Daily health check
$dashboard = WP_MCP_AI_SLA_Manager::get_dashboard_data();

if ($dashboard['overall']['compliance_rate'] < 95) {
    notify_admins('SLA compliance below 95%');
}
```

**2. Performance Analysis**
```php
// Analyze P95 latency
$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics('realtime', 24);

if ($stats['p95_actual_time'] > 0.9) {
    // 95% of jobs taking >900ms for 1s SLA
    recommend_optimization('realtime tier needs tuning');
}
```

**3. Capacity Planning**
```php
// Check if scaling is needed
foreach (['realtime', 'near_realtime', 'batch'] as $tier) {
    $metrics = WP_MCP_AI_SLA_Manager::analyze_queue_metrics($tier);
    
    if ($metrics['over_capacity']) {
        $current = $metrics['max_concurrent'];
        $recommended = $metrics['recommended_workers'];
        
        log_warning("$tier needs $recommended workers (currently $current)");
    }
}
```

## Best Practices

### 1. Monitor Utilization

```php
// Keep utilization under 80% for responsive systems
$metrics = WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();

if ($metrics['avg_utilization'] > 0.8) {
    // System approaching saturation
    // Add capacity before performance degrades
}
```

### 2. Track Percentiles, Not Just Averages

```php
// P95 and P99 reveal worst-case user experience
$stats = WP_MCP_AI_SLA_Manager::get_sla_statistics($tier, 24);

// Average might be 20s, but if P95 is 28s, many users wait longer
if ($stats['p95_actual_time'] > $stats['avg_target_time'] * 0.9) {
    alert('P95 latency approaching SLA limit');
}
```

### 3. Use Little's Law for Predictions, Not Absolutes

```php
// Predictions are estimates based on current conditions
$wait_time = WP_MCP_AI_Mesh_Router::get_predicted_wait_time($health, 2.0);

// Actual wait may vary due to:
// - Arrival rate changes
// - Service time variance
// - System state changes

// Use predictions to inform decisions, not as guarantees
```

### 4. Combine Multiple Metrics

```php
// Don't rely on single metric
$capacity_score = 60;  // Moderate capacity
$success_rate = 98;    // High reliability
$response_time = 2.0;  // Fast

// All three factors matter for routing decision
```

## Performance Impact

### Computational Overhead

- **Mesh Router**: +1ms per peer evaluation (negligible)
- **Job Notifier**: +0.5ms per status retrieval for running jobs
- **SLA Manager**: +2ms per dashboard data compilation

**Total Impact:** <5ms in typical request paths

### Storage Requirements

- **Compliance Log**: ~150 bytes per entry, max 1000 entries = 150KB
- **Health Metrics**: ~200 bytes per peer, typical 5-10 peers = 1-2KB
- **Queue Stats**: ~500 bytes per tier = 1.5KB

**Total Storage:** <200KB for typical deployment

## Troubleshooting

### Inaccurate Predictions

**Symptoms:** Predicted wait times don't match actual

**Causes:**
- Insufficient historical data
- Highly variable service times
- Bursty arrival patterns

**Solutions:**
1. Collect more data (wait 1+ hours)
2. Increase sampling frequency
3. Use percentiles instead of averages

### High Utilization But Low Load

**Symptoms:** `avg_utilization > 0.8` but `current_load` is low

**Cause:** Long-running jobs with slow arrival rate

**Solution:** This is normal - utilization reflects `λ × service_time`, not just queue depth

### SLA Violations Despite Low Queue Length

**Symptoms:** Jobs violating SLA with `queue_length < 1`

**Cause:** Service time exceeds SLA target

**Solution:** Optimize job execution, not just queue management

## API Reference

### Mesh Router

```php
// Get predicted wait time
WP_MCP_AI_Mesh_Router::get_predicted_wait_time($health, $service_time);

// Get mesh capacity metrics
WP_MCP_AI_Mesh_Router::get_mesh_capacity_metrics();
```

### Job Notifier

```php
// Get job status (automatically includes Little's Law metrics for running jobs)
WP_MCP_AI_Job_Notifier::get_job_status($job_id);
```

### SLA Manager

```php
// Track compliance
WP_MCP_AI_SLA_Manager::track_sla_compliance($job_id, $tier, $actual, $target, $success);

// Get statistics
WP_MCP_AI_SLA_Manager::get_sla_statistics($tier, $hours);

// Get dashboard
WP_MCP_AI_SLA_Manager::get_dashboard_data();
```

## Related Documentation

- [SLA-based Prioritization](../architecture/sla-prioritization.md)
- [Mesh Routing Guide](../features/federation/mesh-routing-guide.md)
- [Job Notification System](../features/async-jobs/job-notification-system.md)
- [Distributed Mesh Compute Pooling](../features/federation/mesh-compute-pooling.md)

## Changelog

### v1.2.0 (January 6, 2026)
- ✅ Integrated Little's Law into mesh router peer selection
- ✅ Added capacity scoring and predicted wait times
- ✅ Enhanced job notifier with completion time prediction
- ✅ Added SLA compliance tracking to job status
- ✅ Implemented comprehensive SLA statistics and dashboard
- ✅ Added percentile calculations (P50, P95, P99)
- ✅ Created mesh-wide capacity monitoring

---

**Maintainer:** NV Digital Solutions  
**License:** GPLv3
