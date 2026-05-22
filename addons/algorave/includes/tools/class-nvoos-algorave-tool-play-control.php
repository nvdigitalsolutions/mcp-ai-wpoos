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

	use WP_MCP_AI_Tool_Default_Capability;

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
		return __( 'Control audio playback in the browser. Use this when the user says "play", "stop", "pause", "record", or wants to change the BPM/CPS of the current playback or switch sample banks. Supports Strudel-native tempo via CPS (cycles per second) as well as BPM. Can switch between sample banks (RolandTR808, RolandTR909, AkaiLinn, RhythmAce, etc.).', 'nvoos-algorave' );
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
					'enum'        => array( 'play', 'stop', 'pause', 'record', 'set_bpm', 'set_cps', 'set_bank' ),
				),
				'code'   => array(
					'type'        => 'string',
					'description' => __( 'Pattern code to play (required for "play" action).', 'nvoos-algorave' ),
				),
				'bpm'    => array(
					'type'        => 'integer',
					'description' => __( 'BPM value (required for "set_bpm" action, 20-300).', 'nvoos-algorave' ),
					'minimum'     => 20,
					'maximum'     => 300,
				),
				'cps'    => array(
					'type'        => 'number',
					'description' => __( 'Cycles per second for Strudel-native tempo (required for "set_cps" action, 0.01-10). Example: 0.5 = 120 BPM with 4-beat cycle.', 'nvoos-algorave' ),
					'minimum'     => 0.01,
					'maximum'     => 10,
				),
				'bank'   => array(
					'type'        => 'string',
					'description' => __( 'Sample bank name (required for "set_bank" action). Options: RolandTR808, RolandTR909, RolandCR78, AkaiLinn, AkaiMPC, RhythmAce, KorgMinipops, KorgM1.', 'nvoos-algorave' ),
					'maxLength'   => 100,
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

		$valid_actions = array( 'play', 'stop', 'pause', 'record', 'set_bpm', 'set_cps', 'set_bank' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid action. Use: play, stop, pause, record, set_bpm, set_cps, or set_bank.', 'nvoos-algorave' ),
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
				$result['cps']     = round( $bpm / 60 / 4, 4 );
				$result['message'] = sprintf(
					/* translators: 1: BPM value, 2: CPS value */
					__( 'BPM set to %1$d (CPS: %2$s).', 'nvoos-algorave' ),
					$bpm,
					number_format( $bpm / 60 / 4, 4 )
				);
				break;

			case 'set_cps':
				$cps               = isset( $arguments['cps'] ) ? max( 0.01, min( 10, floatval( $arguments['cps'] ) ) ) : 0.5;
				$bpm               = intval( round( $cps * 60 * 4 ) );
				$result['cps']     = round( $cps, 4 );
				$result['bpm']     = $bpm;
				$result['message'] = sprintf(
					/* translators: 1: CPS value, 2: BPM value */
					__( 'CPS set to %1$s (equivalent to %2$d BPM).', 'nvoos-algorave' ),
					number_format( $cps, 4 ),
					$bpm
				);
				break;

			case 'set_bank':
				$bank              = sanitize_text_field( $arguments['bank'] ?? 'RolandTR808' );
				$result['bank']    = $bank;
				$result['message'] = sprintf(
					/* translators: %s: bank name */
					__( 'Sample bank set to "%s". New drum patterns will use this bank.', 'nvoos-algorave' ),
					$bank
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
