<?php
/**
 * Algorave Tool — MIDI Output
 *
 * Lists available WebMIDI output devices and provides guidance
 * for routing Strudel patterns to external hardware/DAWs via .midi().
 *
 * @package NV_oOS_Algorave_AI
 * @since   1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MIDI output configuration tool.
 *
 * @since 1.0.4
 */
class NV_oOS_Algorave_Tool_MIDI_Output implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_midi_output';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'MIDI Output', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Configure MIDI output for routing Strudel patterns to external hardware synthesizers, drum machines, or DAWs via WebMIDI. Use this when the user wants to send MIDI from the browser to external devices, list available MIDI ports, or get code examples for .midi() output. The actual MIDI device list is detected in the browser; this tool provides guidance and code patterns.', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'MIDI action to perform.', 'nvoos-algorave-ai' ),
					'enum'        => array( 'list_devices', 'generate_code', 'help' ),
					'default'     => 'help',
				),
				'device_name' => array(
					'type'        => 'string',
					'description' => __( 'Target MIDI device name for code generation (optional — uses first available if empty).', 'nvoos-algorave-ai' ),
					'maxLength'   => 200,
				),
				'channel'     => array(
					'type'        => 'integer',
					'description' => __( 'MIDI channel (0-15).', 'nvoos-algorave-ai' ),
					'minimum'     => 0,
					'maximum'     => 15,
					'default'     => 0,
				),
			),
			'required'             => array(),
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
		$action = sanitize_text_field( $arguments['action'] ?? 'help' );

		switch ( $action ) {
			case 'list_devices':
				return $this->list_devices();

			case 'generate_code':
				return $this->generate_midi_code( $arguments );

			default:
				return $this->get_help();
		}
	}

	/**
	 * Return MIDI device listing guidance.
	 *
	 * @return array
	 */
	private function list_devices() {
		return array(
			'success'          => true,
			'action'           => 'list_devices',
			'_browser_command' => true,
			'message'          => __( 'MIDI device detection runs in the browser via WebMIDI. The live coder will show available devices.', 'nvoos-algorave-ai' ),
			'instructions'     => __( 'The browser will detect available MIDI output devices. Ensure your MIDI device is connected via USB or use a virtual MIDI port (IAC Driver on macOS, MIDI Through on Linux) to route to DAWs. WebMIDI requires HTTPS or localhost.', 'nvoos-algorave-ai' ),
		);
	}

	/**
	 * Generate example MIDI output code.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function generate_midi_code( $arguments ) {
		$device_name = sanitize_text_field( $arguments['device_name'] ?? '' );
		$channel     = isset( $arguments['channel'] ) ? max( 0, min( 15, absint( $arguments['channel'] ) ) ) : 0;

		$midi_target = ! empty( $device_name )
			? sprintf( '.midi("%s")', esc_js( $device_name ) )
			: '.midi()';

		$channel_code = $channel > 0
			? sprintf( '.midichan(%d)', $channel )
			: '';

		$code = sprintf(
			"// MIDI Output Pattern\n"
			. "// Sends notes to %s\n"
			. "setcps(0.5)\n\n"
			. "// Melodic sequence → MIDI\n"
			. "note(\"c4 e4 g4 b4\")\n"
			. "  %s%s\n\n"
			. "// Drum pattern → MIDI (channel 10 is standard for drums)\n"
			. "// note(\"c1 ~ c1 e1 ~ c1 f#1 ~\")\n"
			. "//   .midichan(9)%s\n\n"
			. "// CC automation → MIDI\n"
			. '// ccn(74).ccv(sine.slow(4).range(0,127))%s',
			! empty( $device_name ) ? esc_html( $device_name ) : 'first available MIDI output',
			$channel_code,
			$midi_target,
			$midi_target,
			$midi_target
		);

		return array(
			'success'     => true,
			'action'      => 'generate_code',
			'code'        => $code,
			'device_name' => $device_name,
			'channel'     => $channel,
			'message'     => sprintf(
				/* translators: %s: MIDI device name or "default" */
				__( 'Generated MIDI output code targeting %s. Paste into the live coder to send MIDI.', 'nvoos-algorave-ai' ),
				! empty( $device_name ) ? esc_html( $device_name ) : __( 'the default MIDI output', 'nvoos-algorave-ai' )
			),
		);
	}

	/**
	 * Return MIDI help information.
	 *
	 * @return array
	 */
	private function get_help() {
		return array(
			'success'   => true,
			'action'    => 'help',
			'reference' => array(
				'overview'  => __( 'Strudel can send MIDI notes, CC messages, and clock sync to external devices via WebMIDI API.', 'nvoos-algorave-ai' ),
				'functions' => array(
					'.midi()'              => __( 'Send to first available MIDI output.', 'nvoos-algorave-ai' ),
					'.midi("Device")'      => __( 'Send to a named MIDI output device.', 'nvoos-algorave-ai' ),
					'.midichan(n)'         => __( 'Set MIDI channel (0-15, where 9 = drums).', 'nvoos-algorave-ai' ),
					'ccn(n).ccv(v).midi()' => __( 'Send MIDI CC messages (n=CC number, v=value 0-127).', 'nvoos-algorave-ai' ),
				),
				'setup'     => array(
					__( 'WebMIDI requires HTTPS or localhost.', 'nvoos-algorave-ai' ),
					__( 'Connect hardware synths via USB-MIDI.', 'nvoos-algorave-ai' ),
					__( 'Use virtual MIDI ports for DAW routing (IAC on macOS, MIDI Through on Linux).', 'nvoos-algorave-ai' ),
					__( 'Browser will prompt for MIDI access permission on first use.', 'nvoos-algorave-ai' ),
				),
			),
			'message'   => __( 'MIDI output reference. Use .midi() in Strudel patterns to route audio to external devices.', 'nvoos-algorave-ai' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent' );
	}
}
