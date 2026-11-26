// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// font-audit.test.ts — the browser-free half of the font-survival gate.
//
// The live incident (virtuallyjamaica, 2026-11-15): the localizer dropped the
// et-line @font-face when one encoding 404'd, every icon rendered blank, and
// no gate noticed because the markup, classes and CSS selectors were intact.
// The report now names the family; this pins which entries become findings.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { usedDroppedFamilies } from '../lib/font-audit.ts';

describe( 'usedDroppedFamilies', () => {
	it( 'reports a dropped family a built glyph class still uses', () => {
		const dropped = usedDroppedFamilies( {
			droppedFontFamilies: [
				{ family: 'et-line', classes: [ 'icon-flag', 'icon-target' ], used: true, sheet: '/media/icons.css' },
			],
		} );

		expect( dropped ).toHaveLength( 1 );
		expect( dropped[ 0 ].family ).toBe( 'et-line' );
	} );

	it( 'ignores a dropped fallback nothing uses', () => {
		const dropped = usedDroppedFamilies( {
			droppedFontFamilies: [ { family: 'unused-font', used: false } ],
		} );

		expect( dropped ).toEqual( [] );
	} );

	it( 'tolerates an older report without the field', () => {
		expect( usedDroppedFamilies( undefined ) ).toEqual( [] );
		expect( usedDroppedFamilies( {} ) ).toEqual( [] );
	} );
} );
