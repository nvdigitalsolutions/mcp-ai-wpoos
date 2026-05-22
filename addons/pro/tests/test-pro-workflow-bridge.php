<?php
/**
 * Test the Pro Workflow Builder ↔ Base Orchestration Bridge.
 *
 * Validates that the bridge service wires up the agent injection
 * guardrail, the tool HITL approval gate, and the run-log mirror as
 * documented in `docs/orchestration-reference.md` §5.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for WP_MCP_AI_Pro_Workflow_Bridge.
 */
class Test_Pro_Workflow_Bridge extends WP_UnitTestCase {

	/**
	 * Set up: instantiate the bridge so its hooks are wired.
	 */
	/** Set up test. */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Bridge' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-workflow-bridge.php';
		}

		WP_MCP_AI_Pro_Workflow_Bridge::get_instance();
	}

	/**
	 * The bridge must register the four expected hook listeners.
	 */
	public function test_bridge_registers_expected_hooks() {
		$bridge = WP_MCP_AI_Pro_Workflow_Bridge::get_instance();

		$this->assertNotFalse(
			has_filter( 'wp_mcp_ai_workflow_execute_agent', array( $bridge, 'guard_agent_prompt' ) ),
			'Agent prompt guardrail must be wired to wp_mcp_ai_workflow_execute_agent.'
		);
		$this->assertNotFalse(
			has_filter( 'wp_mcp_ai_pro_workflow_pre_execute_tool', array( $bridge, 'gate_tool_with_approval' ) ),
			'Tool approval gate must be wired to wp_mcp_ai_pro_workflow_pre_execute_tool.'
		);
		$this->assertNotFalse(
			has_action( 'wp_mcp_ai_pro_workflow_node_executed', array( $bridge, 'mirror_node_event' ) ),
			'Run-log mirror must listen to wp_mcp_ai_pro_workflow_node_executed.'
		);
		$this->assertNotFalse(
			has_action( 'wp_mcp_ai_pro_workflow_execution_saved', array( $bridge, 'finalize_run' ) ),
			'Run-log finalizer must listen to wp_mcp_ai_pro_workflow_execution_saved.'
		);
	}

	/**
	 * When a previous filter already short-circuited the agent node, the
	 * guardrail must respect that decision and not run the detector.
	 */
	public function test_guard_agent_prompt_respects_prior_short_circuit() {
		$bridge = WP_MCP_AI_Pro_Workflow_Bridge::get_instance();
		$prior  = array(
			'type'   => 'agent',
			'status' => 'completed',
			'result' => 'pre-handled',
		);
		$out    = $bridge->guard_agent_prompt( $prior, 'agent_a', 'hello world', array() );
		$this->assertSame( $prior, $out );
	}

	/**
	 * When the approval queue and tool registry are not present, the gate
	 * must not interfere with normal execution.
	 */
	public function test_gate_tool_with_approval_passthrough_when_no_registry() {
		$bridge = WP_MCP_AI_Pro_Workflow_Bridge::get_instance();
		$out    = $bridge->gate_tool_with_approval( null, 'nonexistent_tool', array(), array() );
		$this->assertNull( $out );
	}

	/**
	 * Mirroring a node event without an execution_id POST var must be a no-op
	 * (no run created, no PHP errors).
	 */
	public function test_mirror_node_event_without_execution_id_is_noop() {
		$bridge = WP_MCP_AI_Pro_Workflow_Bridge::get_instance();
		// Should not throw or warn even though execution_id is absent.
		unset( $_POST['execution_id'] );
		$bridge->mirror_node_event( 'tool', 'n1', 'wf1', array( 'type' => 'tool' ), array() );
		$this->assertTrue( true );
	}

	/**
	 * Finalizing an execution with no mapped run id must be a no-op.
	 */
	public function test_finalize_run_without_mapping_is_noop() {
		$bridge = WP_MCP_AI_Pro_Workflow_Bridge::get_instance();
		$bridge->finalize_run(
			array(
				'id'     => 'never-existed',
				'status' => 'completed',
			)
		);
		$this->assertTrue( true );
	}
}
