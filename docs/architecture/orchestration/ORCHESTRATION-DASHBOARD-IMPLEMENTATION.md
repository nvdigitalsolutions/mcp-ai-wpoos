# Orchestration Dashboard Implementation Guide

**Last Updated:** November 10, 2024  
**Plugin Version:** 1.0.0  
**Related:** [ORCHESTRATION-LAYER-ARCHITECTURE.md](architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md), [ORCHESTRATION-DASHBOARD-FINDINGS.md](ORCHESTRATION-DASHBOARD-FINDINGS.md)

---

## Overview

This document provides a comprehensive implementation guide for the WP oOS Orchestration Dashboard, including architecture details, component breakdown, and integration points. The orchestration dashboard provides a unified interface for managing AI resource allocation, monitoring system health, and configuring orchestration policies.

---

## Architecture Overview

### Component Hierarchy

```
WP_MCP_AI_Settings_Dashboard (Main Dashboard)
    ├── WP_MCP_AI_Section_Orchestration (Orchestration Tab)
    │   ├── Configuration Controls
    │   ├── Real-Time Statistics Display
    │   └── Quick Actions Panel
    ├── WP_MCP_AI_Resource_Manager (Resource Management)
    │   ├── Memory Limit Detection
    │   ├── Token Budget Calculation
    │   └── Request Timeout Management
    ├── WP_MCP_AI_Token_Budget_Manager (Token Management)
    │   ├── Budget Enforcement
    │   ├── Usage Tracking
    │   └── Predictive Analytics
    └── WP_MCP_AI_Cron_Manager (Task Scheduling)
        ├── Job Registry
        ├── Schedule Management
        └── Budget Inheritance
```

### File Structure

```
includes/
├── admin/
│   ├── class-wp-mcp-ai-settings-dashboard.php     # Main dashboard controller
│   ├── settings-dashboard-init.php                 # Dashboard initialization
│   └── sections/
│       └── class-wp-mcp-ai-section-orchestration.php  # Orchestration section
├── class-resource-manager.php                      # Resource manager
├── class-wp-mcp-ai-token-budget-manager.php       # Token budget manager
└── class-wp-mcp-ai-cron-manager.php               # Cron manager

assets/
├── css/
│   └── settings-dashboard.css                      # Dashboard styles
└── js/
    └── settings-dashboard.js                       # Dashboard interactions
```

---

## Core Components

### 1. Orchestration Settings Section

**File:** `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php`

#### Class Structure

```php
class WP_MCP_AI_Section_Orchestration extends WP_MCP_AI_Settings_Section {
    
    /**
     * Section identifier
     */
    public function get_id() {
        return 'orchestration';
    }
    
    /**
     * Tab assignment
     */
    public function get_tab() {
        return 'orchestration';
    }
    
    /**
     * Field definitions
     */
    public function get_fields() {
        return array(
            'orchestration_intro'           => array( 'type' => 'html' ),
            'enable_budget_management'      => array( 'type' => 'checkbox' ),
            'enable_predictive_optimization' => array( 'type' => 'checkbox' ),
            'enable_capability_gating'      => array( 'type' => 'checkbox' ),
            'enable_cron_orchestration'     => array( 'type' => 'checkbox' ),
            'orchestration_stats'           => array( 'type' => 'html' ),
        );
    }
}
```

#### Configuration Fields

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enable_budget_management` | checkbox | `true` | Automatically allocate and adjust token budgets based on system resources and workload tier |
| `enable_predictive_optimization` | checkbox | `true` | Use historical usage patterns to forecast and prevent resource exhaustion |
| `enable_capability_gating` | checkbox | `true` | Enforce WordPress capability checks for tool access based on user roles |
| `enable_cron_orchestration` | checkbox | `true` | Allow AI agents to create and manage scheduled background tasks with inherited budget constraints |

### 2. Resource Manager Integration

**File:** `includes/class-resource-manager.php`

#### Key Methods

##### `get_memory_limit()`
Detects PHP memory limit and returns value in bytes.

```php
$resource_manager = WP_MCP_AI_Resource_Manager::instance();
$memory_limit = $resource_manager->get_memory_limit();

