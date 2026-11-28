<?php
/**
 * Default limits for the database toolkit.
 *
 * Proposal 167. The four numbers a scope starts from (rows, payload bytes,
 * statement timeout, per-cell bytes) come from the toolkit's manifest settings —
 * so "default" means the same thing on the admin page and in the console.
 *
 * This lives outside the admin page because the console's REST route also reads
 * it, and a front-end REST request never loads `includes/admin/`. Promoted from
 * `WP_MCP_AI_Pro_Database_Settings_Page::limit_defaults()` for exactly that
 * reason: one gatherer, two surfaces, no second copy of the fallbacks.
 *
 * @package WP_MCP_AI
 * @since   3.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Db_Limit_Defaults' ) ) {
	/**
	 * Site-wide limit defaults, read from the toolkit's settings.
	 */
	final class WP_MCP_AI_Db_Limit_Defaults {

		/**
		 * Fallbacks, matching the manifest's declared defaults.
		 *
		 * Kept here rather than in two call sites: a missing setting must mean
		 * the manifest's number on every surface.
		 *
		 * @var array<string, int>
		 */
		const FALLBACKS = array(
			'max_rows'          => 500,
			'max_bytes'         => 2097152,
			'timeout_ms'        => 5000,
			'cell_bytes'        => 16384,
			'max_affected_rows' => 0,
		);

		/**
		 * The effective defaults.
		 *
		 * A scope may lower any of these; the executor merges the scope's
		 * overrides over these values.
		 *
		 * @return array<string, int>
		 */
		public static function get(): array {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();

			return array(
				'max_rows'          => max( 1, (int) ( $settings['database_default_max_rows'] ?? self::FALLBACKS['max_rows'] ) ),
				'max_bytes'         => max( 1, (int) ( $settings['database_default_max_bytes'] ?? self::FALLBACKS['max_bytes'] ) ),
				'timeout_ms'        => max( 1, (int) ( $settings['database_default_timeout_ms'] ?? self::FALLBACKS['timeout_ms'] ) ),
				'cell_bytes'        => max( 1, (int) ( $settings['database_default_cell_bytes'] ?? self::FALLBACKS['cell_bytes'] ) ),
				'max_affected_rows' => self::FALLBACKS['max_affected_rows'],
			);
		}
	}
}
