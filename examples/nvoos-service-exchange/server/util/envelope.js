/**
 * Canonical API envelope.
 *
 * Every route answers either `{ ok: true, data }` or
 * `{ ok: false, error: { code, message, ...extra } }`. Errors are
 * machine-readable codes plus a human message; never raw provider bodies
 * (they can echo credentials).
 */

export function ok( data = null, meta = undefined ) {
	return meta === undefined ? { ok: true, data } : { ok: true, data, meta };
}

export function fail( code, message, extra = {} ) {
	return { ok: false, error: { code, message, ...extra } };
}

export class ApiError extends Error {
	/**
	 * @param {string} code    Stable error code (snake_case).
	 * @param {string} message Human message.
	 * @param {object} extra   Additional safe fields (remediation, tier, ...).
	 * @param {number} status  HTTP status for the server adapter.
	 */
	constructor( code, message, extra = {}, status = 400 ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.extra = extra;
		this.status = status;
	}

	toEnvelope() {
		return fail( this.code, this.message, this.extra );
	}
}

const STATUS_BY_CODE = new Map( [
	[ 'bad_request', 400 ],
	[ 'validation_failed', 422 ],
	[ 'unauthorized', 401 ],
	[ 'forbidden', 403 ],
	[ 'not_found', 404 ],
	[ 'slug_conflict', 409 ],
	[ 'estate_locked', 409 ],
	[ 'rate_limited', 429 ],
	[ 'provider_error', 502 ],
	[ 'provisioning_failed', 502 ],
	[ 'internal_error', 500 ],
] );

export function statusForCode( code ) {
	return STATUS_BY_CODE.get( code ) ?? 400;
}
