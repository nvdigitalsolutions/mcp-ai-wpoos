// ---------------------------------------------------------------------------
// vision.test.ts — the optional vision layer (Proposal 118): env resolution,
// data-URL shaping, request building, verdict parsing, fail-soft contract.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { COMPARE_PROMPT, dataUrl, parseVerdict, visionCompare, visionConfig, visionRun } from '../lib/vision.ts';

const FAKE_CONFIG = { baseUrl: 'https://vision.example.com/v1', key: 'sk-test', model: 'gpt-4o-mini' };

describe( 'visionConfig', () => {
	it( 'resolves the configured env and defaults the base/model', () => {
		const config = visionConfig( { NVOOS_FORGE_VISION_KEY: 'sk-x' } );
		expect( config ).toEqual( { baseUrl: 'https://api.openai.com/v1', key: 'sk-x', model: 'gpt-4o-mini' } );
	} );

	it( 'honors the base/model overrides and strips the trailing slash', () => {
		const config = visionConfig( {
			NVOOS_FORGE_VISION_KEY: 'sk-x',
			NVOOS_FORGE_VISION_BASE_URL: 'https://ollama.local/v1/',
			NVOOS_FORGE_VISION_MODEL: 'llava',
		} );
		expect( config ).toEqual( { baseUrl: 'https://ollama.local/v1', key: 'sk-x', model: 'llava' } );
	} );

	it( 'is undefined without a key — callers skip', () => {
		expect( visionConfig( { NVOOS_FORGE_VISION_BASE_URL: 'https://x/v1' } ) ).toBeUndefined();
		expect( visionConfig( {} ) ).toBeUndefined();
	} );
} );

describe( 'dataUrl / parseVerdict', () => {
	it( 'renders the data-URL form', () => {
		expect( dataUrl( { data: 'aGVsbG8=', mime: 'image/png' } ) ).toBe( 'data:image/png;base64,aGVsbG8=' );
	} );

	it( 'parses strict JSON verdicts', () => {
		expect( parseVerdict( '{"fidelity": 80, "divergences": []}' ) ).toEqual( { fidelity: 80, divergences: [] } );
	} );

	it( 'parses fenced JSON and falls back to prose', () => {
		expect( parseVerdict( '```json\n{"fidelity": 60}\n```' ) ).toEqual( { fidelity: 60 } );
		expect( parseVerdict( 'Looks fine to me.' ) ).toEqual( { fidelity: null, summary: 'Looks fine to me.' } );
	} );
} );

describe( 'visionCompare (fail-soft contract)', () => {
	const img = ( id: string ) => ( { data: id, mime: 'image/png' } );

	it( 'sends both images as data URLs to the OpenAI-compatible endpoint', async () => {
		let captured: { url?: string; headers?: Headers; body?: string } = {};
		const fetchImpl = ( async ( url: string, init: RequestInit ) => {
			captured = { url, headers: new Headers( init.headers ), body: String( init.body ) };
			return new Response( JSON.stringify( { choices: [ { message: { content: '{"fidelity": 85}' } } ] } ), {
				status: 200,
				headers: { 'Content-Type': 'application/json' },
			} );
		} ) as typeof fetch;

		const outcome = await visionCompare( FAKE_CONFIG, img( 'AAA' ), img( 'BBB' ), 'compare', fetchImpl );
		expect( captured.url ).toBe( 'https://vision.example.com/v1/chat/completions' );
		expect( captured.headers?.get( 'Authorization' ) ).toBe( 'Bearer sk-test' );
		const body = JSON.parse( captured.body ?? '{}' );
		expect( body.model ).toBe( 'gpt-4o-mini' );
		expect( body.response_format ).toEqual( { type: 'json_object' } );
		const content = body.messages[ 0 ].content;
		expect( content[ 0 ] ).toEqual( { type: 'text', text: 'compare' } );
		expect( content[ 1 ] ).toEqual( { type: 'image_url', image_url: { url: 'data:image/png;base64,AAA' } } );
		expect( content[ 2 ] ).toEqual( { type: 'image_url', image_url: { url: 'data:image/png;base64,BBB' } } );
		expect( outcome.ok ).toBe( true );
		if ( outcome.ok ) {
			expect( outcome.verdict ).toEqual( { fidelity: 85 } );
		}
	} );

	it( 'returns a typed failure on network errors — never throws', async () => {
		const fetchImpl = ( async () => {
			throw new Error( 'ECONNREFUSED' );
		} ) as unknown as typeof fetch;
		const outcome = await visionCompare( FAKE_CONFIG, img( 'A' ), img( 'B' ), undefined, fetchImpl );
		expect( outcome.ok ).toBe( false );
		if ( ! outcome.ok ) {
			expect( outcome.error ).toContain( 'ECONNREFUSED' );
		}
	} );

	it( 'returns a typed failure on non-2xx responses', async () => {
		const fetchImpl = ( async () => new Response( 'unauthorized', { status: 401 } ) ) as unknown as typeof fetch;
		const outcome = await visionCompare( FAKE_CONFIG, img( 'A' ), img( 'B' ), undefined, fetchImpl );
		expect( outcome.ok ).toBe( false );
	} );

	it( 'ships the concrete comparison prompt', () => {
		expect( COMPARE_PROMPT ).toContain( '"fidelity"' );
		expect( COMPARE_PROMPT ).toContain( '"divergences"' );
		expect( COMPARE_PROMPT ).toContain( 'ORIGINAL WordPress' );
	} );

	it( 'runs single-image analysis through the same contract', async () => {
		const fetchImpl = ( async () =>
			new Response( JSON.stringify( { choices: [ { message: { content: '{"ok":true}' } } ] } ), { status: 200 } ) ) as unknown as typeof fetch;
		const outcome = await visionRun( FAKE_CONFIG, [ img( 'A' ) ], 'describe', fetchImpl );
		expect( outcome.ok ).toBe( true );
	} );
} );
