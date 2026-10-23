/**
 * NodeEditor tests — the selected-node configuration panel for the
 * workflow canvas (Phase 3b W10.2b follow-up).
 *
 * The editor is a controlled component (the canvas owns node state), so the
 * harness below mirrors WorkflowCanvas: it applies each patch back into a
 * local node copy — exactly how production round-trips edits.
 */

import { describe, it, expect, vi } from 'vitest';
import { useState, type JSX } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { NodeEditor } from '../features/workflows/NodeEditor';
import {
	toCanvasNode,
	type CanvasWorkflowNode,
	type WorkflowNodeData,
} from '../features/workflows/workflow-graph';

function EditorHarness( {
	initial,
	onPatch,
}: {
	initial: CanvasWorkflowNode;
	onPatch: ( patch: Partial< WorkflowNodeData > ) => void;
} ): JSX.Element {
	const [ node, setNode ] = useState< CanvasWorkflowNode >( initial );
	const patch = ( next: Partial< WorkflowNodeData > ): void => {
		onPatch( next );
		setNode( ( current ) => ( { ...current, data: { ...current.data, ...next } } ) );
	};
	return <NodeEditor node={ node } onPatch={ patch } />;
}

function renderEditor(
	node: CanvasWorkflowNode,
	onPatch = vi.fn()
): { onPatch: ReturnType< typeof vi.fn > } {
	render( <EditorHarness initial={ node } onPatch={ onPatch } /> );
	return { onPatch };
}

describe( 'NodeEditor', () => {
	it( 'patches the label on change', async () => {
		const user = userEvent.setup();
		const node = toCanvasNode( { id: 't1', type: 'tool', label: 'Demand' }, 0 );
		const { onPatch } = renderEditor( node );

		await user.clear( screen.getByLabelText( 'Label' ) );
		await user.type( screen.getByLabelText( 'Label' ), 'Weekend demand' );

		expect( onPatch ).toHaveBeenCalledWith( { label: 'Weekend demand' } );
	} );

	it( 'edits toolSlug and valid JSON arguments for tool nodes', async () => {
		const user = userEvent.setup();
		const node = toCanvasNode(
			{ id: 't1', type: 'tool', label: 'Demand', data: { toolSlug: 'fnb_weekend_demand' } },
			0
		);
		const { onPatch } = renderEditor( node );

		await user.clear( screen.getByLabelText( 'Tool slug' ) );
		await user.type( screen.getByLabelText( 'Tool slug' ), 'fnb_daily_demand' );
		expect( onPatch ).toHaveBeenCalledWith( { toolSlug: 'fnb_daily_demand' } );

		// userEvent parses `{` as a key descriptor — double it to type JSON.
		await user.clear( screen.getByLabelText( 'Arguments (JSON)' ) );
		await user.type( screen.getByLabelText( 'Arguments (JSON)' ), '{{ "friday": true }}' );
		expect( onPatch ).toHaveBeenCalledWith( { arguments: { friday: true } } );
	} );

	it( 'keeps the previous value and shows an error for invalid JSON', async () => {
		const user = userEvent.setup();
		const node = toCanvasNode( { id: 't1', type: 'tool', label: 'Demand' }, 0 );
		const { onPatch } = renderEditor( node );

		await user.clear( screen.getByLabelText( 'Arguments (JSON)' ) );
		await user.type( screen.getByLabelText( 'Arguments (JSON)' ), '{{ oops' );

		// The error surfaces, and every arguments patch stays empty — the
		// node never receives the invalid payload.
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		const argumentCalls = onPatch.mock.calls.filter(
			( call: unknown[] ) =>
				typeof call[ 0 ] === 'object' &&
				call[ 0 ] !== null &&
				'arguments' in ( call[ 0 ] as Record< string, unknown > )
		);
		expect( argumentCalls.length ).toBeGreaterThan( 0 );
		for ( const call of argumentCalls ) {
			expect( ( call[ 0 ] as WorkflowNodeData ).arguments ).toEqual( {} );
		}
	} );

	it( 'edits command and params for action nodes', async () => {
		const user = userEvent.setup();
		const node = toCanvasNode( { id: 'a1', type: 'action', label: 'Notify' }, 0 );
		const { onPatch } = renderEditor( node );

		await user.type( screen.getByLabelText( 'Command' ), 'email' );
		expect( onPatch ).toHaveBeenCalledWith( { command: 'email' } );

		await user.clear( screen.getByLabelText( 'Params (JSON)' ) );
		await user.type( screen.getByLabelText( 'Params (JSON)' ), '{{ "to": "ops" }}' );
		expect( onPatch ).toHaveBeenCalledWith( { params: { to: 'ops' } } );
	} );

	it( 'edits agent id and prompt for agent nodes', async () => {
		const user = userEvent.setup();
		const node = toCanvasNode( { id: 'g1', type: 'agent', label: 'Planner' }, 0 );
		const { onPatch } = renderEditor( node );

		await user.type( screen.getByLabelText( 'Agent ID' ), '42' );
		expect( onPatch ).toHaveBeenCalledWith( { agentId: '42' } );

		await user.type( screen.getByLabelText( 'Prompt' ), 'Plan the launch' );
		expect( onPatch ).toHaveBeenCalledWith( { prompt: 'Plan the launch' } );
	} );

	it( 'offers label-only editing for preset passthrough nodes', () => {
		const node = toCanvasNode( { id: 'i1', type: 'input', label: 'Topic' }, 0 );
		renderEditor( node );

		expect( screen.getByLabelText( 'Label' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Tool slug' ) ).not.toBeInTheDocument();
		expect( screen.getByText( /comes from a preset/ ) ).toBeInTheDocument();
	} );
} );
