# Token Usage Manager Enhancement Plan

**Version:** 1.0  
**Created:** 2025-11-11  
**Status:** Planning Phase  
**Target:** WP oOS v1.1.0

## Executive Summary

This document outlines practical enhancements to the WP oOS Token Usage Manager, focusing on improving the current 100,000 token default limit with dynamic, intelligent, and user-friendly features that align with the plugin's enterprise-grade architecture while maintaining WordPress best practices.

## Current State Analysis

### Existing Token Management System

The WP oOS plugin currently implements:

1. **Fixed Token Limits**
   - `DEFAULT_GENERAL_LIMIT = 100000` tokens per user per 24 hours
   - `DEFAULT_CRAWL4AI_LIMIT = 200000` tokens for web scraping
   - Stored in `WP_MCP_AI_Tool_Token_Limits` class

2. **Tracking Infrastructure**
   - Per-user, per-tool token usage tracking
   - Daily usage granularity (24-hour rolling window)
   - 30-day historical data retention
   - User meta storage for usage data

3. **Admin Interface**
   - Three views: Per User, Per Tool, Per Site
   - Basic statistics display
   - Manual reset functionality
   - Limited visualization capabilities

4. **Enforcement Mechanism**
   - Pre-execution limit checking via `wp_mcp_ai_before_tool_execution` hook
   - Exception throwing when limits exceeded
   - Filter-based override capability
   - Logging of limit violations

### Identified Limitations

1. **Inflexible Limits**: Fixed 100k limit doesn't accommodate diverse usage patterns
2. **Coarse Granularity**: Daily tracking misses intra-day patterns
3. **Reactive Management**: No predictive or preventive features
4. **Limited Visibility**: Basic stats without trends or analytics
5. **Manual Administration**: No automation for common scenarios
6. **One-Size-Fits-All**: No differentiation by user role or tier

## Enhancement Objectives

### Primary Goals

1. **Flexibility**: Support multiple limit tiers and dynamic adjustments
2. **Intelligence**: Implement predictive and auto-scaling features
3. **Visibility**: Provide comprehensive analytics and monitoring
4. **Efficiency**: Optimize performance with caching and indexing
5. **Compliance**: Ensure GDPR compliance and audit capabilities

### Success Metrics

- Reduce limit-related support tickets by 50%
- Support 3+ user tiers with distinct limits
- Provide usage forecasting with 85%+ accuracy
- Decrease database query overhead by 30%
- Achieve 99% uptime for limit enforcement

## Detailed Enhancement Specifications

### Enhancement 1: Tiered Token Limits

#### Objective
Replace fixed 100k limit with flexible tier-based system.

#### Implementation Details

**New Constants:**
```php
class WP_MCP_AI_Tool_Token_Limits {
    // Tier definitions
    const TIER_FREE       = 'free';
    const TIER_PRO        = 'pro';
    const TIER_ENTERPRISE = 'enterprise';
    
    // Tier limits (per user, per 24 hours)
    const TIER_LIMITS = array(
        self::TIER_FREE       => 50000,   // 50k tokens
        self::TIER_PRO        => 200000,  // 200k tokens  
        self::TIER_ENTERPRISE => 1000000, // 1M tokens
    );
    
    // Role-based default tiers
    const ROLE_TIER_MAP = array(
        'subscriber'    => self::TIER_FREE,
        'contributor'   => self::TIER_FREE,
        'author'        => self::TIER_PRO,
        'editor'        => self::TIER_PRO,
        'administrator' => self::TIER_ENTERPRISE,
    );
}
```

