/**
 * Heartbeat payload v1 validation.
 *
 * The server derives the site slug from the authenticated token — the
 * payload can never spoof another site's identity. Validation follows the
 * two-gate rule's JS equivalent: strict allowlist at entry, and the store
 * only ever receives fields that passed it. Payloads carrying
 * credential-shaped values are rejected outright so secrets can never
 * reach the store or the logs.
 */

/**
 * Status values shared with the WordPress plugin's service-status taxonomy
 * (Interface_WP_MCP_AI_Service_Status_Source). Keep in sync.
 */
export const VALID_STATUSES = [
	'operational',
	'under_maintenance',
	'degraded_performance',
	'partial_outage',
	'major_outage',
];

/** Maximum accepted payload size (bytes). */
export const MAX_PAYLOAD_BYTES = 64 * 1024;

/** Maximum number of components accepted per heartbeat. */
const MAX_COMPONENTS = 64;

/** Maximum message length per component. */
const MAX_MESSAGE_LENGTH = 500;

/**
 * Patterns that indicate a value is a credential. Rejection, not
 * sanitisation — a heartbeat should never need to carry these.
 */
const CREDENTIAL_PATTERNS = [
	/\bsk-[A-Za-z0-9_-]{8,}\b/i, // OpenAI-style keys.
	/\bAIza[0-9A-Za-z_-]{20,}\b/, // Google API keys.
	/\bAKIA[0-9A-Z]{16}\b/, // AWS access key IDs.
	/\bbearer\s+[A-Za-z0-9._~+/=-]{10,}\b/i,
	/\b(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|app[_-]?password|authorization)\b\s*[:=]\s*["']?[A-Za-z0-9._~+/=-]{8,}/i,
	/\bghp_[A-Za-z0-9]{20,}\b/, // GitHub personal access tokens.
];

/**
 * Check a value for credential-shaped content.
 *
 * @param {*} value Any value.
 * @return {boolean} True when the value looks like a secret.
 */
export function looksLikeCredential( value ) {
	if ( 'string' !== typeof value || ! value ) {
		return false;
	}
	return CREDENTIAL_PATTERNS.some( ( pattern ) => pattern.test( value ) );
}

/**
 * Sanitize a URL to its origin form, or '' when invalid.
 *
 * @param {string} raw Raw URL.
 * @return {string} Origin or ''.
 */
function sanitizeSiteUrl( raw ) {
	if ( 'string' !== typeof raw || ! raw ) {
		return '';
	}
	try {
		const url = new URL( raw );
		if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
			return '';
		}
		return url.origin;
	} catch {
		return '';
	}
}

/**
 * Validate and sanitize a heartbeat payload.
 *
 * @param {*}      raw   Parsed JSON body.
 * @param {Object} [opts] Options ({ maxBytes }).
 * @return {{ ok: boolean, payload?: Object, error?: string }} Result.
 */
export function validateHeartbeat( raw, opts = {} ) {
	const maxBytes = Number.isFinite( opts.maxBytes ) ? opts.maxBytes : MAX_PAYLOAD_BYTES;

	if ( ! raw || 'object' !== typeof raw || Array.isArray( raw ) ) {
		return { ok: false, error: 'Payload must be a JSON object' };
	}

	// Size cap before anything else (cheap DoS guard; express already
	// enforces the 10 MB body limit, this is the module-level cap).
	let size = 0;
	try {
		size = JSON.stringify( raw ).length;
	} catch {
		return { ok: false, error: 'Payload is not serializable' };
	}
	if ( size > maxBytes ) {
		return { ok: false, error: 'Payload exceeds the maximum size' };
	}

	// Reject credential-shaped content anywhere in the payload.
	if ( looksLikeCredential( JSON.stringify( raw ) ) ) {
		return { ok: false, error: 'Payload contains credential-shaped content' };
	}

	if ( 1 !== raw.v ) {
		return { ok: false, error: 'Unsupported heartbeat version (v required to be 1)' };
	}

	const payload = {
		v: 1,
		site_url: sanitizeSiteUrl( raw.site_url ),
		sent_at: Number.isFinite( raw.sent_at ) ? Math.floor( raw.sent_at ) : 0,
		checks: { overall: 'operational', components: {} },
		meta: {},
	};

	if ( raw.checks && 'object' === typeof raw.checks ) {
		if ( 'string' === typeof raw.checks.overall && VALID_STATUSES.includes( raw.checks.overall ) ) {
			payload.checks.overall = raw.checks.overall;
		}
		if ( raw.checks.components && 'object' === typeof raw.checks.components ) {
			let count = 0;
			for ( const [ slugRaw, component ] of Object.entries( raw.checks.components ) ) {
				if ( count >= MAX_COMPONENTS ) {
					break;
				}
				const slug = String( slugRaw )
					.toLowerCase()
					.replace( /[^a-z0-9_]+/g, '_' )
					.replace( /^_+|_+$/g, '' )
					.slice( 0, 64 );
				if ( ! slug || ! component || 'object' !== typeof component ) {
					continue;
				}
				const status = 'string' === typeof component.status && VALID_STATUSES.includes( component.status )
					? component.status
					: 'unknown';
				const message = 'string' === typeof component.message
					? component.message.slice( 0, MAX_MESSAGE_LENGTH )
					: '';
				payload.checks.components[ slug ] = { status, message };
				count += 1;
			}
		}
	}

	if ( raw.meta && 'object' === typeof raw.meta ) {
		payload.meta = {
			wp_version: 'string' === typeof raw.meta.wp_version ? raw.meta.wp_version.slice( 0, 32 ) : '',
			php_version: 'string' === typeof raw.meta.php_version ? raw.meta.php_version.slice( 0, 32 ) : '',
			plugin_version: 'string' === typeof raw.meta.plugin_version ? raw.meta.plugin_version.slice( 0, 32 ) : '',
			maintenance_until:
				'number' === typeof raw.meta.maintenance_until && Number.isFinite( raw.meta.maintenance_until )
					? Math.floor( raw.meta.maintenance_until )
					: 0,
		};
	}

	return { ok: true, payload };
}
