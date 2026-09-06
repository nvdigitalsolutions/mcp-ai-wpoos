<?php
/**
 * Tenant Repository — Abstract Base Class (Wave E4, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Tenant_Repository`:
 * byte-identical tenant context binding (`set_tenant_context()` with
 * sanitize_key/absint), the strict-mode flag, the prepared
 * `tenant_where()` SQL fragment with the `1=1` bypass, the
 * `require_tenant()` fail-closed RuntimeException, the
 * `tenant_meta_query()` WP_Query clause, the `save_tenant_meta()` post
 * stamping helper, and the type/ID accessors.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Tenant
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tenant;

/**
 * Abstract tenant repository base class.
 *
 * Every data-access class that deals with tenant-scoped data MUST extend
 * this class. The class defaults to a "bypass" mode (tenant_id = 0) so
 * that existing code continues to work until the feature flag is enabled
 * per-toolkit.
 *
 * @since 2.1.0
 */
abstract class TenantRepository {

	/**
	 * Current tenant type (e.g. 'school', 'company', 'site').
	 *
	 * @var string
	 */
	protected $tenant_type = '';

	/**
	 * Current tenant ID.
	 *
	 * A value of 0 means "global / unscoped" and acts as a bypass for
	 * backward compatibility during the migration window.
	 *
	 * @var int
	 */
	protected $tenant_id = 0;

	/**
	 * Whether strict mode is enabled (rejects tenant_id = 0).
	 *
	 * @var bool
	 */
	protected $strict = false;

	/**
	 * Set the tenant context for this repository instance.
	 *
	 * Called once per request lifecycle, typically by the Tenant Context
	 * Manager or by tool execute() methods.
	 *
	 * @param string $type Tenant type (e.g. 'school', 'company').
	 * @param int    $id   Tenant ID. Use 0 to bypass (backward-compat).
	 * @return void
	 */
	public function set_tenant_context( string $type, int $id ): void {
		$this->tenant_type = sanitize_key( $type );
		$this->tenant_id   = absint( $id );
	}

	/**
	 * Enable strict mode — reject queries when tenant_id is 0.
	 *
	 * @param bool $strict True to enable strict mode.
	 * @return void
	 */
	public function set_strict( bool $strict ): void {
		$this->strict = $strict;
	}

	/**
	 * Build a tenant-scoped, prepared SQL WHERE clause fragment.
	 *
	 * Returns a string suitable for appending to a query:
	 *   "tenant_type = 'school' AND tenant_id = 42"
	 *
	 * When tenant_id is 0 and strict mode is off, returns '1=1' (no-op).
	 *
	 * @return string Prepared SQL fragment.
	 */
	protected function tenant_where(): string {
		global $wpdb;

		if ( 0 === $this->tenant_id && ! $this->strict ) {
			return '1=1';
		}

		return $wpdb->prepare(
			'tenant_type = %s AND tenant_id = %d',
			$this->tenant_type,
			$this->tenant_id
		);
	}

	/**
	 * Assert that a valid tenant context is set.
	 *
	 * In non-strict mode this is a no-op when tenant_id = 0 (bypass).
	 * In strict mode it throws if no valid tenant is active.
	 *
	 * @return void
	 * @throws \RuntimeException When strict mode is on and no tenant context exists.
	 */
	protected function require_tenant(): void {
		if ( $this->strict && ( empty( $this->tenant_type ) || $this->tenant_id <= 0 ) ) {
			throw new \RuntimeException(
				esc_html__( 'Tenant context not set. Call set_tenant_context() before executing queries.', 'nvoos-content-graph-ai-platform' )
			);
		}
	}

	/**
	 * Get the current tenant type.
	 *
	 * @return string
	 */
	public function get_tenant_type(): string {
		return $this->tenant_type;
	}

	/**
	 * Get the current tenant ID.
	 *
	 * @return int 0 means unscoped / bypass.
	 */
	public function get_tenant_id(): int {
		return $this->tenant_id;
	}

	/**
	 * Build a tenant-aware meta query clause for WP_Query / get_posts.
	 *
	 * Adds a meta_query entry that filters by _tenant_type and _tenant_id
	 * post meta. In bypass mode (tenant_id = 0) the clause is a no-op.
	 *
	 * @return array<string, mixed> Meta query clause, or empty array if bypass.
	 */
	protected function tenant_meta_query(): array {
		if ( 0 === $this->tenant_id && ! $this->strict ) {
			return array();
		}

		return array(
			'relation' => 'AND',
			array(
				'key'     => '_tenant_id',
				'value'   => $this->tenant_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => '_tenant_type',
				'value'   => $this->tenant_type,
				'compare' => '=',
			),
		);
	}

	/**
	 * Inject tenant meta into post data before insert/update.
	 *
	 * Call this in save_post handlers for tenant-scoped CPTs.
	 *
	 * @param int $post_id Post ID to update.
	 * @return void
	 */
	protected function save_tenant_meta( int $post_id ): void {
		if ( $this->tenant_type ) {
			update_post_meta( $post_id, '_tenant_type', $this->tenant_type );
		}
		if ( $this->tenant_id > 0 ) {
			update_post_meta( $post_id, '_tenant_id', $this->tenant_id );
		}
	}
}
