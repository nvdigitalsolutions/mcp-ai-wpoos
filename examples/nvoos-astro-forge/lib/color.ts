// color.ts — the shared color-conversion core (Proposal 126 D2).
// Byte-synced copies live in the forge analyzer and the storefront
// design-import module — edit THIS file first, then copy (the drift gates
// in each consumer pin the identity). Deliberate divergences stay per-app:
// `lightnessOf` is WCAG luminance in forge and HSL lightness in the
// storefront, and their hue/saturation helpers follow different formulas —
// see the README's unification notes.

/** Expand a short hex to 6 digits (#abc → #aabbcc); alpha is dropped. */
export function expandHex(hex: string): string {
	const value = hex.replace('#', '');
	if (value.length === 3) {
		return `#${value
			.split('')
			.map((char) => char + char)
			.join('')}`;
	}
	return `#${value.slice(0, 6)}`;
}

export function clampByte(value: number): number {
	return Math.max(0, Math.min(255, Math.round(value)));
}

export function rgbToHex(r: number, g: number, b: number): string {
	return `#${[r, g, b]
		.map((channel) => clampByte(channel).toString(16).padStart(2, '0'))
		.join('')}`;
}

/** HSL → hex (h 0–360, s/l 0–100). */
export function hslToHex(h: number, s: number, l: number): string {
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

export function hexToRgb(hex: string): [number, number, number] {
	const value = hex.replace('#', '');
	return [parseInt(value.slice(0, 2), 16), parseInt(value.slice(2, 4), 16), parseInt(value.slice(4, 6), 16)];
}