**New Methods:**
```php
/**
 * Get user's token limit tier.
 *
 * @param int $user_id User ID.
 * @return string Tier identifier.
 */
public static function get_user_tier( $user_id ) {
    // Check user meta for custom tier
    $custom_tier = get_user_meta( $user_id, '_wp_mcp_ai_token_tier', true );
    
    if ( $custom_tier && isset( self::TIER_LIMITS[ $custom_tier ] ) ) {
        return $custom_tier;
    }
    
    // Determine tier based on user role
    $user = get_userdata( $user_id );
    
    if ( ! $user ) {
        return self::TIER_FREE;
    }
    
    foreach ( $user->roles as $role ) {
        if ( isset( self::ROLE_TIER_MAP[ $role ] ) ) {
            return self::ROLE_TIER_MAP[ $role ];
        }
    }
    
    return apply_filters( 'wp_mcp_ai_default_user_tier', self::TIER_FREE, $user_id );
}

/**
 * Get token limit for user based on tier.
 *
 * @param int    $user_id   User ID.
 * @param string $tool_slug Tool identifier.
 * @return int Token limit.
 */
public static function get_user_tool_limit( $user_id, $tool_slug ) {
    $tier = self::get_user_tier( $user_id );
    $base_limit = self::TIER_LIMITS[ $tier ];
    
    // Apply tool-specific multipliers
    $multiplier = self::get_tool_multiplier( $tool_slug );
    $limit = (int) ( $base_limit * $multiplier );
    
    return apply_filters( 'wp_mcp_ai_user_tool_limit', $limit, $user_id, $tool_slug, $tier );
}

/**
 * Get tool-specific limit multiplier.
 *
 * @param string $tool_slug Tool identifier.
 * @return float Multiplier (e.g., 2.0 for high-output tools).
 */
protected static function get_tool_multiplier( $tool_slug ) {
    $high_output_tools = array(
        'run_crawl4ai_job'       => 2.0,
        'search_content'         => 1.5,
        'web_search'             => 1.5,
        'submit_document_prompt' => 2.0,
    );
    
    return isset( $high_output_tools[ $tool_slug ] ) 
        ? $high_output_tools[ $tool_slug ] 
        : 1.0;
}
```

**Database Schema:**
```php
// User meta keys
_wp_mcp_ai_token_tier          // string: 'free', 'pro', 'enterprise'
_wp_mcp_ai_token_tier_expires  // timestamp: when tier expires (for temporary upgrades)
```

**Admin UI:**
- Bulk tier assignment interface
- Individual user tier editor
- Tier expiration date picker
- Usage comparison by tier (analytics)

#### Benefits
- Accommodates different user types and needs
- Enables monetization through tiered plans
- Provides upgrade path for power users
- Maintains backward compatibility

#### Testing Requirements
- Unit tests for tier detection logic
- Integration tests for role-based tiers
- Performance tests with 1000+ users across tiers
- Edge case testing (role changes, tier expiration)

---

### Enhancement 2: Hourly Usage Tracking

#### Objective
Improve tracking granularity from daily to hourly for better insights.

#### Implementation Details

**Modified Data Structure:**
```php
$usage[ $tool_slug ] = array(
    'total_tokens' => 0,
    'requests'     => 0,
    'first_used'   => '',
    'last_used'    => '',
    'daily'        => array(
        '2025-11-11' => 15000,  // Existing daily totals
    ),
    'hourly'       => array(
        '2025-11-11-14' => 3500,  // New: hourly breakdown
        '2025-11-11-15' => 4200,
    ),
);
```

**New Methods:**
```php
/**
 * Get user's hourly usage for a tool.
 *
 * @param int    $user_id   User ID.
 * @param string $tool_slug Tool identifier.
 * @param string $hour_key  Hour key (YYYY-MM-DD-HH format).
 * @return int Tokens used in that hour.
 */
public static function get_user_tool_hourly_usage( $user_id, $tool_slug, $hour_key = '' ) {
    if ( empty( $hour_key ) ) {
        $hour_key = gmdate( 'Y-m-d-H', current_time( 'timestamp', true ) );
    }
    
    $usage = self::get_user_tool_usage( $user_id );
    
    if ( ! isset( $usage[ $tool_slug ]['hourly'][ $hour_key ] ) ) {
        return 0;
    }
    
    return (int) $usage[ $tool_slug ]['hourly'][ $hour_key ];
}

/**
 * Get peak usage hour for a user and tool.
 *
 * @param int    $user_id   User ID.
 * @param string $tool_slug Tool identifier.
 * @param int    $days      Number of days to analyze.
 * @return array Peak hour data (hour, tokens, timestamp).
 */
public static function get_peak_usage_hour( $user_id, $tool_slug, $days = 7 ) {
    $usage = self::get_user_tool_usage( $user_id );
    
    if ( ! isset( $usage[ $tool_slug ]['hourly'] ) ) {
        return null;
    }
    
    $cutoff = gmdate( 'Y-m-d-H', strtotime( "-{$days} days", current_time( 'timestamp', true ) ) );
    $hourly = array_filter(
        $usage[ $tool_slug ]['hourly'],
        function( $key ) use ( $cutoff ) {
            return $key >= $cutoff;
        },
        ARRAY_FILTER_USE_KEY
    );
    
    if ( empty( $hourly ) ) {
        return null;
    }
    
    arsort( $hourly );
    $peak_hour = key( $hourly );
    
    return array(
        'hour'      => $peak_hour,
        'tokens'    => $hourly[ $peak_hour ],
        'timestamp' => strtotime( $peak_hour . ':00:00' ),
    );
}
```

