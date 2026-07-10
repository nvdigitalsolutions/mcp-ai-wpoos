/**
 * Normalise tool result data for structured display.
 *
 * Mirrors the logic in legacy `chat.js` — `normaliseToolResultForDisplay`,
 * `normaliseArrayToolResult`, `normaliseJetEngineRoutesResult`,
 * `normaliseChartResult`, `extractGenericToolResponse`, and friends.
 *
 * The goal is to turn the raw tool result (string, array, or object) into a
 * typed shape that `ToolCallCard` can render with proper structure:
 * images, videos, files, charts, and formatted text.
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface AttachmentEntry {
	url: string;
	label: string;
	downloadName?: string;
	meta?: string;
	mimeType?: string;
	bytes?: number;
	isImage?: boolean;
	isVideo?: boolean;
}

export interface NormalisedToolResult {
	/** Human-readable summary text (may be empty when only attachments exist). */
	text: string;
	/** List of file / image / video attachments to render. */
	attachments: AttachmentEntry[];
	/** Raw chart HTML (from Chart.js-style tools). */
	chartHtml?: string;
	chartWidth?: number;
	chartHeight?: number;
	/** Whether the result represents an error. */
	isError: boolean;
	/** Whether the result was truncated by the backend. */
	isTruncated: boolean;
	/** Whether this is an async pending result (not yet complete). */
	isAsyncPending: boolean;
	/** Raw parsed JSON for the JSON-viewer fallback. */
	rawJson?: unknown;
}

// ---------------------------------------------------------------------------
// URL sanitization (mirrors legacy `sanitizeToolResultUrl`)
// ---------------------------------------------------------------------------

const ALLOWED_SCHEMES = /^(https?:)?\/\//i;
const SCRIPT_TAG_RE = /<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi;
const JAVASCRIPT_PROTO_RE = /javascript\s*:/gi;
const DATA_IMG_RE = /^data:image\//i;
const BLOB_RE = /^blob:/i;

function sanitizeUrl( url: string ): string {
	if ( ! url ) return '';
	let s = url.trim();
	s = s.replace( SCRIPT_TAG_RE, '' );
	s = s.replace( JAVASCRIPT_PROTO_RE, '' );
	if ( DATA_IMG_RE.test( s ) || BLOB_RE.test( s ) ) return s;
	if ( ! ALLOWED_SCHEMES.test( s ) ) return '';
	return s;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatBytes( bytes: number ): string {
	if ( typeof bytes !== 'number' || ! isFinite( bytes ) || bytes <= 0 ) return '';
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let exponent = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );
	exponent = Math.min( units.length - 1, Math.max( exponent, 0 ) );
	const value = bytes / Math.pow( 1024, exponent );
	const decimals = exponent === 0 ? 0 : value >= 10 ? 1 : 2;
	return value.toFixed( decimals ) + ' ' + units[ exponent ];
}

function isAsyncPending( result: unknown ): boolean {
	if ( ! result || typeof result !== 'object' ) return false;
	const r = result as Record< string, unknown >;
	return r.async === true && r.status === 'pending';
}

function isTruncated( text: string ): boolean {
	return (
		text.includes( '[tool_result_truncated]' ) ||
		text.includes( '…[truncated]' ) ||
		text.includes( '…[content truncated' )
	);
}

function isErrorResult( text: string ): boolean {
	const lower = text.toLowerCase();
	return (
		lower.includes( 'error' ) ||
		lower.includes( 'invalid' ) ||
		lower.includes( 'failed' ) ||
		lower.includes( 'forbidden' ) ||
		lower.includes( 'missing' )
	);
}

