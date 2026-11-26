// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// lane-tracks.ts — how many columns the SOURCE puts on one device row.
//
// Elementor states a container's responsive width as a flex BASIS: lanes wrap
// when the running total passes 100%. The rebuild is a CSS grid, so the
// faithful translation of that fact is the first row's TRACK COUNT — not a
// per-lane width, which would fight the grid and re-open the lane drift the
// measured split already settled (286/954 -> 273/907).
//
// Two rules keep this honest:
//
//  1. EXPLICIT ONLY. A device's count is computed only when at least one lane
//     states a width for that device. Otherwise the template's own collapse
//     (and the band's measured `stackAt`) decide — Elementor's implicit basis
//     is the DESKTOP split, and applying it at 390px squeezes the grid.
//  2. INHERITANCE, as CSS does. A lane with no width for the device inherits its
//     desktop width, which is Elementor's own cascade.
//
// A lane the source HIDES on the device takes no slot: the renderer hides it
// with `display:none`, so counting it would leave an empty track.
// ---------------------------------------------------------------------------

export type LaneDevice = 'desktop' | 'tablet' | 'mobile';

/** The per-lane facts this module reads (a subset of `ForgeSectionColumn`). */
export interface LaneTrackInput {
	width?: number;
	widthTablet?: number;
	widthMobile?: number;
	hiddenDesktop?: boolean;
	hiddenTablet?: boolean;
	hiddenMobile?: boolean;
}

const WIDTH_FOR: Record< LaneDevice, ( lane: LaneTrackInput ) => number | undefined > = {
	desktop: ( lane ) => lane.width,
	tablet: ( lane ) => lane.widthTablet ?? lane.width,
	mobile: ( lane ) => lane.widthMobile ?? lane.width,
};

const STATED_FOR: Record< LaneDevice, ( lane: LaneTrackInput ) => boolean > = {
	desktop: ( lane ) => lane.width !== undefined,
	tablet: ( lane ) => lane.widthTablet !== undefined,
	mobile: ( lane ) => lane.widthMobile !== undefined,
};

const HIDDEN_FOR: Record< LaneDevice, ( lane: LaneTrackInput ) => boolean > = {
	desktop: ( lane ) => lane.hiddenDesktop === true,
	tablet: ( lane ) => lane.hiddenTablet === true,
	mobile: ( lane ) => lane.hiddenMobile === true,
};

/**
 * The number of tracks the source renders on ONE device row.
 *
 * `undefined` when the answer is not stated (no lane sizes this device), when
 * fewer than two lanes are visible, or when a visible lane's width is unknown —
 * a count computed from partial data is a guess, and a wrong track count is
 * worse than the template default.
 */
export function laneTracks( columns: LaneTrackInput[], device: LaneDevice ): number | undefined {
	if ( columns.length < 2 ) {
		return undefined;
	}
	// Rule 1: the device must be addressed explicitly.
	if ( ! columns.some( ( lane ) => ! HIDDEN_FOR[ device ]( lane ) && STATED_FOR[ device ]( lane ) ) ) {
		return undefined;
	}
	const visible = columns.filter( ( lane ) => ! HIDDEN_FOR[ device ]( lane ) );
	if ( visible.length < 2 ) {
		return undefined;
	}
	let sum = 0;
	let tracks = 0;
	for ( const lane of visible ) {
		const width = WIDTH_FOR[ device ]( lane );
		if ( width === undefined || width <= 0 ) {
			// Rule 2 cannot resolve this lane — the count would be a guess.
			return undefined;
		}
		// The 0.5 tolerates `33.3 * 3 = 99.9` without accepting a real wrap.
		if ( sum + width > 100.5 ) {
			break;
		}
		sum += width;
		tracks += 1;
	}
	return tracks >= 1 ? tracks : undefined;
}
