// ---------------------------------------------------------------------------
// content.ts — the content tool slice (096 §3.3 long-tail): audit, preview
// (through the versioned sanitizer), the live redirect map, media audit, and
// the typed loader-config generator. All fetches are registry-gated (site
// names only — no arbitrary URLs) and bounded.
// ---------------------------------------------------------------------------

import { readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { z } from 'zod';

import { scanHtml, type ContentScan } from '../../lib/content-scan.ts';
import { generateRedirects } from '../../lib/redirects.ts';
import { sanitizeHtml } from '../../lib/sanitize.ts';
import { renderContentConfig, rewriteContentConfig } from '../../lib/scaffold.ts';
import { SitesStore } from '../../lib/sites.ts';
import type { SiteProfile, WpCollection, WpEntry } from '../../lib/types.ts';
import { WpClient } from '../../lib/wp-client.ts';
import type { ToolDef } from '../tool-def.ts';
import { errorResult, IDEMPOTENT_WRITE_TOOL, READ_ONLY_TOOL, text } from '../util.ts';

/** The fields every content tool needs. */
const ENTRY_FIELDS = [ 'id', 'slug', 'link', 'permalink', 'title', 'content', 'excerpt', 'date_gmt' ];

/** Aggregate image facts across scans. */
function aggregateImages( scans: ContentScan[] ) {
	const hosts: Record<string, number> = {};
	let total = 0;
	let missingAlt = 0;
	let sameHost = 0;
	for ( const scan of scans ) {
		total += scan.images.total;
		missingAlt += scan.images.missingAlt;
		sameHost += scan.images.sameHost;
		for ( const [ host, count ] of Object.entries( scan.images.hosts ) ) {
			hosts[ host ] = ( hosts[ host ] ?? 0 ) + count;
		}
	}
	return { total, missingAlt, sameHost, hosts };
}

async function resolveSiteClient( env: { homeDir: string }, name: string ): Promise<{ client: WpClient; profile: SiteProfile; url: string }> {
	const { ref, profile } = await new SitesStore( env.homeDir ).resolve( name );
	const client = WpClient.fromProfile( profile );
	return { client, profile, url: ref.url };
}

/** Fetch entries for one collection (or one slug), bounded. */
async function fetchEntries(
	client: WpClient,
	collection: WpCollection,
	options: { slug?: string; limit: number }
): Promise<{ entries: WpEntry[]; truncated: boolean }> {
	if ( options.slug ) {
		const params: Record<string, string> = { _fields: ENTRY_FIELDS.join( ',' ), slug: options.slug };
		const { body } = await client.get<WpEntry[]>( collection.restBase, params );
		return { entries: Array.isArray( body ) ? body.slice( 0, options.limit ) : [], truncated: false };
	}
	const { entries, truncated } = await client.fetchAll<WpEntry>( collection.restBase, {
		perPage: Math.min( collection.perPage ?? 100, options.limit ),
		maxPages: Math.max( 1, Math.ceil( options.limit / Math.min( collection.perPage ?? 100, options.limit ) ) ),
		fields: ENTRY_FIELDS,
	} );
	return { entries: entries.slice( 0, options.limit ), truncated };
}

const SITE = z.string().regex( /^[a-z0-9][a-z0-9-]{0,31}$/ );

export const CONTENT_TOOLS: ToolDef[] = [
	{
		name: 'content_audit',
		title: 'Audit content (embeds, shortcodes, complexity)',
		description:
			'Audit a registered site\'s rendered content: headings, links, tables, figures, embed hosts, shortcode artifacts (and any UNPROCESSED shortcode brackets that leaked into rendered text), and per-entry size. One collection + optional slug, or every collection up to a bounded limit. Registry sites only.',
		schema: z.object( {
			site: SITE,
			collection: z.string().optional(),
			slug: z.string().optional(),
			limit: z.number().int().min( 1 ).max( 50 ).optional(),
		} ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, collection, slug, limit } = args as { site: string; collection?: string; slug?: string; limit?: number };
			try {
				const { client, profile, url } = await resolveSiteClient( env, site );
				const collections = collection ? profile.collections.filter( ( entry ) => entry.slug === collection ) : profile.collections;
				if ( collections.length === 0 ) {
					return errorResult( `Site "${ site }" has no collection named "${ collection ?? '' }".` );
				}
				const cap = limit ?? 10;
				const entries: { slug: string; id?: number; link?: string; scan: ContentScan }[] = [];
				let truncated = false;
				for ( const target of collections ) {
					const fetched = await fetchEntries( client, target, { slug, limit: cap } );
					truncated = truncated || fetched.truncated;
					for ( const entry of fetched.entries ) {
						const rendered = ( entry.content as { rendered?: string } | undefined )?.rendered ?? '';
						const scan = scanHtml( rendered, new URL( url ).hostname );
						entries.push( {
							slug: String( entry.slug ?? entry.id ?? '?' ),
							id: typeof entry.id === 'number' ? entry.id : undefined,
							link: typeof entry.link === 'string' ? entry.link : undefined,
							scan,
						} );
					}
				}
				const embeds = new Set( entries.flatMap( ( entry ) => entry.scan.embeds ) );
				const artifacts = new Set( entries.flatMap( ( entry ) => entry.scan.shortcodeArtifacts ) );
				const unprocessed = new Set( entries.flatMap( ( entry ) => entry.scan.unprocessedShortcodes ) );
				return text(
					`Audited ${ entries.length } entr${ entries.length === 1 ? 'y' : 'ies' } on ${ site } — ${ unprocessed.size ? `${ unprocessed.size } unprocessed shortcode bracket type(s) found!` : 'no unprocessed shortcodes' }.`,
					{
						site,
						entries,
						aggregate: {
							embeds: [ ...embeds ].sort(),
							shortcodeArtifacts: [ ...artifacts ].sort(),
							unprocessedShortcodes: [ ...unprocessed ].sort(),
						},
						truncated,
					}
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'content_preview',
		title: 'Preview one post through the versioned sanitizer',
		description:
			'Fetch one post/page by collection + slug and run it through the forge\'s mandatory sanitizer (the XSS gate every generated page passes): returns the sanitized HTML plus a before/after report (scripts, event handlers, javascript: URLs, removed/kept bytes). Registry sites only.',
		schema: z.object( { site: SITE, collection: z.string(), slug: z.string() } ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, collection, slug } = args as { site: string; collection: string; slug: string };
			try {
				const { client, profile } = await resolveSiteClient( env, site );
				const target = profile.collections.find( ( entry ) => entry.slug === collection );
				if ( ! target ) {
					return errorResult( `Site "${ site }" has no collection named "${ collection }".` );
				}
				const { entries } = await fetchEntries( client, target, { slug, limit: 1 } );
				if ( entries.length === 0 ) {
					return errorResult( `No entry with slug "${ slug }" in collection "${ collection }".` );
				}
				const rendered = ( entries[ 0 ].content as { rendered?: string } | undefined )?.rendered ?? '';
				const countOf = ( html: string, pattern: RegExp ) => ( html.match( pattern ) ?? [] ).length;
				const before = {
					bytes: Buffer.byteLength( rendered ),
					scripts: countOf( rendered, /<script\b/gi ),
					handlers: countOf( rendered, /\son[a-z]+\s*=/gi ),
					javascriptUrls: countOf( rendered, /javascript\s*:/gi ),
				};
				const sanitized = sanitizeHtml( rendered );
				const after = {
					bytes: Buffer.byteLength( sanitized ),
					scripts: countOf( sanitized, /<script\b/gi ),
					handlers: countOf( sanitized, /\son[a-z]+\s*=/gi ),
					javascriptUrls: countOf( sanitized, /javascript\s*:/gi ),
				};
				return text(
					`Preview of "${ slug }" (${ collection }): sanitizer kept ${ after.bytes } of ${ before.bytes } bytes — ${ before.scripts } script tag(s), ${ before.handlers } handler(s), ${ before.javascriptUrls } javascript: URL(s) before; 0/0/0 after.`,
					{ site, collection, slug, before, after, sanitizedHtml: sanitized.slice( 0, 50_000 ) }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'redirects_generate',
		title: 'Generate the WP → Astro 301 redirect map',
		description:
			'Compute the structured single-hop 301 map from the WordPress URL structure to the generated one (the exact rules the template\'s /redirects.json applies at build time): posts → /posts/{id}/{slug}/, pages → / or /{slug}/, products → /products/{id}/{slug}/. Deterministic and deduped. Registry sites only.',
		schema: z.object( { site: SITE } ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site } = args as { site: string };
			try {
				const { client, profile } = await resolveSiteClient( env, site );
				const report = await generateRedirects( profile, client );
				return text(
					`${ report.redirects.length } redirect(s) from ${ report.scanned } scanned entries${ report.truncated ? ' (TRUNCATED — raise maxPages)' : '' }.`,
					report
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'media_audit',
		title: 'Audit images (hosts, alt text, pipeline coverage)',
		description:
			'Aggregate image facts across a registered site\'s content: total images, same-host count (the Astro Image pipeline candidates), per-host breakdown, and images missing alt text — plus the worst per-entry offenders. Bounded to the first entries of each collection. Registry sites only.',
		schema: z.object( { site: SITE, limit: z.number().int().min( 1 ).max( 100 ).optional() } ),
		annotations: READ_ONLY_TOOL,
		write: false,
		handler: async ( args, env ) => {
			const { site, limit } = args as { site: string; limit?: number };
			try {
				const { client, profile, url } = await resolveSiteClient( env, site );
				const cap = limit ?? 25;
				const scans: { slug: string; scan: ContentScan }[] = [];
				let truncated = false;
				for ( const collection of profile.collections ) {
					const { entries, truncated: collectionTruncated } = await fetchEntries( client, collection, { limit: cap } );
					truncated = truncated || collectionTruncated;
					for ( const entry of entries ) {
						const rendered = ( entry.content as { rendered?: string } | undefined )?.rendered ?? '';
						scans.push( { slug: String( entry.slug ?? entry.id ?? '?' ), scan: scanHtml( rendered, new URL( url ).hostname ) } );
					}
				}
				const totals = aggregateImages( scans.map( ( entry ) => entry.scan ) );
				const offenders = scans
					.map( ( entry ) => ( { slug: entry.slug, images: entry.scan.images.total, missingAlt: entry.scan.images.missingAlt } ) )
					.filter( ( entry ) => entry.images > 0 )
					.sort( ( a, b ) => b.missingAlt - a.missingAlt || b.images - a.images )
					.slice( 0, 5 );
				return text(
					`Media audit: ${ totals.total } image(s) — ${ totals.sameHost } same-host (pipeline candidates), ${ totals.missingAlt } missing alt text, ${ Object.keys( totals.hosts ).length } host(s).`,
					{ site, totals, offenders, truncated }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
	{
		name: 'collections_generate',
		title: 'Generate the typed loader config for a site',
		description:
			'Render the typed content-layer loader config block (the FORGE:site span) for a registered site\'s profile — env-driven siteUrl, auth wiring, and one WpCollection literal per collection. With a target (workdir-confined), rewrites the project\'s content.config.ts in place.',
		schema: z.object( { site: SITE, target: z.string().optional() } ),
		annotations: IDEMPOTENT_WRITE_TOOL,
		write: true,
		handler: async ( args, env ) => {
			const { site, target } = args as { site: string; target?: string };
			try {
				const { profile } = await resolveSiteClient( env, site );
				const block = renderContentConfig( profile );
				let applied: string | undefined;
				if ( target ) {
					const absolute = resolve( target );
					const work = resolve( env.workDir );
					if ( absolute !== work && ! absolute.startsWith( work + '/' ) && ! absolute.startsWith( work + '\\' ) ) {
						return errorResult( `Target ${ target } is outside the forge work directory (${ env.workDir }).` );
					}
					const configPath = join( absolute, 'src', 'content.config.ts' );
					await writeFile( configPath, rewriteContentConfig( await readFile( configPath, 'utf8' ), profile ), 'utf8' );
					applied = configPath;
				}
				return text(
					applied ? `Rewrote the collections config in ${ applied }.` : `Rendered the collections config for ${ site }.`,
					{ site, applied, block }
				);
			} catch ( error ) {
				return errorResult( error instanceof Error ? error.message : String( error ) );
			}
		},
	},
];
