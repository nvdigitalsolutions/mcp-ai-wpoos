/**
 * Pro SPA v2 — Conversation export utility.
 * @package NV_oOS_Pro_Spa @since 0.9.0
 */

import { __ } from '@wordpress/i18n';
import type { Message } from '@ai-sdk/react';

export type ExportFormat = 'json' | 'markdown';

function downloadFile( content: string, filename: string, mimeType: string ): void {
	const blob = new Blob( [ content ], { type: mimeType } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' ); a.href = url; a.download = filename;
	document.body.appendChild( a ); a.click(); document.body.removeChild( a ); URL.revokeObjectURL( url );
}

export function exportConversation( messages: Message[], format: ExportFormat, assistantId: number | string, sessionKey: string ): void {
	const stamp = new Date().toISOString().replace( /[:.]/g, '-' ).slice( 0, 19 );
	if ( format === 'json' ) {
		const data = {
			assistant_id: assistantId || undefined, session_key: sessionKey || undefined,
			exported_at: new Date().toISOString(),
			messages: messages.filter( ( m ) => typeof m.content === 'string' ).map( ( m ) => ( { role: m.role, content: m.content as string } ) ),
		};
		downloadFile( JSON.stringify( data, null, 2 ), `nv-oos-chat-${ stamp }.json`, 'application/json' );
	} else {
		const lines = [ `# ${ __( 'Chat Export', 'nvoos-pro-spa' ) }`, '', `_${ new Date().toLocaleString() }_`, '' ];
		for ( const m of messages ) {
			const c = typeof m.content === 'string' ? m.content : '';
			if ( c ) lines.push( `### ${ m.role }`, '', c, '' );
		}
		downloadFile( lines.join( '\n' ), `nv-oos-chat-${ stamp }.md`, 'text/markdown; charset=utf-8' );
	}
}
