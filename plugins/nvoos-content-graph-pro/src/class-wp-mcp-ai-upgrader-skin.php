<?php
/**
 * Upgrader skin (D8-compat copy — ecosystem port, Wave F2 site-creator data layer).
 *
 * Base-owned in `includes/class-wp-mcp-ai-upgrader-skin.php` (classmap-served monolith), so the
 * standalone addon serves this byte-identical copy from `src/` (the site-creator install tools
 * need it at execute time).
 *
 * Documented deviations: `declare(strict_types=1)` added.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
}

/**
 * Silent Upgrader Skin
 *
 * Provides a silent upgrader skin that suppresses output during
 * plugin and theme installations performed by AI tools.
 */
class WP_MCP_AI_Upgrader_Skin extends WP_Upgrader_Skin {
	/**
	 * Override feedback to suppress output.
	 *
	 * @param string $string Feedback message.
	 * @param mixed  ...$args Optional arguments.
	 */
	public function feedback( $string, ...$args ) {
		// Suppress output.
	}

	/**
	 * Override header to suppress output.
	 */
	public function header() {
		// Suppress output.
	}

	/**
	 * Override footer to suppress output.
	 */
	public function footer() {
		// Suppress output.
	}

	/**
	 * Override error to suppress output.
	 *
	 * @param string|WP_Error $errors Error message or WP_Error object.
	 */
	public function error( $errors ) {
		// Suppress output.
	}
}
