/**
 * NV oOS Document Editor — Tiptap rich-text editor canvas.
 *
 * Ships the full `editor` mode: a heading/paragraph/table/link-aware
 * Tiptap document editor with a minimal toolbar. Documents are saved to
 * and loaded from the `/nvoos-document-editor/v1/documents` REST endpoint.
 *
 * Toolbar actions: Bold · Italic · Strikethrough · Code · Link ·
 *   H1 / H2 / H3 · Bullet list · Ordered list · Blockquote ·
 *   Insert table · Undo · Redo
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';

interface EditorCanvasProps {
	toolkit?: string;
	documentId?: number;
}

interface DocumentRecord {
	id: number;
	title: string;
	content: string;
}

function getGlobal() {
	return window.NVOOS_DOCUMENT_EDITOR;
}

/**
 * Fetch one document by ID from the REST API.
 */
async function fetchDocument( id: number ): Promise<DocumentRecord | null> {
	const g = getGlobal();
	if ( ! g ) {
		return null;
	}
	const res = await fetch( `${ g.apiUrl }/documents/${ id }`, {
		headers: { 'X-WP-Nonce': g.nonce },
	} );
	if ( ! res.ok ) {
		return null;
	}
	return ( await res.json() ) as DocumentRecord;
}

/**
 * Persist editor content to the REST API.
 */
async function saveDocument(
	id: number,
	title: string,
	content: string
): Promise<void> {
	const g = getGlobal();
	if ( ! g ) {
		return;
	}
	await fetch( `${ g.apiUrl }/documents/${ id }`, {
		method: 'PUT',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': g.nonce,
		},
		body: JSON.stringify( { title, content } ),
	} );
}

/**
 * Toolbar button (plain button with accessible label).
 */
function ToolbarButton( {
	label,
	active,
	disabled,
	onClick,
}: {
	label: string;
	active?: boolean;
	disabled?: boolean;
	onClick: () => void;
} ) {
	return (
		<button
			type="button"
			aria-label={ label }
			aria-pressed={ active }
			disabled={ disabled }
			className={
				'nvoos-de-toolbar-btn' +
				( active ? ' nvoos-de-toolbar-btn--active' : '' )
			}
			onMouseDown={ ( e ) => {
				e.preventDefault(); // Keep editor focus.
				onClick();
			} }
		>
			{ label }
		</button>
	);
}

