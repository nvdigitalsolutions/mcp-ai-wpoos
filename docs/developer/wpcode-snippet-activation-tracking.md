# WPCode Snippet for Plugin Activation Tracking

This document provides a ready-to-use WPCode snippet for nvdigitalsolutions.com to receive and process plugin activation tracking data from NV oOS installations.

## Quick Setup Instructions

1. Log in to nvdigitalsolutions.com WordPress admin
2. Navigate to **Code Snippets → + Add Snippet**
3. Choose **Create Custom Snippet (New Snippet)**
4. Copy the code below into the snippet editor
5. Set **Code Type**: PHP Snippet
6. Set **Location**: Site Wide Footer (or Everywhere)
7. Set **Status**: Active
8. Click **Update** to save

---

## WPCode Snippet: Plugin Activation Tracking Endpoint

```php
<?php
/**
 * NV oOS Plugin Activation Tracking Endpoint
 * 
 * Receives and processes anonymous plugin activation/deactivation data
 * from NV oOS plugin installations.
 * 
 * Endpoint: /api/plugin-tracking/activation
 * Method: POST
 * Content-Type: application/json
 * 
 * @package NVDigital
 * @version 1.0.0
 */

// Register REST API endpoint for plugin activation tracking
add_action( 'rest_api_init', function() {
    register_rest_route( 'api/plugin-tracking', '/activation', array(
        'methods'             => 'POST',
        'callback'            => 'nvd_handle_plugin_activation',
        'permission_callback' => '__return_true', // Public endpoint
    ) );
} );

/**
 * Handle plugin activation tracking data.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response object.
 */
function nvd_handle_plugin_activation( $request ) {
    // Get JSON body
    $data = $request->get_json_params();
    
    // Validate required fields
    $required_fields = array(
        'plugin_variant',
        'plugin_version',
        'wordpress_version',
        'php_version',
        'site_hash',
        'timestamp'
    );
    
    foreach ( $required_fields as $field ) {
        if ( empty( $data[ $field ] ) ) {
            return new WP_Error(
                'missing_field',
                sprintf( 'Missing required field: %s', $field ),
                array( 'status' => 400 )
            );
        }
    }
    
    // Validate plugin variant
    $valid_variants = array( 'complete', 'base', 'pro', 'core' );
    if ( ! in_array( $data['plugin_variant'], $valid_variants, true ) ) {
        return new WP_Error(
            'invalid_variant',
            'Invalid plugin variant',
            array( 'status' => 400 )
        );
    }
    
    // Sanitize data
    $tracking_data = array(
        'plugin_variant'     => sanitize_text_field( $data['plugin_variant'] ),
        'plugin_version'     => sanitize_text_field( $data['plugin_version'] ),
        'wordpress_version'  => sanitize_text_field( $data['wordpress_version'] ),
        'php_version'        => sanitize_text_field( $data['php_version'] ),
        'locale'             => isset( $data['locale'] ) ? sanitize_text_field( $data['locale'] ) : 'unknown',
        'multisite'          => isset( $data['multisite'] ) ? (bool) $data['multisite'] : false,
        'site_hash'          => sanitize_text_field( $data['site_hash'] ),
        'event'              => isset( $data['event'] ) ? sanitize_text_field( $data['event'] ) : 'activation',
        'timestamp'          => absint( $data['timestamp'] ),
        'pro_version'        => isset( $data['pro_version'] ) ? sanitize_text_field( $data['pro_version'] ) : null,
        'core_version'       => isset( $data['core_version'] ) ? sanitize_text_field( $data['core_version'] ) : null,
        'received_at'        => current_time( 'mysql' ),
    );
    
    // Store in database
    global $wpdb;
    $table_name = $wpdb->prefix . 'nvoos_plugin_tracking';
    
    // Check if table exists, if not create it
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
        nvd_create_tracking_table();
    }
    
    // Insert or update based on site_hash
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table_name WHERE site_hash = %s",
        $tracking_data['site_hash']
    ) );
    
    if ( $existing ) {
        // Update existing record
        $wpdb->update(
            $table_name,
            $tracking_data,
            array( 'site_hash' => $tracking_data['site_hash'] ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ),
            array( '%s' )
        );
    } else {
        // Insert new record
        $wpdb->insert(
            $table_name,
            $tracking_data,
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
        );
    }
    
    // Log to file for backup (optional)
    $log_enabled = get_option( 'nvd_tracking_log_enabled', false );
    if ( $log_enabled ) {
        nvd_log_tracking_data( $tracking_data );
    }
    
    // Send to Google Analytics 4 (optional)
    $ga4_enabled = get_option( 'nvd_tracking_ga4_enabled', false );
    if ( $ga4_enabled ) {
        nvd_send_to_ga4( $tracking_data );
    }
    
    // Return success response
    return new WP_REST_Response(
        array(
            'success' => true,
            'message' => 'Tracking data received',
        ),
        200
    );
}

/**
 * Create the tracking database table.
 */
function nvd_create_tracking_table() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'nvoos_plugin_tracking';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        plugin_variant varchar(20) NOT NULL,
        plugin_version varchar(20) DEFAULT NULL,
        wordpress_version varchar(20) DEFAULT NULL,
        php_version varchar(20) DEFAULT NULL,
        locale varchar(10) DEFAULT NULL,
        multisite tinyint(1) DEFAULT 0,
        site_hash varchar(64) NOT NULL,
        event varchar(20) DEFAULT 'activation',
        timestamp int(11) DEFAULT NULL,
        pro_version varchar(20) DEFAULT NULL,
        core_version varchar(20) DEFAULT NULL,
        received_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY site_hash (site_hash),
        KEY plugin_variant (plugin_variant),
        KEY event (event),
        KEY timestamp (timestamp)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * Log tracking data to file (optional backup).
 *
 * @param array $data Tracking data.
 */
function nvd_log_tracking_data( $data ) {
    $log_dir = WP_CONTENT_DIR . '/uploads/plugin-tracking-logs';
    
    // Create directory if it doesn't exist
    if ( ! file_exists( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
        
        // Add .htaccess to protect logs
        $htaccess = "$log_dir/.htaccess";
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }
    }
    
    // Log file with daily rotation
    $log_file = $log_dir . '/tracking-' . date( 'Y-m-d' ) . '.log';
    $log_entry = sprintf(
        "[%s] %s | Variant: %s | Version: %s | WP: %s | PHP: %s | Site: %s\n",
        current_time( 'mysql' ),
        $data['event'],
        $data['plugin_variant'],
        $data['plugin_version'],
        $data['wordpress_version'],
        $data['php_version'],
        substr( $data['site_hash'], 0, 12 ) . '...'
    );
    
    file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
}

/**
 * Send tracking data to Google Analytics 4 (optional).
 *
 * @param array $data Tracking data.
 */
function nvd_send_to_ga4( $data ) {
    $measurement_id = get_option( 'nvd_tracking_ga4_measurement_id', '' );
    $api_secret     = get_option( 'nvd_tracking_ga4_api_secret', '' );
    
    if ( empty( $measurement_id ) || empty( $api_secret ) ) {
        return;
    }
    
    $endpoint = 'https://www.google-analytics.com/mp/collect';
    $url      = add_query_arg( array(
        'measurement_id' => $measurement_id,
        'api_secret'     => $api_secret,
    ), $endpoint );
    
    $payload = array(
        'client_id' => $data['site_hash'],
        'events'    => array(
            array(
                'name'   => 'plugin_' . $data['event'],
                'params' => array(
                    'plugin_variant'     => $data['plugin_variant'],
                    'plugin_version'     => $data['plugin_version'],
                    'wordpress_version'  => $data['wordpress_version'],
                    'php_version'        => $data['php_version'],
                    'locale'             => $data['locale'],
                    'multisite'          => $data['multisite'],
                ),
            ),
        ),
    );
    
    wp_remote_post( $url, array(
        'body'    => wp_json_encode( $payload ),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'timeout' => 5,
        'blocking' => false, // Non-blocking
    ) );
}
```