// Build a metadata string from attachment record (mirrors legacy `buildAttachmentMeta`).
function buildAttachmentMeta( record: Record< string, unknown > ): string {
	const parts: string[] = [];

	const mime: string = ( record.mime_type as string ) || ( record.mimeType as string ) || '';
	if ( mime ) {
		const suffix = mime.split( '/' ).pop() || mime;
		parts.push( suffix.toUpperCase() );
	}

	const bytes = typeof record.bytes === 'number' ? record.bytes : null;
	if ( bytes !== null && bytes > 0 ) {
		parts.push( formatBytes( bytes ) );
	}

	if ( typeof record.size === 'string' && record.size.trim() ) {
		parts.push( record.size.trim() );
	}

	const attachmentId = record.attachment_id || record.attachmentId || null;
	if ( attachmentId ) {
		parts.push( 'ID ' + String( attachmentId ) );
	}

	return parts.join( ' • ' );
}

// ---------------------------------------------------------------------------
// URL extraction from various result shapes
// ---------------------------------------------------------------------------

function extractUrl( result: Record< string, unknown > ): string {
	// Direct URLs
	for ( const key of [ 'url', 'download_url', 'downloadUrl', 'image_url', 'video_url' ] ) {
		const v = result[ key ];
		if ( typeof v === 'string' && v.trim() ) return sanitizeUrl( v.trim() );
	}

	// Nested image object
	const nestedImage = result.image as Record< string, unknown > | undefined;
	if ( nestedImage && typeof nestedImage === 'object' ) {
		for ( const key of [ 'url', 'download_url', 'downloadUrl' ] ) {
			const v = nestedImage[ key ];
			if ( typeof v === 'string' && v.trim() ) return sanitizeUrl( v.trim() );
		}
	}

	// Nested video object
	const nestedVideo = result.video_url as Record< string, unknown > | undefined;
	if ( nestedVideo && typeof nestedVideo === 'object' ) {
		const v = nestedVideo.url;
		if ( typeof v === 'string' && v.trim() ) return sanitizeUrl( v.trim() );
	}

	return '';
}

function extractLabel( result: Record< string, unknown > ): string {
	const title = result.title;
	if ( typeof title === 'string' && title.trim() ) return title.trim();
	const fileName = result.file_name || result.fileName;
	if ( typeof fileName === 'string' && fileName.trim() ) return fileName.trim();
	const nestedImage = result.image as Record< string, unknown > | undefined;
	if ( nestedImage && typeof nestedImage === 'object' ) {
		const t = nestedImage.title || nestedImage.file_name || nestedImage.fileName;
		if ( typeof t === 'string' && t.trim() ) return t.trim();
	}
	return '';
}

function isImageUrl( url: string ): boolean {
	const imageExt = /\.(png|jpg|jpeg|gif|webp|svg)(\?|$)/i;
	const dataImage = /^data:image\//i;
	return imageExt.test( url ) || dataImage.test( url );
}

function isVideoUrl( url: string ): boolean {
	const videoExt = /\.(mp4|webm|ogg|mov)(\?|$)/i;
	return videoExt.test( url );
}

function getMimeFromUrl( url: string ): string {
	if ( /\.png(\?|$)/i.test( url ) ) return 'image/png';
	if ( /\.jpe?g(\?|$)/i.test( url ) ) return 'image/jpeg';
	if ( /\.gif(\?|$)/i.test( url ) ) return 'image/gif';
	if ( /\.webp(\?|$)/i.test( url ) ) return 'image/webp';
	if ( /\.svg(\?|$)/i.test( url ) ) return 'image/svg+xml';
	if ( /\.mp4(\?|$)/i.test( url ) ) return 'video/mp4';
	if ( /\.webm(\?|$)/i.test( url ) ) return 'video/webm';
	if ( /\.ogg(\?|$)/i.test( url ) ) return 'video/ogg';
	if ( /\.mov(\?|$)/i.test( url ) ) return 'video/quicktime';
	return '';
}

// ---------------------------------------------------------------------------
// Array result normalization
// ---------------------------------------------------------------------------

