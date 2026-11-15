// publish.test.ts — Proposal 104 §2.1: the single publication predicate
// (astro-theme-cactus "filter everywhere" rule).
import { describe, expect, it } from 'vitest';
import { isPublished } from '../../src/lib/commerce/publish';

describe('isPublished', () => {
	it('treats explicit publish as published', () => {
		expect(isPublished({ status: 'publish' })).toBe(true);
	});

	it('rejects drafts', () => {
		expect(isPublished({ status: 'draft' })).toBe(false);
	});

	it('treats a MISSING status as published (older bridge output)', () => {
		expect(isPublished({})).toBe(true);
	});

	it('rejects non-publish WP statuses (woo-mode docs)', () => {
		expect(isPublished({ status: 'private' })).toBe(false);
		expect(isPublished({ status: 'trash' })).toBe(false);
	});
});
