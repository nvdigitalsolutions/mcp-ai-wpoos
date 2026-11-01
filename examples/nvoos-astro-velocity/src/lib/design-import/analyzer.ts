// ---------------------------------------------------------------------------
// analyzer.ts — deterministic static design-language extraction (Proposal 098
// Phase 0.3).
//
// Pure functions: HTML + same-origin CSS in → DesignLanguage out. No network,
// no randomness, no LLM. Role assignment follows the fail-loud discipline:
// semantic CSS custom properties earn 'high' confidence, frequency/saturation
// heuristics earn 'medium', luminance-derived values earn 'low', and
// everything else lands in the unassigned pool.
// ---------------------------------------------------------------------------

import type {
	DesignAnalysisResult,
	DesignLanguage,
	DesignSourceInfo,
	PaletteRole,
} from './types';

/** Mode-specific inputs harvested by the source-mode fetchers (Proposal 098 Phase 1). */
export interface AnalyzeExtra {
	/** theme.json color presets already mapped to roles (high confidence). */
	themeJsonRoles?: Array<{ role: string; value: string; source: string }>;
	/** theme.json font families — authoritative, ranked first. */
	themeJsonFamilies?: string[];
	/** Parsed DTCG-ish token documents from the source. */
	tokenDocuments?: Array<{ path: string; json: unknown }>;
}

/** Colors: hex + rgb()/rgba() + hsl()/hsla(), with frequency counts. */
const HEX_RE = /#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})\b/gi;
const RGB_RE = /rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+)?\s*\)/gi;
const HSL_RE = /hsla?\(\s*([\d.]+)\s*,\s*([\d.]+)%\s*,\s*([\d.]+)%(?:\s*,\s*[\d.]+)?\s*\)/gi;
const CSS_VAR_RE = /(--[\w-]+)\s*:\s*([^;}]+)/g;
const COLOR_VALUE_RE = /^(?:#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})\b|rgba?\(|hsla?\()/i;

/** CSS custom properties whose names hint at a semantic palette role. */
const VAR_ROLE_HINTS: Record<string, RegExp> = {
	// On-accent text is accent-ink material — checked BEFORE accent so
	// `--wc-primary-text` never becomes the body text or the accent itself.
	'accent-ink': /(?:accent|primary)[-_]?(?:text|ink)\b|on-(?:accent|primary)/i,
	// accent|primary|brand as a standalone name segment — but never when
	// followed by -text/-ink/-on (those belong to accent-ink).
	accent: /(?:^|[-_])(?:accent|primary|brand)(?=$|[-_](?!text|ink|on))|highlight/i,
	surface: /background|surface|bg\b|canvas/i,
	// A `-text` segment is body text UNLESS it hangs off a color-role word
	// (primary/secondary/accent/on-…) — those are on-accent text.
	text: /(?<![-_](?:primary|secondary|accent|on|danger|success|warning|info))[-_]text(?:$|[-_])|foreground|content[-_]?text|body[-_]?text|text[-_]?color|(?:^|[-_])ink(?:$|[-_])/i,
	'field.border': /border|stroke|outline/i,
};

const SCALE_CAP = 8;
const UNASSIGNED_CAP = 12;

/** Expand a short hex to 6 digits (#abc → #aabbcc). */
function expandHex(hex: string): string {
	const value = hex.replace('#', '');
	if (value.length === 3) {
		return `#${value
			.split('')
			.map((char) => char + char)
			.join('')}`;
	}
	return `#${value.slice(0, 6)}`;
}

function clampByte(value: number): number {
	return Math.max(0, Math.min(255, Math.round(value)));
}

function rgbToHex(r: number, g: number, b: number): string {
	return `#${[r, g, b]
		.map((channel) => clampByte(channel).toString(16).padStart(2, '0'))
		.join('')}`;
}

/** HSL → hex (h 0–360, s/l 0–100). */
function hslToHex(h: number, s: number, l: number): string {
	const hue = ((h % 360) + 360) % 360;
	const sat = s / 100;
	const light = l / 100;
	const chroma = (1 - Math.abs(2 * light - 1)) * sat;
	const x = chroma * (1 - Math.abs(((hue / 60) % 2) - 1));
	const m = light - chroma / 2;
	let r: number;
	let g: number;
	let b: number;
	if (hue < 60) [r, g, b] = [chroma, x, 0];
	else if (hue < 120) [r, g, b] = [x, chroma, 0];
	else if (hue < 180) [r, g, b] = [0, chroma, x];
	else if (hue < 240) [r, g, b] = [0, x, chroma];
	else if (hue < 300) [r, g, b] = [x, 0, chroma];
	else [r, g, b] = [chroma, 0, x];
	return rgbToHex((r + m) * 255, (g + m) * 255, (b + m) * 255);
}

function hexToRgb(hex: string): [number, number, number] {
	const value = hex.replace('#', '');
	return [parseInt(value.slice(0, 2), 16), parseInt(value.slice(2, 4), 16), parseInt(value.slice(4, 6), 16)];
}

/** HSL hue 0–360 from a hex value (0 = red). */
function hueOf(hex: string): number {
	const [r, g, b] = hexToRgb(hex).map((channel) => channel / 255);
	const max = Math.max(r, g, b);
	const min = Math.min(r, g, b);
	const delta = max - min;
	if (delta === 0) {
		return 0;
	}
	let hue: number;
	if (max === r) hue = ((g - b) / delta) % 6;
	else if (max === g) hue = (b - r) / delta + 2;
	else hue = (r - g) / delta + 4;
	return Math.round(hue * 60);
}

/** HSL saturation 0–100 from a hex value. */
function saturationOf(hex: string): number {
	const [r, g, b] = hexToRgb(hex).map((channel) => channel / 255);
	const max = Math.max(r, g, b);
	const min = Math.min(r, g, b);
	const delta = max - min;
	const light = (max + min) / 2;
	if (delta === 0) {
		return 0;
	}
	return Math.round((delta / (1 - Math.abs(2 * light - 1))) * 100);
}

/** HSL lightness 0–100 from a hex value. */
function lightnessOf(hex: string): number {
	const [r, g, b] = hexToRgb(hex).map((channel) => channel / 255);
	return Math.round(((Math.max(r, g, b) + Math.min(r, g, b)) / 2) * 100);
}

/** Collect inline <style> blocks + style attributes + supplied stylesheets. */
function collectCss(html: string, stylesheets: string[]): string {
	const parts = [...stylesheets];
	for (const match of html.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)) {
		parts.push(match[1]);
	}
	for (const match of html.matchAll(/\bstyle\s*=\s*["']([^"']+)["']/gi)) {
		parts.push(match[1]);
	}
	return parts.join('\n');
}

