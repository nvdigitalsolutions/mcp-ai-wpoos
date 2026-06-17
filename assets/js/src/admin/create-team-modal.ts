/**
 * Create Team Modal — TypeScript edition.
 *
 * Simple modal for creating agent teams in the admin.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

interface JQuery {
	length: number;
	[ index: number ]: HTMLElement;
	is( sel: string ): boolean;
	val(): string | undefined;
	val( v: string ): this;
	text(): string;
	text( t: string ): this;
	prop( n: string ): boolean;
	prop( n: string, v: unknown ): this;
	fadeIn( d: number ): this;
	fadeOut( d: number ): this;
	focus(): this;
	on( e: string, h: ( ev: Event ) => void ): this;
	on( e: string, s: string, h: ( ev: Event ) => void ): this;
	ready( h: () => void ): void;
	reset(): void;
}

interface JQueryXHR {
	done( cb: ( ...args: unknown[] ) => void ): this;
	fail( cb: ( ...args: unknown[] ) => void ): this;
}

interface JQueryStatic {
	( sel: string | HTMLElement | Document | ( () => void ) ): JQuery;
	ajax( s: Record< string, unknown > ): JQueryXHR;
}

declare const jQuery: JQueryStatic;
const $ = jQuery;

// ── Types ────────────────────────────────────────────────────────────

interface CreateTeamGlobal {
	ajaxUrl: string;
	nonce: string;
	strings: {
		required?: string;
		minProfessions?: string;
		creating?: string;
		success?: string;
		error?: string;
	};
}

interface AjaxResponse {
	success: boolean;
	data?: {
		message?: string;
		edit_url?: string;
	};
}

function getG(): CreateTeamGlobal | undefined {
	return ( window as unknown as Record< string, unknown > ).wpMcpAiCreateTeam as CreateTeamGlobal | undefined;
}

// ── Init ─────────────────────────────────────────────────────────────

export function initCreateTeamModal(): void {
	$( document ).ready( () => {
		const g = getG();
		if ( ! g ) { return; }

		const modal = $( '#wp-mcp-ai-create-team-modal' );

		function closeAndReset(): void {
			modal.fadeOut( 200 );
			( $( '#wp-mcp-ai-create-team-form' )[ 0 ] as HTMLFormElement ).reset();
		}

		$( document ).on( 'click', '#wp-mcp-ai-open-create-team-modal', ( e ) => { e.preventDefault(); modal.fadeIn( 200 ); $( '#team-title' ).focus(); } );
		$( '.wp-mcp-ai-modal-close, .wp-mcp-ai-modal-overlay' ).on( 'click', closeAndReset );
		$( document ).on( 'keydown', ( e ) => { if ( ( e as KeyboardEvent ).key === 'Escape' && modal.is( ':visible' ) ) { closeAndReset(); } } );

		$( '#wp-mcp-ai-create-team-form' ).on( 'submit', ( e ) => {
			e.preventDefault();
			const submitButton = $( '#wp-mcp-ai-submit-create-team' );
			const originalText = submitButton.text();
			const title = ( $( '#team-title' ).val() || '' ).trim();
			const professions = ( $( '#team-professions' ).val() as unknown as string[] ) || [];

			if ( ! title ) { alert( g.strings.required || 'Required' ); $( '#team-title' ).focus(); return; }
			if ( professions.length < 2 ) { alert( g.strings.minProfessions || 'Minimum 2 professions' ); $( '#team-professions' ).focus(); return; }

			submitButton.prop( 'disabled', true ).text( g.strings.creating || 'Creating...' );

			$.ajax( {
				url: g.ajaxUrl, type: 'POST', data: {
					action: 'wp_mcp_ai_create_team_from_modal', nonce: g.nonce, title,
					professions, description: $( '#team-description' ).val(),
					provider: $( '#team-provider' ).val(), model: $( '#team-model' ).val(),
					temperature: $( '#team-temperature' ).val(),
				},
				success( r: AjaxResponse ) {
					if ( r.success ) {
						alert( ( g.strings.success || 'Success' ) + '\n\n' + r.data?.message );
						if ( r.data?.edit_url ) { window.location.href = r.data.edit_url; }
						else { window.location.reload(); }
					} else {
						alert( r.data?.message || g.strings.error || 'Error' );
						submitButton.prop( 'disabled', false ).text( originalText );
					}
				},
				error() { alert( g.strings.error || 'Error' ); submitButton.prop( 'disabled', false ).text( originalText ); },
			} as Record< string, unknown > );
		} );
	} );
}

$( document ).ready( () => { if ( getG() ) { initCreateTeamModal(); } } );
