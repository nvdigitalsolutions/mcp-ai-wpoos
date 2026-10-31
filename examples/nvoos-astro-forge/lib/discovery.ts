// ---------------------------------------------------------------------------
// discovery.ts — site_analyze: one command turns a WordPress URL into a
// draft site profile (Proposal 096 §1, tools/discover).
//
// The forge CLI is operator-run, so this module does what an operator would
// do by hand — politely: root doc, post types, one count request per
// candidate collection, the front-page slug, and a wc/v3 auth probe. It
// never writes anything; the draft profile lands on stdout or a file.
// ---------------------------------------------------------------------------

import { WpClient } from './wp-client.ts';
import type { SiteProfile, WpCollection } from './types.ts';

/** System/auth slugs that never belong in a generated frontend. */
export const DEFAULT_JUNK_SLUGS = [
	'checkout',
	'cart',
	'my-account',
	'my-account-2',
	'lost-password',
	'login',
	'login-customizer',
	'thankyou',
	'order-received',
	'refund',
	'test',
];

/** Junk-slug prefixes (manage-profile-2, manage-profile-4, …). */
export const DEFAULT_JUNK_PREFIXES = ['manage-profile'];

export interface CollectionAnalysis {
	slug: string;
	restBase: string;
	kind: 'post' | 'page' | 'product';
	count: number;
	/** The collection would be optional — the source may be absent on some sites. */
	optional: boolean;
}

export interface DiscoveryReport {
	name: string;
	description: string;
	url: string;
	/** Post types exposed over wp/v2, with their rest bases. */
	postTypes: Record<string, { restBase: string; name: string }>;
	/** Content collections worth wiring, with counts. */
	collections: CollectionAnalysis[];
	/** The WordPress static front page slug, when show_on_front === 'page'. */
	frontPageSlug?: string;
	/** Page slugs matched by the junk heuristics. */
	junkPages: string[];
	/** wp/v2 is locked down (404/403) — no content is reachable. */
	lockedDown: boolean;
	/** wc/v3 namespace is advertised. */
	woocommerce: boolean;
	/** wc/v3 products answered 200 unauthenticated (prices available without keys). */
	wooPublic: boolean;
	/** Recognized SEO-plugin namespaces (rankmath, yoast, …). */
	seoPlugins: string[];
}

