// ---------------------------------------------------------------------------
// content-scan.ts — the content audit scanner (096 §3.3 long-tail).
//
// Parses rendered WordPress HTML with htmlparser2 (a REAL parser — no regex
// HTML scanning) and reports the audit facts the content tools surface:
// headings, links, images (hosts, alt text), embeds, tables, and — via text
// nodes — any UNPROCESSED shortcode brackets that survived into the rendered
// output (a real shortcode leak signal; processed shortcodes leave only
// their rendered artifacts, which are reported too).
// ---------------------------------------------------------------------------

import { Parser } from 'htmlparser2';

/** Per-image facts the media audit aggregates. */
export interface ImageAudit {
	total: number;
	missingAlt: number;
	/** Same-host images (the Astro Image pipeline candidates). */
	sameHost: number;
	/** Hostname → image count (external CDNs etc.). */
	hosts: Record<string, number>;
}

export interface ContentScan {
	/** Content length in characters (rendered HTML, tags stripped). */
	length: number;
	headings: number;
	paragraphs: number;
	figures: number;
	blockquotes: number;
	tables: number;
	links: { internal: number; external: number };
	images: ImageAudit;
	/** Unique embed hosts (iframe/embed src). */
	embeds: string[];
	/** Rendered artifacts of WordPress shortcodes (gallery/caption/embed). */
	shortcodeArtifacts: string[];
	/** UNPROCESSED shortcode brackets found in text (a leak signal). */
	unprocessedShortcodes: string[];
}

function hostOf( value: string | undefined ): string | undefined {
	if ( ! value ) {
		return undefined;
	}
	try {
		const url = new URL( value );
		return url.hostname.toLowerCase();
	} catch {
		return undefined;
	}
}

function sameHost( host: string | undefined, baseHost: string ): boolean {
	return host === baseHost.toLowerCase();
}

function isInternalHref( href: string | undefined, baseHost: string ): boolean {
	if ( ! href ) {
		return true; // empty hrefs are internal by definition (anchors)
	}
	if ( href.startsWith( '#' ) || href.startsWith( '/' ) ) {
		return true;
	}
	const host = hostOf( href );
	return host === undefined || sameHost( host, baseHost );
}

const SHORTCODE_ARTIFACT_CLASSES = [ 'wp-caption', 'wp-block-gallery', 'wp-block-embed', 'wp-block-image', 'gallery' ];

/**
 * Scan one rendered HTML document. `baseHost` is the WordPress site host
 * (images on it are the same-host / Astro Image candidates).
 */
export function scanHtml( html: string, baseHost: string ): ContentScan {
	const scan: ContentScan = {
		length: 0,
		headings: 0,
		paragraphs: 0,
		figures: 0,
		blockquotes: 0,
		tables: 0,
		links: { internal: 0, external: 0 },
		images: { total: 0, missingAlt: 0, sameHost: 0, hosts: {} },
		embeds: [],
		shortcodeArtifacts: [],
		unprocessedShortcodes: [],
	};
	const embeds = new Set<string>();
	const artifacts = new Set<string>();
	const unprocessed = new Set<string>();
	const base = baseHost.toLowerCase();

	const parser = new Parser(
		{
			onopentag( name, attribs ) {
				const classes = ( attribs.class ?? '' ).split( /\s+/ ).filter( Boolean );
				switch ( name ) {
					case 'h1':
					case 'h2':
					case 'h3':
					case 'h4':
					case 'h5':
					case 'h6':
						scan.headings++;
						break;
					case 'p':
						scan.paragraphs++;
						break;
					case 'figure':
						scan.figures++;
						break;
					case 'blockquote':
						scan.blockquotes++;
						break;
					case 'table':
						scan.tables++;
						break;
					case 'a': {
						const internal = isInternalHref( attribs.href, base );
						if ( internal ) {
							scan.links.internal++;
						} else {
							scan.links.external++;
						}
						break;
					}
					case 'img': {
						scan.images.total++;
						if ( ! attribs.alt ) {
							scan.images.missingAlt++;
						}
						const host = hostOf( attribs.src ?? attribs[ 'data-src' ] );
						if ( host ) {
							scan.images.hosts[ host ] = ( scan.images.hosts[ host ] ?? 0 ) + 1;
							if ( host === base ) {
								scan.images.sameHost++;
							}
						}
						break;
					}
					case 'iframe':
					case 'embed': {
						const host = hostOf( attribs.src );
						if ( host ) {
							embeds.add( host );
						}
						break;
					}
					default:
						break;
				}
				for ( const className of classes ) {
					if ( SHORTCODE_ARTIFACT_CLASSES.some( ( artifact ) => className.includes( artifact ) ) ) {
						artifacts.add( className );
					}
				}
			},
			ontext( text ) {
				scan.length += text.length;
				// Unprocessed shortcode brackets in rendered text.
				for ( const match of text.matchAll( /\[([a-zA-Z][a-zA-Z0-9_-]*)[^\]]*\]/g ) ) {
					unprocessed.add( match[ 1 ].toLowerCase() );
				}
			},
		},
		{ decodeEntities: true, lowerCaseTags: true, lowerCaseAttributeNames: true }
	);
	parser.write( html );
	parser.end();

	scan.embeds = [ ...embeds ].sort();
	scan.shortcodeArtifacts = [ ...artifacts ].sort();
	scan.unprocessedShortcodes = [ ...unprocessed ].sort();
	return scan;
}