/** Extract normalized hex colors with frequency counts. */
function extractColorCounts(cssText: string): Map<string, number> {
	const counts = new Map<string, number>();
	const bump = (hex: string) => counts.set(hex, (counts.get(hex) ?? 0) + 1);

	for (const match of cssText.matchAll(HEX_RE)) {
		bump(expandHex(match[0].toLowerCase()));
	}
	for (const match of cssText.matchAll(RGB_RE)) {
		bump(rgbToHex(Number(match[1]), Number(match[2]), Number(match[3])));
	}
	for (const match of cssText.matchAll(HSL_RE)) {
		bump(hslToHex(Number(match[1]), Number(match[2]), Number(match[3])));
	}
	return counts;
}

/** Count how often each color appears in a specific declaration (e.g. `color:`). */
function colorDeclarationCounts(cssText: string, property: string): Map<string, number> {
	const counts = new Map<string, number>();
	const declaration = new RegExp(`${property}\\s*:\\s*([^;}]+)`, 'gi');
	for (const match of cssText.matchAll(declaration)) {
		for (const colorMatch of match[1].matchAll(HEX_RE)) {
			const hex = expandHex(colorMatch[0].toLowerCase());
			counts.set(hex, (counts.get(hex) ?? 0) + 1);
		}
		for (const rgbMatch of match[1].matchAll(RGB_RE)) {
			const hex = rgbToHex(Number(rgbMatch[1]), Number(rgbMatch[2]), Number(rgbMatch[3]));
			counts.set(hex, (counts.get(hex) ?? 0) + 1);
		}
		for (const hslMatch of match[1].matchAll(HSL_RE)) {
			const hex = hslToHex(Number(hslMatch[1]), Number(hslMatch[2]), Number(hslMatch[3]));
			counts.set(hex, (counts.get(hex) ?? 0) + 1);
		}
	}
	return counts;
}

/**
 * Single-value color parsing. NOTE: `String.match()` with a /g regex drops
 * capture groups — every group access below must go through matchAll (the
 * first real-customer run produced #NaNNaNNaN from an rgba() variable).
 */
function firstColorMatch(regex: RegExp, value: string): RegExpExecArray | null {
	const matches = value.matchAll(regex);
	const first = matches.next();
	return first.done ? null : first.value;
}

