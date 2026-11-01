// ---------------------------------------------------------------------------
// wp-loader.ts — the Astro content-layer loader for WordPress (Proposal 096).
//
// Content-layer loaders, not materialized files: WordPress stays the CMS,
// Astro pulls on every build (and on every dev reload). A webhook-triggered
// rebuild keeps freshness (096 §2.3). The loader:
//   - fetches via WpClient (pagination, `_fields` pruning, typed errors)
//   - sanitizes rendered HTML through the versioned allowlist before it
//     ever reaches `set:html` (the XSS gate is mandatory — 096 §2.4)
//   - keys entries by the WP post ID (stable across slug edits)
//   - digests the `modified_gmt` so unchanged entries skip re-render work
//   - clears the store up front so deleted posts disappear from the build
//   - supports WooCommerce sources: wc/v3 flat documents (price/stock/sku/
//     images/categories) via `kind: 'product'`, or wp/v2 CPTs (no commerce
//     fields, featured media resolved by ID when `_embed` has none)
// ---------------------------------------------------------------------------

import type { Loader, LoaderContext } from 'astro/loaders';
import { z } from 'astro/zod';

import { WpClient, WpClientError } from './wp-client.ts';
import type { SiteProfile, WpAuth, WpCollection } from './types.ts';
import { sanitizeHtml } from './sanitize.ts';

export interface WpLoaderOptions {
	/** Site profile (allowlisted URL — the forge never takes arbitrary hosts). */
	site: SiteProfile;
	/** The collection this loader instance serves. */
	collection: WpCollection;
	/** Auth for private sources — never written into the scaffolded project. */
	auth?: WpAuth;
}

/** Stable, framework-agnostic entry shape across wp/v2 and wc/v3. */
const schema = z.object({
	id: z.number(),
	title: z.string(),
	slug: z.string(),
	date: z.string(),
	modified: z.string(),
	excerpt: z.string().optional(),
	link: z.string().optional(),
	author: z.string().optional(),
	featuredImage: z.string().optional(),
	price: z.string().optional(),
	regularPrice: z.string().optional(),
	salePrice: z.string().optional(),
	stockStatus: z.string().optional(),
	sku: z.string().optional(),
	categories: z.array(z.string()).optional(),
	images: z.array(z.string()).optional(),
	/** True when the page cap stopped the fetch before the end (096: never silent truncation). */
	truncated: z.boolean().optional(),
});

/** Run an async map over items with bounded concurrency (politeness). */
async function mapLimit<T, R>(items: T[], limit: number, fn: (item: T) => Promise<R>): Promise<R[]> {
	const out: R[] = new Array(items.length);
	let next = 0;
	const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
		while (next < items.length) {
			const index = next++;
			out[index] = await fn(items[index]);
		}
	});
	await Promise.all(workers);
	return out;
}

/**
 * Astro content-layer loader over one WordPress/WooCommerce REST collection.
 */
export function createWpLoader(options: WpLoaderOptions): Loader {
	const { site, collection, auth } = options;

	return {
		name: 'nvoos-wp',
		schema,
		load: async (context: LoaderContext) => {
			const client = WpClient.fromProfile(site, { auth });

			if (collection.source === 'payload') {
				await loadPayloadEntries(context, client, collection);
				return;
			}

			let fetched;
			try {
				fetched = await client.fetchAll(collection.restBase, {
					perPage: collection.perPage,
					maxPages: collection.maxPages,
					fields: collection.fields,
					embed: collection.embed,
				});
			} catch (error) {
				// An explicitly optional source may be absent (e.g. a store
				// without products) — empty collection, not a failed build.
				if (collection.optional && error instanceof WpClientError && error.code === 'wp_not_found') {
					context.store.clear();
					context.logger.warn(
						`[nvoos-wp] ${collection.slug}: source not found (marked optional) — empty collection.`
					);
					return;
				}
				throw error;
			}
			const { entries, truncated } = fetched;

			// Deletions in WP must disappear from the next build.
			context.store.clear();

			// wp/v2 without `_embed`: resolve featured images by ID —
			// bounded concurrency, and an image miss is never a build failure.
			const mediaUrls = new Map<number, string>();
			const mediaIds = new Set<number>();
			for (const entry of entries) {
				const fm = entry.featured_media;
				if (typeof fm === 'number' && fm > 0 && !extractFeaturedImage(entry)) {
					mediaIds.add(fm);
				}
			}
			await mapLimit([...mediaIds], 5, async (id) => {
				try {
					const { body } = await client.get<{ source_url?: string }>(`wp/v2/media/${id}`, {
						_fields: 'source_url',
					});
					if (typeof body.source_url === 'string') {
						mediaUrls.set(id, body.source_url);
					}
				} catch {
					// Bonus field — never fatal.
				}
			});

			for (const entry of entries) {
				if (collection.exclude?.includes(String(entry.slug ?? ''))) {
					continue;
				}
				const id = String(entry.id ?? entry.slug);
				const raw = extractRaw(entry);
				const modified = raw.modified || raw.date;
				const featured = raw.featuredImage ?? mediaUrls.get(Number(entry.featured_media));

				context.store.set({
					id,
					data: {
						id: Number(entry.id),
						title: raw.title,
						slug: raw.slug,
						date: raw.date,
						modified,
						excerpt: raw.excerpt ? sanitizeHtml(raw.excerpt) : undefined,
						link: raw.link,
						author: raw.author,
						featuredImage: featured,
						price: raw.price,
						regularPrice: raw.regularPrice,
						salePrice: raw.salePrice,
						stockStatus: raw.stockStatus,
						sku: raw.sku,
						categories: raw.categories,
						images: raw.images,
						truncated: truncated ? true : undefined,
					},
					digest: context.generateDigest(modified),
					rendered: { html: sanitizeHtml(raw.html) },
				});
			}

			if (truncated) {
				context.logger.warn(
					`[nvoos-wp] ${collection.slug}: fetch stopped at ${collection.maxPages ?? 50} pages — the build is incomplete. Raise maxPages in the site profile.`
				);
			}
		},
	};
}

