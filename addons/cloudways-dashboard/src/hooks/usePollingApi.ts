/**
 * NV oOS Cloudways Dashboard — Polling API Hook
 *
 * usePollingApi<T> — periodic fetch with configurable interval and auto-stop.
 *
 * @since 0.1.0
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { useAuth } from '../contexts/AuthContext';

interface PollingState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  cancel: () => void;
}

/**
 * Polls an endpoint at a given interval until `stopWhen` returns true.
 *
 * @param path      REST path relative to the dashboard API.
 * @param interval  Polling interval in milliseconds.
 * @param stopWhen  Called with the fetched data; return true to stop polling.
 */
export function usePollingApi<T = unknown>(
  path: string,
  interval: number = 5000,
  stopWhen?: (data: T) => boolean
): PollingState<T> {
  const { apiFetch } = useAuth();
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const cancelledRef = useRef(false);

  const cancel = useCallback(() => {
    cancelledRef.current = true;
    if (timerRef.current) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  useEffect(() => {
    cancelledRef.current = false;

    const fetchOnce = () => {
      apiFetch(path)
        .then(async (res) => {
          if (cancelledRef.current) return;
          if (!res.ok) {
            const body = await res.json().catch(() => ({}));
            throw new Error((body as { message?: string }).message || `HTTP ${res.status}`);
          }
          return res.json();
        })
        .then((json: T) => {
          if (cancelledRef.current) return;
          setData(json);
          setLoading(false);
          setError(null);

          if (stopWhen && stopWhen(json)) {
            cancel();
          }
        })
        .catch((err: unknown) => {
          if (cancelledRef.current) return;
          setError(err instanceof Error ? err.message : 'Unknown error');
          setLoading(false);
        });
    };

    // Fetch immediately.
    fetchOnce();

    // Then poll.
    timerRef.current = setInterval(fetchOnce, interval);

    return () => {
      cancelledRef.current = true;
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
    };
  }, [path, interval, apiFetch, stopWhen, cancel]);

  return { data, loading, error, cancel };
}
