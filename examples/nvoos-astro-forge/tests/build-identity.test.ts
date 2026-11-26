// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// build-identity.test.ts — the stale-deploy detector.
//
// virtuallyjamaica (2026-11-15) served an older release while the local build
// was correct; no gate could see it because every gate ran against the build.
// The marker comparison is the browser-free answer.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { compareBuildIdentity, parseBuildMarker } from '../lib/build-identity.ts';

describe('parseBuildMarker', () => {
	it('reads the marker in either attribute order', () => {
		expect(parseBuildMarker('<meta name="forge-build" content="abc-1">')).toBe('abc-1');
		expect(parseBuildMarker("<meta content='abc-1' name='forge-build'>")).toBe('abc-1');
	});

	it('returns undefined when the page predates the marker', () => {
		expect(parseBuildMarker('<meta name="generator" content="NV oOS Astro Forge">')).toBeUndefined();
	});
});

describe('compareBuildIdentity', () => {
	it('passes only when the ids are equal', () => {
		expect(compareBuildIdentity('abc-1', 'abc-1').status).toBe('pass');
	});

	it('warns on a stale release', () => {
		const verdict = compareBuildIdentity('abc-2', 'abc-1');
		expect(verdict.status).toBe('warn');
		expect(verdict.note).toContain('stale');
	});

	it('warns when the live page has no marker', () => {
		expect(compareBuildIdentity('abc-2', undefined).status).toBe('warn');
	});

	it('skips an unstamped local build', () => {
		expect(compareBuildIdentity(undefined, 'abc-1').status).toBe('skip');
	});
});
