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

	use WP_MCP_AI_Tool_Default_Capability;

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
		return __( 'Generate a live coding music pattern from a natural language description. Returns executable Strudel or Tone.js code that produces sound in the browser. Use this when the user asks to create a beat, melody, rhythm, bassline, or any musical pattern. Strudel supports: mini-notation (*, /, ~, [], <>, ",", ?, !, (k,n), :n), effects chain (.room(), .delay(), .lpf(), .hpf(), .crush(), .distort(), .pan(), .phaser(), .shape(), .speed(), .gain()), sample banks (.bank("RolandTR808"), .bank("RolandTR909"), .bank("AkaiLinn") — 65+ drum machines available), pattern transformations (.every(), .sometimes(), .sometimesBy(), .slow(), .fast(), .rev(), .jux()), synthesizers (sawtooth, triangle, square, sine, fm), tempo control (setcps(), setcpm()), MIDI output (.midi()), and visual feedback (.pianoroll(), ._pianoroll(), .punchcard(), ._punchcard(), .color()). When generating Strudel code, prefer idiomatic mini-notation with effects and transformations for rich, dynamic patterns.', 'nvoos-algorave' );
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

		// Choose genre-specific scaffold for richer patterns.
		$genre_lower = strtolower( $genre );
		if ( false !== strpos( $genre_lower, 'ambient' ) ) {
			return $this->scaffold_strudel_ambient( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'drum' ) && false !== strpos( $genre_lower, 'bass' ) ) {
			return $this->scaffold_strudel_dnb( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'house' ) ) {
			return $this->scaffold_strudel_house( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'techno' ) ) {
			return $this->scaffold_strudel_techno( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'trap' ) ) {
			return $this->scaffold_strudel_trap( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'lo-fi' ) || false !== strpos( $genre_lower, 'lofi' ) ) {
			return $this->scaffold_strudel_lofi( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'dubstep' ) ) {
			return $this->scaffold_strudel_dubstep( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'dub' ) ) {
			return $this->scaffold_strudel_dub( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'trance' ) ) {
			return $this->scaffold_strudel_trance( $bpm, $scale );
		}
		if ( false !== strpos( $genre_lower, 'synthwave' ) || false !== strpos( $genre_lower, 'synth' ) ) {
			return $this->scaffold_strudel_synthwave( $bpm, $scale );
		}

		return $this->scaffold_strudel( $bpm, $scale );
	}

	/**
	 * Default Strudel scaffold with effects and transformations.
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Strudel\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Kick — four-on-the-floor\n"
			. "  s(\"bd*4\").bank(\"RolandTR808\").gain(0.8),\n"
			. "  // Hi-hats with variation\n"
			. "  s(\"hh*8\").bank(\"RolandTR808\").gain(\"[.4 .7]*4\")\n"
			. "    .pan(\"<-0.3 0.3>\"),\n"
			. "  // Snare on 2 and 4\n"
			. "  s(\"~ sd ~ sd\").bank(\"RolandTR808\").gain(0.7)\n"
			. "    .room(0.2),\n"
			. "  // Melodic element with effects\n"
			. "  note(\"c3 eb3 g3 bb3\").s(\"sawtooth\")\n"
			. "    .lpf(800).gain(0.4)\n"
			. "    .room(0.3).delay(0.15)\n"
			. "    .every(4, x => x.rev())\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Techno Strudel scaffold.
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_techno( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Techno\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Driving kick\n"
			. "  s(\"bd*4\").bank(\"RolandTR909\").gain(0.9)\n"
			. "    .shape(0.3),\n"
			. "  // Pulsing hi-hats\n"
			. "  s(\"hh*16\").bank(\"RolandTR909\")\n"
			. "    .gain(\"[.2 .5 .3 .7]*4\")\n"
			. "    .pan(sine.slow(4)),\n"
			. "  // Clap on 2 and 4\n"
			. "  s(\"~ cp ~ cp\").bank(\"RolandTR909\").gain(0.6)\n"
			. "    .room(0.3).delay(0.1),\n"
			. "  // Acid bassline\n"
			. "  note(\"c2 c2 eb2 c2 f2 c2 eb2 g2\")\n"
			. "    .s(\"sawtooth\").lpf(sine.range(200,2000).slow(8))\n"
			. "    .gain(0.5).distort(0.2)\n"
			. "    .sometimes(x => x.lpf(400)),\n"
			. "  // Atmospheric pad\n"
			. "  note(\"<c4 eb4 g4>\").s(\"triangle\")\n"
			. "    .gain(0.15).room(0.6).delay(0.25)\n"
			. "    .lpf(1200).slow(2)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Ambient Strudel scaffold.
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_ambient( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Ambient\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Evolving pad\n"
			. "  note(\"<c4 eb4 g4 bb4>\").s(\"sine\")\n"
			. "    .gain(0.3).room(0.8).delay(0.4)\n"
			. "    .lpf(sine.range(400,2000).slow(16))\n"
			. "    .slow(2),\n"
			. "  // Sparse melodic fragments\n"
			. "  note(\"c5 ~ ~ eb5 ~ g5 ~ ~\").s(\"triangle\")\n"
			. "    .gain(0.2).room(0.7).delay(0.5)\n"
			. "    .pan(sine.slow(6))\n"
			. "    .sometimes(x => x.speed(0.5)),\n"
			. "  // Sub bass drone\n"
			. "  note(\"c2\").s(\"sine\").gain(0.25)\n"
			. "    .lpf(200).slow(4),\n"
			. "  // Texture — gentle noise\n"
			. "  s(\"hh:3 ~ ~ hh:5 ~ ~ ~ ~\").gain(0.08)\n"
			. "    .room(0.9).delay(0.6).lpf(3000)\n"
			. "    .pan(\"<-0.5 0.5>\")\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * House Strudel scaffold.
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_house( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — House\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Classic house kick\n"
			. "  s(\"bd*4\").bank(\"RolandTR909\").gain(0.85),\n"
			. "  // Open hi-hat offbeat\n"
			. "  s(\"~ oh ~ oh\").bank(\"RolandTR909\").gain(0.4)\n"
			. "    .room(0.15),\n"
			. "  // Closed hi-hats with shuffle\n"
			. "  s(\"hh*8\").bank(\"RolandTR909\")\n"
			. "    .gain(\"[.3 .6]*4\"),\n"
			. "  // Clap on 2 and 4\n"
			. "  s(\"~ cp ~ cp\").bank(\"RolandTR909\").gain(0.65)\n"
			. "    .room(0.25),\n"
			. "  // Funky bassline\n"
			. "  note(\"c2 ~ c2 eb2 ~ c2 f2 ~\")\n"
			. "    .s(\"square\").lpf(600).gain(0.5)\n"
			. "    .every(8, x => x.rev()),\n"
			. "  // Chord stabs\n"
			. "  note(\"<[c4,eb4,g4] ~ ~ [f4,ab4,c5]>\")\n"
			. "    .s(\"sawtooth\").lpf(1500).gain(0.25)\n"
			. "    .room(0.3).delay(0.2).slow(2)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Drum and Bass Strudel scaffold.
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_dnb( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Drum and Bass\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Breakbeat kick pattern\n"
			. "  s(\"bd ~ ~ ~ bd ~ ~ bd ~ ~ bd ~ ~ ~ ~ ~\")\n"
			. "    .bank(\"RolandTR808\").gain(0.9).shape(0.2),\n"
			. "  // Snare hits — syncopated\n"
			. "  s(\"~ ~ ~ ~ sd ~ ~ ~ ~ ~ sd ~ ~ ~ sd ~\")\n"
			. "    .bank(\"RolandTR808\").gain(0.7)\n"
			. "    .room(0.2),\n"
			. "  // Fast hi-hats\n"
			. "  s(\"hh*16\").bank(\"RolandTR808\")\n"
			. "    .gain(\"[.2 .4 .3 .5]*4\")\n"
			. "    .sometimes(x => x.speed(1.5)),\n"
			. "  // Rolling sub bass\n"
			. "  note(\"c1 ~ c1 ~ ~ c1 eb1 ~\")\n"
			. "    .s(\"sine\").gain(0.6).lpf(150)\n"
			. "    .distort(0.1),\n"
			. "  // Reese bass mid layer\n"
			. "  note(\"c2 ~ ~ eb2 ~ ~ c2 ~\")\n"
			. "    .s(\"sawtooth\").lpf(sine.range(200,1200).slow(4))\n"
			. "    .gain(0.3).distort(0.3)\n"
			. "    .every(4, x => x.rev())\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Trap Strudel scaffold.
	 *
	 * @since 1.0.7
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_trap( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Trap\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Booming 808 kick\n"
			. "  s(\"bd ~ ~ ~ ~ ~ bd ~\").bank(\"RolandTR808\")\n"
			. "    .gain(0.95).shape(0.4),\n"
			. "  // Hard snare on 3\n"
			. "  s(\"~ ~ ~ ~ sd ~ ~ ~\").bank(\"RolandTR808\")\n"
			. "    .gain(0.8).room(0.15),\n"
			. "  // Rolling hi-hats\n"
			. "  s(\"hh*16\").bank(\"RolandTR808\")\n"
			. "    .gain(\"[.3 .2 .4 .2 .5 .2 .3 .2]*2\")\n"
			. "    .sometimes(x => x.speed(2)),\n"
			. "  // Deep 808 sub\n"
			. "  note(\"c1 ~ ~ ~ ~ ~ c1 ~\").s(\"sine\")\n"
			. "    .gain(0.7).lpf(80).distort(0.05)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Lo-Fi Strudel scaffold.
	 *
	 * @since 1.0.7
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_lofi( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Lo-Fi\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Dusty kick\n"
			. "  s(\"bd ~ ~ bd ~ ~ bd ~\").gain(0.7)\n"
			. "    .lpf(400).shape(0.1),\n"
			. "  // Muffled snare\n"
			. "  s(\"~ ~ sd ~ ~ ~ sd ~\").gain(0.5)\n"
			. "    .room(0.4).lpf(2000),\n"
			. "  // Soft hi-hats\n"
			. "  s(\"hh*8\").gain(\"[.2 .3]*4\")\n"
			. "    .lpf(3000).pan(sine.slow(3)),\n"
			. "  // Warm chord progression\n"
			. "  note(\"<[d4,f4,a4] [c4,e4,g4] [bb3,d4,f4] [a3,c4,e4]>\")\n"
			. "    .s(\"triangle\").gain(0.25).lpf(1200)\n"
			. "    .room(0.5).delay(0.3).slow(2)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Dub Strudel scaffold.
	 *
	 * @since 1.0.7
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_dub( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Dub\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Heavyweight kick\n"
			. "  s(\"bd ~ ~ ~ bd ~ ~ ~\").gain(0.85)\n"
			. "    .shape(0.2),\n"
			. "  // Delayed snare\n"
			. "  s(\"~ ~ ~ sd ~ ~ ~ ~\").gain(0.6)\n"
			. "    .room(0.6).delay(0.45),\n"
			. "  // Sparse hi-hats\n"
			. "  s(\"hh ~ hh ~ hh ~ hh ~\").gain(0.3)\n"
			. "    .room(0.3).pan(sine.slow(2)),\n"
			. "  // Deep sub bass\n"
			. "  note(\"c1 ~ c1 ~ ~ c1 ~ ~\").s(\"sine\")\n"
			. "    .gain(0.7).lpf(120).distort(0.05),\n"
			. "  // Echoed melodic stabs\n"
			. "  note(\"c3 ~ ~ eb3 ~ ~ g3 ~\").s(\"sine\")\n"
			. "    .gain(0.2).room(0.8).delay(0.6)\n"
			. "    .lpf(800).pan(\"<-0.6 0.6>\")\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Dubstep Strudel scaffold.
	 *
	 * @since 1.0.7
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_dubstep( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Dubstep (half-time)\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Sparse kick\n"
			. "  s(\"bd ~ ~ ~ ~ ~ ~ ~ bd ~ ~ ~ ~ ~ ~ ~\")\n"
			. "    .bank(\"RolandTR808\").gain(0.9).shape(0.3),\n"
			. "  // Heavy snare on 3\n"
			. "  s(\"~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ sd ~ ~ ~\")\n"
			. "    .bank(\"RolandTR808\").gain(0.85).room(0.2),\n"
			. "  // Minimal hi-hats\n"
			. "  s(\"hh*8\").bank(\"RolandTR808\")\n"
			. "    .gain(\"[.2 .3]*4\"),\n"
			. "  // Wobble bass\n"
			. "  note(\"c1 c1 c1 c1\").s(\"sawtooth\")\n"
			. "    .lpf(sine.range(80,1500).slow(0.5))\n"
			. "    .gain(0.6).distort(0.4)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Trance Strudel scaffold.
	 *
	 * @since 1.0.7
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_trance( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Trance\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Driving kick\n"
			. "  s(\"bd*4\").bank(\"RolandTR909\").gain(0.9).shape(0.2),\n"
			. "  // Clap on 2 and 4\n"
			. "  s(\"~ cp ~ cp\").bank(\"RolandTR909\").gain(0.55)\n"
			. "    .room(0.35),\n"
			. "  // Fast hi-hats\n"
			. "  s(\"hh*16\").bank(\"RolandTR909\")\n"
			. "    .gain(\"[.2 .4 .3 .5]*4\"),\n"
			. "  // Arpeggio lead\n"
			. "  note(\"a4 c5 e5 a5 e5 c5 a4 e4\")\n"
			. "    .s(\"sawtooth\").lpf(sine.range(800,4000).slow(8))\n"
			. "    .gain(0.35).room(0.4).delay(0.2),\n"
			. "  // Pad chords\n"
			. "  note(\"<[a3,c4,e4] [f3,a3,c4] [d3,f3,a3] [e3,g3,b3]>\")\n"
			. "    .s(\"sawtooth\").lpf(2000).gain(0.2)\n"
			. "    .room(0.5).slow(2)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
		);
	}

	/**
	 * Synthwave Strudel scaffold.
	 *
	 * @since 1.0.7
	 *
	 * @param int    $bpm   BPM.
	 * @param string $scale Scale.
	 * @return string
	 */
	private function scaffold_strudel_synthwave( $bpm, $scale ) {
		return sprintf(
			"// Algorave Pattern — Synthwave\n"
			. "// BPM: %d | Scale: %s\n"
			. "setcps(%s)\n\n"
			. "stack(\n"
			. "  // Punchy kick\n"
			. "  s(\"bd*4\").gain(0.8).shape(0.15),\n"
			. "  // Gated snare\n"
			. "  s(\"~ sd ~ sd\").gain(0.7)\n"
			. "    .room(0.5).delay(0.1),\n"
			. "  // Driving hi-hats\n"
			. "  s(\"hh*8\").gain(\"[.3 .5]*4\")\n"
			. "    .lpf(4000),\n"
			. "  // Pulsing bass\n"
			. "  note(\"a2 a2 e2 e2 f2 f2 d2 d2\")\n"
			. "    .s(\"square\").lpf(sine.range(300,1500).slow(8))\n"
			. "    .gain(0.45),\n"
			. "  // Retro pad\n"
			. "  note(\"<[a3,c4,e4] [f3,a3,c4]>\")\n"
			. "    .s(\"sawtooth\").lpf(3000).gain(0.2)\n"
			. "    .room(0.6).delay(0.25).slow(2)\n"
			. ')',
			$bpm,
			$scale,
			number_format( $bpm / 60 / 4, 4 )
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
