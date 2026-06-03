/**
 * NV oOS Document Editor — root component.
 *
 * Dispatches to the requested editor mode:
 *   - `editor` (default) — full Tiptap rich-text document editor.
 *   - `site-creator`     — Coming soon (Tiptap + GrapesJS).
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import { EditorCanvas } from './components/EditorCanvas';
import { SiteCreatorCanvas } from './components/SiteCreatorCanvas';

export type EditorMode = 'editor' | 'site-creator';

interface AppProps {
	config: {
		toolkit?: string;
		theme?: string;
		view?: string;
		height?: string;
		mode?: EditorMode;
		document_id?: number;
	};
}

const MODE_LABELS: Record<EditorMode, string> = {
	editor: __( 'Rich-text document editor', 'nvoos-document-editor' ),
	'site-creator': __( 'Site creator (Tiptap + GrapesJS)', 'nvoos-document-editor' ),
};

export function App( { config }: AppProps ) {
	const mode: EditorMode =
		config.mode && config.mode in MODE_LABELS ? config.mode : 'editor';
	const heightStyle = config.height ? { height: config.height } : undefined;

	let surface: ReactElement;
	switch ( mode ) {
		case 'editor':
			surface = (
				<EditorCanvas
					toolkit={ config.toolkit }
					documentId={ config.document_id }
				/>
			);
			break;
		case 'site-creator':
		default:
			surface = (
				<SiteCreatorCanvas documentId={ config.document_id } />
			);
	}

	return (
		<div
			className="nvoos-document-editor-app"
			data-theme={ config.theme ?? 'auto' }
			data-mode={ mode }
			style={ heightStyle }
		>
			<a className="nvoos-skip-link" href="#nvoos-doc-editor-main-content">
				{ __( 'Skip to main content', 'nvoos-document-editor' ) }
			</a>
			<div id="nvoos-doc-editor-main-content" tabIndex={ -1 }>
				{ surface }
			</div>
		</div>
	);
}