---

## Post-Installation Configuration

### Enable Logging (Optional)

Add these options in WordPress admin or via WP-CLI:

```php
// Enable file logging
update_option( 'nvd_tracking_log_enabled', true );
```

### Enable Google Analytics 4 Integration (Optional)

```php
// Set GA4 credentials
update_option( 'nvd_tracking_ga4_enabled', true );
update_option( 'nvd_tracking_ga4_measurement_id', 'G-XXXXXXXXX' );
update_option( 'nvd_tracking_ga4_api_secret', 'your-api-secret-here' );
```

---

## Testing the Endpoint

Test the endpoint with curl:

```bash
curl -X POST https://nvdigitalsolutions.com/wp-json/api/plugin-tracking/activation \
  -H "Content-Type: application/json" \
  -d '{
    "plugin_variant": "complete",
    "plugin_version": "1.1.0",
    "wordpress_version": "6.7",
    "php_version": "8.1",
    "locale": "en_US",
    "multisite": false,
    "site_hash": "test123abc",
    "timestamp": 1738108800
  }'
```

Expected response:
```json
{
  "success": true,
  "message": "Tracking data received"
}
```

---

## Database Queries for Analytics

### Total Activations by Variant

```sql
SELECT 
    plugin_variant,
    COUNT(*) as total_sites,
    COUNT(CASE WHEN event = 'activation' THEN 1 END) as activations,
    COUNT(CASE WHEN event = 'deactivation' THEN 1 END) as deactivations
FROM wp_nvoos_plugin_tracking
GROUP BY plugin_variant
ORDER BY total_sites DESC;
```

