// SPDX-License-Identifier: GPL-3.0-or-later
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
		document.querySelectorAll( '[data-nvoos-nds-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var message = form.getAttribute( 'data-nvoos-nds-confirm' );

				if ( message && ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );
	}
} )();
