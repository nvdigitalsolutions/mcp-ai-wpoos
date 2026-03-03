/**
 * Chat Attachments Service
 *
 * Handles file attachment operations for the NV oOS chat interface.
 * This includes:
 * - File uploads and validation
 * - Attachment rendering and display
 * - Attachment metadata management
 * - File type checking
 * - Pending attachment state management
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

(function() {
    'use strict';

    /**
     * Create the attachments service and expose it globally.
     */
    const wpMcpAiChatAttachments = {
        /**
         * Get file extension from a file object or filename.
         *
         * @param {File|string} file - File object or filename string.
         * @return {string} File extension in lowercase (without dot), or empty string.
         */
        getFileExtension: function(file) {
            let name = '';
            if (file && typeof file === 'object' && file.name) {
                name = file.name;
            } else if (typeof file === 'string') {
                name = file;
            }

            if (!name) {
                return '';
            }

            const lastDot = name.lastIndexOf('.');
            if (lastDot === -1 || lastDot === name.length - 1) {
                return '';
            }

            return name.substring(lastDot + 1).toLowerCase();
        },

        /**
         * Check if a file type is allowed based on assistant configuration.
         *
         * @param {File} file - File object to check.
         * @param {Object} state - Chat state object containing allowedFileTypes.
         * @return {boolean} True if file type is allowed.
         */
        isFileTypeAllowed: function(file, state) {
            if (!file || !state) {
                return false;
            }

            const allowedTypes = (state.allowedFileTypes && Array.isArray(state.allowedFileTypes))
                ? state.allowedFileTypes
                : [];

            if (!allowedTypes.length) {
                // If no restrictions configured, allow common types
                return true;
            }

            const ext = this.getFileExtension(file);
            if (!ext) {
                return false;
            }

            // Check if extension is in allowed list (case-insensitive)
            return allowedTypes.some(function(allowedExt) {
                return allowedExt && allowedExt.toLowerCase() === ext;
            });
        },

        /**
         * Check if a URL is a real attachment URL (HTTP/HTTPS) vs display-only (blob:/data:).
         * Real attachment URLs should be preserved for the API, while display-only URLs should be stripped.
         * Uses URL constructor for robust validation.
         *
         * @param {string} url - URL to check.
         * @return {boolean} True if URL is a real HTTP/HTTPS attachment URL.
         */
        isRealAttachmentUrl: function(url) {
            if (!url || typeof url !== 'string') {
                return false;
            }
            
            const trimmedUrl = url.trim();
            
            // Use URL constructor for robust validation
            try {
                const parsedUrl = new URL(trimmedUrl);
                const protocol = parsedUrl.protocol.toLowerCase();
                
                // Only accept HTTP and HTTPS protocols (real attachment URLs from WordPress)
                // Reject other protocols like javascript:, data:, blob:, etc.
                return protocol === 'http:' || protocol === 'https:';
            } catch (e) {
                // Invalid URL format - treat as display-only
                return false;
            }
        },

        /**
         * Get file type information (icon and label) for an attachment.
         *
         * Returns an object with `icon` (emoji) and `label` (human-readable type)
         * based on the file's MIME type or extension. Supports documents, spreadsheets,
         * presentations, text files, code, audio, video, images, and archives.
         *
         * @param {Object} attachment - Attachment object with optional type/name/file_name.
         * @return {Object} Object with `icon` and `label` properties.
         */
        getFileTypeInfo: function(attachment) {
            var fallback = { icon: '\uD83D\uDCC4', label: 'File' }; // 📄

            if (!attachment) {
                return fallback;
            }

            var mime = (attachment.type || attachment.mime || attachment.mime_type || '').toLowerCase();
            var ext = this.getFileExtension(attachment.name || attachment.file_name || '');

            // Image types
            if (mime.indexOf('image/') === 0 || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'heic', 'heif', 'ico', 'tiff', 'tif', 'avif'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDDBC\uFE0F', label: 'Image' }; // 🖼️
            }

            // Video types
            if (mime.indexOf('video/') === 0 || ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'avi', 'mkv', 'flv', 'wmv'].indexOf(ext) !== -1) {
                return { icon: '\uD83C\uDFA5', label: 'Video' }; // 🎥
            }

            // Audio types
            if (mime.indexOf('audio/') === 0 || ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'opus', 'wma', 'aiff'].indexOf(ext) !== -1) {
                return { icon: '\uD83C\uDFB5', label: 'Audio' }; // 🎵
            }

            // PDF
            if (mime === 'application/pdf' || ext === 'pdf') {
                return { icon: '\uD83D\uDCC4', label: 'PDF' }; // 📄
            }

            // Word documents
            if (mime.indexOf('wordprocessingml') !== -1 || mime.indexOf('ms-word') !== -1 || mime === 'application/msword' || ['doc', 'docx', 'docm', 'dotx', 'dotm', 'odt'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDCD8', label: 'Word Document' }; // 📘
            }

            // Excel spreadsheets
            if (mime.indexOf('spreadsheetml') !== -1 || mime.indexOf('ms-excel') !== -1 || ['xls', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'ods'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDCCA', label: 'Spreadsheet' }; // 📊
            }

            // PowerPoint presentations
            if (mime.indexOf('presentationml') !== -1 || mime.indexOf('ms-powerpoint') !== -1 || mime === 'application/vnd.ms-powerpoint' || ['ppt', 'pptx', 'pptm', 'ppsx', 'odp'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDCFD\uFE0F', label: 'Presentation' }; // 📽️
            }

            // Markdown
            if (mime === 'text/markdown' || ['md', 'markdown'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDCDD', label: 'Markdown' }; // 📝
            }

            // CSV / TSV
            if (mime === 'text/csv' || mime === 'text/tab-separated-values' || ['csv', 'tsv'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDCC8', label: 'Data File' }; // 📈
            }

            // Plain text
            if (mime === 'text/plain' || ext === 'txt') {
                return { icon: '\uD83D\uDCC3', label: 'Text File' }; // 📃
            }

            // HTML
            if (mime === 'text/html' || ['html', 'htm'].indexOf(ext) !== -1) {
                return { icon: '\uD83C\uDF10', label: 'HTML' }; // 🌐
            }

            // JSON / NDJSON / JSONL
            if (mime === 'application/json' || mime === 'application/x-ndjson' || mime === 'application/jsonl' || ['json', 'jsonl', 'ndjson'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDD27', label: 'JSON' }; // 🔧
            }

            // XML
            if (mime === 'application/xml' || mime === 'text/xml' || ext === 'xml') {
                return { icon: '\uD83D\uDD27', label: 'XML' }; // 🔧
            }

            // Code files
            if (['js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'php', 'java', 'c', 'cpp', 'cs', 'go', 'rs', 'swift', 'kt', 'sh', 'bash', 'sql', 'r', 'yml', 'yaml', 'toml', 'ini', 'cfg', 'conf'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDCBB', label: 'Code' }; // 💻
            }

            // Archive files
            if (mime.indexOf('zip') !== -1 || mime.indexOf('compressed') !== -1 || mime.indexOf('archive') !== -1 || ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz'].indexOf(ext) !== -1) {
                return { icon: '\uD83D\uDCE6', label: 'Archive' }; // 📦
            }

            // Other text/* types
            if (mime.indexOf('text/') === 0) {
                return { icon: '\uD83D\uDCC3', label: 'Text File' }; // 📃
            }

            return fallback;
        },

        /**
         * Check if attachment is a video based on MIME type or file extension.
         *
         * @param {Object} attachment - Attachment object.
         * @return {boolean} True if attachment is a video.
         */
        isVideoAttachment: function(attachment) {
            if (!attachment) {
                return false;
            }

            // Check MIME type first
            if (attachment.type && typeof attachment.type === 'string') {
                if (attachment.type.startsWith('video/')) {
                    return true;
                }
            }

            // Fallback to file extension check
            if (attachment.name || attachment.file_name) {
                const name = attachment.name || attachment.file_name;
                const ext = this.getFileExtension(name);
                const videoExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'flv', 'wmv'];
                return videoExtensions.indexOf(ext) !== -1;
            }

            return false;
        },

        /**
         * Check if attachment is an audio file based on MIME type or file extension.
         *
         * @param {Object} attachment - Attachment object.
         * @return {boolean} True if attachment is audio.
         */
        isAudioAttachment: function(attachment) {
            if (!attachment) {
                return false;
            }

            // Check MIME type first
            if (attachment.type && typeof attachment.type === 'string') {
                if (attachment.type.indexOf('audio/') === 0) {
                    return true;
                }
            }

            // Fallback to file extension check
            var name = attachment.name || attachment.file_name || attachment.label || '';
            if (name) {
                var ext = this.getFileExtension(name);
                var audioExtensions = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'opus', 'mid', 'midi'];
                return audioExtensions.indexOf(ext) !== -1;
            }

            // Check URL for audio extensions
            var url = attachment.url || '';
            if (url && typeof url === 'string') {
                var urlPath = url.toLowerCase().split('?')[0].split('#')[0];
                var audioExts = ['.mp3', '.wav', '.ogg', '.flac', '.aac', '.m4a', '.wma'];
                for (var i = 0; i < audioExts.length; i++) {
                    if (urlPath.lastIndexOf(audioExts[i]) === urlPath.length - audioExts[i].length) {
                        return true;
                    }
                }
            }

            return false;
        },

        /**
         * Normalize upload response from server.
         *
         * @param {Object} data - Response data from upload endpoint.
         * @param {File} file - Original file object.
         * @return {Object} Normalized attachment record.
         */
        normaliseUploadResponse: function(data, file) {
            if (!data) {
                return null;
            }

            // If data already has expected structure, return as-is
            if (data.id || data.fileId || data.url) {
                return {
                    id: data.id || data.fileId || null,
                    fileId: data.fileId || data.id || null,
                    url: data.url || null,
                    name: data.name || data.fileName || data.file_name || (file && file.name) || '',
                    type: data.type || data.mimeType || data.mime_type || (file && file.type) || '',
                    size: data.size || data.fileSize || data.file_size || (file && file.size) || 0,
                };
            }

            // Handle alternative response structures
            if (data.data && (data.data.id || data.data.fileId || data.data.url)) {
                const inner = data.data;
                return {
                    id: inner.id || inner.fileId || null,
                    fileId: inner.fileId || inner.id || null,
                    url: inner.url || null,
                    name: inner.name || inner.fileName || inner.file_name || (file && file.name) || '',
                    type: inner.type || inner.mimeType || inner.mime_type || (file && file.type) || '',
                    size: inner.size || inner.fileSize || inner.file_size || (file && file.size) || 0,
                };
            }

            return null;
        },

        /**
         * Normalize attachment record from various sources.
         *
         * @param {Object} raw - Raw attachment data.
         * @return {Object} Normalized attachment record.
         */
        normaliseAttachmentRecord: function(raw) {
            if (!raw) {
                return null;
            }

            return {
                id: raw.id || raw.fileId || raw.attachment_id || null,
                fileId: raw.fileId || raw.id || raw.attachment_id || null,
                attachmentId: raw.attachment_id || raw.attachmentId || raw.id || raw.fileId || null,
                url: raw.url || raw.src || null,
                name: raw.name || raw.fileName || raw.file_name || raw.title || '',
                type: raw.type || raw.mimeType || raw.mime_type || '',
                size: raw.size || raw.fileSize || raw.file_size || 0,
            };
        },

        /**
         * Build attachment metadata for display.
         *
         * @param {Object} record - Attachment record.
         * @return {Object} Attachment metadata object.
         */
        buildAttachmentMeta: function(record) {
            if (!record) {
                return null;
            }

            const meta = {
                type: record.type || '',
                name: record.name || record.file_name || record.fileName || '',
            };

            if (record.size) {
                meta.size = record.size;
            }

            if (record.url) {
                meta.url = record.url;
            }

            if (record.id || record.fileId || record.attachment_id) {
                meta.attachment_id = record.id || record.fileId || record.attachment_id;
            }

            return meta;
        },

        /**
         * Build display attachment object for rendering.
         *
         * @param {Object} attachment - Attachment record.
         * @param {Object} state - Chat state object.
         * @return {Object|null} Display attachment object or null.
         */
        buildDisplayAttachment: function(attachment, state) {
            if (!attachment) {
                return null;
            }

            const display = {
                type: attachment.type || '',
                name: attachment.name || '',
            };

            // Add URL if available
            if (attachment.url) {
                display.url = attachment.url;
            } else if (attachment.fileId && state) {
                // Build URL from fileId if we have state
                display.url = this.buildFileDownloadUrl(state, attachment.fileId);
            }

            // Add size if available
            if (attachment.size) {
                display.size = attachment.size;
            }

            // Add attachment_id for API
            if (attachment.id || attachment.fileId || attachment.attachment_id) {
                display.attachment_id = attachment.id || attachment.fileId || attachment.attachment_id;
            }

            return display;
        },

        /**
         * Build file download URL from file ID.
         *
         * @param {Object} state - Chat state object.
         * @param {number|string} fileId - File ID.
         * @return {string} Download URL.
         */
        buildFileDownloadUrl: function(state, fileId) {
            if (!state || !state.config || !state.config.filesEndpoint) {
                return '';
            }

            if (!fileId) {
                return '';
            }

            const base = state.config.filesEndpoint;
            const separator = base.indexOf('?') === -1 ? '?' : '&';
            return base + separator + 'file_id=' + encodeURIComponent(fileId);
        },

        /**
         * Get attachment URL from record, building it if needed.
         *
         * @param {Object} record - Attachment record.
         * @param {Object} state - Chat state object.
         * @return {string} Attachment URL.
         */
        getAttachmentUrlFromRecord: function(record, state) {
            if (!record) {
                return '';
            }

            // Return existing URL if available
            if (record.url) {
                return record.url;
            }

            // Build URL from file ID
            const fileId = record.id || record.fileId || record.attachment_id;
            if (fileId && state) {
                return this.buildFileDownloadUrl(state, fileId);
            }

            return '';
        },

        /**
         * Strip display-only data from attachment segments.
         * Preserves attachment_id, real HTTP/HTTPS URLs, and API-required fields.
         *
         * @param {Object} segment - Content segment to process.
         * @return {Object} Processed segment.
         */
        stripSegmentDisplayData: function(segment) {
            if (!segment || segment.type !== 'attachment') {
                return segment;
            }

            // Start with minimal required fields
            const stripped = {
                type: 'attachment',
                attachment_id: segment.attachment_id
            };

            // Preserve real attachment URLs (HTTP/HTTPS) for the agentic workflow
            if (segment.url && this.isRealAttachmentUrl(segment.url)) {
                stripped.url = segment.url;
            }

            return stripped;
        },

        /**
         * Create content segment from attachment for message construction.
         *
         * @param {Object} attachment - Attachment object.
         * @return {Object} Content segment.
         */
        createSegmentFromAttachment: function(attachment) {
            if (!attachment) {
                return null;
            }

            const segment = {
                type: 'attachment',
            };

            // Add attachment_id if available (required for API)
            if (attachment.id || attachment.fileId || attachment.attachment_id) {
                segment.attachment_id = attachment.id || attachment.fileId || attachment.attachment_id;
            }

            // Add URL if it's a real HTTP/HTTPS URL
            if (attachment.url && this.isRealAttachmentUrl(attachment.url)) {
                segment.url = attachment.url;
            }

            // Add display metadata for UI (will be stripped before sending to API)
            if (attachment.type) {
                segment.mime_type = attachment.type;
            }

            if (attachment.name) {
                segment.file_name = attachment.name;
            }

            if (attachment.size) {
                segment.file_size = attachment.size;
            }

            return segment;
        },

        /**
         * Add attachment metadata to an existing content segment.
         *
         * @param {Object} segment - Content segment to enhance.
         * @param {Object} attachment - Attachment metadata.
         * @return {Object} Enhanced segment.
         */
        addAttachmentMetadataToSegment: function(segment, attachment) {
            if (!segment || !attachment) {
                return segment;
            }

            // Add attachment_id if available
            if (attachment.id || attachment.fileId || attachment.attachment_id) {
                segment.attachment_id = attachment.id || attachment.fileId || attachment.attachment_id;
            }

            // Add URL if available and real
            if (attachment.url && this.isRealAttachmentUrl(attachment.url)) {
                segment.url = attachment.url;
            }

            // Add display metadata
            if (attachment.type) {
                segment.mime_type = attachment.type;
            }

            if (attachment.name) {
                segment.file_name = attachment.name;
            }

            if (attachment.size) {
                segment.file_size = attachment.size;
            }

            return segment;
        },

        /**
         * Create Content-Disposition header for file upload.
         *
         * @param {string} filename - Filename to encode.
         * @return {string} Content-Disposition header value.
         */
        createContentDispositionHeader: function(filename) {
            if (!filename || typeof filename !== 'string') {
                return '';
            }

            // Encode filename for header (replace problematic characters)
            const safeName = filename.replace(/[^\w\s.-]/g, '_');
            return 'attachment; filename="' + safeName + '"';
        },
    };

    // Expose service globally
    window.wpMcpAiChatAttachments = wpMcpAiChatAttachments;

})();
