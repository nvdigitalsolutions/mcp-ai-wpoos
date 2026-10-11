/**
 * Generic table view — renders a Resource's rows with TanStack Table.
 *
 * Headless table primitives provide sorting, filtering hooks, and column
 * modelling without pulling in a styled UI framework. Row actions use the
 * ui Button primitives; destructive actions go through ConfirmDialog
 * (accessible focus-trapped dialog replacing window.confirm).
 *
 * @since 0.1.0
 * @since 0.3.0 TanStack Table + ConfirmDialog.
 */

import { useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	flexRender,
	getCoreRowModel,
	getSortedRowModel,
	useReactTable,
	type ColumnDef,
	type SortingState,
} from '@tanstack/react-table';
import type { Resource } from '../api/types';
import { Button } from './ui/button';
import { ConfirmDialog } from './ui/confirm-dialog';

interface TableViewProps {
	resource: Resource;
	rows: Array<Record<string, unknown>>;
	onRowClick?: ( id: string | number ) => void;
	onDelete?: ( id: string | number ) => void;
}

interface DeleteRequest {
	id: string | number;
	title: string;
}

export function TableView( { resource, rows, onRowClick, onDelete }: TableViewProps ) {
	const [ sorting, setSorting ] = useState<SortingState>( [] );
	const [ deleteRequest, setDeleteRequest ] = useState<DeleteRequest | null>( null );

	const columns = useMemo< ColumnDef<Record<string, unknown>>[] >( () => {
		const dataColumns: ColumnDef<Record<string, unknown>>[] = resource.fields.map(
			( field ) => ( {
				id: field.name,
				accessorKey: field.name,
				header: field.label || field.name,
				cell: ( info ) => formatCell( info.getValue(), field.type ),
			} )
		);

		if ( ! onRowClick && ! onDelete ) {
			return dataColumns;
		}

		dataColumns.push( {
			id: '__actions',
			header: __( 'Actions', 'nvoos-toolkit-shell' ),
			enableSorting: false,
			cell: ( info ) => {
				const id = info.row.original[ resource.primary_key ];
				if ( id === undefined || id === null ) {
					return null;
				}
				const key = id as string | number;
				return (
					<div className="nvoos-toolkit-shell-table-actions">
						{ onRowClick && (
							<Button variant="secondary" size="sm" onClick={ () => onRowClick( key ) }>
								{ __( 'View', 'nvoos-toolkit-shell' ) }
							</Button>
						) }
						{ onDelete && (
							<Button
								variant="destructive"
								size="sm"
								onClick={ () =>
									setDeleteRequest( { id: key, title: rowTitle( resource, info.row.original, key ) } )
								}
							>
								{ __( 'Delete', 'nvoos-toolkit-shell' ) }
							</Button>
						) }
					</div>
				);
			},
		} );

		return dataColumns;
	}, [ resource, onRowClick, onDelete ] );

	const table = useReactTable( {
		data: rows,
		columns,
		state: { sorting },
		onSortingChange: setSorting,
		getCoreRowModel: getCoreRowModel(),
		getSortedRowModel: getSortedRowModel(),
	} );

	if ( resource.fields.length === 0 ) {
		return <p>No fields declared on resource &ldquo;{ resource.label }&rdquo;.</p>;
	}

	return (
		<>
			<table className="nvoos-toolkit-shell-table">
				<thead>
					{ table.getHeaderGroups().map( ( headerGroup ) => (
						<tr key={ headerGroup.id }>
							{ headerGroup.headers.map( ( header ) => (
								<th key={ header.id } scope="col">
									{ header.column.getCanSort() ? (
										<button
											type="button"
											className="nvoos-toolkit-shell-table-sort"
											onClick={ header.column.getToggleSortingHandler() }
											aria-label={ sprintf(
												/* translators: %s: column label */
												__( 'Sort by %s', 'nvoos-toolkit-shell' ),
												String( header.column.columnDef.header ?? '' )
											) }
										>
											{ flexRender( header.column.columnDef.header, header.getContext() ) }
											<span aria-hidden="true">
												{ { asc: ' ▲', desc: ' ▼' }[
													header.column.getIsSorted() as string
												] ?? '' }
											</span>
										</button>
									) : (
										flexRender( header.column.columnDef.header, header.getContext() )
									) }
								</th>
							) ) }
						</tr>
					) ) }
				</thead>
				<tbody>
					{ table.getRowModel().rows.length === 0 ? (
						<tr>
							<td colSpan={ columns.length }>{ __( 'No rows.', 'nvoos-toolkit-shell' ) }</td>
						</tr>
					) : (
						table.getRowModel().rows.map( ( row ) => (
							<tr key={ row.id }>
								{ row.getVisibleCells().map( ( cell ) => (
									<td key={ cell.id }>
										{ flexRender( cell.column.columnDef.cell, cell.getContext() ) }
									</td>
								) ) }
							</tr>
						) )
					) }
				</tbody>
			</table>

			<ConfirmDialog
				open={ deleteRequest !== null }
				onOpenChange={ ( open ) => {
					if ( ! open ) {
						setDeleteRequest( null );
					}
				} }
				title={ __( 'Delete record?', 'nvoos-toolkit-shell' ) }
				description={ sprintf(
					/* translators: %s: row title or id */
					__( 'This will permanently delete “%s”. This action cannot be undone.', 'nvoos-toolkit-shell' ),
					deleteRequest?.title ?? ''
				) }
				confirmLabel={ __( 'Delete', 'nvoos-toolkit-shell' ) }
				onConfirm={ () => {
					if ( deleteRequest && onDelete ) {
						onDelete( deleteRequest.id );
					}
				} }
			/>
		</>
	);
}

/**
 * Best-effort human title for a row, used in the delete confirmation copy.
 */
function rowTitle( resource: Resource, row: Record<string, unknown>, fallback: string | number ): string {
	const candidates = [ 'title', 'name', 'full_name', 'label', 'subject' ];
	for ( const field of candidates ) {
		const value = row[ field ];
		if ( typeof value === 'string' && value.length > 0 ) {
			return value;
		}
	}
	return String( fallback );
}

function formatCell( value: unknown, type: string ): string {
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( type === 'boolean' ) {
		return value ? '✓' : '✗';
	}
	if ( typeof value === 'object' ) {
		try {
			return JSON.stringify( value );
		} catch {
			return '[object]';
		}
	}
	return String( value );
}
