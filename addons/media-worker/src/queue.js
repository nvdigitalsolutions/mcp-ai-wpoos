import { EventEmitter } from 'events';

/**
 * Lightweight Redis-backed job queue for the Design Stack media worker.
 *
 * Uses Redis lists (LPUSH/BRPOP) for simple job queuing without
 * requiring BullMQ as a hard dependency. Falls back to in-memory
 * queue if Redis is unavailable.
 *
 * Queue names:
 *   - image-generation    — AI image generation jobs
 *   - image-optimization  — Image optimization jobs
 *   - video-generation    — AI video generation jobs
 *   - social-scheduled    — Scheduled social media posts
 *   - workflow            — Orchestration pipeline jobs
 */

const queues = new Map();
let redisClient = null;
let redisAvailable = false;

// Never hammer Redis faster than this after a failed connection attempt.
// Prevents reconnect storms when callers poll (the in-memory processor
// calls getRedis() on every loop iteration) and keeps the event loop
// drainable so the process can exit cleanly (tests, graceful shutdown).
const REDIS_RETRY_MS = 30000;
let redisRetryAt = 0;

// Lazy Redis connection
async function getRedis() {
  if (redisClient) return redisClient;

  // Back off after a failure instead of re-dialing on every call.
  if (!redisAvailable && Date.now() < redisRetryAt) return null;

  const redisUrl = process.env.REDIS_URL || 'redis://redis:6379';
  try {
    // Dynamic import so ioredis is optional
    const { Redis } = await import('ioredis');
    redisClient = new Redis(redisUrl, {
      maxRetriesPerRequest: 2,
      retryStrategy(times) {
        if (times > 3) return null; // stop retrying
        return Math.min(times * 200, 2000);
      },
      lazyConnect: true,
    });

    await redisClient.connect();
    redisAvailable = true;
    console.log('[Queue] Redis connected:', redisUrl);
  } catch (err) {
    console.warn('[Queue] Redis unavailable, using in-memory queue:', err.message);
    redisAvailable = false;
    redisRetryAt = Date.now() + REDIS_RETRY_MS;
    if (redisClient) {
      // Release the socket and stop retry timers so the process can exit.
      try {
        await redisClient.disconnect();
      } catch {
        // Best effort — the client is already unusable.
      }
    }
    redisClient = null;
  }

  return redisClient;
}

class JobQueue extends EventEmitter {
  constructor(name, site = '') {
    super();
    this.name = name;
    this.site = site;
    const scope = site ? `${site}:${name}` : name;
    this.queueKey = `queue:${scope}`;
    this.delayedKey = site ? `queue:delayed:${site}` : 'queue:delayed';
    this.processingKey = `queue:${scope}:processing`;
    this._processing = false;
    this._handlers = new Map();
    this._inMemory = [];
    this._wakeResolve = null;
  }

  /**
   * Wake the in-memory processing loop (called when a job is enqueued or
   * when processing stops) so jobs are picked up immediately instead of
   * waiting for the idle poll tick.
   */
  _wake() {
    if (this._wakeResolve) {
      const resolve = this._wakeResolve;
      this._wakeResolve = null;
      resolve();
    }
  }

  /**
   * Add a job to the queue.
   * @param {string} type - Job type identifier
   * @param {object} data - Job payload
   * @param {object} options - { delay, attempts, priority }
   */
  async add(type, data, options = {}) {
    const job = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
      type,
      data,
      options,
      createdAt: new Date().toISOString(),
      attempts: 0,
    };

    const redis = await getRedis();
    if (redis && redisAvailable) {
      const serialized = JSON.stringify(job);

      if (options.delay && options.delay > 0) {
        // Delayed job — store with score for later pickup
        const executeAt = Date.now() + options.delay;
        await redis.zadd(this.delayedKey, executeAt, serialized);
      } else {
        await redis.lpush(this.queueKey, serialized);
      }
    } else {
      // In-memory fallback
      if (options.delay && options.delay > 0) {
        setTimeout(() => {
          this._inMemory.push(job);
          this._wake();
        }, options.delay);
      } else {
        this._inMemory.push(job);
        this._wake();
      }
    }

