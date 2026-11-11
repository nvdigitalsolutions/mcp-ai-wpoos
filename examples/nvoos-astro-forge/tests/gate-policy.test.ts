// ---------------------------------------------------------------------------
// gate-policy.test.ts — the per-site gate blocking policy.
//
// The forge serves many sites, so a gate's BLOCKING-ness is per site while its
// MEASUREMENT is not. These tests pin the three properties that keep that
// honest: a downgrade is never anonymous, a profile can never invent or hide a
// gate, and a build-vs-build comparison is never reported as parity.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	FORGE_ADDRESSABLE_GATES,
	gateNote,
	resolveGateMode,
	resolvePixelMasks,
	validateVerifyBlock,
} from '../lib/gate-policy.ts';
import type { ForgeVerify } from '../lib/gate-policy.ts';

describe( 'FORGE_ADDRESSABLE_GATES', () => {
	it( 'is the four render-sensitive gates and nothing else', () => {
		// Widening this list is a promise that `verify` honours `advisory` for the
		// gate, so it is asserted rather than assumed.
		expect( [ ...FORGE_ADDRESSABLE_GATES ] ).toEqual( [
			'pixel parity',
			'pixel regression',
			'box geometry',
			'interaction states',
		] );
	} );

	it( 'does not expose the content gates', () => {
		const addressable: readonly string[] = FORGE_ADDRESSABLE_GATES;
		for ( const name of [ 'band flow', 'token block', 'text parity', 'design language', 'nav anchors' ] ) {
			expect( addressable ).not.toContain( name );
		}
	} );
} );

describe( 'resolveGateMode — defaults', () => {
	it( 'blocks by default on a gate with no reference requirement', () => {
		expect( resolveGateMode( 'box geometry', undefined ) ).toEqual( { mode: 'gate', from: 'default' } );
	} );

	it( 'blocks by default on the regression gate — a changed build is a real change', () => {
		expect( resolveGateMode( 'pixel regression', undefined ) ).toEqual( { mode: 'gate', from: 'default' } );
	} );

	it( 'resolves pixel parity to ADVISORY while no source seam exists', () => {
		const resolution = resolveGateMode( 'pixel parity', undefined, { hasSourceSeam: false } );
		expect( resolution.mode ).toBe( 'advisory' );
		expect( resolution.from ).toBe( 'default' );
		expect( resolution.reason ).toContain( 'no source seam' );
	} );

	it( 'treats a missing evidence flag as "no seam"', () => {
		expect( resolveGateMode( 'pixel parity', undefined, {} ).mode ).toBe( 'advisory' );
	} );

	it( 'resolves pixel parity to a BLOCKING gate once a source seam exists', () => {
		const resolution = resolveGateMode( 'pixel parity', undefined, { hasSourceSeam: true } );
		expect( resolution.mode ).toBe( 'gate' );
		expect( resolution.from ).toBe( 'default' );
	} );
} );

describe( 'resolveGateMode — profile', () => {
	it( 'honours an explicit advisory and marks it as profile-sourced', () => {
		const verify: ForgeVerify = { gates: { 'pixel parity': 'advisory' } };
		expect( resolveGateMode( 'pixel parity', verify, { hasSourceSeam: true } ) ).toEqual( {
			mode: 'advisory',
			from: 'profile',
			declared: 'advisory',
		} );
	} );

	it( 'honours an explicit gate even with no seam', () => {
		const verify: ForgeVerify = { gates: { 'pixel parity': 'gate' } };
		expect( resolveGateMode( 'pixel parity', verify, { hasSourceSeam: false } ).mode ).toBe( 'gate' );
	} );

	it( 'resolves a profile `auto` from evidence but keeps the profile provenance', () => {
		const verify: ForgeVerify = { gates: { 'pixel parity': 'auto' } };
		const resolution = resolveGateMode( 'pixel parity', verify, { hasSourceSeam: false } );
		expect( resolution.mode ).toBe( 'advisory' );
		expect( resolution.from ).toBe( 'profile' );
		expect( resolution.declared ).toBe( 'auto' );
	} );

	it( 'lets a site downgrade geometry', () => {
		const verify: ForgeVerify = { gates: { 'box geometry': 'advisory' } };
		expect( resolveGateMode( 'box geometry', verify ).mode ).toBe( 'advisory' );
	} );

	it( 'lets a site soften the regression gate', () => {
		const verify: ForgeVerify = { gates: { 'pixel regression': 'advisory' } };
		expect( resolveGateMode( 'pixel regression', verify ).mode ).toBe( 'advisory' );
	} );
} );

