// SPDX-License-Identifier: MIT
import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';
import {
	fetchBridgeRedirects,
	PORTABLE_STATUSES,
	type RedirectRow,
	type RedirectStatus,
} from '../lib/bridge-redirects.ts';

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
 * it onto the status set the static hosts actually honour.
 *
 * A missing bridge is a console warning, never a build failure — that promise
 * is enforced in `lib/bridge-redirects.ts`, which also refuses to call the old
 * origin when the profile says the CMS is gone.
 */
export const prerender = true;

export const GET: APIRoute = async () => {
	const redirects = new Map< string, RedirectRow >();
	const push = ( source: string, target: string, status: RedirectStatus = 301 ) => {
		if ( source && source !== '/' && source !== target ) {
			redirects.set( source, { from: source, to: target, status } );
		}
	};

	// 1. Stored rules from the source site's redirect plugin (via the bridge).
	//    `bridge-redirects.ts` never throws: a dead origin, an HTML body or a
	//    payload of the wrong shape each degrade to this URL-structure map.
	const stored = await fetchBridgeRedirects( {
		base:
			import.meta.env.NVOOS_FORGE_REDIRECTS_ENDPOINT ||
			import.meta.env.WP_SITE_URL ||
			manifest.url ||
			'',
		token: import.meta.env.NVOOS_FORGE_REDIRECTS_TOKEN,
		// `materialized` means the CMS is GONE: nothing may contact the old
		// origin, which is now this very site.
		contentSource: ( manifest as { contentSource?: string } ).contentSource,
	} );
	if ( stored.warning ) {
		console.warn( `[forge] ${ stored.warning }` );
	}
	for ( const entry of stored.rows ) {
		push( entry.from, entry.to, entry.status );
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

	// 2b. Extra (CPT) collections move as well: the scaffold builds them under
	//     `basePath` (default `/<slug>`) as `/<base>/<id>/<slug>/`, so
	//     `/eca/art-and-craft/` must 301 to `/eca/10784/art-and-craft/` — not
	//     to `/posts/…`, which is what a posts-only map produced.
	const CORE = new Set(['posts', 'pages', 'products']);
	const loadCollection = getCollection as unknown as (name: string) => Promise<Array<any>>;
	for (const declared of manifest.collections) {
		if (CORE.has(declared.slug)) continue;
		const base = ((declared as { basePath?: string }).basePath ?? `/${declared.slug}`).replace(/\/+$/, '');
		const entries = await loadCollection(declared.slug);
		for (const entry of entries) {
			const source = pathnameOf(entry.data.link);
			if (!source) continue;
			push(source, `${base}/${entry.id}/${entry.data.slug ?? entry.id}/`);
		}
	}

	// 3. Operator-declared redirects — LAST so they win on conflict. These are
	//    the cutover leftovers the URL structure cannot see: a front page's own
	//    slug, a retired landing page, a path the host still answers with 403.
	const declared = (manifest as { redirects?: unknown }).redirects;
	if (Array.isArray(declared)) {
		for (const raw of declared) {
			if (typeof raw !== 'object' || raw === null) continue;
			const entry = raw as { from?: unknown; to?: unknown; status?: unknown };
			if (typeof entry.from !== 'string' || !entry.from.startsWith('/')) continue;
			const status =
				typeof entry.status === 'number' && PORTABLE_STATUSES.has(entry.status)
					? (entry.status as RedirectStatus)
					: 301;
			// A 4xx rule carries no target; anything else needs one.
			if (status < 400 && typeof entry.to !== 'string') continue;
			push(entry.from, typeof entry.to === 'string' ? entry.to : '', status);
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
