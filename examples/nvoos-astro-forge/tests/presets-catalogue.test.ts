// ---------------------------------------------------------------------------
// presets-catalogue.test.ts — `presets.json` must match lib/presets.ts.
//
// The catalogue exists so an agent outside MCP can choose a preset without
// reading code (Proposal 136 §4.4). A checked-in file that drifts from the
// source silently misleads every agent that reads it, so the test pins them
// together: change a preset, regenerate, or this fails.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { readFile } from 'node:fs/promises';

import { presetCatalogue, serializePresets } from '../lib/presets.ts';

describe( 'presets.json', () => {
	it( 'matches the serializer byte-for-byte', async () => {
		const committed = await readFile( new URL( '../presets.json', import.meta.url ), 'utf8' );
		expect( committed ).toBe( serializePresets() );
	} );

	it( 'lists every preset once, in the canonical order', () => {
		expect( presetCatalogue().map( ( preset ) => preset.id ) ).toEqual( [ 'blog', 'storefront', 'minimal' ] );
	} );

	it( 'gives each preset a bestFor line and at least one kind', () => {
		for ( const preset of presetCatalogue() ) {
			expect( preset.bestFor.length ).toBeGreaterThan( 0 );
			expect( preset.kinds.length ).toBeGreaterThan( 0 );
		}
	} );

	it( 'declares the disabled search surface for the minimal preset', () => {
		const minimal = presetCatalogue().find( ( preset ) => preset.id === 'minimal' );
		expect( minimal?.surfaces ).not.toContain( 'search' );
	} );
} );
