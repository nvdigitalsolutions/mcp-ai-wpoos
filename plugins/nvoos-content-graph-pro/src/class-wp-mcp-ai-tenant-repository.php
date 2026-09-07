<?php
/**
 * Standalone bridge for the base plugin's tenant repository.
 *
 * The ported toolkit data stores hard-extend `WP_MCP_AI_Tenant_Repository`
 * (byte-identical class declarations). In monolith installs the base plugin
 * provides that class from `includes/tenant/class-wp-mcp-ai-tenant-repository.php`;
 * in standalone installs the Platform addon owns the ported implementation
 * (`NvoosContentGraphAiPlatform\Tenant\TenantRepository`, extraction Wave E4 —
 * byte-compatible surface: `set_tenant_context()`, `set_strict()`,
 * `tenant_where()`, `require_tenant()`, `get_tenant_type()`,
 * `get_tenant_id()`, `tenant_meta_query()`, `save_tenant_meta()` plus the
 * protected `$tenant_type`/`$tenant_id`/`$strict` state).
 *
 * This bridge keeps the global class name available standalone so the stores
 * load without modification. Documented deviation: new class, no monolith
 * counterpart — the base plugin owns the real class monolith.
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tenant_Repository' ) ) {
	/**
	 * Standalone alias of the Platform addon's tenant repository.
	 */
	abstract class WP_MCP_AI_Tenant_Repository extends \NvoosContentGraphAiPlatform\Tenant\TenantRepository {}
}