/** Standard field pruning per kind — the anti-N+1 discipline. */
const FIELDS: Record<CollectionAnalysis['kind'], string[]> = {
	post: ['id', 'date_gmt', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt'],
	page: ['id', 'date_gmt', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt'],
	product: [
		'id',
		'name',
		'slug',
		'permalink',
		'date_created_gmt',
		'date_modified_gmt',
		'description',
		'short_description',
		'price',
		'regular_price',
		'sale_price',
		'stock_status',
		'sku',
		'images',
		'categories',
	],
};

const KNOWN_SEO_NAMESPACES = ['rankmath', 'yoast', 'aioseo', 'wpseo'];

/** Analyze a WordPress URL — polite, read-only, ~6 requests. */
export async function analyzeSite(client: WpClient): Promise<DiscoveryReport> {
	const root = await client.root();
	const report: DiscoveryReport = {
		name: root.name,
		description: root.description,
		url: root.url,
		postTypes: {},
		collections: [],
		junkPages: [],
		lockedDown: false,
		woocommerce: root.namespaces?.some((ns) => ns.startsWith('wc/')) ?? false,
		wooPublic: false,
		seoPlugins: [...new Set(
			(root.namespaces ?? [])
				.map((ns) => ns.split('/').slice(0, 2).join('/'))
				.filter((ns) => KNOWN_SEO_NAMESPACES.some((known) => ns.toLowerCase().startsWith(known)))
		)],
	};

	// Post types — capability discovery, not content.
	try {
		const types = await client.postTypes();
		report.postTypes = Object.fromEntries(
			Object.entries(types)
				.filter(([, value]) => value.rest_base)
				.map(([key, value]) => [key, { restBase: value.rest_base as string, name: value.name ?? key }])
		);
	} catch (error) {
		if (isNotFound(error)) {
			report.lockedDown = true;
			return report;
		}
		throw error;
	}

	// Candidate collections: the standard three, plus any CPT with a rest base.
	const candidates: Array<{ slug: string; restBase: string; kind: CollectionAnalysis['kind'] }> = [];
	if (report.postTypes.post) {
		candidates.push({ slug: 'posts', restBase: `wp/v2/${report.postTypes.post.restBase}`, kind: 'post' });
	}
	if (report.postTypes.page) {
		candidates.push({ slug: 'pages', restBase: `wp/v2/${report.postTypes.page.restBase}`, kind: 'page' });
	}
	if (report.postTypes.product) {
		candidates.push({
			slug: 'products',
			restBase: report.woocommerce ? 'wc/v3/products' : `wp/v2/${report.postTypes.product.restBase}`,
			kind: 'product',
		});
	}

	for (const candidate of candidates) {
		try {
			const { headers } = await client.get(candidate.restBase, { page: '1', per_page: '1' });
			const count = Number(headers.get('x-wp-total') ?? '0');
			report.collections.push({ ...candidate, count, optional: count === 0 });
			if (candidate.slug === 'products' && candidate.restBase.startsWith('wc/')) {
				report.wooPublic = true;
			}
		} catch (error) {
			// Auth-required wc/v3: count through the public wp/v2 CPT instead.
			// The profile still prefers wc/v3 (env consumer keys) for prices.
			if (
				candidate.slug === 'products' &&
				candidate.restBase.startsWith('wc/') &&
				isAuthError(error) &&
				report.postTypes.product
			) {
				try {
					const fallback = `wp/v2/${report.postTypes.product.restBase}`;
					const { headers } = await client.get(fallback, { page: '1', per_page: '1' });
					const count = Number(headers.get('x-wp-total') ?? '0');
					report.collections.push({ ...candidate, count, optional: count === 0 });
				} catch (fallbackError) {
					if (isNotFound(fallbackError)) {
						report.collections.push({ ...candidate, count: 0, optional: true });
					} else {
						throw fallbackError;
					}
				}
				continue;
			}
			if (isNotFound(error)) {
				report.collections.push({ ...candidate, count: 0, optional: true });
			} else {
				throw error;
			}
		}
	}

	// Front page: show_on_front === 'page' + page_on_front → slug lookup.
	if (root.show_on_front === 'page' && typeof root.page_on_front === 'number') {
		try {
			const { body } = await client.get<{ slug?: string }>(`wp/v2/pages/${root.page_on_front}`, {
				_fields: 'slug',
			});
			if (body.slug) {
				report.frontPageSlug = body.slug;
			}
		} catch {
			// Front-page slug is a bonus — never fatal.
		}
	}

	// Junk pages: match the page collection against the heuristics.
	const pages = report.collections.find((c) => c.kind === 'page');
	if (pages) {
		try {
			const { body } = await client.get<Array<{ slug?: string }>>(pages.restBase, {
				per_page: '100',
				page: '1',
				_fields: 'slug',
			});
			report.junkPages = (Array.isArray(body) ? body : [])
				.map((page) => page.slug ?? '')
				.filter((slug) => isJunkSlug(slug));
		} catch {
			// Junk detection is a bonus — never fatal.
		}
	}

	// Woo auth probe folded into the count pass: wc/v3 answering 200 means
	// prices work without consumer keys (wooPublic).
	return report;
}

/** Convert a discovery report into a draft site profile. */
export function profileFromDiscovery(report: DiscoveryReport): SiteProfile {
	const collections: WpCollection[] = report.collections.map((analysis) => ({
		slug: analysis.slug,
		restBase: analysis.restBase,
		fields: FIELDS[analysis.kind],
		embed: analysis.kind !== 'product',
		kind: analysis.kind,
		authMode: analysis.kind === 'product' && analysis.restBase.startsWith('wc/') ? 'wc-consumer' : undefined,
		optional: analysis.optional,
		exclude: analysis.kind === 'page' && report.junkPages.length > 0 ? report.junkPages : undefined,
	}));

	return {
		url: report.url,
		name: report.name,
		frontPage: report.frontPageSlug,
		collections,
	};
}

/** Render the human-readable discovery summary. */
export function renderReport(report: DiscoveryReport): string {
	const lines: string[] = [];
	lines.push(`${report.name} — ${report.url}`);
	if (report.description) {
		lines.push(`  ${report.description}`);
	}
	if (report.lockedDown) {
		lines.push('  ! wp/v2 is locked down (REST disabled or protected) — no content is reachable.');
		return lines.join('\n');
	}
	lines.push(
		report.woocommerce
			? report.wooPublic
				? '  WooCommerce: wc/v3 public (prices available without credentials)'
				: '  WooCommerce: wc/v3 present but requires consumer keys (prices need WP_WC_CONSUMER_KEY/_SECRET in .env)'
			: '  WooCommerce: not detected'
	);
	for (const plugin of report.seoPlugins) {
		lines.push(`  SEO plugin: ${plugin}`);
	}
	if (report.frontPageSlug) {
		lines.push(`  Front page: /${report.frontPageSlug}/ (static front page → renders at /)`);
	}
	for (const collection of report.collections) {
		lines.push(
			`  ${collection.slug} (${collection.restBase}): ${collection.count} entries${collection.optional ? ' — marked optional' : ''}`
		);
	}
	if (report.junkPages.length > 0) {
		lines.push(`  Excluded junk pages: ${report.junkPages.join(', ')}`);
	}
	return lines.join('\n');
}

function isNotFound(error: unknown): boolean {
	return (
		typeof error === 'object' &&
		error !== null &&
		'code' in error &&
		((error as { code: string }).code === 'wp_not_found')
	);
}

function isAuthError(error: unknown): boolean {
	return (
		typeof error === 'object' &&
		error !== null &&
		'code' in error &&
		((error as { code: string }).code === 'wp_auth_required')
	);
}

function isJunkSlug(slug: string): boolean {
	if (DEFAULT_JUNK_SLUGS.includes(slug)) {
		return true;
	}
	return DEFAULT_JUNK_PREFIXES.some((prefix) => slug.startsWith(prefix));
}
