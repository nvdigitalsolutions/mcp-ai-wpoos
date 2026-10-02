/**
 * Status store: per-site latest state + transition history.
 *
 * Redis is primary when available (site-scoped keys, matching the
 * `tenants.*` naming discipline); an in-memory fallback keeps single-
 * process deployments (and tests) working without Redis — the same
 * contract as queue.js. All Redis calls are best-effort: a Redis failure
 * never breaks heartbeat ingestion.
 */

let redisClient = null;
let redisAvailable = false;
let redisRetryAt = 0;
const REDIS_RETRY_MS = 30000;

/**
 * Lazy Redis connection (shared discipline with queue.js).
 *
 * @return {Promise<Object|null>} Redis client or null when unavailable.
 */
async function getRedis() {
	if ( redisClient ) {
		return redisClient;
	}
	if ( ! redisAvailable && Date.now() < redisRetryAt ) {
		return null;
	}
	const redisUrl = process.env.REDIS_URL || 'redis://redis:6379';
	try {
		const { Redis } = await import( 'ioredis' );
		redisClient = new Redis( redisUrl, {
			maxRetriesPerRequest: 1,
			retryStrategy( times ) {
				if ( times > 2 ) {
					return null;
				}
				return Math.min( times * 200, 2000 );
			},
			lazyConnect: true,
		} );
		await redisClient.connect();
		redisAvailable = true;
	} catch ( err ) {
		console.warn( '[Status] Redis unavailable, using in-memory status store:', err.message );
		redisAvailable = false;
		redisRetryAt = Date.now() + REDIS_RETRY_MS;
		if ( redisClient ) {
			try {
				await redisClient.disconnect();
			} catch {
				// Best effort.
			}
		}
		redisClient = null;
	}
	return redisClient;
}

/** Redis key helpers (site-scoped). */
function siteKey( slug ) {
	return `status:site:${ slug }`;
}

function histKey( slug ) {
	return `status:hist:${ slug }`;
}

/**
 * Status store with an in-memory fallback.
 */
class StatusStore {
	constructor() {
		/** @type {Map<string, Object>} */
		this.memory = new Map();
		/** @type {Map<string, Array<Object>>} */
		this.memoryHistory = new Map();
	}

	/**
	 * Persist the latest record for a site.
	 *
	 * @param {string} slug   Site slug.
	 * @param {Object} record Full record (see record shape below).
	 * @return {Promise<void>}
	 */
	async putLatest( slug, record ) {
		this.memory.set( slug, record );
		const redis = await getRedis();
		if ( redis && redisAvailable ) {
			try {
				await redis.set( siteKey( slug ), JSON.stringify( record ) );
			} catch ( err ) {
				console.warn( `[Status] Redis write failed for "${ slug }":`, err.message );
			}
		}
	}

	/**
	 * Read the latest record for a site.
	 *
	 * @param {string} slug Site slug.
	 * @return {Promise<Object|null>} Record or null.
	 */
	async getLatest( slug ) {
		const redis = await getRedis();
		if ( redis && redisAvailable ) {
			try {
				const raw = await redis.get( siteKey( slug ) );
				if ( raw ) {
					const parsed = JSON.parse( raw );
					this.memory.set( slug, parsed );
					return parsed;
				}
			} catch ( err ) {
				console.warn( `[Status] Redis read failed for "${ slug }":`, err.message );
			}
		}
		return this.memory.get( slug ) || null;
	}

	/**
	 * List every site slug with a stored record.
	 *
	 * @return {Promise<string[]>} Slugs.
	 */
	async listSites() {
		const redis = await getRedis();
		if ( redis && redisAvailable ) {
			try {
				const keys = await redis.keys( 'status:site:*' );
				const slugs = keys
					.map( ( key ) => String( key ).replace( /^status:site:/, '' ) )
					.filter( Boolean );
				if ( slugs.length ) {
					return slugs;
				}
			} catch ( err ) {
				console.warn( '[Status] Redis list failed:', err.message );
			}
		}
		return Array.from( this.memory.keys() );
	}

	/**
	 * Append a status transition to history.
	 *
	 * @param {string} slug   Site slug.
	 * @param {string} status Status value.
	 * @param {number} at     Timestamp (ms epoch).
	 * @return {Promise<void>}
	 */
	async appendTransition( slug, status, at ) {
		const entry = { status, at };
		const bucket = this.memoryHistory.get( slug ) || [];
		bucket.push( entry );
		// Cap the in-memory history defensively (Redis carries the TTL).
		if ( bucket.length > 10000 ) {
			bucket.splice( 0, bucket.length - 10000 );
		}
		this.memoryHistory.set( slug, bucket );

		const redis = await getRedis();
		if ( redis && redisAvailable ) {
			try {
				const historyDays = Number( process.env.STATUS_HISTORY_DAYS ) || 90;
				await redis.zadd( histKey( slug ), at, JSON.stringify( entry ) );
				await redis.expire( histKey( slug ), historyDays * 24 * 60 * 60 );
			} catch ( err ) {
				console.warn( `[Status] Redis history write failed for "${ slug }":`, err.message );
			}
		}
	}

	/**
	 * Read transition history for a site within N days.
	 *
	 * @param {string} slug Site slug.
	 * @param {number} days Number of days.
	 * @param {number} now  Current time (ms epoch).
	 * @return {Promise<Array<Object>>} Entries sorted by time.
	 */
	async history( slug, days, now ) {
		const since = now - days * 24 * 60 * 60 * 1000;
		const redis = await getRedis();
		if ( redis && redisAvailable ) {
			try {
				const raw = await redis.zrangebyscore( histKey( slug ), since, now );
				return raw.map( ( entry ) => JSON.parse( entry ) );
			} catch ( err ) {
				console.warn( `[Status] Redis history read failed for "${ slug }":`, err.message );
			}
		}
		return ( this.memoryHistory.get( slug ) || [] ).filter( ( entry ) => entry.at >= since );
	}
}

/** Shared singleton store (module-scoped, per process). */
const store = new StatusStore();

export { StatusStore, store };
