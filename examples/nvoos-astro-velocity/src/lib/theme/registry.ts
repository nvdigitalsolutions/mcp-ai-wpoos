// ---------------------------------------------------------------------------
// Theme registry — Proposal 104 §1.2. The selectable theme list derives from
// the generated ADS bundle (tokens.generated.ts → adsThemeNames), never from
// a hardcoded array: a brand theme added via the /mcp theme_create flow or a
// DTCG import shows up here on the next `npm run build:tokens` + build.
//
// 'default' (the ADS light/dark carrier) is not a selectable name — its light
// values are the :root block and its dark values ride data-theme="dark".
// ---------------------------------------------------------------------------

import { adsThemeNames } from '../tokens.generated';

export const ALL_THEMES: readonly string[] = adsThemeNames;

/** Cycle target — pure, unit-testable (the >3 menu threshold lives in the UI). */
export function nextTheme(current: string, registry: readonly string[]): string {
	const themes = ['light', ...registry.filter((name) => name !== 'light')];
	const index = themes.indexOf(current);
	return themes[(index + 1) % themes.length];
}
