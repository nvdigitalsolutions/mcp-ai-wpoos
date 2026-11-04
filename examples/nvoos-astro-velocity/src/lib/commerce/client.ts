// ---------------------------------------------------------------------------
// Commerce fetch layer — Proposal 092 §5.2 / D4 / D12, revised by 104 §3.2.
//
// - Runtime SSR: binds to the same-origin /api/* proxy (relative URLs) —
//   zero origin config; the T1 backend flip is a serve.mjs env change only.
// - Build time: no routes fetch at build anymore (the 092 PDP prerender was
//   dropped in 104), so NVOOS_API_BASE is now a dev/CI-only fixture handle
//   kept for the hermetic local build loop.
// - Envelope validation is strict (zod v4, D3): a malformed response is a
//   loud error, never a silently empty catalog.
//
// Caching: explicit Cache-Control headers are set by routes (D4); this layer
// adds a tiny per-process memo for repeated reads within one render. The
// bridge emits `Cache-Control: public, s-maxage=300` (B1) so the BFF
// micro-cache covers proxied GETs.
// ---------------------------------------------------------------------------

import type {
	Banner,
	Brand,
	Carousel,
	Category,
	ColorRef,
	ContentDoc,
	PaginatedCollection,
	Product,
	Promocode,
	Promotion,
	ShopDoc,
	SortValue,
	Status,
	WhereClause,
} from './types';
import { parsePolicyDoc, type ThemePolicy } from '../theme/policy';
import { generatedPolicyDoc } from '../theme/policy.generated';
import { z } from 'zod';

/** Bridge-enforced ceiling on `limit` (verified in the REST controller). */
export const MAX_LIMIT = 100;

/** Display page size for PLPs. */
export const PAGE_SIZE = 24;

type CollectionSlug =
	| 'products'
	| 'categories'
	| 'brands'
	| 'colors'
	| 'statuses'
	| 'promotions'
	| 'promocodes'
	| 'content/pages'
	| 'content/posts';

/** Resolve the base URL.
 *
 * Precedence (highest first):
 * - NVOOS_SSR_API_ORIGIN (set by scripts/serve.mjs to
 *   `http://127.0.0.1:<PORT>`) — the SSR render self-fetches the in-process
 *   BFF listener instead of the public URL. On Velocity the public self-
 *   request fails at the network layer (~1s TLS/DNS error, incident
 *   2026-10-29 #2) even though x-forwarded-* headers are present.
 * - Runtime SSR: the request URL — the same-origin /api/* proxy. A
 *   build-time NVOOS_API_BASE (the hermetic fixture server) is baked into
 *   the mirror dist and MUST be ignored at runtime, otherwise every SSR
 *   render fetches localhost:8123 and 500s (incident 2026-10-29).
 * - Build time (getStaticPaths): no request URL, so NVOOS_API_BASE applies.
 */
export function resolveBase(requestUrl: string | URL | undefined): string {
	const ssrOrigin = process.env.NVOOS_SSR_API_ORIGIN;
	if (ssrOrigin) {
		return `${ssrOrigin.replace(/\/+$/, '')}/api`;
	}
	if (requestUrl) {
		return new URL('/api', requestUrl).toString().replace(/\/+$/, '');
	}
	const buildBase = import.meta.env.NVOOS_API_BASE;
	if (buildBase) {
		return buildBase.replace(/\/+$/, '');
	}
	throw new Error(
		'Commerce client needs a request URL (runtime) or NVOOS_API_BASE (build).'
	);
}

export function joinPath(base: string, path: string): string {
	return `${base}/${path}`;
}

/** Serialize a where clause into Payload-style query params (bridge B2). */
export function buildWhereParams(where: WhereClause): string {
	const params = new URLSearchParams();
	for (const [field, operators] of Object.entries(where)) {
		for (const [operator, value] of Object.entries(operators)) {
			if (value === undefined || value === null || value === '') {
				continue;
			}
			const values = Array.isArray(value) ? value : [value];
			for (const item of values) {
				params.append(`where[${field}][${operator}]`, String(item));
			}
		}
	}
	return params.toString();
}

