/**
 * Kanban view — groups rows of a Resource into columns by a
 * manifest-declared `group_by` field. Read-only in this phase
 * (no drag-and-drop yet); rows are clickable to open the detail view.
 *
 * Phase-1 implementation: pure CSS columns + manifest-declared
 * enum options as the column set. Drag-and-drop will be layered in
 * once `@dnd-kit/sortable` is added as a Tier-A dep in a follow-up.
 *
 * @since 0.2.0
 */

import type { Resource, View } from '../api/types';

interface KanbanViewProps {
	resource: Resource;
	view: View;
	rows: Array<Record<string, unknown>>;
	onRowClick?: ( id: string | number ) => void;
}

const UNGROUPED = '__ungrouped__';

export function KanbanView( { resource, view, rows, onRowClick }: KanbanViewProps ) {
	const groupBy = view.group_by;
	if ( ! groupBy ) {
		return <p>Kanban view requires a <code>group_by</code> field.</p>;
	}
	const field = resource.fields.find( ( f ) => f.name === groupBy );
	if ( ! field ) {
		return (
			<p>
				Kanban <code>group_by</code> references unknown field:{ ' ' }
				<code>{ groupBy }</code>.
			</p>
		);
	}

	// Column set: enum options if declared, otherwise distinct values seen in rows.
	const columns: string[] = [];
	if ( field.options && field.options.length > 0 ) {
		columns.push( ...field.options );
	} else {
		const seen = new Set<string>();
		rows.forEach( ( row ) => {
			const v = row[ groupBy ];
			if ( typeof v === 'string' ) {
				seen.add( v );
			}
		} );
		columns.push( ...Array.from( seen ) );
	}
	if ( columns.length === 0 ) {
		columns.push( UNGROUPED );
	}

	const titleField = pickTitleField( resource );

	const grouped: Record<string, Array<Record<string, unknown>>> = {};
	columns.forEach( ( c ) => ( grouped[ c ] = [] ) );
	grouped[ UNGROUPED ] = [];
	rows.forEach( ( row ) => {
		const v = row[ groupBy ];
		const key = typeof v === 'string' && grouped[ v ] ? v : UNGROUPED;
		grouped[ key ].push( row );
	} );

	const finalColumns =
		grouped[ UNGROUPED ].length > 0 ? [ ...columns, UNGROUPED ] : columns;

	return (
		<div className="nvoos-toolkit-shell-kanban" role="list">
			{ finalColumns.map( ( column ) => (
				<section
					key={ column }
					className="nvoos-toolkit-shell-kanban-column"
					role="listitem"
					aria-label={ column === UNGROUPED ? 'Ungrouped' : column }
				>
					<header className="nvoos-toolkit-shell-kanban-column-header">
						<h3>{ column === UNGROUPED ? 'Ungrouped' : column }</h3>
						<span className="nvoos-toolkit-shell-kanban-count">
							{ grouped[ column ].length }
						</span>
					</header>
					<ul className="nvoos-toolkit-shell-kanban-cards">
						{ grouped[ column ].map( ( row, idx ) => {
							const id = row[ resource.primary_key ];
							const key = String( id ?? idx );
							const title = titleField
								? String( row[ titleField ] ?? '' )
								: key;
							return (
								<li
									key={ key }
									className="nvoos-toolkit-shell-kanban-card"
								>
									<button
										type="button"
										onClick={ () =>
											onRowClick &&
											( id !== undefined && id !== null ) &&
											onRowClick( id as string | number )
										}
									>
										{ title || `#${ key }` }
									</button>
								</li>
							);
						} ) }
					</ul>
				</section>
			) ) }
		</div>
	);
}

function pickTitleField( resource: Resource ): string | undefined {
	const candidates = [ 'title', 'name', 'full_name', 'label', 'subject' ];
	for ( const name of candidates ) {
		if ( resource.fields.some( ( f ) => f.name === name ) ) {
			return name;
		}
	}
	const firstString = resource.fields.find(
		( f ) => f.type === 'string' && ! f.readonly
	);
	return firstString?.name;
}
