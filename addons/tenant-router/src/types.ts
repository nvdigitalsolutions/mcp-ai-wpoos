/**
 * Shared type definitions for the Tenant Router Cloudflare Worker.
 */

export interface Env {
  /**
   * KV namespace mapping tenant slugs to WordPress origin URLs.
   *
   * Key: tenant subdomain slug (e.g., "acme-corp")
   * Value: full origin URL (e.g., "https://wp-1.cloudwaysapps.com")
   */
  TENANT_KV?: KVNamespace;

  /**
   * Cloudflare rate limiter binding for per-tenant throttling.
   *
   * Key: tenant slug
   * Limits: 60 requests per minute per tenant (configurable)
   */
  RATE_LIMITER?: {
    limit(options: { key: string }): Promise<{ success: boolean }>;
  };

  /**
   * Platform WordPress origin URL.
   *
   * The main scheduleanything.com WordPress instance that hosts
   * the platform plugin and tenant provisioning API.
   */
  PLATFORM_ORIGIN?: string;

  /**
   * Internal API key for service-to-service communication
   * between the Cloud Worker and the WordPress platform plugin.
   */
  SAAS_API_KEY?: string;
}
