/**
 * Toolkit value helpers — formatting and form coercion.
 */

import { describe, it, expect } from 'vitest';

import type { ToolkitEntity } from '@nvoos/pro-spa-v2/api/toolkits';
import {
	coerceFormValue,
	formatCellValue,
	formatMetric,
	isEmptyValue,
	isNumericColumn,
	payloadFromFormState,
	formStateFromRecord,
} from '@nvoos/pro-spa-v2/features/toolkits/format';

const ENTITY: ToolkitEntity = {
	key: 'product',
	label: 'Products',
	singular: 'Product',
	icon: '📦',
	id_field: 'sku',
	columns: [
		{ key: 'sku', label: 'SKU', type: 'string', required: true },
		{ key: 'price', label: 'Price', type: 'currency' },
		{ key: 'stock', label: 'Stock', type: 'number' },
		{ key: 'status', label: 'Status', type: 'enum', enum: [ 'draft', 'active' ] },
		{ key: 'active', label: 'Active', type: 'boolean' },
		{ key: 'released', label: 'Released', type: 'date' },
	],
};

describe('formatCellValue', () => {
	it('renders an em dash for blank values', () => {
		expect(formatCellValue(ENTITY.columns[ 0 ], null)).toBe('—');
		expect(formatCellValue(ENTITY.columns[ 0 ], '   ')).toBe('—');
	});

	it('formats currency, numbers and booleans', () => {
		expect(formatCellValue(ENTITY.columns[ 1 ], 1234.5, 'USD')).toMatch(/1,234[.,]50/);
		expect(formatCellValue(ENTITY.columns[ 2 ], 42)).toBe('42');
		expect(formatCellValue(ENTITY.columns[ 4 ], true)).toBe('Yes');
		expect(formatCellValue(ENTITY.columns[ 4 ], false)).toBe('No');
	});

	it('formats dates and falls back to the raw value', () => {
		expect(formatCellValue(ENTITY.columns[ 5 ], '2026-03-04')).toMatch(/2026/);
		expect(formatCellValue(ENTITY.columns[ 5 ], 'not-a-date')).toBe('not-a-date');
	});

	it('falls back to a plain number for an unknown currency code', () => {
		expect(formatCellValue(ENTITY.columns[ 1 ], 10, 'ZZZ')).toContain('10');
	});

	it('marks numeric columns for right alignment', () => {
		expect(isNumericColumn(ENTITY.columns[ 1 ])).toBe(true);
		expect(isNumericColumn(ENTITY.columns[ 2 ])).toBe(true);
		expect(isNumericColumn(ENTITY.columns[ 0 ])).toBe(false);
	});
});

describe('formatMetric', () => {
	it('formats dashboard figures and clamps non-finite input', () => {
		expect(formatMetric(1234)).toMatch(/1,234/);
		expect(formatMetric(Number.NaN)).toBe('0');
	});
});

describe('isEmptyValue', () => {
	it('treats null, undefined and blank strings as empty', () => {
		expect(isEmptyValue(null)).toBe(true);
		expect(isEmptyValue('')).toBe(true);
		expect(isEmptyValue('x')).toBe(false);
		expect(isEmptyValue(0)).toBe(false);
	});
});

describe('coerceFormValue', () => {
	it('parses numbers and keeps non-numeric input for the server to reject', () => {
		expect(coerceFormValue(ENTITY.columns[ 2 ], '12')).toBe(12);
		expect(coerceFormValue(ENTITY.columns[ 2 ], '12.5')).toBe(12.5);
		expect(coerceFormValue(ENTITY.columns[ 2 ], 'many')).toBe('many');
		expect(coerceFormValue(ENTITY.columns[ 2 ], '')).toBeNull();
	});

	it('trims text and treats blank as null', () => {
		expect(coerceFormValue(ENTITY.columns[ 0 ], '  A-1 ')).toBe('A-1');
		expect(coerceFormValue(ENTITY.columns[ 0 ], '   ')).toBeNull();
	});
});

describe('form state', () => {
	it('seeds controls from a record, stringifying booleans', () => {
		const state = formStateFromRecord(ENTITY, { sku: 'A-1', active: true, stock: 3 });

		expect(state.sku).toBe('A-1');
		expect(state.active).toBe('true');
		expect(state.stock).toBe('3');
		expect(state.price).toBe('');
	});

	it('defaults booleans to false on create', () => {
		const state = formStateFromRecord(ENTITY);
		expect(state.active).toBe('false');
	});

	it('builds a create payload including required blanks so the server reports them', () => {
		const payload = payloadFromFormState(
			ENTITY,
			{ sku: '', status: 'active', active: 'true', stock: '5', price: '10.5' },
			'create',
		);

		expect(payload).toEqual({
			sku: null,
			price: 10.5,
			stock: 5,
			status: 'active',
			active: true,
		});
	});

	it('omits untouched blanks on update', () => {
		const payload = payloadFromFormState(
			ENTITY,
			{ sku: 'A-1', stock: '9', price: '' },
			'update',
		);

		// Untouched controls are not in the state at all, and a cleared optional
		// field is dropped rather than sent as null.
		expect( payload ).toEqual( { sku: 'A-1', stock: 9 } );
	});
});
