# Token Manager Phase 7: Advanced Analytics & Visualization

**Version:** 1.0  
**Created:** 2025-11-12  
**Status:** Planning Phase  
**Target:** WP oOS v1.2.0  
**Prerequisites:** Phases 1-6 Complete

## Executive Summary

This document outlines **Phase 7** of the Token Usage Manager enhancement roadmap. Building on the solid foundation established in Phases 1-6 (tiered limits, hourly tracking, forecasting, REST API, and performance optimizations), Phase 7 focuses on **Advanced Analytics & Visualization** to provide administrators and users with actionable insights into token consumption patterns.

## Current State (After Phase 6)

### Completed Features

**Phase 1-3: Core System**
- ✅ Tiered token limits (Free: 50k, Pro: 200k, Enterprise: 1M tokens/day)
- ✅ Hourly usage tracking with 7-day retention
- ✅ Usage forecasting with 30-90% confidence scoring
- ✅ Email alert system for limit exhaustion

**Phase 4: Admin UI**
- ✅ Per-user, per-tool, and per-site views
- ✅ CSV export with filtering capabilities
- ✅ Bulk tier management UI
- ✅ Tier badge displays

**Phase 5: API Integration**
- ✅ REST endpoints for tier management and usage queries
- ✅ Permission-based access control
- ✅ Forecast API endpoint

**Phase 6: Performance & Security**
- ✅ WordPress object caching for tier lookups
- ✅ Database indexing optimization
- ✅ Anomaly detection (5x threshold)
- ✅ Comprehensive audit logging

### Current Gaps

Despite the robust foundation, several analytical and visualization capabilities are missing:

1. **No Visual Analytics**: Data is presented in tables, not charts
2. **Limited Historical Analysis**: 7-day hourly + 30-day daily, but no trend analysis
3. **No Cost Attribution**: Token usage not linked to actual costs or projects
4. **Manual Reporting**: No automated scheduled reports
5. **Basic Anomaly Detection**: 5x threshold only, no pattern recognition
6. **No Comparative Analysis**: Can't compare users, tools, or time periods
7. **Missing Predictive Insights**: Forecasting exists but no recommendations

## Phase 7 Objectives

### Primary Goals

1. **Visualize Token Usage**: Implement Chart.js-based dashboards with interactive charts
2. **Cost Intelligence**: Track and attribute costs to users, tools, and time periods
3. **Advanced Analytics**: Trend analysis, pattern recognition, and comparative metrics
4. **Automated Reporting**: Scheduled email reports with customizable templates
5. **Enhanced Anomaly Detection**: ML-based pattern recognition beyond simple thresholds
6. **Actionable Insights**: Auto-generate recommendations for tier adjustments and optimizations

### Success Metrics

- Reduce time spent analyzing token usage by 70%
- Increase admin satisfaction with analytics features to 90%+
- Enable data-driven tier assignment decisions
- Provide ROI visibility within 2 clicks
- Automate 80% of routine usage reporting tasks

## Detailed Feature Specifications

### Feature 1: Chart.js Integration & Dashboards

#### Overview
Replace static tables with interactive, responsive charts using Chart.js library already included in WP oOS.

#### Implementation Details

**1.1 Dashboard Widget Architecture**

```php
/**
 * New class: WP_MCP_AI_Analytics_Dashboard
 * Location: includes/admin/class-wp-mcp-ai-analytics-dashboard.php
 */
class WP_MCP_AI_Analytics_Dashboard {
    /**
     * Register dashboard widgets.
     */
    public static function register_widgets() {
        wp_add_dashboard_widget(
            'wp_mcp_ai_token_usage_overview',
            __( 'AI Token Usage Overview', 'wp-mcp-ai' ),
            array( __CLASS__, 'render_usage_overview_widget' )
        );
        
        wp_add_dashboard_widget(
            'wp_mcp_ai_cost_breakdown',
            __( 'AI Cost Breakdown', 'wp-mcp-ai' ),
            array( __CLASS__, 'render_cost_breakdown_widget' )
        );
    }
    
    /**
     * Render usage overview widget with Chart.js.
     */
    public static function render_usage_overview_widget() {
        $data = self::get_usage_overview_data();
        include WP_MCP_AI_PLUGIN_DIR . '/includes/admin/widgets/token-usage-overview.php';
    }
    
    /**
     * Get usage data formatted for Chart.js.
     */
    private static function get_usage_overview_data() {
        // Last 30 days of usage data
        $data = array(
            'labels' => array(), // Dates
            'datasets' => array(
                array(
                    'label' => __( 'Total Tokens', 'wp-mcp-ai' ),
                    'data' => array(), // Token counts
                    'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'borderWidth' => 2,
                ),
            ),
        );
        
        // Populate data from database
        // Implementation details...
        
        return $data;
    }
}
```

**1.2 Chart Types to Implement**

| Chart Type | Purpose | Location |
|------------|---------|----------|
| **Line Chart** | Token usage trends over time | Per-user, per-site views |
| **Bar Chart** | Comparison across tools/users | Per-tool view |
| **Pie Chart** | Usage distribution by provider | Per-site view |
| **Stacked Bar** | Provider + model breakdown | All views |
| **Gauge Chart** | Current usage vs. limit | Per-user dashboard |
| **Heatmap** | Hourly usage patterns | Advanced analytics |

**1.3 Interactive Features**

- **Zoom & Pan**: Navigate time ranges
- **Tooltips**: Hover for detailed stats
- **Legends**: Toggle datasets on/off
- **Export**: Download charts as PNG
- **Responsive**: Adapt to screen sizes

**1.4 File Structure**

