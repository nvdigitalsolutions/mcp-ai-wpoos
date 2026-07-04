<?php
/**
 * FlowHub Sync Tool.
 *
 * Enables AI assistants to trigger FlowHub inventory sync operations,
 * check sync status, and clear the CCT cache. All write operations
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
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-sync-engine.php';

/**
 * FlowHub Sync Tool.
 *
 * Trigger sync operations, check status, and manage cache.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Pro_Tool_FlowHub_Sync implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_FlowHub_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_sync';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'FlowHub Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Trigger FlowHub inventory sync operations. Use sync_now to pull fresh data from the FlowHub API, sync_status to check the last sync, and clear_cache to remove all cached inventory data.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'  => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'sync_now', 'sync_status', 'clear_cache' ),
					'default'     => 'sync_status',
				),
				'confirm' => array(
					'type'        => 'boolean',
					'description' => __( 'Set to true to confirm cache clearing (required for clear_cache).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
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
		$action  = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'sync_status';
		$confirm = isset( $arguments['confirm'] ) ? (bool) $arguments['confirm'] : false;

		// Capability.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_forbidden',
				__( 'You do not have permission to manage FlowHub sync operations.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Dependencies.
		$deps = $this->check_flowhub_dependencies();
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_flowhub_cct_manager();

		switch ( $action ) {
			case 'sync_now':
				$result = $cct_manager->sync_from_api( true );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: item count, 2: locations, 3: duration */
						__( 'Sync completed: %1$d items across %2$d locations (took %3$ss).', 'mcp-ai-wpoos-pro' ),
						$result['item_count'],
						$result['location_count'],
						$result['duration']
					),
					'data'    => array(
						'item_count'     => $result['item_count'],
						'location_count' => $result['location_count'],
						'error_count'    => $result['error_count'],
						'duration'       => $result['duration'],
						'timestamp'      => $result['timestamp'],
					),
				);

			case 'sync_status':
				$last_sync  = $cct_manager->get_last_sync_time();
				$row_count  = $cct_manager->get_row_count();
				$is_fresh   = $cct_manager->is_fresh();
				$last_error = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
				$next_sync  = $this->get_next_scheduled_sync();

				return array(
					'success' => true,
					'message' => $is_fresh
						? __( 'FlowHub sync is up to date.', 'mcp-ai-wpoos-pro' )
						: __( 'FlowHub sync may be stale.', 'mcp-ai-wpoos-pro' ),
					'data'    => array(
						'last_sync'  => esc_html( $last_sync ),
						'next_sync'  => esc_html( $next_sync ),
						'row_count'  => $row_count,
						'is_fresh'   => $is_fresh,
						'last_error' => esc_html( $last_error ),
						'cct_slug'   => esc_html( $cct_manager->get_cct_slug() ),
					),
				);

			case 'clear_cache':
				if ( ! $confirm ) {
					return new WP_Error(
						'wp_mcp_ai_flowhub_confirm_required',
						__( 'Set confirm to true to clear the FlowHub cache. This will delete all cached inventory data.', 'mcp-ai-wpoos-pro' )
					);
				}

				$result = $cct_manager->truncate();

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				delete_option( 'wp_mcp_ai_flowhub_last_sync' );

				return array(
					'success' => true,
					'message' => __( 'FlowHub cache cleared. Run sync_now to repopulate.', 'mcp-ai-wpoos-pro' ),
					'data'    => array(
						'cleared' => true,
					),
				);

			default:
				return new WP_Error( 'wp_mcp_ai_flowhub_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Get the next scheduled sync time.
	 *
	 * @since 1.2.0
	 * @return string Human-readable time or empty string.
	 */
	protected function get_next_scheduled_sync() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return __( 'Action Scheduler not available', 'mcp-ai-wpoos-pro' );
		}

		$timestamp = as_next_scheduled_action(
			WP_MCP_AI_FlowHub_Sync_Engine::HOOK_FULL_SYNC,
			array(),
			WP_MCP_AI_FlowHub_Sync_Engine::GROUP
		);

		if ( ! $timestamp ) {
			return __( 'Not scheduled (may run on next page load)', 'mcp-ai-wpoos-pro' );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
