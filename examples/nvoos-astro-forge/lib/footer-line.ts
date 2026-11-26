// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// footer-line.ts — a footer LINE split into its text and its anchors.
//
// A builder publishes the © statement as ONE paragraph with only the credit
// anchored:
//
//   © 2026 VIRTUALLY SRI LANKA IS POWERED BY
//   <a class="gray-text" href="http://www.nvdigitalsolutions.com/">NV DIGITAL
//   SOLUTIONS.</a>
//
// `chrome.footerBlocks` keeps that line as one string (a footer line is a
// string, not a fragment tree), and `chrome.footerLinks` records the anchors it
// carries by their TEXT. This module is the join: it splits a line at every
// recorded anchor so the layout can rebuild the `<a>` in place instead of
// printing the credit as dead copy.
//
// Vendored into every generated project (`FORGE_LIB_FILES`) and free of any
// DOM/Playwright dependency — the renderer imports it directly.
// ---------------------------------------------------------------------------

/** An anchor a footer line carries (the `chrome.footerLinks` entry). */
export interface FooterLineLink {
	text: string;
	href: string;
}

/** One run of a footer line: plain text, or a link when `href` is set. */
export interface FooterLineSegment {
	text: string;
	href?: string;
}

/**
 * Split `line` at every recorded link whose text it CONTAINS.
 *
 * Longest match wins, so a line carrying both `Solutions.` and
 * `NV Digital Solutions.` anchors the longer phrase rather than leaving a
 * dangling `NV Digital`. A link whose text does not occur leaves the line
 * whole — the fact is a lookup, never a rewrite.
 */
export function footerLineSegments(
	line: string,
	links: FooterLineLink[] | undefined
): FooterLineSegment[] {
	const usable = ( links ?? [] )
		.filter( ( link ) => link.text && link.href && line.includes( link.text ) )
		.sort( ( a, b ) => b.text.length - a.text.length );
	if ( usable.length === 0 ) {
		return [ { text: line } ];
	}
	const segments: FooterLineSegment[] = [];
	let rest = line;
	while ( rest.length > 0 ) {
		const hit = usable.find( ( link ) => rest.includes( link.text ) );
		if ( ! hit ) {
			segments.push( { text: rest } );
			break;
		}
		const at = rest.indexOf( hit.text );
		if ( at > 0 ) {
			segments.push( { text: rest.slice( 0, at ) } );
		}
		segments.push( { text: hit.text, href: hit.href } );
		rest = rest.slice( at + hit.text.length );
	}
	return segments;
}
