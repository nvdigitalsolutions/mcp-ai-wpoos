<?php
/**
 * NV oOS LibreChat — REST Controller
 *
 * Handles addon-specific REST endpoints: health, config, code execution, speech.
 * Domain data flows through existing mcp-ai/v1/* routes.
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the LibreChat addon.
 *
 * @since 0.1.0
 */
class NV_oOS_LibreChat_REST {

	/**
	 * REST namespace for this addon.
	 *
	 * @var string
	 */
	const NAMESPACE = 'nvoos-librechat/v1';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => array( __CLASS__, 'health_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/config',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return is_user_logged_in() || self::is_guest_allowed();
				},
				'callback'            => array( __CLASS__, 'get_config' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/code/execute',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return is_user_logged_in() && current_user_can( 'edit_posts' );
				},
				'callback'            => array( __CLASS__, 'execute_code' ),
				'args'                => array(
					'language' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'enum'              => array( 'python', 'javascript', 'typescript', 'go', 'cpp', 'java', 'php', 'rust' ),
					),
					'code'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/code/result/(?P<job_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'callback'            => array( __CLASS__, 'get_code_result' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/speech/transcribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'callback'            => array( __CLASS__, 'transcribe_audio' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/speech/synthesize',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'callback'            => array( __CLASS__, 'synthesize_speech' ),
				'args'                => array(
					'text'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'voice' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'alloy',
					),
				),
			)
		);
	}

	/**
	 * Check if guest access is allowed.
	 *
	 * @return bool
	 */
	private static function is_guest_allowed() {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		return ! empty( $settings['allow_guests'] );
	}

	/**
	 * Health check endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health_check() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => NVOOS_LIBRECHAT_VERSION,
			)
		);
	}

	/**
	 * Get client configuration.
	 *
	 * @param WP_REST_Request $request Request object (unused — config is request-agnostic).
	 * @return WP_REST_Response
	 */
	public static function get_config( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings  = NV_oOS_LibreChat_Plugin::get_settings();
		$rest_url  = rest_url();
		$namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' )
			? WP_MCP_AI_REST::REST_NAMESPACE
			: 'mcp-ai/v1';

		return rest_ensure_response(
			array(
				'messagesEndpoint'           => esc_url_raw( $rest_url . $namespace . '/chat-client' ),
				'transcriptsEndpoint'        => esc_url_raw( $rest_url . $namespace . '/chat-transcripts' ),
				'memoryEndpoint'             => esc_url_raw( $rest_url . $namespace . '/chat-memory' ),
				'uploadEndpoint'             => esc_url_raw( $rest_url . 'wp/v2/media' ),
				'codeExecuteEndpoint'        => esc_url_raw( $rest_url . self::NAMESPACE . '/code/execute' ),
				'codeResultEndpointTemplate' => esc_url_raw( $rest_url . self::NAMESPACE . '/code/result/{job_id}' ),
				'speechTranscribeEndpoint'   => esc_url_raw( $rest_url . self::NAMESPACE . '/speech/transcribe' ),
				'speechSynthesizeEndpoint'   => esc_url_raw( $rest_url . self::NAMESPACE . '/speech/synthesize' ),
				'restNonce'                  => wp_create_nonce( 'wp_rest' ),
				'userId'                     => get_current_user_id(),
				'theme'                      => $settings['theme'],
				'features'                   => array(
					'codeInterpreter' => (bool) $settings['enable_code_interpreter'],
					'webSearch'       => (bool) $settings['enable_web_search'],
					'speech'          => (bool) $settings['enable_speech'],
					'artifacts'       => (bool) $settings['enable_artifacts'],
				),
			)
		);
	}

	/**
	 * Execute code in sandboxed interpreter.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function execute_code( WP_REST_Request $request ) {
		$language = $request->get_param( 'language' );
		$code     = $request->get_param( 'code' );

		// Rate limit check.
		$user_id       = get_current_user_id();
		$transient_key = 'nvoos_librechat_code_exec_' . $user_id;
		$exec_count    = (int) get_transient( $transient_key );

		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$max_exec = absint( $settings['max_executions_per_hour'] );

		if ( $exec_count >= $max_exec ) {
			return new WP_Error(
				'nvoos_librechat_rate_limit',
				sprintf(
					/* translators: %d: max executions per hour */
					__( 'Rate limit reached. Maximum %d code executions per hour.', 'nvoos-librechat' ),
					$max_exec
				),
				array( 'status' => 429 )
			);
		}

		// Generate job ID.
		$job_id = wp_generate_uuid4();

		// Store execution request as transient for async processing.
		set_transient(
			'nvoos_librechat_code_job_' . $job_id,
			array(
				'status'   => 'queued',
				'language' => $language,
				'code'     => $code,
				'user_id'  => $user_id,
				'created'  => time(),
			),
			3600
		);

		// Increment rate limit counter.
		set_transient( $transient_key, $exec_count + 1, HOUR_IN_SECONDS );

		// Schedule async execution via WP-Cron.
		wp_schedule_single_event(
			time(),
			'nvoos_librechat_process_code_execution',
			array( $job_id )
		);

		return rest_ensure_response(
			array(
				'job_id'  => $job_id,
				'status'  => 'queued',
				'message' => __( 'Code execution queued. Poll /code/result/{job_id} for results.', 'nvoos-librechat' ),
			)
		);
	}

	/**
	 * Get code execution result.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_code_result( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );
		$job    = get_transient( 'nvoos_librechat_code_job_' . $job_id );

		if ( false === $job ) {
			return new WP_Error(
				'nvoos_librechat_job_not_found',
				__( 'Code execution job not found or expired.', 'nvoos-librechat' ),
				array( 'status' => 404 )
			);
		}

		$user_id = get_current_user_id();
		if ( (int) $job['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'nvoos_librechat_unauthorized',
				__( 'You are not authorized to view this job.', 'nvoos-librechat' ),
				array( 'status' => 403 )
			);
		}

		return rest_ensure_response( $job );
	}

	/**
	 * Transcribe audio to text.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function transcribe_audio( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['audio'] ) ) {
			return new WP_Error(
				'nvoos_librechat_missing_audio',
				__( 'No audio file provided.', 'nvoos-librechat' ),
				array( 'status' => 400 )
			);
		}

		$audio_file = $files['audio'];

		// Validate file type.
		$allowed_types = array( 'audio/webm', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/flac' );
		if ( ! in_array( $audio_file['type'], $allowed_types, true ) ) {
			return new WP_Error(
				'nvoos_librechat_invalid_audio_type',
				__( 'Unsupported audio format.', 'nvoos-librechat' ),
				array( 'status' => 400 )
			);
		}

		// Validate file size (25MB max).
		if ( $audio_file['size'] > 25 * MB_IN_BYTES ) {
			return new WP_Error(
				'nvoos_librechat_audio_too_large',
				__( 'Audio file exceeds maximum size of 25MB.', 'nvoos-librechat' ),
				array( 'status' => 400 )
			);
		}

		// Defer to speech service (stub — returns placeholder until service is built).
		return rest_ensure_response(
			array(
				'text'   => __( 'Speech transcription is not yet configured. Please set up a speech provider in the LibreChat settings.', 'nvoos-librechat' ),
				'status' => 'not_configured',
			)
		);
	}

	/**
	 * Synthesize text to speech.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function synthesize_speech( WP_REST_Request $request ) {
		$text  = $request->get_param( 'text' );
		$voice = $request->get_param( 'voice' );

		// Defer to speech service (stub — returns placeholder until service is built).
		return rest_ensure_response(
			array(
				'status'  => 'not_configured',
				'message' => __( 'Speech synthesis is not yet configured. Please set up a speech provider in the LibreChat settings.', 'nvoos-librechat' ),
			)
		);
	}
}
