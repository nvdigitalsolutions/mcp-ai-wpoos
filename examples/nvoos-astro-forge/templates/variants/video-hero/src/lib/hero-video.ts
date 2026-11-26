// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// hero-video.ts — resolve a hero video and decide whether it may autoplay
// (video-hero variant).
//
// The hero has ONE job: paint fast. So the still is the whole contract and the
// video is an enhancement:
//   - no valid poster  → no hero at all (the caller keeps its normal hero band);
//   - reduced motion   → poster only;
//   - Save-Data / 2G  → poster only.
//
// Pure: no DOM, no network, no Astro — the browser signals are passed IN, so
// every branch is unit-tested rather than hoped for.
// ---------------------------------------------------------------------------

/** A media URL must be root-relative or https — never `//`, never another scheme. */
const SITE_ASSET = /^(?:\/(?!\/)|https:\/\/)/;

/** The operator-authored hero block. */
export interface HeroVideoInput {
	/** The still that paints first and remains the accessible name. */
	poster?: unknown;
	/** MP4/H.264 — required: the only format every browser decodes. */
	mp4?: unknown;
	/** Optional WebM/AV1 sibling, offered first (smaller, better quality). */
	webm?: unknown;
	/** Accessible description of the scene. Falls back to `title`. */
	alt?: unknown;
	/** Band title, used as the last-resort accessible name. */
	title?: unknown;
	caption?: unknown;
}

/** The resolved hero contract. */
export interface HeroVideoSource {
	poster: string;
	mp4: string;
	webm?: string;
	alt: string;
	caption?: string;
}

/** True for a root-relative or https media URL. */
export function isSiteAsset(value: unknown): value is string {
	return typeof value === 'string' && SITE_ASSET.test(value);
}

/**
 * Resolve the hero from an authoring block, or null when it cannot carry a fast
 * first paint. A missing MP4 is a hard no: a WebM-only hero fails on Safari,
 * and a broken hero is worse than a still one.
 */
export function resolveHeroVideo(input: HeroVideoInput | null | undefined): HeroVideoSource | null {
	if (!input || !isSiteAsset(input.poster) || !isSiteAsset(input.mp4)) {
		return null;
	}

	const alt = typeof input.alt === 'string' && input.alt.trim() !== ''
		? input.alt.trim()
		: typeof input.title === 'string' ? input.title.trim() : '';
	if (!alt) {
		return null;
	}

	const resolved: HeroVideoSource = { poster: input.poster, mp4: input.mp4, alt };
	if (isSiteAsset(input.webm)) {
		resolved.webm = input.webm;
	}
	if (typeof input.caption === 'string' && input.caption.trim() !== '') {
		resolved.caption = input.caption.trim();
	}
	return resolved;
}

/** The browser signals that can veto autoplay. */
export interface PlaybackSignals {
	/** `matchMedia('(prefers-reduced-motion: reduce)').matches`. */
	reducedMotion?: boolean;
	/** `navigator.connection.saveData` (or a data-saver heuristic). */
	saveData?: boolean;
	/** `navigator.connection.effectiveType` — 'slow-2g' | '2g' | '3g' | '4g'. */
	effectiveType?: string;
}

/**
 * Decide whether the hero may start playing on its own.
 *
 * Muted autoplay is the only autoplay browsers allow without a gesture, and
 * even then it is a *motion* decision: a visitor who asked for less motion, or
 * who is on a metered/slow connection, gets the still. Everything else is an
 * explicit play.
 */
export function shouldAutoplay(signals: PlaybackSignals = {}): boolean {
	if (signals.reducedMotion === true || signals.saveData === true) {
		return false;
	}
	const effective = (signals.effectiveType ?? '').toLowerCase();
	return effective !== 'slow-2g' && effective !== '2g';
}
