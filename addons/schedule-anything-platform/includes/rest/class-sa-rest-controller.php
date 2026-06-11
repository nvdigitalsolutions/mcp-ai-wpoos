<?php
/**
 * Schedule Anything — REST Controller
 *
 * Platform-level REST endpoints for tenant management.
 * Namespace: /wp-json/nvoos-saas/v1/
 *
 * All state-changing routes require X-SaaS-API-Key header
 * (internal service-to-service auth from the Cloud Worker).
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for tenant provisioning and management.
 *
 * @since 0.1.0
 */
class SA_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'nvoos-saas/v1';

	/**
	 * Register REST routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		// Health / diagnostics.
		register_rest_route(
			self::NAMESPACE,
			'/healthz',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'healthz' ),
				'permission_callback' => array( __CLASS__, 'permission_api_key' ),
			)
		);

		// Nonce endpoint for SPA auth.
		register_rest_route(
			self::NAMESPACE,
			'/auth/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_nonce' ),
				'permission_callback' => '__return_true',
			)
		);

		// Tenant provisioning (called by Cloud Worker after Stripe checkout).
		register_rest_route(
			self::NAMESPACE,
			'/tenants/provision',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'provision_tenant' ),
				'permission_callback' => array( __CLASS__, 'permission_api_key' ),
				'args'                => self::provision_args(),
			)
		);

		// Tenant offboarding (called by Cloud Worker on subscription cancellation).
		register_rest_route(
			self::NAMESPACE,
			'/tenants/(?P<id>\d+)/offboard',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'offboard_tenant' ),
				'permission_callback' => array( __CLASS__, 'permission_api_key' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Tenant lookup by slug (used by tenant router for KV fallback).
		register_rest_route(
			self::NAMESPACE,
			'/tenants/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'lookup_tenant' ),
				'permission_callback' => array( __CLASS__, 'permission_api_key' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Tenant status check.
		register_rest_route(
			self::NAMESPACE,
			'/tenants/(?P<id>\d+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_tenant_status' ),
				'permission_callback' => array( __CLASS__, 'permission_api_key' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: validate the internal API key.
	 *
	 * Used for service-to-service calls from the Cloud Worker.
	 * The API key is set via the SA_SAAS_API_KEY constant or filter.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return true|WP_Error True if authorized, WP_Error otherwise.
	 */
	public static function permission_api_key( $request ) {
		$provided = $request->get_header( 'X-SaaS-API-Key' );

		/**
		 * Filter the accepted SaaS API keys.
		 *
		 * @since 0.1.0
		 *
		 * @param array $keys Array of valid API key hashes.
		 */
		$valid_keys = apply_filters( 'sa_api_keys', array() );

		// Also check the constant.
		if ( defined( 'SA_SAAS_API_KEY' ) && SA_SAAS_API_KEY ) {
			$valid_keys[] = SA_SAAS_API_KEY;
		}

		if ( empty( $valid_keys ) ) {
			return new WP_Error(
				'sa_not_configured',
				__( 'SaaS API key is not configured.', 'schedule-anything' ),
				array( 'status' => 500 )
			);
		}

		if ( empty( $provided ) || ! in_array( $provided, $valid_keys, true ) ) {
			return new WP_Error(
				'sa_unauthorized',
				__( 'Invalid or missing API key.', 'schedule-anything' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Health check endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public static function healthz( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$data = array(
			'status'           => 'ok',
			'version'          => SA_PLATFORM_VERSION,
			'multisite'        => is_multisite(),
			'blog_count'       => is_multisite() ? get_blog_count() : 1,
			'base_active'      => class_exists( 'WP_MCP_AI_Tool_Registry' ),
			'pro_active'       => defined( 'WP_MCP_AI_PRO_VERSION' ),
			'schedule_manager' => class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ),
			'redis'            => class_exists( 'Redis' ) ? 'available' : 'unavailable',
			'timestamp'        => time(),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Return a WordPress nonce for the React SPA auth flow.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public static function get_nonce( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = get_current_user_id();

		return rest_ensure_response(
			array(
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'user_id'   => $user_id,
				'logged_in' => 0 !== $user_id,
			)
		);
	}

	/**
	 * Provision a new tenant workspace.
	 *
	 * Called by the Cloud Worker after a successful Stripe Checkout.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function provision_tenant( $request ) {
		$tenant_data = array(
			'slug'               => $request->get_param( 'slug' ),
			'tier'               => $request->get_param( 'tier' ),
			'stripe_customer_id' => $request->get_param( 'stripe_customer_id' ),
			'admin_email'        => $request->get_param( 'admin_email' ),
			'admin_name'         => $request->get_param( 'admin_name' ),
			'company_name'       => $request->get_param( 'company_name' ),
		);

		$result = SA_Multisite_Provisioner::provision( $tenant_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'tenant' => $result,
			)
		);
	}

	/**
	 * Offboard a tenant workspace.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function offboard_tenant( $request ) {
		$blog_id = $request->get_param( 'id' );
		$result  = SA_Multisite_Provisioner::offboard( $blog_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'result' => $result,
			)
		);
	}

	/**
	 * Get tenant workspace status.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_tenant_status( $request ) {
		$blog_id = $request->get_param( 'id' );
		$details = get_blog_details( $blog_id );

		if ( ! $details ) {
			return new WP_Error(
				'sa_blog_not_found',
				__( 'Tenant workspace not found.', 'schedule-anything' ),
				array( 'status' => 404 )
			);
		}

		switch_to_blog( $blog_id );
		$meta          = get_option( 'sa_tenant_meta', array() );
		$pending       = get_option( 'sa_pending_deletion', array() );
		$toolkit_count = 0;

		if ( class_exists( 'SA_Toolkit_Manager' ) ) {
			$statuses = SA_Toolkit_Manager::get_all_statuses();
			foreach ( $statuses as $status ) {
				if ( $status['enabled'] ) {
					++$toolkit_count;
				}
			}
		}

		restore_current_blog();

		return rest_ensure_response(
			array(
				'blog_id'          => $blog_id,
				'site_url'         => $details->siteurl,
				'registered'       => $details->registered,
				'tier'             => $meta['tier'] ?? 'unknown',
				'toolkits_enabled' => $toolkit_count,
				'pending_deletion' => ! empty( $pending ),
				'deletion_date'    => $pending['scheduled_at'] ?? null,
			)
		);
	}

	/**
	 * Lookup a tenant by subdomain slug.
	 *
	 * Used by the tenant router as a KV fallback when a tenant
	 * isn't yet in the KV cache (freshly provisioned).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lookup_tenant( $request ) {
		$slug    = sanitize_key( $request->get_param( 'slug' ) );
		$blog_id = get_blog_id_from_url( $slug );

		if ( ! $blog_id ) {
			return new WP_Error(
				'sa_tenant_not_found',
				__( 'No tenant workspace found for this slug.', 'schedule-anything' ),
				array( 'status' => 404 )
			);
		}

		$details = get_blog_details( $blog_id );
		if ( ! $details || $details->deleted || $details->archived ) {
			return new WP_Error(
				'sa_tenant_not_found',
				__( 'Tenant workspace is not available.', 'schedule-anything' ),
				array( 'status' => 404 )
			);
		}

		switch_to_blog( $blog_id );
		$meta = get_option( 'sa_tenant_meta', array() );
		restore_current_blog();

		return rest_ensure_response(
			array(
				'slug'     => $slug,
				'blog_id'  => $blog_id,
				'site_url' => $details->siteurl,
				'tier'     => $meta['tier'] ?? 'starter',
				'status'   => 'active',
			)
		);
	}

	/**
	 * Argument schema for the provision endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 */
	private static function provision_args() {
		return array(
			'slug'               => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tier'               => array(
				'required'          => true,
				'type'              => 'string',
				'enum'              => array( 'starter', 'professional', 'enterprise' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'stripe_customer_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'admin_email'        => array(
				'required'          => true,
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
			),
			'admin_name'         => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'company_name'       => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
