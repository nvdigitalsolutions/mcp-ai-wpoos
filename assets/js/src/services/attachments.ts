/**
 * Chat Attachments Service — TypeScript edition.
 *
 * Handles file attachment operations for the NV oOS chat interface.
 * This includes:
 * - File uploads and validation
 * - Attachment rendering and display
 * - Attachment metadata management
 * - File type checking
 * - Pending attachment state management
 *
 * Exports everything as named ESM exports while also registering on
 * `window.wpMcpAiChatAttachments` for backward compatibility with the
 * plain-JS chat.js.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

// ── Types ────────────────────────────────────────────────────────────

export interface FileTypeInfo {
	icon: string;
	label: string;
}

export interface NormalisedAttachmentRecord {
	id: string | number | null;
	fileId: string | number | null;
	attachmentId: string | number | null;
	url: string | null;
	name: string;
	type: string;
	size: number;
}

export interface AttachmentMeta {
	type: string;
	name: string;
	size?: number;
	url?: string;
	attachment_id?: string | number | null;
}

export interface DisplayAttachment {
	type: string;
	name: string;
	url?: string;
	size?: number;
	attachment_id?: string | number | null;
}

export interface ContentSegment {
	type: string;
	attachment_id?: string | number | null;
	url?: string;
	mime_type?: string;
	file_name?: string;
	file_size?: number;
}

/** Minimal chat state shape needed by attachment functions. */
export interface ChatState {
	allowedFileTypes?: string[];
	config?: {
		filesEndpoint?: string;
	};
	[key: string]: unknown;
}

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * Get file extension from a file object or filename.
 *
 * @param file - File object or filename string.
 * @return File extension in lowercase (without dot), or empty string.
 */
export function getFileExtension( file: File | string ): string {
	let name = '';
	if ( file && typeof file === 'object' && ( file as File ).name ) {
		name = ( file as File ).name;
	} else if ( typeof file === 'string' ) {
		name = file;
	}

	if ( ! name ) {
		return '';
	}

	const lastDot = name.lastIndexOf( '.' );
	if ( lastDot === -1 || lastDot === name.length - 1 ) {
		return '';
	}

	return name.substring( lastDot + 1 ).toLowerCase();
}

/**
 * Check if a file type is allowed based on assistant configuration.
 *
 * @param file - File object to check.
 * @param state - Chat state object containing allowedFileTypes.
 * @return True if file type is allowed.
 */
export function isFileTypeAllowed( file: File, state: ChatState | null ): boolean {
	if ( ! file || ! state ) {
		return false;
	}

	const allowedTypes: string[] = ( state.allowedFileTypes && Array.isArray( state.allowedFileTypes ) )
		? state.allowedFileTypes
		: [];

	if ( ! allowedTypes.length ) {
		// If no restrictions configured, allow common types
		return true;
	}

	const ext = getFileExtension( file );
	if ( ! ext ) {
		return false;
	}

	// Check if extension is in allowed list (case-insensitive)
	return allowedTypes.some( function ( allowedExt: string ): boolean {
		return !!( allowedExt && allowedExt.toLowerCase() === ext );
	} );
}

/**
 * Check if a URL is a real attachment URL (HTTP/HTTPS) vs display-only (blob:/data:).
 * Real attachment URLs should be preserved for the API, while display-only URLs should be stripped.
 * Uses URL constructor for robust validation.
 *
 * @param url - URL to check.
 * @return True if URL is a real HTTP/HTTPS attachment URL.
 */
export function isRealAttachmentUrl( url: string | unknown ): boolean {
	if ( ! url || typeof url !== 'string' ) {
		return false;
	}

	const trimmedUrl = ( url as string ).trim();

	// Use URL constructor for robust validation
	try {
		const parsedUrl = new URL( trimmedUrl );
		const protocol = parsedUrl.protocol.toLowerCase();

		// Only accept HTTP and HTTPS protocols (real attachment URLs from WordPress)
		// Reject other protocols like javascript:, data:, blob:, etc.
		return protocol === 'http:' || protocol === 'https:';
	} catch ( _e ) {
		// Invalid URL format - treat as display-only
		return false;
	}
}

