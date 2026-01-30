<?php
/**
 * NV oOS Plugin Activation Tracking Endpoint - WPCode Snippet
 *
 * SETUP INSTRUCTIONS:
 * 1. Copy this entire code
 * 2. Go to WordPress Admin → Code Snippets → + Add Snippet
 * 3. Choose "Create Custom Snippet"
 * 4. Paste this code
 * 5. Set Code Type: PHP Snippet
 * 6. Set Location: Site Wide Footer
 * 7. Set Status: Active
 * 8. Click Update
 *
 * The endpoint will be available at:
 * https://nvdigitalsolutions.com/wp-json/api/plugin-tracking/activation
 *
 * @package NV_oOS
 * @version 1.0.0
 */

// Register REST API endpoint.
add_action(
'rest_api_init',
function () {
register_rest_route(
'api/plugin-tracking',
'/activation',
array(
'methods'             => 'POST',
'callback'            => 'nvd_handle_plugin_activation',
'permission_callback' => '__return_true',
)
);
}
);

/**
 * Handle plugin activation tracking.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response object or error.
 */
function nvd_handle_plugin_activation( $request ) {
$data = $request->get_json_params();

// Validate required fields.
$required = array( 'plugin_variant', 'plugin_version', 'wordpress_version', 'php_version', 'site_hash', 'timestamp' );
foreach ( $required as $field ) {
if ( empty( $data[ $field ] ) ) {
return new WP_Error( 'missing_field', "Missing: $field", array( 'status' => 400 ) );
}
}

// Validate variant.
if ( ! in_array( $data['plugin_variant'], array( 'complete', 'base', 'pro', 'core' ), true ) ) {
return new WP_Error( 'invalid_variant', 'Invalid variant', array( 'status' => 400 ) );
}

// Sanitize data.
$tracking_data = array(
'plugin_variant'    => sanitize_text_field( $data['plugin_variant'] ),
'plugin_version'    => sanitize_text_field( $data['plugin_version'] ),
'wordpress_version' => sanitize_text_field( $data['wordpress_version'] ),
'php_version'       => sanitize_text_field( $data['php_version'] ),
'locale'            => isset( $data['locale'] ) ? sanitize_text_field( $data['locale'] ) : 'unknown',
'multisite'         => isset( $data['multisite'] ) ? (bool) $data['multisite'] : false,
'site_hash'         => sanitize_text_field( $data['site_hash'] ),
'event'             => isset( $data['event'] ) ? sanitize_text_field( $data['event'] ) : 'activation',
'timestamp'         => absint( $data['timestamp'] ),
'pro_version'       => isset( $data['pro_version'] ) ? sanitize_text_field( $data['pro_version'] ) : null,
'core_version'      => isset( $data['core_version'] ) ? sanitize_text_field( $data['core_version'] ) : null,
'received_at'       => current_time( 'mysql' ),
);

// Store in database.
global $wpdb;
$table_name = $wpdb->prefix . 'nvoos_plugin_tracking';

// Create table if needed.
if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
nvd_create_tracking_table();
}

// Check if site exists.
$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->prepare(
"SELECT id FROM $table_name WHERE site_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$tracking_data['site_hash']
)
);

if ( $existing ) {
// Update existing.
$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$table_name,
$tracking_data,
array( 'site_hash' => $tracking_data['site_hash'] ),
array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ),
array( '%s' )
);
} else {
// Insert new.
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$table_name,
$tracking_data,
array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
);
}

return new WP_REST_Response( array( 'success' => true ), 200 );
}

/**
 * Create tracking table.
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

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );
}

/**
 * OPTIONAL: Add dashboard widget to view stats.
 * Uncomment the code below to enable the dashboard widget.
 */

/*
add_action(
'wp_dashboard_setup',
function () {
if ( ! current_user_can( 'manage_options' ) ) {
return;
}
wp_add_dashboard_widget( 'nvd_plugin_tracking', 'NV oOS Tracking Stats', 'nvd_dashboard_widget' );
}
);

/**
 * Display dashboard widget with tracking stats.
 *
 * @return void
 *\/
function nvd_dashboard_widget() {
global $wpdb;
$table    = $wpdb->prefix . 'nvoos_plugin_tracking';
$total    = $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$variants = $wpdb->get_results( "SELECT plugin_variant, COUNT(*) as count FROM $table GROUP BY plugin_variant" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

echo '<h3>Total Installations: ' . esc_html( number_format( $total ) ) . '</h3>';
echo '<h4>By Variant:</h4><ul>';
foreach ( $variants as $v ) {
$pct = $total > 0 ? round( ( $v->count / $total ) * 100, 1 ) : 0;
echo '<li><strong>' . esc_html( ucfirst( $v->plugin_variant ) ) . ':</strong> ' .
esc_html( number_format( $v->count ) ) . ' (' . esc_html( $pct ) . '%)</li>';
}
echo '</ul>';
}
*/
