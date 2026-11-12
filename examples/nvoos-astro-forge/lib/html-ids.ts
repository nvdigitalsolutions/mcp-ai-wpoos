// ---------------------------------------------------------------------------
// html-ids.ts — id uniqueness in the BUILT page.
//
// The band wrapper emitted an anchor id the band's own markup already owned, so
// the page carried `id="wifi"` twice. `querySelector('#wifi')` then resolved to
// the wrapper — in-page links landed on the band instead of the red panel, and
// `verify --colors` compared the band's `#ededed` against the source's `#dd3333`
// panel, reporting a colour drift that did not exist (virtuallyjamaica,
// 2026-11-12). The wrapper no longer duplicates an id (`lib/anchor.ts`); this is
// the gate that proves it on the output, for every site.
//
// Deliberately NOT a full HTML validator: it reads start tags, ignores comments,
// and reports only what a browser would resolve ambiguously.
// ---------------------------------------------------------------------------

export interface DuplicateId {
	id: string;
	count: number;
}

/** Strip comments and script/style bodies so their text is never parsed as tags. */
function stripInert( html: string ): string {
	return html
		.replace( /<!--[\s\S]*?-->/g, '' )
		.replace( /<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, '' );
}

/** Every `id` attribute in the document, in order (duplicates kept). */
export function idsIn( html: string ): string[] {
	const source = stripInert( html );
	const ids: string[] = [];
	const TAG = /<[a-z][^>]*>/gi;
	for ( const tag of source.match( TAG ) ?? [] ) {
		const match = /\sid\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i.exec( tag );
		const value = ( match?.[ 1 ] ?? match?.[ 2 ] ?? match?.[ 3 ] ?? '' ).trim();
		if ( value ) {
			ids.push( value );
		}
	}
	return ids;
}

/** Ids that appear more than once, with their counts (order: first appearance). */
export function duplicateIds( html: string ): DuplicateId[] {
	const counts = new Map< string, number >();
	for ( const id of idsIn( html ) ) {
		counts.set( id, ( counts.get( id ) ?? 0 ) + 1 );
	}
	return [ ...counts.entries() ]
		.filter( ( [ , count ] ) => count > 1 )
		.map( ( [ id, count ] ) => ( { id, count } ) );
}

/**
 * In-page link targets: `#about`, `/#about`, `https://host/#about` and
 * `index.html#about`. A duplicate id is a correctness bug everywhere; it breaks
 * the document only when something LINKS to it.
 */
export function inPageTargets( html: string ): Set< string > {
	const source = stripInert( html );
	const targets = new Set< string >();
	const HREF = /\shref\s*=\s*(?:"([^"]*)"|'([^']*)')/gi;
	for ( const match of source.matchAll( HREF ) ) {
		const href = ( match[ 1 ] ?? match[ 2 ] ?? '' ).trim();
		const hash = href.indexOf( '#' );
		if ( hash === -1 ) {
			continue;
		}
		const before = href.slice( 0, hash );
		// A same-page target: a bare hash, or a path ending in `/` or `.html`.
		if ( before === '' || /\/$/.test( before ) || /\.html$/.test( before ) || /^[a-z]+:\/\/[^/]+\/$/i.test( before ) ) {
			const id = href.slice( hash + 1 ).trim();
			if ( id && id !== 'top' ) {
				targets.add( decodeURIComponent( id ) );
			}
		}
	}
	return targets;
}
