/**
 * Add Assistant page behaviour for the Content Graph AI addon.
 *
 * Opens the create-assistant modal from a professional card, submits
 * the create-from-template AJAX request, and redirects to the new
 * assistant's editor.
 *
 * Config arrives via `window.nvoosCgAddAssistant`.
 *
 * @since 1.1.0
 */
( function ( root ) {
	'use strict';

	/**
	 * Wire the modal open/close behaviour.
	 */
	function wireModal() {
		const modal = document.getElementById( 'nvoos-cg-create-modal' );
		const professionInput = document.getElementById( 'profession-id' );

		if ( ! modal || ! professionInput ) {
			return;
		}

		function open( professionId ) {
			professionInput.value = professionId;
			modal.style.display = 'block';
		}

		function close() {
			modal.style.display = 'none';
		}

		document.querySelectorAll( '.nvoos-cg-create-assistant' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				open( button.getAttribute( 'data-profession-id' ) || '' );
			} );
		} );

		modal.querySelectorAll( '.nvoos-cg-modal-close, .nvoos-cg-modal-overlay' ).forEach( function ( el ) {
			el.addEventListener( 'click', close );
		} );
	}

	/**
	 * Wire the create-from-template form submission.
	 */
	function wireCreateForm() {
		const form = document.getElementById( 'nvoos-cg-create-form' );
		const config = root.nvoosCgAddAssistant;

		if ( ! form || ! config ) {
			return;
		}

		const submit = document.getElementById( 'nvoos-cg-submit-create' );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			const data = new root.FormData( form );
			data.append( 'action', config.action );
			data.append( 'nonce', config.nonce );

			if ( submit ) {
				submit.disabled = true;
			}

			function done() {
				if ( submit ) {
					submit.disabled = false;
				}
			}

			root
				.fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					done();

					if ( payload && payload.success && payload.data ) {
						root.location.href = payload.data.edit_url || config.redirect;
						return;
					}

					root.alert(
						payload && payload.data && payload.data.message
							? payload.data.message
							: config.strings.error
					);
				} )
				.catch( function () {
					done();
					root.alert( config.strings.error );
				} );
		} );
	}

	function init() {
		wireModal();
		wireCreateForm();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window );
