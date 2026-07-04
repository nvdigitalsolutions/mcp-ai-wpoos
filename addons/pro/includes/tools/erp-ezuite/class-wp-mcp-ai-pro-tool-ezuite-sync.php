<?php
/**
 * EZuite Sync Tool.
 *
 * Enables AI assistants to trigger EZuite inventory sync operations,
 * check sync status, and run dry-run validations. Write operations
 * (trigger) require manage_options capability; status reads require
 * only edit_posts.
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
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-sync-engine.php';

/**
 * EZuite Sync Tool.
 *
 * Trigger sync operations, check status, and run dry-run validations.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Pro_Tool_EZuite_Sync implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Rate limit: max requests per minute for trigger/dry_run.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const RATE_LIMIT_PER_MINUTE = 5;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ezuite_sync';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'EZuite Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Trigger EZuite inventory sync, check sync status, or run a dry-run validation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'trigger', 'status', 'dry_run' ),
					'default'     => 'status',
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for per-connection operations.', 'mcp-ai-wpoos-pro' ),
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
			'rate-limited',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Returns a context-dependent capability: manage_options for state-changing
	 * actions (trigger, dry_run), edit_posts for read-only (status).
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$action        = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'status';
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_text_field( $arguments['connection_id'] ) : '';

		// Determine required capability based on action.
		$is_write_action = in_array( $action, array( 'trigger', 'dry_run' ), true );
		$required_cap    = $is_write_action ? 'manage_options' : 'edit_posts';

		// Capability check.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $required_cap ) ) {
			return new WP_Error(
				'wp_mcp_ai_ezuite_forbidden',
				$is_write_action
					? __( 'You do not have permission to trigger EZuite sync operations. This requires manage_options capability.', 'mcp-ai-wpoos-pro' )
					: __( 'You do not have permission to view EZuite sync status.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Rate limit for write and dry_run actions.
		if ( $is_write_action ) {
			$rate_limit_check = $this->check_rate_limit( $user_id );
			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}
		}

		$conn_id     = ! empty( $connection_id ) ? $connection_id : null;
		$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager( $conn_id );

		switch ( $action ) {
			case 'trigger':
				return $this->handle_trigger( $conn_id );

			case 'status':
				return $this->handle_status( $cct_manager, $conn_id );

			case 'dry_run':
				return $this->handle_dry_run( $conn_id );

			default:
				return new WP_Error(
					'wp_mcp_ai_ezuite_invalid_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Invalid action "%s".', 'mcp-ai-wpoos-pro' ),
						$action
					)
				);
		}
	}

	/**
	 * Handle trigger action — dispatch a full sync via Action Scheduler.
	 *
	 * @since 1.9.0
	 *
	 * @param string|null $connection_id Optional Remote Sites connection ID.
	 * @return array|WP_Error
	 */
	protected function handle_trigger( $connection_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			return new WP_Error(
				'wp_mcp_ai_ezuite_sync_engine_missing',
				__( 'EZuite Sync Engine is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Dispatch via Action Scheduler for async execution.
		WP_MCP_AI_EZuite_Sync_Engine::dispatch_full_sync( $connection_id );

		// Also dispatch WC sync if enabled.
		$settings = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
		if ( ! empty( $settings['enable_wc_sync'] ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				WP_MCP_AI_EZuite_Sync_Engine::HOOK_WC_SYNC,
				array(),
				WP_MCP_AI_EZuite_Sync_Engine::GROUP_WC,
				true
			);
		}

		return array(
			'success' => true,
			'message' => __( 'EZuite full sync dispatched. The sync will run in the background via Action Scheduler. Use the status action to monitor progress.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'action'         => 'trigger',
				'dispatched_at'  => current_time( 'mysql' ),
				'connection_id'  => esc_html( (string) $connection_id ),
				'wc_sync_queued' => ! empty( $settings['enable_wc_sync'] ),
			),
		);
	}

	/**
	 * Handle status action — read current sync state.
	 *
	 * @since 1.9.0
	 *
	 * @param WP_MCP_AI_EZuite_CCT_Manager $cct_manager   CCT manager instance.
	 * @param string|null                  $connection_id Optional Remote Sites connection ID.
	 * @return array
	 */
	protected function handle_status( $cct_manager, $connection_id = null ) {
		$last_sync  = $cct_manager->get_last_sync_time();
		$row_count  = $cct_manager->get_row_count();
		$is_fresh   = $cct_manager->is_fresh();
		$last_error = get_option( 'wp_mcp_ai_ezuite_last_sync_error', '' );
		$next_sync  = $this->get_next_scheduled_sync();
		$cct_slug   = $cct_manager->get_cct_slug();

		// API usage stats.
		$requests_today  = absint( get_option( 'wp_mcp_ai_ezuite_api_requests_today', 0 ) );
		$rate_limit_hits = absint( get_option( 'wp_mcp_ai_ezuite_api_rate_limit_hits', 0 ) );

		$status_data = array(
			'last_sync'           => esc_html( $last_sync ),
			'next_sync'           => esc_html( $next_sync ),
			'row_count'           => $row_count,
			'is_fresh'            => $is_fresh,
			'last_error'          => esc_html( $last_error ),
			'cct_slug'            => esc_html( $cct_slug ),
			'connection_id'       => esc_html( (string) $connection_id ),
			'api_requests_today'  => $requests_today,
			'api_rate_limit_hits' => $rate_limit_hits,
		);

		// Build a markdown summary for chat display.
		$freshness = $is_fresh
			? __( '✅ Fresh — data is up to date.', 'mcp-ai-wpoos-pro' )
			: __( '⚠️ Stale — sync may be overdue.', 'mcp-ai-wpoos-pro' );

		$markdown  = "## EZuite Sync Status\n\n";
		$markdown .= sprintf( "**Last Sync:** %s\n\n", ! empty( $last_sync ) ? esc_html( $last_sync ) : __( 'Never', 'mcp-ai-wpoos-pro' ) );
		$markdown .= sprintf( "**Next Sync:** %s\n\n", esc_html( $next_sync ) );
		$markdown .= sprintf( "**Cached Rows:** %d\n\n", $row_count );
		$markdown .= sprintf( "**Freshness:** %s\n\n", $freshness );
		$markdown .= sprintf( "**CCT Slug:** `%s`\n\n", esc_html( $cct_slug ) );
		$markdown .= sprintf( "**API Requests Today:** %d\n\n", $requests_today );

		if ( ! empty( $last_error ) ) {
			$markdown .= sprintf( "**Last Error:** %s\n\n", esc_html( $last_error ) );
		}

		return array(
			'success'  => true,
			'message'  => $is_fresh
				? __( 'EZuite sync is up to date.', 'mcp-ai-wpoos-pro' )
				: __( 'EZuite sync may be stale. Consider triggering a sync.', 'mcp-ai-wpoos-pro' ),
			'data'     => $status_data,
			'markdown' => $markdown,
		);
	}

	/**
	 * Handle dry_run action — validate configuration without writing data.
	 *
	 * @since 1.9.0
	 *
	 * @param string|null $connection_id Optional Remote Sites connection ID.
	 * @return array|WP_Error
	 */
	protected function handle_dry_run( $connection_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			return new WP_Error(
				'wp_mcp_ai_ezuite_sync_engine_missing',
				__( 'EZuite Sync Engine is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$dry_report = WP_MCP_AI_EZuite_Sync_Engine::run_full_sync( true, $connection_id );

		if ( is_wp_error( $dry_report ) ) {
			return $dry_report;
		}

		// Build a markdown summary.
		$markdown  = "## EZuite Dry-Run Report\n\n";
		$markdown .= sprintf( "**CCT Slug:** `%s`\n\n", esc_html( isset( $dry_report['status']['cct_slug'] ) ? $dry_report['status']['cct_slug'] : '' ) );
		$markdown .= sprintf( "**CCT Exists:** %s\n\n", ! empty( $dry_report['status']['cct_exists'] ) ? __( 'Yes', 'mcp-ai-wpoos-pro' ) : __( 'No', 'mcp-ai-wpoos-pro' ) );
		$markdown .= sprintf( "**API Configured:** %s\n\n", ! empty( $dry_report['status']['is_configured'] ) ? __( 'Yes', 'mcp-ai-wpoos-pro' ) : __( 'No', 'mcp-ai-wpoos-pro' ) );
		$markdown .= sprintf( "**Items Would Sync:** %d\n\n", absint( isset( $dry_report['data_summary']['items_would_sync'] ) ? $dry_report['data_summary']['items_would_sync'] : 0 ) );
		$markdown .= sprintf( "**Errors:** %d\n\n", absint( isset( $dry_report['data_summary']['errors'] ) ? $dry_report['data_summary']['errors'] : 0 ) );
		$markdown .= sprintf( "**Duration:** %ds\n\n", absint( isset( $dry_report['data_summary']['duration'] ) ? $dry_report['data_summary']['duration'] : 0 ) );

		$passed = empty( $dry_report['data_summary']['errors'] ) && ! empty( $dry_report['status']['is_configured'] );

		return array(
			'success'  => true,
			'message'  => $passed
				? __( 'EZuite dry-run passed. Configuration is valid and ready for sync.', 'mcp-ai-wpoos-pro' )
				: __( 'EZuite dry-run completed with issues. Review the report for details.', 'mcp-ai-wpoos-pro' ),
			'data'     => array(
				'dry_report' => $dry_report,
			),
			'markdown' => $markdown,
		);
	}

	/**
	 * Get the next scheduled sync time.
	 *
	 * @since 1.9.0
	 * @return string Human-readable time or status message.
	 */
	protected function get_next_scheduled_sync() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return __( 'Action Scheduler not available', 'mcp-ai-wpoos-pro' );
		}

		if ( ! class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			return __( 'Sync engine not loaded', 'mcp-ai-wpoos-pro' );
		}

		$timestamp = as_next_scheduled_action(
			WP_MCP_AI_EZuite_Sync_Engine::HOOK_FULL_SYNC,
			array(),
			WP_MCP_AI_EZuite_Sync_Engine::GROUP
		);

		if ( ! $timestamp ) {
			return __( 'Not scheduled (may run on next page load)', 'mcp-ai-wpoos-pro' );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Check rate limit for sync trigger/dry_run operations.
	 *
	 * @since 1.9.0
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$user_id        = absint( $user_id );
		$transient_key  = 'wp_mcp_ai_pro_ezuite_sync_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = self::RATE_LIMIT_PER_MINUTE;

		/**
		 * Filter the maximum EZuite sync operations allowed per minute per user.
		 *
		 * @since 1.9.0
		 *
		 * @param int $max_per_minute Maximum requests per minute (default: 5).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_pro_ezuite_sync_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_pro_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum requests allowed per minute */
					__( 'EZuite sync rate limit exceeded. Maximum %d trigger/dry-run operations per minute allowed.', 'mcp-ai-wpoos-pro' ),
					$max_per_minute
				)
			);
		}

		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
