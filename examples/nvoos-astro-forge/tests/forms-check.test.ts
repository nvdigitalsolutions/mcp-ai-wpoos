// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// forms-check.test.ts — the live half of the enquiry contract.
//
// `verify --forms` reads the build; this half talks to the endpoint. Every case
// maps to a real reply the bridge (or WordPress) actually returned on
// 2026-11-24 while the forged forms were being wired.
// ---------------------------------------------------------------------------

import { describe, expect, it, vi } from 'vitest';

import {
	PROBE_EMAIL,
	describeHealth,
	fetchFormsHealth,
	probePayload,
	sendFormProbe,
} from '../lib/forms-check.ts';

const ENDPOINT = 'https://eca.directory/wp-json/nvoos-forge/v1';

function jsonResponse( body: unknown, status = 200 ): Response {
	return new Response( JSON.stringify( body ), {
		status,
		headers: { 'content-type': 'application/json' },
	} );
}

describe( 'probePayload', () => {
	const base = {
		endpoint: ENDPOINT,
		origin: 'https://static.example.com',
		form: '329253bd',
		page: 'https://static.example.com/',
	};

	it( 'keeps the wire field names exactly as the built form emits them', () => {
		const payload = probePayload( {
			...base,
			fields: [ 'form_fields[name]', 'form_fields[email]', 'form_fields[message]' ],
		} );
		const fields = payload.fields as Record< string, string >;
		expect( Object.keys( fields ) ).toEqual( [
			'form_fields[name]',
			'form_fields[email]',
			'form_fields[message]',
		] );
	} );

	it( 'submits a machine-generated address so a human can ignore the record', () => {
		const payload = probePayload( { ...base, fields: [ 'form_fields[email]' ] } );
		expect( ( payload.fields as Record< string, string > )[ 'form_fields[email]' ] ).toBe( PROBE_EMAIL );
		expect( PROBE_EMAIL ).toMatch( /\.invalid$/ );
	} );

	it( 'carries post_id only when the built form has a real carrier', () => {
		expect( probePayload( { ...base, postId: 2, fields: [ 'a' ] } ).post_id ).toBe( 2 );
		expect( probePayload( { ...base, postId: 0, fields: [ 'a' ] } ) ).not.toHaveProperty( 'post_id' );
		expect( probePayload( { ...base, fields: [ 'a' ] } ) ).not.toHaveProperty( 'post_id' );
	} );

	it( 'always sends an empty honeypot and a fresh numeric timestamp', () => {
		const payload = probePayload( { ...base, postId: 2, fields: [ 'a' ] } );
		expect( payload.honeypot ).toBe( '' );
		expect( typeof payload.timestamp ).toBe( 'number' );
		expect( Math.abs( Date.now() - ( payload.timestamp as number ) ) ).toBeLessThan( 5_000 );
	} );
} );

describe( 'fetchFormsHealth', () => {
	it( 'maps the bridge payload', async () => {
		const fetchImpl = vi.fn( async () =>
			jsonResponse( {
				status: 'ok',
				pluginVersion: '0.3.4',
				formsEnabled: true,
				formsConfigured: true,
				formsOriginsCount: 1,
			} )
		) as unknown as typeof fetch;
		const health = await fetchFormsHealth( ENDPOINT, fetchImpl );
		expect( health ).toMatchObject( {
			reachable: true,
			status: 200,
			pluginVersion: '0.3.4',
			formsEnabled: true,
			formsConfigured: true,
			formsOriginsCount: 1,
		} );
		expect( String( ( fetchImpl as unknown as ReturnType< typeof vi.fn > ).mock.calls[ 0 ][ 0 ] ) ).toBe(
			`${ ENDPOINT }/health`
		);
	} );

	it( 'reports an unreachable endpoint instead of throwing', async () => {
		const fetchImpl = ( async () => {
			throw new Error( 'getaddrinfo ENOTFOUND' );
		} ) as unknown as typeof fetch;
		const health = await fetchFormsHealth( ENDPOINT, fetchImpl );
		expect( health.reachable ).toBe( false );
		expect( health.error ).toMatch( /ENOTFOUND/ );
	} );

	it( 'reports a non-JSON health body (a cached HTML page, a WAF interstitial)', async () => {
		const fetchImpl = ( async () => new Response( '<html>maintenance</html>', { status: 200 } ) ) as unknown as typeof fetch;
		const health = await fetchFormsHealth( ENDPOINT, fetchImpl );
		expect( health.reachable ).toBe( true );
		expect( health.error ).toMatch( /non-JSON/ );
	} );
} );

