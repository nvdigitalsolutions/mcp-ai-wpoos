// ---------------------------------------------------------------------------
// seo-graph.ts — the site-level JSON-LD graph helper (Proposal 131 §2.3).
//
// Pages pass their typed page node + breadcrumb trail; this wires the
// Organization/WebSite entities from the manifest + harvested chrome, so the
// rebuild emits the graph a real SEO plugin would — rebuilt from facts, never
// copied from Yoast's `schema.@graph`.
// ---------------------------------------------------------------------------
import manifest from '../../nvoos.json';
import { chrome } from './chrome.generated.ts';
import { jsonLdGraph, type JsonLdGraph } from './seo.ts';

/** Build the Organization + WebSite + (breadcrumbs) + page graph. */
export function buildGraph(
	astroSite: URL | undefined,
	page: object,
	breadcrumbs?: Array<{ name: string; url: string }>
): JsonLdGraph {
	return jsonLdGraph({
		site: {
			url: astroSite?.origin ?? '',
			name: manifest.name ?? 'Forge',
			logo: chrome?.logo?.src,
			searchUrl: astroSite && manifest.surfaces?.search !== false ? new URL('/search/', astroSite).href : undefined,
		},
		page,
		breadcrumbs,
	});
}

/** A Home crumb, or an empty trail when the site URL is unknown. */
export function homeCrumb(astroSite: URL | undefined): Array<{ name: string; url: string }> {
	return astroSite ? [{ name: 'Home', url: new URL('/', astroSite).href }] : [];
}
