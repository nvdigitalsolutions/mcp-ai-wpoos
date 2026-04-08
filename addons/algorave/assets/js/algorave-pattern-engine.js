/**
 * Algorave Pattern Engine
 *
 * Wraps Tone.js and Strudel for browser-based audio synthesis.
 * Provides a unified API for the live coder UI and chat-driven commands.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/* global Tone, nvoosAlgoraveConfig */

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

		/** @type {Promise|null} Resolves when Strudel REPL is ready. */
		strudelReady: null,

		/**
		 * Initialize the engine with config.
		 *
		 * @param {object} config Configuration from wp_localize_script.
		 */
		init: function ( config ) {
			this.bpm = config.defaultBpm || 120;
			this.scale = config.defaultScale || 'C minor';

			// Initialize Strudel REPL if loaded from CDN.
			// initStrudel() exposes evaluate() and hush() as globals.
			if ( typeof window.initStrudel === 'function' ) {
				var initPromise = window.initStrudel();
				// Handle async init (thenable check supports Promise polyfills).
				if ( initPromise && typeof initPromise.then === 'function' ) {
					this.strudelReady = initPromise;
					this.strudelReady.catch( function ( e ) {
						// eslint-disable-next-line no-console
						console.warn( '[Algorave] Strudel initialization failed:', e );
					} );
				}
			}

			// Check for Tone.js availability.
			if ( typeof Tone === 'undefined' ) {
				// eslint-disable-next-line no-console
				console.warn( '[Algorave] Tone.js not loaded. Pattern engine requires Tone.js.' );
				return;
			}

			this.transport = Tone.getTransport();
			this.transport.bpm.value = this.bpm;

			// Create analyser for visualizations.
			this.analyser = new Tone.Analyser( 'waveform', 1024 );
			Tone.getDestination().connect( this.analyser );
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
		 * Play a pattern.
		 *
		 * @param {string} code   Pattern code to evaluate.
		 * @param {string} engine Engine type ('strudel' or 'tonejs').
		 */
		play: async function ( code, engine ) {
			await this.ensureStarted();
			this.stop();

			if ( 'strudel' === engine ) {
				// Use Strudel's evaluate() which provides the DSL context
				// (stack, note, s, sound, setcps, etc.).
				try {
					// Wait for Strudel init if it was async.
					if ( this.strudelReady ) {
						await this.strudelReady;
					}

					if ( typeof window.evaluate === 'function' ) {
						await window.evaluate( code );
					} else {
						// eslint-disable-next-line no-console
						console.warn( '[Algorave] Strudel evaluate() not available. Enable Strudel CDN in settings.' );
					}
				} catch ( e ) {
					// eslint-disable-next-line no-console
					console.error( '[Algorave] Strudel evaluation error:', e );
				}
			} else if ( typeof Tone !== 'undefined' ) {
				// Tone.js evaluation.
				try {
					// eslint-disable-next-line no-eval
					const fn = new Function( 'Tone', code );
					fn( Tone );
					this.transport.start();
				} catch ( e ) {
					// eslint-disable-next-line no-console
					console.error( '[Algorave] Tone.js evaluation error:', e );
				}
			}

			this.playing = true;
			document.dispatchEvent( new CustomEvent( 'algorave:playing', { detail: { playing: true } } ) );
		},

		/**
		 * Stop all playback.
		 */
		stop: function () {
			if ( typeof Tone !== 'undefined' ) {
				this.transport.stop();
				this.transport.cancel();
			}

			// Stop Strudel if running.
			if ( typeof window.hush === 'function' ) {
				window.hush();
			}

			this.playing = false;
			document.dispatchEvent( new CustomEvent( 'algorave:playing', { detail: { playing: false } } ) );
		},

		/**
		 * Pause playback.
		 */
		pause: function () {
			if ( typeof Tone !== 'undefined' ) {
				this.transport.pause();
			}
			this.playing = false;
			document.dispatchEvent( new CustomEvent( 'algorave:playing', { detail: { playing: false } } ) );
		},

		/**
		 * Set BPM.
		 *
		 * @param {number} bpm Beats per minute.
		 */
		setBpm: function ( bpm ) {
			this.bpm = Math.max( 20, Math.min( 300, bpm ) );
			if ( typeof Tone !== 'undefined' ) {
				this.transport.bpm.value = this.bpm;
			}
			document.dispatchEvent( new CustomEvent( 'algorave:bpm', { detail: { bpm: this.bpm } } ) );
		},

		/**
		 * Get analyser data for visualizations.
		 *
		 * @return {Float32Array|null} Waveform data.
		 */
		getAnalyserData: function () {
			if ( this.analyser ) {
				return this.analyser.getValue();
			}
			return null;
		},
	};

	// Export globally.
	window.AlgoraveEngine = AlgoraveEngine;

	// Auto-initialize when config is available.
	if ( typeof nvoosAlgoraveConfig !== 'undefined' ) {
		AlgoraveEngine.init( nvoosAlgoraveConfig );
	}
} )();
