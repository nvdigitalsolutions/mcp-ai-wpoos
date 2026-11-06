// ---------------------------------------------------------------------------
// semantic.ts — the builder-structure classifier (Proposal 114 P2).
//
// Turns sanitized WordPress/page-builder HTML into a semantic content model:
// typed sections (hero, feature-grid, testimonial, …) with text/media slots,
// classified from stable structural signatures — Elementor widget classes,
// `wp-block-*` classes, and WPBakery `vc_*` wrappers. Unrecognized content
// falls back to a single `text` section carrying the sanitized substring
// (the .post-body treatment); a recognized-but-empty section is dropped.
// Deterministic, pure, vendored into generated projects under src/forge/.
//
// XSS position: this module only SPLITS an already-sanitized HTML string and
// copies substrings/slot text out of it. It never re-serializes nodes and
// never introduces markup of its own — the renderer emits extracted text via
// Astro interpolation and substrings via set:html.
// ---------------------------------------------------------------------------

import { DomHandler, Parser } from 'htmlparser2';

export type SectionKind =
	| 'hero'
	| 'feature-grid'
	| 'icon-list'
	| 'testimonial'
	| 'logo-cloud'
	| 'cta'
	| 'gallery'
	| 'text'
	| 'form'
	| 'raw';

export interface MediaRef {
	src?: string;
	alt?: string;
}

export interface LinkRef {
	text?: string;
	href?: string;
}

export interface SectionItem {
	title?: string;
	body?: string;
	media?: MediaRef;
	links?: LinkRef[];
	/** The item renders with an icon marker (icon-list family). */
	icon?: boolean;
	/** The source icon glyph's font classes, marker-ready — e.g.
	 * `auxicon auxicon-badge`, `fa fas fa-check`, `dashicons dashicons-cart`.
	 * The rebuild's marker carries them so the source's own icon stylesheet
	 * (chrome.iconCss) draws the real glyph; absent → the ✓ fallback. */
	iconClass?: string;
	/** The source icon position (aux-ico-pos-left/top, …): 'row' renders the
	 * icon left of the text (the source's stacked icon rows), 'stack' above
	 * it. Absent → the default card grid. */
	layout?: 'row' | 'stack';
	/** Attribution line (testimonial/quote authors). */
	author?: string;
}

/**
 * One rebuilt form field (Proposal 114 §3.9) — the classified, presentation-
 * level view of a source form control. Names are the ORIGINAL wire names
 * (Elementor's `form_fields[name]` syntax survives as-is so the bridge can
 * map them back into the native handler).
 */
export interface FormField {
	/** The wire name (submission key). */
	name?: string;
	/** Control type — 'text' (default), 'email', 'textarea', 'select', … */
	type?: string;
	label?: string;
	placeholder?: string;
	required?: boolean;
	/** Pre-filled value (never rendered as raw HTML). */
	value?: string;
	rows?: string;
	options?: { value?: string; label: string; selected?: boolean }[];
}

/**
 * The classified view of a source `<form>`: the static site renders a
 * rebuilt ADS form from this — never the source markup — and posts to the
 * configured bridge endpoint with `form` (the WP-side identifier) as the
 * routing key.
 */
export interface FormInfo {
	/** 'search' maps to the site's own /search/ surface; 'submit' posts to
	 * the bridge; 'cta' (no resolvable WP form) degrades to a plain band. */
	kind: 'search' | 'submit' | 'cta';
	/** Which WP-side plugin owns the form (from the hidden ID carrier). */
	handler?: 'elementor' | 'jetformbuilder' | 'wpcf7' | 'generic';
	/** The WP-side form identifier (widget id, form post ID, …). */
	formId?: string;
	/** The source form's name attribute (used when no ID carrier exists). */
	formName?: string;
	/** The source WP post id (Elementor's `post_id` / JFB's `__queried_post_id`
	 * hidden carrier) — the bridge passes it back to the native handler. */
	postId?: string;
	fields: FormField[];
	submitLabel?: string;
}

export interface SemanticSection {
	kind: SectionKind;
	confidence: 'high' | 'medium' | 'low';
	slots: {
		title?: string;
		body?: string[];
		media?: MediaRef[];
		items?: SectionItem[];
		links?: LinkRef[];
	};
	/** The classified form (form sections only) — the Form component's input. */
	form?: FormInfo;
	/** The sanitized source substring (text/raw kinds only). */
	html?: string;
	/** The source builder section id (Elementor's elementor-element-<id>
	 * class) — the theming hook: the scaffold bakes the source section
	 * backgrounds keyed by this id into sections.generated.ts, and the
	 * renderer re-applies them per section. */
	id?: string;
}

/** classifyHtml options (the generated renderer passes the manifest's
 * per-site search-form names through). */
export interface ClassifyOptions {
	/** Extra input names that mark a single-field form as a search form. */
	searchForms?: string[];
	/**
	 * Learned role overrides (Proposal 116) — win over the seeded default
	 * dictionaries. The scaffold bakes the profile's vocabulary into
	 * vocabulary.generated.ts; the renderer passes it here so widget/block
	 * vocabulary evolves per-site without a forge release.
	 */
	widgetRoles?: Record<string, WidgetRole>;
	blockRoles?: Record<string, BlockRole>;
}

