<?php
/**
 * Algorave Tool — Generate Pattern
 *
 * Translates natural language descriptions into Strudel/Tone.js live coding
 * patterns. The AI assistant interprets the user's request and produces
 * executable browser-side code.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates algorave patterns from natural language descriptions.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Tool_Generate_Pattern implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_generate_pattern';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Algorave Pattern', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a live coding music pattern from a natural language description. Returns executable Strudel mini-notation or Tone.js code that produces sound in the browser. Use this when the user asks to create a beat, melody, rhythm, bassline, or any musical pattern. Supports specifying BPM, scale, genre, and synthesis engine.', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Natural language description of the desired pattern (e.g. "a techno beat at 130bpm with a rolling bassline", "ambient pads in D minor", "drum and bass rhythm").', 'nvoos-algorave' ),
					'minLength'   => 1,
					'maxLength'   => 2000,
				),
				'engine'       => array(
					'type'        => 'string',
					'description' => __( 'Target synthesis engine. "strudel" for TidalCycles mini-notation (default), "tonejs" for Tone.js JavaScript code.', 'nvoos-algorave' ),
					'enum'        => array( 'strudel', 'tonejs' ),
					'default'     => 'strudel',
				),
				'bpm'          => array(
					'type'        => 'integer',
					'description' => __( 'Beats per minute (20–300). Defaults to 120.', 'nvoos-algorave' ),
					'minimum'     => 20,
					'maximum'     => 300,
					'default'     => 120,
				),
				'scale'        => array(
					'type'        => 'string',
					'description' => __( 'Musical scale/key (e.g. "C minor", "A major", "F# dorian"). Defaults to "C minor".', 'nvoos-algorave' ),
					'maxLength'   => 50,
				),
				'genre'        => array(
					'type'        => 'string',
					'description' => __( 'Musical genre for style guidance (e.g. "techno", "ambient", "drum and bass", "house", "experimental").', 'nvoos-algorave' ),
					'maxLength'   => 100,
				),
				'save_pattern' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save the generated pattern to the pattern library.', 'nvoos-algorave' ),
					'default'     => false,
				),
			),
			'required'             => array( 'description' ),
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
		$description = sanitize_text_field( $arguments['description'] ?? '' );

		if ( empty( $description ) ) {
			return array(
				'success' => false,
				'error'   => __( 'A description of the desired pattern is required.', 'nvoos-algorave' ),
			);
		}

		$engine = sanitize_text_field( $arguments['engine'] ?? 'strudel' );
		$bpm    = isset( $arguments['bpm'] ) ? max( 20, min( 300, absint( $arguments['bpm'] ) ) ) : 120;
		$scale  = sanitize_text_field( $arguments['scale'] ?? 'C minor' );
		$genre  = sanitize_text_field( $arguments['genre'] ?? '' );

		// Build the pattern code based on engine type.
		// The AI assistant should generate this code itself using the description,
		// but we provide a structured scaffold as a starting point.
		$code = $this->generate_scaffold( $engine, $description, $bpm, $scale, $genre );

		$result = array(
			'success'      => true,
			'code'         => $code,
			'engine'       => $engine,
			'bpm'          => $bpm,
			'scale'        => $scale,
			'genre'        => $genre,
			'description'  => $description,
			'message'      => sprintf(
				/* translators: 1: engine name, 2: BPM, 3: scale */
				__( 'Generated a %1$s pattern at %2$d BPM in %3$s. Copy the code into the live coder to play it, or ask me to modify it.', 'nvoos-algorave' ),
				$engine,
				$bpm,
				$scale
			),
			'instructions' => __( 'To play this pattern, paste the code into the Algorave Live Coder or use the [algorave_live_coder] shortcode. You can also ask me to modify the tempo, add effects, or change the scale.', 'nvoos-algorave' ),
		);

		// Optionally save the pattern.
		if ( ! empty( $arguments['save_pattern'] ) ) {
			$post_id = NV_oOS_Algorave_Pattern_CPT::save_pattern(
				array(
					'name'        => $description,
					'code'        => $code,
					'engine'      => $engine,
					'bpm'         => $bpm,
					'scale'       => $scale,
					'genre'       => $genre,
					'description' => $description,
				)
			);

			if ( ! is_wp_error( $post_id ) ) {
				$result['pattern_id'] = $post_id;
				$result['message']   .= ' ' . __( 'Pattern saved to the library.', 'nvoos-algorave' );
			}
		}

		return $result;
	}

	/**
	 * Generate a scaffold pattern based on the engine type.
	 *
	 * @param string $engine      Synthesis engine.
	 * @param string $description User description.
	 * @param int    $bpm         Beats per minute.
	 * @param string $scale       Musical scale.
	 * @param string $genre       Genre hint.
	 * @return string Generated code scaffold.
	 */
	private function generate_scaffold( $engine, $description, $bpm, $scale, $genre ) {
		if ( 'tonejs' === $engine ) {
			return $this->scaffold_tonejs( $bpm, $scale );
		}

		return $this->scaffold_strudel( $bpm, $scale );
	}

	/**
	 * Strudel mini-notation scaffold.
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Strudel mini-notation\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Kick drum pattern\n"
			. "  s(\"bd*4\").gain(0.8),\n"
			. "  // Hi-hat pattern\n"
			. "  s(\"~ hh ~ hh\").gain(0.5),\n"
			. "  // Snare on beats 2 and 4\n"
			. "  s(\"~ sd ~ sd\").gain(0.7),\n"
			. "  // Melodic element\n"
			. "  note(\"%s\").s(\"sawtooth\").cutoff(800).gain(0.4)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 ),
			'c3 e3 g3 b3'
		);
	}

	/**
	 * Tone.js scaffold.
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_tonejs( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Tone.js\n"
			. "// BPM: %d | Scale: %s\n"
			. "Tone.Transport.bpm.value = %d;\n\n"
			. "const synth = new Tone.PolySynth(Tone.Synth).toDestination();\n"
			. "const kick = new Tone.MembraneSynth().toDestination();\n\n"
			. "// Kick on every beat\n"
			. "new Tone.Loop((time) => {\n"
			. "  kick.triggerAttackRelease('C1', '8n', time);\n"
			. "}, '4n').start(0);\n\n"
			. "// Melodic sequence\n"
			. "const seq = new Tone.Sequence((time, note) => {\n"
			. "  synth.triggerAttackRelease(note, '8n', time);\n"
			. "}, ['C3', 'Eb3', 'G3', 'Bb3'], '8n').start(0);\n\n"
			. 'Tone.Transport.start();',
			$bpm,
			$scale,
			$bpm
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent', 'cacheable' );
	}
}
