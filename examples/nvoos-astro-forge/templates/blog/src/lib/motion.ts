// ---------------------------------------------------------------------------
// motion.ts — piece-level parallax CSS (Proposal 121 + the element pass).
//
// The analyzer records the SOURCE's moving pieces (Phlox `aux-parallax-piece`
// depth/origin/off, Elementor motion_fx direction/speed/range/devices). This
// module turns those facts into a scoped rule per rebuilt media carrier. It is
// pure and deterministic: no DOM, no timestamps, no source markup.
//
// The motion itself stays CSS-native (`scroll(root)` + `translate`), so it runs
// off the main thread and is gated by `@supports` + `prefers-reduced-motion` in
// global.css. The source algorithm being mirrored (AvertaParallaxBox):
//
//   sign   = depth < 0 ? +1 : -1          // negative depth renders DOWN
//   value  = origin === 'bottom' ? max(0, spaceFromBot * |depth|)
//          : origin === 'top'    ? min(0, spaceFromTop * |depth|)
//          :                        spaceFromMid * |depth|
//   transform = translateY(value * sign)
//
// so `bottom` travels 0 → +range, `top` travels 0 → -range, and `middle`
// travels -range → +range (before the depth sign is applied).
//
// Elementor's `direction: negative` ALSO renders a positive translateY (measured
// live on the jayainc about collage: depth -0.1 + direction negative →
// translateY(+81.44px), depth +0.1 → translateY(-81.44px)).
// ---------------------------------------------------------------------------

import type { ParallaxPiece, ScrollBehaviorEntry } from './behaviors.generated.ts';

/** The template's band height unit: a speed-1 piece travels ±24px. */
const BASE_RANGE_PX = 24;
const MIN_RANGE_PX = 8;
const MAX_RANGE_PX = 96;

/** The source's depth is a fraction of the viewport; 0.1 is the common default. */
const REFERENCE_DEPTH = 0.1;

/** Clamp a percentage to the 0-100 viewport range. */
function percent( value: number ): number {
	return Math.min( Math.max( Math.round( value ), 0 ), 100 );
}

/**
 * The piece's travel in px. Speed sets the band's base (the existing
 * `speed × 24px` contract); depth scales it relative to the 0.1 reference, so
 * two pieces at -0.1/+0.1 keep the source's relative magnitudes.
 */
export function parallaxRangePx( piece: ParallaxPiece, entrySpeed?: number ): number {
	if ( piece.depth === 0 ) {
		return 0;
	}
	const speed = Math.min( Math.max( piece.speed ?? entrySpeed ?? 1, 1 ), 4 );
	const base = speed * BASE_RANGE_PX;
	const depth = Math.min( Math.abs( piece.depth ?? REFERENCE_DEPTH ), 0.5 );
	const scaled = base * ( depth / REFERENCE_DEPTH );
	return Math.round( Math.min( Math.max( scaled, MIN_RANGE_PX ), MAX_RANGE_PX ) );
}

/**
 * The source's sign convention as measured: a negative Phlox depth renders a
 * POSITIVE translateY; Elementor `direction: negative` does the same. A piece
 * with neither (a generic `data-parallax-*` convention) defaults to +1.
 */
export function parallaxSign( piece: ParallaxPiece ): 1 | -1 {
	if ( piece.depth !== undefined ) {
		return piece.depth < 0 ? 1 : -1;
	}
	if ( piece.direction === 'negative' ) {
		return 1;
	}
	if ( piece.direction === 'positive' ) {
		return -1;
	}
	return 1;
}

/** The keyframe endpoints for the piece's origin (see the module header). */
export function parallaxEndpoints( piece: ParallaxPiece, entrySpeed?: number ): { from: number; to: number } {
	const range = parallaxRangePx( piece, entrySpeed );
	const sign = parallaxSign( piece );
	const origin = piece.origin ?? 'middle';
	if ( origin === 'bottom' ) {
		return { from: 0, to: range * sign };
	}
	if ( origin === 'top' ) {
		return { from: 0, to: -range * sign };
	}
	return { from: range * sign, to: -range * sign };
}

/** The source disabled the effect at `innerWidth <= off`; Elementor scopes it
 * to a device list. Returns the min-width query that keeps the source's gate. */
