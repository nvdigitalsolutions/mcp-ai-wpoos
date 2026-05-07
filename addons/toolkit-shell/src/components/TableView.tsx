/**
 * Generic table view — renders a Resource's rows as an HTML table.
 *
 * @since 0.1.0
 */

import type { Resource } from '../api/types';

interface TableViewProps {
	resource: Resource;
	rows: Array<Record<string, unknown>>;
}

export function TableView( { resource, rows }: TableViewProps ) {
	const fields = resource.fields;
	if ( fields.length === 0 ) {
		return <p>No fields declared on resource &ldquo;{ resource.label }&rdquo;.</p>;
	}
	return (
		<table className="nvoos-toolkit-shell-table">
			<thead>
				<tr>
					{ fields.map( ( field ) => (
						<th key={ field.name } scope="col">
							{ field.label || field.name }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.length === 0 ? (
					<tr>
						<td colSpan={ fields.length }>No rows.</td>
					</tr>
				) : (
					rows.map( ( row, idx ) => (
						<tr key={ String( row[ resource.primary_key ] ?? idx ) }>
							{ fields.map( ( field ) => (
								<td key={ field.name }>
									{ formatCell( row[ field.name ], field.type ) }
								</td>
							) ) }
						</tr>
					) )
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