/**
 * Get file type information (icon and label) for an attachment.
 *
 * Returns an object with `icon` (emoji) and `label` (human-readable type)
 * based on the file's MIME type or extension. Supports documents, spreadsheets,
 * presentations, text files, code, audio, video, images, and archives.
 *
 * @param attachment - Attachment object with optional type/name/file_name.
 * @return Object with `icon` and `label` properties.
 */
export function getFileTypeInfo( attachment: Record< string, unknown > | null ): FileTypeInfo {
	const fallback: FileTypeInfo = { icon: '\uD83D\uDCC4', label: 'File' }; // 📄

	if ( ! attachment ) {
		return fallback;
	}

	const mime = ( ( attachment.type as string ) || ( attachment.mime as string ) || ( attachment.mime_type as string ) || '' ).toLowerCase();
	const ext = getFileExtension( ( attachment.name as string ) || ( attachment.file_name as string ) || '' );

	// Image types
	if ( mime.indexOf( 'image/' ) === 0 || [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'heic', 'heif', 'ico', 'tiff', 'tif', 'avif' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDDBC\uFE0F', label: 'Image' }; // 🖼️
	}

	// Video types
	if ( mime.indexOf( 'video/' ) === 0 || [ 'mp4', 'webm', 'ogg', 'ogv', 'mov', 'avi', 'mkv', 'flv', 'wmv' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83C\uDFA5', label: 'Video' }; // 🎥
	}

	// Audio types
	if ( mime.indexOf( 'audio/' ) === 0 || [ 'mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'opus', 'wma', 'aiff' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83C\uDFB5', label: 'Audio' }; // 🎵
	}

	// PDF
	if ( mime === 'application/pdf' || ext === 'pdf' ) {
		return { icon: '\uD83D\uDCC4', label: 'PDF' }; // 📄
	}

	// Word documents
	if ( mime.indexOf( 'wordprocessingml' ) !== -1 || mime.indexOf( 'ms-word' ) !== -1 || mime === 'application/msword' || [ 'doc', 'docx', 'docm', 'dotx', 'dotm', 'odt' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDCD8', label: 'Word Document' }; // 📘
	}

	// Excel spreadsheets
	if ( mime.indexOf( 'spreadsheetml' ) !== -1 || mime.indexOf( 'ms-excel' ) !== -1 || [ 'xls', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'ods' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDCCA', label: 'Spreadsheet' }; // 📊
	}

	// PowerPoint presentations
	if ( mime.indexOf( 'presentationml' ) !== -1 || mime.indexOf( 'ms-powerpoint' ) !== -1 || mime === 'application/vnd.ms-powerpoint' || [ 'ppt', 'pptx', 'pptm', 'ppsx', 'odp' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDCFD\uFE0F', label: 'Presentation' }; // 📽️
	}

	// Markdown
	if ( mime === 'text/markdown' || [ 'md', 'markdown' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDCDD', label: 'Markdown' }; // 📝
	}

	// CSV / TSV
	if ( mime === 'text/csv' || mime === 'text/tab-separated-values' || [ 'csv', 'tsv' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDCC8', label: 'Data File' }; // 📈
	}

	// Plain text
	if ( mime === 'text/plain' || ext === 'txt' ) {
		return { icon: '\uD83D\uDCC3', label: 'Text File' }; // 📃
	}

	// HTML
	if ( mime === 'text/html' || [ 'html', 'htm' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83C\uDF10', label: 'HTML' }; // 🌐
	}

	// JSON / NDJSON / JSONL
	if ( mime === 'application/json' || mime === 'application/x-ndjson' || mime === 'application/jsonl' || [ 'json', 'jsonl', 'ndjson' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDD27', label: 'JSON' }; // 🔧
	}

	// XML
	if ( mime === 'application/xml' || mime === 'text/xml' || ext === 'xml' ) {
		return { icon: '\uD83D\uDD27', label: 'XML' }; // 🔧
	}

	// Code files
	if ( [ 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'php', 'java', 'c', 'cpp', 'cs', 'go', 'rs', 'swift', 'kt', 'sh', 'bash', 'sql', 'r', 'yml', 'yaml', 'toml', 'ini', 'cfg', 'conf' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDCBB', label: 'Code' }; // 💻
	}

	// Archive files
	if ( mime.indexOf( 'zip' ) !== -1 || mime.indexOf( 'compressed' ) !== -1 || mime.indexOf( 'archive' ) !== -1 || [ 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz' ].indexOf( ext ) !== -1 ) {
		return { icon: '\uD83D\uDCE6', label: 'Archive' }; // 📦
	}

	// Other text/* types
	if ( mime.indexOf( 'text/' ) === 0 ) {
		return { icon: '\uD83D\uDCC3', label: 'Text File' }; // 📃
	}

	return fallback;
}

/**
 * Check if attachment is a video based on MIME type or file extension.
 *
 * @param attachment - Attachment object.
 * @return True if attachment is a video.
 */
export function isVideoAttachment( attachment: Record< string, unknown > | null ): boolean {
	if ( ! attachment ) {
		return false;
	}

	// Check MIME type first
	if ( attachment.type && typeof attachment.type === 'string' ) {
		if ( ( attachment.type as string ).startsWith( 'video/' ) ) {
			return true;
		}
	}

	// Fallback to file extension check
	if ( attachment.name || attachment.file_name ) {
		const name = ( attachment.name || attachment.file_name ) as string;
		const ext = getFileExtension( name );
		const videoExtensions = [ 'mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'flv', 'wmv' ];
		return videoExtensions.indexOf( ext ) !== -1;
	}

	return false;
}

/**
 * Check if attachment is an audio file based on MIME type or file extension.
 *
 * @param attachment - Attachment object.
 * @return True if attachment is audio.
 */
export function isAudioAttachment( attachment: Record< string, unknown > | null ): boolean {
	if ( ! attachment ) {
		return false;
	}

	// Check MIME type first
	if ( attachment.type && typeof attachment.type === 'string' ) {
		if ( ( attachment.type as string ).indexOf( 'audio/' ) === 0 ) {
			return true;
		}
	}

	// Fallback to file extension check
	const name = ( attachment.name || attachment.file_name || attachment.label || '' ) as string;
	if ( name ) {
		const ext = getFileExtension( name );
		const audioExtensions = [ 'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'opus', 'mid', 'midi' ];
		return audioExtensions.indexOf( ext ) !== -1;
	}

	// Check URL for audio extensions
	const url = ( attachment.url || '' ) as string;
	if ( url && typeof url === 'string' ) {
		const urlPath = url.toLowerCase().split( '?' )[ 0 ].split( '#' )[ 0 ];
		const audioExts = [ '.mp3', '.wav', '.ogg', '.flac', '.aac', '.m4a', '.wma' ];
		for ( let i = 0; i < audioExts.length; i++ ) {
			if ( urlPath.lastIndexOf( audioExts[ i ] ) === urlPath.length - audioExts[ i ].length ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Normalize upload response from server.
 *
 * @param data - Response data from upload endpoint.
 * @param file - Original file object.
 * @return Normalized attachment record, or null.
 */
export function normaliseUploadResponse(
	data: Record< string, unknown > | null,
	file: File | null,
): NormalisedAttachmentRecord | null {
	if ( ! data ) {
		return null;
	}

	// If data already has expected structure, return as-is
	if ( data.id || data.fileId || data.url ) {
		return {
			id: ( data.id || data.fileId || null ) as string | number | null,
			fileId: ( data.fileId || data.id || null ) as string | number | null,
			attachmentId: ( data.attachment_id || data.attachmentId || data.id || data.fileId || null ) as string | number | null,
			url: ( data.url || null ) as string | null,
			name: ( data.name || data.fileName || data.file_name || ( file && file.name ) || '' ) as string,
			type: ( data.type || data.mimeType || data.mime_type || ( file && file.type ) || '' ) as string,
			size: ( data.size || data.fileSize || data.file_size || ( file && file.size ) || 0 ) as number,
		};
	}

	// Handle alternative response structures
	if ( data.data && ( data.data as Record< string, unknown > ).id || ( data.data as Record< string, unknown > ).fileId || ( data.data as Record< string, unknown > ).url ) {
		const inner = data.data as Record< string, unknown >;
		return {
			id: ( inner.id || inner.fileId || null ) as string | number | null,
			fileId: ( inner.fileId || inner.id || null ) as string | number | null,
			attachmentId: ( inner.attachment_id || inner.attachmentId || inner.id || inner.fileId || null ) as string | number | null,
			url: ( inner.url || null ) as string | null,
			name: ( inner.name || inner.fileName || inner.file_name || ( file && file.name ) || '' ) as string,
			type: ( inner.type || inner.mimeType || inner.mime_type || ( file && file.type ) || '' ) as string,
			size: ( inner.size || inner.fileSize || inner.file_size || ( file && file.size ) || 0 ) as number,
		};
	}

	return null;
}

/**
 * Normalize attachment record from various sources.
 *
 * @param raw - Raw attachment data.
 * @return Normalized attachment record, or null.
 */
export function normaliseAttachmentRecord( raw: Record< string, unknown > | null ): NormalisedAttachmentRecord | null {
	if ( ! raw ) {
		return null;
	}

	return {
		id: ( raw.id || raw.fileId || raw.attachment_id || null ) as string | number | null,
		fileId: ( raw.fileId || raw.id || raw.attachment_id || null ) as string | number | null,
		attachmentId: ( raw.attachment_id || raw.attachmentId || raw.id || raw.fileId || null ) as string | number | null,
		url: ( raw.url || raw.src || null ) as string | null,
		name: ( raw.name || raw.fileName || raw.file_name || raw.title || '' ) as string,
		type: ( raw.type || raw.mimeType || raw.mime_type || '' ) as string,
		size: ( raw.size || raw.fileSize || raw.file_size || 0 ) as number,
	};
}

/**
 * Build attachment metadata for display.
 *
 * @param record - Attachment record.
 * @return Attachment metadata object, or null.
 */
export function buildAttachmentMeta( record: NormalisedAttachmentRecord | null ): AttachmentMeta | null {
	if ( ! record ) {
		return null;
	}

	const meta: AttachmentMeta = {
		type: record.type || '',
		name: record.name || '',
	};

	if ( record.size ) {
		meta.size = record.size;
	}

	if ( record.url ) {
		meta.url = record.url;
	}

	if ( record.id || record.fileId || record.attachmentId ) {
		meta.attachment_id = record.id || record.fileId || record.attachmentId;
	}

	return meta;
}

/**
 * Build display attachment object for rendering.
 *
 * @param attachment - Attachment record.
 * @param state - Chat state object.
 * @return Display attachment object or null.
 */
export function buildDisplayAttachment(
	attachment: NormalisedAttachmentRecord | null,
	state: ChatState | null,
): DisplayAttachment | null {
	if ( ! attachment ) {
		return null;
	}

	const display: DisplayAttachment = {
		type: attachment.type || '',
		name: attachment.name || '',
	};

	// Add URL if available
	if ( attachment.url ) {
		display.url = attachment.url;
	} else if ( attachment.fileId && state ) {
		// Build URL from fileId if we have state
		display.url = buildFileDownloadUrl( state, attachment.fileId );
	}

	// Add size if available
	if ( attachment.size ) {
		display.size = attachment.size;
	}

	// Add attachment_id for API
	if ( attachment.id || attachment.fileId || attachment.attachmentId ) {
		display.attachment_id = attachment.id || attachment.fileId || attachment.attachmentId;
	}

	return display;
}

/**
 * Build file download URL from file ID.
 *
 * @param state - Chat state object.
 * @param fileId - File ID.
 * @return Download URL.
 */
export function buildFileDownloadUrl( state: ChatState, fileId: string | number ): string {
	if ( ! state || ! state.config || ! state.config.filesEndpoint ) {
		return '';
	}

	if ( ! fileId ) {
		return '';
	}

	const base = state.config.filesEndpoint;
	const separator = base.indexOf( '?' ) === -1 ? '?' : '&';
	return base + separator + 'file_id=' + encodeURIComponent( String( fileId ) );
}

/**
 * Get attachment URL from record, building it if needed.
 *
 * @param record - Attachment record.
 * @param state - Chat state object.
 * @return Attachment URL.
 */
export function getAttachmentUrlFromRecord( record: NormalisedAttachmentRecord | null, state: ChatState | null ): string {
	if ( ! record ) {
		return '';
	}

	// Return existing URL if available
	if ( record.url ) {
		return record.url;
	}

	// Build URL from file ID
	const fileId = record.id || record.fileId || record.attachmentId;
	if ( fileId && state ) {
		return buildFileDownloadUrl( state, fileId );
	}

	return '';
}

/**
 * Strip display-only data from attachment segments.
 * Preserves attachment_id, real HTTP/HTTPS URLs, and API-required fields.
 *
 * @param segment - Content segment to process.
 * @return Processed segment.
 */
export function stripSegmentDisplayData( segment: ContentSegment | null ): ContentSegment | null {
	if ( ! segment || segment.type !== 'attachment' ) {
		return segment;
	}

	// Start with minimal required fields
	const stripped: ContentSegment = {
		type: 'attachment',
		attachment_id: segment.attachment_id,
	};

	// Preserve real attachment URLs (HTTP/HTTPS) for the agentic workflow
	if ( segment.url && isRealAttachmentUrl( segment.url ) ) {
		stripped.url = segment.url;
	}

	return stripped;
}

/**
 * Create content segment from attachment for message construction.
 *
 * @param attachment - Attachment object.
 * @return Content segment, or null.
 */
export function createSegmentFromAttachment( attachment: NormalisedAttachmentRecord | null ): ContentSegment | null {
	if ( ! attachment ) {
		return null;
	}

	const segment: ContentSegment = {
		type: 'attachment',
	};

	// Add attachment_id if available (required for API)
	if ( attachment.id || attachment.fileId || attachment.attachmentId ) {
		segment.attachment_id = attachment.id || attachment.fileId || attachment.attachmentId;
	}

	// Add URL if it's a real HTTP/HTTPS URL
	if ( attachment.url && isRealAttachmentUrl( attachment.url ) ) {
		segment.url = attachment.url;
	}

	// Add display metadata for UI (will be stripped before sending to API)
	if ( attachment.type ) {
		segment.mime_type = attachment.type;
	}

	if ( attachment.name ) {
		segment.file_name = attachment.name;
	}

	if ( attachment.size ) {
		segment.file_size = attachment.size;
	}

	return segment;
}

/**
 * Add attachment metadata to an existing content segment.
 *
 * @param segment - Content segment to enhance.
 * @param attachment - Attachment metadata.
 * @return Enhanced segment.
 */
export function addAttachmentMetadataToSegment(
	segment: ContentSegment | null,
	attachment: NormalisedAttachmentRecord | null,
): ContentSegment | null {
	if ( ! segment || ! attachment ) {
		return segment;
	}

	// Add attachment_id if available
	if ( attachment.id || attachment.fileId || attachment.attachmentId ) {
		segment.attachment_id = attachment.id || attachment.fileId || attachment.attachmentId;
	}

	// Add URL if available and real
	if ( attachment.url && isRealAttachmentUrl( attachment.url ) ) {
		segment.url = attachment.url;
	}

	// Add display metadata
	if ( attachment.type ) {
		segment.mime_type = attachment.type;
	}

	if ( attachment.name ) {
		segment.file_name = attachment.name;
	}

	if ( attachment.size ) {
		segment.file_size = attachment.size;
	}

	return segment;
}

/**
 * Create Content-Disposition header for file upload.
 *
 * @param filename - Filename to encode.
 * @return Content-Disposition header value.
 */
export function createContentDispositionHeader( filename: string ): string {
	if ( ! filename || typeof filename !== 'string' ) {
		return '';
	}

	// Encode filename for header (replace problematic characters)
	const safeName = filename.replace( /[^\w\s.-]/g, '_' );
	return 'attachment; filename="' + safeName + '"';
}

// ── Backward-compatible global registration ──────────────────────────

( window as unknown as Record< string, unknown > ).wpMcpAiChatAttachments = {
	getFileExtension,
	isFileTypeAllowed,
	isRealAttachmentUrl,
	getFileTypeInfo,
	isVideoAttachment,
	isAudioAttachment,
	normaliseUploadResponse,
	normaliseAttachmentRecord,
	buildAttachmentMeta,
	buildDisplayAttachment,
	buildFileDownloadUrl,
	getAttachmentUrlFromRecord,
	stripSegmentDisplayData,
	createSegmentFromAttachment,
	addAttachmentMetadataToSegment,
	createContentDispositionHeader,
};
