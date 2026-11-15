// ---------------------------------------------------------------------------
// system-export.ts — package a forged site as a reusable design system
// (Proposal 136 §4.3).
//
// The deliverable shape is the one every design-system doc tool ships
// (Storybook / Zeroheight / Backlight / Supernova — the DTCG spec's own §3.5
// category): tokens, ONE scoped component stylesheet, a specimen page, and a
// conventions README.
//
// The hard part is `scopeCss`: Astro's component scoping is attribute-based
// (`[data-astro-cid-abc123]`), so a standalone stylesheet has to strip those
// attributes and prefix every selector with one root class. Only depth-0 commas
// split a selector list — `:is(a, b)` must stay intact — and at-rule bodies are
// handled per at-rule: container queries recurse, keyframes/font-face do not.
//
// Everything here is pure text transformation: no browser, no build, no
// network. Deterministic, so two exports of the same build are byte-identical.
// ---------------------------------------------------------------------------

/** At-rules whose bodies contain ordinary rules and must be recursed into. */
const RECURSIVE_AT = new Set( [ 'media', 'supports', 'container', 'layer', 'scope', 'starting-style' ] );

interface Block {
	/** The text before the opening brace (a selector or an at-rule prelude). */
	prelude: string;
	/** The block body, or undefined for a statement (`@import …;`). */
	body?: string;
}

/**
 * Split a stylesheet into top-level blocks. Comments and strings are skipped
 * defensively; braces inside parentheses (`:is(.a, .b)`) do not open a block.
 */
function parseBlocks( css: string ): Block[] {
	const blocks: Block[] = [];
	let prelude = '';
	let index = 0;
	let parens = 0;

	while ( index < css.length ) {
		const char = css[ index ];

		// Comments are dropped — they carry build noise, not design facts.
		if ( char === '/' && css[ index + 1 ] === '*' ) {
			const end = css.indexOf( '*/', index + 2 );
			index = end === -1 ? css.length : end + 2;
			continue;
		}

		// Strings are inert: copy them through, never inspect them.
		if ( char === '"' || char === "'" ) {
			const quote = char;
			let cursor = index + 1;
			while ( cursor < css.length && css[ cursor ] !== quote ) {
				cursor += css[ cursor ] === '\\' ? 2 : 1;
			}
			prelude += css.slice( index, Math.min( cursor + 1, css.length ) );
			index = cursor + 1;
			continue;
		}

		if ( char === '(' ) {
			parens++;
		} else if ( char === ')' ) {
			parens = Math.max( 0, parens - 1 );
		}

		if ( char === ';' && parens === 0 && ! prelude.includes( '{' ) ) {
			// An at-statement: `@import …;` / `@charset …;`
			if ( prelude.trim() ) {
				blocks.push( { prelude: `${ prelude.trim() };` } );
			}
			prelude = '';
			index++;
			continue;
		}

		if ( char === '{' && parens === 0 ) {
			// Find the matching close brace.
			let depth = 1;
			let cursor = index + 1;
			let localParens = 0;
			while ( cursor < css.length && depth > 0 ) {
				const inner = css[ cursor ];
				if ( inner === '"' || inner === "'" ) {
					const quote = inner;
					cursor++;
					while ( cursor < css.length && css[ cursor ] !== quote ) {
						cursor += css[ cursor ] === '\\' ? 2 : 1;
					}
				} else if ( inner === '/' && css[ cursor + 1 ] === '*' ) {
					const end = css.indexOf( '*/', cursor + 2 );
					cursor = end === -1 ? css.length : end + 2;
					continue;
				} else if ( inner === '(' ) {
					localParens++;
				} else if ( inner === ')' ) {
					localParens = Math.max( 0, localParens - 1 );
				} else if ( localParens === 0 && inner === '{' ) {
					depth++;
				} else if ( localParens === 0 && inner === '}' ) {
					depth--;
					if ( depth === 0 ) {
						break;
					}
				}
				cursor++;
			}
			blocks.push( { prelude: prelude.trim(), body: css.slice( index + 1, cursor ) } );
			prelude = '';
			index = cursor + 1;
			continue;
		}

		if ( char === '}' ) {
			// Stray close brace (a malformed sheet) — drop it.
			prelude = '';
			index++;
			continue;
		}

		prelude += char;
		index++;
	}

	if ( prelude.trim() ) {
		blocks.push( { prelude: prelude.trim() } );
	}
	return blocks;
}

