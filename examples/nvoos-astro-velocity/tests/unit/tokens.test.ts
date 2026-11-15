// tokens.test.ts — ADS generator + drift gate (Proposal 095). Regenerates
// from src/tokens/*.json and asserts the committed artifacts are current
// and carry the expected light/dark/brand blocks + legacy-compatible values.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { buildTokens, readTokenSources } from '../../scripts/lib/tokens-build.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const APP_ROOT = join(HERE, '..', '..');

describe('ADS token generator', () => {
	const { css, ts, bundle, tailwind, report } = buildTokens(readTokenSources());

	it('validates cleanly', () => {
		expect(report.errors).toEqual([]);
	});

	it('committed generated artifacts are current (drift gate)', () => {
		const committedCss = readFileSync(join(APP_ROOT, 'src', 'styles', 'generated', 'tokens.css'), 'utf8');
		const committedTs = readFileSync(join(APP_ROOT, 'src', 'lib', 'tokens.generated.ts'), 'utf8');
		const committedBundle = readFileSync(join(APP_ROOT, 'src', 'tokens', 'bundle.generated.json'), 'utf8');
		const committedTailwind = readFileSync(join(APP_ROOT, 'src', 'styles', 'generated', 'tokens-tailwind.css'), 'utf8');
		expect(css).toBe(committedCss);
		expect(ts).toBe(committedTs);
		expect(bundle).toBe(committedBundle);
		expect(tailwind).toBe(committedTailwind);
	});

	it('emits the Tailwind v4 bridge over the semantic layer (Proposal 104 D3)', () => {
		expect(tailwind).toContain('@theme inline {');
		expect(tailwind).toContain('--color-ads-accent: var(--ads-semantic-accent);');
		expect(tailwind).toContain('--color-ads-surface: var(--ads-semantic-surface);');
		expect(tailwind).toContain('--font-ads: var(--ads-semantic-font);');
		expect(tailwind).toContain('--radius-ads: var(--ads-semantic-radius);');
		expect(tailwind).toContain('--spacing-ads: var(--ads-semantic-space);');
		// The bridge references the semantic layer — it never bakes raw values.
		expect(tailwind).not.toContain('#0f6b4f');
	});

	it('semantic light values equal the legacy 092 palette (zero visual drift)', () => {
		for (const expected of [
			'--ads-semantic-ink: #1a1a1a;',
			'--ads-semantic-muted: #6b6b6b;',
			'--ads-semantic-surface: #ffffff;',
			'--ads-semantic-surface-alt: #f5f5f4;',
			'--ads-semantic-line: #e4e4e2;',
			'--ads-semantic-accent: #0f6b4f;',
			'--ads-semantic-danger: #b3261e;',
		]) {
			expect(css).toContain(expected);
		}
	});

	it('emits dark-mode and brand-theme blocks', () => {
		expect(css).toContain('[data-theme="dark"] {');
		expect(css).toContain('[data-theme="example-brand"] {');
		expect(css).toContain('--ads-semantic-accent: #3d9c7d;'); // dark accent
		expect(css).toContain('--ads-semantic-accent: #1d4ed8;'); // brand accent
	});

	it('emits typed @property declarations for semantic colours', () => {
		expect(css).toContain('@property --ads-semantic-accent {');
		expect(css).toContain("syntax: '<color>';");
	});

	it('component tokens reference the semantic layer values (resolved)', () => {
		expect(css).toContain('--ads-component-btn-background: #0f6b4f;');
		expect(css).toContain('--ads-component-btn-radius: 8px;');
	});

	it('generated TS exposes the resolved font stack', () => {
		expect(ts).toContain("'font': 'system-ui, -apple-system, \\'Segoe UI\\', Roboto, sans-serif'");
	});

	it('generated TS satisfies the repo lint style (tabs, single quotes)', () => {
		expect(ts).not.toContain('"');
		expect(ts).not.toContain('\t\t');
	});
});
