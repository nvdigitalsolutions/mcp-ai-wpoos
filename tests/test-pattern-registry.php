<?php
/**
 * Pattern Registry Tests
 *
 * @package WP_MCP_AI
 */

/**
 * Test pattern registry functionality
 */
class Test_Pattern_Registry extends WP_UnitTestCase {

	/**
	 * Pattern registry instance
	 *
	 * @var WP_MCP_AI_Pattern_Registry
	 */
	protected $registry;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pattern-constants.php';
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pattern-registry.php';

		$this->registry = new WP_MCP_AI_Pattern_Registry();
	}

	/**
	 * Test getting all patterns
	 */
	public function test_get_all_patterns() {
		$patterns = $this->registry->get_all_patterns();

		$this->assertIsArray( $patterns );
		$this->assertCount( 8, $patterns, 'Should have 8 standard patterns' );

		// Check that all 8 expected patterns exist.
		$expected_patterns = array(
			WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR,
			WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL,
			WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER,
			WP_MCP_AI_Pattern_Constants::PATTERN_SKILL_ROUTER,
			WP_MCP_AI_Pattern_Constants::PATTERN_LAYERED_DEFENSE,
			WP_MCP_AI_Pattern_Constants::PATTERN_EVENT_DRIVEN,
			WP_MCP_AI_Pattern_Constants::PATTERN_HIERARCHICAL,
			WP_MCP_AI_Pattern_Constants::PATTERN_EXPERIMENTATION,
		);

		foreach ( $expected_patterns as $pattern_slug ) {
			$this->assertArrayHasKey( $pattern_slug, $patterns );
		}
	}

	/**
	 * Test getting a specific pattern
	 */
	public function test_get_pattern() {
		$pattern = $this->registry->get_pattern( WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR );

		$this->assertIsArray( $pattern );
		$this->assertEquals( 'Orchestrator (Supervisor)', $pattern['name'] );
		$this->assertArrayHasKey( 'description', $pattern );
		$this->assertArrayHasKey( 'use_cases', $pattern );
		$this->assertArrayHasKey( 'strengths', $pattern );
		$this->assertArrayHasKey( 'weaknesses', $pattern );
		$this->assertArrayHasKey( 'best_for_toolkits', $pattern );
		$this->assertArrayHasKey( 'team_size', $pattern );
		$this->assertArrayHasKey( 'complexity', $pattern );
		$this->assertArrayHasKey( 'scalability', $pattern );
		$this->assertArrayHasKey( 'fault_tolerance', $pattern );
		$this->assertArrayHasKey( 'coordination_style', $pattern );
	}

	/**
	 * Test getting pattern for invalid slug
	 */
	public function test_get_pattern_invalid() {
		$pattern = $this->registry->get_pattern( 'invalid_pattern' );

		$this->assertNull( $pattern );
	}

	/**
	 * Test getting patterns for a toolkit
	 */
	public function test_get_patterns_for_toolkit() {
		$patterns = $this->registry->get_patterns_for_toolkit( 'content_publishing' );

		$this->assertIsArray( $patterns );
		$this->assertNotEmpty( $patterns );
		$this->assertContains( WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR, $patterns );
	}

	/**
	 * Test pattern selection with toolkit
	 */
	public function test_select_pattern_with_toolkit() {
		$task_requirements = array(
			'toolkit'   => 'content_publishing',
			'team_size' => 5,
		);

		$selected = $this->registry->select_pattern( $task_requirements );

		$this->assertNotNull( $selected );
		$this->assertIsString( $selected );

		// Should be one of the patterns suitable for content_publishing.
		$pattern = $this->registry->get_pattern( $selected );
		$this->assertContains( 'content_publishing', $pattern['best_for_toolkits'] );
	}

	/**
	 * Test pattern selection with team size
	 */
	public function test_select_pattern_with_team_size() {
		$task_requirements = array(
			'toolkit'   => 'media_processing',
			'team_size' => 3,
		);

		$selected = $this->registry->select_pattern( $task_requirements );

		$this->assertNotNull( $selected );

		$pattern = $this->registry->get_pattern( $selected );
		$this->assertGreaterThanOrEqual( $pattern['team_size']['min'], 3 );
		$this->assertLessThanOrEqual( $pattern['team_size']['max'], 3 );
	}

	/**
	 * Test pattern selection with complexity
	 */
	public function test_select_pattern_with_complexity() {
		$task_requirements = array(
			'toolkit'    => 'data_analytics',
			'team_size'  => 4,
			'complexity' => 'high',
		);

		$selected = $this->registry->select_pattern( $task_requirements );

		$this->assertNotNull( $selected );

		// High complexity tasks may prefer peer-to-peer or experimentation.
		$pattern = $this->registry->get_pattern( $selected );
		$this->assertNotEmpty( $pattern );
	}

	/**
	 * Test pattern selection with fault tolerance
	 */
	public function test_select_pattern_with_fault_tolerance() {
		$task_requirements = array(
			'toolkit'         => 'security_compliance',
			'team_size'       => 3,
			'fault_tolerance' => true,
		);

		$selected = $this->registry->select_pattern( $task_requirements );

		$this->assertNotNull( $selected );

		// Should prefer patterns with high fault tolerance.
		$pattern = $this->registry->get_pattern( $selected );
		$this->assertContains( $pattern['fault_tolerance'], array( 'high', 'medium' ) );
	}

	/**
	 * Test pattern recommendations for toolkit
	 */
	public function test_recommend_patterns_for_toolkit() {
		$recommendations = $this->registry->recommend_patterns_for_toolkit( 'content_publishing' );

		$this->assertIsArray( $recommendations );
		$this->assertNotEmpty( $recommendations );

		// Check structure of recommendations.
		foreach ( $recommendations as $pattern_slug => $rec ) {
			$this->assertArrayHasKey( 'pattern', $rec );
			$this->assertArrayHasKey( 'score', $rec );
			$this->assertArrayHasKey( 'primary', $rec );
			$this->assertArrayHasKey( 'description', $rec );
		}

		// Primary patterns should have score of 100.
		$has_primary = false;
		foreach ( $recommendations as $rec ) {
			if ( $rec['primary'] ) {
				$this->assertEquals( 100, $rec['score'] );
				$has_primary = true;
			}
		}
		$this->assertTrue( $has_primary, 'Should have at least one primary pattern' );
	}

	/**
	 * Test pattern compatibility validation - valid case
	 */
	public function test_validate_pattern_compatibility_valid() {
		$team_members = array(
			array(
				'id'   => 1,
				'role' => 'writer',
			),
			array(
				'id'   => 2,
				'role' => 'editor',
			),
			array(
				'id'   => 3,
				'role' => 'publisher',
			),
		);

		$result = $this->registry->validate_pattern_compatibility(
			WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR,
			$team_members
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test pattern compatibility validation - team too small
	 */
	public function test_validate_pattern_compatibility_too_small() {
		$team_members = array(
			array(
				'id'   => 1,
				'role' => 'worker',
			),
		);

		$result = $this->registry->validate_pattern_compatibility(
			WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR,
			$team_members
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_team_too_small', $result->get_error_code() );
	}

	/**
	 * Test pattern compatibility validation - team too large
	 */
	public function test_validate_pattern_compatibility_too_large() {
		// Create a team that's too large for sequential pattern (max 8).
		$team_members = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$team_members[] = array(
				'id'   => $i,
				'role' => 'agent',
			);
		}

		$result = $this->registry->validate_pattern_compatibility(
			WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL,
			$team_members
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_team_too_large', $result->get_error_code() );
	}

	/**
	 * Test pattern compatibility validation - invalid pattern
	 */
	public function test_validate_pattern_compatibility_invalid_pattern() {
		$team_members = array(
			array(
				'id'   => 1,
				'role' => 'agent',
			),
		);

		$result = $this->registry->validate_pattern_compatibility(
			'invalid_pattern',
			$team_members
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_pattern', $result->get_error_code() );
	}

	/**
	 * Test pattern statistics
	 */
	public function test_get_pattern_statistics() {
		$stats = $this->registry->get_pattern_statistics();

		$this->assertIsArray( $stats );
		$this->assertEquals( 8, $stats['total_patterns'] );
		$this->assertArrayHasKey( 'by_complexity', $stats );
		$this->assertArrayHasKey( 'by_scalability', $stats );
		$this->assertArrayHasKey( 'by_fault_tolerance', $stats );
		$this->assertArrayHasKey( 'by_coordination', $stats );
		$this->assertArrayHasKey( 'avg_team_size', $stats );
		$this->assertArrayHasKey( 'toolkit_coverage', $stats );

		// Check that each toolkit has at least one pattern.
		$this->assertNotEmpty( $stats['toolkit_coverage'] );
	}

	/**
	 * Test pattern comparison
	 */
	public function test_compare_patterns() {
		$patterns_to_compare = array(
			WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR,
			WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL,
			WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER,
		);

		$comparison = $this->registry->compare_patterns( $patterns_to_compare );

		$this->assertIsArray( $comparison );
		$this->assertCount( 3, $comparison );

		foreach ( $patterns_to_compare as $pattern_slug ) {
			$this->assertArrayHasKey( $pattern_slug, $comparison );
			$this->assertArrayHasKey( 'name', $comparison[ $pattern_slug ] );
			$this->assertArrayHasKey( 'complexity', $comparison[ $pattern_slug ] );
			$this->assertArrayHasKey( 'scalability', $comparison[ $pattern_slug ] );
			$this->assertArrayHasKey( 'fault_tolerance', $comparison[ $pattern_slug ] );
			$this->assertArrayHasKey( 'coordination', $comparison[ $pattern_slug ] );
			$this->assertArrayHasKey( 'optimal_team_size', $comparison[ $pattern_slug ] );
		}
	}

	/**
	 * Test all patterns have required fields
	 */
	public function test_all_patterns_have_required_fields() {
		$patterns = $this->registry->get_all_patterns();

		$required_fields = array(
			'name',
			'description',
			'use_cases',
			'strengths',
			'weaknesses',
			'best_for_toolkits',
			'team_size',
			'complexity',
			'scalability',
			'fault_tolerance',
			'coordination_style',
		);

		foreach ( $patterns as $pattern_slug => $pattern ) {
			foreach ( $required_fields as $field ) {
				$this->assertArrayHasKey(
					$field,
					$pattern,
					"Pattern {$pattern_slug} missing required field: {$field}"
				);
			}

			// Validate team_size structure.
			$this->assertArrayHasKey( 'min', $pattern['team_size'] );
			$this->assertArrayHasKey( 'max', $pattern['team_size'] );
			$this->assertArrayHasKey( 'optimal', $pattern['team_size'] );

			// Validate arrays are not empty.
			$this->assertNotEmpty( $pattern['use_cases'], "{$pattern_slug} should have use cases" );
			$this->assertNotEmpty( $pattern['strengths'], "{$pattern_slug} should have strengths" );
			$this->assertNotEmpty( $pattern['weaknesses'], "{$pattern_slug} should have weaknesses" );
			$this->assertNotEmpty( $pattern['best_for_toolkits'], "{$pattern_slug} should have best_for_toolkits" );
		}
	}

	/**
	 * Test team size logic
	 */
	public function test_team_size_logic() {
		$patterns = $this->registry->get_all_patterns();

		foreach ( $patterns as $pattern_slug => $pattern ) {
			$min     = $pattern['team_size']['min'];
			$max     = $pattern['team_size']['max'];
			$optimal = $pattern['team_size']['optimal'];

			$this->assertGreaterThan( 0, $min, "{$pattern_slug} min team size should be > 0" );
			$this->assertGreaterThanOrEqual( $min, $max, "{$pattern_slug} max should be >= min" );
			$this->assertGreaterThanOrEqual( $min, $optimal, "{$pattern_slug} optimal should be >= min" );
			$this->assertLessThanOrEqual( $max, $optimal, "{$pattern_slug} optimal should be <= max" );
		}
	}

	/**
	 * Test coordination styles are valid
	 */
	public function test_coordination_styles_valid() {
		$patterns = $this->registry->get_all_patterns();

		$valid_styles = array(
			'centralized',
			'linear',
			'distributed',
			'event-based',
			'hierarchical',
			'parallel',
		);

		foreach ( $patterns as $pattern_slug => $pattern ) {
			$this->assertContains(
				$pattern['coordination_style'],
				$valid_styles,
				"{$pattern_slug} has invalid coordination style: {$pattern['coordination_style']}"
			);
		}
	}

	/**
	 * Test complexity levels are valid
	 */
	public function test_complexity_levels_valid() {
		$patterns = $this->registry->get_all_patterns();

		$valid_levels = array( 'low', 'medium', 'high' );

		foreach ( $patterns as $pattern_slug => $pattern ) {
			$this->assertContains(
				$pattern['complexity'],
				$valid_levels,
				"{$pattern_slug} has invalid complexity: {$pattern['complexity']}"
			);

			$this->assertContains(
				$pattern['scalability'],
				$valid_levels,
				"{$pattern_slug} has invalid scalability: {$pattern['scalability']}"
			);

			$this->assertContains(
				$pattern['fault_tolerance'],
				$valid_levels,
				"{$pattern_slug} has invalid fault_tolerance: {$pattern['fault_tolerance']}"
			);
		}
	}
}
