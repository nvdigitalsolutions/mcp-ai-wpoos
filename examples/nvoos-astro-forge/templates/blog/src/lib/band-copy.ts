// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-copy.ts — the band's body paragraphs, without the text the measured
// heading already prints.
//
// The classifier names a band's title by picking its LARGEST heading, and the
// measurement can disagree: a long all-caps kicker out-masses the real title, so
// the promoted text stays behind in `slots.body` while `SectionTitle` prints the
// measured title above it. virtuallysrilanka's hardware band rendered
// "SUPPORTED HARDWARE" twice — once as the `<h2>`, once as body copy.
//
// It also owns the TITLE ECHO rule: a card whose heading is the band's own
// title is the band's sub-heading, not a card. Two files need that answer —
// `FeatureGrid` renders the cards and `DesignRenderer` decides whether the
// band's measured `min-height` still applies once they fold away — so it lives
// here rather than being re-derived at each call site.
//
// This module is DELIBERATELY dependency-free: it is copied into every
// generated project, where `src/forge/*` exists, but it is also type-checked in
// the forge repo, where it does not. The item shape below is therefore
// structural, never an import of `SectionItem`.
// ---------------------------------------------------------------------------

/** The item fields the fold rule reads (structurally compatible with `SectionItem`). */
export interface BandCardItem {
	title?: string;
	body?: string;
	paragraphs?: string[];
	media?: unknown;
	links?: readonly unknown[];
	columnIndex?: number;
}

/** The measured heading facts a band body must not repeat. */
export interface BandHeadingFacts {
	titleText?: string;
	eyebrow?: { text?: string };
}

/** A measured lane's painted surface (see `SectionTheme.columns`). */
export interface BandLaneSurface {
	bgColor?: string;
	bgImage?: string;
}

/**
 * The band's media LANE, when its measured row is one picture beside content.
 *
 * A builder puts a photo in its own column (`e-child`) and the copy in the
 * other; the rebuild's component kinds (form, icon list, feature grid, stats,
 * CTA) render the copy full-width and drop the column entirely. The measured
 * lane list is the proof the column exists, so the renderer can put the
 * component beside the picture instead of pretending the band is one column.
 *
 * Deliberately narrow: exactly TWO lanes with exactly ONE painted. A card row
 * where a single card happens to carry a background photo still has three or
 * more lanes (or two painted), and is left to its own component.
 */
export function mediaLane(
	theme?: { columns?: readonly ( BandLaneSurface | undefined )[] }
): { src: string; side: 'left' | 'right' } | undefined {
	const lanes = theme?.columns;
	if ( ! Array.isArray( lanes ) || lanes.length !== 2 ) {
		return undefined;
	}
	const painted = lanes
		.map( ( lane, index ) => ( lane?.bgImage ? index : -1 ) )
		.filter( ( index ) => index >= 0 );
	if ( painted.length !== 1 ) {
		return undefined;
	}
	const src = lanes[ painted[ 0 ] ]?.bgImage;
	return src ? { src, side: painted[ 0 ] === 0 ? 'left' : 'right' } : undefined;
}

/** The band's paragraphs, minus any text its title or eyebrow already renders. */
export function bandParagraphs(
	body: string[] | undefined,
	theme?: BandHeadingFacts
): string[] {
	if ( ! body ) {
		return [];
	}
	const shown = new Set(
		[ theme?.titleText, theme?.eyebrow?.text ].filter(
			( value ): value is string => Boolean( value )
		)
	);
	if ( shown.size === 0 ) {
		return body;
	}
	return body.filter( ( paragraph ) => ! shown.has( paragraph ) );
}

function normalise( value: string | undefined ): string {
	return ( value ?? '' ).trim().replace( /\s+/g, ' ' ).toLowerCase();
}

/**
 * The title this band actually PRINTS: the measured pair wins over the
 * classifier's pick, exactly as `SectionTitle` decides it.
 */
export function bandShownTitle(
	title: string | undefined,
	theme?: BandHeadingFacts
): string | undefined {
	return theme?.eyebrow && theme?.titleText ? theme.titleText : title;
}

/**
 * The cards that are really the band's SUB-HEADING: their heading is the string
 * the band title already prints.
 *
 * A builder publishes an updates band as a small kicker heading plus an
 * icon-box whose title is the real heading and whose body is the band's lede
 * (eca.directory: a 14px `h2` "Our Updates" over a 33px icon-box "News &
 * Insights"). The measured eyebrow/title pair then promotes the icon-box heading
 * to the band title, and rendering the card too printed the same string twice
 * above an empty-looking one-card grid.
 *
 * NEVER folded:
 *   - a card with media — that is its picture, and the title does not speak for it;
 *   - a card with its own link — that is a destination;
 *   - a card whose measured lane paints a surface — that is a panel the band owns.
 */
export function titleEchoItems< T extends BandCardItem >(
	items: T[] | undefined,
	title: string | undefined,
	theme?: BandHeadingFacts,
	lanes?: BandLaneSurface[]
): T[] {
	const shown = bandShownTitle( title, theme );
	if ( ! shown ) {
		return [];
	}
	const key = normalise( shown );
	if ( ! key ) {
		return [];
	}
	return ( items ?? [] ).filter( ( item ) => {
		if ( normalise( item.title ) !== key ) {
			return false;
		}
		if ( item.media || ( item.links?.length ?? 0 ) > 0 ) {
			return false;
		}
		const lane = item.columnIndex === undefined ? undefined : lanes?.[ item.columnIndex ];
		return ! lane?.bgColor && ! lane?.bgImage;
	} );
}