// Returns: integer (bytes)
// Example: 268435456 for 256MB
```

##### `get_max_tokens()`
Calculates maximum token budget based on workload tier.

```php
$max_tokens = $resource_manager->get_max_tokens();

// Workload Tiers:
// Low    (< 128MB):  1,000 tokens
// Medium (128-512MB): 4,000 tokens
// High   (> 512MB):  16,000 tokens
```

##### `get_request_timeout()`
Determines safe request timeout based on PHP execution limits.

```php
$timeout = $resource_manager->get_request_timeout();

// Returns: integer (seconds)
// Calculation: min(120, max(30, max_execution_time * 0.8))
```

#### Workload Tier Calculation

```php
if ( $memory_limit < 128 * 1024 * 1024 ) {
    $tier = 'Low';
    $max_tokens = 1000;
} elseif ( $memory_limit < 512 * 1024 * 1024 ) {
    $tier = 'Medium';
    $max_tokens = 4000;
} else {
    $tier = 'High';
    $max_tokens = 16000;
}
```

### 3. Cron Manager Integration

**File:** `includes/class-wp-mcp-ai-cron-manager.php`

#### Job Registry

Active cron jobs are tracked in WordPress options:

```php
// Option name
const OPTION_NAME = 'wp_mcp_ai_cron_jobs';

// Job structure
array(
    'job_id'          => string,  // Unique identifier
    'hook'            => string,  // WordPress cron hook
    'args'            => array,   // Job arguments
    'schedule'        => string,  // Recurrence pattern
    'first_timestamp' => int,     // Initial execution time
    'created_at'      => int,     // Creation timestamp
    'created_by'      => int,     // User ID who created the job
)
```

#### Key Methods

##### `get_jobs()`
Retrieves all registered cron jobs.

```php
$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

// Returns: array of job arrays
foreach ( $jobs as $job_id => $job ) {
    echo $job['hook'];
    echo $job['schedule'];
}
```

##### `record_job()`
Records a new cron job in the registry.

```php
WP_MCP_AI_Cron_Manager::record_job(
    $hook,
    $args,
    $schedule,
    $timestamp,
    $user_id
);
```

---

## PR #852 Enhancements

### Slider Controls (14 Parameters)

**Added:** November 9, 2025

#### Health Monitoring Thresholds

```php
// Memory Warning Threshold (50-95%, default 75%)
WP_MCP_AI_Settings_Registry::get_setting('memory_warning_threshold', 75);

// Memory Critical Threshold (75-99%, default 90%)
WP_MCP_AI_Settings_Registry::get_setting('memory_critical_threshold', 90);

// Error Rate Warning Threshold (5-25%, default 10%)
WP_MCP_AI_Settings_Registry::get_setting('error_rate_warning_threshold', 10);

// Error Rate Critical Threshold (10-50%, default 20%)
WP_MCP_AI_Settings_Registry::get_setting('error_rate_critical_threshold', 20);
```

#### Adaptive Budget Allocation

```php
// High Priority Budget (50-100%, default 100%)
WP_MCP_AI_Settings_Registry::get_setting('high_priority_budget', 100);

// Medium Priority Budget (30-100%, default 80%)
WP_MCP_AI_Settings_Registry::get_setting('medium_priority_budget', 80);

// Low Priority Budget (10-80%, default 50%)
WP_MCP_AI_Settings_Registry::get_setting('low_priority_budget', 50);

// Critical Health Reduction (10-80%, default 50%)
WP_MCP_AI_Settings_Registry::get_setting('critical_health_reduction', 50);