/** section-kind → template component + human label (the data mapping, 114 §3.4). */
export const SECTION_MAP: Record<SectionKind, { component: string; label: string }> = {
	hero: { component: 'Hero', label: 'Hero' },
	'feature-grid': { component: 'FeatureGrid', label: 'Feature grid' },
	'icon-list': { component: 'IconList', label: 'Icon list' },
	testimonial: { component: 'Testimonial', label: 'Testimonial' },
	'logo-cloud': { component: 'LogoCloud', label: 'Logo cloud' },
	cta: { component: 'Cta', label: 'Call to action' },
	gallery: { component: 'Gallery', label: 'Gallery' },
	text: { component: 'TextBlock', label: 'Text' },
	form: { component: 'Form', label: 'Form' },
	raw: { component: 'RawHtml', label: 'Raw HTML' },
};

// -- minimal structural node model (htmlparser2 output, typed structurally) ---

interface HNode {
	type: string;
	name?: string;
	attribs?: Record<string, string>;
	children?: HNode[] | null;
	data?: string;
	startIndex?: number | null;
	endIndex?: number | null;
}

/** domhandler children are arrays (possibly null) — normalize defensively. */
function childrenOf( node: HNode ): HNode[] {
	const children = node.children;
	if ( ! children ) {
		return [];
	}
	return Array.isArray( children ) ? children : Array.from( children as unknown as ArrayLike<HNode> );
}

// -- elementor + block signatures --------------------------------------------

const ELEMENTOR_SECTION = /(^|\s)elementor-section(\s|$)/;
const ELEMENTOR_WIDGET = /(^|\s)elementor-widget(\s|$)/;
const WIDGET_NAME_RE = /elementor-widget-([\w-]+)/;
const WP_BLOCK = /(^|\s)wp-block-([\w-]+)(\s|$)/;
const VC_WRAPPER = /(^|\s)vc_[a-z_]+(\s|$)/;

/**
 * Widget name → role vocabulary (Elementor's widget taxonomy) — the SEEDED
 * DEFAULT dictionary (Proposal 116 §3). Unknown names never drop content:
 * they classify as `content` (slots merge, the section shape decides the
 * kind) and the operator can pin them per-profile via `widgetRoles`.
 */
export type WidgetRole = { kind: 'title' | 'body' | 'media' | 'links' | 'feature' | 'icon-item' | 'quote' | 'gallery' | 'form' | 'content' };
export type BlockRole = { kind: 'title' | 'body' | 'media' | 'links' | 'quote' | 'gallery' | 'container' | 'content' };

/** The seeded widget dictionary (exported for the learn/report surface). */
export const SEEDED_WIDGET_ROLES: Record<string, WidgetRole> = {
	heading: { kind: 'title' },
	'theme-page-title': { kind: 'title' },
	'text-editor': { kind: 'body' },
	image: { kind: 'media' },
	'image-box': { kind: 'media' },
	'image-carousel': { kind: 'media' },
	'image-gallery': { kind: 'gallery' },
	button: { kind: 'links' },
	icon: { kind: 'icon-item' },
	'icon-box': { kind: 'feature' },
	'icon-list': { kind: 'icon-item' },
	testimonial: { kind: 'quote' },
	'testimonial-carousel': { kind: 'quote' },
	reviews: { kind: 'quote' },
	blockquote: { kind: 'quote' },
	counter: { kind: 'feature' },
	progress: { kind: 'feature' },
	// Elementor Pro's form widget — the form is extracted by the caller
	// (form nodes are split out BEFORE widget grouping), so the role only
	// marks the wrapper; the FormInfo carries the payload.
	form: { kind: 'form' },
	// Phlox Pro / Auxin widget family (jayainc's live vocabulary, 2026-11-06
	// run) — same Elementor widget-class contract, vendor-prefixed names.
	aux_modern_heading: { kind: 'title' },
	aux_button: { kind: 'links' },
	aux_image: { kind: 'media' },
	aux_blockquote: { kind: 'quote' },
	aux_text: { kind: 'body' },
};

/** wp-block-* name → role (Gutenberg's block vocabulary). */
export const SEEDED_BLOCK_ROLES: Record<string, BlockRole> = {
	heading: { kind: 'title' },
	paragraph: { kind: 'body' },
	list: { kind: 'body' },
	image: { kind: 'media' },
	'media-text': { kind: 'media' },
	cover: { kind: 'media' },
	gallery: { kind: 'gallery' },
	button: { kind: 'links' },
	buttons: { kind: 'links' },
	quote: { kind: 'quote' },
	pullquote: { kind: 'quote' },
	// Structured blocks (116 §2): container-gated into the section pipeline.
	// Gutenberg-only docs were gated to text before, so re-seeding these from
	// 'body' changes no existing output — it only arms the learned-vocabulary gate.
	columns: { kind: 'container' },
	group: { kind: 'container' },
};

function classList( attribs?: Record<string, string> ): string[] {
	return ( attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );
}

function textOf( node: HNode ): string {
	if ( node.type === 'text' ) {
		return node.data ?? '';
	}
	return childrenOf( node ).map( textOf ).join( '' );
}

function trimmed( value: string ): string {
	return value.replace( /\s+/g, ' ' ).trim();
}

/** First descendant matching a predicate (depth-first). */
function findDescendant( node: HNode, predicate: ( node: HNode ) => boolean ): HNode | undefined {
	if ( predicate( node ) ) {
		return node;
	}
	for ( const child of childrenOf( node ) ) {
		const found = findDescendant( child, predicate );
		if ( found ) {
			return found;
		}
	}
	return undefined;
}

