// ---------------------------------------------------------------------------
// copy/index.ts — the typed copy-table registry (Proposal 104 §4.5).
// `Copy` derives from the reference table, so a second locale is added as
// another `Copy`-typed table and registered here — a missing key fails
// `astro check` (the completeness gate is the type itself). Single-locale
// for now: `useCopy()` returns the current locale's table.
// ---------------------------------------------------------------------------

import { en } from './en.ts';

export type Copy = typeof en;

export const copy: Record<string, Copy> = { en };

export const DEFAULT_LOCALE = 'en';

/** The current locale's copy table (falls back to the reference locale). */
export function useCopy(locale: string = DEFAULT_LOCALE): Copy {
	return copy[locale] ?? copy[DEFAULT_LOCALE];
}
