<?php
/**
 * WP_MCP_AI_Tool_Check_Jukebox_Status (ecosystem port - Wave F2, dj-management jukebox slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/dj-management/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface require gains an exists-check seam resolving from the addon's D8-compat
 * copy; the jukebox-service require resolves from `NVOOS_CONTENT_GRAPH_PRO_PATH`.
 *
 * Check Jukebox Status tool.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage DJ_Management
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-jukebox-service.php';

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
		return __( 'Check Jukebox Status', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks if OpenAI Jukebox is installed and properly configured on the server. Returns installation status, Python path, and Jukebox installation path.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			// Empty stdClass encodes as `{}`; an empty PHP array would encode
			// as `[]`, which strict providers (DeepSeek) reject.
			'properties'           => new stdClass(),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to check Jukebox status.', 'nvoos-content-graph-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Capability check - requires manage_options to view system configuration.
		if ( $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check Jukebox status.', 'nvoos-content-graph-pro' )
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
				'step_1' => __( 'Install Python 3.7+ on your server.', 'nvoos-content-graph-pro' ),
				'step_2' => __( 'Clone the Jukebox repository: git clone https://github.com/openai/jukebox.git', 'nvoos-content-graph-pro' ),
				'step_3' => __( 'Install Jukebox dependencies: pip install -r jukebox/requirements.txt', 'nvoos-content-graph-pro' ),
				'step_4' => __( 'Install additional dependencies: pip install mpi4py av', 'nvoos-content-graph-pro' ),
				'step_5' => __( 'Configure the installation path in NV oOS settings (Settings → NV oOS → Tools → Jukebox).', 'nvoos-content-graph-pro' ),
				'note'   => __( 'Jukebox requires significant GPU resources (CUDA-capable GPU with 16GB+ VRAM recommended).', 'nvoos-content-graph-pro' ),
			);
		} else {
			$output['available_models'] = array(
				'1b_lyrics' => __( 'Small model with lyrics support (faster, lower quality)', 'nvoos-content-graph-pro' ),
				'5b'        => __( 'Large model without lyrics support (better quality)', 'nvoos-content-graph-pro' ),
				'5b_lyrics' => __( 'Large model with lyrics support (best quality, slowest)', 'nvoos-content-graph-pro' ),
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
			'pro',                  // Pro feature.
			'read-only',            // Does not modify data.
			'local-execution',      // Checks local system.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
