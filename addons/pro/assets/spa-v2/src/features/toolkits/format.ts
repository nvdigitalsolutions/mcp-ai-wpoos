/**
 * Toolkit value helpers — formatting and form coercion.
 *
 * Kept pure so the workbench, the dashboard and the consolidate screen all
 * render a value the same way, and so the coercion rules are unit-testable
 * without a DOM.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import type { ToolkitColumn, ToolkitEntity } from '../../api/toolkits';

/** Columns the table right-aligns (tabular figures). */
export function isNumericColumn( column: ToolkitColumn ): boolean {
	return column.type === 'number' || column.type === 'currency';
}

/** A blank value is `null`, `undefined` or an empty/whitespace string. */
export function isEmptyValue( value: unknown ): boolean {
	if ( value === null || value === undefined ) {
		return true;
	}
	return typeof value === 'string' && value.trim() === '';
}

/** Format one cell for the workbench table. */
export function formatCellValue(
	column: ToolkitColumn,
	value: unknown,
	currency = 'USD',
): string {
	if ( isEmptyValue( value ) ) {
		return '—';
	}

	switch ( column.type ) {
		case 'boolean':
			return value === true || value === 1 || value === '1' ? 'Yes' : 'No';

		case 'currency':
			return formatNumber( value, currency );

		case 'number':
			return formatNumber( value );

		case 'date':
			return formatDate( value );

		default:
			return String( value );
	}
}

/** Format a dashboard figure (locale-aware). */
export function formatMetric( value: number ): string {
	if ( ! Number.isFinite( value ) ) {
		return '0';
	}
	return new Intl.NumberFormat( undefined, { maximumFractionDigits: 2 } ).format( value );
}

/** Coerce a form control's string into the column's JSON value. */
export function coerceFormValue( column: ToolkitColumn, raw: string ): unknown {
	switch ( column.type ) {
		case 'number':
		case 'currency': {
			const trimmed = raw.trim();
			if ( trimmed === '' ) {
				return null;
			}
			const parsed = Number( trimmed );
			return Number.isFinite( parsed ) ? parsed : trimmed;
		}

		default:
			return raw.trim() === '' ? null : raw.trim();
	}
}

/**
 * Seed a form state from a record (edit) or from the entity config (create).
 *
 * Booleans become `'true'`/`'false'` strings so one `Record<string, string>`
 * drives every control; the checkbox reads `value === 'true'`.
 */
export function formStateFromRecord(
	entity: ToolkitEntity,
	record?: Record< string, unknown >,
): Record< string, string > {
	const state: Record< string, string > = {};

	for ( const column of entity.columns ) {
		const value = record ? record[ column.key ] : undefined;

		if ( column.type === 'boolean' ) {
			state[ column.key ] = value === true ? 'true' : 'false';
			continue;
		}

		state[ column.key ] = isEmptyValue( value ) ? '' : String( value );
	}

	return state;
}

/** Convert form strings back into the payload the API validates. */
export function payloadFromFormState(
	entity: ToolkitEntity,
	state: Record< string, string >,
	mode: 'create' | 'update',
): Record< string, unknown > {
	const payload: Record< string, unknown > = {};

	for ( const column of entity.columns ) {
		const raw = state[ column.key ];

		if ( raw === undefined ) {
			continue;
		}

		if ( column.type === 'boolean' ) {
			payload[ column.key ] = raw === 'true';
			continue;
		}

		const value = coerceFormValue( column, raw );

		// On update only send what the operator filled in, so a partial edit
		// never clears untouched fields.
		if ( mode === 'update' && value === null ) {
			continue;
		}

		if ( value !== null || column.required ) {
			payload[ column.key ] = value;
		}
	}

	return payload;
}

/**
 * Parse a pasted sheet into record objects for the bulk importer.
 *
 * Tab, comma and pipe are auto-detected from the first line (tab wins ties,
 * because a spreadsheet paste uses tabs). The first line is treated as a
 * header when at least half of its cells match a column key or label;
 * otherwise cells are mapped positionally to the manifest's column order.
 * Blank cells are omitted so optional columns stay absent rather than empty,
 * and rows with more cells than headers are skipped instead of silently
 * shifting every value one column left.
 */
export type DelimitedRow = Record< string, string >;

export interface ParsedDelimitedRows {
	delimiter: '\t' | ',' | '|';
	headers: string[];
	unknownHeaders: string[];
	rows: DelimitedRow[];
	total: number;
	skipped: number;
}

