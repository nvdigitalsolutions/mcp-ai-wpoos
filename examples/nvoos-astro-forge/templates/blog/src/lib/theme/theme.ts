// ---------------------------------------------------------------------------
// Theme runtime — Proposal 104 §3.1, forge port (§4.2). Single source of
// truth for the client theme + contrast axes. No Astro imports — this is
// the canonical port target for the velocity storefront's theme module and
// stays pure for unit coverage. Wired into the template layout only when
// runtime theming lands (104 Q4 — define-only today).
//
// Pattern follows the ecosystem norm (astro-bento-portfolio appearance.ts /
// AstroPaper [data-theme] blocks): localStorage is truth, application is
// idempotent (single attribute, set-or-remove — the "remove all, add one"
// rule collapses to one attribute here), and re-application happens on every
// navigation via the astro:after-swap listener in the layout boot script.
// ---------------------------------------------------------------------------

export const THEME_STORAGE_KEY = 'nvoos-theme';
export const CONTRAST_STORAGE_KEY = 'nvoos-contrast';
export const CONTRAST_MODES = ['standard', 'high'] as const;
export type ContrastMode = (typeof CONTRAST_MODES)[number];

/** 'light' is implicit (absent data-theme); registry names extend it. */
export type Theme = string;

/** localStorage → prefers-color-scheme fallback (Starlight pattern). */
export function resolveStoredTheme(
	storage: Pick<Storage, 'getItem'> | null,
	media: Pick<MediaQueryList, 'matches'> | null,
	registry: readonly string[]
): Theme {
	let stored: string | null = null;
	try {
		stored = storage?.getItem(THEME_STORAGE_KEY) ?? null;
	} catch {
		/* storage unavailable */
	}
	if (stored && (stored === 'light' || registry.includes(stored))) {
		return stored;
	}
	return media?.matches ? 'light' : 'dark';
}

/** data-theme + color-scheme applied atomically (web.dev color-scheme rule). */
export function applyTheme(theme: Theme, root: HTMLElement = document.documentElement): void {
	if (theme === 'light') {
		root.removeAttribute('data-theme'); // contract: absent = light
	} else {
		root.setAttribute('data-theme', theme);
	}
	root.style.colorScheme = theme === 'dark' ? 'dark' : 'light';
}

/** High-contrast axis (Accessible Starter pattern) — independent of theme. */
export function applyContrast(
	mode: ContrastMode,
	root: HTMLElement = document.documentElement
): void {
	if (mode === 'high') {
		root.setAttribute('data-contrast', 'high');
	} else {
		root.removeAttribute('data-contrast');
	}
}

export function persistTheme(theme: Theme, storage: Pick<Storage, 'setItem'> | null): void {
	try {
		storage?.setItem(THEME_STORAGE_KEY, theme);
	} catch {
		/* storage unavailable */
	}
}

export function persistContrast(
	mode: ContrastMode,
	storage: Pick<Storage, 'setItem'> | null
): void {
	try {
		storage?.setItem(CONTRAST_STORAGE_KEY, mode);
	} catch {
		/* storage unavailable */
	}
}
