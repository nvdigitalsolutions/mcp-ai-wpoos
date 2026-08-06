<?php
/**
 * Pro License Export Provider.
 *
 * Exports and imports the Pro addon license key and status
 * for migration between sites.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports/imports Pro license key, status, expiry, and plan.
 *
 * License data is optional — licenses can be re-activated on the
 * new site. The provider is available only when the Pro addon is active.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_License extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Option names that comprise the Pro license data.
	 *
	 * @since 1.2.0
	 * @var   array<int, string>
	 */
	const LICENSE_OPTIONS = array(
		'wp_mcp_ai_pro_license_key',
		'wp_mcp_ai_pro_license_status',
		'wp_mcp_ai_pro_license_expires',
		'wp_mcp_ai_pro_plan',
	);

	/**
	 * Unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'pro_license';
	}

	/**
	 * Human-readable label for the UI checkbox.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Pro License', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description shown beneath the checkbox in the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'Pro addon license key and status. Optional — licenses can be re-activated on the new site.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Requires the Pro addon to be active.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return defined( 'WP_MCP_AI_PRO_VERSION' );
	}

	/**
	 * Whether the exported data contains sensitive values.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return true;
	}

	/**
	 * Approximate count of items for the UI badge.
	 *
	 * Always returns 4 — the fixed number of license option fields.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		return 4;
	}

	/**
	 * Export the Pro license data.
	 *
	 * @since 1.2.0
	 *
	 * @return array Associative array keyed by option name.
	 */
	public function export(): array {
		$data = array();

		foreach ( self::LICENSE_OPTIONS as $option_name ) {
			$data[ $option_name ] = $this->get_option_safe( $option_name, '' );
		}

		return $data;
	}

	/**
	 * Validate imported license data before committing.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return true;
		}

		// At least one known license key must be present.
		$has_known_key = false;

		foreach ( self::LICENSE_OPTIONS as $option_name ) {
			if ( array_key_exists( $option_name, $data ) ) {
				$has_known_key = true;
				break;
			}
		}

		if ( ! $has_known_key ) {
			return new WP_Error(
				'wp_mcp_ai_pro_export_invalid_license',
				__( 'License import data does not contain any recognized license fields.', 'mcp-ai-wpoos-pro' )
			);
		}

		return true;
	}

	/**
	 * Import the Pro license data, updating each of the four
	 * license-related options.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data ) {
		if ( empty( $data ) ) {
			return true;
		}

		foreach ( $data as $option_name => $value ) {
			if ( ! in_array( $option_name, self::LICENSE_OPTIONS, true ) ) {
				continue;
			}

			// Cast to string for safe storage.
			$sanitized = is_scalar( $value ) ? (string) $value : '';
			update_option( $option_name, $sanitized, false );
		}

		$this->log_action( 'imported', 'success' );

		return true;
	}
}
