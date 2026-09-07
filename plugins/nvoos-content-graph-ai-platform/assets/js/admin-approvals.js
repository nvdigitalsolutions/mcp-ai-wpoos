/**
 * Approvals admin page — JavaScript.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const cfg = window.wpMcpAiApprovals || {};
	const ajax = cfg.ajaxUrl || '';
	const nonce = cfg.nonce || '';
	const i18n = cfg.i18n || {};

	/**
	 * Load pending approvals into the table.
	 */
	function loadApprovals() {
		const assistantId = $( '#approvals-filter-assistant' ).val() || '';
		const $tbody = $( '#approvals-tbody' ).html(
			'<tr><td colspan="7">' + i18n.loading + '</td></tr>'
		);

		$.get( ajax, {
			action: 'wp_mcp_ai_list_approvals',
			nonce,
			assistant_id: assistantId,
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					$tbody.html( '<tr><td colspan="7">' + ( response.data && response.data.message ? response.data.message : 'Error' ) + '</td></tr>' );
					return;
				}
				const approvals = response.data.approvals || [];
				if ( ! approvals.length ) {
					$tbody.html( '<tr><td colspan="7">' + i18n.noPending + '</td></tr>' );
					$( '#wp-mcp-ai-approvals-badge' ).hide();
					return;
				}
				renderTable( $tbody, approvals );
				$( '#wp-mcp-ai-approvals-badge' ).text( approvals.length ).show();
			} )
			.fail( function () {
				$tbody.html( '<tr><td colspan="7">Request failed.</td></tr>' );
			} );
	}

	/**
	 * Render rows into the approvals table.
	 *
	 * @param {jQuery} $tbody
	 * @param {Array}  approvals
	 */
	function renderTable( $tbody, approvals ) {
		$tbody.empty();
		approvals.forEach( function ( item ) {
			const args = item.arguments && Object.keys( item.arguments ).length
				? JSON.stringify( item.arguments, null, 2 )
				: '—';

			const $tr = $( '<tr>' );
			$tr.append( $( '<td>' ).text( item.id ) );
			$tr.append( $( '<td>' ).html( '<code>' + escHtml( item.tool ) + '</code>' ) );
			$tr.append( $( '<td>' ).text( item.reason || '—' ) );
			$tr.append( $( '<td>' ).text( item.requester_name || item.requester_id || '—' ) );
			$tr.append( $( '<td>' ).text( item.created_at_formatted || '—' ) );
			$tr.append( $( '<td>' ).text( item.expires_at_formatted || '—' ) );

			// Actions cell.
			const $actions = $( '<td>' );
			const $approveBtn = $( '<button>' )
				.addClass( 'button button-primary approval-action-btn' )
				.text( i18n.approve )
				.attr( 'data-id', item.id )
				.attr( 'data-resolution', 'approve' );
			const $denyBtn = $( '<button>' )
				.addClass( 'button approval-action-btn' )
				.text( i18n.deny )
				.attr( 'data-id', item.id )
				.attr( 'data-resolution', 'deny' )
				.css( 'margin-left', '6px' );

			// Show arguments on hover / title.
			if ( args !== '—' ) {
				$tr.attr( 'title', args );
			}

			$actions.append( $approveBtn ).append( $denyBtn );
			$tr.append( $actions );
			$tbody.append( $tr );
		} );
	}

	/**
	 * Resolve an approval (approve or deny).
	 *
	 * @param {number} approvalId
	 * @param {string} resolution  'approve' or 'deny'
	 * @param {string} note
	 */
	function resolveApproval( approvalId, resolution, note ) {
		$.post( ajax, {
			action: 'wp_mcp_ai_resolve_approval',
			nonce,
			approval_id: approvalId,
			resolution,
			note,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					// Remove row from table.
					$( '#approvals-tbody tr' ).filter( function () {
						return $( this ).find( '.approval-action-btn' ).first().data( 'id' ) === approvalId;
					} ).fadeOut( 300, function () {
						$( this ).remove();
						if ( ! $( '#approvals-tbody tr' ).length ) {
							$( '#approvals-tbody' ).html( '<tr><td colspan="7">' + i18n.noPending + '</td></tr>' );
							$( '#wp-mcp-ai-approvals-badge' ).hide();
						}
					} );
				} else {
					window.alert( response.data && response.data.message ? response.data.message : 'Error' );
				}
			} )
			.fail( function () {
				window.alert( 'Request failed.' );
			} );
	}

	/**
	 * Simple HTML escaper.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	$( function () {
		loadApprovals();

		$( '#approvals-refresh' ).on( 'click', function () {
			loadApprovals();
		} );

		$( '#approvals-filter-assistant' ).on( 'change', function () {
			loadApprovals();
		} );

		$( '#approvals-tbody' ).on( 'click', '.approval-action-btn', function () {
			const $btn = $( this );
			const approvalId = parseInt( $btn.data( 'id' ), 10 );
			const resolution = String( $btn.data( 'resolution' ) );

			const note = window.prompt( i18n.noteLabel || 'Note (optional):', '' );
			if ( null === note ) {
				return; // User cancelled.
			}

			$btn.prop( 'disabled', true ).text( i18n.loading );
			resolveApproval( approvalId, resolution, note );
		} );
	} );
} )( jQuery );
