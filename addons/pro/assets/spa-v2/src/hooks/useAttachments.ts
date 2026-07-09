/**
 * Pro SPA v2 — file attachment state hook.
 *
 * Manages pending File objects, validates them, and converts them
 * to AI SDK `Attachment` records (base64 data-URLs) ready for
 * `ChatRequestOptions.experimental_attachments`.
 *
 * Limits (matching NV oOS server-side constants):
 *   5 MB per file, 10 MB total, MIME allowlist for images/PDFs/text.
 *
 * Mirrors chat-spa's useAttachments with pro namespace.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { useCallback, useState } from 'react';
import { type Attachment } from '@ai-sdk/ui-utils';
import { __ } from '@wordpress/i18n';

export const ALLOWED_MIME_TYPES = [
	'image/jpeg', 'image/png', 'image/gif', 'image/webp',
	'application/pdf', 'text/plain', 'text/markdown', 'text/csv',
	'application/json',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/msword',
	'text/html', 'text/xml', 'application/xml',
	'application/zip', 'application/x-rar-compressed',
	'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm',
	'video/mp4', 'video/webm', 'video/ogg',
] as const;

/** Browser accept attribute — intentionally broad to allow any file the AI can process. */
export const ACCEPT_ATTR = '*/*';

const MAX_FILE_BYTES = 5 * 1024 * 1024;
const MAX_TOTAL_BYTES = 10 * 1024 * 1024;
const MAX_FILES = 10;

export interface PendingFile {
	key: string;
	file: File;
	previewUrl: string | null;
}

export interface UseAttachmentsReturn {
	files: PendingFile[];
	attachError: string | null;
	attach: ( list: FileList ) => string | null;
	remove: ( key: string ) => void;
	clear: () => void;
	toPendingAttachments: () => Promise< Attachment[] >;
}

function readAsDataURL( file: File ): Promise< string > {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( reader.result as string );
		reader.onerror = () => reject( new Error( `FileReader error: ${ file.name }` ) );
		reader.readAsDataURL( file );
	} );
}

function isImageMime( mime: string ) { return mime.startsWith( 'image/' ); }

let keyCounter = 0;
function nextKey() { return `pa-${ ++keyCounter }`; }

export function useAttachments(): UseAttachmentsReturn {
	const [ files, setFiles ] = useState< PendingFile[] >( [] );
	const [ attachError, setAttachError ] = useState< string | null >( null );

	const attach = useCallback( ( list: FileList ): string | null => {
		setAttachError( null );
		const incoming = Array.from( list );

		const badMime = incoming.find( ( f ) => ! ( ALLOWED_MIME_TYPES as readonly string[] ).includes( f.type ) );
		if ( badMime ) { const msg = `${ badMime.name }: ${ __( 'unsupported file type.', 'nvoos-pro-spa' ) }`; setAttachError( msg ); return msg; }

		const tooBig = incoming.find( ( f ) => f.size > MAX_FILE_BYTES );
		if ( tooBig ) { const msg = `${ tooBig.name }: ${ __( 'exceeds the 5 MB limit.', 'nvoos-pro-spa' ) }`; setAttachError( msg ); return msg; }

		setFiles( ( prev ) => {
			const combined = [ ...prev, ...incoming ];
			if ( combined.length > MAX_FILES ) { setAttachError( __( 'Maximum 10 attachments per message.', 'nvoos-pro-spa' ) ); return prev; }
			const totalSize = prev.reduce( ( a, pf ) => a + pf.file.size, 0 ) + incoming.reduce( ( a, f ) => a + f.size, 0 );
			if ( totalSize > MAX_TOTAL_BYTES ) { setAttachError( __( 'Attachments exceed the 10 MB total limit.', 'nvoos-pro-spa' ) ); return prev; }
			return [ ...prev, ...incoming.map( ( file ) => ( { key: nextKey(), file, previewUrl: isImageMime( file.type ) ? URL.createObjectURL( file ) : null } ) ) ];
		} );
		return null;
	}, [] );

	const remove = useCallback( ( key: string ) => {
		setFiles( ( prev ) => { const removed = prev.find( ( pf ) => pf.key === key ); if ( removed?.previewUrl ) URL.revokeObjectURL( removed.previewUrl ); return prev.filter( ( pf ) => pf.key !== key ); } );
	}, [] );

	const clear = useCallback( () => {
		setFiles( ( prev ) => { prev.forEach( ( pf ) => { if ( pf.previewUrl ) URL.revokeObjectURL( pf.previewUrl ); } ); return []; } );
	}, [] );

	const toPendingAttachments = useCallback( async () => {
		return Promise.all( files.map( async ( pf ) => ( { name: pf.file.name, contentType: pf.file.type || 'application/octet-stream', url: await readAsDataURL( pf.file ) } satisfies Attachment ) ) );
	}, [ files ] );

	return { files, attachError, attach, remove, clear, toPendingAttachments };
}
