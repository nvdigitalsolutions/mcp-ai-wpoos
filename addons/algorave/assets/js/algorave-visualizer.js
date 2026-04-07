/**
 * Algorave Audio Visualizer
 *
 * Canvas-based audio visualization using Web Audio API analyser data.
 * Supports waveform, spectrum, bars, circular, and particles modes.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/* global AlgoraveEngine */

( function () {
	'use strict';

	/**
	 * AlgoraveVisualizer — canvas-based audio visualization.
	 */
	const AlgoraveVisualizer = {
		/** @type {HTMLCanvasElement|null} Canvas element. */
		canvas: null,

		/** @type {CanvasRenderingContext2D|null} 2D rendering context. */
		ctx: null,

		/** @type {string} Current visualization mode. */
		mode: 'waveform',

		/** @type {string} Foreground color. */
		color: '#00ff88',

		/** @type {string} Background color. */
		backgroundColor: '#0a0a0a',

		/** @type {boolean} Whether the visualizer is active. */
		enabled: true,

		/** @type {number|null} Animation frame ID. */
		animationId: null,

		/**
		 * Initialize the visualizer.
		 *
		 * @param {string} canvasId DOM ID of the canvas element.
		 */
		init: function ( canvasId ) {
			this.canvas = document.getElementById( canvasId );
			if ( ! this.canvas ) {
				return;
			}

			this.ctx = this.canvas.getContext( '2d' );
			this.resizeCanvas();

			window.addEventListener( 'resize', () => this.resizeCanvas() );

			// Listen for browser commands from the chat interface.
			document.addEventListener( 'algorave:visualizer', ( e ) => {
				this.handleCommand( e.detail );
			} );

			this.startAnimation();
		},

		/**
		 * Resize canvas to container.
		 */
		resizeCanvas: function () {
			if ( ! this.canvas || ! this.canvas.parentElement ) {
				return;
			}
			this.canvas.width = this.canvas.parentElement.clientWidth;
			this.canvas.height = this.canvas.parentElement.clientHeight || 200;
		},

		/**
		 * Handle commands from the tool responses.
		 *
		 * @param {object} command Command data.
		 */
		handleCommand: function ( command ) {
			if ( command.mode ) {
				this.mode = command.mode;
			}
			if ( command.color ) {
				this.color = command.color;
			}
			if ( command.background_color ) {
				this.backgroundColor = command.background_color;
			}
			if ( typeof command.enabled !== 'undefined' ) {
				this.enabled = command.enabled;
				if ( ! this.enabled ) {
					this.clearCanvas();
				}
			}
		},

		/**
		 * Start the animation loop.
		 */
		startAnimation: function () {
			const draw = () => {
				this.animationId = requestAnimationFrame( draw );

				if ( ! this.enabled || ! this.ctx ) {
					return;
				}

				const data =
					window.AlgoraveEngine &&
					window.AlgoraveEngine.getAnalyserData
						? window.AlgoraveEngine.getAnalyserData()
						: null;

				this.clearCanvas();

				if ( ! data ) {
					this.drawIdleState();
					return;
				}

				switch ( this.mode ) {
					case 'spectrum':
						this.drawSpectrum( data );
						break;
					case 'bars':
						this.drawBars( data );
						break;
					case 'circular':
						this.drawCircular( data );
						break;
					case 'particles':
						this.drawParticles( data );
						break;
					default:
						this.drawWaveform( data );
						break;
				}
			};

			draw();
		},

		/**
		 * Clear the canvas.
		 */
		clearCanvas: function () {
			if ( ! this.ctx ) {
				return;
			}
			this.ctx.fillStyle = this.backgroundColor;
			this.ctx.fillRect( 0, 0, this.canvas.width, this.canvas.height );
		},

		/**
		 * Draw idle state (no audio).
		 */
		drawIdleState: function () {
			this.ctx.strokeStyle = this.color + '40';
			this.ctx.lineWidth = 1;
			this.ctx.beginPath();
			this.ctx.moveTo( 0, this.canvas.height / 2 );
			this.ctx.lineTo( this.canvas.width, this.canvas.height / 2 );
			this.ctx.stroke();
		},

		/**
		 * Draw waveform visualization.
		 *
		 * @param {Float32Array} data Audio data.
		 */
		drawWaveform: function ( data ) {
			const width = this.canvas.width;
			const height = this.canvas.height;
			const sliceWidth = width / data.length;

			this.ctx.lineWidth = 2;
			this.ctx.strokeStyle = this.color;
			this.ctx.beginPath();

			let x = 0;
			for ( let i = 0; i < data.length; i++ ) {
				const v = data[ i ];
				const y = ( v + 1 ) / 2 * height;

				if ( i === 0 ) {
					this.ctx.moveTo( x, y );
				} else {
					this.ctx.lineTo( x, y );
				}
				x += sliceWidth;
			}

			this.ctx.stroke();
		},

		/**
		 * Draw spectrum visualization.
		 *
		 * @param {Float32Array} data Audio data.
		 */
		drawSpectrum: function ( data ) {
			const width = this.canvas.width;
			const height = this.canvas.height;
			const barWidth = width / data.length * 2;

			for ( let i = 0; i < data.length / 2; i++ ) {
				const value = ( data[ i ] + 1 ) / 2;
				const barHeight = value * height;

				const hue = ( i / data.length ) * 360;
				this.ctx.fillStyle = 'hsl(' + hue + ', 100%, 50%)';
				this.ctx.fillRect(
					i * barWidth,
					height - barHeight,
					barWidth - 1,
					barHeight
				);
			}
		},

		/**
		 * Draw bars visualization.
		 *
		 * @param {Float32Array} data Audio data.
		 */
		drawBars: function ( data ) {
			const width = this.canvas.width;
			const height = this.canvas.height;
			const barCount = 32;
			const barWidth = width / barCount - 2;
			const step = Math.floor( data.length / barCount );

			for ( let i = 0; i < barCount; i++ ) {
				const value = Math.abs( data[ i * step ] );
				const barHeight = value * height;

				this.ctx.fillStyle = this.color;
				this.ctx.fillRect(
					i * ( barWidth + 2 ),
					height - barHeight,
					barWidth,
					barHeight
				);
			}
		},

		/**
		 * Draw circular visualization.
		 *
		 * @param {Float32Array} data Audio data.
		 */
		drawCircular: function ( data ) {
			const cx = this.canvas.width / 2;
			const cy = this.canvas.height / 2;
			const radius = Math.min( cx, cy ) * 0.6;

			this.ctx.strokeStyle = this.color;
			this.ctx.lineWidth = 2;
			this.ctx.beginPath();

			for ( let i = 0; i < data.length; i++ ) {
				const angle = ( i / data.length ) * Math.PI * 2;
				const value = ( data[ i ] + 1 ) / 2;
				const r = radius + value * radius * 0.5;

				const x = cx + Math.cos( angle ) * r;
				const y = cy + Math.sin( angle ) * r;

				if ( i === 0 ) {
					this.ctx.moveTo( x, y );
				} else {
					this.ctx.lineTo( x, y );
				}
			}

			this.ctx.closePath();
			this.ctx.stroke();
		},

		/**
		 * Draw particles visualization.
		 *
		 * @param {Float32Array} data Audio data.
		 */
		drawParticles: function ( data ) {
			const width = this.canvas.width;
			const height = this.canvas.height;
			const step = Math.max( 1, Math.floor( data.length / 64 ) );

			this.ctx.fillStyle = this.color;

			for ( let i = 0; i < data.length; i += step ) {
				const value = Math.abs( data[ i ] );
				const size = value * 10 + 1;
				const x = ( i / data.length ) * width;
				const y = height / 2 + data[ i ] * height / 2;

				this.ctx.beginPath();
				this.ctx.arc( x, y, size, 0, Math.PI * 2 );
				this.ctx.fill();
			}
		},
	};

	// Export globally.
	window.AlgoraveVisualizer = AlgoraveVisualizer;

	// Auto-initialize on DOMContentLoaded.
	document.addEventListener( 'DOMContentLoaded', function () {
		const canvas = document.getElementById( 'algorave-visualizer-canvas' );
		if ( canvas ) {
			AlgoraveVisualizer.init( 'algorave-visualizer-canvas' );
		}
	} );
} )();
