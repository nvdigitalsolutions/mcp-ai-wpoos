// policy.test.ts — Proposal 105 P2: the theme-policy module ladder.
// Pins: forced light/dark/brand, unknown-slug fail-open, forced-ignores-
// storage, the auto ladder (stored → media → defaultTheme), the contrast
// ladder and the lenient doc parser.
import { describe, expect, it } from 'vitest';
import {
	DEFAULT_POLICY_DOC,
	parsePolicyDoc,
	resolvePolicyTheme,
	type ThemePolicy,
} from '../../src/lib/theme/policy';

const REGISTRY = ['light', 'dark', 'example-brand'] as const;

const doc = (overrides: Partial<ThemePolicy>): ThemePolicy => ({
	...DEFAULT_POLICY_DOC,
	...overrides,
});

describe('resolvePolicyTheme — forced ladder', () => {
	it('forces light and ignores a stored dark theme', () => {
		const result = resolvePolicyTheme(doc({ policy: 'light' }), 'dark', true, REGISTRY);
		expect(result).toEqual({ theme: 'light', contrast: 'standard', forced: true, contrastForced: false });
	});

	it('forces dark and ignores a stored light theme', () => {
		const result = resolvePolicyTheme(doc({ policy: 'dark' }), 'light', true, REGISTRY);
		expect(result).toEqual({ theme: 'dark', contrast: 'standard', forced: true, contrastForced: false });
	});

	it('forces a valid brand slug against the registry', () => {
		const result = resolvePolicyTheme(
			doc({ policy: 'brand:example-brand' }),
			'dark',
			true,
			REGISTRY
		);
		expect(result.theme).toBe('example-brand');
		expect(result.forced).toBe(true);
	});

	it('fails open to auto on an unknown brand slug (forced: false)', () => {
		const result = resolvePolicyTheme(
			doc({ policy: 'brand:ghost-brand' }),
			null,
			true,
			REGISTRY
		);
		expect(result.theme).toBe('light'); // media light
		expect(result.forced).toBe(false);
	});

	it('unknown brand slug still honours the stored theme (auto ladder)', () => {
		const result = resolvePolicyTheme(
			doc({ policy: 'brand:ghost-brand' }),
			'dark',
			false,
			REGISTRY
		);
		expect(result.theme).toBe('dark');
		expect(result.forced).toBe(false);
	});
});

describe('resolvePolicyTheme — auto ladder', () => {
	it('prefers a valid stored theme over the media query', () => {
		const result = resolvePolicyTheme(doc({ policy: 'auto' }), 'dark', true, REGISTRY);
		expect(result.theme).toBe('dark');
	});

	it('accepts a stored brand theme from the registry', () => {
		const result = resolvePolicyTheme(doc({ policy: 'auto' }), 'example-brand', true, REGISTRY);
		expect(result.theme).toBe('example-brand');
	});

	it('ignores a stored value that left the registry, then falls to media', () => {
		const result = resolvePolicyTheme(doc({ policy: 'auto' }), 'ghost-brand', false, REGISTRY);
		expect(result.theme).toBe('dark');
	});

	it('falls to the media query when nothing is stored (light and dark)', () => {
		expect(resolvePolicyTheme(doc({ policy: 'auto' }), null, true, REGISTRY).theme).toBe('light');
		expect(resolvePolicyTheme(doc({ policy: 'auto' }), null, false, REGISTRY).theme).toBe('dark');
	});

	it('falls to defaultTheme when no media query exists', () => {
		const dark = resolvePolicyTheme(doc({ policy: 'auto', defaultTheme: 'dark' }), null, null, REGISTRY);
		expect(dark.theme).toBe('dark');
		const light = resolvePolicyTheme(doc({ policy: 'auto', defaultTheme: 'light' }), null, null, REGISTRY);
		expect(light.theme).toBe('light');
	});

	it('never returns a forced flag on the auto ladder', () => {
		const result = resolvePolicyTheme(doc({ policy: 'auto' }), 'dark', null, REGISTRY);
		expect(result.forced).toBe(false);
	});
});

describe('resolvePolicyTheme — contrast ladder', () => {
	it('forces high contrast and ignores stored contrast', () => {
		const result = resolvePolicyTheme(doc({ contrast: 'high' }), null, true, REGISTRY, 'standard');
		expect(result.contrast).toBe('high');
		expect(result.contrastForced).toBe(true);
	});

	it('forces standard contrast over a stored high value', () => {
		const result = resolvePolicyTheme(doc({ contrast: 'standard' }), null, true, REGISTRY, 'high');
		expect(result.contrast).toBe('standard');
		expect(result.contrastForced).toBe(true);
	});

	it('auto honours stored high contrast', () => {
		const result = resolvePolicyTheme(doc({ contrast: 'auto' }), null, true, REGISTRY, 'high');
		expect(result.contrast).toBe('high');
		expect(result.contrastForced).toBe(false);
	});

	it('auto falls back to standard with no stored contrast', () => {
		const result = resolvePolicyTheme(doc({ contrast: 'auto' }), null, true, REGISTRY, null);
		expect(result.contrast).toBe('standard');
		expect(result.contrastForced).toBe(false);
	});
});

describe('parsePolicyDoc — lenient typing', () => {
	it('round-trips a valid doc', () => {
		const source = {
			policy: 'brand:example-brand',
			contrast: 'high',
			defaultTheme: 'light',
			updatedAt: '2026-11-03T09:00:00Z',
			$extensions: {},
		};
		expect(parsePolicyDoc(source)).toEqual(source);
	});

	it('returns the default doc for non-object input', () => {
		expect(parsePolicyDoc(null)).toEqual(DEFAULT_POLICY_DOC);
		expect(parsePolicyDoc('auto')).toEqual(DEFAULT_POLICY_DOC);
		expect(parsePolicyDoc([1, 2])).toEqual(DEFAULT_POLICY_DOC);
	});

	it('fills invalid fields with defaults', () => {
		const parsed = parsePolicyDoc({
			policy: 'brand:Bad Slug!',
			contrast: 'maximum',
			defaultTheme: 'sepia',
			updatedAt: 42,
		});
		expect(parsed.policy).toBe('auto');
		expect(parsed.contrast).toBe('auto');
		expect(parsed.defaultTheme).toBe('dark');
		expect(parsed.updatedAt).toBe('');
	});

	it('rejects brand slugs with invalid characters', () => {
		expect(parsePolicyDoc({ policy: 'brand:UPPER' }).policy).toBe('auto');
		expect(parsePolicyDoc({ policy: 'brand:with.dot' }).policy).toBe('auto');
		expect(parsePolicyDoc({ policy: 'brand:' }).policy).toBe('auto');
	});

	it('accepts every valid policy shape', () => {
		expect(parsePolicyDoc({ policy: 'auto' }).policy).toBe('auto');
		expect(parsePolicyDoc({ policy: 'light' }).policy).toBe('light');
		expect(parsePolicyDoc({ policy: 'dark' }).policy).toBe('dark');
		expect(parsePolicyDoc({ policy: 'brand:example-brand' }).policy).toBe('brand:example-brand');
	});
});