function allDescendants( node: HNode, predicate: ( node: HNode ) => boolean ): HNode[] {
	const out: HNode[] = [];
	const walk = ( current: HNode ) => {
		if ( predicate( current ) ) {
			out.push( current );
		}
		for ( const child of childrenOf( current ) ) {
			walk( child );
		}
	};
	walk( node );
	return out;
}

/** Collect imgs/links/headings/paragraphs inside a widget node. */
function collectSlots( node: HNode ): SemanticSection['slots'] {
	const slots: SemanticSection['slots'] = {};
	const media: MediaRef[] = [];
	const links: LinkRef[] = [];
	const body: string[] = [];

	const isImg = ( n: HNode ) => n.type === 'tag' && n.name === 'img';
	const isLink = ( n: HNode ) => n.type === 'tag' && n.name === 'a';
	for ( const img of allDescendants( node, isImg ) ) {
		const src = img.attribs?.src ?? img.attribs?.[ 'data-src' ];
		if ( src ) {
			media.push( { src, alt: img.attribs?.alt } );
		}
	}
	for ( const link of allDescendants( node, isLink ) ) {
		const text = trimmed( textOf( link ) );
		const href = link.attribs?.href;
		if ( text || href ) {
			links.push( { text, href } );
		}
	}

	const isHeading = ( n: HNode ) => n.type === 'tag' && /^h[1-6]$/.test( n.name ?? '' );
	const heading = findDescendant( node, isHeading );
	const titleText = heading ? trimmed( textOf( heading ) ) : '';
	if ( titleText ) {
		slots.title = titleText;
	}
	const isParagraph = ( n: HNode ) => n.type === 'tag' && n.name === 'p';
	for ( const p of allDescendants( node, isParagraph ) ) {
		const value = trimmed( textOf( p ) );
		if ( value && value !== titleText ) {
			body.push( value );
		}
	}
	if ( media.length > 0 ) {
		slots.media = media;
	}
	if ( links.length > 0 ) {
		slots.links = links;
	}
	if ( body.length > 0 ) {
		slots.body = body;
	}
	return slots;
}

interface WidgetLike {
	node: HNode;
	role: WidgetRole | BlockRole;
	confidence: 'high' | 'medium' | 'low';
}

/** Resolve a widget role: profile overrides win over the seeded defaults. */
function resolveWidgetRole( name: string, options: ClassifyOptions ): WidgetRole | undefined {
	return options.widgetRoles?.[ name ] ?? SEEDED_WIDGET_ROLES[ name ];
}

/** Resolve a block role: profile overrides win over the seeded defaults. */
function resolveBlockRole( name: string, options: ClassifyOptions ): BlockRole | undefined {
	return options.blockRoles?.[ name ] ?? SEEDED_BLOCK_ROLES[ name ];
}

/** Find classified widgets inside a node (Elementor + blocks). Unknown names
 *  classify as `content` — never dropped (Proposal 116 §2). */
function collectWidgets( root: HNode, options: ClassifyOptions ): WidgetLike[] {
	const widgets: WidgetLike[] = [];
	const walk = ( node: HNode ) => {
		if ( node.type === 'tag' ) {
			const classes = classList( node.attribs );
			const elementorWidget = classes.some( ( c ) => ELEMENTOR_WIDGET.test( c ) || ELEMENTOR_WIDGET.test( ` ${ c } ` ) );
			if ( elementorWidget ) {
				const match = classes.map( ( c ) => WIDGET_NAME_RE.exec( c ) ).find( Boolean );
				const name = match?.[ 1 ].toLowerCase();
				const role = name ? resolveWidgetRole( name, options ) : undefined;
				widgets.push( { node, role: role ?? { kind: 'content' }, confidence: role ? 'high' : 'low' } );
				return; // Widget content belongs to the widget.
			}
			const block = classes.map( ( c ) => WP_BLOCK.exec( c ) ).find( Boolean );
			if ( block ) {
				const role = resolveBlockRole( block[ 2 ].toLowerCase(), options );
				widgets.push( { node, role: role ?? { kind: 'content' }, confidence: role ? 'high' : 'low' } );
				return;
			}
		}
		for ( const child of childrenOf( node ) ) {
			walk( child );
		}
	};
	for ( const child of childrenOf( root ) ) {
		walk( child );
	}
	return widgets;
}

/** Is `candidate` inside `container` (any depth)? */
function within( candidate: HNode, container: HNode ): boolean {
	return allDescendants( container, ( n ) => n === candidate ).length > 0;
}

/**
 * Extract the structured icon-text widget pattern — a header box (an icon
 * glyph or an image) plus a small heading plus a body paragraph. Elementor's
 * aux_text widget (and similar icon-box families) renders single-column
 * feature cards this way; the flat slot collector would flatten them into
 * uncaptioned media + orphan paragraphs (jayainc's product cards and
 * service features hit exactly this). Returns an item draft or undefined.
 */
/** The source icon glyph's font classes, marker-ready. The source icon
 * fonts identify glyphs by class (auxicon-*, Font Awesome's fa fa-*,
 * dashicons-*, Elementor's eicon-*); the rebuild's marker carries them so
 * the source's own icon stylesheet (chrome.iconCss) can draw the real
 * glyph. Unrecognized classes yield undefined (the ✓ fallback). */