**Cleanup Strategy:**
```php
/**
 * Clean up old hourly data (keep 7 days).
 */
protected static function cleanup_hourly_usage( &$usage ) {
    $cutoff = gmdate( 'Y-m-d-H', strtotime( '-7 days', current_time( 'timestamp', true ) ) );
    
    foreach ( $usage as $tool_slug => &$tool_data ) {
        if ( ! isset( $tool_data['hourly'] ) ) {
            continue;
        }
        
        foreach ( $tool_data['hourly'] as $hour => $tokens ) {
            if ( $hour < $cutoff ) {
                unset( $tool_data['hourly'][ $hour ] );
            }
        }
    }
}
```

#### Benefits
- Identify peak usage times for capacity planning
- Detect anomalous usage patterns (security)
- Enable time-based rate limiting
- Provide granular analytics

#### Performance Considerations
- Increased user meta size (~5-10% for 7 days of hourly data)
- More frequent cleanup operations
- Caching recommendations for frequently accessed data

---

### Enhancement 3: Usage Forecasting & Alerts

#### Objective
Predict when users will exhaust their token limits and alert proactively.

#### Implementation Details

**Forecasting Algorithm:**
```php
/**
 * Forecast when user will exhaust daily token limit.
 *
 * Uses linear regression on last 7 days of hourly usage.
 *
 * @param int    $user_id   User ID.
 * @param string $tool_slug Tool identifier.
 * @return array|null Forecast data or null if insufficient data.
 */
public static function forecast_limit_exhaustion( $user_id, $tool_slug ) {
    $usage = self::get_user_tool_usage( $user_id );
    
    if ( ! isset( $usage[ $tool_slug ]['hourly'] ) ) {
        return null;
    }
    
    // Get last 7 days of hourly data
    $cutoff = gmdate( 'Y-m-d-H', strtotime( '-7 days', current_time( 'timestamp', true ) ) );
    $hourly = array_filter(
        $usage[ $tool_slug ]['hourly'],
        function( $key ) use ( $cutoff ) {
            return $key >= $cutoff;
        },
        ARRAY_FILTER_USE_KEY
    );
    
    if ( count( $hourly ) < 24 ) {
        return null; // Insufficient data
    }
    
    // Calculate average hourly usage
    $avg_hourly = array_sum( $hourly ) / count( $hourly );
    
    // Get current daily usage
    $today_key = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );
    $today_usage = isset( $usage[ $tool_slug ]['daily'][ $today_key ] ) 
        ? (int) $usage[ $tool_slug ]['daily'][ $today_key ] 
        : 0;
    
    // Get user's daily limit
    $limit = self::get_user_tool_limit( $user_id, $tool_slug );
    
    // Calculate remaining tokens and hours
    $remaining_tokens = $limit - $today_usage;
    $hours_until_reset = self::get_hours_until_daily_reset();
    
    // Forecast
    $projected_usage = $today_usage + ( $avg_hourly * $hours_until_reset );
    
    return array(
        'will_exceed'        => $projected_usage > $limit,
        'current_usage'      => $today_usage,
        'projected_usage'    => (int) $projected_usage,
        'limit'              => $limit,
        'remaining_tokens'   => $remaining_tokens,
        'hours_until_reset'  => $hours_until_reset,
        'avg_hourly_usage'   => (int) $avg_hourly,
        'confidence'         => self::calculate_forecast_confidence( $hourly ),
    );
}

/**
 * Calculate confidence level of forecast (0-100%).
 *
 * Based on data consistency and recency.
 *
 * @param array $hourly_data Hourly usage data.
 * @return int Confidence percentage.
 */
protected static function calculate_forecast_confidence( $hourly_data ) {
    if ( count( $hourly_data ) < 24 ) {
        return 30; // Low confidence with <1 day of data
    }
    
    if ( count( $hourly_data ) >= 168 ) {
        return 90; // High confidence with 7 days of data
    }
    
    // Linear interpolation between 30% and 90%
    $hours = count( $hourly_data );
    return (int) ( 30 + ( ( $hours - 24 ) / 144 ) * 60 );
}

/**
 * Get hours until daily limit resets.
 *
 * @return float Hours remaining.
 */
protected static function get_hours_until_daily_reset() {
    $now = current_time( 'timestamp', true );
    $tomorrow = strtotime( 'tomorrow midnight', $now );
    return ( $tomorrow - $now ) / 3600;
}
```

