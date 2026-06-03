/**
 * NV oOS Canvas Toolkit — root component.
 *
 * Dispatches to the requested canvas mode. Each mode is implemented as a
 * separate component under `./components/`:
 *
 * - `flow`       — @xyflow/react node-graph (FlowCanvas)
 * - `whiteboard` — tldraw v5 freehand whiteboard (WhiteboardCanvas)
 * - `bpmn`       — bpmn-js BPMN 2.0 viewer/editor (BpmnCanvas)
 * - `mermaid`    — Mermaid live-preview (MermaidCanvas)
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';
import { FlowCanvas }       from './components/FlowCanvas';
import { WhiteboardCanvas } from './components/WhiteboardCanvas';
import { BpmnCanvas }       from './components/BpmnCanvas';
import { MermaidCanvas }    from './components/MermaidCanvas';
import type { ReactElement } from 'react';

export type CanvasMode = 'flow' | 'whiteboard' | 'bpmn' | 'mermaid';

interface AppProps {
	config: {
		toolkit?: string;
		theme?: string;
		view?: string;
		height?: string;
		mode?: CanvasMode;
	};
}

const MODE_LABELS: Record<CanvasMode, string> = {
	flow: __( 'Flow (node graph)', 'nvoos-canvas-toolkit' ),
	whiteboard: __( 'Whiteboard', 'nvoos-canvas-toolkit' ),
	bpmn: __( 'BPMN diagram', 'nvoos-canvas-toolkit' ),
	mermaid: __( 'Mermaid live preview', 'nvoos-canvas-toolkit' ),
};

export function App( { config }: AppProps ) {
	const mode: CanvasMode = config.mode && config.mode in MODE_LABELS ? config.mode : 'flow';
	const heightStyle = config.height ? { height: config.height } : undefined;

	let surface: ReactElement;
	switch ( mode ) {
		case 'flow':
			surface = <FlowCanvas toolkit={ config.toolkit } />;
			break;
		case 'whiteboard':
			surface = <WhiteboardCanvas toolkit={ config.toolkit } />;
			break;
		case 'bpmn':
			surface = <BpmnCanvas toolkit={ config.toolkit } />;
			break;
		case 'mermaid':
			surface = <MermaidCanvas toolkit={ config.toolkit } />;
			break;
	}

	return (
		<div
			className="nvoos-canvas-toolkit-app"
			data-theme={ config.theme ?? 'auto' }
			data-mode={ mode }
			style={ heightStyle }
		>
			<a className="nvoos-skip-link" href="#nvoos-canvas-main-content">
				{ __( 'Skip to main content', 'nvoos-canvas-toolkit' ) }
			</a>
			<div id="nvoos-canvas-main-content" tabIndex={ -1 }>
				{ surface }
			</div>
		</div>
	);
}

