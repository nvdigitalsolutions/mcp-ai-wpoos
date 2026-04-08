<?php
/**
 * Algorave Tool — Strudel Reference
 *
 * Provides a comprehensive reference for Strudel live coding syntax,
 * effects, transformations, sample banks, and best practices.
 * Helps the AI assistant generate correct Strudel code.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strudel reference and documentation tool.
 *
 * @since 1.0.4
 */
class NV_oOS_Algorave_Tool_Strudel_Reference implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_strudel_reference';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Strudel Reference', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Get Strudel live coding reference documentation. Use this tool when you need to look up Strudel mini-notation syntax, available effects, pattern transformations, sample banks, synthesizer types, or MIDI output syntax before generating or modifying a pattern. Returns detailed reference for the requested topic. Always consult this before writing complex Strudel code.', 'nvoos-algorave' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'topic' => array(
					'type'        => 'string',
					'description' => __( 'Reference topic to look up.', 'nvoos-algorave' ),
					'enum'        => array( 'mini_notation', 'effects', 'transformations', 'sample_banks', 'synthesizers', 'tempo', 'midi', 'all' ),
					'default'     => 'all',
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
		$topic = sanitize_text_field( $arguments['topic'] ?? 'all' );

		$reference = array();

		if ( 'all' === $topic || 'mini_notation' === $topic ) {
			$reference['mini_notation'] = $this->get_mini_notation_reference();
		}

		if ( 'all' === $topic || 'effects' === $topic ) {
			$reference['effects'] = $this->get_effects_reference();
		}

		if ( 'all' === $topic || 'transformations' === $topic ) {
			$reference['transformations'] = $this->get_transformations_reference();
		}

		if ( 'all' === $topic || 'sample_banks' === $topic ) {
			$reference['sample_banks'] = $this->get_sample_banks_reference();
		}

		if ( 'all' === $topic || 'synthesizers' === $topic ) {
			$reference['synthesizers'] = $this->get_synthesizers_reference();
		}

		if ( 'all' === $topic || 'tempo' === $topic ) {
			$reference['tempo'] = $this->get_tempo_reference();
		}

		if ( 'all' === $topic || 'midi' === $topic ) {
			$reference['midi'] = $this->get_midi_reference();
		}

		return array(
			'success'   => true,
			'topic'     => $topic,
			'reference' => $reference,
			'message'   => sprintf(
				/* translators: %s: topic name */
				__( 'Strudel reference for "%s". Use this information to generate correct Strudel patterns.', 'nvoos-algorave' ),
				$topic
			),
		);
	}

	/**
	 * Mini-notation reference.
	 *
	 * @return array
	 */
	private function get_mini_notation_reference() {
		return array(
			'description' => 'Strudel mini-notation is a compact way to express musical patterns inline.',
			'syntax'      => array(
				array(
					'symbol'  => 'space',
					'name'    => 'Sequence',
					'example' => 's("bd sd hh cp")',
					'desc'    => 'Space-separated items play in sequence across one cycle.',
				),
				array(
					'symbol'  => '*n',
					'name'    => 'Speed up',
					'example' => 's("hh*8")',
					'desc'    => 'Repeat the element n times per cycle.',
				),
				array(
					'symbol'  => '/n',
					'name'    => 'Slow down',
					'example' => 's("bd/2")',
					'desc'    => 'Play the element once every n cycles.',
				),
				array(
					'symbol'  => '~',
					'name'    => 'Rest/silence',
					'example' => 's("bd ~ sd ~")',
					'desc'    => 'A silent step in the pattern.',
				),
				array(
					'symbol'  => '[ ]',
					'name'    => 'Sub-sequence',
					'example' => 's("bd [sd sd] hh cp")',
					'desc'    => 'Group items to fit in one step.',
				),
				array(
					'symbol'  => '< >',
					'name'    => 'Alternate',
					'example' => 's("<bd cp>")',
					'desc'    => 'Alternate between items each cycle.',
				),
				array(
					'symbol'  => ',',
					'name'    => 'Parallel/stack',
					'example' => 's("bd*4, hh*8")',
					'desc'    => 'Play patterns simultaneously (comma-separated).',
				),
				array(
					'symbol'  => '?',
					'name'    => 'Random',
					'example' => 's("hh*8?")',
					'desc'    => 'Each event has 50% chance of playing.',
				),
				array(
					'symbol'  => '!n',
					'name'    => 'Repeat',
					'example' => 's("bd!3 sd")',
					'desc'    => 'Repeat element n times in the sequence.',
				),
				array(
					'symbol'  => '(k,n)',
					'name'    => 'Euclidean',
					'example' => 's("bd(3,8)")',
					'desc'    => 'Distribute k hits evenly across n steps.',
				),
				array(
					'symbol'  => ':n',
					'name'    => 'Sample index',
					'example' => 's("hh:2 hh:5")',
					'desc'    => 'Select specific sample variation (0-indexed).',
				),
			),
		);
	}

	/**
	 * Effects reference.
	 *
	 * @return array
	 */
	private function get_effects_reference() {
		return array(
			'description' => 'Strudel effects are chained with dot notation after a pattern. Order matters: distortion/crush → filter → gain → reverb → delay → pan.',
			'effects'     => array(
				array(
					'name'    => '.gain(value)',
					'range'   => '0.0 - 1.0',
					'desc'    => 'Volume control.',
					'example' => 's("bd*4").gain(0.8)',
				),
				array(
					'name'    => '.room(value)',
					'range'   => '0.0 - 1.0',
					'desc'    => 'Reverb amount / room size.',
					'example' => 's("sd").room(0.5)',
				),
				array(
					'name'    => '.delay(value)',
					'range'   => '0.0 - 1.0',
					'desc'    => 'Echo/delay amount.',
					'example' => 's("cp").delay(0.3)',
				),
				array(
					'name'    => '.lpf(freq)',
					'range'   => '20 - 20000 Hz',
					'desc'    => 'Lowpass filter cutoff frequency.',
					'example' => 'note("c3").s("sawtooth").lpf(800)',
				),
				array(
					'name'    => '.hpf(freq)',
					'range'   => '20 - 20000 Hz',
					'desc'    => 'Highpass filter cutoff frequency.',
					'example' => 's("hh*8").hpf(3000)',
				),
				array(
					'name'    => '.crush(bits)',
					'range'   => '1 - 16',
					'desc'    => 'Bitcrusher for lo-fi effects. Lower = more crushed.',
					'example' => 's("bd sd").crush(4)',
				),
				array(
					'name'    => '.distort(amount)',
					'range'   => '0.0 - 1.0',
					'desc'    => 'Distortion / overdrive.',
					'example' => 'note("c2").s("sawtooth").distort(0.4)',
				),
				array(
					'name'    => '.pan(value)',
					'range'   => '-1 (left) to 1 (right)',
					'desc'    => 'Stereo panning. Can be patterned.',
					'example' => 's("hh*4").pan("<-0.5 0.5>")',
				),
				array(
					'name'    => '.shape(amount)',
					'range'   => '0.0 - 1.0',
					'desc'    => 'Waveshaping distortion.',
					'example' => 's("bd*4").shape(0.3)',
				),
				array(
					'name'    => '.speed(rate)',
					'range'   => '0.1 - 10',
					'desc'    => 'Sample playback speed. 2 = octave up, 0.5 = octave down.',
					'example' => 's("bd").speed("<1 2 0.5>")',
				),
				array(
					'name'    => '.phaser(speed)',
					'range'   => '0.1 - 10',
					'desc'    => 'Phaser effect.',
					'example' => 'note("c3 e3 g3").s("square").phaser(0.5)',
				),
			),
			'patternable' => 'All effect values can be patterned: .gain("[.3 .7]*4"), .lpf(sine.range(200,2000).slow(8)), .pan("<-1 0 1>").',
		);
	}

	/**
	 * Pattern transformations reference.
	 *
	 * @return array
	 */
	private function get_transformations_reference() {
		return array(
			'description'     => 'Transformations modify patterns over time for evolving, dynamic music.',
			'transformations' => array(
				array(
					'name'    => '.every(n, fn)',
					'desc'    => 'Apply transformation every n cycles.',
					'example' => 's("bd*4").every(4, x => x.rev())',
				),
				array(
					'name'    => '.sometimes(fn)',
					'desc'    => 'Apply transformation randomly (~50% of the time).',
					'example' => 's("hh*8").sometimes(x => x.speed(2))',
				),
				array(
					'name'    => '.sometimesBy(prob, fn)',
					'desc'    => 'Apply transformation with given probability (0-1).',
					'example' => 's("bd sd").sometimesBy(0.3, x => x.crush(4))',
				),
				array(
					'name'    => '.slow(n)',
					'desc'    => 'Stretch pattern over n cycles.',
					'example' => 'note("c3 e3 g3 b3").slow(2)',
				),
				array(
					'name'    => '.fast(n)',
					'desc'    => 'Compress pattern to play n times per cycle.',
					'example' => 's("bd sd").fast(2)',
				),
				array(
					'name'    => '.rev()',
					'desc'    => 'Reverse the pattern.',
					'example' => 'note("c3 e3 g3 b3").rev()',
				),
				array(
					'name'    => '.jux(fn)',
					'desc'    => 'Apply transformation to right channel only (stereo split).',
					'example' => 's("bd*4").jux(x => x.rev())',
				),
			),
			'composition'     => array(
				array(
					'name'    => 'stack(...patterns)',
					'desc'    => 'Layer multiple patterns simultaneously.',
					'example' => 'stack(s("bd*4"), s("hh*8"), note("c3 e3 g3"))',
				),
				array(
					'name'    => 'cat(...patterns)',
					'desc'    => 'Play patterns one after another.',
					'example' => 'cat(s("bd*4"), s("cp*2"))',
				),
			),
		);
	}

	/**
	 * Sample banks reference.
	 *
	 * @return array
	 */
	private function get_sample_banks_reference() {
		return array(
			'description' => 'Sample banks change the character of drum sounds. Use .bank("name") to switch.',
			'banks'       => array(
				array(
					'name'    => 'RolandTR808',
					'desc'    => 'Classic analog drum machine. Warm, punchy sounds.',
					'sounds'  => 'bd, sd, hh, oh, cp, lt, mt, ht, rs, cb, cl',
					'example' => 's("bd sd hh cp").bank("RolandTR808")',
				),
				array(
					'name'    => 'RolandTR909',
					'desc'    => 'Iconic house/techno drum machine. Crisp, powerful hits.',
					'sounds'  => 'bd, sd, hh, oh, cp, lt, mt, ht, rs, rd, cr',
					'example' => 's("bd*4").bank("RolandTR909")',
				),
				array(
					'name'    => 'RolandCR78',
					'desc'    => 'Vintage rhythm machine. Thin, distinctive character.',
					'sounds'  => 'bd, sd, hh, oh, rs, cb',
					'example' => 's("bd sd hh hh").bank("RolandCR78")',
				),
				array(
					'name'    => 'AkaiLinn',
					'desc'    => 'Digital drum machine. Clean, precise samples.',
					'sounds'  => 'bd, sd, hh, oh, cp, tm',
					'example' => 's("bd cp sd cp").bank("AkaiLinn")',
				),
				array(
					'name'    => 'RhythmAce',
					'desc'    => 'Vintage rhythm unit. Lo-fi, characterful sounds.',
					'sounds'  => 'bd, sd, hh, rs',
					'example' => 's("bd*4").bank("RhythmAce")',
				),
				array(
					'name'    => 'KorgMinipops',
					'desc'    => 'Classic rhythm machine. Delicate, acoustic-like tones.',
					'sounds'  => 'bd, sd, hh, oh, cb',
					'example' => 's("bd sd hh oh").bank("KorgMinipops")',
				),
			),
			'patternable' => 'Banks can be patterned: .bank("<RolandTR808 RolandTR909>")',
			'usage'       => 'Default samples (without .bank()) use the dirt-samples library: bd, sd, hh, oh, cp, mt, lt, ht, cr, rd, rim, cb, cl.',
		);
	}

	/**
	 * Synthesizers reference.
	 *
	 * @return array
	 */
	private function get_synthesizers_reference() {
		return array(
			'description'  => 'Strudel includes built-in synthesizers via its superdough audio engine.',
			'synthesizers' => array(
				array(
					'name'    => 'sawtooth',
					'desc'    => 'Bright, harmonics-rich waveform. Great for leads and basses.',
					'example' => 'note("c3 e3 g3").s("sawtooth").lpf(1200)',
				),
				array(
					'name'    => 'square',
					'desc'    => 'Hollow, reedy tone. Good for bass and retro leads.',
					'example' => 'note("c2 eb2 g2").s("square").lpf(800)',
				),
				array(
					'name'    => 'triangle',
					'desc'    => 'Soft, mellow waveform. Ideal for pads and sub-bass.',
					'example' => 'note("c4 e4 g4").s("triangle").gain(0.3)',
				),
				array(
					'name'    => 'sine',
					'desc'    => 'Pure tone, no harmonics. Perfect for sub-bass and gentle pads.',
					'example' => 'note("c2").s("sine").gain(0.5)',
				),
			),
			'note_format'  => 'Notes use lowercase with octave: c3, eb3, f#4, bb2. Chords: [c3,e3,g3]. Scales: note("c3 d3 e3 f3 g3 a3 b3").',
			'modulation'   => 'LFOs can modulate parameters: .lpf(sine.range(200,2000).slow(8)) creates a filter sweep. Works with: sine, cosine, saw, square, tri.',
		);
	}

	/**
	 * Tempo reference.
	 *
	 * @return array
	 */
	private function get_tempo_reference() {
		return array(
			'description' => 'Strudel uses cycles per second (CPS) for tempo, not BPM directly.',
			'functions'   => array(
				array(
					'name'    => 'setcps(n)',
					'desc'    => 'Set cycles per second. This is the primary Strudel tempo control.',
					'example' => 'setcps(0.5) // = 120 BPM with 4-beat cycle',
				),
				array(
					'name'    => 'setcpm(n)',
					'desc'    => 'Set cycles per minute. More intuitive for some users.',
					'example' => 'setcpm(30) // = 120 BPM with 4-beat cycle',
				),
			),
			'conversion'  => array(
				'formula'  => 'cps = bpm / 60 / beats_per_cycle (typically 4)',
				'examples' => array(
					'60 BPM'  => 'setcps(0.25)',
					'80 BPM'  => 'setcps(0.3333)',
					'100 BPM' => 'setcps(0.4167)',
					'120 BPM' => 'setcps(0.5)',
					'130 BPM' => 'setcps(0.5417)',
					'140 BPM' => 'setcps(0.5833)',
					'160 BPM' => 'setcps(0.6667)',
					'170 BPM' => 'setcps(0.7083)',
					'180 BPM' => 'setcps(0.75)',
				),
			),
		);
	}

	/**
	 * MIDI output reference.
	 *
	 * @return array
	 */
	private function get_midi_reference() {
		return array(
			'description' => 'Strudel can send MIDI notes and control messages to external hardware/software via WebMIDI.',
			'functions'   => array(
				array(
					'name'    => '.midi()',
					'desc'    => 'Send pattern as MIDI to the first available output device.',
					'example' => 'note("c4 e4 g4").midi()',
				),
				array(
					'name'    => '.midi("device name")',
					'desc'    => 'Send MIDI to a specific named output device.',
					'example' => 'note("c3 e3 g3").midi("IAC Driver")',
				),
				array(
					'name'    => 'CC messages',
					'desc'    => 'Send MIDI Control Change messages for external parameter control.',
					'example' => 'ccn(74).ccv(sine.slow(4).range(0,127)).midi()',
				),
			),
			'setup'       => 'WebMIDI requires HTTPS or localhost. The browser will prompt for MIDI access permission. Connect hardware via USB-MIDI or use a virtual MIDI port (IAC Driver on macOS, MIDI Through on Linux) to route to DAWs.',
			'channels'    => 'MIDI channels can be patterned: .midichan("<0 1 2 3>").',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent', 'cacheable' );
	}
}
