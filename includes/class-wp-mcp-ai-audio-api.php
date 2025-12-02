<?php
/**
 * Provides dedicated REST API endpoints for audio operations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Audio_API' ) ) {
	/**
	 * Implements dedicated audio API endpoints for transcription and speech generation.
	 */
	class WP_MCP_AI_Audio_API {
		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Register the audio REST routes.
		 */
		public function register_routes() {
			$namespace = class_exists( 'WP_MCP_AI_REST' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

			register_rest_route(
				$namespace,
				'/audio/transcribe',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_transcribe_request' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'attachment_id' => array(
							'description'       => __( 'WordPress attachment ID containing the audio file.', 'wp-mcp-ai' ),
							'type'              => array( 'integer', 'string' ),
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'translate'     => array(
							'description' => __( 'Whether to translate the audio to English instead of transcribing.', 'wp-mcp-ai' ),
							'type'        => 'boolean',
							'required'    => false,
							'default'     => false,
						),
					),
				)
			);
		}

		/**
		 * Permission callback for audio endpoints.
		 *
		 * @param WP_REST_Request $request Request instance.
		 * @return true|WP_Error
		 */
		public function check_permissions( $request ) {
			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return new WP_Error(
					'wp_mcp_ai_audio_unauthenticated',
					__( 'Authentication is required to use the audio API.', 'wp-mcp-ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error(
					'wp_mcp_ai_audio_forbidden',
					__( 'You do not have permission to use the audio API.', 'wp-mcp-ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			return true;
		}

		/**
		 * Handle audio transcription request.
		 *
		 * @param WP_REST_Request $request Request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_transcribe_request( $request ) {
			$attachment_id = absint( $request->get_param( 'attachment_id' ) );
			$translate     = (bool) $request->get_param( 'translate' );

			if ( ! $attachment_id ) {
				return new WP_Error(
					'wp_mcp_ai_missing_attachment',
					__( 'Attachment ID is required.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			// Load the transcribe tool.
			$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php';
			if ( ! file_exists( $tool_file ) ) {
				return new WP_Error(
					'wp_mcp_ai_tool_not_found',
					__( 'Transcription tool not available.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			require_once $tool_file;

			if ( ! class_exists( 'WP_MCP_AI_Tool_Transcribe_OpenAI_Audio' ) ) {
				return new WP_Error(
					'wp_mcp_ai_tool_class_missing',
					__( 'Transcription tool class not found.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$tool = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();

			$arguments = array(
				'attachment_id' => $attachment_id,
				'translate'     => $translate,
			);

			$context = array(
				'user_id' => get_current_user_id(),
			);

			$result = $tool->execute( $arguments, $context );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}
	}
}
