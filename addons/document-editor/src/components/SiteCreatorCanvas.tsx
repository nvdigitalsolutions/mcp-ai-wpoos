/**
 * SiteCreatorCanvas — GrapesJS-powered visual page builder.
 *
 * Mounts a GrapesJS editor (`@grapesjs/react` + `grapesjs`) inside the
 * document-editor SPA. Project data is persisted to `localStorage` keyed by
 * the `documentId` prop so the page layout survives hard refreshes.
 *
 * @since 0.2.0
 */

import { __ } from '@wordpress/i18n';
import grapesjs from 'grapesjs';
import Editor from '@grapesjs/react';
import type { Editor as GjsEditor } from 'grapesjs';
import { useCallback, useRef } from 'react';

// Self-host the GrapesJS stylesheet via esbuild CSS loader.
import 'grapesjs/dist/css/grapes.min.css';

const STORAGE_KEY_PREFIX = 'nvoos_site_creator_';

interface SiteCreatorCanvasProps {
	documentId?: number;
}

/**
 * Minimal built-in block definitions: a header row, a text paragraph,
 * and a two-column layout — just enough to make the editor immediately useful.
 */
function addBuiltInBlocks( editor: GjsEditor ) {
	editor.Blocks.add( 'header', {
		label: __( 'Header', 'nvoos-document-editor' ),
		category: __( 'Basic', 'nvoos-document-editor' ),
		content: '<header class="gjs-header"><h1>' + __( 'Page Title', 'nvoos-document-editor' ) + '</h1></header>',
		media: '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="6" rx="1"/></svg>',
	} );

	editor.Blocks.add( 'text', {
		label: __( 'Text', 'nvoos-document-editor' ),
		category: __( 'Basic', 'nvoos-document-editor' ),
		content: '<p>' + __( 'Insert your text here.', 'nvoos-document-editor' ) + '</p>',
		media: '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="1"/><line x1="5" y1="9" x2="19" y2="9" stroke-width="1.5"/><line x1="5" y1="13" x2="19" y2="13" stroke-width="1.5"/></svg>',
	} );

	editor.Blocks.add( 'two-columns', {
		label: __( 'Two Columns', 'nvoos-document-editor' ),
		category: __( 'Layout', 'nvoos-document-editor' ),
		content: '<div class="gjs-row" style="display:flex;gap:16px"><div class="gjs-col" style="flex:1;padding:8px">' + __( 'Column 1', 'nvoos-document-editor' ) + '</div><div class="gjs-col" style="flex:1;padding:8px">' + __( 'Column 2', 'nvoos-document-editor' ) + '</div></div>',
		media: '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="9" height="16" rx="1"/><rect x="13" y="4" width="9" height="16" rx="1"/></svg>',
	} );
}

export function SiteCreatorCanvas( { documentId }: SiteCreatorCanvasProps ) {
	const storageKey = STORAGE_KEY_PREFIX + ( documentId ?? 'default' );
	const editorRef  = useRef<GjsEditor | null>( null );

	const handleEditor = useCallback( ( editor: GjsEditor ) => {
		editorRef.current = editor;
		addBuiltInBlocks( editor );
	}, [] );

	const handleReady = useCallback( ( editor: GjsEditor ) => {
		// Load previously saved project data from localStorage.
		try {
			const saved = localStorage.getItem( storageKey );
			if ( saved ) {
				editor.loadProjectData( JSON.parse( saved ) );
			}
		} catch {
			// Ignore parse errors — start with a blank canvas.
		}
	}, [ storageKey ] );

	const handleUpdate = useCallback( () => {
		const editor = editorRef.current;
		if ( ! editor ) {
			return;
		}
		try {
			localStorage.setItem( storageKey, JSON.stringify( editor.getProjectData() ) );
		} catch {
			// Ignore QuotaExceededError silently.
		}
	}, [ storageKey ] );

	return (
		<div
			className="nvoos-site-creator-wrap"
			style={ { height: '100%', display: 'flex', flexDirection: 'column' } }
		>
			<div className="nvoos-site-creator-toolbar" style={ { padding: '4px 8px', borderBottom: '1px solid #ddd', fontSize: 13 } }>
				<span>{ __( 'Site Creator', 'nvoos-document-editor' ) }</span>
			</div>
			<div style={ { flex: 1, overflow: 'hidden' } }>
				<Editor
					grapesjs={ grapesjs }
					options={ {
						height:          '100%',
						storageManager:  false,   // we handle persistence ourselves
						deviceManager:   { devices: [
							{ id: 'desktop', name: __( 'Desktop', 'nvoos-document-editor' ), width: '' },
							{ id: 'tablet',  name: __( 'Tablet', 'nvoos-document-editor' ),  width: '768px' },
							{ id: 'mobile',  name: __( 'Mobile', 'nvoos-document-editor' ),  width: '375px' },
						] },
						blockManager:    {},
						panels:          { defaults: [] },
					} }
					onEditor={ handleEditor }
					onReady={ handleReady }
					onUpdate={ handleUpdate }
					style={ { height: '100%' } }
				/>
			</div>
		</div>
	);
}
