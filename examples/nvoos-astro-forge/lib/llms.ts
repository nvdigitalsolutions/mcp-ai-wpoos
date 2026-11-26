// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// llms.ts — the deterministic agent surfaces (Proposal 136 §4.4).
//
// `llms.txt` v2 (llmstxt.org, modified 2026-08-10) is a small markdown file at
// a path that describes the pages under it: an H1, a blockquote summary, then
// sections of links to LLM-friendly content, with an optional `## Optional`
// tail. It is deliberately NOT an SEO feature — Google's changelog (2026-06-15)
// states it has no ranking effect — its consumers are agents, and Chrome's
// Lighthouse audits for it under its agentic-browsing checks.
//
// Everything here is derived from facts already in the build (titles, paths,
// descriptions). No model writes any of it, and nothing carries a timestamp:
// two builds of the same content must produce byte-identical output.
// ---------------------------------------------------------------------------

import { DomHandler, Parser } from 'htmlparser2';

/** One link entry: the path is site-relative (`/about/`). */
export interface LlmsEntry {
	path: string;
	title: string;
	note?: string;
}

export interface LlmsInput {
	/** The site name (the H1 — the only required section). */
	name: string;
	/** One-paragraph summary (the blockquote). */
	summary: string;
	/** Absolute origin, no trailing slash. */
	origin: string;
	pages?: LlmsEntry[];
	products?: LlmsEntry[];
	posts?: LlmsEntry[];
	/** Additional PRIMARY sections — e.g. an extra CPT collection. */
	extraSections?: Array<{ heading: string; entries: LlmsEntry[] }>;
	/** Secondary links an agent may skip under a short context budget. */
	optional?: LlmsEntry[];
	/** Free-form prose between the summary and the first section. */
	extra?: string;
}

/** The markdown variant of a site path (`/about/` → `<origin>/about/index.md`). */
export function markdownUrl( origin: string, path: string ): string {
	const base = origin.replace( /\/+$/, '' );
	const clean = path.replace( /\/+$/, '' );
	return `${ base }${ clean }/index.md`;
}

/** One markdown list line. Notes are single-line, word-bounded. */
function entryLine( entry: LlmsEntry, origin: string ): string | undefined {
	const title = entry.title.trim();
	if ( ! title ) {
		return undefined;
	}
	const note = ( entry.note ?? '' ).replace( /\s+/g, ' ' ).trim();
	const clipped = note.length > 120 ? `${ note.slice( 0, 117 ).replace( /\s+\S*$/, '' ) }…` : note;
	return `- [${ title.replace( /[[\]]/g, '' ) }](${ markdownUrl( origin, entry.path ) })${ clipped ? `: ${ clipped }` : '' }`;
}

/** A section of entries, or '' when every entry is unusable. */
function section( heading: string, entries: LlmsEntry[] | undefined, origin: string ): string {
	const lines = ( entries ?? [] )
		.slice()
		.sort( ( a, b ) => a.path.localeCompare( b.path ) )
		.map( ( entry ) => entryLine( entry, origin ) )
		.filter( ( line ): line is string => Boolean( line ) );
	if ( lines.length === 0 ) {
		return '';
	}
	return `## ${ heading }\n\n${ lines.join( '\n' ) }\n`;
}

/**
 * Compose an `llms.txt` document in the v2 shape. Section order is fixed
 * (required for the format to be parseable), and within a section entries are
 * sorted by path so the file is stable across builds.
 */
export function composeLlmsTxt( input: LlmsInput ): string {
	const blocks = [
		`# ${ input.name.trim() }\n`,
		`> ${ input.summary.replace( /\s+/g, ' ' ).trim() }\n`,
		input.extra ? `${ input.extra.trim() }\n` : '',
		section( 'Pages', input.pages, input.origin ),
		section( 'Posts', input.posts, input.origin ),
		section( 'Products', input.products, input.origin ),
		...( input.extraSections ?? [] ).map( ( extra ) => section( extra.heading, extra.entries, input.origin ) ),
		section( 'Optional', input.optional, input.origin ),
	].filter( Boolean );

	return `${ blocks.join( '\n' ) }`.replace( /\n{3,}/g, '\n\n' ).trimEnd() + '\n';
}

// ---------------------------------------------------------------------------
// HTML → markdown (a narrow, own projection — never a converter dependency)
// ---------------------------------------------------------------------------

interface HNode {
	type: string;
	name?: string;
	attribs?: Record< string, string >;
	children?: HNode[] | null;
	data?: string;
}

