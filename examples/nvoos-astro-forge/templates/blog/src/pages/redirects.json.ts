import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';

/**
 * /redirects.json — the structured redirect map for the generated site.
 *
 * Two sources:
 *   1. The stored rules your redirect plugin already has (Redirection / Rank
 *      Math), read from the Forge Bridge's read-only export (Proposal 132).
 *      These are the legacy URLs that must survive the cutover.
 *   2. The rebuild's own URL structure (Proposal 096 §0 SEO rule): preserve
 *      URLs at the URL level; everything that moved gets a single-hop
 *      redirect. These win on conflict — they describe where the rebuilt
 *      site actually serves the content.
 *
 * Prerendered in both render modes — `scripts/postbuild.mjs` materializes
 * the host-agnostic `dist/_redirects` from this document after `astro build`.
 * This document keeps the FAITHFUL status from the source; postbuild projects
 * it onto the status set the static hosts actually honour. A missing bridge is
 * a console warning, never a build failure.
 */
export const prerender = true;

type RedirectStatus = 301 | 302 | 303 | 307 | 308 | 404 | 410;
type RedirectRow = { from: string; to: string; status: RedirectStatus };

/** Statuses a static host can express in `_redirects`. */
const PORTABLE_STATUSES: ReadonlySet<number> = new Set([301, 302, 303, 307, 308, 404, 410]);

export const GET: APIRoute = async () => {
	const redirects = new Map<string, RedirectRow>();
	const push = (source: string, target: string, status: RedirectStatus = 301) => {
		if (source && source !== '/' && source !== target) {
			redirects.set(source, { from: source, to: target, status });
		}
	};

	// 1. Stored rules from the source site's redirect plugin (via the bridge).
	for (const entry of await fetchStoredRedirects()) {
		push(entry.from, entry.to, entry.status);
	}

	// 2. The rebuild's own URL structure.
	const posts = await getCollection('posts');
	for (const post of posts) {
		const source = pathnameOf(post.data.link);
		if (!source) continue;
		push(source, `/posts/${post.id}/${post.data.slug}/`);
	}

	const pages = await getCollection('pages');
	for (const page of pages) {
		const source = pathnameOf(page.data.link);
		if (!source) continue;
		const target = page.data.slug === manifest.frontPage ? '/' : `/${page.data.slug}/`;
		push(source, target);
	}

	const hasProducts = manifest.collections.some((collection) => collection.slug === 'products');
	if (hasProducts) {
		// `astro check` types getCollection against the declared collections;
		// products only exists in storefront scaffolds. The manifest gate
		// keeps this call unreachable otherwise, and the routing fields used
		// here (id/slug/link) exist on every collection shape — the cast
		// documents exactly that.
		const products = await getCollection('products' as 'posts');
		for (const product of products) {
			const source = pathnameOf(product.data.link);
			if (!source) continue;
			push(source, `/products/${product.id}/${product.data.slug}/`);
		}
	}

	// Deterministic output — same content, same file, stable diffs.
	const sorted = [...redirects.values()].sort((a, b) => a.from.localeCompare(b.from));
	return new Response(`${JSON.stringify(sorted, null, '\t')}\n`, {
		headers: { 'content-type': 'application/json; charset=utf-8' },
	});
};

/** The pathname of a WP canonical link (empty when not same-site/parseable). */
function pathnameOf(link: string | undefined): string | undefined {
	if (!link) {
		return undefined;
	}
	try {
		const url = new URL(link);
		return url.pathname;
	} catch {
		return undefined;
	}
}

/**
 * Fetch the redirect plugins' stored rules from the Forge Bridge.
 *
 * Endpoint resolution is env-first (deploy override), then WP_SITE_URL (the
 * build's real source), then the manifest URL. A 404 means "bridge not
 * installed" and stays silent; other failures warn and fall back to the
 * URL-structure map so the build always ships.
 */
async function fetchStoredRedirects(): Promise<RedirectRow[]> {
	const base = (
		import.meta.env.NVOOS_FORGE_REDIRECTS_ENDPOINT ||
		import.meta.env.WP_SITE_URL ||
		manifest.url ||
		''
	).replace(/\/+$/, '');
	if (!base) {
		return [];
	}

	const token = import.meta.env.NVOOS_FORGE_REDIRECTS_TOKEN;
	const headers: Record<string, string> = { accept: 'application/json' };
	if (token) {
		headers['x-forge-redirects-token'] = token;
	}

	let response: Response;
	try {
		response = await fetch(`${base}/wp-json/nvoos-forge/v1/redirects`, { headers });
	} catch (error) {
		console.warn(
			`[forge] stored redirects unavailable (${error instanceof Error ? error.message : String(error)}) — using the URL-structure map only.`
		);
		return [];
	}

	if (!response.ok) {
		if (response.status !== 404) {
			console.warn(`[forge] stored redirects unavailable (HTTP ${response.status}) — using the URL-structure map only.`);
		}
		return [];
	}

	const body = (await response.json()) as unknown;
	const rawList = typeof body === 'object' && body !== null ? (body as { redirects?: unknown }).redirects : undefined;
	if (!Array.isArray(rawList)) {
		console.warn('[forge] stored redirects returned an unexpected payload — using the URL-structure map only.');
		return [];
	}

	const rows: RedirectRow[] = [];
	const seen = new Set<string>();
	for (const raw of rawList) {
		if (typeof raw !== 'object' || raw === null) {
			continue;
		}
		const record = raw as Record<string, unknown>;
		const from = typeof record.source === 'string' ? record.source : '';
		const to = typeof record.target === 'string' ? record.target : '';
		const status = typeof record.type === 'number' ? record.type : 301;
		if (!from.startsWith('/') || from === '/' || from === to || seen.has(from)) {
			continue;
		}
		if (!PORTABLE_STATUSES.has(status) || (status < 400 && !to)) {
			continue;
		}
		seen.add(from);
		rows.push({ from, to, status: status as RedirectStatus });
	}

	return rows;
}
