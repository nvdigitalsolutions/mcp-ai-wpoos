/**
 * Fleet audit sink (Phase 3 of Proposal 094).
 *
 * Fire-and-forget HTTPS delivery of governance events to a configured sink
 * (`AUDIT_SINK_URL` + `AUDIT_SINK_SECRET`). Bodies are HMAC-SHA256 signed
 * with a `sha256=` signature header (the OutboundWebhook pattern) so the
 * receiver can verify provenance; events never carry tool arguments (tool
 * names, sites, and trace ids only). The gateway stays stateless: no local
 * audit storage, no queue — a failed sink degrades to local logs and the
 * tool call proceeds. Delivery never blocks the request path.
 */

import crypto from 'node:crypto';

/** Retry budget before dropping an event. */
const MAX_RETRIES = 3;

/** Backoff base in ms (exponential: 250, 500, 1000). */
const BACKOFF_BASE_MS = 250;

/**
 * Whether an audit sink is configured.
 *
 * @param {object} cfg Gateway config.
 * @return {boolean} True when both URL and secret are set.
 */
export function auditEnabled( cfg ) {
	return Boolean( cfg?.auditSinkUrl && cfg?.auditSinkSecret );
}

/**
 * HMAC-SHA256 signature for a body (`sha256=<hex>` form).
 *
 * @param {string} secret Shared secret.
 * @param {string} body   Exact body bytes.
 * @return {string} Signature header value.
 */
export function signBody( secret, body ) {
	return 'sha256=' + crypto.createHmac( 'sha256', secret ).update( body ).digest( 'hex' );
}

/**
 * Emit one governance event. Never throws; never blocks callers (callers
 * `void emitAudit(...)`).
 *
 * @param {object} cfg     Gateway config.
 * @param {object} event   Event fields (kind, traceId, key, site, tool, …).
 * @param {number} [retry] Internal retry counter.
 * @return {Promise<void>}
 */
export async function emitAudit( cfg, event, retry = 0 ) {
	if ( ! auditEnabled( cfg ) ) {
		return;
	}
	const payload = { ts: new Date().toISOString(), ...event };
	const body = JSON.stringify( payload );
	try {
		const res = await fetch( cfg.auditSinkUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-Audit-Signature': signBody( cfg.auditSinkSecret, body ),
				'X-Audit-Timestamp': payload.ts,
			},
			body,
			signal: AbortSignal.timeout( 3000 ),
		} );
		if ( ! res.ok && retry < MAX_RETRIES ) {
			await backoff( retry );
			return emitAudit( cfg, event, retry + 1 );
		}
	} catch ( err ) {
		if ( retry < MAX_RETRIES ) {
			await backoff( retry );
			return emitAudit( cfg, event, retry + 1 );
		}
		console.warn( `[audit] sink delivery failed after retries: ${ err.message }` );
	}
}

/**
 * Exponential backoff sleep.
 *
 * @param {number} retry Retry index (0-based).
 * @return {Promise<void>}
 */
function backoff( retry ) {
	return new Promise( ( resolve ) => setTimeout( resolve, BACKOFF_BASE_MS * 2 ** retry ) );
}
