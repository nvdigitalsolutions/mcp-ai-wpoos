/**
 * NV oOS Canvas Toolkit — Whiteboard canvas (tldraw v5).
 *
 * Provides a freehand whiteboard surface backed by tldraw (MIT). The canvas
 * persists its state to the browser's IndexedDB automatically via tldraw's
 * built-in persistence layer; no server-side REST sync is required for the
 * initial implementation.
 *
 * @link    https://github.com/tldraw/tldraw
 * @credit  tldraw by tldraw Inc. (MIT)
 * @since   0.2.0
 */

import { __ } from '@wordpress/i18n';
import { Tldraw } from 'tldraw';
import 'tldraw/tldraw.css';

interface WhiteboardCanvasProps {
	/** Optional toolkit slug forwarded from the shortcode. */
	toolkit?: string;
}

export function WhiteboardCanvas( { toolkit }: WhiteboardCanvasProps ) {
	return (
		<div className="nvoos-canvas-toolkit-whiteboard" role="application" aria-label={ __( 'Whiteboard canvas', 'nvoos-canvas-toolkit' ) }>
			<header className="nvoos-canvas-toolkit-whiteboard-header">
				<strong>{ __( 'Whiteboard', 'nvoos-canvas-toolkit' ) }</strong>
				{ toolkit ? (
					<span className="nvoos-canvas-toolkit-whiteboard-toolkit">{ toolkit }</span>
				) : null }
			</header>
			<div className="nvoos-canvas-toolkit-whiteboard-surface">
				<Tldraw persistenceKey={ `nvoos-canvas-whiteboard${ toolkit ? `-${ toolkit }` : '' }` } />
			</div>
		</div>
	);
}
