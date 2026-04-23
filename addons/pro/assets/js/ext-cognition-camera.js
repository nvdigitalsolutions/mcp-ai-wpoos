/**
 * Extended Cognition Toolkit — Camera Module
 *
 * Captures a still frame from the user's camera using MediaDevices.getUserMedia()
 * combined with ImageCapture (with canvas fallback). Returns a base64 JPEG.
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.0.0
 */

( function () {
	'use strict';

	/** Resolution presets in pixels. */
	const RESOLUTIONS = {
		low:    { width: 320,  height: 240 },
		medium: { width: 640,  height: 480 },
		high:   { width: 1280, height: 720 },
	};

	/** Active media stream (retained to allow reuse). */
	let activeStream = null;

	/**
	 * Capture a still frame from the camera.
	 *
	 * @param {Object}   req      Sensor request from the bridge.
	 * @param {Function} callback cb(data, error).
	 */
	function capture( req, callback ) {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			callback( null, 'getUserMedia not supported in this browser.' );
			return;
		}

		const res = req.resolution || {};
		const width  = res.width  || RESOLUTIONS.medium.width;
		const height = res.height || RESOLUTIONS.medium.height;

		const constraints = {
			video: {
				width:  { ideal: width },
				height: { ideal: height },
				facingMode: 'user',
			},
			audio: false,
		};

		// Reuse existing stream if available.
		if ( activeStream && activeStream.active ) {
			grabFrame( activeStream, width, height, req, callback );
			return;
		}

		navigator.mediaDevices.getUserMedia( constraints )
			.then( function ( stream ) {
				activeStream = stream;
				grabFrame( stream, width, height, req, callback );
			} )
			.catch( function ( err ) {
				const msg = err && err.name === 'NotAllowedError'
					? 'Camera permission denied.'
					: 'Camera access failed: ' + ( err && err.message || String( err ) );
				callback( null, msg );
			} );
	}

	/**
	 * Grab a single frame from an active stream.
	 *
	 * Uses ImageCapture API when available, falling back to canvas drawImage.
	 *
	 * @param {MediaStream} stream   Active media stream.
	 * @param {number}      width    Target width.
	 * @param {number}      height   Target height.
	 * @param {Object}      req      Original sensor request.
	 * @param {Function}    callback cb(data, error).
	 */
	function grabFrame( stream, width, height, req, callback ) {
		const track = stream.getVideoTracks()[ 0 ];

		if ( ! track ) {
			callback( null, 'No video track in stream.' );
			return;
		}

		// Try ImageCapture API first.
		if ( typeof ImageCapture !== 'undefined' ) {
			try {
				const capture = new ImageCapture( track );
				capture.takePhoto()
					.then( function ( blob ) {
						blobToBase64( blob, function ( b64, err ) {
							if ( err ) {
								callback( null, err );
								return;
							}
							callback( buildResult( b64, width, height, req ), null );
						} );
					} )
					.catch( function () {
						// Fallback to canvas.
						canvasGrab( stream, width, height, req, callback );
					} );
				return;
			} catch ( e ) {
				// Fall through to canvas.
			}
		}

		canvasGrab( stream, width, height, req, callback );
	}

	/**
	 * Capture a frame via an off-screen canvas element.
	 *
	 * @param {MediaStream} stream   Active stream.
	 * @param {number}      width    Width.
	 * @param {number}      height   Height.
	 * @param {Object}      req      Sensor request.
	 * @param {Function}    callback cb(data, error).
	 */
	function canvasGrab( stream, width, height, req, callback ) {
		const video = document.createElement( 'video' );
		video.setAttribute( 'autoplay', '' );
		video.setAttribute( 'playsinline', '' );
		video.setAttribute( 'muted', '' );
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

				const b64 = canvas.toDataURL( 'image/jpeg', 0.85 );

				// Clean up.
				video.srcObject = null;
				if ( video.parentNode ) {
					video.parentNode.removeChild( video );
				}

				callback( buildResult( b64, width, height, req ), null );
			} ).catch( function ( err ) {
				if ( video.parentNode ) {
					video.parentNode.removeChild( video );
				}
				callback( null, 'Video play failed: ' + err.message );
			} );
		} );

		video.addEventListener( 'error', function () {
			if ( video.parentNode ) {
				video.parentNode.removeChild( video );
			}
			callback( null, 'Video element error.' );
		} );
	}

	/**
	 * Build the result object to post back to REST.
	 *
	 * @param {string} b64    Base64 image data.
	 * @param {number} width  Capture width.
	 * @param {number} height Capture height.
	 * @param {Object} req    Original request.
	 * @return {Object}
	 */
	function buildResult( b64, width, height, req ) {
		return {
			image_base64: b64,
			dimensions:   { width: width, height: height },
			store:        !! req.store,
		};
	}

	/**
	 * Convert a Blob to a base64 data URI.
	 *
	 * @param {Blob}     blob     Source blob.
	 * @param {Function} callback cb(base64String, error).
	 */
	function blobToBase64( blob, callback ) {
		const reader = new FileReader();
		reader.onloadend = function () {
			callback( reader.result, null );
		};
		reader.onerror = function () {
			callback( null, 'FileReader error.' );
		};
		reader.readAsDataURL( blob );
	}

	/**
	 * Release the active camera stream.
	 * Call this when the chat session ends to free the camera hardware.
	 */
	function release() {
		if ( activeStream ) {
			activeStream.getTracks().forEach( function ( t ) {
				t.stop();
			} );
			activeStream = null;
		}
	}

	// Public API.
	window.NVExtCogCamera = {
		capture: capture,
		release: release,
	};
}() );