describe( 'sendFormProbe', () => {
	const input = {
		endpoint: ENDPOINT,
		origin: 'https://static.example.com',
		form: '329253bd',
		postId: 2,
		fields: [ 'form_fields[name]' ],
		page: 'https://static.example.com/',
	};

	it( 'maps the bridge success envelope', async () => {
		const fetchImpl = ( async () =>
			jsonResponse( { ok: true, message: 'Your submission was successful.', errors: null } ) ) as unknown as typeof fetch;
		const result = await sendFormProbe( { ...input, fetchImpl } );
		expect( result ).toMatchObject( {
			status: 200,
			ok: true,
			message: 'Your submission was successful.',
		} );
	} );

	it( 'maps the silent failure that started this (200 with ok:false)', async () => {
		const fetchImpl = ( async () =>
			jsonResponse( {
				ok: false,
				message: 'Your submission failed because the form is invalid.',
				errors: [],
			} ) ) as unknown as typeof fetch;
		const result = await sendFormProbe( { ...input, fetchImpl } );
		expect( result.ok ).toBe( false );
		expect( result.status ).toBe( 200 );
		expect( result.message ).toMatch( /form is invalid/ );
	} );

	it( 'maps the 0.3.4 typed refusal', async () => {
		const fetchImpl = ( async () =>
			jsonResponse( {
				code: 'nvoos_forge_form_post_id_required',
				message: 'The Elementor form needs the post_id of the page that carries it.',
				data: { status: 400 },
			}, 400 ) ) as unknown as typeof fetch;
		const result = await sendFormProbe( { ...input, fetchImpl } );
		expect( result ).toMatchObject( { status: 400, ok: false, code: 'nvoos_forge_form_post_id_required' } );
	} );

	it( 'sends the allowlisted origin and the JSON body the component sends', async () => {
		const fetchImpl = vi.fn( async () => jsonResponse( { ok: true, message: 'ok' } ) ) as unknown as typeof fetch;
		await sendFormProbe( { ...input, fetchImpl } );
		const [ url, init ] = ( fetchImpl as unknown as ReturnType< typeof vi.fn > ).mock.calls[ 0 ] as [
			string,
			RequestInit,
		];
		expect( url ).toBe( `${ ENDPOINT }/forms/submit` );
		expect( ( init.headers as Record< string, string > ).origin ).toBe( 'https://static.example.com' );
		const body = JSON.parse( String( init.body ) ) as Record< string, unknown >;
		expect( body ).toMatchObject( { form: '329253bd', post_id: 2, honeypot: '' } );
	} );

	it( 'reports a transport failure without throwing', async () => {
		const fetchImpl = ( async () => {
			throw new Error( 'socket hang up' );
		} ) as unknown as typeof fetch;
		const result = await sendFormProbe( { ...input, fetchImpl } );
		expect( result ).toMatchObject( { status: 0, ok: false } );
		expect( result.transportError ).toMatch( /socket hang up/ );
	} );

	it( 'never treats a non-JSON reply as success', async () => {
		const fetchImpl = ( async () => new Response( 'OK', { status: 200 } ) ) as unknown as typeof fetch;
		const result = await sendFormProbe( { ...input, fetchImpl } );
		expect( result.ok ).toBe( false );
		expect( result.message ).toMatch( /non-JSON/ );
	} );
} );

describe( 'describeHealth', () => {
	it( 'leads with unreachable', () => {
		expect( describeHealth( { reachable: false, status: 0, error: 'boom' } )[ 0 ] ).toMatch( /unreachable: boom/ );
	} );

	it( 'calls out a disabled bridge and an empty allowlist', () => {
		const lines = describeHealth( {
			reachable: true,
			status: 200,
			pluginVersion: '0.3.4',
			formsEnabled: false,
			formsConfigured: false,
			formsOriginsCount: 0,
		} );
		expect( lines.join( ' ' ) ).toMatch( /DISABLED/ );
		expect( lines.join( ' ' ) ).toMatch( /no static origin/ );
	} );

	it( 'is quiet when the bridge is ready', () => {
		const lines = describeHealth( {
			reachable: true,
			status: 200,
			pluginVersion: '0.3.4',
			formsEnabled: true,
			formsConfigured: true,
			formsOriginsCount: 1,
		} );
		expect( lines ).toHaveLength( 1 );
		expect( lines[ 0 ] ).toMatch( /0\.3\.4/ );
	} );
} );
