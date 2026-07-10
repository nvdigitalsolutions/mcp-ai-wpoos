<?php
/**
 * Page Agent Execute Tool
 *
 * This tool lets the NV oOS assistant LLM delegate browser-level actions
 * to the client-side Page Agent. Instead of executing server-side, it
 * returns a `page_agent_delegate` envelope that signals the chat UI to
 * invoke Page Agent in the browser.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: page_agent_execute
 *
 * Delegates a natural language instruction to the Page Agent running
 * in the user's browser. The Page Agent will interpret the instruction,
 * interact with the current page DOM, and return the result.
 *
 * @since 0.1.0
 */
class WP_MCP_AI_Tool_Page_Agent_Execute implements WP_MCP_AI_Tool_Interface {

	/**
	 * Unique slug for the tool.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'page_agent_execute';
	}

	/**
	 * Human readable name for the tool.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Page Agent Execute', 'nvoos-page-agent' );
	}

	/**
	 * Description of what the tool does.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Execute a natural language instruction on the current page through the browser. Use for UI-level operations like navigating admin menus, filling forms, clicking buttons, or reading page content. The instruction is delegated to the Alibaba Page Agent running in the user\'s browser.',
			'nvoos-page-agent'
		);
	}

	/**
	 * JSON schema describing accepted parameters.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'instruction'     => array(
					'type'        => 'string',
					'description' => __( 'Natural language instruction for the page agent (e.g., "Click Posts → Add New", "Fill the form with test data", "Read the current page title").', 'nvoos-page-agent' ),
				),
				'wait_for_result' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to wait for the page agent to complete before responding. Default: true.', 'nvoos-page-agent' ),
					'default'     => true,
				),
				'max_steps'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of browser interaction steps the agent may take for this instruction. Overrides the global setting.', 'nvoos-page-agent' ),
					'minimum'     => 1,
					'maximum'     => 200,
				),
			),
			'required'   => array( 'instruction' ),
		);
	}

	/**
	 * WordPress capability required to execute this tool.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * This tool is special: it does not execute server-side. Instead, it
	 * returns a delegate envelope that signals the chat UI to invoke the
	 * Page Agent in the user's browser.
	 *
	 * @since 0.1.0
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$instruction     = sanitize_text_field( $arguments['instruction'] );
		$wait_for_result = isset( $arguments['wait_for_result'] ) ? (bool) $arguments['wait_for_result'] : true;
		$max_steps       = isset( $arguments['max_steps'] ) ? absint( $arguments['max_steps'] ) : 0;

		if ( empty( $instruction ) ) {
			return array(
				'success' => false,
				'message' => __( 'Instruction cannot be empty.', 'nvoos-page-agent' ),
			);
		}

		// Build the delegate envelope.
		$envelope = array(
			'type'            => 'page_agent_delegate',
			'instruction'     => $instruction,
			'wait_for_result' => $wait_for_result,
			'status'          => 'pending',
			'timestamp'       => current_time( 'c' ),
		);

		if ( $max_steps > 0 ) {
			$envelope['max_steps'] = $max_steps;
		}

		return array(
			'success' => true,
			'message' => __( 'Page Agent instruction queued for browser execution.', 'nvoos-page-agent' ),
			'data'    => $envelope,
		);
	}
}