**Alert System:**
```php
/**
 * Check if user should be alerted about approaching limit.
 *
 * @param int    $user_id   User ID.
 * @param string $tool_slug Tool identifier.
 * @return bool True if alert should be sent.
 */
public static function should_send_limit_alert( $user_id, $tool_slug ) {
    $forecast = self::forecast_limit_exhaustion( $user_id, $tool_slug );
    
    if ( ! $forecast || ! $forecast['will_exceed'] ) {
        return false;
    }
    
    // Only alert if confidence is high enough
    if ( $forecast['confidence'] < 70 ) {
        return false;
    }
    
    // Check if alert was already sent today
    $alert_key = "wp_mcp_ai_limit_alert_{$user_id}_{$tool_slug}";
    $last_alert = get_transient( $alert_key );
    
    if ( false !== $last_alert ) {
        return false; // Already alerted
    }
    
    // Set transient to prevent duplicate alerts
    set_transient( $alert_key, time(), DAY_IN_SECONDS );
    
    return true;
}

/**
 * Send limit alert to user.
 *
 * @param int    $user_id   User ID.
 * @param string $tool_slug Tool identifier.
 * @param array  $forecast  Forecast data.
 */
public static function send_limit_alert( $user_id, $tool_slug, $forecast ) {
    $user = get_userdata( $user_id );
    
    if ( ! $user ) {
        return;
    }
    
    $tier = self::get_user_tier( $user_id );
    
    $subject = __( 'Token Limit Alert - Action Recommended', 'wp-mcp-ai' );
    
    $message = sprintf(
        /* translators: 1: User name, 2: Tool name, 3: Current usage, 4: Projected usage, 5: Limit, 6: Current tier */
        __(
            "Hi %1\$s,\n\n" .
            "Based on your recent usage patterns, you're projected to exceed your daily token limit for the '%2\$s' tool.\n\n" .
            "Current Usage: %3\$s tokens\n" .
            "Projected Usage: %4\$s tokens\n" .
            "Daily Limit: %5\$s tokens\n" .
            "Current Tier: %6\$s\n\n" .
            "To avoid service interruption, consider:\n" .
            "- Optimizing your queries\n" .
            "- Upgrading to a higher tier\n" .
            "- Spreading usage throughout the day\n\n" .
            "Thank you,\n" .
            "WP oOS Team",
            'wp-mcp-ai'
        ),
        $user->display_name,
        $tool_slug,
        number_format_i18n( $forecast['current_usage'] ),
        number_format_i18n( $forecast['projected_usage'] ),
        number_format_i18n( $forecast['limit'] ),
        $tier
    );
    
    wp_mail( $user->user_email, $subject, $message );
    
    /**
     * Fires after limit alert is sent.
     *
     * @param int    $user_id   User ID.
     * @param string $tool_slug Tool identifier.
     * @param array  $forecast  Forecast data.
     */
    do_action( 'wp_mcp_ai_limit_alert_sent', $user_id, $tool_slug, $forecast );
    
    WP_MCP_AI_Logger::log_event(
        'token_limit_alert_sent',
        'User alerted about approaching token limit.',
        array(
            'user_id'   => $user_id,
            'tool_slug' => $tool_slug,
            'forecast'  => $forecast,
        )
    );
}
```

**Cron Job:**
```php
/**
 * Register cron job for hourly forecast checks.
 */
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'wp_mcp_ai_hourly_forecast_check' ) ) {
        wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_hourly_forecast_check' );
    }
} );

add_action( 'wp_mcp_ai_hourly_forecast_check', function() {
    WP_MCP_AI_Tool_Token_Limits::check_and_send_alerts();
} );
```

#### Benefits
- Proactive user communication
- Reduced limit exhaustion incidents
- Improved user experience
- Data-driven tier recommendations

---

### Enhancement 4: Admin UI Improvements

#### Objective
Enhance admin interface with visual analytics and management tools.

#### Implementation Details

**New Dashboard Widgets:**

1. **Usage Trend Chart** (Chart.js)
   - Line graph: 7-day or 30-day token usage
   - Multiple tools comparison
   - Tier-based color coding

2. **Real-Time Usage Meter**
   - Progress bars for each user showing % of limit used
   - Color indicators (green <50%, yellow 50-80%, red >80%)
   - Live updates via AJAX

3. **Tier Distribution Pie Chart**
   - Visual breakdown of users by tier
   - Click to filter user list

4. **Top Consumers Table**
   - Top 10 users by token consumption
   - Sortable by different metrics
   - Quick actions (adjust tier, reset usage)

