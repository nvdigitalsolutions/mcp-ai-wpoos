/**
 * Auth context — manages WordPress nonce and user state.
 *
 * On mount, fetches the nonce from WordPress REST API and
 * configures the API client. Exposes the auth state to the
 * component tree via React context.
 */

import {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
  type ReactNode,
} from 'react';
import { initApiClient, publicFetch } from '@/api/client';

export interface AuthState {
  /** WordPress REST nonce. Null if not yet fetched or user is logged out. */
  nonce: string | null;
  /** WordPress user ID. 0 if not logged in. */
  userId: number;
  /** Whether the user is authenticated. */
  isLoggedIn: boolean;
  /** Whether the initial auth check is still loading. */
  isLoading: boolean;
  /** Error message if auth check failed. */
  error: string | null;
}

const AuthContext = createContext<AuthState>({
  nonce: null,
  userId: 0,
  isLoggedIn: false,
  isLoading: true,
  error: null,
});

interface AuthProviderProps {
  children: ReactNode;
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [auth, setAuth] = useState<AuthState>({
    nonce: null,
    userId: 0,
    isLoggedIn: false,
    isLoading: true,
    error: null,
  });

  const refreshAuth = useCallback(async () => {
    try {
      const data = await publicFetch<{
        nonce: string;
        user_id: number;
        logged_in: boolean;
      }>('/nvoos-saas/v1/auth/nonce');

      if (data.nonce) {
        initApiClient(data.nonce);
      }

      setAuth({
        nonce: data.nonce,
        userId: data.user_id,
        isLoggedIn: data.logged_in,
        isLoading: false,
        error: null,
      });
    } catch (err) {
      setAuth({
        nonce: null,
        userId: 0,
        isLoggedIn: false,
        isLoading: false,
        error: err instanceof Error ? err.message : 'Failed to initialize auth',
      });
    }
  }, []);

  useEffect(() => {
    refreshAuth();
  }, [refreshAuth]);

  return (
    <AuthContext.Provider value={auth}>
      {children}
    </AuthContext.Provider>
  );
}

/**
 * Hook to access the current auth state.
 */
export function useAuth(): AuthState {
  return useContext(AuthContext);
}
