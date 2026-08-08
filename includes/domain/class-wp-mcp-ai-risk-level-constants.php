<?php
/**
 * Risk Level Constants
 *
 * Defines tool risk levels for safe execution.
 *
 * Domain layer — pure PHP, no WordPress or infrastructure dependencies.
 *
 * @package WP_MCP_AI
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Risk Level Constants class
 *
 * Defines the 3 risk levels for tool categorization.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Risk_Level_Constants {
	/**
	 * Info risk level.
	 *
	 * Read-only operations that cannot modify data.
	 * Safe for unrestricted use.
	 *
	 * @since 1.1.0
	 */
	const RISK_INFO = 'info';

	/**
	 * Standard risk level.
	 *
	 * Normal CRUD operations that modify data.
	 * Requires standard permissions.
	 *
	 * @since 1.1.0
	 */
	const RISK_STANDARD = 'standard';

	/**
	 * Destructive risk level.
	 *
	 * Operations that delete or permanently modify data.
	 * Requires elevated permissions and confirmation.
	 *
	 * @since 1.1.0
	 */
	const RISK_DESTRUCTIVE = 'destructive';

	/**
	 * Irreversible risk level.
	 *
	 * Operations that CANNOT be undone once executed: sending emails
	 * to customers, processing payments, permanently deleting data,
	 * revoking access, or taking legal actions.
	 *
	 * Requires the highest level of gating — human-in-the-loop
	 * approval is mandatory by default.
	 *
	 * @since 1.9.0
	 */
	const RISK_IRREVERSIBLE = 'irreversible';

	/**
	 * Get all risk level constants
	 *
	 * @return array Array of risk level slugs.
	 */
	public static function get_all_risk_levels() {
		return array(
			self::RISK_INFO,
			self::RISK_STANDARD,
			self::RISK_DESTRUCTIVE,
			self::RISK_IRREVERSIBLE,
		);
	}

	/**
	 * Check if a risk level is valid
	 *
	 * @param string $level Risk level slug to check.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_risk_level( $level ) {
		return in_array( $level, self::get_all_risk_levels(), true );
	}

	/**
	 * Get risk level description
	 *
	 * @param string $level Risk level slug.
	 * @return string|null Risk level description or null if not found.
	 */
	public static function get_risk_level_description( $level ) {
		$descriptions = array(
			self::RISK_INFO         => __( 'Read-only, no modifications', 'mcp-ai-wpoos' ),
			self::RISK_STANDARD     => __( 'Normal CRUD operations', 'mcp-ai-wpoos' ),
			self::RISK_DESTRUCTIVE  => __( 'Destructive operations, use with caution', 'mcp-ai-wpoos' ),
			self::RISK_IRREVERSIBLE => __( 'Irreversible — cannot be undone, requires human approval', 'mcp-ai-wpoos' ),
		);

		return isset( $descriptions[ $level ] ) ? $descriptions[ $level ] : null;
	}

	/**
	 * Get risk level color for UI
	 *
	 * @param string $level Risk level slug.
	 * @return string|null Hex color code or null if not found.
	 */
	public static function get_risk_level_color( $level ) {
		$colors = array(
			self::RISK_INFO         => '#28a745', // Green.
			self::RISK_STANDARD     => '#ffc107', // Yellow.
			self::RISK_DESTRUCTIVE  => '#dc3545', // Red.
			self::RISK_IRREVERSIBLE => '#6f42c1', // Purple — most severe.
		);

		return isset( $colors[ $level ] ) ? $colors[ $level ] : null;
	}
}
