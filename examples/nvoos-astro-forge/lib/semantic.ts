// SPDX-License-Identifier: MIT
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
	| 'accordion'
	| 'raw'
	// Proposal 136 §4.1 — the six kinds decided by STRUCTURAL evidence.
	| 'pricing'
	| 'stats'
	| 'steps'
	| 'team'
	| 'marquee'
	| 'comparison';

// -- Proposal 136 §4.1 band slots ---------------------------------------------
// The classifier and the template's DesignRenderer share these shapes (this
// file is vendored into generated projects as src/forge/semantic.ts, so it must
// stay dependency-free — no imports from lib/types.ts).

/** One plan in a pricing band. `price` is the source's verbatim string (it
 * carries the currency and period — `$98/mo`) so the structured-data gate can
 * match it against the visible text. */
export interface PricingPlan {
	name: string;
	price: string;
	currency?: string;
	period?: string;
	features?: string[];
	cta?: { label: string; href: string };
	featured?: boolean;
	/** Only present when the source shows a struck-through original price. */
	salePrice?: string;
}

/** One metric. `unit` is split only when the source splits it. */
export interface StatItem {
	value: string;
	unit?: string;
	label: string;
	caption?: string;
}

/** One ordered step. */
export interface StepItem {
	title: string;
	body?: string;
	media?: string;
}

/** One person. */
export interface TeamMember {
	name: string;
	/** Absent when the source shows only a name (never fabricated). */
	role?: string;
	photo?: string;
	bio?: string;
	sameAs?: string;
}

/** One repeated label in a marquee. */
export interface MarqueeItem {
	label: string;
	icon?: string;
}

/** One row of a comparison band. */
export interface ComparisonRow {
	label: string;
	values: string[];
}

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
	/**
	 * The heading LEVEL the item's title came from (2–6). A multi-column band
	 * spreads real `h2` headings across its lanes, and the band's measured type
	 * rule is scoped to that level — an item rendered at a different level never
	 * matches it (virtuallysrilanka's services columns stayed at the template's
	 * default size). Absent → the component's own default.
	 */
	titleLevel?: number;
	/**
	 * The SOURCE COLUMN this item came from, when the band is a column grid.
	 *
	 * A lane can hold a surface and no content (a photo column painted as a CSS
	 * background), and `columnItems` skips those so no empty card is emitted —
	 * but then the remaining items no longer line up with `design.sections[].columns`
	 * and a three-column band rebuilt as two lanes with none of its surfaces.
	 * The index is what lets the grid place and paint the lanes faithfully.
	 */
	columnIndex?: number;
	body?: string;
	/**
	 * A BOLD lead-in that opens `body` (the source's `<strong>` run).
	 *
	 * A builder opens most copy blocks with one — the accordion panel is
	 * `<p><strong>Advertise Your Business</strong> with your own Wi-Fi Start
	 * Page…` — and flattening the panel to `textContent` printed it as ordinary
	 * copy, so the para lost the only thing distinguishing its first clause
	 * (virtuallysrilanka's features band, 2026-11-20). Plain text, never markup:
	 * the template wraps it in its own `<strong>`, so nothing is injected.
	 */
	lead?: string;
	/**
	 * The card's copy as SEPARATE paragraphs, when the source publishes more than
	 * one.
	 *
	 * `body` joins them with a space so the card still has one string, but a
	 * builder's services panel is four distinct paragraphs 20px apart — joined,
	 * they render as one block of prose and the card reads as a wall of text
	 * (virtuallysrilanka's black services column, 2026-11-20).
	 */
	paragraphs?: string[];
	/**
	 * The card's BULLET LIST — a list the CARD carries, not items beside it.
	 *
	 * A builder publishes one as `<ul class="elementor-icon-list-items">
	 * <li class="elementor-icon-list-item"><span class="elementor-icon-list-text">
	 * Branding &amp; SSO setup</span></li>…`. `iconListItems` turns each ENTRY into a
	 * card when the list IS the band, which is right for an icon-list section and
	 * wrong inside a card: the column path then dropped the entries entirely.
	 * eca.directory's phased-delivery band lost 17 entries this way — its largest
	 * text loss (2026-11-23), still missing after `iconListItems` shipped because
	 * `columnItems` replaced the items it had found (2026-11-24).
	 */
	bullets?: string[];
	/**
	 * A small label that sits ABOVE the card's title in the source — Elementor’s
	 * `h3` “Phase 1” over its icon-box title “Pilot Setup”.
	 *
	 * Order is copy: printed after the title the shingles no longer match the
	 * source (“phase 1 pilot” vanished from eca.directory’s coverage report,
	 * 2026-11-24). Absent → no kicker.
	 */
	kicker?: string;
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
		/** The source heading level the title came from (`title` is an h1 at 1). */
		titleLevel?: number;
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
	/** The source element's own HTML id — the nav anchor (#about, #contact…).
	 * The renderer re-applies it when the band has no themed anchor, so a
	 * content-classified section keeps a one-pager's targets (virtuallyjamaica's
	 * `#contact` was lost this way). */
	anchor?: string;

	// -- Proposal 136 §4.1: the six structural kinds carry their own slots -----
	/** Pricing band: plans in source order (≥2, else it is not a pricing band). */
	pricing?: { plans: PricingPlan[]; note?: string };
	/** Stats band: value/unit/label triples in source order (≥2). */
	stats?: { items: StatItem[] };
	/** Steps band: an ORDERED list — the order IS the information (≥2). */
	steps?: { items: StepItem[] };
	/** Team band: people with their role (≥2). */
	team?: { people: TeamMember[] };
	/** Marquee band: repeated labels (≥4). The pause control is the template's
	 * obligation (WCAG 2.2.2), never the source's. */
	marquee?: { items: MarqueeItem[]; speed?: string };
	/** Comparison band: columns then rows; `values` align to the columns. */
	comparison?: { columns: string[]; rows: ComparisonRow[]; caption?: string };
	/**
	 * The band renders a DYNAMIC LIST — a query loop (Elementor's Posts /
	 * Portfolio widget), not authored cards.
	 *
	 * Recorded because the two cases need different empty states: an authored
	 * grid with no cards is a classification miss, while a query loop with no
	 * published cards is the CMS honestly having nothing. The renderer also uses
	 * it to keep a loop band's empty state to its own COPY instead of inventing
	 * placeholder tiles.
	 */
	dynamicList?: boolean;
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
	accordion: { component: 'Accordion', label: 'Accordion' },
	raw: { component: 'RawHtml', label: 'Raw HTML' },
	pricing: { component: 'Pricing', label: 'Pricing' },
	stats: { component: 'Stats', label: 'Stats' },
	steps: { component: 'Steps', label: 'Steps' },
	team: { component: 'Team', label: 'Team' },
	marquee: { component: 'Marquee', label: 'Marquee' },
	comparison: { component: 'Comparison', label: 'Comparison' },
};

// -- minimal structural node model (htmlparser2 output, typed structurally) ---

