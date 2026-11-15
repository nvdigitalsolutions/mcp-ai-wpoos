// ---------------------------------------------------------------------------
// anchor.ts — where a band's in-page anchor id lives.
//
// The wrapper normally carries the id (that is what `#services` scrolls to), but
// a builder frequently puts the anchor on an element INSIDE the band: Brando
// puts `id="wifi"` on the red HALF-COLUMN, so the source's `#wifi` is that panel
// and not the band around it. The harvested markup keeps the id verbatim, so
// emitting one on the wrapper too produces a DUPLICATE id — invalid HTML, and
// worse, `querySelector('#wifi')` then resolves to the wrapper: in-page links
// land on the band instead of the panel, and `verify --colors` measured the
// band's `#ededed` surface against the source's `#dd3333` panel (virtuallyjamaica,
// 2026-11-12).
//
// The source's placement wins: if the band's own markup already carries the id,
// the wrapper emits none.
// ---------------------------------------------------------------------------

const escapeForRegExp = ( value: string ): string => value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

/**
 * The id the band WRAPPER should carry, or `undefined` when the band's inner
 * markup already owns it.
 */
export const wrapperAnchor = (
	anchor: string | undefined,
	innerHtml: string | undefined
): string | undefined => {
	if ( ! anchor ) {
		return undefined;
	}
	if ( ! innerHtml ) {
		return anchor;
	}
	// Attribute-aware on purpose: a paragraph that WRITES `id="wifi"` in prose
	// must not be mistaken for the element that owns the anchor. The pattern is
	// anchored to a start tag, so it can never cross a `>` into text content.
	// Any quoting style, with whitespace around `=` — `id=wifi` is legal unquoted
	// HTML and survives sanitisation.
	const pattern = new RegExp( `<[a-z][^>]*?\\sid\\s*=\\s*["']?${ escapeForRegExp( anchor ) }(?=["'\\s/>])`, 'i' );
	return pattern.test( innerHtml ) ? undefined : anchor;
};
