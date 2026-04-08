/**
 * Algorave Pattern Engine
 *
 * Wraps Tone.js and Strudel for browser-based audio synthesis.
 * Provides a unified API for the live coder UI and chat-driven commands.
 *
 * Strudel features supported:
 * - Full mini-notation: * / ~ [] <> , ? ! (k,n) :n
 * - Effects chain: .room() .delay() .lpf() .hpf() .crush() .distort() .pan() .phaser() .shape() .speed()
 * - Sample banks: .bank("RolandTR808"), .bank("RolandTR909"), etc.
 * - Pattern transformations: .every() .sometimes() .sometimesBy() .slow() .fast() .rev() .jux()
 * - Synthesizers: sawtooth, triangle, square, sine, fm
 * - Tempo: setcps(), setcpm()
 * - MIDI output: .midi() via WebMIDI API
 * - Custom samples: samples() for loading sample maps
 * - Visual feedback: .pianoroll(), ._pianoroll(), .punchcard(), ._punchcard(), .color()
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/* global Tone, strudel, nvoosAlgoraveConfig */

( function () {
	'use strict';

	/**
	 * AlgoraveEngine — singleton audio engine.
	 */
	const AlgoraveEngine = {
		/** @type {boolean} Whether Tone.js audio context is started. */
		started: false,

		/** @type {number} Current BPM. */
		bpm: 120,

		/** @type {string} Current scale. */
		scale: 'C minor',

		/** @type {boolean} Whether audio is currently playing. */
		playing: false,

		/** @type {object|null} Current Tone.js transport reference. */
		transport: null,

		/** @type {object|null} Main audio analyser node for visualizations. */
		analyser: null,

		/** @type {AnalyserNode|null} Web Audio API analyser for Strudel's AudioContext. */
		strudelAnalyser: null,

		/** @type {AnalyserNode|null} Frequency-domain analyser for spectrum modes. */
		strudelFreqAnalyser: null,

		/** @type {Promise|null} Resolves when Strudel REPL is ready. */
		strudelReady: null,

		/** @type {boolean} Whether initStrudel() has been called. */
		strudelAvailable: false,

		/** @type {boolean} Whether Strudel REPL has completed initialization. */
		strudelInitialized: false,

		/** @type {string} Currently active sample bank. */
		currentBank: '',

		/** @type {Array} Available MIDI output devices. */
		midiOutputs: [],

		/** @type {boolean} Whether WebMIDI is available. */
		midiAvailable: false,

		/**
		 * Initialize the engine with config.
		 *
		 * @param {object} config Configuration from wp_localize_script.
		 */
		init: function ( config ) {
			this.bpm = config.defaultBpm || 120;
			this.scale = config.defaultScale || 'C minor';

			// Record Strudel CDN availability but do NOT call initStrudel() yet.
			// Strudel creates an AudioContext internally, which requires a user
			// gesture on modern browsers. Deferred to ensureStrudel().
			this.strudelAvailable = ( typeof window.initStrudel === 'function' );

			// Set up Tone.js if available (optional — Strudel has its own audio engine).
			if ( typeof Tone !== 'undefined' ) {
				this.transport = Tone.getTransport();
				this.transport.bpm.value = this.bpm;

				// Create analyser for visualizations.
				this.analyser = new Tone.Analyser( 'waveform', 1024 );
				Tone.getDestination().connect( this.analyser );
			}

			// Probe WebMIDI availability.
			this.initMidi();
		},

		/**
		 * Ensure audio context is started (requires user gesture).
		 *
		 * @return {Promise} Resolves when audio is ready.
		 */
		ensureStarted: async function () {
			if ( this.started ) {
				return;
			}
			if ( typeof Tone !== 'undefined' ) {
				await Tone.start();
				this.started = true;
			}
		},

		/**
		 * Lazily initialize the Strudel REPL on first use.
		 *
		 * Must be called inside a user-gesture handler so the internal
		 * AudioContext is allowed by the browser.
		 *
		 * @return {Promise} Resolves when Strudel REPL is ready.
		 */
		ensureStrudel: async function () {
			if ( this.strudelInitialized ) {
				return;
			}
			if ( ! this.strudelAvailable ) {
				return;
			}

			// First call — kick off initStrudel() with all sample collections.
			// The prebake function loads:
			//   1. dirt-samples (bd, sd, hh, cp, etc.) from tidalcycles GitHub CDN
			//   2. tidal-drum-machines (RolandTR808, TR909, AkaiLinn, etc.)
			//   3. tidal-drum-machines-alias (short names: TR808, TR909, Linn, etc.)
			//   4. piano (Salamander Grand Piano samples)
			//   5. vcsl (VCSL orchestral sample library — CC0)
			//   6. mridangam (Indian percussion samples)
			//   7. uzu-drumkit (community drum kit samples)
			//   8. uzu-wavetables (wavetable synthesis sounds)
			// Without this, initStrudel() creates a REPL with no samples.
			if ( ! this.strudelReady ) {
				try {
					const sampleMaps = ( typeof nvoosAlgoraveConfig !== 'undefined' && nvoosAlgoraveConfig.sampleMaps ) || {};
					this.strudelReady = window.initStrudel( {
						prebake: function () {
							if ( typeof strudel === 'undefined' || typeof strudel.samples !== 'function' ) {
								// eslint-disable-next-line no-console
								console.warn( '[Algorave] strudel.samples() not available — no default samples loaded.' );
								return;
							}

							const loads = [];

							// Helper: load a sample map, catching errors individually
							// so one failed collection doesn't break the entire init.
							const loadSafe = function ( label, ...args ) {
								loads.push(
									strudel.samples( ...args ).catch( function ( err ) {
										// eslint-disable-next-line no-console
										console.warn( '[Algorave] Failed to load ' + label + ':', err );
									} )
								);
							};

							// 1. Standard dirt-samples (basic bd, sd, hh, cp sounds).
							loadSafe( 'dirt-samples', 'github:tidalcycles/dirt-samples' );

							// 2. Tidal drum machines (RolandTR808, TR909, AkaiLinn, etc.).
							// These provide .bank("RolandTR808") support.
							if ( sampleMaps.drumMachines ) {
								loadSafe(
									'tidal-drum-machines',
									sampleMaps.drumMachines,
									'github:ritchse/tidal-drum-machines/main/machines/',
									{ tag: 'drum-machines' }
								);
							}

							// 3. Piano (Salamander Grand Piano — CC-BY).
							if ( sampleMaps.piano ) {
								loadSafe( 'piano', sampleMaps.piano );
							}

							// 4. VCSL orchestral samples (CC0).
							if ( sampleMaps.vcsl ) {
								loadSafe(
									'vcsl',
									sampleMaps.vcsl,
									'github:sgossner/VCSL/master/'
								);
							}

							// 5. Mridangam (Indian percussion).
							if ( sampleMaps.mridangam ) {
								loadSafe(
									'mridangam',
									sampleMaps.mridangam,
									undefined,
									{ tag: 'drum-machines' }
								);
							}

							// 6. Uzu community drum kit.
							if ( sampleMaps.uzuDrumkit ) {
								loadSafe(
									'uzu-drumkit',
									sampleMaps.uzuDrumkit,
									undefined,
									{ tag: 'drum-machines' }
								);
							}

							// 7. Uzu wavetable synthesis sounds.
							if ( sampleMaps.uzuWavetables ) {
								loadSafe( 'uzu-wavetables', sampleMaps.uzuWavetables );
							}

							return Promise.all( loads );
						},
					} );
				} catch ( e ) {
					// eslint-disable-next-line no-console
					console.warn( '[Algorave] Strudel initialization failed:', e );
					this.strudelAvailable = false;
					return;
				}
			}

			try {
				await this.strudelReady;
				this.strudelInitialized = true;

				// Register drum machine bank aliases (short names like TR808 for RolandTR808).
				this.loadDrumMachineAliases();

				// Connect to Strudel's internal AudioContext for visualization.
				this.connectStrudelAnalyser();
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.warn( '[Algorave] Strudel initialization failed:', e );
				this.strudelAvailable = false;
			}
		},

		/**
		 * Load drum machine bank aliases (e.g. TR808 → RolandTR808).
		 *
		 * Uses the tidal-drum-machines-alias.json file to register
		 * short names via Strudel's aliasBank() function.
		 */
		loadDrumMachineAliases: function () {
			if ( typeof strudel === 'undefined' || typeof strudel.aliasBank !== 'function' ) {
				return;
			}

			const sampleMaps = ( typeof nvoosAlgoraveConfig !== 'undefined' && nvoosAlgoraveConfig.sampleMaps ) || {};
			if ( sampleMaps.drumMachinesAlias ) {
				try {
					strudel.aliasBank( sampleMaps.drumMachinesAlias );
				} catch ( e ) {
					// eslint-disable-next-line no-console
					console.warn( '[Algorave] Could not load drum machine aliases:', e );
				}
			}
		},

		/**
		 * Connect a Web Audio AnalyserNode to Strudel's internal AudioContext.
		 *
		 * Strudel (superdough) routes audio through a master gain node
		 * before the final AudioContext.destination. We tap that gain
		 * node to get waveform data for our custom visualizer.
		 *
		 * Because Strudel may not expose getDestination() in all builds,
		 * we also try tapping the AudioContext destination directly by
		 * inserting a pass-through gain node as a proxy. If all else
		 * fails we poll for the destination node to become available.
		 */
		connectStrudelAnalyser: function () {
			try {
				// Strudel exposes getAudioContext() after initialization.
				let audioCtx = null;
				if ( typeof strudel !== 'undefined' && typeof strudel.getAudioContext === 'function' ) {
					audioCtx = strudel.getAudioContext();
				}

				if ( ! audioCtx ) {
					return;
				}

				this.strudelAnalyser = audioCtx.createAnalyser();
				this.strudelAnalyser.fftSize = 2048;
				this.strudelAnalyser.smoothingTimeConstant = 0.8;
				this.strudelAnalyser.minDecibels = -90;
				this.strudelAnalyser.maxDecibels = -10;

				// Also create a frequency analyser for spectrum/bars modes.
				this.strudelFreqAnalyser = audioCtx.createAnalyser();
				this.strudelFreqAnalyser.fftSize = 2048;
				this.strudelFreqAnalyser.smoothingTimeConstant = 0.85;
				this.strudelFreqAnalyser.minDecibels = -90;
				this.strudelFreqAnalyser.maxDecibels = -10;

				// Strategy 1: Strudel exposes its master destination gain node
				// via getDestination(). Connect the analysers in parallel.
				if ( typeof strudel.getDestination === 'function' ) {
					const dest = strudel.getDestination();
					if ( dest && typeof dest.connect === 'function' ) {
						dest.connect( this.strudelAnalyser );
						dest.connect( this.strudelFreqAnalyser );
						return;
					}
				}

				// Strategy 2: Insert a pass-through GainNode between the
				// AudioContext destination and whatever is connected to it.
				// We replace audioCtx.destination with a gain proxy so all
				// future audio routed to destination flows through us.
				const proxyGain = audioCtx.createGain();
				proxyGain.gain.value = 1;
				proxyGain.connect( audioCtx.destination );
				proxyGain.connect( this.strudelAnalyser );
				proxyGain.connect( this.strudelFreqAnalyser );
				this._strudelSplitter = proxyGain;

				// Strategy 3: Poll for getDestination() to become available
				// after audio starts (some Strudel builds lazy-init superdough).
				const self = this;
				let pollCount = 0;
				// 20 attempts × 500 ms = 10 seconds maximum polling window.
				const maxPollAttempts = 20;
				const pollInterval = setInterval( function () {
					pollCount++;
					if ( pollCount > maxPollAttempts ) {
						clearInterval( pollInterval );
						return;
					}
					if ( typeof strudel !== 'undefined' && typeof strudel.getDestination === 'function' ) {
						const dest = strudel.getDestination();
						if ( dest && typeof dest.connect === 'function' ) {
							try {
								dest.connect( self.strudelAnalyser );
								dest.connect( self.strudelFreqAnalyser );
							} catch ( err ) {
								// Already connected or node mismatch — ignore.
							}
							clearInterval( pollInterval );
						}
					}
				}, 500 );
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.warn( '[Algorave] Could not connect Strudel analyser:', e );
			}
		},

		/**
		 * Play a pattern.
		 *
		 * @param {string} code   Pattern code to evaluate.
		 * @param {string} engine Engine type ('strudel' or 'tonejs').
		 */
		play: async function ( code, engine ) {
			await this.ensureStarted();
			this.stop();

			if ( 'strudel' === engine ) {
				// Initialize Strudel lazily (needs user gesture for AudioContext).
				await this.ensureStrudel();

				// Use strudel.evaluate() from the @strudel/web IIFE namespace.
				// This provides the full DSL context (stack, note, s, sound, setcps, etc.).
				try {
					if ( this.strudelInitialized && typeof strudel !== 'undefined' && typeof strudel.evaluate === 'function' ) {
						await strudel.evaluate( code );
					} else {
						const msg = 'strudel.evaluate() not available. Enable Strudel CDN in settings.';
						// eslint-disable-next-line no-console
						console.warn( '[Algorave] ' + msg );
						document.dispatchEvent( new CustomEvent( 'algorave:error', { detail: { message: msg } } ) );
						return;
					}
				} catch ( e ) {
					// eslint-disable-next-line no-console
					console.error( '[Algorave] Strudel evaluation error:', e );
					document.dispatchEvent( new CustomEvent( 'algorave:error', { detail: { message: e.message || String( e ) } } ) );
					return;
				}
			} else if ( 'tonejs' === engine ) {
				if ( typeof Tone === 'undefined' ) {
					const msg = 'Tone.js is not loaded. Select the Strudel engine or load Tone.js.';
					// eslint-disable-next-line no-console
					console.warn( '[Algorave] ' + msg );
					document.dispatchEvent( new CustomEvent( 'algorave:error', { detail: { message: msg } } ) );
					return;
				}
				// Tone.js evaluation.
				try {
					// eslint-disable-next-line no-eval
					const fn = new Function( 'Tone', code );
					fn( Tone );
					if ( this.transport ) {
						this.transport.start();
					}
				} catch ( e ) {
					// eslint-disable-next-line no-console
					console.error( '[Algorave] Tone.js evaluation error:', e );
					document.dispatchEvent( new CustomEvent( 'algorave:error', { detail: { message: e.message || String( e ) } } ) );
					return;
				}
			}

			this.playing = true;
			document.dispatchEvent( new CustomEvent( 'algorave:playing', { detail: { playing: true } } ) );
		},

		/**
		 * Stop all playback.
		 */
		stop: function () {
			if ( typeof Tone !== 'undefined' && this.transport ) {
				this.transport.stop();
				this.transport.cancel();
			}

			// Only hush Strudel if the REPL has been fully initialized.
			if ( this.strudelInitialized && typeof strudel !== 'undefined' && typeof strudel.hush === 'function' ) {
				strudel.hush();
			}

			this.playing = false;
			document.dispatchEvent( new CustomEvent( 'algorave:playing', { detail: { playing: false } } ) );
		},

		/**
		 * Pause playback.
		 */
		pause: function () {
			if ( typeof Tone !== 'undefined' && this.transport ) {
				this.transport.pause();
			}
			this.playing = false;
			document.dispatchEvent( new CustomEvent( 'algorave:playing', { detail: { playing: false } } ) );
		},

		/**
		 * Set BPM (updates both Tone.js transport and Strudel CPS).
		 *
		 * @param {number} bpm Beats per minute.
		 */
		setBpm: function ( bpm ) {
			this.bpm = Math.max( 20, Math.min( 300, bpm ) );
			if ( typeof Tone !== 'undefined' && this.transport ) {
				this.transport.bpm.value = this.bpm;
			}

			// Update Strudel tempo via CPS if currently playing.
			if ( this.strudelInitialized && typeof strudel !== 'undefined' && typeof strudel.evaluate === 'function' ) {
				const cps = this.bpm / 60 / 4;
				try {
					strudel.evaluate( 'setcps(' + cps.toFixed( 4 ) + ')' );
				} catch ( e ) {
					// Silently ignore — tempo update is best-effort.
				}
			}

			document.dispatchEvent( new CustomEvent( 'algorave:bpm', { detail: { bpm: this.bpm } } ) );
		},

		/**
		 * Set tempo using Cycles Per Second (Strudel-native).
		 *
		 * @param {number} cps Cycles per second.
		 */
		setCps: function ( cps ) {
			cps = Math.max( 0.01, Math.min( 10, cps ) );
			this.bpm = Math.round( cps * 60 * 4 );

			if ( this.strudelInitialized && typeof strudel !== 'undefined' && typeof strudel.evaluate === 'function' ) {
				try {
					strudel.evaluate( 'setcps(' + cps.toFixed( 4 ) + ')' );
				} catch ( e ) {
					// Silently ignore.
				}
			}

			if ( typeof Tone !== 'undefined' && this.transport ) {
				this.transport.bpm.value = this.bpm;
			}

			document.dispatchEvent( new CustomEvent( 'algorave:bpm', { detail: { bpm: this.bpm, cps: cps } } ) );
		},

		/**
		 * Load a named sample bank into Strudel.
		 *
		 * @param {string} bankName Bank name (e.g. "RolandTR808", "RolandTR909").
		 */
		loadBank: function ( bankName ) {
			this.currentBank = bankName;
			document.dispatchEvent( new CustomEvent( 'algorave:bank', { detail: { bank: bankName } } ) );
		},

		/**
		 * Get waveform (time-domain) analyser data for visualizations.
		 * Supports both Tone.js analyser and Strudel's AudioContext analyser.
		 *
		 * @return {Float32Array|null} Waveform data (-1 to +1).
		 */
		getAnalyserData: function () {
			// Prefer Tone.js analyser if available and has data.
			if ( this.analyser ) {
				return this.analyser.getValue();
			}

			// Fall back to Strudel's AudioContext analyser.
			if ( this.strudelAnalyser ) {
				const bufferLength = this.strudelAnalyser.frequencyBinCount;
				const dataArray = new Float32Array( bufferLength );
				this.strudelAnalyser.getFloatTimeDomainData( dataArray );
				return dataArray;
			}

			return null;
		},

		/**
		 * Get frequency-domain analyser data for spectrum visualizations.
		 *
		 * Returns unsigned byte data (0–255) suitable for bar/spectrum rendering.
		 *
		 * @return {Uint8Array|null} Frequency data (0–255 per bin).
		 */
		getFrequencyData: function () {
			// Prefer the dedicated frequency analyser when connected to Strudel.
			const analyser = this.strudelFreqAnalyser || this.strudelAnalyser;
			if ( analyser ) {
				const bufferLength = analyser.frequencyBinCount;
				const dataArray = new Uint8Array( bufferLength );
				analyser.getByteFrequencyData( dataArray );
				return dataArray;
			}

			return null;
		},

		/**
		 * Initialize WebMIDI support.
		 */
		initMidi: function () {
			if ( typeof navigator === 'undefined' || ! navigator.requestMIDIAccess ) {
				return;
			}

			const self = this;
			navigator.requestMIDIAccess( { sysex: false } ).then( function ( access ) {
				self.midiAvailable = true;
				self.updateMidiOutputs( access );

				// Listen for device changes.
				access.onstatechange = function () {
					self.updateMidiOutputs( access );
				};
			} ).catch( function () {
				// WebMIDI not available or permission denied.
				self.midiAvailable = false;
			} );
		},

		/**
		 * Update the list of available MIDI outputs.
		 *
		 * @param {MIDIAccess} access WebMIDI access object.
		 */
		updateMidiOutputs: function ( access ) {
			this.midiOutputs = [];
			const outputs = access.outputs;
			if ( outputs && typeof outputs.forEach === 'function' ) {
				const self = this;
				outputs.forEach( function ( output ) {
					self.midiOutputs.push( {
						id: output.id,
						name: output.name,
						manufacturer: output.manufacturer,
					} );
				} );
			}

			document.dispatchEvent( new CustomEvent( 'algorave:midi:devices', {
				detail: { outputs: this.midiOutputs, available: this.midiAvailable },
			} ) );
		},

		/**
		 * Get list of available MIDI output devices.
		 *
		 * @return {Array} Array of MIDI output device info objects.
		 */
		getMidiOutputs: function () {
			return this.midiOutputs;
		},

		/**
		 * Available sample banks reference.
		 *
		 * @return {Array} List of well-known Strudel sample banks.
		 */
		getAvailableBanks: function () {
			return [
				'RolandTR808',
				'RolandTR909',
				'RolandTR707',
				'RolandTR606',
				'RolandTR505',
				'RolandTR626',
				'RolandTR727',
				'RolandCR78',
				'RolandCompurhythm78',
				'RolandCompurhythm1000',
				'RolandCompurhythm8000',
				'RolandMC202',
				'RolandMC303',
				'RolandD110',
				'RolandD70',
				'RolandDDR30',
				'RolandJD990',
				'RolandMT32',
				'RolandR8',
				'RolandS50',
				'RolandSH09',
				'RolandSystem100',
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
				'AJKPercusyn',
			];
		},
	};

	// Export globally.
	window.AlgoraveEngine = AlgoraveEngine;

	// Auto-initialize when config is available.
	if ( typeof nvoosAlgoraveConfig !== 'undefined' ) {
		AlgoraveEngine.init( nvoosAlgoraveConfig );
	}
} )();
