// ---------------------------------------------------------------------------
// materialize.ts — turn a BUILT artifact's baked content store into local
// content files (2026-11-15).
//
// Why: a forged site can outlive its CMS. Once WordPress is removed, the only
// surviving copy of the content is the store Astro baked into the artifact,
// and without a command to read it a rebuild is impossible — every build dies
// at `Endpoint not found: wp/v2/pages`, and the operator has no path back.
//
// Shape written: `<out>/<collection>/<slug>.mdx` (frontmatter only) beside
// `<slug>.html` (the sanitized body). The body must NOT go in the MDX: MDX
// parses its body as JSX and rejects arbitrary WordPress HTML
// (`Expected a closing tag for <ul> … before the end of paragraph`).
// ---------------------------------------------------------------------------

import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import type { SiteProfile } from './types.ts';

/** Frontmatter fields the hybrid loader's schema understands (106 P2). */
const FRONTMATTER_FIELDS = [
	'id',
	'title',
	'slug',
	'date',
	'modified',
	'excerpt',
	'link',
	'seoTitle',
	'seoDescription',
];

export interface MaterializedDocument {
	collection: string;
	slug: string;
	mdxBytes: number;
	htmlBytes: number;
}

/**
 * Revive Astro's serialized content store.
 *
 * The store is a flat array of nodes: primitives inline, tagged arrays
 * (`['Map', k1, v1, …]`, `['Set', …]`, `['Date', iso]`) and plain objects whose
 * properties are INDEXES back into the array. `-1` means "absent" (Astro writes
 * it for an undefined field). It must be read, never `JSON.parse`d — the
 * literal is JS, so it can carry escapes JSON rejects.
 */
export function decodeContentStore( store: readonly unknown[] ): Record< string, unknown > {
	const cache = new Map< number, unknown >();

	const resolve = ( ref: unknown ): unknown => {
		if ( typeof ref !== 'number' ) {
			return ref;
		}
		if ( ref < 0 ) {
			return undefined;
		}
		if ( ref >= store.length ) {
			return ref;
		}
		if ( cache.has( ref ) ) {
			return cache.get( ref );
		}
		return reviveNode( store[ ref ], ref );
	};

	const reviveNode = ( value: unknown, index: number ): unknown => {
		if ( value === null || typeof value !== 'object' ) {
			return value;
		}
		if ( Array.isArray( value ) ) {
			const [ tag, ...rest ] = value as unknown[];
			if ( tag === 'Map' ) {
				const out: Record< string, unknown > = {};
				cache.set( index, out );
				for ( let i = 0; i < rest.length; i += 2 ) {
					out[ String( resolve( rest[ i ] ) ) ] = resolve( rest[ i + 1 ] );
				}
				return out;
			}
			if ( tag === 'Set' ) {
				return rest.map( ( item ) => resolve( item ) );
			}
			if ( tag === 'Date' ) {
				return new Date( String( rest[ 0 ] ) );
			}
			return value;
		}
		const out: Record< string, unknown > = {};
		cache.set( index, out );
		for ( const [ key, raw ] of Object.entries( value as Record< string, unknown > ) ) {
			out[ key ] = resolve( raw );
		}
		return out;
	};

	return reviveNode( store[ 0 ], 0 ) as Record< string, unknown >;
}

/** Locate the baked content store inside a built artifact. */
export async function findContentStore( artifactDir: string ): Promise< string | undefined > {
	const chunks = join( artifactDir, 'dist', 'server', 'chunks' );
	const names = await readdir( chunks ).catch( () => [] as string[] );
	const match = names.find(
		( name ) => name.startsWith( '_astro_data-layer-content_' ) && name.endsWith( '.mjs' )
	);
	return match ? join( chunks, match ) : undefined;
}

/** A YAML-scalar for a frontmatter value: numbers bare, everything else quoted. */
function yamlValue( value: unknown ): string {
	return typeof value === 'number' ? String( value ) : JSON.stringify( String( value ) );
}

/**
 * Write captured entries as `<outDir>/<collection>/<slug>.mdx` (frontmatter) plus
 * `<slug>.html` (the sanitized body).
 *
 * Both capture paths share this writer so a snapshot taken from a build and one
 * taken from a live CMS are byte-identical in shape.
 */
async function writeDocuments(
	outDir: string,
	documents: ReadonlyArray< {
		collection: string;
		slug: string;
		data: Record< string, unknown >;
		html: string;
	} >
): Promise< MaterializedDocument[] > {
	const results: MaterializedDocument[] = [];
	for ( const doc of documents ) {
		const dir = join( outDir, doc.collection );
		await mkdir( dir, { recursive: true } );

		const lines = [ '---' ];
		for ( const field of FRONTMATTER_FIELDS ) {
			const value = doc.data[ field ];
			if ( value === undefined || value === null || value === '' ) {
				continue;
			}
			lines.push( `${ field }: ${ yamlValue( value ) }` );
		}
		lines.push( '---', '' );
		const mdx = lines.join( '\n' );
		await writeFile( join( dir, `${ doc.slug }.mdx` ), mdx, 'utf8' );

		if ( doc.html !== '' ) {
			await writeFile( join( dir, `${ doc.slug }.html` ), doc.html, 'utf8' );
		}
		results.push( { collection: doc.collection, slug: doc.slug, mdxBytes: mdx.length, htmlBytes: doc.html.length } );
	}
	return results;
}

