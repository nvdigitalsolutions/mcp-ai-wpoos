/**
 * Per-tenant rate limiting.
 *
 * Uses Cloudflare's built-in rate limiter when available,
 * with a simple in-memory fallback for development.
 */

import type { Env } from './types';

interface RateLimitResult {
  allowed: boolean;
  retryAfter?: number;
}

// In-memory fallback for dev environments without rate limiter binding
const inMemoryCounters = new Map<string, { count: number; resetAt: number }>();

/**
 * Check and apply rate limiting for a tenant.
 *
 * When the RATE_LIMITER binding is available (production),
 * uses Cloudflare's distributed rate limiter. Falls back
 * to an in-memory counter for local development.
 *
 * @param env    Worker environment.
 * @param slug   Tenant slug to rate-limit.
 * @returns      Whether the request is allowed.
 */
export async function applyRateLimit(env: Env, slug: string): Promise<RateLimitResult> {
  // Production: use Cloudflare rate limiter
  if (env.RATE_LIMITER) {
    try {
      const result = await env.RATE_LIMITER.limit({ key: slug });
      return { allowed: result.success, retryAfter: result.success ? undefined : 60 };
    } catch {
      // Rate limiter error — allow the request (fail open)
      console.warn(`Rate limiter error for tenant: ${slug}`);
      return { allowed: true };
    }
  }

  // Development: simple in-memory rate limiter
  // 60 requests per minute per tenant
  const now = Date.now();
  const windowMs = 60_000; // 1 minute
  const maxRequests = 60;

  let counter = inMemoryCounters.get(slug);
  if (!counter || now > counter.resetAt) {
    counter = { count: 1, resetAt: now + windowMs };
    inMemoryCounters.set(slug, counter);
    return { allowed: true };
  }

  counter.count++;
  if (counter.count > maxRequests) {
    return {
      allowed: false,
      retryAfter: Math.ceil((counter.resetAt - now) / 1000),
    };
  }

  return { allowed: true };
}

/**
 * Clean up expired in-memory counters periodically.
 * Only relevant in dev mode without the RATE_LIMITER binding.
 */
setInterval(() => {
  const now = Date.now();
  for (const [key, counter] of inMemoryCounters) {
    if (now > counter.resetAt) {
      inMemoryCounters.delete(key);
    }
  }
}, 60_000); // Cleanup every minute
