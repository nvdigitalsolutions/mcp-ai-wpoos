#!/usr/bin/env php
<?php
/**
 * Migrate existing plugin settings to Remote Site Connections.
 *
 * This script detects existing API credentials stored in plugin settings
 * and automatically creates corresponding remote site connections.
 *
 * Usage:
 *   php bin/migrate-settings-to-connections.php [--dry-run] [--verbose]
 *
 * Options:
 *   --dry-run   Show what would be migrated without making changes
 *   --verbose   Show detailed output during migration
 *
 * @package WP_MCP_AI_Pro
 */

// Find WordPress installation
$wp_root = dirname( dirname( __FILE__ ) );
$wp_load_paths = array(
	$wp_root . '/../../wp-load.php',
	$wp_root . '/../../../wp-load.php',
	$wp_root . '/../../../../wp-load.php',
);

$wp_load = null;
foreach ( $wp_load_paths as $path ) {
	if ( file_exists( $path ) ) {
		$wp_load = $path;
		break;
	}
}

if ( ! $wp_load ) {
	echo "Error: Could not find WordPress installation.\n";
	echo "This script must be run from the plugin directory within a WordPress installation.\n";
	exit( 1 );
}

// Load WordPress
require_once $wp_load;

// Check if we're running in CLI
if ( 'cli' !== php_sapi_name() ) {
	echo "Error: This script must be run from the command line.\n";
	exit( 1 );
}

// Check if user is allowed to run this
if ( ! current_user_can( 'manage_options' ) ) {
	// In CLI context, check if running as admin
	if ( ! defined( 'WP_CLI' ) ) {
		wp_set_current_user( 1 ); // Set as first admin user for CLI context
	}
}

// Parse arguments
$dry_run = in_array( '--dry-run', $argv, true );
$verbose = in_array( '--verbose', $argv, true ) || $dry_run;

// Output functions
function log_info( $message, $force_verbose = false ) {
	global $verbose;
	if ( $force_verbose || $verbose ) {
		echo "[INFO] $message\n";
	}
}

function log_success( $message ) {
	echo "[SUCCESS] $message\n";
}

function log_warning( $message ) {
	echo "[WARNING] $message\n";
}

function log_error( $message ) {
	echo "[ERROR] $message\n";
}

// Ensure required classes are loaded
if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
	$manager_path = dirname( dirname( __FILE__ ) ) . '/addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php';
	if ( file_exists( $manager_path ) ) {
		require_once $manager_path;
	} else {
		log_error( 'WP_MCP_AI_Pro_Remote_Site_Manager class not found. Is the Pro addon active?' );
		exit( 1 );
	}
}

// Start migration
echo "\n";
echo "========================================\n";
echo "Settings to Connections Migration Tool\n";
echo "========================================\n";
echo "\n";

if ( $dry_run ) {
	echo "Running in DRY-RUN mode - no changes will be made.\n\n";
}

// Get current settings
$settings = get_option( 'wp_mcp_ai_settings', array() );
$migrated_count = 0;
$skipped_count = 0;

// Define migration configurations
$migrations = array(
	'isams'       => array(
		'name'      => 'iSAMS School Management',
		'url_key'   => 'isams_api_url',
		'type'      => 'isams',
		'auth_type' => 'none',
		'fields'    => array(
			'api_key'    => 'isams_api_key',
			'api_secret' => 'isams_api_secret',
		),
		'required'  => array( 'isams_api_url', 'isams_api_key', 'isams_api_secret' ),
	),
	'flowhub'     => array(
		'name'      => 'Flowhub POS',
		'url_key'   => 'flowhub_api_url',
		'type'      => 'flowhub',
		'auth_type' => 'none',
		'fields'    => array(
			'api_key'       => 'flowhub_api_key',
			'client_id'     => 'flowhub_client_id',
			'client_secret' => 'flowhub_client_secret',
			'location_id'   => 'flowhub_location_id',
		),
		'required'  => array( 'flowhub_api_key', 'flowhub_client_id', 'flowhub_client_secret', 'flowhub_location_id' ),
		'default_url' => 'https://api.flowhub.com',
	),
	'payhere'     => array(
		'name'      => 'PayHere Payment Gateway',
		'url_key'   => 'payhere_api_url',
		'type'      => 'payhere',
		'auth_type' => 'none',
		'fields'    => array(
			'app_id'     => 'payhere_app_id',
			'app_secret' => 'payhere_app_secret',
		),
		'boolean'   => array(
			'sandbox_mode' => 'payhere_sandbox_mode',
		),
		'required'  => array( 'payhere_app_id', 'payhere_app_secret' ),
		'default_url' => 'https://www.payhere.lk',
	),
	'quickbooks'  => array(
		'name'      => 'QuickBooks Accounting',
		'url_key'   => 'quickbooks_api_url',
		'type'      => 'quickbooks',
		'auth_type' => 'none',
		'fields'    => array(
			'client_id'     => 'quickbooks_client_id',
			'client_secret' => 'quickbooks_client_secret',
			'company_id'    => 'quickbooks_company_id',
		),
		'required'  => array( 'quickbooks_client_id', 'quickbooks_client_secret' ),
		'default_url' => 'https://quickbooks.api.intuit.com',
	),
);

