<?php
/**
 * Tenant Context Manager
 *
 * Singleton that resolves the current request's tenant context from multiple
 * sources in priority order. All tenant-scoped operations read from this
 * singleton to determine which tenant's data they may access.
 *
 * Resolution order:
 *   1. Explicit REST API header:  X-WP-MCP-AI-Tenant: school:42
 *   2. Logged-in user's primary tenant meta: _wp_mcp_ai_tenant
 *   3. Assistant's bound tenant (assistant post meta)
 *   4. Multisite blog ID (fallback for multisite installs)
 *
 * Returns WP_Error with code 'tenant_not_resolved' if none match (fail-closed).
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenant Context Manager class.
 */
class WP_MCP_AI_Tenant_Context {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Tenant_Context|null
	 */
	private static $instance = null;

	/**
	 * Current tenant type (e.g. 'school', 'teacher', 'student', 'eca', 'company').
	 *
	 * @var string
	 */
	private $tenant_type = '';

	/**
	 * Current tenant ID.
	 *
	 * @var int
	 */
	private $tenant_id = 0;

	/**
	 * Whether the tenant context has been resolved.
	 *
	 * @var bool
	 */
	private $resolved = false;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Tenant_Context
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (useful in tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Resolve the current tenant context.
	 *
	 * Tries each resolution source in priority order.  Once resolved the
	 * context is cached for the remainder of the request.
	 *
	 * @return array{type: string, id: int}|WP_Error Tenant info or error.
	 */
	public function resolve() {
		if ( $this->resolved ) {
			if ( empty( $this->tenant_type ) || $this->tenant_id <= 0 ) {
				return new WP_Error(
					'tenant_not_resolved',
					__( 'Could not determine tenant context for this request.', 'mcp-ai-wpoos' )
				);
			}
			return array(
				'type' => $this->tenant_type,
				'id'   => $this->tenant_id,
			);
		}

		// 1. Explicit REST API / HTTP header.
		$result = $this->resolve_from_header();
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// 2. Logged-in user's primary tenant.
		$result = $this->resolve_from_user();
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// 3. Assistant context (post meta on the assistant CPT).
		$result = $this->resolve_from_assistant();
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// 4. Multisite blog ID.
		$result = $this->resolve_from_multisite();
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// No source matched — fail closed.
		$this->resolved = true;
		return new WP_Error(
			'tenant_not_resolved',
			__( 'Could not determine tenant context for this request.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Try to resolve tenant from HTTP header.
	 *
	 * Expected format: X-WP-MCP-AI-Tenant: school:42
	 *
	 * @return array{type: string, id: int}|WP_Error
	 */
	private function resolve_from_header() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Header-based auth; nonce not applicable.
		$header = isset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] ) )
			: '';

		if ( empty( $header ) ) {
			return new WP_Error( 'tenant_no_header', '' );
		}

		$parts = explode( ':', $header, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error(
				'tenant_invalid_header',
				sprintf(
					/* translators: %s: header value received */
					__( 'Invalid tenant header format. Expected "type:id", got "%s".', 'mcp-ai-wpoos' ),
					$header
				)
			);
		}

		$type = sanitize_key( trim( $parts[0] ) );
		$id   = absint( trim( $parts[1] ) );

		if ( empty( $type ) || $id <= 0 ) {
			return new WP_Error(
				'tenant_invalid_header_values',
				__( 'Tenant header must contain a non-empty type and a positive integer ID.', 'mcp-ai-wpoos' )
			);
		}

		return $this->set( $type, $id );
	}

	/**
	 * Try to resolve tenant from the current WordPress user.
	 *
	 * @return array{type: string, id: int}|WP_Error
	 */
	private function resolve_from_user() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'tenant_no_user', '' );
		}

		$tenant = get_user_meta( $user_id, '_wp_mcp_ai_tenant', true );
		if ( empty( $tenant ) || ! is_array( $tenant ) ) {
			return new WP_Error( 'tenant_no_user_meta', '' );
		}

		$type = isset( $tenant['type'] ) ? sanitize_key( $tenant['type'] ) : '';
		$id   = isset( $tenant['id'] ) ? absint( $tenant['id'] ) : 0;

		if ( empty( $type ) || $id <= 0 ) {
			return new WP_Error( 'tenant_invalid_user_meta', '' );
		}

		return $this->set( $type, $id );
	}

	/**
	 * Try to resolve tenant from the assistant bound to the current request.
	 *
	 * The assistant post type (mcp_ai_assistant) may have a _wp_mcp_ai_bound_tenant
	 * post meta entry identifying which tenant it serves.
	 *
	 * @return array{type: string, id: int}|WP_Error
	 */
	private function resolve_from_assistant() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query var for context resolution.
		$assistant_id = isset( $_REQUEST['assistant_id'] )
			? absint( wp_unslash( $_REQUEST['assistant_id'] ) )
			: 0;

		if ( ! $assistant_id ) {
			return new WP_Error( 'tenant_no_assistant', '' );
		}

		$tenant = get_post_meta( $assistant_id, '_wp_mcp_ai_bound_tenant', true );
		if ( empty( $tenant ) || ! is_array( $tenant ) ) {
			return new WP_Error( 'tenant_no_assistant_meta', '' );
		}

		$type = isset( $tenant['type'] ) ? sanitize_key( $tenant['type'] ) : '';
		$id   = isset( $tenant['id'] ) ? absint( $tenant['id'] ) : 0;

		if ( empty( $type ) || $id <= 0 ) {
			return new WP_Error( 'tenant_invalid_assistant_meta', '' );
		}

		return $this->set( $type, $id );
	}

	/**
	 * Try to resolve tenant from multisite blog ID.
	 *
	 * On multisite installs the blog ID may serve as a natural tenant boundary.
	 *
	 * @return array{type: string, id: int}|WP_Error
	 */
	private function resolve_from_multisite() {
		if ( ! is_multisite() ) {
			return new WP_Error( 'tenant_not_multisite', '' );
		}

		return $this->set( 'site', get_current_blog_id() );
	}

	/**
	 * Set the tenant context and mark as resolved.
	 *
	 * @param string $type Tenant type.
	 * @param int    $id   Tenant ID.
	 * @return array{type: string, id: int}
	 */
	public function set( string $type, int $id ): array {
		$this->tenant_type = $type;
		$this->tenant_id   = $id;
		$this->resolved    = true;

		return array(
			'type' => $this->tenant_type,
			'id'   => $this->tenant_id,
		);
	}

	/**
	 * Get the current tenant type.
	 *
	 * @return string Empty string if not resolved.
	 */
	public function get_type(): string {
		return $this->tenant_type;
	}

	/**
	 * Get the current tenant ID.
	 *
	 * @return int 0 if not resolved.
	 */
	public function get_id(): int {
		return $this->tenant_id;
	}

	/**
	 * Whether the tenant context has been resolved.
	 *
	 * @return bool
	 */
	public function is_resolved(): bool {
		return $this->resolved && ! empty( $this->tenant_type ) && $this->tenant_id > 0;
	}
}
