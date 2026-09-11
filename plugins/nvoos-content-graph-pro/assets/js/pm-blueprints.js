/**
 * PM Blueprints Admin JS
 *
 * Handles "Install Blueprint" and "View Details" button clicks
 * on the PM Blueprints admin page.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

( function ( $ ) {
	'use strict';

	var config = window.wpMcpAiPmBlueprints || {};

	if ( ! config.ajaxUrl ) {
		return;
	}

	/**
	 * Install a blueprint via AJAX.
	 */
	function installBlueprint( slug, $btn, $spinner ) {
		$btn.prop( 'disabled', true );
		$btn.text( config.i18n.installing );
		$spinner.show();

		$.post(
			config.ajaxUrl,
			{
				action: 'wp_mcp_ai_pm_install_blueprint',
				blueprint_slug: slug,
				nonce: config.nonce,
			}
		)
			.done( function ( response ) {
				if ( response.success ) {
					$btn.text( config.i18n.installed );
					$btn.removeClass( 'button-primary' ).addClass( 'button' );

					// Update the tags to show installed badge.
					var $card = $btn.closest( '.pm-bp-card' );
					$card.find( '.pm-bp-meta' ).append(
						'<span class="pm-bp-tag installed">' + config.i18n.installed + '</span>'
					);

					// If the response includes an assistant ID, link to it.
					if ( response.data && response.data.assistant_id ) {
						$btn.replaceWith(
							'<a href="' +
								config.editUrl.replace( '0', response.data.assistant_id ) +
								'" class="button">' +
								config.i18n.viewAssistant +
								'</a>'
						);
					}
				} else {
					var msg = response.data && response.data.message
						? response.data.message
						: config.i18n.error;

					// Check for duplicate and offer overwrite.
					if (
						msg.indexOf( 'already exists' ) !== -1 ||
						msg.indexOf( 'duplicate' ) !== -1
					) {
						if ( window.confirm( config.i18n.overwrite ) ) {
							$.post(
								config.ajaxUrl,
								{
									action: 'wp_mcp_ai_pm_install_blueprint',
									blueprint_slug: slug,
									overwrite: 1,
									nonce: config.nonce,
								}
							)
								.done( function ( r ) {
									if ( r.success ) {
										$btn.text( config.i18n.installed );
										$btn.removeClass( 'button-primary' ).addClass( 'button' );
										$btn.prop( 'disabled', true );
									} else {
										showError( $btn, $spinner, msg );
									}
								} )
								.fail( function () {
									showError( $btn, $spinner, config.i18n.error );
								} );
							return;
						}
					}

					showError( $btn, $spinner, msg );
				}
			} )
			.fail( function () {
				showError( $btn, $spinner, config.i18n.error );
			} )
			.always( function () {
				$spinner.hide();
			} );
	}

	/**
	 * Show an error state on a button.
	 */
	function showError( $btn, $spinner, message ) {
		$btn.text( message );
		$btn.prop( 'disabled', false );
		$spinner.hide();
		setTimeout( function () {
			$btn.text( config.i18n.installLabel || 'Install Blueprint' );
		}, 3000 );
	}

	/**
	 * Toggle blueprint details view.
	 */
	function toggleDetails( slug, $btn ) {
		var $card    = $btn.closest( '.pm-bp-card' );
		var $details = $card.find( '.pm-bp-details' );

		if ( $details.length ) {
			// Toggle existing details.
			$details.slideToggle( 200 );
			$btn.text(
				$details.is( ':visible' )
					? config.i18n.hideDetails
					: config.i18n.viewDetails
			);
			return;
		}

		// Fetch blueprint details via AJAX.
		$btn.prop( 'disabled', true );
		$btn.text( config.i18n.loading );

		$.post(
			config.ajaxUrl,
			{
				action: 'wp_mcp_ai_pm_get_blueprint_details',
				blueprint_slug: slug,
				nonce: config.nonce,
			}
		)
			.done( function ( response ) {
				var html = '';
				if ( response.success && response.data ) {
					var bp = response.data;
					html += '<div class="pm-bp-details" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #c3c4c7;">';

					if ( bp.instructions ) {
						html += '<h4 style="margin: 0 0 8px;">' + config.i18n.instructions + '</h4>';
						html += '<div style="font-size: 13px; line-height: 1.6; color: #50575e; max-height: 200px; overflow-y: auto; background: #f0f0f1; padding: 12px; border-radius: 4px; margin-bottom: 12px;">' + nl2br( escHtml( bp.instructions ) ) + '</div>';
					}

					if ( bp.tools && bp.tools.length ) {
						html += '<h4 style="margin: 12px 0 4px;">' + config.i18n.tools + ' (' + bp.tools.length + ')</h4>';
						html += '<div style="display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px;">';
						$.each( bp.tools, function ( i, tool ) {
							html += '<span class="pm-bp-tag" style="font-size: 11px;">' + escHtml( tool ) + '</span>';
						} );
						html += '</div>';
					}

					if ( bp.defaults ) {
						html += '<h4 style="margin: 12px 0 4px;">' + config.i18n.defaults + '</h4>';
						html += '<div style="font-size: 12px; color: #646970;">';
						if ( bp.defaults.provider ) {
							html += 'Provider: <strong>' + escHtml( bp.defaults.provider ) + '</strong><br>';
						}
						if ( bp.defaults.model ) {
							html += config.i18n.model + ': <strong>' + escHtml( bp.defaults.model ) + '</strong><br>';
						}
						if ( bp.defaults.temperature !== undefined ) {
							html += config.i18n.temperature + ': <strong>' + escHtml( bp.defaults.temperature ) + '</strong><br>';
						}
						if ( bp.defaults.max_tokens ) {
							html += config.i18n.maxTokens + ': <strong>' + escHtml( bp.defaults.max_tokens ) + '</strong><br>';
						}
						if ( bp.defaults.profession ) {
							html += 'Profession: <strong>' + escHtml( bp.defaults.profession ) + '</strong><br>';
						}
						if ( bp.defaults.version ) {
							html += 'Version: <strong>' + escHtml( bp.defaults.version ) + '</strong><br>';
						}
						if ( bp.defaults.tags && bp.defaults.tags.length ) {
							html += 'Tags: ';
							$.each( bp.defaults.tags, function ( i, tag ) {
								html += '<span class="pm-bp-tag" style="font-size: 11px;">' + escHtml( tag ) + '</span> ';
							} );
							html += '<br>';
						}
						html += '</div>';
					}

					html += '</div>';
				} else {
					html += '<div class="pm-bp-details" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #c3c4c7;">';
					html += '<p style="color: #646970;">' + config.i18n.noDetails + '</p>';
					html += '</div>';
				}

				$card.append( html );
				$card.find( '.pm-bp-details' ).slideDown( 200 );
				$btn.text( config.i18n.hideDetails );
			} )
			.fail( function () {
				$btn.text( config.i18n.viewDetails );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	}

	/**
	 * Escape HTML entities.
	 */
	function escHtml( text ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( text ) );
		return div.innerHTML;
	}

	/**
	 * Convert newlines to <br> tags.
	 */
	function nl2br( text ) {
		return escHtml( text ).replace( /\n/g, '<br>' );
	}

	// ---- Event Bindings ----

	$( document ).on( 'click', '.pm-bp-install-btn', function () {
		var $btn     = $( this );
		var slug     = $btn.data( 'slug' );
		var $card    = $btn.closest( '.pm-bp-card' );
		var $spinner = $card.find( '.pm-bp-spinner' );

		installBlueprint( slug, $btn, $spinner );
	} );

	$( document ).on( 'click', '.pm-bp-view-btn', function () {
		var $btn = $( this );
		var slug = $btn.data( 'slug' );

		toggleDetails( slug, $btn );
	} );

} )( jQuery );
