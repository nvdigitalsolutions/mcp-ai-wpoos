/**
 * Tenant routing — KV-backed tenant→origin resolution.
 *
 * Maps a tenant slug (subdomain) to the origin WordPress instance URL.
 * Also supports fallback resolution for tenants not found in KV
 * (e.g., freshly provisioned tenants before KV propagation).
 */

import type { Env } from './types';

/**
 * Resolve a tenant slug to its WordPress origin URL.
 *
 * First checks KV (fast, eventually consistent). Falls back
 * to the platform REST API if the tenant isn't in KV yet.
 *
 * @param env    Worker environment bindings.
 * @param slug   Tenant subdomain slug.
 * @returns      Origin URL string, or null if tenant not found.
 */
export async function resolveTenant(env: Env, slug: string): Promise<string | null> {
  // 1. Check KV (primary source, sub-millisecond lookup)
  if (env.TENANT_KV) {
    try {
      const origin = await env.TENANT_KV.get(slug);
      if (origin) {
        return origin;
      }
    } catch {
      // KV miss — continue to fallback
      console.warn(`KV miss for tenant: ${slug}`);
    }
  }

  // 2. Fallback: query the platform REST API
  // This handles freshly provisioned tenants before KV propagation.
  if (env.PLATFORM_ORIGIN) {
    try {
      const response = await fetch(
        `${env.PLATFORM_ORIGIN}/wp-json/nvoos-saas/v1/tenants/lookup?slug=${encodeURIComponent(slug)}`,
        {
          headers: {
            'X-SaaS-API-Key': env.SAAS_API_KEY || '',
          },
        }
      );

      if (response.ok) {
        const data = await response.json() as { site_url?: string };
        if (data.site_url) {
          // Populate KV for next lookup
          if (env.TENANT_KV) {
            try {
              await env.TENANT_KV.put(slug, data.site_url, { expirationTtl: 86400 });
            } catch {
              // Non-critical — will be resolved on next KV miss
            }
          }
          return data.site_url;
        }
      }
    } catch {
      console.warn(`Platform API lookup failed for tenant: ${slug}`);
    }
  }

  return null;
}

/**
 * Preload tenant mappings into KV.
 *
 * Called during provisioning to ensure immediate availability
 * without waiting for the fallback path.
 *
 * @param env    Worker environment bindings.
 * @param slug   Tenant subdomain slug.
 * @param origin WordPress origin URL.
 */
export async function registerTenant(env: Env, slug: string, origin: string): Promise<void> {
  if (!env.TENANT_KV) {
    console.warn('TENANT_KV not available — tenant registration skipped');
    return;
  }

  await env.TENANT_KV.put(slug, origin);
}

/**
 * Remove a tenant from KV.
 *
 * Called during offboarding.
 */
export async function unregisterTenant(env: Env, slug: string): Promise<void> {
  if (!env.TENANT_KV) {
    return;
  }

  await env.TENANT_KV.delete(slug);
}
