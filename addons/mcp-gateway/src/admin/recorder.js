/**
 * In-memory call recorder for policy simulation (Phase 4 of Proposal 094).
 *
 * A fixed 200-entry ring buffer of tool-call attempts — the same pattern as
 * the platform's toolkit audit ring buffer. Entries carry tool/site/key
 * identity, argument KEYS (never values — the LOG_TOOL_ARGS=1 precedent
 * holds), the resolved country, and the trace id, so `/admin/policy-sim`
 * can replay a candidate policy against real past traffic without
 * persisting anything.
 */

const RING_CAPACITY = 200;

/**
 * Create a recorder.
 *
 * @return {object} `{ record, recent }`.
 */
export function createRecorder() {
	const entries = [];

	return {
		/**
		 * Record one call attempt.
		 *
		 * @param {object} entry `{ ts, tool, site, key, country, argsKeys, traceId }`.
		 * @return {void}
		 */
		record( entry ) {
			entries.push( entry );
			if ( entries.length > RING_CAPACITY ) {
				entries.shift();
			}
		},

		/**
		 * Snapshot of recorded calls, newest first.
		 *
		 * @return {object[]} Entries.
		 */
		recent() {
			return [ ...entries ].reverse();
		},
	};
}

/** Module-level recorder shared by the MCP route and the admin router. */
export const recorder = createRecorder();
