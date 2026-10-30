/**
 * Capability/read-only gating (the console's permissions.ts contract).
 * The server is authoritative; these helpers are UX gates fed by the
 * server-authored window.AstroAdminConfig.user block.
 */
import { afterEach, describe, expect, it } from 'vitest';
import {
	adminUser,
	can,
	defaultSlug,
	isCollectionWritable,
	isNavEntryVisible,
	navEntryFor,
} from '../permissions';

afterEach(() => {
	delete (window as any).AstroAdminConfig;
});

describe('adminUser / can', () => {
	it('returns an empty user when the host supplied none', () => {
		expect(adminUser()).toEqual({});
		expect(can('manage_options')).toBe(false);
	});

	it('resolves capabilities from the server-authored user block', () => {
		(window as any).AstroAdminConfig = {
			user: { canManage: true, capabilities: ['manage_astro', 'edit_posts'], readOnlyCollections: [] },
		};
		expect(can('manage_astro')).toBe(true);
		expect(can('manage_options')).toBe(false);
	});
});

describe('isCollectionWritable', () => {
	it('is writable by default', () => {
		(window as any).AstroAdminConfig = { user: { capabilities: [] } };
		expect(isCollectionWritable('products')).toBe(true);
	});

	it('honours the server read-only map (woo stock statuses)', () => {
		(window as any).AstroAdminConfig = {
			user: { canManage: true, capabilities: ['manage_astro'], readOnlyCollections: ['statuses'] },
		};
		expect(isCollectionWritable('statuses')).toBe(false);
		expect(isCollectionWritable('products')).toBe(true);
	});
});

describe('nav catalogue', () => {
	it('exposes every collection plus the managers section', () => {
		expect(navEntryFor('products')?.label).toBe('Products');
		expect(navEntryFor('managers')?.section).toBe('access');
		expect(navEntryFor('nope' as any)).toBeUndefined();
	});

	it('is visible unless a declared capability is missing', () => {
		expect(isNavEntryVisible(navEntryFor('products')!)).toBe(true);
		// A hypothetical owner-only entry: fail-closed for missing caps.
		expect(
			isNavEntryVisible({
				slug: 'managers',
				label: 'Managers',
				section: 'access',
				capability: 'delete_users',
			})
		).toBe(false);
	});

	it('defaults to the first visible catalogue entry', () => {
		expect(defaultSlug()).toBe('products');
	});
});