```
includes/admin/
├── class-wp-mcp-ai-analytics-dashboard.php  (NEW)
├── widgets/
│   ├── token-usage-overview.php             (NEW)
│   ├── cost-breakdown.php                   (NEW)
│   └── usage-forecast.php                   (NEW)
assets/js/
├── token-manager-charts.js                  (ENHANCE - already exists)
└── analytics-dashboard.js                   (NEW)
assets/css/
└── analytics-dashboard.css                  (NEW)
```

### Feature 2: Cost Attribution & ROI Tracking

#### Overview
Link token usage to actual costs based on provider pricing models and enable project-level cost attribution.

#### Implementation Details

**2.1 Cost Calculation Engine**

```php
/**
 * New class: WP_MCP_AI_Cost_Calculator
 * Location: includes/class-wp-mcp-ai-cost-calculator.php
 */
class WP_MCP_AI_Cost_Calculator {
    /**
     * Provider pricing models (per 1M tokens).
     */
    const PRICING = array(
        'openai' => array(
            'gpt-4o'           => array( 'input' => 2.50, 'output' => 10.00 ),
            'gpt-4o-mini'      => array( 'input' => 0.15, 'output' => 0.60 ),
            'gpt-4-turbo'      => array( 'input' => 10.00, 'output' => 30.00 ),
            'gpt-3.5-turbo'    => array( 'input' => 0.50, 'output' => 1.50 ),
            'o1-preview'       => array( 'input' => 15.00, 'output' => 60.00 ),
            'o1-mini'          => array( 'input' => 3.00, 'output' => 12.00 ),
        ),
        'gemini' => array(
            'gemini-1.5-pro'   => array( 'input' => 1.25, 'output' => 5.00 ),
            'gemini-1.5-flash' => array( 'input' => 0.075, 'output' => 0.30 ),
            'gemini-2.0-flash' => array( 'input' => 0.10, 'output' => 0.40 ),
        ),
        'anthropic' => array(
            'claude-3.5-sonnet' => array( 'input' => 3.00, 'output' => 15.00 ),
            'claude-3-opus'     => array( 'input' => 15.00, 'output' => 75.00 ),
            'claude-3-haiku'    => array( 'input' => 0.25, 'output' => 1.25 ),
        ),
        'ollama' => array(
            'default' => array( 'input' => 0.00, 'output' => 0.00 ), // Free
        ),
    );
    
    /**
     * Calculate cost for a specific usage record.
     *
     * @param string $provider Provider name.
     * @param string $model Model name.
     * @param int $input_tokens Input token count.
     * @param int $output_tokens Output token count.
     * @return float Cost in USD.
     */
    public static function calculate_cost( $provider, $model, $input_tokens, $output_tokens ) {
        $pricing = self::get_model_pricing( $provider, $model );
        
        if ( ! $pricing ) {
            return 0.0;
        }
        
        $input_cost  = ( $input_tokens / 1000000 ) * $pricing['input'];
        $output_cost = ( $output_tokens / 1000000 ) * $pricing['output'];
        
        return $input_cost + $output_cost;
    }
    
    /**
     * Get cost breakdown for a user over time period.
     *
     * @param int $user_id User ID.
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date End date (YYYY-MM-DD).
     * @return array Cost breakdown.
     */
    public static function get_user_cost_breakdown( $user_id, $start_date, $end_date ) {
        $usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );
        
        $breakdown = array(
            'total_cost' => 0.0,
            'by_provider' => array(),
            'by_model' => array(),
            'by_tool' => array(),
            'by_date' => array(),
        );
        
        // Calculate costs with date filtering
        // Implementation details...
        
        return $breakdown;
    }
    
    /**
     * Calculate ROI based on productivity metrics.
     *
     * @param int $user_id User ID.
     * @param array $metrics Productivity metrics.
     * @return array ROI data.
     */
    public static function calculate_roi( $user_id, $metrics ) {
        $cost = self::get_user_cost_breakdown( $user_id, '-30 days', 'now' );
        
        // Example metrics: tasks_automated, time_saved_hours, revenue_generated
        $roi = array(
            'total_cost' => $cost['total_cost'],
            'time_saved' => $metrics['time_saved_hours'] ?? 0,
            'tasks_automated' => $metrics['tasks_automated'] ?? 0,
            'cost_per_task' => 0.0,
            'hourly_rate' => $metrics['hourly_rate'] ?? 50.0,
            'value_generated' => 0.0,
            'roi_percentage' => 0.0,
        );
        
        if ( $roi['tasks_automated'] > 0 ) {
            $roi['cost_per_task'] = $roi['total_cost'] / $roi['tasks_automated'];
        }
        
        $roi['value_generated'] = $roi['time_saved'] * $roi['hourly_rate'];
        
        if ( $roi['total_cost'] > 0 ) {
            $roi['roi_percentage'] = ( ( $roi['value_generated'] - $roi['total_cost'] ) / $roi['total_cost'] ) * 100;
        }
        
        return $roi;
    }
}
```

**2.2 Project-Level Attribution**

```php
/**
 * Associate token usage with projects/campaigns.
 */
add_filter( 'wp_mcp_ai_track_usage_metadata', function( $metadata, $context ) {
    // Add project_id from context if available
    if ( isset( $context['project_id'] ) ) {
        $metadata['project_id'] = sanitize_key( $context['project_id'] );
    }
    
    // Add campaign from query parameters
    if ( isset( $_GET['campaign'] ) ) {
        $metadata['campaign'] = sanitize_text_field( $_GET['campaign'] );
    }
    
    return $metadata;
}, 10, 2 );
```

**2.3 Cost Reports**

