/**
 * Build Assistant page behaviour for the Content Graph AI addon.
 *
 * Handles the manual create form (AJAX create, then redirect to the
 * assistant list) and the Build-with-AI chat modal toggle.
 *
 * Config arrives via `window.nvoosCgCreateAssistant`.
 *
 * @since 1.1.0
 */
( function ( root ) {
	'use strict';

	/**
	 * Wire the Build with AI modal open/close behaviour.
	 */
	function wireModal() {
		const trigger = document.querySelector( '.nvoos-cg-build-with-ai-btn' );
		const modal = document.getElementById( 'nvoos-cg-build-assistant-modal' );

		if ( ! trigger || ! modal ) {
			return;
		}

		function open() {
			modal.style.display = 'block';
		}

		function close() {
			modal.style.display = 'none';
		}

		trigger.addEventListener( 'click', open );

		modal
			.querySelectorAll( '.nvoos-cg-test-modal__close, .nvoos-cg-test-modal__backdrop' )
			.forEach( function ( el ) {
				el.addEventListener( 'click', close );
			} );
	}

	/**
	 * Wire the manual create form submission.
	 */
	function wireCreateForm() {
		const form = document.getElementById( 'nvoos-cg-create-assistant-form' );
		const config = root.nvoosCgCreateAssistant;

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
