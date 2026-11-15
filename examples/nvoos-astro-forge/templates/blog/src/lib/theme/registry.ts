// ---------------------------------------------------------------------------
// Theme registry — Proposal 104 §4.2 (forge variant). The selectable theme
// list is static for now: 'light' (the implicit default — absent data-theme)
// plus 'dark'. When runtime theming lands on the forge (104 Q4), brand
// themes will derive from the forge's OWN token source — the template's
// `--forge-*` block in src/styles/global.css — instead of a hardcoded
// array, mirroring how the velocity storefront derives its list from the
// generated ADS bundle.
// ---------------------------------------------------------------------------

export const ALL_THEMES = ['light', 'dark'] as const;

/** Cycle target — pure, unit-testable (the >3 menu threshold lives in the UI). */
export function nextTheme(current: string, registry: readonly string[] = ALL_THEMES): string {
	const themes = ['light', ...registry.filter((name) => name !== 'light')];
	const index = themes.indexOf(current);
	return themes[(index + 1) % themes.length];
}
