// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// element-css.ts — carry a builder's PER-ELEMENT declarations into the rebuild.
//
// Flatsome's UX Builder (and WPBakery, and Divi's shortcode blocks) write the
// layout of individual elements as `<style>` blocks that target the element by
// ID:
//
//   #gap-1131238735 { padding-top: 30px }
//   #col-1041533482 > .col-inner { margin: 180px 0 0 }
//   #text-box-1856221106 { width: 20% }
//   @media (min-width:550px) { #section_126321452 { padding-top: 100px } }
//
// The sanitizer drops `<style>` (it is raw CSS and cannot be trusted), so a
// rebuild loses every one of those facts and the template re-derives the layout
// from class conventions instead. That is why gaps collapsed, column padding
// vanished, a chevron rendered 1×1 (its wrapper's `width` lived in one of these
// rules) and text boxes came out narrower than the source.
//
// This module recovers them behind a strict gate:
//   • only `#id` selectors, and only ids from a builder's own element families
//     (chrome ids like `#logo`, `#main`, `#wrapper` are excluded — the rebuild
//     owns its chrome and must not have the source's header CSS applied to it);
//   • only an allowlist of layout/typography properties;
//   • no `url()`, `expression()`, `javascript:`, `@import`, `behavior`;
//   • `!important` stripped;
//   • every emitted rule is scoped under `.ds-section-wrap` so it can never
//     reach the header, footer, nav or any other band.
//
// Deterministic and pure — no network, no DOM.
// ---------------------------------------------------------------------------

import { DomHandler, Parser } from 'htmlparser2';

import type { ForgeElementCssRule } from './types.ts';

/** A safe HTML identifier (no spaces, quotes, or leading digit). */
const SAFE_ID = /^[a-z][a-z0-9_-]{0,40}$/i;

/**
 * Id families a page builder uses for CONTENT elements. Anything else is chrome
 * (`#logo`, `#main`, `#masthead`, `#wrapper`, …) and is deliberately ignored:
 * the rebuild renders its own header/footer from measured chrome facts.
 */
const ELEMENT_ID_PREFIXES = [
	'section_',
	'row-',
	'col-',
	'gap-',
	'banner-',
	'text-box-',
	'image_',
	'icon-box',
	'box-',
	'ux_',
	'slider-',
	'accordion-',
	'tab-',
];

/** Property → allowed? Layout, type and paint only. */
const ALLOWED_PROPERTIES = new Set( [
	'display',
	'position',
	'top',
	'right',
	'bottom',
	'left',
	'inset',
	'width',
	'height',
	'min-width',
	'min-height',
	'max-width',
	'max-height',
	'margin',
	'margin-top',
	'margin-right',
	'margin-bottom',
	'margin-left',
	'margin-inline',
	'margin-inline-start',
	'margin-inline-end',
	'margin-block',
	'margin-block-start',
	'margin-block-end',
	'padding',
	'padding-top',
	'padding-right',
	'padding-bottom',
	'padding-left',
	'padding-inline',
	'padding-inline-start',
	'padding-inline-end',
	'padding-block',
	'padding-block-start',
	'padding-block-end',
	'gap',
	'row-gap',
	'column-gap',
	'flex',
	'flex-basis',
	'flex-direction',
	'flex-grow',
	'flex-shrink',
	'flex-wrap',
	'align-items',
	'align-self',
	'align-content',
	'justify-content',
	'justify-items',
	'justify-self',
	'order',
	'grid-template-columns',
	'grid-template-rows',
	'grid-column',
	'grid-row',
	'overflow',
	'overflow-x',
	'overflow-y',
	'object-fit',
	'opacity',
	'z-index',
	'background',
	'background-color',
	'background-position',
	'background-size',
	'background-repeat',
	'color',
	'font-family',
	'font-size',
	'font-weight',
	'font-style',
	'line-height',
	'letter-spacing',
	'text-align',
	'text-transform',
	'text-decoration',
	'text-indent',
	'white-space',
	'vertical-align',
	'border',
	'border-radius',
	'border-color',
	'border-style',
	'border-width',
	'box-shadow',
	'transform',
	'transform-origin',
	'transition',
] );