### Version Distribution

```sql
SELECT 
    plugin_version,
    COUNT(*) as sites,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM wp_nvoos_plugin_tracking), 2) as percentage
FROM wp_nvoos_plugin_tracking
GROUP BY plugin_version
ORDER BY sites DESC;
```

### WordPress Version Distribution

```sql
SELECT 
    wordpress_version,
    COUNT(*) as sites,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM wp_nvoos_plugin_tracking), 2) as percentage
FROM wp_nvoos_plugin_tracking
GROUP BY wordpress_version
ORDER BY sites DESC;
```

### PHP Version Distribution

```sql
SELECT 
    php_version,
    COUNT(*) as sites,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM wp_nvoos_plugin_tracking), 2) as percentage
FROM wp_nvoos_plugin_tracking
GROUP BY php_version
ORDER BY sites DESC;
```

### Active Sites (Last 30 Days)

```sql
SELECT 
    plugin_variant,
    COUNT(*) as active_sites
FROM wp_nvoos_plugin_tracking
WHERE timestamp >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
AND event = 'activation'
GROUP BY plugin_variant;
```

### Geographic Distribution (by Locale)

```sql
SELECT 
    locale,
    COUNT(*) as sites,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM wp_nvoos_plugin_tracking), 2) as percentage
FROM wp_nvoos_plugin_tracking
GROUP BY locale
ORDER BY sites DESC
LIMIT 10;
```

---

## WordPress Dashboard Widget (Optional Enhancement)

Add this to display tracking stats in WordPress admin:

