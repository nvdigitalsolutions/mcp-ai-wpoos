// ---------------------------------------------------------------------------
// fetcher.ts — SSRF-safe design-source fetch (Proposal 098 Phase 0.2).
//
// OWASP SSRF cheat-sheet discipline: scheme allowlist, no credentials in the
// URL, DNS-resolved private/link-local/reserved address rejection on EVERY
// redirect hop, redirect cap, timeout, and byte caps. The private-host gate
// is opt-in via `allowPrivate` (the MCP surface maps it to
// NVOOS_MCP_FETCH_ALLOW_PRIVATE=1 for local-dev analysis only).
//
// Honest limitation: plain fetch re-resolves DNS at request time, so this is
// rebinding-aware, not rebinding-proof. The tool is read-only and emits only
// token data, which bounds the exposure until Phase 2 adds pinned-IP capture.
// ---------------------------------------------------------------------------

import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';
import type { SourceMode } from './types';

/** Errors from the fetch guard — machine-readable codes for tool results. */
export class FetchGuardError extends Error {
	code: string;

	constructor(code: string, message: string) {
		super(message);
		this.name = 'FetchGuardError';
		this.code = code;
	}
}

/** Shared guard options for every fetch helper in this module. */
export interface FetchGuardOptions {
	/** Overall per-request timeout. Default 10s. */
	timeoutMs?: number;
	/** Redirect budget. Default 5. */
	maxRedirects?: number;
	/** Body cap in bytes. */
	maxBytes?: number;
	/** Opt-in gate for private/link-local hosts (local dev only). */
	allowPrivate?: boolean;
	/** Test seam — DNS resolution (unit tests must never hit the network). */
	lookupImpl?: typeof lookup;
	/** Test seam — the underlying fetch. */
	fetchImpl?: typeof fetch;
}

const DEFAULT_TIMEOUT_MS = 10_000;
const DEFAULT_MAX_REDIRECTS = 5;
const DEFAULT_MAX_BYTES = 2 * 1024 * 1024;

/** Parse and guard a URL before any network work happens. */
function guardedUrl(raw: string): URL {
	let parsed: URL;
	try {
		parsed = new URL(raw);
	} catch {
		throw new FetchGuardError('invalid_url', `Not a parseable URL: ${raw}`);
	}
	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		throw new FetchGuardError('unsupported_scheme', `Only http/https URLs are allowed: ${parsed.protocol}`);
	}
	if (parsed.username || parsed.password) {
		throw new FetchGuardError('credentials_rejected', 'URLs with embedded credentials are rejected.');
	}
	return parsed;
}

/** True when the address is private, link-local, loopback, or reserved. */
function isPrivateAddress(address: string): boolean {
	const version = isIP(address);
	if (version === 4) {
		const [a, b, c] = address.split('.').map(Number);
		return (
			a === 0 ||
			a === 10 ||
			a === 127 ||
			(a === 100 && b >= 64 && b <= 127) || // CGNAT 100.64/10.
			(a === 169 && b === 254) || // Link-local.
			(a === 172 && b >= 16 && b <= 31) || // RFC 1918.
			(a === 192 && b === 168) || // RFC 1918.
			(a === 192 && b === 0 && c === 0) || // IETF protocol assignments.
			(a === 198 && (b === 18 || b === 19)) || // Benchmarking.
			a >= 224 // Multicast + reserved.
		);
	}
	if (version === 6) {
		const lower = address.toLowerCase();
		return (
			lower === '::' ||
			lower === '::1' ||
			lower.startsWith('fc') ||
			lower.startsWith('fd') || // Unique local.
			lower.startsWith('fe8') ||
			lower.startsWith('fe9') ||
			lower.startsWith('fea') ||
			lower.startsWith('feb') || // Link-local.
			lower.startsWith('::ffff:127.') ||
			lower.startsWith('::ffff:10.') ||
			lower.startsWith('::ffff:192.168.') ||
			lower.startsWith('::ffff:169.254.')
		);
	}
	return true;
}

/** Resolve the hostname and reject private/reserved targets (every hop). */
async function guardHost(url: URL, allowPrivate: boolean, lookupImpl: typeof lookup): Promise<void> {
	const hostname = url.hostname.replace(/^\[|\]$/g, '');
	const isLiteral = isIP(hostname) !== 0;
	if (isLiteral) {
		if (!allowPrivate && isPrivateAddress(hostname)) {
			throw new FetchGuardError('blocked_private_host', `Private/reserved host blocked: ${hostname}`);
		}
		return;
	}
	let addresses: Array<{ address: string; family: number }>;
	try {
		addresses = await lookupImpl(hostname, { all: true, verbatim: true });
	} catch {
		throw new FetchGuardError('dns_failed', `DNS resolution failed for ${hostname}`);
	}
	if (addresses.length === 0) {
		throw new FetchGuardError('dns_failed', `DNS resolution returned no addresses for ${hostname}`);
	}
	if (!allowPrivate && addresses.some((entry) => isPrivateAddress(entry.address))) {
		throw new FetchGuardError('blocked_private_host', `Private/reserved host blocked: ${hostname}`);
	}
}

async function readBody(response: Response, cap: number): Promise<string> {
	if (!response.body) {
		return '';
	}
	const reader = response.body.getReader();
	const chunks: Uint8Array[] = [];
	let total = 0;
	for (;;) {
		const { done, value } = await reader.read();
		if (done) {
			break;
		}
		total += value.byteLength;
		if (total > cap) {
			await reader.cancel();
			throw new FetchGuardError('body_too_large', `Body exceeds the ${cap}-byte cap.`);
		}
		chunks.push(value);
	}
	return Buffer.concat(chunks).toString('utf8');
}

