/**
 * DataTable — shared semantic data table for the Pro SPA console pages.
 *
 * Renders a native `<table>` (the W3C WAI-ARIA APG recommendation — the
 * native element beats ARIA grid roles for tabular data) with:
 *
 * - A visually-hidden `<caption>` for screen readers.
 * - `<th scope="col">` column headers; sortable columns expose a header
 *   `<button>` and the `aria-sort` state per the W3C sortable-table pattern.
 * - Right-aligned numeric cells via the `nvoos-pro-spa-tools-list__cell--num`
 *   column class (tabular-nums kept in CSS).
 * - A scroll wrapper (vertical cap with sticky header, horizontal overflow)
 *   so wide or long tables never break the page layout.
 * - An optional footer slot (pagination, counts) rendered outside the scroll
 *   wrapper so it stays visible, and an empty state rendered in-body.
 *
 * The markup reuses the legacy `nvoos-pro-spa-tools-list` class family so the
 * shared stylesheet covers every table in the app from one place.
 *
 * @package NV_oOS_Pro_Spa
 * @since   1.0.0
 */

import { Fragment } from 'react';
import type { JSX, ReactNode } from 'react';

export type SortDirection = 'ascending' | 'descending';

export interface DataTableColumn {
	/** Stable column key (used for sorting). */
	key: string;
	/** Header label. */
	label: ReactNode;
	/** Extra class applied to the header and every body cell in the column. */
	className?: string;
	/** Marks the column as sortable via its header button. */
	sortable?: boolean;
	/** Optional width hint (e.g. `'120px'`, `'minmax(280px, 2fr)'`). */
	width?: string;
}

export interface DataTableRow {
	/** Stable row key. */
	id: string | number;
	/** Cell contents, one node per column (JSX allowed — badges, buttons…). */
	cells: ReactNode[];
	/**
	 * Optional expandable detail rendered as a full-width row directly below
	 * the main row (master-detail pattern, e.g. team members).
	 */
	detail?: ReactNode;
}

interface DataTableProps {
	columns: DataTableColumn[];
	rows: DataTableRow[];
	/** Table caption (visually hidden but announced to assistive tech). */
	caption?: string;
	/** Optional footer rendered below the table (pager, counts…). */
	footer?: ReactNode;
	/** Empty state rendered inside the table body when `rows` is empty. */
	empty?: ReactNode;
	/** Currently sorted column key (unset = natural order). */
	sortKey?: string;
	/** Sort direction for `sortKey`. */
	sortDir?: SortDirection;
	/** Called with the column key when a sortable header is clicked. */
	onSort?: ( key: string ) => void;
	/** Hide the header row (e.g. for two-column message logs). */
	hideHeader?: boolean;
}

/**
 * Render a semantic, accessible data table.
 */
export function DataTable( {
	columns,
	rows,
	caption,
	footer,
	empty,
	sortKey,
	sortDir,
	onSort,
	hideHeader = false,
}: DataTableProps ): JSX.Element {
	return (
		<>
			<div className="nvoos-pro-spa-tools-list-scroll">
				<table className="nvoos-pro-spa-tools-list nvoos-pro-spa-tools-list--table">
					{ caption ? (
						<caption className="nvoos-pro-spa-sr-only">
							{ caption }
						</caption>
					) : null }
					{ ! hideHeader ? (
						<thead>
							<tr>
								{ columns.map( ( column ) => {
									const isActive = sortKey === column.key;
									const ariaSort = isActive
										? sortDir
										: column.sortable
										? 'none'
										: undefined;
									return (
										<th
											key={ column.key }
											scope="col"
											className={ [
												'nvoos-pro-spa-tools-list__cell',
												'nvoos-pro-spa-tools-list__cell--head',
												column.className ?? '',
											]
												.filter( Boolean )
												.join( ' ' ) }
											style={
												column.width
													? { width: column.width }
													: undefined
											}
											aria-sort={ ariaSort }
										>
											{ column.sortable ? (
												<button
													type="button"
													className="nvoos-pro-spa-tools-list__sort"
													onClick={ () =>
														onSort?.( column.key )
													}
												>
													{ column.label }
													<span
														className="nvoos-pro-spa-tools-list__sort-icon"
														aria-hidden="true"
													>
														{ isActive
															? sortDir === 'ascending'
																? '\u2191'
																: '\u2193'
															: '\u2195' }
													</span>
												</button>
											) : (
												column.label
											) }
										</th>
									);
								} ) }
							</tr>
						</thead>
					) : null }
					<tbody>
						{ rows.length === 0 && empty ? (
							<tr className="nvoos-pro-spa-tools-list__row--empty">
								<td
									className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc"
									colSpan={ columns.length }
								>
									{ empty }
								</td>
							</tr>
						) : (
							rows.map( ( row ) => (
								<Fragment key={ row.id }>
									<tr>
										{ columns.map( ( column, index ) => (
											<td
												key={ column.key }
												className={ [
													'nvoos-pro-spa-tools-list__cell',
													column.className ?? '',
												]
													.filter( Boolean )
													.join( ' ' ) }
											>
												{ row.cells[ index ] ?? '' }
											</td>
										) ) }
									</tr>
									{ row.detail !== undefined ? (
										<tr className="nvoos-pro-spa-tools-list__row--detail">
											<td
												className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc"
												colSpan={ columns.length }
											>
												{ row.detail }
											</td>
										</tr>
									) : null }
								</Fragment>
							) )
						) }
					</tbody>
				</table>
			</div>
			{ footer }
		</>
	);
}
