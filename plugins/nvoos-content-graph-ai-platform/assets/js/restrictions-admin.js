/**
 * Restriction management helpers for admin pages.
 *
 * Powers the "Lift restriction" buttons on the Token Manager panel and the
 * dismissible restriction notice banner. Communicates with the server via
 * the wp_mcp_ai_lift_user_restriction and wp_mcp_ai_dismiss_restriction_notice
 * AJAX endpoints.
 *
 * @since 1.2.0
 */

/* global jQuery, wpMcpAiRestrictionsAdmin */

( function ( $ ) {
	'use strict';

	/**
	 * Lift a restriction for a user and reload the page so the panel
	 * re-renders from the registry.
	 *
	 * @param {number} userId     User ID.
	 * @param {string} type       Restriction type ('all' lifts everything).
	 * @param {jQuery} $button    Triggering button (for UI feedback).
	 */
	function liftRestriction( userId, type, $button ) {
		if ( ! window.confirm( wpMcpAiRestrictionsAdmin.confirmLift ) ) {
			return;
		}

		$button.prop( 'disabled', true );

		$.post(
			wpMcpAiRestrictionsAdmin.ajaxUrl,
			{
				action: 'wp_mcp_ai_lift_user_restriction',
				nonce: wpMcpAiRestrictionsAdmin.nonce,
				user_id: userId,
				type: type,
			},
			function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					window.alert( wpMcpAiRestrictionsAdmin.liftFailed );
					$button.prop( 'disabled', false );
				}
			}
		).fail( function () {
			window.alert( wpMcpAiRestrictionsAdmin.liftFailed );
			$button.prop( 'disabled', false );
		} );
	}

	/**
	 * Dismiss the restriction notice banner.
	 *
	 * @param {jQuery} $notice Notice element to remove.
	 */
	function dismissNotice( $notice ) {
		$.post( wpMcpAiRestrictionsAdmin.ajaxUrl, {
			action: 'wp_mcp_ai_dismiss_restriction_notice',
			nonce: wpMcpAiRestrictionsAdmin.nonce,
		} ).done( function ( response ) {
			if ( response && response.success ) {
				$notice.remove();
			}
		} );
	}

	$( function () {
		$( document ).on( 'click', '.wp-mcp-ai-lift-restriction', function ( event ) {
			event.preventDefault();

			const $button = $( this );
			const userId = parseInt( $button.data( 'user-id' ), 10 );
			const type = $button.data( 'type' ) || 'all';

			if ( userId > 0 ) {
				liftRestriction( userId, type, $button );
			}
		} );

		$( document ).on( 'click', '.wp-mcp-ai-restriction-notice .notice-dismiss', function () {
			dismissNotice( $( this ).closest( '.wp-mcp-ai-restriction-notice' ) );
		} );
	} );
}( jQuery ) );
