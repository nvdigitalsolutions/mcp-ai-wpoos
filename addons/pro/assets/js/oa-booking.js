/**
 * Outbound Booking form handler.
 *
 * Submits the internal booking form to the public REST endpoint and swaps
 * the form for the confirmation message.
 */
( function () {
	'use strict';

	if ( ! window.oaBooking ) {
		return;
	}
	document.querySelectorAll( '[data-oa-booking]' ).forEach( function ( wrap ) {
		var form = wrap.querySelector( '[data-oa-form]' );
		var status = wrap.querySelector( '[data-oa-status]' );
		if ( ! form || ! status ) {
			return;
		}
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var data = new FormData( form );
			var slot = data.get( 'slot' );
			var payload = {
				booking_link_id: window.oaBooking.linkId,
				name: data.get( 'name' ),
				email: data.get( 'email' ),
				company: data.get( 'company' ) || '',
				slot: slot || '',
				consent: !! data.get( 'consent' ),
				message: data.get( 'message' ) || '',
				website: data.get( 'website' ) || '',
				lead_id: window.oaBooking.lead || 0,
				lead_token: window.oaBooking.token || ''
			};
			status.textContent = 'Booking…';
			fetch( window.oaBooking.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload )
			} )
				.then( function ( response ) {
					return response.json().then( function ( json ) {
						return { ok: response.ok, json: json };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && result.json.success ) {
						form.style.display = 'none';
						status.textContent =
							result.json.confirmation ||
							'Thanks! Your call is booked. Check your inbox for the confirmation.';
					} else {
						status.textContent =
							( result.json && result.json.message ) ||
							'Something went wrong. Please try again.';
					}
				} )
				.catch( function () {
					status.textContent = 'Something went wrong. Please try again.';
				} );
		} );
	} );
} )();
