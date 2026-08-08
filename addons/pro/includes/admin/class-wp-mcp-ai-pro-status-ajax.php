<?php
/**
 * Pro Status Dashboard AJAX Handlers
 *
 * Handles AJAX requests for the Status Dashboard page: refreshing status
 * data, triggering manual health checks, and toggling component visibility.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Admin
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Status_Ajax' ) ) {
	/**
	 * Pro Status AJAX handler class.
	 *
	 * @since 1.2.0
	 */
	class WP_MCP_AI_Pro_Status_Ajax {

		/**
		 * Nonce action for status dashboard AJAX requests.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const NONCE_ACTION = 'wp_mcp_ai_status_dashboard';

		/**
		 * Constructor. Registers AJAX handlers.
		 *
		 * @since 1.2.0
		 */
		public function __construct() {
			add_action( 'wp_ajax_wp_mcp_ai_status_refresh', array( $this, 'handle_refresh' ) );
			add_action( 'wp_ajax_wp_mcp_ai_status_health_check', array( $this, 'handle_health_check' ) );
			add_action( 'wp_ajax_wp_mcp_ai_status_toggle_public', array( $this, 'handle_toggle_public' ) );
			add_action( 'wp_ajax_wp_mcp_ai_status_history', array( $this, 'handle_history' ) );
		}

		/**
		 * Handle status refresh request.
		 *
		 * Returns the current status snapshot.
		 *
		 * @since 1.2.0
		 *
		 * @return void
		 */
		public function handle_refresh(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}

			if ( ! class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Service Status Registry is not available.', 'mcp-ai-wpoos-pro' ) ),
					500
				);
			}

			// Per-user throttle: prevent rapid-fire polling from hammering MySQL.
			// Each admin user can trigger at most one refresh per 60 seconds.
			$throttle_key = 'wp_mcp_ai_status_refresh_throttle_' . get_current_user_id();
			if ( get_transient( $throttle_key ) ) {
				// Return the cached snapshot without any processing.
				$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
				$cached   = $registry->get_cached_status();
				wp_send_json_success(
					array(
						'throttled'  => true,
						'components' => $cached,
					)
				);
			}
			set_transient( $throttle_key, 1, 60 );

			try {
				$registry = WP_MCP_AI_Service_Status_Registry::get_instance();

				// Use cache-only read — never trigger a health check on the AJAX
				// request thread. The five_minute_tick cron keeps the cache warm.
				$status  = $registry->get_cached_status();
				$sources = $registry->get_sources();

				// Enrich with source metadata.
				$enriched = array();
				foreach ( $status as $slug => $data ) {
					if ( ! is_array( $data ) ) {
						continue;
					}
					$source = $sources[ $slug ] ?? null;

					$enriched[ $slug ] = array_merge(
						$data,
						array(
							'name'   => $source ? $source->get_name() : $slug,
							'group'  => $source ? $source->get_group() : 'unknown',
							'public' => $source ? $source->is_public() : false,
						)
					);
				}

				$overall    = $registry->compute_overall_status( $enriched );
				$last_check = (int) get_option( WP_MCP_AI_Service_Status_Registry::LAST_CHECK_KEY, 0 );

				wp_send_json_success(
					array(
						'overall'           => $overall,
						'components'        => $enriched,
						'last_checked'      => $last_check,
						'last_checked_text' => $last_check > 0
							? human_time_diff( $last_check )
							: __( 'Never', 'mcp-ai-wpoos-pro' ),
					)
				);
			} catch ( \Throwable $e ) {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to load status data.', 'mcp-ai-wpoos-pro' ),
						'debug'   => WP_DEBUG ? $e->getMessage() : null,
					),
					500
				);
			}
		}

		/**
		 * Handle uptime history request (separate from status refresh).
		 *
		 * The 30-day uptime chart data changes at most hourly (on rollup),
		 * so it is fetched independently from the component status grid.
		 * This keeps the frequent polling endpoint lightweight.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public function handle_history(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}

			if ( ! class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Service Status Registry is not available.', 'mcp-ai-wpoos-pro' ) ),
					500
				);
			}

			try {
				$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
				$history  = $registry->get_uptime_history( 30 );

				wp_send_json_success(
					array(
						'history' => $history,
					)
				);
			} catch ( \Throwable $e ) {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to load uptime history.', 'mcp-ai-wpoos-pro' ),
						'debug'   => WP_DEBUG ? $e->getMessage() : null,
					),
					500
				);
			}
		}

		/**
		 * Handle manual health check trigger.
		 *
		 * Runs all health checks and returns fresh results.
		 *
		 * @since 1.2.0
		 *
		 * @return void
		 */
		public function handle_health_check(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}

			$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
			$results  = $registry->run_health_checks();

			wp_send_json_success(
				array(
					'message'    => __( 'Health check completed.', 'mcp-ai-wpoos-pro' ),
					'results'    => $results,
					'checked_at' => time(),
				)
			);
		}

		/**
		 * Handle toggle public visibility request.
		 *
		 * Updates the _mcp_ai_service_public post meta for a component.
		 *
		 * @since 1.2.0
		 *
		 * @return void
		 */
		public function handle_toggle_public(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos-pro' ) ),
					403
				);
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer above.
			$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer above.
			$is_public = isset( $_POST['is_public'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['is_public'] ) ) : true;

			if ( '' === $slug ) {
				wp_send_json_error(
					array( 'message' => __( 'Component slug is required.', 'mcp-ai-wpoos-pro' ) ),
					400
				);
			}

			// Find or create the CPT post for this component.
			$post_id = $this->find_or_create_component_post( $slug );

			if ( is_wp_error( $post_id ) ) {
				wp_send_json_error(
					array( 'message' => $post_id->get_error_message() ),
					500
				);
			}

			update_post_meta( $post_id, '_mcp_ai_service_public', $is_public );

			wp_send_json_success(
				array(
					'slug'      => $slug,
					'is_public' => $is_public,
					'message'   => $is_public
						? __( 'Component is now public.', 'mcp-ai-wpoos-pro' )
						: __( 'Component is now private.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		/**
		 * Find or create a service component CPT post by slug.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug Component slug.
		 * @return int|WP_Error Post ID on success, WP_Error on failure.
		 */
		private function find_or_create_component_post( string $slug ) {
			$existing = get_posts(
				array(
					'post_type'      => WP_MCP_AI_Service_Status_CPT::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => '_mcp_ai_service_slug',
					'meta_value'     => $slug,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $existing ) ) {
				return (int) $existing[0];
			}

			// Get source metadata.
			$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
			$sources  = $registry->get_sources();
			$source   = $sources[ $slug ] ?? null;

			$post_id = wp_insert_post(
				array(
					'post_type'   => WP_MCP_AI_Service_Status_CPT::POST_TYPE,
					'post_title'  => $source ? $source->get_name() : $slug,
					'post_status' => 'publish',
					'meta_input'  => array(
						'_mcp_ai_service_slug'   => $slug,
						'_mcp_ai_service_group'  => $source ? $source->get_group() : 'unknown',
						'_mcp_ai_service_public' => true,
						'_mcp_ai_service_status' => 'operational',
					),
				),
				true
			);

			return $post_id;
		}
	}

	// Bootstrap.
	if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
		new WP_MCP_AI_Pro_Status_Ajax();
	}
}
