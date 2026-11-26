// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// structure-pass.test.ts — the vision structure pass, without a browser.
//
// The pass exists to label the bands a deterministic detector cannot measure.
// Its whole value is its restraint, so every restraint is pinned here:
//
//  * no vision endpoint  → skipped, never an error;
//  * a measured structure → never overridden, and never even asked about;
//  * an unknown/low-confidence answer → nothing stored;
//  * the caller's profile → never mutated (only `--write` persists);
//  * an identical screenshot → served from the content-addressed cache.
//
// Capture is injected, so this suite neither needs Playwright nor touches the
// filesystem.
// ---------------------------------------------------------------------------

import { describe, expect, it, vi } from 'vitest';

import {
	parseStructureCache,
	runStructurePass,
	serializeStructureCache,
	structureCacheKey,
	type BandShot,
} from '../lib/structure-pass.ts';
import type { SiteProfile } from '../lib/types.ts';

const profileWith = ( structure?: Array< Record< string, unknown > > ): SiteProfile =>
	( {
		name: 'eca',
		url: 'https://eca.example',
		collections: [],
		design: {
			sections: [ { id: 'abc1234', ...( structure ? { structure } : {} ) }, { id: 'def5678' } ],
			bandSource: 'sections',
		},
	} as unknown as SiteProfile );

const shot = ( id: string, device: string, seed: string ): BandShot => ( {
	id,
	device,
	png: new Uint8Array( Buffer.from( seed ) ),
	mime: 'image/png',
} );

const env = { NVOOS_FORGE_VISION_KEY: 'test', NVOOS_FORGE_VISION_MODEL: 'vision-test' };

/** A fetch stub answering every request with the same verdict JSON. */
const answering = ( verdict: Record< string, unknown > ) =>
	vi.fn( async () =>
		( {
			ok: true,
			json: async () => ( { choices: [ { message: { content: JSON.stringify( verdict ) } } ] } ),
		} as unknown as Response )
	);

const run = ( profile: SiteProfile, shots: BandShot[], fetchImpl: typeof fetch, cache = new Map() ) =>
	runStructurePass( profile, {
		target: '.',
		env,
		capture: async () => shots,
		fetchImpl,
		cache,
	} );

