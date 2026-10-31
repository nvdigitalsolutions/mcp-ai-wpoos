// ---------------------------------------------------------------------------
// modes/wp.ts — WordPress source mode (Proposal 098 Phase 1.1).
//
// An FSE block theme's theme.json IS a design-token layer (presets emitted
// as CSS custom properties on the frontend). This module derives the theme
// slug from the rendered HTML, fetches the theme's public theme.json
// (same-origin, behind the shared guard), and maps its presets into
// semantic roles — the cleanest WP → DTCG path. The rendered HTML's inline
// `--wp--preset--color--*` variables flow through the analyzer's regular
// CSS-variable pass, so both signals merge without special casing.
// ---------------------------------------------------------------------------

import { fetchGuarded, type FetchGuardOptions } from '../fetcher';

export interface WpThemeJson {
	settings?: {
		color?: { palette?: Array<{ slug?: string; color?: string }> };
		typography?: { fontFamilies?: Array<{ fontFamily?: string; slug?: string }> };
	};
}

export interface WpThemeAssets {
	/** The parsed theme.json, or null when the theme doesn't ship one. */
	themeJson: WpThemeJson | null;
	/** The theme slug derived from the rendered HTML. */
	themeSlug: string | null;
}

/** Derive the theme slug from wp-content links in the rendered HTML. */
export function extractThemeSlug(html: string): string | null {
	const match = html.match(/\/wp-content\/themes\/([a-z0-9_-]+)\//i);
	return match ? match[1] : null;
}

/** Fetch the theme's public theme.json (same-origin, guarded). */
export async function fetchWpThemeAssets(
	html: string,
	finalUrl: string,
	options: FetchGuardOptions = {}
): Promise<WpThemeAssets> {
	const slug = extractThemeSlug(html);
	if (!slug) {
		return { themeJson: null, themeSlug: null };
	}
	const base = new URL(finalUrl);
	const themeJsonUrl = `${base.origin}/wp-content/themes/${slug}/theme.json`;
	try {
		const { body } = await fetchGuarded(themeJsonUrl, {
			...options,
			maxBytes: 256 * 1024,
			accept: ['application/json'],
		});
		try {
			return { themeJson: JSON.parse(body) as WpThemeJson, themeSlug: slug };
		} catch {
			return { themeJson: null, themeSlug: slug }; // Malformed theme.json degrades.
		}
	} catch {
		return { themeJson: null, themeSlug: slug }; // Missing theme.json degrades.
	}
}

/** Map theme.json color presets onto semantic roles (high confidence). */
export function wpThemeJsonRoles(themeJson: WpThemeJson): Array<{ role: string; value: string; source: string }> {
	const palette = themeJson.settings?.color?.palette ?? [];
	const hints: Array<{ role: string; match: RegExp }> = [
		{ role: 'accent', match: /primary|accent|vivid|main/i },
		{ role: 'surface', match: /background|base|surface/i },
		{ role: 'text', match: /foreground|text|contrast|ink/i },
		{ role: 'field.border', match: /border|outline|stroke/i },
	];
	const roles: Array<{ role: string; value: string; source: string }> = [];
	for (const { role, match } of hints) {
		const preset = palette.find((entry) => entry.slug && match.test(entry.slug));
		if (preset?.color && /^#[0-9a-f]{3,8}$/i.test(preset.color.trim())) {
			roles.push({ role, value: preset.color.trim().toLowerCase(), source: `theme.json:${preset.slug}` });
		}
	}
	return roles;
}

/** theme.json font families → analyzer family hints. */
export function wpThemeJsonFamilies(themeJson: WpThemeJson): string[] {
	return (themeJson.settings?.typography?.fontFamilies ?? [])
		.map((entry) => entry.fontFamily?.trim())
		.filter((family): family is string => Boolean(family));
}