export function EditorCanvas( { toolkit, documentId }: EditorCanvasProps ) {
	const [ title, setTitle ] = useState( '' );
	const [ saveStatus, setSaveStatus ] = useState<'idle' | 'saving' | 'saved' | 'error'>(
		'idle'
	);

	const editor = useEditor( {
		extensions: [
			StarterKit,
			Link.configure( { openOnClick: false } ),
			Placeholder.configure( {
				placeholder: 'Start writing your document…',
			} ),
			Table.configure( { resizable: false } ),
			TableRow,
			TableHeader,
			TableCell,
		],
		content: '',
	} );

	// Load document on mount when a documentId is provided.
	useEffect( () => {
		if ( ! documentId || ! editor ) {
			return;
		}
		fetchDocument( documentId ).then( ( doc ) => {
			if ( doc ) {
				setTitle( doc.title );
				editor.commands.setContent( doc.content );
			}
		} );
	}, [ documentId, editor ] );

	const handleSave = useCallback( async () => {
		if ( ! editor || ! documentId ) {
			return;
		}
		setSaveStatus( 'saving' );
		try {
			await saveDocument( documentId, title, editor.getHTML() );
			setSaveStatus( 'saved' );
			setTimeout( () => setSaveStatus( 'idle' ), 2000 );
		} catch {
			setSaveStatus( 'error' );
		}
	}, [ editor, documentId, title ] );

	const setLink = useCallback( () => {
		if ( ! editor ) {
			return;
		}
		const previous = editor.getAttributes( 'link' ).href as string | undefined;
		const url = window.prompt( 'Enter URL', previous ?? 'https://' );
		if ( url === null ) {
			return;
		}
		if ( url === '' ) {
			editor.chain().focus().extendMarkRange( 'link' ).unsetLink().run();
			return;
		}
		editor
			.chain()
			.focus()
			.extendMarkRange( 'link' )
			.setLink( { href: url } )
			.run();
	}, [ editor ] );

	if ( ! editor ) {
		return <div className="nvoos-de-loading" role="status">{ __( 'Loading editor…', 'nvoos-document-editor' ) }</div>;
	}

	const isTable = editor.isActive( 'table' );

	return (
		<div className="nvoos-de-editor-canvas">
			{ /* Document title */ }
			<input
				type="text"
				className="nvoos-de-title-input"
				value={ title }
				onChange={ ( e ) => setTitle( e.target.value ) }
				placeholder={ __( 'Document title', 'nvoos-document-editor' ) }
				aria-label={ __( 'Document title', 'nvoos-document-editor' ) }
			/>

			{ /* Toolbar */ }
			<div className="nvoos-de-toolbar" role="toolbar" aria-label={ __( 'Editor toolbar', 'nvoos-document-editor' ) }>
				<ToolbarButton
					label={ __( 'Bold', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'bold' ) }
					onClick={ () => editor.chain().focus().toggleBold().run() }
				/>
				<ToolbarButton
					label={ __( 'Italic', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'italic' ) }
					onClick={ () => editor.chain().focus().toggleItalic().run() }
				/>
				<ToolbarButton
					label={ __( 'Strike', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'strike' ) }
					onClick={ () => editor.chain().focus().toggleStrike().run() }
				/>
				<ToolbarButton
					label={ __( 'Code', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'code' ) }
					onClick={ () => editor.chain().focus().toggleCode().run() }
				/>
				<ToolbarButton
					label={ __( 'Link', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'link' ) }
					onClick={ setLink }
				/>
				<span className="nvoos-de-toolbar-sep" aria-hidden="true" />
				<ToolbarButton
					label="H1"
					active={ editor.isActive( 'heading', { level: 1 } ) }
					onClick={ () =>
						editor.chain().focus().toggleHeading( { level: 1 } ).run()
					}
				/>
				<ToolbarButton
					label="H2"
					active={ editor.isActive( 'heading', { level: 2 } ) }
					onClick={ () =>
						editor.chain().focus().toggleHeading( { level: 2 } ).run()
					}
				/>
				<ToolbarButton
					label="H3"
					active={ editor.isActive( 'heading', { level: 3 } ) }
					onClick={ () =>
						editor.chain().focus().toggleHeading( { level: 3 } ).run()
					}
				/>
				<span className="nvoos-de-toolbar-sep" aria-hidden="true" />
				<ToolbarButton
					label={ __( 'Bullets', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'bulletList' ) }
					onClick={ () => editor.chain().focus().toggleBulletList().run() }
				/>
				<ToolbarButton
					label={ __( 'Numbered', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'orderedList' ) }
					onClick={ () =>
						editor.chain().focus().toggleOrderedList().run()
					}
				/>
				<ToolbarButton
					label={ __( 'Quote', 'nvoos-document-editor' ) }
					active={ editor.isActive( 'blockquote' ) }
					onClick={ () =>
						editor.chain().focus().toggleBlockquote().run()
					}
				/>
				<span className="nvoos-de-toolbar-sep" aria-hidden="true" />
				<ToolbarButton
					label={ isTable ? 'Del table' : 'Table' }
					active={ isTable }
					onClick={ () => {
						if ( isTable ) {
							editor.chain().focus().deleteTable().run();
						} else {
							editor
								.chain()
								.focus()
								.insertTable( { rows: 3, cols: 3, withHeaderRow: true } )
								.run();
						}
					} }
				/>
				<span className="nvoos-de-toolbar-sep" aria-hidden="true" />
				<ToolbarButton
					label={ __( 'Undo', 'nvoos-document-editor' ) }
					disabled={ ! editor.can().undo() }
					onClick={ () => editor.chain().focus().undo().run() }
				/>
				<ToolbarButton
					label={ __( 'Redo', 'nvoos-document-editor' ) }
					disabled={ ! editor.can().redo() }
					onClick={ () => editor.chain().focus().redo().run() }
				/>
			</div>

			{ /* Editable content area */ }
			<EditorContent className="nvoos-de-content" editor={ editor } />

			{ /* Footer: toolkit label + save button */ }
			<footer className="nvoos-de-footer">
				{ toolkit ? (
					<span className="nvoos-de-footer-toolkit">{ toolkit }</span>
				) : null }
				{ documentId ? (
					<button
						type="button"
						className={ 'nvoos-de-save-btn nvoos-de-save-btn--' + saveStatus }
						onClick={ handleSave }
						disabled={ saveStatus === 'saving' }
					>
						{ saveStatus === 'saving'
							? __( 'Saving…', 'nvoos-document-editor' )
							: saveStatus === 'saved'
							? __( 'Saved ✓', 'nvoos-document-editor' )
							: saveStatus === 'error'
							? __( 'Error — retry', 'nvoos-document-editor' )
							: __( 'Save', 'nvoos-document-editor' ) }
					</button>
				) : null }
			</footer>
		</div>
	);
}
