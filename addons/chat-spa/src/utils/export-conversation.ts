/**
 * NV oOS Chat SPA — Conversation export utility.
 *
 * Serialises messages to JSON or Markdown and triggers a browser download.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import type { Message } from '@ai-sdk/react';

export type ExportFormat = 'json' | 'markdown';

function sanitiseMessages( messages: Message[] ): Array< { role: string; content: string } > {
	return messages
		.filter( ( m ) => typeof m.content === 'string' && m.content !== '' )
		.map( ( m ) => ( { role: m.role, content: m.content as string } ) );
}

function downloadFile( content: string, filename: string, mimeType: string ): void {
	const blob = new Blob( [ content ], { type: mimeType } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	document.body.removeChild( a );
	URL.revokeObjectURL( url );
}

export function exportConversation(
	messages: Message[],
	format: ExportFormat,
	assistantId: number | string,
	sessionKey: string
): void {
	const stamp = new Date().toISOString().replace( /[:.]/g, '-' ).slice( 0, 19 );

	if ( format === 'json' ) {
		const data = {
			assistant_id: assistantId || undefined,
			session_key: sessionKey || undefined,
			exported_at: new Date().toISOString(),
			messages: sanitiseMessages( messages ),
		};
		downloadFile(
			JSON.stringify( data, null, 2 ),
			`nv-oos-chat-${ stamp }.json`,
			'application/json'
		);
	} else {
		const lines: string[] = [
			`# ${ __( 'Chat Export', 'nvoos-chat-spa' ) }`,
			'',
			`_${ __( 'Exported on', 'nvoos-chat-spa' ) } ${ new Date().toLocaleString() }_`,
			'',
		];
		for ( const m of messages ) {
			const content = typeof m.content === 'string' ? m.content : '';
			if ( ! content ) continue;
			lines.push( `### ${ m.role }`, '', content, '' );
		}
		downloadFile(
			lines.join( '\n' ),
			`nv-oos-chat-${ stamp }.md`,
			'text/markdown; charset=utf-8'
		);
	}
}
