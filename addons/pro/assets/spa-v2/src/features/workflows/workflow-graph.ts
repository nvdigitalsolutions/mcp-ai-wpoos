/**
 * Workflow graph model — catalog, converters, validation (Phase 3b W10.2b).
 *
 * The REST graph stores plain node entries { id, type: tool|action|agent,
 * label, ... } (Engine V2 vocabulary — see
 * class-wp-mcp-ai-workflow-engine-v2.php). The canvas maps them onto
 * @xyflow/react nodes typed `toolNode`/`actionNode`/`agentNode`. Pure
 * functions here keep the mapping and validation unit-testable without a
 * browser.
 */

import type { Edge, Node } from '@xyflow/react';
import type { WorkflowGraph, WorkflowGraphNode, WorkflowGraphEdge } from '../../api/workflow-builder';

/** Engine V2 node vocabulary (defaults to tool). */
export const WORKFLOW_NODE_TYPES = [ 'tool', 'action', 'agent' ] as const;
export type WorkflowNodeType = ( typeof WORKFLOW_NODE_TYPES )[ number ];

/** Canvas node type keys (xyflow). */
export type CanvasNodeType = 'toolNode' | 'actionNode' | 'agentNode';

export const NODE_TYPE_LABELS: Record< WorkflowNodeType, string > = {
	tool: 'Tool',
	action: 'Action',
	agent: 'Agent',
};

/** Node data must be a type alias (xyflow Record constraint). */
export type WorkflowNodeData = {
	label: string;
	description?: string;
	/** Preserved stored type outside the execution trio (e.g. input/output). */
	rawType?: string;
};

export type CanvasWorkflowNode = Node< WorkflowNodeData, CanvasNodeType >;

const CANVAS_TYPE_FOR: Record< WorkflowNodeType, CanvasNodeType > = {
	tool: 'toolNode',
	action: 'actionNode',
	agent: 'agentNode',
};

const GRAPH_TYPE_FOR: Record< CanvasNodeType, WorkflowNodeType > = {
	toolNode: 'tool',
	actionNode: 'action',
	agentNode: 'agent',
};

export function isWorkflowNodeType( value: unknown ): value is WorkflowNodeType {
	return (
		typeof value === 'string' &&
		( WORKFLOW_NODE_TYPES as readonly string[] ).includes( value )
	);
}

/**
 * Map a stored graph node onto a canvas node. Preset graphs may carry node
 * types outside the execution trio (input/output/condition, report kinds) —
 * those render as generic tool nodes and keep their original type in
 * data.rawType so saving round-trips them untouched. Stored positions are
 * honoured when present; otherwise nodes stack loosely.
 */
export function toCanvasNode( node: WorkflowGraphNode, index: number ): CanvasWorkflowNode {
	const rawType = node.type;
	const graphType: WorkflowNodeType = isWorkflowNodeType( rawType ) ? rawType : 'tool';
	const rawLabel = typeof node.label === 'string' && node.label ? node.label : '';
	const rawDescription =
		typeof node.description === 'string' ? node.description : undefined;
	const position =
		node.position &&
		typeof node.position === 'object' &&
		typeof ( node.position as { x?: unknown } ).x === 'number' &&
		typeof ( node.position as { y?: unknown } ).y === 'number'
			? {
					x: ( node.position as { x: number } ).x,
					y: ( node.position as { y: number } ).y,
			  }
			: { x: 40 + ( index % 4 ) * 260, y: 40 + Math.floor( index / 4 ) * 140 };

	return {
		id: String( node.id ),
		type: CANVAS_TYPE_FOR[ graphType ],
		position,
		data: {
			label: rawLabel || NODE_TYPE_LABELS[ graphType ],
			description: rawDescription,
			...( typeof rawType === 'string' && ! isWorkflowNodeType( rawType )
				? { rawType }
				: {} ),
		},
	};
}

/** Map a canvas node back onto the REST graph shape. */
export function toGraphNode( node: CanvasWorkflowNode ): WorkflowGraphNode {
	const canvasType: CanvasNodeType = ( [ 'toolNode', 'actionNode', 'agentNode' ] as string[] ).includes(
		node.type ?? ''
	)
		? ( node.type as CanvasNodeType )
		: 'toolNode';

	return {
		id: node.id,
		type: node.data?.rawType ?? GRAPH_TYPE_FOR[ canvasType ],
		label: node.data?.label ?? NODE_TYPE_LABELS[ GRAPH_TYPE_FOR[ canvasType ] ],
		...( node.data?.description ? { description: node.data.description } : {} ),
	};
}

export function toCanvasNodes( nodes: WorkflowGraphNode[] ): CanvasWorkflowNode[] {
	return nodes.map( toCanvasNode );
}

export function toGraphEdges( edges: WorkflowGraphEdge[] ): Edge[] {
	return edges
		.filter(
			( edge ): edge is WorkflowGraphEdge & { source: string; target: string } =>
				typeof edge.source === 'string' &&
				edge.source.length > 0 &&
				typeof edge.target === 'string' &&
				edge.target.length > 0
		)
		.map( ( edge, index ) => ( {
			id:
				typeof edge.id === 'string' && edge.id
					? edge.id
					: `edge_${ index }_${ edge.source }_${ edge.target }`,
			source: edge.source,
			target: edge.target,
		} ) );
}

export interface GraphValidation {
	valid: boolean;
	errors: string[];
}

/**
 * Validate a graph before it goes to the REST PUT: every node carries a
 * catalog type, ids are unique, and every edge references existing nodes.
 */
export function validateGraph(
	nodes: CanvasWorkflowNode[],
	edges: WorkflowGraphEdge[]
): GraphValidation {
	const errors: string[] = [];
	const ids = new Set< string >();

	nodes.forEach( ( node ) => {
		if ( ! node.id ) {
			errors.push( 'Every node needs an id.' );
			return;
		}
		if ( ids.has( node.id ) ) {
			errors.push( `Duplicate node id: ${ node.id }` );
		}
		ids.add( node.id );

		const type = node.type as string;
		if ( ! [ 'toolNode', 'actionNode', 'agentNode' ].includes( type ) ) {
			errors.push( `Node "${ node.id }" has an unsupported type.` );
		}
	} );

	edges.forEach( ( edge, index ) => {
		if ( typeof edge.source !== 'string' || ! ids.has( edge.source ) ) {
			errors.push( `Edge #${ index + 1 } references a missing source node.` );
		}
		if ( typeof edge.target !== 'string' || ! ids.has( edge.target ) ) {
			errors.push( `Edge #${ index + 1 } references a missing target node.` );
		}
	} );

	return { valid: errors.length === 0, errors };
}