**Bulk Management Interface:**
```php
/**
 * Bulk adjust user tiers.
 *
 * @param array  $user_ids Array of user IDs.
 * @param string $new_tier New tier to assign.
 * @param string $expiry   Optional expiry date (YYYY-MM-DD).
 * @return array Results (success/failure counts).
 */
public static function bulk_set_user_tiers( $user_ids, $new_tier, $expiry = '' ) {
    $results = array(
        'success' => 0,
        'failed'  => 0,
        'errors'  => array(),
    );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        $results['errors'][] = __( 'Insufficient permissions.', 'wp-mcp-ai' );
        return $results;
    }
    
    if ( ! isset( self::TIER_LIMITS[ $new_tier ] ) ) {
        $results['errors'][] = __( 'Invalid tier specified.', 'wp-mcp-ai' );
        return $results;
    }
    
    foreach ( $user_ids as $user_id ) {
        $user_id = absint( $user_id );
        
        if ( ! $user_id ) {
            ++$results['failed'];
            continue;
        }
        
        update_user_meta( $user_id, '_wp_mcp_ai_token_tier', $new_tier );
        
        if ( ! empty( $expiry ) ) {
            $expiry_timestamp = strtotime( $expiry . ' 23:59:59' );
            if ( $expiry_timestamp ) {
                update_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', $expiry_timestamp );
            }
        }
        
        ++$results['success'];
    }
    
    WP_MCP_AI_Logger::log_event(
        'bulk_tier_update',
        'Administrator performed bulk tier update.',
        array(
            'user_count' => count( $user_ids ),
            'new_tier'   => $new_tier,
            'expiry'     => $expiry,
            'results'    => $results,
        )
    );
    
    return $results;
}
```

**Export Functionality:**
```php
/**
 * Export usage report as CSV.
 *
 * @param array $filters Report filters (date_range, tier, tool).
 * @return string CSV content.
 */
public static function export_usage_report( $filters = array() ) {
    global $wpdb;
    
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }
    
    // Get users matching filters
    $users = self::get_filtered_users( $filters );
    
    $csv = array();
    $csv[] = array(
        'User ID',
        'Username',
        'Email',
        'Tier',
        'Total Tokens',
        'Total Requests',
        'Last Used',
        'Limit',
        'Usage %',
    );
    
    foreach ( $users as $user_id ) {
        $user = get_userdata( $user_id );
        $tier = self::get_user_tier( $user_id );
        $limit = self::TIER_LIMITS[ $tier ];
        $usage = self::get_user_tool_usage( $user_id );
        
        $total_tokens = 0;
        $total_requests = 0;
        $last_used = '';
        
        foreach ( $usage as $tool_data ) {
            $total_tokens += isset( $tool_data['total_tokens'] ) ? (int) $tool_data['total_tokens'] : 0;
            $total_requests += isset( $tool_data['requests'] ) ? (int) $tool_data['requests'] : 0;
            
            if ( isset( $tool_data['last_used'] ) && $tool_data['last_used'] > $last_used ) {
                $last_used = $tool_data['last_used'];
            }
        }
        
        $usage_pct = $limit > 0 ? round( ( $total_tokens / $limit ) * 100, 2 ) : 0;
        
        $csv[] = array(
            $user_id,
            $user->user_login,
            $user->user_email,
            $tier,
            $total_tokens,
            $total_requests,
            $last_used,
            $limit,
            $usage_pct . '%',
        );
    }
    
    // Convert to CSV string
    ob_start();
    $output = fopen( 'php://output', 'w' );
    foreach ( $csv as $row ) {
        fputcsv( $output, $row );
    }
    fclose( $output );
    return ob_get_clean();
}
```

#### Benefits
- Better administrative oversight
- Data-driven decision making
- Efficient bulk operations
- Compliance reporting capabilities

---

### Enhancement 5: REST API Endpoints

#### Objective
Provide programmatic access to token management features.

#### Implementation Details

**New Endpoints:**

