<?php
/**
 * includes/healthcare-imaging-toolkit-init.php (ecosystem port — Wave F4, healthcare toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/healthcare-imaging-toolkit-init.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path
 * (the `defined( 'WP_MCP_AI_PRO_VERSION' )` base-mode gates stay byte-identical).
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

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/init.php';
