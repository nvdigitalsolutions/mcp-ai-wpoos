// ---------------------------------------------------------------------------
// contrast-audit.test.ts — Phase 1 WCAG 2.2 contrast gate (Proposal 095 §3.1).
//
// Builds the fg/bg pair table for every shipped mode (default light, default
// dark, example-brand overlays on light) from the committed token sources
// (read via JSON imports) and asserts each pair reaches its WCAG 2.2 AA
// threshold:
//   - text pairs → SC 1.4.3 (Contrast Minimum) → 4.5:1 for normal text
//   - UI pairs  → SC 1.4.11 (Non-text Contrast) → 3:1 for UI components
//     (the same 3:1 floor as SC 1.4.3's large-text branch)
// All gated pairs verified passing on 2026-10-30. If a pair ever regresses,
// fix the token value in the theme JSON (prefer the theme layer, keep
// semantic names), re-run, and regenerate with `npm run build:tokens`.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { contrastRatio, semanticTokensOf } from '../../src/lib/tokens/service';
import bundleData from '../../src/tokens/bundle.generated.json';
import componentSource from '../../src/tokens/component.json';
import semanticSource from '../../src/tokens/semantic.json';
import defaultTheme from '../../src/tokens/themes/default.json';
import exampleBrand from '../../src/tokens/themes/example-brand.json';

// --- Text pairs -------------------------------------------------------------
// WCAG 2.2 SC 1.4.3 (Contrast Minimum): body copy and other normal-size text
// need 4.5:1 against its background. Threshold: 4.5:1.
const TEXT_PAIRS: Array<{ fg: string; bg: string }> = [
	{ fg: 'ink', bg: 'surface' },
	{ fg: 'ink', bg: 'surface-alt' },
	{ fg: 'muted', bg: 'surface' },
	{ fg: 'muted', bg: 'surface-alt' },
	{ fg: 'accent-ink', bg: 'accent' },
	{ fg: 'danger', bg: 'surface' },
];

// --- UI pairs ---------------------------------------------------------------
// WCAG 2.2 SC 1.4.11 (Non-text Contrast): UI component parts and graphics
// required to identify the component need 3:1 against adjacent colors (the
// same floor SC 1.4.3 applies to large text). Threshold: 3:1.
const UI_PAIRS: Array<{ fg: string; bg: string }> = [
	{ fg: 'accent', bg: 'surface' },
	{ fg: 'line-strong', bg: 'surface' },
	{ fg: 'line-strong', bg: 'surface-alt' },
];

// --- Component pairs ---------------------------------------------------------
// Every color token in component.json resolves to a semantic token via a
// `{semantic.x}` reference; map component paths to semantic names so the
// audit tracks the shipped refs rather than hardcoding names.
function componentColorRefs(tree: unknown): Record<string, string> {
	const refs: Record<string, string> = {};
	const walk = (node: unknown, parts: string[]): void => {
		if (node === null || typeof node !== 'object' || Array.isArray(node)) return;
		const token = node as { $type?: unknown; $value?: unknown };
		if (token.$type === 'color' && typeof token.$value === 'string') {
			const match = /^\{semantic\.([a-z0-9-]+)\}$/.exec(token.$value);
			if (match) refs[parts.join('.')] = match[1];
			return;
		}
		for (const [key, child] of Object.entries(node as Record<string, unknown>)) {
			if (key.startsWith('$')) continue;
			walk(child, [...parts, key]);
		}
	};
	walk(tree, []);
	return refs;
}

const COMPONENT_REFS = componentColorRefs(componentSource);

interface ComponentPair {
	/** Component token path, or 'surface' for the page surface it sits on. */
	fg: string;
	bg: string;
	/** AA threshold; null = documented but deliberately not gated this phase. */
	min: number | null;
	/** WCAG 2.2 criterion the threshold is derived from. */
	sc: string;
	note: string;
}

// Each pair's threshold choice is documented below per the WCAG criterion:
//   - btn.color vs btn.background — the button label is normal-size text on
//     the button fill → text pair, SC 1.4.3 → 4.5:1.
//   - btn.border vs surface — the button boundary against the page surface;
//     a non-text UI part required to identify the control → UI pair,
//     SC 1.4.11 → 3:1.
//   - field.border vs surface — resolves to {semantic.line-strong} (the
//     additive SC 1.4.11 fix, 2026-10-30): a form-field boundary must reach
//     3:1 against the page surface → UI pair, SC 1.4.11 → 3:1.
const COMPONENT_PAIRS: ComponentPair[] = [
	{ fg: 'btn.color', bg: 'btn.background', min: 4.5, sc: '1.4.3', note: 'button label text on the button fill — text pair' },
	{ fg: 'btn.border', bg: 'surface', min: 3, sc: '1.4.11', note: 'button boundary on the page surface — UI pair' },
	{ fg: 'field.border', bg: 'surface', min: 3, sc: '1.4.11', note: 'form-field boundary on the page surface — UI pair (line-strong)' },
];

