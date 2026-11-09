// ---------------------------------------------------------------------------
// llms.ts — the agent-facing index (Proposal 136 §4.4, velocity port).
//
// Same shape as the forge's composer (H1, blockquote, sections, Optional last)
// so one agent can read either site. Deliberately REDUCED: no HTML→markdown
// projection here, because velocity's content is Portable Text, not builder
// HTML — the `.md` variants arrive with a Portable Text projection, and until
// then this file links the canonical HTML pages rather than advertising URLs
// that do not exist yet.
//
// This is NOT an SEO feature: Google's changelog (2026-06-15) gives llms.txt no
// ranking effect. Its consumers are coding/chat agents and Lighthouse's
// agentic-browsing checks.
// ---------------------------------------------------------------------------

/** One link entry: the path is site-relative (`/products/1/thing/`). */
export interface LlmsEntry {
	path: string;
	title: string;
	note?: string;
}

export interface LlmsInput {
	name: string;
	summary: string;
	/** Absolute origin, no trailing slash. */
	origin: string;
	pages?: LlmsEntry[];
	products?: LlmsEntry[];
	posts?: LlmsEntry[];
	optional?: LlmsEntry[];
	/** Additional PRIMARY sections — e.g. an extra CPT collection. */
	extraSections?: Array<{ heading: string; entries: LlmsEntry[] }>;
	extra?: string;
	/**
	 * What the links point at. `html` (default) links the canonical pages;
	 * `markdown` links the `.md` variant. Only set `markdown` once the
	 * route exists — a link to a 404 is worse than a link to the page.
	 */
	variant?: 'html' | 'markdown';
}

/** The canonical URL of a site path. */
export function pageUrl( origin: string, path: string ): string {
	const base = origin.replace( /\/+$/, '' );
	const clean = path.replace( /\/+$/, '' );
	return `${ base }${ clean }/`;
}

/** The markdown variant of a site path (`/about/` → `<origin>/about/index.md`). */
export function markdownUrl( origin: string, path: string ): string {
	const base = origin.replace( /\/+$/, '' );
	const clean = path.replace( /\/+$/, '' );
	return `${ base }${ clean }/index.md`;
}

function entryLine( entry: LlmsEntry, origin: string, variant: 'html' | 'markdown' ): string | undefined {
	const title = entry.title.trim();
	if ( ! title ) {
		return undefined;
	}
	const note = ( entry.note ?? '' ).replace( /\s+/g, ' ' ).trim();
	const clipped = note.length > 120 ? `${ note.slice( 0, 117 ).replace( /\s+\S*$/, '' ) }…` : note;
	const href = variant === 'markdown' ? markdownUrl( origin, entry.path ) : pageUrl( origin, entry.path );
	return `- [${ title.replace( /[[\]]/g, '' ) }](${ href })${ clipped ? `: ${ clipped }` : '' }`;
}

function section( heading: string, entries: LlmsEntry[] | undefined, origin: string, variant: 'html' | 'markdown' ): string {
	const lines = ( entries ?? [] )
		.slice()
		.sort( ( a, b ) => a.path.localeCompare( b.path ) )
		.map( ( entry ) => entryLine( entry, origin, variant ) )
		.filter( ( line ): line is string => Boolean( line ) );
	if ( lines.length === 0 ) {
		return '';
	}
	return `## ${ heading }\n\n${ lines.join( '\n' ) }\n`;
}

/** Compose an `llms.txt` document. Section order is fixed by the v2 format. */
export function composeLlmsTxt( input: LlmsInput ): string {
	const variant = input.variant ?? 'html';
	const blocks = [
		`# ${ input.name.trim() }\n`,
		`> ${ input.summary.replace( /\s+/g, ' ' ).trim() }\n`,
		input.extra ? `${ input.extra.trim() }\n` : '',
		section( 'Pages', input.pages, input.origin, variant ),
		section( 'Posts', input.posts, input.origin, variant ),
		section( 'Products', input.products, input.origin, variant ),
		...( input.extraSections ?? [] ).map( ( extra ) => section( extra.heading, extra.entries, input.origin, variant ) ),
		section( 'Optional', input.optional, input.origin, variant ),
	].filter( Boolean );

	return `${ blocks.join( '\n' ) }`.replace( /\n{3,}/g, '\n\n' ).trimEnd() + '\n';
}

// ---------------------------------------------------------------------------
// Content projections for the `.md` variants
//
// Velocity's forward content is Portable Text (structured JSON), which maps to
// markdown 1:1 — no parsing hazards. Legacy WordPress HTML degrades to
// paragraph-structured text: it is trusted upstream content, but a regex-based
// HTML→markdown converter is not worth the failure modes for a fallback path.
// ---------------------------------------------------------------------------

interface PortableSpan {
	text?: string;
	marks?: string[];
}

interface PortableLinkDef {
	_key?: string;
	href?: string;
}

/** One Portable Text block's spans → inline markdown (strong/em/link only). */
function spansToMarkdown( children: PortableSpan[], markDefs: PortableLinkDef[] ): string {
	return children
		.map( ( span ) => {
			let text = span.text ?? '';
			for ( const mark of span.marks ?? [] ) {
				if ( mark === 'strong' ) {
					text = `**${ text }**`;
				} else if ( mark === 'em' ) {
					text = `*${ text }*`;
				} else {
					const definition = markDefs.find( ( def ) => def._key === mark );
					if ( definition?.href ) {
						text = `[${ text }](${ definition.href })`;
					}
				}
			}
			return text;
		} )
		.join( '' );
}