// Warning Health Reduction (50-100%, default 75%)
WP_MCP_AI_Settings_Registry::get_setting('warning_health_reduction', 75);
```

#### Token Limits by Tier

```php
// Low Tier Max Tokens (500-5,000, default 1,000)
WP_MCP_AI_Settings_Registry::get_setting('low_tier_max_tokens', 1000);

// Medium Tier Max Tokens (2,000-10,000, default 4,000)
WP_MCP_AI_Settings_Registry::get_setting('medium_tier_max_tokens', 4000);

// High Tier Max Tokens (8,000-32,000, default 16,000)
WP_MCP_AI_Settings_Registry::get_setting('high_tier_max_tokens', 16000);
```

#### Predictive Analytics

```php
// Prediction Confidence Threshold (10-90%, default 30%)
WP_MCP_AI_Settings_Registry::get_setting('prediction_confidence_threshold', 30);

// Prediction Safety Buffer (10-50%, default 20%)
WP_MCP_AI_Settings_Registry::get_setting('prediction_safety_buffer', 20);
```

### Configuration Presets (12 Presets)

**Added:** November 9, 2025

#### Preset System

```php
/**
 * Get available configuration presets
 */
public function get_presets() {
    return array(
        'custom'          => array( /* Custom settings */ ),
        'auto'            => array( /* Auto-detected */ ),
        'balanced'        => array( /* Balanced config */ ),
        'conservative'    => array( /* Conservative */ ),
        'aggressive'      => array( /* Aggressive */ ),
        'development'     => array( /* Development */ ),
        'high_traffic'    => array( /* High traffic */ ),
        'burst_workload'  => array( /* Burst handling */ ),
        'cost_optimized'  => array( /* Cost optimized */ ),
        'enterprise'      => array( /* Enterprise */ ),
        'failsafe'        => array( /* Failsafe */ ),
        'predictive_first' => array( /* ML-focused */ ),
    );
}
```

#### Auto Preset Detection

```php
/**
 * Auto preset intelligently detects server capabilities
 */
public function wpMcpAiCalculateAutoPreset() {
    $memory_limit = $this->get_memory_limit();
    
    if ( $memory_limit >= 1024 * 1024 * 1024 ) {
        // 1GB+ = Aggressive preset
        return 'aggressive';
    } elseif ( $memory_limit >= 512 * 1024 * 1024 ) {
        // 512MB+ = Balanced preset
        return 'balanced';
    } else {
        // < 512MB = Conservative preset
        return 'conservative';
    }
}
```

---

## Statistics Display

### Real-Time Metrics

The dashboard displays four key metrics in a responsive grid:

```php
private function get_stats_content() {
    $resource_manager = WP_MCP_AI_Resource_Manager::instance();
    
    // 1. Workload Tier
    $memory_limit = $resource_manager->get_memory_limit();
    $tier = $this->calculate_tier( $memory_limit );
    
    // 2. Max Tokens
    $max_tokens = $resource_manager->get_max_tokens();
    
    // 3. Request Timeout
    $timeout = $resource_manager->get_request_timeout();
    
    // 4. Active Cron Jobs
    $cron_jobs = WP_MCP_AI_Cron_Manager::get_jobs();
    $active_jobs = count( array_filter( $cron_jobs, function( $job ) {
        return wp_get_scheduled_event( $job['hook'], $job['args'] );
    }));
    
    // Render grid with these metrics
}
```

### Grid Layout

```css
.wp-mcp-ai-orchestration-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: #fff;
    border: 1px solid #dcdcde;
    padding: 1rem;
    border-radius: 4px;
}

.stat-label {
    font-size: 0.875rem;
    color: #646970;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 600;
}
```

---

## Quick Actions Panel

### Available Actions

```php
// Manage Cron Jobs
$url = admin_url( 'admin.php?page=wp-mcp-ai-cron-manager' );

// View Token Manager
$url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' );

