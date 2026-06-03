/**
 * Extended Cognition Toolkit — Camera Viewfinder Module
 *
 * Provides the user-facing camera preview, detection overlays, and explicit
 * consent flow.  This is the security gate and UX surface for all vision
 * recognition tools:
 *
 *  - ext_cog_detect_objects
 *  - ext_cog_recognize_products
 *  - ext_cog_analyze_video_feed
 *  - ext_cog_capture_visual (already exists)
 *
 * The AI agent requests a capture → the viewfinder opens with live preview →
 * user approves → frame is captured → detection results overlay on the preview.
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.8.0
 */

/* global nvOosExtCog, NVExtCogCamera */

( function () {
	'use strict';

	const cfg    = typeof nvOosExtCog !== 'undefined' ? nvOosExtCog : {};
	const i18n   = cfg.i18n || {};

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------

	/** Whether the viewfinder modal is currently open. */
	let isOpen = false;

	/** Active media stream handle. */
	let stream = null;

	/** Whether camera permission has been granted by the user. */
	let cameraAuthorized = false;

	/** Pending detection result (last AI tool response with detections). */
	let lastDetections = [];

	/** Pending brand labels from the last AI tool response. */
	let lastBrands = [];

	/** RAF handle for the overlay animation loop. */
	let overlayRaf = null;

	// -----------------------------------------------------------------------
	// DOM nodes (created on first open)
	// -----------------------------------------------------------------------

	let $overlay    = null;
	let $viewport   = null;
	let $video      = null;
	let $canvas     = null;
	let $ctx        = null;
	let $detections = null;
	let $brandList  = null;
	let $status     = null;
	let $scanBtn    = null;
	let $closeBtn   = null;

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Open the camera viewfinder with live preview.
	 *
	 * Called when:
	 *  - User clicks the camera button in the chat actions bar
	 *  - AI agent requests a capture (bridge calls this automatically)
	 *
	 * @param {Object}  [opts]            Options.
	 * @param {string}  [opts.mode]       'preview' (just show camera) or 'scan' (capture + analyze).
	 * @param {Function}[opts.onCapture]  Callback receiving { imageBase64, timestamp }.
	 * @param {Function}[opts.onCancel]   Called when user dismisses without capturing.
	 */
	function open( opts ) {
		opts = opts || {};

		if ( isOpen ) {
			// Already open — update state if needed.
			if ( opts.mode === 'scan' && $scanBtn ) {
				$scanBtn.style.display = '';
				$status.textContent = i18n.scanPrompt || 'Tap Scan to identify products';
			}
			return;
		}

		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			alert( i18n.noCamera || 'Camera not supported in this browser.' );
			return;
		}

		buildDOM();
		openStream( opts );
	}

	/**
	 * Close the viewfinder and release the camera.
	 */
	function close() {
		isOpen = false;
		releaseStream();

		if ( $overlay ) {
			$overlay.classList.remove( 'is-open' );
		}

		// Dispatch event so bridge knows user dismissed.
		document.dispatchEvent( new CustomEvent( 'wp_mcp_ai_ext_cog_viewfinder_closed' ) );
	}

	/**
	 * Show detection overlays on the current preview.
	 *
	 * Called by the sensor bridge when an AI tool returns detection results.
	 *
	 * @param {Array}  detections Array of { label, confidence, bounding_box? }.
	 * @param {string[]} brands   Unique brand names found.
	 * @param {string}  message   Human-readable summary.
	 */
	function showDetections( detections, brands, message ) {
		lastDetections = detections || [];
		lastBrands     = brands || [];

		// Update the brand list sidebar.
		if ( $brandList ) {
			$brandList.innerHTML = '';
			if ( lastBrands.length > 0 ) {
				lastBrands.forEach( function ( b ) {
					var li = document.createElement( 'li' );
					li.className = 'nvoos-ext-cog-vf__brand-item';
					li.textContent = b;
					$brandList.appendChild( li );
				} );
			} else if ( lastDetections.length > 0 ) {
				lastDetections.slice( 0, 10 ).forEach( function ( d ) {
					var li = document.createElement( 'li' );
					li.className = 'nvoos-ext-cog-vf__brand-item';
					li.textContent = d.label + ' (' + Math.round( ( d.confidence || 0 ) * 100 ) + '%)';
					$brandList.appendChild( li );
				} );
			}
		}

		if ( $status && message ) {
			$status.textContent = message;
			$status.classList.add( 'nvoos-ext-cog-vf__status--success' );
			setTimeout( function () {
				$status.classList.remove( 'nvoos-ext-cog-vf__status--success' );
			}, 3000 );
		}

		// Start overlay animation loop.
		startOverlayLoop();
	}

	/**
	 * Returns whether the user has explicitly authorized camera access.
	 *
	 * @return {boolean}
	 */
	function isAuthorized() {
		return cameraAuthorized;
	}

	// -----------------------------------------------------------------------
	// Internal: DOM construction
	// -----------------------------------------------------------------------

	function buildDOM() {
		if ( $overlay ) {
			// Already built — just show.
			$overlay.classList.add( 'is-open' );
			return;
		}

		// Overlay backdrop.
		$overlay = document.createElement( 'div' );
		$overlay.className = 'nvoos-ext-cog-vf';
		$overlay.setAttribute( 'role', 'dialog' );
		$overlay.setAttribute( 'aria-label', i18n.viewfinderTitle || 'Camera Viewfinder' );
		$overlay.setAttribute( 'aria-modal', 'true' );

		// Inner container.
		$overlay.innerHTML =
			'<div class="nvoos-ext-cog-vf__inner">' +
				'<div class="nvoos-ext-cog-vf__header">' +
					'<h2 class="nvoos-ext-cog-vf__title">' + ( i18n.cameraTitle || 'Camera' ) + '</h2>' +
					'<span class="nvoos-ext-cog-vf__status" id="nvoos-ext-cog-vf-status">' +
						( i18n.ready || 'Camera ready' ) +
					'</span>' +
				'</div>' +
				'<div class="nvoos-ext-cog-vf__body">' +
					'<div class="nvoos-ext-cog-vf__viewport">' +
						'<video class="nvoos-ext-cog-vf__video" autoplay playsinline muted></video>' +
						'<canvas class="nvoos-ext-cog-vf__overlay-canvas"></canvas>' +
						'<div class="nvoos-ext-cog-vf__detections" aria-live="polite"></div>' +
					'</div>' +
					'<div class="nvoos-ext-cog-vf__sidebar">' +
						'<h3 class="nvoos-ext-cog-vf__sidebar-title">' +
							( i18n.detectedItems || 'Detected Items' ) +
						'</h3>' +
						'<ul class="nvoos-ext-cog-vf__brand-list"></ul>' +
					'</div>' +
				'</div>' +
				'<div class="nvoos-ext-cog-vf__footer">' +
					'<button type="button" class="nvoos-ext-cog-vf__btn nvoos-ext-cog-vf__btn--secondary nvoos-ext-cog-vf__close">' +
						( i18n.close || 'Close' ) +
					'</button>' +
					'<button type="button" class="nvoos-ext-cog-vf__btn nvoos-ext-cog-vf__btn--primary nvoos-ext-cog-vf__scan">' +
						'<svg class="nvoos-ext-cog-vf__scan-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v8M8 12h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ' +
						( i18n.scan || 'Scan Now' ) +
					'</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( $overlay );

		// Cache DOM refs.
		$video      = $overlay.querySelector( '.nvoos-ext-cog-vf__video' );
		$canvas     = $overlay.querySelector( '.nvoos-ext-cog-vf__overlay-canvas' );
		$ctx        = $canvas ? $canvas.getContext( '2d' ) : null;
		$detections = $overlay.querySelector( '.nvoos-ext-cog-vf__detections' );
		$brandList  = $overlay.querySelector( '.nvoos-ext-cog-vf__brand-list' );
		$status     = $overlay.querySelector( '.nvoos-ext-cog-vf__status' );
		$scanBtn    = $overlay.querySelector( '.nvoos-ext-cog-vf__scan' );
		$closeBtn   = $overlay.querySelector( '.nvoos-ext-cog-vf__close' );

		// Event listeners.
		$closeBtn.addEventListener( 'click', close );
		$scanBtn.addEventListener( 'click', handleScanClick );
		$overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === $overlay ) {
				close();
			}
		} );

		// Escape key.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && isOpen ) {
				close();
			}
		} );
	}

	// -----------------------------------------------------------------------
	// Internal: camera lifecycle
	// -----------------------------------------------------------------------

	function openStream( opts ) {
		navigator.mediaDevices.getUserMedia( {
			video: {
				width:  { ideal: 1280 },
				height: { ideal: 720 },
				facingMode: 'environment', // Prefer rear camera on mobile.
			},
			audio: false,
		} )
			.then( function ( s ) {
				stream = s;
				cameraAuthorized = true;
				isOpen = true;

				$video.srcObject = stream;
				$overlay.classList.add( 'is-open' );

				// Dispatch authorization event.
				document.dispatchEvent( new CustomEvent(
					'wp_mcp_ai_ext_cog_camera_authorized',
					{ detail: { stream: stream } }
				) );

				$video.addEventListener( 'loadedmetadata', function () {
					$video.play();

					// Size the overlay canvas to match.
					if ( $canvas && $video.videoWidth ) {
						$canvas.width  = $video.videoWidth;
						$canvas.height = $video.videoHeight;
					}

					// Show scan button if in scan mode.
					if ( opts.mode === 'scan' && $scanBtn ) {
						$scanBtn.style.display = '';
						if ( $status ) {
							$status.textContent = i18n.scanPrompt || 'Tap Scan to identify products';
						}
					}
				} );
			} )
			.catch( function ( err ) {
				var msg = ( err && err.name === 'NotAllowedError' )
					? ( i18n.cameraDenied || 'Camera permission denied. Please allow camera access in your browser settings.' )
					: ( i18n.cameraError || 'Camera error' ) + ': ' + ( err && err.message || String( err ) );

				if ( $status ) {
					$status.textContent = msg;
					$status.classList.add( 'nvoos-ext-cog-vf__status--error' );
				}

				// Still show the overlay with error message.
				isOpen = true;
				if ( $overlay ) {
					$overlay.classList.add( 'is-open' );
				}
			} );
	}

	function releaseStream() {
		if ( stream ) {
			stream.getTracks().forEach( function ( track ) {
				track.stop();
			} );
			stream = null;
		}
		if ( $video ) {
			$video.srcObject = null;
		}
		if ( overlayRaf ) {
			cancelAnimationFrame( overlayRaf );
			overlayRaf = null;
		}
		lastDetections = [];
		lastBrands     = [];
	}

	// -----------------------------------------------------------------------
	// Internal: capture + scan
	// -----------------------------------------------------------------------

	function handleScanClick() {
		if ( ! $video || ! $video.videoWidth ) {
			return;
		}

		if ( $status ) {
			$status.textContent = i18n.capturing || 'Capturing...';
			$status.classList.add( 'nvoos-ext-cog-vf__status--capturing' );
		}

		if ( $scanBtn ) {
			$scanBtn.disabled = true;
		}

		// Capture a still frame.
		var canvas = document.createElement( 'canvas' );
		canvas.width  = $video.videoWidth;
		canvas.height = $video.videoHeight;
		var ctx = canvas.getContext( '2d' );
		ctx.drawImage( $video, 0, 0 );

		var b64 = canvas.toDataURL( 'image/jpeg', 0.85 );

		// Dispatch capture event — bridge or chat.js picks this up
		// and sends it to the AI via the REST endpoint.
		document.dispatchEvent( new CustomEvent(
			'wp_mcp_ai_ext_cog_frame_captured',
			{
				detail: {
					imageBase64: b64,
					timestamp:   Date.now(),
					width:       canvas.width,
					height:      canvas.height,
				},
			}
		) );

		if ( $status ) {
			$status.textContent = i18n.analyzing || 'Analyzing...';
		}

		// Re-enable after a short cooldown.
		setTimeout( function () {
			if ( $scanBtn ) {
				$scanBtn.disabled = false;
			}
			if ( $status ) {
				$status.classList.remove( 'nvoos-ext-cog-vf__status--capturing' );
			}
		}, 2000 );
	}

	// -----------------------------------------------------------------------
	// Internal: detection overlays
	// -----------------------------------------------------------------------

	function startOverlayLoop() {
		if ( overlayRaf ) {
			return;
		}

		function draw() {
			if ( ! $ctx || ! $canvas || ! $video || ! $video.videoWidth ) {
				overlayRaf = null;
				return;
			}

			// Clear canvas.
			$ctx.clearRect( 0, 0, $canvas.width, $canvas.height );

			if ( lastDetections.length === 0 ) {
				overlayRaf = requestAnimationFrame( draw );
				return;
			}

			var scaleX = $canvas.width  / $video.videoWidth;
			var scaleY = $canvas.height / $video.videoHeight;

			lastDetections.forEach( function ( det ) {
				var box = det.bounding_box || det.box;

				if ( ! box ) {
					return;
				}

				// Normalize coordinates to viewport pixels.
				var x = ( box.x || 0 ) * scaleX;
				var y = ( box.y || 0 ) * scaleY;
				var w = ( box.width  || 100 ) * scaleX;
				var h = ( box.height || 100 ) * scaleY;

				// Confidence color: green (high) → yellow (medium) → red (low).
				var conf = det.confidence || det.brand_confidence || 0.5;
				var r, g;
				if ( conf > 0.7 ) {
					r = 46; g = 204; // green
				} else if ( conf > 0.4 ) {
					r = 255; g = 193; // yellow
				} else {
					r = 255; g = 82;  // red
				}
				var color = 'rgba(' + r + ',' + g + ',59,0.85)';

				// Draw bounding box.
				$ctx.strokeStyle = color;
				$ctx.lineWidth   = 2.5;
				$ctx.strokeRect( x, y, w, h );

				// Draw label background.
				var label = det.brand_label || det.label || '';
				if ( label ) {
					var fontSize = Math.max( 12, Math.min( 16, h * 0.18 ) );
					$ctx.font = '600 ' + fontSize + 'px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
					var textW = $ctx.measureText( label ).width + 12;
					var textH = fontSize + 8;

					$ctx.fillStyle = color;
					$ctx.fillRect( x, y - textH - 2, textW, textH );

					$ctx.fillStyle = '#fff';
					$ctx.fillText( label, x + 6, y - 6 );
				}
			} );

			overlayRaf = requestAnimationFrame( draw );
		}

		overlayRaf = requestAnimationFrame( draw );
	}

	// -----------------------------------------------------------------------
	// Camera button injection
	// -----------------------------------------------------------------------

	/**
	 * Inject the camera toggle button into the chat actions bar.
	 * Called once after DOM ready.
	 */
	function injectCameraButton() {
		// Find all chat instances with the ext-cog toolkit enabled.
		var actionsBars = document.querySelectorAll(
			'.wp-mcp-ai-chat__actions:not(.nvoos-ext-cog-camera-injected)'
		);

		actionsBars.forEach( function ( bar ) {
			bar.classList.add( 'nvoos-ext-cog-camera-injected' );

			var btn = document.createElement( 'button' );
			btn.type       = 'button';
			btn.className  = 'wp-mcp-ai-chat__camera';
			btn.setAttribute( 'aria-label', i18n.openCamera || 'Open camera' );
			btn.setAttribute( 'title', i18n.openCameraTitle || 'Open camera viewfinder to scan products' );
			btn.innerHTML  =
				'<svg class="wp-mcp-ai-chat__camera-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
					'<path d="M12 16a4 4 0 100-8 4 4 0 000 8z" fill="currentColor"/>' +
					'<path d="M22 8.5v7a2 2 0 01-2 2H6a2 2 0 01-2-2v-7a2 2 0 012-2h2l1-2h6l1 2h2a2 2 0 012 2z" fill="none" stroke="currentColor" stroke-width="1.5"/>' +
					'<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.5"/>' +
				'</svg>' +
				'<span class="screen-reader-text">' + ( i18n.openCamera || 'Open camera' ) + '</span>';

			btn.addEventListener( 'click', function () {
				open( { mode: 'scan' } );
			} );

			// Insert before the attach button.
			var attachBtn = bar.querySelector( '.wp-mcp-ai-chat__attach' );
			if ( attachBtn ) {
				bar.insertBefore( btn, attachBtn );
			} else {
				bar.appendChild( btn );
			}
		} );
	}

	// -----------------------------------------------------------------------
	// Listen for bridge-driven capture requests
	// -----------------------------------------------------------------------

	/**
	 * When the sensor bridge needs a frame (AI requested capture),
	 * auto-open the viewfinder.  The user must still click Scan.
	 */
	document.addEventListener( 'wp_mcp_ai_ext_cog_capture_requested', function ( e ) {
		var mode = ( e.detail && e.detail.tool === 'detect' ) ? 'scan' : 'preview';
		open( { mode: mode } );
	} );

	/**
	 * Listen for detection results pushed from the bridge (after AI response).
	 */
	document.addEventListener( 'wp_mcp_ai_ext_cog_detection_results', function ( e ) {
		if ( e.detail ) {
			showDetections(
				e.detail.detections || [],
				e.detail.brands_found || e.detail.brandsFound || [],
				e.detail.message || ''
			);
		}
	} );

	// -----------------------------------------------------------------------
	// Bootstrap
	// -----------------------------------------------------------------------

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			injectCameraButton();
		} );
	} else {
		injectCameraButton();
	}

	// Expose public API globally for the sensor bridge.
	window.NVExtCogViewfinder = {
		open:             open,
		close:            close,
		showDetections:   showDetections,
		isAuthorized:     isAuthorized,
		isOpen:           function () { return isOpen; },
	};
} )();
