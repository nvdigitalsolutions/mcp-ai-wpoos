<?php
/**
 * Tests the WP_MCP_AI_Feature_Flags availability map — the server-side
 * source of truth for surface gating (Proposal 084 Phase 0).
 *
 * Covers: the default map shape, boolean typing, and the per-feature
 * override filters.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Feature_Flags_Test extends WP_UnitTestCase {

	/**
	 * Drop any filters installed by individual tests.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_feature_available_docs_hub' );
		remove_all_filters( 'wp_mcp_ai_feature_available_knowledge_graph' );
		remove_all_filters( 'wp_mcp_ai_feature_available_media_studio' );
		remove_all_filters( 'wp_mcp_ai_feature_available_document_editor' );
		remove_all_filters( 'wp_mcp_ai_feature_available_pro' );
		remove_all_filters( 'wp_mcp_ai_feature_available_okf' );
		parent::tear_down();
	}

	/**
	 * The map exposes every recognised key as a boolean.
	 */
	public function test_all_returns_boolean_map() {
		$map = WP_MCP_AI_Feature_Flags::all();

		$this->assertIsArray( $map );
		foreach ( array( 'docs_hub', 'knowledge_graph', 'media_studio', 'document_editor', 'pro', 'okf' ) as $key ) {
			$this->assertArrayHasKey( $key, $map, "Feature map must expose {$key}." );
			$this->assertIsBool( $map[ $key ], "Feature {$key} must be a boolean." );
		}
	}

	/**
	 * In the bare unit-test environment no optional addon is loaded, so the
	 * addon-backed flags default to false.
	 */
	public function test_addon_backed_flags_default_false_without_addons() {
		$this->assertFalse( WP_MCP_AI_Feature_Flags::docs_hub() );
		$this->assertFalse( WP_MCP_AI_Feature_Flags::knowledge_graph() );
		$this->assertFalse( WP_MCP_AI_Feature_Flags::media_studio() );
		$this->assertFalse( WP_MCP_AI_Feature_Flags::document_editor() );
	}

	/**
	 * The per-feature filter lets a deployment force a surface on.
	 */
	public function test_filter_overrides_flag() {
		add_filter( 'wp_mcp_ai_feature_available_docs_hub', '__return_true' );
		$this->assertTrue( WP_MCP_AI_Feature_Flags::docs_hub() );
		$this->assertTrue( WP_MCP_AI_Feature_Flags::all()['docs_hub'] );
	}

	/**
	 * The filter can also force a surface off.
	 */
	public function test_filter_can_force_off() {
		add_filter( 'wp_mcp_ai_feature_available_docs_hub', '__return_false' );
		$this->assertFalse( WP_MCP_AI_Feature_Flags::docs_hub() );
	}
}
