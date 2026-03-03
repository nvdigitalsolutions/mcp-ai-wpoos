<?php
/**
 * Test Slash Commands Pro Workflows Phase 4
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

class Test_Slash_Commands_Pro_Workflows_Phase4 extends WP_UnitTestCase {

	protected $orchestrator;

	public function setUp(): void {
		parent::setUp();
		$this->orchestrator = WP_MCP_AI_Slash_Command_Workflow_Orchestrator::get_instance();
	}

	public function test_orchestrator_instance() {
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Workflow_Orchestrator', $this->orchestrator );
	}

	public function test_comprehensive_ecommerce_suite_registered() {
		$workflows = $this->get_workflows();
		$this->assertArrayHasKey( 'comprehensive_ecommerce_suite', $workflows );
	}

	public function test_comprehensive_ecommerce_suite_structure() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['comprehensive_ecommerce_suite'];

		$this->assertArrayHasKey( 'name', $workflow );
		$this->assertArrayHasKey( 'description', $workflow );
		$this->assertArrayHasKey( 'steps', $workflow );
		$this->assertIsArray( $workflow['steps'] );
	}

	public function test_comprehensive_ecommerce_suite_steps_count() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['comprehensive_ecommerce_suite'];

		$this->assertCount( 5, $workflow['steps'] );
	}

	public function test_comprehensive_ecommerce_suite_step_commands() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['comprehensive_ecommerce_suite'];

		$expected_commands = array(
			'ecom-analytics',
			'inventory-forecast',
			'customer-segment',
			'discount-optimize',
			'upsell-suggest',
		);

		foreach ( $workflow['steps'] as $index => $step ) {
			$this->assertArrayHasKey( 'command', $step );
			$this->assertEquals( $expected_commands[ $index ], $step['command'] );
		}
	}

	public function test_comprehensive_ecommerce_suite_has_params() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['comprehensive_ecommerce_suite'];

		foreach ( $workflow['steps'] as $step ) {
			$this->assertArrayHasKey( 'params', $step );
			$this->assertIsArray( $step['params'] );
		}
	}

	public function test_video_production_complete_registered() {
		$workflows = $this->get_workflows();
		$this->assertArrayHasKey( 'video_production_complete', $workflows );
	}

	public function test_video_production_complete_structure() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['video_production_complete'];

		$this->assertArrayHasKey( 'name', $workflow );
		$this->assertArrayHasKey( 'description', $workflow );
		$this->assertArrayHasKey( 'steps', $workflow );
		$this->assertIsArray( $workflow['steps'] );
	}

	public function test_video_production_complete_steps_count() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['video_production_complete'];

		$this->assertCount( 7, $workflow['steps'] );
	}

	public function test_video_production_complete_step_commands() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['video_production_complete'];

		$expected_commands = array(
			'video-edit',
			'video-trim',
			'video-effect',
			'video-music',
			'video-subtitle',
			'video-render',
			'video-publish',
		);

		foreach ( $workflow['steps'] as $index => $step ) {
			$this->assertArrayHasKey( 'command', $step );
			$this->assertEquals( $expected_commands[ $index ], $step['command'] );
		}
	}

	public function test_video_production_complete_parameter_placeholders() {
		$workflows = $this->get_workflows();
		$workflow  = $workflows['video_production_complete'];

		// Check for placeholder usage.
		$has_placeholders = false;
		foreach ( $workflow['steps'] as $step ) {
			if ( isset( $step['params'] ) ) {
				foreach ( $step['params'] as $param ) {
					if ( is_string( $param ) && strpos( $param, '{' ) !== false ) {
						$has_placeholders = true;
						break 2;
					}
				}
			}
		}

		$this->assertTrue( $has_placeholders, 'Workflow should use parameter placeholders' );
	}

	public function test_all_phase4_workflows_have_required_fields() {
		$workflows        = $this->get_workflows();
		$phase4_workflows = array( 'comprehensive_ecommerce_suite', 'video_production_complete' );

		foreach ( $phase4_workflows as $workflow_key ) {
			$this->assertArrayHasKey( $workflow_key, $workflows );

			$workflow = $workflows[ $workflow_key ];
			$this->assertArrayHasKey( 'name', $workflow );
			$this->assertArrayHasKey( 'description', $workflow );
			$this->assertArrayHasKey( 'steps', $workflow );

			$this->assertIsString( $workflow['name'] );
			$this->assertIsString( $workflow['description'] );
			$this->assertIsArray( $workflow['steps'] );
			$this->assertNotEmpty( $workflow['steps'] );
		}
	}

	public function test_all_phase4_workflow_steps_have_command_and_params() {
		$workflows        = $this->get_workflows();
		$phase4_workflows = array( 'comprehensive_ecommerce_suite', 'video_production_complete' );

		foreach ( $phase4_workflows as $workflow_key ) {
			$workflow = $workflows[ $workflow_key ];

			foreach ( $workflow['steps'] as $step_index => $step ) {
				$this->assertArrayHasKey( 'command', $step, "Step $step_index missing command in $workflow_key" );
				$this->assertArrayHasKey( 'params', $step, "Step $step_index missing params in $workflow_key" );
			}
		}
	}

	public function test_workflow_names_are_translatable() {
		$workflows        = $this->get_workflows();
		$phase4_workflows = array( 'comprehensive_ecommerce_suite', 'video_production_complete' );

		foreach ( $phase4_workflows as $workflow_key ) {
			$workflow = $workflows[ $workflow_key ];

			// Names should be translatable strings (not empty).
			$this->assertNotEmpty( $workflow['name'] );
			$this->assertNotEmpty( $workflow['description'] );
		}
	}

	/**
	 * Helper method to get workflows.
	 *
	 * @return array
	 */
	protected function get_workflows() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$method     = $reflection->getMethod( 'get_workflow_definitions' );
		$method->setAccessible( true );
		return $method->invoke( $this->orchestrator );
	}
}