// Run Diagnostics
$url = admin_url( 'tools.php?page=wp-mcp-ai-diagnostic' );
```

---

## Hooks and Filters

### Action Hooks

```php
// Before orchestration section renders
do_action( 'wp_mcp_ai_before_orchestration_section' );

// After orchestration section renders
do_action( 'wp_mcp_ai_after_orchestration_section' );

// Before statistics display
do_action( 'wp_mcp_ai_before_orchestration_stats' );

// After statistics display
do_action( 'wp_mcp_ai_after_orchestration_stats' );
```

### Filter Hooks

```php
// Modify orchestration fields
$fields = apply_filters( 'wp_mcp_ai_orchestration_fields', $fields );

// Customize intro content
$content = apply_filters( 'wp_mcp_ai_orchestration_intro_content', $content );

// Customize stats content
$content = apply_filters( 'wp_mcp_ai_orchestration_stats_content', $content );

// Modify quick actions
$actions = apply_filters( 'wp_mcp_ai_orchestration_quick_actions', $actions );
```

---

## Customization Examples

### Adding Custom Statistics

```php
add_filter( 'wp_mcp_ai_orchestration_stats_content', function( $content ) {
    $custom_stat = '<div class="stat-card">';
    $custom_stat .= '<div class="stat-label">Custom Metric</div>';
    $custom_stat .= '<div class="stat-value">1,234</div>';
    $custom_stat .= '</div>';
    
    // Inject before closing div
    $content = str_replace( '</div>', $custom_stat . '</div>', $content );
    
    return $content;
});
```

### Adding Custom Quick Actions

```php
add_filter( 'wp_mcp_ai_orchestration_quick_actions', function( $actions ) {
    $actions[] = array(
        'label' => __( 'Custom Action', 'my-plugin' ),
        'url'   => admin_url( 'admin.php?page=my-custom-page' ),
        'class' => 'button button-primary',
    );
    
    return $actions;
});
```

### Modifying Workload Tier Thresholds

```php
add_filter( 'wp_mcp_ai_workload_tier_thresholds', function( $thresholds ) {
    return array(
        'low'    => 64 * 1024 * 1024,   // 64MB
        'medium' => 256 * 1024 * 1024,  // 256MB
        'high'   => 512 * 1024 * 1024,  // 512MB
    );
});
```

---

## Settings API Integration

### Registering Settings

```php
// Settings are registered via WP_MCP_AI_Settings_Registry
WP_MCP_AI_Settings_Registry::register_setting(
    'enable_budget_management',
    'orchestration',
    true  // default value
);
```

### Retrieving Settings

```php
// Get setting value
$enabled = WP_MCP_AI_Settings_Registry::get_setting(
    'enable_budget_management',
    true  // default if not set
);

// Check if feature is enabled
if ( $enabled ) {
    // Apply budget management
}
```

### Updating Settings

```php
// Update via WordPress options API
update_option( 'wp_mcp_ai_enable_budget_management', true );

// Or via Settings Registry
WP_MCP_AI_Settings_Registry::update_setting(
    'enable_budget_management',
    true
);
```

---

## Access Control

### Dashboard Access

```php
// Minimum capability required
$capability = apply_filters( 'wp_mcp_ai_dashboard_capability', 'manage_options' );

// Check access
if ( ! current_user_can( $capability ) ) {
    wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
}
```

### Per-Section Capabilities

```php
// Orchestration section capability
$capability = apply_filters(
    'wp_mcp_ai_orchestration_section_capability',
    'manage_options'
);
```

---

## Testing

### Unit Tests

```php
/**
 * Test orchestration section initialization
 */
public function test_orchestration_section_init() {
    $section = new WP_MCP_AI_Section_Orchestration();
    
    $this->assertEquals( 'orchestration', $section->get_id() );
    $this->assertEquals( 'orchestration', $section->get_tab() );
    $this->assertIsArray( $section->get_fields() );
}

/**
 * Test resource manager integration
 */
