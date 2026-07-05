<?php
/**
 * Extended Cognition Tool — Capture Audio
 *
 * Implements the active sensing loop for the microphone sensor (auditory cortex).
 * Pushes a recording request to the browser, which captures audio using
 * MediaRecorder + Web Speech API for transcription. The AI receives
 * a transcript and/or ambient classification (speech/music/noise/silence).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-mcp-ai-ext-cog-tool.php';
require_once __DIR__ . '/trait-wp-mcp-ai-ext-cog-sensor-access.php';
/**
 * Audio capture tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Capture_Audio implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_capture_audio';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Capture Audio (Extended Cognition)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Record audio from the user\'s microphone for transcription and ambient sound classification.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'ext_cog_capture_audio',
			'description'         => 'Record audio from the user\'s microphone (auditory cortex input). The AI agent actively requests a microphone recording of specified duration. Returns a transcript of spoken words and/or an ambient sound classification (speech, music, noise, silence). Use this to hear what the user hears, understand spoken commands, identify audio context, or transcribe speech in real time. Requires microphone permission.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'       => array(
						'type'        => 'string',
						'description' => 'Active chat session ID for routing to the correct browser tab.',
					),
					'duration_seconds' => array(
						'type'        => 'integer',
						'description' => 'Recording duration in seconds (3–30). Default: 5.',
						'minimum'     => 3,
						'maximum'     => 30,
						'default'     => 5,
					),
					'transcribe'       => array(
						'type'        => 'boolean',
						'description' => 'Attempt speech-to-text transcription via Web Speech API. Default: true.',
						'default'     => true,
					),
					'language'         => array(
						'type'        => 'string',
						'description' => 'BCP-47 language tag for transcription (e.g. "en-US", "fr-FR"). Default: browser default.',
						'maxLength'   => 20,
					),
					'classify_ambient' => array(
						'type'        => 'boolean',
						'description' => 'Classify the ambient sound type (speech, music, noise, silence). Default: true.',
						'default'     => true,
					),
					'timeout_ms'       => array(
						'type'        => 'integer',
						'description' => 'Max milliseconds to wait for the browser to return audio data. Default: 35000.',
						'minimum'     => 5000,
						'maximum'     => 60000,
						'default'     => 35000,
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'extended-cognition', 'sensors', 'audio' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return new WP_Error( 'https_required', __( 'Audio capture requires a secure (HTTPS) connection.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos' ) );
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();

		if ( empty( $settings['sensor_microphone'] ) ) {
			return new WP_Error( 'sensor_disabled', __( 'The microphone sensor is disabled in Extended Cognition settings.', 'mcp-ai-wpoos' ) );
		}

		$session_id       = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$duration         = isset( $arguments['duration_seconds'] ) ? max( 3, min( 30, absint( $arguments['duration_seconds'] ) ) ) : 5;
		$transcribe       = ! isset( $arguments['transcribe'] ) || ! empty( $arguments['transcribe'] );
		$language         = isset( $arguments['language'] ) ? sanitize_text_field( $arguments['language'] ) : '';
		$classify_ambient = ! isset( $arguments['classify_ambient'] ) || ! empty( $arguments['classify_ambient'] );
		$timeout_ms       = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 35000;

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'A session_id is required to route sensor requests to the browser.', 'mcp-ai-wpoos' ) );
		}

		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$rate_limit = absint( $settings['rate_limit'] );
		if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, 'microphone', $rate_limit ) ) {
			return new WP_Error( 'rate_limited', __( 'Microphone capture rate limit exceeded. Please wait before requesting another recording.', 'mcp-ai-wpoos' ) );
		}

		$request_id = wp_generate_uuid4();
		WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
			$post_id,
			array(
				'type'             => 'capture_audio',
				'request_id'       => $request_id,
				'duration_seconds' => $duration,
				'transcribe'       => $transcribe,
				'language'         => $language,
				'classify_ambient' => $classify_ambient,
			)
		);

		// Poll for browser response.
		$timeout_s  = ceil( $timeout_ms / 1000 );
		$poll_start = time();
		$captured   = null;

		while ( ( time() - $poll_start ) < $timeout_s ) {
			$data = WP_MCP_AI_Ext_Cog_Sensor_Session::consume_data( $post_id, $request_id );
			if ( null !== $data ) {
				$captured = $data;
				break;
			}
			usleep( 400000 ); // 400ms.
		}

		if ( null === $captured ) {
			return new WP_Error(
				'capture_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Audio capture timed out after %d seconds. Ensure the browser tab is open and microphone permission is granted.', 'mcp-ai-wpoos' ),
					$timeout_s
				)
			);
		}

		return array(
			'success'                  => true,
			'sensor'                   => 'microphone',
			'captured_at'              => $captured['captured_at'],
			'duration_seconds'         => $duration,
			'transcript'               => isset( $captured['transcript'] ) ? sanitize_text_field( $captured['transcript'] ) : '',
			'ambient_label'            => isset( $captured['ambient_label'] ) ? sanitize_text_field( $captured['ambient_label'] ) : 'unknown',
			'language_detected'        => isset( $captured['language_detected'] ) ? sanitize_text_field( $captured['language_detected'] ) : '',
			'transcription_confidence' => isset( $captured['transcription_confidence'] ) ? floatval( $captured['transcription_confidence'] ) : null,
			'message'                  => __( 'Audio captured. Use the transcript to understand what was spoken and ambient_label for environmental context.', 'mcp-ai-wpoos' ),
		);
	}
}
