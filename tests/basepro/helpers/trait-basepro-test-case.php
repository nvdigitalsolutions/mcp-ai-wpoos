<?php
/**
 * Shared guard + helpers for the base+pro regression suite.
 *
 * The base+pro matrix (phpunit-basepro.xml.dist) boots with
 * WP_MCP_AI_BASE_VERSION=true while the Pro addon stays loaded. Every test
 * in tests/basepro/ guards on that shape so an accidental inclusion in the
 * monolith suite degrades to a skip instead of a false failure.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName -- Helper trait, not a test class.

/**
 * Base+pro matrix guard + toolkit seeding helpers.
 */
trait WP_MCP_AI_BasePro_Test_Case {

	/**
	 * Skip the test unless this process boots the base+pro shape.
	 *
	 * @return void
	 */
	protected function wp_mcp_ai_basepro_require_matrix(): void {
		if ( ! wp_mcp_ai_is_base_version() || ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Requires the base+pro matrix (phpunit-basepro.xml.dist).' );
		}
	}

	/**
	 * Enable the given toolkit toggles in wp_mcp_ai_settings.
	 *
	 * @param string[] $keys Settings keys (e.g. enable_crm_toolkit).
	 * @return void
	 */
	protected function wp_mcp_ai_basepro_enable_toolkits( array $keys ): void {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		foreach ( $keys as $key ) {
			$settings[ $key ] = 1;
		}
		update_option( 'wp_mcp_ai_settings', $settings );
	}
}