- **Daily Cost Summary**: Email report with yesterday's costs
- **Monthly Invoice**: PDF invoice with cost breakdown
- **Budget Alerts**: Notify when approaching budget thresholds
- **Cost Comparison**: Compare costs across time periods

### Feature 3: Advanced Analytics & Trend Analysis

#### Overview
Implement sophisticated analytical capabilities to identify patterns, trends, and insights.

#### Implementation Details

**3.1 Trend Analysis Algorithms**

```php
/**
 * New class: WP_MCP_AI_Analytics_Engine
 * Location: includes/class-wp-mcp-ai-analytics-engine.php
 */
class WP_MCP_AI_Analytics_Engine {
    /**
     * Calculate usage trends over time.
     *
     * @param int $user_id User ID.
     * @param int $days Number of days to analyze.
     * @return array Trend data.
     */
    public static function calculate_usage_trend( $user_id, $days = 30 ) {
        $usage_data = self::get_daily_usage( $user_id, $days );
        
        // Linear regression for trend line
        $trend = self::linear_regression( $usage_data );
        
        return array(
            'direction' => $trend['slope'] > 0 ? 'increasing' : 'decreasing',
            'slope' => $trend['slope'],
            'r_squared' => $trend['r_squared'],
            'projected_30d' => $trend['projected_value'],
            'trend_strength' => self::categorize_trend( $trend['r_squared'] ),
        );
    }
    
    /**
     * Perform linear regression analysis.
     *
     * @param array $data_points Array of (x, y) points.
     * @return array Regression results.
     */
    private static function linear_regression( $data_points ) {
        $n = count( $data_points );
        
        if ( $n < 2 ) {
            return array(
                'slope' => 0,
                'intercept' => 0,
                'r_squared' => 0,
                'projected_value' => 0,
            );
        }
        
        $sum_x = $sum_y = $sum_xy = $sum_x2 = $sum_y2 = 0;
        
        foreach ( $data_points as $point ) {
            $x = $point['x'];
            $y = $point['y'];
            
            $sum_x  += $x;
            $sum_y  += $y;
            $sum_xy += $x * $y;
            $sum_x2 += $x * $x;
            $sum_y2 += $y * $y;
        }
        
        $slope = ( $n * $sum_xy - $sum_x * $sum_y ) / ( $n * $sum_x2 - $sum_x * $sum_x );
        $intercept = ( $sum_y - $slope * $sum_x ) / $n;
        
        // Calculate R-squared
        $mean_y = $sum_y / $n;
        $ss_tot = $ss_res = 0;
        
        foreach ( $data_points as $point ) {
            $y_pred = $slope * $point['x'] + $intercept;
            $ss_tot += pow( $point['y'] - $mean_y, 2 );
            $ss_res += pow( $point['y'] - $y_pred, 2 );
        }
        
        $r_squared = 1 - ( $ss_res / $ss_tot );
        
        // Project 30 days ahead
        $projected_value = $slope * ( $n + 30 ) + $intercept;
        
        return array(
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => $r_squared,
            'projected_value' => max( 0, $projected_value ),
        );
    }
    
    /**
     * Identify usage patterns (daily, weekly, monthly cycles).
     *
     * @param int $user_id User ID.
     * @return array Pattern insights.
     */
    public static function identify_usage_patterns( $user_id ) {
        $hourly_data = self::get_hourly_usage( $user_id, 30 );
        
        $patterns = array(
            'peak_hour' => self::find_peak_hour( $hourly_data ),
            'peak_day' => self::find_peak_day( $hourly_data ),
            'weekday_vs_weekend' => self::compare_weekday_weekend( $hourly_data ),
            'cyclicity' => self::detect_cycles( $hourly_data ),
        );
        
        return $patterns;
    }
    
    /**
     * Compare users for benchmarking.
     *
     * @param array $user_ids Array of user IDs to compare.
     * @param string $metric Metric to compare ('tokens', 'cost', 'efficiency').
     * @return array Comparison results.
     */
    public static function compare_users( $user_ids, $metric = 'tokens' ) {
        $comparison = array();
        
        foreach ( $user_ids as $user_id ) {
            $user_data = array(
                'user_id' => $user_id,
                'user' => get_userdata( $user_id ),
                'tier' => WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id ),
            );
            
            switch ( $metric ) {
                case 'tokens':
                    $user_data['total_tokens'] = self::get_total_tokens( $user_id );
                    $user_data['avg_daily'] = self::get_avg_daily_tokens( $user_id );
                    break;
                case 'cost':
                    $user_data['total_cost'] = WP_MCP_AI_Cost_Calculator::get_user_cost_breakdown( $user_id, '-30 days', 'now' )['total_cost'];
                    break;
                case 'efficiency':
                    $user_data['cost_per_token'] = self::calculate_cost_per_token( $user_id );
                    break;
            }
            
            $comparison[] = $user_data;
        }
        
        // Sort by metric
        usort( $comparison, function( $a, $b ) use ( $metric ) {
            $key = $metric === 'tokens' ? 'total_tokens' : ( $metric === 'cost' ? 'total_cost' : 'cost_per_token' );
            return $b[ $key ] <=> $a[ $key ];
        } );
        
        return $comparison;
    }
}
```

**3.2 Analytical Reports**

| Report Type | Description | Frequency |
|-------------|-------------|-----------|
| **Usage Trends** | Token consumption trends with projections | Weekly |
| **Cost Analysis** | Cost breakdown by provider/model/tool | Monthly |
| **User Benchmarks** | Comparative analysis across users | Monthly |
| **Efficiency Metrics** | Cost per token, tokens per task | Weekly |
| **Anomaly Report** | Detected unusual patterns | Daily |

**3.3 Metrics & KPIs**