/** Build a collection query string. */
export function buildCollectionQuery(
	params: {
		page?: number;
		limit?: number;
		where?: WhereClause;
		sort?: SortValue;
		/** Publication status filter (publish | draft | any). Manager-only for non-publish. */
		status?: string;
	} = {}
): string {
	const q = new URLSearchParams();
	if (params.page && params.page > 1) {
		q.set('page', String(params.page));
	}
	if (params.limit) {
		q.set('limit', String(Math.min(params.limit, MAX_LIMIT)));
	}
	if (params.sort) {
		q.set('sort', params.sort);
	}
	if (params.status && params.status !== 'publish') {
		q.set('status', params.status);
	}
	const where = buildWhereParams(params.where ?? {});
	return where ? `${q.toString()}&${where}` : q.toString();
}

async function fetchJson(url: string): Promise<unknown> {
	const res = await fetch(url, {
		headers: { accept: 'application/json' },
	});
	if (!res.ok) {
		throw new Error(`Commerce API ${res.status} for ${url}`);
	}
	return res.json();
}

/** Strict envelope validation — loud failure on contract drift (P5).
 *  zod v4 parse (D3): typed narrowing at call sites, prefixed errors that
 *  name the collection — a malformed response is a loud error, never a
 *  silently empty catalog. */
const envelopeSchema = z.object({
	docs: z.array(z.unknown()),
	totalDocs: z.number(),
	totalPages: z.number(),
	page: z.number(),
});

function assertEnvelope<T>(data: unknown, collection: string): PaginatedCollection<T> {
	const result = envelopeSchema.safeParse(data);
	if (!result.success) {
		const issues = result.error.issues
			.map((issue) => `${issue.path.join('.')}: ${issue.message}`)
			.join('; ');
		throw new Error(`[${collection}] envelope invalid — ${issues}`);
	}
	return data as PaginatedCollection<T>;
}

// In-process memo of VALIDATED envelopes keyed by URL — one render, many
// components, one fetch (and one validation). Raw responses are never
// cached: a malformed response must fail loudly on every consumer.
//
// TTL-bounded (2026-11-03 prod incident): the memo is module-level and
// lives as long as the server process, so an unbounded entry would pin the
// first-render data forever (the homepage served seed-era images for 30
// minutes until the next redeploy). Entries expire after MEMO_TTL_MS —
// long enough to dedupe within a render, short enough to never outlive a
// content update by more than a few seconds.
const memo = new Map<string, { data: unknown; expires: number }>();

/** Module-memo TTL — see above. 10 s covers any single render comfortably. */
const MEMO_TTL_MS = 10_000;

/** Fetch one page of a collection. */
export async function getCollection<T>(
	collection: CollectionSlug,
	requestUrl: string | URL | undefined,
	params: {
		page?: number;
		limit?: number;
		where?: WhereClause;
		sort?: SortValue;
	} = {}
): Promise<PaginatedCollection<T>> {
	const query = buildCollectionQuery(params);
	const suffix = query ? `?${query}` : '';
	const url = joinPath(resolveBase(requestUrl), collection) + suffix;

	const hit = memo.get(url);
	if (hit) {
		if (hit.expires > Date.now()) {
			return hit.data as PaginatedCollection<T>;
		}
		memo.delete(url);
	}

	const data = await fetchJson(url);
	const validated = assertEnvelope<T>(data, collection);
	memo.set(url, { data: validated, expires: Date.now() + MEMO_TTL_MS });
	return validated;
}

