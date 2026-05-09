/**
 * Generic table view — renders a Resource's rows as an HTML table.
 *
 * @since 0.1.0
 */

import { __ } from '@wordpress/i18n';
import type { Resource } from '../api/types';

interface TableViewProps {
	resource: Resource;
	rows: Array<Record<string, unknown>>;
	onRowClick?: ( id: string | number ) => void;
	onDelete?: ( id: string | number ) => void;
}

export function TableView( { resource, rows, onRowClick, onDelete }: TableViewProps ) {
	const fields = resource.fields;
	if ( fields.length === 0 ) {
		return <p>No fields declared on resource &ldquo;{ resource.label }&rdquo;.</p>;
	}
	const showActions = Boolean( onRowClick || onDelete );
	return (
		<table className="nvoos-toolkit-shell-table">
			<thead>
				<tr>
					{ fields.map( ( field ) => (
						<th key={ field.name } scope="col">
							{ field.label || field.name }
						</th>
					) ) }
					{ showActions && <th scope="col">{ __( 'Actions', 'nvoos-toolkit-shell' ) }</th> }
				</tr>
			</thead>
			<tbody>
				{ rows.length === 0 ? (
					<tr>
						<td colSpan={ fields.length + ( showActions ? 1 : 0 ) }>
							{ __( 'No rows.', 'nvoos-toolkit-shell' ) }
						</td>
					</tr>
				) : (
					rows.map( ( row, idx ) => {
						const id = row[ resource.primary_key ];
						const key = String( id ?? idx );
						return (
							<tr key={ key }>
								{ fields.map( ( field ) => (
									<td key={ field.name }>
										{ formatCell( row[ field.name ], field.type ) }
									</td>
								) ) }
								{ showActions && (
									<td className="nvoos-toolkit-shell-table-actions">
										{ onRowClick && id !== undefined && id !== null && (
											<button
												type="button"
												onClick={ () =>
													onRowClick( id as string | number )
												}
											>
												{ __( 'View', 'nvoos-toolkit-shell' ) }
											</button>
										) }
										{ onDelete && id !== undefined && id !== null && (
											<button
												type="button"
												onClick={ () =>
													onDelete( id as string | number )
												}
											>
												{ __( 'Delete', 'nvoos-toolkit-shell' ) }
											</button>
										) }
									</td>
								) }
							</tr>
						);
					} )
				) }
			</tbody>
		</table>
	);
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
