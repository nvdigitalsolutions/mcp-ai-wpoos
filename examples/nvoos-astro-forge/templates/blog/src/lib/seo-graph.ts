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
import { repairMediaUrl } from './media.ts';
import { jsonLdGraph, type JsonLdGraph } from './seo.ts';

/** Build the Organization + WebSite + (breadcrumbs) + bands + page graph. */
export function buildGraph(
	astroSite: URL | undefined,
	page: object,
	breadcrumbs?: Array<{ name: string; url: string }>,
	extraNodes?: Array<Record<string, unknown>>
): JsonLdGraph {
	return jsonLdGraph({
		site: {
			url: astroSite?.origin ?? '',
			name: manifest.name ?? 'Forge',
			// The source's absolute logo URL is data, so JSON-LD keeps an
			// absolute URL — but it must point at the LIVE media host after a
			// domain move (Proposal 139 §repair).
			logo: repairMediaUrl( chrome?.logo?.src ),
			searchUrl: astroSite && manifest.surfaces?.search !== false ? new URL('/search/', astroSite).href : undefined,
		},
		page,
		breadcrumbs,
		extraNodes,
	});
}

/** The Organization @id the band nodes reference (136 §4.5). */
export function organizationId(astroSite: URL | undefined): string {
	return `${(astroSite?.origin ?? '').replace(/\/+$/, '')}/#organization`;
}

/** A Home crumb, or an empty trail when the site URL is unknown. */
export function homeCrumb(astroSite: URL | undefined): Array<{ name: string; url: string }> {
	return astroSite ? [{ name: 'Home', url: new URL('/', astroSite).href }] : [];
}