function glyphClassesOf( node: HNode ): string | undefined {
	const classes = classList( node.attribs );
	const auxGlyph = classes.find( ( c ) => /^auxicon-/i.test( c ) );
	if ( auxGlyph ) {
		return `auxicon ${ auxGlyph }`;
	}
	const dashiconsGlyph = classes.find( ( c ) => /^dashicons-/i.test( c ) );
	if ( dashiconsGlyph ) {
		return `dashicons ${ dashiconsGlyph }`;
	}
	const eiconGlyph = classes.find( ( c ) => /^eicon-/i.test( c ) );
	if ( eiconGlyph ) {
		return `eicon ${ eiconGlyph }`;
	}
	const faFamily = classes.find( ( c ) => /^(?:fa|fas|far|fab|fal|fad|fat)$/i.test( c ) );
	const faGlyph = classes.find( ( c ) => /^fa-/i.test( c ) );
	if ( faFamily ) {
		return faGlyph ? `fa ${ faFamily } ${ faGlyph }` : `fa ${ faFamily }`;
	}
	return undefined;
}

function extractStructuredItem( node: HNode ): { title?: string; body?: string; media?: MediaRef; icon: boolean; iconClass?: string; layout?: 'row' | 'stack' } | undefined {
	const headings = allDescendants( node, ( n ) => n.type === 'tag' && /^h[3-6]$/.test( n.name ?? '' ) );
	if ( headings.length === 0 ) {
		return undefined;
	}
	// The header-box class match must not hit the widget's own class
	// (elementor-widget-icon-box contains 'icon') — only real icon containers:
	// aux's `aux-ico-box` family and Elementor's icon-box internals.
	const headerBoxes = allDescendants( node, ( n ) => n.type === 'tag' && classList( n.attribs ).some( ( c ) => /ico-box|icon-box-icon|icon-box-wrapper/i.test( c ) ) );
	let icon = false;
	let iconClass: string | undefined;
	let media: MediaRef | undefined;
	if ( headerBoxes.length > 0 ) {
		const box = headerBoxes[ 0 ];
		const iconSpans = allDescendants( box, ( n ) => n.type === 'tag' && n.name === 'span' && classList( n.attribs ).some( ( c ) => /icon/i.test( c ) ) );
		icon = iconSpans.length > 0;
		iconClass = iconSpans.length > 0 ? glyphClassesOf( iconSpans[ 0 ] ) : undefined;
		const img = findDescendant( box, ( n ) => n.type === 'tag' && n.name === 'img' );
		if ( img ) {
			media = { src: img.attribs?.src ?? img.attribs?.[ 'data-src' ], alt: img.attribs?.alt };
		}
	}
	// The source icon position (aux's aux-ico-pos-* container classes) — the
	// row/stack fact the renderer rebuilds (jayainc's service cards are
	// icon-LEFT rows, not an icon-above card grid).
	const positionNode = findDescendant( node, ( n ) => n.type === 'tag' && classList( n.attribs ).some( ( c ) => /^aux-ico-pos-(?:left|top|right|bottom)$/.test( c ) ) );
	const positionClass = positionNode ? classList( positionNode.attribs ).find( ( c ) => /^aux-ico-pos-(?:left|top|right|bottom)$/.test( c ) ) : undefined;
	const layout: 'row' | 'stack' | undefined = positionClass === 'aux-ico-pos-top' || positionClass === 'aux-ico-pos-bottom' ? 'stack' : positionClass ? 'row' : undefined;
	const title = trimmed( textOf( headings[ 0 ] ) );
	if ( ! title ) {
		return undefined;
	}
	// Body: the widget's paragraphs, minus the title and anything inside the
	// header box.
	const paragraphs = allDescendants( node, ( n ) => n.type === 'tag' && n.name === 'p' )
		.filter( ( p ) => ! ( headerBoxes.length > 0 && within( p, headerBoxes[ 0 ] ) ) )
		.map( ( p ) => trimmed( textOf( p ) ) )
		.filter( ( value ) => value && value !== title );
	const body = paragraphs.length > 0 ? paragraphs.join( ' ' ) : undefined;
	return { title, body, media, icon, iconClass, layout };
}

/** The attribution line of a quote widget (aux-author / cite / byline). */
function authorOf( node: HNode ): string | undefined {
	for ( const el of allDescendants( node, ( n ) => n.type === 'tag' ) ) {
		const classes = classList( el.attribs );
		const isAuthorEl = el.name === 'cite' || classes.some( ( c ) => /(author|byline|attribution)/i.test( c ) );
		if ( isAuthorEl ) {
			const value = trimmed( textOf( el ) );
			if ( value ) {
				return value;
			}
		}
	}
	return undefined;
}

/** The source builder section id (Elementor's elementor-element-<id> class). */
function elementorId( node: HNode ): string | undefined {
	const match = classList( node.attribs )
		.map( ( c ) => /^elementor-element-([0-9a-f]{4,})$/.exec( c ) )
		.find( Boolean );
	return match?.[ 1 ];
}