interface RawEntry {
	title: string;
	slug: string;
	date: string;
	modified: string;
	html: string;
	excerpt?: string;
	link?: string;
	author?: string;
	featuredImage?: string;
	price?: string;
	regularPrice?: string;
	salePrice?: string;
	stockStatus?: string;
	sku?: string;
	categories?: string[];
	images?: string[];
}

/** Normalize one raw document into the stable entry shape. */
function extractRaw(entry: Record<string, unknown>): RawEntry {
	// wc/v3 flat documents (product kind).
	const title = typeof entry.name === 'string' ? entry.name : extractHtml(entry.title);
	const html = typeof entry.description === 'string' ? entry.description : extractHtml(entry.content);
	const excerpt =
		typeof entry.short_description === 'string' ? entry.short_description : extractHtml(entry.excerpt);
	const date = String(
		entry.date_created_gmt ?? entry.date_created ?? entry.date_gmt ?? entry.date ?? ''
	);
	const modified = String(entry.date_modified_gmt ?? entry.date_modified ?? entry.modified_gmt ?? entry.modified ?? date);
	const embedded = entry._embedded as { author?: Array<{ name?: unknown }> } | undefined;

	const wcImages = Array.isArray(entry.images)
		? entry.images
				.map((image) => (image && typeof image === 'object' && 'src' in image ? String(image.src) : ''))
				.filter(Boolean)
		: [];
	const wcCategories = Array.isArray(entry.categories)
		? entry.categories
				.map((category) => (category && typeof category === 'object' && 'name' in category ? String(category.name) : ''))
				.filter(Boolean)
		: [];

	return {
		title: title || String(entry.id ?? ''),
		slug: String(entry.slug ?? ''),
		date,
		modified,
		html,
		excerpt: excerpt || undefined,
		link: typeof entry.permalink === 'string' ? entry.permalink : typeof entry.link === 'string' ? entry.link : undefined,
		author: embedded?.author?.[0]?.name ? String(embedded.author[0].name) : undefined,
		featuredImage: extractFeaturedImage(entry) ?? wcImages[0],
		price: extractCommerce(entry, ['price']),
		regularPrice: extractCommerce(entry, ['regular_price']),
		salePrice: extractCommerce(entry, ['sale_price']),
		stockStatus: extractCommerce(entry, ['stock_status']),
		sku: extractCommerce(entry, ['sku']),
		categories: wcCategories.length > 0 ? wcCategories : undefined,
		images: wcImages.length > 0 ? wcImages : undefined,
	};
}

/** Commerce fields are additive — read them when present, skip otherwise. */
function extractCommerce(entry: Record<string, unknown>, keys: string[]): string | undefined {
	for (const key of keys) {
		const value = entry[key];
		if (typeof value === 'string' && value.length > 0) {
			return value;
		}
		if (typeof value === 'number') {
			return String(value);
		}
	}
	return undefined;
}

/**
 * Payload-envelope branch — the nvoos-commerce/v1 wire format served by the
 * nvoos-astro-bridge: `{ docs, totalDocs, limit, totalPages, page,
 * hasNextPage, hasPrevPage }` pagination over flat documents (no `_fields`
 * pruning, no X-WP headers). Reads are public; auth is not sent.
 */
