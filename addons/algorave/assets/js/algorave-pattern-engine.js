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

		/** @type {string} Currently active engine ('strudel' or 'tonejs'). */
		activeEngine: '',

		/** @type {object|null} Current Tone.js transport reference. */
		transport: null,

		/** @type {object|null} Main audio analyser node for visualizations. */
		analyser: null,

		/** @type {AnalyserNode|null} Web Audio API analyser for Strudel's AudioContext. */
		strudelAnalyser: null,

		/** @type {AnalyserNode|null} Frequency-domain analyser for spectrum modes. */
		strudelFreqAnalyser: null,

		/** @type {boolean} Whether the Strudel analyser is connected to a live audio source. */
		strudelAnalyserConnected: false,

		/** @type {GainNode|null} Proxy node inserted before AudioContext.destination for analyser tap. */
		strudelDestProxy: null,

		/** @type {Promise|null} Resolves when Strudel REPL is ready. */
		strudelReady: null,

		/** @type {boolean} Whether initStrudel() has been called. */
		strudelAvailable: false,

		/** @type {boolean} Whether Strudel REPL has completed initialization. */
		strudelInitialized: false,

		/** @type {boolean} Whether ensureStrudel() is currently running. */
		strudelInitializing: false,

		/** @type {string} Currently active sample bank. */
		currentBank: '',

		/** @type {Array} Available MIDI output devices. */
		midiOutputs: [],

		/** @type {boolean} Whether WebMIDI is available. */
		midiAvailable: false,

		/** @type {number|null} Animation frame ID for pattern visualization. */
		patternVizAnimFrame: null,

		/** @type {boolean} Whether Pattern.prototype visualization methods have been registered. */
		patternVizRegistered: false,

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

			// Prevent parallel initialization — if another call is already
			// running ensureStrudel(), just wait for it to finish.
			if ( this.strudelInitializing ) {
				if ( this.strudelReady ) {
					await this.strudelReady;
				}
				return;
			}
			this.strudelInitializing = true;

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
								return Promise.resolve();
							}

							const loads = [];

							// Helper: load a sample map, catching errors individually
							// so one failed collection doesn't break the entire init.
							const loadSafe = function ( label, ...args ) {
								loads.push(
									Promise.resolve().then( function () {
										return strudel.samples( ...args );
									} ).catch( function ( err ) {
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
					this.strudelInitializing = false;
					return;
				}
			}

			try {
				await this.strudelReady;
				this.strudelInitialized = true;
				this.strudelInitializing = false;

				// Register drum machine bank aliases (short names like TR808 for RolandTR808).
				// Await the async alias loading so aliases are ready before evaluation.
				await this.loadDrumMachineAliases();

				// Connect to Strudel's internal AudioContext for visualization.
				this.connectStrudelAnalyser();

				// Register pianoroll/punchcard methods on Pattern.prototype
				// so users can call ._punchcard(), .pianoroll(), etc.
				this.registerPatternVisualization();

				// Notify other components that Strudel is ready.
				document.dispatchEvent( new CustomEvent( 'algorave:strudel-ready' ) );
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.warn( '[Algorave] Strudel initialization failed:', e );
				this.strudelAvailable = false;
				this.strudelInitializing = false;
			}
		},

		/**
		 * Load drum machine bank aliases (e.g. TR808 → RolandTR808).
		 *
		 * Uses the tidal-drum-machines-alias.json file to register
		 * short names via Strudel's aliasBank() function.
		 *
		 * @return {Promise} Resolves when aliases are registered.
		 */
		loadDrumMachineAliases: async function () {
			if ( typeof strudel === 'undefined' || typeof strudel.aliasBank !== 'function' ) {
				return Promise.resolve();
			}

			const sampleMaps = ( typeof nvoosAlgoraveConfig !== 'undefined' && nvoosAlgoraveConfig.sampleMaps ) || {};
			if ( sampleMaps.drumMachinesAlias ) {
				try {
					await strudel.aliasBank( sampleMaps.drumMachinesAlias );
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

				// Try to connect to Strudel's master destination gain node.
				// If getDestination() is not yet available (superdough lazy-init),
				// poll until it becomes available. Only one strategy succeeds,
				// guarded by strudelAnalyserConnected flag.
				if ( this.tryConnectAnalyser() ) {
					return;
				}

				// Poll for getDestination() to become available
				// (some Strudel builds lazy-init superdough).
				const self = this;
				let pollCount = 0;
				// 20 attempts × 500 ms = 10 seconds maximum polling window.
				const maxPollAttempts = 20;
				const pollInterval = setInterval( function () {
					pollCount++;
					if ( self.strudelAnalyserConnected || pollCount > maxPollAttempts ) {
						clearInterval( pollInterval );
						return;
					}
					self.tryConnectAnalyser();
				}, 500 );
			} catch ( e ) {
				// eslint-disable-next-line no-console
				console.warn( '[Algorave] Could not connect Strudel analyser:', e );
			}
		},

		/**
		 * Attempt to connect analysers to Strudel's destination node.
		 *
		 * Strategy 1: Use strudel.getDestination() to tap the master gain.
		 * Strategy 2: Insert a proxy GainNode before AudioContext.destination
		 *             so that future connections (e.g. from superdough) route
		 *             through the proxy and feed our analysers.
		 *
		 * @return {boolean} True if connection succeeded.
		 */
		tryConnectAnalyser: function () {
			try {
				// Strategy 1: tap Strudel's master destination gain node directly.
				// This is the preferred method — it connects to the actual gain
				// node that superdough routes audio through. Web Audio connect()
				// is idempotent for the same src→dest pair, so safe to retry.
				if ( typeof strudel !== 'undefined' && typeof strudel.getDestination === 'function' ) {
					const dest = strudel.getDestination();
					if ( dest && typeof dest.connect === 'function' ) {
						dest.connect( this.strudelAnalyser );
						dest.connect( this.strudelFreqAnalyser );
						if ( ! this.strudelAnalyserConnected ) {
							this.strudelAnalyserConnected = true;
							document.dispatchEvent( new CustomEvent( 'algorave:analyser-connected' ) );
						}
						return true;
					}
				}

				// Already connected — skip Strategy 2 (proxy install).
				if ( this.strudelAnalyserConnected ) {
					return true;
				}

				// Strategy 2: proxy AudioContext.destination so future connections
				// (made during evaluate()) route through our analyser tap.
				// This mutates AudioContext.destination which is safe here because
				// the context belongs to Strudel's internal audio graph and we are
				// the sole consumer needing to intercept its output.
				// Note: this may fail when superdough caches a reference to the
				// real destination before the proxy is installed. Strategy 1
				// (retried after evaluate()) handles that case.
				if ( ! this.strudelDestProxy && this.strudelAnalyser && this.strudelFreqAnalyser ) {
					let audioCtx = null;
					if ( typeof strudel !== 'undefined' && typeof strudel.getAudioContext === 'function' ) {
						audioCtx = strudel.getAudioContext();
					}
					if ( audioCtx && audioCtx.destination ) {
						const realDest = audioCtx.destination;
						const proxy = audioCtx.createGain();

						// Superdough reads destination.maxChannelCount and sets
						// destination.channelCount to match. A plain GainNode
						// does not expose maxChannelCount, so superdough would
						// read undefined → 0, causing "channelCount outside
						// range [1,32]". Mirror the real destination's channel
						// properties onto the proxy to prevent this.
						try {
							const maxCh = realDest.maxChannelCount || 2;
							proxy.channelCount = realDest.channelCount || maxCh;
							proxy.channelCountMode = realDest.channelCountMode || 'explicit';
							proxy.channelInterpretation = realDest.channelInterpretation || 'speakers';
							Object.defineProperty( proxy, 'maxChannelCount', {
								get: function () {
									return maxCh;
								},
								configurable: true,
							} );
						} catch ( _chErr ) {
							// Non-critical — proceed without channel mirroring.
						}

						proxy.connect( realDest );
						proxy.connect( this.strudelAnalyser );
						proxy.connect( this.strudelFreqAnalyser );

						// Replace destination so superdough routes through our proxy.
						// The Strudel AudioContext is created once per session and
						// never recreated, so a single override is sufficient.
						Object.defineProperty( audioCtx, 'destination', {
							get: function () {
								return proxy;
							},
							configurable: true,
						} );

						this.strudelDestProxy = proxy;
						this.strudelAnalyserConnected = true;
						document.dispatchEvent( new CustomEvent( 'algorave:analyser-connected' ) );
						return true;
					}
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.warn( '[Algorave] analyser connect attempt failed:', err );
			}
			return false;
		},

		/**
		 * Eagerly create and resume Strudel's AudioContext.
		 *
		 * MUST be called synchronously (before any `await`) while the
		 * browser's user-gesture activation window is still open.
		 * `strudel.getAudioContext()` returns a singleton — creating it
		 * here ensures superdough reuses this already-running context
		 * instead of lazily creating a new one in "suspended" state
		 * after the gesture expires.
		 */
		ensureAudioContext: function () {
			if ( typeof strudel === 'undefined' || typeof strudel.getAudioContext !== 'function' ) {
				return;
			}
			try {
				const ctx = strudel.getAudioContext();
				if ( ctx && ctx.state === 'suspended' ) {
					ctx.resume();
				}
			} catch ( _e ) {
				// Non-critical — initAudioOnFirstClick will handle this.
			}
		},

		/**
		 * Play a pattern.
		 *
		 * @param {string} code   Pattern code to evaluate.
		 * @param {string} engine Engine type ('strudel' or 'tonejs').
		 */
		play: async function ( code, engine ) {
			// ── Eagerly create + resume the audio context ──────────
			// This MUST happen synchronously (before any await) so the
			// browser's user-gesture activation window is still open.
			// Without this, Strudel's AudioContext is created lazily
			// (after async sample-loading in ensureStrudel) and starts
			// in "suspended" state — causing complete silence even
			// though the pattern scheduler is running.
			if ( 'strudel' === ( engine || 'strudel' ) ) {
				this.ensureAudioContext();
			}

			await this.ensureStarted();
			this.stop();

			this.activeEngine = engine || 'strudel';

			if ( 'strudel' === engine ) {
				// Initialize Strudel lazily (needs user gesture for AudioContext).
				await this.ensureStrudel();

				// Load AudioWorklets so effects (reverb, delay, etc.) work.
				// The AudioContext was already created and resumed above, so
				// initAudio() just loads the worklet module.
				if ( typeof strudel !== 'undefined' && typeof strudel.initAudio === 'function' ) {
					try {
						await strudel.initAudio();
					} catch ( _e ) {
						// Non-critical — basic synthesis works without worklets.
						// eslint-disable-next-line no-console
						console.warn( '[Algorave] AudioWorklet init skipped:', _e );
					}
				}

				// Use strudel.evaluate() from the @strudel/web IIFE namespace.
				// This provides the full DSL context (stack, note, s, sound, setcps, etc.).
				try {
					if ( this.strudelInitialized && typeof strudel !== 'undefined' && typeof strudel.evaluate === 'function' ) {
						await strudel.evaluate( code );

						// Retry analyser connection now that superdough is active.
						// evaluate() triggers superdough initialization, making
						// getDestination() available in builds that lazy-init it.
						// Always retry — the proxy strategy (Strategy 2) may have
						// set strudelAnalyserConnected without actually capturing
						// audio if superdough cached the real destination before
						// the proxy was installed.
						this.tryConnectAnalyser();
					} else {
						const msg = 'strudel.evaluate() not available. Enable Strudel Engine in settings.';
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

			// Stop pattern visualization animation frame.
			this.stopPatternViz();

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
			// When Strudel is the active engine and the analyser is connected
			// to live audio, prefer it. Tone.js analyser is disconnected from
			// Strudel's AudioContext and would return silence.
			if ( this.strudelAnalyser && this.strudelAnalyserConnected && ( this.activeEngine === 'strudel' || ! this.analyser ) ) {
				const bufferLength = this.strudelAnalyser.frequencyBinCount;
				const dataArray = new Float32Array( bufferLength );
				this.strudelAnalyser.getFloatTimeDomainData( dataArray );

				// Check for actual signal — if all values are exactly 0.0,
				// the analyser may not be receiving audio despite being
				// "connected" (e.g. the proxy strategy failed silently).
				// Return null so the visualizer shows the idle animation
				// instead of a misleading flat line.
				let hasSignal = false;
				for ( let i = 0; i < bufferLength; i++ ) {
					if ( dataArray[ i ] !== 0 ) {
						hasSignal = true;
						break;
					}
				}
				return hasSignal ? dataArray : null;
			}

			// Fall back to Tone.js analyser when using tonejs engine.
			if ( this.analyser ) {
				return this.analyser.getValue();
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
			// Only return data when the analyser is connected to a live audio
			// source.  Without this guard the visualizer receives a zero-filled
			// buffer and skips the idle state, causing an invisible flat-line
			// render while the idle animation should be showing.
			if ( ! this.strudelAnalyserConnected ) {
				return null;
			}

			// Prefer the dedicated frequency analyser when connected to Strudel.
			const analyser = this.strudelFreqAnalyser || this.strudelAnalyser;
			if ( analyser ) {
				const bufferLength = analyser.frequencyBinCount;
				const dataArray = new Uint8Array( bufferLength );
				analyser.getByteFrequencyData( dataArray );

				// Check for actual signal — all-zero frequency data means
				// the analyser is not receiving audio (e.g. proxy bypass).
				let hasSignal = false;
				for ( let i = 0; i < bufferLength; i++ ) {
					if ( dataArray[ i ] !== 0 ) {
						hasSignal = true;
						break;
					}
				}
				return hasSignal ? dataArray : null;
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
		 * Stop any active pattern visualization (pianoroll / punchcard).
		 *
		 * Called when playback stops so the animation frame loop is cancelled
		 * and the visualization container is hidden.
		 */
		stopPatternViz: function () {
			if ( this.patternVizAnimFrame ) {
				cancelAnimationFrame( this.patternVizAnimFrame );
				this.patternVizAnimFrame = null;
			}
			const container = document.querySelector( '.algorave-pattern-viz' );
			if ( container ) {
				container.style.display = 'none';
			}
		},

		/**
		 * Register pianoroll / punchcard methods on Strudel's Pattern.prototype.
		 *
		 * `@strudel/draw` is NOT bundled in `@strudel/web`, so the standard
		 * `.pianoroll()`, `._pianoroll()`, `.punchcard()`, `._punchcard()` methods
		 * are missing.  We polyfill them here after `initStrudel()` resolves,
		 * rendering to the dedicated `.algorave-pattern-viz` canvas inside the
		 * live-coder template.
		 *
		 * Both the underscore (inline) and non-underscore (background) variants
		 * render to the same canvas since the plugin does not use CodeMirror.
		 */
		registerPatternVisualization: function () {
			if ( this.patternVizRegistered ) {
				return;
			}
			if ( typeof strudel === 'undefined' || ! strudel.Pattern ) {
				return;
			}

			const engine = this;
			const PatternProto = strudel.Pattern.prototype;

			/**
			 * Shared pianoroll / punchcard renderer.
			 *
			 * @param {object} pat     The Pattern instance.
			 * @param {object} options Visualisation options.
			 * @param {boolean} fold   If true, fold unique values (punchcard style).
			 */
			function startViz( pat, options, fold ) {
				const container = document.querySelector( '.algorave-pattern-viz' );
				const canvas = document.getElementById( 'algorave-pattern-viz-canvas' );
				if ( ! container || ! canvas ) {
					return;
				}

				// Show the container.
				container.style.display = 'block';

				// DPI-aware sizing.
				const dpr = window.devicePixelRatio || 1;
				let rect = container.getBoundingClientRect();
				canvas.width = rect.width * dpr;
				canvas.height = rect.height * dpr;
				const ctx = canvas.getContext( '2d' );
				ctx.scale( dpr, dpr );

				// Re-size canvas when container dimensions change.
				if ( typeof ResizeObserver !== 'undefined' ) {
					const resizeObs = new ResizeObserver( function () {
						rect = container.getBoundingClientRect();
						canvas.width = rect.width * dpr;
						canvas.height = rect.height * dpr;
						ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
					} );
					resizeObs.observe( container );
				}

				const cycles = options.cycles || 4;
				const playheadPos = options.playhead != null ? options.playhead : 0.5;
				const activeColor = options.active || '#FFCA28';
				const inactiveColor = options.inactive || '#7491D2';
				const playheadColor = options.playheadColor || '#ffffff';
				const vertical = !! options.vertical;
				const labels = !! options.labels;
				const flipTime = !! options.flipTime;
				const flipValues = !! options.flipValues;
				const smear = options.smear || 0;
				const hideNegative = !! options.hideNegative;

				const lookbehind = cycles * playheadPos;
				const lookahead = cycles * ( 1 - playheadPos );
				const overscan = options.overscan || 1;

				// Hap memory (persists between frames to show notes scrolling past).
				let memory = [];
				let lastPhase = null;

				// Cancel any existing animation frame.
				if ( engine.patternVizAnimFrame ) {
					cancelAnimationFrame( engine.patternVizAnimFrame );
				}

				function render() {
					if ( typeof strudel === 'undefined' || typeof strudel.getTime !== 'function' ) {
						engine.patternVizAnimFrame = requestAnimationFrame( render );
						return;
					}

					const w = rect.width;
					const h = rect.height;
					const now = Math.max( strudel.getTime(), 0 );
					const from = now - lookbehind;
					const to = now + lookahead;

					// Query new haps since last frame.
					const begin = lastPhase != null ? Math.max( lastPhase, to - 0.1 ) : from - overscan;
					try {
						const newHaps = pat.queryArc( begin, to + overscan )
							.filter( function ( hap ) {
								return hap.hasOnset();
							} );
						memory = memory.concat( newHaps );
					} catch ( _e ) {
						// Pattern query can fail during transitions — ignore.
					}
					lastPhase = to;

					// Remove haps that are too far in the past.
					memory = memory.filter( function ( hap ) {
						return hap.whole && hap.whole.end >= from - overscan;
					} );

					// Filter visible haps.
					const visible = memory.filter( function ( hap ) {
						if ( hideNegative && hap.whole.begin < 0 ) {
							return false;
						}
						return hap.whole.end >= from && hap.whole.begin <= to;
					} );

					// Derive value range.
					let minVal = Infinity;
					let maxVal = -Infinity;
					const uniqueVals = [];
					visible.forEach( function ( hap ) {
						const v = hapValue( hap );
						if ( v < minVal ) {
							minVal = v;
						}
						if ( v > maxVal ) {
							maxVal = v;
						}
						if ( uniqueVals.indexOf( v ) === -1 ) {
							uniqueVals.push( v );
						}
					} );
					if ( minVal === Infinity ) {
						minVal = 0;
						maxVal = 127;
					}
					uniqueVals.sort( function ( a, b ) {
						return a - b;
					} );

					let valueExtent, barThickness;
					if ( fold ) {
						valueExtent = uniqueVals.length || 1;
						barThickness = ( vertical ? w : h ) / valueExtent;
					} else {
						valueExtent = ( maxVal - minVal + 1 ) || 1;
						barThickness = ( vertical ? w : h ) / valueExtent;
					}

					// Clear.
					ctx.save();
					ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
					if ( ! smear ) {
						ctx.clearRect( 0, 0, w, h );
					}

					const timeAxis = vertical ? h : w;
					const timeExtent = to - from;

					// Draw haps.
					visible.forEach( function ( hap ) {
						const isActive = hap.whole.begin <= now && hap.endClipped > now;
						const color = isActive ? activeColor : inactiveColor;
						const value = hapValue( hap );

						// Time position → pixels.
						const tNorm = ( hap.whole.begin - from ) / timeExtent;
						let tPx = tNorm * timeAxis;
						const durPx = ( hap.duration / timeExtent ) * timeAxis;
						if ( flipTime ) {
							tPx = timeAxis - tPx - durPx;
						}

						// Value position → pixels.
						let vNorm;
						if ( fold ) {
							vNorm = uniqueVals.indexOf( value ) / valueExtent;
						} else {
							vNorm = ( value - minVal ) / valueExtent;
						}
						let vPx;
						if ( vertical ) {
							vPx = vNorm * w;
						} else {
							vPx = ( 1 - vNorm ) * h - barThickness;
							if ( flipValues ) {
								vPx = vNorm * h;
							}
						}

						ctx.globalAlpha = ( hap.value && typeof hap.value.velocity === 'number' ) ? hap.value.velocity : 1;
						ctx.fillStyle = color;
						if ( vertical ) {
							ctx.fillRect( vPx, tPx, barThickness - 1, durPx - 1 );
						} else {
							ctx.fillRect( tPx, vPx, durPx - 1, barThickness - 1 );
						}

						// Optional labels.
						if ( labels && hap.value ) {
							const label = hap.value.note || hap.value.s || '';
							if ( label ) {
								ctx.fillStyle = isActive ? '#000' : color;
								ctx.font = Math.max( 8, barThickness * 0.6 ) + 'px monospace';
								ctx.textBaseline = 'top';
								if ( vertical ) {
									ctx.fillText( label, vPx + 2, tPx + 2 );
								} else {
									ctx.fillText( label, tPx + 2, vPx + 2 );
								}
							}
						}
					} );

					// Draw playhead line.
					ctx.globalAlpha = 0.7;
					ctx.strokeStyle = playheadColor;
					ctx.lineWidth = 1.5;
					ctx.beginPath();
					if ( vertical ) {
						const phY = playheadPos * h;
						ctx.moveTo( 0, phY );
						ctx.lineTo( w, phY );
					} else {
						const phX = playheadPos * w;
						ctx.moveTo( phX, 0 );
						ctx.lineTo( phX, h );
					}
					ctx.stroke();

					ctx.globalAlpha = 1;
					ctx.restore();

					engine.patternVizAnimFrame = requestAnimationFrame( render );
				}

				engine.patternVizAnimFrame = requestAnimationFrame( render );
			}

			/**
			 * Extract a numeric value from a hap for the value axis.
			 *
			 * @param {object} hap Strudel hap.
			 * @return {number} Numeric value (MIDI note, sample index, etc.).
			 */
			function hapValue( hap ) {
				let val = hap.value;
				if ( typeof val !== 'object' ) {
					val = { value: val };
				}
				if ( val.freq ) {
					// freq → MIDI: 12 * log2(freq / 440 Hz) + 69 (A4).
					return Math.round( 12 * Math.log2( val.freq / 440 ) + 69 );
				}
				const note = val.note != null ? val.note : val.n;
				if ( typeof note === 'string' ) {
					return noteNameToMidi( note );
				}
				if ( typeof note === 'number' ) {
					return note;
				}
				if ( val.s ) {
					// For sample patterns, hash the sample name to a number.
					return hashString( val.s );
				}
				if ( typeof val.value === 'number' ) {
					return val.value;
				}
				return 0;
			}

			/**
			 * Convert a note name like "c4" or "eb3" to a MIDI number.
			 *
			 * @param {string} name Note name.
			 * @return {number} MIDI note number.
			 */
			function noteNameToMidi( name ) {
				const match = name.match( /^([a-gA-G])([#b]?)(-?\d+)?$/ );
				if ( ! match ) {
					return 60;
				}
				const semitones = { c: 0, d: 2, e: 4, f: 5, g: 7, a: 9, b: 11 };
				let base = semitones[ match[ 1 ].toLowerCase() ] || 0;
				if ( match[ 2 ] === '#' ) {
					base += 1;
				}
				if ( match[ 2 ] === 'b' ) {
					base -= 1;
				}
				const octave = match[ 3 ] != null ? parseInt( match[ 3 ], 10 ) : 4;
				return ( octave + 1 ) * 12 + base;
			}

			/**
			 * Deterministic numeric hash for a string.
			 *
			 * @param {string} str Input string.
			 * @return {number} Hash value (0–127 range, like MIDI).
			 */
			function hashString( str ) {
				let hash = 0;
				for ( let i = 0; i < str.length; i++ ) {
					hash = ( ( hash << 5 ) - hash ) + str.charCodeAt( i );
					hash |= 0;
				}
				return Math.abs( hash ) % 128;
			}

			// --- Polyfill Pattern.prototype visualization methods ---

			if ( typeof PatternProto.pianoroll !== 'function' ) {
				PatternProto.pianoroll = function ( options ) {
					startViz( this, options || {}, false );
					return this;
				};
			}

			if ( typeof PatternProto._pianoroll !== 'function' ) {
				PatternProto._pianoroll = function ( options ) {
					startViz( this, options || {}, false );
					return this;
				};
			}

			if ( typeof PatternProto.punchcard !== 'function' ) {
				PatternProto.punchcard = function ( options ) {
					startViz( this, options || {}, true );
					return this;
				};
			}

			if ( typeof PatternProto._punchcard !== 'function' ) {
				PatternProto._punchcard = function ( options ) {
					startViz( this, options || {}, true );
					return this;
				};
			}

			this.patternVizRegistered = true;
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
	} else {
		// eslint-disable-next-line no-console
		console.warn( '[Algorave] nvoosAlgoraveConfig not found — engine initialized with defaults.' );
		AlgoraveEngine.init( {} );
	}

	// Notify other scripts that AlgoraveEngine is available.
	document.dispatchEvent( new CustomEvent( 'algorave:engine-ready' ) );

	/**
	 * Bridge: convert _browser_command tool results into DOM CustomEvents.
	 *
	 * When the oOS chat processes a tool result that contains
	 * `_browser_command: true`, the agentic loop delivers the response to
	 * the browser. This listener inspects every SSE "tool_result" message
	 * and dispatches the appropriate addon event so the visualizer,
	 * play-control, and MIDI output components can react.
	 */
	document.addEventListener( 'algorave:browser-command', function ( e ) {
		if ( ! window.AlgoraveEngine ) {
			return;
		}

		const detail = e.detail || {};
		const action = detail.action || '';
		const engine = window.AlgoraveEngine;

		// Visualizer commands.
		if ( action === 'set_mode' || action === 'set_color' || action === 'toggle' || action === 'fullscreen' ) {
			document.dispatchEvent( new CustomEvent( 'algorave:visualizer', { detail: detail } ) );
		}

		// Play control commands.
		if ( action === 'play' && detail.code ) {
			engine.play( detail.code, detail.engine || 'strudel' );
		} else if ( action === 'stop' ) {
			engine.stop();
		} else if ( action === 'pause' ) {
			engine.pause();
		} else if ( action === 'set_bpm' && detail.bpm ) {
			engine.setBpm( detail.bpm );
		} else if ( action === 'set_cps' && detail.cps ) {
			engine.setCps( detail.cps );
		} else if ( action === 'set_bank' && detail.bank ) {
			engine.loadBank( detail.bank );
		}
	} );
} )();
