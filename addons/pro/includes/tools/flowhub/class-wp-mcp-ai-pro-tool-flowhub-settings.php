<?php
/**
 * FlowHub Settings Tool.
 *
 * Enables AI assistants to read and update FlowHub toolkit configuration,
 * test the API connection, and manage field mappings. All write operations
 * require manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/trait-wp-mcp-ai-flowhub-connection-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';

/**
 * FlowHub Settings Tool.
 *
 * Read/update FlowHub toolkit settings and test API connectivity.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Pro_Tool_FlowHub_Settings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_FlowHub_Connection_Resolver;

	/**
	 * Option key for toolkit settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_flowhub_toolkit_settings';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'FlowHub Settings', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'View and update FlowHub toolkit settings. Test the API connection and manage field mappings between FlowHub and WooCommerce.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'              => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'get_settings', 'update_settings', 'test_connection', 'get_field_mapping' ),
					'default'     => 'get_settings',
				),
				'client_id'           => array(
					'type'        => 'string',
					'description' => __( 'FlowHub API client ID (for update_settings).', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'             => array(
					'type'        => 'string',
					'description' => __( 'FlowHub API key (for update_settings).', 'mcp-ai-wpoos-pro' ),
				),
				'sync_interval'       => array(
					'type'        => 'integer',
					'description' => __( 'Sync interval in minutes: 1, 5, 15, 30, or 60.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 1, 5, 15, 30, 60 ),
				),
				'enable_wc_sync'      => array(
					'type'        => 'boolean',
					'description' => __( 'Enable WooCommerce stock synchronization.', 'mcp-ai-wpoos-pro' ),
				),
				'sync_direction'      => array(
					'type'        => 'string',
					'description' => __( 'Sync direction.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'flowhub_to_woo', 'woo_to_flowhub', 'bidirectional' ),
				),
				'low_stock_threshold' => array(
					'type'        => 'integer',
					'description' => __( 'Quantity threshold for "low stock" status.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'requires-credentials', 'requires-capability' );
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
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action              = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'get_settings';
		$client_id           = isset( $arguments['client_id'] ) ? sanitize_text_field( $arguments['client_id'] ) : null;
		$api_key             = isset( $arguments['api_key'] ) ? sanitize_text_field( $arguments['api_key'] ) : null;
		$sync_interval       = isset( $arguments['sync_interval'] ) ? absint( $arguments['sync_interval'] ) : null;
		$enable_wc_sync      = isset( $arguments['enable_wc_sync'] ) ? (bool) $arguments['enable_wc_sync'] : null;
		$sync_direction      = isset( $arguments['sync_direction'] ) ? sanitize_key( $arguments['sync_direction'] ) : null;
		$low_stock_threshold = isset( $arguments['low_stock_threshold'] ) ? absint( $arguments['low_stock_threshold'] ) : null;

		// Capability.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_flowhub_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'get_settings':
				$settings = $this->get_settings_array();
				return array(
					'success' => true,
					'message' => __( 'FlowHub settings retrieved.', 'mcp-ai-wpoos-pro' ),
					'data'    => $settings,
				);

			case 'update_settings':
				$updates = array_filter(
					array(
						'client_id'           => $client_id,
						'api_key'             => $api_key,
						'sync_interval'       => $sync_interval,
						'enable_wc_sync'      => null !== $enable_wc_sync ? ( $enable_wc_sync ? 'yes' : 'no' ) : null,
						'sync_direction'      => $sync_direction,
						'low_stock_threshold' => $low_stock_threshold,
					),
					function ( $v ) {
						return null !== $v;
					}
				);

				if ( empty( $updates ) ) {
					return new WP_Error(
						'wp_mcp_ai_flowhub_no_updates',
						__( 'No settings to update. Provide at least one setting value.', 'mcp-ai-wpoos-pro' )
					);
				}

				$this->persist_settings( $updates );
				return array(
					'success' => true,
					'message' => __( 'FlowHub settings updated.', 'mcp-ai-wpoos-pro' ),
					'data'    => $this->get_settings_array(),
				);

			case 'test_connection':
				return $this->handle_test_connection();

			case 'get_field_mapping':
				$cct_manager = $this->get_flowhub_cct_manager();
				return array(
					'success' => true,
					'message' => __( 'Field mapping retrieved.', 'mcp-ai-wpoos-pro' ),
					'data'    => array(
						'default_mapping' => $cct_manager->get_default_field_mapping(),
						'custom_mapping'  => $this->get_custom_mapping(),
					),
				);

			default:
				return new WP_Error( 'wp_mcp_ai_flowhub_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Get the current settings array (with api_key redacted).
	 *
	 * @since 1.2.0
	 * @return array
	 */
	protected function get_settings_array() {
		$settings = get_option( self::OPTION_KEY, array() );

		// Redact sensitive fields.
		if ( ! empty( $settings['api_key'] ) ) {
			$settings['api_key'] = substr( $settings['api_key'], 0, 4 ) . str_repeat( '*', max( 0, strlen( $settings['api_key'] ) - 8 ) ) . substr( $settings['api_key'], -4 );
		}

		return array(
			'client_id'           => esc_html( isset( $settings['client_id'] ) ? $settings['client_id'] : '' ),
			'api_key'             => esc_html( $settings['api_key'] ?? '' ),
			'api_base_url'        => esc_url( isset( $settings['api_base_url'] ) ? $settings['api_base_url'] : 'https://api.flowhub.co/v0/' ),
			'sync_interval'       => absint( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 15 ),
			'enable_wc_sync'      => ! empty( $settings['enable_wc_sync'] ),
			'sync_direction'      => esc_html( isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'flowhub_to_woo' ),
			'low_stock_threshold' => absint( isset( $settings['low_stock_threshold'] ) ? $settings['low_stock_threshold'] : 5 ),
			'cct_slug'            => esc_html( isset( $settings['cct_slug'] ) ? $settings['cct_slug'] : WP_MCP_AI_FlowHub_CCT_Manager::CCT_SLUG_DEFAULT ),
		);
	}

	/**
	 * Persist updated settings.
	 *
	 * @since 1.2.0
	 * @param array $updates Key-value pairs to update.
	 */
	protected function persist_settings( $updates ) {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = array_merge( $settings, $updates );
		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Test the FlowHub API connection.
	 *
	 * @since 1.2.0
	 * @return array|WP_Error
	 */
	protected function handle_test_connection() {
		$client = WP_MCP_AI_FlowHub_Client::from_settings();

		if ( is_wp_error( $client ) ) {
			return array(
				'success' => true,
				'message' => __( 'Connection test could not run.', 'mcp-ai-wpoos-pro' ),
				'data'    => array(
					'connected' => false,
					'error'     => $client->get_error_message(),
				),
			);
		}

		$result = $client->check_connection();

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => true,
				'message' => __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ),
				'data'    => array(
					'connected' => false,
					'error'     => $result->get_error_message(),
					'http_code' => $client->get_last_response_code(),
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connection test passed.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'connected' => true,
			),
		);
	}

	/**
	 * Get custom field mapping from settings.
	 *
	 * @since 1.2.0
	 * @return array
	 */
	protected function get_custom_mapping() {
		$settings = get_option( self::OPTION_KEY, array() );
		return isset( $settings['field_mapping'] ) && is_array( $settings['field_mapping'] )
			? $settings['field_mapping']
			: array();
	}
}
