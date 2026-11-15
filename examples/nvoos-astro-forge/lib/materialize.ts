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
	const results: MaterializedDocument[] = [];

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

			const dir = join( options.outDir, collection );
			await mkdir( dir, { recursive: true } );

			const lines = [ '---' ];
			for ( const field of FRONTMATTER_FIELDS ) {
				const value = data[ field ];
				if ( value === undefined || value === null || value === '' ) {
					continue;
				}
				lines.push( `${ field }: ${ yamlValue( value ) }` );
			}
			lines.push( '---', '' );
			const mdx = lines.join( '\n' );
			await writeFile( join( dir, `${ slug }.mdx` ), mdx, 'utf8' );

			const html = typeof record.rendered?.html === 'string' ? record.rendered.html : '';
			if ( html !== '' ) {
				await writeFile( join( dir, `${ slug }.html` ), html, 'utf8' );
			}
			results.push( { collection, slug, mdxBytes: mdx.length, htmlBytes: html.length } );
		}
	}

	return results;
}
