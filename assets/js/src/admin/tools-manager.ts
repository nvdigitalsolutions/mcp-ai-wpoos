/**
 * Tools Manager — TypeScript edition.
 *
 * Handles tool enable/disable toggle interactions on the admin tools
 * management screen.  Uses jQuery for DOM and AJAX.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── jQuery declaration ───────────────────────────────────────────────

interface JQuery {
	length: number;
	prop( name: string ): boolean;
	prop( name: string, value: unknown ): this;
	data( key: string ): string | undefined;
	val( value: string ): this;
	text( text: string ): this;
	css( property: string, value: string ): this;
	addClass( className: string ): this;
	append( content: string | JQuery ): this;
	appendTo( target: string | JQuery ): this;
	prependTo( target: string | JQuery ): this;
	closest( selector: string ): JQuery;
	find( selector: string ): JQuery;
	fadeOut( callback: () => void ): this;
	remove(): void;
	on( event: string, handler: ( event: Event ) => void ): this;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	on( event: string, selector: string, handler: ( event: Event ) => void ): this;
	ready( handler: () => void ): void;
	first(): JQuery;
	is( selector: string ): boolean;
}

interface JQueryXHR {
	done( callback: () => void ): this;
	fail( callback: () => void ): this;
	always( callback: () => void ): this;
}

interface JQueryStatic {
	( selector: string | HTMLElement | Document | ( () => void ) ): JQuery;
	ajax( settings: Record< string, unknown > ): JQueryXHR;
}

interface WpNotices {
	initialize(): void;
}

declare const jQuery: JQueryStatic;
const $ = jQuery;

// ── Types ────────────────────────────────────────────────────────────

interface AdminGlobal {
	ajaxUrl: string;
	nonce: string;
	i18n: {
		enabled?: string;
		disabled?: string;
	};
}

interface ToggleResponse {
	success: boolean;
	data?: {
		enabled?: boolean;
		message?: string;
	};
}

function getAdmin(): AdminGlobal | undefined {
	return ( window as unknown as Record< string, unknown > ).wpMcpAiAdmin as AdminGlobal | undefined;
}

function getWp(): { notices?: WpNotices } | undefined {
	return ( window as unknown as Record< string, unknown > ).wp as { notices?: WpNotices } | undefined;
}

// ── Notice helper ────────────────────────────────────────────────────

function showNotice( message: string, type: string ): void {
	const $notice = $( '<div>' )
		.addClass( 'notice notice-' + type + ' is-dismissible' )
		.append( $( '<p>' ).text( message ) );

	const $target = $( '.wp-mcp-ai-tools-manager' ).length
		? $( '.wp-mcp-ai-tools-manager' ).first()
		: $( '.wrap' ).first();

	if ( ! $target.length ) { return; }

	$notice.prependTo( $target );

	setTimeout( () => {
		$notice.fadeOut( function ( this: HTMLElement ) {
			$( this ).remove();
		} );
	}, 5000 );

	const wp = getWp();
	if ( wp?.notices ) {
		wp.notices.initialize();
	}
}

// ── Initialization ───────────────────────────────────────────────────

export function initToolsManager(): void {
	$( document ).on( 'change', '.wp-mcp-ai-tool-toggle', function ( this: HTMLElement ) {
		const $toggle = $( this );
		const $row = $toggle.closest( 'tr' );
		const toolSlug = $toggle.data( 'tool-slug' ) || '';
		const isChecked = $toggle.prop( 'checked' );
		const action = isChecked ? 'enable' : 'disable';
		const admin = getAdmin();

		if ( ! admin ) { return; }

		$toggle.prop( 'disabled', true );

		$.ajax( {
			url: admin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wp_mcp_ai_toggle_tool',
				nonce: admin.nonce,
				tool_slug: toolSlug,
				tool_action: action,
			},
			success( response: ToggleResponse ) {
				if ( response.success ) {
					const $status = $row.find( '.wp-mcp-ai-tool-status' );
					const statusText = response.data?.enabled
						? ( admin.i18n.enabled || 'Enabled' )
						: ( admin.i18n.disabled || 'Disabled' );
					const statusColor = response.data?.enabled ? '#46b450' : '#999';
					$status.text( statusText ).css( 'background', statusColor );
					showNotice( response.data?.message || '', 'success' );
				} else {
					$toggle.prop( 'checked', ! isChecked );
					showNotice( response.data?.message || 'Failed to update tool status.', 'error' );
				}
			},
			error() {
				$toggle.prop( 'checked', ! isChecked );
				showNotice( 'An error occurred while updating tool status.', 'error' );
			},
			complete() {
				$toggle.prop( 'disabled', false );
			},
		} as Record< string, unknown > );
	} );
}

// ── Auto-init ────────────────────────────────────────────────────────

$( document ).ready( () => {
	if ( $( '.wp-mcp-ai-tools-manager' ).length ) {
		initToolsManager();
	}
} );
