/**
 * Policy rules engine (Phase 2 of Proposal 094).
 *
 * A deliberately small, declarative policy for the fleet gateway. Rules are
 * first-match-wins with a default of allow-all when no policy is configured
 * (back-compat) and deny-default once any rule set exists. The input shape
 * mirrors Rego conventions (`input.tool`, `input.site`, `input.key`,
 * `input.time`, `input.country`) so a future OPA/WASM migration is a
 * mechanical swap behind the same `evaluate(input)` seam.
 *
 * Grammar (GATEWAY_POLICY / POLICY_FILE):
 *
 *   {
 *     "rules": [
 *       {
 *         "id": "no-night-deletes",
 *         "effect": "deny" | "require_approval",
 *         "tool":  { "match": "delete_*" },
 *         "site":  [ "site-a" ],
 *         "key":   [ "key-id" ],
 *         "time":  { "window": { "tz": "Europe/London",
 *                                "days": [1,2,3,4,5],
 *                                "start": "09:00", "end": "17:00" } },
 *         "geo":   { "country": [ "GB", "US" ] }
 *       }
 *     ]
 *   }
 *
 * All conditions in a rule must match (AND); omitted conditions are
 * unconditional. `days` is ISO weekday (1=Mon … 7=Sun); windows may cross
 * midnight (start > end wraps).
 */

import { compileToolPattern } from '../quota/config.js';

/** Effects a rule can produce. */
export const EFFECTS = new Set( [ 'deny', 'require_approval' ] );

/** ISO weekday names indexed 1..7 (1=Monday). */
const WEEKDAYS = [ '', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ];

/**
 * Parse a "HH:MM" token to a minute-of-day integer (0–1439).
 *
 * @param {string} token Time token.
 * @return {number|null} Minutes, or null when malformed.
 */
export function parseTimeToken( token ) {
	const match = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec( String( token || '' ).trim() );
	if ( ! match ) {
		return null;
	}
	return Number( match[ 1 ] ) * 60 + Number( match[ 2 ] );
}

/**
 * Validate one rule object; returns a list of problems (empty = valid).
 *
 * @param {object} rule Raw rule.
 * @return {string[]} Problems.
 */
export function validateRule( rule ) {
	const problems = [];
	if ( ! rule || 'object' !== typeof rule ) {
		return [ 'each policy rule must be an object' ];
	}
	if ( 'string' !== typeof rule.id || ! rule.id ) {
		problems.push( 'rule "id" is required' );
	}
	if ( ! EFFECTS.has( rule.effect ) ) {
		problems.push( `rule "${ rule.id || '?' }" effect must be one of: deny, require_approval` );
	}
	if ( undefined !== rule.tool && ( 'object' !== typeof rule.tool || 'string' !== typeof rule.tool.match || ! rule.tool.match ) ) {
		problems.push( `rule "${ rule.id || '?' }" tool.match must be a glob string` );
	}
	for ( const key of [ 'site', 'key' ] ) {
		if ( undefined !== rule[ key ] && ( ! Array.isArray( rule[ key ] ) || rule[ key ].some( ( v ) => 'string' !== typeof v ) ) ) {
			problems.push( `rule "${ rule.id || '?' }" ${ key } must be an array of strings` );
		}
	}
	if ( undefined !== rule.time ) {
		const w = rule.time?.window;
		if ( ! w || 'string' !== typeof w.tz || ! w.tz ) {
			problems.push( `rule "${ rule.id || '?' }" time.window.tz is required` );
		}
		if ( null === parseTimeToken( w?.start ) || null === parseTimeToken( w?.end ) ) {
			problems.push( `rule "${ rule.id || '?' }" time.window start/end must be "HH:MM"` );
		}
		if ( ! Array.isArray( w?.days ) || w.days.length === 0 || w.days.some( ( d ) => ! Number.isInteger( d ) || d < 1 || d > 7 ) ) {
			problems.push( `rule "${ rule.id || '?' }" time.window.days must be ISO weekdays 1–7` );
		}
	}
	if ( undefined !== rule.geo && ( ! Array.isArray( rule.geo?.country ) || rule.geo.country.some( ( c ) => 'string' !== typeof c || ! /^[A-Z]{2}$/.test( c ) ) ) ) {
		problems.push( `rule "${ rule.id || '?' }" geo.country must be ISO 3166-1 alpha-2 codes` );
	}
	return problems;
}

/**
 * Parse and compile a policy document. Returns null when unset; collects
 * every problem into the shared errors array (fail-closed at boot).
 *
 * @param {string}   raw    Policy JSON (string) or null/''.
 * @param {string[]} errors Shared error collector.
 * @return {object|null} `{ rules }` — compiled rules in order.
 */