/** Merge widget slots into one section draft. */
function sectionFromWidgets( widgets: WidgetLike[], index: number ): SemanticSection {
	const slots: SemanticSection['slots'] = {};
	const media: MediaRef[] = [];
	const links: LinkRef[] = [];
	const body: string[] = [];
	const items: SectionItem[] = [];
	const confidence: 'high' | 'medium' | 'low' = 'high';

	for ( const widget of widgets ) {
		// Structured icon-text widgets (an icon/image header + a small heading)
		// are single-column feature cards — extract them as items BEFORE the
		// flat slot merge, which loses the captions and flattens the columns.
		const structured =
			widget.role.kind === 'body' || widget.role.kind === 'content' || widget.role.kind === 'feature'
				? extractStructuredItem( widget.node )
				: undefined;
		if ( structured ) {
			const item: SectionItem = { title: structured.title };
			if ( structured.body ) {
				item.body = structured.body;
			}
			if ( structured.media ) {
				item.media = structured.media;
			}
			if ( structured.icon ) {
				item.icon = true;
			}
			if ( structured.iconClass ) {
				item.iconClass = structured.iconClass;
			}
			if ( structured.layout ) {
				item.layout = structured.layout;
			}
			items.push( item );
			continue;
		}
		const collected = collectSlots( widget.node );
		if ( collected.title && ! slots.title ) {
			slots.title = collected.title;
		}
		for ( const paragraph of collected.body ?? [] ) {
			if ( ! body.includes( paragraph ) ) {
				body.push( paragraph );
			}
		}
		media.push( ...( collected.media ?? [] ) );
		links.push( ...( collected.links ?? [] ) );
		if ( widget.role.kind === 'feature' || widget.role.kind === 'icon-item' || widget.role.kind === 'quote' ) {
			const item: SectionItem = {};
			if ( collected.title ) {
				item.title = collected.title;
			}
			if ( ( collected.body ?? [] ).length > 0 ) {
				item.body = collected.body?.[ 0 ];
			}
			if ( ( collected.media ?? [] ).length > 0 ) {
				item.media = collected.media?.[ 0 ];
			}
			if ( ( collected.links ?? [] ).length > 0 ) {
				item.links = collected.links;
			}
			if ( widget.role.kind === 'quote' ) {
				item.author = authorOf( widget.node );
			}
			items.push( item );
		}
	}
	if ( media.length > 0 ) {
		slots.media = media;
	}
	if ( links.length > 0 ) {
		slots.links = links;
	}
	if ( body.length > 0 ) {
		slots.body = body;
	}
	if ( items.length > 0 ) {
		slots.items = items;
	}

	const hasBody = ( slots.body?.length ?? 0 ) > 0;
	// Unknown-name widgets drop the confidence — the shape decided the kind,
	// not a recognized vocabulary entry (Proposal 116).
	const effectiveConfidence = widgets.some( ( widget ) => widget.role.kind === 'content' ) ? 'low' : confidence;
	const kinds: Array<{ kind: SectionKind; reason: boolean }> = [
		{ kind: 'logo-cloud', reason: media.length >= 3 && ! hasBody && items.length === 0 },
		{ kind: 'gallery', reason: media.length >= 2 && ! slots.title && ! hasBody },
		{ kind: 'testimonial', reason: items.length > 0 && items.some( ( item ) => Boolean( item.body ) ) && ! slots.title && ! items.some( ( item ) => Boolean( item.title ) ) },
		{ kind: 'icon-list', reason: items.length >= 2 && items.every( ( item ) => Boolean( item.icon ) ) },
		{ kind: 'feature-grid', reason: items.length >= 2 },
		{ kind: 'cta', reason: Boolean( slots.title ) && links.length > 0 && ! hasBody && media.length === 0 },
		{ kind: 'hero', reason: Boolean( slots.title ) && ( hasBody || media.length > 0 ) && ( index === 0 || ( links.length > 0 && media.length > 0 ) ) },
		{ kind: 'text', reason: true },
	];
	const matched = kinds.find( ( entry ) => entry.reason ) ?? kinds[ kinds.length - 1 ];
	return { kind: matched.kind, confidence: effectiveConfidence, slots };
}

/** Does this node look like a section boundary (Elementor section / group)? */
function isSectionBoundary( node: HNode ): boolean {
	const classes = classList( node.attribs );
	return classes.some(
		( c ) =>
			ELEMENTOR_SECTION.test( c ) ||
			ELEMENTOR_SECTION.test( ` ${ c } ` ) ||
			/(^|\s)wp-block-group(\s|$)/.test( c ) ||
			/(^|\s)wp-block-cover(\s|$)/.test( c )
	);
}

function sliceHtml( html: string, node: HNode ): string {
	// domhandler indices are slice-compatible (endIndex exclusive).
	if ( typeof node.startIndex === 'number' && typeof node.endIndex === 'number' && node.endIndex > node.startIndex ) {
		return html.slice( node.startIndex, node.endIndex );
	}
	return '';
}

/** True when the node subtree carries any of the recognized builder markers. */
function hasBuilderMarkers( node: HNode ): boolean {
	const classes = classList( node.attribs );
	const marked = classes.some(
		( c ) => ELEMENTOR_WIDGET.test( c ) || ELEMENTOR_WIDGET.test( ` ${ c } ` ) || ELEMENTOR_SECTION.test( c ) || WP_BLOCK.test( c ) || VC_WRAPPER.test( c )
	);
	if ( marked ) {
		return true;
	}
	return childrenOf( node ).some( hasBuilderMarkers );
}

// -- form classification (Proposal 114 §3.9) -----------------------------------

/** Hidden-input names that carry the WP-side form identifier. */
const FORM_ID_CARRIERS: Record<string, 'elementor' | 'jetformbuilder' | 'wpcf7'> = {
	form_id: 'elementor',
	_jet_engine_booking_form_id: 'jetformbuilder',
	_wpcf7: 'wpcf7',
};

/** Default input names that mark a single-field form as a site search form. */
const DEFAULT_SEARCH_NAMES = new Set( [ 's', 'search', 'query', 'q' ] );

