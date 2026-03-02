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

        /**
         * Get file type information (label and icon) for a given file extension.
         *
         * Maps 40+ common document types to human-readable labels and emoji icons.
         * Returns a default "File" entry for unrecognised extensions.
         *
         * @param {string} ext - File extension (without dot), e.g. "pdf", "docx".
         * @return {Object} Object with `label` (string) and `icon` (string) properties.
         */
        getFileTypeInfo: function(ext) {
            if (!ext || typeof ext !== 'string') {
                return { label: 'File', icon: '📄' };
            }

            const map = {
                // Documents
                pdf:  { label: 'PDF Document', icon: '📕' },
                doc:  { label: 'Word Document', icon: '📝' },
                docx: { label: 'Word Document', icon: '📝' },
                odt:  { label: 'OpenDocument Text', icon: '📝' },
                rtf:  { label: 'Rich Text', icon: '📝' },
                txt:  { label: 'Text File', icon: '📄' },
                md:   { label: 'Markdown', icon: '📄' },
                // Spreadsheets
                xls:  { label: 'Excel Spreadsheet', icon: '📊' },
                xlsx: { label: 'Excel Spreadsheet', icon: '📊' },
                ods:  { label: 'OpenDocument Spreadsheet', icon: '📊' },
                csv:  { label: 'CSV File', icon: '📊' },
                tsv:  { label: 'TSV File', icon: '📊' },
                // Presentations
                ppt:  { label: 'PowerPoint', icon: '📽️' },
                pptx: { label: 'PowerPoint', icon: '📽️' },
                odp:  { label: 'OpenDocument Presentation', icon: '📽️' },
                key:  { label: 'Keynote', icon: '📽️' },
                // Data / config
                json: { label: 'JSON', icon: '🔧' },
                xml:  { label: 'XML', icon: '🔧' },
                yaml: { label: 'YAML', icon: '🔧' },
                yml:  { label: 'YAML', icon: '🔧' },
                toml: { label: 'TOML', icon: '🔧' },
                ini:  { label: 'INI Config', icon: '🔧' },
                env:  { label: 'Environment', icon: '🔧' },
                // Archives
                zip:  { label: 'ZIP Archive', icon: '🗜️' },
                rar:  { label: 'RAR Archive', icon: '🗜️' },
                '7z': { label: '7-Zip Archive', icon: '🗜️' },
                tar:  { label: 'TAR Archive', icon: '🗜️' },
                gz:   { label: 'GZip Archive', icon: '🗜️' },
                bz2:  { label: 'BZip2 Archive', icon: '🗜️' },
                // Code
                js:   { label: 'JavaScript', icon: '💻' },
                ts:   { label: 'TypeScript', icon: '💻' },
                py:   { label: 'Python', icon: '💻' },
                php:  { label: 'PHP', icon: '💻' },
                rb:   { label: 'Ruby', icon: '💻' },
                java: { label: 'Java', icon: '💻' },
                c:    { label: 'C', icon: '💻' },
                cpp:  { label: 'C++', icon: '💻' },
                cs:   { label: 'C#', icon: '💻' },
                go:   { label: 'Go', icon: '💻' },
                rs:   { label: 'Rust', icon: '💻' },
                swift:{ label: 'Swift', icon: '💻' },
                html: { label: 'HTML', icon: '🌐' },
                css:  { label: 'CSS', icon: '🎨' },
                scss: { label: 'SCSS', icon: '🎨' },
                less: { label: 'LESS', icon: '🎨' },
                sql:  { label: 'SQL', icon: '🗃️' },
                sh:   { label: 'Shell Script', icon: '💻' },
                bat:  { label: 'Batch Script', icon: '💻' },
                // Images (for completeness)
                jpg:  { label: 'JPEG Image', icon: '🖼️' },
                jpeg: { label: 'JPEG Image', icon: '🖼️' },
                png:  { label: 'PNG Image', icon: '🖼️' },
                gif:  { label: 'GIF Image', icon: '🖼️' },
                svg:  { label: 'SVG Image', icon: '🖼️' },
                webp: { label: 'WebP Image', icon: '🖼️' },
                bmp:  { label: 'BMP Image', icon: '🖼️' },
                ico:  { label: 'Icon', icon: '🖼️' },
                // Audio
                mp3:  { label: 'MP3 Audio', icon: '🎵' },
                wav:  { label: 'WAV Audio', icon: '🎵' },
                ogg:  { label: 'OGG Audio', icon: '🎵' },
                flac: { label: 'FLAC Audio', icon: '🎵' },
                aac:  { label: 'AAC Audio', icon: '🎵' },
                m4a:  { label: 'M4A Audio', icon: '🎵' },
                wma:  { label: 'WMA Audio', icon: '🎵' },
                // Video
                mp4:  { label: 'MP4 Video', icon: '🎬' },
                webm: { label: 'WebM Video', icon: '🎬' },
                mov:  { label: 'MOV Video', icon: '🎬' },
                avi:  { label: 'AVI Video', icon: '🎬' },
                mkv:  { label: 'MKV Video', icon: '🎬' },
                flv:  { label: 'FLV Video', icon: '🎬' },
                wmv:  { label: 'WMV Video', icon: '🎬' },
                // Fonts
                ttf:  { label: 'TrueType Font', icon: '🔤' },
                otf:  { label: 'OpenType Font', icon: '🔤' },
                woff: { label: 'Web Font', icon: '🔤' },
                woff2:{ label: 'Web Font 2', icon: '🔤' },
                // eBook
                epub: { label: 'ePub', icon: '📚' },
                mobi: { label: 'Mobi eBook', icon: '📚' },
            };

            var key = ext.toLowerCase();
            return map[key] || { label: 'File', icon: '📄' };
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
    };

    // Expose service globally
    window.wpMcpAiChatAttachments = wpMcpAiChatAttachments;

})();
