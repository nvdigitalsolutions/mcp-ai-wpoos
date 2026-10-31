// ---------------------------------------------------------------------------
// contrast.test.ts — the WCAG gate math (known pairs, SC 1.4.3).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { contrastRatio, parseHex, passesAaText, relativeLuminance } from '../lib/contrast.ts';

describe('parseHex', () => {
	it('parses 3/6/8-digit forms and rejects junk', () => {
		expect(parseHex('#fff')).toEqual({ r: 255, g: 255, b: 255 });
		expect(parseHex('#0f6b4f')).toEqual({ r: 15, g: 107, b: 79 });
		expect(parseHex('#720eecff')).toEqual({ r: 114, g: 14, b: 236 });
		expect(parseHex('red')).toBeUndefined();
		expect(parseHex('#12')).toBeUndefined();
	});
});

describe('relativeLuminance', () => {
	it('returns the reference values for black and white', () => {
		expect(relativeLuminance('#000000')).toBeCloseTo(0, 5);
		expect(relativeLuminance('#ffffff')).toBeCloseTo(1, 5);
	});
});

describe('contrastRatio', () => {
	it('computes the canonical pairs', () => {
		expect(contrastRatio('#ffffff', '#000000')).toBeCloseTo(21, 1);
		expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 1);
		expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 1);
	});

	it('flags the pilot-flagged pair (#7a7a7a on white fails AA)', () => {
		const ratio = contrastRatio('#7a7a7a', '#ffffff');
		expect(ratio).toBeLessThan(4.5);
		expect(ratio).toBeGreaterThan(4); // 4.29:1 — the documented case
	});

	it('passes the one-token fix (#555555 on white)', () => {
		const ratio = contrastRatio('#555555', '#ffffff');
		expect(passesAaText(ratio as number)).toBe(true);
	});

	it('is symmetric and undefined on unparseable input', () => {
		expect(contrastRatio('#123456', '#abcdef')).toBeCloseTo(contrastRatio('#abcdef', '#123456') as number, 5);
		expect(contrastRatio('nope', '#ffffff')).toBeUndefined();
	});
});

describe('passesAaText', () => {
	it('enforces the 4.5:1 floor', () => {
		expect(passesAaText(4.49)).toBe(false);
		expect(passesAaText(4.5)).toBe(true);
	});
});
