// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// forms.ts — the form payload contract shared by BOTH delivery modes.
//
// A forged site turns a submission into an email two ways:
//
//   bridge → POST to WordPress (`/wp-json/nvoos-forge/v1/forms/submit`),
//            which owns the handler, its side effects and its mailer;
//   direct → send from the managed runtime through the provider's HTTP API.
//
// Both read the SAME client envelope and must produce the SAME email from it,
// so the parsing and the message live here rather than in either endpoint.
// `forms.fallback: "direct"` is the case that needs both at once, and a second
// copy of this logic would drift the moment one side changed.
//
// Vendored verbatim into every generated project (`FORGE_LIB_FILES`).
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';

import type { MailMessage } from './mail.ts';

/** Bodies larger than this are refused before parsing (the 64 KB cap). */
export const MAX_BODY_BYTES = 64 * 1024;

/**
 * Fields the form itself owns — never echoed into the email.
 *
 * `cf-turnstile-response` is reserved even when no widget is configured:
 * Turnstile injects it into the form, so it must never reach the body.
 */
export const INTERNAL_FIELDS: ReadonlySet< string > = new Set( [
	'honeypot',
	'timestamp',
	'_redirect',
	'form',
	'post_id',
	'cf-turnstile-response',
] );

/**
 * Harvested-payload noise — sent by bots, never by this site's form.
 *
 * A contact-form payload sprayed at the route arrives with the scraper's field
 * list attached: the submit button, a decade-old reCAPTCHA hidden input, and
 * Contact Form 7's own bookkeeping. They mean the sender never rendered our
 * form, so echoing them only pads the email (virtuallyjamaica, 2026-11-16).
 */
export const NOISE_FIELDS: ReadonlySet< string > = new Set( [
	'submit',
	'g-recaptcha-response',
	'h-captcha-response',
	'_wpcf7',
	'_wpcf7_version',
	'_wpcf7_locale',
	'_wpcf7_unit_tag',
	'_wpcf7_container_post',
	'_wpcf7_posted_data_hash',
] );

/**
 * The first field name that proves the payload came from a harvested form.
 *
 * This site's form never emits any `NOISE_FIELDS` name, so their presence
 * means the sender scraped a form definition elsewhere — typically the
 * pre-forge WordPress CF7 form. A minted Turnstile token does not make such a
 * payload legitimate (virtuallyjamaica, 2026-11-16 12:24 UTC).
 */
export function harvestedField( fields: Record< string, string > ): string | undefined {
	for ( const key of Object.keys( fields ) ) {
		if ( NOISE_FIELDS.has( key ) ) {
			return key;
		}
	}
	return undefined;
}

/**
 * True when `page` names the expected host.
 *
 * `page` is the carrier the form's own script always sends
 * (`page: window.location.href`). It is not proof of humanity — a bot can
 * forge it — but a flat replayed payload does not carry it, which closes the
 * cheapest attack on a route that already verifies tokens.
 */
export function carriesPage( fields: Record< string, string >, expectedHost: string ): boolean {
	const page = ( fields.page ?? '' ).trim();
	if ( page === '' || expectedHost === '' ) {
		return false;
	}
	try {
		return new URL( page ).hostname === expectedHost;
	} catch {
		return false;
	}
}

/**
 * Normalise a submission to string pairs, or `undefined` when it cannot be
 * parsed or exceeds the cap.
 *
 * The shared client posts `{ form, fields: { name, email, … }, honeypot,
 * timestamp, page }` in BOTH delivery modes — the envelope the WordPress
 * bridge reads. The nested bag is flattened FIRST so the visitor values (and
 * the Turnstile token) survive; the top-level carriers then win on a name
 * collision. Reading only top-level scalars dropped every visitor value
 * (measured 2026-11-15: emails arrived with no name/email/message, and
 * Reply-To never resolved).
 */
export async function readSubmissionFields( request: Request ): Promise< Record< string, string > | undefined > {
	const raw = await request.text();
	if ( raw.length > MAX_BODY_BYTES ) {
		return undefined;
	}
	const type = request.headers.get( 'content-type' ) ?? '';
	try {
		if ( type.includes( 'application/json' ) ) {
			const parsed = JSON.parse( raw ) as Record< string, unknown >;
			const out: Record< string, string > = {};
			const nested = parsed.fields;
			if ( nested && typeof nested === 'object' && ! Array.isArray( nested ) ) {
				for ( const [ key, value ] of Object.entries( nested as Record< string, unknown > ) ) {
					if ( typeof value === 'string' || typeof value === 'number' ) {
						out[ key ] = String( value );
					}
				}
			}
			for ( const [ key, value ] of Object.entries( parsed ) ) {
				if ( key === 'fields' ) {
					continue;
				}
				if ( typeof value === 'string' || typeof value === 'number' ) {
					out[ key ] = String( value );
				}
			}
			return out;
		}
		const form = await new Response( raw, { headers: { 'content-type': type } } ).formData();
		const out: Record< string, string > = {};
		for ( const [ key, value ] of form.entries() ) {
			if ( typeof value === 'string' ) {
				out[ key ] = value;
			}
		}
		return out;
	} catch {
		return undefined;
	}
}

/**
 * Build the email for a submission, or `undefined` when there is nothing to
 * send.
 *
 * The digest of the filtered body is the provider idempotency key
 * (`Idempotency-Key` semantics — draft-ietf-httpapi-idempotency-key-header):
 * the same payload sent twice inside one window is ONE email, so a
 * double-click, a retried request or a bridge→direct fallback cannot deliver
 * twice. A different payload yields a different key, which is exactly the
 * contract the draft requires.
 */
export function submissionMessage( fields: Record< string, string > ): MailMessage | undefined {
	const rows = Object.entries( fields )
		.filter( ( [ key ] ) => ! INTERNAL_FIELDS.has( key ) && ! NOISE_FIELDS.has( key ) )
		.map( ( [ key, value ] ) => `${ key }: ${ String( value ).slice( 0, 2000 ) }` );
	if ( rows.length === 0 ) {
		return undefined;
	}
	const replyTo = ( fields.email ?? fields[ 'e-mail' ] ?? '' ).trim();
	const NL = String.fromCharCode( 10 );
	const digest = createHash( 'sha256' ).update( rows.join( NL ) ).digest( 'hex' ).slice( 0, 32 );
	return {
		text: `${ rows.join( NL ) }${ NL }${ NL }--${ NL }Sent from a form on the website.`,
		replyTo: replyTo.includes( '@' ) ? replyTo : undefined,
		idempotencyKey: digest,
	};
}
