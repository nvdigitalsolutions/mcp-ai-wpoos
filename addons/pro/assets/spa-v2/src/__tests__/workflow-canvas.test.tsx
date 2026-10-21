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
	nodeExecutePayload,
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

	it( 'preserves executable node data through the round-trip', () => {
		const canvas = toCanvasNode(
			{
				id: 't1',
				type: 'tool',
				label: 'Demand',
				data: { toolSlug: 'fnb_weekend_demand', arguments: { friday: true } },
			},
			0
		);
		const restored = toGraphNode( canvas );
		expect( restored ).toMatchObject( {
			id: 't1',
			type: 'tool',
			label: 'Demand',
		} );
		expect( ( restored as { data?: Record< string, unknown > } ).data ).toMatchObject( {
			toolSlug: 'fnb_weekend_demand',
		} );
	} );

	it( 'filters malformed edges and keeps valid ones', () => {
		expect(
			toGraphEdges( [ { source: 'a', target: 'b' }, { source: '', target: 'b' } ] )
		).toEqual( [ { id: 'edge_0_a_b', source: 'a', target: 'b' } ] );
	} );
} );

describe( 'nodeExecutePayload', () => {
	it( 'builds a tool payload from toolSlug data', () => {
		const node = toCanvasNode(
			{ id: 't1', type: 'tool', label: 'Demand', data: { toolSlug: 'fnb_weekend_demand' } },
			0
		);
		expect( nodeExecutePayload( node, 'wf_1' ) ).toMatchObject( {
			node_type: 'tool',
			node_id: 't1',
			workflow_id: 'wf_1',
			tool_name: 'fnb_weekend_demand',
		} );
	} );

	it( 'returns null when the executable field is missing', () => {
		const bare = toCanvasNode( { id: 't2', type: 'tool', label: 'Bare' }, 0 );
		expect( nodeExecutePayload( bare, 'wf_1' ) ).toBeNull();

		const action = toCanvasNode( { id: 'a1', type: 'action', label: 'Action' }, 0 );
		expect( nodeExecutePayload( action, 'wf_1' ) ).toBeNull();
	} );

	it( 'builds action and agent payloads from their data', () => {
		const action = toCanvasNode(
			{ id: 'a1', type: 'action', label: 'Notify', data: { command: 'notify', params: {} } },
			0
		);
		expect( nodeExecutePayload( action, 'wf_1' ) ).toMatchObject( {
			node_type: 'action',
			command: 'notify',
		} );

		const agent = toCanvasNode(
			{ id: 'g1', type: 'agent', label: 'Planner', data: { prompt: 'Plan it' } },
			0
		);
		expect( nodeExecutePayload( agent, 'wf_1' ) ).toMatchObject( {
			node_type: 'agent',
			prompt: 'Plan it',
			agent_id: 'default',
		} );
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
