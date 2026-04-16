/**
 * NV oOS Graphify — Admin Settings
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */
( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		// Rebuild button handler
		$( '#nvoos-graphify-rebuild-btn' ).on( 'click', function( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $status = $( '#nvoos-graphify-build-status' );

			$btn.prop( 'disabled', true ).text( 'Building...' );
			$status.text( 'Building graph...' ).attr( 'class', 'nvoos-graphify-status nvoos-graphify-status--building' );

			$.ajax( {
				url: nvoos_graphify_admin.rest_url + 'nvoos-graphify/v1/build',
				method: 'POST',
				data: JSON.stringify( { mode: 'full' } ),
				contentType: 'application/json',
				beforeSend: function( xhr ) {
					xhr.setRequestHeader( 'X-WP-Nonce', nvoos_graphify_admin.nonce );
				},
				success: function( response ) {
					$status.text( 'Complete' ).attr( 'class', 'nvoos-graphify-status nvoos-graphify-status--complete' );
					// Update stat cards
					if ( response.stats ) {
						$( '#nvoos-graphify-node-count' ).text( response.stats.node_count || 0 );
						$( '#nvoos-graphify-edge-count' ).text( response.stats.edge_count || 0 );
					}
					$btn.prop( 'disabled', false ).text( 'Rebuild Graph' );
					location.reload(); // Refresh to show updated stats
				},
				error: function( xhr ) {
					$status.text( 'Error' ).attr( 'class', 'nvoos-graphify-status nvoos-graphify-status--error' );
					$btn.prop( 'disabled', false ).text( 'Rebuild Graph' );
					alert( 'Build failed: ' + ( xhr.responseJSON ? xhr.responseJSON.message : 'Unknown error' ) );
				},
			} );
		} );

		// Toggle sections
		$( '.nvoos-graphify-section-toggle' ).on( 'click', function() {
			$( this ).next( '.nvoos-graphify-section-content' ).slideToggle( 200 );
			$( this ).find( '.dashicons' ).toggleClass( 'dashicons-arrow-down-alt2 dashicons-arrow-up-alt2' );
		} );
	} );
} )( jQuery );