- **Token Efficiency**: Tokens per task/request
- **Cost Efficiency**: Cost per task/request
- **Provider Mix**: Distribution across providers
- **Model Selection**: Most/least used models
- **Time-of-Day Patterns**: Peak usage hours
- **Tier Utilization**: % of limit consumed
- **Forecast Accuracy**: Actual vs. predicted usage

### Feature 4: Automated Scheduled Reports

#### Overview
Generate and email customizable reports on a schedule (daily, weekly, monthly).

#### Implementation Details

**4.1 Report Scheduler**

```php
/**
 * New class: WP_MCP_AI_Report_Scheduler
 * Location: includes/class-wp-mcp-ai-report-scheduler.php
 */
class WP_MCP_AI_Report_Scheduler {
    /**
     * Register cron schedules for reports.
     */
    public static function init() {
        // Daily report at 8 AM
        if ( ! wp_next_scheduled( 'wp_mcp_ai_daily_usage_report' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 08:00' ), 'daily', 'wp_mcp_ai_daily_usage_report' );
        }
        
        // Weekly report every Monday at 9 AM
        if ( ! wp_next_scheduled( 'wp_mcp_ai_weekly_usage_report' ) ) {
            $next_monday = strtotime( 'next monday 09:00' );
            wp_schedule_event( $next_monday, 'weekly', 'wp_mcp_ai_weekly_usage_report' );
        }
        
        // Monthly report on 1st of month at 10 AM
        if ( ! wp_next_scheduled( 'wp_mcp_ai_monthly_usage_report' ) ) {
            $first_of_month = strtotime( 'first day of next month 10:00' );
            wp_schedule_event( $first_of_month, 'monthly', 'wp_mcp_ai_monthly_usage_report' );
        }
        
        add_action( 'wp_mcp_ai_daily_usage_report', array( __CLASS__, 'send_daily_report' ) );
        add_action( 'wp_mcp_ai_weekly_usage_report', array( __CLASS__, 'send_weekly_report' ) );
        add_action( 'wp_mcp_ai_monthly_usage_report', array( __CLASS__, 'send_monthly_report' ) );
    }
    
    /**
     * Send daily usage report.
     */
    public static function send_daily_report() {
        $recipients = self::get_report_recipients( 'daily' );
        
        foreach ( $recipients as $recipient ) {
            $report_data = self::generate_daily_report_data( $recipient['user_id'] );
            $html = self::render_report_template( 'daily', $report_data );
            
            wp_mail(
                $recipient['email'],
                __( 'Daily AI Token Usage Report', 'wp-mcp-ai' ),
                $html,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        }
        
        WP_MCP_AI_Logger::log_event( 'daily_report_sent', 'Daily usage reports sent', array( 'count' => count( $recipients ) ) );
    }
    
    /**
     * Generate daily report data.
     *
     * @param int $user_id User ID.
     * @return array Report data.
     */
    private static function generate_daily_report_data( $user_id ) {
        $yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
        
        return array(
            'user' => get_userdata( $user_id ),
            'tier' => WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id ),
            'date' => $yesterday,
            'total_tokens' => WP_MCP_AI_Analytics_Engine::get_daily_tokens( $user_id, $yesterday ),
            'total_cost' => WP_MCP_AI_Cost_Calculator::get_daily_cost( $user_id, $yesterday ),
            'top_tools' => WP_MCP_AI_Analytics_Engine::get_top_tools( $user_id, $yesterday ),
            'limit_usage_percent' => self::calculate_limit_usage( $user_id, $yesterday ),
            'comparison_vs_avg' => self::compare_to_average( $user_id, $yesterday ),
        );
    }
    
    /**
     * Get recipients for specific report type.
     *
     * @param string $report_type Report type.
     * @return array Recipients.
     */
    private static function get_report_recipients( $report_type ) {
        $recipients = array();
        
        // Get users who opted in for this report type
        $meta_key = '_wp_mcp_ai_report_' . $report_type;
        $users = get_users( array(
            'meta_key' => $meta_key,
            'meta_value' => '1',
        ) );
        
        foreach ( $users as $user ) {
            $recipients[] = array(
                'user_id' => $user->ID,
                'email' => $user->user_email,
            );
        }
        
        return $recipients;
    }
}
```

**4.2 Report Templates**

```html
<!-- File: includes/admin/report-templates/daily-usage.php -->
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background: #0073aa; color: white; padding: 20px; }
        .content { padding: 20px; }
        .metric { background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 4px; }
        .metric-value { font-size: 24px; font-weight: bold; color: #0073aa; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php esc_html_e( 'Daily AI Token Usage Report', 'wp-mcp-ai' ); ?></h1>
        <p><?php echo esc_html( gmdate( 'F j, Y', strtotime( $data['date'] ) ) ); ?></p>
    </div>
    
    <div class="content">
        <h2><?php esc_html_e( 'Summary', 'wp-mcp-ai' ); ?></h2>
        
        <div class="metric">
            <div><?php esc_html_e( 'Total Tokens Used', 'wp-mcp-ai' ); ?></div>
            <div class="metric-value"><?php echo number_format( $data['total_tokens'] ); ?></div>
        </div>
        
        <div class="metric">
            <div><?php esc_html_e( 'Total Cost', 'wp-mcp-ai' ); ?></div>
            <div class="metric-value">$<?php echo number_format( $data['total_cost'], 2 ); ?></div>
        </div>
        
        <div class="metric">
            <div><?php esc_html_e( 'Limit Usage', 'wp-mcp-ai' ); ?></div>
            <div class="metric-value"><?php echo number_format( $data['limit_usage_percent'], 1 ); ?>%</div>
        </div>
        
        <h2><?php esc_html_e( 'Top Tools Used', 'wp-mcp-ai' ); ?></h2>
        <table>
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Tool', 'wp-mcp-ai' ); ?></th>
                    <th><?php esc_html_e( 'Tokens', 'wp-mcp-ai' ); ?></th>
                    <th><?php esc_html_e( 'Requests', 'wp-mcp-ai' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $data['top_tools'] as $tool ) : ?>
                    <tr>
                        <td><?php echo esc_html( $tool['name'] ); ?></td>
                        <td><?php echo number_format( $tool['tokens'] ); ?></td>
                        <td><?php echo number_format( $tool['requests'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
```

