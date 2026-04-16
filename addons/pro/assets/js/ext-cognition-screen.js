/**
 * Extended Cognition Toolkit — Screen Capture Module
 *
 * Captures a screenshot using getDisplayMedia() for full screen/window/tab modes,
 * or html2canvas / canvas drawImage for element-level captures.
 * Returns a base64 PNG.
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.0.0
 */

( function () {
	'use strict';

	/**
	 * Capture a screenshot.
	 *
	 * @param {Object}   req      Sensor request from the bridge.
	 * @param {Function} callback cb(data, error).
	 */
	function capture( req, callback ) {
		const mode     = req.mode || 'tab';
		const selector = req.selector || '';
		const annotate = !! req.annotate;
		const store    = !! req.store;

		if ( mode === 'element' ) {
			captureElement( selector, annotate, store, callback );
		} else {
			captureDisplay( mode, annotate, store, callback );
		}
	}

	/**
	 * Capture using getDisplayMedia (fullscreen / window / tab).
	 *
	 * @param {string}   mode     Capture mode.
	 * @param {boolean}  annotate Add timestamp overlay.
	 * @param {boolean}  store    Store flag to pass through.
	 * @param {Function} callback cb(data, error).
	 */
	function captureDisplay( mode, annotate, store, callback ) {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getDisplayMedia ) {
			callback( null, 'getDisplayMedia not supported in this browser.' );
			return;
		}

		const displaySurface = mode === 'window' ? 'window'
			: mode === 'fullscreen' ? 'monitor'
			: 'browser';

		const constraints = {
			video: {
				displaySurface: displaySurface,
			},
			audio: false,
		};

		navigator.mediaDevices.getDisplayMedia( constraints )
			.then( function ( stream ) {
				const track  = stream.getVideoTracks()[ 0 ];
				const settings = track.getSettings();
				const width  = settings.width  || 1280;
				const height = settings.height || 720;

				const video = document.createElement( 'video' );
				video.setAttribute( 'autoplay', '' );
				video.setAttribute( 'playsinline', '' );
				video.style.display = 'none';
				video.srcObject = stream;

				document.body.appendChild( video );

				video.addEventListener( 'loadedmetadata', function () {
					video.play().then( function () {
						const canvas = document.createElement( 'canvas' );
						canvas.width  = width;
						canvas.height = height;
						const ctx = canvas.getContext( '2d' );
						ctx.drawImage( video, 0, 0, width, height );

						if ( annotate ) {
							addTimestampOverlay( ctx, width, height );
						}

						const b64 = canvas.toDataURL( 'image/png' );

						// Stop stream.
						stream.getTracks().forEach( function ( t ) { t.stop(); } );
						video.srcObject = null;
						if ( video.parentNode ) {
							video.parentNode.removeChild( video );
						}

						callback(
							{
								image_base64: b64,
								dimensions:   { width: width, height: height },
								store:        store,
							},
							null
						);
					} ).catch( function ( err ) {
						stream.getTracks().forEach( function ( t ) { t.stop(); } );
						if ( video.parentNode ) {
							video.parentNode.removeChild( video );
						}
						callback( null, 'Video play failed: ' + err.message );
					} );
				} );

				video.addEventListener( 'error', function () {
					stream.getTracks().forEach( function ( t ) { t.stop(); } );
					if ( video.parentNode ) {
						video.parentNode.removeChild( video );
					}
					callback( null, 'Video element error during screen capture.' );
				} );
			} )
			.catch( function ( err ) {
				const msg = err && err.name === 'NotAllowedError'
					? 'Screen capture permission denied by user.'
					: 'getDisplayMedia failed: ' + ( err && err.message || String( err ) );
				callback( null, msg );
			} );
	}

	/**
	 * Capture a specific DOM element using canvas drawImage.
	 *
	 * @param {string}   selector CSS selector.
	 * @param {boolean}  annotate Add timestamp overlay.
	 * @param {boolean}  store    Store flag.
	 * @param {Function} callback cb(data, error).
	 */
	function captureElement( selector, annotate, store, callback ) {
		if ( ! selector ) {
			callback( null, 'A CSS selector is required for element capture mode.' );
			return;
		}

		const el = document.querySelector( selector );
		if ( ! el ) {
			callback( null, 'Element not found: ' + selector );
			return;
		}

		const rect   = el.getBoundingClientRect();
		const width  = Math.round( rect.width );
		const height = Math.round( rect.height );

		if ( width === 0 || height === 0 ) {
			callback( null, 'Element has zero dimensions: ' + selector );
			return;
		}

		// Attempt html2canvas if available.
		if ( typeof window.html2canvas === 'function' ) {
			window.html2canvas( el )
				.then( function ( canvas ) {
					if ( annotate ) {
						const ctx = canvas.getContext( '2d' );
						addTimestampOverlay( ctx, canvas.width, canvas.height );
					}
					const b64 = canvas.toDataURL( 'image/png' );
					callback( { image_base64: b64, dimensions: { width: canvas.width, height: canvas.height }, store: store }, null );
				} )
				.catch( function ( err ) {
					callback( null, 'html2canvas failed: ' + err.message );
				} );
			return;
		}

		// Fallback: SVG foreignObject technique.
		try {
			const data = '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '">'
				+ '<foreignObject width="100%" height="100%">'
				+ '<div xmlns="http://www.w3.org/1999/xhtml">'
				+ el.outerHTML
				+ '</div></foreignObject></svg>';

			const img = new Image();
			const svgBlob = new Blob( [ data ], { type: 'image/svg+xml;charset=utf-8' } );
			const url = URL.createObjectURL( svgBlob );

			img.onload = function () {
				const canvas = document.createElement( 'canvas' );
				canvas.width  = width;
				canvas.height = height;
				const ctx = canvas.getContext( '2d' );
				ctx.drawImage( img, 0, 0 );
				URL.revokeObjectURL( url );

				if ( annotate ) {
					addTimestampOverlay( ctx, width, height );
				}

				const b64 = canvas.toDataURL( 'image/png' );
				callback( { image_base64: b64, dimensions: { width: width, height: height }, store: store }, null );
			};
			img.onerror = function () {
				URL.revokeObjectURL( url );
				callback( null, 'SVG element capture failed.' );
			};
			img.src = url;
		} catch ( e ) {
			callback( null, 'Element capture not supported: ' + e.message );
		}
	}

	/**
	 * Draw a timestamp overlay in the bottom-right corner of a canvas.
	 *
	 * @param {CanvasRenderingContext2D} ctx    Canvas context.
	 * @param {number}                  width  Canvas width.
	 * @param {number}                  height Canvas height.
	 */
	function addTimestampOverlay( ctx, width, height ) {
		const now  = new Date().toISOString();
		const pad  = 8;
		const size = 12;

		ctx.save();
		ctx.font = size + 'px monospace';
		const tw = ctx.measureText( now ).width;
		ctx.fillStyle = 'rgba(0,0,0,0.55)';
		ctx.fillRect( width - tw - pad * 2, height - size - pad * 2, tw + pad * 2, size + pad );
		ctx.fillStyle = '#ffffff';
		ctx.fillText( now, width - tw - pad, height - pad );
		ctx.restore();
	}

	// Public API.
	window.NVExtCogScreen = {
		capture: capture,
	};
}() );
