/**
 * NV oOS Comic Reader — Reader Preferences
 *
 * Persistent reader preferences modelled on Komga's webreader settings:
 * reading mode, scale type, background, double pages, transitions, gestures.
 * Global prefs live under `nvoos_cr_prefs`; per-comic overrides under
 * `nvoos_cr_prefs_{comicId}`.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.3.0
 */

export type ReadingMode = 'paged' | 'scroll' | 'webtoon';
export type ScaleType = 'fit-screen' | 'fit-width' | 'fit-height' | 'none';
export type ReaderBackground = 'white' | 'gray' | 'black';
export type PageTransition = 'none' | 'fade' | 'slide';
export type ReadingDirection = 'ltr' | 'rtl';

export interface ReaderPrefs {
	readingMode: ReadingMode;
	direction: ReadingDirection;
	scale: ScaleType;
	background: ReaderBackground;
	doublePage: boolean;
	transition: PageTransition;
	gestures: boolean;
}

export const DEFAULT_PREFS: ReaderPrefs = {
	readingMode: 'paged',
	direction: 'ltr',
	scale: 'fit-width',
	background: 'gray',
	doublePage: false,
	transition: 'fade',
	gestures: true,
};

const GLOBAL_KEY = 'nvoos_cr_prefs';

function perComicKey(comicId: number): string {
	return `nvoos_cr_prefs_${comicId}`;
}

function mergePrefs(...sources: Array<Partial<ReaderPrefs>>): ReaderPrefs {
	return sources.reduce<ReaderPrefs>(
		(merged, source) => ({ ...merged, ...source }),
		{ ...DEFAULT_PREFS }
	);
}

/**
 * Load reader preferences, applying the per-comic override over the global
 * defaults. Never throws — falls back to defaults when storage is unavailable.
 */
export function loadPrefs(comicId?: number): ReaderPrefs {
	try {
		const globalRaw = localStorage.getItem(GLOBAL_KEY);
		const global = globalRaw
			? (JSON.parse(globalRaw) as Partial<ReaderPrefs>)
			: {};
		if (!comicId) {
			return mergePrefs(global);
		}

		const comicRaw = localStorage.getItem(perComicKey(comicId));
		const comic = comicRaw
			? (JSON.parse(comicRaw) as Partial<ReaderPrefs>)
			: {};
		return mergePrefs(global, comic);
	} catch {
		return { ...DEFAULT_PREFS };
	}
}

/**
 * Persist reader preferences. Pass a comic ID to store a per-comic override;
 * omit it for the global defaults.
 */
export function savePrefs(prefs: ReaderPrefs, comicId?: number): void {
	try {
		localStorage.setItem(
			comicId ? perComicKey(comicId) : GLOBAL_KEY,
			JSON.stringify(prefs)
		);
	} catch {
		// Storage unavailable — prefs stay session-only.
	}
}

/** Map a background preference to a concrete CSS color. */
export function backgroundToColor(background: ReaderBackground): string {
	switch (background) {
		case 'white':
			return '#f5f5f5';
		case 'black':
			return '#000000';
		case 'gray':
		default:
			return '#333333';
	}
}
