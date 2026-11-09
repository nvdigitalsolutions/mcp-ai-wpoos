// ---------------------------------------------------------------------------
// mail.test.ts — direct-delivery provider layer.
//
// Deliverability is a DOMAIN concern (SPF/DKIM/DMARC), so the code's job is
// narrow and must be exact: the right endpoint, the right auth header, the
// right body, no header injection, and a clean failure contract.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	brevoRequest,
	buildMailRequest,
	formatFrom,
	mailjetRequest,
	missingSecret,
	readMailConfig,
	sendMail,
} from '../lib/mail.ts';
import type { MailConfig } from '../lib/mail.ts';

const config = ( overrides: Partial< MailConfig > = {} ): MailConfig => ( {
	provider: 'brevo',
	from: 'forms@mail.example.com',
	fromName: 'Example Site',
	to: 'hello@example.com',
	subject: 'New enquiry',
	brevoKey: 'brevo-key',
	mailjetKey: 'mj-key',
	mailjetSecret: 'mj-secret',
	...overrides,
} );

const message = { text: 'Name: Ada\nMessage: Hello', replyTo: 'ada@example.com' };

describe( 'formatFrom', () => {
	it( 'includes the display name when one is set', () => {
		expect( formatFrom( { from: 'forms@mail.example.com', fromName: 'Example Site' } ) ).toBe(
			'Example Site <forms@mail.example.com>'
		);
	} );

	it( 'falls back to the bare address', () => {
		expect( formatFrom( { from: 'forms@mail.example.com' } ) ).toBe( 'forms@mail.example.com' );
	} );

	it( 'strips CR/LF so a name cannot inject a header', () => {
		expect( formatFrom( { from: 'a@b.c', fromName: 'Evil\r\nBcc: x@y.z' } ) ).toBe( 'Evil Bcc: x@y.z <a@b.c>' );
	} );
} );

describe( 'brevoRequest', () => {
	it( 'POSTs to the transactional endpoint with the api-key header', () => {
		const request = brevoRequest( config(), message );
		expect( request.url ).toBe( 'https://api.brevo.com/v3/smtp/email' );
		expect( request.headers[ 'api-key' ] ).toBe( 'brevo-key' );
		expect( request.headers[ 'content-type' ] ).toBe( 'application/json' );
	} );

	it( 'carries sender, recipient, subject, text and reply-to', () => {
		const body = JSON.parse( brevoRequest( config(), message ).body );
		expect( body.sender ).toEqual( { email: 'forms@mail.example.com', name: 'Example Site' } );
		expect( body.to ).toEqual( [ { email: 'hello@example.com' } ] );
		expect( body.subject ).toBe( 'New enquiry' );
		expect( body.textContent ).toBe( message.text );
		expect( body.replyTo ).toEqual( { email: 'ada@example.com' } );
	} );

	it( 'forwards an idempotency key as a header', () => {
		const body = JSON.parse( brevoRequest( config(), { ...message, idempotencyKey: 'abc123' } ).body );
		expect( body.headers ).toEqual( { idempotencyKey: 'abc123' } );
	} );

	it( 'omits reply-to when absent', () => {
		const body = JSON.parse( brevoRequest( config(), { text: 'x' } ).body );
		expect( body.replyTo ).toBeUndefined();
	} );
} );

describe( 'mailjetRequest', () => {
	it( 'uses HTTP Basic auth from the key/secret pair', () => {
		const request = mailjetRequest( config( { provider: 'mailjet' } ), message );
		expect( request.url ).toBe( 'https://api.mailjet.com/v3.1/send' );
		expect( request.headers.authorization ).toBe(
			`Basic ${ Buffer.from( 'mj-key:mj-secret' ).toString( 'base64' ) }`
		);
	} );

	it( 'wraps the payload in a Messages array', () => {
		const body = JSON.parse( mailjetRequest( config( { provider: 'mailjet' } ), message ).body );
		expect( body.Messages ).toHaveLength( 1 );
		expect( body.Messages[ 0 ] ).toMatchObject( {
			From: { Email: 'forms@mail.example.com', Name: 'Example Site' },
			To: [ { Email: 'hello@example.com' } ],
			Subject: 'New enquiry',
			TextPart: message.text,
			ReplyTo: { Email: 'ada@example.com' },
		} );
	} );
} );

