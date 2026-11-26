// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// admin-chrome.ts — remove the CMS's ADMIN chrome from a rendered page.
//
// A gated source is analysed with a logged-in session (Proposal 152), and a
// logged-in WordPress response is not the page a visitor gets: it prepends the
// admin toolbar (`#wpadminbar`) and the plugin strings attached to it. Those
// are admin-only UI, and every consumer that reads the page as DESIGN or
// CONTENT must not see them:
//
//   - the gate probe matched WooCommerce's `…badge-store-coming-soon` and
//     Elementor's `wp-admin-bar-elementor-maintenance-on`, so a site that had
//     just been unlocked read as gated (eca.directory, 2026-11-23);
//   - the chrome pass read the WPCode "Header (0)" admin link as the site's
//     navigation and its green as the header ink;
//   - the text baseline counted "Howdy, …" as page copy.
//
// Balanced by `<div>` nesting rather than a non-greedy regex: the toolbar
// contains nested divs, and cutting at the first `</div>` leaves half of it.
// ---------------------------------------------------------------------------

const ADMIN_BAR_OPEN = /<div\b[^>]*\bid\s*=\s*["']wpadminbar["'][^>]*>/i;

/**
 * Remove the WordPress admin toolbar element from rendered HTML.
 *
 * A page without the toolbar (every anonymous response) is returned unchanged.
 */
export function stripAdminChrome( html: string ): string {
	const open = ADMIN_BAR_OPEN.exec( html );
	if ( ! open ) {
		return html;
	}
	const start = open.index;
	const tag = /<(\/?)div\b/gi;
	tag.lastIndex = start + open[ 0 ].length;
	let depth = 1;
	let end = -1;
	let match: RegExpExecArray | null;
	while ( ( match = tag.exec( html ) ) ) {
		depth += match[ 1 ] === '/' ? -1 : 1;
		if ( depth === 0 ) {
			end = html.indexOf( '>', tag.lastIndex );
			break;
		}
	}
	if ( end === -1 ) {
		return html;
	}
	return html.slice( 0, start ) + html.slice( end + 1 );
}

/**
 * The document BODY — the page, without the head's asset references.
 *
 * A head `<link>` is not page content: WooCommerce enqueues
 * `coming-soon.css` for its admin badge, and a stylesheet's filename must not
 * decide whether a site is gated. The opening tag is kept — a gate template's
 * marker IS its body class.
 */
export function pageMarkup( html: string ): string {
	const open = /<body\b[^>]*>/i.exec( html );
	if ( ! open ) {
		return html;
	}
	const start = open.index;
	const close = html.toLowerCase().lastIndexOf( '</body>' );
	return close > start ? html.slice( start, close ) : html.slice( start );
}

/** The page as a visitor (or the forge) reads it: body only, no admin toolbar. */
export function visitorMarkup( html: string ): string {
	return stripAdminChrome( pageMarkup( html ) );
}