export function parsePolicy( raw, errors = [] ) {
	if ( ! raw || ! String( raw ).trim() ) {
		return null;
	}
	let doc;
	try {
		doc = JSON.parse( String( raw ) );
	} catch ( err ) {
		errors.push( `Policy JSON is invalid: ${ err.message }` );
		return null;
	}
	if ( ! doc || 'object' !== typeof doc || ! Array.isArray( doc.rules ) ) {
		errors.push( 'Policy document must be an object with a "rules" array.' );
		return null;
	}

	const rules = [];
	for ( const rawRule of doc.rules ) {
		const problems = validateRule( rawRule );
		if ( problems.length ) {
			errors.push( ...problems );
			continue;
		}
		const rule = {
			id: rawRule.id,
			effect: rawRule.effect,
			tool: rawRule.tool ? compileToolPattern( rawRule.tool.match ) : null,
			site: rawRule.site ? new Set( rawRule.site ) : null,
			key: rawRule.key ? new Set( rawRule.key ) : null,
			time: rawRule.time
				? {
						tz: rawRule.time.window.tz,
						days: new Set( rawRule.time.window.days ),
						start: parseTimeToken( rawRule.time.window.start ),
						end: parseTimeToken( rawRule.time.window.end ),
				  }
				: null,
			geo: rawRule.geo ? new Set( rawRule.geo.country ) : null,
		};
		rules.push( rule );
	}
	if ( ! rules.length ) {
		return null;
	}
	return { rules };
}

/**
 * Resolve the local time for a zone into `{ weekday, minuteOfDay }` via
 * Intl (stdlib — no tz database dependency).
 *
 * @param {Date}   date Moment to resolve.
 * @param {string} tz   IANA timezone.
 * @return {{weekday: number, minuteOfDay: number}} ISO weekday (1–7) and
 *         minutes since midnight in the zone.
 */
export function localTime( date, tz ) {
	const parts = new Intl.DateTimeFormat( 'en-CA', {
		timeZone: tz,
		weekday: 'short',
		hour: '2-digit',
		minute: '2-digit',
		hour12: false,
	} ).formatToParts( date );

	const values = {};
	for ( const part of parts ) {
		if ( 'weekday' === part.type ) {
			values.weekday = WEEKDAYS.indexOf( part.value );
		} else if ( 'hour' === part.type ) {
			values.hour = Number( part.value );
		} else if ( 'minute' === part.type ) {
			values.minute = Number( part.value );
		}
	}
	return {
		weekday: values.weekday,
		minuteOfDay: values.hour * 60 + values.minute,
	};
}

/**
 * Test whether a minute-of-day falls inside a possibly-midnight-crossing
 * window.
 *
 * @param {number} minute Minute of day.
 * @param {number} start  Window start.
 * @param {number} end    Window end.
 * @return {boolean} Inside.
 */
export function inWindow( minute, start, end ) {
	if ( start <= end ) {
		return minute >= start && minute <= end;
	}
	return minute >= start || minute <= end;
}

/**
 * Evaluate a compiled policy against an input. First match wins; no match
 * yields `allow`.
 *
 * @param {object} policy Compiled policy from parsePolicy().
 * @param {object} input  `{ tool, site, key, time: Date, country }`.
 * @return {{effect: string, ruleId: string|null}} Decision.
 */
export function evaluatePolicy( policy, input ) {
	if ( ! policy?.rules?.length ) {
		return { effect: 'allow', ruleId: null };
	}
	for ( const rule of policy.rules ) {
		if ( rule.tool && ! rule.tool.test( String( input.tool ) ) ) {
			continue;
		}
		if ( rule.site && ! rule.site.has( String( input.site ) ) ) {
			continue;
		}
		if ( rule.key && ! rule.key.has( String( input.key ) ) ) {
			continue;
		}
		if ( rule.time ) {
			let local;
			try {
				local = localTime( input.time instanceof Date ? input.time : new Date( input.time ), rule.time.tz );
			} catch {
				continue; // Invalid tz never matches.
			}
			if ( ! rule.time.days.has( local.weekday ) || ! inWindow( local.minuteOfDay, rule.time.start, rule.time.end ) ) {
				continue;
			}
		}
		if ( rule.geo && ( ! input.country || ! rule.geo.has( String( input.country ).toUpperCase() ) ) ) {
			continue;
		}
		return { effect: rule.effect, ruleId: rule.id };
	}
	return { effect: 'allow', ruleId: null };
}
