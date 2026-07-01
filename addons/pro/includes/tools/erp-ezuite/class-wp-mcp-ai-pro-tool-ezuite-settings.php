<?php
/**
 * EZuite Settings Tool.
 *
 * Enables AI assistants to read and manage EZuite Toolkit configuration
 * settings. All write operations require manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-cct-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * EZuite Settings Tool.
 *
 * Read/update EZuite toolkit settings and list available connections.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Pro_Tool_EZuite_Settings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Option key for toolkit settings.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_ezuite_toolkit_settings';

	/**
	 * Rate limit: max requests per minute per user.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const RATE_LIMIT_PER_MINUTE = 10;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ezuite_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'EZuite Settings', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read and manage EZuite Toolkit configuration settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'get', 'update' ),
					'default'     => 'get',
				),
				'settings' => array(
					'type'        => 'object',
					'description' => __( 'Settings object. Required for update action. Supported keys: sync_interval, enable_wc_sync, sync_direction, low_stock_threshold, cct_slug, field_mapping.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'sync_interval'       => array(
							'type'        => 'integer',
							'description' => __( 'Sync interval in minutes (5-1440).', 'mcp-ai-wpoos-pro' ),
							'minimum'     => 5,
							'maximum'     => 1440,
						),
						'enable_wc_sync'      => array(
							'type'        => 'boolean',
							'description' => __( 'Enable WooCommerce stock synchronization.', 'mcp-ai-wpoos-pro' ),
						),
						'sync_direction'      => array(
							'type'        => 'string',
							'description' => __( 'Sync direction.', 'mcp-ai-wpoos-pro' ),
							'enum'        => array( 'ezuite_to_woo', 'woo_to_ezuite', 'bidirectional' ),
						),
						'low_stock_threshold' => array(
							'type'        => 'integer',
							'description' => __( 'Quantity threshold for "low stock" status.', 'mcp-ai-wpoos-pro' ),
							'minimum'     => 0,
						),
						'cct_slug'            => array(
							'type'        => 'string',
							'description' => __( 'JetEngine CCT slug for inventory cache.', 'mcp-ai-wpoos-pro' ),
						),
						'field_mapping'       => array(
							'type'        => 'object',
							'description' => __( 'Custom field mapping between EZuite API fields and CCT columns.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'rate-limited',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'get';

		// Capability check.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_ezuite_forbidden',
				__( 'You do not have permission to manage EZuite settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Rate limit.
		$rate_limit_check = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		switch ( $action ) {
			case 'get':
				return $this->handle_get();

			case 'update':
				return $this->handle_update( $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_ezuite_invalid_action',
					__( 'Invalid action. Use "get" or "update".', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Handle get action — return current settings with a markdown summary.
	 *
	 * @since 1.9.0
	 * @return array
	 */
	protected function handle_get() {
		$settings    = $this->get_settings_array();
		$connections = $this->get_available_connections();

		// Build markdown table for chat display.
		$markdown  = "## EZuite Toolkit Settings\n\n";
		$markdown .= "| Setting | Value |\n";
		$markdown .= "|---------|-------|\n";
		$markdown .= sprintf( "| Sync Interval | %d min |\n", $settings['sync_interval'] );
		$markdown .= sprintf( "| WC Sync Enabled | %s |\n", $settings['enable_wc_sync'] ? __( 'Yes', 'mcp-ai-wpoos-pro' ) : __( 'No', 'mcp-ai-wpoos-pro' ) );
		$markdown .= sprintf( "| Sync Direction | %s |\n", esc_html( $settings['sync_direction'] ) );
		$markdown .= sprintf( "| Low Stock Threshold | %d |\n", $settings['low_stock_threshold'] );
		$markdown .= sprintf( "| CCT Slug | `%s` |\n", esc_html( $settings['cct_slug'] ) );

		// Field mapping as a sub-table.
		if ( ! empty( $settings['field_mapping'] ) && is_array( $settings['field_mapping'] ) ) {
			$markdown .= "\n**Field Mapping:**\n\n";
			$markdown .= "| EZuite Field | CCT Column |\n";
			$markdown .= "|-------------|------------|\n";
			foreach ( $settings['field_mapping'] as $ezuite_field => $cct_column ) {
				$markdown .= sprintf( "| %s | %s |\n", esc_html( $ezuite_field ), esc_html( $cct_column ) );
			}
		}

		// Available connections.
		if ( ! empty( $connections ) ) {
			$markdown .= "\n**Available Connections:**\n\n";
			foreach ( $connections as $conn ) {
				$markdown .= sprintf(
					"- **%s** (`%s`) — %s\n",
					esc_html( $conn['name'] ),
					esc_html( $conn['id'] ),
					! empty( $conn['enabled'] ) ? __( 'Enabled', 'mcp-ai-wpoos-pro' ) : __( 'Disabled', 'mcp-ai-wpoos-pro' )
				);
			}
		} else {
			$markdown .= "\n*No EZuite connections configured.*\n";
		}

		return array(
			'success'     => true,
			'message'     => __( 'EZuite settings retrieved.', 'mcp-ai-wpoos-pro' ),
			'data'        => $settings,
			'connections' => $connections,
			'markdown'    => $markdown,
		);
	}

	/**
	 * Handle update action — merge and sanitize provided settings, then persist.
	 *
	 * @since 1.9.0
	 *
	 * @param array $arguments Raw tool arguments (Gate 1 already applied to action).
	 * @return array|WP_Error
	 */
	protected function handle_update( $arguments ) {
		$raw_settings = isset( $arguments['settings'] ) ? $arguments['settings'] : null;

		if ( ! is_array( $raw_settings ) || empty( $raw_settings ) ) {
			return new WP_Error(
				'wp_mcp_ai_ezuite_missing_settings',
				__( 'The "settings" parameter is required for the update action. Provide at least one setting key to update.', 'mcp-ai-wpoos-pro' )
			);
		}

		$current = get_option( self::OPTION_KEY, array() );
		$updates = array();

		// Sanitize each known key.
		if ( isset( $raw_settings['sync_interval'] ) ) {
			$interval                 = absint( $raw_settings['sync_interval'] );
			$updates['sync_interval'] = max( 5, min( $interval, 1440 ) );
		}

		if ( array_key_exists( 'enable_wc_sync', $raw_settings ) ) {
			$updates['enable_wc_sync'] = ! empty( $raw_settings['enable_wc_sync'] ) ? 'yes' : 'no';
		}

		if ( isset( $raw_settings['sync_direction'] ) ) {
			$direction                 = sanitize_key( $raw_settings['sync_direction'] );
			$allowed                   = array( 'ezuite_to_woo', 'woo_to_ezuite', 'bidirectional' );
			$updates['sync_direction'] = in_array( $direction, $allowed, true ) ? $direction : 'ezuite_to_woo';
		}

		if ( isset( $raw_settings['low_stock_threshold'] ) ) {
			$updates['low_stock_threshold'] = absint( $raw_settings['low_stock_threshold'] );
		}

		if ( isset( $raw_settings['cct_slug'] ) ) {
			$updates['cct_slug'] = sanitize_key( $raw_settings['cct_slug'] );
		}

		if ( isset( $raw_settings['field_mapping'] ) && is_array( $raw_settings['field_mapping'] ) ) {
			$mapping = array();
			foreach ( $raw_settings['field_mapping'] as $key => $value ) {
				$mapping[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
			}
			$updates['field_mapping'] = $mapping;
		}

		if ( empty( $updates ) ) {
			return new WP_Error(
				'wp_mcp_ai_ezuite_no_valid_keys',
				__( 'No valid setting keys were found in the provided settings object. Supported keys: sync_interval, enable_wc_sync, sync_direction, low_stock_threshold, cct_slug, field_mapping.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Persist.
		$merged = array_merge( $current, $updates );
		update_option( self::OPTION_KEY, $merged );

		// Return the full updated config.
		$updated_settings = $this->get_settings_array();

		// Build markdown confirmation.
		$markdown  = "## EZuite Settings Updated\n\n";
		$markdown .= "The following settings were changed:\n\n";
		$markdown .= "| Setting | New Value |\n";
		$markdown .= "|---------|----------|\n";

		foreach ( $updates as $key => $value ) {
			$display_value = is_array( $value )
				? wp_json_encode( $value )
				: ( is_bool( $value ) ? ( $value ? __( 'Yes', 'mcp-ai-wpoos-pro' ) : __( 'No', 'mcp-ai-wpoos-pro' ) ) : (string) $value );
			$markdown     .= sprintf( "| %s | %s |\n", esc_html( $key ), esc_html( $display_value ) );
		}

		return array(
			'success'  => true,
			'message'  => __( 'EZuite settings updated.', 'mcp-ai-wpoos-pro' ),
			'data'     => $updated_settings,
			'markdown' => $markdown,
		);
	}

	/**
	 * Get the current settings array with all keys present.
	 *
	 * @since 1.9.0
	 * @return array
	 */
	protected function get_settings_array() {
		$settings = get_option( self::OPTION_KEY, array() );

		return array(
			'sync_interval'       => absint( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 15 ),
			'enable_wc_sync'      => ! empty( $settings['enable_wc_sync'] ) && 'no' !== $settings['enable_wc_sync'],
			'sync_direction'      => esc_html( isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'ezuite_to_woo' ),
			'low_stock_threshold' => absint( isset( $settings['low_stock_threshold'] ) ? $settings['low_stock_threshold'] : 5 ),
			'cct_slug'            => esc_html( isset( $settings['cct_slug'] ) ? $settings['cct_slug'] : WP_MCP_AI_EZuite_CCT_Manager::CCT_SLUG_DEFAULT ),
			'field_mapping'       => isset( $settings['field_mapping'] ) && is_array( $settings['field_mapping'] )
				? $settings['field_mapping']
				: array(),
		);
	}

	/**
	 * Get available EZuite ERP connections from Remote Sites.
	 *
	 * @since 1.9.0
	 * @return array
	 */
	protected function get_available_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array();
		}

		$all_connections    = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$ezuite_connections = array();

		foreach ( $all_connections as $connection ) {
			if ( ! empty( $connection['connection_type'] ) && 'ezuite_erp' === $connection['connection_type'] ) {
				$ezuite_connections[] = array(
					'id'      => esc_html( isset( $connection['id'] ) ? $connection['id'] : '' ),
					'name'    => esc_html( isset( $connection['name'] ) ? $connection['name'] : '' ),
					'enabled' => ! empty( $connection['enabled'] ),
					'url'     => esc_url( isset( $connection['url'] ) ? $connection['url'] : '' ),
				);
			}
		}

		return $ezuite_connections;
	}

	/**
	 * Check rate limit for settings operations.
	 *
	 * @since 1.9.0
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$user_id        = absint( $user_id );
		$transient_key  = 'wp_mcp_ai_pro_ezuite_settings_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = self::RATE_LIMIT_PER_MINUTE;

		/**
		 * Filter the maximum EZuite settings operations allowed per minute per user.
		 *
		 * @since 1.9.0
		 *
		 * @param int $max_per_minute Maximum requests per minute (default: 10).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_pro_ezuite_settings_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_pro_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum requests allowed per minute */
					__( 'EZuite settings rate limit exceeded. Maximum %d requests per minute allowed.', 'mcp-ai-wpoos-pro' ),
					$max_per_minute
				)
			);
		}

		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
