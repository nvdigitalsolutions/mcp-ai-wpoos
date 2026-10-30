// ---------------------------------------------------------------------------
// Commerce fetch layer — Proposal 092 §5.2 / D4 / D12.
//
// - Build time: binds to NVOOS_API_BASE (full base URL of the NV commerce
//   contract). Required for getStaticPaths prerendering; CI and local
//   builds point it at the fixture server (scripts/fixture-server.mjs).
// - Runtime SSR: binds to the same-origin /api/* proxy (relative URLs) —
//   zero origin config; the T1 backend flip is a serve.mjs env change only.
// - Envelope validation is strict: a malformed response is a loud error,
//   never a silently empty catalog.
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
	PaginatedCollection,
	Product,
	Promocode,
	Promotion,
	SortValue,
	Status,
	WhereClause,
} from './types';

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
	| 'promocodes';

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

/** Strict envelope validation — loud failure on contract drift (P5). */
function assertEnvelope<T>(data: unknown, collection: string): PaginatedCollection<T> {
	if (!data || typeof data !== 'object') {
		throw new Error(`[${collection}] envelope is not an object`);
	}
	const d = data as Record<string, unknown>;
	if (!Array.isArray(d.docs)) {
		throw new Error(`[${collection}] envelope missing docs array`);
	}
	if (
		typeof d.totalDocs !== 'number' ||
		typeof d.totalPages !== 'number' ||
		typeof d.page !== 'number'
	) {
		throw new Error(`[${collection}] envelope missing pagination fields`);
	}
	return data as PaginatedCollection<T>;
}

// In-process memo of VALIDATED envelopes keyed by URL — one render, many
// components, one fetch (and one validation). Raw responses are never
// cached: a malformed response must fail loudly on every consumer.
const memo = new Map<string, unknown>();

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

	const hit = memo.get(url) as PaginatedCollection<T> | undefined;
	if (hit) {
		return hit;
	}

	const data = await fetchJson(url);
	const validated = assertEnvelope<T>(data, collection);
	memo.set(url, validated);
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
