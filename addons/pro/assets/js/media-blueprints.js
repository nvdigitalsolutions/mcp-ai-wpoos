/* global jQuery, wpMcpAiMediaBlueprints */
( function ( $ ) {
	'use strict';

	var config = wpMcpAiMediaBlueprints || {};

	// Toggle detail panels.
	$( document ).on( 'click', '.media-bp-details-btn', function () {
		var slug = $( this ).data( 'slug' );
		var panel = $( '#media-bp-details-' + slug );
		var visible = panel.hasClass( 'visible' );

		// Hide all other panels.
		$( '.media-bp-details.visible' ).removeClass( 'visible' );
		$( '.media-bp-details-btn' ).text( config.i18n.viewDetails || 'View Details' );

		if ( ! visible ) {
			panel.addClass( 'visible' );
			$( this ).text( config.i18n.hideDetails || 'Hide Details' );
		}
	} );

	// Install blueprint.
	$( document ).on( 'click', '.media-bp-install-btn', function () {
		var btn = $( this );
		var slug = btn.data( 'slug' );
		var card = btn.closest( '.media-bp-card' );

		if ( ! confirm( config.i18n.overwrite || 'Install this blueprint?' ) ) {
			return;
		}

		btn.prop( 'disabled', true ).text( config.i18n.installing || 'Installing...' );

		$.post(
			config.ajaxUrl,
			{
				action: 'wp_mcp_ai_media_install_blueprint',
				blueprint_slug: slug,
				nonce: config.nonce,
			},
			function ( response ) {
				if ( response.success ) {
					btn.removeClass( 'button-primary' )
						.addClass( 'button' )
						.text( config.i18n.installed || 'Installed!' )
						.prop( 'disabled', true );

					// Add installed tag.
					var meta = card.find( '.media-bp-meta' );
					if ( meta.find( '.media-bp-tag.installed' ).length === 0 ) {
						meta.append(
							'<span class="media-bp-tag installed">' +
							( config.i18n.installed || 'Installed' ) +
							'</span>'
						);
					}

					// If we have an edit URL and post ID, add edit button.
					if ( response.data && response.data.post_id ) {
						var editUrl =
							config.editUrl +
							'&post=' +
							response.data.post_id +
							'&post_type=mcp_ai_assistant';
						card.find( '.media-bp-actions' ).append(
							'<a href="' +
							editUrl +
							'" class="button" target="_blank">' +
							( config.i18n.editAssistant || 'Edit' ) +
							'</a>'
						);
					}
				} else {
					btn.prop( 'disabled', false ).text(
						config.i18n.installLabel || 'Install Blueprint'
					);
					alert(
						( response.data && response.data.message ) ||
						config.i18n.error ||
						'Error installing blueprint.'
					);
				}
			}
		).fail( function () {
			btn.prop( 'disabled', false ).text(
				config.i18n.installLabel || 'Install Blueprint'
			);
			alert( config.i18n.error || 'Error installing blueprint.' );
		} );
	} );
} )( jQuery );
