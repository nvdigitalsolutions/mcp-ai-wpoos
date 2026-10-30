// dtcg.test.ts — validator + resolver matrix (Proposal 095). Also
// cross-checks the runtime TS validator against the build-time JS twin.
import { describe, expect, it } from 'vitest';
import {
	TOKEN_TYPE_VALIDATORS,
	findReferences,
	resolveTokenGroups,
	validateGroups,
	validateThemes,
} from '../../src/lib/tokens/dtcg';
import {
	TOKEN_TYPE_VALIDATORS as JS_VALIDATORS,
	findReferences as jsFindReferences,
} from '../../scripts/lib/dtcg.mjs';

describe('value type validators', () => {
	it('accepts the colour formats used by the ADS palette', () => {
		expect(TOKEN_TYPE_VALIDATORS.color('#1a1a1a')).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.color('#fff')).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.color('rgb(26, 26, 26)')).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.color('hsl(120, 10%, 20%)')).toBe(true);
	});

	it('rejects non-colour values (058 CSS-injection discipline)', () => {
		expect(TOKEN_TYPE_VALIDATORS.color('url(https://evil)')).toBe(false);
		expect(TOKEN_TYPE_VALIDATORS.color('red; } body { display:none')).toBe(false);
		expect(TOKEN_TYPE_VALIDATORS.color('expression(alert(1))')).toBe(false);
		expect(TOKEN_TYPE_VALIDATORS.color('')).toBe(false);
	});

	it('accepts dimensions incl. fluid clamps, rejects junk', () => {
		expect(TOKEN_TYPE_VALIDATORS.dimension('8px')).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.dimension('1rem')).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.dimension('1.6')).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.dimension('clamp(1rem, 0.5rem + 2vw, 2rem)')).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.dimension('8px; url(x)')).toBe(false);
	});

	it('accepts font stacks, rejects url()/braces entries', () => {
		expect(TOKEN_TYPE_VALIDATORS.fontFamily(['system-ui', 'sans-serif'])).toBe(true);
		expect(TOKEN_TYPE_VALIDATORS.fontFamily(['url(https://evil)'])).toBe(false);
		expect(TOKEN_TYPE_VALIDATORS.fontFamily(['sans; }'])).toBe(false);
	});

	it('matches the build-time JS twin on the shared cases', () => {
		const cases = ['#1a1a1a', 'red; } body { display:none', '8px', 'clamp(1rem, 2vw, 2rem)', '1.6'];
		for (const value of cases) {
			expect(TOKEN_TYPE_VALIDATORS.dimension(value)).toBe(JS_VALIDATORS.dimension(value));
			expect(TOKEN_TYPE_VALIDATORS.color(value)).toBe(JS_VALIDATORS.color(value));
		}
		expect(findReferences('{core.palette.ink} x {semantic.space}')).toEqual(jsFindReferences('{core.palette.ink} x {semantic.space}'));
	});
});

describe('validateGroups', () => {
	it('rejects invalid values with paths, tolerates $comment keys', () => {
		const report = { errors: [] as string[], warnings: [] as string[] };
		validateGroups(
			{
				bad: {
					$comment: 'ignored',
					token: { $type: 'color', $value: 'not a color' },
				},
			},
			report
		);
		expect(report.errors.some((e) => e.includes('bad.token'))).toBe(true);
	});
});

describe('resolveTokenGroups', () => {
	it('resolves cross-group references including fontFamily arrays', () => {
		const { errors, resolved } = resolveTokenGroups({
			core: { type: { family: { $type: 'fontFamily', $value: ['system-ui', 'sans-serif'] } } },
			semantic: { font: { $type: 'fontFamily', $value: '{core.type.family}' } },
		});
		expect(errors).toEqual([]);
		const font = (resolved.semantic as Record<string, { $value: unknown }>).font;
		expect(font.$value).toEqual(['system-ui', 'sans-serif']);
	});

	it('reports unknown and circular references', () => {
		const unknown = resolveTokenGroups({
			semantic: { x: { $type: 'color', $value: '{core.nope}' } },
		});
		expect(unknown.errors.some((e) => e.includes('unknown reference'))).toBe(true);

		const circular = resolveTokenGroups({
			a: { one: { $type: 'dimension', $value: '{b.two}' } },
			b: { two: { $type: 'dimension', $value: '{a.one}' } },
		});
		expect(circular.errors.some((e) => e.includes('circular'))).toBe(true);
	});
});

describe('validateThemes', () => {
	it('flags unknown semantic overrides as warnings, bad types as errors', () => {
		const report = { errors: [] as string[], warnings: [] as string[] };
		validateThemes(
			{ brand: { tokens: { accent: 'not-a-color', nope: '#fff' } } },
			{ accent: { $type: 'color', $value: '#000' } },
			report
		);
		expect(report.errors.some((e) => e.includes('brand.accent'))).toBe(true);
		expect(report.warnings.some((w) => w.includes('brand.nope'))).toBe(true);
	});

	it('skips $comment keys in the default theme', () => {
		const report = { errors: [] as string[], warnings: [] as string[] };
		validateThemes({ default: { $comment: 'x', light: { accent: '#fff' } } }, { accent: { $type: 'color', $value: '#000' } }, report);
		expect(report.errors).toEqual([]);
	});
});
