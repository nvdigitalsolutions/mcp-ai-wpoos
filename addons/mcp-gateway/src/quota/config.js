/**
 * Per-tool quota configuration (Phase 1 of Proposal 094).
 *
 * Compact env form (Velocity-dashboard friendly):
 *
 *   TOOL_QUOTAS=delete_post:5/1h:2,woo_*:60/1m:*,*:600/5m:*
 *
 *   `<pattern>:<rate>/<window>:<burst>`
 *     pattern  tool slug with optional `*` glob (most-specific wins)
 *     rate     calls allowed per window (integer ≥ 1)
 *     window   Ns | Nm | Nh | Nd
 *     burst    burst capacity (integer ≥ 1, or `*` = same as rate)
 *
 * The same quotas can be provided in JSON through the shared policy file
 * (GATEWAY_POLICY / POLICY_FILE — parsed by src/policy/config.js).
 */

/** One compiled quota rule. */
/**
 * Parse one window token (`5m`) to milliseconds.
 *
 * @param {string} token Window token.
 * @return {number|null} Milliseconds, or null when malformed.
 */
export function parseWindowMs( token ) {
	const match = /^(\d+)([smhd])$/.exec( String( token || '' ).trim() );
	if ( ! match ) {
		return null;
	}
	const value = Number( match[ 1 ] );
	const unit = {
		s: 1000,
		m: 60 * 1000,
		h: 60 * 60 * 1000,
		d: 24 * 60 * 60 * 1000,
	}[ match[ 2 ] ];
	return value > 0 ? value * unit : null;
}

/**
 * Compile a glob pattern into a RegExp anchored on both ends (`*` → `.*`).
 *
 * @param {string} pattern Glob pattern.
 * @return {RegExp} Compiled matcher.
 */
export function compileToolPattern( pattern ) {
	const escaped = String( pattern ).replace( /[.+?^${}()|[\]\\]/g, '\\$&' ).replace( /\*/g, '.*' );
	return new RegExp( `^${ escaped }$` );
}

/**
 * Specificity score for a pattern: literal characters before the first
 * glob outrank globs; longer patterns outrank shorter ones.
 *
 * @param {string} pattern Glob pattern.
 * @return {number} Score (higher = more specific).
 */
export function patternSpecificity( pattern ) {
	const literalPrefix = String( pattern ).split( '*' )[ 0 ].length;
	return literalPrefix * 1000 + String( pattern ).length;
}

/**
 * Parse the TOOL_QUOTAS compact form.
 *
 * @param {string} raw Raw env value.
 * @param {string[]} errors Shared error collector.
 * @return {Array|null} Sorted rules (most-specific first), or null when
 *                      unset. Malformed entries are skipped with an error.
 */
export function parseToolQuotas( raw, errors = [] ) {
	if ( ! raw || ! String( raw ).trim() ) {
		return null;
	}

	const rules = [];
	for ( const entry of String( raw ).split( ',' ) ) {
		const trimmed = entry.trim();
		if ( ! trimmed ) {
			continue;
		}
		const parts = trimmed.split( ':' );
		if ( 3 !== parts.length ) {
			errors.push( `TOOL_QUOTAS entry "${ trimmed }" must be <pattern>:<rate>/<window>:<burst>.` );
			continue;
		}
		const [ pattern, rateWindow, burstRaw ] = parts;
		const rw = rateWindow.split( '/' );
		if ( 2 !== rw.length ) {
			errors.push( `TOOL_QUOTAS entry "${ trimmed }" must be <pattern>:<rate>/<window>:<burst>.` );
			continue;
		}
		const rate = Number( rw[ 0 ] );
		const windowMs = parseWindowMs( rw[ 1 ] );
		if ( ! Number.isInteger( rate ) || rate < 1 || null === windowMs ) {
			errors.push( `TOOL_QUOTAS entry "${ trimmed }" has an invalid rate or window.` );
			continue;
		}
		const burst = '*' === burstRaw.trim() ? rate : Number( burstRaw );
		if ( ! Number.isInteger( burst ) || burst < 1 ) {
			errors.push( `TOOL_QUOTAS entry "${ trimmed }" has an invalid burst.` );
			continue;
		}
		rules.push( {
			pattern: pattern.trim(),
			matcher: compileToolPattern( pattern.trim() ),
			rate,
			windowMs,
			burst,
			specificity: patternSpecificity( pattern.trim() ),
		} );
	}

	if ( ! rules.length ) {
		return null;
	}
	rules.sort( ( a, b ) => b.specificity - a.specificity );
	return rules;
}

/**
 * Resolve the effective quota rule for a tool slug (first match wins —
 * rules are pre-sorted most-specific first).
 *
 * @param {Array}  rules Quota rules from parseToolQuotas().
 * @param {string} tool  Tool slug (unprefixed upstream name).
 * @return {object|null} Rule, or null when no rule matches.
 */
export function resolveQuota( rules, tool ) {
	for ( const rule of rules ) {
		if ( rule.matcher.test( String( tool ) ) ) {
			return rule;
		}
	}
	return null;
}