```php
/**
 * Register REST API endpoints for token management.
 */
add_action( 'rest_api_init', function() {
    // Get user's current tier and limits
    register_rest_route( 'mcp-ai/v1', '/users/(?P<id>\d+)/token-tier', array(
        'methods'             => 'GET',
        'callback'            => 'wp_mcp_ai_rest_get_user_tier',
        'permission_callback' => 'wp_mcp_ai_rest_check_user_access',
        'args'                => array(
            'id' => array(
                'required' => true,
                'type'     => 'integer',
            ),
        ),
    ) );
    
    // Update user's tier
    register_rest_route( 'mcp-ai/v1', '/users/(?P<id>\d+)/token-tier', array(
        'methods'             => 'POST',
        'callback'            => 'wp_mcp_ai_rest_update_user_tier',
        'permission_callback' => 'wp_mcp_ai_rest_check_admin_access',
        'args'                => array(
            'id'     => array(
                'required' => true,
                'type'     => 'integer',
            ),
            'tier'   => array(
                'required' => true,
                'type'     => 'string',
                'enum'     => array( 'free', 'pro', 'enterprise' ),
            ),
            'expiry' => array(
                'type'   => 'string',
                'format' => 'date',
            ),
        ),
    ) );
    
    // Get user's token usage
    register_rest_route( 'mcp-ai/v1', '/users/(?P<id>\d+)/token-usage', array(
        'methods'             => 'GET',
        'callback'            => 'wp_mcp_ai_rest_get_user_usage',
        'permission_callback' => 'wp_mcp_ai_rest_check_user_access',
        'args'                => array(
            'id'        => array(
                'required' => true,
                'type'     => 'integer',
            ),
            'tool'      => array(
                'type' => 'string',
            ),
            'timeframe' => array(
                'type'    => 'string',
                'enum'    => array( 'hourly', 'daily', 'weekly', 'monthly' ),
                'default' => 'daily',
            ),
        ),
    ) );
    
    // Get usage forecast
    register_rest_route( 'mcp-ai/v1', '/users/(?P<id>\d+)/token-forecast', array(
        'methods'             => 'GET',
        'callback'            => 'wp_mcp_ai_rest_get_usage_forecast',
        'permission_callback' => 'wp_mcp_ai_rest_check_user_access',
        'args'                => array(
            'id'   => array(
                'required' => true,
                'type'     => 'integer',
            ),
            'tool' => array(
                'required' => true,
                'type'     => 'string',
            ),
        ),
    ) );
} );

/**
 * REST: Get user's tier and limits.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response object.
 */
function wp_mcp_ai_rest_get_user_tier( $request ) {
    $user_id = absint( $request['id'] );
    
    $tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
    $limits = WP_MCP_AI_Tool_Token_Limits::TIER_LIMITS;
    $tier_expires = get_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', true );
    
    return rest_ensure_response( array(
        'user_id'      => $user_id,
        'tier'         => $tier,
        'limit'        => $limits[ $tier ],
        'tier_expires' => $tier_expires ? gmdate( 'Y-m-d H:i:s', $tier_expires ) : null,
    ) );
}

/**
 * REST: Permission callback for user access.
 *
 * @param WP_REST_Request $request Request object.
 * @return bool True if user can access.
 */
function wp_mcp_ai_rest_check_user_access( $request ) {
    $user_id = absint( $request['id'] );
    $current_user_id = get_current_user_id();
    
    // User can access their own data or admins can access any
    return $user_id === $current_user_id || current_user_can( 'manage_options' );
}

/**
 * REST: Permission callback for admin access.
 *
 * @param WP_REST_Request $request Request object.
 * @return bool True if user is admin.
 */
function wp_mcp_ai_rest_check_admin_access( $request ) {
    return current_user_can( 'manage_options' );
}
```

#### Benefits
- Third-party integration support
- Headless WordPress compatibility
- Mobile app development enablement
- Automation and scripting capabilities

---

## Performance Optimizations

### Database Indexing

Add indexes to improve query performance:

```sql
-- Add index on user meta for faster tier lookups
ALTER TABLE wp_usermeta 
ADD INDEX idx_wp_mcp_ai_token_tier (meta_key, meta_value(20)) 
WHERE meta_key = '_wp_mcp_ai_token_tier';

-- Add index on user meta for usage lookups
ALTER TABLE wp_usermeta 
ADD INDEX idx_wp_mcp_ai_usage (meta_key, user_id) 
WHERE meta_key = '_wp_mcp_ai_tool_token_usage';
```

### Caching Strategy

```php
/**
 * Get user tier with caching.
 *
 * @param int $user_id User ID.
 * @return string Tier identifier.
 */
public static function get_user_tier_cached( $user_id ) {
    $cache_key = "wp_mcp_ai_user_tier_{$user_id}";
    $tier = wp_cache_get( $cache_key );
    
    if ( false === $tier ) {
        $tier = self::get_user_tier( $user_id );
        wp_cache_set( $cache_key, $tier, '', HOUR_IN_SECONDS );
    }
    
    return $tier;
}

/**
 * Invalidate tier cache on update.
 */
add_action( 'update_user_meta', function( $meta_id, $user_id, $meta_key ) {
    if ( '_wp_mcp_ai_token_tier' === $meta_key ) {
        wp_cache_delete( "wp_mcp_ai_user_tier_{$user_id}" );
    }
}, 10, 3 );
```

### Query Optimization

