// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// vision-structure.test.ts — the STRUCTURE verdict contract.
//
// The model call itself needs credentials, so these cover the two halves that
// must be deterministic: parsing a verdict out of whatever the model says, and
// the rule that a DETERMINISTIC detector always outranks vision.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	mergeStructureVerdicts,
	parseStructureVerdict,
	STRUCTURE_VOCABULARY,
} from '../lib/vision.ts';

describe( 'parseStructureVerdict', () => {
	it( 'reads a strict JSON answer', () => {
		const verdict = parseStructureVerdict(
			JSON.stringify( { type: 'comparison', confidence: 0.93, note: 'a matrix of ticks' } )
		);
		expect( verdict ).toEqual( { type: 'comparison', confidence: 0.93, note: 'a matrix of ticks' } );
	} );

	it( 'reads a fenced answer and normalises the label', () => {
		const verdict = parseStructureVerdict( '```json\n{ "type": "PRICING", "confidence": 0.8 }\n```' );
		expect( verdict.type ).toBe( 'pricing' );
		expect( verdict.confidence ).toBe( 0.8 );
	} );

	it( 'refuses a label outside the closed vocabulary', () => {
		// A model that invents "carousel-grid" must not become a component name.
		expect( parseStructureVerdict( '{"type":"carousel-grid","confidence":0.99}' ).type ).toBe( 'unknown' );
		expect( STRUCTURE_VOCABULARY ).not.toContain( 'carousel-grid' );
	} );

	it( 'treats prose as unknown rather than guessing', () => {
		const verdict = parseStructureVerdict( 'This looks like a nice band with some cards.' );
		expect( verdict.type ).toBe( 'unknown' );
		expect( verdict.confidence ).toBe( 0 );
	} );

	it( 'clamps confidence and refuses a label with none', () => {
		expect( parseStructureVerdict( '{"type":"stats","confidence":4}' ).confidence ).toBe( 1 );
		expect( parseStructureVerdict( '{"type":"stats","confidence":-1}' ).type ).toBe( 'unknown' );
		expect( parseStructureVerdict( '{"type":"stats"}' ).type ).toBe( 'unknown' );
	} );
} );

describe( 'mergeStructureVerdicts', () => {
	const band = ( type: string, source?: string ) => ( { type, ...( source ? { source } : {} ) } );

	it( 'never overrules a deterministic detector', () => {
		const measured = [ band( 'comparison' ) ];
		const merged = mergeStructureVerdicts( measured, { type: 'timeline', confidence: 0.95 } );
		expect( merged ).toBe( measured );
	} );

	it( 'fills a gap when nothing was measured', () => {
		const merged = mergeStructureVerdicts( [], { type: 'timeline', confidence: 0.9, note: 'a dated line' }, { model: 'llava' } );
		expect( merged ).toHaveLength( 1 );
		expect( merged[ 0 ] ).toMatchObject( { type: 'timeline', source: 'vision', model: 'llava' } );
	} );

	it( 'ignores a low-confidence or unknown verdict', () => {
		expect( mergeStructureVerdicts( [], { type: 'timeline', confidence: 0.4 } ) ).toEqual( [] );
		expect( mergeStructureVerdicts( [], { type: 'unknown', confidence: 0.99 } ) ).toEqual( [] );
	} );
} );
