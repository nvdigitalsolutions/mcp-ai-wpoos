<?php
/**
 * Media Studio stub class for integration-path tests.
 *
 * Minimal stand-in for the Media Studio addon so the Pro SPA runtime config
 * tests can exercise the island endpoint gating (Proposal 087 Phase 3)
 * without the real addon being loaded. Guarded by class_exists() so
 * requiring this file is always safe.
 *
 * This file is required lazily from inside test methods — never from a test
 * class body — so the "addon inactive" code paths can be asserted before the
 * stub exists.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

if ( ! class_exists( 'NV_oOS_Media_Studio_Shortcode' ) ) {
	// When the real addon ships in the tree (monorepo full-suite runs), always
	// load the real class so later media-studio suites never see an empty stub
	// that would collide with the real unguarded declaration.
	$real_class_file = dirname( __DIR__, 2 ) . '/media-studio/includes/shortcode/class-nvoos-media-studio-shortcode.php';
	if ( file_exists( $real_class_file ) ) {
		require_once $real_class_file;
	}
}

if ( ! class_exists( 'NV_oOS_Media_Studio_Shortcode' ) ) {
	/**
	 * Stub Media Studio shortcode class (the feature flag gate).
	 */
	class NV_oOS_Media_Studio_Shortcode {}
}