export function parallaxMinWidth( piece: ParallaxPiece ): number | undefined {
	const values: number[] = [];
	if ( piece.off !== undefined ) {
		values.push( piece.off + 1 );
	}
	if ( piece.devices && piece.devices.length > 0 ) {
		const hasTablet = piece.devices.includes( 'tablet' );
		const hasMobile = piece.devices.includes( 'mobile' );
		if ( ! hasTablet && ! hasMobile ) {
			values.push( 1025 );
		} else if ( ! hasMobile ) {
			values.push( 768 );
		}
	}
	return values.length > 0 ? Math.max( ...values ) : undefined;
}

/** Elementor's mobile-only motion (rare) scopes the opposite way. */
export function parallaxMaxWidth( piece: ParallaxPiece ): number | undefined {
	if ( ! piece.devices || piece.devices.length === 0 ) {
		return undefined;
	}
	const mobileOnly =
		piece.devices.includes( 'mobile' ) && ! piece.devices.includes( 'desktop' ) && ! piece.devices.includes( 'tablet' );
	return mobileOnly ? 767 : undefined;
}

/** The media carriers a rebuilt section kind renders, in source media order.
 * The scoped prefix repeats `.ds-parallax` so a piece rule outranks the band's
 * fallback even/odd rule (equal specificity at 3 classes otherwise). */
function carrierSelectors( kind: string, scoped: string, ordinal: number ): string[] {
	const nth = ordinal + 1;
	const media = `${ scoped } .ds-media:nth-of-type(${ nth })`;
	switch ( kind ) {
		case 'icon-list':
			return [ media, `${ scoped } .ds-icon-media img:nth-of-type(${ nth })`, `${ scoped } .ds-card:nth-of-type(${ nth })` ];
		case 'feature-grid':
			return [ `${ scoped } .ds-card:nth-of-type(${ nth })`, media ];
		case 'gallery':
			return [ media, `${ scoped } .ds-gallery img:nth-of-type(${ nth })` ];
		default:
			return [ media ];
	}
}

/**
 * One piece → one scoped rule. The rule only sets custom properties; the band's
 * `ds-parallax` animation consumes them, so a `background`-type piece (the
 * source animates `background-position`) is skipped rather than faked.
 */
export function parallaxPieceCss(
	sectionId: string,
	kind: string,
	piece: ParallaxPiece,
	fallbackIndex: number,
	entrySpeed?: number
): string {
	if ( piece.type === 'background' ) {
		return '';
	}
	const ordinal = piece.index ?? fallbackIndex;
	const { from, to } = parallaxEndpoints( piece, entrySpeed );
	const declarations = [ `--ds-parallax-from:${ from }px`, `--ds-parallax-to:${ to }px` ];
	// Elementor's affectedRange narrows the source's viewport window. The
	// default 0-100 is already the base rule's `cover 0% cover 100%`, so only a
	// non-default range needs a declaration.
	const range = piece.range;
	if ( range && ( ( range.start ?? 0 ) > 0 || ( range.end ?? 100 ) < 100 ) ) {
		declarations.push( `animation-range:${ percent( range.start ?? 0 ) }% ${ percent( range.end ?? 100 ) }%` );
	}
	const rule = `${ carrierSelectors( kind, `[data-section-id="${ sectionId }"].ds-parallax`, ordinal ).join( ',' ) }{${ declarations.join( ';' ) }}`;
	const minWidth = parallaxMinWidth( piece );
	if ( minWidth !== undefined ) {
		return `@media (min-width:${ minWidth }px){${ rule }}`;
	}
	const maxWidth = parallaxMaxWidth( piece );
	if ( maxWidth !== undefined ) {
		return `@media (max-width:${ maxWidth }px){${ rule }}`;
	}
	return rule;
}

/** Every piece rule for one band; '' when the band has no real pieces. */
export function parallaxBandCss(
	section: { id?: string; kind: string },
	entry: ScrollBehaviorEntry | undefined
): string {
	if ( ! section.id || ! entry || entry.kind !== 'parallax' || ! entry.pieces || entry.pieces.length === 0 ) {
		return '';
	}
	return entry.pieces
		.map( ( piece, index ) => parallaxPieceCss( section.id as string, section.kind, piece, index, entry.speed ) )
		.join( '' );
}
