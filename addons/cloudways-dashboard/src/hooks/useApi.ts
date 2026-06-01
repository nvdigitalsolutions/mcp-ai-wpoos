/**
 * NV oOS Cloudways Dashboard — API Hooks
 *
 * Lightweight data-fetching hooks for the dashboard REST endpoints.
 *
 * @since 0.1.0
 */

import { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';

interface ApiState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  refetch: () => void;
}

/**
 * Generic GET hook — calls the dashboard REST API.
 */
export function useApi<T = unknown>(path: string): ApiState<T> {
  const { apiFetch } = useAuth();
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    apiFetch(path)
      .then(async (res) => {
        if (cancelled) return;
        if (!res.ok) {
          const body = await res.json().catch(() => ({}));
          throw new Error((body as { message?: string }).message || `HTTP ${res.status}`);
        }
        return res.json();
      })
      .then((json) => {
        if (!cancelled) setData(json as T);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Unknown error');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
  }, [path, tick, apiFetch]);

  return { data, loading, error, refetch };
}

/**
 * POST mutation hook — returns a trigger function.
 */
export function useMutation<T = unknown, B = Record<string, unknown>>(
  path: string
): { mutate: (body: B) => Promise<T>; loading: boolean; error: string | null } {
  const { apiFetch } = useAuth();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mutate = useCallback(
    async (body: B): Promise<T> => {
      setLoading(true);
      setError(null);
      try {
        const res = await apiFetch(path, {
          method: 'POST',
          body: JSON.stringify(body),
        });
        if (!res.ok) {
          const errBody = await res.json().catch(() => ({}));
          throw new Error((errBody as { message?: string }).message || `HTTP ${res.status}`);
        }
        return (await res.json()) as T;
      } catch (err: unknown) {
        const msg = err instanceof Error ? err.message : 'Unknown error';
        setError(msg);
        throw err;
      } finally {
        setLoading(false);
      }
    },
    [path, apiFetch]
  );

  return { mutate, loading, error };
}
