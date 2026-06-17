/**
 * Schedule Anything — Tenant Router
 *
 * Cloudflare Worker that maps subdomain requests to the correct
 * WordPress Multisite tenant instance. Uses KV for tenant→origin
 * lookups and applies per-tenant rate limiting.
 *
 * Architecture:
 *   User → *.scheduleanything.com → Tenant Router → WP Instance
 *
 * The router is transparent to the SPA — it forwards headers, bodies,
 * and preserves the original HTTP method. WordPress authenticates
 * the request normally via X-WP-Nonce or Bearer token.
 */

import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { resolveTenant } from './routing';
import { applyRateLimit } from './ratelimit';
import type { Env } from './types';

const app = new Hono<{ Bindings: Env }>();

// CORS: allow the SPA origin and all tenant subdomains
app.use('*', cors({
  origin: (origin) => {
    // Allow any scheduleanything.com subdomain
    if (origin && (
      origin.endsWith('.scheduleanything.com') ||
      origin === 'https://scheduleanything.com'
    )) {
      return origin;
    }
    // Allow localhost for development
    if (origin && origin.startsWith('http://localhost')) {
      return origin;
    }
    return 'https://scheduleanything.com';
  },
  allowMethods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
  allowHeaders: ['Content-Type', 'Authorization', 'X-WP-Nonce', 'X-Tenant-Slug'],
  exposeHeaders: ['X-Tenant-Slug', 'X-RateLimit-Remaining'],
  maxAge: 86400,
}));

/**
 * Main request handler.
 *
 * Flow:
 * 1. Extract tenant slug from subdomain
 * 2. Look up tenant→origin in KV
 * 3. Apply per-tenant rate limit
 * 4. Rewrite request to tenant's WP origin
 * 5. Forward the response back
 */
app.all('*', async (c) => {
  const url = new URL(c.req.url);
  const hostname = url.hostname;

  // Skip routing for the main domain (not a tenant subdomain)
  if (hostname === 'scheduleanything.com' || hostname === 'www.scheduleanything.com') {
    // This is the main site — forward to the platform WP instance
    const platformOrigin = c.env.PLATFORM_ORIGIN || 'https://scheduleanything.com';
    return forwardRequest(c, platformOrigin);
  }

  // Extract tenant slug from subdomain
  // e.g., "acme-corp.scheduleanything.com" → "acme-corp"
  const parts = hostname.split('.');
  if (parts.length < 3) {
    return c.json({ error: 'Invalid tenant subdomain' }, 400);
  }

  const tenantSlug = parts[0].toLowerCase();

  // Resolve tenant → origin URL
  const origin = await resolveTenant(c.env, tenantSlug);
  if (!origin) {
    return c.json({
      error: 'Tenant not found',
      message: `No workspace found for "${tenantSlug}". Please check the URL or contact support.`,
    }, 404);
  }

  // Apply per-tenant rate limiting
  const rateLimitResult = await applyRateLimit(c.env, tenantSlug);
  if (!rateLimitResult.allowed) {
    c.header('Retry-After', String(rateLimitResult.retryAfter || 60));
    return c.json({
      error: 'Rate limit exceeded',
      message: 'Too many requests. Please try again later.',
    }, 429);
  }

  // Forward the request to the tenant's WordPress instance
  return forwardRequest(c, origin, tenantSlug);
});

/**
 * Forward the incoming request to the target origin.
 *
 * Preserves method, headers (except host), and body.
 * Adds X-Tenant-Slug header for WP-side tenant awareness.
 * Strips Cloudflare-specific headers before forwarding.
 */
async function forwardRequest(
  c: ReturnType<Parameters<typeof app.all>[1]> extends (c: infer C) => any ? C : never,
  origin: string,
  tenantSlug?: string
): Promise<Response> {
  const url = new URL(c.req.url);

  // Build the target URL
  const targetUrl = new URL(url.pathname + url.search, origin);

  // Clone headers, removing Cloudflare-specific ones
  const headers = new Headers();
  c.req.raw.headers.forEach((value, key) => {
    const lower = key.toLowerCase();
    // Skip Cloudflare-specific headers
    if (lower.startsWith('cf-') || lower === 'x-forwarded-proto' || lower === 'x-real-ip') {
      return;
    }
    headers.set(key, value);
  });

  // Override host header with the target origin's hostname
  try {
    const originUrl = new URL(origin);
    headers.set('Host', originUrl.hostname);
  } catch {
    // If origin parsing fails, leave host as-is
  }

  // Add tenant context header for WP-side filtering
  if (tenantSlug) {
    headers.set('X-Tenant-Slug', tenantSlug);
  }

  // Add forwarded-for headers for audit logging
  headers.set('X-Forwarded-For', c.req.header('CF-Connecting-IP') || '');
  headers.set('X-Forwarded-Proto', url.protocol.replace(':', ''));

  // Build the forwarded request
  const forwardedRequest = new Request(targetUrl.toString(), {
    method: c.req.method,
    headers,
    body: ['GET', 'HEAD'].includes(c.req.method) ? undefined : c.req.raw.body,
    redirect: 'follow',
  });

  try {
    const response = await fetch(forwardedRequest);

    // Create response with tenant context header
    const responseHeaders = new Headers(response.headers);
    if (tenantSlug) {
      responseHeaders.set('X-Tenant-Slug', tenantSlug);
    }

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: responseHeaders,
    });
  } catch (err) {
    console.error(`Failed to forward request to ${origin}:`, err);
    return c.json({
      error: 'Gateway error',
      message: 'Unable to reach the tenant workspace. Please try again.',
    }, 502);
  }
}

export default app;