/** Harvest semantic CSS custom properties whose values are colors. */
function harvestCssVars(cssText: string): Record<string, string> {
	const vars: Record<string, string> = {};
	for (const match of cssText.matchAll(CSS_VAR_RE)) {
		const name = match[1];
		const value = match[2].trim();
		if (!COLOR_VALUE_RE.test(value)) {
			continue;
		}
		const hexMatch = firstColorMatch(HEX_RE, value);
		const rgbMatch = firstColorMatch(RGB_RE, value);
		const hslMatch = firstColorMatch(HSL_RE, value);
		let hex: string | null = null;
		if (hexMatch) {
			hex = expandHex(hexMatch[0].toLowerCase());
		} else if (rgbMatch) {
			hex = rgbToHex(Number(rgbMatch[1]), Number(rgbMatch[2]), Number(rgbMatch[3]));
		} else if (hslMatch) {
			hex = hslToHex(Number(hslMatch[1]), Number(hslMatch[2]), Number(hslMatch[3]));
		}
		// Defense in depth: never let a malformed value (e.g. NaN channels
		// from a nested var() reference) become a role.
		if (hex && /^#[0-9a-f]{6}$/i.test(hex)) {
			vars[name] = hex;
		}
	}
	return vars;
}

/** Walk token documents for $value hex entries and hint-match their key names. */
function collectTokenRoles(docs: Array<{ path: string; json: unknown }>): Array<{ role: string; value: string; source: string }> {
	const out: Array<{ role: string; value: string; source: string }> = [];
	const walk = (node: unknown, trail: string[], path: string): void => {
		if (Array.isArray(node)) {
			node.forEach((entry) => walk(entry, trail, path));
			return;
		}
		if (!node || typeof node !== 'object') {
			return;
		}
		for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
			if (key === '$value' && typeof value === 'string' && /^#[0-9a-f]{3,8}$/i.test(value.trim())) {
				const name = trail[trail.length - 1] ?? 'unknown';
				for (const [role, hint] of Object.entries(VAR_ROLE_HINTS)) {
					if (hint.test(name)) {
						out.push({ role, value: value.trim().toLowerCase(), source: `tokens:${path}#${trail.join('.')}` });
						break;
					}
				}
			} else {
				walk(value, [...trail, key], path);
			}
		}
	};
	for (const doc of docs) {
		walk(doc.json, [], doc.path);
	}
	return out;
}

/** Assign semantic roles — fail-loud: only confident labels, the rest pooled. */
function assignRoles(
	counts: Map<string, number>,
	cssText: string,
	extra: AnalyzeExtra = {}
): {
	roles: Record<string, PaletteRole>;
	unassigned: string[];
} {
	const roles: Record<string, PaletteRole> = {};
	const assigned = new Set<string>();
	const cssVars = harvestCssVars(cssText);

	// Pass 1 — semantic CSS custom properties (high confidence).
	for (const [role, hint] of Object.entries(VAR_ROLE_HINTS)) {
		for (const [name, value] of Object.entries(cssVars)) {
			if (hint.test(name)) {
				roles[role] = { value, confidence: 'high', source: `css:${name}` };
				assigned.add(value);
				break;
			}
		}
	}

	// Pass 1.5 — mode extras: theme.json presets + token documents (high
	// confidence). First non-empty assignment wins per role; CSS vars already
	// claimed a role stay.
	for (const candidate of [...(extra.themeJsonRoles ?? []), ...collectTokenRoles(extra.tokenDocuments ?? [])]) {
		if (!roles[candidate.role] && /^#[0-9a-f]{6}$/i.test(candidate.value)) {
			roles[candidate.role] = { value: candidate.value, confidence: 'high', source: candidate.source };
			assigned.add(candidate.value);
		}
	}

	// Pass 2 — frequency + saturation heuristics (medium confidence).
	const colorCounts = colorDeclarationCounts(cssText, 'color');
	const backgroundCounts = colorDeclarationCounts(cssText, 'background-color');
	const byFrequency = [...counts.entries()].sort((a, b) => b[1] - a[1]);

	const mostFrequent = (declarations: Map<string, number>): string | null => {
		let best: string | null = null;
		let bestCount = 0;
		for (const [hex, count] of declarations) {
			if (count > bestCount) {
				best = hex;
				bestCount = count;
			}
		}
		return best;
	};

	const textColor = mostFrequent(colorCounts);
	if (textColor && !assigned.has(textColor) && !roles.text) {
		roles.text = { value: textColor, confidence: 'medium', source: 'heuristic:color-frequency' };
		assigned.add(textColor);
	}
	const surfaceColor = mostFrequent(backgroundCounts);
	if (surfaceColor && !assigned.has(surfaceColor) && !roles.surface) {
		roles.surface = { value: surfaceColor, confidence: 'medium', source: 'heuristic:background-frequency' };
		assigned.add(surfaceColor);
	}

	// Accent: the most saturated of the frequent colors in a usable band.
	if (!roles.accent) {
		const candidates = byFrequency
			.slice(0, 12)
			.filter(([hex, count]) => {
				const sat = saturationOf(hex);
				const light = lightnessOf(hex);
				return !assigned.has(hex) && count >= 2 && sat >= 30 && light >= 15 && light <= 85;
			})
			.sort((a, b) => saturationOf(b[0]) - saturationOf(a[0]));
		if (candidates.length > 0) {
			roles.accent = { value: candidates[0][0], confidence: 'medium', source: 'heuristic:saturation' };
			assigned.add(candidates[0][0]);
		}
	}

	// accent-ink: contrast ink over the accent (low confidence — derived).
	if (roles.accent && !roles['accent-ink']) {
		const ink = lightnessOf(roles.accent.value) >= 55 ? '#000000' : '#ffffff';
		roles['accent-ink'] = { value: ink, confidence: 'low', source: 'derived:luminance' };
		assigned.add(ink);
	}

	// field.border: nearest low-saturation gray when no semantic var existed.
	if (!roles['field.border'] && roles.text) {
		const grays = byFrequency.filter(([hex, count]) => {
			const sat = saturationOf(hex);
			const light = lightnessOf(hex);
			return !assigned.has(hex) && count >= 2 && sat <= 12 && light >= 40 && light <= 90;
		});
		if (grays.length > 0) {
			roles['field.border'] = { value: grays[0][0], confidence: 'medium', source: 'heuristic:neutral' };
			assigned.add(grays[0][0]);
		}
	}

	// Fail-loud pool: frequent colors with no role.
	const unassigned = byFrequency
		.filter(([hex, count]) => !assigned.has(hex) && count >= 2)
		.slice(0, UNASSIGNED_CAP)
		.map(([hex]) => hex);

	return { roles, unassigned };
}

