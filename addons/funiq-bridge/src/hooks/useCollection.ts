/**
 * React Query hooks for the Astro CMS REST API.
 *
 * Uses @tanstack/react-query with @wordpress/api-fetch. The host supplies
 * `window.AstroAdminConfig` (root + mediaBase); apiFetch attaches
 * X-WP-Nonce from `window.wpApiSettings.nonce` — set by wp-admin on the
 * backend page and by the storefront's /manage gate island on the
 * standalone surface.
 */
import apiFetch from '@wordpress/api-fetch';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { CollectionSlug, PaginatedResponse } from '../types';

/** Runtime config supplied by the host page (backend or /manage gate). */
interface AdminConfig {
	root?: string;
	nonce?: string;
	mediaBase?: string;
}

const CONFIG: AdminConfig =
	(typeof window !== 'undefined' && (window as any).AstroAdminConfig) || {};

/** Base URL for the CMS REST API. */
const ROOT = CONFIG.root || '/api/';

/**
 * Media proxy base for image previews. On the standalone storefront surface
 * this is `/media/` (same-origin uploads proxy); in wp-admin it is empty and
 * the absolute attachment URL is used directly.
 */
const MEDIA_BASE = CONFIG.mediaBase || '';

export interface MediaUploadResult {
	id: number;
	url: string;
	path: string;
}

/**
 * Build an apiFetch request. The admin config may hand us a FULL URL root
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

/**
 * Upload an image file (multipart) — the standalone uploader path.
 *
 * Uses plain fetch: apiFetch JSON-encodes bodies and cannot send FormData.
 * The X-WP-Nonce header satisfies the cookie-auth nonce check the same way
 * apiFetch would.
 */
export async function uploadMedia(file: File): Promise<MediaUploadResult> {
	const nonce = (window as any).wpApiSettings?.nonce || '';
	const form = new FormData();
	form.append('file', file);

	const res = await fetch(`${ROOT}media`, {
		method: 'POST',
		credentials: 'include',
		headers: nonce ? { 'X-WP-Nonce': nonce } : {},
		body: form,
	});

	if (!res.ok) {
		const payload = (await res.json().catch(() => ({}))) as { message?: string };
		throw new Error(payload.message || 'Upload failed.');
	}

	return (await res.json()) as MediaUploadResult;
}

/**
 * Fetch an attachment record (URL + upload path) by ID — used for previews
 * of images already attached to a document.
 */
export async function fetchMedia(id: number): Promise<MediaUploadResult> {
	return request<MediaUploadResult>(`media/${id}`);
}

/**
 * Resolve a preview URL for an attachment: the same-origin media proxy when
 * the host supplies a media base, otherwise the absolute attachment URL.
 */
export function mediaUrl(media: MediaUploadResult | null | undefined): string {
	if (!media) {
		return '';
	}
	return MEDIA_BASE ? `${MEDIA_BASE}${media.path}` : media.url;
}
