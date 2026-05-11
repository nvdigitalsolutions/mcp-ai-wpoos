/**
 * FlexSearch adapter for client-side full-text search.
 *
 * When the user types into the search box the adapter first queries the REST
 * API.  If the REST call fails (e.g. offline) it falls back to the in-memory
 * FlexSearch index built from the manifest.
 *
 * @since 1.0.0
 */

import type { ManifestEntry } from '../api/manifest-client';

// ---------------------------------------------------------------------------
// FlexSearch dynamic import wrapper
// ---------------------------------------------------------------------------

// We import FlexSearch lazily to keep it out of the critical path.
// The `Document` export is the record-store variant that lets us index
// and retrieve typed objects.

let _indexReady: Promise<void> | null = null;
// FlexSearch's `Document<T extends DocumentData>` generic constraint conflicts
// with our typed ManifestEntry (the latter intentionally has no string-index
// signature). Storing the runtime instance as `unknown` and narrowing at each
// call site keeps the public ManifestEntry shape strict.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let _index: any = null;

async function ensureIndex( entries: ManifestEntry[] ): Promise<void> {
	if ( _indexReady ) {
		return _indexReady;
	}

	_indexReady = ( async () => {
		const FlexSearch = await import( 'flexsearch' );

		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const Document = ( FlexSearch as any ).Document ?? ( FlexSearch as any ).default?.Document;

		_index = new Document( {
			document: {
				id: 'slug',
				index: [
					{ field: 'title', tokenize: 'forward', resolution: 9 },
					{ field: 'description', tokenize: 'forward', resolution: 5 },
					{ field: 'tags', tokenize: 'forward', resolution: 3 },
					{ field: 'plugin_name', tokenize: 'forward', resolution: 2 },
				],
				store: [ 'slug', 'title', 'description', 'plugin_name', 'tags' ],
			},
		} );

		for ( const entry of entries ) {
			if ( ! _index ) {
				break;
			}
			await _index.addAsync( entry.slug, entry );
		}
	} )();

	return _indexReady;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Result shape returned by the client-side search adapter.
 */
export interface LocalSearchResult {
	slug: string;
	title: string;
	excerpt: string;
	score: number;
}

/**
 * Warm the FlexSearch index with all manifest entries.
 * Call this when the manifest is first loaded.
 */
export async function indexManifest( entries: ManifestEntry[] ): Promise<void> {
	// Reset if called again with fresh entries (e.g. after a rebuild).
	_indexReady = null;
	_index = null;
	await ensureIndex( entries );
}

/**
 * Search the local FlexSearch index.
 *
 * Returns an empty array if the index is not yet ready.
 */
export async function localSearch( query: string, limit = 10 ): Promise<LocalSearchResult[]> {
	if ( ! query.trim() || ! _index ) {
		return [];
	}

	const raw = await _index.searchAsync( query, { limit, enrich: true } );

	// `raw` is an array of field-result objects: [{ field, result: [{id, doc}] }]
	const seen = new Set<string>();
	const results: LocalSearchResult[] = [];

	for ( const fieldResult of raw ) {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		for ( const item of ( fieldResult as any ).result ?? [] ) {
			const doc = item.doc as ManifestEntry;
			const slug = doc?.slug ?? item.id;

			if ( seen.has( slug ) ) {
				continue;
			}
			seen.add( slug );

			// Use field index as a rough score proxy (first fields are higher resolution).
			const fieldIndex = raw.indexOf( fieldResult );
			const score = Math.max( 1, 10 - fieldIndex * 2 );

			results.push( {
				slug,
				title: doc?.title ?? slug,
				excerpt: doc?.description ?? '',
				score,
			} );
		}
	}

	// Sort by score descending.
	results.sort( ( a, b ) => b.score - a.score );
	return results.slice( 0, limit );
}

/**
 * Discard the current index (e.g. after a server-side rebuild).
 */
export function resetIndex(): void {
	_indexReady = null;
	_index = null;
}
