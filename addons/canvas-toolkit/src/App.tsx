/**
 * NV oOS Canvas Toolkit — root component.
 *
 * Dispatches to the requested canvas mode. Each mode is implemented as a
 * separate component under `./components/`. Modes that aren't shipped yet
 * render a friendly "Coming soon" stub.
 *
 * @since 0.1.0
 */

import { FlowCanvas } from './components/FlowCanvas';
import { ComingSoon } from './components/ComingSoon';
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
	flow: 'Flow (node graph)',
	whiteboard: 'Whiteboard',
	bpmn: 'BPMN diagram',
	mermaid: 'Mermaid live preview',
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
			surface = (
				<ComingSoon
					mode="whiteboard"
					label={ MODE_LABELS.whiteboard }
					note="The tldraw-based whiteboard mode ships in a follow-up PR."
				/>
			);
			break;
		case 'bpmn':
			surface = (
				<ComingSoon
					mode="bpmn"
					label={ MODE_LABELS.bpmn }
					note="The bpmn-js BPMN mode ships in a follow-up PR."
				/>
			);
			break;
		case 'mermaid':
			surface = (
				<ComingSoon
					mode="mermaid"
					label={ MODE_LABELS.mermaid }
					note="The Mermaid live-preview mode ships in a follow-up PR."
				/>
			);
			break;
	}

	return (
		<div
			className="nvoos-canvas-toolkit-app"
			data-theme={ config.theme ?? 'auto' }
			data-mode={ mode }
			style={ heightStyle }
		>
			{ surface }
		</div>
	);
}

