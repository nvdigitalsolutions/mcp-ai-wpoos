<?php
/**
 * Example: Tool with Flow Stage Eligibility
 *
 * This example demonstrates how to implement a tool that can only be used
 * at the start of an agentic workflow. This is useful for initialization
 * tasks, configuration setup, or gathering initial context.
 *
 * @package WP_MCP_AI
 */

// Load the tool interface if running this example standalone.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	require_once __DIR__ . '/../../includes/tools/class-wp-mcp-ai-tool-interface.php';
}

/**
 * Example tool that demonstrates flow stage eligibility.
 *
 * This tool performs initialization tasks and should only be called
 * at the start of an agentic workflow.
 */
class WP_MCP_AI_Tool_Example_Initialize_Session implements
	WP_MCP_AI_Tool_Interface,
	WP_MCP_AI_Tool_Flow_Stage_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'initialize_session';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Initialize Session', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Initializes a new session with user preferences and context. Should only be called at the start of a workflow.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'User ID to initialize session for.', 'wp-mcp-ai' ),
				),
				'context' => array(
					'type'        => 'string',
					'description' => __( 'Additional context for the session.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_flow_stages() {
		// This tool can ONLY be used at the start of a workflow.
		// Attempting to use it in middle or end stages will result in an error.
		return array( 'start' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;

		if ( ! $user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'User ID is required for session initialization.', 'wp-mcp-ai' )
			);
		}

		// Get user data.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'invalid_user',
				__( 'User not found.', 'wp-mcp-ai' )
			);
		}

		// Initialize session data (example).
		$session_data = array(
			'session_id'   => wp_generate_uuid4(),
			'user_id'      => $user_id,
			'user_name'    => $user->display_name,
			'user_email'   => $user->user_email,
			'initialized_at' => current_time( 'mysql' ),
			'context'      => isset( $arguments['context'] ) ? sanitize_text_field( $arguments['context'] ) : '',
		);

		// In a real implementation, you might:
		// - Store session data in transients or database
		// - Load user preferences
		// - Set up custom configurations
		// - Initialize tracking or analytics

		return array(
			'success'      => true,
			'session_data' => $session_data,
			'message'      => sprintf(
				/* translators: %s: user display name */
				__( 'Session initialized for %s', 'wp-mcp-ai' ),
				$user->display_name
			),
		);
	}
}

// Example usage:
// 
// 1. Register the tool with the registry:
//    $registry = WP_MCP_AI_Tool_Registry::get_instance();
//    $registry->register_tool( new WP_MCP_AI_Tool_Example_Initialize_Session() );
//
// 2. Execute at start of workflow (iteration 0):
//    $context = array(
//        'iteration'      => 0,
//        'max_iterations' => 5,
//    );
//    $result = $registry->execute_tool( 
//        'initialize_session', 
//        array( 'user_id' => 1 ), 
//        $context 
//    );
//    // Returns: array( 'success' => true, 'session_data' => [...] )
//
// 3. Attempting to execute in middle stage fails:
//    $context = array(
//        'iteration'      => 2,
//        'max_iterations' => 5,
//    );
//    $result = $registry->execute_tool( 
//        'initialize_session', 
//        array( 'user_id' => 1 ), 
//        $context 
//    );
//    // Returns: WP_Error with code 'tool_flow_stage_not_eligible'


/**
 * Example tool that can be used in multiple stages.
 */
class WP_MCP_AI_Tool_Example_Search_Data implements
	WP_MCP_AI_Tool_Interface,
	WP_MCP_AI_Tool_Flow_Stage_Interface {

	public function get_slug() {
		return 'search_data';
	}

	public function get_name() {
		return __( 'Search Data', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Searches for data. Can be used at start or middle of workflow, but not at the end.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => __( 'Search query.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * This tool can be used in start or middle stages.
	 * It's restricted from end stage because it's meant for
	 * gathering information, not final output.
	 */
	public function get_flow_stages() {
		return array( 'start', 'middle' );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		if ( empty( $query ) ) {
			return new WP_Error( 'missing_query', __( 'Search query is required.', 'wp-mcp-ai' ) );
		}

		// Perform search (example implementation).
		$results = array(
			'query'   => $query,
			'results' => array(
				array( 'id' => 1, 'title' => 'Example Result 1' ),
				array( 'id' => 2, 'title' => 'Example Result 2' ),
			),
			'count'   => 2,
		);

		return $results;
	}
}


/**
 * Example tool for finalization (end stage only).
 */
class WP_MCP_AI_Tool_Example_Generate_Report implements
	WP_MCP_AI_Tool_Interface,
	WP_MCP_AI_Tool_Flow_Stage_Interface {

	public function get_slug() {
		return 'generate_report';
	}

	public function get_name() {
		return __( 'Generate Report', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Generates a final report. Should only be used at the end of a workflow.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'format' => array(
					'type'        => 'string',
					'description' => __( 'Report format (html, pdf, json).', 'wp-mcp-ai' ),
					'enum'        => array( 'html', 'pdf', 'json' ),
					'default'     => 'html',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * This tool should only be called at the end of a workflow
	 * to generate the final output/report.
	 */
	public function get_flow_stages() {
		return array( 'end' );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$format = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'html';

		// Generate report (example).
		$report = array(
			'format'       => $format,
			'generated_at' => current_time( 'mysql' ),
			'content'      => 'Report content would be here...',
		);

		return $report;
	}
}