function childrenOf( node: HNode ): HNode[] {
	return node.children ? Array.from( node.children ) : [];
}

const SKIP = new Set( [ 'script', 'style', 'noscript', 'template', 'svg' ] );

/** Plain text of a subtree (scripts/styles dropped). */
function plainText( node: HNode ): string {
	if ( node.type === 'text' ) {
		return node.data ?? '';
	}
	if ( node.name && SKIP.has( node.name ) ) {
		return '';
	}
	return childrenOf( node ).map( plainText ).join( '' );
}

/** Inline markdown: text, links, emphasis, code, images, breaks. */
function inlineMarkdown( node: HNode ): string {
	if ( node.type === 'text' ) {
		return ( node.data ?? '' ).replace( /\s+/g, ' ' );
	}
	if ( node.name && SKIP.has( node.name ) ) {
		return '';
	}
	const inner = () => childrenOf( node ).map( inlineMarkdown ).join( '' );
	switch ( node.name ) {
		case 'br':
			return '\n';
		case 'strong':
		case 'b':
			return `**${ inner().trim() }**`;
		case 'em':
		case 'i':
			return `*${ inner().trim() }*`;
		case 'code':
			return `\`${ plainText( node ).trim() }\``;
		case 'a': {
			const href = node.attribs?.href ?? '';
			const text = inner().trim() || href;
			return href ? `[${ text }](${ href })` : text;
		}
		case 'img': {
			const src = node.attribs?.src ?? '';
			const alt = ( node.attribs?.alt ?? '' ).replace( /\s+/g, ' ' ).trim();
			return src ? `![${ alt }](${ src })` : '';
		}
		default:
			return inner();
	}
}

function cellsOf( row: HNode ): string[] {
	return childrenOf( row )
		.filter( ( child ) => child.name === 'th' || child.name === 'td' )
		.map( ( cell ) => inlineMarkdown( cell ).replace( /\s+/g, ' ' ).replace( /\|/g, '\\|' ).trim() );
}

function tableMarkdown( table: HNode ): string {
	const rows = ( function walk( node: HNode ): HNode[] {
		return childrenOf( node ).flatMap( ( child ) => ( child.name === 'tr' ? [ child ] : walk( child ) ) );
	} )( table );
	if ( rows.length === 0 ) {
		return '';
	}
	const header = cellsOf( rows[ 0 ] );
	if ( header.length === 0 ) {
		return '';
	}
	const lines = [
		`| ${ header.join( ' | ' ) } |`,
		`| ${ header.map( () => '---' ).join( ' | ' ) } |`,
		...rows.slice( 1 ).map( ( row ) => `| ${ cellsOf( row ).join( ' | ' ) } |` ),
	];
	return lines.join( '\n' );
}

/** Block markdown for a node list; `indent` carries list depth. */
function blockMarkdown( nodes: HNode[], indent = '' ): string {
	const out: string[] = [];
	for ( const node of nodes ) {
		if ( node.type === 'text' ) {
			const text = ( node.data ?? '' ).replace( /\s+/g, ' ' ).trim();
			if ( text ) {
				out.push( text );
			}
			continue;
		}
		if ( node.name && SKIP.has( node.name ) ) {
			continue;
		}
		switch ( node.name ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6': {
				const level = Number( node.name.slice( 1 ) );
				const text = inlineMarkdown( node ).replace( /\s+/g, ' ' ).trim();
				if ( text ) {
					// Clamp to ### — deeper headings add noise to an agent's view.
					out.push( `${ '#'.repeat( Math.min( level, 3 ) ) } ${ text }` );
				}
				break;
			}
			case 'p': {
				const text = inlineMarkdown( node ).replace( /\s+/g, ' ' ).trim();
				if ( text ) {
					out.push( text );
				}
				break;
			}
			case 'ul':
			case 'ol': {
				const items = childrenOf( node ).filter( ( child ) => child.name === 'li' );
				items.forEach( ( item, index ) => {
					const bullet = node.name === 'ol' ? `${ index + 1 }.` : '-';
					const text = blockMarkdown( childrenOf( item ), `${ indent }  ` )
						.split( '\n' )
						.filter( Boolean )
						.join( `\n${ indent }  ` );
					out.push( text ? `${ indent }${ bullet } ${ text }` : `${ indent }${ bullet }` );
				} );
				break;
			}
			case 'blockquote': {
				const text = blockMarkdown( childrenOf( node ) )
					.split( '\n' )
					.filter( Boolean )
					.map( ( line ) => `> ${ line }` )
					.join( '\n' );
				if ( text ) {
					out.push( text );
				}
				break;
			}
			case 'table': {
				const table = tableMarkdown( node );
				if ( table ) {
					out.push( table );
				}
				break;
			}
			case 'pre': {
				const code = plainText( node ).replace( /\n+$/, '' );
				if ( code.trim() ) {
					out.push( `\`\`\`\n${ code }\n\`\`\`` );
				}
				break;
			}
			case 'hr':
				out.push( '---' );
				break;
			case 'img': {
				// A block-level image (not inside a paragraph) is still a picture.
				const image = inlineMarkdown( node );
				if ( image ) {
					out.push( image );
				}
				break;
			}
			case 'details': {
				// A native accordion reads as a question/answer pair.
				const summary = childrenOf( node ).find( ( child ) => child.name === 'summary' );
				const body = childrenOf( node ).filter( ( child ) => child.name !== 'summary' );
				if ( summary ) {
					out.push( `**${ inlineMarkdown( summary ).trim() }**` );
				}
				const text = blockMarkdown( body );
				if ( text ) {
					out.push( text );
				}
				break;
			}
			default: {
				const text = blockMarkdown( childrenOf( node ), indent );
				if ( text ) {
					out.push( text );
				}
			}
		}
	}
	return out.join( '\n\n' );
}