// Check each service for existing credentials
foreach ( $migrations as $service_key => $config ) {
	log_info( "Checking for {$config['name']} credentials...", true );
	
	// Check if all required settings exist
	$has_credentials = true;
	foreach ( $config['required'] as $required_key ) {
		if ( empty( $settings[ $required_key ] ) ) {
			$has_credentials = false;
			break;
		}
	}
	
	if ( ! $has_credentials ) {
		log_info( "  No credentials found for {$config['name']}, skipping.", true );
		$skipped_count++;
		continue;
	}
	
	// Build connection data
	$connection_data = array(
		'name'            => $config['name'],
		'connection_type' => $config['type'],
		'auth_type'       => $config['auth_type'],
		'enabled'         => true,
		'cache_ttl'       => 300,
	);
	
	// Get URL
	if ( isset( $config['url_key'] ) && ! empty( $settings[ $config['url_key'] ] ) ) {
		$connection_data['url'] = $settings[ $config['url_key'] ];
	} elseif ( isset( $config['default_url'] ) ) {
		$connection_data['url'] = $config['default_url'];
	} else {
		log_warning( "  No URL found for {$config['name']}, skipping." );
		$skipped_count++;
		continue;
	}
	
	// Copy field values
	if ( isset( $config['fields'] ) ) {
		foreach ( $config['fields'] as $connection_field => $setting_key ) {
			if ( ! empty( $settings[ $setting_key ] ) ) {
				$connection_data[ $connection_field ] = $settings[ $setting_key ];
			}
		}
	}
	
	// Copy boolean fields
	if ( isset( $config['boolean'] ) ) {
		foreach ( $config['boolean'] as $connection_field => $setting_key ) {
			$connection_data[ $connection_field ] = ! empty( $settings[ $setting_key ] );
		}
	}
	
	// Check if connection already exists
	$existing_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
	$connection_exists = false;
	foreach ( $existing_connections as $existing ) {
		if ( isset( $existing['connection_type'] ) && 
		     $existing['connection_type'] === $config['type'] &&
		     isset( $existing['name'] ) &&
		     $existing['name'] === $config['name'] ) {
			$connection_exists = true;
			break;
		}
	}
	
	if ( $connection_exists ) {
		log_info( "  Connection already exists for {$config['name']}, skipping." );
		$skipped_count++;
		continue;
	}
	
	// Display what will be migrated
	if ( $verbose ) {
		echo "  Found credentials:\n";
		echo "    - URL: {$connection_data['url']}\n";
		echo "    - Type: {$connection_data['connection_type']}\n";
		foreach ( $config['fields'] as $field => $setting_key ) {
			$has_value = ! empty( $connection_data[ $field ] );
			echo "    - " . ucwords( str_replace( '_', ' ', $field ) ) . ": " . ( $has_value ? '[SET]' : '[EMPTY]' ) . "\n";
		}
	}
	
	if ( $dry_run ) {
		log_success( "  Would create connection for {$config['name']}" );
		$migrated_count++;
	} else {
		// Create the connection
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		
		if ( is_wp_error( $result ) ) {
			log_error( "  Failed to create connection: " . $result->get_error_message() );
		} else {
			log_success( "  Created connection for {$config['name']} with ID: {$result}" );
			$migrated_count++;
		}
	}
}

// Summary
echo "\n";
echo "========================================\n";
echo "Migration Summary\n";
echo "========================================\n";
echo "Migrated: $migrated_count connection(s)\n";
echo "Skipped:  $skipped_count service(s)\n";
echo "\n";

if ( $dry_run ) {
	echo "This was a DRY-RUN. Run without --dry-run to perform the migration.\n\n";
} elseif ( $migrated_count > 0 ) {
	echo "Migration completed successfully!\n";
	echo "Visit Settings > Remote Sites to view and manage your connections.\n\n";
	echo "Note: Original settings have NOT been removed. You can manually remove them\n";
	echo "from the plugin settings after verifying the connections work correctly.\n\n";
} else {
	echo "No connections were migrated.\n\n";
}

exit( 0 );
