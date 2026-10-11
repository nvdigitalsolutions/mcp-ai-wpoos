/**
 * TableView — component tests.
 *
 * Covers row/header rendering, TanStack sorting, row-click actions, and
 * the accessible delete confirmation flow (Radix dialog replacing
 * window.confirm).
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TableView } from '../TableView';
import type { Resource } from '../../api/types';

const RESOURCE: Resource = {
	name: 'posts',
	label: 'Posts',
	endpoint: '/posts',
	primary_key: 'id',
	fields: [
		{ name: 'id', type: 'integer', label: 'ID', required: true, readonly: true },
		{ name: 'title', type: 'string', label: 'Title', required: true, readonly: false },
		{ name: 'status', type: 'enum', label: 'Status', required: false, readonly: false, options: [ 'todo', 'done' ] },
	],
};

const ROWS = [
	{ id: 1, title: 'Alpha', status: 'todo' },
	{ id: 2, title: 'Beta', status: 'done' },
];

describe( 'TableView', () => {
	it( 'renders column headers and row cells', () => {
		render( <TableView resource={ RESOURCE } rows={ ROWS } /> );
		expect( screen.getByRole( 'columnheader', { name: /Title/ } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Alpha' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Beta' ) ).toBeInTheDocument();
		expect( screen.getByText( 'todo' ) ).toBeInTheDocument();
	} );

	it( 'shows the empty state when there are no rows', () => {
		render( <TableView resource={ RESOURCE } rows={ [] } /> );
		expect( screen.getByText( 'No rows.' ) ).toBeInTheDocument();
	} );

	it( 'toggles sorting when a sortable header is clicked', async () => {
		const user = userEvent.setup();
		render( <TableView resource={ RESOURCE } rows={ ROWS } /> );
		const sortButton = screen.getByRole( 'button', { name: 'Sort by Title' } );
		expect( sortButton ).not.toHaveTextContent( '▲' );

		await user.click( sortButton );
		expect( sortButton ).toHaveTextContent( '▲' );

		await user.click( sortButton );
		expect( sortButton ).toHaveTextContent( '▼' );
	} );

	it( 'calls onRowClick with the primary key when View is clicked', async () => {
		const user = userEvent.setup();
		const onRowClick = vi.fn();
		render( <TableView resource={ RESOURCE } rows={ ROWS } onRowClick={ onRowClick } /> );

		await user.click( screen.getAllByRole( 'button', { name: 'View' } )[ 1 ] );
		expect( onRowClick ).toHaveBeenCalledWith( 2 );
	} );

	it( 'confirms before deleting, then calls onDelete with the row id', async () => {
		const user = userEvent.setup();
		const onDelete = vi.fn();
		render( <TableView resource={ RESOURCE } rows={ ROWS } onDelete={ onDelete } /> );

		// Open the confirm dialog from the second row.
		await user.click( screen.getAllByRole( 'button', { name: 'Delete' } )[ 1 ] );

		const dialog = screen.getByRole( 'dialog' );
		expect( within( dialog ).getByText( 'Delete record?' ) ).toBeInTheDocument();
		expect( within( dialog ).getByText( /Beta/ ) ).toBeInTheDocument();

		await user.click( within( dialog ).getByRole( 'button', { name: 'Delete' } ) );

		expect( onDelete ).toHaveBeenCalledTimes( 1 );
		expect( onDelete ).toHaveBeenCalledWith( 2 );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'cancelling the confirm dialog does not delete', async () => {
		const user = userEvent.setup();
		const onDelete = vi.fn();
		render( <TableView resource={ RESOURCE } rows={ ROWS } onDelete={ onDelete } /> );

		await user.click( screen.getAllByRole( 'button', { name: 'Delete' } )[ 0 ] );
		await user.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', { name: 'Cancel' } )
		);

		expect( onDelete ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );
} );
