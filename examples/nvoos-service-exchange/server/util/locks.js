/**
 * Keyed in-memory mutex.
 *
 * Provisioning must never run twice against the same estate concurrently
 * (double-click, retry storm). Locks are process-local — acceptable because
 * the Velocity app runs one instance and the reconciler is idempotent
 * across restarts (state is derived, ADR-04).
 */

export function createLockMap() {
	const tails = new Map();

	async function run( key, fn ) {
		const previous = tails.get( key ) ?? Promise.resolve();
		let release;
		const gate = new Promise( ( resolve ) => {
			release = resolve;
		} );
		const tail = previous.then( () => gate );
		tails.set( key, tail );

		await previous;
		try {
			return await fn();
		} finally {
			release();
			// Clean up when this is still the tail (avoid unbounded map growth).
			if ( tails.get( key ) === tail ) {
				tail.then( () => {
					if ( tails.get( key ) === tail ) {
						tails.delete( key );
					}
				} );
			}
		}
	}

	function isLocked( key ) {
		return tails.has( key );
	}

	return { run, isLocked };
}

export const estateLocks = createLockMap();
