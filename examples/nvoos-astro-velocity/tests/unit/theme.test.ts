// theme.test.ts — Proposal 104 P1: the client theme module + registry.
// Pins: storage-as-truth resolution, idempotent application, contrast axis,
// registry derivation from the ADS bundle, and the cycle math.
import { describe, expect, it } from 'vitest';
import {
	applyContrast,
	applyTheme,
	persistContrast,
	persistTheme,
	resolveStoredTheme,
} from '../../src/lib/theme/theme';
import { ALL_THEMES, nextTheme } from '../../src/lib/theme/registry';

function fakeStorage(seed: Record<string, string> = {}): Pick<Storage, 'getItem' | 'setItem'> {
	const store = new Map(Object.entries(seed));
	return {
		getItem: (key: string) => store.get(key) ?? null,
		setItem: (key: string, value: string) => {
			store.set(key, value);
		},
	};
}

function fakeMedia(matches: boolean): Pick<MediaQueryList, 'matches'> {
	return { matches };
}

/** Minimal HTMLElement stand-in for apply* functions. */
class FakeRoot {
	attributes = new Map<string, string>();
	style: { colorScheme: string } = { colorScheme: '' };

	setAttribute(name: string, value: string) {
		this.attributes.set(name, value);
	}

	removeAttribute(name: string) {
		this.attributes.delete(name);
	}

	getAttribute(name: string): string | null {
		return this.attributes.get(name) ?? null;
	}
}

describe('resolveStoredTheme', () => {
	const registry = ALL_THEMES as readonly string[];

	it('returns a valid stored theme', () => {
		expect(resolveStoredTheme(fakeStorage({ 'nvoos-theme': 'dark' }), null, registry)).toBe(
			'dark'
		);
	});

	it('ignores an invalid stored value (removed brand theme)', () => {
		expect(
			resolveStoredTheme(fakeStorage({ 'nvoos-theme': 'ghost-brand' }), null, registry)
		).toBe('dark');
	});

	it('falls back to the media query when nothing is stored', () => {
		expect(resolveStoredTheme(fakeStorage(), fakeMedia(true), registry)).toBe('light');
		expect(resolveStoredTheme(fakeStorage(), fakeMedia(false), registry)).toBe('dark');
	});

	it('survives unavailable storage', () => {
		expect(resolveStoredTheme(null, fakeMedia(false), registry)).toBe('dark');
	});
});

describe('applyTheme / applyContrast', () => {
	it('sets data-theme and color-scheme for dark', () => {
		const root = new FakeRoot();
		applyTheme('dark', root as unknown as HTMLElement);
		expect(root.getAttribute('data-theme')).toBe('dark');
		expect(root.style.colorScheme).toBe('dark');
	});

	it('removes data-theme for light (contract: absent = light)', () => {
		const root = new FakeRoot();
		root.setAttribute('data-theme', 'dark');
		applyTheme('light', root as unknown as HTMLElement);
		expect(root.getAttribute('data-theme')).toBeNull();
		expect(root.style.colorScheme).toBe('light');
	});

	it('is idempotent across repeated application (after-swap pattern)', () => {
		const root = new FakeRoot();
		applyTheme('example-brand', root as unknown as HTMLElement);
		applyTheme('example-brand', root as unknown as HTMLElement);
		expect(root.getAttribute('data-theme')).toBe('example-brand');
	});

	it('sets and clears the high-contrast attribute', () => {
		const root = new FakeRoot();
		applyContrast('high', root as unknown as HTMLElement);
		expect(root.getAttribute('data-contrast')).toBe('high');
		applyContrast('standard', root as unknown as HTMLElement);
		expect(root.getAttribute('data-contrast')).toBeNull();
	});

	it('persists theme and contrast through the storage seam', () => {
		const storage = fakeStorage();
		persistTheme('dark', storage);
		persistContrast('high', storage);
		expect(storage.getItem('nvoos-theme')).toBe('dark');
		expect(storage.getItem('nvoos-contrast')).toBe('high');
	});
});

describe('theme registry', () => {
	it('derives from the ADS bundle (drift pin — fails if build:tokens drops themes)', () => {
		expect(ALL_THEMES).toContain('light');
		expect(ALL_THEMES).toContain('dark');
		expect(ALL_THEMES).toContain('example-brand');
		// 'default' is the ADS carrier, not a selectable name.
		expect(ALL_THEMES).not.toContain('default');
	});

	it('cycles light → dark → brand → light', () => {
		expect(nextTheme('light', ALL_THEMES)).toBe('dark');
		expect(nextTheme('dark', ALL_THEMES)).toBe('example-brand');
		expect(nextTheme('example-brand', ALL_THEMES)).toBe('light');
	});

	it('wraps unknown/removed themes back to light', () => {
		expect(nextTheme('ghost-brand', ALL_THEMES)).toBe('light');
	});

	it('keeps brand themes at the end of the cycle (registry > 3)', () => {
		const registry = ['light', 'dark', 'brand-a', 'brand-b'];
		expect(nextTheme('light', registry)).toBe('dark');
		expect(nextTheme('dark', registry)).toBe('brand-a');
		expect(nextTheme('brand-b', registry)).toBe('light');
	});
});