/** Drop Astro's scoping attributes from a selector. */
function stripScoping( selector: string ): string {
	return selector.replace( /\[data-astro-cid-[^\]]*\]/g, '' ).replace( /\s{2,}/g, ' ' ).trim();
}

/** Split a selector list on depth-0 commas only. */
function splitSelectors( selector: string ): string[] {
	const parts: string[] = [];
	let current = '';
	let parens = 0;
	for ( const char of selector ) {
		if ( char === '(' ) {
			parens++;
		} else if ( char === ')' ) {
			parens = Math.max( 0, parens - 1 );
		}
		if ( char === ',' && parens === 0 ) {
			parts.push( current );
			current = '';
			continue;
		}
		current += char;
	}
	parts.push( current );
	return parts.map( ( part ) => part.trim() ).filter( Boolean );
}

function prefixSelector( selector: string, slug: string ): string {
	const cleaned = stripScoping( selector );
	if ( ! cleaned ) {
		return '';
	}
	return splitSelectors( cleaned )
		.map( ( part ) => {
			// `html`/`:root` become the root class itself so a standalone
			// consumer gets the tokens on its own wrapper.
			if ( part === ':root' || part === 'html' || part === 'html, body' || part === 'body' ) {
				return `.${ slug }`;
			}
			return `.${ slug } ${ part }`;
		} )
		.join( ', ' );
}

/**
 * Scope a built stylesheet under one root class.
 *
 * `@import` / `@charset` are hoisted verbatim (a prefixed import is invalid);
 * `@media` / `@supports` / `@container` / `@layer` recurse; `@keyframes` and
 * `@font-face` keep their bodies untouched — their inner `from`/`to`/`50%`
 * keys are not selectors and a prefix would break them.
 */
