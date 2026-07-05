/**
 * Crocoblock Design System — Token Preview
 *
 * Lightweight admin script for the CDS settings page. Handles:
 *   - Live preview updates when token values change
 *   - "Reset to default" button
 *   - "Copy to clipboard" for export JSON
 *
 * @package NV_oOS_Crocoblock_DS
 * @since 0.1.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initResetButtons();
		initCopyExport();
	} );

	/**
	 * Wire up "Reset" buttons that restore a token to its default value.
	 */
	function initResetButtons() {
		document.querySelectorAll( '.nvoos-cds-reset-token' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId = btn.getAttribute( 'data-target' );
				var defaultValue = btn.getAttribute( 'data-default' );
				var input = document.getElementById( targetId );

				if ( input && defaultValue !== null ) {
					input.value = defaultValue;
					btn.style.display = 'none';

					// Trigger change for any listeners (e.g., live preview).
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
		} );

		// Show reset button when a token value differs from its default.
		document.querySelectorAll( '.nvoos-cds-text-input, .nvoos-cds-color-picker' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				var defaultValue = input.getAttribute( 'data-default' );
				var resetBtn = input.parentElement.querySelector( '.nvoos-cds-reset-token' );

				if ( resetBtn ) {
					if ( input.value !== defaultValue ) {
						resetBtn.style.display = '';
					} else {
						resetBtn.style.display = 'none';
					}
				}
			} );
		} );
	}

	/**
	 * Wire up "Copy to Clipboard" for the export JSON textarea.
	 */
	function initCopyExport() {
		var copyBtn = document.getElementById( 'nvoos-cds-copy-export' );
		var textarea = document.getElementById( 'nvoos-cds-export-json' );

		if ( ! copyBtn || ! textarea ) {
			return;
		}

		copyBtn.addEventListener( 'click', function () {
			textarea.select();
			textarea.setSelectionRange( 0, 99999 ); // Mobile fallback.

			try {
				document.execCommand( 'copy' );
				showCopiedFeedback( copyBtn );
			} catch ( err ) {
				// Fallback: let the user copy manually.
				// Modern browsers also support navigator.clipboard.writeText().
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( textarea.value ).then( function () {
						showCopiedFeedback( copyBtn );
					} ).catch( function () {
						// Silent fail — text is already selected.
					} );
				}
			}
		} );
	}

	/**
	 * Show temporary "Copied!" feedback on a button.
	 *
	 * @param {HTMLElement} btn The button element.
	 */
	function showCopiedFeedback( btn ) {
		var originalText = btn.textContent;
		btn.textContent = 'Copied!';
		btn.disabled = true;

		setTimeout( function () {
			btn.textContent = originalText;
			btn.disabled = false;
		}, 2000 );
	}
} )();