/**
 * Project sanitized HTML to markdown. Deliberately lossy: the HTML stays
 * canonical (`rel=canonical` still points at it) and this is the version an
 * agent reads when it wants the content, not the layout.
 */
export function markdownFromHtml( html: string ): string {
	if ( ! html.trim() ) {
		return '';
	}
	const handler = new DomHandler();
	const parser = new Parser( handler, { decodeEntities: true, lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( html );
	parser.end();
	const root = handler.root as unknown as HNode;
	const body = blockMarkdown( childrenOf( root ) );
	return body ? `${ body.replace( /\n{3,}/g, '\n\n' ).trim() }\n` : '';
}

// ---------------------------------------------------------------------------
// Source → markdown (local content, 2026-11-15)
// ---------------------------------------------------------------------------

/**
 * Project a LOCAL content source (`.md` / `.mdx`) to markdown.
 *
 * A glob-loaded entry's `body` is the SOURCE, not output: MDX adds ESM
 * `import`/`export` statements and JSX tags that must never reach an agent, and
 * a deferred body can still carry its frontmatter fence. The prose is already
 * markdown, so this only removes what is not.
 *
 * Deliberately lossy, exactly like `markdownFromHtml()`: a component that takes
 * its content as props (`<FaqAccordion items={…} />`) contributes nothing,
 * because that content never exists as markdown. The HTML page stays canonical
 * and carries it.
 */
export function markdownFromSource( source: string ): string {
	if ( ! source.trim() ) {
		return '';
	}
	const withoutFrontmatter = source.replace( /^\uFEFF?\s*---[ \t]*\r?\n[\s\S]*?\r?\n---[ \t]*(?:\r?\n|$)/, '' );
	const withoutComments = withoutFrontmatter.replace( /<!--[\s\S]*?-->/g, '' );

	// ESM statements: drop the line, and keep dropping while a multi-line
	// statement is open (`import {` … `} from './x';`). A statement that ends on
	// its own line closes immediately, so prose is never swallowed.
	const kept: string[] = [];
	let openStatement = false;
	for ( const line of withoutComments.split( /\r?\n/ ) ) {
		if ( openStatement ) {
			openStatement = ! /;[ \t]*$/.test( line );
			continue;
		}
		if ( /^[ \t]*(?:import|export)[ \t]/.test( line ) ) {
			openStatement = ! /;[ \t]*$/.test( line );
			continue;
		}
		kept.push( line );
	}

	// JSX/HTML tags go, their text children stay. Requiring a tag NAME keeps an
	// autolink (`<https://example.com>`) intact.
	const text = kept
		.join( '\n' )
		.replace( /<\/?[A-Za-z][A-Za-z0-9.-]*(?:[ \t][^>]*)?\/?>/g, '' )
		// A multi-line self-closing component (`<Tabs` … `/>`). Anchored at the
		// line start and bounded by `/>`, so a stray `<` in prose cannot swallow
		// the rest of the document.
		.replace( /^[ \t]*<[A-Za-z][A-Za-z0-9.-]*(?:[ \t]|[\r\n])[\s\S]*?\/>[ \t]*$/gm, '' );
	const body = text.replace( /\n{3,}/g, '\n\n' ).trim();
	return body ? `${ body }\n` : '';
}
