/**
 * Algorave Audio Visualizer
 *
 * Canvas-based audio visualization using Web Audio API analyser data.
 * Supports waveform, spectrum, bars, circular, particles, scope, spectrogram, and lissajous modes.
 *
 * Industry best-practices applied:
 * - DPI-aware canvas rendering (devicePixelRatio)
 * - Frequency data for spectrum/bars (getByteFrequencyData)
 * - Smooth decay with peak hold for bars
 * - Glow effects via shadow layers
 * - Semi-transparent clear for trailing effects
 * - Trigger-stabilised oscilloscope (scope mode)
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

/* global AlgoraveEngine */

( function () {
	'use strict';

	/**
	 * Parse a CSS hex colour into {r,g,b} (0-255).
	 *
	 * @param {string} hex Hex colour string.
	 * @return {{r:number,g:number,b:number}} Parsed colour.
	 */
	function hexToRgb( hex ) {
		const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec( hex );
		return result
			? {
				r: parseInt( result[ 1 ], 16 ),
				g: parseInt( result[ 2 ], 16 ),
				b: parseInt( result[ 3 ], 16 ),
			}
			: { r: 0, g: 255, b: 136 };
	}

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

		/** @type {string} Foreground colour. */
		color: '#00ff88',

		/** @type {string} Background colour. */
		backgroundColor: '#0a0a0a',

		/** @type {boolean} Whether the visualizer is active. */
		enabled: true,

		/** @type {number|null} Animation frame ID. */
		animationId: null,

		/** @type {number} CSS pixel width of the canvas. */
		cssWidth: 0,

		/** @type {number} CSS pixel height of the canvas. */
		cssHeight: 0,

		/** @type {Float32Array|null} Smoothed bar heights for decay effect. */
		barHeights: null,

		/** @type {Float32Array|null} Peak hold values for bars. */
		barPeaks: null,

		/** @type {number} Idle animation phase counter. */
		idlePhase: 0,

		/** @type {ImageData|null} Scrolling spectrogram buffer. */
		spectrogramData: null,

		/** @type {string} Status message shown while waiting for audio. */
		statusMessage: 'WAITING FOR AUDIO',

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

			// Update status message based on engine lifecycle events.
			document.addEventListener( 'algorave:strudel-ready', () => {
				if ( this.statusMessage === 'INITIALIZING AUDIO...' ) {
					this.statusMessage = 'WAITING FOR AUDIO';
				}
			} );
			document.addEventListener( 'algorave:analyser-connected', () => {
				this.statusMessage = 'ANALYSER CONNECTED';
			} );
			document.addEventListener( 'algorave:playing', ( e ) => {
				if ( e.detail && e.detail.playing ) {
					this.statusMessage = 'PLAYING';
				} else {
					this.statusMessage = 'WAITING FOR AUDIO';
				}
			} );

			// Wire up the mode-switch overlay buttons.
			this.bindModeButtons();

			this.startAnimation();
		},

		/**
		 * Attach click handlers to the visualizer overlay mode buttons.
		 */
		bindModeButtons: function () {
			if ( ! this.canvas || ! this.canvas.parentElement ) {
				return;
			}
			const controls = this.canvas.parentElement.querySelector( '.algorave-visualizer-controls' );
			if ( ! controls ) {
				return;
			}
			const self = this;
			controls.querySelectorAll( 'button[data-mode]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					self.mode = btn.getAttribute( 'data-mode' );

					// Highlight active button.
					controls.querySelectorAll( 'button[data-mode]' ).forEach( function ( b ) {
						b.classList.remove( 'is-active' );
					} );
					btn.classList.add( 'is-active' );
				} );
			} );
		},

		/**
		 * Resize canvas to container, accounting for devicePixelRatio.
		 */
		resizeCanvas: function () {
			if ( ! this.canvas || ! this.canvas.parentElement ) {
				return;
			}

			const parent = this.canvas.parentElement;
			const dpr = window.devicePixelRatio || 1;

			this.cssWidth = parent.clientWidth;
			this.cssHeight = parent.clientHeight || 200;

			// Set drawing-buffer size to match physical pixels.
			this.canvas.width = Math.round( this.cssWidth * dpr );
			this.canvas.height = Math.round( this.cssHeight * dpr );

			// Scale the context so drawing operations use CSS pixels.
			this.ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );

			// Reset decay buffers on resize.
			this.barHeights = null;
			this.barPeaks = null;
			this.spectrogramData = null;
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
					this.clearCanvas( 1 );
				}
			}
			if ( command.fullscreen ) {
				this.toggleFullscreen();
			}
		},

		/**
		 * Toggle fullscreen on the visualizer container.
		 */
		toggleFullscreen: function () {
			if ( ! this.canvas || ! this.canvas.parentElement ) {
				return;
			}
			this.canvas.parentElement.classList.toggle( 'is-fullscreen' );
			this.resizeCanvas();
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

				const waveData =
					window.AlgoraveEngine && window.AlgoraveEngine.getAnalyserData
						? window.AlgoraveEngine.getAnalyserData()
						: null;

				const freqData =
					window.AlgoraveEngine && window.AlgoraveEngine.getFrequencyData
						? window.AlgoraveEngine.getFrequencyData()
						: null;

				// Use semi-transparent clear for trailing effects on some modes.
				const trailModes = [ 'particles', 'circular', 'lissajous' ];
				if ( this.mode === 'spectrogram' ) {
					// Spectrogram manages its own buffer — skip clearing.
				} else if ( trailModes.indexOf( this.mode ) !== -1 ) {
					this.clearCanvas( 0.25 );
				} else {
					this.clearCanvas( 1 );
				}

				if ( ! waveData && ! freqData ) {
					this.drawIdleState();
					return;
				}

				switch ( this.mode ) {
					case 'spectrum':
						this.drawSpectrum( freqData || waveData );
						break;
					case 'bars':
						this.drawBars( freqData || waveData );
						break;
					case 'circular':
						this.drawCircular( waveData );
						break;
					case 'particles':
						this.drawParticles( waveData );
						break;
					case 'scope':
						this.drawScope( waveData );
						break;
					case 'spectrogram':
						this.drawSpectrogram( freqData || waveData );
						break;
					case 'lissajous':
						this.drawLissajous( waveData );
						break;
					default:
						this.drawWaveform( waveData );
						break;
				}
			};

			draw();
		},

		/**
		 * Clear the canvas with optional opacity for trail effects.
		 *
		 * @param {number} alpha Opacity (0-1). 1 = full clear.
		 */
		clearCanvas: function ( alpha ) {
			if ( ! this.ctx ) {
				return;
			}
			const w = this.cssWidth;
			const h = this.cssHeight;

			if ( alpha >= 1 ) {
				this.ctx.fillStyle = this.backgroundColor;
				this.ctx.fillRect( 0, 0, w, h );
			} else {
				// Semi-transparent overlay for trails.
				const rgb = hexToRgb( this.backgroundColor );
				const rgbStr = rgb.r + ',' + rgb.g + ',' + rgb.b;
				this.ctx.fillStyle = 'rgba(' + rgbStr + ',' + alpha + ')';
				this.ctx.fillRect( 0, 0, w, h );
			}
		},

		/**
		 * Draw idle state — animated breathing sine line.
		 */
		drawIdleState: function () {
			const w = this.cssWidth;
			const h = this.cssHeight;
			const midY = h / 2;

			this.idlePhase += 0.02;

			this.ctx.strokeStyle = this.color + '30';
			this.ctx.lineWidth = 1;
			this.ctx.beginPath();

			for ( let x = 0; x < w; x++ ) {
				const y = midY + Math.sin( x * 0.02 + this.idlePhase ) * 8 * Math.sin( this.idlePhase * 0.5 );
				if ( x === 0 ) {
					this.ctx.moveTo( x, y );
				} else {
					this.ctx.lineTo( x, y );
				}
			}
			this.ctx.stroke();

			// Centre label.
			this.ctx.fillStyle = this.color + '20';
			this.ctx.font = '12px monospace';
			this.ctx.textAlign = 'center';
			this.ctx.fillText( this.statusMessage, w / 2, midY + 30 );
		},

		// ── Waveform ──────────────────────────────────────────────

		/**
		 * Draw waveform with glow effect.
		 *
		 * @param {Float32Array|null} data Time-domain audio data.
		 */
		drawWaveform: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const w = this.cssWidth;
			const h = this.cssHeight;
			const sliceWidth = w / data.length;
			const midY = h / 2;

			// Glow layer (wide, dim).
			this.ctx.save();
			this.ctx.shadowColor = this.color;
			this.ctx.shadowBlur = 16;
			this.ctx.lineWidth = 3;
			this.ctx.strokeStyle = this.color + '60';
			this.ctx.beginPath();

			let x = 0;
			for ( let i = 0; i < data.length; i++ ) {
				const y = midY + data[ i ] * midY;
				if ( i === 0 ) {
					this.ctx.moveTo( x, y );
				} else {
					this.ctx.lineTo( x, y );
				}
				x += sliceWidth;
			}
			this.ctx.stroke();
			this.ctx.restore();

			// Crisp foreground stroke.
			this.ctx.lineWidth = 2;
			this.ctx.strokeStyle = this.color;
			this.ctx.beginPath();

			x = 0;
			for ( let i = 0; i < data.length; i++ ) {
				const y = midY + data[ i ] * midY;
				if ( i === 0 ) {
					this.ctx.moveTo( x, y );
				} else {
					this.ctx.lineTo( x, y );
				}
				x += sliceWidth;
			}
			this.ctx.stroke();
		},

		// ── Spectrum ──────────────────────────────────────────────

		/**
		 * Draw spectrum using frequency data with gradient bars.
		 *
		 * @param {Uint8Array|Float32Array} data Frequency or time-domain data.
		 */
		drawSpectrum: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const w = this.cssWidth;
			const h = this.cssHeight;
			const isFreq = data instanceof Uint8Array;

			// Use the lower three-quarters of frequency bins (most musically relevant).
			const usableBins = Math.floor( data.length * 0.75 );
			const barWidth = w / usableBins;

			this.ctx.save();
			this.ctx.shadowColor = this.color;
			this.ctx.shadowBlur = 4;

			for ( let i = 0; i < usableBins; i++ ) {
				let value;
				if ( isFreq ) {
					value = data[ i ] / 255;
				} else {
					value = Math.abs( data[ i ] );
				}

				const barHeight = value * h;
				const hue = ( i / usableBins ) * 270; // Purple → red range.

				// Gradient fill per bar.
				const grad = this.ctx.createLinearGradient( 0, h, 0, h - barHeight );
				grad.addColorStop( 0, 'hsl(' + hue + ', 100%, 50%)' );
				grad.addColorStop( 1, 'hsl(' + hue + ', 80%, 25%)' );
				this.ctx.fillStyle = grad;

				this.ctx.fillRect(
					i * barWidth,
					h - barHeight,
					Math.max( barWidth - 1, 1 ),
					barHeight
				);
			}

			this.ctx.restore();
		},

		// ── Bars ──────────────────────────────────────────────────

		/**
		 * Draw equaliser bars with smooth decay and peak hold.
		 *
		 * @param {Uint8Array|Float32Array} data Frequency or time-domain data.
		 */
		drawBars: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const w = this.cssWidth;
			const h = this.cssHeight;
			const barCount = 48;
			const gap = 2;
			const barWidth = ( w / barCount ) - gap;
			const isFreq = data instanceof Uint8Array;
			const step = Math.max( 1, Math.floor( data.length / barCount ) );

			// Initialize decay buffers.
			if ( ! this.barHeights || this.barHeights.length !== barCount ) {
				this.barHeights = new Float32Array( barCount );
				this.barPeaks = new Float32Array( barCount );
			}

			const rgb = hexToRgb( this.color );
			const rgbStr = rgb.r + ',' + rgb.g + ',' + rgb.b;
			const decay = 0.92;
			const peakDecay = 0.98;

			this.ctx.save();
			this.ctx.shadowColor = this.color;
			this.ctx.shadowBlur = 6;

			for ( let i = 0; i < barCount; i++ ) {
				// Average a few neighbouring bins for smoother display.
				let sum = 0;
				const samples = Math.min( step, 4 );
				for ( let j = 0; j < samples; j++ ) {
					const idx = Math.min( i * step + j, data.length - 1 );
					if ( isFreq ) {
						sum += data[ idx ] / 255;
					} else {
						sum += Math.abs( data[ idx ] );
					}
				}
				const raw = sum / samples;

				// Smooth decay — bars fall gradually.
				this.barHeights[ i ] = Math.max( raw, this.barHeights[ i ] * decay );
				const barH = this.barHeights[ i ] * h;

				// Peak hold — small marker at highest point.
				if ( raw > this.barPeaks[ i ] ) {
					this.barPeaks[ i ] = raw;
				} else {
					this.barPeaks[ i ] *= peakDecay;
				}
				const peakY = h - this.barPeaks[ i ] * h;

				// Gradient bar.
				const xPos = i * ( barWidth + gap );
				const grad = this.ctx.createLinearGradient( 0, h, 0, h - barH );
				grad.addColorStop( 0, 'rgba(' + rgbStr + ',1)' );
				grad.addColorStop( 0.6, 'rgba(' + rgbStr + ',0.7)' );
				grad.addColorStop( 1, 'rgba(' + rgbStr + ',0.3)' );
				this.ctx.fillStyle = grad;
				this.ctx.fillRect( xPos, h - barH, barWidth, barH );

				// Peak indicator line.
				this.ctx.fillStyle = this.color;
				this.ctx.fillRect( xPos, peakY, barWidth, 2 );
			}

			this.ctx.restore();
		},

		// ── Circular ──────────────────────────────────────────────

		/**
		 * Draw circular visualisation with gradient fill and glow.
		 *
		 * @param {Float32Array|null} data Time-domain audio data.
		 */
		drawCircular: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const w = this.cssWidth;
			const h = this.cssHeight;
			const cx = w / 2;
			const cy = h / 2;
			const baseRadius = Math.min( cx, cy ) * 0.4;
			const maxExtend = Math.min( cx, cy ) * 0.35;

			// Outer glow.
			this.ctx.save();
			this.ctx.shadowColor = this.color;
			this.ctx.shadowBlur = 20;

			// Draw filled shape.
			this.ctx.beginPath();
			const step = Math.max( 1, Math.floor( data.length / 180 ) );
			for ( let i = 0; i < data.length; i += step ) {
				const angle = ( i / data.length ) * Math.PI * 2 - Math.PI / 2;
				const value = Math.abs( data[ i ] );
				const r = baseRadius + value * maxExtend;
				const px = cx + Math.cos( angle ) * r;
				const py = cy + Math.sin( angle ) * r;
				if ( i === 0 ) {
					this.ctx.moveTo( px, py );
				} else {
					this.ctx.lineTo( px, py );
				}
			}
			this.ctx.closePath();

			// Radial gradient fill.
			const grad = this.ctx.createRadialGradient( cx, cy, baseRadius * 0.5, cx, cy, baseRadius + maxExtend );
			const rgb = hexToRgb( this.color );
			const rgbStr = rgb.r + ',' + rgb.g + ',' + rgb.b;
			grad.addColorStop( 0, 'rgba(' + rgbStr + ',0.05)' );
			grad.addColorStop( 0.7, 'rgba(' + rgbStr + ',0.15)' );
			grad.addColorStop( 1, 'rgba(' + rgbStr + ',0.3)' );
			this.ctx.fillStyle = grad;
			this.ctx.fill();

			this.ctx.strokeStyle = this.color;
			this.ctx.lineWidth = 2;
			this.ctx.stroke();
			this.ctx.restore();

			// Inner circle reference.
			this.ctx.strokeStyle = this.color + '20';
			this.ctx.lineWidth = 1;
			this.ctx.beginPath();
			this.ctx.arc( cx, cy, baseRadius, 0, Math.PI * 2 );
			this.ctx.stroke();
		},

		// ── Particles ─────────────────────────────────────────────

		/**
		 * Draw particles with velocity and alpha decay.
		 *
		 * @param {Float32Array|null} data Time-domain audio data.
		 */
		drawParticles: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const w = this.cssWidth;
			const h = this.cssHeight;
			const midY = h / 2;
			const step = Math.max( 1, Math.floor( data.length / 80 ) );
			const rgb = hexToRgb( this.color );
			const rgbStr = rgb.r + ',' + rgb.g + ',' + rgb.b;

			for ( let i = 0; i < data.length; i += step ) {
				const value = Math.abs( data[ i ] );
				const size = value * 12 + 1;
				const alpha = Math.min( 1, value * 2 + 0.15 );
				const x = ( i / data.length ) * w;
				const y = midY + data[ i ] * midY * 0.9;

				// Glow.
				this.ctx.save();
				this.ctx.shadowColor = this.color;
				this.ctx.shadowBlur = size * 2;

				this.ctx.fillStyle = 'rgba(' + rgbStr + ',' + alpha + ')';
				this.ctx.beginPath();
				this.ctx.arc( x, y, size, 0, Math.PI * 2 );
				this.ctx.fill();
				this.ctx.restore();
			}
		},

		// ── Scope (oscilloscope) ──────────────────────────────────

		/**
		 * Draw trigger-stabilised oscilloscope.
		 *
		 * Finds a zero-crossing in the waveform and starts drawing from
		 * there so the display is stable (standard oscilloscope behaviour).
		 *
		 * @param {Float32Array|null} data Time-domain audio data.
		 */
		drawScope: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const w = this.cssWidth;
			const h = this.cssHeight;
			const midY = h / 2;

			// Find trigger point: first rising zero-crossing.
			let trigger = 0;
			for ( let i = 1; i < data.length - 1; i++ ) {
				if ( data[ i - 1 ] < 0 && data[ i ] >= 0 ) {
					trigger = i;
					break;
				}
			}

			// Draw one screen-width of samples starting from trigger.
			const samplesToShow = Math.min( data.length - trigger, Math.floor( data.length * 0.5 ) );
			const sliceWidth = w / samplesToShow;

			// Grid lines.
			this.ctx.strokeStyle = this.color + '12';
			this.ctx.lineWidth = 1;
			for ( let g = 0; g <= 4; g++ ) {
				const gy = ( g / 4 ) * h;
				this.ctx.beginPath();
				this.ctx.moveTo( 0, gy );
				this.ctx.lineTo( w, gy );
				this.ctx.stroke();
			}
			for ( let g = 0; g <= 8; g++ ) {
				const gx = ( g / 8 ) * w;
				this.ctx.beginPath();
				this.ctx.moveTo( gx, 0 );
				this.ctx.lineTo( gx, h );
				this.ctx.stroke();
			}

			// Centre line.
			this.ctx.strokeStyle = this.color + '30';
			this.ctx.beginPath();
			this.ctx.moveTo( 0, midY );
			this.ctx.lineTo( w, midY );
			this.ctx.stroke();

			// Glow layer.
			this.ctx.save();
			this.ctx.shadowColor = this.color;
			this.ctx.shadowBlur = 10;
			this.ctx.lineWidth = 2.5;
			this.ctx.strokeStyle = this.color + '50';
			this.ctx.beginPath();

			let x = 0;
			for ( let i = 0; i < samplesToShow; i++ ) {
				const y = midY + data[ trigger + i ] * midY * 0.9;
				if ( i === 0 ) {
					this.ctx.moveTo( x, y );
				} else {
					this.ctx.lineTo( x, y );
				}
				x += sliceWidth;
			}
			this.ctx.stroke();
			this.ctx.restore();

			// Crisp foreground.
			this.ctx.lineWidth = 1.5;
			this.ctx.strokeStyle = this.color;
			this.ctx.beginPath();

			x = 0;
			for ( let i = 0; i < samplesToShow; i++ ) {
				const y = midY + data[ trigger + i ] * midY * 0.9;
				if ( i === 0 ) {
					this.ctx.moveTo( x, y );
				} else {
					this.ctx.lineTo( x, y );
				}
				x += sliceWidth;
			}
			this.ctx.stroke();
		},

		// ── Spectrogram (waterfall) ──────────────────────────────

		/**
		 * Draw scrolling spectrogram heatmap.
		 *
		 * Each animation frame adds a new column on the right and scrolls
		 * the existing image left. Uses a viridis-style colour palette
		 * mapped to frequency amplitude.
		 *
		 * @param {Uint8Array|Float32Array|null} data Frequency data.
		 */
		drawSpectrogram: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const dpr = window.devicePixelRatio || 1;
			const pw = this.canvas.width;   // physical pixels
			const ph = this.canvas.height;

			// On first call or canvas resize, initialise buffer.
			if ( ! this.spectrogramData || this.spectrogramData.width !== pw || this.spectrogramData.height !== ph ) {
				this.ctx.save();
				this.ctx.setTransform( 1, 0, 0, 1, 0, 0 ); // reset to physical pixels
				this.spectrogramData = this.ctx.createImageData( pw, ph );
				this.ctx.restore();
			}

			const imgData = this.spectrogramData;
			const isFreq = data instanceof Uint8Array;
			const stride = pw * 4;  // bytes per row
			const colWidth = Math.max( 1, Math.round( 2 * dpr ) );

			// Scroll existing data left by colWidth pixels.
			for ( let y = 0; y < ph; y++ ) {
				const rowOff = y * stride;
				for ( let x = 0; x < pw - colWidth; x++ ) {
					const dst = rowOff + x * 4;
					const src = rowOff + ( x + colWidth ) * 4;
					imgData.data[ dst ]     = imgData.data[ src ];
					imgData.data[ dst + 1 ] = imgData.data[ src + 1 ];
					imgData.data[ dst + 2 ] = imgData.data[ src + 2 ];
					imgData.data[ dst + 3 ] = imgData.data[ src + 3 ];
				}
			}

			// Write new column(s) on the right.
			const usableBins = Math.floor( data.length * 0.75 );
			for ( let y = 0; y < ph; y++ ) {
				// Map y to frequency bin (low freq at bottom).
				const binIdx = Math.floor( ( 1 - y / ph ) * usableBins );
				let value;
				if ( isFreq ) {
					value = data[ Math.min( binIdx, data.length - 1 ) ] / 255;
				} else {
					value = Math.abs( data[ Math.min( binIdx, data.length - 1 ) ] );
				}

				// Viridis-inspired palette: dark purple → teal → yellow.
				const t = Math.min( 1, value * 1.2 );
				let r, g, b;
				if ( t < 0.33 ) {
					const p = t / 0.33;
					r = Math.floor( 68 * p );
					g = Math.floor( 1 + 53 * p );
					b = Math.floor( 84 + 80 * p );
				} else if ( t < 0.66 ) {
					const p = ( t - 0.33 ) / 0.33;
					r = Math.floor( 68 - 35 * p );
					g = Math.floor( 54 + 133 * p );
					b = Math.floor( 164 - 30 * p );
				} else {
					const p = ( t - 0.66 ) / 0.34;
					r = Math.floor( 33 + 220 * p );
					g = Math.floor( 187 + 60 * p );
					b = Math.floor( 134 - 134 * p );
				}

				const rowOff = y * stride;
				for ( let c = 0; c < colWidth; c++ ) {
					const xp = pw - colWidth + c;
					const off = rowOff + xp * 4;
					imgData.data[ off ]     = r;
					imgData.data[ off + 1 ] = g;
					imgData.data[ off + 2 ] = b;
					imgData.data[ off + 3 ] = 255;
				}
			}

			// Draw to canvas in physical pixel space.
			this.ctx.save();
			this.ctx.setTransform( 1, 0, 0, 1, 0, 0 );
			this.ctx.putImageData( imgData, 0, 0 );
			this.ctx.restore();

			// Frequency axis labels (in CSS pixels).
			this.ctx.fillStyle = 'rgba(255,255,255,0.35)';
			this.ctx.font = '10px monospace';
			this.ctx.textAlign = 'left';
			const labels = [ '20k', '10k', '5k', '1k', '200', '20' ];
			for ( let i = 0; i < labels.length; i++ ) {
				const yPos = ( i / ( labels.length - 1 ) ) * this.cssHeight;
				this.ctx.fillText( labels[ i ] + ' Hz', 4, yPos + 12 );
			}
		},

		// ── Lissajous (X/Y stereo scope) ─────────────────────────

		/**
		 * Draw Lissajous X/Y oscilloscope.
		 *
		 * Plots time-domain samples as (x[i], x[i+offset]) pairs to
		 * create a Lissajous figure — an industry-standard technique for
		 * visualising stereo correlation and phase relationships.
		 *
		 * @param {Float32Array|null} data Time-domain audio data.
		 */
		drawLissajous: function ( data ) {
			if ( ! data ) {
				this.drawIdleState();
				return;
			}

			const w = this.cssWidth;
			const h = this.cssHeight;
			const cx = w / 2;
			const cy = h / 2;
			const scale = Math.min( cx, cy ) * 0.85;

			// Phase offset — quarter of the buffer for 90° shift (Lissajous).
			const offset = Math.floor( data.length / 4 );

			// Grid circles.
			this.ctx.strokeStyle = this.color + '10';
			this.ctx.lineWidth = 1;
			for ( let r = 1; r <= 3; r++ ) {
				this.ctx.beginPath();
				this.ctx.arc( cx, cy, ( scale / 3 ) * r, 0, Math.PI * 2 );
				this.ctx.stroke();
			}

			// Crosshairs.
			this.ctx.strokeStyle = this.color + '15';
			this.ctx.beginPath();
			this.ctx.moveTo( cx - scale, cy );
			this.ctx.lineTo( cx + scale, cy );
			this.ctx.moveTo( cx, cy - scale );
			this.ctx.lineTo( cx, cy + scale );
			this.ctx.stroke();

			// Glow layer.
			this.ctx.save();
			this.ctx.shadowColor = this.color;
			this.ctx.shadowBlur = 12;
			this.ctx.lineWidth = 1.5;
			this.ctx.strokeStyle = this.color + '40';
			this.ctx.beginPath();

			const step = Math.max( 1, Math.floor( data.length / 512 ) );
			for ( let i = 0; i < data.length - offset; i += step ) {
				const px = cx + data[ i ] * scale;
				const py = cy + data[ i + offset ] * scale;
				if ( i === 0 ) {
					this.ctx.moveTo( px, py );
				} else {
					this.ctx.lineTo( px, py );
				}
			}
			this.ctx.stroke();
			this.ctx.restore();

			// Foreground layer.
			this.ctx.lineWidth = 1;
			this.ctx.strokeStyle = this.color;
			this.ctx.beginPath();

			for ( let i = 0; i < data.length - offset; i += step ) {
				const px = cx + data[ i ] * scale;
				const py = cy + data[ i + offset ] * scale;
				if ( i === 0 ) {
					this.ctx.moveTo( px, py );
				} else {
					this.ctx.lineTo( px, py );
				}
			}
			this.ctx.stroke();
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
