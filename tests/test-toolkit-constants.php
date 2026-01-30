<?php
/**
 * Tests for Toolkit Constants.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Toolkit Constants.
 */
class WP_MCP_AI_Toolkit_Constants_Test extends WP_UnitTestCase {

	/**
	 * Test that all 12 toolkit constants are defined.
	 */
	public function test_all_toolkits_defined() {
		$toolkits = WP_MCP_AI_Toolkit_Constants::get_all_toolkits();

		$this->assertCount( 12, $toolkits, 'Should have exactly 12 toolkits' );

		$expected_toolkits = array(
			'content_publishing',
			'media_processing',
			'data_analytics',
			'ecommerce_business',
			'developer_technical',
			'security_compliance',
			'research_discovery',
			'geospatial_location',
			'workflow_automation',
			'communication_outreach',
			'integration_external',
			'ai_model_management',
		);

		foreach ( $expected_toolkits as $toolkit ) {
			$this->assertContains( $toolkit, $toolkits, "Toolkit '{$toolkit}' should be defined" );
		}
	}

	/**
	 * Test toolkit validation.
	 */
	public function test_toolkit_validation() {
		$this->assertTrue( WP_MCP_AI_Toolkit_Constants::is_valid_toolkit( 'content_publishing' ) );
		$this->assertTrue( WP_MCP_AI_Toolkit_Constants::is_valid_toolkit( 'ai_model_management' ) );
		$this->assertFalse( WP_MCP_AI_Toolkit_Constants::is_valid_toolkit( 'invalid_toolkit' ) );
		$this->assertFalse( WP_MCP_AI_Toolkit_Constants::is_valid_toolkit( '' ) );
	}

	/**
	 * Test that all 8 pattern constants are defined.
	 */
	public function test_all_patterns_defined() {
		$patterns = WP_MCP_AI_Pattern_Constants::get_all_patterns();

		$this->assertCount( 8, $patterns, 'Should have exactly 8 patterns' );

		$expected_patterns = array(
			'orchestrator',
			'sequential',
			'peer_to_peer',
			'skill_router',
			'layered_defense',
			'event_driven',
			'hierarchical',
			'experimentation',
		);

		foreach ( $expected_patterns as $pattern ) {
			$this->assertContains( $pattern, $patterns, "Pattern '{$pattern}' should be defined" );
		}
	}

	/**
	 * Test pattern validation.
	 */
	public function test_pattern_validation() {
		$this->assertTrue( WP_MCP_AI_Pattern_Constants::is_valid_pattern( 'orchestrator' ) );
		$this->assertTrue( WP_MCP_AI_Pattern_Constants::is_valid_pattern( 'experimentation' ) );
		$this->assertFalse( WP_MCP_AI_Pattern_Constants::is_valid_pattern( 'invalid_pattern' ) );
		$this->assertFalse( WP_MCP_AI_Pattern_Constants::is_valid_pattern( '' ) );
	}

	/**
	 * Test pattern descriptions.
	 */
	public function test_pattern_descriptions() {
		$description = WP_MCP_AI_Pattern_Constants::get_pattern_description( 'orchestrator' );
		$this->assertNotEmpty( $description, 'Orchestrator pattern should have a description' );

		$description = WP_MCP_AI_Pattern_Constants::get_pattern_description( 'invalid' );
		$this->assertEmpty( $description, 'Invalid pattern should return empty description' );
	}

	/**
	 * Test that all 3 risk level constants are defined.
	 */
	public function test_all_risk_levels_defined() {
		$risk_levels = WP_MCP_AI_Risk_Level_Constants::get_all_risk_levels();

		$this->assertCount( 3, $risk_levels, 'Should have exactly 3 risk levels' );

		$expected_levels = array( 'info', 'standard', 'destructive' );

		foreach ( $expected_levels as $level ) {
			$this->assertContains( $level, $risk_levels, "Risk level '{$level}' should be defined" );
		}
	}

	/**
	 * Test risk level validation.
	 */
	public function test_risk_level_validation() {
		$this->assertTrue( WP_MCP_AI_Risk_Level_Constants::is_valid_risk_level( 'info' ) );
		$this->assertTrue( WP_MCP_AI_Risk_Level_Constants::is_valid_risk_level( 'standard' ) );
		$this->assertTrue( WP_MCP_AI_Risk_Level_Constants::is_valid_risk_level( 'destructive' ) );
		$this->assertFalse( WP_MCP_AI_Risk_Level_Constants::is_valid_risk_level( 'invalid' ) );
		$this->assertFalse( WP_MCP_AI_Risk_Level_Constants::is_valid_risk_level( '' ) );
	}

	/**
	 * Test risk level descriptions.
	 */
	public function test_risk_level_descriptions() {
		$description = WP_MCP_AI_Risk_Level_Constants::get_risk_level_description( 'info' );
		$this->assertNotEmpty( $description, 'Info risk level should have a description' );

		$description = WP_MCP_AI_Risk_Level_Constants::get_risk_level_description( 'invalid' );
		$this->assertEmpty( $description, 'Invalid risk level should return empty description' );
	}

	/**
	 * Test risk level colors.
	 */
	public function test_risk_level_colors() {
		$color = WP_MCP_AI_Risk_Level_Constants::get_risk_level_color( 'info' );
		$this->assertSame( '#28a745', $color, 'Info should be green' );

		$color = WP_MCP_AI_Risk_Level_Constants::get_risk_level_color( 'standard' );
		$this->assertSame( '#ffc107', $color, 'Standard should be yellow' );

		$color = WP_MCP_AI_Risk_Level_Constants::get_risk_level_color( 'destructive' );
		$this->assertSame( '#dc3545', $color, 'Destructive should be red' );

		$color = WP_MCP_AI_Risk_Level_Constants::get_risk_level_color( 'invalid' );
		$this->assertSame( '#6c757d', $color, 'Invalid should return default gray' );
	}

	/**
	 * Test that constants match toolkit registry definitions.
	 */
	public function test_constants_match_registry() {
		$toolkit_registry = WP_MCP_AI_Toolkit_Registry::get_instance();
		$toolkits         = $toolkit_registry->get_toolkits();

		$constant_toolkits = WP_MCP_AI_Toolkit_Constants::get_all_toolkits();

		foreach ( array_keys( $toolkits ) as $toolkit_slug ) {
			$this->assertContains(
				$toolkit_slug,
				$constant_toolkits,
				"Toolkit '{$toolkit_slug}' from registry should have a constant"
			);
		}

		$this->assertCount(
			count( $toolkits ),
			$constant_toolkits,
			'Should have same number of toolkits in constants as in registry'
		);
	}
}
