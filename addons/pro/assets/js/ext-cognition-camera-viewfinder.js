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
 * UX features (v1.8.0):
 *  - Camera selector (front/rear/environment toggle)
 *  - Torch/flashlight toggle for low-light scanning
 *  - Scanning region indicator (corner brackets on viewport)
 *  - File upload fallback (scan from an image instead of live camera)
 *  - Live bounding-box overlays with colour-coded confidence
 *  - Brand/product sidebar with real-time detection results
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

	/** Current video track (for torch / zoom / facingMode manipulation). */
	let videoTrack = null;

	/** Whether camera permission has been granted by the user. */
	let cameraAuthorized = false;

	/** Current facing mode: 'environment' (rear) or 'user' (front). */
	let facingMode = 'environment';

	/** Whether torch is on. */
	let torchOn = false;

	/** Whether torch is supported on this device. */
	let torchSupported = false;

	/** Pending detection result (last AI tool response with detections). */
	let lastDetections = [];

	/** Pending brand labels from the last AI tool response. */
	let lastBrands = [];

	/** RAF handle for the overlay animation loop. */
	let overlayRaf = null;

	/** Whether the viewfinder was opened via file-upload fallback (no stream). */
	let isFileMode = false;

	// -----------------------------------------------------------------------
	// DOM nodes (created on first open)
	// -----------------------------------------------------------------------

	let $overlay       = null;
	let $video         = null;
	let $canvas        = null;
	let $ctx           = null;
	let $detections    = null;
	let $brandList     = null;
	let $status        = null;
	let $scanBtn       = null;
	let $closeBtn      = null;
	let $cameraSelect  = null;
	let $torchBtn      = null;
	let $fileInput     = null;
	let $scanRegion    = null;
	let $fileBtn       = null;

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Open the camera viewfinder with live preview.
	 *
	 * @param {Object}  [opts]            Options.
	 * @param {string}  [opts.mode]       'preview' (just show camera) or 'scan' (capture + analyze).
	 * @param {Function}[opts.onCapture]  Callback receiving { imageBase64, timestamp }.
	 */
	function open( opts ) {
		opts = opts || {};

		if ( isOpen ) {
			if ( opts.mode === 'scan' && $scanBtn ) {
				$scanBtn.style.display = '';
				$status.textContent = i18n.scanPrompt || 'Tap Scan to identify products';
			}
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
		isFileMode = false;
		torchOn = false;
		releaseStream();

		if ( $overlay ) {
			$overlay.classList.remove( 'is-open' );
		}

		document.dispatchEvent( new CustomEvent( 'wp_mcp_ai_ext_cog_viewfinder_closed' ) );
	}

	/**
	 * Show detection overlays on the current preview.
	 *
	 * @param {Array}    detections Array of { label, confidence, bounding_box? }.
	 * @param {string[]} brands     Unique brand names found.
	 * @param {string}   message    Human-readable summary.
	 */
	function showDetections( detections, brands, message ) {
		lastDetections = detections || [];
		lastBrands     = brands || [];

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
			$overlay.classList.add( 'is-open' );
			return;
		}

		$overlay = document.createElement( 'div' );
		$overlay.className = 'nvoos-ext-cog-vf';
		$overlay.setAttribute( 'role', 'dialog' );
		$overlay.setAttribute( 'aria-label', i18n.viewfinderTitle || 'Camera Viewfinder' );
		$overlay.setAttribute( 'aria-modal', 'true' );

		$overlay.innerHTML =
			'<div class="nvoos-ext-cog-vf__inner">' +
				'<div class="nvoos-ext-cog-vf__header">' +
					'<h2 class="nvoos-ext-cog-vf__title">' + ( i18n.cameraTitle || 'Camera' ) + '</h2>' +
					'<div class="nvoos-ext-cog-vf__header-actions">' +
						'<select class="nvoos-ext-cog-vf__camera-select" aria-label="' + ( i18n.switchCamera || 'Switch camera' ) + '">' +
							'<option value="environment">' + ( i18n.rearCamera || 'Rear' ) + '</option>' +
							'<option value="user">' + ( i18n.frontCamera || 'Front' ) + '</option>' +
						'</select>' +
						'<button type="button" class="nvoos-ext-cog-vf__torch-btn" aria-label="' + ( i18n.torch || 'Torch' ) + '" title="' + ( i18n.torchTitle || 'Toggle flashlight' ) + '" hidden>' +
							'<svg class="nvoos-ext-cog-vf__torch-icon" viewBox="0 0 24 24" aria-hidden="true">' +
								'<path d="M18 11c0-3.314-2.686-6-6-6s-6 2.686-6 6c0 2.094.86 3.99 2.25 5.355V19a1 1 0 001 1h5.5a1 1 0 001-1v-2.645A7.975 7.975 0 0018 11z" fill="none" stroke="currentColor" stroke-width="1.5"/>' +
								'<path d="M11 21h2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' +
								'<circle cx="12" cy="11" r="3" fill="currentColor" opacity="0.3"/>' +
							'</svg>' +
						'</button>' +
						'<span class="nvoos-ext-cog-vf__status" id="nvoos-ext-cog-vf-status">' +
							( i18n.ready || 'Camera ready' ) +
						'</span>' +
					'</div>' +
				'</div>' +
				'<div class="nvoos-ext-cog-vf__body">' +
					'<div class="nvoos-ext-cog-vf__viewport">' +
						'<video class="nvoos-ext-cog-vf__video" autoplay playsinline muted></video>' +
						'<canvas class="nvoos-ext-cog-vf__overlay-canvas"></canvas>' +
						'<div class="nvoos-ext-cog-vf__scan-region" aria-hidden="true">' +
							'<span class="nvoos-ext-cog-vf__corner nvoos-ext-cog-vf__corner--tl"></span>' +
							'<span class="nvoos-ext-cog-vf__corner nvoos-ext-cog-vf__corner--tr"></span>' +
							'<span class="nvoos-ext-cog-vf__corner nvoos-ext-cog-vf__corner--bl"></span>' +
							'<span class="nvoos-ext-cog-vf__corner nvoos-ext-cog-vf__corner--br"></span>' +
						'</div>' +
						'<img class="nvoos-ext-cog-vf__file-preview" alt="" hidden />' +
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
					'<input type="file" class="nvoos-ext-cog-vf__file-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden />' +
					'<button type="button" class="nvoos-ext-cog-vf__btn nvoos-ext-cog-vf__btn--secondary nvoos-ext-cog-vf__file-btn">' +
						( i18n.uploadImage || 'Upload image' ) +
					'</button>' +
					'<span class="nvoos-ext-cog-vf__footer-spacer"></span>' +
					'<button type="button" class="nvoos-ext-cog-vf__btn nvoos-ext-cog-vf__btn--secondary nvoos-ext-cog-vf__close">' +
						( i18n.close || 'Close' ) +
					'</button>' +
					'<button type="button" class="nvoos-ext-cog-vf__btn nvoos-ext-cog-vf__btn--primary nvoos-ext-cog-vf__scan">' +
						'<svg class="nvoos-ext-cog-vf__scan-icon" viewBox="0 0 24 24" aria-hidden="true">' +
							'<circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>' +
							'<path d="M12 8v8M8 12h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
						'</svg> ' +
						( i18n.scan || 'Scan Now' ) +
					'</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( $overlay );

		// Cache DOM refs.
		$video         = $overlay.querySelector( '.nvoos-ext-cog-vf__video' );
		$canvas        = $overlay.querySelector( '.nvoos-ext-cog-vf__overlay-canvas' );
		$ctx           = $canvas ? $canvas.getContext( '2d' ) : null;
		$detections    = $overlay.querySelector( '.nvoos-ext-cog-vf__detections' );
		$brandList     = $overlay.querySelector( '.nvoos-ext-cog-vf__brand-list' );
		$status        = $overlay.querySelector( '.nvoos-ext-cog-vf__status' );
		$scanBtn       = $overlay.querySelector( '.nvoos-ext-cog-vf__scan' );
		$closeBtn      = $overlay.querySelector( '.nvoos-ext-cog-vf__close' );
		$cameraSelect  = $overlay.querySelector( '.nvoos-ext-cog-vf__camera-select' );
		$torchBtn      = $overlay.querySelector( '.nvoos-ext-cog-vf__torch-btn' );
		$fileInput     = $overlay.querySelector( '.nvoos-ext-cog-vf__file-input' );
		$scanRegion    = $overlay.querySelector( '.nvoos-ext-cog-vf__scan-region' );
		$fileBtn       = $overlay.querySelector( '.nvoos-ext-cog-vf__file-btn' );

		// Event listeners.
		$closeBtn.addEventListener( 'click', close );
		$scanBtn.addEventListener( 'click', handleScanClick );
		$cameraSelect.addEventListener( 'change', handleCameraSwitch );
		$torchBtn.addEventListener( 'click', handleTorchToggle );
		$fileBtn.addEventListener( 'click', function () { $fileInput.click(); } );
		$fileInput.addEventListener( 'change', handleFileSelected );

		$overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === $overlay ) {
				close();
			}
		} );

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
				width:       { ideal: 1280 },
				height:      { ideal: 720 },
				facingMode:  facingMode,
			},
			audio: false,
		} )
			.then( function ( s ) {
				stream     = s;
				videoTrack = s.getVideoTracks()[ 0 ];

				cameraAuthorized = true;
				isOpen           = true;
				isFileMode       = false;

				$video.srcObject = stream;
				$overlay.classList.add( 'is-open' );

				// Hide file-preview, show video.
				var $preview = $overlay.querySelector( '.nvoos-ext-cog-vf__file-preview' );
				if ( $preview ) { $preview.hidden = true; }
				$video.style.display = '';

				// Show scan region, hide file-mode corner bracket animation.
				if ( $scanRegion ) { $scanRegion.classList.remove( 'nvoos-ext-cog-vf__scan-region--hidden' ); }

				// Check torch support.
				checkTorchSupport();

				document.dispatchEvent( new CustomEvent(
					'wp_mcp_ai_ext_cog_camera_authorized',
					{ detail: { stream: stream } }
				) );

				$video.addEventListener( 'loadedmetadata', function () {
					$video.play();

					if ( $canvas && $video.videoWidth ) {
						$canvas.width  = $video.videoWidth;
						$canvas.height = $video.videoHeight;
					}

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
			stream    = null;
			videoTrack = null;
		}
		if ( $video ) {
			$video.srcObject = null;
		}
		if ( overlayRaf ) {
			cancelAnimationFrame( overlayRaf );
			overlayRaf = null;
		}
		torchOn = false;
		torchSupported = false;
		lastDetections = [];
		lastBrands     = [];
	}

	// -----------------------------------------------------------------------
	// Camera controls
	// -----------------------------------------------------------------------

	/**
	 * Switch between front and rear camera.
	 */
	function handleCameraSwitch() {
		if ( ! $cameraSelect ) { return; }

		var newFacing = $cameraSelect.value;
		if ( newFacing === facingMode ) { return; }

		facingMode = newFacing;
		torchOn = false;

		// Update torch button state (only rear camera supports torch).
		if ( $torchBtn ) {
			if ( facingMode !== 'environment' ) {
				$torchBtn.hidden = true;
				$torchBtn.classList.remove( 'nvoos-ext-cog-vf__torch-btn--on' );
			} else {
				checkTorchSupport();
			}
		}

		releaseStream();

		// Re-open with new facing mode.
		openStream( { mode: 'scan' } );
	}

	/**
	 * Check if the current device/video track supports torch.
	 */
	function checkTorchSupport() {
		if ( ! videoTrack ) {
			torchSupported = false;
			if ( $torchBtn ) { $torchBtn.hidden = true; }
			return;
		}

		var capabilities = videoTrack.getCapabilities ? videoTrack.getCapabilities() : {};
		torchSupported = !!( capabilities.torch );

		if ( $torchBtn ) {
			$torchBtn.hidden = ! torchSupported;
		}
	}

	/**
	 * Toggle the flashlight on/off.
	 */
	function handleTorchToggle() {
		if ( ! videoTrack || ! torchSupported ) { return; }

		torchOn = ! torchOn;

		videoTrack.applyConstraints( {
			advanced: [ { torch: torchOn } ],
		} ).catch( function () {
			// Torch constraint failed — silently ignore.
			torchOn = false;
		} );

		if ( $torchBtn ) {
			$torchBtn.classList.toggle( 'nvoos-ext-cog-vf__torch-btn--on', torchOn );
		}
	}

	// -----------------------------------------------------------------------
	// File upload fallback
	// -----------------------------------------------------------------------

	/**
	 * Handle a file selected by the user for offline scanning.
	 */
	function handleFileSelected( event ) {
		var file = event.target && event.target.files && event.target.files[ 0 ];
		if ( ! file ) { return; }

		if ( $status ) {
			$status.textContent = i18n.loadingImage || 'Loading image...';
		}

		var reader = new FileReader();
		reader.onload = function () {
			var b64     = reader.result;                       // data:image/...;base64,...
			var raw     = b64.split( ',' )[ 1 ] || b64;        // strip data URI prefix for service

			// Show the image in the viewport instead of the camera feed.
			isFileMode = true;
			releaseStream();

			$video.style.display = 'none';
			var $preview = $overlay.querySelector( '.nvoos-ext-cog-vf__file-preview' );
			if ( $preview ) {
				$preview.src   = b64;
				$preview.hidden = false;
			}
			if ( $scanRegion ) {
				$scanRegion.classList.add( 'nvoos-ext-cog-vf__scan-region--hidden' );
			}
			if ( $torchBtn ) { $torchBtn.hidden = true; }

			if ( $status ) {
				$status.textContent = i18n.imageReady || 'Image loaded. Tap Scan to analyze.';
			}

			// Dispatch the captured frame so the bridge sends it to the AI.
			document.dispatchEvent( new CustomEvent(
				'wp_mcp_ai_ext_cog_frame_captured',
				{
					detail: {
						imageBase64: raw,
						timestamp:   Date.now(),
						isFromFile:  true,
						fileName:    file.name,
					},
				}
			) );
		};

		reader.onerror = function () {
			if ( $status ) {
				$status.textContent = i18n.fileError || 'Could not read the selected file.';
				$status.classList.add( 'nvoos-ext-cog-vf__status--error' );
			}
		};

		reader.readAsDataURL( file );
	}

	// -----------------------------------------------------------------------
	// Internal: capture + scan
	// -----------------------------------------------------------------------

	function handleScanClick() {
		// File mode: image is already loaded, just dispatch.
		if ( isFileMode ) {
			if ( $status ) {
				$status.textContent = i18n.analyzing || 'Analyzing...';
				$status.classList.add( 'nvoos-ext-cog-vf__status--capturing' );
			}

			var $preview = $overlay.querySelector( '.nvoos-ext-cog-vf__file-preview' );
			if ( $preview && $preview.src ) {
				var raw = $preview.src.split( ',' )[ 1 ] || $preview.src;
				document.dispatchEvent( new CustomEvent(
					'wp_mcp_ai_ext_cog_frame_captured',
					{
						detail: {
							imageBase64: raw,
							timestamp:   Date.now(),
							isFromFile:  true,
						},
					}
				) );
			}

			setTimeout( function () {
				if ( $status ) {
					$status.classList.remove( 'nvoos-ext-cog-vf__status--capturing' );
				}
			}, 2000 );
			return;
		}

		// Live camera mode.
		if ( ! $video || ! $video.videoWidth ) { return; }

		if ( $status ) {
			$status.textContent = i18n.capturing || 'Capturing...';
			$status.classList.add( 'nvoos-ext-cog-vf__status--capturing' );
		}
		if ( $scanBtn ) { $scanBtn.disabled = true; }

		var canvas = document.createElement( 'canvas' );
		canvas.width  = $video.videoWidth;
		canvas.height = $video.videoHeight;
		var ctx = canvas.getContext( '2d' );
		ctx.drawImage( $video, 0, 0 );

		var b64 = canvas.toDataURL( 'image/jpeg', 0.85 );
		var raw = b64.split( ',' )[ 1 ] || b64;

		document.dispatchEvent( new CustomEvent(
			'wp_mcp_ai_ext_cog_frame_captured',
			{
				detail: {
					imageBase64: raw,
					timestamp:   Date.now(),
					width:       canvas.width,
					height:      canvas.height,
				},
			}
		) );

		if ( $status ) { $status.textContent = i18n.analyzing || 'Analyzing...'; }

		setTimeout( function () {
			if ( $scanBtn ) { $scanBtn.disabled = false; }
			if ( $status ) { $status.classList.remove( 'nvoos-ext-cog-vf__status--capturing' ); }
		}, 2000 );
	}

	// -----------------------------------------------------------------------
	// Internal: detection overlays
	// -----------------------------------------------------------------------

	function startOverlayLoop() {
		if ( overlayRaf ) { return; }

		function draw() {
			if ( ! $ctx || ! $canvas ) {
				overlayRaf = null;
				return;
			}

			$ctx.clearRect( 0, 0, $canvas.width, $canvas.height );

			// If file mode, size canvas to the preview image.
			if ( isFileMode ) {
				var $preview = $overlay.querySelector( '.nvoos-ext-cog-vf__file-preview' );
				if ( $preview && $preview.naturalWidth ) {
					$canvas.width  = $preview.naturalWidth;
					$canvas.height = $preview.naturalHeight;
				}
			} else if ( $video && $video.videoWidth ) {
				$canvas.width  = $video.videoWidth;
				$canvas.height = $video.videoHeight;
			}

			if ( lastDetections.length === 0 ) {
				overlayRaf = requestAnimationFrame( draw );
				return;
			}

			var scaleX = $canvas.width  / ( isFileMode ? ( $canvas.width  || 640 ) : ( $video && $video.videoWidth  || 640 ) );
			var scaleY = $canvas.height / ( isFileMode ? ( $canvas.height || 480 ) : ( $video && $video.videoHeight || 480 ) );

			lastDetections.forEach( function ( det ) {
				var box = det.bounding_box || det.box;
				if ( ! box ) { return; }

				var x = ( box.x || 0 ) * scaleX;
				var y = ( box.y || 0 ) * scaleY;
				var w = ( box.width  || 100 ) * scaleX;
				var h = ( box.height || 100 ) * scaleY;

				var conf = det.confidence || det.brand_confidence || 0.5;
				var r, g;
				if ( conf > 0.7 )      { r = 46;  g = 204; }
				else if ( conf > 0.4 ) { r = 255; g = 193; }
				else                   { r = 255; g = 82;  }
				var color = 'rgba(' + r + ',' + g + ',59,0.85)';

				$ctx.strokeStyle = color;
				$ctx.lineWidth   = 2.5;
				$ctx.strokeRect( x, y, w, h );

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

	function injectCameraButton() {
		var actionsBars = document.querySelectorAll(
			'.wp-mcp-ai-chat__actions:not(.nvoos-ext-cog-camera-injected)'
		);

		actionsBars.forEach( function ( bar ) {
			bar.classList.add( 'nvoos-ext-cog-camera-injected' );

			var btn = document.createElement( 'button' );
			btn.type      = 'button';
			btn.className = 'wp-mcp-ai-chat__camera';
			btn.setAttribute( 'aria-label', i18n.openCamera || 'Open camera' );
			btn.setAttribute( 'title', i18n.openCameraTitle || 'Open camera viewfinder to scan products' );
			btn.innerHTML =
				'<svg class="wp-mcp-ai-chat__camera-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
					'<path d="M12 16a4 4 0 100-8 4 4 0 000 8z" fill="currentColor"/>' +
					'<path d="M22 8.5v7a2 2 0 01-2 2H6a2 2 0 01-2-2v-7a2 2 0 012-2h2l1-2h6l1 2h2a2 2 0 012 2z" fill="none" stroke="currentColor" stroke-width="1.5"/>' +
					'<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.5"/>' +
				'</svg>' +
				'<span class="screen-reader-text">' + ( i18n.openCamera || 'Open camera' ) + '</span>';

			btn.addEventListener( 'click', function () {
				open( { mode: 'scan' } );
			} );

			var attachBtn = bar.querySelector( '.wp-mcp-ai-chat__attach' );
			if ( attachBtn ) {
				bar.insertBefore( btn, attachBtn );
			} else {
				bar.appendChild( btn );
			}
		} );
	}

	// -----------------------------------------------------------------------
	// Event listeners
	// -----------------------------------------------------------------------

	document.addEventListener( 'wp_mcp_ai_ext_cog_capture_requested', function ( e ) {
		var mode = ( e.detail && e.detail.tool === 'detect' ) ? 'scan' : 'preview';
		open( { mode: mode } );
	} );

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

	window.NVExtCogViewfinder = {
		open:           open,
		close:          close,
		showDetections: showDetections,
		isAuthorized:   isAuthorized,
		isOpen:         function () { return isOpen; },
	};
} )();