describe( 'buildMailRequest — provider dispatch', () => {
	it( 'picks the provider from the config, not the environment', () => {
		expect( buildMailRequest( config( { provider: 'brevo' } ), message ).url ).toContain( 'brevo.com' );
		expect( buildMailRequest( config( { provider: 'mailjet' } ), message ).url ).toContain( 'mailjet.com' );
	} );
} );

describe( 'readMailConfig', () => {
	it( 'defaults to brevo and a generic subject', () => {
		const read = readMailConfig( { MAIL_FROM: 'a@b.c', MAIL_TO: 'd@e.f' } );
		expect( read.provider ).toBe( 'brevo' );
		expect( read.subject ).toBe( 'New website enquiry' );
	} );

	it( 'reads mailjet only when MAIL_PROVIDER says so', () => {
		expect( readMailConfig( { MAIL_PROVIDER: 'mailjet' } ).provider ).toBe( 'mailjet' );
		expect( readMailConfig( { MAIL_PROVIDER: 'sendgrid' } ).provider ).toBe( 'brevo' );
	} );

	it( 'takes secrets from the environment only', () => {
		const read = readMailConfig( { BREVO_API_KEY: 'k', MAILJET_API_KEY: 'j', MAILJET_SECRET_KEY: 's' } );
		expect( read.brevoKey ).toBe( 'k' );
		expect( read.mailjetKey ).toBe( 'j' );
		expect( read.mailjetSecret ).toBe( 's' );
	} );
} );

describe( 'missingSecret', () => {
	it( 'names the env var the operator has to set', () => {
		expect( missingSecret( config( { provider: 'brevo', brevoKey: undefined } ) ) ).toContain( 'BREVO_API_KEY' );
		expect(
			missingSecret( config( { provider: 'mailjet', mailjetSecret: undefined } ) )
		).toContain( 'MAILJET_API_KEY and MAILJET_SECRET_KEY' );
	} );

	it( 'is undefined when the provider is configured', () => {
		expect( missingSecret( config() ) ).toBeUndefined();
	} );
} );

describe( 'sendMail — the failure contract', () => {
	it( 'succeeds on a 2xx', async () => {
		const calls: string[] = [];
		const fakeFetch = ( async ( url: string ) => {
			calls.push( url );
			return new Response( '{}', { status: 201 } );
		} ) as unknown as typeof fetch;

		const result = await sendMail( config(), message, fakeFetch );
		expect( result ).toEqual( { ok: true, status: 201 } );
		expect( calls ).toHaveLength( 1 );
	} );

	it( 'reports the provider status without throwing', async () => {
		const fakeFetch = ( async () => new Response( 'invalid key', { status: 401 } ) ) as unknown as typeof fetch;
		const result = await sendMail( config(), message, fakeFetch );
		expect( result.ok ).toBe( false );
		expect( result.status ).toBe( 401 );
		expect( result.detail ).toContain( 'invalid key' );
	} );

	it( 'maps a network failure to 502', async () => {
		const fakeFetch = ( async () => {
			throw new Error( 'ECONNRESET' );
		} ) as unknown as typeof fetch;
		const result = await sendMail( config(), message, fakeFetch );
		expect( result ).toMatchObject( { ok: false, status: 502 } );
	} );

	it( 'never calls the provider without a secret', async () => {
		let called = false;
		const fakeFetch = ( async () => {
			called = true;
			return new Response( '{}' );
		} ) as unknown as typeof fetch;
		const result = await sendMail( config( { brevoKey: undefined } ), message, fakeFetch );
		expect( called ).toBe( false );
		expect( result.ok ).toBe( false );
		expect( result.detail ).toContain( 'BREVO_API_KEY' );
	} );

	it( 'refuses an incomplete recipient configuration', async () => {
		const fakeFetch = ( async () => new Response( '{}' ) ) as unknown as typeof fetch;
		const result = await sendMail( config( { to: '' } ), message, fakeFetch );
		expect( result.ok ).toBe( false );
		expect( result.detail ).toContain( 'MAIL_TO' );
	} );
} );