function checkContentType(response: Response, accept: string[]): void {
	const type = (response.headers.get('content-type') ?? '').toLowerCase();
	if (!accept.some((prefix) => type.includes(prefix))) {
		throw new FetchGuardError('unsupported_content_type', `Expected ${accept.join('/')}, got: ${type || 'none'}`);
	}
}

/**
 * Fetch one URL behind the full guard, following redirects with re-validation
 * on every hop. The shared primitive for the page fetch and the mode fetchers
 * (theme.json, GitHub API/raw, LICENSE).
 */
export async function fetchGuarded(
	rawUrl: string,
	options: FetchGuardOptions & { accept?: string[] } = {}
): Promise<{ body: string; finalUrl: string }> {
	const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
	const maxRedirects = options.maxRedirects ?? DEFAULT_MAX_REDIRECTS;
	const maxBytes = options.maxBytes ?? DEFAULT_MAX_BYTES;
	const allowPrivate = options.allowPrivate ?? false;
	const lookupImpl = options.lookupImpl ?? lookup;
	const fetchImpl = options.fetchImpl ?? fetch;
	const accept = options.accept ?? ['text/html'];

	let url = guardedUrl(rawUrl);
	for (let hop = 0; hop <= maxRedirects; hop++) {
		await guardHost(url, allowPrivate, lookupImpl);
		const response = await fetchImpl(url.toString(), {
			redirect: 'manual',
			signal: AbortSignal.timeout(timeoutMs),
			headers: { accept: accept.join(', ') },
		});
		if (response.status >= 300 && response.status < 400) {
			const location = response.headers.get('location');
			if (!location) {
				throw new FetchGuardError('redirect_without_location', `Redirect without Location at ${url.toString()}`);
			}
			url = guardedUrl(new URL(location, url).toString());
			continue;
		}
		if (!response.ok) {
			throw new FetchGuardError('http_error', `Upstream responded ${response.status} for ${url.toString()}`);
		}
		checkContentType(response, accept);
		return { body: await readBody(response, maxBytes), finalUrl: url.toString() };
	}
	throw new FetchGuardError('too_many_redirects', `Exceeded the ${maxRedirects}-redirect cap.`);
}

export interface FetchedDesignSource {
	/** The final page HTML (after redirects). */
	html: string;
	/** Same-origin stylesheet bodies (link rel=stylesheet only). */
	stylesheets: string[];
	/** The final URL after redirects. */
	finalUrl: string;
}

export interface FetchDesignSourceOptions extends FetchGuardOptions {
	/** Stylesheet body cap in bytes. Default 512 KiB. */
	maxStylesheetBytes?: number;
	/** Same-origin stylesheet fan-out cap. Default 8. */
	maxStylesheets?: number;
}

const DEFAULT_MAX_STYLESHEET_BYTES = 512 * 1024;
const DEFAULT_MAX_STYLESHEETS = 8;

/**
 * Fetch a design source: the page plus a bounded set of same-origin
 * stylesheets, behind the SSRF guard on every hop.
 */
export async function fetchDesignSource(rawUrl: string, options: FetchDesignSourceOptions = {}): Promise<FetchedDesignSource> {
	const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
	const maxStylesheetBytes = options.maxStylesheetBytes ?? DEFAULT_MAX_STYLESHEET_BYTES;
	const maxStylesheets = options.maxStylesheets ?? DEFAULT_MAX_STYLESHEETS;
	const allowPrivate = options.allowPrivate ?? false;
	const lookupImpl = options.lookupImpl ?? lookup;
	const fetchImpl = options.fetchImpl ?? fetch;

	const page = await fetchGuarded(rawUrl, { ...options, accept: ['text/html'] });
	const html = page.body;
	const url = new URL(page.finalUrl);

	// Bounded same-origin stylesheet fan-out.
	const stylesheets: string[] = [];
	const hrefs = [...html.matchAll(/<link\b[^>]*\brel=["']?stylesheet["']?[^>]*>/gi)]
		.map((match) => match[0].match(/\bhref\s*=\s*["']([^"']+)["']/i)?.[1])
		.filter((href): href is string => Boolean(href));
	for (const href of hrefs) {
		if (stylesheets.length >= maxStylesheets) {
			break;
		}
		let sheetUrl: URL;
		try {
			sheetUrl = new URL(href, url);
		} catch {
			continue;
		}
		if (sheetUrl.origin !== url.origin) {
			continue; // Cross-origin stylesheets are never fetched in v1.
		}
		try {
			await guardHost(sheetUrl, allowPrivate, lookupImpl);
			const response = await fetchImpl(sheetUrl.toString(), {
				redirect: 'manual',
				signal: AbortSignal.timeout(timeoutMs),
				headers: { accept: 'text/css' },
			});
			if (!response.ok) {
				continue; // A missing stylesheet is not a failure.
			}
			checkContentType(response, ['text/css']);
			stylesheets.push(await readBody(response, maxStylesheetBytes));
		} catch (error) {
			if (error instanceof FetchGuardError && error.code === 'body_too_large') {
				stylesheets.push(''); // Oversize sheets degrade, never fail the run.
			}
			// Other guard failures on a stylesheet are skipped; the page body
			// remains the primary signal.
		}
	}

	return { html, stylesheets, finalUrl: page.finalUrl };
}

/** Classify the source mode from the URL + page content (Proposal 098 §3.3). */
export function detectMode(url: string, html: string): SourceMode {
	if (url.includes('github.com')) {
		return 'astro-theme';
	}
	if (/wp-(?:content|includes|json)/i.test(html)) {
		return 'wordpress';
	}
	return 'generic';
}