async function loadPayloadEntries(
	context: LoaderContext,
	client: WpClient,
	collection: WpCollection
): Promise<void> {
	const perPage = collection.perPage ?? 100;
	const maxPages = collection.maxPages ?? 50;
	const entries: Array<Record<string, unknown>> = [];
	let truncated = false;
	let pages = 0;

	for (let page = 1; page <= maxPages; page++) {
		const { body } = await client.get<PayloadPage>(collection.restBase, {
			page: String(page),
			limit: String(perPage),
		});
		entries.push(...(body.docs ?? []));
		pages = page;
		const totalPages = Math.max(1, Number(body.totalPages ?? 1));
		if (page >= totalPages) {
			break;
		}
		if (page === maxPages) {
			truncated = true;
		}
	}

	context.store.clear();
	for (const entry of entries) {
		if (collection.exclude?.includes(String(entry.slug ?? ''))) {
			continue;
		}
		const raw = extractPayloadRaw(entry);
		const modified = raw.modified || raw.date;
		context.store.set({
			id: String(entry.id ?? raw.slug),
			data: {
				id: Number(entry.id),
				title: raw.title,
				slug: raw.slug,
				date: raw.date,
				modified,
				excerpt: raw.excerpt ? sanitizeHtml(raw.excerpt) : undefined,
				link: raw.link,
				author: raw.author,
				featuredImage: raw.featuredImage,
				price: raw.price,
				regularPrice: raw.regularPrice,
				salePrice: raw.salePrice,
				stockStatus: raw.stockStatus,
				sku: raw.sku,
				categories: raw.categories,
				images: raw.images,
				truncated: truncated ? true : undefined,
			},
			digest: context.generateDigest(modified),
			rendered: { html: sanitizeHtml(raw.html) },
		});
	}

	if (truncated) {
		context.logger.warn(
			`[nvoos-wp] ${collection.slug}: fetch stopped at ${maxPages} pages — the build is incomplete. Raise maxPages in the site profile.`
		);
	}
}

interface PayloadPage {
	docs?: Array<Record<string, unknown>>;
	totalDocs?: number;
	totalPages?: number;
	page?: number;
}

/** Map one flat nvoos-commerce/v1 document into the stable entry shape. */
export function extractPayloadRaw(entry: Record<string, unknown>): RawEntry {
	const title = typeof entry.name === 'string' ? entry.name : String(entry.id ?? '');
	const html = typeof entry.description === 'string' ? entry.description : '';
	const date = String(entry.createdAt ?? entry.created_at ?? '');
	const modified = String(entry.updatedAt ?? entry.updated_at ?? date);

	// Price semantics: `price` is the CURRENT price; `oldPrice` present means
	// a sale (strike-through = oldPrice). Mirrors the Woo mapping.
	const price = extractCommerce(entry, ['price']);
	const oldPrice = extractCommerce(entry, ['oldPrice', 'old_price', 'regular_price']);
	const regularPrice = oldPrice ?? price;
	const salePrice = oldPrice && price && oldPrice !== price ? price : undefined;

	const images = Array.isArray(entry.images)
		? entry.images.map((image) => String(image)).filter(Boolean)
		: [];
	const featured = typeof entry.image === 'string' && entry.image.length > 0 ? entry.image : images[0];

	const categories: string[] = [];
	if (Array.isArray(entry.categories)) {
		for (const category of entry.categories) {
			if (category && typeof category === 'object' && 'name' in category) {
				categories.push(String(category.name));
			}
		}
	} else if (entry.category && typeof entry.category === 'object' && 'name' in entry.category) {
		categories.push(String(entry.category.name));
	}

	// Stock statuses are named objects in the envelope (e.g. "In Stock").
	const stockStatus = Array.isArray(entry.statuses) && entry.statuses.length > 0
		? String((entry.statuses[0] as { name?: unknown })?.name ?? '')
		: undefined;

	return {
		title,
		slug: slugify(title),
		date,
		modified,
		html,
		link: undefined,
		featuredImage: featured,
		price,
		regularPrice,
		salePrice,
		stockStatus: stockStatus || undefined,
		sku: undefined,
		categories: categories.length > 0 ? categories : undefined,
		images: images.length > 0 ? images : undefined,
	};
}

/** Deterministic slug for envelope documents that carry none. */
export function slugify(value: string): string {
	return value
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 80);
}

/** Extract rendered HTML from a wp/v2 `content`/`excerpt` object. */
function extractHtml(field: unknown): string {
	if (typeof field === 'string') {
		return field;
	}
	if (field && typeof field === 'object' && typeof (field as { rendered?: unknown }).rendered === 'string') {
		return (field as { rendered: string }).rendered;
	}
	return '';
}

/** Featured image URL from `_embedded['wp:featuredmedia']` (or the plain field). */
function extractFeaturedImage(entry: Record<string, unknown>): string | undefined {
	const media = (entry._embedded as Record<string, unknown> | undefined)?.['wp:featuredmedia'];
	if (Array.isArray(media) && media.length > 0) {
		const source = media[0]?.['source_url'];
		if (typeof source === 'string' && source.length > 0) {
			return source;
		}
	}
	const plain = entry.featured_media_url ?? entry.featured_image;
	return typeof plain === 'string' && plain.length > 0 ? plain : undefined;
}
