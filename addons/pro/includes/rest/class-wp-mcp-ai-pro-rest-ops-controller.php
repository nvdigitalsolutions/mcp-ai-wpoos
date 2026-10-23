<?php
/**
 * REST controller for the Pro ops cluster (Proposal 084 Phase 3, gap P11).
 *
 * Groups the four wp-admin Pro ops surfaces behind one read-only overview
 * the Complete Console's Pro Ops page renders:
 *   GET /mcp-ai-pro/v1/ops/overview
 *
 * Sections wrap the existing services/pages — no logic forks:
 *   - status       WP_MCP_AI_Service_Status_Registry (cache-only snapshot)
 *   - incidents    mcp_ai_incident CPT rows + phase/severity meta
 *   - maintenance  mcp_ai_maintenance CPT rows + status/start/end meta
 *   - webhooks     WP_MCP_AI_Pro_Webhook_Status_Page::get_webhook_connections()
 *                  (list only — Telegram setWebhook/deleteWebhook calls are
 *                  network-bound and stay wp-admin AJAX; tracked follow-up)
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro ops overview REST controller.
 */
class WP_MCP_AI_Pro_REST_Ops_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/ops';

	/**
	 * Register the ops overview route.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/overview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check — Pro ops are administrator-only.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /ops/overview — the consolidated Pro ops payload.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function get_overview() {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'status'      => $this->collect_status(),
					'incidents'   => $this->collect_incidents(),
					'maintenance' => $this->collect_maintenance(),
					'webhooks'    => $this->collect_webhooks(),
				),
			)
		);
	}

	/**
	 * Service-status snapshot (cache-only — mirrors the AJAX refresh read).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_status() {
		if ( ! class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
			return array();
		}

		try {
			$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
			$status   = $registry->get_cached_status();
			$sources  = $registry->get_sources();

			$components = array();
			foreach ( (array) $status as $slug => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}
				$source = isset( $sources[ $slug ] ) ? $sources[ $slug ] : null;

				$component = array();
				foreach ( $data as $key => $value ) {
					$component[ sanitize_key( $key ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
				$component['slug']   = sanitize_key( $slug );
				$component['name']   = $source ? sanitize_text_field( $source->get_name() ) : sanitize_key( $slug );
				$component['group']  = $source ? sanitize_key( $source->get_group() ) : 'unknown';
				$component['public'] = $source ? (bool) $source->is_public() : false;

				$components[] = $component;
			}

			$last_check = (int) get_option( WP_MCP_AI_Service_Status_Registry::LAST_CHECK_KEY, 0 );

			return array(
				'overall'      => sanitize_key( $registry->compute_overall_status( $components ) ),
				'components'   => $components,
				'last_checked' => $last_check,
			);
		} catch ( Throwable $e ) {
			return array( 'error' => sanitize_text_field( $e->getMessage() ) );
		}
	}

	/**
	 * Incident rows (same CPT + meta the incidents page lists).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_incidents() {
		if ( ! class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
			return array();
		}

		$rows = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$incidents = array();
		foreach ( $rows as $incident ) {
			$incidents[] = array(
				'id'          => absint( $incident->ID ),
				'title'       => sanitize_text_field( $incident->post_title ),
				'phase'       => sanitize_key( (string) get_post_meta( $incident->ID, '_mcp_ai_incident_phase', true ) ),
				'severity'    => sanitize_key( (string) get_post_meta( $incident->ID, '_mcp_ai_incident_severity', true ) ),
				'services'    => array_map( 'sanitize_text_field', (array) get_post_meta( $incident->ID, '_mcp_ai_incident_services', true ) ),
				'resolved_at' => sanitize_text_field( (string) get_post_meta( $incident->ID, '_mcp_ai_incident_resolved_at', true ) ),
				'date'        => sanitize_text_field( $incident->post_date ),
			);
		}

		return $incidents;
	}

	/**
	 * Maintenance windows (same CPT + meta the maintenance page lists).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_maintenance() {
		if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
			return array();
		}

		$rows = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Maintenance_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$windows = array();
		foreach ( $rows as $window ) {
			$windows[] = array(
				'id'             => absint( $window->ID ),
				'title'          => sanitize_text_field( $window->post_title ),
				'status'         => sanitize_key( (string) get_post_meta( $window->ID, '_mcp_ai_maintenance_status', true ) ),
				'start'          => sanitize_text_field( (string) get_post_meta( $window->ID, '_mcp_ai_maintenance_start', true ) ),
				'end'            => sanitize_text_field( (string) get_post_meta( $window->ID, '_mcp_ai_maintenance_end', true ) ),
				'banner_enabled' => (bool) get_post_meta( $window->ID, '_mcp_ai_maintenance_banner_enabled', true ),
			);
		}

		return $windows;
	}

	/**
	 * Webhook-capable connection list (no network checks).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_webhooks() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Webhook_Status_Page' ) ) {
			return array();
		}

		$connections = WP_MCP_AI_Pro_Webhook_Status_Page::get_webhook_connections();
		if ( ! is_array( $connections ) ) {
			return array();
		}

		$webhooks = array();
		foreach ( $connections as $id => $connection ) {
			if ( ! is_array( $connection ) ) {
				continue;
			}
			$type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';

			$webhooks[] = array(
				'id'                => sanitize_key( (string) $id ),
				'type'              => $type,
				'label'             => isset( $connection['label'] ) ? sanitize_text_field( (string) $connection['label'] ) : '',
				'expected_url'      => esc_url_raw( WP_MCP_AI_Pro_Webhook_Status_Page::get_expected_webhook_url( (string) $id, $type ) ),
				'webhook_set'       => ! empty( $connection['webhook_set'] ) || ! empty( $connection['webhook_verified'] ),
				'last_checked_text' => isset( $connection['last_checked_text'] ) ? sanitize_text_field( (string) $connection['last_checked_text'] ) : '',
			);
		}

		return $webhooks;
	}

	/**
	 * Boot the controller (idempotent) — mirrors the Pro schedule/SA
	 * controller init pattern.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}
}