/** Values that would open a fetch, script or import surface. */
const UNSAFE_VALUE = /url\s*\(|expression\s*\(|javascript:|@import|behavior\s*:/i;

/** Selector shape we accept: `#id` followed by class/combinator segments. */
const SAFE_SELECTOR = /^#[a-z][a-z0-9_-]{0,40}(?:[\s>+~]+(?:[.#][a-z0-9_-]+|\*))*$/i;

function allowedId( id: string ): boolean {
	return SAFE_ID.test( id ) && ELEMENT_ID_PREFIXES.some( ( prefix ) => id.toLowerCase().startsWith( prefix ) );
}

/** Clean a declaration block; returns '' when nothing survives. */
function sanitizeDeclarations( body: string ): string {
	const out: string[] = [];
	for ( const part of body.split( ';' ) ) {
		const colon = part.indexOf( ':' );
		if ( colon < 0 ) {
			continue;
		}
		const property = part.slice( 0, colon ).trim().toLowerCase();
		let value = part.slice( colon + 1 ).trim();
		if ( ! ALLOWED_PROPERTIES.has( property ) || ! value ) {
			continue;
		}
		if ( UNSAFE_VALUE.test( value ) ) {
			continue;
		}
		value = value.replace( /!important/gi, '' ).trim();
		// A layout value is a token list; anything long is a red flag.
		if ( ! value || value.length > 160 ) {
			continue;
		}
		out.push( `${ property }:${ value }` );
	}
	return out.join( ';' );
}

/** Keep the selectors whose leading id belongs to a content element family. */
function sanitizeSelectorList( list: string ): string {
	const kept: string[] = [];
	for ( const raw of list.split( ',' ) ) {
		const selector = raw.trim().replace( /\s+/g, ' ' );
		if ( ! SAFE_SELECTOR.test( selector ) ) {
			continue;
		}
		const id = selector.slice( 1 ).split( /[\s>+~]/ )[ 0 ];
		if ( allowedId( id ) ) {
			kept.push( selector );
		}
	}
	return kept.join( ',' );
}

/**
 * Walk a stylesheet body, honouring one level of `@media`. Other at-rules
 * (`@supports`, `@font-face`, `@keyframes`) are skipped: their inner text is not
 * a set of element rules.
 */
function walkRules( css: string, media: string | undefined, into: ForgeElementCssRule[] ): void {
	let i = 0;
	while ( i < css.length ) {
		const brace = css.indexOf( '{', i );
		if ( brace < 0 ) {
			return;
		}
		const prelude = css.slice( i, brace ).trim();
		// Find the matching close brace for this block.
		let depth = 1;
		let j = brace + 1;
		while ( j < css.length && depth > 0 ) {
			const ch = css[ j ];
			if ( ch === '{' ) {
				depth++;
			} else if ( ch === '}' ) {
				depth--;
			}
			j++;
		}
		const body = css.slice( brace + 1, j - 1 );

		if ( prelude.startsWith( '@' ) ) {
			if ( /^@media\b/i.test( prelude ) ) {
				walkRules( body, prelude.replace( /\s+/g, ' ' ), into );
			}
			// Every other at-rule is ignored deliberately.
		} else {
			const selector = sanitizeSelectorList( prelude );
			const declarations = sanitizeDeclarations( body );
			if ( selector && declarations ) {
				into.push( media ? { media, selector, declarations } : { selector, declarations } );
			}
		}
		i = j;
	}
}

/** The declarations a STRUCTURAL re-anchor may carry (see `extractElementCss`). */
const SIZE_PROPERTIES = new Set( [ 'width', 'max-width', 'min-width' ] );

interface StructuralIndex {
	byId: Map< string, string >;
}

interface HNode {
	type: string;
	name?: string;
	attribs?: Record< string, string >;
	children?: HNode[];
}

/** A class-chain selector; odd tokens fall back to `[class~=…]`. */
function classCompound( tag: string, tokens: string[] ): string {
	const parts = tokens.map( ( token ) =>
		/^[a-z0-9_-]+$/i.test( token ) ? `.${ token }` : `[class~='${ token.replace( /'/g, '' ) }']`
	);
	return `${ tag }${ parts.join( '' ) }`;
}

/**
 * id → a CLASS-anchored selector, but only for elements whose class set is
 * unique in the document: a shared compound would paint one element's rule onto
 * a sibling.
 */
function indexStructuralSelectors( html: string ): StructuralIndex {
	const handler = new DomHandler();
	const parser = new Parser( handler );
	parser.write( html );
	parser.end();
	const signature = new Map< string, number >();
	const byId = new Map< string, { tag: string; tokens: string[] } >();
	const walk = ( nodes: HNode[] ): void => {
		for ( const node of nodes ) {
			if ( node.type === 'tag' && node.name ) {
				const tokens = ( node.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
				if ( tokens.length > 0 ) {
					const key = `${ node.name }|${ [ ...tokens ].sort().join( ' ' ) }`;
					signature.set( key, ( signature.get( key ) ?? 0 ) + 1 );
					const id = node.attribs?.id;
					if ( id ) {
						byId.set( id, { tag: node.name, tokens } );
					}
				}
			}
			if ( node.children ) {
				walk( node.children );
			}
		}
	};
	walk( handler.dom as HNode[] );

	const unique = new Map< string, string >();
	for ( const [ id, { tag, tokens } ] of byId ) {
		const key = `${ tag }|${ [ ...tokens ].sort().join( ' ' ) }`;
		if ( signature.get( key ) === 1 ) {
			unique.set( id, classCompound( tag, tokens ) );
		}
	}
	return { byId: unique };
}

/** A SIZE-only rule re-anchored from its volatile id to the stable class list. */
function structuralFallback(
	rule: ForgeElementCssRule,
	index: StructuralIndex
): ForgeElementCssRule | undefined {
	// A selector LIST would need every id resolved; skip rather than half-apply.
	if ( rule.selector.includes( ',' ) ) {
		return undefined;
	}
	const leading = rule.selector.match( /^#([a-z][a-z0-9_-]{0,40})(?=$|[\s>+~])/i );
	if ( ! leading ) {
		return undefined;
	}
	const compound = index.byId.get( leading[ 1 ] );
	if ( ! compound ) {
		return undefined;
	}
	const properties = rule.declarations
		.split( ';' )
		.map( ( part ) => part.split( ':' )[ 0 ]?.trim().toLowerCase() );
	if ( properties.length === 0 || ! properties.every( ( property ) => SIZE_PROPERTIES.has( property ) ) ) {
		return undefined;
	}
	const selector = rule.selector.replace( /^#[a-z][a-z0-9_-]{0,40}/i, compound );
	return rule.media
		? { media: rule.media, selector, declarations: rule.declarations }
		: { selector, declarations: rule.declarations };
}

/**
 * Extract the recovery-eligible element rules from a page's inline `<style>`
 * blocks (document order preserved, duplicates dropped).
 *
 * Flatsome mints its element ids per RENDER: the id a `<style>` block names is
 * not the id the REST content carries, so an id-keyed rule can never bind in the
 * rebuild (krishandramikasavethe.world, 2026-11-19: the analyzed page said
 * `#text-box-4014392`, the built content `#text-box-389974702`, and a second
 * REST fetch minted a third set). The CLASS list is stable across renders, so
 * a SIZE rule is re-anchored to it. Only size properties are re-anchored: a
 * structural `padding`/`min-height` would re-apply rules the source's own sheet
 * neutralised — the hero banner's `padding-top:100%` loses to `is-full-height`
 * there and would blow the band up here — while a width lands on exactly the box
 * it sized in the source.
 */
export function extractElementCss( html: string ): ForgeElementCssRule[] {
	const styleBodies = [ ...html.matchAll( /<style[^>]*>([\s\S]*?)<\/style>/gi ) ]
		.map( ( match ) => match[ 1 ] )
		.join( '\n' );
	if ( ! styleBodies.trim() ) {
		return [];
	}
	const rules: ForgeElementCssRule[] = [];
	walkRules( styleBodies, undefined, rules );

	const index = indexStructuralSelectors( html );
	const expanded: ForgeElementCssRule[] = [];
	for ( const rule of rules ) {
		expanded.push( rule );
		const fallback = structuralFallback( rule, index );
		if ( fallback ) {
			expanded.push( fallback );
		}
	}

	const seen = new Set<string>();
	return expanded.filter( ( rule ) => {
		const key = `${ rule.media ?? '' }|${ rule.selector }|${ rule.declarations }`;
		if ( seen.has( key ) ) {
			return false;
		}
		seen.add( key );
		return true;
	} );
}

/**
 * Render the rules as one stylesheet, every selector scoped under a rebuilt
 * band so source CSS can never reach the chrome or another band.
 */
export function renderElementCss( rules: ForgeElementCssRule[] | undefined ): string {
	if ( ! rules || rules.length === 0 ) {
		return '/* No builder element rules were recovered from the source. */\n';
	}
	const scope = ( selector: string ): string =>
		selector
			.split( ',' )
			.map( ( part ) => `.ds-section-wrap ${ part.trim() }` )
			.join( ',' );

	const plain: string[] = [];
	const byMedia = new Map< string, string[] >();
	for ( const rule of rules ) {
		const line = `${ scope( rule.selector ) }{${ rule.declarations }}`;
		if ( ! rule.media ) {
			plain.push( line );
			continue;
		}
		const bucket = byMedia.get( rule.media ) ?? [];
		bucket.push( line );
		byMedia.set( rule.media, bucket );
	}
	const blocks = [ ...plain ];
	for ( const [ media, lines ] of byMedia ) {
		blocks.push( `${ media }{${ lines.join( '' ) }}` );
	}
	return `/* Recovered from the source builder's per-element <style> blocks. */\n${ blocks.join( '\n' ) }\n`;
}
