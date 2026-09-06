<?php
/**
 * Tenant-Scoped Options Helper (Wave E4, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Tenant_Options`:
 * byte-identical `wp_mcp_ai_{type}_{id}_{key}` scoped key format and the
 * `wp_mcp_ai_{type}_{key}` type-level variant, the
 * get/update/delete + get_type_option/update_type_option/delete_type_option
 * wrappers with autoload pass-through, and the `from_context()` factory
 * (null when the tenant context cannot be resolved).
 *
 * Documented deviations:
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `from_context()` resolves this package's `TenantContext` (the
 *    ported context singleton — identical resolution semantics).
 *  - Text domain — n/a (no translatable strings in the ported surface).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Tenant
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tenant;

/**
 * Tenant-scoped options helper.
 *
 * Wraps the WordPress Options API so that option keys are automatically
 * prefixed with the current tenant scope.
 *
 * @since 2.1.0
 */
class TenantOptions {

	/**
	 * Key prefix for all tenant-scoped options.
	 *
	 * @var string
	 */
	const PREFIX = 'wp_mcp_ai_';

	/**
	 * Current tenant type.
	 *
	 * @var string
	 */
	private $tenant_type;

	/**
	 * Current tenant ID.
	 *
	 * @var int
	 */
	private $tenant_id;

	/**
	 * Constructor.
	 *
	 * @param string $tenant_type Tenant type.
	 * @param int    $tenant_id   Tenant ID.
	 */
	public function __construct( string $tenant_type, int $tenant_id ) {
		$this->tenant_type = sanitize_key( $tenant_type );
		$this->tenant_id   = absint( $tenant_id );
	}

	/**
	 * Build the full, tenant-scoped option key.
	 *
	 * @param string $key Base option key.
	 * @return string Scoped key, e.g. 'wp_mcp_ai_school_42_enable_feature'.
	 */
	private function scoped_key( string $key ): string {
		return self::PREFIX . $this->tenant_type . '_' . $this->tenant_id . '_' . $key;
	}

	/**
	 * Build a tenant-type-level key (no tenant_id).
	 *
	 * @param string $key Base option key.
	 * @return string Type-level key, e.g. 'wp_mcp_ai_school_enable_feature'.
	 */
	private function type_key( string $key ): string {
		return self::PREFIX . $this->tenant_type . '_' . $key;
	}

	// ─── Tenant-Scoped (per-tenant-id) ───────────────────────────────

	/**
	 * Get a tenant-scoped option.
	 *
	 * @param string $key     Base option key.
	 * @param mixed  $default Default value if option does not exist.
	 * @return mixed
	 */
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Byte-identical base signature; $default is the public named-argument surface.
	public function get( string $key, $default = false ) {
		return get_option( $this->scoped_key( $key ), $default );
	}
	// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames

	/**
	 * Update a tenant-scoped option.
	 *
	 * @param string $key      Base option key.
	 * @param mixed  $value    Option value.
	 * @param bool   $autoload Whether to autoload (default: false).
	 * @return bool
	 */
	public function update( string $key, $value, bool $autoload = false ): bool {
		return update_option( $this->scoped_key( $key ), $value, $autoload );
	}

	/**
	 * Delete a tenant-scoped option.
	 *
	 * @param string $key Base option key.
	 * @return bool
	 */
	public function delete( string $key ): bool {
		return delete_option( $this->scoped_key( $key ) );
	}

	// ─── Tenant-Type-Level (shared across all tenant IDs of a type) ──

	/**
	 * Get a tenant-type-level option.
	 *
	 * @param string $key     Base option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Byte-identical base signature; $default is the public named-argument surface.
	public function get_type_option( string $key, $default = false ) {
		return get_option( $this->type_key( $key ), $default );
	}
	// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames

	/**
	 * Update a tenant-type-level option.
	 *
	 * @param string $key      Base option key.
	 * @param mixed  $value    Option value.
	 * @param bool   $autoload Whether to autoload.
	 * @return bool
	 */
	public function update_type_option( string $key, $value, bool $autoload = false ): bool {
		return update_option( $this->type_key( $key ), $value, $autoload );
	}

	/**
	 * Delete a tenant-type-level option.
	 *
	 * @param string $key Base option key.
	 * @return bool
	 */
	public function delete_type_option( string $key ): bool {
		return delete_option( $this->type_key( $key ) );
	}

	// ─── Static Helpers ──────────────────────────────────────────────

	/**
	 * Create an instance from the Tenant Context singleton.
	 *
	 * @return TenantOptions|null Null if tenant context not resolved.
	 */
	public static function from_context(): ?self {
		$context = TenantContext::instance()->resolve();
		if ( is_wp_error( $context ) ) {
			return null;
		}
		return new self( $context['type'], $context['id'] );
	}
}
