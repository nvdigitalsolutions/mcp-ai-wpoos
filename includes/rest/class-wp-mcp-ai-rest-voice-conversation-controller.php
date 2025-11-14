<?php
/**
 * REST API controller for voice conversation orchestration.
 *
 * Handles the server-side orchestration of voice conversations by coordinating:
 * - Audio file upload
 * - Transcription using the transcribe_openai_audio tool
 * - AI response generation using the chat service
 * - Speech synthesis using the generate_openai_speech tool
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-controller-base.php';

/**
 * Voice Conversation REST controller.
 */
class WP_MCP_AI_REST_Voice_Conversation_Controller extends WP_MCP_AI_REST_Controller_Base {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $tool_registry;

	/**
	 * Assistant service instance.
	 *
	 * @var WP_MCP_AI_Assistant_Service
	 */
	protected $assistant_service;

	/**
	 * Chat service instance.
	 *
	 * @var WP_MCP_AI_Chat_Service
	 */
	protected $chat_service;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Tool_Registry     $tool_registry      Tool registry instance.
	 * @param WP_MCP_AI_Assistant_Service $assistant_service  Assistant service instance.
	 * @param WP_MCP_AI_Chat_Service      $chat_service       Chat service instance.
	 */
	public function __construct( $tool_registry, $assistant_service, $chat_service ) {
		$this->tool_registry     = $tool_registry;
		$this->assistant_service = $assistant_service;
		$this->chat_service      = $chat_service;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			'mcp-ai/v1',
			'/voice-conversation',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_voice_conversation' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_voice_conversation_args(),
			)
		);
	}

	/**
	 * Get argument schema for voice conversation endpoint.
	 *
	 * @return array
	 */
	protected function get_voice_conversation_args() {
		return array(
			'audio'                => array(
				'required'          => true,
				'description'       => __( 'Audio file to transcribe', 'wp-mcp-ai' ),
				'validate_callback' => function( $param, $request, $key ) {
					$files = $request->get_file_params();
					return isset( $files['audio'] );
				},
			),
			'assistant_id'         => array(
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'description'       => __( 'Assistant ID to use for the conversation', 'wp-mcp-ai' ),
			),
			'allow_guests'         => array(
				'required'          => false,
				'default'           => '0',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Allow guest access', 'wp-mcp-ai' ),
			),
			'conversation_history' => array(
				'required'          => false,
				'default'           => '[]',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Previous conversation history as JSON', 'wp-mcp-ai' ),
			),
		);
	}

	/**
	 * Check permission for voice conversation endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		// If allow_guests is enabled, allow the request
		$allow_guests = $request->get_param( 'allow_guests' ) === '1';
		
		if ( $allow_guests ) {
			// Guest access is allowed
			return true;
		}

		// Otherwise, require user to be logged in
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to use voice conversations.', 'wp-mcp-ai' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle voice conversation request.
	 *
	 * Orchestrates the full voice conversation workflow:
	 * 1. Upload audio file to media library
	 * 2. Transcribe audio using transcribe_openai_audio tool
	 * 3. Get AI response using chat service
	 * 4. Generate speech from response using generate_openai_speech tool
	 * 5. Return transcription, response text, and audio URL
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_voice_conversation( $request ) {
		$files        = $request->get_file_params();
		$assistant_id = $request->get_param( 'assistant_id' );
		$history_json = $request->get_param( 'conversation_history' );

		// Decode conversation history
		$conversation_history = json_decode( $history_json, true );
		if ( ! is_array( $conversation_history ) ) {
			$conversation_history = array();
		}

		// Step 1: Upload audio file to media library
		$audio_file = isset( $files['audio'] ) ? $files['audio'] : null;
		if ( ! $audio_file ) {
			return new WP_Error(
				'missing_audio',
				__( 'No audio file provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$attachment_id = $this->upload_audio_file( $audio_file );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Step 2: Transcribe audio
		$transcription = $this->transcribe_audio( $attachment_id );
		if ( is_wp_error( $transcription ) ) {
			// Clean up uploaded file
			wp_delete_attachment( $attachment_id, true );
			return $transcription;
		}

		$user_text = isset( $transcription['text'] ) ? $transcription['text'] : '';
		if ( empty( $user_text ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error(
				'empty_transcription',
				__( 'Could not transcribe audio. Please try again.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Step 3: Get AI response
		$ai_response = $this->get_ai_response( $user_text, $assistant_id, $conversation_history );
		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		$assistant_text = $ai_response;

		// Step 4: Generate speech from AI response
		$speech_result = $this->generate_speech( $assistant_text );
		if ( is_wp_error( $speech_result ) ) {
			return $speech_result;
		}

		$audio_url = isset( $speech_result['url'] ) ? $speech_result['url'] : '';

		// Return the complete conversation result
		return new WP_REST_Response(
			array(
				'success'        => true,
				'data'           => array(
					'user_text'      => $user_text,
					'assistant_text' => $assistant_text,
					'audio_url'      => $audio_url,
					'transcription'  => $transcription,
				),
			),
			200
		);
	}

	/**
	 * Upload audio file to media library.
	 *
	 * @param array $file File array from $_FILES.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected function upload_audio_file( $file ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Validate file type
		$allowed_types = array( 'audio/webm', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/ogg' );
		$file_type     = $file['type'];

		if ( ! in_array( $file_type, $allowed_types, true ) ) {
			return new WP_Error(
				'invalid_audio_type',
				__( 'Invalid audio file type. Allowed types: webm, mp4, mpeg, wav, ogg', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Upload file
		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'webm' => 'audio/webm',
					'mp4'  => 'audio/mp4',
					'mp3'  => 'audio/mpeg',
					'wav'  => 'audio/wav',
					'ogg'  => 'audio/ogg',
				),
			)
		);

		if ( isset( $upload['error'] ) ) {
			return new WP_Error(
				'upload_failed',
				$upload['error'],
				array( 'status' => 500 )
			);
		}

		// Create attachment
		$attachment_data = array(
			'post_mime_type' => $upload['type'],
			'post_title'     => 'Voice Recording ' . gmdate( 'Y-m-d H:i:s' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_data, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate attachment metadata
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		return $attachment_id;
	}

	/**
	 * Transcribe audio using the transcribe_openai_audio tool.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error Transcription result or error.
	 */
	protected function transcribe_audio( $attachment_id ) {
		$tool = $this->tool_registry->get_tool( 'transcribe_openai_audio' );

		if ( ! $tool ) {
			return new WP_Error(
				'tool_not_found',
				__( 'Transcription tool not available.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'translate'     => false, // Keep in original language
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Get AI response using the chat service.
	 *
	 * @param string $user_text          User's transcribed text.
	 * @param int    $assistant_id       Assistant ID.
	 * @param array  $conversation_history Previous conversation messages.
	 * @return string|WP_Error Response text or error.
	 */
	protected function get_ai_response( $user_text, $assistant_id, $conversation_history ) {
		// Build messages array from history
		$messages = array();
		
		foreach ( $conversation_history as $message ) {
			if ( isset( $message['role'] ) && isset( $message['content'] ) ) {
				$messages[] = array(
					'role'    => $message['role'],
					'content' => $message['content'],
				);
			}
		}

		// Add current user message
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_text,
		);

		// Use the assistant service to get configuration
		if ( ! $assistant_id && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$assistant_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		}

		// Get assistant configuration
		$assistant_config = null;
		if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		}

		if ( ! $assistant_config ) {
			return new WP_Error(
				'no_assistant',
				__( 'No assistant configured for voice conversations.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get the language model router
		if ( ! class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php';
		}

		$router = WP_MCP_AI_Language_Model_Router::instance();

		// Make the API call
		$response = $router->send_message(
			$messages,
			$assistant_config,
			array(
				'user_id'      => get_current_user_id(),
				'assistant_id' => $assistant_id,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Extract text from response
		$response_text = '';
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$response_text = $response['choices'][0]['message']['content'];
		} elseif ( isset( $response['content'] ) ) {
			$response_text = $response['content'];
		}

		if ( empty( $response_text ) ) {
			return new WP_Error(
				'empty_response',
				__( 'AI returned an empty response.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return $response_text;
	}

	/**
	 * Generate speech from text using the generate_openai_speech tool.
	 *
	 * @param string $text Text to convert to speech.
	 * @return array|WP_Error Speech result with URL or error.
	 */
	protected function generate_speech( $text ) {
		$tool = $this->tool_registry->get_tool( 'generate_openai_speech' );

		if ( ! $tool ) {
			return new WP_Error(
				'tool_not_found',
				__( 'Speech generation tool not available.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$result = $tool->execute(
			array(
				'text' => $text,
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}
}
