<?php
/**
 * Shopify Sync Settings Tool.
 *
 * Enables AI assistants to read and update Shopify Sync toolkit configuration,
 * check sync status, view GraphQL cost reports, and trigger manual syncs.
 * All write operations require manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/trait-wp-mcp-ai-shopify-sync-connection-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';

/**
 * Shopify Sync Settings Tool.
 *
 * Read/update Shopify Sync toolkit settings and view cost/status reports.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Sync_Settings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Shopify_Sync_Connection_Resolver;

	/**
	 * Option key for toolkit settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_shopify_sync_toolkit_settings';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_sync_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Sync Settings', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'View and update Shopify Sync toolkit settings. Check sync status, view GraphQL API cost reports, test connections, and trigger manual syncs.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'connection_id'       => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID. Auto-resolved if omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'action'              => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'get_settings', 'update_settings', 'get_sync_status', 'get_cost_report', 'sync_now' ),
					'default'     => 'get_settings',
				),
				'sync_interval'       => array(
					'type'        => 'integer',
					'description' => __( 'Sync interval in minutes: 5, 15, 30, or 60.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 5, 15, 30, 60 ),
				),
				'enable_wc_sync'      => array(
					'type'        => 'boolean',
					'description' => __( 'Enable WooCommerce stock synchronization.', 'mcp-ai-wpoos-pro' ),
				),
				'sync_direction'      => array(
					'type'        => 'string',
					'description' => __( 'Sync direction.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'shopify_to_woo', 'woo_to_shopify', 'bidirectional', 'read_only' ),
				),
				'low_stock_threshold' => array(
					'type'        => 'integer',
					'description' => __( 'Quantity threshold for "low stock" status.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'enable_webhooks'     => array(
					'type'        => 'boolean',
					'description' => __( 'Enable Shopify webhook registration for real-time sync.', 'mcp-ai-wpoos-pro' ),
				),
				'sync_connections'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Array of connection IDs to enable for sync.', 'mcp-ai-wpoos-pro' ),
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
			'external-api',
			'requires-credentials',
			'requires-capability',
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
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action              = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'get_settings';
		$sync_interval       = isset( $arguments['sync_interval'] ) ? absint( $arguments['sync_interval'] ) : null;
		$enable_wc_sync      = isset( $arguments['enable_wc_sync'] ) ? (bool) $arguments['enable_wc_sync'] : null;
		$sync_direction      = isset( $arguments['sync_direction'] ) ? sanitize_key( $arguments['sync_direction'] ) : null;
		$low_stock_threshold = isset( $arguments['low_stock_threshold'] ) ? absint( $arguments['low_stock_threshold'] ) : null;
		$enable_webhooks     = isset( $arguments['enable_webhooks'] ) ? (bool) $arguments['enable_webhooks'] : null;
		$sync_connections    = isset( $arguments['sync_connections'] ) ? array_map( 'sanitize_key', $arguments['sync_connections'] ) : null;

		// Capability.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_sync_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'get_settings':
				return array(
					'success' => true,
					'message' => __( 'Shopify Sync settings retrieved.', 'mcp-ai-wpoos-pro' ),
					'data'    => $this->get_settings_array(),
				);

			case 'update_settings':
				$updates = array_filter(
					array(
						'sync_interval'       => $sync_interval,
						'enable_wc_sync'      => null !== $enable_wc_sync ? ( $enable_wc_sync ? 'yes' : 'no' ) : null,
						'sync_direction'      => $sync_direction,
						'low_stock_threshold' => $low_stock_threshold,
						'enable_webhooks'     => null !== $enable_webhooks ? $enable_webhooks : null,
						'sync_connections'    => $sync_connections,
					),
					function ( $v ) {
						return null !== $v;
					}
				);

				if ( empty( $updates ) ) {
					return new WP_Error(
						'wp_mcp_ai_shopify_sync_no_updates',
						__( 'No settings to update. Provide at least one setting value.', 'mcp-ai-wpoos-pro' )
					);
				}

				$this->persist_settings( $updates );
				return array(
					'success' => true,
					'message' => __( 'Shopify Sync settings updated.', 'mcp-ai-wpoos-pro' ),
					'data'    => $this->get_settings_array(),
				);

			case 'get_sync_status':
				$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
				if ( is_wp_error( $connection_id ) ) {
					return $connection_id;
				}
				return $this->handle_get_sync_status( $connection_id );

			case 'get_cost_report':
				$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
				if ( is_wp_error( $connection_id ) ) {
					return $connection_id;
				}
				return $this->handle_get_cost_report( $connection_id );

			case 'sync_now':
				$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
				if ( is_wp_error( $connection_id ) ) {
					return $connection_id;
				}
				return $this->handle_sync_now( $connection_id );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_sync_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Get the current settings array.
	 *
	 * @return array
	 */
	protected function get_settings_array() {
		$settings = get_option( self::OPTION_KEY, array() );

		return array(
			'sync_interval'       => absint( isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 15 ),
			'enable_wc_sync'      => ! empty( $settings['enable_wc_sync'] ),
			'sync_direction'      => esc_html( isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'shopify_to_woo' ),
			'low_stock_threshold' => absint( isset( $settings['low_stock_threshold'] ) ? $settings['low_stock_threshold'] : 5 ),
			'enable_webhooks'     => ! isset( $settings['enable_webhooks'] ) || ! empty( $settings['enable_webhooks'] ),
			'cct_slug'            => esc_html( isset( $settings['cct_slug'] ) ? $settings['cct_slug'] : WP_MCP_AI_Shopify_Sync_CCT_Manager::CCT_SLUG_DEFAULT ),
			'sync_connections'    => isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array(),
		);
	}

	/**
	 * Persist updated settings.
	 *
	 * @param array $updates Key-value pairs to update.
	 */
	protected function persist_settings( $updates ) {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = array_merge( $settings, $updates );
		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Handle get_sync_status action.
	 *
	 * @param string $connection_id Connection ID.
	 * @return array
	 */
	protected function handle_get_sync_status( $connection_id ) {
		$cct_manager = $this->get_shopify_sync_cct_manager( $connection_id );
		$engine      = $this->get_shopify_sync_engine( $connection_id );

		$last_sync  = $cct_manager->get_last_sync_time();
		$row_count  = $cct_manager->get_row_count();
		$is_fresh   = $cct_manager->is_fresh();
		$last_error = get_option( 'wp_mcp_ai_shopify_last_sync_error_' . $connection_id, '' );
		$webhook_ok = get_option( 'wp_mcp_ai_shopify_webhook_registered_' . $connection_id, false );

		// Get next scheduled sync.
		$next_sync = '';
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$hook      = WP_MCP_AI_Shopify_Sync_Engine::HOOK_FULL_SYNC . '_' . $connection_id;
			$timestamp = as_next_scheduled_action( $hook, array(), WP_MCP_AI_Shopify_Sync_Engine::GROUP );
			$next_sync = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : __( 'Not scheduled', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success' => true,
			'message' => $is_fresh
				? __( 'Shopify sync is up to date.', 'mcp-ai-wpoos-pro' )
				: __( 'Shopify sync may be stale.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'connection_id'   => $connection_id,
				'last_sync'       => esc_html( $last_sync ),
				'next_sync'       => esc_html( $next_sync ),
				'row_count'       => $row_count,
				'is_fresh'        => $is_fresh,
				'last_error'      => esc_html( $last_error ),
				'webhooks_active' => (bool) $webhook_ok,
				'cct_slug'        => esc_html( $cct_manager->get_cct_slug() ),
			),
		);
	}

	/**
	 * Handle get_cost_report action.
	 *
	 * @param string $connection_id Connection ID.
	 * @return array
	 */
	protected function handle_get_cost_report( $connection_id ) {
		$engine = $this->get_shopify_sync_engine( $connection_id );
		$report = $engine->get_cost_report();

		return array(
			'success' => true,
			'message' => $report['is_low']
				? __( 'GraphQL cost budget is critically low.', 'mcp-ai-wpoos-pro' )
				: __( 'GraphQL cost budget is healthy.', 'mcp-ai-wpoos-pro' ),
			'data'    => $report,
		);
	}

	/**
	 * Handle sync_now action.
	 *
	 * @param string $connection_id Connection ID.
	 * @return array
	 */
	protected function handle_sync_now( $connection_id ) {
		$cct_manager = $this->get_shopify_sync_cct_manager( $connection_id );
		$result      = $cct_manager->sync_from_bulk_operation();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Track cost.
		$engine = $this->get_shopify_sync_engine( $connection_id );
		$engine->track_sync_cost( 10 );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: inserted count, 2: updated count, 3: duration */
				__( 'Sync completed: %1$d inserted, %2$d updated (took %3$ss).', 'mcp-ai-wpoos-pro' ),
				isset( $result['inserted'] ) ? $result['inserted'] : 0,
				isset( $result['updated'] ) ? $result['updated'] : 0,
				isset( $result['duration'] ) ? $result['duration'] : 0
			),
			'data'    => $result,
		);
	}
}
