/**
 * React Query hooks for the Astro CMS REST API.
 *
 * Uses @tanstack/react-query with @wordpress/api-fetch. The host supplies
 * `window.AstroAdminConfig` (root, nonce, mediaBase, user); the request
 * helper attaches `X-WP-Nonce` to every call — the same explicit-header
 * discipline as the Pro SPA clients (RecordsClient/TeamsClient) — and
 * re-mints the nonce from `/auth/nonce` once when a write answers
 * `rest_cookie_invalid_nonce` (the console's session/nonce retry pattern).
 */
import apiFetch from '@wordpress/api-fetch';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { CollectionSlug, Manager, PaginatedResponse } from '../types';
import type { AdminUser } from '../auth/permissions';

/** Runtime config supplied by the host page (backend or /manage gate). */
interface AdminConfig {
	root?: string;
	nonce?: string;
	mediaBase?: string;
	user?: AdminUser;
}

const CONFIG: AdminConfig =
	(typeof window !== 'undefined' && (window as any).AstroAdminConfig) || {};

/**
 * Live nonce state. The boot nonce (from the gate) may expire mid-session;
 * `request()` re-mints and updates this holder so every later call uses the
 * fresh value. Reads `wpApiSettings.nonce` as a fallback so the wp-admin
 * surface (where WordPress localizes it) keeps working.
 */
const nonceState: { current: string } = {
	current: CONFIG.nonce || (typeof window !== 'undefined' ? (window as any).wpApiSettings?.nonce || '' : ''),
};

/** Base URL for the CMS REST API (always ends with a slash). */
const ROOT = CONFIG.root ? CONFIG.root.replace(/\/?$/, '/') : '/api/';

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

export interface CollectionListArgs {
	page?: number;
	limit?: number;
	/** Server-side sort (native source: name/-name, price/-price, ...). */
	sort?: string;
	/** Payload-style where clause, e.g. `{ name: { like: 'chair' } }`. */
	where?: Record<string, unknown>;
}

/**
 * Mint a fresh `wp_rest` nonce from the contract's own nonce endpoint.
 *
 * Server-minted for the session identified by the request's auth cookie, so
 * it cannot go stale the way a scraped/cached page nonce can (the console
 * fetchSessionNonce() rationale).
 */
export async function mintNonce(): Promise<string> {
	try {
		const response = await fetch(`${ROOT}auth/nonce`, {
			credentials: 'include',
			headers: { accept: 'application/json' },
		});
		if (response.ok) {
			const data = (await response.json()) as { nonce?: string };
			if (typeof data?.nonce === 'string' && data.nonce) {
				return data.nonce;
			}
		}
	} catch {
		// Fall through — the caller keeps the current nonce.
	}
	return nonceState.current;
}

/**
 * Build an apiFetch request. The admin config may hand us a FULL URL root
 * (https://site/wp-json/funiq/v1/); apiFetch's `path` option would
 * re-prepend the REST namespace to it (wp-json/https://... double base).
 * Use the `url` option for absolute roots, `path` for relative ones.
 *
 * Cookie auth: the `X-WP-Nonce` header is attached explicitly (the
 * standalone bundle registers no apiFetch nonce middleware, so this helper
 * is the single choke point). On a stale-nonce rejection the nonce is
 * re-minted once and the request retried — the console's
 * fetchCurrentUserFallback() pattern applied to every write.
 */
export async function request<T>(endpoint: string, options: Record<string, unknown> = {}): Promise<T> {
	const target = `${ROOT}${endpoint}`;
	const headers: Record<string, string> = {
		...(options.headers as Record<string, string> | undefined),
	};
	if (nonceState.current) {
		headers['X-WP-Nonce'] = nonceState.current;
	}

	const call = () => {
		const opts = { ...options, headers };
		return /^https?:\/\//i.test(target)
			? (apiFetch({ ...opts, url: target }) as Promise<T>)
			: (apiFetch({ ...opts, path: target }) as Promise<T>);
	};

	try {
		return await call();
	} catch (error) {
		const code = (error as { code?: string })?.code;
		if (code !== 'rest_cookie_invalid_nonce' || !nonceState.current) {
			throw error;
		}

		// Stale nonce — mint a fresh one and retry exactly once.
		const fresh = await mintNonce();
		if (!fresh || fresh === nonceState.current) {
			throw error;
		}
		nonceState.current = fresh;
		if (typeof window !== 'undefined' && (window as any).wpApiSettings) {
			(window as any).wpApiSettings.nonce = fresh;
		}
		headers['X-WP-Nonce'] = fresh;
		return call();
	}
}

