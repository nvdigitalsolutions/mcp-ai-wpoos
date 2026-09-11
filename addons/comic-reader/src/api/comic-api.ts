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
	series: string[];
	metadata: ComicMetadata;
	progress: ProgressRecord | null;
	reading_direction: 'ltr' | 'rtl';
}

export interface ComicMetadata {
	title?: string;
	series?: string;
	number?: string;
	volume?: string;
	writer?: string;
	penciller?: string;
	publisher?: string;
	genre?: string;
	page_count?: string;
	rtl?: string;
	manga?: string;
	reading_direction?: string;
	[key: string]: string | undefined;
}

export interface ProgressRecord {
	page: number;
	total: number;
	completed: boolean;
	ts: number;
}

export interface ComicSeries {
	id: number;
	name: string;
	count: number;
}

export interface ComicCollection {
	id: number;
	name: string;
	items: number[];
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
	search = '',
	series = '',
	orderby = 'date'
): Promise<ComicListResponse> {
	const config = getConfig();
	const params = new URLSearchParams({
		page: String(page),
		per_page: String(perPage),
		orderby,
	});
	if (search) params.set('search', search);
	if (series) params.set('series', series);

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

// ─── Progress & Metadata API ─────────────────────────────────

export async function fetchProgressMap(): Promise<
	Record<string, ProgressRecord>
> {
	const config = getConfig();
	const data = await apiFetch<{ progress: Record<string, ProgressRecord> }>(
		`${config.apiUrl}/comics/progress`
	);
	return data.progress || {};
}

export async function fetchComicProgress(
	id: number
): Promise<ProgressRecord | null> {
	const config = getConfig();
	const data = await apiFetch<{ progress: ProgressRecord | null }>(
		`${config.apiUrl}/comics/${id}/progress`
	);
	return data.progress ?? null;
}

export async function saveComicProgress(
	id: number,
	record: Omit<ProgressRecord, 'ts'>
): Promise<void> {
	const config = getConfig();
	await fetch(`${config.apiUrl}/comics/${id}/progress`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify(record),
	});
}

/** Send client-parsed ComicInfo.xml metadata to the server (best-effort). */
export async function saveComicMetadata(
	id: number,
	metadata: ComicMetadata
): Promise<void> {
	const config = getConfig();
	try {
		await fetch(`${config.apiUrl}/comics/${id}/metadata`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify(metadata),
		});
	} catch {
		// Read-only users cannot persist metadata; never block reading on it.
	}
}

export interface ComicDetailsUpdate {
	title?: string;
	series?: string;
	number?: string;
	volume?: string;
	writer?: string;
	publisher?: string;
	reading_direction?: 'ltr' | 'rtl';
}

export async function updateComicDetails(
	id: number,
	details: ComicDetailsUpdate
): Promise<ComicItem> {
	const config = getConfig();
	const response = await fetch(`${config.apiUrl}/comics/${id}`, {
		method: 'PUT',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify(details),
	});
	if (!response.ok) {
		const body = await response.json().catch(() => ({}));
		throw new Error(body.message || `HTTP ${response.status}`);
	}
	return response.json();
}

// ─── Series & Collections API ─────────────────────────────────

export async function fetchSeries(): Promise<ComicSeries[]> {
	const config = getConfig();
	const data = await apiFetch<{ series: ComicSeries[] }>(
		`${config.apiUrl}/series`
	);
	return data.series || [];
}

export async function fetchCollections(): Promise<ComicCollection[]> {
	const config = getConfig();
	const data = await apiFetch<{ collections: ComicCollection[] }>(
		`${config.apiUrl}/collections`
	);
	return data.collections || [];
}

export async function createCollection(name: string): Promise<ComicCollection> {
	const config = getConfig();
	const response = await fetch(`${config.apiUrl}/collections`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify({ name }),
	});
	if (!response.ok) {
		const body = await response.json().catch(() => ({}));
		throw new Error(body.message || `HTTP ${response.status}`);
	}
	return response.json();
}

export async function addToCollection(
	collectionId: number,
	comicId: number
): Promise<void> {
	const config = getConfig();
	await fetch(`${config.apiUrl}/collections/${collectionId}/items`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify({ comic_id: comicId }),
	});
}

export async function removeFromCollection(
	collectionId: number,
	comicId: number
): Promise<void> {
	const config = getConfig();
	await fetch(`${config.apiUrl}/collections/${collectionId}/items/${comicId}`, {
		method: 'DELETE',
		headers: { 'X-WP-Nonce': config.nonce },
	});
}

// ─── Creator Types ────────────────────────────────────────────

export interface CreatorComic {
	id: number;
	title: string;
	style: string;
	reading_direction: string;
	page_layout: string;
	series_name: string;
	issue_number: number;
	date: string;
	modified: string;
}

