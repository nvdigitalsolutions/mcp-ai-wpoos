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
 * Build an apiFetch request. The admin config hands us a FULL URL root
 * (https://site/wp-json/funiq/v1/); apiFetch's `path` option would
 * re-prepend the REST namespace to it (wp-json/https://... double base).
 * Use the `url` option for absolute roots, `path` for relative ones.
 */
function request<T>(endpoint: string, options: Record<string, unknown> = {}): Promise<T> {
	const target = `${ROOT}${endpoint}`;
	return /^https?:\/\//i.test( target )
		? ( apiFetch( { ...options, url: target } ) as Promise<T> )
		: ( apiFetch( { ...options, path: target } ) as Promise<T> );
}

/**
 * Fetch a paginated collection.
 */
export function useCollection<T>(slug: CollectionSlug, page = 1, limit = 10) {
  return useQuery<PaginatedResponse<T>>({
    queryKey: [slug, { page, limit }],
    queryFn: () =>
      request<PaginatedResponse<T>>( `${slug}?page=${page}&limit=${limit}` ),
    placeholderData: (prev) => prev,
  });
}

/**
 * Fetch a single document by ID.
 */
export function useDocument<T>(slug: string, id: number | null) {
  return useQuery<T>({
    queryKey: [slug, id],
    queryFn: () => request<T>( `${slug}/${id}` ),
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
        return request( `${slug}/${id}`, {
          method: 'PUT',
          data,
        } );
      }
      return request( `${slug}`, {
        method: 'POST',
        data,
      } );
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
      request( `${slug}/${id}`, {
        method: 'DELETE',
      } ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [slug] });
    },
  });
}