/**
 * Fetch a paginated collection.
 *
 * `signal` comes from TanStack Query's context: when the query key changes
 * (page/sort/search), the framework aborts the in-flight fetch — the
 * keep-previous-data + abort discipline the console's DataTable uses.
 */
export function useCollection<T>(slug: CollectionSlug, args: CollectionListArgs = {}) {
	const { page = 1, limit = 20, sort, where } = args;
	const params = new URLSearchParams();
	params.set('page', String(page));
	params.set('limit', String(limit));
	if (sort) {
		params.set('sort', sort);
	}
	// Bracket-encoded where clauses — the same wire shape the storefront
	// commerce client emits (buildWhereParams), so the backend sees one
	// contract from both consumers.
	if (where) {
		for (const [field, operators] of Object.entries(where)) {
			for (const [operator, value] of Object.entries(operators as Record<string, unknown>)) {
				if (value === undefined || value === null || value === '') {
					continue;
				}
				params.append(`where[${field}][${operator}]`, String(value));
			}
		}
	}

	return useQuery<PaginatedResponse<T>>({
		queryKey: [slug, { page, limit, sort, where }],
		queryFn: ({ signal }) =>
			request<PaginatedResponse<T>>(`${slug}?${params.toString()}`, { signal }),
		placeholderData: (prev) => prev,
	});
}

/**
 * Fetch a single document by ID.
 */
export function useDocument<T>(slug: string, id: number | null) {
	return useQuery<T>({
		queryKey: [slug, id],
		queryFn: ({ signal }) => request<T>(`${slug}/${id}`, { signal }),
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
				return request(`${slug}/${id}`, {
					method: 'PUT',
					data,
				});
			}
			return request(`${slug}`, {
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
 * Delete a document — optimistic.
 *
 * The affected page is updated immediately (the row disappears), with a
 * snapshot for rollback; the cache is revalidated on settle so the server's
 * truth always wins (TanStack Query optimistic-update contract).
 */
export function useDocumentDelete(slug: string) {
	const qc = useQueryClient();

	return useMutation({
		mutationFn: (id: number) =>
			request(`${slug}/${id}`, {
				method: 'DELETE',
			}),
		onMutate: async (id: number) => {
			await qc.cancelQueries({ queryKey: [slug] });
			const snapshots = qc.getQueriesData<PaginatedResponse<{ id: number }>>({
				queryKey: [slug],
			});
			snapshots.forEach(([key, data]) => {
				if (!data) {
					return;
				}
				qc.setQueryData(key, {
					...data,
					docs: data.docs.filter((doc) => doc.id !== id),
					totalDocs: Math.max(0, data.totalDocs - 1),
				});
			});
			return { snapshots };
		},
		onError: (_error, _id, context) => {
			const snapshots = context?.snapshots ?? [];
			snapshots.forEach(([key, data]) => {
				if (data) {
					qc.setQueryData(key, data);
				}
			});
		},
		onSettled: () => {
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
	const nonce = nonceState.current;
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
 * Fetch the current managers list.
 */
export function useManagers() {
	return useQuery<PaginatedResponse<Manager>>({
		queryKey: ['managers'],
		queryFn: ({ signal }) => request<PaginatedResponse<Manager>>('managers', { signal }),
		placeholderData: (prev) => prev,
	});
}

/**
 * Search users by name or email for the add-manager picker.
 */
export function useManagerSearch(term: string) {
	return useQuery<PaginatedResponse<Manager>>({
		queryKey: ['managers', 'search', term],
		queryFn: ({ signal }) =>
			request<PaginatedResponse<Manager>>(
				`managers?search=${encodeURIComponent(term)}`,
				{ signal }
			),
		enabled: term.trim().length >= 2,
	});
}

/**
 * Promote or demote a manager.
 */
export function useManagerMutation() {
	const qc = useQueryClient();

	return useMutation({
		mutationFn: ({ id, manage }: { id: number; manage: boolean }) =>
			request(`managers/${id}`, {
				method: 'PUT',
				data: { manage },
			}),
		onSuccess: () => {
			qc.invalidateQueries({ queryKey: ['managers'] });
		},
	});
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
