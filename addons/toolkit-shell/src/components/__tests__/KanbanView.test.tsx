/**
 * KanbanView — component tests.
 *
 * Covers column derivation from manifest enum options, ungrouped rows,
 * guard clauses for missing/unknown group_by fields, and card clicks.
 * (Drag-and-drop persistence is exercised via the onMove callback in
 * App integration; simulating @dnd-kit pointer drags in jsdom is not
 * meaningful without layout.)
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { KanbanView } from '../KanbanView';
import type { Resource, View } from '../../api/types';

const RESOURCE: Resource = {
	name: 'tasks',
	label: 'Tasks',
	endpoint: '/tasks',
	primary_key: 'id',
	fields: [
		{ name: 'id', type: 'integer', label: 'ID', required: true, readonly: true },
		{ name: 'title', type: 'string', label: 'Title', required: true, readonly: false },
		{ name: 'status', type: 'enum', label: 'Status', required: false, readonly: false, options: [ 'todo', 'done' ] },
	],
};

const VIEW: View = {
	name: 'board',
	type: 'kanban',
	resource: 'tasks',
	default: true,
	group_by: 'status',
};

describe( 'KanbanView', () => {
	it( 'requires a group_by field', () => {
		render(
			<KanbanView
				resource={ RESOURCE }
				view={ { ...VIEW, group_by: undefined } }
				rows={ [] }
			/>
		);
		expect( screen.getByText( /requires a/ ) ).toBeInTheDocument();
	} );

	it( 'reports an unknown group_by field', () => {
		render(
			<KanbanView
				resource={ RESOURCE }
				view={ { ...VIEW, group_by: 'nope' } }
				rows={ [] }
			/>
		);
		expect( screen.getByText( /references unknown field/ ) ).toBeInTheDocument();
	} );

	it( 'derives columns from enum options and buckets ungrouped rows', () => {
		const { container } = render(
			<KanbanView
				resource={ RESOURCE }
				view={ VIEW }
				rows={ [
					{ id: 1, title: 'Alpha', status: 'todo' },
					{ id: 2, title: 'Beta', status: 'done' },
					{ id: 3, title: 'Gamma', status: 'unexpected' },
				] }
			/>
		);
		// Two declared columns plus the ungrouped fallback column.
		expect( container.querySelectorAll( 'section[role="listitem"]' ) ).toHaveLength( 3 );
		expect( screen.getByText( 'todo' ) ).toBeInTheDocument();
		expect( screen.getByText( 'done' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Ungrouped' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Alpha' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Gamma' } ) ).toBeInTheDocument();
	} );

	it( 'clicks a card to open its row detail', async () => {
		const user = userEvent.setup();
		const onRowClick = vi.fn();
		render(
			<KanbanView
				resource={ RESOURCE }
				view={ VIEW }
				rows={ [ { id: 7, title: 'Alpha', status: 'todo' } ] }
				onRowClick={ onRowClick }
			/>
		);
		await user.click( screen.getByRole( 'button', { name: 'Alpha' } ) );
		expect( onRowClick ).toHaveBeenCalledWith( 7 );
	} );

	it( 'falls back to a numeric row key when the primary key is missing', () => {
		render(
			<KanbanView
				resource={ RESOURCE }
				view={ VIEW }
				rows={ [ { title: 'NoId', status: 'todo' } ] }
			/>
		);
		expect( screen.getByRole( 'button', { name: 'NoId' } ) ).toBeInTheDocument();
	} );
} );
