<?php
/**
 * Test Task Plan Seeder
 *
 * @package WP_MCP_AI
 */

/**
 * Test task plan seeder functionality
 */
class Test_Task_Plan_Seeder extends WP_UnitTestCase {

	/**
	 * Test that seeder creates task plans.
	 */
	public function test_seeder_creates_task_plans() {
		// Delete seeded option to allow reseeding.
		delete_option( WP_MCP_AI_Task_Plan_Seeder::SEEDED_OPTION );

		// Load seeder.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-task-plan-seeder.php';

		// Run seeder.
		WP_MCP_AI_Task_Plan_Seeder::seed_task_plans();

		// Check that seeded option is set.
		$this->assertTrue( (bool) get_option( WP_MCP_AI_Task_Plan_Seeder::SEEDED_OPTION ) );

		// Query task plans.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_task_plan',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		// Check that task plans were created.
		$this->assertGreaterThan( 0, $query->post_count, 'No task plans were created' );

		// Check for specific expected plans.
		$plan_titles = wp_list_pluck( $query->posts, 'post_title' );
		$this->assertContains( 'E-commerce Store Launch', $plan_titles );
		$this->assertContains( 'Social Media Marketing Campaign', $plan_titles );
		$this->assertContains( 'Client Financial Portfolio Analysis', $plan_titles );

		// Verify at least 8 plans were created.
		$this->assertGreaterThanOrEqual( 8, $query->post_count, 'Expected at least 8 task plans' );
	}

	/**
	 * Test that seeder only runs once.
	 */
	public function test_seeder_only_runs_once() {
		// Delete seeded option to allow reseeding.
		delete_option( WP_MCP_AI_Task_Plan_Seeder::SEEDED_OPTION );

		// Load seeder.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-task-plan-seeder.php';

		// Run seeder first time.
		WP_MCP_AI_Task_Plan_Seeder::seed_task_plans();

		// Count task plans.
		$query1 = new WP_Query(
			array(
				'post_type'      => 'mcp_task_plan',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		$count1 = $query1->post_count;

		// Try to run seeder again.
		WP_MCP_AI_Task_Plan_Seeder::seed_task_plans();

		// Count task plans again.
		$query2 = new WP_Query(
			array(
				'post_type'      => 'mcp_task_plan',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		$count2 = $query2->post_count;

		// Verify count didn't increase.
		$this->assertEquals( $count1, $count2, 'Seeder ran more than once' );
	}

	/**
	 * Test that task plans have proper metadata.
	 */
	public function test_task_plans_have_metadata() {
		// Delete seeded option to allow reseeding.
		delete_option( WP_MCP_AI_Task_Plan_Seeder::SEEDED_OPTION );

		// Load seeder.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-task-plan-seeder.php';

		// Run seeder.
		WP_MCP_AI_Task_Plan_Seeder::seed_task_plans();

		// Get one task plan.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_task_plan',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		$this->assertTrue( $query->have_posts(), 'No task plans found' );

		$post = $query->posts[0];

		// Check metadata exists.
		$goal = get_post_meta( $post->ID, '_goal', true );
		$this->assertNotEmpty( $goal, 'Goal metadata is missing' );

		$task_count = get_post_meta( $post->ID, '_task_count', true );
		$this->assertGreaterThan( 0, $task_count, 'Task count should be greater than 0' );

		$status = get_post_meta( $post->ID, '_status', true );
		$this->assertEquals( 'draft', $status, 'Initial status should be draft' );

		$progress = get_post_meta( $post->ID, '_progress', true );
		$this->assertEquals( 0, $progress, 'Initial progress should be 0' );
	}

	/**
	 * Test that task plans have markdown checkboxes.
	 */
	public function test_task_plans_have_checkboxes() {
		// Delete seeded option to allow reseeding.
		delete_option( WP_MCP_AI_Task_Plan_Seeder::SEEDED_OPTION );

		// Load seeder.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-task-plan-seeder.php';

		// Run seeder.
		WP_MCP_AI_Task_Plan_Seeder::seed_task_plans();

		// Get one task plan.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_task_plan',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		$this->assertTrue( $query->have_posts(), 'No task plans found' );

		$post    = $query->posts[0];
		$content = $post->post_content;

		// Check for markdown checkboxes.
		$this->assertStringContainsString( '- [ ]', $content, 'Task plan should contain unchecked checkboxes' );

		// Check for priority tags.
		$this->assertMatchesRegularExpression( '/\(Priority: (High|Medium|Low)\)/', $content, 'Task plan should contain priority tags' );
	}
}
