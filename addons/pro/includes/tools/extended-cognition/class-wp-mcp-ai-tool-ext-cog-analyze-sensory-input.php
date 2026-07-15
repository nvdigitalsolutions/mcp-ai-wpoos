<?php
/**
 * Extended Cognition Tool — Analyze Sensory Input
 *
 * Composite tool that captures one or more sensors simultaneously and then
 * sends the captured data to a vision/language model for structured analysis.
 * This is the highest-level tool in the extended cognition toolkit — it
 * embodies the complete active sensing loop: request → capture → analyse → return.
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
 * Composite sensory analysis tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Analyze_Sensory_Input implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Analyze Sensory Input (Extended Cognition)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Composite tool that simultaneously captures multiple sensors and returns a structured multi-modal analysis.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_analyze_sensory_input';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'ext_cog_analyze_sensory_input',
			'description'         => 'Composite extended cognition tool: simultaneously captures one or more sensors (camera, screen, audio, motion) and returns a structured multi-modal analysis. This implements the full active sensing loop — the AI requests perceptual access, the environment is sampled, and the AI integrates the result into its reasoning. Use this for rich situational awareness: "What am I looking at?", "What is the user doing?", "What does the environment sound like while I can also see it?". Combines Clark & Chalmers extended mind theory with practical multi-modal AI.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'      => array(
						'type'        => 'string',
						'description' => 'Active chat session ID.',
					),
					'sensors'         => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'camera', 'screen', 'audio', 'motion' ),
						),
						'description' => 'Which sensors to sample. Can combine multiple sensors for multi-modal analysis.',
						'minItems'    => 1,
						'maxItems'    => 4,
					),
					'analysis_prompt' => array(
						'type'        => 'string',
						'description' => 'Specific question or analysis task for the captured data (e.g. "identify all objects visible", "transcribe any text on screen", "describe the user\'s emotional state based on voice and posture").',
						'maxLength'   => 1000,
					),
					'model'           => array(
						'type'        => 'string',
						'enum'        => array( 'auto', 'gpt-4.1', 'gpt-4.1-mini', 'gemini-3.1-flash', 'gemini-2.5-flash' ),
						'description' => 'Vision/analysis model to use. "auto" uses the assistant\'s configured provider. Default: auto.',
						'default'     => 'auto',
					),
					'timeout_ms'      => array(
						'type'        => 'integer',
						'description' => 'Max ms to wait for all sensor captures. Default: 20000.',
						'minimum'     => 5000,
						'maximum'     => 60000,
						'default'     => 20000,
					),
				),
				'required'   => array( 'session_id', 'sensors' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'extended-cognition', 'sensors', 'vision', 'audio' ),
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
			return new WP_Error( 'https_required', __( 'Sensory analysis requires a secure (HTTPS) connection.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos' ) );
		}

		$session_id      = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$sensors         = isset( $arguments['sensors'] ) && is_array( $arguments['sensors'] ) ? $arguments['sensors'] : array();
		$analysis_prompt = isset( $arguments['analysis_prompt'] ) ? sanitize_text_field( $arguments['analysis_prompt'] ) : '';
		$timeout_ms      = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 20000;

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'A session_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $sensors ) ) {
			return new WP_Error( 'missing_sensors', __( 'At least one sensor must be specified.', 'mcp-ai-wpoos' ) );
		}

		// Validate sensors.
		$valid_sensors = array( 'camera', 'screen', 'audio', 'motion' );
		$sensors       = array_values(
			array_filter(
				array_map( 'sanitize_text_field', $sensors ),
				function ( $s ) use ( $valid_sensors ) {
					return in_array( $s, $valid_sensors, true );
				}
			)
		);

		$settings = wp_mcp_ai_ext_cog_get_settings();
		$user_id  = get_current_user_id();
		$post_id  = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Push capture requests for all requested sensors.
		$request_map = array();
		foreach ( $sensors as $sensor ) {
			$sensor_key = 'sensor_' . $sensor;
			if ( empty( $settings[ $sensor_key ] ) ) {
				continue;
			}

			$rate_limit = absint( $settings['rate_limit'] );
			if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, $sensor, $rate_limit ) ) {
				continue;
			}

			$request_id             = wp_generate_uuid4();
			$request_map[ $sensor ] = $request_id;

			WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
				$post_id,
				array(
					'type'             => 'capture_' . ( 'camera' === $sensor ? 'visual' : $sensor ),
					'request_id'       => $request_id,
					'resolution'       => 'medium',
					'duration_seconds' => 5,
					'transcribe'       => true,
					'classify_ambient' => true,
					'sample_count'     => 5,
				)
			);
		}

		if ( empty( $request_map ) ) {
			return new WP_Error( 'no_sensors_available', __( 'None of the requested sensors are enabled or available.', 'mcp-ai-wpoos' ) );
		}

		// Poll until all sensors respond or timeout.
		$timeout_s     = ceil( $timeout_ms / 1000 );
		$poll_start    = time();
		$results       = array();
		$expected      = count( $request_map );
		$results_count = 0;

		while ( ( time() - $poll_start ) < $timeout_s && $results_count < $expected ) {
			foreach ( $request_map as $sensor => $request_id ) {
				if ( isset( $results[ $sensor ] ) ) {
					continue;
				}
				$data = WP_MCP_AI_Ext_Cog_Sensor_Session::consume_data( $post_id, $request_id );
				if ( null !== $data ) {
					$results[ $sensor ] = $data;
					++$results_count;
				}
			}
			if ( $results_count < $expected ) {
				usleep( 400000 ); // 400ms.
			}
		}

		// Build the composite output.
		$output = array(
			'success'         => true,
			'sensors_sampled' => array_keys( $results ),
			'sensors_missing' => array_values( array_diff( array_keys( $request_map ), array_keys( $results ) ) ),
			'analysis_prompt' => $analysis_prompt,
			'captured_at'     => time(),
			'sensory_data'    => array(),
		);

		foreach ( $results as $sensor => $data ) {
			switch ( $sensor ) {
				case 'camera':
					$output['sensory_data']['camera'] = array(
						'image_base64' => isset( $data['image_base64'] ) ? $data['image_base64'] : null,
						'image_mime'   => 'image/jpeg',
					);
					break;
				case 'screen':
					$output['sensory_data']['screen'] = array(
						'image_base64' => isset( $data['image_base64'] ) ? $data['image_base64'] : null,
						'image_mime'   => 'image/png',
					);
					break;
				case 'audio':
					$output['sensory_data']['audio'] = array(
						'transcript'    => isset( $data['transcript'] ) ? sanitize_text_field( $data['transcript'] ) : '',
						'ambient_label' => isset( $data['ambient_label'] ) ? sanitize_text_field( $data['ambient_label'] ) : 'unknown',
					);
					break;
				case 'motion':
					$output['sensory_data']['motion'] = array(
						'orientation' => array(
							'alpha' => isset( $data['alpha'] ) ? floatval( $data['alpha'] ) : null,
							'beta'  => isset( $data['beta'] ) ? floatval( $data['beta'] ) : null,
							'gamma' => isset( $data['gamma'] ) ? floatval( $data['gamma'] ) : null,
						),
						'is_mobile'   => isset( $data['is_mobile'] ) ? (bool) $data['is_mobile'] : false,
					);
					break;
			}
		}

		if ( $analysis_prompt ) {
			$output['message'] = sprintf(
				/* translators: %s: analysis prompt */
				__( 'Multi-modal sensory capture complete. Apply the following analysis: %s', 'mcp-ai-wpoos' ),
				$analysis_prompt
			);
		} else {
			$output['message'] = __( 'Multi-modal sensory capture complete. Integrate the captured data to build comprehensive situational awareness.', 'mcp-ai-wpoos' );
		}

		return $output;
	}
}
