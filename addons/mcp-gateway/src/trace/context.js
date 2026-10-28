/**
 * W3C Trace Context propagation (Phase 0 of Proposal 094).
 *
 * The gateway is the trust boundary where external MCP traffic first enters
 * the fleet — exactly the place W3C Trace Context designates for starting a
 * distributed trace. Requests are stamped with a `traceparent` header
 * (version 00, 128-bit trace id, 64-bit span id, flags) that is propagated
 * to every upstream site call and echoed on every structured log line, so a
 * single `traceId` joins gateway logs to per-site audit trails
 * (correlation_id / trace_id on the WordPress side).
 *
 * W3C requirements honoured here:
 *  - traceparent/tracestate carry no PII (random identifiers only);
 *  - an inbound traceparent is inherited, never re-rolled;
 *  - malformed inbound headers are discarded and a fresh trace is started.
 */

import crypto from 'node:crypto';

/** W3C Trace Context version supported. */
export const TRACEPARENT_VERSION = '00';

/**
 * W3C traceparent grammar: version-2HEX, 32-hex trace id (non-zero),
 * 16-hex span id (non-zero), 2-hex flags. `00` version forbids tracestate.
 */
export const TRACEPARENT_RE =
	/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/i;

/**
 * Parse a traceparent header value.
 *
 * @param {string} value Raw header value.
 * @return {{traceId: string, spanId: string, flags: string}|null}
 *         Parsed fields, or null when the header is absent/malformed.
 */
export function parseTraceparent( value ) {
	if ( 'string' !== typeof value || ! value ) {
		return null;
	}
	const trimmed = value.trim();
	const match = TRACEPARENT_RE.exec( trimmed );
	if ( ! match ) {
		return null;
	}
	const [ , traceId, spanId, flags ] = trimmed.split( '-' );
	if ( /^0{32}$/.test( traceId ) || /^0{16}$/.test( spanId ) ) {
		return null;
	}
	return { traceId: traceId.toLowerCase(), spanId: spanId.toLowerCase(), flags };
}

/**
 * Generate a fresh traceparent with a random trace id and span id.
 *
 * @return {string} `00-<trace>-<span>-01` header value.
 */
export function generateTraceparent() {
	const traceId = crypto.randomBytes( 16 ).toString( 'hex' );
	const spanId = crypto.randomBytes( 8 ).toString( 'hex' );
	return `${ TRACEPARENT_VERSION }-${ traceId }-${ spanId }-01`;
}

/**
 * Start a trace for an inbound request, or inherit a valid one.
 *
 * Attaches `traceId` (32-hex) and `traceparent` (full header) to the
 * request object for downstream logging and upstream propagation.
 *
 * @param {import('express').Request} req Request.
 * @return {string} The effective traceparent header value.
 */
export function startOrInherit( req ) {
	const inbound = req.get( 'traceparent' );
	const parsed = parseTraceparent( inbound );
	const traceparent = parsed
		? `${ TRACEPARENT_VERSION }-${ parsed.traceId }-${ parsed.spanId }-01`
		: generateTraceparent();

	req.traceId = parseTraceparent( traceparent ).traceId;
	req.traceparent = traceparent;
	return traceparent;
}

/**
 * Express middleware assigning a trace to every request (runs before the
 * request logger so log lines always carry the trace id).
 *
 * @param {import('express').Request}  req  Request.
 * @param {import('express').Response} res  Response.
 * @param {Function}                   next Next middleware.
 */
export function traceMiddleware( req, res, next ) {
	const traceparent = startOrInherit( req );
	res.setHeader( 'traceparent', traceparent );
	next();
}
