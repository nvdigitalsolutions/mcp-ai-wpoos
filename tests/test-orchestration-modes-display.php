<?php
/**
 * Test Orchestration Modes Display Enhancement
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for orchestration modes display enhancement.
 */
class Test_Orchestration_Modes_Display extends WP_UnitTestCase {

	/**
	 * Test that orchestration modes metric card shows correct format (X/4).
	 */
	public function test_orchestration_modes_shows_correct_format() {
		// Arrange - create test teams with different orchestration modes.
		$team1_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_team',
				'post_title' => 'Test Team 1',
			)
		);
		update_post_meta( $team1_id, '_wp_mcp_ai_team_orchestration_mode', 'sequential' );

		$team2_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_team',
				'post_title' => 'Test Team 2',
			)
		);
		update_post_meta( $team2_id, '_wp_mcp_ai_team_orchestration_mode', 'parallel' );

		// Act - get orchestration section output.
		$section = new WP_MCP_AI_Section_Orchestration();
		$fields  = $section->get_fields();

		// Get the teams view content.
		ob_start();
		if ( isset( $fields['teams_view']['content'] ) ) {
			echo $fields['teams_view']['content'];
		}
		$output = ob_get_clean();

		// Assert - check for X/4 format where X is number of unique modes used.
		$this->assertStringContainsString( '/4', $output, 'Should show total of 4 available modes' );
		$this->assertStringContainsString( 'Orchestration Modes', $output, 'Should show label' );

		// Clean up.
		wp_delete_post( $team1_id, true );
		wp_delete_post( $team2_id, true );
	}

	/**
	 * Test that orchestration modes displays breakdown of modes used.
	 */
	public function test_orchestration_modes_shows_mode_breakdown() {
		// Arrange - create teams with specific orchestration modes.
		$team_ids = array();

		// Create 2 sequential teams.
		for ( $i = 0; $i < 2; $i++ ) {
			$team_id = $this->factory->post->create(
				array(
					'post_type'  => 'mcp_ai_team',
					'post_title' => 'Sequential Team ' . ( $i + 1 ),
				)
			);
			update_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', 'sequential' );
			$team_ids[] = $team_id;
		}

		// Create 1 parallel team.
		$team_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_team',
				'post_title' => 'Parallel Team',
			)
		);
		update_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', 'parallel' );
		$team_ids[] = $team_id;

		// Act - get orchestration section output.
		$section = new WP_MCP_AI_Section_Orchestration();
		$fields  = $section->get_fields();

		ob_start();
		if ( isset( $fields['teams_view']['content'] ) ) {
			echo $fields['teams_view']['content'];
		}
		$output = ob_get_clean();

		// Assert - check for mode breakdown.
		$this->assertStringContainsString( 'Sequential (2)', $output, 'Should show Sequential with count of 2' );
		$this->assertStringContainsString( 'Parallel (1)', $output, 'Should show Parallel with count of 1' );

		// Clean up.
		foreach ( $team_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Test that orchestration modes shows info icon with tooltip.
	 */
	public function test_orchestration_modes_has_info_icon() {
		// Act - get orchestration section output.
		$section = new WP_MCP_AI_Section_Orchestration();
		$fields  = $section->get_fields();

		ob_start();
		if ( isset( $fields['teams_view']['content'] ) ) {
			echo $fields['teams_view']['content'];
		}
		$output = ob_get_clean();

		// Assert - check for info icon with tooltip.
		$this->assertStringContainsString( 'dashicons-info-outline', $output, 'Should have info icon' );
		$this->assertStringContainsString( 'Available modes:', $output, 'Should have tooltip text' );
		$this->assertStringContainsString( 'Single', $output, 'Should mention Single mode in tooltip' );
		$this->assertStringContainsString( 'Sequential', $output, 'Should mention Sequential mode in tooltip' );
		$this->assertStringContainsString( 'Parallel', $output, 'Should mention Parallel mode in tooltip' );
		$this->assertStringContainsString( 'Swarm', $output, 'Should mention Swarm mode in tooltip' );
	}

	/**
	 * Test that orchestration modes handles empty/no teams gracefully.
	 */
	public function test_orchestration_modes_handles_no_teams() {
		// Act - get orchestration section output with no teams.
		$section = new WP_MCP_AI_Section_Orchestration();
		$fields  = $section->get_fields();

		ob_start();
		if ( isset( $fields['teams_view']['content'] ) ) {
			echo $fields['teams_view']['content'];
		}
		$output = ob_get_clean();

		// Assert - should show 0/4 and appropriate message.
		$this->assertStringContainsString( '0/4', $output, 'Should show 0 modes in use out of 4 available' );
		$this->assertStringContainsString( 'No modes configured', $output, 'Should show appropriate message when no teams exist' );
	}

	/**
	 * Test that all 4 orchestration modes are recognized when used.
	 */
	public function test_all_four_orchestration_modes_recognized() {
		// Arrange - create teams with all 4 orchestration modes.
		$team_ids = array();
		$modes    = array( 'single', 'sequential', 'parallel', 'swarm' );

		foreach ( $modes as $mode ) {
			$team_id = $this->factory->post->create(
				array(
					'post_type'  => 'mcp_ai_team',
					'post_title' => ucfirst( $mode ) . ' Team',
				)
			);
			update_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', $mode );
			$team_ids[] = $team_id;
		}

		// Act - get orchestration section output.
		$section = new WP_MCP_AI_Section_Orchestration();
		$fields  = $section->get_fields();

		ob_start();
		if ( isset( $fields['teams_view']['content'] ) ) {
			echo $fields['teams_view']['content'];
		}
		$output = ob_get_clean();

		// Assert - should show 4/4 modes in use.
		$this->assertStringContainsString( '4/4', $output, 'Should show all 4 modes in use' );
		$this->assertStringContainsString( 'Single (1)', $output, 'Should show Single mode' );
		$this->assertStringContainsString( 'Sequential (1)', $output, 'Should show Sequential mode' );
		$this->assertStringContainsString( 'Parallel (1)', $output, 'Should show Parallel mode' );
		$this->assertStringContainsString( 'Swarm (1)', $output, 'Should show Swarm mode' );

		// Clean up.
		foreach ( $team_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