function gfmTable( columns: string[], rows: Array< { label: string; values: string[] } > ): string {
	const escape = ( cell: string ) => cell.replace( /\|/g, '\\|' );
	return [
		`| | ${ columns.map( escape ).join( ' | ' ) } |`,
		`| --- | ${ columns.map( () => '---' ).join( ' | ' ) } |`,
		...rows.map( ( row ) => `| **${ escape( row.label ) }** | ${ row.values.map( escape ).join( ' | ' ) } |` ),
	].join( '\n' );
}

/**
 * Project Portable Text to markdown. Every allowlisted block type has an
 * explicit projection — nothing is silently dropped except `promotion`, which
 * is a rendered banner whose copy lives in the promotions collection, not in
 * the block. `productGrid` keeps its ids (facts) rather than inventing names.
 */
export function markdownFromPortableText( items: unknown[] ): string {
	const out: string[] = [];

	for ( const raw of items ) {
		const item = raw as Record< string, unknown >;
		const type = String( item._type ?? '' );

		switch ( type ) {
		case 'block': {
			const style = String( item.style ?? 'normal' );
			const text = spansToMarkdown(
				( item.children as PortableSpan[] ) ?? [],
				( item.markDefs as PortableLinkDef[] ) ?? []
			).trim();
			if ( ! text ) {
				break;
			}
			out.push( style === 'h2' ? `## ${ text }` : style === 'h3' ? `### ${ text }` : text );
			break;
		}
		case 'hero': {
			if ( item.title ) {
				out.push( `## ${ String( item.title ) }` );
			}
			if ( item.lede ) {
				out.push( String( item.lede ) );
			}
			break;
		}
		case 'image': {
			if ( item.src ) {
				out.push( `![${ String( item.alt ?? '' ) }](${ String( item.src ) })` );
			}
			break;
		}
		case 'testimonial': {
			const author = item.author ? `\n\n— ${ String( item.author ) }` : '';
			out.push( `> ${ String( item.quote ?? '' ) }${ author }` );
			break;
		}
		case 'faq': {
			out.push( `**${ String( item.question ?? '' ) }**\n\n${ String( item.answer ?? '' ) }` );
			break;
		}
		case 'stat': {
			out.push( `- **${ String( item.value ?? '' ) }** — ${ String( item.label ?? '' ) }` );
			break;
		}
		case 'comparison': {
			const columns = ( item.columns as string[] ) ?? [];
			const rows = ( item.rows as Array< { label: string; values: string[] } > ) ?? [];
			if ( item.caption ) {
				out.push( `**${ String( item.caption ) }**` );
			}
			if ( columns.length > 0 && rows.length > 0 ) {
				out.push( gfmTable( columns, rows ) );
			}
			break;
		}
		case 'steps': {
			const steps = ( item.items as Array< { title: string; body?: string } > ) ?? [];
			out.push(
				steps
					.map( ( step, index ) => `${ index + 1 }. **${ step.title }**${ step.body ? ` — ${ step.body }` : '' }` )
					.join( '\n' )
			);
			break;
		}
		case 'team': {
			const people = ( item.people as Array< { name: string; role?: string } > ) ?? [];
			out.push( people.map( ( person ) => `- **${ person.name }**${ person.role ? ` — ${ person.role }` : '' }` ).join( '\n' ) );
			break;
		}
		case 'tabs': {
			const tabs = ( item.tabs as Array< { title: string; blocks: unknown[] } > ) ?? [];
			for ( const tab of tabs ) {
				out.push( `### ${ tab.title }` );
				out.push( markdownFromPortableText( tab.blocks ) );
			}
			break;
		}
		case 'productGrid': {
			const ids = ( item.ids as number[] ) ?? [];
			if ( ids.length > 0 ) {
				out.push( `**Featured products:** ${ ids.join( ', ' ) }` );
			}
			break;
		}
		case 'promotion':
			// A rendered banner — its copy lives in the promotions collection.
			break;
		default:
			// An unknown type cannot reach here (the schema rejects it); if one
			// ever does, say so rather than dropping it silently.
			out.push( `_[unrendered block: ${ type }]_` );
		}
	}

	return `${ out.filter( Boolean ).join( '\n\n' ).replace( /\n{3,}/g, '\n\n' ).trim() }\n`;
}

/**
 * Legacy WordPress HTML → paragraph-structured text. Structural markdown comes
 * from Portable Text; this path keeps paragraph and list breaks and strips the
 * markup, which is the honest ceiling for trusted-but-unstructured HTML.
 */
export function textFromHtml( html: string ): string {
	if ( ! html ) {
		return '';
	}
	const text = html
		.replace( /<(script|style)[\s\S]*?<\/\1>/gi, ' ' )
		.replace( /<br\s*\/?>/gi, '\n' )
		.replace( /<\/(p|div|li|h[1-6]|blockquote|tr)>/gi, '\n\n' )
		.replace( /<[^>]+>/g, '' )
		.replace( /&nbsp;/g, ' ' )
		.replace( /&amp;/g, '&' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&quot;/g, '"' )
		.replace( /&#0?39;/g, "'" )
		.replace( /[ \t]+/g, ' ' )
		.replace( / *\n */g, '\n' )
		.replace( /\n{3,}/g, '\n\n' );
	return `${ text.trim() }\n`;
}
