<?php
/**
 * Document Editor stub class for integration-path tests.
 *
 * Minimal stand-in for the Document Editor addon so the Pro SPA runtime
 * config tests can exercise the island endpoint gating (Proposal 087
 * Phase 3) without the real addon being loaded. Guarded by class_exists()
 * so requiring this file is always safe.
 *
 * This file is required lazily from inside test methods — never from a test
 * class body — so the "addon inactive" code paths can be asserted before the
 * stub exists.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

if ( ! class_exists( 'NV_oOS_Document_Editor_Shortcode' ) ) {
	/**
	 * Stub Document Editor shortcode class (the feature flag gate).
	 */
	class NV_oOS_Document_Editor_Shortcode {}
}