const HIDDEN_INPUT_TYPES = new Set( [ 'hidden', 'submit', 'button', 'image', 'reset' ] );

function isFormNode( node: HNode ): boolean {
	return node.type === 'tag' && node.name === 'form';
}

function isFieldNode( node: HNode ): boolean {
	return node.type === 'tag' && ( node.name === 'input' || node.name === 'textarea' || node.name === 'select' );
}

/** Visible (submittable) fields only — hidden/submit/button inputs are chrome. */
function isVisibleField( node: HNode ): boolean {
	if ( ! isFieldNode( node ) ) {
		return false;
	}
	if ( node.name === 'input' ) {
		const type = ( node.attribs?.type ?? 'text' ).toLowerCase();
		if ( HIDDEN_INPUT_TYPES.has( type ) ) {
			return false;
		}
	}
	return node.attribs?.disabled === undefined;
}

function hasAttr( node: HNode, name: string ): boolean {
	return Object.prototype.hasOwnProperty.call( node.attribs ?? {}, name );
}

/** Collect every `<form>` node under a root (document order, nested allowed). */
function collectFormNodes( root: HNode ): HNode[] {
	return allDescendants( root, isFormNode );
}

/** The submit control's label (button text or input value), if any. */
function findSubmitLabel( formNode: HNode ): string | undefined {
	const isSubmitButton = ( n: HNode ) =>
		n.type === 'tag' && n.name === 'button' && ( ( n.attribs?.type ?? 'submit' ).toLowerCase() === 'submit' );
	const isSubmitInput = ( n: HNode ) =>
		n.type === 'tag' && n.name === 'input' && ( n.attribs?.type ?? '' ).toLowerCase() === 'submit';
	for ( const candidate of allDescendants( formNode, ( n ) => isSubmitButton( n ) || isSubmitInput( n ) ) ) {
		if ( candidate.name === 'input' && candidate.attribs?.value ) {
			return trimmed( candidate.attribs.value );
		}
		const text = trimmed( textOf( candidate ) );
		if ( text ) {
			return text;
		}
	}
	return undefined;
}

/** Label text → the input it labels (by `for`, else the nearest preceding label). */
function labelMap( formNode: HNode ): Map<HNode, string> {
	const map = new Map<HNode, string>();
	const labels = allDescendants( formNode, ( n ) => n.type === 'tag' && n.name === 'label' );
	const fields = allDescendants( formNode, isFieldNode );
	const byFor = new Map<string, string>();
	const forless: Array<{ end: number; text: string }> = [];
	for ( const label of labels ) {
		const text = trimmed( textOf( label ) );
		if ( label.attribs?.for ) {
			byFor.set( label.attribs.for, text );
		} else if ( text && typeof label.endIndex === 'number' ) {
			forless.push( { end: label.endIndex, text } );
		}
	}
	for ( const field of fields ) {
		if ( field.attribs?.id && byFor.has( field.attribs.id ) ) {
			const text = byFor.get( field.attribs.id );
			if ( text ) {
				map.set( field, text );
			}
			continue;
		}
		// For-less labels precede their control in document order — take the
		// NEAREST preceding one.
		if ( typeof field.startIndex === 'number' ) {
			let nearest: { end: number; text: string } | undefined;
			for ( const candidate of forless ) {
				if ( candidate.end <= field.startIndex && ( ! nearest || candidate.end > nearest.end ) ) {
					nearest = candidate;
				}
			}
			if ( nearest ) {
				map.set( field, nearest.text );
			}
		}
	}
	return map;
}

function fieldFrom( node: HNode, label?: string ): FormField {
	const field: FormField = {};
	if ( node.attribs?.name ) {
		field.name = node.attribs.name;
	}
	const type = node.name === 'input' ? ( node.attribs?.type ?? 'text' ).toLowerCase() : node.name;
	if ( type !== 'text' ) {
		field.type = type;
	}
	if ( label ) {
		field.label = label;
	}
	if ( node.attribs?.placeholder ) {
		field.placeholder = node.attribs.placeholder;
	}
	if ( node.attribs?.value ) {
		field.value = node.attribs.value;
	}
	if ( hasAttr( node, 'required' ) ) {
		field.required = true;
	}
	if ( node.name === 'textarea' && node.attribs?.rows ) {
		field.rows = node.attribs.rows;
	}
	if ( node.name === 'select' ) {
		const options = allDescendants( node, ( n ) => n.type === 'tag' && n.name === 'option' ).map( ( option ) => {
			const labelText = trimmed( textOf( option ) );
			return {
				label: labelText,
				value: option.attribs?.value ?? labelText,
				selected: hasAttr( option, 'selected' ),
			};
		} );
		if ( options.length > 0 ) {
			field.options = options;
		}
	}
	return field;
}

/** Is a single visible field a search input (type, name, role, per-site list)? */
function isSearchInput( formNode: HNode, field: HNode, searchForms?: string[] ): boolean {
	const type = ( field.attribs?.type ?? 'text' ).toLowerCase();
	if ( type === 'search' ) {
		return true;
	}
	const name = ( field.attribs?.name ?? '' ).toLowerCase();
	if ( DEFAULT_SEARCH_NAMES.has( name ) ) {
		return true;
	}
	if ( ( searchForms ?? [] ).map( ( value ) => value.toLowerCase() ).includes( name ) ) {
		return true;
	}
	return formNode.attribs?.role === 'search';
}

