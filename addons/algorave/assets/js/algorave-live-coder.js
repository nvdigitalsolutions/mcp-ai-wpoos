/**
 * Algorave Live Coder Interface
 *
 * Provides the interactive code editor UI for writing and executing
 * live coding patterns in the browser.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/* global AlgoraveEngine, nvoosAlgoraveConfig */

( function () {
	'use strict';

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

			// Auto-save code on change.
			this.editor.addEventListener( 'input', () => {
				this.saveCode();
			} );

			// Update play state display.
			document.addEventListener( 'algorave:playing', ( e ) => {
				this.updatePlayState( e.detail.playing );
			} );
		},

		/**
		 * Execute the code in the editor.
		 */
		executeCode: function () {
			const code = this.editor.value.trim();
			if ( ! code ) {
				return;
			}
			this.currentCode = code;

			if ( window.AlgoraveEngine ) {
				window.AlgoraveEngine.play( code, this.engine );
			}

			this.saveCode();
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
