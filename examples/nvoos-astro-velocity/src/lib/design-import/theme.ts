// ---------------------------------------------------------------------------
// theme.ts — theme emission + verification (Proposal 098 Phase 4).
//
// Turns an analysis into: (1) a tokens_import_dtcg-ready DTCG document, (2) a
// runtime-theme descriptor for theme_create, (3) a WCAG 2.2 contrast audit of
// the critical pairs, and (4) an attribution/NOTICE block. Emission never
// writes anything — the MCP tool decides apply-vs-dry-run.
// ---------------------------------------------------------------------------

import { contrastCheck } from '../tokens/service';
import type { DesignAnalysisResult } from './types';

export interface EmittedTheme {
	/** Suggested theme name (host-slug derived, [a-z0-9-]). */
	name: string;
	/** DTCG document for tokens_import_dtcg (groups + themes shape). */
	dtcgDocument: Record<string, unknown>;
	/** Runtime-theme descriptor for theme_create. */
	themeDescriptor: { name: string; description: string; tokens: Record<string, string> };
	/** WCAG 2.2 audit of the critical pairs. */
	audit: {
		pairs: Array<{ fg: string; bg: string; role: string; ratio: number | null; aaNormal: boolean }>;
		passed: boolean;
	};
	/** Attribution + licensing note for the repo NOTICE discipline. */
	attribution: string[];
}

/** Derive a valid theme name from the source URL's hostname. */
export function themeNameFromUrl(url: string): string {
	try {
		const host = new URL(url).hostname.replace(/^www\./, '');
		const base = host.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32);
		return `imported-${base || 'site'}`;
	} catch {
		return 'imported-site';
	}
}

/** Contract roles → ADS semantic keys (the theme/token runtime vocabulary). */
const ADS_ROLE_KEYS: Record<string, string> = {
	accent: 'accent',
	surface: 'surface',
	text: 'ink',
	'field.border': 'line',
};

/** The semantic tokens the theme carries — confident roles, ADS key names. */
function semanticTokens(analysis: DesignAnalysisResult): Record<string, string> {
	const roles = analysis.designLanguage.palette.roles;
	const tokens: Record<string, string> = {};
	for (const [role, entry] of Object.entries(roles)) {
		const adsKey = ADS_ROLE_KEYS[role];
		if (adsKey && entry.confidence !== 'low') {
			tokens[adsKey] = entry.value;
		}
	}
	return tokens;
}

export function emitTheme(analysis: DesignAnalysisResult, name?: string): EmittedTheme {
	const tokens = semanticTokens(analysis);
	const themeName = name && /^[a-z0-9-]+$/.test(name) ? name : themeNameFromUrl(analysis.source.url);

	// The audit covers the critical pairs — including the derived accent-ink,
	// which is never emitted but still tells us whether the accent holds AA.
	const roles = analysis.designLanguage.palette.roles;
	const pairs: Array<{ fg: string; bg: string; role: string }> = [
		...(roles.accent && roles['accent-ink']
			? [{ fg: roles['accent-ink'].value, bg: roles.accent.value, role: 'accent pair' }]
			: []),
		...(roles.text && roles.surface ? [{ fg: roles.text.value, bg: roles.surface.value, role: 'body pair' }] : []),
		...(roles.accent && roles.surface
			? [{ fg: roles.accent.value, bg: roles.surface.value, role: 'accent-on-surface' }]
			: []),
	];
	const audit = {
		pairs: pairs.map(({ fg, bg, role }) => {
			const result = contrastCheck(fg, bg);
			return { fg, bg, role, ratio: result?.ratio ?? null, aaNormal: result?.aaNormal ?? false };
		}),
		passed: false,
	};
	audit.passed = audit.pairs.length > 0 && audit.pairs.every((pair) => pair.aaNormal);

	const dtcgDocument = {
		groups: { semantic: tokens },
		themes: {
			[themeName]: {
				description: `Imported from ${analysis.source.url} (inspired-by — Proposal 098).`,
				tokens,
			},
		},
	};

	const attribution = [
		`Source: ${analysis.source.url} (mode: ${analysis.source.mode})`,
		analysis.designLanguage.adaptationBrief.licensingNote,
		'Theme name suggestion: ' + themeName,
	];

	return {
		name: themeName,
		dtcgDocument,
		themeDescriptor: {
			name: themeName,
			description: `Imported design language from ${analysis.source.url}`,
			tokens,
		},
		audit,
		attribution,
	};
}