    this.emit('added', job);
    return job;
  }

  /**
   * Register a handler for a job type.
   */
  process(type, handler) {
    this._handlers.set(type, handler);
    this._startProcessing();
  }

  async _startProcessing() {
    if (this._processing) return;
    this._processing = true;

    const processLoop = async () => {
      while (this._processing) {
        try {
          let job = null;

          const redis = await getRedis();
          if (redis && redisAvailable) {
            // Check delayed jobs first
            const now = Date.now();
            const delayed = await redis.zrangebyscore(this.delayedKey, 0, now, 'LIMIT', 0, 1);
            if (delayed.length > 0) {
              await redis.zrem(this.delayedKey, delayed[0]);
              job = JSON.parse(delayed[0]);
            } else {
              // Blocking pop from queue
              const result = await redis.brpop(this.queueKey, 5);
              if (result) {
                job = JSON.parse(result[1]);
              }
            }
          } else {
            // In-memory fallback
            if (this._inMemory.length > 0) {
              job = this._inMemory.shift();
            } else {
              // Idle: sleep until a job is enqueued (unref'd so a quiet
              // process can still exit — tests and graceful shutdown).
              await new Promise((resolve) => {
                this._wakeResolve = resolve;
                setTimeout(resolve, 1000).unref();
              });
            }
          }

          if (!job) continue;

          const handler = this._handlers.get(job.type);
          if (!handler) {
            console.warn(`[Queue:${this.name}] No handler for type: ${job.type}`);
            continue;
          }

          job.attempts++;
          try {
            await handler(job);
            this.emit('completed', job);
          } catch (err) {
            console.error(`[Queue:${this.name}] Job ${job.id} failed (attempt ${job.attempts}):`, err.message);
            job.lastError = err.message;

            const maxAttempts = job.options?.attempts || 3;
            if (job.attempts < maxAttempts) {
              // Retry with exponential backoff
              const delay = Math.min(1000 * Math.pow(2, job.attempts), 60000);
              await this.add(job.type, job.data, { ...job.options, delay });
              this.emit('retrying', job);
            } else {
              this.emit('failed', job);
            }
          }
        } catch (err) {
          console.error(`[Queue:${this.name}] Processing error:`, err.message);
          await new Promise((r) => setTimeout(r, 5000));
        }
      }
    };

    processLoop().catch((err) => {
      console.error(`[Queue:${this.name}] Fatal processing error:`, err.message);
      this._processing = false;
    });

    return this;
  }

  /**
   * Get queue stats.
   */
  async getStats() {
    const redis = await getRedis();
    if (redis && redisAvailable) {
      const [waiting, delayed] = await Promise.all([
        redis.llen(this.queueKey),
        redis.zcard(this.delayedKey),
      ]);
      return { waiting, delayed, inMemory: false };
    }
    return { waiting: this._inMemory.length, delayed: 0, inMemory: true };
  }

  /**
   * Stop processing.
   */
  stop() {
    this._processing = false;
    this._wake();
  }
}

/**
 * Get or create a named queue (singleton per site + name).
 *
 * @param {string} name Queue name (e.g. 'workflow').
 * @param {string} [site] Site slug in multi-tenant mode; 'default' and ''
 *                        mean unscoped (legacy single-tenant keys).
 * @return {JobQueue} Queue instance.
 */
function getQueue(name, site = '') {
  const scope = site && 'default' !== site ? site : '';
  const key = `${scope}:${name}`;
  if (!queues.has(key)) {
    queues.set(key, new JobQueue(name, scope));
  }
  return queues.get(key);
}

export { JobQueue, getQueue, getRedis };