public function test_resource_manager_integration() {
    $manager = WP_MCP_AI_Resource_Manager::instance();
    
    $this->assertIsInt( $manager->get_memory_limit() );
    $this->assertIsInt( $manager->get_max_tokens() );
    $this->assertIsInt( $manager->get_request_timeout() );
}

/**
 * Test cron manager integration
 */
public function test_cron_manager_integration() {
    $jobs = WP_MCP_AI_Cron_Manager::get_jobs();
    
    $this->assertIsArray( $jobs );
}
```

---

## Troubleshooting

### Statistics Not Displaying

**Problem:** Orchestration statistics panel is empty.

**Solution:**
1. Verify `WP_MCP_AI_Resource_Manager` class exists
2. Check PHP memory limit is properly detected
3. Ensure `WP_MCP_AI_Cron_Manager` is loaded

```php
// Debug resource manager
$manager = WP_MCP_AI_Resource_Manager::instance();
var_dump( $manager->get_memory_limit() );
var_dump( $manager->get_max_tokens() );
```

### Settings Not Saving

**Problem:** Configuration changes don't persist.

**Solution:**
1. Verify nonce validation is passing
2. Check user has `manage_options` capability
3. Ensure settings are registered properly

```php
// Debug settings
$value = get_option( 'wp_mcp_ai_enable_budget_management' );
var_dump( $value );
```

### Cron Jobs Not Showing

**Problem:** Active cron jobs count is 0.

**Solution:**
1. Verify WordPress cron is enabled
2. Check `WP_MCP_AI_Cron_Manager::get_jobs()` returns data
3. Ensure jobs are scheduled in WordPress

```php
// Debug cron jobs
$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
var_dump( $jobs );

// Check WordPress cron
$cron = _get_cron_array();
var_dump( $cron );
```

---

## Performance Considerations

### Caching

Statistics are cached for performance:

```php
// Cache key pattern
$cache_key = 'wp_mcp_ai_orchestration_stats_' . get_current_user_id();

// Cache duration
$cache_duration = 5 * MINUTE_IN_SECONDS;

// Get cached or fresh stats
$stats = wp_cache_get( $cache_key );
if ( false === $stats ) {
    $stats = $this->calculate_stats();
    wp_cache_set( $cache_key, $stats, '', $cache_duration );
}
```

### Database Queries

Minimize queries in statistics display:

```php
// Bad: Multiple queries
foreach ( $jobs as $job ) {
    $event = wp_get_scheduled_event( $job['hook'], $job['args'] );
}

// Good: Single query
$all_cron = _get_cron_array();
// Then filter in PHP
```

---

## Security Considerations

### Input Validation

All settings are validated before saving:

```php
public function validate( $input ) {
    // Validate boolean checkboxes
    $validated = array();
    
    foreach ( $input as $key => $value ) {
        $validated[ $key ] = (bool) $value;
    }
    
    return $validated;
}
```

### Output Escaping

All HTML output is escaped:

```php
// Escape HTML
echo esc_html( $tier );

// Escape URLs
echo esc_url( $url );

// Escape attributes
echo esc_attr( $class );
```

### Capability Checks

Always verify user permissions:

```php
if ( ! current_user_can( 'manage_options' ) ) {
    return;
}
```

---

## Related Documentation

- [ORCHESTRATION-LAYER-ARCHITECTURE.md](architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md) - Architecture overview
- [ORCHESTRATION-DASHBOARD-FINDINGS.md](ORCHESTRATION-DASHBOARD-FINDINGS.md) - Search findings and PR #852 details
- [orchestration-budget-enforcement.md](orchestration-budget-enforcement.md) - Budget enforcement details
- [RESOURCE-MANAGEMENT.md](RESOURCE-MANAGEMENT.md) - Resource management system

---

**Maintained by:** NV Digital Solutions  
**Documentation Repository:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos  
**License:** GPLv3 or later
