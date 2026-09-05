/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — Addon Purchase Modal
 *
 * Opens a modal with Stripe's Payment Element, processes the purchase of
 * the nvoos-content-graph-ai addon, then verifies the payment server-side
 * and installs the addon — a single flow.
 *
 * Card details are entered inside Stripe's own iframe; this file never
 * sees card data. Amounts are decided server-side.
 *
 * @package NvoosContentGraph
 * @since   1.0.4
 */
( function () {
	'use strict';

	var config = window.nvoosContentGraphCommerce || {};
	var i18n = config.i18n || {};

	var stripe = null;
	var elements = null;
	var paymentElement = null;
	var overlay = null;
	var dialog = null;
	var errorBox = null;
	var busy = false;
	var verifying = false;

	/**
	 * Build an element with text content, safe from XSS by construction.
	 *
	 * @param  {string} tag   Tag name.
	 * @param  {string} klass Optional CSS class.
	 * @param  {string} text  Optional text content.
	 * @return {HTMLElement}
	 */
	function el( tag, klass, text ) {
		var node = document.createElement( tag );
		if ( klass ) {
			node.className = klass;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * POST to the plugin REST API with the wp_rest nonce.
	 *
	 * @param  {string} route REST route (relative to the namespace root).
	 * @param  {Object} body  JSON body.
	 * @return {Promise} Resolves with { ok, status, data }.
	 */
	function apiPost( route, body ) {
		return fetch( config.rest_url + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				return { ok: response.ok, status: response.status, data: json };
			} );
		} );
	}

	/**
	 * Show an error inside the modal.
	 *
	 * @param {string} message Error message.
	 * @return {void}
	 */
	function showError( message ) {
		errorBox.textContent = message || i18n.generic_error || 'Something went wrong.';
		errorBox.style.display = 'block';
		setBusy( false );
	}

	/**
	 * Checkout endpoint unavailable — fall back to the product page.
	 *
	 * When the `/payments/session` endpoint cannot be reached (network
	 * failure, 404, or server error), replace the modal with a short
	 * notice and redirect to the vendor product page so the purchase can
	 * still complete. The URL is filterable server-side
	 * (`nvoos_content_graph/payments/fallback_url`); an empty value keeps
	 * the plain in-modal error instead.
	 *
	 * @return {void}
	 */
	function checkoutUnavailable() {
		var fallback = String( config.fallback_url || '' ).trim();

		if ( ! fallback || ! /^https?:\/\//i.test( fallback ) ) {
			showError( i18n.generic_error );
			return;
		}

		if ( dialog ) {
			var modalBody = dialog.querySelector( '.nvoos-cg-modal-body' );
			var footer = dialog.querySelector( '.nvoos-cg-modal-footer' );
			if ( modalBody ) {
				modalBody.innerHTML = '';
				modalBody.appendChild( el( 'p', 'nvoos-cg-pending-message', i18n.fallback_note || 'The checkout service is unavailable right now. Redirecting you to the product page to complete your purchase…' ) );
			}
			if ( footer ) {
				footer.innerHTML = '';
			}
		}

		window.setTimeout( function () {
			window.location.href = fallback;
		}, 1200 );
	}

	/**
	 * Hide the modal error box.
	 *
	 * @return {void}
	 */
	function hideError() {
		errorBox.style.display = 'none';
	}

	/**
	 * Toggle the busy state of the pay button.
	 *
	 * @param {boolean} value Whether the modal is busy.
	 * @return {void}
	 */
	function setBusy( value ) {
		busy = value;
		var payBtn = dialog.querySelector( '.nvoos-cg-pay-btn' );
		if ( payBtn ) {
			payBtn.disabled = value;
		}
	}

	/**
	 * Close and remove the modal, tearing down the Stripe elements.
	 *
	 * @return {void}
	 */
	function closeModal() {
		if ( paymentElement ) {
			try {
				paymentElement.unmount();
			} catch ( e ) {
				// Ignore — the modal is going away regardless.
			}
		}
		paymentElement = null;
		elements = null;
		stripe = null;
		busy = false;
		verifying = false;
		if ( overlay && overlay.parentNode ) {
			overlay.parentNode.removeChild( overlay );
		}
		overlay = null;
		dialog = null;
		errorBox = null;
	}

	/**
	 * Render the modal skeleton and start the checkout flow.
	 *
	 * @return {void}
	 */
	function openModal() {
		closeModal();

		overlay = el( 'div', 'nvoos-cg-modal-overlay' );
		dialog = el( 'div', 'nvoos-cg-modal' );

		var header = el( 'div', 'nvoos-cg-modal-header' );
		var title = el( 'h2', 'nvoos-cg-modal-title', i18n.title || 'Get NV oOS Content Graph — AI' );
		var closeBtn = el( 'button', 'nvoos-cg-modal-close', '×' );
		closeBtn.type = 'button';
		closeBtn.setAttribute( 'aria-label', i18n.close || 'Close' );
		closeBtn.addEventListener( 'click', closeModal );
		header.appendChild( title );
		header.appendChild( closeBtn );

		var modalBody = el( 'div', 'nvoos-cg-modal-body' );
		modalBody.appendChild( el( 'p', 'nvoos-cg-price', config.price_label || '' ) );
		modalBody.appendChild( el( 'p', 'nvoos-cg-secure-note', i18n.secure_note || '' ) );

		errorBox = el( 'div', 'nvoos-cg-error' );
		errorBox.style.display = 'none';
		modalBody.appendChild( errorBox );

		var payBox = el( 'div', 'nvoos-cg-pay-element' );
		modalBody.appendChild( payBox );

		var footer = el( 'div', 'nvoos-cg-modal-footer' );
		var cancelBtn = el( 'button', 'button nvoos-cg-cancel-btn', i18n.cancel || 'Cancel' );
		cancelBtn.type = 'button';
		cancelBtn.addEventListener( 'click', closeModal );
		var payBtn = el( 'button', 'button button-primary nvoos-cg-pay-btn', i18n.pay || 'Pay' );
		payBtn.type = 'button';
		payBtn.disabled = true;
		payBtn.addEventListener( 'click', handlePayClick );
		footer.appendChild( cancelBtn );
		footer.appendChild( payBtn );

		dialog.appendChild( header );
		dialog.appendChild( modalBody );
		dialog.appendChild( footer );
		overlay.appendChild( dialog );
		document.body.appendChild( overlay );

		startCheckout( payBox, payBtn );
	}

	/**
	 * Read a remembered, paid-but-unverified PaymentIntent ID.
	 *
	 * @return {string} Intent ID or empty string.
	 */
	function readPendingIntent() {
		try {
			return sessionStorage.getItem( 'nvoosCgPendingIntent' ) || '';
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Remember an intent whose verification did not complete, so a later
	 * modal visit can resume instead of charging the buyer twice.
	 *
	 * @param {string} intentId PaymentIntent ID.
	 * @return {void}
	 */
	function rememberPendingIntent( intentId ) {
		try {
			sessionStorage.setItem( 'nvoosCgPendingIntent', intentId );
		} catch ( e ) {
			// Storage unavailable — recovery simply won't survive a reload.
		}
	}

	/**
	 * Forget the remembered pending intent.
	 *
	 * @return {void}
	 */
	function forgetPendingIntent() {
		try {
			sessionStorage.removeItem( 'nvoosCgPendingIntent' );
		} catch ( e ) {
			// Ignore.
		}
	}

	/**
	 * Create the Stripe PaymentIntent session and mount the Payment Element.
	 *
	 * When a previous payment never finished verifying, resume it first —
	 * the vendor may have issued the license via webhook in the meantime.
	 *
	 * @param {HTMLElement} payBox Container for the payment element.
	 * @param {HTMLButtonElement} payBtn The pay/verify button.
	 * @return {void}
	 */
	function startCheckout( payBox, payBtn ) {
		if ( ! window.Stripe ) {
			showError( 'Stripe failed to load. Check your network connection and try again.' );
			return;
		}

		var pendingId = readPendingIntent();
		if ( pendingId ) {
			verifyAndInstall( pendingId, { fromPending: true } );
			return;
		}

		apiPost( '/payments/session' ).then( function ( result ) {
			if ( ! result.ok ) {
				// The endpoint is unreachable (route missing or server error):
				// redirect to the vendor product page. Other client errors
				// (e.g. session throttling) keep the in-modal error.
				if ( result.status === 404 || result.status >= 500 ) {
					checkoutUnavailable();
				} else {
					showError( ( result.data && result.data.message ) || i18n.generic_error );
				}
				return;
			}

			stripe = window.Stripe( result.data.publishable_key );
			elements = stripe.elements( {
				clientSecret: result.data.client_secret,
				appearance: { theme: 'stripe' }
			} );
			paymentElement = elements.create( 'paymentElement' );
			paymentElement.mount( payBox );

			if ( result.data.test_mode ) {
				var modalBody = dialog.querySelector( '.nvoos-cg-modal-body' );
				modalBody.appendChild( el( 'p', 'nvoos-cg-test-mode', i18n.test_mode || '' ) );
			}

			payBtn.dataset.clientSecret = result.data.client_secret;
			payBtn.disabled = false;
		} ).catch( function () {
			// Network-level failure (fetch rejection): the endpoint is
			// unavailable — fall back to the product page.
			checkoutUnavailable();
		} );
	}

	/**
	 * Pay-button handler: confirm payment, then verify + install.
	 *
	 * @return {void}
	 */
	function handlePayClick() {
		if ( busy ) {
			return;
		}
		setBusy( true );
		hideError();

		var payBtn = dialog.querySelector( '.nvoos-cg-pay-btn' );
		var clientSecret = payBtn.dataset.clientSecret || '';

		// Retry path: the payment is processing (delayed notification
		// methods). Retrieve the intent and check instead of re-confirming.
		if ( verifying ) {
			stripe.retrievePaymentIntent( clientSecret ).then( function ( result ) {
				var intent = result && result.paymentIntent;
				if ( ! intent ) {
					showError( i18n.generic_error );
					return;
				}
			if ( 'succeeded' === intent.status ) {
					rememberPendingIntent( intent.id );
					verifyAndInstall( intent.id );
				} else {
					showError( 'Payment is still processing. Click Verify once it completes.' );
					setBusy( false );
				}
			} ).catch( function () {
				showError( i18n.generic_error );
			} );
			return;
		}

		stripe.confirmPayment( {
			elements: elements,
			confirmParams: { return_url: window.location.href },
			redirect: 'if_required'
		} ).then( function ( result ) {
			if ( result.error ) {
				showError( result.error.message || i18n.generic_error );
				return;
			}

			var intent = result.paymentIntent;
			if ( ! intent ) {
				showError( i18n.generic_error );
				return;
			}

			if ( 'succeeded' === intent.status ) {
				rememberPendingIntent( intent.id );
				verifyAndInstall( intent.id );
			} else if ( 'processing' === intent.status ) {
				verifying = true;
				rememberPendingIntent( intent.id );
				payBtn.textContent = 'Verify';
				showError( 'Payment is still processing. Click Verify once it completes.' );
				setBusy( false );
			} else {
				showError( 'Payment did not complete. Status: ' + intent.status );
			}
		} ).catch( function () {
			showError( i18n.generic_error );
		} );
	}

	/**
	 * Verify the payment server-side, then install and activate the addon.
	 *
	 * @param {string} paymentIntentId Stripe PaymentIntent ID.
	 * @param {Object} opts            Options: { fromPending: bool } marks a
	 *                                 recovery attempt for a remembered intent.
	 * @return {void}
	 */
	function verifyAndInstall( paymentIntentId, opts ) {
		opts = opts || {};

		var payBox = dialog.querySelector( '.nvoos-cg-pay-element' );
		var spinner = el( 'div', 'nvoos-cg-installing', i18n.installing || 'Installing…' );
		payBox.innerHTML = '';
		payBox.appendChild( spinner );

		apiPost( '/payments/verify', { payment_intent: paymentIntentId } ).then( function ( result ) {
			var data = result.data || {};

			if ( ! result.ok ) {
				payBox.innerHTML = '';
				var message = ( data.message || i18n.generic_error ) + '';
				showError( message );

				// Recovery attempt for a remembered purchase: offer retry
				// (the vendor webhook may not have fired yet) or a fresh
				// checkout. Never silently start a new chargeable intent.
				if ( opts.fromPending ) {
					errorBox.style.display = 'none';
					payBox.appendChild( el( 'p', 'nvoos-cg-pending-message', message ) );
					var retryBtn = el( 'button', 'button button-primary', i18n.pending_retry || 'Check again' );
					retryBtn.type = 'button';
					retryBtn.addEventListener( 'click', function () {
						verifyAndInstall( paymentIntentId, { fromPending: true } );
					} );
					var newBtn = el( 'button', 'button', i18n.pending_new || 'Start a new purchase' );
					newBtn.type = 'button';
					newBtn.addEventListener( 'click', function () {
						forgetPendingIntent();
						closeModal();
						openModal();
					} );
					payBox.appendChild( retryBtn );
					payBox.appendChild( document.createTextNode( ' ' ) );
					payBox.appendChild( newBtn );
					return;
				}

				if ( data.zip_url ) {
					errorBox.style.display = 'none';
					var dl = el( 'a', 'button', 'Download ZIP manually' );
					dl.href = data.zip_url;
					dl.target = '_blank';
					dl.rel = 'noopener';
					payBox.appendChild( el( 'p', '', message ) );
					payBox.appendChild( dl );
				}
				return;
			}

			forgetPendingIntent();
			renderSuccess( data );
		} ).catch( function () {
			showError( i18n.generic_error );
		} );
	}

	/**
	 * Render the success state (license + reload).
	 *
	 * @param {Object} data Verify response.
	 * @return {void}
	 */
	function renderSuccess( data ) {
		var payBox = dialog.querySelector( '.nvoos-cg-pay-element' );
		payBox.innerHTML = '';

		payBox.appendChild( el( 'h3', 'nvoos-cg-success-title', i18n.success_title || 'You are all set!' ) );
		if ( data.message ) {
			payBox.appendChild( el( 'p', 'nvoos-cg-success-message', data.message ) );
		}
		if ( data.license_key ) {
			var keyRow = el( 'p', 'nvoos-cg-license' );
			keyRow.appendChild( document.createTextNode( ( i18n.license_label || 'License key' ) + ': ' ) );
			keyRow.appendChild( el( 'code', '', data.license_key ) );
			payBox.appendChild( keyRow );
		}

		var footer = dialog.querySelector( '.nvoos-cg-modal-footer' );
		footer.innerHTML = '';
		var reloadBtn = el( 'button', 'button button-primary', i18n.refresh || 'Reload page' );
		reloadBtn.type = 'button';
		reloadBtn.addEventListener( 'click', function () {
			window.location.reload();
		} );
		footer.appendChild( reloadBtn );

		// Auto-refresh so the upsell cards disappear and AI features appear.
		window.setTimeout( function () {
			window.location.reload();
		}, 2500 );
	}

	/**
	 * Wire up the upsell buttons.
	 *
	 * @return {void}
	 */
	function bindButtons() {
		var buttons = document.querySelectorAll( '.nvoos-content-graph-buy-ai' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].addEventListener( 'click', openModal );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindButtons );
	} else {
		bindButtons();
	}
} )();
