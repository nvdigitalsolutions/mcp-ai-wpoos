/**
 * Test Assistant page behaviour for the Content Graph AI addon.
 *
 * Wires the chat-test modal close controls. The modal itself is
 * server-rendered (the Test buttons are links carrying the selected
 * assistant), so this script only handles closing: the close link,
 * the backdrop click, and the Escape key.
 *
 * @since 1.1.0
 */
( function ( root ) {
	'use strict';

	function init() {
		const modal = document.getElementById( 'nvoos-cg-test-modal' );
		const close = modal ? modal.querySelector( '.nvoos-cg-test-modal__close' ) : null;
		const backdrop = modal ? modal.querySelector( '.nvoos-cg-test-modal__backdrop' ) : null;

		if ( ! modal || ! close ) {
			return;
		}

		function hide() {
			modal.style.display = 'none';
		}

		close.addEventListener( 'click', function ( event ) {
			if ( close.tagName === 'A' && close.getAttribute( 'href' ) && close.getAttribute( 'href' ) !== '#' ) {
				// The close link navigates (removes the query parameter);
				// hide immediately for instant feedback.
				hide();
				return;
			}

			event.preventDefault();
			hide();
		} );

		if ( backdrop ) {
			backdrop.addEventListener( 'click', hide );
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && 'block' === modal.style.display ) {
				hide();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window );
