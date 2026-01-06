<?php
/**
 * Tests for Security Training System
 *
 * @package WP_MCP_AI
 */

/**
 * Test Security Training functionality.
 */
class Test_Security_Training extends WP_UnitTestCase {
	/**
	 * Security training instance.
	 *
	 * @var WP_MCP_AI_Security_Training
	 */
	protected $training;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->training = WP_MCP_AI_Security_Training::get_instance();
		$this->user_id  = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Test singleton instance.
	 */
	public function test_get_instance() {
		$this->assertInstanceOf( WP_MCP_AI_Security_Training::class, $this->training );

		// Test singleton.
		$instance2 = WP_MCP_AI_Security_Training::get_instance();
		$this->assertSame( $this->training, $instance2 );
	}

	/**
	 * Test training roles constant.
	 */
	public function test_training_roles() {
		$roles = WP_MCP_AI_Security_Training::TRAINING_ROLES;

		$this->assertIsArray( $roles );
		$this->assertArrayHasKey( 'developer', $roles );
		$this->assertArrayHasKey( 'administrator', $roles );
		$this->assertArrayHasKey( 'security_team', $roles );
		$this->assertArrayHasKey( 'support_staff', $roles );
		$this->assertArrayHasKey( 'all_users', $roles );
	}

	/**
	 * Test module types constant.
	 */
	public function test_module_types() {
		$types = WP_MCP_AI_Security_Training::MODULE_TYPES;

		$this->assertIsArray( $types );
		$this->assertArrayHasKey( 'awareness', $types );
		$this->assertArrayHasKey( 'technical', $types );
		$this->assertArrayHasKey( 'compliance', $types );
		$this->assertArrayHasKey( 'incident', $types );
		$this->assertArrayHasKey( 'policy', $types );
	}

	/**
	 * Test training post type registration.
	 */
	public function test_post_type_registered() {
		$this->training->register_post_types();
		$this->assertTrue( post_type_exists( 'mcp_ai_training' ) );
	}

	/**
	 * Test record completion.
	 */
	public function test_record_completion() {
		// Create a test training module.
		$module_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_training',
				'post_title' => 'Test Training Module',
			)
		);

		// Record completion.
		$result = $this->training->record_completion( $this->user_id, $module_id, 95 );
		$this->assertTrue( $result );

		// Verify completion was recorded.
		$completions = $this->training->get_user_completions( $this->user_id );
		$this->assertIsArray( $completions );
		$this->assertArrayHasKey( $module_id, $completions );
		$this->assertEquals( 95, $completions[ $module_id ]['score'] );
	}

	/**
	 * Test get user completions.
	 */
	public function test_get_user_completions() {
		// Should return empty array initially.
		$completions = $this->training->get_user_completions( $this->user_id );
		$this->assertIsArray( $completions );
		$this->assertEmpty( $completions );

		// Add a completion.
		$module_id = $this->factory->post->create(
			array(
				'post_type' => 'mcp_ai_training',
			)
		);
		$this->training->record_completion( $this->user_id, $module_id );

		// Should now have one completion.
		$completions = $this->training->get_user_completions( $this->user_id );
		$this->assertCount( 1, $completions );
	}

	/**
	 * Test is training completed.
	 */
	public function test_is_training_completed() {
		$module_id = $this->factory->post->create(
			array(
				'post_type' => 'mcp_ai_training',
			)
		);

		// Should not be completed initially.
		$this->assertFalse( $this->training->is_training_completed( $this->user_id, $module_id ) );

		// Record completion.
		$this->training->record_completion( $this->user_id, $module_id );

		// Should now be completed.
		$this->assertTrue( $this->training->is_training_completed( $this->user_id, $module_id ) );
	}

	/**
	 * Test get training statistics.
	 */
	public function test_get_training_statistics() {
		// Create some test modules.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->factory->post->create(
				array(
					'post_type'   => 'mcp_ai_training',
					'post_status' => 'publish',
				)
			);
		}

		// Get statistics.
		$stats = $this->training->get_training_statistics();

		// Should have required fields.
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_modules', $stats );
		$this->assertArrayHasKey( 'total_users', $stats );
		$this->assertArrayHasKey( 'total_completions', $stats );
		$this->assertArrayHasKey( 'completion_rate', $stats );

		// Should have at least the modules we created.
		$this->assertGreaterThanOrEqual( 3, $stats['total_modules'] );
	}

	/**
	 * Test multiple completions.
	 */
	public function test_multiple_completions() {
		// Create multiple modules.
		$module_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$module_ids[] = $this->factory->post->create(
				array(
					'post_type' => 'mcp_ai_training',
				)
			);
		}

		// Complete all modules.
		foreach ( $module_ids as $module_id ) {
			$this->training->record_completion( $this->user_id, $module_id );
		}

		// Verify all completions.
		$completions = $this->training->get_user_completions( $this->user_id );
		$this->assertCount( 3, $completions );

		foreach ( $module_ids as $module_id ) {
			$this->assertTrue( $this->training->is_training_completed( $this->user_id, $module_id ) );
		}
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up user data.
		delete_user_meta( $this->user_id, 'wp_mcp_ai_training_completions' );

		parent::tearDown();
	}
}