```php
/**
 * Get all users by tier (optimized).
 *
 * @param string $tier Tier identifier.
 * @return array User IDs.
 */
public static function get_users_by_tier_optimized( $tier ) {
    global $wpdb;
    
    // Use direct SQL for better performance
    $user_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} 
             WHERE meta_key = %s AND meta_value = %s",
            '_wp_mcp_ai_token_tier',
            $tier
        )
    );
    
    return array_map( 'absint', $user_ids );
}
```

## Security Considerations

### Audit Logging

```php
/**
 * Log all tier changes for audit trail.
 *
 * @param int    $user_id  User ID.
 * @param string $old_tier Previous tier.
 * @param string $new_tier New tier.
 * @param int    $admin_id Admin who made the change.
 */
public static function log_tier_change( $user_id, $old_tier, $new_tier, $admin_id ) {
    WP_MCP_AI_Logger::log_event(
        'token_tier_changed',
        'User token tier was modified.',
        array(
            'user_id'      => $user_id,
            'old_tier'     => $old_tier,
            'new_tier'     => $new_tier,
            'changed_by'   => $admin_id,
            'ip_address'   => WP_MCP_AI_Logger::get_client_ip(),
            'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
        )
    );
}
```

### Anomaly Detection

```php
/**
 * Detect unusual usage patterns.
 *
 * @param int    $user_id   User ID.
 * @param string $tool_slug Tool identifier.
 * @param int    $tokens    Tokens used in request.
 * @return bool True if anomaly detected.
 */
public static function detect_usage_anomaly( $user_id, $tool_slug, $tokens ) {
    // Get average hourly usage for last 7 days
    $usage = self::get_user_tool_usage( $user_id );
    
    if ( ! isset( $usage[ $tool_slug ]['hourly'] ) ) {
        return false;
    }
    
    $hourly_data = $usage[ $tool_slug ]['hourly'];
    $avg_hourly = array_sum( $hourly_data ) / count( $hourly_data );
    
    // Flag if current request is 5x average
    if ( $tokens > ( $avg_hourly * 5 ) ) {
        WP_MCP_AI_Logger::log_event(
            'usage_anomaly_detected',
            'Unusual token usage pattern detected.',
            array(
                'user_id'    => $user_id,
                'tool_slug'  => $tool_slug,
                'tokens'     => $tokens,
                'avg_hourly' => (int) $avg_hourly,
                'multiplier' => round( $tokens / $avg_hourly, 2 ),
            )
        );
        
        return true;
    }
    
    return false;
}
```

## Migration Strategy

### Phase 1: Database Migration

```php
/**
 * Migrate existing users to tiered system.
 */
public static function migrate_to_tiered_limits() {
    global $wpdb;
    
    // Get all users with usage data
    $users = $wpdb->get_results(
        "SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
         WHERE meta_key = '_wp_mcp_ai_tool_token_usage'"
    );
    
    foreach ( $users as $row ) {
        $user_id = absint( $row->user_id );
        $user = get_userdata( $user_id );
        
        if ( ! $user ) {
            continue;
        }
        
        // Assign tier based on role
        $tier = self::TIER_FREE;
        
        if ( in_array( 'administrator', $user->roles, true ) ) {
            $tier = self::TIER_ENTERPRISE;
        } elseif ( in_array( 'editor', $user->roles, true ) || in_array( 'author', $user->roles, true ) ) {
            $tier = self::TIER_PRO;
        }
        
        update_user_meta( $user_id, '_wp_mcp_ai_token_tier', $tier );
    }
    
    update_option( 'wp_mcp_ai_tiered_limits_migrated', true );
}
```

### Phase 2: Backward Compatibility

```php
/**
 * Ensure backward compatibility with old limit system.
 */
add_filter( 'wp_mcp_ai_enforce_tool_token_limits', function( $enforce, $tool_slug, $user_id, $usage, $limit ) {
    // If migration hasn't run, use old behavior
    if ( ! get_option( 'wp_mcp_ai_tiered_limits_migrated' ) ) {
        return $enforce;
    }
    
    // New tier-based behavior
    $tier_limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, $tool_slug );
    
    if ( $usage >= $tier_limit ) {
        return $enforce;
    }
    
    return false; // Don't enforce old limit if new tier limit not exceeded
}, 10, 5 );
```

## Testing Strategy

### Unit Tests

