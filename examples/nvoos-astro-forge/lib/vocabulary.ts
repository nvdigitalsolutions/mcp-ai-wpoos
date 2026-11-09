// ---------------------------------------------------------------------------
// vocabulary.ts — the LEARNED widget/block vocabulary (Proposal 116).
//
// The classifier's seeded dictionaries go stale as Elementor/Gutenberg
// evolve (and vendor widget families like Auxin keep appearing). This module
// learns per-site instead of enumerating:
//
//   - Gutenberg: GET /wp-json/wp/v2/block-types — every block type
//     registered on the server with name/title/keywords/category (WP 5.5+).
//     Deterministic keyword rules map the metadata to classifier roles.
//   - Elementor + third-party widgets: every `elementor-widget-*` name
//     found on the site's rendered pages forms the site's widget vocabulary;
//     unknown names are REPORTED for the operator to pin as profile
//     overrides — and regardless of mapping they classify as `content` in
//     the classifier, so content is never dropped.
//
// Atomic editor output (Elementor 4.0) renders real semantic HTML (forms are
// real forms, grids are real grids) — the structure-first classifier handles
// it without name matching at all (116 §4).
// ---------------------------------------------------------------------------

import { fetchText } from './inventory.ts';
import { SEEDED_BLOCK_ROLES, SEEDED_WIDGET_ROLES, type BlockRole } from './semantic.ts';
import type { ForgeVocabulary, SiteProfile } from './types.ts';

export interface VocabularyOptions {
	fetch: typeof fetch;
	/** Rendered page HTML to harvest widget names from. */
	htmls?: string[];
	/** Bound on block-type REST pagination (default 5). */
	maxBlockTypePages?: number;
	timeoutMs?: number;
}

export interface BlockTypeRecord {
	name?: string;
	title?: string;
	keywords?: string[];
	category?: string;
}

/**
 * Deterministic keyword rules: block registry metadata → classifier role.
 * No match ⇒ undefined (the classifier's `content` fallback applies).
 */
export function roleFromBlockType( block: BlockTypeRecord ): BlockRole | undefined {
	const text = [ block.name ?? '', block.title ?? '', ...( block.keywords ?? [] ), block.category ?? '' ]
		.join( ' ' )
		.toLowerCase();
	if ( /\b(columns|column|group|cover|row|stack|grid|container|layout)\b/.test( text ) ) {
		return { kind: 'container' };
	}
	if ( /\b(heading|title)\b/.test( text ) ) {
		return { kind: 'title' };
	}
	if ( /\b(paragraph|text|verse|preformatted|list|details)\b/.test( text ) ) {
		return { kind: 'body' };
	}
	if ( /\bgallery\b/.test( text ) ) {
		return { kind: 'gallery' };
	}
	if ( /\b(image|media|logo|photo|avatar|figure)\b/.test( text ) ) {
		return { kind: 'media' };
	}
	if ( /\b(quote|pullquote)\b/.test( text ) ) {
		return { kind: 'quote' };
	}
	if ( /\b(buttons?|link|cta|call|navigation|menu)\b/.test( text ) ) {
		return { kind: 'links' };
	}
	return undefined;
}

/**
 * Declarative widget-name extracts — one row per builder (Proposal 137).
 *
 * The vocabulary pass used to read ONLY `elementor-widget-*`, so a WPBakery /
 * Divi / Bricks / Flatsome page reported "0 widgets" — indistinguishable from
 * "no widgets present", which hides real coverage gaps. Adding a builder is a
 * row here, the same shape as the band adapters in lib/bands.ts.
 *
 * `structural` lists the builder's own LAYOUT containers: they are not widgets,
 * so counting them would pollute `unknownWidgets` with row/column noise.
 */