function normaliseArrayResult( arr: unknown[], toolName: string ): NormalisedToolResult | null {
	if ( arr.length === 0 ) return null;

	// search_attachments — each item has id, title, mime_type, download_url, etc.
	if ( toolName === 'search_attachments' ) {
		const attachments: AttachmentEntry[] = [];
		for ( const item of arr ) {
			if ( ! item || typeof item !== 'object' ) continue;
			const r = item as Record< string, unknown >;
			const url = sanitizeUrl( String( r.download_url || r.url || '' ) );
			if ( ! url ) continue;
			const label = String( r.title || 'Untitled' );
			const meta = buildAttachmentMeta( r );
			const isImg = isImageUrl( url );
			const isVid = isVideoUrl( url );
			attachments.push( { url, label, meta, isImage: isImg, isVideo: isVid } );
		}
		if ( attachments.length === 0 ) {
			return { text: 'No attachments found.', attachments: [], isError: false, isTruncated: false, isAsyncPending: false };
		}
		return {
			text: 'Found ' + arr.length + ' attachment(s):',
			attachments,
			isError: false,
			isTruncated: false,
			isAsyncPending: false,
		};
	}

	// Generic array — build text list
	const items = arr
		.map( ( item ) => {
			if ( typeof item === 'string' ) return item;
			if ( item && typeof item === 'object' ) {
				const r = item as Record< string, unknown >;
				return String( r.title || r.name || r.message || r.text || JSON.stringify( item ) );
			}
			return String( item );
		} )
		.filter( ( s ) => s.trim() );

	if ( items.length === 0 ) return null;
	return {
		text: items.join( '\n' ),
		attachments: [],
		isError: false,
		isTruncated: false,
		isAsyncPending: false,
	};
}

// ---------------------------------------------------------------------------
// Chart result normalization
// ---------------------------------------------------------------------------

function normaliseChartResult( result: Record< string, unknown > ): NormalisedToolResult | null {
	const html = result.html;
	if ( typeof html !== 'string' || ! html.trim() ) return null;
	const text = typeof result.text === 'string' ? result.text : '';
	const width = typeof result.width === 'number' ? result.width : undefined;
	const height = typeof result.height === 'number' ? result.height : undefined;
	return {
		text: text || 'Chart generated',
		attachments: [],
		chartHtml: html.trim(),
		chartWidth: width,
		chartHeight: height,
		isError: false,
		isTruncated: false,
		isAsyncPending: false,
	};
}

// ---------------------------------------------------------------------------
// Crawl4AI result normalization
// ---------------------------------------------------------------------------

function normaliseCrawl4aiResult( result: Record< string, unknown > ): NormalisedToolResult | null {
	const markdown = result.markdown || result.content || result.text;
	const text: string = typeof markdown === 'string' ? markdown : JSON.stringify( markdown || '' );
	return {
		text,
		attachments: [],
		isError: false,
		isTruncated: isTruncated( text ),
		isAsyncPending: false,
	};
}

// ---------------------------------------------------------------------------
// JetEngine REST routes normalization
// ---------------------------------------------------------------------------

function normaliseJetEngineRoutesResult( result: Record< string, unknown > ): NormalisedToolResult {
	const routes = result.routes;
	if ( ! Array.isArray( routes ) || routes.length === 0 ) {
		return { text: 'No JetEngine REST routes found.', attachments: [], isError: false, isTruncated: false, isAsyncPending: false };
	}
	const namespace = String( result.namespace || 'jet-engine/v2' );
	const lines: string[] = [ 'Available JetEngine REST API Routes (' + namespace + '):', '' ];
	routes.forEach( ( route: unknown, index: number ) => {
		if ( ! route || typeof route !== 'object' ) return;
		const r = route as Record< string, unknown >;
		const path = String( r.path || '' );
		const methods = Array.isArray( r.methods ) ? r.methods.join( ', ' ) : '';
		const desc = String( r.description || '' );
		lines.push( ( index + 1 ) + '. ' + methods + ' ' + path );
		if ( desc ) lines.push( '   ' + desc );
		if ( index < routes.length - 1 ) lines.push( '' );
	} );
	return { text: lines.join( '\n' ), attachments: [], isError: false, isTruncated: false, isAsyncPending: false };
}