```php
// Add dashboard widget
add_action( 'wp_dashboard_setup', 'nvd_tracking_dashboard_widget' );

function nvd_tracking_dashboard_widget() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    wp_add_dashboard_widget(
        'nvd_plugin_tracking',
        'NV oOS Plugin Tracking Stats',
        'nvd_tracking_dashboard_widget_content'
    );
}

function nvd_tracking_dashboard_widget_content() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nvoos_plugin_tracking';
    
    // Get total installations
    $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
    
    // Get by variant
    $variants = $wpdb->get_results( "
        SELECT plugin_variant, COUNT(*) as count
        FROM $table_name
        GROUP BY plugin_variant
        ORDER BY count DESC
    " );
    
    // Get latest version
    $latest = $wpdb->get_var( "
        SELECT plugin_version
        FROM $table_name
        ORDER BY timestamp DESC
        LIMIT 1
    " );
    
    ?>
    <div class="nvd-tracking-stats">
        <h3>Total Installations: <?php echo number_format( $total ); ?></h3>
        
        <h4>By Variant:</h4>
        <ul>
            <?php foreach ( $variants as $variant ) : ?>
                <li>
                    <strong><?php echo esc_html( ucfirst( $variant->plugin_variant ) ); ?>:</strong>
                    <?php echo number_format( $variant->count ); ?>
                    (<?php echo round( ( $variant->count / $total ) * 100, 1 ); ?>%)
                </li>
            <?php endforeach; ?>
        </ul>
        
        <p><strong>Latest Version Seen:</strong> <?php echo esc_html( $latest ); ?></p>
        
        <p>
            <a href="<?php echo admin_url( 'admin.php?page=nvd-plugin-tracking-reports' ); ?>">
                View Full Reports →
            </a>
        </p>
    </div>
    <style>
        .nvd-tracking-stats { padding: 10px; }
        .nvd-tracking-stats h3 { margin-top: 0; color: #2271b1; }
        .nvd-tracking-stats ul { list-style: none; padding: 0; }
        .nvd-tracking-stats li { padding: 5px 0; border-bottom: 1px solid #f0f0f0; }
    </style>
    <?php
}
```

---

## Security Considerations

1. **Rate Limiting**: Consider adding rate limiting to prevent abuse
2. **Data Validation**: All inputs are sanitized and validated
3. **Log Protection**: Log files are protected with .htaccess
4. **Database**: Uses prepared statements to prevent SQL injection
5. **HTTPS**: Ensure site uses HTTPS (already configured for nvdigitalsolutions.com)

---

## Maintenance

### Clean Old Log Files (Cron Job)

```php
// Clean logs older than 90 days
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'nvd_cleanup_tracking_logs' ) ) {
        wp_schedule_event( time(), 'daily', 'nvd_cleanup_tracking_logs' );
    }
} );

add_action( 'nvd_cleanup_tracking_logs', function() {
    $log_dir = WP_CONTENT_DIR . '/uploads/plugin-tracking-logs';
    
    if ( ! is_dir( $log_dir ) ) {
        return;
    }
    
    $files = glob( $log_dir . '/tracking-*.log' );
    $cutoff = strtotime( '-90 days' );
    
    foreach ( $files as $file ) {
        if ( filemtime( $file ) < $cutoff ) {
            unlink( $file );
        }
    }
} );
```

---

## Troubleshooting

### Endpoint Not Working

1. Check if REST API is enabled: Visit `https://nvdigitalsolutions.com/wp-json/`
2. Check permalink settings: Ensure permalinks are set to "Post name" or custom
3. Check .htaccess: Ensure WordPress rewrite rules are present
4. Check server logs for PHP errors

### Database Table Not Created

Run manually via WP-CLI or phpMyAdmin:

```sql
CREATE TABLE wp_nvoos_plugin_tracking (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    plugin_variant varchar(20) NOT NULL,
    plugin_version varchar(20) DEFAULT NULL,
    wordpress_version varchar(20) DEFAULT NULL,
    php_version varchar(20) DEFAULT NULL,
    locale varchar(10) DEFAULT NULL,
    multisite tinyint(1) DEFAULT 0,
    site_hash varchar(64) NOT NULL,
    event varchar(20) DEFAULT 'activation',
    timestamp int(11) DEFAULT NULL,
    pro_version varchar(20) DEFAULT NULL,
    core_version varchar(20) DEFAULT NULL,
    received_at datetime DEFAULT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY site_hash (site_hash),
    KEY plugin_variant (plugin_variant),
    KEY event (event),
    KEY timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Support

For questions or issues with this tracking endpoint:
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `docs/EXTERNAL_SERVICES.md` in the plugin repository

---

**Version:** 1.0.0  
**Last Updated:** January 28, 2026  
**Compatible With:** WordPress 6.0+, WPCode 2.0+
