// tokens-service.test.ts — runtime TokenService: transactional overrides,
// snapshots/rollback, themes, contrast (Proposal 095).
import { describe, expect, it } from 'vitest';
import { TokenService, contrastCheck, contrastRatio } from '../../src/lib/tokens/service';

describe('TokenService', () => {
	it('resolves the semantic layer to the legacy 092 palette in light mode', () => {
		const service = new TokenService();
		const semantic = service.resolveActiveSemantic();
		expect(semantic.ink).toBe('#1a1a1a');
		expect(semantic.accent).toBe('#0f6b4f');
		expect(semantic.radius).toBe('8px');
	});

	it('switches themes and applies brand overrides', () => {
		const service = new TokenService();
		expect(service.applyTheme('dark')).toEqual({ ok: true, message: expect.stringContaining('dark') });
		expect(service.resolveActiveSemantic().surface).toBe('#141414');

		expect(service.applyTheme('example-brand')).toEqual({ ok: true, message: expect.stringContaining('example-brand') });
		expect(service.resolveActiveSemantic().accent).toBe('#1d4ed8');
	});

	it('rejects invalid overrides all-or-nothing (058 transactional rule)', () => {
		const service = new TokenService();
		const result = service.applyOverrides({
			accent: '#ff0000',
			ink: 'url(https://evil)',
		});
		expect(result.applied).toBe(false);
		expect(result.report.errors.length).toBeGreaterThan(0);
		// Nothing applied — not even the valid entry.
		expect(service.resolveActiveSemantic().accent).toBe('#0f6b4f');
	});

	it('supports dry-run diffs without applying', () => {
		const service = new TokenService();
		const result = service.applyOverrides({ accent: '#123456' }, true);
		expect(result.applied).toBe(false);
		expect(result.diff).toEqual([{ name: 'accent', before: '#0f6b4f', after: '#123456' }]);
		expect(service.resolveActiveSemantic().accent).toBe('#0f6b4f');
	});

	it('snapshots and rolls back', () => {
		const service = new TokenService();
		const applied = service.applyOverrides({ accent: '#654321' });
		expect(applied.applied).toBe(true);
		expect(service.resolveActiveSemantic().accent).toBe('#654321');
		const rollback = service.rollback(applied.snapshotId);
		expect(rollback.ok).toBe(true);
		expect(service.resolveActiveSemantic().accent).toBe('#0f6b4f');
	});

	it('imports DTCG documents transactionally', () => {
		const service = new TokenService();
		const bad = service.importDTCG('{ not json');
		expect(bad.applied).toBe(false);
		expect(bad.report.errors.some((e) => e.includes('invalid JSON'))).toBe(true);

		const invalid = service.importDTCG({
			semantic: { accent: { $type: 'color', $value: 'nope' } },
		});
		expect(invalid.applied).toBe(false);
		expect(invalid.report.errors.length).toBeGreaterThan(0);

		const good = service.importDTCG({
			semantic: { accent: { $type: 'color', $value: '#00ff00' } },
		});
		expect(good.applied).toBe(true);
		expect(service.resolveActiveSemantic().accent).toBe('#00ff00');
	});

	it('creates, applies and deletes runtime themes; static themes are protected', () => {
		const service = new TokenService();
		const created = service.createTheme({
			name: 'client-a',
			description: 'Client A brand',
			tokens: { accent: '#7c3aed', 'accent-ink': '#ffffff' },
		});
		expect(created.applied).toBe(true);
		expect(service.listThemes()).toContain('client-a');
		expect(service.applyTheme('client-a').ok).toBe(true);
		expect(service.resolveActiveSemantic().accent).toBe('#7c3aed');

		expect(service.deleteTheme('dark').ok).toBe(false); // static
		expect(service.deleteTheme('client-a').ok).toBe(true);
		expect(service.getActiveTheme()).toBe('light'); // falls back after delete
	});

	it('rejects invalid theme names', () => {
		const service = new TokenService();
		const bad = service.createTheme({ name: 'Bad Name!', tokens: {} });
		expect(bad.applied).toBe(false);
	});
});

describe('contrast', () => {
	it('computes known WCAG ratios', () => {
		expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 0);
		expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
		expect(contrastRatio('#777777', '#ffffff')).toBeCloseTo(4.48, 1);
	});

	it('returns null for unparsable colours', () => {
		expect(contrastRatio('red-ish', '#fff')).toBeNull();
	});

	it('flags AA/AAA pass levels', () => {
		const pass = contrastCheck('#000000', '#ffffff');
		expect(pass?.aaNormal).toBe(true);
		expect(pass?.aaaNormal).toBe(true);
		const fail = contrastCheck('#cccccc', '#ffffff');
		expect(fail?.aaNormal).toBe(false);
	});
});
