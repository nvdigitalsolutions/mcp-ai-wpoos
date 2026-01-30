<?php
/**
 * Risk Level Constants
 *
 * Defines tool risk levels for safe execution.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
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
	 */
	const RISK_INFO = 'info';

	/**
	 * Standard risk level.
	 *
	 * Normal CRUD operations that modify data.
	 * Requires standard permissions.
	 */
	const RISK_STANDARD = 'standard';

	/**
	 * Destructive risk level.
	 *
	 * Operations that delete or permanently modify data.
	 * Requires elevated permissions and confirmation.
	 */
	const RISK_DESTRUCTIVE = 'destructive';

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
			self::RISK_INFO        => __( 'Read-only, no modifications', 'mcp-ai-wpoos' ),
			self::RISK_STANDARD    => __( 'Normal CRUD operations', 'mcp-ai-wpoos' ),
			self::RISK_DESTRUCTIVE => __( 'Destructive operations, use with caution', 'mcp-ai-wpoos' ),
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
			self::RISK_INFO        => '#28a745', // Green.
			self::RISK_STANDARD    => '#ffc107', // Yellow.
			self::RISK_DESTRUCTIVE => '#dc3545', // Red.
		);

		return isset( $colors[ $level ] ) ? $colors[ $level ] : null;
	}
}