interface HNode {
	type: string;
	name?: string;
	attribs?: Record< string, string >;
	children?: HNode[] | null;
	data?: string;
	startIndex?: number | null;
	endIndex?: number | null;
	/** domhandler links children back to their parent — the accordion extractor
	 * needs it because the sanitizer drops the tab's `aria-controls`. */
	parent?: HNode | null;
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
// Elementor's flexbox containers (≥3.16, the default since 3.19) replaced top
// sections: a TOP-LEVEL container is `.e-con.e-parent`, and the nested ones
// are `.e-con.e-child`. Without this, every container-built page collapsed to
// ONE band plus a form section (eca.directory, 2026-11-23: a 7-band home page
// classified as a single `form`).
const ELEMENTOR_CONTAINER_BAND = /(^|\s)e-parent(\s|$)/;
const ELEMENTOR_WIDGET = /(^|\s)elementor-widget(\s|$)/;
const WIDGET_NAME_RE = /elementor-widget-([\w-]+)/;
const WP_BLOCK = /(^|\s)wp-block-([\w-]+)(\s|$)/;
// WPBakery (and themes that rewrite it). Brando — virtuallyjamaica.com —
// emits `wpb_column` inside `<section class="brando_row_…">` and drops the
// `vc_row` wrapper entirely, so both the column and the row markers matter.
const VC_WRAPPER = /(^|\s)(?:vc|wpb)_[a-z_]+(\s|$)/;
const WPB_ROW = /(^|\s)(?:vc_row|wpb_row)(?:[\s-]|$)/;

/**
 * Widget name → role vocabulary (Elementor's widget taxonomy) — the SEEDED
 * DEFAULT dictionary (Proposal 116 §3). Unknown names never drop content:
 * they classify as `content` (slots merge, the section shape decides the
 * kind) and the operator can pin them per-profile via `widgetRoles`.
 */
export type WidgetRole = { kind: 'title' | 'body' | 'media' | 'links' | 'feature' | 'icon-item' | 'quote' | 'gallery' | 'form' | 'accordion' | 'list' | 'content' };
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
	// An accordion is a COMPONENT, not a heading list: its titles are <a>
	// elements the flat merge would collect as buttons and its panels as body
	// copy (virtuallysrilanka's features band: 8 red chips and a 1177px band
	// expanded to 2987px).
	accordion: { kind: 'accordion' },
	'toggle': { kind: 'accordion' },
	// A tab set is an accordion with a different control: its pane TITLES are
	// links the flat merge would collect as buttons and its panes are body copy.
	// Fewer than two panes falls through to the normal path (see `accordionEntries`).
	tabs: { kind: 'accordion' },
	// DYNAMIC LISTS. Their cards come from a query, not from the markup, so the
	// role only NAMES the band: `SectionItem`s still come from the rendered
	// cards, but the renderer can now tell that a band is a post/portfolio loop
	// (and render its empty state instead of a placeholder card). A `list` role
	// deliberately does NOT force `low` confidence the way `content` does — a
	// posts band is a recognised widget, not an unknown one.
	posts: { kind: 'list' },
	portfolio: { kind: 'list' },
	// Elementor Free widgets that were unseeded: an unseeded name resolves to
	// `content`, which (a) flattens the widget's own structure and (b) forces
	// the band to `low` confidence by the `effectiveConfidence` rule.
	'star-rating': { kind: 'feature' },
	video: { kind: 'media' },
	google_maps: { kind: 'media' },
	'social-icons': { kind: 'links' },
	'text-path': { kind: 'body' },
	// Elementor Pro widgets.
	'nav-menu': { kind: 'links' },
	'search-form': { kind: 'form' },
	login: { kind: 'form' },
	'share-buttons': { kind: 'links' },
	'paypal-button': { kind: 'links' },
	'stripe-button': { kind: 'links' },
	countdown: { kind: 'feature' },
	rating: { kind: 'feature' },
	'price-table': { kind: 'feature' },
	'price-list': { kind: 'feature' },
	'animated-headline': { kind: 'title' },
	gallery: { kind: 'gallery' },
	slides: { kind: 'gallery' },
	// Theme-builder widgets: a header/footer/archive template renders through
	// these, and their copy belongs to the slot the name says.
	'theme-site-logo': { kind: 'media' },
	'theme-site-title': { kind: 'title' },
	'theme-post-title': { kind: 'title' },
	'theme-archive-title': { kind: 'title' },
	'theme-post-content': { kind: 'body' },
	'theme-post-featured-image': { kind: 'media' },
	// Phlox Pro / Auxin widget family (jayainc's live vocabulary, 2026-11-06
	// run) — same Elementor widget-class contract, vendor-prefixed names.
	aux_modern_heading: { kind: 'title' },
	aux_button: { kind: 'links' },
	aux_image: { kind: 'media' },
	aux_blockquote: { kind: 'quote' },
	aux_text: { kind: 'body' },
	// Theme/utility widget classes seen on BOTH the jayainc (Phlox/Auxin +
	// Elementor) and virtuallyjamaica (WPBakery + Brando) live sites, so they
	// are seeded rather than pinned per profile. Every mapping is
	// non-destructive: wrappers carry their own text only in rare cases and
	// their children are classified individually, and the link/file widgets
	// are buttons whose label belongs in the links slot.
	'button__link': { kind: 'links' },
	'file__button': { kind: 'links' },
	// Decorative shape widget (Phlox) — renders as a graphic, never as text.
	aux_simple_shape: { kind: 'media' },
	aux_shape: { kind: 'media' },
	container: { kind: 'content' },
	wrap: { kind: 'content' },
	spacer: { kind: 'content' },
	shortcode: { kind: 'content' },
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

/** Words in a string (whitespace-separated runs). */
function wordCount( text: string ): number {
	const clean = text.trim();
	return clean.length === 0 ? 0 : clean.split( /\s+/ ).length;
}

/**
 * Which slots each RENDERED kind actually paints.
 *
 * Mirrors the template's design components (`templates/blog/src/components/design/`
 * read `section.slots` directly). A kind that does not read a slot SILENTLY
 * DROPS its words: eca.directory's “Explore Our Activities” band classified as a
 * `cta`, which renders title + links only, so an icon-box's three paragraphs
 * vanished. Kept as data so the fidelity guard in `classifyRegion` can measure
 * what a component will actually put on the page. An unlisted kind (the
 * structural six) renders its own payload and is never demoted.
 */
const RENDERED_SLOTS: Partial< Record< SectionKind, Array< 'title' | 'body' | 'items' | 'links' | 'media' > > > = {
	hero: [ 'title', 'body', 'media', 'links' ],
	cta: [ 'title', 'links' ],
	'feature-grid': [ 'title', 'body', 'items', 'links' ],
	'icon-list': [ 'title', 'body', 'items', 'media', 'links' ],
	testimonial: [ 'title', 'body', 'items', 'links' ],
	'logo-cloud': [ 'title', 'media', 'items', 'links' ],
	gallery: [ 'title', 'media', 'links' ],
	text: [ 'title', 'body', 'media', 'links' ],
	accordion: [ 'title', 'body', 'media', 'items', 'links' ],
};

/**
 * The words a section's SLOTS carry — optionally restricted to the slots its
 * RENDERED kind will paint.
 *
 * Compared against the band's source text to catch a confident-but-wrong kind:
 * the shape ladder can see a heading and a button, call the band a `cta`, and
 * discard the paragraphs it was never shaped for (see the guard in
 * `classifyRegion`).
 */
function slotWordCount(
	slots: SemanticSection[ 'slots' ],
	rendered: Array< 'title' | 'body' | 'items' | 'links' | 'media' > = [ 'title', 'body', 'items', 'links', 'media' ]
): number {
	const parts: string[] = [];
	if ( rendered.includes( 'title' ) && slots.title ) {
		parts.push( slots.title );
	}
	if ( rendered.includes( 'body' ) ) {
		for ( const paragraph of slots.body ?? [] ) {
			parts.push( paragraph );
		}
	}
	if ( rendered.includes( 'items' ) ) {
		for ( const item of slots.items ?? [] ) {
			if ( item.kicker ) {
				parts.push( item.kicker );
			}
			if ( item.title ) {
				parts.push( item.title );
			}
			if ( item.body ) {
				parts.push( item.body );
			}
			// The card's LIST renders (`.ds-card-bullets`); leaving it out made a band
			// whose words are mostly bullets look lossy to the fidelity guard.
			for ( const bullet of item.bullets ?? [] ) {
				parts.push( bullet );
			}
			if ( item.author ) {
				parts.push( item.author );
			}
		}
	}
	if ( rendered.includes( 'links' ) ) {
		for ( const link of slots.links ?? [] ) {
			if ( link.text ) {
				parts.push( link.text );
			}
		}
	}
	return wordCount( parts.join( ' ' ) );
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

/** A heading with its level — the title picker's input. */
interface HeadingCandidate {
	level: number;
	text: string;
}

/**
 * Pick a band's title from its headings.
 *
 * A builder band routinely carries TWO headings: a small kicker above the real
 * headline. Taking the first one dropped the headline entirely
 * (virtuallysrilanka.com: the hero rendered "SEE WHAT WE CAN DO FOR YOU" and
 * lost the 55px "WE SPECIALIZE IN CUSTOM SOLUTIONS…" sitting right below it;
 * the "How it Works" band rendered its kicker instead of its title). The most
 * important LEVEL wins, and a same-level ALL-CAPS kicker yields to the line
 * under it.
 */
/**
 * A heading ELEMENT of the page.
 *
 * A real `<h1>`–`<h6>`, or a builder heading that renders as a `div`/`span`.
 * Elementor lets the owner pick the HTML tag on a Heading widget ("div" is a
 * common choice when the visual order differs from the document order), and
 * its Icon Box always uses a div — so skimming `h*` alone dropped whole bands'
 * copy (eca.directory's "Compare User Roles" grid and its 4.8 / +1k counters,
 * 2026-11-23). The reported LEVEL is 7 for a div heading: a real heading still
 * wins the band title, and a band made only of div headings still has one.
 */
const isHeadingLike = ( n: HNode ): boolean =>
	n.type === 'tag' &&
	( /^h[1-6]$/.test( n.name ?? '' ) ||
		classList( n.attribs ).some( ( c ) => /^(?:elementor-heading-title|elementor-icon-box-title)$/.test( c ) ) );

const headingLevelOf = ( n: HNode ): number => {
	const tag = /^h([1-6])$/.exec( n.name ?? '' );
	return tag ? Number( tag[ 1 ] ) : 7;
};

function pickHeadingTitle( headings: HeadingCandidate[] ): HeadingCandidate | undefined {
	if ( headings.length === 0 ) {
		return undefined;
	}
	let index = 0;
	for ( let i = 1; i < headings.length; i++ ) {
		if ( headings[ i ].level < headings[ index ].level ) {
			index = i;
		}
	}
	if (
		index === 0 &&
		headings.length > 1 &&
		headings[ 0 ].level === headings[ 1 ].level &&
		isKickerLine( headings[ 0 ].text )
	) {
		index = 1;
	}
	return headings[ index ];
}

/** An ALL-CAPS short line — the kicker that sits above a band title. */
function isKickerLine( text: string ): boolean {
	const letters = text.replace( /[^A-Za-z]/g, '' );
	return letters.length > 0 && letters === letters.toUpperCase() && text.trim().split( /\s+/ ).length <= 6;
}

/** Every heading under a node, in document order. */
function headingCandidatesIn( node: HNode ): HeadingCandidate[] {
	const out: HeadingCandidate[] = [];
	for ( const heading of allDescendants( node, isHeadingLike ) ) {
		const text = trimmed( textOf( heading ) );
		if ( text ) {
			out.push( { level: headingLevelOf( heading ), text } );
		}
	}
	return out;
}

/** The collected slots plus the internal title level (never serialized). */
type CollectedSlots = SemanticSection['slots'] & { titleLevel?: number };

/** Collect imgs/links/headings/paragraphs inside a widget node. */
function collectSlots( node: HNode ): CollectedSlots {
	const slots: CollectedSlots = {};
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

	const isHeading = isHeadingLike;
	const headings = headingCandidatesIn( node );
	const title = pickHeadingTitle( headings );
	const titleText = title?.text ?? '';
	if ( titleText ) {
		slots.title = titleText;
		slots.titleLevel = title?.level;
	}
	// A second heading in the same widget is copy, not decoration.
	const extraHeadings = headings.map( ( heading ) => heading.text ).filter( ( text ) => text !== titleText );
	const isParagraph = ( n: HNode ) => n.type === 'tag' && n.name === 'p';
	for ( const extra of extraHeadings ) {
		if ( ! body.includes( extra ) ) {
			body.push( extra );
		}
	}
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

function extractStructuredItem( node: HNode ): { title?: string; titleLevel?: number; body?: string; bullets?: string[]; media?: MediaRef; icon: boolean; iconClass?: string; layout?: 'row' | 'stack' } | undefined {
	// h3–h6 or a DIV heading are CARD titles. An h1/h2 inside a widget is the
	// band's own heading — treating it as a card title hid the band title from
	// every caller that reads `slots.title`.
	const headings = allDescendants(
		node,
		( n ) =>
			n.type === 'tag' &&
			( /^h[3-6]$/.test( n.name ?? '' ) ||
				( ! /^h[1-6]$/.test( n.name ?? '' ) &&
					classList( n.attribs ).some( ( c ) => /^(?:elementor-heading-title|elementor-icon-box-title)$/.test( c ) ) ) )
	);
	if ( headings.length === 0 ) {
		return undefined;
	}
	// The header-box class match must not hit the widget's own class
	// (elementor-widget-icon-box contains 'icon') — only real icon containers:
	// aux's `aux-ico-box` family and Elementor's `elementor-icon-box-icon`.
	// `elementor-icon-box-wrapper` is NOT one of them: it wraps the icon AND the
	// title AND the description, so treating it as the header dropped every
	// card's copy (virtuallysrilanka.com's four icon-boxes rendered as bare
	// titles — band text coverage 1.2%).
	const headerBoxes = allDescendants( node, ( n ) => n.type === 'tag' && classList( n.attribs ).some( ( c ) => /ico-box|icon-box-icon/i.test( c ) ) );
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
	// A list INSIDE the card (see `SectionItem.bullets`) — the entries are the
	// card's copy, never sibling cards.
	const bullets = bulletItems( node );
	return { title, titleLevel: headingLevelOf( headings[ 0 ] ) <= 6 ? headingLevelOf( headings[ 0 ] ) : undefined, body, bullets, media, icon, iconClass, layout };
}

/**
 * One item per entry of a builder ICON LIST.
 *
 * `<ul class="elementor-icon-list-items"><li class="elementor-icon-list-item">
 * <a><span class="elementor-icon-list-icon"><i …></i></span>
 * <span class="elementor-icon-list-text">Branding &amp; SSO setup</span>
 * </a></li>…`
 *
 * The flat slot merge saw the widget as an item widget with no heading, so
 * every entry became an EMPTY item — the single largest text loss on
 * eca.directory (the phased-delivery lists: 17 entries gone, 2026-11-23).
 */
function iconListItems( node: HNode ): SectionItem[] | undefined {
	const entries = allDescendants(
		node,
		( n ) => n.type === 'tag' && n.name === 'li' && classList( n.attribs ).some( ( c ) => /icon-list-item/.test( c ) )
	);
	if ( entries.length === 0 ) {
		return undefined;
	}
	const items: SectionItem[] = [];
	for ( const entry of entries ) {
		const label = trimmed( textOf( entry ) );
		if ( ! label ) {
			continue;
		}
		const glyph = findDescendant( entry, ( n ) => n.type === 'tag' && ( n.name === 'i' || n.name === 'svg' ) );
		// The icon makes the KIND `icon-list` on a pure list; a mixed band stays a
		// feature grid. The entry's own text is the copy either way.
		items.push( {
			title: label,
			...( glyph ? { icon: true } : {} ),
			...( glyph ? { iconClass: glyphClassesOf( glyph ) } : {} ),
		} );
	}
	return items.length > 0 ? items : undefined;
}

/**
 * The BULLETS a card carries (see `SectionItem.bullets`).
 *
 * Only a real list counts: a `<ul>`/`<ol>` with at least one `<li>`, and not a
 * navigation menu, breadcrumb row, pagination row or social row — chrome the
 * card's copy must never absorb (a mega-menu's entries would print as bullet
 * copy). Entries are de-duplicated and length-capped: a bullet is copy, not a
 * paragraph.
 */
function bulletItems( node: HNode ): string[] | undefined {
	const chrome = allDescendants(
		node,
		( n ) =>
			n.type === 'tag' &&
			( n.name === 'nav' ||
				( n.attribs?.role ?? '' ) === 'navigation' ||
				classList( n.attribs ).some( ( c ) => /(?:^|[-_])(?:menu|nav|breadcrumb|pagination|social)(?:[-_]|$)/i.test( c ) ) )
	);
	const bullets: string[] = [];
	for ( const list of allDescendants( node, ( n ) => n.type === 'tag' && ( n.name === 'ul' || n.name === 'ol' ) ) ) {
		if ( chrome.some( ( ancestor ) => within( list, ancestor ) ) ) {
			continue;
		}
		for ( const entry of directChildren( list ) ) {
			if ( entry.type !== 'tag' || entry.name !== 'li' ) {
				continue;
			}
			const text = trimmed( textOf( entry ) );
			if ( ! text || text.length > 160 || bullets.includes( text ) ) {
				continue;
			}
			bullets.push( text );
		}
	}
	return bullets.length > 0 ? bullets : undefined;
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

/**
 * An accordion's (title, body) entries — Elementor's tab contract.
 *
 * The tab title carries `aria-controls` pointing at its panel, so the pair
 * survives even when the panels are siblings rather than children.
 */
function accordionEntries( node: HNode ): SectionItem[] {
	const titles = titlesOfAccordion( node );
	const items: SectionItem[] = [];
	for ( const titleNode of titles ) {
		// The wrapper also matches `elementor-accordion-title` on the <a>; take
		// the element that actually owns the label.
		const label = trimmed( textOf( titleNode ) );
		if ( ! label ) {
			continue;
		}
		const panel = accordionPanelOf( node, titleNode );
		const panelText = panel ? trimmed( textOf( panel ) ) : '';
		if ( ! panelText ) {
			items.push( { title: label } );
			continue;
		}
		// The panel's opening `<strong>` is a LEAD-IN, not emphasis: the source
		// bolds the first clause (`Advertise Your Business`) and leaves the rest
		// plain. Kept as a separate plain-text fact so the body stays a string and
		// the template renders the emphasis itself.
		const lead = panel ? leadingStrongOf( panel, panelText ) : undefined;
		items.push( lead ? { title: label, body: panelText.slice( lead.length ).trim(), lead } : { title: label, body: panelText } );
	}
	return items;
}

/**
 * The `<strong>`/`<b>` run that OPENS a copy block, as plain text.
 *
 * Only a prefix qualifies: an emphasised phrase mid-paragraph is emphasis, not
 * the block's lead-in, and splitting there would print the sentence twice.
 */
function leadingStrongOf( panel: HNode, text: string ): string | undefined {
	const bold = findDescendant( panel, ( n ) => n.type === 'tag' && ( n.name === 'strong' || n.name === 'b' ) );
	const lead = bold ? trimmed( textOf( bold ) ) : '';
	if ( ! lead || lead === text || ! text.startsWith( lead ) ) {
		return undefined;
	}
	return lead;
}

function titlesOfAccordion( node: HNode ): HNode[] {
	// Elementor marks BOTH the tab container (`elementor-tab-title`) and its
	// inner anchor (`elementor-accordion-title`); matching both counted every
	// panel twice, so a one-entry accordion looked like a two-item one.
	const titles = allDescendants( node, ( n ) => n.type === 'tag' && classList( n.attribs ).includes( 'elementor-tab-title' ) );
	if ( titles.length > 0 ) {
		return titles;
	}
	return allDescendants( node, ( n ) => n.type === 'tag' && classList( n.attribs ).includes( 'elementor-accordion-title' ) );
}

/** The panel that belongs to a tab title. */
function accordionPanelOf( node: HNode, titleNode: HNode ): HNode | undefined {
	// The sanitizer keeps `id` but drops `aria-controls`, so the id reference
	// resolves only on unsanitized HTML — the structural walk is the reliable
	// path (the panel is inside the same `.elementor-accordion-item`).
	const controls = titleNode.attribs?.[ 'aria-controls' ];
	if ( controls ) {
		const byId = findDescendant( node, ( n ) => n.attribs?.id === controls );
		if ( byId ) {
			return byId;
		}
	}
	let current = titleNode.parent ?? undefined;
	while ( current ) {
		if ( classList( current.attribs ).includes( 'elementor-accordion-item' ) ) {
			return findDescendant( current, ( n ) => classList( n.attribs ).includes( 'elementor-tab-content' ) );
		}
		current = current.parent ?? undefined;
	}
	return undefined;
}

/**
 * Elementor's CONTAINER model, named once (4.x flexbox + the legacy grid).
 *
 * Elementor 4.x publishes a band as a stack of flex containers —
 * `div.e-con.e-parent > .e-con-inner > .e-con-full.e-child` — and each CARD is
 * itself a container (`e-con-full e-child`). The pre-4.x model is the same idea
 * under different names (`.elementor-container > .elementor-column`), and a
 * builder that migrated halfway carries BOTH in one page.
 *
 * Before this, three consumers re-derived the vocabulary from class strings and
 * disagreed: `columnItems` only knew the legacy pair, and `cardContainerItems`
 * carried its own regex plus a private card predicate. The symptoms were a band
 * grouped by ROWS instead of cards (2 items for 5) and a comparison table's
 * cells read as cards. One model, one place to fix the next shape.
 *
 * A ROW is ≥2 sibling containers of the same SHAPE — the shape signature drops
 * `elementor-element-*`/`elementor-col-*`, so two cards built in different
 * sessions still match. A CARD is a container with a heading AND a list; that
 * is what separates the three phase cards from a matrix cell (heading only) and
 * from an intro row (a single child).
 */

/** Every class token that marks a container in either model. */
const CONTAINER_TOKENS = /^(?:e-con|e-con-inner|e-con-full|e-con-boxed|e-child|elementor-column|elementor-container)$/;

/** A heading, or a builder's heading element (Elementor uses a `div`). */
export function isHeadingNode( node: HNode ): boolean {
	return (
		node.type === 'tag' &&
		( /^h[1-6]$/.test( node.name ?? '' ) ||
			classList( node.attribs ).some( ( name ) => /^(?:elementor-heading-title|elementor-icon-box-title)$/.test( name ) ) )
	);
}

/** Is this node a layout container (flexbox or legacy grid)? */
export function isContainerNode( node: HNode ): boolean {
	return node.type === 'tag' && classList( node.attribs ).some( ( name ) => CONTAINER_TOKENS.test( name ) );
}

/** The legacy row wrapper (`.elementor-container`). */
export function isLegacyRow( node: HNode ): boolean {
	return node.type === 'tag' && classList( node.attribs ).includes( 'elementor-container' );
}

/** A legacy column (`.elementor-column`) — a direct child of `isLegacyRow`. */
export function isLegacyColumn( node: HNode ): boolean {
	return node.type === 'tag' && classList( node.attribs ).includes( 'elementor-column' );
}

/**
 * A container's SHAPE: its class tokens minus the per-element identity.
 *
 * `elementor-element-<id>` is unique per instance and `elementor-col-*` encodes
 * a width, so keeping either would give every card its own shape and the row
 * would never group.
 */
export function containerShape( node: HNode ): string {
	return classList( node.attribs )
		.filter( ( name ) => ! /^elementor-(?:element-|col-)/.test( name ) )
		.sort()
		.join( ' ' );
}

/** The card's own heading — the icon-box title wins over a plain heading above it. */
export function containerHeadingNode( node: HNode ): HNode | undefined {
	return findDescendant( node, isHeadingNode );
}

/** A CARD: a container carrying a heading AND a list (see the model note). */
export function isCardContainer( node: HNode ): boolean {
	return (
		Boolean( containerHeadingNode( node ) ) &&
		Boolean( findDescendant( node, ( child ) => child.type === 'tag' && ( child.name === 'ul' || child.name === 'ol' ) ) )
	);
}

/**
 * The ROWS of a band: sibling container groups of ≥2 members sharing a shape.
 *
 * Groups, not children: a band can carry several rows (an intro row and a card
 * row) with different shapes, and a nested row is its own group. Document order
 * is preserved — the caller indexes cards by it.
 */
export function containerRows( scope: HNode ): HNode[][] {
	const rows: HNode[][] = [];
	for ( const parent of allDescendants( scope, isContainerNode ) ) {
		const kids = directChildren( parent ).filter( isContainerNode );
		if ( kids.length < 2 ) {
			continue;
		}
		const byShape = new Map< string, HNode[] >();
		for ( const kid of kids ) {
			const shape = containerShape( kid );
			if ( ! shape ) {
				continue;
			}
			byShape.set( shape, [ ...( byShape.get( shape ) ?? [] ), kid ] );
		}
		for ( const members of byShape.values() ) {
			if ( members.length >= 2 ) {
				rows.push( members );
			}
		}
	}
	return rows;
}

/**
 * One item per SOURCE COLUMN, when a band spreads its content across columns.
 *
 * A multi-column band has no flat equivalent: concatenating the columns gave
 * one long text band (virtuallysrilanka's services band — a red panel, a photo
 * and a dark copy panel — rebuilt as a single stacked column with the copy
 * pushed off the right edge). Returns undefined unless at least two columns
 * carry content, so an ordinary one-column band keeps its old path.
 */
function columnItems( section: HNode ): { items: SectionItem[]; lead: string[] } | undefined {
	const container = findDescendant( section, isLegacyRow );
	if ( ! container ) {
		return undefined;
	}
	const columns = childrenOf( container ).filter( isLegacyColumn );
	if ( columns.length < 2 ) {
		return undefined;
	}
	const items: SectionItem[] = [];
	let droppedPlaceholder = false;
	for ( const [ columnIndex, column ] of columns.entries() ) {
		const collected = collectSlots( column );
		// The LANE index rides every item, including the ones after a skipped lane:
		// `design.sections[].columns` is keyed by this index and carries that
		// lane's surface (virtuallysrilanka's services band is red / photo / black).
		const item: SectionItem = { columnIndex };
		if ( collected.title ) {
			item.title = collected.title;
		}
		if ( collected.titleLevel ) {
			item.titleLevel = collected.titleLevel;
		}
		if ( ( collected.body ?? [] ).length > 0 ) {
			item.body = ( collected.body ?? [] ).join( ' ' );
			// Two or more blocks are PARAGRAPHS, not one sentence run together: the
			// card renders them separately so the source's inter-paragraph rhythm
			// survives.
			if ( ( collected.body ?? [] ).length > 1 ) {
				item.paragraphs = collected.body;
			}
		}
		if ( ( collected.media ?? [] ).length > 0 ) {
			item.media = collected.media?.[ 0 ];
		}
		if ( ( collected.links ?? [] ).length > 0 ) {
			item.links = collected.links;
		}
		// A list inside the card (see `SectionItem.bullets`). This is where
		// eca.directory's phased-delivery entries were lost: the entries are
		// neither the card's title nor its `<p>` copy.
		const bullets = bulletItems( column );
		if ( bullets ) {
			item.bullets = bullets;
		}
		if ( ! item.title && ! item.body && ! item.media ) {
			// A column with no copy and no media (a photo painted as a CSS
			// background, a spacer) has no rebuildable content, so no item is
			// emitted — but its LANE still exists in the profile's `columns`, and
			// the grid places the remaining items by `columnIndex` and paints that
			// lane's surface. Skipping the item must not renumber the lanes.
			continue;
		}
		if ( isPlaceholderCard( item ) ) {
			// WordPress's default "Hello world!" post, rendered by the source's
			// own posts widget. The lane keeps its index like any skipped column.
			droppedPlaceholder = true;
			continue;
		}
		items.push( item );
	}
	// A single surviving card is still a column grid WHEN one was removed here:
	// falling back to the raw band HTML would put the placeholder straight back
	// (the sanitized markup still carries the source's posts widget).
	if ( ! ( items.length >= 2 || ( droppedPlaceholder && items.length >= 1 ) ) ) {
		return undefined;
	}
	// Copy that sits OUTSIDE the columns — the band's own LEDE, above the grid.
	// The caller replaces `slots` with the items, so without this the sentence
	// introducing the cards is thrown away (eca.directory's phased-delivery band,
	// 2026-11-24). Paragraphs a card already carries are excluded so the lede
	// never repeats the cards.
	const carried = new Set(
		items.flatMap( ( item ) => [ item.body ?? '', ...( item.paragraphs ?? [] ), ...( item.bullets ?? [] ) ] ).filter( Boolean )
	);
	const lead = allDescendants( section, ( n ) => n.type === 'tag' && n.name === 'p' && ! within( n, container ) )
		.map( ( n ) => trimmed( textOf( n ) ) )
		.filter( ( text ) => text && ! carried.has( text ) );
	return { items, lead };
}

/**
 * One item per CARD CONTAINER of a band.
 *
 * Elementor 4.x publishes each card as its own flex container
 * (`div.e-con-full.e-child`), not as a `.elementor-column`, so `columnItems`
 * cannot see the band at all:
 *
 * ```
 * e-con-inner                    (the band)
 * ├─ e-con-boxed.e-child        (intro row: h2 + icon-box)
 * ├─ e-con-full.e-child         (card row)
 * │   ├─ e-con-full.e-child     (CARD: h3 label + h4 title + p + ul ×4 + a)
 * │   └─ …
 * └─ e-con-full.e-child         (comparison table — a grid of CELLS)
 * ```
 *
 * A CARD is a container with a heading AND a LIST. That is what separates the
 * three phase cards from the comparison table: a table cell holds a heading and
 * nothing else (or a mark), a row of cells is not a row of cards, and the intro
 * row is a single child. Without the predicate, grouping took the ROWS for cards
 * and produced 2 items for 5.
 *
 * Items carry `columnIndex`, so the laned grid renders exactly the cards the
 * source laid out.
 */
function cardContainerItems( section: HNode ): { items: SectionItem[]; lead: string[] } | undefined {
	// One row per shape-sharing sibling group (see `containerRows`), keeping the
	// groups whose members are all CARDS. The comparison table's cells fail
	// `isCardContainer` (a heading and nothing else), so the matrix is never
	// mistaken for a card row.
	const buckets = containerRows( section ).filter( ( members ) => members.every( isCardContainer ) );
	if ( buckets.length === 0 ) {
		return undefined;
	}

	const headingsOf = ( card: HNode ): HNode[] => allDescendants( card, isHeadingNode );

	const items: SectionItem[] = [];
	for ( const bucket of buckets ) {
		for ( const [ columnIndex, card ] of bucket.entries() ) {
			const headings = headingsOf( card );
			// The visual TITLE is the icon-box heading when the card has one; the
			// plain heading above it is a KICKER (“Phase 1” over “Pilot Setup”).
			const titleNode =
				headings.find( ( n ) => classList( n.attribs ).some( ( c ) => /icon-box-title/.test( c ) ) ) ?? headings[ 0 ];
			const title = titleNode ? trimmed( textOf( titleNode ) ) : '';
			if ( ! title ) {
				continue;
			}
			const bullets = bulletItems( card ) ?? [];
			// A heading that comes BEFORE the title is the card's KICKER (“Phase 1”
			// over “Pilot Setup”); one after it is copy. Printing the kicker after the
			// title breaks the source's word order.
			const titleIndex = titleNode ? headings.indexOf( titleNode ) : -1;
			const label = ( n: HNode ): string => trimmed( textOf( n ) );
			const usable = ( text: string ): boolean => Boolean( text ) && text !== title && ! bullets.includes( text );
			const kicker = ( titleIndex > 0 ? headings.slice( 0, titleIndex ) : [] ).map( label ).filter( usable ).join( ' ' );
			const others = ( titleIndex >= 0 ? headings.slice( titleIndex + 1 ) : headings ).map( label ).filter( usable );
			const paragraphs = allDescendants( card, ( n ) => n.type === 'tag' && n.name === 'p' )
				.map( ( n ) => trimmed( textOf( n ) ) )
				.filter( ( text ) => text && text !== title && ! bullets.includes( text ) );
			const body = [ ...new Set( [ ...others, ...paragraphs ] ) ];
			const anchor = findDescendant(
				card,
				( n ) => n.type === 'tag' && n.name === 'a' && Boolean( trimmed( attrOf( n, 'href' ) ?? '' ) ) && Boolean( trimmed( textOf( n ) ) )
			);
			const href = anchor ? trimmed( attrOf( anchor, 'href' ) ?? '' ) : '';
			const item: SectionItem = {
				title,
				columnIndex,
				...( kicker ? { kicker } : {} ),
				...( titleNode && headingLevelOf( titleNode ) <= 6 ? { titleLevel: headingLevelOf( titleNode ) } : {} ),
				...( body.length > 0 ? { body: body.join( ' ' ), ...( body.length > 1 ? { paragraphs: body } : {} ) } : {} ),
				...( bullets.length > 0 ? { bullets } : {} ),
				...( anchor && href && ! href.startsWith( '#' ) ? { links: [ { text: trimmed( textOf( anchor ) ), href } ] } : {} ),
			};
			items.push( item );
		}
	}
	if ( items.length < 2 ) {
		return undefined;
	}
	// Copy that sits OUTSIDE the cards — the band’s own lede, above the grid. The
	// flat merge leaves it inside a card-like item instead, so the intro printed
	// as a card and the comparison table’s headings became the lede
	// (eca.directory, 2026-11-24).
	const cardNodes = buckets.flat();
	const lead = [
		...new Set(
			allDescendants( section, ( n ) => n.type === 'tag' && n.name === 'p' && ! cardNodes.some( ( card ) => within( n, card ) ) )
				.map( ( n ) => trimmed( textOf( n ) ) )
				.filter( Boolean )
		),
	];
	return { items, lead };
}

/**
 * WordPress ships one placeholder post and one placeholder page on every
 * install — "Hello world!" (slug `hello-world`) and "Sample Page"
 * (`sample-page`) — and every setup checklist tells the owner to delete them.
 *
 * A rebuild cannot delete CMS content, and a builder's "Latest posts" widget
 * renders whatever the CMS returns, so a dropped-in card titled "Hello world!"
 * published as if it were the school's only story (eca.directory's updates
 * band, 2026-11-24). Drop the CARD, not the record: the post keeps its route,
 * so removing the placeholder in the CMS is still all it takes for the card to
 * come back.
 *
 * Matching is on the TITLE only and only for a card with no body, media or
 * attribution — an article that quotes "Hello world!" is copy, not a placeholder.
 */
const PLACEHOLDER_TITLES = new Set( [ 'hello world', 'sample page' ] );

function isPlaceholderCard( item: SectionItem ): boolean {
	if ( item.body || item.media || item.author ) {
		return false;
	}
	const title = ( item.title ?? '')
		.toLowerCase()
		.replace( /\s+/g, ' ' )
		// "Hello world!" also arrives as "Hello world" and "Hello, world!"
		// depending on the WP version and locale.
		.replace( /^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '' );
	return title.length > 0 && PLACEHOLDER_TITLES.has( title );
}

/** Merge widget slots into one section draft. */
function sectionFromWidgets( widgets: WidgetLike[], index: number, section?: HNode ): SemanticSection {
	const slots: SemanticSection['slots'] = {};
	const media: MediaRef[] = [];
	const links: LinkRef[] = [];
	const body: string[] = [];
	const items: SectionItem[] = [];
	const headingCandidates: HeadingCandidate[] = [];
	const accordionItems: SectionItem[] = [];
	const confidence: 'high' | 'medium' | 'low' = 'high';

	for ( const widget of widgets ) {
		// An accordion owns its own copy: its titles must never become the
		// band's links and its panels must never join the flat body.
		if ( widget.role.kind === 'accordion' ) {
			const entries = accordionEntries( widget.node );
			if ( entries.length >= 2 ) {
				accordionItems.push( ...entries );
			}
			continue;
		}
		// Structured icon-text widgets (an icon/image header + a small heading)
		// are single-column feature cards — extract them as items BEFORE the
		// flat slot merge, which loses the captions and flattens the columns.
		const iconList = iconListItems( widget.node );
		if ( iconList ) {
			items.push( ...iconList );
			continue;
		}
		const structured =
			widget.role.kind === 'body' ||
			widget.role.kind === 'content' ||
			widget.role.kind === 'feature' ||
			// A query loop's REAL cards are still authored markup — `list` only adds
			// the signal, it must not skip the extractor (a genuine post card was
			// dropped the first time this role was added).
			widget.role.kind === 'list'
				? extractStructuredItem( widget.node )
				: undefined;
		if ( structured ) {
			const item: SectionItem = { title: structured.title };
			if ( structured.titleLevel ) {
				item.titleLevel = structured.titleLevel;
			}
			if ( structured.body ) {
				item.body = structured.body;
			}
			if ( structured.bullets ) {
				item.bullets = structured.bullets;
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
		// Item widgets (feature/icon/quote) carry their own headings — those are
		// card captions, never the band title. An `h1` is the exception wherever it
		// sits: eca.directory's hero publishes the page's only h1 inside an icon-box
		// (`elementor-icon-box-title`), so treating the widget as a card demoted it
		// to an `<h3>` inside a grid and titled the band after a rating label
		// ("Parent ratings") — the page had no h1 at all (2026-11-24).
		const isItemWidget =
			widget.role.kind === 'feature' || widget.role.kind === 'icon-item' || widget.role.kind === 'quote';
		const isBandHeading = collected.titleLevel === 1;
		if ( collected.title && ( ! isItemWidget || isBandHeading ) ) {
			headingCandidates.push( { level: collected.titleLevel ?? 6, text: collected.title } );
		}
		for ( const paragraph of collected.body ?? [] ) {
			if ( ! body.includes( paragraph ) ) {
				body.push( paragraph );
			}
		}
		media.push( ...( collected.media ?? [] ) );
		links.push( ...( collected.links ?? [] ) );
		if ( ( widget.role.kind === 'feature' || widget.role.kind === 'icon-item' || widget.role.kind === 'quote' ) && ! isBandHeading ) {
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
			// An item with no title, copy or media is an empty CARD in the rebuild
			// (a layout-only widget, a heading the extractor already claimed) — a
			// third of eca.directory's phased-delivery grid rendered as blanks. The
			// band's own text is unaffected; only the empty cell is dropped.
			if ( item.title || item.body || item.media || item.author ) {
				items.push( item );
			}
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
		// One guard covers EVERY item path (structured extract, icon list, posts
		// widget): WordPress's placeholder post must never publish as a card.
		// Placed here because the same band reaches `items` through three
		// different branches and a per-branch check had already missed two.
		const published = items.filter( ( item ) => ! isPlaceholderCard( item ) );
		if ( published.length !== items.length ) {
			slots.items = published.length > 0 ? published : undefined;
		} else {
			slots.items = items;
		}
	}
	const title = pickHeadingTitle( headingCandidates );
	if ( title ) {
		slots.title = title.text;
		// The level rides along so the renderer can keep an `h1` where the page
		// owns one (the front page) instead of relying on measured facts that a
		// maintenance-mode analysis never captured.
		slots.titleLevel = title.level;
	}
	// Every heading that is not the title is still the source's copy — it ships
	// as the band's intro text so no word is lost (and the title gate stays
	// green) without changing how the band is classified.
	const extraHeadings = headingCandidates.map( ( heading ) => heading.text ).filter( ( text ) => text !== title?.text );

	// A query loop (Posts/Portfolio) — see `SemanticSection.dynamicList`.
	const dynamicList = widgets.some( ( widget ) => widget.role.kind === 'list' );
	const hasBody = ( slots.body?.length ?? 0 ) > 0;
	// Unknown-name widgets drop the confidence — the shape decided the kind,
	// not a recognized vocabulary entry (Proposal 116).
	const effectiveConfidence = widgets.some( ( widget ) => widget.role.kind === 'content' ) ? 'low' : confidence;
	const kinds: Array<{ kind: SectionKind; reason: boolean }> = [
		// An accordion is decided by its own component contract — the band's
		// headings and media still ride along as the intro.
		{ kind: 'accordion', reason: accordionItems.length >= 2 },
		{ kind: 'logo-cloud', reason: media.length >= 3 && ! hasBody && items.length === 0 },
		{ kind: 'gallery', reason: media.length >= 2 && ! slots.title && ! hasBody },
		{ kind: 'testimonial', reason: items.length > 0 && items.some( ( item ) => Boolean( item.body ) ) && ! slots.title && ! items.some( ( item ) => Boolean( item.title ) ) },
		{ kind: 'icon-list', reason: items.length >= 2 && items.every( ( item ) => Boolean( item.icon ) ) },
		{ kind: 'feature-grid', reason: items.length >= 2 },
		{
			// A single item is the band's own copy (a lone icon-box carrying the
			// heading and lede), not a card row. Requiring ZERO items kept every CTA
			// with a supporting sentence out of the `cta` kind, and raw builder markup
			// ships no vendor CSS (eca.directory's blog CTA, 2026-11-25).
			//
			// A LEADING band that also states copy is the page's hero, which keeps the
			// h1 and its media; a leading title + button with no copy is still a CTA.
			kind: 'cta',
			reason:
				Boolean( slots.title ) &&
				links.length > 0 &&
				items.length <= 1 &&
				media.length === 0 &&
				( index > 0 || ! hasBody ),
		},
		{ kind: 'hero', reason: Boolean( slots.title ) && ( hasBody || media.length > 0 ) && ( index === 0 || ( links.length > 0 && media.length > 0 ) ) },
		{ kind: 'text', reason: true },
	];
	const matched = kinds.find( ( entry ) => entry.reason ) ?? kinds[ kinds.length - 1 ];
	// Extras land AFTER classification, so a kicker never turns a CTA into a
	// hero. Media-only kinds keep no prose (their slots ignore body).
	if ( extraHeadings.length > 0 && matched.kind !== 'logo-cloud' && matched.kind !== 'gallery' ) {
		slots.body = [ ...extraHeadings.filter( ( text ) => ! ( slots.body ?? [] ).includes( text ) ), ...( slots.body ?? [] ) ];
	}
	if ( matched.kind === 'accordion' ) {
		slots.items = accordionItems;
		return { kind: 'accordion', confidence: effectiveConfidence, slots, ...( dynamicList ? { dynamicList: true } : {} ) };
	}
	// Cards that are CONTAINERS (Elementor 4.x flex) — see `cardContainerItems`.
	// Runs before the text→columns fallback: such a band has no
	// `.elementor-container` for that path to find.
	if ( section && ( matched.kind === 'feature-grid' || matched.kind === 'icon-list' || matched.kind === 'text' ) ) {
		const cards = cardContainerItems( section );
		if ( cards ) {
			// The flat merge put the cards’ captions in `body`; they live in the items
			// now, so the body keeps the copy no card carries — the band’s own lede.
			const carried = new Set(
				cards.items
					.flatMap( ( item ) => [ item.kicker ?? '', item.title ?? '', item.body ?? '', ...( item.paragraphs ?? [] ), ...( item.bullets ?? [] ) ] )
					.filter( Boolean )
			);
			const body = [
				...cards.lead,
				// Copy the flat merge found but no card carries and the lead does not
				// repeat — the comparison table’s headings, when a band mixes a card row
				// with a table. Losing them demoted the band to raw text once already.
				...( slots.body ?? [] ).filter( ( text ) => ! carried.has( text ) && ! cards.lead.includes( text ) ),
			];
			// Flat items no card carries are still the band’s content — the comparison
			// table that shares this band, for instance. Keeping them means no word is
			// lost: when they were dropped the fidelity guard demoted the whole band to
			// raw text (2026-11-24).
			const extras = ( slots.items ?? [] ).filter( ( item ) => {
				if ( item.columnIndex !== undefined ) {
					return false;
				}
				const text = [ item.title ?? '', item.body ?? '', ...( item.paragraphs ?? [] ), ...( item.bullets ?? [] ) ].filter( Boolean );
				// The band already renders its own title and lede — an extra that repeats
				// them would print the intro twice.
				const own = new Set( [ slots.title ?? '', ...( body ) ].filter( Boolean ) );
				if ( text.some( ( value ) => own.has( value ) ) ) {
					return false;
				}
				return text.every( ( value ) => ! carried.has( value ) );
			} );
			// An extra is a ROW LABEL of a comparison table that shares this band. It
			// is copy, not a card: rendered as a card it printed as a 35px heading in
			// the phase grid (eca.directory, 2026-11-24). Fold the labels into the
			// band's text so no word is lost and the grid stays cards-only.
			const extraLines = extras.map( ( item ) => item.title ?? '' ).filter( Boolean );
			const finalBody = [ ...body, ...extraLines.filter( ( text ) => ! body.includes( text ) ) ];
			return {
				kind: 'feature-grid',
				confidence: 'high',
				slots: {
					...( slots.title ? { title: slots.title } : {} ),
					...( slots.titleLevel ? { titleLevel: slots.titleLevel } : {} ),
					...( finalBody.length > 0 ? { body: finalBody } : {} ),
					items: cards.items,
				},
			};
		}
	}
	// A band the flat merge could only describe as one text blob, whose source
	// spreads its copy across columns, rebuilds as a column grid instead.
	if ( matched.kind === 'text' && section ) {
		const columns = columnItems( section );
		if ( columns ) {
			return {
				kind: 'feature-grid',
				confidence: 'low',
				slots: {
					...( slots.title ? { title: slots.title } : {} ),
					...( columns.lead.length > 0 ? { body: columns.lead } : {} ),
					items: columns.items,
				},
			};
		}
	}
	return { kind: matched.kind, confidence: effectiveConfidence, slots, ...( dynamicList ? { dynamicList: true } : {} ) };
}

// -- structural kinds (Proposal 136 §4.1) -------------------------------------
//
// The six 136 kinds are decided by EVIDENCE, never by a class token alone: a
// band that merely mentions a price stays a feature grid; a pricing band has
// two or more priced plans. Detection order matters — comparison (a table) and
// steps (an ordered list) are unambiguous; pricing runs before stats so a
// priced plan never falls through to a metric; marquee runs last because a
// repeated track is the weakest evidence.
//
// Everything here is FACT extraction from the already-sanitized DOM — text,
// hrefs, image srcs. No markup is copied and nothing is synthesized.

const PRICE_TEXT = /[$€£¥₹]\s?\d|\b\d[\d.,]*\s?(?:\/|per\b)/i;
const PRICE_TOKEN = /[$€£¥₹]\s?\d[\d.,]*(?:\s?(?:\/|per)\s?[a-z]+)?/i;
const STAT_VALUE = /^[\d.,]+\s*[%+kKmMx]?$/i;
const STEP_INTENT = /step|process|timeline|how-?to|roadmap/i;
const TEAM_INTENT = /(^|[-_])team|person|member|staff|people/i;
const PLAN_INTENT = /price|plan|package|tier|pricing/i;
const PLAN_FEATURED = /featured|popular|highlight|recommend|best/i;
const STAT_INTENT = /counter|stat|metric|odometer|number|figure/i;
const MARQUEE_INTENT = /marquee|ticker|logo-carousel|logo-marquee|client-strip/i;

/** Every tag descendant (depth-first), excluding the node itself. */
function descendantTags( node: HNode, out: HNode[] = [] ): HNode[] {
	for ( const child of childrenOf( node ) ) {
		if ( child.type === 'tag' ) {
			out.push( child );
			descendantTags( child, out );
		}
	}
	return out;
}

function tagsNamed( node: HNode, name: string ): HNode[] {
	return descendantTags( node ).filter( ( tag ) => tag.name === name );
}

function directChildren( node: HNode ): HNode[] {
	return childrenOf( node ).filter( ( child ) => child.type === 'tag' );
}

function ownsClass( node: HNode, pattern: RegExp ): boolean {
	return classList( node.attribs ).some( ( token ) => pattern.test( token ) );
}

function attrOf( node: HNode, name: string ): string | undefined {
	const value = node.attribs?.[ name ];
	return value === undefined || value === '' ? undefined : value;
}

function firstHeading( node: HNode ): HNode | undefined {
	return descendantTags( node ).find( ( tag ) => typeof tag.name === 'string' && /^h[1-6]$/.test( tag.name ) );
}

function firstImageSrc( node: HNode ): string | undefined {
	const image = tagsNamed( node, 'img' )[ 0 ];
	return image ? attrOf( image, 'src' ) : undefined;
}

/** The text of the next tagged sibling of `target` inside `root`. */
function nextSiblingText( root: HNode, target: HNode ): string | undefined {
	for ( const parent of [ root, ...descendantTags( root ) ] ) {
		const kids = directChildren( parent );
		const index = kids.indexOf( target );
		if ( index >= 0 && kids[ index + 1 ] ) {
			const text = trimmed( textOf( kids[ index + 1 ] ) );
			if ( text ) {
				return text;
			}
		}
	}
	return undefined;
}

/**
 * A comparison table: `<table>` whose header carries a label column plus two or
 * more value columns, and at least two body rows. Fewer than three header cells
 * is ambiguous (a two-column table may be a plain spec list), so it is not a
 * comparison band.
 */
function comparisonFrom( node: HNode ): SemanticSection['comparison'] | undefined {
	const table = tagsNamed( node, 'table' )[ 0 ];
	if ( ! table ) {
		return undefined;
	}
	const cellsOf = ( row: HNode ) => directChildren( row ).filter( ( cell ) => cell.name === 'th' || cell.name === 'td' );
	const rows = tagsNamed( table, 'tr' );
	if ( rows.length < 3 ) {
		return undefined;
	}
	const headers = cellsOf( rows[ 0 ] ).map( ( cell ) => trimmed( textOf( cell ) ) );
	// `['', 'Starter', 'Pro']` and `['Feature', 'Starter', 'Pro']` both mean a
	// label column plus two value columns.
	const columns = headers.length >= 3 ? headers.slice( 1 ) : [];
	if ( columns.length < 2 || ! columns.every( Boolean ) ) {
		return undefined;
	}
	const bodyRows: ComparisonRow[] = [];
	for ( const row of rows.slice( 1 ) ) {
		const cells = cellsOf( row ).map( ( cell ) => trimmed( textOf( cell ) ) );
		if ( cells.length < 3 ) {
			continue;
		}
		bodyRows.push( { label: cells[ 0 ], values: cells.slice( 1 ) } );
	}
	if ( bodyRows.length < 2 ) {
		return undefined;
	}
	const caption = tagsNamed( table, 'caption' )[ 0 ];
	const captionText = caption ? trimmed( textOf( caption ) ) : '';
	return { columns, rows: bodyRows, ...( captionText ? { caption: captionText } : {} ) };
}

/**
 * An ordered process list. Intent is required alongside the structure — a plain
 * `<ol>` of prose points in a text band is not a step sequence.
 */
function stepsFrom( node: HNode ): SemanticSection['steps'] | undefined {
	const list = tagsNamed( node, 'ol' )[ 0 ];
	if ( ! list ) {
		return undefined;
	}
	const items = directChildren( list ).filter( ( child ) => child.name === 'li' );
	if ( items.length < 2 ) {
		return undefined;
	}
	const intent = ownsClass( node, STEP_INTENT ) || items.some( ( item ) => Boolean( firstHeading( item ) ) );
	if ( ! intent ) {
		return undefined;
	}
	const steps: StepItem[] = [];
	for ( const item of items ) {
		const heading = firstHeading( item );
		const full = trimmed( textOf( item ) );
		const title = heading ? trimmed( textOf( heading ) ) : full.slice( 0, 90 );
		if ( ! title ) {
			continue;
		}
		const body = heading ? trimmed( full.replace( title, '' ) ) : '';
		const media = firstImageSrc( item );
		steps.push( { title, ...( body ? { body } : {} ), ...( media ? { media } : {} ) } );
	}
	return steps.length >= 2 ? { items: steps } : undefined;
}

/** Team members: figures with a portrait, or explicit team/member cards. */
function teamFrom( node: HNode ): SemanticSection['team'] | undefined {
	const figures = tagsNamed( node, 'figure' ).filter( ( figure ) => Boolean( firstImageSrc( figure ) ) );
	const boxes =
		figures.length >= 2
			? figures
			: descendantTags( node ).filter(
					( tag ) => ownsClass( tag, TEAM_INTENT ) && Boolean( firstImageSrc( tag ) ) && trimmed( textOf( tag ) ).length > 0
			  );
	if ( boxes.length < 2 ) {
		return undefined;
	}
	const people: TeamMember[] = [];
	for ( const box of boxes ) {
		const caption = tagsNamed( box, 'figcaption' )[ 0 ];
		// A caption with element children is name-then-role markup; a flat
		// caption is split on the usual separator. `textOf` concatenates without
		// separators, so reading a structured caption whole would yield "AdaCEO".
		const captionKids = caption ? directChildren( caption ) : [];
		let name = '';
		let role = '';
		if ( captionKids.length >= 2 ) {
			name = trimmed( textOf( captionKids[ 0 ] ) );
			role = trimmed( captionKids.slice( 1 ).map( ( kid ) => textOf( kid ) ).join( ' ' ) );
		} else {
			const flat = trimmed( textOf( caption ?? box ) );
			const split = /^(.{2,60}?)\s*[—–,|·]\s*(.+)$/.exec( flat );
			name = split ? split[ 1 ].trim() : flat;
			role = split ? split[ 2 ].trim() : '';
		}
		if ( ! name || name.length > 80 ) {
			continue;
		}
		const photo = firstImageSrc( box );
		const link = tagsNamed( box, 'a' ).find( ( anchor ) => Boolean( attrOf( anchor, 'href' ) ) );
		people.push( {
			name,
			...( role ? { role } : {} ),
			...( photo ? { photo } : {} ),
			...( link ? { sameAs: attrOf( link, 'href' ) } : {} ),
		} );
	}
	return people.length >= 2 ? { people } : undefined;
}

/** Short direct-text fragments of a node and its descendants. A price lives in
 * one of these (`$29/mo` is the direct text of its own element); reading the
 * card's whole concatenated text would swallow the next word into the price. */
function textFragments( node: HNode ): string[] {
	return [ node, ...descendantTags( node ) ]
		.flatMap( ( tag ) =>
			childrenOf( tag )
				.filter( ( child ) => child.type === 'text' )
				.map( ( child ) => trimmed( child.data ?? '' ) )
		)
		.filter( ( fragment ) => fragment.length >= 2 && fragment.length <= 24 );
}

/** Priced plans. Two or more priced cards are required — one price in a
 * feature grid is a caption, not a pricing band. */
function pricingFrom( node: HNode ): SemanticSection['pricing'] | undefined {
	// A plan is a PRICED card: it carries a price token AND either a heading or
	// a feature list. Without the structure requirement the innermost `.price`
	// element itself would become the "plan" (and lose its name and features).
	const priced = descendantTags( node ).filter(
		( tag ) =>
			ownsClass( tag, PLAN_INTENT ) &&
			PRICE_TEXT.test( textOf( tag ) ) &&
			( Boolean( firstHeading( tag ) ) || tagsNamed( tag, 'li' ).length > 0 )
	);
	// Keep the leaf-most candidates: a `.pricing-table` wrapper contains the
	// `.price` cards, and would otherwise become a plan itself.
	const candidates = priced.filter( ( candidate ) => ! priced.some( ( other ) => other !== candidate && descendantTags( candidate ).includes( other ) ) );
	if ( candidates.length < 2 ) {
		return undefined;
	}
	const plans: PricingPlan[] = [];
	for ( const plan of candidates ) {
		const text = trimmed( textOf( plan ) );
		// The price that is NOT struck through is what the visitor pays; the
		// struck text is the original. Anything else invents a sale the page does
		// not show.
		const struck = tagsNamed( plan, 'del' ).concat( tagsNamed( plan, 's' ) )[ 0 ];
		const struckText = struck ? trimmed( textOf( struck ) ) : '';
		const fragments = textFragments( plan );
		const priceOf = ( fragment: string ) => ! struckText || ! struckText.includes( fragment );
		const currentPrice = fragments.find( ( fragment ) => PRICE_TOKEN.test( fragment ) && priceOf( fragment ) );
		const originalPrice = struckText
			? fragments.find( ( fragment ) => PRICE_TOKEN.test( fragment ) && struckText.includes( fragment ) )
			: undefined;
		const price = currentPrice ?? originalPrice;
		if ( ! price ) {
			continue;
		}
		const heading = firstHeading( plan );
		const name = trimmed( ( heading ? textOf( heading ) : text ).replace( price, '' ) ).slice( 0, 60 ) || `Plan ${ plans.length + 1 }`;
		// A struck-through original price is the SALE evidence; without one the
		// band emits no sale price at all.
		const features = tagsNamed( plan, 'li' )
			.map( ( item ) => trimmed( textOf( item ) ) )
			.filter( ( feature ) => feature.length > 0 )
			.slice( 0, 12 );
		const link = tagsNamed( plan, 'a' ).find( ( anchor ) => Boolean( attrOf( anchor, 'href' ) ) );
		const href = link ? attrOf( link, 'href' ) : undefined;
		plans.push( {
			name,
			price,
			...( originalPrice && originalPrice !== price ? { salePrice: originalPrice } : {} ),
			...( features.length > 0 ? { features } : {} ),
			...( link && href ? { cta: { label: trimmed( textOf( link ) ) || 'Choose plan', href } } : {} ),
			...( ownsClass( plan, PLAN_FEATURED ) ? { featured: true } : {} ),
		} );
	}
	return plans.length >= 2 ? { plans } : undefined;
}

/**
 * Elementor's counter widget ANIMATES from 0: the DOM text is the start value
 * ("0") and the real number lives in `data-to-value`. Reading text alone is why
 * about-us (`14c60321`) published "0 / %" and "0 / Term" while the titles
 * ("Hour allocation time", "Pilot implementation") were dropped in favour of the
 * suffix spans (2026-11-24). The live site shows the same `0` in its HTML and
 * only reaches the real figure once its JS runs.
 *
 * The prefix carries the comparison ("≤", "+"); the suffix carries the unit
 * ("%", "% less", "Term") and belongs in `unit`, which Stats renders inside the
 * same node as the value.
 */
function elementorCounterStats( node: HNode ): StatItem[] | undefined {
	const boxes = descendantTags( node ).filter( ( tag ) => ownsClass( tag, /^elementor-counter$/ ) );
	if ( boxes.length < 2 ) {
		return undefined;
	}
	const items: StatItem[] = [];
	for ( const box of boxes ) {
		const number = findDescendant( box, ( tag ) => ownsClass( tag, /^elementor-counter-number$/ ) );
		const target = trimmed( attrOf( number ?? box, 'data-to-value' ) ?? '' );
		const digits = /^[\d.,]+$/.test( target ) ? target : trimmed( textOf( number ?? box ) );
		if ( ! /^[\d.,]+$/.test( digits ) ) {
			continue;
		}
		const prefix = trimmed( textOf( findDescendant( box, ( tag ) => ownsClass( tag, /^elementor-counter-number-prefix$/ ) ) ?? box ) );
		const suffixEl = findDescendant( box, ( tag ) => ownsClass( tag, /^elementor-counter-number-suffix$/ ) );
		const suffix = suffixEl ? trimmed( textOf( suffixEl ) ) : '';
		const label = trimmed( textOf( findDescendant( box, ( tag ) => ownsClass( tag, /^elementor-counter-title$/ ) ) ?? box ) );
		if ( ! label || label.length > 80 ) {
			continue;
		}
		// A prefix that is only the widget's own text (no number element at all)
		// would be the whole box — never prepend that.
		const barePrefix = number && prefix && prefix !== trimmed( textOf( box ) ) ? prefix : '';
		// A WORD suffix needs a space (`1Term` reads as a typo); a symbol does not
		// (`50% less`, not `50 % less`).
		const unit = suffix ? ( /^[a-z]/i.test( suffix ) ? ` ${ suffix }` : suffix ) : '';
		items.push( {
			value: `${ barePrefix }${ digits }`,
			...( unit ? { unit } : {} ),
			label,
		} );
	}
	return items.length >= 2 ? items : undefined;
}

/** Value/label metrics. A label is required — a bare number is not a stat. */
function statsFrom( node: HNode ): SemanticSection['stats'] | undefined {
	// A counter widget first: its text is always "0", so the generic path below
	// finds numbers that are animation artefacts and labels that are suffixes.
	const counters = elementorCounterStats( node );
	if ( counters ) {
		return { items: counters };
	}
	const all = descendantTags( node );
	let boxes = all.filter( ( tag ) => ownsClass( tag, STAT_INTENT ) && STAT_VALUE.test( trimmed( textOf( tag ) ) ) );
	if ( boxes.length < 2 ) {
		// Fallback: repeated bare-number siblings under one parent.
		boxes = [];
		for ( const parent of all ) {
			const hits = directChildren( parent ).filter( ( child ) => STAT_VALUE.test( trimmed( textOf( child ) ) ) );
			if ( hits.length >= 2 ) {
				boxes.push( ...hits );
			}
		}
	}
	if ( boxes.length < 2 ) {
		return undefined;
	}
	const items: StatItem[] = [];
	for ( const box of boxes ) {
		const raw = trimmed( textOf( box ) );
		const value = STAT_VALUE.exec( raw )?.[ 0 ]?.trim();
		if ( ! value ) {
			continue;
		}
		const parts = /^([\d.,]+)\s*([%+kKmMx]?)$/i.exec( value );
		const label = nextSiblingText( node, box ) ?? '';
		if ( ! label || label.length > 80 ) {
			continue;
		}
		items.push( {
			value: parts?.[ 1 ] ?? value,
			...( parts?.[ 2 ] ? { unit: parts[ 2 ] } : {} ),
			label,
		} );
	}
	return items.length >= 2 ? { items } : undefined;
}

/** A repeated track. The class must SAY it is a marquee/ticker — an ordinary
 * logo cloud is a logo cloud. */
function marqueeFrom( node: HNode ): SemanticSection['marquee'] | undefined {
	// The track (or a wrapper) names itself a marquee — the section itself
	// usually does not.
	if ( ! ownsClass( node, MARQUEE_INTENT ) && ! descendantTags( node ).some( ( tag ) => ownsClass( tag, MARQUEE_INTENT ) ) ) {
		return undefined;
	}
	// Leaf-ish nodes only: a wrapper's text is the concatenation of its children
	// and would otherwise become one giant extra "label".
	const leafish = descendantTags( node ).filter(
		( tag ) => tag.name === 'li' || tag.name === 'a' || directChildren( tag ).length === 0
	);
	const labels = [
		...new Set(
			leafish
				.map( ( tag ) => ( tag.name === 'img' ? trimmed( attrOf( tag, 'alt' ) ?? '' ) : trimmed( textOf( tag ) ) ) )
				.filter( ( label ) => label.length > 1 && label.length < 80 )
		),
	];
	if ( labels.length < 4 ) {
		return undefined;
	}
	return { items: labels.slice( 0, 24 ).map( ( label ) => ( { label } ) ) };
}

/**
 * Classify a whole band by structural evidence (Proposal 136). Returns
 * undefined when no kind's evidence holds — the section then takes the existing
 * shape ladder, unchanged (the byte-identity pin).
 */
function structuralSection( node: HNode ): SemanticSection | undefined {
	const comparison = comparisonFrom( node );
	if ( comparison ) {
		return { kind: 'comparison', confidence: 'high', slots: {}, comparison };
	}
	const steps = stepsFrom( node );
	if ( steps ) {
		return { kind: 'steps', confidence: 'high', slots: {}, steps };
	}
	const team = teamFrom( node );
	if ( team ) {
		return { kind: 'team', confidence: 'high', slots: {}, team };
	}
	const pricing = pricingFrom( node );
	if ( pricing ) {
		return { kind: 'pricing', confidence: 'high', slots: {}, pricing };
	}
	const stats = statsFrom( node );
	if ( stats ) {
		return { kind: 'stats', confidence: 'high', slots: {}, stats };
	}
	const marquee = marqueeFrom( node );
	if ( marquee ) {
		return { kind: 'marquee', confidence: 'high', slots: {}, marquee };
	}
	return undefined;
}

/** Does this node look like a section boundary (Elementor section / group / WPBakery row / <section>)? */
function isSectionBoundary( node: HNode ): boolean {
	// A semantic <section> element IS a band. Proposal 137's vendor-agnostic
	// adapters established this on the analyzer side; the render-side
	// classifier needs the same rule, or every non-Elementor band collapses
	// into one text block (virtuallyjamaica.com's 7 Brando sections became a
	// single 8058px band, so the positional band theming painted the whole
	// page red).
	if ( node.name === 'section' ) {
		return true;
	}
	const classes = classList( node.attribs );
	return classes.some(
		( c ) =>
			ELEMENTOR_SECTION.test( c ) ||
			ELEMENTOR_SECTION.test( ` ${ c } ` ) ||
			ELEMENTOR_CONTAINER_BAND.test( c ) ||
			WPB_ROW.test( c ) ||
			/(^|\s)wp-block-group(\s|$)/.test( c ) ||
			/(^|\s)wp-block-cover(\s|$)/.test( c )
	);
}

function sliceHtml( html: string, node: HNode ): string {
	// htmlparser2's `endIndex` is the index of the element's LAST character —
	// the `>` of its closing tag — so the slice end is `endIndex + 1`. Treating
	// it as exclusive truncated every closing tag by one character, emitting
	// unbalanced `<\/section` (no `>`); the browser then nested each following
	// band inside the previous one and every surface stacked into the first
	// (virtuallyjamaica.com, 2026-11-10).
	if ( typeof node.startIndex === 'number' && typeof node.endIndex === 'number' && node.endIndex >= node.startIndex ) {
		return html.slice( node.startIndex, node.endIndex + 1 );
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

/**
 * Copy paragraphs in the region OUTSIDE the form subtree — the band's intro
 * copy.
 *
 * A form band used to keep only the heading and the controls, so the source's
 * own lead paragraph disappeared: virtuallyjamaica's contact band renders
 * "This deep customer understanding allows our clients to make evidence based
 * decisions…" above the form, and the rebuilt band carried 15 characters
 * against the source's 249. `width-50`/`text-center` wrappers are structural,
 * not content — only the text survives.
 */
function paragraphsOutside( region: HNode, form: HNode ): string[] {
	const found: string[] = [];
	const walk = ( node: HNode ) => {
		if ( node === form ) {
			return;
		}
		if ( node.type === 'tag' && node.name === 'p' ) {
			const text = trimmed( textOf( node ) );
			if ( text.length > 1 ) {
				found.push( text );
			}
			return;
		}
		for ( const child of childrenOf( node ) ) {
			walk( child );
		}
	};
	walk( region );
	return found;
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

/** A nav-target anchor: a plain lowercase slug, never an internal/auto id. */
function anchorOfNode( node: HNode ): string | undefined {
	const id = node.attribs?.id;
	return id &&
		/^[a-z][a-z0-9-]{1,31}$/.test( id ) &&
		! /^(?:elementor|aux-|df_|nvoos|rev_slider|wp-)/.test( id )
		? id
		: undefined;
}

/** Build a `form` section for one classified form node. */
function formSection( html: string, region: HNode, form: HNode, searchForms?: string[] ): SemanticSection | undefined {
	const info = classifyForm( form, searchForms );
	if ( ! info ) {
		return undefined;
	}
	const section: SemanticSection = { kind: 'form', confidence: 'high', slots: {} };
	// The source section id — without it the band has no theming key and the
	// band audit reports the source band as MISSING from the build
	// (virtuallysrilanka.com's #contact band).
	const sourceId = elementorId( region );
	if ( sourceId ) {
		section.id = sourceId;
	}
	// The band's own id is the nav target (a CF7 form inside
	// `<section id="contact">` must keep that anchor).
	const anchor = anchorOfNode( region );
	if ( anchor ) {
		section.anchor = anchor;
	}
	const title = headingOutside( region, form );
	if ( title ) {
		section.slots.title = title;
	}
	// The band's intro copy — dropped copy is a content regression the page-level
	// text gate reports but cannot attribute to a band.
	const paragraphs = paragraphsOutside( region, form );
	if ( paragraphs.length > 0 ) {
		section.slots.body = paragraphs;
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

/** The outermost band boundaries within a node list (never a nested one). */
function boundarySections( nodes: HNode[] ): HNode[] {
	const found: HNode[] = [];
	const visit = ( node: HNode ) => {
		for ( const child of childrenOf( node ) ) {
			if ( child.type === 'tag' && isSectionBoundary( child ) ) {
				found.push( child );
			} else {
				visit( child );
			}
		}
	};
	for ( const node of nodes ) {
		if ( node.type === 'tag' && isSectionBoundary( node ) ) {
			found.push( node );
		} else {
			visit( node );
		}
	}
	return found;
}

/**
 * Classify a form-free node region with the pre-form pipeline (the
 * builder-marker / Elementor-section grouping path). `fallbackHtml` is the
 * raw-HTML carried by the text fallback — the whole document for the
 * no-forms fast path, joined slices for split regions.
 */
function classifyRegion( html: string, nodes: HNode[], fallbackHtml: string, options: ClassifyOptions ): SemanticSection[] {
	const root: HNode = { type: 'root', children: nodes, startIndex: 0, endIndex: html.length - 1 };

	// Vendor-agnostic band split (Proposal 137, render half): 2+ semantic
	// boundaries (`<section>`, Elementor section, WPBakery row, wp-block-group/
	// cover) mean the document IS banded, even when no builder MARKER class is
	// present. Flatsome's UX Builder emits `<section class="section">` +
	// `.section-content` with no Elementor/WPBakery/wp-block class at all, so
	// the marker gate alone collapsed four bands into one raw text section and
	// only the first band's surface could apply (monicarajiv.com, 2026-11-11).
	const bands = boundarySections( nodes );
	const bandedSections = (): SemanticSection[] => {
		const out: SemanticSection[] = [];
		for ( const band of bands ) {
			const slice = sliceHtml( html, band );
			if ( ! slice ) {
				continue;
			}
			const anchor = anchorOfNode( band );
			out.push( { kind: 'text', confidence: 'low', slots: {}, html: slice, ...( anchor ? { anchor } : {} ) } );
		}
		return out;
	};

	if ( ! hasBuilderMarkers( root ) ) {
		if ( bands.length > 1 ) {
			const split = bandedSections();
			if ( split.length > 0 ) {
				return split;
			}
		}
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
		// Vendor-agnostic band split (Proposal 137, render half). Builders the
		// Elementor taxonomy does not describe — WPBakery/Brando, Divi, Bricks,
		// Flatsome, plain semantic HTML — still carry band boundaries. Splitting
		// them keeps the source's band flow and lets the positional band theming
		// paint each surface; without it the whole page is one text section and
		// only the first band's background can apply (virtuallyjamaica, 2026-11-10).
		if ( bands.length > 1 ) {
			const split = bandedSections();
			if ( split.length > 0 ) {
				return split;
			}
		}
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
		const anchor = anchorOfNode( section );
		// Proposal 136: structural evidence (a priced plan set, an ordered
		// process, a comparison table, a team, a metrics row, a repeated track)
		// decides the kind BEFORE the shape ladder. No evidence → the ladder runs
		// exactly as before, so form-less/kind-less documents stay byte-identical.
		const structural = structuralSection( section );
		if ( structural ) {
			if ( sourceId ) {
				structural.id = sourceId;
			}
			if ( anchor ) {
				structural.anchor = anchor;
			}
			out.push( structural );
			continue;
		}
		if ( widgets.length === 0 ) {
			const text = trimmed( textOf( section ) );
			if ( text.length > 0 ) {
				out.push( { kind: 'text', confidence: 'low', slots: { body: [ text ] }, html: sliceHtml( html, section ) || fallbackHtml, id: sourceId, ...( anchor ? { anchor } : {} ) } );
			}
			continue;
		}
		const built = sectionFromWidgets( widgets, out.length, section );
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
				out.push( { kind: 'text', confidence: 'low', slots: {}, id: sourceId, ...( anchor ? { anchor } : {} ) } );
			}
			continue;
		}
		// Words are the one thing a rebuild may never lose. A CONFIDENT kind can
		// still drop the copy it was never shaped for: eca.directory's “Explore
		// Our Activities” band (a heading, an icon-box and a button) classified as
		// a `cta` and lost three paragraphs, and its team band took the first
		// card's name for the band title and dropped the roster. When the slots
		// carry under half the band's words, the band keeps its RAW slice instead
		// — the measured band facts still theme it. This is Proposal 116's
		// “never empty a document” ladder, applied per band.
		const sourceWords = wordCount( textOf( section ) );
		if ( sourceWords >= 12 && slotWordCount( built.slots, RENDERED_SLOTS[ built.kind ] ) < sourceWords * 0.5 ) {
			out.push( {
				kind: 'text',
				confidence: 'low',
				slots: {},
				html: sliceHtml( html, section ) || fallbackHtml,
				...( sourceId ? { id: sourceId } : {} ),
				...( anchor ? { anchor } : {} ),
			} );
			continue;
		}
		if ( sourceId ) {
			built.id = sourceId;
		}
		if ( anchor ) {
			built.anchor = anchor;
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
/**
 * Flatten a builder wrapper down to its section boundaries.
 *
 * Elementor (and every other builder) wraps the whole page in ONE container
 * (`data-elementor-type="wp-page"`), so the document's top-level node is that
 * wrapper rather than a band. The form walk below consumes any node whose
 * subtree contains a `<form>` — against a wrapped document that means the
 * ENTIRE page became a single `form` section, and every other band was lost
 * (virtuallysrilanka.com: 7 bands → 1 form, `verify --bands` 0/7, no anchors).
 *
 * The band boundaries are still there, one level down. Descend to the
 * OUTERMOST boundaries when a wrapper holds several, and leave the node list
 * alone otherwise so a genuinely single-band page keeps its wrapper.
 */
function bandLevelNodes( nodes: HNode[] ): HNode[] {
	if ( nodes.some( ( node ) => node.type === 'tag' && isSectionBoundary( node ) ) ) {
		return nodes;
	}
	const boundaries: HNode[] = [];
	const collectOutermost = ( node: HNode ) => {
		for ( const child of childrenOf( node ) ) {
			if ( child.type === 'tag' && isSectionBoundary( child ) ) {
				boundaries.push( child );
			} else {
				collectOutermost( child );
			}
		}
	};
	for ( const node of nodes ) {
		collectOutermost( node );
	}
	return boundaries.length > 1 ? boundaries : nodes;
}

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

	// Forms present: walk the BAND-level nodes in document order; nodes whose
	// subtree contains a form become form sections, everything else keeps
	// its order in the regular pipeline.
	const sections: SemanticSection[] = [];
	const pending: HNode[] = [];
	const walk = bandLevelNodes( nodes );
	const flushPending = () => {
		if ( pending.length > 0 ) {
			sections.push( ...classifyRegion( html, pending, joinSlices( html, pending ), options ) );
			pending.length = 0;
		}
	};
	for ( const child of walk ) {
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
