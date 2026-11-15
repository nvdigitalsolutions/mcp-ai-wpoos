// ---------------------------------------------------------------------------
// contrast.ts — WCAG 2.2 contrast math for the token gate (Proposal 096 §2.4).
//
// The scaffold warns (never blocks) when a profile's design tokens fail
// SC 1.4.3 (4.5:1 for body text) on the pairs the template actually renders:
// ink/surface (body text) and accent-ink/accent (buttons, accents).
// ---------------------------------------------------------------------------

/**
 * Parse #rgb / #rrggbb / #rrggbbaa (alpha ignored) or rgb()/rgba() into
 * channels.
 *
 * `rgb()` matters: the analyzer records COMPUTED styles, so every measured
 * surface arrives as `background-color:rgb(221, 51, 51)`. Rejecting that form
 * made the ink guard return undefined and bake nothing at all — a silent
 * no-op, not a visible error.
 */
export function parseHex(hex: string): { r: number; g: number; b: number } | undefined {
	const rgb = /^rgba?\(\s*(\d{1,3})[,\s]+(\d{1,3})[,\s]+(\d{1,3})/i.exec(hex.trim());
	if (rgb) {
		const [r, g, b] = [rgb[1], rgb[2], rgb[3]].map(Number);
		return r > 255 || g > 255 || b > 255 ? undefined : { r, g, b };
	}
	const match = /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.exec(hex.trim());
	if (!match) {
		return undefined;
	}
	let value = match[1];
	if (value.length === 3) {
		value = value
			.split('')
			.map((c) => c + c)
			.join('');
	}
	const r = parseInt(value.slice(0, 2), 16);
	const g = parseInt(value.slice(2, 4), 16);
	const b = parseInt(value.slice(4, 6), 16);
	return { r, g, b };
}

/** WCAG 2.2 relative luminance of a hex color. */
export function relativeLuminance(hex: string): number | undefined {
	const channels = parseHex(hex);
	if (!channels) {
		return undefined;
	}
	const [r, g, b] = [channels.r, channels.g, channels.b].map((c) => {
		const s = c / 255;
		return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
	});
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio (1–21). Undefined when a color fails to parse. */
export function contrastRatio(fg: string, bg: string): number | undefined {
	const l1 = relativeLuminance(fg);
	const l2 = relativeLuminance(bg);
	if (l1 === undefined || l2 === undefined) {
		return undefined;
	}
	const [light, dark] = l1 >= l2 ? [l1, l2] : [l2, l1];
	return (light + 0.05) / (dark + 0.05);
}

/** SC 1.4.3 AA pass for body text (4.5:1). */
export function passesAaText( ratio: number ): boolean {
	return ratio >= 4.5;
}

/** SC 1.4.11 AA pass for non-text UI components (3:1). */
export function passesAaNonText( ratio: number ): boolean {
	return ratio >= 3;
}

/**
 * The ink a derived surface needs — one of the two options the caller paints.
 *
 * WHY a shared rule: the forge derives panel ink in two places (the sanitizer's
 * kept `background-color` panels and the scaffold's per-element surfaces). When
 * each picked `Math.max(contrast-on-black, contrast-on-white)` independently,
 * a saturated brand red landed on the wrong side: #dd3333 scores 4.597 on black
 * and 4.568 on white, so the raw max chose BLACK while the source renders
 * white text — a 0.03 margin deciding a 100 ΔE00 colour-gate failure.
 *
 * AA decides first, so an illegible option is never chosen. Only the narrow
 * mid-tone window where BOTH pass (luminance ≈ 0.175–0.183) is genuinely a
 * toss-up; there the light ink wins, because a surface in that window is a
 * saturated brand colour, not a near-white one.
 *
 * `options` lets each caller keep its own representation: the scaffold matches
 * the source's pure black, the sanitizer uses a softer near-black for panels.
 * Swapping one for the other is not free — #000000 → #1a1a1a is ΔE00 5.5.
 */
export function readableInk(
	color: string,
	options: { light?: string; dark?: string } = {}
): string | undefined {
	const light = options.light ?? '#ffffff';
	const dark = options.dark ?? '#000000';
	const onBlack = contrastRatio( color, '#000000' );
	const onWhite = contrastRatio( color, '#ffffff' );
	if ( onBlack === undefined || onWhite === undefined ) {
		return undefined;
	}
	const blackPasses = passesAaText( onBlack );
	const whitePasses = passesAaText( onWhite );
	if ( blackPasses && ! whitePasses ) {
		return dark;
	}
	if ( whitePasses ) {
		return light;
	}
	// Neither clears AA (only possible for a mid-tone just outside the window) —
	// the better of the two is still the best available choice.
	return onWhite >= onBlack ? light : dark;
}

// -- the multi-mode audit (Proposal 114 §3.5) --------------------------------

export type ContrastMode = 'light' | 'dark' | 'high';

export interface ContrastAuditPair {
	/** Foreground token key (ink, muted, accent-ink, line, …). */
	token: string;
	/** Background token key. */
	background: string;
	/** The rendered pair label (body text, button text, …). */
	label: string;
	mode: ContrastMode;
	/** WCAG threshold (4.5 text, 3 non-text). */
	required: number;
	/** Computed ratio (undefined when a value failed to parse). */
	ratio?: number;
	pass: boolean;
}

export interface ContrastAudit {
	pairs: ContrastAuditPair[];
	failures: ContrastAuditPair[];
	/** Values that failed to parse — the pair was not auditable. */
	unparsed: ContrastAuditPair[];
}

/**
 * The pairs the template actually renders, per mode (114 §3.5): body text,
 * secondary text, text-on-accent, and the line/border non-text pair — in
 * light, dark (the template's dark block) and high-contrast (muted/line
 * collapse onto ink) variants. The token keys resolve against a merged
 * token map, so both the scaffold gate and the generated project's
 * build-time `/design-audit.json` endpoint share one definition.
 */
export const AUDIT_PAIRS: ReadonlyArray<{
	fg: string;
	bg: string;
	label: string;
	required: number;
	mode: ContrastMode;
}> = [
	{ fg: 'ink', bg: 'surface', label: 'body text (ink on surface)', required: 4.5, mode: 'light' },
	{ fg: 'muted', bg: 'surface', label: 'secondary text (muted on surface)', required: 4.5, mode: 'light' },
	{ fg: 'accent-ink', bg: 'accent', label: 'text on accent (accent-ink on accent)', required: 4.5, mode: 'light' },
	{ fg: 'line-strong', bg: 'surface', label: 'input borders (line-strong on surface)', required: 3, mode: 'light' },
	{ fg: 'ink', bg: 'surface', label: 'body text (dark mode)', required: 4.5, mode: 'dark' },
	{ fg: 'muted', bg: 'surface', label: 'secondary text (dark mode)', required: 4.5, mode: 'dark' },
	{ fg: 'accent-ink', bg: 'accent', label: 'text on accent (dark mode)', required: 4.5, mode: 'dark' },
	{ fg: 'line-strong', bg: 'surface', label: 'input borders (dark mode)', required: 3, mode: 'dark' },
	{ fg: 'ink', bg: 'surface', label: 'body text (high contrast)', required: 4.5, mode: 'high' },
	{ fg: 'line-strong', bg: 'surface', label: 'input borders (high contrast)', required: 3, mode: 'high' },
];

/**
 * Audit every rendered pair across modes. `tokens` carries the light values;
 * `darkTokens`/`highTokens` override per mode (high contrast collapses
 * muted/line onto ink by default). Pure — no I/O.
 */
export function contrastAudit(
	tokens: Record<string, string>,
	darkTokens: Record<string, string> = {},
	highTokens: Record<string, string> = {}
): ContrastAudit {
	const audit: ContrastAudit = { pairs: [], failures: [], unparsed: [] };
	for ( const pair of AUDIT_PAIRS ) {
		// The high-contrast axis audits over the LIGHT theme (the dark theme's
		// pairs are audited separately) — high contrast is its own axis, not
		// a third palette.
		const resolved =
			pair.mode === 'dark' ? { ...tokens, ...darkTokens } : pair.mode === 'high' ? { ...tokens, ...highTokens } : tokens;
		const fg = resolved[ pair.fg ];
		const bg = resolved[ pair.bg ];
		const entry: ContrastAuditPair = {
			token: pair.fg,
			background: pair.bg,
			label: pair.label,
			mode: pair.mode,
			required: pair.required,
			pass: false,
		};
		const ratio = contrastRatio( fg ?? '', bg ?? '' );
		if ( ratio === undefined ) {
			entry.pass = false;
			audit.unparsed.push( entry );
		} else {
			entry.ratio = ratio;
			entry.pass = pair.required >= 4.5 ? passesAaText( ratio ) : passesAaNonText( ratio );
			if ( ! entry.pass ) {
				audit.failures.push( entry );
			}
		}
		audit.pairs.push( entry );
	}
	return audit;
}

/** Human-readable scaffold warnings for an audit (one line per problem). */
export function auditWarnings( audit: ContrastAudit ): string[] {
	const warnings: string[] = [];
	for ( const pair of audit.unparsed ) {
		warnings.push( `Token pair ${ pair.token }/${ pair.background } (${ pair.mode }) failed to parse — contrast not checked.` );
	}
	for ( const pair of audit.failures ) {
		warnings.push(
			`WCAG: ${ pair.label } contrast is ${ pair.ratio?.toFixed( 2 ) ?? '?' }:1 — below the ${ pair.required }:1 AA minimum (${ pair.mode } mode).`
		);
	}
	return warnings;
}