/** Accumulate ALL pages of a collection (getStaticPaths use). */
export async function getAll<T>(
	collection: CollectionSlug,
	requestUrl: string | URL | undefined,
	params: { where?: WhereClause; sort?: SortValue } = {}
): Promise<T[]> {
	const first = await getCollection<T>(collection, requestUrl, {
		page: 1,
		limit: MAX_LIMIT,
		...params,
	});
	if (first.totalPages <= 1) {
		return first.docs;
	}
	const rest = await Promise.all(
		Array.from({ length: first.totalPages - 1 }, (_, i) =>
			getCollection<T>(collection, requestUrl, {
				page: i + 2,
				limit: MAX_LIMIT,
				...params,
			})
		)
	);
	return [...first.docs, ...rest.flatMap((p) => p.docs)];
}

/** Fetch one page of a collection, degrading a 404 to null.
 *
 * For AUXILIARY reads only (the home-page stat counts): a 404 here means
 * the backend predates this collection (deployment version skew — e.g. the
 * bridge plugin on the backend lacks a newer endpoint). An absent stat must
 * not fail the whole render. Malformed envelopes and other statuses still
 * throw loudly (P5 discipline is untouched). Null results are not memoized
 * so a later backend deploy becomes visible on the next render.
 */
export async function getCollectionOrNull<T>(
	collection: CollectionSlug,
	requestUrl: string | URL | undefined,
	params: {
		page?: number;
		limit?: number;
		where?: WhereClause;
		sort?: SortValue;
	} = {}
): Promise<PaginatedCollection<T> | null> {
	const query = buildCollectionQuery(params);
	const suffix = query ? `?${query}` : '';
	const url = joinPath(resolveBase(requestUrl), collection) + suffix;

	const hit = memo.get(url);
	if (hit) {
		if (hit.expires > Date.now()) {
			return hit.data as PaginatedCollection<T>;
		}
		memo.delete(url);
	}

	const res = await fetch(url, { headers: { accept: 'application/json' } });
	if (res.status === 404) {
		return null;
	}
	if (!res.ok) {
		throw new Error(`Commerce API ${res.status} for ${url}`);
	}
	const data = await res.json();
	const validated = assertEnvelope<T>(data, collection);
	memo.set(url, { data: validated, expires: Date.now() + MEMO_TTL_MS });
	return validated;
}

/** Fetch a single product by ID (SSR fallback + 404 handling). */
export async function getProduct(
	id: number,
	requestUrl: string | URL | undefined
): Promise<Product | null> {
	const url = joinPath(resolveBase(requestUrl), `products/${id}`);
	const res = await fetch(url, { headers: { accept: 'application/json' } });
	if (res.status === 404) {
		return null;
	}
	if (!res.ok) {
		throw new Error(`Commerce API ${res.status} for ${url}`);
	}
	return (await res.json()) as Product;
}

// Typed convenience wrappers.
export const getProducts = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<Product>('products', requestUrl, params);

export const getCategories = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<Category>('categories', requestUrl, params);

export const getBrands = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<Brand>('brands', requestUrl, params);

export const getColors = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<ColorRef>('colors', requestUrl, params);

export const getStatuses = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<Status>('statuses', requestUrl, params);

export const getPromotions = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<Promotion>('promotions', requestUrl, params);

export const getPromocodes = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<Promocode>('promocodes', requestUrl, params);

/** Fetch a global singleton (banner/carousel). */
export async function getGlobal(
	slug: 'banner' | 'carousel',
	requestUrl: string | URL | undefined
): Promise<Banner | Carousel | null> {
	const url = joinPath(resolveBase(requestUrl), `globals/${slug}`);
	const res = await fetch(url, { headers: { accept: 'application/json' } });
	if (!res.ok) {
		return null;
	}
	return (await res.json()) as Banner | Carousel;
}

// ---------------------------------------------------------------------------
// Theme policy + shop globals (Proposals 105/106) — short in-process TTLs so
// repeated reads within one render stay cheap while a PATCH is visible to the
// next render within the TTL window. Failures return the build-baked default
// doc and are never cached (a down backend must not stick).
// ---------------------------------------------------------------------------

/** Runtime TTL override (seconds; ≤ 0 disables the memo) — the e2e seam that
 *  makes PATCH→render deterministic in a single long-lived process. Default
 *  60 s (105 §3). Read per call so a serve.mjs env change applies live. */
