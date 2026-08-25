<?php
/**
 * Stub tool that records the execution context it receives.
 *
 * Used by the workflow log-context tests to prove that tools keep receiving
 * the full accumulated `previous_results` array while the log context is
 * slimmed down.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Records the context passed to execute().
 */
class WP_MCP_AI_Workflow_Log_Context_Recorder_Tool implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * Last context seen by execute().
	 *
	 * @var array|null
	 */
	public $last_context = null;

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'wf_log_context_recorder';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Workflow Log Context Recorder';
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Test tool recording its execution context.';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$this->last_context = $context;

		return array(
			'echo' => isset( $arguments['message'] ) ? $arguments['message'] : '',
		);
	}
}
