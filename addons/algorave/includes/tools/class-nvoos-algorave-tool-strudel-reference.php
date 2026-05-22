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

	use WP_MCP_AI_Tool_Default_Capability;

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
		return __( 'Get Strudel live coding reference documentation. Use this tool when you need to look up Strudel mini-notation syntax, available effects, pattern transformations, sample banks, synthesizer types, MIDI output syntax, or visual feedback features before generating or modifying a pattern. Returns detailed reference for the requested topic. Always consult this before writing complex Strudel code.', 'nvoos-algorave' );
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
					'enum'        => array( 'mini_notation', 'effects', 'transformations', 'sample_banks', 'synthesizers', 'tempo', 'midi', 'visual_feedback', 'all' ),
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

		if ( 'all' === $topic || 'visual_feedback' === $topic ) {
			$reference['visual_feedback'] = $this->get_visual_feedback_reference();
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
			'description'  => 'Sample banks change the character of drum sounds. Use .bank("name") to switch. 65+ drum machines are available from the tidal-drum-machines collection. Short aliases are also supported (e.g. TR808 for RolandTR808).',
			'banks'        => array(
				array(
					'name'    => 'RolandTR808',
					'alias'   => 'TR808',
					'desc'    => 'Classic analog drum machine. Warm, punchy sounds.',
					'sounds'  => 'bd, sd, hh, oh, cp, lt, mt, ht, rs, cb, cl',
					'example' => 's("bd sd hh cp").bank("RolandTR808")',
				),
				array(
					'name'    => 'RolandTR909',
					'alias'   => 'TR909',
					'desc'    => 'Iconic house/techno drum machine. Crisp, powerful hits.',
					'sounds'  => 'bd, sd, hh, oh, cp, lt, mt, ht, rs, rd, cr',
					'example' => 's("bd*4").bank("RolandTR909")',
				),
				array(
					'name'    => 'RolandTR707',
					'alias'   => 'TR707',
					'desc'    => 'Digital rhythm composer. Clean, punchy PCM samples.',
					'sounds'  => 'bd, sd, hh, oh, cp, lt, mt, ht, rs, cb, cr, rd, tb',
					'example' => 's("bd sd hh cp").bank("RolandTR707")',
				),
				array(
					'name'    => 'RolandTR606',
					'alias'   => 'TR606',
					'desc'    => 'Compact analog drum machine. Thin, characterful lo-fi tones.',
					'sounds'  => 'bd, sd, hh, oh, lt, ht',
					'example' => 's("bd*4 sd").bank("RolandTR606")',
				),
				array(
					'name'    => 'RolandCR78',
					'alias'   => 'Compurhythm78',
					'desc'    => 'Vintage rhythm machine. Thin, distinctive character.',
					'sounds'  => 'bd, sd, hh, oh, rs, cb',
					'example' => 's("bd sd hh hh").bank("RolandCR78")',
				),
				array(
					'name'    => 'AkaiLinn',
					'alias'   => 'Linn',
					'desc'    => 'Digital drum machine (LinnDrum). Clean, precise samples.',
					'sounds'  => 'bd, sd, hh, oh, cp, tm, cb, rd, cr',
					'example' => 's("bd cp sd cp").bank("AkaiLinn")',
				),
				array(
					'name'    => 'OberheimDMX',
					'alias'   => 'DMX',
					'desc'    => 'Classic hip-hop drum machine. Thick, punchy digital sounds.',
					'sounds'  => 'bd, sd, hh, oh, cp, tm, cr',
					'example' => 's("bd sd hh hh").bank("OberheimDMX")',
				),
				array(
					'name'    => 'EmuSP12',
					'alias'   => 'SP12',
					'desc'    => 'Sampling drum machine. Crunchy 12-bit character.',
					'sounds'  => 'bd, sd, hh, oh, cp, cr',
					'example' => 's("bd*4").bank("EmuSP12")',
				),
				array(
					'name'    => 'RhythmAce',
					'alias'   => 'Ace',
					'desc'    => 'Vintage rhythm unit. Lo-fi, characterful sounds.',
					'sounds'  => 'bd, sd, hh, rs',
					'example' => 's("bd*4").bank("RhythmAce")',
				),
				array(
					'name'    => 'KorgMinipops',
					'alias'   => 'Minipops',
					'desc'    => 'Classic rhythm machine. Delicate, acoustic-like tones.',
					'sounds'  => 'bd, sd, hh, oh, cb',
					'example' => 's("bd sd hh oh").bank("KorgMinipops")',
				),
				array(
					'name'    => 'KorgM1',
					'alias'   => 'M1',
					'desc'    => 'Iconic 80s workstation. Clean, polished drum sounds.',
					'sounds'  => 'bd, sd, hh, oh, cp',
					'example' => 's("bd sd hh cp").bank("KorgM1")',
				),
				array(
					'name'    => 'LinnLM1',
					'alias'   => 'LM1',
					'desc'    => 'First digital drum machine. Distinctive, natural samples.',
					'sounds'  => 'bd, sd, hh, oh, cp, cb, tm',
					'example' => 's("bd sd cp sd").bank("LinnLM1")',
				),
				array(
					'name'    => 'ViscoSpaceDrum',
					'alias'   => 'SpaceDrum',
					'desc'    => 'Analog space drums. Unique, otherworldly tones.',
					'sounds'  => 'bd, sd, tm',
					'example' => 's("bd sd tm sd").bank("ViscoSpaceDrum")',
				),
			),
			'all_machines' => implode(
				', ',
				array(
					'AJKPercusyn',
					'AkaiLinn',
					'AkaiMPC60',
					'AkaiXR10',
					'AlesisHR16',
					'AlesisSR16',
					'BossDR110',
					'BossDR220',
					'BossDR55',
					'BossDR550',
					'CasioRZ1',
					'CasioSK1',
					'CasioVL1',
					'DoepferMS404',
					'EmuDrumulator',
					'EmuSP12',
					'KorgDDM110',
					'KorgKPR77',
					'KorgKR55',
					'KorgKRZ',
					'KorgM1',
					'KorgMinipops',
					'KorgPoly800',
					'KorgT3',
					'Linn9000',
					'LinnLM1',
					'LinnLM2',
					'MoogConcertMateMG1',
					'OberheimDMX',
					'RhodesPolaris',
					'RhythmAce',
					'RolandCompurhythm78',
					'RolandCompurhythm1000',
					'RolandCompurhythm8000',
					'RolandD110',
					'RolandD70',
					'RolandDDR30',
					'RolandJD990',
					'RolandMC202',
					'RolandMC303',
					'RolandMT32',
					'RolandR8',
					'RolandS50',
					'RolandSH09',
					'RolandSystem100',
					'RolandTR505',
					'RolandTR606',
					'RolandTR626',
					'RolandTR707',
					'RolandTR727',
					'RolandTR808',
					'RolandTR909',
					'SakataDPM48',
					'SequentialCircuitsDrumtracks',
					'SequentialCircuitsTom',
					'SimmonsSDS400',
					'SimmonsSDS5',
					'SoundmastersR88',
					'UnivoxMicroRhythmer12',
					'ViscoSpaceDrum',
					'XdrumLM8953',
					'YamahaRM50',
					'YamahaRX21',
					'YamahaRX5',
					'YamahaRY30',
					'YamahaTG33',
				)
			),
			'other_sounds' => array(
				'piano'      => 'Salamander Grand Piano. Use: note("c4 e4 g4").s("piano"). 29 velocity-sampled notes.',
				'vcsl'       => 'VCSL orchestral samples (CC0). Includes brass, woodwinds, strings, percussion.',
				'mridangam'  => 'Indian mridangam percussion. Sounds: gumki, ka, nam, ta, ki, dhin, na, chaapu, dhum, ardha, thom, dhi, tha.',
				'wavetables' => 'Wavetable synthesis: wt_digital, wt_vgame collections for synth textures.',
			),
			'patternable'  => 'Banks can be patterned: .bank("<RolandTR808 RolandTR909>"). Aliases work too: .bank("<TR808 TR909>").',
			'usage'        => 'Default samples (without .bank()) use the dirt-samples library: bd, sd, hh, oh, cp, mt, lt, ht, cr, rd, rim, cb, cl.',
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
	 * Visual feedback reference.
	 *
	 * @return array
	 */
	private function get_visual_feedback_reference() {
		return array(
			'description'    => 'Strudel provides built-in visual feedback to help understand patterns. Visualizations render directly in the code editor or as background displays.',
			'highlighting'   => array(
				'description' => 'Mini-notation inside quotes is automatically highlighted in real-time, showing which part of the pattern is currently playing.',
				'color'       => array(
					'name'    => '.color("value")',
					'desc'    => 'Set the highlight color for pattern events. Can be patterned.',
					'example' => 'note("c a f e").color("cyan")',
				),
			),
			'visualizations' => array(
				array(
					'name'    => '.pianoroll()',
					'desc'    => 'Render a piano-roll visualization in the background. Shows note pitch vs. time.',
					'example' => 'note("c a f e").color("white").pianoroll()',
				),
				array(
					'name'    => '._pianoroll()',
					'desc'    => 'Render a piano-roll visualization inline below the pattern in the editor.',
					'example' => 'note("c a f e").color("cyan")._pianoroll()',
				),
				array(
					'name'    => '.punchcard()',
					'desc'    => 'Render a punchcard visualization in the background. Like pianoroll but considers post-call transformations.',
					'example' => 'note("c a f e").punchcard()',
				),
				array(
					'name'    => '._punchcard()',
					'desc'    => 'Render a punchcard visualization inline below the pattern in the editor.',
					'example' => 'note("c a f e")._punchcard()',
				),
			),
			'options'        => array(
				'description' => 'Both pianoroll() and punchcard() accept an options object.',
				'parameters'  => array(
					array(
						'name'    => 'cycles',
						'type'    => 'number',
						'default' => '4',
						'desc'    => 'Number of cycles to display in the visualization.',
					),
					array(
						'name'    => 'playhead',
						'type'    => 'number',
						'default' => '0.5',
						'desc'    => 'Position of the playhead (0 to 1). Controls where active notes appear.',
					),
					array(
						'name'    => 'vertical',
						'type'    => 'boolean',
						'default' => 'false',
						'desc'    => 'Display the visualization vertically instead of horizontally.',
					),
					array(
						'name'    => 'labels',
						'type'    => 'boolean',
						'default' => 'false',
						'desc'    => 'Show note labels on the visualization.',
					),
					array(
						'name'    => 'active',
						'type'    => 'string',
						'default' => 'inherited',
						'desc'    => 'CSS color for currently playing/active notes.',
					),
					array(
						'name'    => 'flipTime',
						'type'    => 'boolean',
						'default' => 'false',
						'desc'    => 'Reverse the time axis direction.',
					),
					array(
						'name'    => 'flipValues',
						'type'    => 'boolean',
						'default' => 'false',
						'desc'    => 'Reverse the value (pitch) axis direction.',
					),
					array(
						'name'    => 'smear',
						'type'    => 'number',
						'default' => '0',
						'desc'    => 'Trail/smear amount for notes. Creates a motion blur effect.',
					),
					array(
						'name'    => 'fold',
						'type'    => 'boolean',
						'default' => 'false',
						'desc'    => 'Fold (wrap) notes that extend beyond the visible range.',
					),
				),
				'example'     => 'note("c a f e")._pianoroll({ cycles: 8, playhead: 0.5, labels: true, active: "#ff0" })',
			),
			'tips'           => array(
				'Both inline (prefixed with _) and background methods render to a dedicated canvas below the editor.',
				'Use .color() to customize the visual appearance of individual patterns in stacked views.',
				'Pianoroll is best for melodic patterns, punchcard for rhythmic/drum patterns.',
				'Punchcard uses fold mode by default (unique values fill the axis), pianoroll uses absolute pitch.',
				'The active option colors currently playing notes differently for real-time feedback.',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent', 'cacheable' );
	}
}