function policyTtlMs(): number {
	const value = Number(process.env.NVOOS_THEME_POLICY_TTL);
	if (Number.isFinite(value) && value >= 0) {
		return value * 1000;
	}
	return 60_000;
}

const globalsMemo = new Map<string, { doc: unknown; expires: number }>();

/** Fetch the theme-policy doc (105 §1) through the resolved base, 60 s TTL,
 *  default doc on failure. */
export async function getThemePolicy(
	requestUrl: string | URL | undefined
): Promise<ThemePolicy> {
	const now = Date.now();
	let base: string;
	try {
		base = resolveBase(requestUrl);
	} catch {
		// Build-time hermetic path (no request URL, no NVOOS_API_BASE) — the
		// baked fallback keeps the render alive (104 §2.5 invariant).
		return generatedPolicyDoc;
	}
	const key = `${base}/globals/theme`;
	const hit = globalsMemo.get(key);
	if (hit && hit.expires > now) {
		return hit.doc as ThemePolicy;
	}
	try {
		const res = await fetch(key, { headers: { accept: 'application/json' } });
		if (!res.ok) {
			throw new Error(`Commerce API ${res.status} for ${key}`);
		}
		const doc = parsePolicyDoc(await res.json());
		globalsMemo.set(key, { doc, expires: now + policyTtlMs() });
		return doc;
	} catch {
		return generatedPolicyDoc;
	}
}

/** Fallback shop doc — fail closed: an empty checkoutUrl disables the
 *  handoff link in the cart (106 §4); the missing source mode resolves to
 *  `native` so legacy backends keep the URL handoff (108 §2). */
export const DEFAULT_SHOP_DOC: ShopDoc = {
	checkoutUrl: '',
	cartUrl: '',
	returnUrl: '',
	currency: 'USD',
	currencySymbol: '$',
	updatedAt: '',
	productsSource: 'native',
	paymentMethods: [],
	stripePublishableKey: '',
	guestCheckout: false,
};

/** Fetch the shop globals doc (106 §1.3) through the resolved base, 60 s TTL,
 *  default doc on failure. */
export async function getShop(requestUrl: string | URL | undefined): Promise<ShopDoc> {
	const now = Date.now();
	let base: string;
	try {
		base = resolveBase(requestUrl);
	} catch {
		return DEFAULT_SHOP_DOC;
	}
	const key = `${base}/globals/shop`;
	const hit = globalsMemo.get(key);
	if (hit && hit.expires > now) {
		return hit.doc as ShopDoc;
	}
	try {
		const res = await fetch(key, { headers: { accept: 'application/json' } });
		if (!res.ok) {
			throw new Error(`Commerce API ${res.status} for ${key}`);
		}
		const raw = (await res.json()) as Partial<ShopDoc>;
		// 108 normalization — legacy backends omit the extension fields.
		const doc: ShopDoc = {
			checkoutUrl: typeof raw.checkoutUrl === 'string' ? raw.checkoutUrl : '',
			cartUrl: typeof raw.cartUrl === 'string' ? raw.cartUrl : '',
			returnUrl: typeof raw.returnUrl === 'string' ? raw.returnUrl : '',
			currency: typeof raw.currency === 'string' ? raw.currency : 'USD',
			currencySymbol: typeof raw.currencySymbol === 'string' ? raw.currencySymbol : '$',
			updatedAt: typeof raw.updatedAt === 'string' ? raw.updatedAt : '',
			productsSource:
				raw.productsSource === 'woo' || raw.productsSource === 'both'
					? raw.productsSource
					: 'native',
			paymentMethods: Array.isArray(raw.paymentMethods)
				? raw.paymentMethods.filter(
					(m) =>
						typeof m === 'object' &&
						m !== null &&
						typeof m.id === 'string' &&
						typeof m.title === 'string'
				)
				: [],
			// 110 extensions — absent on legacy backends (fail closed).
			stripePublishableKey:
				typeof raw.stripePublishableKey === 'string' ? raw.stripePublishableKey : '',
			guestCheckout:
				typeof raw.guestCheckout === 'boolean' ? raw.guestCheckout : false,
		};
		globalsMemo.set(key, { doc, expires: now + 60_000 });
		return doc;
	} catch {
		return DEFAULT_SHOP_DOC;
	}
}

