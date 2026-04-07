<?php
/**
 * Algorave Tool — Play Control
 *
 * Sends play, stop, pause, and record commands to the browser-side
 * audio engine via the chat response.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controls audio playback in the browser.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Tool_Play_Control implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_play_control';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Algorave Play Control', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Control audio playback in the browser. Use this when the user says "play", "stop", "pause", "record", or wants to change the BPM of the current playback. The control command is sent to the browser-side audio engine.', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action' => array(
					'type'        => 'string',
					'description' => __( 'Playback action to perform.', 'nvoos-algorave' ),
					'enum'        => array( 'play', 'stop', 'pause', 'record', 'set_bpm' ),
				),
				'code'   => array(
					'type'        => 'string',
					'description' => __( 'Pattern code to play (required for "play" action).', 'nvoos-algorave' ),
				),
				'bpm'    => array(
					'type'        => 'integer',
					'description' => __( 'BPM value (required for "set_bpm" action).', 'nvoos-algorave' ),
					'minimum'     => 20,
					'maximum'     => 300,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = sanitize_text_field( $arguments['action'] ?? '' );

		$valid_actions = array( 'play', 'stop', 'pause', 'record', 'set_bpm' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid action. Use: play, stop, pause, record, or set_bpm.', 'nvoos-algorave' ),
			);
		}

		$result = array(
			'success'          => true,
			'action'           => $action,
			'_browser_command' => true,
		);

		switch ( $action ) {
			case 'play':
				$result['code']    = $arguments['code'] ?? '';
				$result['message'] = __( 'Playing the pattern. The browser audio engine will now start playback.', 'nvoos-algorave' );
				break;

			case 'stop':
				$result['message'] = __( 'Stopping playback. All audio will be silenced.', 'nvoos-algorave' );
				break;

			case 'pause':
				$result['message'] = __( 'Pausing playback. Audio will resume from the current position when played again.', 'nvoos-algorave' );
				break;

			case 'record':
				$result['message'] = __( 'Recording started. The browser will capture audio output.', 'nvoos-algorave' );
				break;

			case 'set_bpm':
				$bpm               = isset( $arguments['bpm'] ) ? max( 20, min( 300, absint( $arguments['bpm'] ) ) ) : 120;
				$result['bpm']     = $bpm;
				$result['message'] = sprintf(
					/* translators: %d: BPM value */
					__( 'BPM set to %d.', 'nvoos-algorave' ),
					$bpm
				);
				break;
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'local-only', 'idempotent' );
	}
}
