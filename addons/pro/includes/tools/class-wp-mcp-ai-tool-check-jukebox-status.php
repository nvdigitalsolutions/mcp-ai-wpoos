<?php
/**
 * Tool that checks Jukebox installation status.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-jukebox-service.php';

/**
 * Provides a tool for checking OpenAI Jukebox installation status.
 */
class WP_MCP_AI_Tool_Check_Jukebox_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_jukebox_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Jukebox Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks if OpenAI Jukebox is installed and properly configured on the server. Returns installation status, Python path, and Jukebox installation path.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to check Jukebox status.', 'wp-mcp-ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Capability check - requires manage_options to view system configuration.
		if ( $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check Jukebox status.', 'wp-mcp-ai' )
			);
		}

		// Check installation status.
		$service = new WP_MCP_AI_Jukebox_Service();
		$status  = $service->check_installation();

		// Build comprehensive response.
		$output = array(
			'installed'   => $status['installed'],
			'message'     => $status['message'],
			'python_path' => $status['python_path'],
		);

		if ( ! empty( $status['jukebox_path'] ) ) {
			$output['jukebox_path'] = $status['jukebox_path'];
		}

		// Add configuration information.
		$output['configuration'] = array(
			'python_path_setting'  => get_option( 'wp_mcp_ai_jukebox_python_path', 'python3' ),
			'install_path_setting' => get_option( 'wp_mcp_ai_jukebox_install_path', '' ),
		);

		// Add setup instructions if not installed.
		if ( ! $status['installed'] ) {
			$output['setup_instructions'] = array(
				'step_1' => __( 'Install Python 3.7+ on your server.', 'wp-mcp-ai' ),
				'step_2' => __( 'Clone the Jukebox repository: git clone https://github.com/openai/jukebox.git', 'wp-mcp-ai' ),
				'step_3' => __( 'Install Jukebox dependencies: pip install -r jukebox/requirements.txt', 'wp-mcp-ai' ),
				'step_4' => __( 'Install additional dependencies: pip install mpi4py av', 'wp-mcp-ai' ),
				'step_5' => __( 'Configure the installation path in WP oOS settings (Settings → WP oOS → Tools → Jukebox).', 'wp-mcp-ai' ),
				'note'   => __( 'Jukebox requires significant GPU resources (CUDA-capable GPU with 16GB+ VRAM recommended).', 'wp-mcp-ai' ),
			);
		} else {
			$output['available_models'] = array(
				'1b_lyrics' => __( 'Small model with lyrics support (faster, lower quality)', 'wp-mcp-ai' ),
				'5b'        => __( 'Large model without lyrics support (better quality)', 'wp-mcp-ai' ),
				'5b_lyrics' => __( 'Large model with lyrics support (best quality, slowest)', 'wp-mcp-ai' ),
			);
		}

		/**
		 * Allow third parties to filter the Jukebox status check result.
		 *
		 * @param array $output    Result array returned by the tool.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		return apply_filters( 'wp_mcp_ai_check_jukebox_status_result', $output, $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Does not modify data.
			'local-execution',      // Checks local system.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
