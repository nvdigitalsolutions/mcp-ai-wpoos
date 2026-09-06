/**
 * Algorave Live Coder Interface
 *
 * Provides the interactive code editor UI for writing and executing
 * live coding patterns in the browser. Supports Strudel and Tone.js engines,
 * sample bank selection, pattern presets, and effects quick-reference.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/* global AlgoraveEngine, nvoosAlgoraveConfig */

( function () {
	'use strict';

	/**
	 * Pattern presets for quick-start coding.
	 *
	 * @type {Object.<string, {code: string, bpm: number}>}
	 */
	const PRESETS = {
		techno: {
			bpm: 135,
			code: '// Techno — 135 BPM\n'
				+ 'setcps(0.5625)\n\n'
				+ 'stack(\n'
				+ '  s("bd*4").bank("RolandTR909").gain(0.9).shape(0.3),\n'
				+ '  s("hh*16").bank("RolandTR909")\n'
				+ '    .gain("[.2 .5 .3 .7]*4").pan(sine.slow(4)),\n'
				+ '  s("~ cp ~ cp").bank("RolandTR909").gain(0.6)\n'
				+ '    .room(0.3).delay(0.1),\n'
				+ '  note("c2 c2 eb2 c2 f2 c2 eb2 g2")\n'
				+ '    .s("sawtooth").lpf(sine.range(200,2000).slow(8))\n'
				+ '    .gain(0.5).distort(0.2)\n'
				+ ')',
		},
		house: {
			bpm: 124,
			code: '// House — 124 BPM\n'
				+ 'setcps(0.5167)\n\n'
				+ 'stack(\n'
				+ '  s("bd*4").bank("RolandTR909").gain(0.85),\n'
				+ '  s("~ oh ~ oh").bank("RolandTR909").gain(0.4).room(0.15),\n'
				+ '  s("hh*8").bank("RolandTR909").gain("[.3 .6]*4"),\n'
				+ '  s("~ cp ~ cp").bank("RolandTR909").gain(0.65).room(0.25),\n'
				+ '  note("c2 ~ c2 eb2 ~ c2 f2 ~")\n'
				+ '    .s("square").lpf(600).gain(0.5)\n'
				+ '    .every(8, x => x.rev())\n'
				+ ')',
		},
		ambient: {
			bpm: 70,
			code: '// Ambient — 70 BPM\n'
				+ 'setcps(0.2917)\n\n'
				+ 'stack(\n'
				+ '  note("<c4 eb4 g4 bb4>").s("sine")\n'
				+ '    .gain(0.3).room(0.8).delay(0.4)\n'
				+ '    .lpf(sine.range(400,2000).slow(16)).slow(2),\n'
				+ '  note("c5 ~ ~ eb5 ~ g5 ~ ~").s("triangle")\n'
				+ '    .gain(0.2).room(0.7).delay(0.5)\n'
				+ '    .pan(sine.slow(6))\n'
				+ '    .sometimes(x => x.speed(0.5)),\n'
				+ '  note("c2").s("sine").gain(0.25).lpf(200).slow(4)\n'
				+ ')',
		},
		dnb: {
			bpm: 174,
			code: '// Drum & Bass — 174 BPM\n'
				+ 'setcps(0.725)\n\n'
				+ 'stack(\n'
				+ '  s("bd ~ ~ ~ bd ~ ~ bd ~ ~ bd ~ ~ ~ ~ ~")\n'
				+ '    .bank("RolandTR808").gain(0.9).shape(0.2),\n'
				+ '  s("~ ~ ~ ~ sd ~ ~ ~ ~ ~ sd ~ ~ ~ sd ~")\n'
				+ '    .bank("RolandTR808").gain(0.7).room(0.2),\n'
				+ '  s("hh*16").bank("RolandTR808")\n'
				+ '    .gain("[.2 .4 .3 .5]*4")\n'
				+ '    .sometimes(x => x.speed(1.5)),\n'
				+ '  note("c1 ~ c1 ~ ~ c1 eb1 ~")\n'
				+ '    .s("sine").gain(0.6).lpf(150).distort(0.1)\n'
				+ ')',
		},
		minimal: {
			bpm: 120,
			code: '// Minimal — 120 BPM\n'
				+ 'setcps(0.5)\n\n'
				+ 'stack(\n'
				+ '  s("bd*4").gain(0.7),\n'
				+ '  s("~ hh ~ hh").gain(0.4),\n'
				+ '  s("~ ~ sd ~").gain(0.6).room(0.2)\n'
				+ ')',
		},
		trap: {
			bpm: 140,
			code: '// Trap — 140 BPM\n'
				+ 'setcps(0.5833)\n\n'
				+ 'stack(\n'
				+ '  s("bd ~ ~ ~ ~ ~ bd ~").bank("RolandTR808")\n'
				+ '    .gain(0.95).shape(0.4),\n'
				+ '  s("~ ~ ~ ~ sd ~ ~ ~").bank("RolandTR808")\n'
				+ '    .gain(0.8).room(0.15),\n'
				+ '  s("hh*16").bank("RolandTR808")\n'
				+ '    .gain("[.3 .2 .4 .2 .5 .2 .3 .2]*2")\n'
				+ '    .sometimes(x => x.speed(2)),\n'
				+ '  note("c1 ~ ~ ~ ~ ~ c1 ~").s("sine")\n'
				+ '    .gain(0.7).lpf(80).distort(0.05)\n'
				+ ')',
		},
		lofi: {
			bpm: 85,
			code: '// Lo-Fi — 85 BPM\n'
				+ 'setcps(0.3542)\n\n'
				+ 'stack(\n'
				+ '  s("bd ~ ~ bd ~ ~ bd ~").gain(0.7)\n'
				+ '    .lpf(400).shape(0.1),\n'
				+ '  s("~ ~ sd ~ ~ ~ sd ~").gain(0.5)\n'
				+ '    .room(0.4).lpf(2000),\n'
				+ '  s("hh*8").gain("[.2 .3]*4")\n'
				+ '    .lpf(3000).pan(sine.slow(3)),\n'
				+ '  note("<[d4,f4,a4] [c4,e4,g4] [bb3,d4,f4] [a3,c4,e4]>")\n'
				+ '    .s("triangle").gain(0.25).lpf(1200)\n'
				+ '    .room(0.5).delay(0.3).slow(2)\n'
				+ ')',
		},
		dub: {
			bpm: 72,
			code: '// Dub — 72 BPM\n'
				+ 'setcps(0.3)\n\n'
				+ 'stack(\n'
				+ '  s("bd ~ ~ ~ bd ~ ~ ~").gain(0.85)\n'
				+ '    .shape(0.2),\n'
				+ '  s("~ ~ ~ sd ~ ~ ~ ~").gain(0.6)\n'
				+ '    .room(0.6).delay(0.45),\n'
				+ '  s("hh ~ hh ~ hh ~ hh ~").gain(0.3)\n'
				+ '    .room(0.3).pan(sine.slow(2)),\n'
				+ '  note("c1 ~ c1 ~ ~ c1 ~ ~").s("sine")\n'
				+ '    .gain(0.7).lpf(120).distort(0.05),\n'
				+ '  note("c3 ~ ~ eb3 ~ ~ g3 ~").s("sine")\n'
				+ '    .gain(0.2).room(0.8).delay(0.6)\n'
				+ '    .lpf(800).pan("<-0.6 0.6>")\n'
				+ ')',
		},
		dubstep: {
			bpm: 140,
			code: '// Dubstep — 140 BPM (half-time)\n'
				+ 'setcps(0.5833)\n\n'
				+ 'stack(\n'
				+ '  s("bd ~ ~ ~ ~ ~ ~ ~ bd ~ ~ ~ ~ ~ ~ ~")\n'
				+ '    .bank("RolandTR808").gain(0.9).shape(0.3),\n'
				+ '  s("~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ ~ sd ~ ~ ~")\n'
				+ '    .bank("RolandTR808").gain(0.85).room(0.2),\n'
				+ '  s("hh*8").bank("RolandTR808")\n'
				+ '    .gain("[.2 .3]*4"),\n'
				+ '  note("c1 c1 c1 c1").s("sawtooth")\n'
				+ '    .lpf(sine.range(80,1500).slow(0.5))\n'
				+ '    .gain(0.6).distort(0.4)\n'
				+ ')',
		},
		trance: {
			bpm: 138,
			code: '// Trance — 138 BPM\n'
				+ 'setcps(0.575)\n\n'
				+ 'stack(\n'
				+ '  s("bd*4").bank("RolandTR909").gain(0.9).shape(0.2),\n'
				+ '  s("~ cp ~ cp").bank("RolandTR909").gain(0.55)\n'
				+ '    .room(0.35),\n'
				+ '  s("hh*16").bank("RolandTR909")\n'
				+ '    .gain("[.2 .4 .3 .5]*4"),\n'
				+ '  note("a4 c5 e5 a5 e5 c5 a4 e4")\n'
				+ '    .s("sawtooth").lpf(sine.range(800,4000).slow(8))\n'
				+ '    .gain(0.35).room(0.4).delay(0.2),\n'
				+ '  note("<[a3,c4,e4] [f3,a3,c4] [d3,f3,a3] [e3,g3,b3]>")\n'
				+ '    .s("sawtooth").lpf(2000).gain(0.2)\n'
				+ '    .room(0.5).slow(2)\n'
				+ ')',
		},
		synthwave: {
			bpm: 118,
			code: '// Synthwave — 118 BPM\n'
				+ 'setcps(0.4917)\n\n'
				+ 'stack(\n'
				+ '  s("bd*4").gain(0.8).shape(0.15),\n'
				+ '  s("~ sd ~ sd").gain(0.7)\n'
				+ '    .room(0.5).delay(0.1),\n'
				+ '  s("hh*8").gain("[.3 .5]*4")\n'
				+ '    .lpf(4000),\n'
				+ '  note("a2 a2 e2 e2 f2 f2 d2 d2")\n'
				+ '    .s("square").lpf(sine.range(300,1500).slow(8))\n'
				+ '    .gain(0.45),\n'
				+ '  note("<[a3,c4,e4] [f3,a3,c4]>")\n'
				+ '    .s("sawtooth").lpf(3000).gain(0.2)\n'
				+ '    .room(0.6).delay(0.25).slow(2)\n'
				+ ')',
		},
	};

	/**
	 * AlgoraveLiveCoder — manages the code editor UI.
	 */
	const AlgoraveLiveCoder = {
		/** @type {HTMLTextAreaElement|null} Code editor element. */
		editor: null,

		/** @type {HTMLElement|null} Container element. */
		container: null,

		/** @type {string} Current code in the editor. */
		currentCode: '',

		/** @type {string} Engine type. */
		engine: 'strudel',

		/**
		 * Whether the user has acknowledged this session that the Tone.js engine
		 * evaluates pasted code with the page's own permissions (F-AI-01).
		 * Session-scoped: reset on reload.
		 *
		 * @type {boolean}
		 */
		toneJsEvalConfirmed: false,

		/**
		 * Initialize the live coder.
		 *
		 * @param {string} containerId DOM ID of the container.
		 */
		init: function ( containerId ) {
			this.container = document.getElementById( containerId );
			if ( ! this.container ) {
				return;
			}

			this.editor = this.container.querySelector( '.algorave-code-editor' );
			if ( ! this.editor ) {
				return;
			}

			this.bindEvents();
			this.loadSavedCode();
			this.updateEvalWarning();
		},

		/**
		 * Bind UI event listeners.
		 */
		bindEvents: function () {
			// Execute code on Ctrl+Enter / Cmd+Enter.
			this.editor.addEventListener( 'keydown', ( e ) => {
				if ( ( e.ctrlKey || e.metaKey ) && e.key === 'Enter' ) {
					e.preventDefault();
					this.executeCode();
				}

				// Stop on Ctrl+. / Cmd+.
				if ( ( e.ctrlKey || e.metaKey ) && e.key === '.' ) {
					e.preventDefault();
					this.stop();
				}

				// Tab key inserts spaces instead of changing focus.
				if ( e.key === 'Tab' ) {
					e.preventDefault();
					const start = this.editor.selectionStart;
					const end = this.editor.selectionEnd;
					this.editor.value =
						this.editor.value.substring( 0, start ) +
						'  ' +
						this.editor.value.substring( end );
					this.editor.selectionStart = this.editor.selectionEnd = start + 2;
				}
			} );

			// Play button.
			const playBtn = this.container.querySelector( '.algorave-btn-play' );
			if ( playBtn ) {
				playBtn.addEventListener( 'click', () => this.executeCode() );
			}

			// Stop button.
			const stopBtn = this.container.querySelector( '.algorave-btn-stop' );
			if ( stopBtn ) {
				stopBtn.addEventListener( 'click', () => this.stop() );
			}

			// Engine selector.
			const engineSelect = this.container.querySelector( '.algorave-engine-select' );
			if ( engineSelect ) {
				engineSelect.addEventListener( 'change', ( e ) => {
					this.engine = e.target.value;
					this.updateEvalWarning();
				} );
			}

			// Bank selector.
			const bankSelect = this.container.querySelector( '.algorave-bank-select' );
			if ( bankSelect ) {
				bankSelect.addEventListener( 'change', ( e ) => {
					const bank = e.target.value;
					if ( bank && window.AlgoraveEngine ) {
						window.AlgoraveEngine.loadBank( bank );
					}
				} );
			}

			// BPM input.
			const bpmInput = this.container.querySelector( '.algorave-bpm-input' );
			if ( bpmInput ) {
				bpmInput.addEventListener( 'change', ( e ) => {
					const bpm = parseInt( e.target.value, 10 );
					if ( bpm >= 20 && bpm <= 300 && window.AlgoraveEngine ) {
						window.AlgoraveEngine.setBpm( bpm );
					}
				} );
			}

			// Pattern preset buttons.
			const presetBtns = this.container.querySelectorAll( '.algorave-preset-btn' );
			presetBtns.forEach( ( btn ) => {
				btn.addEventListener( 'click', ( e ) => {
					const presetName = e.target.getAttribute( 'data-preset' );
					this.loadPreset( presetName );
				} );
			} );

			// Auto-save code on change.
			this.editor.addEventListener( 'input', () => {
				this.saveCode();
				this.clearError();
			} );

			// Update play state display.
			document.addEventListener( 'algorave:playing', ( e ) => {
				this.updatePlayState( e.detail.playing );
			} );

			// Update BPM display when changed via engine.
			document.addEventListener( 'algorave:bpm', ( e ) => {
				if ( bpmInput && e.detail.bpm ) {
					bpmInput.value = e.detail.bpm;
				}
			} );

			// Show evaluation errors to the user.
			document.addEventListener( 'algorave:error', ( e ) => {
				this.showError( e.detail.message );
			} );
		},

		/**
		 * Load a pattern preset into the editor.
		 *
		 * @param {string} presetName Preset name key.
		 */
		loadPreset: function ( presetName ) {
			const preset = PRESETS[ presetName ];
			if ( ! preset ) {
				return;
			}

			this.editor.value = preset.code;
			this.saveCode();
			this.clearError();

			// Update BPM input.
			const bpmInput = this.container.querySelector( '.algorave-bpm-input' );
			if ( bpmInput ) {
				bpmInput.value = preset.bpm;
			}
		},

		/**
		 * Execute the code in the editor.
		 */
		executeCode: function () {
			const code = this.editor.value.trim();
			if ( ! code ) {
				return;
			}

			// F-AI-01: when the site operator has opted into the raw-eval Tone.js
			// engine, require one explicit confirmation per browser session before
			// compiling pasted code, so an unwitting paste cannot run code silently.
			if ( 'tonejs' === this.engine && this.isToneJsEvalAllowed() && ! this.toneJsEvalConfirmed ) {
				// eslint-disable-next-line no-alert
				const ok = window.confirm(
					'Tone.js live coding executes your code with this site\'s permissions. Only run code you trust. Continue?'
				);
				if ( ! ok ) {
					return;
				}
				this.toneJsEvalConfirmed = true;
			}

			this.currentCode = code;
			this.clearError();

			if ( window.AlgoraveEngine ) {
				window.AlgoraveEngine.play( code, this.engine );
			}

			this.saveCode();
		},

		/**
		 * Whether the raw-eval Tone.js engine is permitted on this site.
		 *
		 * The flag is forwarded from PHP (WP_MCP_AI_ALLOW_TONEJS_EVAL combined
		 * with the current user's capability) via nvoosAlgoraveConfig.
		 *
		 * @return {boolean} True when Tone.js eval is allowed for this user.
		 */
		isToneJsEvalAllowed: function () {
			return typeof nvoosAlgoraveConfig !== 'undefined' && !! nvoosAlgoraveConfig.tonejsEvalAllowed;
		},

		/**
		 * Show or hide the raw-eval warning banner.
		 *
		 * The banner is visible only when the Tone.js engine is selected AND the
		 * site operator has enabled raw eval. Strudel never shows it.
		 */
		updateEvalWarning: function () {
			if ( ! this.container ) {
				return;
			}

			const banner = this.container.querySelector( '.algorave-eval-warning' );
			if ( ! banner ) {
				return;
			}

			banner.hidden = ! ( 'tonejs' === this.engine && this.isToneJsEvalAllowed() );
		},

		/**
		 * Stop playback.
		 */
		stop: function () {
			if ( window.AlgoraveEngine ) {
				window.AlgoraveEngine.stop();
			}
		},

		/**
		 * Update the UI to reflect play/stop state.
		 *
		 * @param {boolean} playing Whether audio is playing.
		 */
		updatePlayState: function ( playing ) {
			if ( ! this.container ) {
				return;
			}

			const playBtn = this.container.querySelector( '.algorave-btn-play' );
			const indicator = this.container.querySelector( '.algorave-status-indicator' );

			if ( playBtn ) {
				playBtn.textContent = playing ? '⏸ Pause' : '▶ Play';
			}

			if ( indicator ) {
				indicator.classList.toggle( 'is-playing', playing );
			}
		},

		/**
		 * Save current code to localStorage.
		 */
		saveCode: function () {
			if ( this.editor ) {
				try {
					localStorage.setItem( 'algorave_code', this.editor.value );
					localStorage.setItem( 'algorave_engine', this.engine );
				} catch ( e ) {
					// Storage may be full or disabled.
				}
			}
		},

		/**
		 * Load saved code from localStorage.
		 */
		loadSavedCode: function () {
			try {
				const savedCode = localStorage.getItem( 'algorave_code' );
				const savedEngine = localStorage.getItem( 'algorave_engine' );

				if ( savedCode && this.editor ) {
					this.editor.value = savedCode;
				}

				if ( savedEngine ) {
					this.engine = savedEngine;
					const engineSelect = this.container.querySelector( '.algorave-engine-select' );
					if ( engineSelect ) {
						engineSelect.value = savedEngine;
					}
				}
			} catch ( e ) {
				// Storage may be unavailable.
			}
		},

		/**
		 * Show an error message below the editor.
		 *
		 * @param {string} message Error message text.
		 */
		showError: function ( message ) {
			if ( ! this.container ) {
				return;
			}
			this.clearError();

			const el = document.createElement( 'div' );
			el.className = 'algorave-error-message';
			el.textContent = message;

			// Insert after the editor textarea.
			if ( this.editor && this.editor.parentNode ) {
				this.editor.parentNode.insertBefore( el, this.editor.nextSibling );
			}
		},

		/**
		 * Clear any visible error message.
		 */
		clearError: function () {
			if ( ! this.container ) {
				return;
			}
			const existing = this.container.querySelector( '.algorave-error-message' );
			if ( existing ) {
				existing.remove();
			}
		},

		/**
		 * Set the editor content programmatically.
		 *
		 * @param {string} code   Code to insert.
		 * @param {string} engine Engine type.
		 */
		setCode: function ( code, engine ) {
			if ( this.editor ) {
				this.editor.value = code;
			}
			if ( engine ) {
				this.engine = engine;
				const engineSelect = this.container.querySelector( '.algorave-engine-select' );
				if ( engineSelect ) {
					engineSelect.value = engine;
				}
				this.updateEvalWarning();
			}
			this.saveCode();
		},
	};

	// Export globally.
	window.AlgoraveLiveCoder = AlgoraveLiveCoder;

	// Auto-initialize on DOMContentLoaded.
	document.addEventListener( 'DOMContentLoaded', function () {
		const container = document.getElementById( 'algorave-live-coder' );
		if ( container ) {
			AlgoraveLiveCoder.init( 'algorave-live-coder' );
		}
	} );
} )();
