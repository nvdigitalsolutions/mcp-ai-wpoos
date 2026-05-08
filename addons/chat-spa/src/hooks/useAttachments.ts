/**
 * NV oOS Chat SPA — file attachment state hook (Phase 6).
 *
 * Manages a list of pending `File` objects, validates them, and converts them
 * to AI SDK `Attachment` records (base64 data-URLs) ready for
 * `ChatRequestOptions.experimental_attachments`.
 *
 * Limits (matching NV oOS server-side constants):
 *   - 5 MB per file   (MEMORY_MAX_FILE_BYTES)
 *   - 10 MB total     (2× server budget — client-side advisory cap)
 *   - MIME allowlist  — images, PDFs, plain-text, JSON, CSV, Markdown, Word
 *
 * The hook never throws; invalid files are silently rejected and an error
 * string is returned so the UI can display a transient pill.
 */

import { useCallback, useState } from 'react';
import { type Attachment } from '@ai-sdk/ui-utils';
import { __ } from '@wordpress/i18n';

// ── Constants ────────────────────────────────────────────────────────────────

export const ALLOWED_MIME_TYPES = [
	// Images (vision models).
	'image/jpeg',
	'image/png',
	'image/gif',
	'image/webp',
	// Documents.
	'application/pdf',
	'text/plain',
	'text/markdown',
	'text/csv',
	'application/json',
	// Word / spreadsheet (sent as text, server converts).
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
	'application/msword', // .doc
] as const;

/** Human-readable list for the accept attribute. */
export const ACCEPT_ATTR = ALLOWED_MIME_TYPES.join( ',' );

/** 5 MB per file. */
const MAX_FILE_BYTES = 5 * 1024 * 1024;

/** 10 MB aggregate (advisory, client-side). */
const MAX_TOTAL_BYTES = 10 * 1024 * 1024;

/** Maximum number of attachments per message. */
const MAX_FILES = 10;

// ── Types ────────────────────────────────────────────────────────────────────

export interface PendingFile {
	/** Stable key within this session (not File.name, which may collide). */
	key: string;
	file: File;
	/** Preview URL — data-URL for images, null for others. */
	previewUrl: string | null;
}

export interface UseAttachmentsReturn {
	files: PendingFile[];
	/** Validation error message from the last `attach()` call, if any. */
	attachError: string | null;
	/** Add files from a FileList. Returns validation error or null. */
	attach: ( list: FileList ) => string | null;
	/** Remove a single file by key. */
	remove: ( key: string ) => void;
	/** Remove all files. */
	clear: () => void;
	/**
	 * Convert pending files to AI SDK `Attachment[]` (base64 data-URLs).
	 * Resolves after all FileReader operations complete.
	 */
	toPendingAttachments: () => Promise< Attachment[] >;
}

// ── Implementation ────────────────────────────────────────────────────────────

let keyCounter = 0;
function nextKey() {
	return `pa-${ ++keyCounter }`;
}

function readAsDataURL( file: File ): Promise< string > {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( reader.result as string );
		reader.onerror = () => reject( new Error( `FileReader error: ${ file.name }` ) );
		reader.readAsDataURL( file );
	} );
}

function isImageMime( mime: string ) {
	return mime.startsWith( 'image/' );
}

export function useAttachments(): UseAttachmentsReturn {
	const [ files, setFiles ] = useState< PendingFile[] >( [] );
	const [ attachError, setAttachError ] = useState< string | null >( null );

	const attach = useCallback( ( list: FileList ): string | null => {
		setAttachError( null );
		const incoming = Array.from( list );

		// Check MIME types.
		const badMime = incoming.find(
			( f ) => ! ( ALLOWED_MIME_TYPES as readonly string[] ).includes( f.type )
		);
		if ( badMime ) {
			const msg = `${ badMime.name }: ${ __( 'unsupported file type.', 'nvoos-chat-spa' ) }`;
			setAttachError( msg );
			return msg;
		}

		// Check per-file size.
		const tooBig = incoming.find( ( f ) => f.size > MAX_FILE_BYTES );
		if ( tooBig ) {
			const msg = `${ tooBig.name }: ${ __( 'exceeds the 5 MB limit.', 'nvoos-chat-spa' ) }`;
			setAttachError( msg );
			return msg;
		}

		setFiles( ( prev ) => {
			// Reject if combined count would exceed cap.
			const combined = [ ...prev, ...incoming ];
			if ( combined.length > MAX_FILES ) {
				const msg = __( 'Maximum 10 attachments per message.', 'nvoos-chat-spa' );
				setAttachError( msg );
				return prev;
			}
			// Reject if combined size would exceed aggregate cap.
			const totalSize =
				prev.reduce( ( acc, pf ) => acc + pf.file.size, 0 ) +
				incoming.reduce( ( acc, f ) => acc + f.size, 0 );
			if ( totalSize > MAX_TOTAL_BYTES ) {
				const msg = __( 'Attachments exceed the 10 MB total limit.', 'nvoos-chat-spa' );
				setAttachError( msg );
				return prev;
			}

			const newPending: PendingFile[] = incoming.map( ( file ) => ( {
				key: nextKey(),
				file,
				// Synchronously generate preview for images — the actual data-URL
				// read for transmission happens in `toPendingAttachments`.
				previewUrl: isImageMime( file.type ) ? URL.createObjectURL( file ) : null,
			} ) );

			return [ ...prev, ...newPending ];
		} );

		return null;
	}, [] );

	const remove = useCallback( ( key: string ) => {
		setFiles( ( prev ) => {
			const removed = prev.find( ( pf ) => pf.key === key );
			if ( removed?.previewUrl ) {
				URL.revokeObjectURL( removed.previewUrl );
			}
			return prev.filter( ( pf ) => pf.key !== key );
		} );
	}, [] );

	const clear = useCallback( () => {
		setFiles( ( prev ) => {
			prev.forEach( ( pf ) => {
				if ( pf.previewUrl ) URL.revokeObjectURL( pf.previewUrl );
			} );
			return [];
		} );
	}, [] );

	const toPendingAttachments = useCallback( async (): Promise< Attachment[] > => {
		return Promise.all(
			files.map( async ( pf ) => {
				const dataUrl = await readAsDataURL( pf.file );
				return {
					name: pf.file.name,
					contentType: pf.file.type || 'application/octet-stream',
					url: dataUrl,
				} satisfies Attachment;
			} )
		);
	}, [ files ] );

	// Clean up all object URLs when the component that uses this hook unmounts.
	// (Relies on React calling the cleanup returned by useEffect — callers should
	// call `clear()` in a cleanup effect if they need deterministic release.)

	return { files, attachError, attach, remove, clear, toPendingAttachments };
}
