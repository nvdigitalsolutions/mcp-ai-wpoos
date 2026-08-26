<?php
/**
 * Algorave Tool — Modify Pattern
 *
 * Modifies an existing pattern by changing tempo, key, effects, or structure.
 *
 * @package NV_oOS_Algorave_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modifies existing algorave patterns.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Tool_Modify_Pattern implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_modify_pattern';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Modify Algorave Pattern', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Modify an existing live coding pattern. Use this when the user asks to change tempo, key, scale, add or adjust effects, add or remove instruments, change rhythm, or any other modification. Strudel effects: .room(0-1) reverb, .delay(0-1), .lpf(freq) lowpass, .hpf(freq) highpass, .crush(bits) bitcrusher, .distort(0-1), .pan(-1 to 1), .phaser(speed), .shape(0-1) distortion, .speed(rate), .gain(0-1). Strudel transformations: .every(n, fn) apply every n cycles, .sometimes(fn) random apply, .sometimesBy(prob, fn), .slow(n) stretch, .fast(n) compress, .rev() reverse, .jux(fn) split stereo. Sample banks: .bank("RolandTR808"), .bank("RolandTR909"), .bank("AkaiLinn"), .bank("RhythmAce"). Mini-notation: * speed, / slow, ~ rest, [] sub-sequence, <> alternate, "," parallel, ? random, ! repeat, (k,n) Euclidean, :n sample variation. Tempo: setcps(cycles_per_sec), setcpm(cycles_per_min). MIDI: .midi("device name"). Provide the original code and modification instruction.', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'code'         => array(
					'type'        => 'string',
					'description' => __( 'The existing pattern code to modify.', 'nvoos-algorave-ai' ),
					'minLength'   => 1,
				),
				'modification' => array(
					'type'        => 'string',
					'description' => __( 'Description of what to change (e.g. "speed it up to 140bpm", "add reverb", "change to A minor", "remove the hi-hats", "make it more minimal").', 'nvoos-algorave-ai' ),
					'minLength'   => 1,
					'maxLength'   => 1000,
				),
				'engine'       => array(
					'type'        => 'string',
					'description' => __( 'Synthesis engine of the code ("strudel" or "tonejs").', 'nvoos-algorave-ai' ),
					'enum'        => array( 'strudel', 'tonejs' ),
					'default'     => 'strudel',
				),
				'pattern_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Optional: ID of a saved pattern to modify.', 'nvoos-algorave-ai' ),
				),
			),
			'required'             => array( 'code', 'modification' ),
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
		$code         = $arguments['code'] ?? '';
		$modification = sanitize_text_field( $arguments['modification'] ?? '' );
		$engine       = sanitize_text_field( $arguments['engine'] ?? 'strudel' );

		if ( empty( $code ) || empty( $modification ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Both the original code and modification description are required.', 'nvoos-algorave-ai' ),
			);
		}

		// The AI assistant should perform the actual code modification.
		// This tool provides the structured response format.
		$result = array(
			'success'       => true,
			'original_code' => $code,
			'modification'  => $modification,
			'engine'        => $engine,
			'message'       => sprintf(
				/* translators: %s: modification description */
				__( 'Please modify the pattern code according to: "%s". Return the complete modified code that can be pasted into the live coder.', 'nvoos-algorave-ai' ),
				$modification
			),
			'instructions'  => __( 'The AI assistant should now produce the modified code based on the original pattern and the requested modification. The modified code should be complete and immediately playable.', 'nvoos-algorave-ai' ),
		);

		// If a pattern_id is provided, load metadata.
		if ( ! empty( $arguments['pattern_id'] ) ) {
			$pattern = NV_oOS_Algorave_Pattern_CPT::get_pattern( absint( $arguments['pattern_id'] ) );
			if ( $pattern ) {
				$result['pattern'] = $pattern;
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent' );
	}
}