const WIDGET_PATTERNS: { builder: string; re: RegExp; structural: Set< string > }[] = [
	{ builder: 'elementor', re: /elementor-widget-([\w-]+)/gi, structural: new Set() },
	{
		builder: 'gutenberg',
		re: /wp-block-([\w-]+)/gi,
		structural: new Set( [ 'group', 'columns', 'column', 'cover', 'media-text', 'spacer', 'separator' ] ),
	},
	{ builder: 'divi', re: /\bet_pb_(?!section\b|row\b|column\b)([\w]+)/gi, structural: new Set() },
	{
		builder: 'bricks',
		re: /\bbrxe-([\w-]+)/gi,
		structural: new Set( [ 'section', 'container', 'block', 'div' ] ),
	},
	{
		builder: 'wpbakery',
		re: /\b(?:wpb|vc)_([\w]+)/gi,
		structural: new Set( [ 'row', 'column', 'col', 'wrapper', 'inner', 'row-fluid' ] ),
	},
	{ builder: 'flatsome', re: /\bux-([\w-]+)/gi, structural: new Set( [ 'builder' ] ) },
];

/**
 * Utility/atomic class families that match a builder prefix but are not
 * widgets (Auxin/Brando ship `ux-library-inline-css`, `ux-synced-color-…`,
 * `ux-animate_when_almost_visible`, `ux-…__link`). Without this filter the
 * unknown bucket fills with noise and stops being actionable.
 */
const NON_WIDGET = /(?:^|[-_])(?:library|synced|responsive|inline|animate|css|rgb|__)/i;

/** Every widget/module name a page declares, across all supported builders
 *  (deduped, sorted). Layout containers and utility classes are excluded. */
export function collectWidgetNames( html: string ): string[] {
	const names = new Set< string >();
	for ( const { re, structural } of WIDGET_PATTERNS ) {
		re.lastIndex = 0; // the table is module-level: stateful /g regexes
		let match;
		while ( ( match = re.exec( html ) ) ) {
			const name = match[ 1 ].toLowerCase();
			if ( ! structural.has( name ) && ! NON_WIDGET.test( name ) ) {
				names.add( name );
			}
		}
	}
	return [ ...names ].sort();
}

/** The registered block types (bounded pagination; absent = empty — some
 *  hosts disable the endpoint). */
export async function fetchBlockTypes( siteUrl: string, options: VocabularyOptions ): Promise<BlockTypeRecord[]> {
	const base = siteUrl.replace( /\/+$/, '' );
	const records: BlockTypeRecord[] = [];
	const maxPages = options.maxBlockTypePages ?? 5;
	for ( let page = 1; page <= maxPages; page++ ) {
		const { status, text } = await fetchText(
			options.fetch,
			`${ base }/wp-json/wp/v2/block-types?_fields=name,title,keywords,category&per_page=100&page=${ page }`,
			options
		);
		if ( status !== 200 ) {
			break;
		}
		let rows: BlockTypeRecord[];
		try {
			rows = JSON.parse( text );
		} catch {
			break;
		}
		if ( ! Array.isArray( rows ) ) {
			break;
		}
		records.push( ...rows );
		if ( rows.length < 100 ) {
			break;
		}
	}
	return records;
}

/**
 * Learn the site's vocabulary: block roles from the registry, the widget
 * name set from the rendered pages, and the unknown-name reports.
 */
export async function learnVocabulary( profile: SiteProfile, options: VocabularyOptions ): Promise<ForgeVocabulary> {
	const blockTypes = await fetchBlockTypes( profile.url, options );

	const blockRoles: Record<string, string> = {};
	const unknownBlocks: string[] = [];
	for ( const block of blockTypes ) {
		const name = ( block.name ?? '' ).split( '/' ).pop()?.toLowerCase() ?? '';
		if ( ! name || SEEDED_BLOCK_ROLES[ name ] ) {
			continue; // Seeded entries are already in the defaults.
		}
		const role = roleFromBlockType( block );
		if ( role ) {
			blockRoles[ name ] = role.kind;
		} else {
			unknownBlocks.push( block.name ?? name );
		}
	}

	const widgetNames = new Set<string>();
	for ( const html of options.htmls ?? [] ) {
		for ( const name of collectWidgetNames( html ) ) {
			widgetNames.add( name );
		}
	}
	const unknownWidgets = [ ...widgetNames ]
		.filter( ( name ) => ! SEEDED_WIDGET_ROLES[ name ] )
		.sort();

	return {
		widgetRoles: {},
		blockRoles,
		unknownWidgets,
		unknownBlocks: unknownBlocks.sort(),
		analyzedAt: new Date().toISOString(),
	};
}