// ---------------------------------------------------------------------------
// Generic object result extraction
// ---------------------------------------------------------------------------

function extractGenericToolResponse( result: Record< string, unknown > ): NormalisedToolResult {
	const text = typeof result.text === 'string' ? result.text
		: typeof result.message === 'string' ? result.message
			: typeof result.summary === 'string' ? result.summary
				: typeof result.content === 'string' ? result.content
					: typeof result.data === 'string' ? result.data
						: '';

	// Check for inline content (base64 image data, etc.)
	const inlineContent = result.inlineData || result.inline_data || result.b64_json || null;
	if ( inlineContent && typeof inlineContent === 'string' ) {
		return {
			text: text || 'Inline content',
			attachments: [ { url: inlineContent, label: 'Inline content', isImage: inlineContent.startsWith( 'data:image/' ) } ],
			isError: false,
			isTruncated: false,
			isAsyncPending: false,
		};
	}

	return {
		text: text || 'Tool completed successfully',
		attachments: [],
		isError: isErrorResult( text ),
		isTruncated: isTruncated( text ),
		isAsyncPending: false,
	};
}

// ---------------------------------------------------------------------------
// Main normalisation entry point
// ---------------------------------------------------------------------------

export function normaliseToolResult(
	toolName: string,
	result: unknown
): NormalisedToolResult {
	// --- String result ---
	if ( typeof result === 'string' ) {
		const text = result.trim();
		return {
			text: text || 'Empty result',
			attachments: [],
			isError: isErrorResult( text ),
			isTruncated: isTruncated( text ),
			isAsyncPending: false,
			rawJson: text,
		};
	}

	// --- Non-object ---
	if ( ! result || typeof result !== 'object' ) {
		return {
			text: String( result ?? '' ),
			attachments: [],
			isError: false,
			isTruncated: false,
			isAsyncPending: false,
		};
	}

	const r = result as Record< string, unknown >;

	// --- Async pending ---
	if ( isAsyncPending( r ) ) {
		const msg = typeof r.message === 'string' ? r.message : 'Tool is processing in the background…';
		return {
			text: msg,
			attachments: [],
			isError: false,
			isTruncated: false,
			isAsyncPending: true,
		};
	}

	// --- Array result ---
	if ( Array.isArray( result ) ) {
		const normalised = normaliseArrayResult( result as unknown[], toolName );
		if ( normalised ) return normalised;
	}

	// --- Chart output ---
	if ( r.output_format === 'chart' && typeof r.html === 'string' && r.html.trim() ) {
		const chart = normaliseChartResult( r );
		if ( chart ) return chart;
	}

	// --- Tool-specific handlers ---
	if ( toolName === 'run_crawl4ai_job' ) {
		const crawl = normaliseCrawl4aiResult( r );
		if ( crawl ) return crawl;
	}

	if ( toolName === 'list_jetengine_rest_routes' ) {
		return normaliseJetEngineRoutesResult( r );
	}

	// --- URL-based (images, videos, files) ---
	const url = extractUrl( r );
	if ( url ) {
		const label = extractLabel( r ) || toolName;
		const meta = buildAttachmentMeta( r );
		const isImg = isImageUrl( url );
		const isVid = isVideoUrl( url );

		const attachments: AttachmentEntry[] = [ { url, label, meta, isImage: isImg, isVideo: isVid } ];

		let text = label;
		if ( r.caption && typeof r.caption === 'string' ) text = r.caption;
		else if ( r.alt && typeof r.alt === 'string' ) text = r.alt;

		return {
			text,
			attachments,
			isError: false,
			isTruncated: false,
			isAsyncPending: false,
		};
	}

	// --- Generic object ---
	return extractGenericToolResponse( r );
}