**4.3 Report Customization**

Allow users to configure report preferences:

```php
// User meta for report preferences
update_user_meta( $user_id, '_wp_mcp_ai_report_daily', true/false );
update_user_meta( $user_id, '_wp_mcp_ai_report_weekly', true/false );
update_user_meta( $user_id, '_wp_mcp_ai_report_monthly', true/false );
update_user_meta( $user_id, '_wp_mcp_ai_report_format', 'html' ); // 'html' or 'pdf'
update_user_meta( $user_id, '_wp_mcp_ai_report_metrics', array( 'tokens', 'cost', 'tools' ) );
```

### Feature 5: Enhanced Anomaly Detection

#### Overview
Upgrade from simple 5x threshold detection to ML-based pattern recognition.

#### Implementation Details

**5.1 Pattern-Based Anomaly Detection**

```php
/**
 * Enhanced anomaly detection methods.
 * Add to: includes/class-wp-mcp-ai-tool-token-limits.php
 */

/**
 * Detect anomalies using statistical analysis.
 *
 * @param int $user_id User ID.
 * @param string $tool_slug Tool slug.
 * @param int $current_tokens Current usage.
 * @return array|null Anomaly details or null.
 */
public static function detect_advanced_anomaly( $user_id, $tool_slug, $current_tokens ) {
    $usage_history = self::get_user_tool_hourly_usage_history( $user_id, $tool_slug, 168 ); // 7 days
    
    if ( count( $usage_history ) < 24 ) {
        return null; // Insufficient data
    }
    
    // Calculate statistics
    $mean = array_sum( $usage_history ) / count( $usage_history );
    $std_dev = self::calculate_standard_deviation( $usage_history );
    
    // Z-score: How many standard deviations from mean
    $z_score = $std_dev > 0 ? ( $current_tokens - $mean ) / $std_dev : 0;
    
    // Anomaly if z-score > 3 (99.7% confidence)
    if ( abs( $z_score ) > 3 ) {
        return array(
            'type' => $z_score > 0 ? 'spike' : 'drop',
            'severity' => self::categorize_anomaly_severity( abs( $z_score ) ),
            'z_score' => $z_score,
            'current_value' => $current_tokens,
            'expected_value' => $mean,
            'std_dev' => $std_dev,
            'confidence' => self::z_score_to_confidence( abs( $z_score ) ),
        );
    }
    
    return null;
}

/**
 * Detect unusual time-of-day patterns.
 *
 * @param int $user_id User ID.
 * @param string $tool_slug Tool slug.
 * @return array Temporal anomalies.
 */
public static function detect_temporal_anomalies( $user_id, $tool_slug ) {
    $hourly_patterns = self::get_hourly_patterns( $user_id, $tool_slug );
    $current_hour = (int) gmdate( 'H' );
    $current_usage = self::get_user_tool_hourly_usage( $user_id, $tool_slug );
    
    $expected_usage = $hourly_patterns[ $current_hour ]['avg'];
    $std_dev = $hourly_patterns[ $current_hour ]['std_dev'];
    
    if ( $std_dev > 0 && $current_usage > $expected_usage + ( 2 * $std_dev ) ) {
        return array(
            'type' => 'temporal_anomaly',
            'hour' => $current_hour,
            'expected' => $expected_usage,
            'actual' => $current_usage,
            'message' => sprintf(
                __( 'Unusual usage at %s:00. Expected ~%d tokens, got %d.', 'wp-mcp-ai' ),
                $current_hour,
                $expected_usage,
                $current_usage
            ),
        );
    }
    
    return null;
}

/**
 * Geolocation-based anomaly detection.
 *
 * @param int $user_id User ID.
 * @return array|null Geo anomaly or null.
 */
public static function detect_geolocation_anomaly( $user_id ) {
    // Get user's typical location(s) from historical data
    $typical_locations = self::get_user_typical_locations( $user_id );
    
    // Get current request location (IP-based)
    $current_location = self::get_request_location();
    
    if ( ! in_array( $current_location['country'], $typical_locations['countries'], true ) ) {
        return array(
            'type' => 'geolocation_anomaly',
            'current_country' => $current_location['country'],
            'typical_countries' => $typical_locations['countries'],
            'message' => sprintf(
                __( 'Usage from unusual location: %s', 'wp-mcp-ai' ),
                $current_location['country']
            ),
        );
    }
    
    return null;
}
```

**5.2 Anomaly Severity Classification**

| Z-Score | Severity | Action |
|---------|----------|--------|
| 3-4 | Low | Log only |
| 4-5 | Medium | Log + Email admin |
| 5-6 | High | Log + Email + Flag account |
| >6 | Critical | Log + Email + Suspend account |

**5.3 Automated Responses**