// ---------------------------------------------------------------------------
// CMS content (106 §1.2 — content/pages + content/posts).
// ---------------------------------------------------------------------------

export const getPages = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<ContentDoc>('content/pages', requestUrl, params);

export const getPosts = (
	requestUrl: string | URL | undefined,
	params: Parameters<typeof getCollection>[2] = {}
) => getCollection<ContentDoc>('content/posts', requestUrl, params);

export const getAllPages = (requestUrl: string | URL | undefined) =>
	getAll<ContentDoc>('content/pages', requestUrl);

export const getAllPosts = (requestUrl: string | URL | undefined) =>
	getAll<ContentDoc>('content/posts', requestUrl);

/** Fetch a single page by ID (SSR + 404 handling — the getProduct pattern).
 *
 * Preview reads (Proposal 107 §3.2) pass `status: 'any'` plus the visitor's
 * cookie header: the backend then serves drafts to managers only (fail-closed
 * 404 otherwise). Preview reads never touch the memo cache — cookie-dependent
 * responses must not leak across renders. */
export async function getPage(
	id: number,
	requestUrl: string | URL | undefined,
	opts: { status?: 'any'; cookie?: string | null } = {}
): Promise<ContentDoc | null> {
	const q = opts.status === 'any' ? '?status=any' : '';
	const url = joinPath(resolveBase(requestUrl), `content/pages/${id}`) + q;
	const res = await fetch(url, {
		headers: { accept: 'application/json', ...(opts.cookie ? { cookie: opts.cookie } : {}) },
	});
	if (res.status === 404) {
		return null;
	}
	if (!res.ok) {
		throw new Error(`Commerce API ${res.status} for ${url}`);
	}
	return (await res.json()) as ContentDoc;
}

/** Fetch a single post by ID (SSR + 404 handling; preview opts — see getPage). */
export async function getPost(
	id: number,
	requestUrl: string | URL | undefined,
	opts: { status?: 'any'; cookie?: string | null } = {}
): Promise<ContentDoc | null> {
	const q = opts.status === 'any' ? '?status=any' : '';
	const url = joinPath(resolveBase(requestUrl), `content/posts/${id}`) + q;
	const res = await fetch(url, {
		headers: { accept: 'application/json', ...(opts.cookie ? { cookie: opts.cookie } : {}) },
	});
	if (res.status === 404) {
		return null;
	}
	if (!res.ok) {
		throw new Error(`Commerce API ${res.status} for ${url}`);
	}
	return (await res.json()) as ContentDoc;
}

/** Fetch all products for getStaticPaths (build-time, hermetic fixtures). */
export const getAllProducts = (
	requestUrl: string | URL | undefined,
	params: { where?: WhereClause; sort?: SortValue } = {}
) => getAll<Product>('products', requestUrl, params);

export const getAllCategories = (requestUrl: string | URL | undefined) =>
	getAll<Category>('categories', requestUrl);

export const getAllBrands = (requestUrl: string | URL | undefined) =>
	getAll<Brand>('brands', requestUrl);

export const getAllColors = (requestUrl: string | URL | undefined) =>
	getAll<ColorRef>('colors', requestUrl);
export const getAllStatuses = (requestUrl: string | URL | undefined) =>
	getAll<Status>('statuses', requestUrl);
export const getAllPromotions = (requestUrl: string | URL | undefined) =>
	getAll<Promotion>('promotions', requestUrl);
export const getAllPromocodes = (requestUrl: string | URL | undefined) =>
	getAll<Promocode>('promocodes', requestUrl);