export function scopeCss( css: string, slug: string ): string {
	const out: string[] = [];

	for ( const block of parseBlocks( css ) ) {
		if ( block.body === undefined ) {
			// A statement: `@import …;` — hoisted as-is by the caller's order.
			out.push( block.prelude );
			continue;
		}
		const prelude = block.prelude.trim();
		if ( prelude.startsWith( '@' ) ) {
			const name = prelude.slice( 1 ).split( /[\s(]/, 1 )[ 0 ].toLowerCase();
			const body = RECURSIVE_AT.has( name ) ? scopeCss( block.body, slug ) : block.body;
			out.push( `${ prelude }{${ body }}` );
			continue;
		}
		const selector = prefixSelector( prelude, slug );
		if ( ! selector ) {
			continue;
		}
		// Declarations are preserved verbatim — only the selector is rewritten.
		out.push( `${ selector }{${ block.body.trim() }}` );
	}

	return out.join( '\n' );
}

// ---------------------------------------------------------------------------
// The conventions README
// ---------------------------------------------------------------------------

export interface SystemReadmeInput {
	/** The design system's display name. */
	name: string;
	/** Lowercase slug used for the root class, the CSS filename and tokens. */
	slug: string;
	/** The section kinds the site actually renders. */
	kinds: string[];
	/** The source site (origin only — never a client name in generated copy). */
	sourceUrl: string;
	/** The analysis date, ISO `YYYY-MM-DD` (a fact, passed in explicitly). */
	analyzedAt: string;
	/** Token names that are set (e.g. `accent`, `surface`, `ink`). */
	tokens: string[];
	/** One-token contrast fixes applied during the rebuild. */
	contrastFixes?: string[];
	/** Bands with a motion behaviour, as `id → behaviour`. */
	behaviors?: Array< { id: string; kind: string } >;
}

const KIND_RULES: Record< string, string > = {
	pricing: 'Plan cards; each price is the source\'s verbatim string. A feature matrix is the `comparison` kind.',
	stats: 'A `<dl>` of value/label pairs — never a row of `<div>`s.',
	steps: 'An `<ol>`; the order is the information. No HowTo markup (Google dropped that rich result in 2023).',
	team: '`<figure>`/`<figcaption>` per person; the image alt is the person\'s name.',
	marquee: 'A persistent pause control is part of the component (WCAG 2.2.2). Static under `prefers-reduced-motion`.',
	comparison: 'A real `<table>` with `th[scope]`; the wrapper scrolls, the semantics never change.',
	hero: 'One `<h1>` per page.',
	'feature-grid': 'Cards in a grid; the first card is not special.',
	'icon-list': 'A list with a leading marker per row.',
	testimonial: '`<blockquote>` + a cited author.',
	'logo-cloud': 'Decorative logos; alt text is the brand name.',
	cta: 'A heading plus links.',
	gallery: 'A grid of images with real alt text.',
	text: 'Prose. Long runs cap at the measure token.',
	form: 'Native form elements; the no-JS floor is an ordinary POST.',
};

/**
 * Compose the package README. Deterministic: the only date is the analysis
 * date the caller passes in, and no section is emitted when it has no facts.
 */
export function composeSystemReadme( input: SystemReadmeInput ): string {
	const kinds = input.kinds
		.slice()
		.sort()
		.map( ( kind ) => `- **${ kind }** — ${ KIND_RULES[ kind ] ?? 'Rendered from the site\'s own facts.' }` );

	const blocks = [
		`# ${ input.name } — design system\n`,
		`\`${ input.slug }\` · ${ input.kinds.length } section kinds · extracted from a rebuilt site\n`,
		[
			'## Files',
			'',
			'| File | What it is |',
			'| --- | --- |',
			'| `tokens.css` | The design tokens as CSS custom properties. |',
			'| `tokens.dtcg.json` | The same tokens in the DTCG 2025.10 shape (group → token → `$type`/`$value`). |',
			`| \`${ input.slug }.css\` | Tokens plus every component, scoped under \`.${ input.slug }\`. |`,
			'| `specimen.html` | Every component, live, with neutral fixture data. |',
			'',
		].join( '\n' ),
		[
			'## Use it',
			'',
			`1. Link \`${ input.slug }.css\`.`,
			`2. Wrap the part of the page that uses the system in \`<div class="${ input.slug }">\`.`,
			'3. Copy component markup from `specimen.html`.',
			'4. Change the look by editing the tokens, not the components.',
			'',
		].join( '\n' ),
		[
			'## Section kinds',
			'',
			'Each band in the rebuilt site carries `data-section="<kind>"`. The kinds present here:',
			'',
			...kinds,
			'',
			'A band with no recognised structure is `content`; it is never dropped.',
			'',
		].join( '\n' ),
		input.tokens.length > 0
			? [ '## Tokens', '', input.tokens.map( ( token ) => `- \`${ token }\`` ).join( '\n' ), '' ].join( '\n' )
			: '',
		input.contrastFixes && input.contrastFixes.length > 0
			? [
					'## Contrast fixes',
					'',
					'The source palette failed WCAG in the places below; one token was changed in each, chosen from the source\'s own unassigned pool.',
					'',
					...input.contrastFixes.map( ( fix ) => `- ${ fix }` ),
					'',
			  ].join( '\n' )
			: '',
		input.behaviors && input.behaviors.length > 0
			? [
					'## Motion contract',
					'',
					'Every band is complete at rest. Motion is CSS-only and additive, and `prefers-reduced-motion` returns the page to its at-rest state.',
					'',
					'| Band | Behaviour |',
					'| --- | --- |',
					...input.behaviors.map( ( behaviour ) => `| \`${ behaviour.id }\` | ${ behaviour.kind } |` ),
					'',
			  ].join( '\n' )
			: '',
		[
			'## Extracted from',
			'',
			`- Source: ${ input.sourceUrl } (analyzed ${ input.analyzedAt })`,
			'- This package contains design LANGUAGE only. No source markup, CSS or copy is reproduced.',
			'- Reuse of the source\'s brand assets is not granted by this export.',
			'- Token semantics follow the DTCG 2025.10 draft\'s stable subset; `$extends`/`$root` are not used.',
			'',
		].join( '\n' ),
	].filter( Boolean );

	return `${ blocks.join( '\n' ) }`.replace( /\n{3,}/g, '\n\n' ).trimEnd() + '\n';
}
