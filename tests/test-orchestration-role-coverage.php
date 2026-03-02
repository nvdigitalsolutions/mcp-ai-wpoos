<?php
/**
 * Test Orchestration Role Coverage
 *
 * Validates that the orchestration seeder assigns agent roles to all 260 professions
 * using the expanded keyword heuristics.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

/**
 * Test case for orchestration role coverage validation.
 *
 * @since 1.9.0
 */
class Test_Orchestration_Role_Coverage extends WP_UnitTestCase {

	/**
	 * Test that the orchestration seeder class exists and can be instantiated.
	 */
	public function test_orchestration_seeder_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Profession_Orchestration_Seeder' ),
			'WP_MCP_AI_Profession_Orchestration_Seeder class should exist'
		);

		$seeder = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$this->assertInstanceOf(
			'WP_MCP_AI_Profession_Orchestration_Seeder',
			$seeder,
			'Should be able to instantiate seeder'
		);
	}

	/**
	 * Test that the seeder version has been bumped to 1.1.0.
	 */
	public function test_seeder_version_is_updated() {
		$this->assertEquals(
			'1.1.0',
			WP_MCP_AI_Profession_Orchestration_Seeder::SEEDER_VERSION,
			'Seeder version should be 1.1.0'
		);
	}

	/**
	 * Test that determine_agent_role assigns roles to all profession categories.
	 *
	 * Uses reflection to test the protected method directly.
	 */
	public function test_determine_agent_role_covers_all_categories() {
		$seeder     = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$reflection = new ReflectionClass( $seeder );
		$method     = $reflection->getMethod( 'determine_agent_role' );
		$method->setAccessible( true );

		$valid_roles = array( 'planner', 'executor', 'critic', 'specialist', 'generalist' );

		// Test representative professions from each category.
		$test_cases = array(
			// Healthcare → specialist.
			array(
				'title'     => 'Doctor',
				'category'  => 'healthcare',
				'expertise' => array( 'medical diagnosis', 'patient care' ),
				'expected'  => 'specialist',
			),
			// Legal → specialist.
			array(
				'title'     => 'Lawyer',
				'category'  => 'legal',
				'expertise' => array( 'legal research', 'litigation' ),
				'expected'  => 'specialist',
			),
			// Financial → specialist.
			array(
				'title'     => 'Accountant',
				'category'  => 'financial',
				'expertise' => array( 'accounting', 'financial reporting' ),
				'expected'  => 'specialist',
			),
			// Technical → executor.
			array(
				'title'     => 'Software Developer',
				'category'  => 'technical',
				'expertise' => array( 'software development', 'programming' ),
				'expected'  => 'executor',
			),
			// Creative → executor.
			array(
				'title'     => 'Graphic Designer',
				'category'  => 'creative',
				'expertise' => array( 'design', 'creative' ),
				'expected'  => 'executor',
			),
			// Advisory → planner.
			array(
				'title'     => 'Business Consultant',
				'category'  => 'advisory',
				'expertise' => array( 'strategy', 'planning' ),
				'expected'  => 'planner',
			),
			// Other category - teacher → specialist.
			array(
				'title'     => 'High School Teacher',
				'category'  => 'other',
				'expertise' => array( 'curriculum design', 'adolescent development' ),
				'expected'  => 'specialist',
			),
			// Other category - farmer → executor.
			array(
				'title'     => 'Farmer',
				'category'  => 'other',
				'expertise' => array( 'crop cultivation', 'livestock husbandry' ),
				'expected'  => 'executor',
			),
			// Other category - police officer → critic.
			array(
				'title'     => 'Police Officer',
				'category'  => 'other',
				'expertise' => array( 'law enforcement', 'criminal law' ),
				'expected'  => 'critic',
			),
			// Other category - dispatcher → planner.
			array(
				'title'     => 'Dispatcher',
				'category'  => 'other',
				'expertise' => array( 'schedule coordination', 'route planning' ),
				'expected'  => 'planner',
			),
			// Other category - tutor → specialist.
			array(
				'title'     => 'IGCSE Mathematics Tutor',
				'category'  => 'other',
				'expertise' => array( 'curriculum alignment', 'exam technique' ),
				'expected'  => 'specialist',
			),
			// Other category - translator → specialist.
			array(
				'title'     => 'Interpreter/Translator',
				'category'  => 'other',
				'expertise' => array( 'language translation', 'interpretation' ),
				'expected'  => 'specialist',
			),
			// Inspector → critic.
			array(
				'title'     => 'Building Inspector',
				'category'  => 'other',
				'expertise' => array( 'building code interpretation', 'inspection' ),
				'expected'  => 'critic',
			),
			// Project Manager → planner.
			array(
				'title'     => 'Project Manager',
				'category'  => 'other',
				'expertise' => array( 'project management', 'leadership' ),
				'expected'  => 'planner',
			),
			// Chef → executor.
			array(
				'title'     => 'Chef',
				'category'  => 'other',
				'expertise' => array( 'culinary techniques', 'menu development' ),
				'expected'  => 'executor',
			),
		);

		foreach ( $test_cases as $case ) {
			$post = $this->create_test_profession( $case['title'], $case['category'], $case['expertise'] );

			$result = $method->invoke( $seeder, $post );

			// Handle multi-role return.
			$primary_role = is_array( $result ) ? $result['primary'] : $result;

			$this->assertContains(
				$primary_role,
				$valid_roles,
				sprintf( 'Role for "%s" should be a valid role', $case['title'] )
			);

			$this->assertEquals(
				$case['expected'],
				$primary_role,
				sprintf(
					'Role for "%s" (category: %s) should be "%s" but got "%s"',
					$case['title'],
					$case['category'],
					$case['expected'],
					$primary_role
				)
			);
		}
	}

	/**
	 * Test that no profession from the JSON files would get assigned 'generalist'.
	 *
	 * Validates 100% role coverage using the expanded heuristics.
	 */
	public function test_all_json_professions_get_specific_roles() {
		$seeder     = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$reflection = new ReflectionClass( $seeder );
		$method     = $reflection->getMethod( 'determine_agent_role' );
		$method->setAccessible( true );

		$json_dir    = WP_MCP_AI_PATH . 'includes/knowledge-base/professions';
		$json_files  = glob( $json_dir . '/*.json' );
		$generalists = array();
		$total       = 0;

		foreach ( $json_files as $file ) {
			$contents = file_get_contents( $file );
			$this->assertNotFalse( $contents, sprintf( 'Failed to read file: %s', $file ) );

			$data = json_decode( $contents, true );
			$this->assertNotNull( $data, sprintf( 'Failed to parse JSON in file: %s', $file ) );

			if ( ! isset( $data['professions'] ) ) {
				continue;
			}

			foreach ( $data['professions'] as $prof ) {
				++$total;
				$post   = $this->create_test_profession(
					$prof['title'],
					isset( $prof['category'] ) ? $prof['category'] : 'other',
					isset( $prof['expertise'] ) ? $prof['expertise'] : array()
				);
				$result = $method->invoke( $seeder, $post );

				$primary_role = is_array( $result ) ? $result['primary'] : $result;

				if ( 'generalist' === $primary_role ) {
					$generalists[] = $prof['title'];
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			260,
			$total,
			'Should have at least 260 professions from JSON files'
		);

		$this->assertEmpty(
			$generalists,
			sprintf(
				'All professions should have a specific role. Found %d generalists: %s',
				count( $generalists ),
				implode( ', ', $generalists )
			)
		);
	}

	/**
	 * Test that role distribution is not artificially even.
	 *
	 * Validates that the role distribution reflects realistic proportions,
	 * not an artificial 10/10/10/10/10 split.
	 */
	public function test_role_distribution_is_realistic() {
		$seeder     = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$reflection = new ReflectionClass( $seeder );
		$method     = $reflection->getMethod( 'determine_agent_role' );
		$method->setAccessible( true );

		$json_dir   = WP_MCP_AI_PATH . 'includes/knowledge-base/professions';
		$json_files = glob( $json_dir . '/*.json' );
		$role_counts = array(
			'planner'    => 0,
			'executor'   => 0,
			'critic'     => 0,
			'specialist' => 0,
			'generalist' => 0,
		);

		foreach ( $json_files as $file ) {
			$contents = file_get_contents( $file );
			$this->assertNotFalse( $contents, sprintf( 'Failed to read file: %s', $file ) );

			$data = json_decode( $contents, true );
			$this->assertNotNull( $data, sprintf( 'Failed to parse JSON in file: %s', $file ) );

			if ( ! isset( $data['professions'] ) ) {
				continue;
			}

			foreach ( $data['professions'] as $prof ) {
				$post   = $this->create_test_profession(
					$prof['title'],
					isset( $prof['category'] ) ? $prof['category'] : 'other',
					isset( $prof['expertise'] ) ? $prof['expertise'] : array()
				);
				$result = $method->invoke( $seeder, $post );

				$primary_role = is_array( $result ) ? $result['primary'] : $result;
				if ( isset( $role_counts[ $primary_role ] ) ) {
					++$role_counts[ $primary_role ];
				}
			}
		}

		// Specialist should be the largest group (healthcare, legal, financial, education).
		$this->assertGreaterThan(
			$role_counts['executor'],
			$role_counts['specialist'],
			'Specialist should be larger than executor (covers healthcare, legal, financial, education domains)'
		);

		// All roles except generalist should have meaningful counts.
		$this->assertGreaterThan( 0, $role_counts['planner'], 'Planner count should be > 0' );
		$this->assertGreaterThan( 0, $role_counts['executor'], 'Executor count should be > 0' );
		$this->assertGreaterThan( 0, $role_counts['critic'], 'Critic count should be > 0' );
		$this->assertGreaterThan( 0, $role_counts['specialist'], 'Specialist count should be > 0' );

		// Distribution should NOT be artificially even (not all counts equal).
		$values = array_values( $role_counts );
		$this->assertFalse(
			count( array_unique( $values ) ) === 1 && $values[0] > 0,
			'Role distribution should not be artificially even (all identical counts)'
		);
	}

	/**
	 * Test that the task patterns include at least 50 professions.
	 */
	public function test_task_patterns_coverage() {
		$seeder     = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$reflection = new ReflectionClass( $seeder );
		$method     = $reflection->getMethod( 'get_default_task_patterns' );
		$method->setAccessible( true );

		$patterns = $method->invoke( $seeder );

		$this->assertIsArray( $patterns, 'Task patterns should be an array' );
		$this->assertGreaterThanOrEqual(
			50,
			count( $patterns ),
			'Should have task patterns for at least 50 professions'
		);

		// Each pattern should have at least one workflow.
		foreach ( $patterns as $slug => $config ) {
			$this->assertIsArray(
				$config,
				sprintf( 'Pattern config for "%s" should be an array', $slug )
			);
			$this->assertNotEmpty(
				$config,
				sprintf( 'Pattern config for "%s" should not be empty', $slug )
			);
		}
	}

	/**
	 * Test multi-role support for professions that naturally combine roles.
	 */
	public function test_multi_role_assignment() {
		$seeder     = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$reflection = new ReflectionClass( $seeder );
		$method     = $reflection->getMethod( 'determine_agent_role' );
		$method->setAccessible( true );

		// QA Engineer should match both critic and executor.
		$post   = $this->create_test_profession(
			'QA Engineer',
			'technical',
			array( 'quality assurance', 'test automation', 'software development' )
		);
		$result = $method->invoke( $seeder, $post );

		// Should return array with primary and secondary roles.
		$this->assertIsArray( $result, 'Multi-role profession should return array' );
		$this->assertArrayHasKey( 'primary', $result, 'Should have primary role' );
		$this->assertArrayHasKey( 'secondary', $result, 'Should have secondary roles' );
		$this->assertNotEmpty( $result['secondary'], 'QA Engineer should have secondary roles' );
	}

	/**
	 * Helper method to create a test profession post object.
	 *
	 * @param string $title    Profession title.
	 * @param string $category Profession category.
	 * @param array  $expertise Expertise array.
	 * @return WP_Post Test post object.
	 */
	private function create_test_profession( $title, $category, $expertise = array() ) {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, $category );
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, $expertise );

		return get_post( $post_id );
	}
}
