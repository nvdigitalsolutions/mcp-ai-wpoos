/**
 * NV oOS Cloudways Dashboard — Auth Context
 *
 * Provides the WordPress REST nonce and a helper for authenticated fetches.
 *
 * @since 0.1.0
 */

import { createElement, createContext, useContext, useCallback, type ReactNode } from 'react';

interface AuthState {
  nonce: string;
  apiUrl: string;
  proApi: string;
  baseApi: string;
  tkApi: string;
  /** Perform an authenticated fetch to the dashboard REST API. */
  apiFetch: (path: string, init?: RequestInit) => Promise<Response>;
}

const AuthContext = createContext<AuthState | null>(null);

function getConfig() {
  if (typeof window !== 'undefined' && window.NVOOS_CLOUDWAYS_DASHBOARD) {
    return window.NVOOS_CLOUDWAYS_DASHBOARD;
  }
  return {
    apiUrl: '/wp-json/nvoos-cloudways-dashboard/v1',
    proApi: '/wp-json/mcp-ai-pro/v1',
    baseApi: '/wp-json/mcp-ai/v1',
    tkApi:  '/wp-json/nvoos-toolkit-shell/v1',
    nonce:  '',
    locale: 'en_US',
    config: {},
  };
}

export function AuthProvider({ children }: { children: ReactNode }): React.ReactElement {
  const cfg = getConfig();

  const apiFetch = useCallback(
    (path: string, init?: RequestInit): Promise<Response> => {
      const headers = new Headers(init?.headers);
      headers.set('X-WP-Nonce', cfg.nonce);
      headers.set('Content-Type', 'application/json');
      return fetch(`${cfg.apiUrl}${path}`, {
        ...init,
        headers,
        credentials: 'same-origin',
      });
    },
    [cfg.nonce, cfg.apiUrl]
  );

  const value: AuthState = {
    nonce: cfg.nonce,
    apiUrl: cfg.apiUrl,
    proApi: cfg.proApi,
    baseApi: cfg.baseApi,
    tkApi: cfg.tkApi,
    apiFetch,
  };

  return createElement(AuthContext.Provider, { value }, children);
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return ctx;
}
