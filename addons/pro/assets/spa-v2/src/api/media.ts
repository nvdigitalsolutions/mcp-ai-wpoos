/**
 * Pro SPA v2 — typed wrappers around WordPress Media Library REST API.
 *
 * Uses the native WP REST endpoint `/wp/wp/v2/media` to list, search,
 * and retrieve Media Library items.  Supports pagination, MIME-type
 * filtering, and thumbnail URLs via `media_details.sizes`.
 *
 * @since 2.0.3
 */

import { __ } from '@wordpress/i18n';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface MediaItem {
	id: number;
	title: string;
	/** Rendered media title (HTML). */
	titleRendered: string;
	/** Rendered caption (HTML). */
	captionRendered: string;
	/** Rendered alt text (HTML). */
	altText: string;
	/** Rendered description (HTML). */
	descriptionRendered: string;
	/** MIME type, e.g. "image/jpeg". */
	mimeType: string;
	/** Full-size source URL. */
	sourceUrl: string;
	/** Width / Height / sizes from `media_details`. */
	width: number;
	height: number;
	/** Thumbnail URL (150×150). Falls back to sourceUrl for non-image media. */
	thumbnailUrl: string;
	/** Medium size URL (300×300). */
	mediumUrl: string;
	/** File size in bytes (if available). */
	fileSize: number;
	/** Upload date (ISO 8601). */
	date: string;
	/** Alt text (plain). */
	altTextPlain: string;
	/** WordPress attachment post type status. */
	status: string;
	/** Author user ID. */
	authorId: number;
}

export interface MediaListResponse {
	items: MediaItem[];
	total: number;
	totalPages: number;
}

export interface MediaClientOptions {
	/** WordPress REST API root URL (e.g. "https://example.com/wp-json"). */
	apiRoot: string;
	nonce: string;
}

/* ------------------------------------------------------------------ */
/*  Client                                                             */
/* ------------------------------------------------------------------ */

export const MEDIA_PER_PAGE = 30;

/**
 * The WordPress REST API raw response shape for a media item.
 * We only extract the fields we care about in `mapMediaItem`.
 */
interface WpRestMediaItem {
	id: number;
	title: { rendered: string };
	caption: { rendered: string };
	alt_text: string;
	description: { rendered: string };
	mime_type: string;
	source_url: string;
	media_details: {
		width: number;
		height: number;
		filesize?: number;
		sizes: Record< string, {
			source_url: string;
			width: number;
			height: number;
		} >;
	};
	date: string;
	status: string;
	author: number;
}

function mapMediaItem( raw: WpRestMediaItem ): MediaItem {
	const sizes = raw.media_details?.sizes ?? {};
	const thumb = sizes.thumbnail;
	const medium = sizes.medium;

	return {
		id: raw.id,
		title: raw.title?.rendered ?? '',
		titleRendered: raw.title?.rendered ?? '',
		captionRendered: raw.caption?.rendered ?? '',
		altText: raw.alt_text ?? '',
		descriptionRendered: raw.description?.rendered ?? '',
		mimeType: raw.mime_type ?? '',
		sourceUrl: raw.source_url ?? '',
		width: raw.media_details?.width ?? 0,
		height: raw.media_details?.height ?? 0,
		thumbnailUrl: thumb?.source_url ?? raw.source_url ?? '',
		mediumUrl: medium?.source_url ?? raw.source_url ?? '',
		fileSize: raw.media_details?.filesize ?? 0,
		date: raw.date ?? '',
		altTextPlain: raw.alt_text ?? '',
		status: raw.status ?? '',
		authorId: raw.author ?? 0,
	};
}

export class MediaClient {
	private readonly apiRoot: string;
	private readonly nonce: string;

	constructor( opts: MediaClientOptions ) {
		this.apiRoot = opts.apiRoot.replace( /\/+$/, '' );
		this.nonce = opts.nonce;
	}

	/**
	 * List media items from the WordPress Media Library.
	 *
	 * @param page   Page number (1-based).
	 * @param search Optional search term.
	 * @param mimeType Optional MIME type filter (e.g. "image", "image/jpeg").
	 * @param signal Optional AbortSignal.
	 */
	async list(
		page: number = 1,
		search: string = '',
		mimeType: string = '',
		signal?: AbortSignal
	): Promise< MediaListResponse > {
		const url = new URL( `${ this.apiRoot }/wp/v2/media`, window.location.origin );
		url.searchParams.set( 'per_page', String( MEDIA_PER_PAGE ) );
		url.searchParams.set( 'page', String( page ) );
		url.searchParams.set( '_fields',
			'id,title,caption,alt_text,description,mime_type,source_url,media_details,date,status,author'
		);

		if ( search ) {
			url.searchParams.set( 'search', search );
		}
		if ( mimeType ) {
			url.searchParams.set( 'media_type', mimeType );
		}

		const data = await this.request< WpRestMediaItem[] >( { method: 'GET', url: url.toString(), signal } );

		const totalPages = data._totalPages ?? 1;
		const total = data._total ?? 0;

		return {
			items: ( data.items ?? [] ).map( mapMediaItem ),
			total: typeof total === 'number' ? total : 0,
			totalPages: typeof totalPages === 'number' ? totalPages : 1,
		};
	}

	/**
	 * Get a single media item by ID.
	 */
	async get( id: number, signal?: AbortSignal ): Promise< MediaItem > {
		const url = `${ this.apiRoot }/wp/v2/media/${ id }`;
		const data = await this.request< WpRestMediaItem >( { method: 'GET', url, signal } );
		return mapMediaItem( data );
	}

	/* -------------------------------------------------------------- */
	/*  Private helpers                                                */
	/* -------------------------------------------------------------- */

	private async request< T = unknown >( req: {
		method: 'GET' | 'POST' | 'DELETE';
		url: string;
		body?: unknown;
		signal?: AbortSignal;
	} ): Promise< T & { items?: WpRestMediaItem[]; _total?: number; _totalPages?: number } > {
		const headers: Record< string, string > = {
			Accept: 'application/json',
		};
		if ( this.nonce ) {
			headers[ 'X-WP-Nonce' ] = this.nonce;
		}
		if ( req.body !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		const response = await fetch( req.url, {
			method: req.method,
			credentials: 'same-origin',
			headers,
			body: req.body !== undefined ? JSON.stringify( req.body ) : undefined,
			signal: req.signal,
		} );

		if ( ! response.ok ) {
			let detail = '';
			try {
				const err = ( await response.json() ) as { message?: string; code?: string };
				detail = err?.message ?? err?.code ?? '';
			} catch { /* Body wasn't JSON. */ }
			throw new Error(
				detail ||
					__( 'Media Library request failed (status %d).', 'nvoos-pro-spa' ).replace(
						'%d',
						String( response.status )
					)
			);
		}

		const totalPages = response.headers.get( 'X-WP-TotalPages' );
		const total = response.headers.get( 'X-WP-Total' );

		const items = ( await response.json() ) as T[];
		return {
			...( items as unknown as object ),
			items: Array.isArray( items ) ? items as WpRestMediaItem[] : [],
			_total: total ? parseInt( total, 10 ) : 0,
			_totalPages: totalPages ? parseInt( totalPages, 10 ) : 1,
		} as T & { items?: WpRestMediaItem[]; _total?: number; _totalPages?: number };
	}
}
