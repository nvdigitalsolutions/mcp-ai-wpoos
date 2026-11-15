// ---------------------------------------------------------------------------
// design-refresh-diff.test.ts — what a `--design` refresh silently drops.
//
// `analyze --design` replaces `profile.design` wholesale, so a fact the run
// failed to extract is gone with no trace. virtuallyjamaica lost #wifi's anchor
// and its entire chrome block that way (2026-11-11) and found out from a parity
// failure later. The report is deliberately a WARNING, not a merge: the same run
// showed the site's copyright line had genuinely been removed, so re-instating a
// dropped fact silently would be wrong in the other direction.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { diffDesignFacts } from '../lib/design.ts';
import type { ForgeDesign } from '../lib/types.ts';

const design = ( overrides: Record< string, unknown > ): ForgeDesign =>
	( { palette: { roles: {}, unassigned: [] }, typography: {}, ...overrides } as unknown as ForgeDesign );

describe( 'diffDesignFacts', () => {
	it( 'reports nothing for a first analysis', () => {
		expect( diffDesignFacts( undefined, design( { sections: [ { id: 'a', anchor: 'home' } ] } ) ) ).toEqual( [] );
	} );

	it( 'reports a section that lost its anchor', () => {
		const before = design( { sections: [ { id: 'a', anchor: 'home' }, { id: '1ff4891', anchor: 'wifi' } ] } );
		const after = design( { sections: [ { id: 'a', anchor: 'home' }, { id: '1ff4891' } ] } );
		expect( diffDesignFacts( before, after ) ).toEqual( [ 'section 1ff4891\'s anchor "wifi"' ] );
	} );

	it( 'reports nothing when the anchor survives', () => {
		const before = design( { sections: [ { id: 'a', anchor: 'home' } ] } );
		const after = design( { sections: [ { id: 'a', anchor: 'home' } ] } );
		expect( diffDesignFacts( before, after ) ).toEqual( [] );
	} );

	it( 'reports a section that disappeared, naming its anchor', () => {
		const before = design( { sections: [ { id: 'a', anchor: 'home' }, { id: 'b', anchor: 'wifi' } ] } );
		const after = design( { sections: [ { id: 'a', anchor: 'home' } ] } );
		expect( diffDesignFacts( before, after ) ).toEqual( [ 'section b (wifi)' ] );
	} );

	it( 'reports every dropped chrome key', () => {
		const before = design( { chrome: { logo: { src: 'x' }, footerBg: '#ededed', navInk: '#ffffff' } } );
		const after = design( { chrome: { navInk: '#ffffff' } } );
		expect( diffDesignFacts( before, after ).sort() ).toEqual( [ 'chrome.footerBg', 'chrome.logo' ] );
	} );

	it( 'does not report an ADDED chrome key', () => {
		const before = design( { chrome: { navInk: '#ffffff' } } );
		const after = design( { chrome: { navInk: '#ffffff', footerBg: '#ededed' } } );
		expect( diffDesignFacts( before, after ) ).toEqual( [] );
	} );

	it( 'does not report a CHANGED chrome value — that is a source change', () => {
		const before = design( { chrome: { footerBg: '#ededed' } } );
		const after = design( { chrome: { footerBg: '#f5f5f4' } } );
		expect( diffDesignFacts( before, after ) ).toEqual( [] );
	} );

	it( 'ignores sections that carry no id', () => {
		const before = design( { sections: [ { anchor: 'wifi' } ] } );
		const after = design( { sections: [] } );
		expect( diffDesignFacts( before, after ) ).toEqual( [] );
	} );

	it( 'reports every fact when the design block vanishes entirely', () => {
		const before = design( {
			sections: [ { id: 'a', anchor: 'home' } ],
			chrome: { footerBg: '#ededed' },
		} );
		expect( diffDesignFacts( before, undefined ).sort() ).toEqual( [ 'chrome.footerBg', 'section a (home)' ] );
	} );
} );
