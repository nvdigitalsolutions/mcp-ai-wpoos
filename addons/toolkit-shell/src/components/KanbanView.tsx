/**
 * Kanban view — groups rows of a Resource into columns by a
 * manifest-declared `group_by` field.
 *
 * @dnd-kit powers drag-and-drop: cards reorder within a column and can be
 * dragged between columns. Cross-column moves call `onMove( column, rowId )`
 * so the host can persist the new `group_by` value via the toolkit REST
 * endpoint; the list then reloads and reconciles local state.
 *
 * @since 0.2.0
 * @since 0.3.0 @dnd-kit drag-and-drop.
 */

import { useEffect, useMemo, useState } from 'react';
import { __ } from '@wordpress/i18n';
import {
	DndContext,
	KeyboardSensor,
	PointerSensor,
	closestCorners,
	useDroppable,
	useSensor,
	useSensors,
	type DragEndEvent,
	type DragOverEvent,
} from '@dnd-kit/core';
import {
	SortableContext,
	arrayMove,
	sortableKeyboardCoordinates,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { Resource, View } from '../api/types';

interface KanbanViewProps {
	resource: Resource;
	view: View;
	rows: Array<Record<string, unknown>>;
	onRowClick?: ( id: string | number ) => void;
	onMove?: ( column: string, id: string | number ) => void;
}

const UNGROUPED = '__ungrouped__';

interface CardEntry {
	id: string;
	row: Record<string, unknown>;
	rowId: string | number;
}

export function KanbanView( { resource, view, rows, onRowClick, onMove }: KanbanViewProps ) {
	const groupBy = view.group_by;
	const field = useMemo(
		() => resource.fields.find( ( f ) => f.name === groupBy ),
		[ resource, groupBy ]
	);

	const columns = useMemo< string[] >( () => {
		if ( ! groupBy || ! field ) {
			return [];
		}
		if ( field.options && field.options.length > 0 ) {
			return [ ...field.options ];
		}
		const seen = new Set< string >();
		rows.forEach( ( row ) => {
			const v = row[ groupBy ];
			if ( typeof v === 'string' ) {
				seen.add( v );
			}
		} );
		return Array.from( seen );
	}, [ rows, field, groupBy ] );

	const [ groups, setGroups ] = useState< Record< string, CardEntry[] > >( {} );

	// Rebuild local state whenever rows or the column set changes.
	useEffect( () => {
		const next: Record< string, CardEntry[] > = {};
		columns.forEach( ( c ) => ( next[ c ] = [] ) );
		next[ UNGROUPED ] = [];
		rows.forEach( ( row, idx ) => {
			const raw = groupBy ? row[ groupBy ] : undefined;
			const column = typeof raw === 'string' && next[ raw ] ? raw : UNGROUPED;
			const rowId = row[ resource.primary_key ] ?? idx;
			next[ column ].push( {
				id: `card:${ String( rowId ) }`,
				row,
				rowId: rowId as string | number,
			} );
		} );
		setGroups( next );
	}, [ rows, columns, groupBy, resource.primary_key ] );

	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 6 } } ),
		useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } )
	);

	const findColumnOfCard = ( cardId: string ): string | undefined => {
		for ( const [ column, cards ] of Object.entries( groups ) ) {
			if ( cards.some( ( c ) => c.id === cardId ) ) {
				return column;
			}
		}
		return undefined;
	};

	const handleDragOver = ( event: DragOverEvent ) => {
		// Only handle card-over-card; column drops are resolved in onDragEnd.
		const { active, over } = event;
		if ( ! over || over.id === active.id ) {
			return;
		}
		const from = findColumnOfCard( String( active.id ) );
		const toCard = String( over.id ).startsWith( 'card:' )
			? findColumnOfCard( String( over.id ) )
			: undefined;
		if ( ! from || ! toCard || from === toCard ) {
			return;
		}
		// Optimistically move the active card to the target column so the
		// drop target stays visible while dragging between columns.
		setGroups( ( prev ) => {
			const source = prev[ from ] ?? [];
			const target = prev[ toCard ] ?? [];
			const activeEntry = source.find( ( c ) => c.id === String( active.id ) );
			if ( ! activeEntry ) {
				return prev;
			}
			return {
				...prev,
				[ from ]: source.filter( ( c ) => c.id !== String( active.id ) ),
				[ toCard ]: [ ...target, activeEntry ],
			};
		} );
	};

	const handleDragEnd = ( event: DragEndEvent ) => {
		const { active, over } = event;
		if ( ! over ) {
			return;
		}
		const activeId = String( active.id );
		const from = findColumnOfCard( activeId );
		if ( ! from || ! activeId.startsWith( 'card:' ) ) {
			return;
		}
		const rowId = activeId.slice( 'card:'.length );

		// Dropped onto a column droppable.
		if ( String( over.id ).startsWith( 'col:' ) ) {
			const targetColumn = String( over.id ).slice( 'col:'.length );
			if ( targetColumn !== from && onMove ) {
				onMove( targetColumn, isNumeric( rowId ) ? Number( rowId ) : rowId );
			}
			return;
		}

		// Dropped onto another card.
		const to = findColumnOfCard( String( over.id ) );
		if ( ! to ) {
			return;
		}
		if ( from === to ) {
			setGroups( ( prev ) => {
				const cards = prev[ from ] ?? [];
				const oldIndex = cards.findIndex( ( c ) => c.id === activeId );
				const newIndex = cards.findIndex( ( c ) => c.id === String( over.id ) );
				if ( oldIndex < 0 || newIndex < 0 ) {
					return prev;
				}
				return { ...prev, [ from ]: arrayMove( cards, oldIndex, newIndex ) };
			} );
			return;
		}
		if ( onMove ) {
			onMove( to, isNumeric( rowId ) ? Number( rowId ) : rowId );
		}
	};

	if ( ! groupBy ) {
		return <p>Kanban view requires a <code>group_by</code> field.</p>;
	}
	if ( ! field ) {
		return (
			<p>
				Kanban <code>group_by</code> references unknown field:{ ' ' }
				<code>{ groupBy }</code>.
			</p>
		);
	}

	const titleField = pickTitleField( resource );
	const hasUngrouped = ( groups[ UNGROUPED ] ?? [] ).length > 0;
	const finalColumns = hasUngrouped ? [ ...columns, UNGROUPED ] : columns;
	const canDrag = Boolean( onMove );

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ closestCorners }
			onDragOver={ canDrag ? handleDragOver : undefined }
			onDragEnd={ canDrag ? handleDragEnd : undefined }
		>
			<div className="nvoos-toolkit-shell-kanban" role="list">
				{ finalColumns.map( ( column ) => (
					<KanbanColumn
						key={ column }
						column={ column }
						cards={ groups[ column ] ?? [] }
						titleField={ titleField }
						onRowClick={ onRowClick }
						canDrag={ canDrag }
					/>
				) ) }
			</div>
		</DndContext>
	);
}

