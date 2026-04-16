<?php
/**
 * Tests for PSO Optimizer Service
 *
 * @package WP_MCP_AI
 */

class Test_PSO_Optimizer_Service extends WP_UnitTestCase {

	/**
	 * PSO service instance.
	 *
	 * @var WP_MCP_AI_PSO_Optimizer_Service
	 */
	private $service;

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-pso-optimizer-service.php';

		// Enable PSO.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_pso_optimizer' => true,
				'enable_logging'       => false,
			)
		);

		$this->service = new WP_MCP_AI_PSO_Optimizer_Service();

		// Create a test assistant post.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'PSO Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	public function tearDown(): void {
		// Clean up PSO state.
		$meta_keys = array( 'position', 'velocity', 'pbest', 'pbest_fit', 'samples' );
		foreach ( $meta_keys as $key ) {
			delete_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_' . $key );
		}
		delete_option( 'wp_mcp_ai_pso_global_best' );

		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Dimensions.
	// ------------------------------------------------------------------

	public function test_dimensions_are_valid() {
		$dims = WP_MCP_AI_PSO_Optimizer_Service::get_dimensions();

		$this->assertIsArray( $dims );
		$this->assertCount( 7, $dims );

		foreach ( $dims as $dim ) {
			$this->assertArrayHasKey( 'key', $dim );
			$this->assertArrayHasKey( 'min', $dim );
			$this->assertArrayHasKey( 'max', $dim );
			$this->assertArrayHasKey( 'default', $dim );
			$this->assertLessThanOrEqual( $dim['max'], $dim['default'] );
			$this->assertGreaterThanOrEqual( $dim['min'], $dim['default'] );
		}
	}

	// ------------------------------------------------------------------
	// Inertia calculation.
	// ------------------------------------------------------------------

	public function test_inertia_starts_high() {
		$inertia = $this->service->calculate_inertia( 0 );
		$this->assertEqualsWithDelta( 0.9, $inertia, 0.001 );
	}

	public function test_inertia_ends_low() {
		$inertia = $this->service->calculate_inertia( 100 );
		$this->assertEqualsWithDelta( 0.4, $inertia, 0.001 );
	}

	public function test_inertia_decays_linearly() {
		$at_50 = $this->service->calculate_inertia( 50 );
		$this->assertEqualsWithDelta( 0.65, $at_50, 0.001 );
	}

	public function test_inertia_does_not_go_below_end() {
		$inertia = $this->service->calculate_inertia( 500 );
		$this->assertEqualsWithDelta( 0.4, $inertia, 0.001 );
	}

	// ------------------------------------------------------------------
	// Fitness evaluation.
	// ------------------------------------------------------------------

	public function test_fitness_returns_zero_for_zero_duration() {
		$fitness = $this->service->evaluate_fitness(
			array(
				'duration'        => 0.0,
				'iterations'      => 1,
				'tool_executions' => 3,
				'cache_hits'      => 1,
				'cache_misses'    => 1,
			)
		);
		$this->assertSame( 0.0, $fitness );
	}

	public function test_fitness_positive_for_valid_metrics() {
		$fitness = $this->service->evaluate_fitness(
			array(
				'duration'        => 2.5,
				'iterations'      => 3,
				'tool_executions' => 5,
				'cache_hits'      => 2,
				'cache_misses'    => 1,
			)
		);
		$this->assertGreaterThan( 0.0, $fitness );
	}

	public function test_faster_execution_has_higher_fitness() {
		$fast = $this->service->evaluate_fitness(
			array(
				'duration'        => 1.0,
				'iterations'      => 2,
				'tool_executions' => 4,
				'cache_hits'      => 2,
				'cache_misses'    => 0,
			)
		);
		$slow = $this->service->evaluate_fitness(
			array(
				'duration'        => 10.0,
				'iterations'      => 2,
				'tool_executions' => 4,
				'cache_hits'      => 2,
				'cache_misses'    => 0,
			)
		);
		$this->assertGreaterThan( $slow, $fast );
	}

	public function test_fitness_returns_zero_for_empty_metrics() {
		$this->assertSame( 0.0, $this->service->evaluate_fitness( array() ) );
	}

	// ------------------------------------------------------------------
	// Particle update (core PSO equation).
	// ------------------------------------------------------------------

	public function test_particle_update_returns_same_dimension_count() {
		$dims = WP_MCP_AI_PSO_Optimizer_Service::get_dimensions();
		$n    = count( $dims );

		$position = array_fill( 0, $n, 0.5 );
		$velocity = array_fill( 0, $n, 0.0 );
		$pbest    = array_fill( 0, $n, 0.7 );
		$gbest    = array_fill( 0, $n, 0.8 );

		$result = $this->service->update_particle( $position, $velocity, $pbest, $gbest, 0.7 );

		$this->assertCount( $n, $result['position'] );
		$this->assertCount( $n, $result['velocity'] );
	}

	public function test_particle_position_stays_in_bounds() {
		$dims = WP_MCP_AI_PSO_Optimizer_Service::get_dimensions();
		$n    = count( $dims );

		// Push position to extremes.
		$position = array();
		$velocity = array();
		$pbest    = array();
		$gbest    = array();

		foreach ( $dims as $dim ) {
			$position[] = $dim['min'];
			$velocity[] = -1.0; // Strong negative velocity.
			$pbest[]    = $dim['max'];
			$gbest[]    = $dim['max'];
		}

		$result = $this->service->update_particle( $position, $velocity, $pbest, $gbest, 0.9 );

		for ( $d = 0; $d < $n; $d++ ) {
			$this->assertGreaterThanOrEqual( $dims[ $d ]['min'], $result['position'][ $d ] );
			$this->assertLessThanOrEqual( $dims[ $d ]['max'], $result['position'][ $d ] );
		}
	}

	public function test_zero_inertia_ignores_previous_velocity() {
		$dims = WP_MCP_AI_PSO_Optimizer_Service::get_dimensions();
		$n    = count( $dims );

		$position = array_fill( 0, $n, 0.5 );
		$velocity = array_fill( 0, $n, 0.3 );

		// Set pbest and gbest to same as position — no personal/social pull.
		$result = $this->service->update_particle( $position, $velocity, $position, $position, 0.0 );

		// With W=0 and pbest=gbest=position, new velocity should be 0.
		foreach ( $result['velocity'] as $v ) {
			$this->assertEqualsWithDelta( 0.0, $v, 0.001, 'Velocity should be zero when inertia=0 and no personal/social pull' );
		}
	}

	// ------------------------------------------------------------------
	// Particle state management.
	// ------------------------------------------------------------------

	public function test_init_particle_creates_default_state() {
		$this->service->maybe_init_particle( $this->assistant_id );

		$position = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );
		$velocity = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_velocity', true );
		$pbest    = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_pbest', true );

		$this->assertIsArray( $position );
		$this->assertIsArray( $velocity );
		$this->assertIsArray( $pbest );
		$this->assertCount( 7, $position );
		$this->assertEquals( $position, $pbest );
	}

	public function test_init_particle_idempotent() {
		$this->service->maybe_init_particle( $this->assistant_id );

		// Modify position.
		$custom = array( 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7 );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', $custom );

		// Re-init should NOT overwrite.
		$this->service->maybe_init_particle( $this->assistant_id );

		$position = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );
		$this->assertEquals( $custom, $position );
	}

	public function test_get_particle_state_returns_all_fields() {
		$this->service->maybe_init_particle( $this->assistant_id );
		$state = $this->service->get_particle_state( $this->assistant_id );

		$this->assertArrayHasKey( 'position', $state );
		$this->assertArrayHasKey( 'velocity', $state );
		$this->assertArrayHasKey( 'personal_best', $state );
		$this->assertArrayHasKey( 'pbest_fitness', $state );
		$this->assertArrayHasKey( 'samples', $state );
	}

	// ------------------------------------------------------------------
	// Parameter accessors.
	// ------------------------------------------------------------------

	public function test_get_parameter_by_key() {
		$this->service->maybe_init_particle( $this->assistant_id );

		$value = $this->service->get_parameter( $this->assistant_id, 'model_quality_weight' );
		$this->assertEqualsWithDelta( 0.5, $value, 0.001 );
	}

	public function test_get_parameter_unknown_key_returns_null() {
		$this->service->maybe_init_particle( $this->assistant_id );

		$value = $this->service->get_parameter( $this->assistant_id, 'nonexistent_key' );
		$this->assertNull( $value );
	}

	public function test_get_all_parameters_returns_keyed_array() {
		$this->service->maybe_init_particle( $this->assistant_id );

		$params = $this->service->get_all_parameters( $this->assistant_id );
		$this->assertArrayHasKey( 'model_quality_weight', $params );
		$this->assertArrayHasKey( 'temperature_offset', $params );
		$this->assertArrayHasKey( 'async_threshold', $params );
		$this->assertArrayHasKey( 'capacity_weight', $params );
		$this->assertArrayHasKey( 'max_iterations_factor', $params );
		$this->assertArrayHasKey( 'cache_aggressiveness', $params );
		$this->assertArrayHasKey( 'cost_sensitivity', $params );
	}

	// ------------------------------------------------------------------
	// Global best.
	// ------------------------------------------------------------------

	public function test_global_best_defaults_to_zero_fitness() {
		$global = $this->service->get_global_best();
		$this->assertEqualsWithDelta( 0.0, $global['fitness'], 0.001 );
		$this->assertIsArray( $global['position'] );
	}

	// ------------------------------------------------------------------
	// Iteration filter.
	// ------------------------------------------------------------------

	public function test_filter_max_iterations_default_factor() {
		$this->service->maybe_init_particle( $this->assistant_id );

		$result = $this->service->filter_max_iterations(
			15,
			array( 'id' => $this->assistant_id )
		);

		// Default factor is 1.0 → 15 * 1.0 = 15.
		$this->assertEquals( 15, $result );
	}

	public function test_filter_max_iterations_with_custom_factor() {
		$this->service->maybe_init_particle( $this->assistant_id );

		// Set a higher factor.
		$position    = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );
		$position[4] = 1.5; // max_iterations_factor.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', $position );

		$result = $this->service->filter_max_iterations(
			10,
			array( 'id' => $this->assistant_id )
		);

		// 10 * 1.5 = 15.
		$this->assertEquals( 15, $result );
	}

	public function test_filter_max_iterations_enforces_bounds() {
		$this->service->maybe_init_particle( $this->assistant_id );

		// Set very high factor.
		$position    = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );
		$position[4] = 2.0;
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', $position );

		$result = $this->service->filter_max_iterations(
			40,
			array( 'id' => $this->assistant_id )
		);

		// 40 * 2.0 = 80, but capped at 50.
		$this->assertLessThanOrEqual( 50, $result );
	}

	public function test_filter_max_iterations_no_config_passthrough() {
		$result = $this->service->filter_max_iterations( 15, null );
		$this->assertEquals( 15, $result );
	}

	// ------------------------------------------------------------------
	// Reset.
	// ------------------------------------------------------------------

	public function test_reset_particle_clears_state() {
		$this->service->maybe_init_particle( $this->assistant_id );

		$this->service->reset_particle( $this->assistant_id );

		$position = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );
		$this->assertEmpty( $position );
	}

	public function test_reset_swarm_clears_everything() {
		$this->service->maybe_init_particle( $this->assistant_id );

		// Create a second assistant.
		$second_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		$this->service->maybe_init_particle( $second_id );

		// Set global best.
		update_option(
			'wp_mcp_ai_pso_global_best',
			array(
				'position'     => array( 0.9 ),
				'fitness'      => 99.0,
				'assistant_id' => $this->assistant_id,
				'updated_at'   => time(),
			)
		);

		$this->service->reset_swarm();

		$this->assertFalse( get_option( 'wp_mcp_ai_pso_global_best' ) );

		$position1 = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );
		$position2 = get_post_meta( $second_id, '_wp_mcp_ai_pso_position', true );
		$this->assertEmpty( $position1 );
		$this->assertEmpty( $position2 );
	}

	// ------------------------------------------------------------------
	// Enabled / disabled.
	// ------------------------------------------------------------------

	public function test_disabled_when_setting_off() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_pso_optimizer' => false ) );
		$svc = new WP_MCP_AI_PSO_Optimizer_Service();
		$this->assertFalse( $svc->is_enabled() );
	}

	public function test_enabled_when_setting_on() {
		$this->assertTrue( $this->service->is_enabled() );
	}

	// ------------------------------------------------------------------
	// Swarm summary.
	// ------------------------------------------------------------------

	public function test_swarm_summary_structure() {
		$this->service->maybe_init_particle( $this->assistant_id );

		$summary = $this->service->get_swarm_summary();

		$this->assertArrayHasKey( 'global_best', $summary );
		$this->assertArrayHasKey( 'total_conversations', $summary );
		$this->assertArrayHasKey( 'current_inertia', $summary );
		$this->assertArrayHasKey( 'particle_count', $summary );
		$this->assertArrayHasKey( 'dimensions', $summary );
	}

	// ------------------------------------------------------------------
	// Full PSO update cycle.
	// ------------------------------------------------------------------

	public function test_run_pso_update_changes_position() {
		$this->service->maybe_init_particle( $this->assistant_id );

		// Set personal best to a non-default position so there's a pull.
		$pbest = array( 0.8, 0.1, 0.9, 0.2, 1.5, 0.9, 0.3 );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_pbest', $pbest );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_pbest_fit', 5.0 );

		// Set a global best.
		update_option(
			'wp_mcp_ai_pso_global_best',
			array(
				'position'     => array( 0.7, -0.1, 0.8, 0.3, 1.8, 0.8, 0.2 ),
				'fitness'      => 10.0,
				'assistant_id' => 999,
				'updated_at'   => time(),
			)
		);

		$before = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );

		$this->service->run_pso_update( $this->assistant_id );

		$after = get_post_meta( $this->assistant_id, '_wp_mcp_ai_pso_position', true );

		// Position should have changed because there's personal and social pull.
		$this->assertNotEquals( $before, $after );
	}

	// ------------------------------------------------------------------
	// Orchestration Preset integration.
	// ------------------------------------------------------------------

	public function test_pso_adaptive_preset_exists() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-orchestration-presets.php';

		$presets_service = new WP_MCP_AI_Orchestration_Presets();
		$preset         = $presets_service->get_preset( 'pso_adaptive' );

		$this->assertNotNull( $preset, 'pso_adaptive preset should exist' );
		$this->assertArrayHasKey( 'name', $preset );
		$this->assertArrayHasKey( 'settings', $preset );
		$this->assertEquals( 'optimization', $preset['category'] );
	}

	public function test_pso_adaptive_preset_enables_optimizer() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-orchestration-presets.php';

		$presets_service = new WP_MCP_AI_Orchestration_Presets();
		$preset         = $presets_service->get_preset( 'pso_adaptive' );

		$this->assertTrue( $preset['settings']['enable_pso_optimizer'] );
		$this->assertArrayHasKey( 'pso_inertia_start', $preset['settings'] );
		$this->assertArrayHasKey( 'pso_learning_rate_personal', $preset['settings'] );
		$this->assertArrayHasKey( 'pso_learning_rate_social', $preset['settings'] );
		$this->assertArrayHasKey( 'pso_update_frequency', $preset['settings'] );
	}

	public function test_pso_adaptive_preset_has_expected_defaults() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-orchestration-presets.php';

		$presets_service = new WP_MCP_AI_Orchestration_Presets();
		$preset         = $presets_service->get_preset( 'pso_adaptive' );
		$settings       = $preset['settings'];

		$this->assertEqualsWithDelta( 0.9, $settings['pso_inertia_start'], 0.001 );
		$this->assertEqualsWithDelta( 0.4, $settings['pso_inertia_end'], 0.001 );
		$this->assertEqualsWithDelta( 1.5, $settings['pso_learning_rate_personal'], 0.001 );
		$this->assertEqualsWithDelta( 2.0, $settings['pso_learning_rate_social'], 0.001 );
		$this->assertEquals( 10, $settings['pso_update_frequency'] );
	}

	public function test_recommend_preset_matches_pso_keywords() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-orchestration-presets.php';

		$presets_service = new WP_MCP_AI_Orchestration_Presets();

		$keywords = array( 'optimize performance', 'adaptive tuning', 'self-tuning system', 'improve over time', 'evolving strategy', 'tune parameters', 'enable pso' );

		foreach ( $keywords as $keyword ) {
			$result = $presets_service->recommend_preset_for_task( $keyword );
			$this->assertArrayHasKey( 'pso_adaptive', $result['recommendations'], "Keyword '$keyword' should recommend pso_adaptive" );
		}
	}

	public function test_recommend_preset_does_not_match_unrelated() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-orchestration-presets.php';

		$presets_service = new WP_MCP_AI_Orchestration_Presets();
		$result         = $presets_service->recommend_preset_for_task( 'write a blog post about cats' );

		$this->assertArrayNotHasKey( 'pso_adaptive', $result['recommendations'] );
	}

	public function test_pso_adaptive_preset_can_be_applied() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-orchestration-presets.php';

		$presets_service = new WP_MCP_AI_Orchestration_Presets();
		$result         = $presets_service->apply_preset( 'pso_adaptive' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['applied'] );
		$this->assertEquals( 'pso_adaptive', $result['preset'] );
		$this->assertTrue( $result['settings']['enable_pso_optimizer'] );

		// Verify it was stored as the active preset.
		$this->assertEquals( 'pso_adaptive', $presets_service->get_active_preset() );
	}
}
