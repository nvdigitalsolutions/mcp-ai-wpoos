/**
 * Workflow graph model + node component tests (Phase 3b W10.2b).
 *
 * The converters and validator are pure — tested directly. The node
 * components need ReactFlowProvider ancestry for <Handle/>; NodeProps is
 * satisfied through a typed factory (see the spa-ui skill).
 */

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReactFlowProvider, type NodeProps } from '@xyflow/react';
import type { JSX } from 'react';

import {
	NODE_TYPE_LABELS,
	toCanvasNode,
	toCanvasNodes,
	toGraphEdges,
	toGraphNode,
	validateGraph,
	type CanvasWorkflowNode,
} from '../features/workflows/workflow-graph';
import { ActionNode, AgentNode, ToolNode } from '../features/workflows/WorkflowNode';

function nodeProps( overrides: Partial< CanvasWorkflowNode > = {} ): NodeProps< CanvasWorkflowNode > {
	const node: CanvasWorkflowNode = {
		id: 'n1',
		type: 'toolNode',
		position: { x: 0, y: 0 },
		data: { label: 'Web search' },
		...overrides,
	};
	return {
		id: node.id,
		type: node.type,
		data: node.data,
		selected: false,
		dragging: false,
		zIndex: 0,
		isConnectable: true,
		positionAbsoluteX: 0,
		positionAbsoluteY: 0,
	} as unknown as NodeProps< CanvasWorkflowNode >;
}

describe( 'workflow-graph converters', () => {
	it( 'maps stored nodes onto canvas nodes with catalog types', () => {
		const canvas = toCanvasNode( { id: 'a', type: 'agent', label: 'Planner' }, 0 );
		expect( canvas.type ).toBe( 'agentNode' );
		expect( canvas.data.label ).toBe( 'Planner' );

		const unknown = toCanvasNode( { id: 'b', type: 'mystery' }, 1 );
		expect( unknown.type ).toBe( 'toolNode' );
		expect( unknown.data.label ).toBe( NODE_TYPE_LABELS.tool );
	} );

	it( 'round-trips canvas nodes back to the REST graph shape', () => {
		const canvas = toCanvasNodes( [ { id: 'a', type: 'action', label: 'Notify' } ] )[ 0 ];
		const graphNode = toGraphNode( canvas );
		expect( graphNode ).toMatchObject( { id: 'a', type: 'action', label: 'Notify' } );
	} );

	it( 'preserves preset node types outside the execution trio', () => {
		const canvas = toCanvasNode( { id: 'i1', type: 'input', label: 'Topic' }, 0 );
		expect( canvas.type ).toBe( 'toolNode' );
		expect( canvas.data.rawType ).toBe( 'input' );
		expect( toGraphNode( canvas ) ).toMatchObject( { id: 'i1', type: 'input' } );
	} );

	it( 'filters malformed edges and keeps valid ones', () => {
		expect(
			toGraphEdges( [ { source: 'a', target: 'b' }, { source: '', target: 'b' } ] )
		).toEqual( [ { id: 'edge_0_a_b', source: 'a', target: 'b' } ] );
	} );
} );

describe( 'validateGraph', () => {
	const nodes = toCanvasNodes( [
		{ id: 'a', type: 'tool', label: 'A' },
		{ id: 'b', type: 'agent', label: 'B' },
	] );

	it( 'accepts a valid graph', () => {
		expect( validateGraph( nodes, [ { source: 'a', target: 'b' } ] ).valid ).toBe( true );
	} );

	it( 'rejects dangling edges and unsupported types', () => {
		const bad = toCanvasNodes( [ { id: 'a', type: 'tool', label: 'A' } ] );
		bad[ 0 ].type = 'mysteryNode' as CanvasWorkflowNode[ 'type' ];

		const result = validateGraph( bad, [ { source: 'a', target: 'ghost' } ] );
		expect( result.valid ).toBe( false );
		expect( result.errors.some( ( message ) => message.includes( 'unsupported type' ) ) ).toBe( true );
		expect( result.errors.some( ( message ) => message.includes( 'missing target' ) ) ).toBe( true );
	} );
} );

describe( 'workflow node components', () => {
	function renderNode(
		Component: ( props: NodeProps< CanvasWorkflowNode > ) => JSX.Element,
		props: NodeProps< CanvasWorkflowNode >
	): void {
		render(
			<ReactFlowProvider>
				<Component { ...props } />
			</ReactFlowProvider>
		);
	}

	it( 'renders each node kind with its label', () => {
		renderNode( ToolNode, nodeProps( { data: { label: 'Web search' } } ) );
		expect( screen.getByText( 'Web search' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Tool' ) ).toBeInTheDocument();

		renderNode( ActionNode, nodeProps( { type: 'actionNode', data: { label: 'Notify' } } ) );
		expect( screen.getByText( 'Notify' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Action' ) ).toBeInTheDocument();

		renderNode( AgentNode, nodeProps( { type: 'agentNode', data: { label: 'Planner' } } ) );
		expect( screen.getByText( 'Planner' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Agent' ) ).toBeInTheDocument();
	} );
} );
