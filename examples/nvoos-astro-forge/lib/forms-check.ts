// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// forms-check.ts — the LIVE half of the enquiry contract (`forge forms check`).
//
// `verify --forms` judges the built page: is there a form, does it have a field,
// does it post at the configured endpoint, can the carrier resolve. That gate
// cannot answer the question that actually broke every forged Elementor form in
// 2026-11-24: does the ENDPOINT accept the submission, and which native handler
// took it?
//
// That failure was HTTP 200 + `{"ok":false,"message":"…the form is invalid."}`
// with nothing recorded — invisible to every unit test (they all use the
// dispatch-seam filters) and visible only by submitting in a browser. Hence a
// command instead of a gate, and a read-only default: the live POST is opt-in
// (`--send`) because it creates a real submission and may send real mail.
// ---------------------------------------------------------------------------

/** The bridge's `/health` payload, as far as this command reads it. */
export interface FormsHealth {
	reachable: boolean;
	status: number;
	pluginVersion?: string;
	formsEnabled?: boolean;
	formsConfigured?: boolean;
	formsOriginsCount?: number;
	error?: string;
}

/** One probe result, mapped from the bridge envelope or a WP_Error. */
export interface FormProbeResult {
	/** HTTP status as received. */
	status: number;
	/** `envelope.ok` for the bridge envelope; `status < 400` otherwise. */
	ok: boolean;
	/** The bridge/WP error code when the request was refused. */
	code?: string;
	/** Human message from either envelope. */
	message: string;
	/** Present when the handler reported per-field errors. */
	errors?: unknown;
	/** Set when the request never reached the server. */
	transportError?: string;
}

export interface FormProbeInput {
	endpoint: string;
	/** The allowlisted static origin the bridge checks (the `Origin` header). */
	origin: string;
	/** The carrier `form` value (Elementor widget hash or JFB form id). */
	form: string;
	/** The Elementor carrier post; omitted for JetFormBuilder. */
	postId?: number;
	/** Field names exactly as the built form renders them (`form_fields[name]`). */
	fields: string[];
	/** The page URL recorded as the referrer. */
	page: string;
	/** Bootstrap address; the probe body names this so a human can ignore it. */
	email?: string;
	fetchImpl?: typeof fetch;
}

function trimEndpoint( endpoint: string ): string {
	return endpoint.trim().replace( /\/+$/, '' );
}

/** GET `<endpoint>/health` — the first thing to check, and never destructive. */
export async function fetchFormsHealth(
	endpoint: string,
	fetchImpl: typeof fetch = fetch
): Promise< FormsHealth > {
	const url = `${ trimEndpoint( endpoint ) }/health`;
	try {
		const response = await fetchImpl( url, {
			headers: { accept: 'application/json' },
			signal: AbortSignal.timeout( 20_000 ),
		} );
		const text = await response.text();
		let body: Record< string, unknown > = {};
		try {
			body = JSON.parse( text ) as Record< string, unknown >;
		} catch {
			return {
				reachable: response.ok,
				status: response.status,
				error: response.ok ? 'health returned a non-JSON body' : `HTTP ${ response.status }`,
			};
		}
		return {
			reachable: true,
			status: response.status,
			pluginVersion: typeof body.pluginVersion === 'string' ? body.pluginVersion : undefined,
			formsEnabled: body.formsEnabled === true,
			formsConfigured: body.formsConfigured === true,
			formsOriginsCount: typeof body.formsOriginsCount === 'number' ? body.formsOriginsCount : undefined,
		};
	} catch ( error ) {
		return {
			reachable: false,
			status: 0,
			error: error instanceof Error ? error.message : String( error ),
		};
	}
}

/** The address a probe submits as — obviously machine-generated to a human. */
export const PROBE_EMAIL = 'forge-probe@example.invalid';

/** A value a native handler will accept for a field named like this. */
function probeValue( field: string ): string {
	const name = field.toLowerCase();
	if ( name.includes( 'email' ) ) {
		return PROBE_EMAIL;
	}
	if ( name.includes( 'phone' ) || name.includes( 'tel' ) ) {
		return '0000000000';
	}
	return 'Automated forge probe — no action needed.';
}

/**
 * Build the JSON body the forged component sends.
 *
 * Keys stay EXACTLY as the built form names them (`form_fields[name]`) — that is
 * the wire shape the bridge flattens and the native handler expects, and
 * normalising here would hide the very drift this command exists to catch.
 */
export function probePayload( input: FormProbeInput ): Record< string, unknown > {
	const fields: Record< string, string > = {};
	for ( const name of input.fields ) {
		fields[ name ] = probeValue( name );
	}
	return {
		form: input.form,
		...( input.postId && input.postId > 0 ? { post_id: input.postId } : {} ),
		fields,
		honeypot: '',
		timestamp: Date.now(),
		page: input.page,
	};
}

/**
 * POST a probe submission and map the reply.
 *
 * Recognises both envelopes: the bridge's `{ ok, message, errors }` and a
 * WordPress error (`{ code, message, data:{status} }`).
 */
export async function sendFormProbe( input: FormProbeInput ): Promise< FormProbeResult > {
	const url = `${ trimEndpoint( input.endpoint ) }/forms/submit`;
	const fetchImpl = input.fetchImpl ?? fetch;
	let response: Response;
	try {
		response = await fetchImpl( url, {
			method: 'POST',
			headers: {
				'content-type': 'application/json',
				origin: input.origin,
				accept: 'application/json',
			},
			body: JSON.stringify( probePayload( input ) ),
			signal: AbortSignal.timeout( 60_000 ),
		} );
	} catch ( error ) {
		return {
			status: 0,
			ok: false,
			message: 'the probe never reached the endpoint',
			transportError: error instanceof Error ? error.message : String( error ),
		};
	}

	const text = await response.text();
	let body: Record< string, unknown > = {};
	try {
		body = JSON.parse( text ) as Record< string, unknown >;
	} catch {
		return {
			status: response.status,
			ok: false,
			message: `non-JSON reply: ${ text.slice( 0, 160 ) || '(empty)' }`,
		};
	}

	if ( typeof body.ok === 'boolean' ) {
		return {
			status: response.status,
			ok: body.ok,
			message: typeof body.message === 'string' ? body.message : '',
			errors: body.errors ?? undefined,
		};
	}
	return {
		status: response.status,
		ok: response.ok && ! ( 'code' in body ),
		code: typeof body.code === 'string' ? body.code : undefined,
		message: typeof body.message === 'string' ? body.message : '',
	};
}

/**
 * Turn the health payload into the lines a human needs.
 *
 * The two failures that matter are ordered by what they block: an unreachable
 * endpoint, a disabled bridge, then a missing origin allowlist entry.
 */
export function describeHealth( health: FormsHealth ): string[] {
	if ( ! health.reachable ) {
		return [ `unreachable: ${ health.error ?? 'no response' }` ];
	}
	const lines = [ `plugin ${ health.pluginVersion ?? '?' } (HTTP ${ health.status })` ];
	if ( health.formsEnabled === false ) {
		lines.push( 'form submissions are DISABLED (Settings → Forge Bridge)' );
	}
	if ( health.formsConfigured === false ) {
		lines.push( 'no static origin is allowlisted — every submission will be refused' );
	} else if ( health.formsOriginsCount === 0 ) {
		lines.push( 'the origin allowlist is empty — every submission will be refused' );
	}
	return lines;
}
