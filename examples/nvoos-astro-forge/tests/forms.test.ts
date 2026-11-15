// ---------------------------------------------------------------------------
// forms.test.ts — the payload contract BOTH delivery modes share.
//
// The direct endpoint and the bridge's `forms.fallback` path call these two
// functions, so a drift here would mean two different emails for the same
// submission. The rules pinned: the nested client envelope is flattened, the
// reserved fields never reach the body, and the idempotency key is a pure
// function of the payload (same payload → same key → one email).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { INTERNAL_FIELDS, MAX_BODY_BYTES, readSubmissionFields, submissionMessage } from '../lib/forms.ts';

function jsonRequest( body: unknown ): Request {
	return new Request( 'https://example.com/api/forms/submit', {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify( body ),
	} );
}

describe( 'readSubmissionFields', () => {
	it( 'flattens the nested client envelope', async () => {
		const fields = await readSubmissionFields(
			jsonRequest( {
				form: 'contact',
				fields: { name: 'Ada', email: 'ada@example.com', message: 'Hi' },
				honeypot: '',
				timestamp: 1,
				page: '/contact/',
			} )
		);

		expect( fields ).toEqual( {
			name: 'Ada',
			email: 'ada@example.com',
			message: 'Hi',
			form: 'contact',
			honeypot: '',
			timestamp: '1',
			page: '/contact/',
		} );
	} );

	it( 'lets a top-level carrier win over a nested collision', async () => {
		const fields = await readSubmissionFields(
			jsonRequest( { form: 'outer', fields: { form: 'inner', name: 'Ada' } } )
		);

		expect( fields?.form ).toBe( 'outer' );
		expect( fields?.name ).toBe( 'Ada' );
	} );

	it( 'reads a form-encoded submission (the no-JS floor)', async () => {
		const body = new URLSearchParams( { email: 'ada@example.com', _redirect: '/contact/' } );
		const fields = await readSubmissionFields(
			new Request( 'https://example.com/x', {
				method: 'POST',
				headers: { 'content-type': 'application/x-www-form-urlencoded' },
				body,
			} )
		);

		expect( fields ).toEqual( { email: 'ada@example.com', _redirect: '/contact/' } );
	} );

	it( 'refuses a body over the cap without parsing it', async () => {
		const huge = 'x'.repeat( MAX_BODY_BYTES + 1 );

		expect( await readSubmissionFields( jsonRequest( { fields: { message: huge } } ) ) ).toBeUndefined();
	} );

	it( 'returns undefined for malformed JSON', async () => {
		const request = new Request( 'https://example.com/x', {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: '{not json',
		} );

		expect( await readSubmissionFields( request ) ).toBeUndefined();
	} );
} );

describe( 'submissionMessage', () => {
	const fields = {
		name: 'Ada',
		email: 'ada@example.com',
		message: 'Hello',
		honeypot: '',
		timestamp: '1',
		form: 'contact',
		'cf-turnstile-response': 'token',
	};

	it( 'keeps the visitor values and drops every reserved field', () => {
		const message = submissionMessage( fields );

		expect( message?.text ).toContain( 'name: Ada' );
		expect( message?.text ).toContain( 'email: ada@example.com' );
		expect( message?.text ).toContain( 'message: Hello' );
		for ( const reserved of INTERNAL_FIELDS ) {
			expect( message?.text ).not.toContain( `${ reserved }: ` );
		}
	} );

	it( 'resolves Reply-To from the visitor address', () => {
		expect( submissionMessage( fields )?.replyTo ).toBe( 'ada@example.com' );
		expect( submissionMessage( { name: 'Ada', 'e-mail': 'a@b.co' } )?.replyTo ).toBe( 'a@b.co' );
		expect( submissionMessage( { name: 'Ada' } )?.replyTo ).toBeUndefined();
	} );

	it( 'derives a stable idempotency key from the payload alone', () => {
		const first = submissionMessage( fields )?.idempotencyKey;
		const second = submissionMessage( { ...fields } )?.idempotencyKey;

		expect( first ).toBeDefined();
		expect( first ).toBe( second );
		// A different payload is a different key — the draft's core rule.
		expect( submissionMessage( { ...fields, message: 'Different' } )?.idempotencyKey ).not.toBe( first );
		// A reserved field never reaches the body, so it cannot change the key.
		expect( submissionMessage( { ...fields, honeypot: 'bot' } )?.idempotencyKey ).toBe( first );
	} );

	it( 'returns undefined when only reserved fields were submitted', () => {
		expect( submissionMessage( { honeypot: '', timestamp: '1', form: 'contact' } ) ).toBeUndefined();
	} );
} );
