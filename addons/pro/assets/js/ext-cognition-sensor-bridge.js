/**
 * Extended Cognition Toolkit — Core Sensor Bridge
 *
 * Implements the active sensing loop between the NV oOS AI agents and
 * the browser's hardware APIs. The bridge:
 *
 * 1. Polls the REST sensor queue for pending capture requests from the AI
 * 2. Routes each request to the appropriate sensor module
 * 3. Posts captured data back to the REST sensor-data endpoint
 * 4. Manages GDPR consent and browser permission state
 *
 * Based on Clark & Chalmers (1998) extended mind theory:
 * the AI's cognition extends to reliable perceptual resources it can sense.
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.0.0
 */

/* global nvOosExtCog, NVExtCogCamera, NVExtCogAudio, NVExtCogScreen, NVExtCogMotion */

( function () {
	'use strict';

	/**
	 * Configuration from wp_localize_script.
	 */
	const cfg = typeof nvOosExtCog !== 'undefined' ? nvOosExtCog : {};
	const restUrl = cfg.restUrl || '';
	const nonce = cfg.nonce || '';
	const i18n = cfg.i18n || {};

	/** Active session ID — set when chat session initialises. */
	let sessionId = null;

	/** Whether GDPR consent has been given in this page load. */
	let consentGiven = false;

	/** Poll interval handle. */
	let pollInterval = null;

	/** Poll frequency in milliseconds. */
	const POLL_MS = 1500;

	/**
	 * Initialise the sensor bridge.
	 * Called automatically when the DOM is ready and chat session starts.
	 */
	function init() {
		if ( ! restUrl ) {
			return;
		}

		// Listen for session start events from the oOS chat UI.
		document.addEventListener( 'wp_mcp_ai_session_start', function ( e ) {
			if ( e.detail && e.detail.sessionId ) {
				startBridge( e.detail.sessionId );
			}
		} );

		// Also attach to any existing chat session via data attributes.
		const chatEl = document.querySelector( '[data-ext-cog-session]' );
		if ( chatEl ) {
			startBridge( chatEl.dataset.extCogSession );
		}
	}

	/**
	 * Start polling for sensor requests for the given session.
	 *
	 * @param {string} id Session ID.
	 */
	function startBridge( id ) {
		if ( ! id ) {
			return;
		}

		sessionId = id;

		if ( pollInterval ) {
			clearInterval( pollInterval );
		}

		// Immediately poll, then every POLL_MS.
		poll();
		pollInterval = setInterval( poll, POLL_MS );
	}

	/**
	 * Stop polling.
	 */
	function stopBridge() {
		if ( pollInterval ) {
			clearInterval( pollInterval );
			pollInterval = null;
		}
		sessionId = null;
	}

	/**
	 * Poll the sensor queue for pending requests.
	 */
	function poll() {
		if ( ! sessionId ) {
			return;
		}

		fetch( restUrl + 'sensor-queue/' + encodeURIComponent( sessionId ), {
			headers: {
				'X-WP-Nonce': nonce,
			},
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					return null;
				}
				return res.json();
			} )
			.then( function ( body ) {
				if ( ! body || ! body.requests || body.requests.length === 0 ) {
					return;
				}
				body.requests.forEach( function ( req ) {
					handleRequest( req );
				} );
			} )
			.catch( function () {
				// Silently ignore network errors — AI tools handle timeouts.
			} );
	}

	/**
	 * Route a sensor request to the appropriate module.
	 *
	 * @param {Object} req Sensor request object from queue.
	 */
	function handleRequest( req ) {
		if ( ! req || ! req.type ) {
			return;
		}

		// GDPR consent gate.
		if ( cfg.gdprConsent && ! consentGiven ) {
			const msg = i18n.consentRequired || 'Allow AI agent sensor access?';
			if ( ! window.confirm( msg ) ) { // eslint-disable-line no-alert
				postError( req.request_id, 'consent_denied', 'User declined sensor consent.' );
				return;
			}
			consentGiven = true;
		}

		// HTTPS enforcement.
		if ( window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' ) {
			postError( req.request_id, 'https_required', i18n.httpsRequired || 'HTTPS required.' );
			return;
		}

		switch ( req.type ) {
			case 'capture_visual':
				if ( cfg.sensorCamera && typeof NVExtCogCamera !== 'undefined' ) {
					NVExtCogCamera.capture( req, function ( data, err ) {
						if ( err ) {
							postError( req.request_id, 'capture_failed', err );
						} else {
							postData( req.request_id, 'camera', data );
						}
					} );
				} else {
					postError( req.request_id, 'sensor_disabled', 'Camera sensor not enabled.' );
				}
				break;

			case 'capture_screen':
				if ( cfg.sensorScreen && typeof NVExtCogScreen !== 'undefined' ) {
					NVExtCogScreen.capture( req, function ( data, err ) {
						if ( err ) {
							postError( req.request_id, 'capture_failed', err );
						} else {
							postData( req.request_id, 'screen', data );
						}
					} );
				} else {
					postError( req.request_id, 'sensor_disabled', 'Screen sensor not enabled.' );
				}
				break;

			case 'capture_audio':
				if ( cfg.sensorMicrophone && typeof NVExtCogAudio !== 'undefined' ) {
					NVExtCogAudio.capture( req, function ( data, err ) {
						if ( err ) {
							postError( req.request_id, 'capture_failed', err );
						} else {
							postData( req.request_id, 'audio', data );
						}
					} );
				} else {
					postError( req.request_id, 'sensor_disabled', 'Microphone sensor not enabled.' );
				}
				break;

			case 'get_motion_context':
				if ( cfg.sensorMotion && typeof NVExtCogMotion !== 'undefined' ) {
					NVExtCogMotion.capture( req, function ( data, err ) {
						if ( err ) {
							postError( req.request_id, 'capture_failed', err );
						} else {
							postData( req.request_id, 'motion', data );
						}
					} );
				} else {
					postError( req.request_id, 'sensor_disabled', 'Motion sensor not enabled.' );
				}
				break;

			case 'permission_request':
				handlePermissionRequest( req );
				break;

			default:
				postError( req.request_id, 'unknown_request_type', 'Unknown sensor request type: ' + req.type );
		}
	}

	/**
	 * Trigger browser permission prompts for requested sensors.
	 *
	 * @param {Object} req Permission request object.
	 */
	function handlePermissionRequest( req ) {
		const sensors = req.sensors || [];
		const permissions = {};

		function checkNext( idx ) {
			if ( idx >= sensors.length ) {
				postData( req.request_id, 'permission', { permissions: permissions } );
				return;
			}

			const sensor = sensors[ idx ];

			if ( ! navigator.permissions ) {
				permissions[ sensor ] = 'not-supported';
				checkNext( idx + 1 );
				return;
			}

			const permName = sensor === 'camera' ? 'camera'
				: sensor === 'microphone' ? 'microphone'
				: null;

			if ( ! permName ) {
				permissions[ sensor ] = 'not-supported';
				checkNext( idx + 1 );
				return;
			}

			navigator.permissions.query( { name: permName } )
				.then( function ( result ) {
					permissions[ sensor ] = result.state;
					checkNext( idx + 1 );
				} )
				.catch( function () {
					permissions[ sensor ] = 'unknown';
					checkNext( idx + 1 );
				} );
		}

		checkNext( 0 );
	}

	/**
	 * Post captured sensor data to the REST endpoint.
	 *
	 * @param {string} requestId  Request identifier.
	 * @param {string} sensorType Sensor type slug.
	 * @param {Object} data       Captured sensor data.
	 */
	function postData( requestId, sensorType, data ) {
		fetch( restUrl + 'sensor-data', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( {
				session_id: sessionId,
				request_id: requestId,
				sensor_type: sensorType,
				data: data,
			} ),
		} ).catch( function () {} );
	}

	/**
	 * Post an error response for a failed sensor request.
	 *
	 * @param {string} requestId Request identifier.
	 * @param {string} code      Error code.
	 * @param {string} message   Error message.
	 */
	function postError( requestId, code, message ) {
		postData( requestId, 'error', { error: code, message: String( message ) } );
	}

	// Public API.
	window.NVExtCogBridge = {
		init: init,
		start: startBridge,
		stop: stopBridge,
		setConsent: function ( given ) {
			consentGiven = !! given;
		},
	};

	// Auto-init on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