interface KanbanColumnProps {
	column: string;
	cards: CardEntry[];
	titleField: string | undefined;
	onRowClick?: ( id: string | number ) => void;
	canDrag: boolean;
}

function KanbanColumn( { column, cards, titleField, onRowClick, canDrag }: KanbanColumnProps ) {
	const { setNodeRef, isOver } = useDroppable( { id: `col:${ column }` } );
	const cardIds = cards.map( ( c ) => c.id );
	return (
		<section
			ref={ setNodeRef }
			className={
				isOver
					? 'nvoos-toolkit-shell-kanban-column is-over'
					: 'nvoos-toolkit-shell-kanban-column'
			}
			role="listitem"
			aria-label={ column === UNGROUPED ? 'Ungrouped' : column }
		>
			<header className="nvoos-toolkit-shell-kanban-column-header">
				<h3>{ column === UNGROUPED ? 'Ungrouped' : column }</h3>
				<span className="nvoos-toolkit-shell-kanban-count">{ cards.length }</span>
			</header>
			<SortableContext
				items={ cardIds }
				strategy={ verticalListSortingStrategy }
			>
				<ul className="nvoos-toolkit-shell-kanban-cards">
					{ cards.map( ( card ) => (
						<KanbanCard
							key={ card.id }
							card={ card }
							titleField={ titleField }
							onRowClick={ onRowClick }
							canDrag={ canDrag }
						/>
					) ) }
				</ul>
			</SortableContext>
		</section>
	);
}

interface KanbanCardProps {
	card: CardEntry;
	titleField: string | undefined;
	onRowClick?: ( id: string | number ) => void;
	canDrag: boolean;
}

function KanbanCard( { card, titleField, onRowClick, canDrag }: KanbanCardProps ) {
	const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
		useSortable( { id: card.id, disabled: ! canDrag } );
	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
	};
	const title = titleField ? String( card.row[ titleField ] ?? '' ) : '';

	return (
		<li
			ref={ setNodeRef }
			style={ style }
			className={
				isDragging
					? 'nvoos-toolkit-shell-kanban-card is-dragging'
					: 'nvoos-toolkit-shell-kanban-card'
			}
		>
			<div className="nvoos-toolkit-shell-kanban-card-body">
				{ canDrag && (
					<button
						type="button"
						className="nvoos-toolkit-shell-kanban-card-handle"
						aria-label={ __( 'Drag card', 'nvoos-toolkit-shell' ) }
						{ ...attributes }
						{ ...listeners }
					>
						⠿
					</button>
				) }
				<button
					type="button"
					className="nvoos-toolkit-shell-kanban-card-title"
					onClick={ () =>
						onRowClick && onRowClick( card.rowId as string | number )
					}
				>
					{ title || `#${ String( card.rowId ) }` }
				</button>
			</div>
		</li>
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

function isNumeric( value: string ): boolean {
	return /^\d+$/.test( value );
}
