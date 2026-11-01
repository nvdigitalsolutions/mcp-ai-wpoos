// ---------------------------------------------------------------------------
// contrast.ts — WCAG 2.2 contrast math for the token gate (Proposal 096 §2.4).
//
// The scaffold warns (never blocks) when a profile's design tokens fail
// SC 1.4.3 (4.5:1 for body text) on the pairs the template actually renders:
// ink/surface (body text) and accent-ink/accent (buttons, accents).
// ---------------------------------------------------------------------------

/** Parse #rgb / #rrggbb / #rrggbbaa (alpha ignored) into channels. */
export function parseHex(hex: string): { r: number; g: number; b: number } | undefined {
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
export function passesAaText(ratio: number): boolean {
	return ratio >= 4.5;
}
