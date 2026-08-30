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
	 * Create the Stripe PaymentIntent session and mount the Payment Element.
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

		apiPost( '/payments/session' ).then( function ( result ) {
			if ( ! result.ok ) {
				showError( ( result.data && result.data.message ) || i18n.generic_error );
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
			showError( i18n.generic_error );
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
				verifyAndInstall( intent.id );
			} else if ( 'processing' === intent.status ) {
				verifying = true;
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
	 * @return {void}
	 */
	function verifyAndInstall( paymentIntentId ) {
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