describe( 'resolveGateMode — CLI flag precedence', () => {
	it( 'lets --strict-pixel force blocking over a profile downgrade', () => {
		const verify: ForgeVerify = { gates: { 'pixel parity': 'advisory' } };
		expect( resolveGateMode( 'pixel parity', verify, { hasSourceSeam: false }, 'gate' ) ).toEqual( {
			mode: 'gate',
			from: 'flag',
		} );
	} );

	it( 'lets a flag soften a blocking gate', () => {
		expect( resolveGateMode( 'box geometry', undefined, {}, 'advisory' ) ).toEqual( {
			mode: 'advisory',
			from: 'flag',
		} );
	} );

	it( 'ignores a flag of `auto` — a flag cannot delegate the decision back', () => {
		const verify: ForgeVerify = { gates: { 'pixel parity': 'advisory' } };
		expect( resolveGateMode( 'pixel parity', verify, { hasSourceSeam: true }, 'auto' ) ).toEqual( {
			mode: 'advisory',
			from: 'profile',
			declared: 'advisory',
		} );
	} );
} );

describe( 'gateNote', () => {
	it( 'is empty for a blocking gate — provenance of the norm is noise', () => {
		expect( gateNote( { mode: 'gate', from: 'default' } ) ).toBe( '' );
		expect( gateNote( { mode: 'gate', from: 'profile', declared: 'gate' } ) ).toBe( '' );
	} );

	it( 'names the source of a downgrade', () => {
		expect( gateNote( { mode: 'advisory', from: 'profile', declared: 'advisory' } ) ).toBe(
			'advisory (profile · advisory)'
		);
	} );

	it( 'carries the auto reasoning so a downgrade is never anonymous', () => {
		const note = gateNote( {
			mode: 'advisory',
			from: 'default',
			declared: 'auto',
			reason: 'no source seam',
		} );
		expect( note ).toBe( 'advisory (default · auto · no source seam)' );
	} );

	it( 'marks a flag as the source', () => {
		expect( gateNote( { mode: 'advisory', from: 'flag' } ) ).toBe( 'advisory (flag)' );
	} );
} );

describe( 'validateVerifyBlock', () => {
	it( 'accepts an absent block and a well-formed one', () => {
		expect( validateVerifyBlock( undefined ) ).toEqual( [] );
		expect( validateVerifyBlock( null ) ).toEqual( [] );
		expect(
			validateVerifyBlock( {
				gates: { 'pixel parity': 'advisory', 'box geometry': 'auto', 'interaction states': 'advisory' },
				pixel: { baseline: 'build', masks: [ '.carousel' ] },
			} )
		).toEqual( [] );
	} );

	it( 'rejects an unknown top-level key (a typo would silently keep the default)', () => {
		const problems = validateVerifyBlock( { gate: {} } );
		expect( problems ).toHaveLength( 1 );
		expect( problems[ 0 ] ).toContain( 'unknown verify key "gate"' );
	} );

	it( 'rejects a content gate by name and explains the fixed set', () => {
		const problems = validateVerifyBlock( { gates: { 'text parity': 'advisory' } } );
		expect( problems[ 0 ] ).toContain( 'not addressable' );
		expect( problems[ 0 ] ).toContain( 'pixel parity' );
	} );

	it( 'rejects `off` and points at advisory instead', () => {
		const problems = validateVerifyBlock( { gates: { 'pixel parity': 'off' } } );
		expect( problems[ 0 ] ).toContain( 'there is no "off"' );
	} );

	it( 'rejects a bad pixel baseline', () => {
		expect( validateVerifyBlock( { pixel: { baseline: 'parity' } } )[ 0 ] ).toContain(
			'verify.pixel.baseline must be source (parity) or build (regression)'
		);
	} );

	it( 'rejects masks that are not selectors', () => {
		expect( validateVerifyBlock( { pixel: { masks: [ 1 ] } } )[ 0 ] ).toContain(
			'verify.pixel.masks must be an array of CSS selectors'
		);
	} );

	it( 'rejects a non-object block', () => {
		expect( validateVerifyBlock( 'strict' ) ).toEqual( [ 'verify must be an object ({ gates, pixel })' ] );
	} );

	it( 'rejects an unknown pixel key', () => {
		expect( validateVerifyBlock( { pixel: { mask: [] } } )[ 0 ] ).toContain( 'unknown verify.pixel key "mask"' );
	} );
} );

describe( 'resolvePixelMasks', () => {
	it( 'prefers the CLI mask so a one-off investigation can widen it', () => {
		const verify: ForgeVerify = { pixel: { masks: [ '.carousel' ] } };
		expect( resolvePixelMasks( verify, [ '.clock' ] ) ).toEqual( [ '.clock' ] );
	} );

	it( 'falls back to the site mask so it travels with the profile', () => {
		const verify: ForgeVerify = { pixel: { masks: [ '.carousel', '.live-chat' ] } };
		expect( resolvePixelMasks( verify, [] ) ).toEqual( [ '.carousel', '.live-chat' ] );
	} );

	it( 'returns nothing when neither side declares a mask', () => {
		expect( resolvePixelMasks( undefined, [] ) ).toEqual( [] );
	} );

	it( 'drops empty and non-string entries', () => {
		expect( resolvePixelMasks( undefined, [ '', 7, '.x' ] ) ).toEqual( [ '.x' ] );
	} );
} );
