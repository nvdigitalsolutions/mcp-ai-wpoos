// content-merge.test.ts — Proposal 106 P2: the L2 merge matrix.
// Envelope wins slug collisions; local fills gaps; posts sort date desc.
import { describe, expect, it } from 'vitest';
import {
	localBySlug,
	mergeEnvelopeLocal,
	mergePostsDesc,
	type PostEntryLike,
} from '../../src/lib/content/merge';

const envelopePost = (slug: string, date: string): PostEntryLike => ({ slug, date });
const localPost = (slug: string, date: string): PostEntryLike => ({ slug, date });

describe('mergeEnvelopeLocal (L2)', () => {
	it('keeps envelope docs first and in order', () => {
		const merged = mergeEnvelopeLocal(
			[{ slug: 'a' }, { slug: 'b' }],
			[{ slug: 'c' }]
		);
		expect(merged.map((doc) => doc.slug)).toEqual(['a', 'b', 'c']);
	});

	it('envelope wins slug collisions (local dropped)', () => {
		const merged = mergeEnvelopeLocal(
			[{ slug: 'about' }, { slug: 'terms' }],
			[{ slug: 'about' }, { slug: 'privacy' }]
		);
		expect(merged).toHaveLength(3);
		expect(merged.map((doc) => doc.slug)).toEqual(['about', 'terms', 'privacy']);
	});

	it('local fills gaps when the envelope is empty', () => {
		const merged = mergeEnvelopeLocal([], [{ slug: 'x' }, { slug: 'y' }]);
		expect(merged.map((doc) => doc.slug)).toEqual(['x', 'y']);
	});

	it('passes the envelope content through when local is empty', () => {
		const envelope = [{ slug: 'a' }];
		expect(mergeEnvelopeLocal(envelope, [])).toEqual([{ slug: 'a' }]);
	});

	it('handles a local doc colliding with a later envelope doc', () => {
		const merged = mergeEnvelopeLocal(
			[{ slug: 'a' }],
			[{ slug: 'a' }, { slug: 'b' }]
		);
		expect(merged.map((doc) => doc.slug)).toEqual(['a', 'b']);
	});
});

describe('mergePostsDesc', () => {
	it('sorts the merged list date-descending', () => {
		const merged = mergePostsDesc(
			[envelopePost('older', '2026-10-01T00:00:00.000Z')],
			[localPost('newer', '2026-10-20T00:00:00.000Z')]
		);
		expect(merged.map((post) => post.slug)).toEqual(['newer', 'older']);
	});

	it('envelope wins the collision and its date sorts into place', () => {
		const merged = mergePostsDesc(
			[envelopePost('shared', '2026-10-10T00:00:00.000Z')],
			[localPost('shared', '2026-10-30T00:00:00.000Z'), localPost('older', '2026-10-01T00:00:00.000Z')]
		);
		expect(merged).toHaveLength(2);
		expect(merged[0].slug).toBe('shared');
		expect(merged[0].date).toBe('2026-10-10T00:00:00.000Z'); // envelope copy won
		expect(merged[1].slug).toBe('older');
	});

	it('sorts envelope-only lists', () => {
		const merged = mergePostsDesc(
			[
				envelopePost('mid', '2026-10-05T00:00:00.000Z'),
				envelopePost('newest', '2026-10-25T00:00:00.000Z'),
			],
			[]
		);
		expect(merged.map((post) => post.slug)).toEqual(['newest', 'mid']);
	});
});

describe('localBySlug', () => {
	it('finds the local doc for a slug (collection entries key on id)', () => {
		const local = [{ id: 'terms' }];
		expect(localBySlug(local, 'terms')).toEqual({ id: 'terms' });
	});

	it('returns null when the slug is absent', () => {
		expect(localBySlug([{ id: 'terms' }], 'missing')).toBeNull();
	});
});
