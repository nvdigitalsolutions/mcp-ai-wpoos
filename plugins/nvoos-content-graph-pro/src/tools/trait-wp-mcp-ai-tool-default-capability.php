<?php
/**
 * WP_MCP_AI_Tool_Default_Capability (ecosystem port — Wave F4, healthcare
 * interop + OpenMed batch).
 *
 * Ported from the base plugin's `includes/tools/trait-wp-mcp-ai-tool-default-capability.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. Base-owned in `includes/tools/`
 * (classmap-excluded), so the addon serves its own copy standalone — the monolith serves the base
 * copy.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`. Note: the monorepo test matrices load the base
 * copy through the base interface's eager require (the classmap serves the
 * base interface), so the consuming tool files carry a matrix-aware seam that
 * loads the base copy in those matrices — this copy serves real standalone
 * installs only.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a default get_required_capability() for tool classes.
 */
trait WP_MCP_AI_Tool_Default_Capability {

	/**
	 * WordPress capability required to execute this tool.
	 *
	 * Looks up the tool slug in WP_MCP_AI_Tool_Capability_Map when available,
	 * falling back to 'edit_posts' if no mapping is found.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		$slug = $this->get_slug();

		if ( class_exists( 'WP_MCP_AI_Tool_Capability_Map' ) ) {
			$cap = WP_MCP_AI_Tool_Capability_Map::get_capability( $slug );
			if ( $cap ) {
				return $cap;
			}
		}

		return 'edit_posts';
	}
}
