/**
 * NV oOS Design System — Email Picker
 *
 * Lightweight admin script for the Emails tab. Handles:
 *   - client-side confirmation for template deletion
 *   - (placeholder for future preview interactions — the preview is
 *     server-rendered at page load)
 *
 * @package NV_oOS_Design_System
 * @since 0.2.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initDeleteConfirmation();
	} );

	/**
	 * Confirm destructive actions submitted through inline forms.
	 */
	function initDeleteConfirmation() {
		document.querySelectorAll( '.nvoos-nds-email-wrap form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var action = form.querySelector( 'input[name="nds_action"]' );

				if ( ! action || 'delete' !== action.value ) {
					return;
				}

				if ( ! window.confirm( 'Delete this template? This cannot be undone.' ) ) {
					event.preventDefault();
				}
			} );
		} );
	}
} )();
