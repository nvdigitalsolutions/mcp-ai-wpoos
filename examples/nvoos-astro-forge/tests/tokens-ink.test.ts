// ---------------------------------------------------------------------------
// tokens-ink.test.ts — rendered body ink beats the declared role.
//
// The design extraction reads CSS declarations; the browser reads what paints.
// virtuallyjamaica: declared `text` #2a2a2a, rendered body #626262 (ΔE00 18.79).
// `ink` is body copy, so the measurement wins — but an explicit operator pin
// always wins, and `accent-ink` is never touched (it is contrast-derived).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { mergeTokens } from '../lib/scaffold.ts';
import type { ForgeColorModes, ForgeDesign } from '../lib/types.ts';

const design = {
	palette: { roles: { text: '#2a2a2a', accent: '#fe6c61' }, unassigned: [] },
	typography: { scalePx: [] },
	ctaStyle: {},
	spacing: { scalePx: [] },
	radii: { valuesPx: [] },
} as unknown as ForgeDesign;

const colors: ForgeColorModes = {
	light: { body: { background: '#ffffff', text: '#626262' } },
	dark: { body: { background: '#ffffff', text: '#626262' } },
};

describe( 'mergeTokens — rendered body ink', () => {
	it( 'prefers the measured body colour over the declared role', () => {
		const tokens = mergeTokens( design, undefined, colors );
		expect( tokens.ink ).toBe( '#626262' );
	} );

	it( 'leaves accent-ink contrast-derived (never follows the body ink)', () => {
		const tokens = mergeTokens( design, undefined, colors );
		expect( tokens[ 'accent-ink' ] ).toBeDefined();
		expect( tokens[ 'accent-ink' ] ).not.toBe( '#626262' );
	} );

	it( 'keeps the declared ink when no colour baseline exists', () => {
		expect( mergeTokens( design ).ink ).toBe( '#2a2a2a' );
	} );

	it( 'lets an explicit operator override win', () => {
		expect( mergeTokens( design, { ink: '#111111' }, colors ).ink ).toBe( '#111111' );
	} );

	it( 'accepts the legacy flat baseline shape', () => {
		const flat = { body: { background: '#ffffff', text: '#626262' } } as unknown as ForgeColorModes;
		expect( mergeTokens( design, undefined, flat ).ink ).toBe( '#626262' );
	} );

	it( 'ignores an unmeasurable (transparent) body surface', () => {
		const transparent: ForgeColorModes = {
			light: { body: { background: '#ffffff', text: 'transparent' } },
		};
		expect( mergeTokens( design, undefined, transparent ).ink ).toBe( '#2a2a2a' );
	} );
} );
