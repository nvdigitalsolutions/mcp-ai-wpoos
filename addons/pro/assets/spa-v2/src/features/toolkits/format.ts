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