export function parseDelimitedRows(
	text: string,
	columns: ToolkitColumn[],
): ParsedDelimitedRows {
	const lines = text
		.split( /\r\n|\r|\n/ )
		.map( ( line ) => line.trimEnd() )
		.filter( ( line ) => line.trim() !== '' );

	const headers = columns.map( ( column ) => column.key );
	const empty: ParsedDelimitedRows = {
		delimiter: '\t',
		headers,
		unknownHeaders: [],
		rows: [],
		total: 0,
		skipped: 0,
	};

	if ( lines.length === 0 || columns.length === 0 ) {
		return empty;
	}

	const delimiter = detectDelimiter( lines[ 0 ] );
	const first = splitLine( lines[ 0 ], delimiter );

	const byName = new Map< string, string >();
	for ( const column of columns ) {
		byName.set( column.key.toLowerCase(), column.key );
		byName.set( column.label.toLowerCase(), column.key );
	}

	const matched = first.map( ( cell ) => byName.get( cell.toLowerCase() ) ?? '' );
	const matchedCount = matched.filter( ( key ) => key !== '' ).length;
	const hasHeader = matchedCount >= 2 && matchedCount * 2 >= first.length;

	const unknownHeaders = hasHeader
		? first.filter( ( cell, index ) => cell !== '' && matched[ index ] === '' )
		: [];

	const activeHeaders = hasHeader ? matched : columns.map( ( column ) => column.key );
	const body = hasHeader ? lines.slice( 1 ) : lines;

	const rows: DelimitedRow[] = [];
	let skipped = 0;

	for ( const line of body ) {
		const cells = trimTrailingEmpty( splitLine( line, delimiter ) );

		if ( cells.length > activeHeaders.length ) {
			skipped++;
			continue;
		}

		const record: DelimitedRow = {};

		cells.forEach( ( cell, index ) => {
			const key = activeHeaders[ index ];

			if ( key && cell !== '' ) {
				record[ key ] = cell;
			}
		} );

		if ( Object.keys( record ).length > 0 ) {
			rows.push( record );
		} else {
			skipped++;
		}
	}

	return {
		delimiter,
		headers: activeHeaders.filter( ( key ) => key !== '' ),
		unknownHeaders,
		rows,
		total: body.length,
		skipped,
	};
}

function detectDelimiter( line: string ): '\t' | ',' | '|' {
	const counts: Array< [ '\t' | ',' | '|', number ] > = [
		[ '\t', ( line.match( /\t/g ) ?? [] ).length ],
		[ ',', ( line.match( /,/g ) ?? [] ).length ],
		[ '|', ( line.match( /\|/g ) ?? [] ).length ],
	];

	let winner: '\t' | ',' | '|' = '\t';
	let best = 0;

	for ( const [ candidate, count ] of counts ) {
		if ( count > best ) {
			best = count;
			winner = candidate;
		}
	}

	return winner;
}

function splitLine( line: string, delimiter: string ): string[] {
	return line.split( delimiter ).map( ( cell ) => unquote( cell.trim() ) );
}

function unquote( cell: string ): string {
	if ( cell.length < 2 ) {
		return cell;
	}

	const first = cell[ 0 ];
	const last = cell[ cell.length - 1 ];

	if ( ( first === '"' || first === "'" ) && first === last ) {
		return cell.slice( 1, -1 ).trim();
	}

	return cell;
}

function trimTrailingEmpty( cells: string[] ): string[] {
	const trimmed = [ ...cells ];

	while ( trimmed.length > 0 && trimmed[ trimmed.length - 1 ] === '' ) {
		trimmed.pop();
	}

	return trimmed;
}

function formatNumber( value: unknown, currency?: string ): string {
	const numeric = typeof value === 'number' ? value : Number( value );

	if ( ! Number.isFinite( numeric ) ) {
		return String( value );
	}

	if ( currency ) {
		try {
			return new Intl.NumberFormat( undefined, {
				style: 'currency',
				currency,
				maximumFractionDigits: 2,
			} ).format( numeric );
		} catch {
			// Unknown currency code — fall through to a plain number.
		}
	}

	return new Intl.NumberFormat( undefined, { maximumFractionDigits: 2 } ).format( numeric );
}

function formatDate( value: unknown ): string {
	const raw = String( value );
	const parsed = new Date( raw );

	if ( Number.isNaN( parsed.getTime() ) ) {
		return raw;
	}

	return parsed.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
}