/** Ranked font families from font-family declarations. */
function extractFamilies(cssText: string): Array<{ family: string; weight: number }> {
	const counts = new Map<string, number>();
	const GENERIC = /^(serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-serif|ui-sans-serif|ui-monospace)$/i;
	for (const match of cssText.matchAll(/font-family\s*:\s*([^;}]+)/gi)) {
		const stack = match[1]
			.split(',')
			.map((part) => part.trim().replace(/^["']|["']$/g, ''))
			.filter(Boolean);
		if (stack.length === 0) {
			continue;
		}
		const family = stack.find((part) => !GENERIC.test(part)) ?? stack[0];
		const normalized = family.replace(/\s+/g, ' ');
		counts.set(normalized, (counts.get(normalized) ?? 0) + 1);
	}
	return [...counts.entries()]
		.sort((a, b) => b[1] - a[1])
		.slice(0, 4)
		.map(([family, weight]) => ({ family, weight }));
}

/** Distinct, ascending, capped px values from a declaration-family regex. */
function extractPxScale(cssText: string, pattern: RegExp): number[] {
	const values = new Set<number>();
	for (const match of cssText.matchAll(pattern)) {
		const value = Math.round(Number(match[1]));
		if (Number.isFinite(value) && value > 0) {
			values.add(value);
		}
	}
	return [...values].sort((a, b) => a - b).slice(0, SCALE_CAP);
}

/** Class-name lexicon hits from the HTML — semantic vocabulary, not layouts. */
function extractVocabulary(html: string): Record<string, number> {
	const words = [
		'button',
		'card',
		'nav',
		'hero',
		'input',
		'field',
		'badge',
		'pill',
		'footer',
		'header',
		'form',
		'grid',
	];
	const counts: Record<string, number> = {};
	for (const match of html.matchAll(/\bclass\s*=\s*["']([^"']+)["']/gi)) {
		const tokens = match[1].toLowerCase().split(/\s+/);
		for (const rawToken of tokens) {
			// btn / btn-* are button aliases — collapse them into one word.
			const token = rawToken === 'btn' ? 'button' : rawToken.replace(/^btn-/, 'button-');
			for (const word of words) {
				if (token === word || token.includes(`${word}-`) || token.includes(`-${word}`) || token.includes(`_${word}`)) {
					counts[word] = (counts[word] ?? 0) + 1;
				}
			}
		}
	}
	return counts;
}

/** Deterministic concept prose — no LLM (Proposal 098 Q5). */
function buildConcept(roles: Record<string, PaletteRole>, families: Array<{ family: string; weight: number }>, spacingPx: number[]): string {
	const accent = roles.accent?.value;
	const surface = roles.surface?.value;
	const text = roles.text?.value;

	let palettePhrase = 'neutral palette';
	if (accent && surface) {
		const hue = hueOf(accent);
		const warmth = hue < 60 || hue >= 300 ? 'warm' : hue >= 150 && hue < 270 ? 'cool' : 'balanced';
		const tone = lightnessOf(surface) >= 60 ? 'light' : 'dark';
		palettePhrase = `${warmth} accent over ${tone} neutrals`;
	}
	let typePhrase = 'sans display / sans body';
	if (families.length > 0) {
		const display = families[0].family;
		const body = families.length > 1 ? families[1].family : display;
		const serif = (name: string) => /serif|slab|didot|garamond|georgia|playfair/i.test(name);
		typePhrase = `${serif(display) ? 'serif' : 'sans'} display / ${serif(body) ? 'serif' : 'sans'} body`;
	}
	const average = spacingPx.length > 0 ? spacingPx.reduce((sum, value) => sum + value, 0) / spacingPx.length : 0;
	const rhythm = average > 24 ? 'airy' : average >= 12 ? 'comfortable' : 'compact';
	void text;
	return `${palettePhrase} palette, ${typePhrase} type, ${rhythm} spacing rhythm.`;
}

/** The whole deterministic analysis. */
export function analyzeDesignLanguage(
	html: string,
	stylesheets: string[],
	source: DesignSourceInfo,
	extra: AnalyzeExtra = {}
): DesignAnalysisResult {
	const cssText = collectCss(html, stylesheets);
	const counts = extractColorCounts(cssText);
	const { roles, unassigned } = assignRoles(counts, cssText, extra);
	const families = extractFamilies(cssText);
	// Authoritative mode families rank first; declaration counts sort the rest.
	for (const family of [...(extra.themeJsonFamilies ?? [])].reverse()) {
		families.unshift({ family, weight: 1000 });
	}
	const scalePx = extractPxScale(cssText, /font-size\s*:\s*(\d+(?:\.\d+)?)px/gi);
	const spacingPx = extractPxScale(
		cssText,
		/(?:padding|margin|gap)(?:-top|-right|-bottom|-left|-inline|-block)?\s*:\s*(\d+(?:\.\d+)?)px/gi
	);
	const radiiPx = extractPxScale(cssText, /border-radius\s*:\s*(\d+(?:\.\d+)?)px/gi).slice(0, 4);

	const shadowSamples: string[] = [];
	for (const match of cssText.matchAll(/box-shadow\s*:\s*([^;}]+)/gi)) {
		if (shadowSamples.length < 2) {
			shadowSamples.push(match[1].trim().slice(0, 80));
		}
	}
	const shadowCount = [...cssText.matchAll(/box-shadow\s*:/gi)].length;

	// tokensDraft carries ONLY confident roles — gaps stay missing on purpose.
	const semantic: Record<string, string> = {};
	for (const [role, entry] of Object.entries(roles)) {
		if (entry.confidence !== 'low') {
			semantic[role] = entry.value;
		}
	}

	const concept = buildConcept(roles, families, spacingPx);

	const designLanguage: DesignLanguage = {
		concept,
		palette: { roles, unassigned },
		typography: { families, scalePx },
		spacing: { scalePx: spacingPx },
		radii: { valuesPx: radiiPx },
		shadows: { count: shadowCount, samples: shadowSamples },
		components: { vocabulary: extractVocabulary(html) },
		tokensDraft: { semantic },
		adaptationBrief: {
			keep: [
				'The palette roles, type pairing, and spacing rhythm — the design language, not the pixels.',
				'Component vocabulary hints (buttons, cards, nav) as a checklist, not as markup.',
			],
			replace: [
				'All copy, imagery, logos, and distinctive artwork with the user\'s own content.',
				'Fonts with licensed equivalents unless the source font is verifiably self-hostable.',
			],
			remix: [
				'Shift the accent hue by roughly 10 degrees and pick the user\'s own brand neutrals — visibly theirs, not a copy.',
				'Let the user\'s real content (products, posts, pages) drive the layout; apply the language to it.',
			],
			licensingNote:
				'Inspired-by analysis: no source CSS, HTML, copy, or assets were copied. Verify the source license before any closer adaptation.',
			attribution: [`Source: ${source.url}`],
		},
	};

	return { source, designLanguage };
}
