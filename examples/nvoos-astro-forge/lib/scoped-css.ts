// ---------------------------------------------------------------------------
// scoped-css.ts — read the band rules out of a BUILT page's <style> blocks.
//
// A band's padding arrives one of two ways. A plain-string padding goes inline
// on the band element (`style="padding:125px 0px"`). A device-scoped map
// (Proposal 114 §3.6) cannot go inline — one attribute cannot hold a media
// query — so DesignRenderer emits it as a scoped rule instead:
//
//   [data-section-id="059421c"]{padding:125px 0px 125px 0px}
//   @media (max-width:1025px){[data-section-id="059421c"]{padding:100px 0px}}
//
// Both the band-flow gate (design.ts) and the fact audit (fact-audit.ts) have
// to see that padding, and they must agree on what "the padding" is. This
// module is the single reader for that, so the two checks cannot drift.
// ---------------------------------------------------------------------------

/** Remove every `@media …{…}` block, preserving the unqueried rules. One
 *  nesting level is enough: the emitted scoped CSS holds a single rule per
 *  media block. */
export function stripAtMedia( css: string ): string {
	let out = '';
	let i = 0;
	for (;;) {
		const start = css.indexOf( '@media', i );
		if ( start === -1 ) {
			return out + css.slice( i );
		}
		out += css.slice( i, start );
		const open = css.indexOf( '{', start );
		if ( open === -1 ) {
			return out;
		}
		let depth = 0;
		let j = open;
		for ( ; j < css.length; j++ ) {
			if ( css[ j ] === '{' ) depth++;
			else if ( css[ j ] === '}' ) {
				depth--;
				if ( depth === 0 ) {
					j++;
					break;
				}
			}
		}
		i = j;
	}
}

/** The scoped stylesheet padding per band — the desktop (unqueried) value,
 *  which is what a device-scoped map compares on. */
export function scopedPaddingOf( html: string ): Map< string, string > {
	const scoped = new Map< string, string >();
	const styleBlocks = [ ...html.matchAll( /<style[^>]*>([\s\S]*?)<\/style>/gi ) ].map( ( match ) => match[ 1 ] ).join( '\n' );
	if ( ! styleBlocks ) {
		return scoped;
	}
	for ( const match of stripAtMedia( styleBlocks ).matchAll( /\[data-section-id="([^"]+)"\]\s*\{([^}]*)\}/g ) ) {
		const padding = /(?:^|;)\s*padding:\s*([^;]+)/i.exec( match[ 2 ] )?.[ 1 ]?.trim();
		if ( padding && ! scoped.has( match[ 1 ] ) ) {
			scoped.set( match[ 1 ], padding );
		}
	}
	return scoped;
}
