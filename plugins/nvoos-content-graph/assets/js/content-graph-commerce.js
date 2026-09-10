/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — NV oOS Complete Purchase Modal
 *
 * Opens a modal with Stripe's Payment Element, processes the purchase of
 * the NV oOS Complete plugin bundle, then verifies the payment
 * server-side and installs the bundle — a single flow.
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
	var consentCheckbox = null;
	var consentAt = 0;
	var euWithdrawalNote = null;
	var emailInput = null;
	var countrySelect = null;
	var addressLine1 = null;
	var addressCity = null;
	var addressPostal = null;
	var addressRow = null;
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
	 * Build an anchor that opens in a new tab, safe by construction.
	 *
	 * @param  {string} klass CSS class.
	 * @param  {string} text  Link text.
	 * @param  {string} href  URL (comes pre-sanitized from the REST response).
	 * @return {HTMLElement}
	 */
	function linkEl( klass, text, href ) {
		var a = el( 'a', klass, text );
		a.href = href;
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		return a;
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
	 * GET from the plugin REST API with the wp_rest nonce.
	 *
	 * @param  {string} route REST route (relative to the namespace root).
	 * @return {Promise} Resolves with { ok, status, data }.
	 */
	function apiGet( route ) {
		return fetch( config.rest_url + route, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce
			}
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
	 * Append a "Test connection" action to the error box.
	 *
	 * Calls the server-side `GET /payments/health` probe, which checks
	 * reachability of the vendor checkout API WITHOUT consuming a
	 * session-throttle token — so the admin can tell a real connectivity
	 * failure apart from the "Too many checkout attempts" lockout.
	 *
	 * @return {void}
	 */
	function renderDiagnoseLink() {
		if ( ! errorBox ) {
			return;
		}

		var btn = el( 'button', 'button-link nvoos-cg-diagnose-btn', i18n.diagnose || 'Test connection to the checkout service' );
		btn.type = 'button';
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			var original = btn.textContent;
			btn.textContent = i18n.diagnosing || 'Testing connection…';

			apiGet( '/payments/health' ).then( function ( result ) {
				btn.disabled = false;
				btn.textContent = original;

				var line = el( 'p', 'nvoos-cg-diagnose-result' );
				var data = result.data || {};
				if ( result.ok && data.reachable ) {
					var vendor = data.vendor || {};
					var latency = 'number' === typeof data.latency_ms ? data.latency_ms : 0;
					line.textContent = ( i18n.diagnose_ok || 'Checkout service is reachable' ) +
						' — ' + ( vendor.service || 'nvoos-checkout' ) + ' v' + ( vendor.version || '?' ) +
						' (' + latency + ' ms)';
				} else {
					line.textContent = data.message || i18n.generic_error || 'Connection test failed.';
				}
				errorBox.appendChild( line );
			} ).catch( function () {
				btn.disabled = false;
				btn.textContent = original;
				errorBox.appendChild( el( 'p', 'nvoos-cg-diagnose-result', i18n.generic_error || 'Connection test failed.' ) );
			} );
		} );
		errorBox.appendChild( btn );
	}

	/**
	 * Toggle the busy state of the pay button.
	 *
	 * @param {boolean} value Whether the modal is busy.
	 * @return {void}
	 */
	function setBusy( value ) {
		busy = value;
		updatePayState();
	}

	/**
	 * Enable the pay button only when a payment session exists, the buyer
	 * has entered a valid email, AND the Terms of Service consent checkbox
	 * is ticked.
	 *
	 * @return {void}
	 */
	function updatePayState() {
		if ( ! dialog ) {
			return;
		}
		var payBtn = dialog.querySelector( '.nvoos-cg-pay-btn' );
		if ( ! payBtn ) {
			return;
		}
		var hasSecret = Boolean( payBtn.dataset.clientSecret );
		var email = emailInput ? emailInput.value.trim() : '';
		payBtn.disabled = busy || ! hasSecret || ! consentCheckbox || ! consentCheckbox.checked || ! isValidEmail( email ) || ! isAddressValid();
	}

	/**
	 * Loose client-side email check (server re-validates via is_email()).
	 *
	 * @param  {string} value Candidate address.
	 * @return {boolean}
	 */
	function isValidEmail( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
	}

	/**
	 * The EU country codes this install treats as requiring an address.
	 *
	 * @return {Array} ISO 3166-1 alpha-2 codes (server-provided).
	 */
	function euCountries() {
		var list = config.eu_countries;
		return Array.isArray( list ) ? list : [];
	}

	/**
	 * Whether the buyer selected an EU country in the billing row.
	 *
	 * @return {boolean}
	 */
	function isEuSelected() {
		if ( ! countrySelect ) {
			return false;
		}
		return euCountries().indexOf( countrySelect.value ) !== -1;
	}

	/**
	 * EU buyers must provide street + city (postal code optional);
	 * non-EU buyers need no address at all.
	 *
	 * @return {boolean}
	 */
	function isAddressValid() {
		if ( ! isEuSelected() ) {
			return true;
		}
		if ( ! addressLine1 || ! addressCity ) {
			return false;
		}
		return addressLine1.value.trim() !== '' && addressCity.value.trim() !== '';
	}

	/**
	 * Build a labelled text input, re-validating the pay button on entry.
	 *
	 * @param  {HTMLElement} container Parent element.
	 * @param  {string}      id        Input id.
	 * @param  {string}      labelText Label text.
	 * @return {HTMLElement} The input element.
	 */
	function buildField( container, id, labelText ) {
		var label = el( 'label', 'nvoos-cg-field-label', labelText );
		label.htmlFor = id;

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.id = id;
		input.className = 'nvoos-cg-field-input';
		input.addEventListener( 'input', updatePayState );

		container.appendChild( label );
		container.appendChild( input );
		return input;
	}

	/**
	 * Build the billing row: a country selector (EU vs other) plus an
	 * address block that is visible and required only for EU countries —
	 * VAT records for digitally supplied services.
	 *
	 * @return {HTMLElement}
	 */
	function renderBillingRow() {
		var row = el( 'div', 'nvoos-cg-billing-row' );

		var countryLabel = el( 'label', 'nvoos-cg-billing-label', i18n.country_label || 'Country (for VAT records)' );
		countryLabel.htmlFor = 'nvoos-cg-country';
		row.appendChild( countryLabel );

		countrySelect = document.createElement( 'select' );
		countrySelect.id = 'nvoos-cg-country';
		countrySelect.className = 'nvoos-cg-country-select';

		var otherOption = document.createElement( 'option' );
		otherOption.value = '';
		otherOption.textContent = i18n.country_other || 'Other / Non-EU';
		countrySelect.appendChild( otherOption );

		var codes = euCountries();
		for ( var i = 0; i < codes.length; i++ ) {
			var option = document.createElement( 'option' );
			option.value = codes[ i ];
			option.textContent = codes[ i ];
			countrySelect.appendChild( option );
		}

		countrySelect.addEventListener( 'change', function () {
			if ( addressRow ) {
				// Restore the stylesheet's grid layout when shown ('block'
				// would override the CSS grid definition).
				addressRow.style.display = isEuSelected() ? '' : 'none';
			}
			if ( euWithdrawalNote ) {
				euWithdrawalNote.style.display = isEuSelected() ? '' : 'none';
			}
			updatePayState();
		} );
		row.appendChild( countrySelect );

		addressRow = el( 'div', 'nvoos-cg-address-row' );
		addressRow.style.display = 'none';
		addressLine1 = buildField( addressRow, 'nvoos-cg-address-line1', i18n.address_line1_label || 'Street address' );
		addressCity = buildField( addressRow, 'nvoos-cg-address-city', i18n.address_city_label || 'City' );
		addressPostal = buildField( addressRow, 'nvoos-cg-address-postal', i18n.address_postal_label || 'Postal code (optional)' );
		row.appendChild( addressRow );

		return row;
	}

	/**
	 * Build the Stripe billing_details object (email + EU address).
	 *
	 * @param  {string} email Buyer email.
	 * @return {Object} Stripe billing_details shape.
	 */
	function buildBillingDetails( email ) {
		var details = { email: email };

		if ( isEuSelected() ) {
			details.address = {
				line1: addressLine1.value.trim(),
				city: addressCity.value.trim(),
				country: countrySelect.value
			};
			if ( addressPostal && addressPostal.value.trim() !== '' ) {
				details.address.postal_code = addressPostal.value.trim();
			}
		}

		return details;
	}

	/**
	 * Build the buyer-email row, prefilled with the current user's address.
	 *
	 * The email is attached to the Stripe PaymentIntent via
	 * confirmParams.receipt_email (so Stripe emails the receipt) and sent to
	 * the vendor on /payments/verify, which stores it on the license row so
	 * refund requests can be matched to the right transaction.
	 *
	 * @return {HTMLElement}
	 */
	function renderEmailRow() {
		var row = el( 'div', 'nvoos-cg-email-row' );

		var label = el( 'label', 'nvoos-cg-email-label', i18n.email_label || 'Email for receipt and refunds' );
		label.htmlFor = 'nvoos-cg-buyer-email';
		row.appendChild( label );

		emailInput = document.createElement( 'input' );
		emailInput.type = 'email';
		emailInput.id = 'nvoos-cg-buyer-email';
		emailInput.className = 'nvoos-cg-email-input';
		emailInput.placeholder = i18n.email_placeholder || 'you@example.com';
		emailInput.value = String( config.buyer_email || '' );
		emailInput.addEventListener( 'input', updatePayState );
		row.appendChild( emailInput );

		return row;
	}

	/**
	 * Build the Terms of Service consent row (checkbox + policy links).
	 *
	 * Ticking the checkbox records the consent timestamp and is the only
	 * way to unlock the pay button. The timestamp is sent with the verify
	 * request and stored on the license as proof of acceptance.
	 *
	 * @param  {Object} data Session response (may carry vendor URLs).
	 * @return {HTMLElement}
	 */
	function renderConsent( data ) {
		data = data || {};

		var termsUrl = data.terms_url || config.terms_url || '';
		var refundUrl = data.refund_policy_url || config.refund_policy_url || '';

		consentCheckbox = document.createElement( 'input' );
		consentCheckbox.type = 'checkbox';
		consentCheckbox.id = 'nvoos-cg-terms-consent';
		consentCheckbox.className = 'nvoos-cg-terms-checkbox';
		consentCheckbox.addEventListener( 'change', function () {
			consentAt = consentCheckbox.checked ? Math.floor( Date.now() / 1000 ) : 0;
			updatePayState();
		} );

		var label = el( 'label', 'nvoos-cg-terms-label' );
		label.htmlFor = 'nvoos-cg-terms-consent';
		label.appendChild( consentCheckbox );
		label.appendChild( document.createTextNode( ' ' + ( i18n.terms_consent || 'I have read and agree to the Terms of Service and the Refund Policy.' ) + ' ' ) );

		if ( termsUrl ) {
			label.appendChild( linkEl( 'nvoos-cg-terms-link', i18n.terms_link || 'Terms of Service', termsUrl ) );
		}
		if ( refundUrl ) {
			if ( termsUrl ) {
				label.appendChild( document.createTextNode( ' · ' ) );
			}
			label.appendChild( linkEl( 'nvoos-cg-terms-link', i18n.refund_link || 'Refund Policy', refundUrl ) );
		}

		var row = el( 'div', 'nvoos-cg-terms-row' );
		row.appendChild( label );

		// EU buyers must acknowledge that immediate delivery ends their
		// statutory right of withdrawal for digital content — shown only
		// when an EU country is selected in the billing row.
		euWithdrawalNote = el( 'p', 'nvoos-cg-terms-sub', i18n.terms_eu_withdrawal || '' );
		euWithdrawalNote.style.display = isEuSelected() ? '' : 'none';
		row.appendChild( euWithdrawalNote );

		return row;
	}

	/**
	 * Build the price block: amount, one-time label, license scope, VAT note.
	 *
	 * @return {HTMLElement}
	 */
	function renderPriceBlock() {
		var block = el( 'div', 'nvoos-cg-price-block' );
		block.appendChild( el( 'p', 'nvoos-cg-price', config.price_label || '' ) );

		var oneTime = i18n.price_one_time || '';
		if ( oneTime ) {
			block.appendChild( el( 'p', 'nvoos-cg-price-sub', oneTime ) );
		}

		var scope = i18n.price_license_scope || '';
		if ( scope ) {
			block.appendChild( el( 'p', 'nvoos-cg-price-scope', scope ) );
		}

		var vatNote = i18n.price_vat_note || '';
		if ( vatNote ) {
			block.appendChild( el( 'p', 'nvoos-cg-price-vat', vatNote ) );
		}

		return block;
	}

	/**
	 * Build the trust list: guarantee, instant delivery, Stripe security.
	 *
	 * @return {HTMLElement}
	 */
	function renderTrustList() {
		var list = el( 'ul', 'nvoos-cg-trust' );
		var items = [
			i18n.trust_guarantee || '',
			i18n.trust_instant || '',
			i18n.secure_note || ''
		];
		for ( var i = 0; i < items.length; i++ ) {
			if ( ! items[ i ] ) {
				continue;
			}
			list.appendChild( el( 'li', 'nvoos-cg-trust-item', items[ i ] ) );
		}
		return list;
	}

	/**
	 * Build the "what's included" block plus the roadmap/feedback line.
	 *
	 * The roadmap line renders only when a roadmap URL is configured —
	 * promising feedback only when the owner actually reads it.
	 *
	 * @return {HTMLElement}
	 */
	function renderIncludesBlock() {
		var block = el( 'div', 'nvoos-cg-includes' );

		var title = i18n.includes_title || '';
		if ( title ) {
			block.appendChild( el( 'h3', 'nvoos-cg-includes-title', title ) );
		}

		var list = el( 'ul', 'nvoos-cg-includes-list' );
		var items = [
			i18n.includes_full || '',
			i18n.includes_updates || '',
			i18n.includes_support || '',
			i18n.includes_roadmap || ''
		];
		for ( var i = 0; i < items.length; i++ ) {
			if ( ! items[ i ] ) {
				continue;
			}
			list.appendChild( el( 'li', 'nvoos-cg-includes-item', items[ i ] ) );
		}
		block.appendChild( list );

		var roadmapUrl = String( config.roadmap_url || '' ).trim();
		if ( roadmapUrl && /^https?:\/\//i.test( roadmapUrl ) ) {
			var line = el( 'p', 'nvoos-cg-roadmap' );
			var funded = i18n.roadmap_funded || '';
			if ( funded ) {
				line.appendChild( document.createTextNode( funded + ' ' ) );
			}
			line.appendChild( linkEl( 'nvoos-cg-roadmap-link', i18n.roadmap_link_label || 'Share your ideas', roadmapUrl ) );
			block.appendChild( line );
		}

		return block;
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
		consentCheckbox = null;
		consentAt = 0;
		euWithdrawalNote = null;
		emailInput = null;
		countrySelect = null;
		addressLine1 = null;
		addressCity = null;
		addressPostal = null;
		addressRow = null;
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
		var title = el( 'h2', 'nvoos-cg-modal-title', i18n.title || 'Get NV oOS Complete' );
		var closeBtn = el( 'button', 'nvoos-cg-modal-close', '×' );
		closeBtn.type = 'button';
		closeBtn.setAttribute( 'aria-label', i18n.close || 'Close' );
		closeBtn.addEventListener( 'click', closeModal );
		header.appendChild( title );
		header.appendChild( closeBtn );

		var modalBody = el( 'div', 'nvoos-cg-modal-body' );
		modalBody.appendChild( renderPriceBlock() );
		modalBody.appendChild( renderTrustList() );
		modalBody.appendChild( renderIncludesBlock() );
		modalBody.appendChild( renderEmailRow() );
		modalBody.appendChild( renderBillingRow() );

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
	 * Load Stripe.js on demand — only when the purchase modal opens.
	 *
	 * Stripe.js is intentionally NOT enqueued with the settings page: this
	 * keeps js.stripe.com from being contacted until the user actually
	 * chooses to start a checkout (privacy-conscious script loading).
	 *
	 * @param {Function} callback Invoked once Stripe.js is available.
	 * @return {void}
	 */
	function loadStripeJs( callback ) {
		if ( window.Stripe ) {
			callback();
			return;
		}

		var script = document.createElement( 'script' );
		script.src = 'https://js.stripe.com/v3/';
		script.async = true;
		script.onload = callback;
		script.onerror = function () {
			showError( i18n.stripe_load_error || 'Stripe failed to load. Check your network connection and try again.' );
		};
		document.head.appendChild( script );
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
		loadStripeJs( function () {
			beginCheckout( payBox, payBtn );
		} );
	}

	/**
	 * Checkout flow once Stripe.js is guaranteed loaded.
	 *
	 * @param {HTMLElement} payBox Container for the payment element.
	 * @param {HTMLButtonElement} payBtn The pay/verify button.
	 * @return {void}
	 */
	function beginCheckout( payBox, payBtn ) {
		if ( ! window.Stripe ) {
			showError( i18n.stripe_load_error || 'Stripe failed to load. Check your network connection and try again.' );
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
					renderDiagnoseLink();
				}
				return;
			}

			stripe = window.Stripe( result.data.publishable_key );
			elements = stripe.elements( {
				clientSecret: result.data.client_secret,
				appearance: { theme: 'stripe' }
			} );
			// The plugin collects the buyer email, country, and EU billing
			// address itself (receipt + VAT records); keep the Stripe iframe
			// from asking for them again.
			paymentElement = elements.create( 'paymentElement', {
				fields: { billingDetails: { email: 'never', address: 'never' } }
			} );
			paymentElement.mount( payBox );

			if ( result.data.test_mode ) {
				var modalBody = dialog.querySelector( '.nvoos-cg-modal-body' );
				modalBody.appendChild( el( 'p', 'nvoos-cg-test-mode', i18n.test_mode || '' ) );
			}

			var body = dialog.querySelector( '.nvoos-cg-modal-body' );
			body.appendChild( renderConsent( result.data ) );

			payBtn.dataset.clientSecret = result.data.client_secret;
			updatePayState();
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
		if ( ! consentCheckbox || ! consentCheckbox.checked ) {
			showError( i18n.terms_required || 'Please agree to the Terms of Service and Refund Policy to continue.' );
			return;
		}
		var buyerEmail = emailInput ? emailInput.value.trim() : '';
		if ( ! isValidEmail( buyerEmail ) ) {
			showError( i18n.email_invalid || 'Please enter a valid email address for your receipt.' );
			return;
		}
		if ( ! isAddressValid() ) {
			showError( i18n.address_required || 'EU purchases require a billing address.' );
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
					showError( i18n.payment_processing || 'Payment is still processing. Click Verify once it completes.' );
					setBusy( false );
				}
			} ).catch( function () {
				showError( i18n.generic_error );
			} );
			return;
		}

		stripe.confirmPayment( {
			elements: elements,
			confirmParams: {
				receipt_email: buyerEmail,
				return_url: window.location.href,
				payment_method_data: {
					billing_details: buildBillingDetails( buyerEmail )
				}
			},
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
				payBtn.textContent = i18n.verify || 'Verify';
				showError( i18n.payment_processing || 'Payment is still processing. Click Verify once it completes.' );
				setBusy( false );
			} else {
				showError( ( i18n.payment_incomplete || 'Payment did not complete. Status: ' ) + intent.status );
			}
		} ).catch( function () {
			showError( i18n.generic_error );
		} );
	}

	/**
	 * Verify the payment server-side, then install and activate the
	 * NV oOS Complete bundle.
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

		var verifyBody = { payment_intent: paymentIntentId };
		if ( consentAt > 0 ) {
			// Omit the field (rather than send 0) when no consent timestamp
			// exists — e.g. the interrupted-payment recovery path — because
			// the endpoint rejects out-of-range values.
			verifyBody.terms_agreed_at = consentAt;
		}
		var buyerEmail = emailInput ? emailInput.value.trim() : '';
		if ( isValidEmail( buyerEmail ) ) {
			verifyBody.buyer_email = buyerEmail;
		}
		if ( isEuSelected() ) {
			verifyBody.buyer_country = countrySelect.value;
		}

		apiPost( '/payments/verify', verifyBody ).then( function ( result ) {
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
					var dl = el( 'a', 'button', i18n.download_zip || 'Download ZIP manually' );
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

		// Manual install is the primary documented path — always offer the
		// signed ZIP download alongside the automatic install result.
		if ( data.download_url ) {
			var manual = el( 'div', 'nvoos-cg-manual-install' );
			manual.appendChild( el( 'p', 'nvoos-cg-manual-note', i18n.manual_install_note || 'Prefer to install manually? Download the ZIP and upload it via Plugins → Add New Plugin → Upload Plugin.' ) );
			manual.appendChild( linkEl( 'button', i18n.download_zip || 'Download ZIP manually', data.download_url ) );
			payBox.appendChild( manual );
		}

		// "What happens next" checklist — post-purchase clarity sets
		// expectations (receipt, install, license, roadmap) and reduces
		// buyer's-remorse support contacts.
		var steps = el( 'div', 'nvoos-cg-success-steps' );
		var stepsTitle = i18n.success_steps_title || '';
		if ( stepsTitle ) {
			steps.appendChild( el( 'h4', 'nvoos-cg-success-steps-title', stepsTitle ) );
		}
		var stepsList = el( 'ol', 'nvoos-cg-success-steps-list' );
		var stepItems = [
			i18n.success_step_receipt || '',
			i18n.success_step_installed || '',
			i18n.success_step_license || ''
		];
		for ( var i = 0; i < stepItems.length; i++ ) {
			if ( ! stepItems[ i ] ) {
				continue;
			}
			stepsList.appendChild( el( 'li', '', stepItems[ i ] ) );
		}
		var roadmapStep = i18n.success_step_roadmap || '';
		var changelogUrl = String( config.changelog_url || '' ).trim();
		if ( roadmapStep || ( changelogUrl && /^https?:\/\//i.test( changelogUrl ) ) ) {
			var last = el( 'li', '', roadmapStep );
			if ( changelogUrl && /^https?:\/\//i.test( changelogUrl ) ) {
				last.appendChild( document.createTextNode( ' ' ) );
				last.appendChild( linkEl( 'nvoos-cg-changelog-link', i18n.changelog_link || 'View changelog', changelogUrl ) );
			}
			stepsList.appendChild( last );
		}
		steps.appendChild( stepsList );
		payBox.appendChild( steps );

		var support = i18n.support_line || '';
		if ( support ) {
			payBox.appendChild( el( 'p', 'nvoos-cg-support-line', support ) );
		}

		var footer = dialog.querySelector( '.nvoos-cg-modal-footer' );
		footer.innerHTML = '';
		var reloadBtn = el( 'button', 'button button-primary', i18n.refresh || 'Reload page' );
		reloadBtn.type = 'button';
		reloadBtn.addEventListener( 'click', function () {
			window.location.reload();
		} );
		footer.appendChild( reloadBtn );
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