```php
add_action( 'wp_mcp_ai_advanced_anomaly_detected', function( $user_id, $anomaly ) {
    switch ( $anomaly['severity'] ) {
        case 'critical':
            // Temporarily suspend token usage
            update_user_meta( $user_id, '_wp_mcp_ai_suspended', time() );
            
            // Notify admin
            wp_mail(
                get_option( 'admin_email' ),
                __( 'Critical Token Usage Anomaly Detected', 'wp-mcp-ai' ),
                sprintf(
                    __( 'User ID %d has been suspended due to critical anomaly: %s', 'wp-mcp-ai' ),
                    $user_id,
                    $anomaly['message']
                )
            );
            break;
            
        case 'high':
            // Flag for review
            update_user_meta( $user_id, '_wp_mcp_ai_flagged_for_review', time() );
            break;
    }
}, 10, 2 );
```

### Feature 6: Tier Adjustment Recommendations

#### Overview
Auto-generate recommendations for tier upgrades/downgrades based on usage patterns.

#### Implementation Details

**6.1 Recommendation Engine**

```php
/**
 * New class: WP_MCP_AI_Tier_Recommendations
 * Location: includes/class-wp-mcp-ai-tier-recommendations.php
 */
class WP_MCP_AI_Tier_Recommendations {
    /**
     * Analyze user and recommend tier adjustment.
     *
     * @param int $user_id User ID.
     * @return array|null Recommendation or null.
     */
    public static function get_tier_recommendation( $user_id ) {
        $current_tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
        $usage_data = self::analyze_usage_patterns( $user_id );
        
        // Check for consistent high usage (upgrade candidate)
        if ( $usage_data['avg_usage_percent'] >= 80 && $usage_data['consistency'] === 'high' ) {
            $recommended_tier = self::get_next_tier( $current_tier );
            
            if ( $recommended_tier !== $current_tier ) {
                return array(
                    'action' => 'upgrade',
                    'from_tier' => $current_tier,
                    'to_tier' => $recommended_tier,
                    'reason' => __( 'Consistently using 80%+ of current limit', 'wp-mcp-ai' ),
                    'confidence' => $usage_data['confidence'],
                    'projected_savings' => self::calculate_projected_savings( $user_id, $recommended_tier ),
                );
            }
        }
        
        // Check for consistent low usage (downgrade candidate)
        if ( $usage_data['avg_usage_percent'] <= 20 && $usage_data['consistency'] === 'high' ) {
            $recommended_tier = self::get_previous_tier( $current_tier );
            
            if ( $recommended_tier !== $current_tier ) {
                return array(
                    'action' => 'downgrade',
                    'from_tier' => $current_tier,
                    'to_tier' => $recommended_tier,
                    'reason' => __( 'Consistently using <20% of current limit', 'wp-mcp-ai' ),
                    'confidence' => $usage_data['confidence'],
                    'potential_savings' => self::calculate_tier_savings( $current_tier, $recommended_tier ),
                );
            }
        }
        
        // No recommendation
        return array(
            'action' => 'maintain',
            'current_tier' => $current_tier,
            'reason' => __( 'Current tier is appropriate', 'wp-mcp-ai' ),
            'avg_usage_percent' => $usage_data['avg_usage_percent'],
        );
    }
    
    /**
     * Analyze usage patterns for recommendation.
     *
     * @param int $user_id User ID.
     * @return array Analysis results.
     */
    private static function analyze_usage_patterns( $user_id ) {
        $daily_usage = WP_MCP_AI_Analytics_Engine::get_daily_usage( $user_id, 30 );
        $tier_limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'general_tools' );
        
        $usage_percentages = array();
        
        foreach ( $daily_usage as $day => $tokens ) {
            $usage_percentages[] = ( $tokens / $tier_limit ) * 100;
        }
        
        $avg_usage_percent = array_sum( $usage_percentages ) / count( $usage_percentages );
        $std_dev = self::calculate_standard_deviation( $usage_percentages );
        
        // Consistency: high if std dev is low
        $consistency = $std_dev < 15 ? 'high' : ( $std_dev < 30 ? 'medium' : 'low' );
        
        // Confidence based on data points and consistency
        $confidence = min( 100, ( count( $usage_percentages ) / 30 ) * 100 * ( $consistency === 'high' ? 1 : 0.7 ) );
        
        return array(
            'avg_usage_percent' => $avg_usage_percent,
            'std_dev' => $std_dev,
            'consistency' => $consistency,
            'confidence' => $confidence,
            'data_points' => count( $usage_percentages ),
        );
    }
    
    /**
     * Display recommendations in admin dashboard.
     */
    public static function render_recommendations_widget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        global $wpdb;
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
             WHERE meta_key = '_wp_mcp_ai_tool_token_usage'"
        );
        
        $recommendations = array();
        
        foreach ( $user_ids as $user_id ) {
            $rec = self::get_tier_recommendation( $user_id );
            
            if ( $rec['action'] !== 'maintain' ) {
                $rec['user'] = get_userdata( $user_id );
                $recommendations[] = $rec;
            }
        }
        
        ?>
        <div class="wp-mcp-ai-tier-recommendations">
            <h3><?php esc_html_e( 'Tier Adjustment Recommendations', 'wp-mcp-ai' ); ?></h3>
            
            <?php if ( empty( $recommendations ) ) : ?>
                <p><?php esc_html_e( 'All users are on appropriate tiers.', 'wp-mcp-ai' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'User', 'wp-mcp-ai' ); ?></th>
                            <th><?php esc_html_e( 'Current Tier', 'wp-mcp-ai' ); ?></th>
                            <th><?php esc_html_e( 'Recommended Tier', 'wp-mcp-ai' ); ?></th>
                            <th><?php esc_html_e( 'Reason', 'wp-mcp-ai' ); ?></th>
                            <th><?php esc_html_e( 'Confidence', 'wp-mcp-ai' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'wp-mcp-ai' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recommendations as $rec ) : ?>
                            <tr>
                                <td><?php echo esc_html( $rec['user']->display_name ); ?></td>
                                <td><?php echo esc_html( ucfirst( $rec['from_tier'] ) ); ?></td>
                                <td><?php echo esc_html( ucfirst( $rec['to_tier'] ) ); ?></td>
                                <td><?php echo esc_html( $rec['reason'] ); ?></td>
                                <td><?php echo number_format( $rec['confidence'], 0 ); ?>%</td>
                                <td>
                                    <button class="button button-small wp-mcp-ai-apply-tier-recommendation" 
                                            data-user-id="<?php echo esc_attr( $rec['user']->ID ); ?>"
                                            data-tier="<?php echo esc_attr( $rec['to_tier'] ); ?>">
                                        <?php echo esc_html( ucfirst( $rec['action'] ) ); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
```