/**
 * Classify one sanitized `<form>` node: 'search' (single search-ish field →
 * the site's own /search/ surface), 'submit' (a resolvable WP form ID/name
 * → the bridge endpoint), or 'cta' (no resolvable identifier → a plain band,
 * never a dead endpoint).
 */
function classifyForm( formNode: HNode, searchForms?: string[] ): FormInfo | undefined {
	const fields = allDescendants( formNode, isFieldNode );

	let formId: string | undefined;
	let postId: string | undefined;
	let handler: FormInfo['handler'];
	for ( const input of fields ) {
		if ( input.name !== 'input' || ! input.attribs?.name || ! input.attribs.value ) {
			continue;
		}
		const carrier = FORM_ID_CARRIERS[ input.attribs.name ];
		if ( carrier ) {
			formId = input.attribs.value;
			handler = carrier;
			break;
		}
	}
	for ( const input of fields ) {
		if ( input.name !== 'input' || ! input.attribs?.name || ! input.attribs.value ) {
			continue;
		}
		if ( input.attribs.name === 'post_id' || input.attribs.name === '__queried_post_id' ) {
			postId = input.attribs.value;
			break;
		}
	}
	const formName = formNode.attribs?.name;
	const labels = labelMap( formNode );
	const visible = fields.filter( isVisibleField );
	const submitLabel = findSubmitLabel( formNode );

	const single = visible.length === 1 ? visible[ 0 ] : undefined;
	if ( single && isSearchInput( formNode, single, searchForms ) ) {
		const field = fieldFrom( single, labels.get( single ) );
		return { kind: 'search', fields: [ field ], submitLabel };
	}

	if ( visible.length === 0 && ! formId && ! formName ) {
		return undefined; // Nothing to rebuild — the section is dropped.
	}

	return {
		kind: formId || formName ? 'submit' : 'cta',
		handler,
		formId,
		formName,
		postId,
		fields: visible.map( ( field ) => fieldFrom( field, labels.get( field ) ) ),
		submitLabel,
	};
}

/** Links in a region OUTSIDE the form subtree (the CTA-degradation carriers). */
function collectLinksOutside( region: HNode, form: HNode ): LinkRef[] {
	const links: LinkRef[] = [];
	const walk = ( node: HNode ) => {
		if ( node === form ) {
			return;
		}
		if ( node.type === 'tag' && node.name === 'a' ) {
			const text = trimmed( textOf( node ) );
			const href = node.attribs?.href;
			if ( text || href ) {
				links.push( { text, href } );
			}
			return;
		}
		for ( const child of childrenOf( node ) ) {
			walk( child );
		}
	};
	walk( region );
	return links;
}

/** The first heading in a region that lives OUTSIDE the form subtree. */
function headingOutside( region: HNode, form: HNode ): string | undefined {
	const isHeading = ( n: HNode ) => n.type === 'tag' && /^h[1-6]$/.test( n.name ?? '' );
	let found: string | undefined;
	const walk = ( node: HNode ) => {
		if ( found || node === form ) {
			return;
		}
		if ( isHeading( node ) ) {
			found = trimmed( textOf( node ) );
			return;
		}
		for ( const child of childrenOf( node ) ) {
			walk( child );
		}
	};
	walk( region );
	return found;
}

/** Build a `form` section for one classified form node. */
function formSection( html: string, region: HNode, form: HNode, searchForms?: string[] ): SemanticSection | undefined {
	const info = classifyForm( form, searchForms );
	if ( ! info ) {
		return undefined;
	}
	const section: SemanticSection = { kind: 'form', confidence: 'high', slots: {} };
	const title = headingOutside( region, form );
	if ( title ) {
		section.slots.title = title;
	}
	if ( info.kind === 'cta' ) {
		const links = collectLinksOutside( region, form );
		if ( links.length > 0 ) {
			section.slots.links = links;
		}
		// Nothing to degrade to (no title, no links) — drop the section;
		// an empty band would be dead weight, never a dead endpoint.
		if ( ! title && links.length === 0 ) {
			return undefined;
		}
	}
	section.form = info;
	const slice = sliceHtml( html, form );
	if ( slice ) {
		section.html = slice;
	}
	return section;
}

/**
 * Classify a form-free node region with the pre-form pipeline (the
 * builder-marker / Elementor-section grouping path). `fallbackHtml` is the
 * raw-HTML carried by the text fallback — the whole document for the
 * no-forms fast path, joined slices for split regions.
 */
