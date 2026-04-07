<?php
/**
 * Algorave Tool — Modify Pattern
 *
 * Modifies an existing pattern by changing tempo, key, effects, or structure.
 *
 * @package NV_oOS_Algorave
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
		return __( 'Modify Algorave Pattern', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Modify an existing live coding pattern. Use this tool when the user asks to change the tempo, key, scale, add effects (reverb, delay, distortion), add or remove instruments, change the rhythm, or make any other modification to a pattern that was previously generated. Provide the original code and the modification instruction.', 'nvoos-algorave' );
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
					'description' => __( 'The existing pattern code to modify.', 'nvoos-algorave' ),
					'minLength'   => 1,
				),
				'modification' => array(
					'type'        => 'string',
					'description' => __( 'Description of what to change (e.g. "speed it up to 140bpm", "add reverb", "change to A minor", "remove the hi-hats", "make it more minimal").', 'nvoos-algorave' ),
					'minLength'   => 1,
					'maxLength'   => 1000,
				),
				'engine'       => array(
					'type'        => 'string',
					'description' => __( 'Synthesis engine of the code ("strudel" or "tonejs").', 'nvoos-algorave' ),
					'enum'        => array( 'strudel', 'tonejs' ),
					'default'     => 'strudel',
				),
				'pattern_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Optional: ID of a saved pattern to modify.', 'nvoos-algorave' ),
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
				'error'   => __( 'Both the original code and modification description are required.', 'nvoos-algorave' ),
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
				__( 'Please modify the pattern code according to: "%s". Return the complete modified code that can be pasted into the live coder.', 'nvoos-algorave' ),
				$modification
			),
			'instructions'  => __( 'The AI assistant should now produce the modified code based on the original pattern and the requested modification. The modified code should be complete and immediately playable.', 'nvoos-algorave' ),
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
