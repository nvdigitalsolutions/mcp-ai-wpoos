/**
 * React Query hooks for Funiq Bridge REST API.
 *
 * Uses @tanstack/react-query with @wordpress/api-fetch
 * (which auto-attaches X-WP-Nonce in WP admin context).
 */
import apiFetch from '@wordpress/api-fetch';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { CollectionSlug, PaginatedResponse } from '../types';

/** Base URL for the Funiq REST API. */
const ROOT = (window as any).FuniqAdminConfig?.root || '/wp-json/funiq/v1/';

/**
 * Fetch a paginated collection.
 */
export function useCollection<T>(slug: CollectionSlug, page = 1, limit = 10) {
  return useQuery<PaginatedResponse<T>>({
    queryKey: [slug, { page, limit }],
    queryFn: () =>
      apiFetch({ path: `${ROOT}${slug}?page=${page}&limit=${limit}` }) as Promise<PaginatedResponse<T>>,
    placeholderData: (prev) => prev,
  });
}

/**
 * Fetch a single document by ID.
 */
export function useDocument<T>(slug: string, id: number | null) {
  return useQuery<T>({
    queryKey: [slug, id],
    queryFn: () => apiFetch({ path: `${ROOT}${slug}/${id}` }) as Promise<T>,
    enabled: id !== null,
  });
}

/**
 * Create or update a document.
 */
export function useDocumentMutation(slug: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id?: number; data: Record<string, unknown> }) => {
      if (id) {
        return apiFetch({
          path: `${ROOT}${slug}/${id}`,
          method: 'PUT',
          data,
        });
      }
      return apiFetch({
        path: `${ROOT}${slug}`,
        method: 'POST',
        data,
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [slug] });
    },
  });
}

/**
 * Delete a document.
 */
export function useDocumentDelete(slug: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch({
        path: `${ROOT}${slug}/${id}`,
        method: 'DELETE',
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [slug] });
    },
  });
}
