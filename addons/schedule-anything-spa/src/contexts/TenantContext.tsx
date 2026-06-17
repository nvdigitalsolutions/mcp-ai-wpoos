/**
 * Tenant context — provides tenant configuration from the current subdomain.
 *
 * Reads the tenant slug from the hostname and exposes tier,
 * toolkit availability, and feature flags to the component tree.
 */

import {
  createContext,
  useContext,
  useState,
  useEffect,
  type ReactNode,
} from 'react';
import { publicFetch } from '@/api/client';

export interface TenantConfig {
  /** Tenant subdomain slug. */
  slug: string;
  /** Subscription tier. */
  tier: 'starter' | 'professional' | 'enterprise' | 'unknown';
  /** Whether the config is still loading. */
  isLoading: boolean;
  /** Error message if config fetch failed. */
  error: string | null;
}

const TenantContext = createContext<TenantConfig>({
  slug: '',
  tier: 'unknown',
  isLoading: true,
  error: null,
});

interface TenantProviderProps {
  children: ReactNode;
}

export function TenantProvider({ children }: TenantProviderProps) {
  const [config, setConfig] = useState<TenantConfig>({
    slug: '',
    tier: 'unknown',
    isLoading: true,
    error: null,
  });

  useEffect(() => {
    // Extract tenant slug from subdomain
    const hostname = window.location.hostname;
    const parts = hostname.split('.');
    const slug = parts.length >= 3 ? parts[0] : '';

    if (!slug) {
      // Main domain — no tenant context needed
      setConfig({
        slug: '',
        tier: 'unknown',
        isLoading: false,
        error: null,
      });
      return;
    }

    // Fetch tenant config from REST API
    publicFetch<{ tier: string }>(`/nvoos-saas/v1/tenants/lookup?slug=${encodeURIComponent(slug)}`)
      .then((data) => {
        setConfig({
          slug,
          tier: (data.tier || 'starter') as TenantConfig['tier'],
          isLoading: false,
          error: null,
        });
      })
      .catch((err) => {
        setConfig({
          slug,
          tier: 'unknown',
          isLoading: false,
          error: err instanceof Error ? err.message : 'Failed to load tenant config',
        });
      });
  }, []);

  return (
    <TenantContext.Provider value={config}>
      {children}
    </TenantContext.Provider>
  );
}

/**
 * Hook to access the current tenant configuration.
 */
export function useTenant(): TenantConfig {
  return useContext(TenantContext);
}