// --- Mode palettes -----------------------------------------------------------
// The runtime merge order (058/095): resolved semantic base → theme mode →
// brand overrides → runtime overrides (none at audit time).
const LIGHT_BASE = semanticTokensOf(bundleData);

function modePalette(mode: Record<string, unknown>): Record<string, string> {
	const palette = { ...LIGHT_BASE };
	for (const [name, value] of Object.entries(mode)) {
		if (name.startsWith('$') || typeof value !== 'string') continue;
		palette[name] = value;
	}
	return palette;
}

const MODES: Array<{ name: string; palette: Record<string, string> }> = [
	{ name: 'default light', palette: modePalette(defaultTheme.light) },
	{ name: 'default dark', palette: modePalette(defaultTheme.dark) },
	{
		name: 'example-brand overlays on light',
		palette: { ...LIGHT_BASE, ...(exampleBrand.tokens as Record<string, string>) },
	},
];

function semanticColorNames(tree: unknown): string[] {
	const names: string[] = [];
	const walk = (node: unknown, parts: string[]): void => {
		if (node === null || typeof node !== 'object' || Array.isArray(node)) return;
		const token = node as { $type?: unknown };
		if (token.$type === 'color') {
			names.push(parts.join('.'));
			return;
		}
		for (const [key, child] of Object.entries(node as Record<string, unknown>)) {
			if (key.startsWith('$')) continue;
			walk(child, [...parts, key]);
		}
	};
	walk(tree, []);
	return names;
}

const SEMANTIC_COLORS = semanticColorNames(semanticSource);

/**
 * Semantic colors deliberately not gated, with rationale. SC 1.4.11 applies
 * to parts required to identify a component — decorative hairlines are
 * exempt, and the form-field boundary uses the gated line-strong instead.
 */
const DOCUMENTED_UNGATED: Record<string, string> = {
	line: 'decorative hairline/divider — not required to identify a control; field borders use line-strong',
};

function checkPair(fg: string, bg: string, min: number, label: string): void {
	const ratio = contrastRatio(fg, bg);
	expect(ratio, `${label}: ${fg} on ${bg}`).not.toBeNull();
	const rounded = Math.round((ratio as number) * 100) / 100;
	expect(rounded, `${label}: ${fg} on ${bg}`).toBeGreaterThanOrEqual(min);
}

function resolveComponentColor(palette: Record<string, string>, path: string): string {
	if (path === 'surface') return palette.surface;
	const semanticName = COMPONENT_REFS[path];
	if (!semanticName) throw new Error(`component color token missing from component.json: ${path}`);
	return palette[semanticName];
}

describe('WCAG 2.2 contrast audit (Phase 1 gate)', () => {
	it('covers every color token in component.json (gated or documented)', () => {
		for (const path of Object.keys(COMPONENT_REFS)) {
			expect(
				COMPONENT_PAIRS.some((pair) => pair.fg === path || pair.bg === path),
				`component token ${path} is not covered by the pair table`
			).toBe(true);
		}
	});

	it('covers every color token in semantic.json', () => {
		const covered = new Set<string>();
		for (const pair of [...TEXT_PAIRS, ...UI_PAIRS]) {
			covered.add(pair.fg);
			covered.add(pair.bg);
		}
		for (const name of Object.values(COMPONENT_REFS)) covered.add(name);
		for (const name of SEMANTIC_COLORS) {
			if (DOCUMENTED_UNGATED[name]) continue; // documented, deliberately ungated
			expect(covered.has(name), `semantic color ${name} is not covered by the audit`).toBe(true);
		}
	});

	for (const mode of MODES) {
		describe(mode.name, () => {
			it('text pairs reach SC 1.4.3 (4.5:1)', () => {
				for (const { fg, bg } of TEXT_PAIRS) {
					checkPair(mode.palette[fg], mode.palette[bg], 4.5, 'SC 1.4.3');
				}
			});

			it('UI pairs reach SC 1.4.11 (3:1)', () => {
				for (const { fg, bg } of UI_PAIRS) {
					checkPair(mode.palette[fg], mode.palette[bg], 3, 'SC 1.4.11');
				}
			});

			it('gated component pairs reach their documented threshold', () => {
				for (const pair of COMPONENT_PAIRS) {
					if (pair.min === null) continue; // documented, not gated
					checkPair(
						resolveComponentColor(mode.palette, pair.fg),
						resolveComponentColor(mode.palette, pair.bg),
						pair.min,
						`${pair.fg} on ${pair.bg} (${pair.sc})`
					);
				}
			});
		});
	}
});
