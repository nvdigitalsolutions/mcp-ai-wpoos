/**
 * Pagination — component tests.
 *
 * Locks in the accessible pager contract used by every console table:
 * a named <nav> landmark, a polite live result range, aria-current on the
 * active page, disabled boundary controls, the rows-per-page selector and
 * the ellipsis-collapsed page list.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Pagination, buildPageList } from '../Pagination';

describe( 'Pagination', () => {
	it( 'renders a labelled navigation landmark with the visible range', () => {
		render(
			<Pagination
				page={ 1 }
				perPage={ 50 }
				total={ 320 }
				itemLabel="records"
				label="Professions pagination"
				onPageChange={ () => {} }
			/>
		);

		expect(
			screen.getByRole( 'navigation', { name: 'Professions pagination' } )
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Showing 1–50 of 320 records'
		);
	} );

	it( 'marks the active page with aria-current', () => {
		render( <Pagination page={ 2 } perPage={ 10 } total={ 45 } onPageChange={ () => {} } /> );

		expect( screen.getByRole( 'button', { name: 'Page 2' } ) ).toHaveAttribute(
			'aria-current',
			'page'
		);
		expect( screen.getByRole( 'button', { name: 'Page 1' } ) ).not.toHaveAttribute(
			'aria-current'
		);
	} );

	it( 'disables Previous on the first page and Next on the last', () => {
		const { rerender } = render(
			<Pagination page={ 1 } perPage={ 10 } total={ 30 } onPageChange={ () => {} } />
		);
		expect( screen.getByRole( 'button', { name: 'Previous' } ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Next' } ) ).toBeEnabled();

		rerender(
			<Pagination page={ 3 } perPage={ 10 } total={ 30 } onPageChange={ () => {} } />
		);
		expect( screen.getByRole( 'button', { name: 'Next' } ) ).toBeDisabled();
	} );

	it( 'reports page changes and rows-per-page changes', () => {
		const onPageChange = vi.fn();
		const onPerPageChange = vi.fn();
		render(
			<Pagination
				page={ 2 }
				perPage={ 25 }
				total={ 300 }
				onPageChange={ onPageChange }
				onPerPageChange={ onPerPageChange }
			/>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect( onPageChange ).toHaveBeenCalledWith( 3 );

		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect( onPageChange ).toHaveBeenCalledWith( 1 );

		fireEvent.change( screen.getByLabelText( 'Rows per page' ), {
			target: { value: '100' },
		} );
		expect( onPerPageChange ).toHaveBeenCalledWith( 100 );
	} );

	it( 'clamps an out-of-range page to the last available page', () => {
		render( <Pagination page={ 99 } perPage={ 10 } total={ 25 } onPageChange={ () => {} } /> );

		// 25 rows at 10 per page = 3 pages; page 3 is the active one.
		expect( screen.getByRole( 'button', { name: 'Page 3' } ) ).toHaveAttribute(
			'aria-current',
			'page'
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Showing 21–25 of 25 items' );
	} );

	it( 'disables every control while busy', () => {
		render(
			<Pagination
				page={ 2 }
				perPage={ 10 }
				total={ 100 }
				busy
				onPageChange={ () => {} }
			/>
		);

		expect( screen.getByRole( 'button', { name: 'Previous' } ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Next' } ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Page 1' } ) ).toBeDisabled();
	} );

	it( 'shows an inline loading indicator while busy and marks the pager busy', () => {
		render(
			<Pagination
				page={ 2 }
				perPage={ 10 }
				total={ 100 }
				busy
				onPageChange={ () => {} }
			/>
		);

		expect( screen.getByRole( 'navigation', { name: 'Pagination' } ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
		// Both the range label and the in-flight indicator are polite live
		// regions — the page change is announced, then the new range.
		const statuses = screen.getAllByRole( 'status' );
		expect( statuses ).toHaveLength( 2 );
		expect( statuses[ 1 ] ).toHaveTextContent( 'Loading…' );
	} );

	it( 'shows no loading indicator when idle', () => {
		render( <Pagination page={ 1 } perPage={ 50 } total={ 320 } onPageChange={ () => {} } /> );

		expect( screen.getByRole( 'navigation', { name: 'Pagination' } ) ).not.toHaveAttribute(
			'aria-busy'
		);
		expect( screen.getAllByRole( 'status' ) ).toHaveLength( 1 );
		expect( screen.queryByText( 'Loading…' ) ).not.toBeInTheDocument();
	} );

	it( 'renders an empty range when there are no rows', () => {
		render( <Pagination page={ 1 } perPage={ 50 } total={ 0 } onPageChange={ () => {} } /> );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Showing 0–0 of 0 items' );
		expect( screen.getByRole( 'button', { name: 'Next' } ) ).toBeDisabled();
	} );
} );

describe( 'buildPageList', () => {
	it( 'lists every page for short ranges', () => {
		expect( buildPageList( 1, 5 ) ).toEqual( [ 1, 2, 3, 4, 5 ] );
	} );

	it( 'collapses long ranges with gaps around the current page', () => {
		expect( buildPageList( 5, 20 ) ).toEqual( [ 1, 2, 'gap', 4, 5, 6, 'gap', 19, 20 ] );
	} );

	it( 'never emits out-of-range pages', () => {
		const list = buildPageList( 20, 20 );
		for ( const item of list ) {
			if ( item !== 'gap' ) {
				expect( item ).toBeGreaterThanOrEqual( 1 );
				expect( item ).toBeLessThanOrEqual( 20 );
			}
		}
	} );
} );
