<?php
/**
 * Healthcare Imaging Toolkit Initialization — Backwards-Compatibility Shim
 *
 * The imaging toolkit boot logic moved into the unified healthcare toolkit
 * bootstrap at `addons/pro/includes/healthcare-toolkit-init.php` together
 * with shared infrastructure (engine, codes, FHIR builders, audit ledger,
 * capability map) and the per-sub-toolkit init files.
 *
 * This file is preserved so any partner code that still does
 * `require_once …/healthcare-imaging-toolkit-init.php;` keeps working.  It
 * forwards to the unified bootstrap which is idempotent (it uses
 * `require_once`/option-gated loads internally).
 *
 * Scheduled for removal two minor versions after 1.3.0; partners should
 * migrate to depending on `healthcare-toolkit-init.php` (or simply on the
 * Pro plugin being active).
 *
 * @package    WP_MCP_AI_Pro
 * @since      1.3.0
 * @deprecated 1.3.0 Use `healthcare-toolkit-init.php` instead.
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/healthcare/init.php';
