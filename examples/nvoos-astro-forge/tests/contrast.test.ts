// ---------------------------------------------------------------------------
// contrast.test.ts — the WCAG gate math (known pairs, SC 1.4.3).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditWarnings, contrastAudit, contrastRatio, parseHex, passesAaNonText, passesAaText, relativeLuminance } from '../lib/contrast.ts';

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

describe('contrastAudit (Proposal 114 §3.5)', () => {
	it('passes every rendered pair on the template defaults (light + dark + high)', () => {
		const audit = contrastAudit(
			{
				ink: '#1a1a1a',
				muted: '#6b6b6b',
			surface: '#ffffff',
			accent: '#0f6b4f',
			'accent-ink': '#ffffff',
			'line-strong': '#767676',
		},
		{
			ink: '#f5f5f4',
			muted: '#a3a3a3',
			surface: '#171717',
			'line-strong': '#6b6b6b',
		},
		{ muted: '#1a1a1a', 'line-strong': '#1a1a1a' }
		);
		expect(audit.failures).toEqual([]);
		expect(audit.unparsed).toEqual([]);
		expect(audit.pairs).toHaveLength(10);
	});

	it('flags the pilot pair (text #7a7a7a on white) as a light-mode failure with a ratio', () => {
		const audit = contrastAudit({ ink: '#7a7a7a', muted: '#6b6b6b', surface: '#ffffff', accent: '#0f6b4f', 'accent-ink': '#ffffff', 'line-strong': '#767676' });
		const failure = audit.failures.find((pair) => pair.token === 'ink' && pair.mode === 'light');
		expect(failure).toBeDefined();
		expect(failure?.ratio).toBeCloseTo(4.29, 1);
		expect(auditWarnings(audit).some((w) => w.includes('4.5:1'))).toBe(true);
	});

	it('audits the high-contrast axis over the LIGHT theme (not the dark palette)', () => {
		// Regression: the high-contrast pairs resolved against the dark
		// surface made default scaffolds fail with ~1:1 ratios.
		const audit = contrastAudit(
			{ ink: '#1a1a1a', muted: '#6b6b6b', surface: '#ffffff', accent: '#0f6b4f', 'accent-ink': '#ffffff', 'line-strong': '#767676' },
			{ ink: '#f5f5f4', muted: '#a3a3a3', surface: '#171717', 'line-strong': '#6b6b6b' },
			{ muted: '#1a1a1a', 'line-strong': '#1a1a1a' }
		);
		const highInk = audit.pairs.find((pair) => pair.token === 'ink' && pair.mode === 'high');
		expect(highInk?.ratio).toBeGreaterThan(4.5);
	});

	it('collects unparseable pairs and the non-text 3:1 floor', () => {
		const audit = contrastAudit({ ink: 'red', muted: '#6b6b6b', surface: '#ffffff', accent: '#0f6b4f', 'accent-ink': '#ffffff', 'line-strong': '#e4e4e2' });
		expect(audit.unparsed.length).toBeGreaterThan(0);
		expect(audit.failures.some((pair) => pair.token === 'line-strong')).toBe(true); // hairline fails 3:1
		expect(passesAaNonText(3)).toBe(true);
		expect(passesAaNonText(2.99)).toBe(false);
	});
});
