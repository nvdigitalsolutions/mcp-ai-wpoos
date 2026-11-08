// ---------------------------------------------------------------------------
// wp-client.ts — the WordPress REST client (Proposal 096 Phase 0).
//
// Industry-standard WP REST discipline (096 research §0/§2): pagination via
// X-WP-TotalPages, `_fields` pruning + `_embed` for related resources,
// typed auth failures, and an explicit truncation flag — a build that stops
// early must say so, never silently ship a partial catalog.
// ---------------------------------------------------------------------------

import type { FetchAllResult, SiteProfile, WpAuth, WpEntry, WpRoot } from './types.ts';

export interface WpClientOptions {
	baseUrl: string;
	auth?: WpAuth;
	timeoutMs?: number;
	/**
	 * User-Agent header. Defaults to a browser-like UA — bot protection
	 * (Imunify360/Cloudflare et al.) frequently 403s UA-less or tool-default
	 * requests from datacenter IPs. Override via the WP_USER_AGENT env in
	 * the scaffolded project.
	 */
	userAgent?: string;
	/** Test seam — the underlying fetch. */
	fetchImpl?: typeof fetch;
}

/** The default request UA — a Chrome desktop signature so WordPress WAFs
 *  treat build-time content fetches as a normal browser. */
export const DEFAULT_USER_AGENT =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/** Typed client errors — machine-readable codes for tool/log surfaces. */
export class WpClientError extends Error {
	code: string;
	status: number;

	constructor(code: string, status: number, message: string) {
		super(message);
		this.name = 'WpClientError';
		this.code = code;
		this.status = status;
	}
}

const DEFAULT_TIMEOUT_MS = 15_000;

export class WpClient {
	private readonly baseUrl: string;
	private readonly auth?: WpAuth;
	private readonly timeoutMs: number;
	private readonly userAgent: string;
	private readonly fetchImpl: typeof fetch;

	constructor(options: WpClientOptions) {
		this.baseUrl = options.baseUrl.replace(/\/+$/, '');
		this.auth = options.auth;
		this.timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
		this.userAgent = options.userAgent ?? DEFAULT_USER_AGENT;
		this.fetchImpl = options.fetchImpl ?? fetch;
	}

	/** GET one endpoint (path relative to /wp-json). */
	async get<T>(path: string, params: Record<string, string> = {}): Promise<{ body: T; headers: Headers }> {
		const url = new URL(`${this.baseUrl}/wp-json/${path.replace(/^\/+/, '')}`);
		for (const [key, value] of Object.entries(params)) {
			url.searchParams.set(key, value);
		}
		const headers: Record<string, string> = { accept: 'application/json', 'user-agent': this.userAgent };
		if (this.auth?.type === 'basic') {
			headers.authorization = `Basic ${Buffer.from(`${this.auth.username}:${this.auth.password}`).toString('base64')}`;
		} else if (this.auth?.type === 'bearer') {
			headers.authorization = `Bearer ${this.auth.token}`;
		}
		const response = await this.fetchImpl(url.toString(), {
			headers,
			signal: AbortSignal.timeout(this.timeoutMs),
		});
		if (response.status === 401 || response.status === 403) {
			throw new WpClientError('wp_auth_required', response.status, `WordPress rejected the request (${response.status}) — check the site credentials.`);
		}
		if (response.status === 404) {
			throw new WpClientError('wp_not_found', 404, `Endpoint not found: ${path}`);
		}
		if (!response.ok) {
			throw new WpClientError('wp_http_error', response.status, `WordPress responded ${response.status} for ${path}`);
		}
		const body = (await response.json()) as T;
		return { body, headers: response.headers };
	}

	/** Fetch one endpoint with pagination until the end or the page cap. */
	async fetchAll<T extends WpEntry>(
		path: string,
		options: { perPage?: number; maxPages?: number; fields?: string[]; embed?: boolean } = {}
	): Promise<FetchAllResult<T>> {
		const perPage = options.perPage ?? 100;
		const maxPages = options.maxPages ?? 50;
		const entries: T[] = [];
		let pages = 0;
		let truncated = false;
		for (let page = 1; page <= maxPages; page++) {
			const params: Record<string, string> = { page: String(page), per_page: String(perPage) };
			if (options.fields && options.fields.length > 0) {
				params._fields = options.fields.join(',');
			}
			if (options.embed) {
				params._embed = '1';
			}
			const { body, headers } = await this.get<T[]>(path, params);
			entries.push(...(Array.isArray(body) ? body : []));
			pages = page;
			const totalPages = Number(headers.get('x-wp-totalpages') ?? '1');
			if (page >= totalPages) {
				break;
			}
			// Cap hit with pages remaining — the build must say so.
			if (page === maxPages) {
				truncated = true;
			}
		}
		return { entries, truncated, pages };
	}

	/** Site discovery — the WP root document. */
	async root(): Promise<WpRoot> {
		const { body } = await this.get<WpRoot>('');
		return body;
	}

	/** Active post types (capability/content discovery). */
	async postTypes(): Promise<Record<string, { rest_base?: string; name?: string }>> {
		const { body } = await this.get<Record<string, { rest_base?: string; name?: string }>>('wp/v2/types');
		return body;
	}

	/** Convenience — build a client from a site profile. */
	static fromProfile(profile: SiteProfile, options: Omit<WpClientOptions, 'baseUrl'> = {}): WpClient {
		return new WpClient({ ...options, baseUrl: profile.url });
	}

	/**
	 * The transport this client uses — lets a sibling fetcher (the bridge
	 * redirects export) reuse the same base URL, fetch implementation, and
	 * User-Agent instead of opening a second, differently-configured channel.
	 */
	transport(): { baseUrl: string; fetchImpl: typeof fetch; userAgent: string } {
		return { baseUrl: this.baseUrl, fetchImpl: this.fetchImpl, userAgent: this.userAgent };
	}
}