/**
 * Write every collection in the store to `<outDir>/<collection>/`.
 *
 * The bodies are already sanitized (the loader sanitizes before storing), so
 * they are written verbatim — re-sanitizing would be a second, drifting gate.
 */
export async function materializeContent( options: {
	storeFile: string;
	outDir: string;
} ): Promise< MaterializedDocument[] > {
	const mod = ( await import( pathToFileURL( options.storeFile ).href ) ) as { default?: unknown };
	const store = Array.isArray( mod.default )
		? mod.default
		: JSON.parse( await readFile( options.storeFile, 'utf8' ) );
	const root = decodeContentStore( store as unknown[] );
	const documents: Array< { collection: string; slug: string; data: Record< string, unknown >; html: string } > = [];

	for ( const [ collection, entries ] of Object.entries( root ) ) {
		if ( collection === 'meta::meta' || ! entries || typeof entries !== 'object' ) {
			continue;
		}
		for ( const entry of Object.values( entries as Record< string, unknown > ) ) {
			const record = ( entry ?? {} ) as {
				id?: unknown;
				data?: Record< string, unknown >;
				rendered?: { html?: unknown };
			};
			const data = record.data ?? {};
			const slug = String( data.slug ?? record.id ?? '' ).trim();
			if ( slug === '' ) {
				continue;
			}
			documents.push( {
				collection,
				slug,
				data,
				html: typeof record.rendered?.html === 'string' ? record.rendered.html : '',
			} );
		}
	}

	return writeDocuments( options.outDir, documents );
}

/** The shape one store entry has after the loader has built it. */
interface CapturedEntry {
	id: string;
	data: Record< string, unknown >;
	rendered?: { html?: string };
}

/**
 * Capture a LIVE WordPress into the same snapshot shape.
 *
 * `materializeContent` recovers content from a BUILT artifact, which needs the
 * `dist/server` data store — a `static` build has none, so a site that wants to
 * cut over while its CMS is still up had no way to freeze anything.
 *
 * This drives the PRODUCTION loader (`wp-loader.ts`) against a capture-only
 * loader context, so the snapshot carries exactly what a build would have
 * baked: pagination, `_fields` pruning, the versioned sanitizer allowlist, the
 * `wp/v2` SEO sidecar, featured-media resolution and the per-collection
 * exclusion list. Reusing the loader is the point — a second field-mapping
 * would drift from it, which is the defect class this repo keeps paying for.
 */
export async function materializeFromWordPress( options: {
	profile: SiteProfile;
	outDir: string;
	/** Basic auth for a private source — the build reads it from the environment. */
	auth?: { type: 'basic'; username: string; password: string };
	log?: ( line: string ) => void;
} ): Promise< MaterializedDocument[] > {
	const { createWpLoader } = await import( './wp-loader.ts' );
	const documents: Array< {
		collection: string;
		slug: string;
		data: Record< string, unknown >;
		html: string;
	} > = [];

	for ( const collection of options.profile.collections ) {
		const store = new Map< string, CapturedEntry >();
		const loader = createWpLoader( {
			site: options.profile,
			collection,
			...( options.auth ? { auth: options.auth } : {} ),
		} );

		// Astro's `LoaderContext` is far wider than the loader reads. Supplying
		// the slice it actually touches keeps this honest: `astro/loaders` is a
		// type-only import here, so nothing else can reach the store.
		await loader.load( {
			store: {
				clear: () => store.clear(),
				set: ( entry: { id: string } & CapturedEntry ) => {
					store.set( String( entry.id ), entry );
				},
				entries: () => [ ...store.entries() ],
				keys: () => [ ...store.keys() ],
				values: () => [ ...store.values() ],
				get: ( key: string ) => store.get( String( key ) ),
				has: ( key: string ) => store.has( String( key ) ),
				delete: ( key: string ) => store.delete( String( key ) ),
			},
			logger: {
				warn: ( line: string ) => options.log?.( `\u26a0 ${ line }` ),
				info: ( line: string ) => options.log?.( line ),
				error: ( line: string ) => options.log?.( `\u2717 ${ line }` ),
			},
			// The digest only decides whether a store entry is re-written; a
			// capture writes every entry once, so any stable value will do.
			generateDigest: () => 'capture',
		} as never );

		for ( const entry of store.values() ) {
			const slug = String( entry.data.slug ?? entry.id ).trim();
			if ( slug === '' ) {
				continue;
			}
			documents.push( {
				collection: collection.slug,
				slug,
				data: entry.data,
				html: typeof entry.rendered?.html === 'string' ? entry.rendered.html : '',
			} );
		}
	}

	return writeDocuments( options.outDir, documents );
}
