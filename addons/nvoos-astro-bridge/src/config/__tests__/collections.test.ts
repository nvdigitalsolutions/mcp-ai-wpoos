/**
 * The local-content catalogue entry (Proposal 135 §D4).
 *
 * `/manage` is the only editorial surface a managed forged site has, so the
 * collection config and its nav entry are part of the contract: a missing
 * entry means an editor can never reach the content the site owns.
 */
import { describe, expect, it } from 'vitest';
import { collections } from '../collections';
import { NAV, defaultSlug, navEntryFor } from '../../auth/permissions';

describe('local collection config', () => {
	const local = collections.find((collection) => collection.slug === 'local');

	it('exists and uses the bridge contract base path', () => {
		expect(local).toBeDefined();
		// The REST base is the slug — /nvoos-commerce/v1/local.
		expect(local?.slug).toBe('local');
	});

	it('is a governed, previewable content collection', () => {
		expect(local?.type).toBe('collection');
		expect(local?.statusFilter).toBe(true);
		expect(local?.previewable).toBe(true);
		expect(local?.defaultColumns).toEqual(['title', 'status', 'updatedAt']);
	});

	it('edits the same field set as the bridged content collections', () => {
		const names = (local?.fields ?? []).map((field) => field.name);
		expect(names).toEqual([
			'title',
			'excerpt',
			'content',
			'seoTitle',
			'seoDescription',
			'publishAt',
			'status',
		]);
	});

	it('offers draft first — publishing is the separate transition', () => {
		const status = local?.fields.find((field) => field.name === 'status');
		expect(status?.options?.[0]).toEqual({ value: 'draft', label: 'Draft' });
	});
});

describe('local nav entry', () => {
	it('sits in the content section', () => {
		expect(navEntryFor('local')).toEqual({
			slug: 'local',
			label: 'Local content',
			section: 'content',
		});
	});

	it('is always reachable', () => {
		// The server enforces manage_astro; the nav has no capability gate.
		expect(NAV.some((entry) => entry.slug === 'local')).toBe(true);
	});

	it('does not hijack the landing page', () => {
		expect(defaultSlug()).toBe('products');
	});
});
