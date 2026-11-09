// /README.md — the deploy-branch build report.
//
// The mirror branch for a site is FORCE-PUSHED on every build (see the
// shared-mirror model in deploy.json), so a hand-written README on that branch
// would be destroyed by the next build. The report therefore has to be an
// artifact of the build, exactly like /design-audit.json and
// /tokens.dtcg.json — this endpoint renders it from the manifest and the
// resolved design, so it is always true of the bytes on the branch.
//
// Everything comes from the build inputs; no model writes any of it. The
// composer is deterministic (no clock, no randomness), so a content-only
// rebuild does not churn the file.
//
// NOTE: a `.ts` endpoint has NO frontmatter fence — a leading `---` is a
// syntax error (Proposal 114 gotcha).
import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';
import { contrastAudit } from '../forge/contrast.ts';
import { composeSiteReadme } from '../forge/readme.ts';
import type { ReadmeBand } from '../forge/readme.ts';
import { resolvedDesign } from '../lib/design.generated.ts';

export const prerender = true;

/**
 * The manifest read structurally, every field optional.
 *
 * `nvoos.json` is written per site and per preset, so the JSON's inferred type
 * varies: `tracking` appears only when the profile has a tracking block,
 * `collections[].kind` only when a kind was set, and `site` / the design
 * block's `builder` / `outline` are newer than the template. Reading the shape
 * we actually consume keeps this endpoint type-safe across every preset and
 * every older scaffold.
 */
type ManifestShape = {
	name?: string;
	url?: string;
	seoDescription?: string;
	generatedAt?: string;
	generator?: string;
	render?: string;
	/** The `sites/<slug>.json` stem (newer scaffolds only). */
	site?: string;
	surfaces?: Record<string, boolean>;
	collections?: Array<{ slug?: string; restBase?: string; kind?: string }>;
	tracking?: { enabled?: boolean; vendors?: string[]; consentMode?: string };
	design?: {
		builder?: string;
		outline?: Array<{ id?: string; anchor?: string; via?: string; columns?: Array<{ width?: number }> }>;
	};
};

/** Load any collection by name — the preset decides which ones exist. */
const loadCollection = getCollection as unknown as ( name: string ) => Promise< Array< unknown > >;

export const GET: APIRoute = async ( { site } ) => {
	const audit = contrastAudit( resolvedDesign.tokens, resolvedDesign.darkTokens, resolvedDesign.highTokens );
	const meta = manifest as unknown as ManifestShape;

	// Detected bands (Proposal 137): one row per outline entry, with the column
	// count reduced from the per-column widths.
	const bands: ReadmeBand[] = ( meta.design?.outline ?? [] )
		.filter( ( band ) => Boolean( band?.id ) )
		.map( ( band ) => ( {
			id: String( band.id ),
			anchor: band.anchor,
			via: band.via,
			columns: Array.isArray( band.columns ) ? band.columns.length : undefined,
		} ) );

	const pages = await getCollection( 'pages' );
	const posts = await getCollection( 'posts' );
	// Woo is optional on a forge site (the preset may not even register the
	// collection) — never let the product count take the build down.
	let productCount = 0;
	try {
		productCount = ( await loadCollection( 'products' ) ).length;
	} catch {
		productCount = 0;
	}

	const origin = ( site?.origin ?? meta.url ?? '' ).replace( /\/+$/, '' );

	const body = composeSiteReadme( {
		name: meta.name ?? 'Forge site',
		summary: meta.seoDescription ?? '',
		cmsUrl: meta.url ?? '',
		publicOrigin: origin,
		generatedAt: meta.generatedAt,
		generator: meta.generator,
		builder: meta.design?.builder,
		bands,
		counts: { pages: pages.length, posts: posts.length, products: productCount },
		render: meta.render ?? 'static',
		surfaces: meta.surfaces ?? {},
		collections: ( meta.collections ?? [] ).map( ( collection ) => ( {
			slug: collection.slug ?? '',
			kind: collection.kind,
			restBase: collection.restBase,
		} ) ),
		tracking: meta.tracking
			? {
					enabled: meta.tracking.enabled,
					vendors: meta.tracking.vendors,
					consentMode: meta.tracking.consentMode,
			  }
			: undefined,
		tokens: resolvedDesign.tokens,
		concept: resolvedDesign.concept,
		analysedAt: resolvedDesign.source.analyzedAt,
		adaptation: resolvedDesign.adaptation
			? {
					licensingNote: resolvedDesign.adaptation.licensingNote,
					attribution: resolvedDesign.adaptation.attribution,
			  }
			: undefined,
		audit,
		profilePath: meta.site ? `sites/${ meta.site }.json` : undefined,
	} );

	return new Response( body, {
		headers: { 'content-type': 'text/markdown; charset=utf-8' },
	} );
};
