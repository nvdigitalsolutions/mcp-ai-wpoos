// ---------------------------------------------------------------------------
// security-headers.mjs — Proposal 104 D12. BFF security headers on
// Astro-handled responses.
//
// CSP is computed PER RESPONSE from the actual inline scripts in the rendered
// HTML — a blanket 'unsafe-inline' is forbidden, and a hardcoded hash would
// drift whenever Astro changes its injected client-runtime snippets or the
// theme boot changes. Buffering applies ONLY to HTML documents rendered
// through an explicit writeHead (Astro's writeResponse path); static assets
// are served by `send`, which never calls writeHead (node emits implicit
// headers on first write), so those are detected by content-type at the
// first write and pass through unbuffered.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';

export const STATIC_SECURITY_HEADERS = {
	'x-frame-options': 'DENY',
	'x-content-type-options': 'nosniff',
	'referrer-policy': 'strict-origin-when-cross-origin',
	'permissions-policy': 'camera=(), microphone=(), geolocation=()',
};

/** Extract inline scripts (no src, not JSON-LD data blocks) and hash each. */
export function buildCsp(html) {
	const hashes = [];
	const re = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
	for (const match of html.matchAll(re)) {
		const attrs = match[1] ?? '';
		if (/\bsrc\s*=/.test(attrs)) {
			continue; // external module — covered by 'self'
		}
		if (/application\/ld\+json/.test(attrs)) {
			continue; // data block, not executable
		}
		const content = match[2] ?? '';
		if (!content.trim()) {
			continue; // empty script — nothing executable to allow
		}
		// Hash the RAW bytes — browsers compute the hash over the exact
		// element content, whitespace included. Trimming here would produce
		// a hash the browser can never match (the boot script carries
		// indentation).
		hashes.push(`'sha256-${createHash('sha256').update(content).digest('base64')}'`);
	}
	return [
		"default-src 'self'",
		`script-src 'self' ${hashes.join(' ')}`.trim(),
		// Inline style ATTRIBUTES (swatches, gallery) require style-src
		// 'unsafe-inline'; scripts remain hash-only above.
		"style-src 'self' 'unsafe-inline'",
		"img-src 'self' data:",
		"font-src 'self'",
		"connect-src 'self'",
		"frame-ancestors 'none'",
		"base-uri 'self'",
		"form-action 'self'",
	].join('; ');
}

/**
 * Wrap a ServerResponse so HTML documents get security headers whose CSP is
 * computed from the final body. Non-HTML responses (static assets, binary
 * /_image output, plain fallbacks) pass through unbuffered.
 */
export function wrapWithSecurityHeaders(res) {
	if (res.__nvoos_security) {
		return res;
	}

	const originalWriteHead = res.writeHead.bind(res);
	const originalWrite = res.write.bind(res);
	const originalEnd = res.end.bind(res);

	let pending = null; // { statusCode, statusMessage, headers } — explicit writeHead seen
	let chunks = [];
	let passthrough = false;

	res.writeHead = function (...args) {
		const statusCode = args[0];
		let statusMessage;
		let headers;
		if (typeof args[1] === 'string') {
			statusMessage = args[1];
			headers = args[2];
		} else {
			headers = args[1];
		}
		const merged = { ...(headers ?? {}) };
		const isHtml = String(merged['content-type'] ?? '').toLowerCase().includes('text/html');
		if (!isHtml) {
			passthrough = true;
			if (statusMessage !== undefined) {
				return originalWriteHead(statusCode, statusMessage, merged);
			}
			return originalWriteHead(statusCode, merged);
		}
		pending = { statusCode, statusMessage, headers: merged };
		return res;
	};

	res.write = function (chunk, ...rest) {
		if (passthrough) {
			return originalWrite(chunk, ...rest);
		}
		if (pending === null) {
			// No explicit writeHead — node will emit implicit headers on the
			// first write (the `send` static-asset path). Decide from the
			// already-set content-type: buffer only HTML documents.
			const contentType = String(res.getHeader('content-type') ?? '').toLowerCase();
			if (!contentType.includes('text/html')) {
				passthrough = true;
				return originalWrite(chunk, ...rest);
			}
		}
		if (chunk) {
			chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
		}
		return true;
	};

	res.end = function (chunk, ...rest) {
		if (passthrough) {
			return originalEnd(chunk, ...rest);
		}
		if (chunk) {
			chunks.push(
				typeof chunk === 'string'
					? Buffer.from(chunk, rest[0] === 'string' ? rest[0] : 'utf8')
					: Buffer.isBuffer(chunk)
						? chunk
						: Buffer.from(chunk)
			);
		}
		if (pending) {
			const body = Buffer.concat(chunks);
			const headers = {
				...pending.headers,
				...STATIC_SECURITY_HEADERS,
				'content-security-policy': buildCsp(body.toString('utf8')),
			};
			if (pending.statusMessage !== undefined) {
				originalWriteHead(pending.statusCode, pending.statusMessage, headers);
			} else {
				originalWriteHead(pending.statusCode, headers);
			}
			originalWrite(body);
			return originalEnd();
		}
		// Implicit-header path (no explicit writeHead seen): flush whatever
		// was buffered — buffering only ever happens for content-type HTML
		// here, but without a writeHead we cannot inject CSP safely.
		if (chunks.length > 0) {
			originalWrite(Buffer.concat(chunks));
		}
		return originalEnd();
	};

	res.__nvoos_security = true;
	return res;
}