describe( 'vision structure pass', () => {
	it( 'skips cleanly when no vision endpoint is configured', async () => {
		const outcome = await runStructurePass( profileWith(), {
			target: '.',
			// `NVOOS_FORGE_NO_LOCAL` keeps the local-daemon probe out of the test:
			// a developer machine running Ollama would otherwise answer.
			env: { NVOOS_FORGE_NO_LOCAL: '1' },
			capture: async () => [ shot( 'abc1234', 'desktop', 'a' ) ],
		} );
		expect( outcome.skipped ).toBe( 'no vision config' );
		expect( outcome.labelled ).toBe( 0 );
	} );

	it( 'stores a confident label WITH its provenance, and never mutates the input', async () => {
		const profile = profileWith();
		const outcome = await run( profile, [ shot( 'abc1234', 'desktop', 'a' ) ], answering( {
			type: 'timeline',
			confidence: 0.91,
			note: 'a connector runs through the icons',
		} ) as unknown as typeof fetch );

		expect( outcome.labelled ).toBe( 1 );
		const stored = outcome.profile.design?.sections?.[ 0 ]?.structure?.[ 0 ];
		expect( stored ).toMatchObject( {
			type: 'timeline',
			source: 'vision',
			model: 'vision-test',
			confidence: 0.91,
		} );
		// The caller's object is untouched — persistence is an explicit decision.
		expect( profile.design?.sections?.[ 0 ]?.structure ).toBeUndefined();
	} );

	it( 'never overrides a measured structure — and never asks about it', async () => {
		const fetchImpl = answering( { type: 'timeline', confidence: 0.99 } );
		const outcome = await run(
			profileWith( [ { type: 'comparison', data: { columns: [ 'A', 'B' ], rows: [] } } ] ),
			[ shot( 'abc1234', 'desktop', 'a' ) ],
			fetchImpl as unknown as typeof fetch
		);
		expect( fetchImpl ).not.toHaveBeenCalled();
		expect( outcome.asked ).toBe( 0 );
		expect( outcome.bands[ 0 ].skipped ).toBe( true );
		expect( outcome.profile.design?.sections?.[ 0 ]?.structure?.[ 0 ].type ).toBe( 'comparison' );
	} );

	it( 'treats the typed `comparison` fast path as measured', async () => {
		// A profile recorded before the `structure` channel carries the matrix in
		// `comparison`, and the renderer falls back to it ONLY while `structure` is
		// empty — so a vision label here would suppress the table it complements.
		const profile = profileWith();
		( profile.design!.sections![ 0 ] as Record< string, unknown > ).comparison = {
			columns: [ 'Student', 'Parent' ],
			rows: [ { label: 'Catalogue', values: [ '✓', '✓' ] } ],
		};
		const fetchImpl = answering( { type: 'steps', confidence: 0.9 } );
		const outcome = await run( profile, [ shot( 'abc1234', 'desktop', 'a' ) ], fetchImpl as unknown as typeof fetch );
		expect( fetchImpl ).not.toHaveBeenCalled();
		expect( outcome.bands[ 0 ].skipped ).toBe( true );
		expect( outcome.profile.design?.sections?.[ 0 ]?.structure ).toBeUndefined();
	} );

	it( 'stores nothing for an unknown answer', async () => {
		const outcome = await run(
			profileWith(),
			[ shot( 'abc1234', 'desktop', 'a' ) ],
			answering( { type: 'unknown', confidence: 0, note: 'a plain paragraph' } ) as unknown as typeof fetch
		);
		expect( outcome.unknown ).toBe( 1 );
		expect( outcome.labelled ).toBe( 0 );
		expect( outcome.profile.design?.sections?.[ 0 ]?.structure ).toBeUndefined();
	} );

	it( 'stores nothing below the confidence floor', async () => {
		const outcome = await run(
			profileWith(),
			[ shot( 'abc1234', 'desktop', 'a' ) ],
			answering( { type: 'faq', confidence: 0.4 } ) as unknown as typeof fetch
		);
		expect( outcome.labelled ).toBe( 0 );
		expect( outcome.profile.design?.sections?.[ 0 ]?.structure ).toBeUndefined();
	} );

	it( 'answers an identical screenshot from the cache, not the model', async () => {
		const cache = new Map();
		const first = await run(
			profileWith(),
			[ shot( 'abc1234', 'desktop', 'a' ) ],
			answering( { type: 'steps', confidence: 0.9 } ) as unknown as typeof fetch,
			cache
		);
		expect( first.asked ).toBe( 1 );

		const fetchImpl = answering( { type: 'steps', confidence: 0.9 } );
		const second = await run( profileWith(), [ shot( 'abc1234', 'desktop', 'a' ) ], fetchImpl as unknown as typeof fetch, cache );
		expect( fetchImpl ).not.toHaveBeenCalled();
		expect( second.cached ).toBe( 1 );
		expect( second.asked ).toBe( 0 );
		expect( second.labelled ).toBe( 1 );
	} );

	it( 'sends the measured heading as evidence, not just the pixels', async () => {
		const bodies: string[] = [];
		const fetchImpl = vi.fn( async ( _url: unknown, init: { body?: unknown } ) => {
			bodies.push( String( init.body ?? '' ) );
			return {
				ok: true,
				json: async () => ( {
					choices: [ { message: { content: JSON.stringify( { type: 'comparison', confidence: 0.9 } ) } } ],
				} ),
			} as unknown as Response;
		} );
		await run(
			profileWith(),
			[ { ...shot( 'abc1234', 'desktop', 'a' ), title: 'Compare User Roles' } ],
			fetchImpl as unknown as typeof fetch
		);
		expect( bodies[ 0 ] ).toContain( 'Compare User Roles' );
	} );

	it( 'sends the plain prompt when the band has no heading', async () => {
		const bodies: string[] = [];
		const fetchImpl = vi.fn( async ( _url: unknown, init: { body?: unknown } ) => {
			bodies.push( String( init.body ?? '' ) );
			return {
				ok: true,
				json: async () => ( { choices: [ { message: { content: '{"type":"text","confidence":0.8}' } } ] } ),
			} as unknown as Response;
		} );
		await run( profileWith(), [ shot( 'abc1234', 'desktop', 'a' ) ], fetchImpl as unknown as typeof fetch );
		expect( bodies[ 0 ] ).not.toContain( 'measured from the page' );
	} );

	it( 'never asks about a band that renders a form', async () => {
		const fetchImpl = answering( { type: 'faq', confidence: 0.9 } );
		const outcome = await run(
			profileWith(),
			[ { ...shot( 'abc1234', 'desktop', 'a' ), hasForm: true } ],
			fetchImpl as unknown as typeof fetch
		);
		expect( fetchImpl ).not.toHaveBeenCalled();
		expect( outcome.asked ).toBe( 0 );
		expect( outcome.bands[ 0 ] ).toMatchObject( { skipped: true, type: 'form' } );
		expect( outcome.profile.design?.sections?.[ 0 ]?.structure ).toBeUndefined();
	} );

	it( 'stores nothing when the two device reads disagree', async () => {
		const answers = [
			{ type: 'team', confidence: 0.9 },
			{ type: 'faq', confidence: 0.9 },
		];
		let index = 0;
		const fetchImpl = vi.fn( async () => ( {
			ok: true,
			json: async () => ( { choices: [ { message: { content: JSON.stringify( answers[ index++ ] ) } } ] } ),
		} ) as unknown as Response );
		const outcome = await run(
			profileWith(),
			[ shot( 'abc1234', 'desktop', 'd' ), shot( 'abc1234', 'mobile', 'm' ) ],
			fetchImpl as unknown as typeof fetch
		);
		expect( outcome.conflicts ).toBe( 1 );
		expect( outcome.labelled ).toBe( 0 );
		expect( outcome.profile.design?.sections?.[ 0 ]?.structure ).toBeUndefined();
	} );

	it( 'accepts one confident read when the other end abstains', async () => {
		// A matrix legitimately collapses to cards on a phone, so an abstention is
		// not a contradiction.
		const answers = [
			{ type: 'unknown', confidence: 0 },
			{ type: 'form', confidence: 0.9 },
		];
		let index = 0;
		const fetchImpl = vi.fn( async () => ( {
			ok: true,
			json: async () => ( { choices: [ { message: { content: JSON.stringify( answers[ index++ ] ) } } ] } ),
		} ) as unknown as Response );
		const outcome = await run(
			profileWith(),
			[ shot( 'abc1234', 'desktop', 'd' ), shot( 'abc1234', 'mobile', 'm' ) ],
			fetchImpl as unknown as typeof fetch
		);
		expect( outcome.conflicts ).toBe( 0 );
		expect( outcome.labelled ).toBe( 1 );
		expect( outcome.profile.design?.sections?.[ 0 ]?.structure?.[ 0 ]?.type ).toBe( 'form' );
	} );

	it( 'keys the cache by band AND screenshot content', () => {
		expect( structureCacheKey( 'abc1234', new Uint8Array( Buffer.from( 'a' ) ) ) ).not.toBe(
			structureCacheKey( 'abc1234', new Uint8Array( Buffer.from( 'b' ) ) )
		);
		expect( structureCacheKey( 'abc1234', new Uint8Array( Buffer.from( 'a' ) ) ) ).not.toBe(
			structureCacheKey( 'def5678', new Uint8Array( Buffer.from( 'a' ) ) )
		);
	} );

	it( 'round-trips the cache file and ignores a corrupt one', () => {
		const cache = new Map( [ [ 'abc1234:deadbeef', { type: 'pricing', confidence: 0.8, note: 'three plans' } ] ] );
		const parsed = parseStructureCache( JSON.stringify( serializeStructureCache( cache ) ) );
		expect( parsed.get( 'abc1234:deadbeef' ) ).toEqual( { type: 'pricing', confidence: 0.8, note: 'three plans' } );
		expect( parseStructureCache( 'not json' ).size ).toBe( 0 );
	} );
} );
