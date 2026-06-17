/**
 * NV oOS Canvas Toolkit — Flow (node-graph) canvas.
 *
 * Built on @xyflow/react (MIT) — an interactive, panable, zoomable
 * node-and-edge surface suitable for the `ai-tool-builder` toolkit and
 * any other workflow / DAG / pipeline visualisation.
 *
 * The component is intentionally stateless and uncontrolled at this stage:
 * it seeds an example graph from a few hard-coded nodes so authors can see a
 * working surface immediately. Persistence, REST sync, and edit mutations
 * are added in follow-up PRs.
 *
 * @since 0.1.0
 */

import { useCallback, useState } from 'react';
import {
	ReactFlow,
	Background,
	Controls,
	MiniMap,
	addEdge,
	applyEdgeChanges,
	applyNodeChanges,
	type Node,
	type Edge,
	type Connection,
	type NodeChange,
	type EdgeChange,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';

interface FlowCanvasProps {
	toolkit?: string;
}

const INITIAL_NODES: Node[] = [
	{ id: '1', position: { x: 40, y: 40 },  data: { label: 'Trigger' },        type: 'input'  },
	{ id: '2', position: { x: 260, y: 40 }, data: { label: 'Tool: search' } },
	{ id: '3', position: { x: 480, y: 40 }, data: { label: 'Tool: summarise' } },
	{ id: '4', position: { x: 700, y: 40 }, data: { label: 'Output' },         type: 'output' },
];

const INITIAL_EDGES: Edge[] = [
	{ id: 'e1-2', source: '1', target: '2' },
	{ id: 'e2-3', source: '2', target: '3' },
	{ id: 'e3-4', source: '3', target: '4' },
];

export function FlowCanvas( { toolkit }: FlowCanvasProps ) {
	const [ nodes, setNodes ] = useState<Node[]>( INITIAL_NODES );
	const [ edges, setEdges ] = useState<Edge[]>( INITIAL_EDGES );

	const onNodesChange = useCallback(
		( changes: NodeChange[] ) => setNodes( ( current ) => applyNodeChanges( changes, current ) ),
		[]
	);
	const onEdgesChange = useCallback(
		( changes: EdgeChange[] ) => setEdges( ( current ) => applyEdgeChanges( changes, current ) ),
		[]
	);
	const onConnect = useCallback(
		( connection: Connection ) => setEdges( ( current ) => addEdge( connection, current ) ),
		[]
	);

	return (
		<div className="nvoos-canvas-toolkit-flow" role="application" aria-label="Canvas flow editor">
			<header className="nvoos-canvas-toolkit-flow-header">
				<strong>Flow</strong>
				{ toolkit ? (
					<span className="nvoos-canvas-toolkit-flow-toolkit">{ toolkit }</span>
				) : null }
			</header>
			<div className="nvoos-canvas-toolkit-flow-surface">
				<ReactFlow
					nodes={ nodes }
					edges={ edges }
					onNodesChange={ onNodesChange }
					onEdgesChange={ onEdgesChange }
					onConnect={ onConnect }
					fitView
				>
					<Background />
					<Controls />
					<MiniMap pannable zoomable />
				</ReactFlow>
			</div>
		</div>
	);
}
