<?php
/**
 * eca/init.php (ecosystem port — Wave F5, eca-management data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/eca/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the byte-identical `defined( 'WP_MCP_AI_PRO_VERSION' )`
 * pro-active checks stay as-is — defined-const checks, standalone-safe).
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

require_once __DIR__ . '/class-wp-mcp-ai-eca-enrollments-db.php';
require_once __DIR__ . '/class-wp-mcp-ai-eca-attendance-db.php';

WP_MCP_AI_ECA_Enrollments_DB::init();
WP_MCP_AI_ECA_Attendance_DB::init();