## Implementation Roadmap

### Timeline: 8-10 Weeks

#### Week 1-2: Chart.js Integration
- [ ] Implement dashboard widget system
- [ ] Create Chart.js visualizations
- [ ] Add interactive features
- [ ] Unit tests for data formatting

#### Week 3-4: Cost Attribution
- [ ] Implement cost calculation engine
- [ ] Add project-level attribution
- [ ] Create cost breakdown reports
- [ ] Test cost accuracy

#### Week 5-6: Advanced Analytics
- [ ] Implement trend analysis
- [ ] Add pattern recognition
- [ ] Create comparison tools
- [ ] Test analytical algorithms

#### Week 7: Automated Reporting
- [ ] Implement report scheduler
- [ ] Create report templates
- [ ] Add customization options
- [ ] Test email delivery

#### Week 8: Enhanced Anomaly Detection
- [ ] Upgrade detection algorithms
- [ ] Add geolocation tracking
- [ ] Implement automated responses
- [ ] Security testing

#### Week 9: Tier Recommendations
- [ ] Implement recommendation engine
- [ ] Add admin dashboard widget
- [ ] Create auto-apply workflow
- [ ] Accuracy testing

#### Week 10: Polish & Documentation
- [ ] Performance optimization
- [ ] Security audit
- [ ] User documentation
- [ ] Admin guide

## Database Schema Changes

### New Tables

```sql
-- Cost tracking (optional optimization)
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_mcp_ai_cost_tracking (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    model VARCHAR(100) NOT NULL,
    tool_slug VARCHAR(100),
    project_id VARCHAR(100),
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    created_at DATETIME NOT NULL,
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_provider (provider),
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anomaly detection log
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wp_mcp_ai_anomalies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    tool_slug VARCHAR(100),
    anomaly_type VARCHAR(50) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    z_score DECIMAL(6,2),
    current_value INT UNSIGNED,
    expected_value INT UNSIGNED,
    metadata TEXT,
    detected_at DATETIME NOT NULL,
    resolved_at DATETIME,
    INDEX idx_user_date (user_id, detected_at),
    INDEX idx_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### New User Meta Keys

```php
// Report preferences
'_wp_mcp_ai_report_daily' => bool
'_wp_mcp_ai_report_weekly' => bool
'_wp_mcp_ai_report_monthly' => bool
'_wp_mcp_ai_report_format' => string ('html' or 'pdf')
'_wp_mcp_ai_report_metrics' => array

// Geolocation tracking
'_wp_mcp_ai_typical_countries' => array
'_wp_mcp_ai_typical_ips' => array

// Tier recommendation tracking
'_wp_mcp_ai_last_tier_recommendation' => timestamp
'_wp_mcp_ai_tier_recommendation_dismissed' => array
```

### New Options

```php
// Chart.js CDN version
'wp_mcp_ai_chartjs_version' => '4.4.0'

// Report settings
'wp_mcp_ai_report_from_email' => 'noreply@yoursite.com'
'wp_mcp_ai_report_from_name' => 'AI Analytics'

// Anomaly thresholds
'wp_mcp_ai_anomaly_z_score_threshold' => 3.0
'wp_mcp_ai_anomaly_auto_suspend' => true
```

## REST API Endpoints (New)

### Cost Endpoints

```
GET  /wp-json/mcp-ai/v1/users/{id}/cost-breakdown?start_date={date}&end_date={date}
GET  /wp-json/mcp-ai/v1/cost/total?start_date={date}&end_date={date}
GET  /wp-json/mcp-ai/v1/cost/by-provider
GET  /wp-json/mcp-ai/v1/cost/by-project/{project_id}
```

### Analytics Endpoints

```
GET  /wp-json/mcp-ai/v1/analytics/trends/{user_id}?days={n}
GET  /wp-json/mcp-ai/v1/analytics/patterns/{user_id}
GET  /wp-json/mcp-ai/v1/analytics/compare?user_ids={id1,id2,id3}
GET  /wp-json/mcp-ai/v1/analytics/anomalies?severity={level}
```

### Recommendation Endpoints

```
GET  /wp-json/mcp-ai/v1/recommendations/tier/{user_id}
POST /wp-json/mcp-ai/v1/recommendations/apply/{user_id}
GET  /wp-json/mcp-ai/v1/recommendations/all
```

## Security Considerations

### Data Privacy
- Cost data may be sensitive - restrict access appropriately
- Implement GDPR-compliant data export/deletion
- Anonymize data in comparative reports
- Encrypt sensitive metadata in database

### Performance
- Cache chart data (1-hour TTL)
- Paginate large analytics queries
- Use transients for expensive calculations
- Index database tables properly

### Access Control
- Admins: Full access to all analytics
- Users: Only their own data
- Reports: Opt-in only, no forced emails
- API: Proper permission callbacks

## Testing Strategy

### Unit Tests

```php
// tests/test-analytics-engine.php
class Test_Analytics_Engine extends WP_UnitTestCase {
    public function test_linear_regression() {
        $data_points = array(
            array( 'x' => 1, 'y' => 2 ),
            array( 'x' => 2, 'y' => 4 ),
            array( 'x' => 3, 'y' => 6 ),
        );
        
        $result = WP_MCP_AI_Analytics_Engine::linear_regression( $data_points );
        
        $this->assertEquals( 2, $result['slope'] );
        $this->assertEquals( 0, $result['intercept'] );
        $this->assertEquals( 1.0, $result['r_squared'] );
    }
    
