/**
 * NV oOS Comic Reader — API Client
 *
 * Handles REST API communication for comic listing, file retrieval,
 * upload, and deletion.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

export interface ComicItem {
	id: number;
	title: string;
	filename: string;
	format: string;
	file_size: number;
	file_url: string;
	file_endpoint: string;
	cover_url: string;
	date: string;
	modified: string;
	mime_type: string;
}

export interface ComicListResponse {
	comics: ComicItem[];
	total: number;
	page: number;
	per_page: number;
	total_pages: number;
}

export interface CoverResponse {
	id: number;
	url: string;
	cached: boolean;
	extract?: string;
}

export interface ReaderConfig {
	apiUrl: string;
	nonce: string;
}

function getConfig(): ReaderConfig {
	return {
		apiUrl: window.NVOOS_COMIC_READER?.apiUrl || '',
		nonce: window.NVOOS_COMIC_READER?.nonce || '',
	};
}

async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
	const config = getConfig();
	const headers: Record<string, string> = {
		'X-WP-Nonce': config.nonce,
		...((options.headers as Record<string, string>) || {}),
	};

	const response = await fetch(url, {
		...options,
		headers,
	});

	if (!response.ok) {
		const body = await response.json().catch(() => ({}));
		throw new Error(body.message || `HTTP ${response.status}`);
	}

	return response.json();
}

export async function fetchComics(
	page = 1,
	perPage = 20,
	search = ''
): Promise<ComicListResponse> {
	const config = getConfig();
	const params = new URLSearchParams({
		page: String(page),
		per_page: String(perPage),
	});
	if (search) params.set('search', search);

	return apiFetch<ComicListResponse>(`${config.apiUrl}/comics?${params}`);
}

export async function fetchComic(id: number): Promise<ComicItem> {
	const config = getConfig();
	return apiFetch<ComicItem>(`${config.apiUrl}/comics/${id}`);
}

export async function fetchComicFileUrl(id: number): Promise<string> {
	const config = getConfig();
	return `${config.apiUrl}/comics/${id}/file`;
}

export async function fetchComicCover(id: number): Promise<CoverResponse> {
	const config = getConfig();
	return apiFetch<CoverResponse>(`${config.apiUrl}/comics/${id}/cover`);
}

export async function deleteComic(id: number): Promise<{ deleted: boolean; id: number }> {
	const config = getConfig();
	return apiFetch(`${config.apiUrl}/comics/${id}/delete`, { method: 'DELETE' });
}

export async function uploadComic(file: File): Promise<ComicItem> {
	const config = getConfig();
	const formData = new FormData();
	formData.append('file', file);

	const response = await fetch(`${config.apiUrl}/upload`, {
		method: 'POST',
		headers: {
			'X-WP-Nonce': config.nonce,
		},
		body: formData,
	});

	if (!response.ok) {
		const body = await response.json().catch(() => ({}));
		throw new Error(body.message || `HTTP ${response.status}`);
	}

	return response.json();
}

export function formatFileSize(bytes: number): string {
	if (bytes === 0) return '0 B';
	const units = ['B', 'KB', 'MB', 'GB'];
	const i = Math.floor(Math.log(bytes) / Math.log(1024));
	return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}