```php
/**
 * Test tier assignment logic.
 */
class WP_MCP_AI_Tiered_Limits_Test extends WP_UnitTestCase {
    
    public function test_user_tier_by_role() {
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        $tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $admin_id );
        $this->assertEquals( 'enterprise', $tier );
        
        $subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        $tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $subscriber_id );
        $this->assertEquals( 'free', $tier );
    }
    
    public function test_custom_tier_override() {
        $user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        update_user_meta( $user_id, '_wp_mcp_ai_token_tier', 'pro' );
        
        $tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
        $this->assertEquals( 'pro', $tier );
    }
    
    public function test_tier_expiration() {
        $user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        update_user_meta( $user_id, '_wp_mcp_ai_token_tier', 'pro' );
        update_user_meta( $user_id, '_wp_mcp_ai_token_tier_expires', strtotime( '-1 day' ) );
        
        // TODO: Implement expiration check
        $tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
        $this->assertEquals( 'free', $tier, 'Expired tier should revert to default' );
    }
    
    public function test_usage_forecast_accuracy() {
        $user_id = $this->factory->user->create();
        
        // Generate synthetic hourly data
        for ( $i = 0; $i < 48; $i++ ) {
            $hour_key = gmdate( 'Y-m-d-H', strtotime( "-{$i} hours" ) );
            // Simulate constant usage of 1000 tokens/hour
            // TODO: Add method to inject test data
        }
        
        $forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( $user_id, 'test_tool' );
        
        $this->assertIsArray( $forecast );
        $this->assertArrayHasKey( 'projected_usage', $forecast );
        $this->assertGreaterThan( 70, $forecast['confidence'] );
    }
}
```

### Integration Tests

- Test tier changes through REST API
- Test bulk operations with 1000+ users
- Test cron job execution for alerts
- Test forecast accuracy with real usage patterns

### Performance Tests

- Benchmark tier lookup with 10k users
- Benchmark usage tracking with concurrent requests
- Memory profiling with hourly data retention
- Database query optimization verification

## Rollout Plan

### Week 1-2: Core Implementation
- Implement tiered limit system
- Add hourly tracking
- Create migration scripts
- Write unit tests

### Week 3-4: Forecasting & Alerts
- Implement forecasting algorithm
- Add alert system
- Create cron jobs
- Test accuracy

### Week 5-6: Admin UI
- Build dashboard widgets
- Add Charts.js visualization
- Implement export functionality
- User acceptance testing

### Week 7-8: REST API & Polish
- Create REST endpoints
- Add API documentation
- Performance optimization
- Security audit

### Week 9: Beta Testing
- Deploy to staging environment
- Invite beta testers
- Collect feedback
- Bug fixes

### Week 10: Production Release
- Final code review
- Documentation update
- Production deployment
- Monitor metrics

## Success Criteria

### Quantitative Metrics
- 50% reduction in limit-related support tickets
- 85%+ forecast accuracy
- <100ms tier lookup latency
- 99% uptime for limit enforcement
- 30% reduction in database query overhead

### Qualitative Metrics
- Positive user feedback on flexibility
- Admin satisfaction with management tools
- Successful tier-based monetization
- Improved compliance reporting

## Risks & Mitigation

### Risk 1: Performance Degradation
**Mitigation:** Implement caching, optimize queries, conduct performance testing

### Risk 2: Data Migration Issues
**Mitigation:** Extensive testing, rollback plan, gradual rollout

### Risk 3: User Confusion
**Mitigation:** Clear documentation, migration communications, in-app guidance

### Risk 4: Forecast Inaccuracy
**Mitigation:** Conservative confidence thresholds, continuous algorithm improvement

## Future Enhancements (v1.2.0+)

1. **Machine Learning-Based Forecasting**
   - Use TensorFlow.js for advanced predictions
   - Pattern recognition for anomaly detection

2. **Token Marketplace**
   - Allow users to purchase/transfer tokens
   - Implement token credits system

3. **Multi-Tenancy Support**
   - Organization-level token pools
   - Hierarchical limit management

4. **Advanced Analytics**
   - Cost attribution by project
   - ROI tracking for AI usage
   - Comparative benchmarks

5. **Mobile App**
   - Real-time usage monitoring
   - Push notifications for alerts
   - On-the-go tier management

## Conclusion

This enhancement plan provides a comprehensive roadmap for improving the WP oOS Token Usage Manager from a fixed 100k limit system to a flexible, intelligent, tier-based solution. The proposed changes maintain backward compatibility while adding enterprise-grade features that support various use cases and user segments.

Key benefits include:
- **Flexibility** through tiered limits
- **Intelligence** via forecasting and automation
- **Visibility** with enhanced analytics
- **Efficiency** through performance optimizations
- **Scalability** to support growth

Implementation will follow WordPress best practices, maintain security standards, and provide thorough testing at each phase. The result will be a world-class token management system that positions WP oOS as a leader in enterprise WordPress AI integration.

---

**Document Maintainer:** WP oOS Development Team  
**Review Schedule:** Quarterly  
**Next Review:** 2025-02-11