    public function test_anomaly_detection() {
        $usage_history = array_fill( 0, 100, 1000 ); // Normal usage
        $current_usage = 5000; // Spike
        
        $anomaly = WP_MCP_AI_Tool_Token_Limits::detect_advanced_anomaly(
            1,
            'test_tool',
            $current_usage
        );
        
        $this->assertNotNull( $anomaly );
        $this->assertEquals( 'spike', $anomaly['type'] );
        $this->assertGreaterThan( 3, $anomaly['z_score'] );
    }
}

// tests/test-cost-calculator.php
class Test_Cost_Calculator extends WP_UnitTestCase {
    public function test_cost_calculation() {
        $cost = WP_MCP_AI_Cost_Calculator::calculate_cost(
            'openai',
            'gpt-4o-mini',
            1000000, // 1M input tokens
            500000   // 500k output tokens
        );
        
        // Expected: (1M/1M * 0.15) + (500k/1M * 0.60) = 0.15 + 0.30 = $0.45
        $this->assertEquals( 0.45, $cost );
    }
}
```

### Integration Tests

```php
// tests/test-scheduled-reports.php
class Test_Scheduled_Reports extends WP_UnitTestCase {
    public function test_daily_report_generation() {
        // Create test user with usage data
        $user_id = $this->factory->user->create();
        // Add usage data...
        
        // Trigger report
        WP_MCP_AI_Report_Scheduler::send_daily_report();
        
        // Check if email was sent
        $emails = $this->get_sent_emails();
        $this->assertCount( 1, $emails );
        $this->assertStringContainsString( 'Daily AI Token Usage Report', $emails[0]['subject'] );
    }
}
```

## User Documentation

### Admin Guide Sections

1. **Viewing Analytics Dashboard**
   - How to access charts
   - Understanding metrics
   - Filtering data

2. **Cost Tracking**
   - Understanding cost calculations
   - Project attribution
   - Budget management

3. **Automated Reports**
   - Configuring report schedules
   - Customizing templates
   - Managing recipients

4. **Anomaly Alerts**
   - Understanding severity levels
   - Responding to alerts
   - Adjusting thresholds

5. **Tier Recommendations**
   - How recommendations work
   - Applying recommendations
   - Overriding suggestions

### End-User Documentation

1. **Viewing Your Usage**
   - Personal dashboard
   - Understanding charts
   - Checking costs

2. **Report Preferences**
   - Opting in/out
   - Choosing frequency
   - Selecting metrics

3. **Understanding Alerts**
   - Limit warnings
   - Cost alerts
   - Anomaly notifications

## Success Criteria

### Functional Requirements
- ✅ All charts render correctly
- ✅ Cost calculations accurate to $0.01
- ✅ Reports send on schedule
- ✅ Anomaly detection >95% accurate
- ✅ API endpoints functional

### Performance Requirements
- ✅ Dashboard loads in <2 seconds
- ✅ Chart rendering <500ms
- ✅ API response time <1 second
- ✅ Report generation <30 seconds
- ✅ No impact on front-end performance

### User Experience Requirements
- ✅ Intuitive navigation
- ✅ Mobile-responsive charts
- ✅ Clear, actionable insights
- ✅ Helpful error messages
- ✅ Comprehensive documentation

## Rollout Plan

### Phase 1: Internal Testing (Week 1)
- Deploy to staging environment
- Internal team testing
- Bug fixes and refinements

### Phase 2: Beta Testing (Week 2)
- Select 10-20 beta users
- Collect feedback
- Iterate based on input

### Phase 3: Gradual Rollout (Week 3)
- Enable for 25% of users
- Monitor performance
- Address issues

### Phase 4: Full Release (Week 4)
- Enable for all users
- Announcement and marketing
- Monitor and support

## Future Enhancements (Phase 8+)

1. **Machine Learning Forecasting**
   - TensorFlow.js integration
   - LSTM neural networks
   - Seasonal pattern recognition

2. **Multi-Currency Support**
   - Support for EUR, GBP, etc.
   - Currency conversion
   - Regional pricing

3. **Mobile App**
   - iOS/Android apps
   - Push notifications
   - On-the-go management

4. **API Integrations**
   - Slack notifications
   - Zapier integration
   - Webhook support

5. **Advanced Budgeting**
   - Department budgets
   - Project allocations
   - Spend forecasting

## Conclusion

Phase 7 represents a significant leap forward in the Token Usage Manager's capabilities, transforming it from a basic tracking system into a comprehensive analytics platform. By implementing advanced visualization, cost attribution, predictive analytics, and automated reporting, WP oOS will provide administrators and users with the insights needed to optimize AI usage and costs.

The proposed features maintain backward compatibility, follow WordPress best practices, and build logically on the foundation established in Phases 1-6. With careful implementation and thorough testing, Phase 7 will position WP oOS as the premier WordPress AI management solution.

---

**Document Status:** Planning Phase  
**Next Steps:** Stakeholder review and approval  
**Target Start Date:** TBD  
**Estimated Completion:** 10 weeks from start
