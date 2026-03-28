/**
 * NV oOS Onboarding Wizard
 *
 * Handles provider tab switching (ARIA tablist pattern), API key save/test,
 * preset card selection, copy-to-clipboard, and keyboard navigation.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */
(function( $ ) {
	'use strict';

	/* ------------------------------------------------------------------
	 * 1. Provider tab switching (WAI-ARIA Tabs pattern)
	 * ----------------------------------------------------------------*/

	const $tablist = $( '[role="tablist"]' );
	const $tabs    = $tablist.find( '[role="tab"]' );
	const $panels  = $( '[role="tabpanel"]' );

	/**
	 * Activate a given tab and show its associated panel.
	 *
	 * @param {jQuery} $tab The tab element to activate.
	 */
	function activateTab( $tab ) {
		// Deactivate all tabs.
		$tabs.attr( 'aria-selected', 'false' ).attr( 'tabindex', '-1' ).removeClass( 'is-active' );

		// Hide all panels.
		$panels.attr( 'hidden', 'hidden' ).removeClass( 'is-active' );

		// Activate the selected tab.
		$tab.attr( 'aria-selected', 'true' ).attr( 'tabindex', '0' ).addClass( 'is-active' );

		// Show the associated panel using a safe attribute selector.
		const panelId = $tab.attr( 'aria-controls' );
		$( document.getElementById( panelId ) ).removeAttr( 'hidden' ).addClass( 'is-active' );

		// Move focus to the activated tab.
		$tab.focus();
	}

	// Click handler for tabs.
	$tabs.on( 'click', function() {
		activateTab( $( this ) );
	} );

	// Keyboard navigation for tabs (arrow keys, Home, End).
	$tabs.on( 'keydown', function( e ) {
		const tabArray = $tabs.toArray();
		const idx      = tabArray.indexOf( this );
		let newIdx     = idx;

		switch ( e.key ) {
			case 'ArrowRight':
			case 'ArrowDown':
				newIdx = ( idx + 1 ) % tabArray.length;
				e.preventDefault();
				break;
			case 'ArrowLeft':
			case 'ArrowUp':
				newIdx = ( idx - 1 + tabArray.length ) % tabArray.length;
				e.preventDefault();
				break;
			case 'Home':
				newIdx = 0;
				e.preventDefault();
				break;
			case 'End':
				newIdx = tabArray.length - 1;
				e.preventDefault();
				break;
			default:
				return;
		}

		activateTab( $( tabArray[ newIdx ] ) );
	} );

	/* ------------------------------------------------------------------
	 * 2. Show / Hide API key toggle
	 * ----------------------------------------------------------------*/

	$( '.wp-mcp-ai-show-key' ).on( 'click', function() {
		const targetId = $( this ).data( 'target' );
		const $input   = $( '#' + targetId );

		if ( 'password' === $input.attr( 'type' ) ) {
			$input.attr( 'type', 'text' );
			$( this ).text( wpMcpAiWizard.i18n.hide );
			$( this ).attr( 'aria-label', wpMcpAiWizard.i18n.hideKey );
		} else {
			$input.attr( 'type', 'password' );
			$( this ).text( wpMcpAiWizard.i18n.show );
			$( this ).attr( 'aria-label', wpMcpAiWizard.i18n.showKey );
		}
	} );

	/* ------------------------------------------------------------------
	 * 3. Save API key + Test connection
	 * ----------------------------------------------------------------*/

	$( '.wp-mcp-ai-wizard-test-btn' ).on( 'click', function() {
		const provider = $( this ).data( 'provider' );
		const $result  = $( '[data-for="' + provider + '"]' );
		const $btn     = $( this );

		let apiKey    = '';
		const extraData = {};

		if ( 'openai' === provider ) {
			apiKey = $( '#wp_mcp_ai_openai_key' ).val();
		} else if ( 'anthropic' === provider ) {
			apiKey = $( '#wp_mcp_ai_anthropic_key' ).val();
		} else if ( 'gemini' === provider ) {
			apiKey = $( '#wp_mcp_ai_gemini_key' ).val();
		} else if ( 'huggingface' === provider ) {
			apiKey = $( '#wp_mcp_ai_huggingface_key' ).val();
		} else if ( 'ollama' === provider ) {
			extraData.ollama_url = $( '#wp_mcp_ai_ollama_url' ).val();
		} else if ( 'lm_studio' === provider ) {
			extraData.lm_studio_url = $( '#wp_mcp_ai_lm_studio_url' ).val();
		} else if ( 'cloudflare' === provider ) {
			apiKey = $( '#wp_mcp_ai_cloudflare_token' ).val();
			extraData.cloudflare_account_id = $( '#wp_mcp_ai_cloudflare_account_id' ).val();
		}

		$result.html( '<span class="wp-mcp-ai-testing">' + wpMcpAiWizard.i18n.testing + '</span>' );
		$btn.prop( 'disabled', true );

		// First save the key via the wizard AJAX handler.
		$.post( wpMcpAiWizard.ajaxUrl, {
			action: 'wp_mcp_ai_wizard_save_step',
			step: 2,
			provider: provider,
			api_key: apiKey,
			nonce: $( '#wp_mcp_ai_wizard_nonce' ).val(),
			extra: extraData,
		} ).always( function() {
			// Then test the connection using the provider diagnostics AJAX action.
			$.post( wpMcpAiWizard.ajaxUrl, {
				action: 'wp_mcp_ai_test_provider',
				provider: provider,
				nonce: $btn.data( 'nonce' ),
			} )
				.done( function( resp ) {
					if ( resp && resp.success ) {
						$result.html( '<span class="wp-mcp-ai-test-success">✓ ' + wpMcpAiWizard.i18n.connected + '</span>' );
					} else {
						const msg = ( resp && resp.data && resp.data.message )
							? resp.data.message
							: wpMcpAiWizard.i18n.connectionFailed;
						$result.html( '<span class="wp-mcp-ai-test-error">✗ ' + msg + '</span>' );
					}
				} )
				.fail( function() {
					$result.html( '<span class="wp-mcp-ai-test-error">✗ ' + wpMcpAiWizard.i18n.requestFailed + '</span>' );
				} )
				.always( function() {
					$btn.prop( 'disabled', false );
				} );
		} );
	} );

	/* ------------------------------------------------------------------
	 * 4. Preset card selection (step 3)
	 * ----------------------------------------------------------------*/

	$( '.wp-mcp-ai-preset-card' ).on( 'click', function() {
		const $card = $( this );
		// Let the browser toggle the checkbox first.
		setTimeout( function() {
			if ( $card.find( 'input' ).is( ':checked' ) ) {
				$card.addClass( 'is-selected' ).attr( 'aria-checked', 'true' );
			} else {
				$card.removeClass( 'is-selected' ).attr( 'aria-checked', 'false' );
			}
		}, 0 );
	} );

	// Save presets and redirect to next step.
	$( '#wp-mcp-ai-apply-presets' ).on( 'click', function() {
		const selected = [];
		$( '.wp-mcp-ai-preset-checkbox:checked' ).each( function() {
			selected.push( $( this ).val() );
		} );

		const $btn    = $( this );
		const $result = $( '#wp-mcp-ai-preset-save-result' );
		$btn.prop( 'disabled', true ).text( wpMcpAiWizard.i18n.saving );

		$.post( wpMcpAiWizard.ajaxUrl, {
			action: 'wp_mcp_ai_wizard_save_step',
			step: 3,
			presets: selected,
			nonce: $btn.data( 'nonce' ),
		} )
			.done( function( resp ) {
				if ( resp && resp.success ) {
					window.location.href = wpMcpAiWizard.nextStepUrl;
				} else {
					$result.html( '<span class="wp-mcp-ai-test-error">' + wpMcpAiWizard.i18n.saveFailed + '</span>' );
					$btn.prop( 'disabled', false ).text( wpMcpAiWizard.i18n.saveAndContinue );
				}
			} )
			.fail( function() {
				$result.html( '<span class="wp-mcp-ai-test-error">' + wpMcpAiWizard.i18n.requestFailed + '</span>' );
				$btn.prop( 'disabled', false ).text( wpMcpAiWizard.i18n.saveAndContinue );
			} );
	} );

	/* ------------------------------------------------------------------
	 * 5. Complete wizard (step 4 explicit button)
	 * ----------------------------------------------------------------*/

	$( '#wp-mcp-ai-complete-wizard' ).on( 'click', function() {
		const $btn = $( this );
		$btn.prop( 'disabled', true );

		$.post( wpMcpAiWizard.ajaxUrl, {
			action: 'wp_mcp_ai_wizard_complete',
			nonce: wpMcpAiWizard.completeNonce,
		} )
			.done( function( resp ) {
				if ( resp && resp.success ) {
					$btn.text( wpMcpAiWizard.i18n.completed ).addClass( 'button-disabled' );
					$( '.wp-mcp-ai-wizard-completion-status' ).html(
						'<span class="wp-mcp-ai-test-success">✓ ' + wpMcpAiWizard.i18n.setupComplete + '</span>'
					);
				}
			} )
			.always( function() {
				$btn.prop( 'disabled', false );
			} );
	} );

	/* ------------------------------------------------------------------
	 * 6. Copy shortcode to clipboard
	 * ----------------------------------------------------------------*/

	$( '.wp-mcp-ai-copy-shortcode' ).on( 'click', function() {
		const $btn  = $( this );
		const code  = $btn.data( 'shortcode' );
		const $feedback = $btn.find( '.wp-mcp-ai-copy-feedback' );

		/**
		 * Show a temporary success/failure message in the feedback element.
		 *
		 * @param {string}  msg     Text to show.
		 * @param {boolean} success Whether the operation succeeded.
		 */
		const showFeedback = function( msg, success ) {
			$feedback
				.text( msg )
				.toggleClass( 'is-visible', true )
				.toggleClass( 'is-error', ! success );
			setTimeout( function() {
				$feedback.removeClass( 'is-visible is-error' );
			}, 2000 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( code ).then(
				function() {
					showFeedback( wpMcpAiWizard.i18n.copied, true );
				},
				function() {
					showFeedback( wpMcpAiWizard.i18n.copyFailed, false );
				}
			);
		} else {
			// Fallback for older browsers using deprecated execCommand.
			try {
				const $temp = $( '<textarea>' ).val( code ).appendTo( 'body' ).select();
				const ok    = document.execCommand( 'copy' );
				$temp.remove();
				showFeedback(
					ok ? wpMcpAiWizard.i18n.copied : wpMcpAiWizard.i18n.copyFailed,
					ok
				);
			} catch ( _err ) {
				showFeedback( wpMcpAiWizard.i18n.copyFailed, false );
			}
		}
	} );

	/* ------------------------------------------------------------------
	 * 7. Welcome notice dismissal
	 * ----------------------------------------------------------------*/

	const $notice = $( '.wp-mcp-ai-welcome-notice' );
	if ( $notice.length ) {
		$notice.on( 'click', function( e ) {
			if ( $( e.target ).hasClass( 'notice-dismiss' ) ) {
				$.post( wpMcpAiWizard.ajaxUrl, {
					action: 'wp_mcp_ai_dismiss_welcome_notice',
					nonce: $notice.data( 'nonce' ),
				} );
			}
		} );
	}
}( jQuery ));