function classifyRegion( html: string, nodes: HNode[], fallbackHtml: string, options: ClassifyOptions ): SemanticSection[] {
	const root: HNode = { type: 'root', children: nodes, startIndex: 0, endIndex: html.length - 1 };

	if ( ! hasBuilderMarkers( root ) ) {
		return fallbackHtml.trim().length > 0 ? [ { kind: 'text', confidence: 'low', slots: {}, html: fallbackHtml } ] : [];
	}

	// Plain Gutenberg prose (no Elementor widgets, no structured/unknown
	// blocks) stays on the proven post-body path — a single raw text section.
	// Structured blocks (columns/group/cover — `container`) and unknown-name
	// blocks (`content`, Proposal 116) enter the section-rebuild pipeline so
	// the learned vocabulary applies without a forge release.
	const allWidgets = collectWidgets( root, options );
	const elementorWidgets = allWidgets.filter( ( widget ) =>
		classList( widget.node.attribs ).some( ( c ) => ELEMENTOR_WIDGET.test( c ) || ELEMENTOR_WIDGET.test( ` ${ c } ` ) )
	);
	const structuredBlocks = allWidgets.some(
		( widget ) => widget.role.kind === 'container' || widget.role.kind === 'content'
	);
	if ( elementorWidgets.length === 0 && ! structuredBlocks ) {
		return fallbackHtml.trim().length > 0 ? [ { kind: 'text', confidence: 'low', slots: {}, html: fallbackHtml } ] : [];
	}

	// Group the document into sections along Elementor section boundaries;
	// without one, the whole document is a single section.
	const sections: HNode[] = [];
	for ( const child of nodes ) {
		if ( child.type === 'tag' && isSectionBoundary( child ) ) {
			sections.push( child );
		}
	}
	if ( sections.length === 0 ) {
		// Wrapped documents (Elementor's data-elementor-type="wp-post" root
		// wraps every section in one container): take the OUTERMOST section
		// boundaries at any depth — never a boundary nested inside another.
		const collectOutermost = ( node: HNode ) => {
			for ( const child of childrenOf( node ) ) {
				if ( child.type === 'tag' && isSectionBoundary( child ) ) {
					sections.push( child );
				} else {
					collectOutermost( child );
				}
			}
		};
		collectOutermost( root );
	}

	if ( sections.length === 0 ) {
		sections.push( root );
	}

	const out: SemanticSection[] = [];
	for ( const section of sections ) {
		const widgets = collectWidgets( section, options );
		const sourceId = elementorId( section );
		if ( widgets.length === 0 ) {
			const text = trimmed( textOf( section ) );
			if ( text.length > 0 ) {
				out.push( { kind: 'text', confidence: 'low', slots: { body: [ text ] }, html: sliceHtml( html, section ) || fallbackHtml, id: sourceId } );
			}
			continue;
		}
		const built = sectionFromWidgets( widgets, out.length );
		const empty =
			! built.slots.title &&
			( built.slots.body?.length ?? 0 ) === 0 &&
			( built.slots.media?.length ?? 0 ) === 0 &&
			( built.slots.items?.length ?? 0 ) === 0 &&
			( built.slots.links?.length ?? 0 ) === 0;
		if ( empty ) {
			// A recognized-but-empty band WITH a source id still renders as
			// an empty themed band — dropping it would break the source's
			// continuous band flow (jayainc's gray spacer bridge and the
			// flush contact/map band). Plain scaffolding without an id
			// stays dropped (the pinned fallback ladder).
			if ( sourceId ) {
				out.push( { kind: 'text', confidence: 'low', slots: {}, id: sourceId } );
			}
			continue;
		}
		if ( sourceId ) {
			built.id = sourceId;
		}
		out.push( built );
	}
	// The pipeline must never empty a non-empty document: embed-only or
	// unclassifiable content falls back to the raw post-body path. Scoped
	// to the structured-block gate — Elementor scaffolding that classifies
	// empty stays dropped (the pinned fallback ladder).
	if ( out.length === 0 && fallbackHtml.trim().length > 0 && elementorWidgets.length === 0 ) {
		return [ { kind: 'text', confidence: 'low', slots: {}, html: fallbackHtml } ];
	}
	return out;
}

/** Join the sanitized source slices of a node list (for split regions). */
function joinSlices( html: string, nodes: HNode[] ): string {
	return nodes.map( ( node ) => sliceHtml( html, node ) ).join( '' );
}

/**
 * Classify sanitized builder HTML into semantic sections. No builder markers
 * → a single `text` section wrapping the whole document (the post-body
 * fallback). Forms (Proposal 114 §3.9) are extracted FIRST — each `<form>`
 * becomes a `form` section carrying its FormInfo (search → the site's own
 * /search/ surface; submit → the bridge endpoint; cta → a plain band) — and
 * the remaining content runs the pre-form pipeline on its own slices.
 * Empty recognized sections are dropped, never rendered.
 */
export function classifyHtml( html: string, options: ClassifyOptions = {} ): SemanticSection[] {
	const handler = new DomHandler( undefined, { withStartIndices: true, withEndIndices: true } );
	const parser = new Parser( handler, { decodeEntities: true, lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( html );
	parser.end();
	// handler.root is the DOCUMENT node — its children are the top-level
	// element/text list.
	const document = handler.root as unknown as HNode;
	const nodes = childrenOf( document );

	const formNodes = collectFormNodes( { type: 'root', children: nodes } );
	if ( formNodes.length === 0 ) {
		// The pre-form pipeline, unchanged — the whole document is one region
		// and the text fallback carries the full source string.
		return classifyRegion( html, nodes, html, options );
	}

	// Forms present: walk top-level nodes in document order; nodes whose
	// subtree contains a form become form sections, everything else keeps
	// its order in the regular pipeline.
	const sections: SemanticSection[] = [];
	const pending: HNode[] = [];
	const flushPending = () => {
		if ( pending.length > 0 ) {
			sections.push( ...classifyRegion( html, pending, joinSlices( html, pending ), options ) );
			pending.length = 0;
		}
	};
	for ( const child of nodes ) {
		const forms = collectFormNodes( child );
		if ( forms.length > 0 ) {
			flushPending();
			for ( const form of forms ) {
				const section = formSection( html, child, form, options.searchForms );
				if ( section ) {
					sections.push( section );
				}
			}
		} else {
			pending.push( child );
		}
	}
	flushPending();
	return sections;
}
