/**
 * DataTable — component tests.
 *
 * Locks in the semantic-table contract: native <table>/<th scope="col">,
 * the W3C sortable-header pattern (button + aria-sort), the master-detail
 * row, the in-body empty state, and the footer rendered outside the scroll
 * wrapper.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DataTable, type DataTableColumn, type DataTableRow } from '../DataTable';

const columns: DataTableColumn[] = [
	{ key: 'name', label: 'Name', sortable: true },
	{ key: 'count', label: 'Count', className: 'is-num', sortable: true },
];

function rows( withDetail = false ): DataTableRow[] {
	return [
		{
			id: 'alpha',
			cells: [ 'Alpha', '3' ],
			detail: withDetail ? <p>Detail for Alpha</p> : undefined,
		},
		{ id: 'beta', cells: [ 'Beta', '7' ] },
	];
}

describe( 'DataTable', () => {
	it( 'renders a native table with column headers and a caption', () => {
		render(
			<DataTable
				columns={ columns }
				rows={ rows() }
				caption="Test registry"
			/>
		);

		const table = screen.getByRole( 'table' );
		expect( table.tagName ).toBe( 'TABLE' );
		expect( table ).toHaveAccessibleName( 'Test registry' );
		expect( screen.getByRole( 'columnheader', { name: 'Name' } ) ).toHaveAttribute(
			'scope',
			'col'
		);
		expect( screen.getByRole( 'columnheader', { name: 'Count' } ) ).toHaveAttribute(
			'scope',
			'col'
		);
	} );

	it( 'exposes the W3C sortable-header pattern', () => {
		const onSort = vi.fn();
		render(
			<DataTable
				columns={ columns }
				rows={ rows() }
				sortKey="name"
				sortDir="ascending"
				onSort={ onSort }
			/>
		);

		const sortButton = screen.getByRole( 'button', { name: /Name/ } );
		expect(
			screen.getByRole( 'columnheader', { name: /Name/ } )
		).toHaveAttribute( 'aria-sort', 'ascending' );
		// The non-active sortable column announces its sortable state.
		expect(
			screen.getByRole( 'columnheader', { name: /Count/ } )
		).toHaveAttribute( 'aria-sort', 'none' );

		fireEvent.click( sortButton );
		expect( onSort ).toHaveBeenCalledWith( 'name' );
	} );

	it( 'renders a full-width detail row for master-detail content', () => {
		render( <DataTable columns={ columns } rows={ rows( true ) } /> );

		expect( screen.getByText( 'Detail for Alpha' ) ).toBeInTheDocument();
		const detailCell = screen.getByText( 'Detail for Alpha' ).closest( 'td' );
		expect( detailCell ).toHaveAttribute( 'colspan', '2' );
	} );

	it( 'renders the empty state inside the table body', () => {
		render(
			<DataTable columns={ columns } rows={ [] } empty={ <p>Nothing here</p> } />
		);

		expect( screen.getByText( 'Nothing here' ) ).toBeInTheDocument();
		// The empty-state cell spans every column.
		expect( screen.getByText( 'Nothing here' ).closest( 'td' ) ).toHaveAttribute(
			'colspan',
			'2'
		);
	} );

	it( 'keeps the footer outside the scroll wrapper', () => {
		const { container } = render(
			<DataTable
				columns={ columns }
				rows={ rows() }
				footer={ <nav aria-label="Pagination">Pager</nav> }
			/>
		);

		const scroll = container.querySelector( '.nvoos-pro-spa-tools-list-scroll' );
		expect( scroll ).not.toBeNull();
		expect( scroll!.querySelector( 'nav' ) ).toBeNull();
		expect( screen.getByRole( 'navigation', { name: 'Pagination' } ) ).toBeInTheDocument();
	} );

	it( 'marks the table busy and dims stale rows while a page fetch is in flight', () => {
		const { container } = render(
			<DataTable columns={ columns } rows={ rows() } busy />
		);

		expect( screen.getByRole( 'table' ) ).toHaveClass( 'nvoos-pro-spa-tools-list--busy' );
		expect( container.querySelector( '.nvoos-pro-spa-tools-list-scroll' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
	} );

	it( 'renders an idle table without the busy markers', () => {
		const { container } = render( <DataTable columns={ columns } rows={ rows() } /> );

		expect( screen.getByRole( 'table' ) ).not.toHaveClass( 'nvoos-pro-spa-tools-list--busy' );
		expect( container.querySelector( '.nvoos-pro-spa-tools-list-scroll' ) ).not.toHaveAttribute(
			'aria-busy'
		);
	} );
} );
