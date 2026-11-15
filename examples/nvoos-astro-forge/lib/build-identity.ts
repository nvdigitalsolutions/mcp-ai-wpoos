// ---------------------------------------------------------------------------
// build-identity.ts — prove the LIVE site is the artifact you just built.
//
// A green build proves the artifact, not the deploy. On 2026-11-15
// virtuallyjamaica served a release older than the checked-in template: the
// local build emitted the band body ink, the live page did not, and the only
// visible symptom was gray copy. `last-modified` is a clock, not an identity.
//
// `Base.astro` stamps `<meta name="forge-build" content="<id>">` from
// `FORGE_BUILD_ID`; `mirror-push` records the same id in `deploy.json`. The
// post-push check reads the live page and compares. Pure functions here so the
// rules are testable without a network.
// ---------------------------------------------------------------------------

/** How a live build id compares with the artifact that was just published. */
export interface BuildIdentityVerdict {
	status: 'pass' | 'warn' | 'skip';
	note: string;
	/** The marker found on the live page (empty when absent). */
	live?: string;
	/** The id the build should have published (empty when unstamped). */
	expected?: string;
}

/**
 * Read `<meta name="forge-build" content="…">` from a document.
 *
 * Attribute order is not a contract (HTML does not preserve it), so the two
 * orders are matched separately rather than with one brittle pattern.
 */
export function parseBuildMarker( html: string ): string | undefined {
	const text = String( html ?? '' );
	const patterns = [
		/<meta\b[^>]*\bname\s*=\s*["']forge-build["'][^>]*\bcontent\s*=\s*["']([^"']*)["'][^>]*>/i,
		/<meta\b[^>]*\bcontent\s*=\s*["']([^"']*)["'][^>]*\bname\s*=\s*["']forge-build["'][^>]*>/i,
	];
	for ( const pattern of patterns ) {
		const match = pattern.exec( text );
		if ( match ) {
			return match[ 1 ].trim();
		}
	}
	return undefined;
}

/**
 * Compare the id the build should carry with the id the live page serves.
 *
 * - an unstamped local build cannot prove anything → `skip` (never a silent
 *   pass; the caller prints the reason);
 * - a live page with no marker is an older release or a cached one → `warn`;
 * - a different marker is a stale deploy → `warn` (the caller's `--strict`
 *   turns it into a failure);
 * - an equal marker is the only `pass`.
 */
export function compareBuildIdentity( expected: string | undefined, live: string | undefined ): BuildIdentityVerdict {
	const wanted = String( expected ?? '' ).trim();
	const actual = String( live ?? '' ).trim();
	if ( ! wanted ) {
		return {
			status: 'skip',
			note: 'the local build carries no FORGE_BUILD_ID — stamp the build to enable the deployed check',
			live: actual || undefined,
		};
	}
	if ( ! actual ) {
		return {
			status: 'warn',
			note: `the live page has no forge-build marker (expected ${ wanted }) — it predates the marker or is serving an older release`,
			expected: wanted,
		};
	}
	if ( actual !== wanted ) {
		return {
			status: 'warn',
			note: `the live release is ${ actual }, this build is ${ wanted } — the deploy is stale`,
			live: actual,
			expected: wanted,
		};
	}
	return { status: 'pass', note: `live release ${ actual } matches the build`, live: actual, expected: wanted };
}
