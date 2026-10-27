/**
 * Pagination — shared pager for the console data tables.
 *
 * Every table in the console that can outgrow one screen uses this component
 * so the paging contract is identical everywhere (tools, records, channels,
 * operations). It follows the USWDS pagination guidance and the W3C WAI-ARIA
 * authoring practices:
 *
 * - A `<nav>` landmark with a descriptive accessible name, so screen-reader
 *   users can jump straight to the pager.
 * - Native `<button>` controls (never styled divs) for free keyboard and
 *   assistive-tech behaviour.
 * - `aria-current="page"` on the active page button.
 * - Boundary controls stay in the DOM but are `disabled`, so the layout never
 *   jumps between pages and the inactive state is announced.
 * - While a page fetch is in flight (`busy`), an inline spinner + “Loading…”
 *   live region appears in the pager and every control is disabled — the
 *   industry keep-previous-data pattern (stale rows stay visible but dimmed
 *   by the page, the pager owns the progress signal).
 * - A polite live region announcing the visible row range on every change —
 *   "Showing 1–50 of 320 records" — so a truncated list can never be mistaken
 *   for the full result set.
 * - Page numbers collapse into ellipsis gaps around the current page
 *   (`1 … 4 5 6 … 35`) instead of rendering an unbounded button row.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.1.0
 */

import type { JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';

/** Rows-per-page choices shared by every paginated surface. */
export const DEFAULT_PER_PAGE_OPTIONS = [ 25, 50, 100 ] as const;

/**
 * Build the page-number sequence for the pager.
 *
 * Collapses long ranges with ellipsis gaps around the current page, e.g.
 * `1 … 4 5 6 … 35`. Short ranges render every page number.
 *
 * @param current Current (1-based) page.
 * @param total   Total number of pages.
 * @return Ordered page numbers and gap markers.
 */
export function buildPageList( current: number, total: number ): ( number | 'gap' )[] {
	if ( total <= 7 ) {
		return Array.from( { length: total }, ( _, i ) => i + 1 );
	}
	const pages = new Set< number >( [ 1, 2, total - 1, total, current - 1, current, current + 1 ] );
	const sorted = Array.from( pages )
		.filter( ( page ) => page >= 1 && page <= total )
		.sort( ( a, b ) => a - b );
	const list: ( number | 'gap' )[] = [];
	let previous = 0;
	for ( const page of sorted ) {
		if ( previous && page - previous > 1 ) {
			list.push( 'gap' );
		}
		list.push( page );
		previous = page;
	}
	return list;
}

export interface PaginationProps {
	/** Current (1-based) page. Clamped to the available range. */
	page: number;
	/** Rows requested per page. */
	perPage: number;
	/** Total rows across every page. */
	total: number;
	/** Called with the next 1-based page. */
	onPageChange: ( page: number ) => void;
	/** Render a rows-per-page selector when provided. */
	onPerPageChange?: ( perPage: number ) => void;
	/** Choices for the rows-per-page selector. */
	perPageOptions?: readonly number[];
	/** Plural noun for the range label (e.g. "records"). */
	itemLabel?: string;
	/** Accessible name for the nav landmark. */
	label?: string;
	/** Extra class names for the nav element. */
	className?: string;
	/** Disable every control and show an inline loading indicator while a
	 * page fetch is in flight. */
	busy?: boolean;
}

/**
 * Render the shared pager.
 */
export function Pagination( {
	page,
	perPage,
	total,
	onPageChange,
	onPerPageChange,
	perPageOptions = DEFAULT_PER_PAGE_OPTIONS,
	itemLabel = __( 'items', 'nvoos-pro-spa' ),
	label = __( 'Pagination', 'nvoos-pro-spa' ),
	className,
	busy = false,
}: PaginationProps ): JSX.Element {
	const safePerPage = Math.max( 1, perPage );
	const pageCount = Math.max( 1, Math.ceil( total / safePerPage ) );
	const currentPage = Math.min( Math.max( 1, page ), pageCount );
	const rangeStart = total === 0 ? 0 : ( currentPage - 1 ) * safePerPage + 1;
	const rangeEnd = Math.min( currentPage * safePerPage, total );
	const pages = buildPageList( currentPage, pageCount );

	return (
		<nav
			className={ [ 'nvoos-pro-spa-tools-page__pager', className ]
				.filter( Boolean )
				.join( ' ' ) }
			aria-label={ label }
			aria-busy={ busy || undefined }
		>
			<p
				className="nvoos-pro-spa-tools-page__pager-label"
				role="status"
				aria-live="polite"
			>
				{ sprintf(
					/* translators: %1$d: first visible row, %2$d: last visible row, %3$d: total rows, %4$s: item noun (e.g. "records") */
					__( 'Showing %1$d–%2$d of %3$d %4$s', 'nvoos-pro-spa' ),
					rangeStart,
					rangeEnd,
					total,
					itemLabel
				) }
			</p>

			{ busy ? (
				<p
					className="nvoos-pro-spa-tools-page__pager-busy"
					role="status"
					aria-live="polite"
				>
					<span
						className="nvoos-pro-spa-page__spinner nvoos-pro-spa-page__spinner--small"
						aria-hidden="true"
					/>
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</p>
			) : null }

			{ onPerPageChange ? (
				<label className="nvoos-pro-spa-tools-page__pager-size">
					<span className="nvoos-pro-spa-sr-only">
						{ __( 'Rows per page', 'nvoos-pro-spa' ) }
					</span>
					<select
						className="nvoos-pro-spa-tools-page__select"
						value={ perPageOptions.includes( perPage ) ? perPage : perPageOptions[ 0 ] }
						onChange={ ( event ) => onPerPageChange( Number( event.target.value ) ) }
					>
						{ perPageOptions.map( ( size ) => (
							<option key={ size } value={ size }>
								{ sprintf(
									/* translators: %d: rows per page */
									__( '%d per page', 'nvoos-pro-spa' ),
									size
								) }
							</option>
						) ) }
					</select>
				</label>
			) : null }

			<button
				type="button"
				className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
				disabled={ busy || currentPage <= 1 }
				onClick={ () => onPageChange( currentPage - 1 ) }
			>
				{ __( 'Previous', 'nvoos-pro-spa' ) }
			</button>

			{ pages.map( ( item, index ) =>
				item === 'gap' ? (
					<span
						key={ `gap-${ index }` }
						className="nvoos-pro-spa-tools-page__pager-ellipsis"
						aria-hidden="true"
					>
						…
					</span>
				) : (
					<button
						key={ item }
						type="button"
						className={ [
							'nvoos-pro-spa-tools-page__pager-page',
							item === currentPage
								? 'nvoos-pro-spa-tools-page__pager-page--active'
								: '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						aria-current={ item === currentPage ? 'page' : undefined }
						aria-label={ sprintf(
							/* translators: %d: page number */
							__( 'Page %d', 'nvoos-pro-spa' ),
							item
						) }
						disabled={ busy }
						onClick={ () => onPageChange( item ) }
					>
						{ item }
					</button>
				)
			) }

			<button
				type="button"
				className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
				disabled={ busy || currentPage >= pageCount }
				onClick={ () => onPageChange( currentPage + 1 ) }
			>
				{ __( 'Next', 'nvoos-pro-spa' ) }
			</button>
		</nav>
	);
}