export interface CreatorComicListResponse {
	comics: CreatorComic[];
	total: number;
	page: number;
	per_page: number;
	total_pages: number;
}

export interface ComicPanel {
	id?: number;
	page: number;
	panel: number;
	description: string;
	image_url?: string;
	dialogue?: string;
	status?: string;
}

export interface PanelsResponse {
	panels: ComicPanel[];
	total: number;
	comic_id: number;
}

export interface GenerateResponse {
	status: string;
	comic_id: number;
	panel_ids: number[] | null;
	message: string;
}

export interface CreatorCharacter {
	id: number;
	title: string;
	meta: {
		_nvoos_character_name: string;
		_nvoos_character_description: string;
		_nvoos_character_style_notes: string;
		_nvoos_character_role: string;
		_nvoos_character_reference_image: string;
		_nvoos_character_comic_id: number;
	};
}

export interface CharactersListResponse {
	characters: Record<string, unknown>[];
	total: number;
	comic_id: number;
}

export interface CreatorScript {
	id: number;
	title: string;
	premise: string;
	genre: string;
	panel_count: number;
	scenes: Scene[];
}

export interface Scene {
	scene_number?: number;
	description?: string;
	characters?: string[];
	setting?: string;
	dialogue?: string;
	action?: string;
	panels?: ComicPanel[];
}

export interface CreatorStyle {
	slug: string;
	name: string;
	description: string;
}

// ─── Creator API ──────────────────────────────────────────────

function getCreatorApiUrl(): string {
	return (window.NVOOS_COMIC_READER?.apiUrl || '') + '/creator';
}

export async function createCreatorComic(data: {
	title: string;
	style?: string;
	reading_direction?: string;
	page_layout?: string;
	series_name?: string;
	issue_number?: number;
}): Promise<CreatorComic> {
	const config = getConfig();
	const response = await fetch(`${getCreatorApiUrl()}/comics`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify(data),
	});
	if (!response.ok) {
		const body = await response.json().catch(() => ({}));
		throw new Error(body.message || `HTTP ${response.status}`);
	}
	return response.json();
}

export async function fetchCreatorComics(
	page = 1,
	perPage = 20,
	search = ''
): Promise<CreatorComicListResponse> {
	const config = getConfig();
	const params = new URLSearchParams({
		page: String(page),
		per_page: String(perPage),
	});
	if (search) params.set('search', search);
	return apiFetch<CreatorComicListResponse>(`${getCreatorApiUrl()}/comics?${params}`);
}

export async function fetchCreatorComic(id: number): Promise<CreatorComic> {
	return apiFetch<CreatorComic>(`${getCreatorApiUrl()}/comics/${id}`);
}

export async function updateCreatorComic(
	id: number,
	data: Partial<Omit<CreatorComic, 'id' | 'date' | 'modified'>>
): Promise<CreatorComic> {
	const config = getConfig();
	const response = await fetch(`${getCreatorApiUrl()}/comics/${id}`, {
		method: 'PUT',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify(data),
	});
	if (!response.ok) {
		const body = await response.json().catch(() => ({}));
		throw new Error(body.message || `HTTP ${response.status}`);
	}
	return response.json();
}

export async function fetchPanels(comicId: number): Promise<PanelsResponse> {
	return apiFetch<PanelsResponse>(`${getCreatorApiUrl()}/comics/${comicId}/panels`);
}

export async function generatePanels(
	comicId: number,
	panelIds?: number[]
): Promise<GenerateResponse> {
	const config = getConfig();
	const body: Record<string, unknown> = {};
	if (panelIds?.length) {
		body.panel_ids = panelIds;
	}
	const response = await fetch(
		`${getCreatorApiUrl()}/comics/${comicId}/panels/generate`,
		{
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify(body),
		}
	);
	if (!response.ok) {
		const err = await response.json().catch(() => ({}));
		throw new Error(err.message || `HTTP ${response.status}`);
	}
	return response.json();
}

export async function fetchCharacters(comicId: number): Promise<CharactersListResponse> {
	return apiFetch<CharactersListResponse>(
		`${getCreatorApiUrl()}/comics/${comicId}/characters`
	);
}

export async function fetchCharacter(id: number): Promise<CreatorCharacter> {
	return apiFetch<CreatorCharacter>(`${getCreatorApiUrl()}/characters/${id}`);
}

export async function fetchScript(id: number): Promise<CreatorScript> {
	return apiFetch<CreatorScript>(`${getCreatorApiUrl()}/scripts/${id}`);
}

export async function fetchCreatorStyles(): Promise<CreatorStyle[]> {
	return apiFetch<CreatorStyle[]>(`${getCreatorApiUrl()}/styles`);
}
