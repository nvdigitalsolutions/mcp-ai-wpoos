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

/**
 * Read `<meta name="forge-css" content="…">` from a document.
 *
 * The value is the fingerprint `postbuild` stamps over the build's stylesheet
 * asset names, which Vite content-hashes. `forge-build` proves WHICH build is
 * live; this proves the STYLESHEET is the one that came with it. The two were
 * once decoupled in production: a hand-composed `global.css` omitted a
 * committed rule and every gate still passed (2026-11-20).
 */
export function parseStyleMarker( html: string ): string | undefined {
	const text = String( html ?? '' );
	const patterns = [
		/<meta\b[^>]*\bname\s*=\s*["']forge-css["'][^>]*\bcontent\s*=\s*["']([^"']*)["'][^>]*>/i,
		/<meta\b[^>]*\bcontent\s*=\s*["']([^"']*)["'][^>]*\bname\s*=\s*["']forge-css["'][^>]*>/i,
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
 * Compare the stylesheet fingerprint this artifact carries with the one the live
 * page serves.
 *
 * - neither side stamped → `skip` (an older artifact cannot prove anything, and
 *   a silent pass would hide that);
 * - the artifact unstamped → `skip`;
 * - the live page unstamped → `warn`: the artifact names a stylesheet the live
 *   document does not, which is exactly the hand-composed-CSS symptom;
 * - different fingerprints → `warn`, naming both so the mismatch is legible.
 */
export function styleIdentityVerdict(
	expected: string | undefined,
	live: string | undefined
): BuildIdentityVerdict {
	if ( ! expected && ! live ) {
		return { status: 'skip', note: 'neither the artifact nor the live page is stylesheet-stamped' };
	}
	if ( ! expected ) {
		return { status: 'skip', note: 'the artifact carries no stylesheet fingerprint', live };
	}
	if ( ! live ) {
		return {
			status: 'warn',
			note: 'the live page carries no stylesheet fingerprint — it may be serving a different stylesheet than this artifact',
			expected,
		};
	}
	if ( live !== expected ) {
		return {
			status: 'warn',
			note: 'the live stylesheet differs from the artifact — the deploy did not ship this build\u2019s CSS',
			live,
			expected,
		};
	}
	return { status: 'pass', note: 'the live page serves this artifact\u2019s stylesheet', live, expected };
}
