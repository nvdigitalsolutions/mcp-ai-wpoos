(function () {
    'use strict';

    const defaultGlobalConfig = {
        restUrl: '',
        uploadEndpoint: '',
        filesEndpoint: '',
        toolsEndpoint: '',
        transcriptsEndpoint: '',
        nonce: '',
        historyPerPage: 20,
        asyncToolTimeout: 300000,
        strings: {},
    };

    const globalConfig = Object.assign({}, defaultGlobalConfig, window.wpMcpAiChat || {});

    const missingGlobalConfigKeys = ['restUrl', 'uploadEndpoint', 'filesEndpoint', 'toolsEndpoint', 'transcriptsEndpoint', 'nonce'].filter(
        function (key) {
            return !globalConfig[key];
        }
    );

    if (missingGlobalConfigKeys.length && window.console && console.warn) {
        console.warn(
            '[WP oOS] Chat configuration missing expected PHP-localized values. Check shortcode setup and plugin settings.',
            missingGlobalConfigKeys
        );
    }

    globalConfig.strings = globalConfig.strings || {};
    // Initialize instances object if it doesn't exist
    if (!window.wpMcpAiChatInstances) {
        window.wpMcpAiChatInstances = {};
    }

    // Storage service compatibility layer - use external service if available
    const storageService = window.wpMcpAiChatStorage || null;

    // Clipboard service compatibility layer - use external service if available
    const clipboardService = window.wpMcpAiChatClipboard || null;

    // Markdown service compatibility layer - use external service if available
    const markdownService = window.wpMcpAiChatMarkdown || null;

    // UI utilities service compatibility layer - use external service if available
    const uiUtilsService = window.wpMcpAiChatUIUtils || null;

    // Audio service compatibility layer - use external service if available
    const audioService = window.wpMcpAiChatAudio || null;

    // SSE service compatibility layer - use external service if available
    const sseService = window.wpMcpAiSSE || null;
    let objectUrlRegistry = [];

    // Audio-related constants - use from service if available
    const SPEECH_TOOL_NAME = 'generate_openai_speech';
    const SPEECH_BUTTON_CLASS = audioService && audioService.SPEECH_BUTTON_CLASS || 'wp-mcp-ai-speech-button';
    const SPEECH_ENABLED_CLASS = audioService && audioService.SPEECH_ENABLED_CLASS || 'wp-mcp-ai-speech-enabled';
    const SPEECH_ERROR_CLASS = 'wp-mcp-ai-speech-button--error';
    const SPEECH_PLAY_ICON = '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 4l9 6-9 6V4z"></path></svg>';
    const SPEECH_STOP_ICON = '<svg class="wp-mcp-ai-speech-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="6" y="5" width="8" height="10" rx="1"></rect></svg>';
    const SPEECH_SPINNER_ICON = '<span class="wp-mcp-ai-speech-spinner" aria-hidden="true"></span>';

    // Clipboard constants
    const COPY_BUTTON_CLASS = 'wp-mcp-ai-copy-button';
    const COPY_ENABLED_CLASS = 'wp-mcp-ai-copy-enabled';
    const COPY_ERROR_CLASS = 'wp-mcp-ai-copy-button--error';
    const COPY_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M6 5a2 2 0 012-2h7a2 2 0 012 2v9a2 2 0 01-2 2H8a2 2 0 01-2-2zm2-1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1V5a1 1 0 00-1-1z"></path><path d="M4 7a2 2 0 012-2v1a1 1 0 00-1 1v9a1 1 0 001 1h7a1 1 0 001-1h1a2 2 0 01-2 2H6a2 2 0 01-2-2z"></path></svg>';
    const COPY_SUCCESS_ICON = '<svg class="wp-mcp-ai-copy-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8.293 12.293l-2.147-2.146 1.414-1.414L9 10.586l3.44-3.44 1.414 1.415L9 13.414z"></path><path d="M6 3a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2zm0 1h8a1 1 0 011 1v10a1 1 0 01-1 1H6a1 1 0 01-1-1V5a1 1 0 011-1z"></path></svg>';

    // Delete button constants
    const DELETE_BUTTON_CLASS = 'wp-mcp-ai-delete-button';
    const DELETE_ENABLED_CLASS = 'wp-mcp-ai-delete-enabled';
    const DELETE_ICON = '<svg class="wp-mcp-ai-delete-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8.5 4h3a1.5 1.5 0 00-3 0zm-1 0a2.5 2.5 0 015 0h4a.5.5 0 010 1h-.441l-1.285 10.28A2 2 0 0112.788 17H7.212a2 2 0 01-1.986-1.72L3.941 5H3.5a.5.5 0 010-1h4zm.5 3a.5.5 0 00-1 0v6a.5.5 0 001 0V7zm3.5-.5a.5.5 0 00-.5.5v6a.5.5 0 001 0V7a.5.5 0 00-.5-.5z"></path></svg>';

    // Save button constants (for saving individual messages)
    const SAVE_BUTTON_CLASS = 'wp-mcp-ai-save-button';
    const SAVE_ENABLED_CLASS = 'wp-mcp-ai-save-enabled';
    const SAVE_STORAGE_KEY = 'wp_mcp_ai_chat_saved_messages';
    const SAVE_ICON = '<svg class="wp-mcp-ai-save-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M15.5 3H4.5A1.5 1.5 0 003 4.5v11A1.5 1.5 0 004.5 17h11a1.5 1.5 0 001.5-1.5v-11A1.5 1.5 0 0015.5 3zm-7 1h3v4h-3V4zm7.5 11.5a.5.5 0 01-.5.5h-11a.5.5 0 01-.5-.5v-11a.5.5 0 01.5-.5h2v4.5a.5.5 0 00.5.5h4a.5.5 0 00.5-.5V4h4a.5.5 0 01.5.5v11z"/><path d="M6 11h8v1H6zm0 2h8v1H6z"/></svg>';
    const SAVE_SUCCESS_ICON = '<svg class="wp-mcp-ai-save-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8.293 12.293l-2.147-2.146 1.414-1.414L9 10.586l3.44-3.44 1.414 1.415L9 13.414z"></path><path d="M6 3a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2zm0 1h8a1 1 0 011 1v10a1 1 0 01-1 1H6a1 1 0 01-1-1V5a1 1 0 011-1z"></path></svg>';

    // Transcription constants
    const TRANSCRIBE_TOOL_NAME = 'transcribe_openai_audio';
    const TRANSCRIBE_RECORDING_CLASS = audioService && audioService.TRANSCRIBE_RECORDING_CLASS || 'wp-mcp-ai-chat__transcribe--recording';
    const VOICE_CHAT_RECORDING_CLASS = audioService && audioService.VOICE_CHAT_RECORDING_CLASS || 'wp-mcp-ai-chat__voice-chat--recording';
    const VOICE_CHAT_PROCESSING_CLASS = audioService && audioService.VOICE_CHAT_PROCESSING_CLASS || 'wp-mcp-ai-chat__voice-chat--processing';
    const MAX_TRANSCRIBE_BYTES = audioService && audioService.MAX_TRANSCRIBE_BYTES || 26214400;

    // Other constants
    const TOOL_SHORTCUT_CONTAINER_CLASS = 'wp-mcp-ai-chat__tool-shortcuts';
    const TOOL_SHORTCUT_BUTTON_CLASS = 'wp-mcp-ai-chat__tool-shortcut';
    const STORAGE_KEY_PREFIX = 'wp_mcp_ai_chat_';
    const STORAGE_EXPIRY_MS = 24 * 60 * 60 * 1000; // 24 hours
    const CRAWL4AI_MAX_CONTENT_LENGTH = 5000; // Maximum characters to display per crawled page
    const STREAMING_STATUS_PREVIEW_LENGTH = 100; // Maximum characters to show in status preview during streaming
    
    // Async tool timeout constants (must match PHP constants in WP_MCP_AI_Shortcode)
    const ASYNC_TOOL_TIMEOUT_DEFAULT_MS = 300000; // 5 minutes default
    const ASYNC_TOOL_TIMEOUT_MIN_MS = 60000; // 1 minute minimum
    
    // Performance optimization settings - can be disabled for debugging
    // Set window.wpMcpAiChatDebugMode = true to disable optimizations
    const DEBUG_MODE = window.wpMcpAiChatDebugMode === true;
    const OPTIMIZATIONS_ENABLED = !DEBUG_MODE;

    /**
     * Get localStorage key for a specific assistant.
     * Uses storage service if available, otherwise uses internal implementation.
     * @param {string} assistantId - The assistant ID.
     * @return {string} The storage key.
     */
    function getStorageKey(assistantId) {
        if (storageService && storageService.getStorageKey) {
            return storageService.getStorageKey(assistantId);
        }
        return STORAGE_KEY_PREFIX + assistantId;
    }

    /**
     * Sanitize a session key to remove whitespace and invalid characters.
     * Uses storage service if available, otherwise uses internal implementation.
     * @param {string} sessionKey - The session key to sanitize.
     * @return {string} The sanitized session key.
     */
    function sanitizeSessionKey(sessionKey) {
        if (storageService && storageService.sanitizeSessionKey) {
            return storageService.sanitizeSessionKey(sessionKey);
        }
        // Fallback implementation
        if (!sessionKey || typeof sessionKey !== 'string') {
            return '';
        }
        return sessionKey.replace(/[^a-zA-Z0-9_-]/g, '');
    }

    // Debounced storage saves to reduce write frequency
    const storageSaveTimers = {};
    const STORAGE_SAVE_DEBOUNCE_MS = 300;

    // Message bundling to group rapid user inputs
    const MESSAGE_BUNDLE_DELAY_MS = 800; // Wait 800ms before sending bundled messages

    /**
     * Streaming diagnostics logger utility (Separation of Concerns).
     * Centralizes all streaming-related logging to keep business logic clean.
     * Follows null-safe patterns to prevent secondary errors during error reporting.
     */
    const streamingLogger = (function() {
        const LOG_PREFIX = '[WP oOS]';
        
        /**
         * Safely get error type from an error object.
         * @param {*} error - Error object or value
         * @return {string} Error type name or 'Unknown'
         */
        function getErrorType(error) {
            return error && error.constructor && error.constructor.name ? error.constructor.name : 'Unknown';
        }
        
        /**
         * Safely get error message from an error object.
         * @param {*} error - Error object or value
         * @return {string} Error message or 'Unknown'
         */
        function getErrorMessage(error) {
            return error ? (error.message || 'Unknown') : 'Unknown';
        }
        
        return {
            /**
             * Log streaming request initiation.
             * @param {Object} context - Request context
             */
            logRequestStart: function(context) {
                if (window.console && console.log) {
                    console.log(LOG_PREFIX + ' Starting streaming request:', {
                        endpoint: context.endpoint,
                        assistantId: context.assistantId,
                        messageCount: context.messageCount,
                        streamEnabled: context.streamEnabled,
                        hasSessionKey: context.hasSessionKey
                    });
                }
            },
            
            /**
             * Log HTTP response reception.
             * @param {Response} response - Fetch Response object
             */
            logResponseReceived: function(response) {
                if (window.console && console.log) {
                    console.log(LOG_PREFIX + ' Streaming response received:', {
                        status: response.status,
                        statusText: response.statusText,
                        ok: response.ok,
                        headers: {
                            'content-type': response.headers.get('content-type') || 'not set',
                            'cache-control': response.headers.get('cache-control'),
                            'connection': response.headers.get('connection')
                        }
                    });
                }
            },
            
            /**
             * Log HTTP error response.
             * @param {Response} response - Fetch Response object
             */
            logHttpError: function(response) {
                if (window.console && console.error) {
                    console.error(LOG_PREFIX + ' HTTP error response:', {
                        status: response.status,
                        statusText: response.statusText,
                        url: response.url
                    });
                }
            },
            
            /**
             * Log fetch failure with detailed context.
             * @param {*} error - Error object
             * @param {Object} context - Request context
             */
            logFetchFailure: function(error, context) {
                if (window.console && console.error) {
                    console.error(LOG_PREFIX + ' Streaming request failed:', {
                        errorType: getErrorType(error),
                        errorMessage: getErrorMessage(error),
                        errorStatus: error && error.status ? error.status : 'N/A',
                        errorStatusText: error && error.statusText ? error.statusText : 'N/A',
                        endpoint: context.endpoint,
                        assistantId: context.assistantId,
                        hasResponse: error && typeof error.json === 'function',
                        streamCompleted: context.streamCompleted
                    });
                    
                    // Extract response text if available (async, non-blocking)
                    // This runs asynchronously and doesn't block the error flow
                    if (error && typeof error.text === 'function') {
                        error.text().then(function(responseText) {
                            console.error(LOG_PREFIX + ' Server response text:', responseText);
                        }).catch(function(extractError) {
                            // Silently fail - response text extraction is best-effort
                            console.error(LOG_PREFIX + ' Could not extract response text:', getErrorMessage(extractError));
                        });
                    }
                }
            },
            
            /**
             * Log SSE stream processing start.
             */
            logStreamStart: function() {
                if (window.console && console.log) {
                    console.log(LOG_PREFIX + ' Starting SSE stream processing');
                }
            },
            
            /**
             * Log SSE stream completion.
             * @param {Object} result - Stream completion result
             */
            logStreamComplete: function(result) {
                if (window.console && console.log) {
                    console.log(LOG_PREFIX + ' SSE stream completed:', {
                        totalContentLength: result.contentLength,
                        contentSample: result.contentSample
                    });
                }
            },
            
            /**
             * Log SSE parsing error.
             * @param {*} parseError - Parse error object
             * @param {Object} context - Parsing context
             */
            logParseError: function(parseError, context) {
                if (window.console && console.warn) {
                    console.warn(LOG_PREFIX + ' Failed to parse SSE event data:', {
                        eventType: context.eventType || '(none)',
                        eventData: context.eventData,
                        error: parseError ? (parseError.message || 'No error message') : 'Unknown error'
                    });
                }
            },
            
            /**
             * Log stream reading error.
             * @param {*} error - Read error object
             */
            logStreamReadError: function(error) {
                if (window.console && console.error) {
                    console.error(LOG_PREFIX + ' Error reading SSE stream chunk:', {
                        error: getErrorMessage(error),
                        errorType: getErrorType(error)
                    });
                }
            },
            
            /**
             * Log top-level stream processing error.
             * @param {*} error - Stream error object
             */
            logStreamError: function(error) {
                if (window.console && console.error) {
                    console.error(LOG_PREFIX + ' SSE stream processing error:', {
                        error: getErrorMessage(error),
                        errorType: getErrorType(error)
                    });
                }
            }
        };
    })();

    /**
     * Scroll to bottom batching utility to prevent forced reflows.
     * Uses requestAnimationFrame to batch multiple scroll requests.
     */
    const scrollBatcher = (function() {
        const pendingScrolls = new Map();
        let rafScheduled = false;

        function performScrolls() {
            rafScheduled = false;
            pendingScrolls.forEach(function(scrollTo, element) {
                if (element && element.parentNode) {
                    element.scrollTop = scrollTo;
                }
            });
            pendingScrolls.clear();
        }

        return {
            /**
             * Schedule a scroll to bottom operation.
             * @param {Element} element - The element to scroll
             */
            scrollToBottom: function(element) {
                if (!element || !OPTIMIZATIONS_ENABLED) {
                    // Fallback to immediate scroll if optimizations disabled
                    if (element) {
                        element.scrollTop = element.scrollHeight;
                    }
                    return;
                }

                // Store the element for batched scrolling
                // We use 'bottom' as a marker, actual scrollHeight will be read in RAF
                pendingScrolls.set(element, 'bottom');

                if (!rafScheduled) {
                    rafScheduled = true;
                    requestAnimationFrame(function() {
                        // Read all scroll heights first (batch reads)
                        const scrollOperations = new Map();
                        pendingScrolls.forEach(function(_, element) {
                            if (element && element.parentNode) {
                                scrollOperations.set(element, element.scrollHeight);
                            }
                        });
                        
                        // Then perform all writes (batch writes)
                        scrollOperations.forEach(function(scrollHeight, element) {
                            element.scrollTop = scrollHeight;
                        });
                        
                        pendingScrolls.clear();
                        rafScheduled = false;
                    });
                }
            }
        };
    })();

    /**
     * Quota monitor cache and async calculation.
     * Prevents blocking the main thread with heavy localStorage iteration.
     */
    const quotaMonitorCache = {
        lastCalculated: 0,
        cachedQuota: { used: 0, total: 0, percentage: 0, available: false },
        calculating: false,
        CACHE_DURATION: 30000, // Cache for 30 seconds
        
        /**
         * Get cached quota or trigger async calculation.
         * 
         * @param {Function} callback - Called with quota data when available
         */
        getQuota: function(callback) {
            const now = Date.now();
            const cacheValid = (now - this.lastCalculated) < this.CACHE_DURATION;
            
            // Return cached data if still valid
            if (cacheValid && this.cachedQuota.available) {
                if (callback) {
                    callback(this.cachedQuota);
                }
                return;
            }
            
            // Don't trigger multiple calculations
            if (this.calculating) {
                if (callback) {
                    // Return stale cache while calculating
                    callback(this.cachedQuota);
                }
                return;
            }
            
            this.calculating = true;
            
            // Use requestIdleCallback for the heavy calculation
            const performCalculation = function() {
                try {
                    const quota = this.calculateQuotaSync();
                    this.cachedQuota = quota;
                    this.lastCalculated = Date.now();
                    this.calculating = false;
                    
                    if (callback) {
                        callback(quota);
                    }
                } catch (error) {
                    this.calculating = false;
                    if (window.console && console.error) {
                        console.error('Error calculating localStorage quota:', error);
                    }
                }
            }.bind(this);
            
            // Use requestIdleCallback when available, otherwise use setTimeout
            if (OPTIMIZATIONS_ENABLED && window.requestIdleCallback) {
                window.requestIdleCallback(performCalculation, { timeout: 2000 });
            } else {
                setTimeout(performCalculation, 0);
            }
        },
        
        /**
         * Synchronous quota calculation (called in idle callback).
         * 
         * @return {Object} Quota data object
         */
        calculateQuotaSync: function() {
            if (!window.localStorage) {
                return { used: 0, total: 0, percentage: 0, available: false };
            }

            let totalSize = 0;
            let wpMcpAiSize = 0;

            // Calculate total localStorage usage
            for (let i = 0; i < window.localStorage.length; i++) {
                const key = window.localStorage.key(i);
                if (!key) {
                    continue;
                }

                const value = window.localStorage.getItem(key);
                if (value) {
                    const itemSize = key.length + value.length;
                    totalSize += itemSize;

                    if (key.startsWith(STORAGE_KEY_PREFIX)) {
                        wpMcpAiSize += itemSize;
                    }
                }
            }

            // Estimate total quota (typically 5-10MB, we'll use conservative estimate)
            const estimatedQuota = 5 * 1024 * 1024; // 5MB in bytes
            const percentage = (totalSize / estimatedQuota) * 100;

            return {
                used: totalSize,
                wpMcpAiUsed: wpMcpAiSize,
                total: estimatedQuota,
                percentage: Math.min(percentage, 100),
                available: true,
                formattedUsed: formatBytes(totalSize),
                formattedWpMcpAiUsed: formatBytes(wpMcpAiSize),
                formattedTotal: formatBytes(estimatedQuota)
            };
        }
    };

    /**
     * DOM update batcher to prevent setTimeout violations.
     * Uses UI utilities service if available, otherwise uses internal implementation.
     */
    const domUpdateBatcher = (uiUtilsService && uiUtilsService.domUpdateBatcher) || (function() {
        let pendingUpdates = [];
        let rafScheduled = false;

        function performUpdates() {
            rafScheduled = false;
            const updates = pendingUpdates.slice();
            pendingUpdates = [];

            // Execute all updates in a single animation frame
            updates.forEach(function(updateFn) {
                try {
                    updateFn();
                } catch (error) {
                    if (window.console && console.error) {
                        console.error('Error in batched DOM update:', error);
                    }
                }
            });
        }

        return {
            /**
             * Schedule a DOM update to be executed in the next animation frame.
             * @param {Function} updateFn - Function to execute
             */
            schedule: function(updateFn) {
                if (!OPTIMIZATIONS_ENABLED || typeof updateFn !== 'function') {
                    // Execute immediately if optimizations disabled
                    if (typeof updateFn === 'function') {
                        updateFn();
                    }
                    return;
                }

                pendingUpdates.push(updateFn);

                if (!rafScheduled) {
                    rafScheduled = true;
                    requestAnimationFrame(performUpdates);
                }
            }
        };
    })();

    /**
     * Get localStorage usage statistics (async).
     * Uses caching and requestIdleCallback to avoid blocking the main thread.
     * Uses storage service if available, otherwise uses internal implementation.
     * 
     * @param {Function} callback - Called with quota data
     */
    function getLocalStorageQuota(callback) {
        if (storageService && storageService.getLocalStorageQuota) {
            return storageService.getLocalStorageQuota(callback);
        }
        quotaMonitorCache.getQuota(callback);
    }

    /**
     * Format bytes to human-readable string.
     * Uses UI utilities service if available, otherwise uses internal implementation.
     * 
     * @param {number} bytes - Number of bytes
     * @return {string} Formatted string (e.g., "1.5 KB", "2.3 MB")
     */
    function formatBytes(bytes) {
        if (uiUtilsService && uiUtilsService.formatBytes) {
            return uiUtilsService.formatBytes(bytes);
        }
        
        if (storageService && storageService.formatBytes) {
            return storageService.formatBytes(bytes);
        }

        if (bytes === 0) {
            return '0 Bytes';
        }

        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    /**
     * Extract error message from error value.
     * Handles both string errors and error objects with a 'message' property.
     * 
     * @param {*} error - Error value (string, object, or other)
     * @param {string} defaultMessage - Default message if error is empty or invalid
     * @return {string} Extracted error message
     */
    function extractErrorMessage(error, defaultMessage) {
        const fallback = defaultMessage || 'Unknown error';
        
        if (!error) {
            return fallback;
        }
        
        if (typeof error === 'string') {
            return error;
        }
        
        // Check for object (but not null, since typeof null === 'object')
        if (error !== null && typeof error === 'object' && error.message) {
            return error.message;
        }
        
        return fallback;
    }

    /**
     * Export conversation to various formats.
     * Uses storage service if available, otherwise uses internal implementation.
     * 
     * @param {Object} state - Chat state object
     * @param {string} format - Export format ('json', 'markdown', 'text')
     * @return {Object} Export result with content and filename
     */
    function exportConversation(state, format) {
        if (storageService && storageService.exportConversation) {
            return storageService.exportConversation(state, format);
        }

        if (!state || !state.conversation || !Array.isArray(state.conversation)) {
            return { success: false, error: 'No conversation to export' };
        }

        const conversation = state.conversation;
        const assistantId = state.config ? state.config.assistantId : 'unknown';
        const sessionKey = state.config ? state.config.sessionKey : '';
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
        
        let content = '';
        let filename = '';
        let mimeType = 'text/plain';

        try {
            if (format === 'json') {
                const exportData = {
                    assistant_id: assistantId,
                    session_key: sessionKey,
                    exported_at: new Date().toISOString(),
                    messages: conversation
                };
                content = JSON.stringify(exportData, null, 2);
                filename = 'chat-' + assistantId + '-' + timestamp + '.json';
                mimeType = 'application/json';
            } else if (format === 'markdown') {
                const lines = ['# Chat Conversation'];
                lines.push('');
                lines.push('**Assistant ID:** ' + assistantId);
                if (sessionKey) {
                    lines.push('**Session Key:** ' + sessionKey);
                }
                lines.push('**Exported:** ' + new Date().toLocaleString());
                lines.push('');
                lines.push('---');
                lines.push('');

                conversation.forEach(function(message) {
                    const role = message.role || 'unknown';
                    const content = message.content || '';
                    
                    lines.push('## ' + role.charAt(0).toUpperCase() + role.slice(1));
                    lines.push('');
                    lines.push(content);
                    lines.push('');
                });

                content = lines.join('\n');
                filename = 'chat-' + assistantId + '-' + timestamp + '.md';
                mimeType = 'text/markdown';
            } else {
                // Plain text format
                const lines = ['Chat Conversation'];
                lines.push('');
                lines.push('Assistant ID: ' + assistantId);
                if (sessionKey) {
                    lines.push('Session Key: ' + sessionKey);
                }
                lines.push('Exported: ' + new Date().toLocaleString());
                lines.push('');
                lines.push('----------------------------------------');
                lines.push('');

                conversation.forEach(function(message) {
                    const role = message.role || 'unknown';
                    const content = message.content || '';
                    
                    lines.push(role.toUpperCase() + ':');
                    lines.push(content);
                    lines.push('');
                });

                content = lines.join('\n');
                filename = 'chat-' + assistantId + '-' + timestamp + '.txt';
                mimeType = 'text/plain';
            }

            return {
                success: true,
                content: content,
                filename: filename,
                mimeType: mimeType
            };
        } catch (error) {
            if (window.console && console.error) {
                console.error('Error exporting conversation:', error);
            }
            return { success: false, error: error.message || 'Export failed' };
        }
    }

    /**
     * Download exported conversation as a file.
     * 
     * @param {string} content - File content
     * @param {string} filename - File name
     * @param {string} mimeType - MIME type
     */
    function downloadFile(content, filename, mimeType) {
        try {
            const blob = new Blob([content], { type: mimeType });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.style.display = 'none';
            
            document.body.appendChild(a);
            a.click();
            
            setTimeout(function() {
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }, 100);
        } catch (error) {
            if (window.console && console.error) {
                console.error('Error downloading file:', error);
            }
        }
    }

    /**
     * Save conversation to localStorage with quota management.
     * Includes automatic cleanup of old conversations if quota is exceeded.
     * Uses storage service if available, otherwise uses internal implementation.
     * 
     * @param {Object} state - Chat state object
     * @param {Object} options - Optional settings
     * @return {Object} Result object with success status
     */
    function saveConversationToStorage(state, options) {
        if (storageService && storageService.saveConversationToStorage) {
            return storageService.saveConversationToStorage(state, options);
        }

        if (!state || !state.config || !state.config.assistantId) {
            return { success: false, skipped: true };
        }

        if (!window.localStorage) {
            return { success: false, error: 'localStorage not available' };
        }

        const assistantId = state.config.assistantId;
        const opts = options || {};
        const forceImmediate = opts.immediate === true;
        
        /**
         * Internal function to perform the actual save.
         * 
         * @return {Object} Result with success status
         */
        function performSave() {
            try {
                const storageKey = getStorageKey(assistantId);
                const data = {
                    conversation: state.conversation || [],
                    sessionKey: state.config.sessionKey || '',
                    timestamp: Date.now(),
                    assistantId: assistantId
                };
                
                // Log save attempt
                if (window.console && console.log) {
                    console.log('[WP oOS] Saving conversation to localStorage:', {
                        assistant_id: assistantId,
                        session_key: state.config.sessionKey || '',
                        message_count: (state.conversation || []).length,
                        storage_key: storageKey
                    });
                }
                
                window.localStorage.setItem(storageKey, JSON.stringify(data));
                
                // Log successful save
                if (window.console && console.log) {
                    console.log('[WP oOS] Conversation saved successfully to localStorage');
                }
                
                return { success: true };
            } catch (error) {
                // Check if it's a quota exceeded error
                const isQuotaError = error.name === 'QuotaExceededError' || 
                                   error.code === 22 || // Legacy browsers
                                   error.code === 1014; // Firefox
                
                if (isQuotaError) {
                    // Try to free up space by removing old conversations
                    const cleaned = cleanupOldStorageEntries();
                    
                    if (cleaned > 0) {
                        // Retry save after cleanup
                        try {
                            const storageKey = getStorageKey(assistantId);
                            const data = {
                                conversation: state.conversation || [],
                                sessionKey: state.config.sessionKey || '',
                                timestamp: Date.now(),
                                assistantId: assistantId
                            };
                            
                            // Log retry attempt
                            if (window.console && console.log) {
                                console.log('[WP oOS] Retrying localStorage save after cleanup (cleaned ' + cleaned + ' entries)');
                            }
                            
                            window.localStorage.setItem(storageKey, JSON.stringify(data));
                            
                            // Log successful retry
                            if (window.console && console.log) {
                                console.log('[WP oOS] Conversation saved successfully to localStorage after cleanup');
                            }
                            
                            return { success: true, cleaned: cleaned };
                        } catch (retryError) {
                            if (window.console && console.warn) {
                                console.warn('[WP oOS] Failed to save conversation to localStorage even after cleanup:', retryError);
                            }
                            return { success: false, error: 'localStorage quota exceeded', cleaned: cleaned };
                        }
                    }
                    
                    return { success: false, error: 'localStorage quota exceeded' };
                }
                
                // Other errors - log but don't interrupt user experience
                if (window.console && console.warn) {
                    console.warn('[WP oOS] Error saving conversation to localStorage:', error);
                }
                
                return { success: false, error: error.message || 'localStorage error' };
            }
        }
        
        // Skip debouncing in debug mode or if immediate save requested
        if (!OPTIMIZATIONS_ENABLED || forceImmediate) {
            return performSave();
        }

        // Clear existing timer for this assistant
        if (storageSaveTimers[assistantId]) {
            clearTimeout(storageSaveTimers[assistantId]);
        }

        // Debounce the save operation to reduce localStorage writes
        storageSaveTimers[assistantId] = setTimeout(function() {
            performSave();
            delete storageSaveTimers[assistantId];
        }, STORAGE_SAVE_DEBOUNCE_MS);
        
        return { success: true, debounced: true };
    }
    
    /**
     * Clean up old localStorage entries to free up space.
     * Removes entries older than STORAGE_EXPIRY_MS.
     * Uses storage service if available, otherwise uses internal implementation.
     * 
     * @return {number} Number of entries cleaned up
     */
    function cleanupOldStorageEntries() {
        if (storageService && storageService.cleanupOldStorageEntries) {
            return storageService.cleanupOldStorageEntries();
        }

        if (!window.localStorage) {
            return 0;
        }
        
        let cleaned = 0;
        const now = Date.now();
        const keysToRemove = [];
        
        try {
            // Find all wp_mcp_ai_chat_ keys
            for (let i = 0; i < window.localStorage.length; i++) {
                const key = window.localStorage.key(i);
                
                if (!key || !key.startsWith(STORAGE_KEY_PREFIX)) {
                    continue;
                }
                
                try {
                    const stored = window.localStorage.getItem(key);
                    if (!stored) {
                        keysToRemove.push(key);
                        continue;
                    }
                    
                    const data = JSON.parse(stored);
                    
                    // Remove if expired
                    if (data && data.timestamp && (now - data.timestamp) > STORAGE_EXPIRY_MS) {
                        keysToRemove.push(key);
                    }
                } catch (error) {
                    // Invalid JSON - mark for removal
                    keysToRemove.push(key);
                }
            }
            
            // Remove identified keys
            keysToRemove.forEach(function(key) {
                try {
                    window.localStorage.removeItem(key);
                    cleaned++;
                } catch (error) {
                    // Ignore errors during cleanup
                }
            });
            
            if (cleaned > 0 && window.console && console.info) {
                console.info('Cleaned up ' + cleaned + ' old conversation(s) from localStorage');
            }
        } catch (error) {
            if (window.console && console.warn) {
                console.warn('Error during localStorage cleanup:', error);
            }
        }
        
        return cleaned;
    }

    /**
     * Load conversation from localStorage.
     * Uses storage service if available, otherwise uses internal implementation.
     * 
     * @param {Object} state - Chat state object
     * @return {Object|null} Loaded conversation data or null
     */
    function loadConversationFromStorage(state) {
        if (storageService && storageService.loadConversationFromStorage) {
            return storageService.loadConversationFromStorage(state);
        }

        if (!state || !state.config || !state.config.assistantId) {
            return null;
        }

        if (!window.localStorage) {
            return null;
        }

        try {
            const storageKey = getStorageKey(state.config.assistantId);
            
            // Log load attempt
            if (window.console && console.log) {
                console.log('[WP oOS] Loading conversation from localStorage:', {
                    assistant_id: state.config.assistantId,
                    storage_key: storageKey
                });
            }
            
            const stored = window.localStorage.getItem(storageKey);

            if (!stored) {
                // Log when no data found
                if (window.console && console.log) {
                    console.log('[WP oOS] No conversation found in localStorage');
                }
                return null;
            }

            const data = JSON.parse(stored);

            if (!data || typeof data !== 'object') {
                if (window.console && console.warn) {
                    console.warn('[WP oOS] Invalid conversation data in localStorage');
                }
                return null;
            }

            // Check if data is expired
            const age = Date.now() - (data.timestamp || 0);
            if (age > STORAGE_EXPIRY_MS) {
                if (window.console && console.log) {
                    console.log('[WP oOS] Conversation expired in localStorage (age: ' + Math.floor(age / 1000 / 60) + ' minutes)');
                }
                window.localStorage.removeItem(storageKey);
                return null;
            }

            // Verify it's for the same assistant
            if (data.assistantId !== state.config.assistantId) {
                if (window.console && console.warn) {
                    console.warn('[WP oOS] Assistant ID mismatch in localStorage data');
                }
                return null;
            }

            // Log successful load
            if (window.console && console.log) {
                console.log('[WP oOS] Conversation loaded successfully from localStorage:', {
                    session_key: data.sessionKey || '',
                    message_count: Array.isArray(data.conversation) ? data.conversation.length : 0,
                    age_minutes: Math.floor(age / 1000 / 60)
                });
            }

            return {
                conversation: Array.isArray(data.conversation) ? data.conversation : [],
                sessionKey: data.sessionKey || '',
                assistantId: data.assistantId || state.config.assistantId
            };
        } catch (error) {
            // Log parse errors
            if (window.console && console.warn) {
                console.warn('[WP oOS] Error loading conversation from localStorage:', error);
            }
            // Return null if parsing fails
            return null;
        }
    }

    /**
     * Clear conversation from localStorage.
     * Uses storage service if available, otherwise uses internal implementation.
     * 
     * @param {Object} state - Chat state object
     */
    function clearConversationFromStorage(state) {
        if (storageService && storageService.clearConversationFromStorage) {
            return storageService.clearConversationFromStorage(state);
        }

        if (!state || !state.config || !state.config.assistantId) {
            return;
        }

        if (!window.localStorage) {
            return;
        }

        try {
            const storageKey = getStorageKey(state.config.assistantId);
            
            // Log delete attempt
            if (window.console && console.log) {
                console.log('[WP oOS] Clearing conversation from localStorage:', {
                    assistant_id: state.config.assistantId,
                    session_key: state.config.sessionKey || '',
                    storage_key: storageKey
                });
            }
            
            window.localStorage.removeItem(storageKey);
            
            // Log successful delete
            if (window.console && console.log) {
                console.log('[WP oOS] Conversation cleared successfully from localStorage');
            }
        } catch (error) {
            // Log error
            if (window.console && console.warn) {
                console.warn('[WP oOS] Error clearing conversation from localStorage:', error);
            }
        }
    }

    /**
     * Extract display metadata from a rendered message element.
     * This captures bubble type, attachments, display text, and usage/cost badges for persistence.
     * 
     * Industry standard: Always persist display metadata for all messages to ensure
     * consistent restoration. This follows the pattern used for JSON bubbles.
     * 
     * Note: System messages are NOT persisted (they are transient UI feedback).
     * 
     * @param {HTMLElement} messageElement - The rendered message element
     * @param {Object} displayPayload - The original display payload used to render
     * @param {Object} options - Optional additional data to persist
     * @param {Object} options.usage - Token usage data (prompt_tokens, completion_tokens, total_tokens)
     * @param {Object} options.cost - Cost data (total, currency)
     * @param {Array<string>} options.capabilityFlags - Capability flags (e.g., 'vision', 'code_interpreter')
     * @return {Object|null} Display metadata object or null if no metadata to preserve
     */
    function extractDisplayMetadata(messageElement, displayPayload, options) {
        if (!messageElement) {
            return null;
        }

        // Message element is now the bubble itself (merged structure)
        if (!messageElement.classList.contains('wp-mcp-ai-chat__bubble')) {
            return null;
        }

        // System messages should NOT be persisted - they are transient UI feedback
        if (messageElement.classList.contains('wp-mcp-ai-chat__bubble--system')) {
            return null;
        }

        const metadata = {};
        let hasMetadata = false;

        // Extract bubble type from data attribute if set (json, truncated, etc.)
        // For standard assistant/user messages, we set a default bubble type
        // to ensure consistent persistence and restoration
        if (messageElement.dataset.bubbleType) {
            metadata.bubbleType = messageElement.dataset.bubbleType;
            hasMetadata = true;
        } else {
            // Determine default bubble type from CSS classes for standard messages
            // This ensures assistant bubbles persist the same way as JSON bubbles
            if (messageElement.classList.contains('wp-mcp-ai-chat__bubble--assistant')) {
                metadata.bubbleType = 'assistant';
                hasMetadata = true;
            } else if (messageElement.classList.contains('wp-mcp-ai-chat__bubble--user')) {
                metadata.bubbleType = 'user';
                hasMetadata = true;
            } else if (messageElement.classList.contains('wp-mcp-ai-chat__bubble--tool')) {
                metadata.bubbleType = 'tool';
                hasMetadata = true;
            }
        }

        // Extract display message if provided
        if (displayPayload && displayPayload.message) {
            metadata.message = displayPayload.message;
            hasMetadata = true;
        }

        // Extract display text if provided
        if (displayPayload && displayPayload.text) {
            metadata.text = displayPayload.text;
            hasMetadata = true;
        }

        // Extract attachments if provided
        if (displayPayload && Array.isArray(displayPayload.attachments) && displayPayload.attachments.length > 0) {
            metadata.attachments = displayPayload.attachments;
            hasMetadata = true;
        }

        // Extract chart HTML for visualization persistence (Chart.js tools)
        if (displayPayload && displayPayload.chartHtml) {
            metadata.chartHtml = displayPayload.chartHtml;
            metadata.chartWidth = displayPayload.chartWidth || 800;
            metadata.chartHeight = displayPayload.chartHeight || 400;
            hasMetadata = true;
        }

        // Extract usage data (token counts) for badge persistence
        if (options && options.usage) {
            metadata.usage = options.usage;
            hasMetadata = true;
        }

        // Extract cost data for badge persistence
        if (options && options.cost) {
            metadata.cost = options.cost;
            hasMetadata = true;
        }

        // Extract capability flags for badge persistence
        if (options && options.capabilityFlags) {
            metadata.capabilityFlags = options.capabilityFlags;
            hasMetadata = true;
        }

        // Extract tool_calls for agentic workflow persistence
        // tool_calls are passed via displayPayload for messages that invoke tools
        if (displayPayload && displayPayload.tool_calls && Array.isArray(displayPayload.tool_calls)) {
            metadata.tool_calls = displayPayload.tool_calls;
            hasMetadata = true;
        }

        // Extract speechText and copyText from data attributes for button persistence
        // These contain the markdown text used for speech synthesis and clipboard copy
        if (messageElement.dataset && messageElement.dataset.speechText) {
            metadata.speechText = messageElement.dataset.speechText;
            hasMetadata = true;
        }
        
        if (messageElement.dataset && messageElement.dataset.copyText) {
            metadata.copyText = messageElement.dataset.copyText;
            hasMetadata = true;
        }

        return hasMetadata ? metadata : null;
    }

    /**
     * Create a conversation message object with display metadata.
     * This ensures proper persistence and restoration of message display.
     * 
     * @param {string} role - Message role (user, assistant, tool, system)
     * @param {*} content - Message content (string or structured content)
     * @param {Object} displayMetadata - Display metadata from extractDisplayMetadata
     * @param {Object} additionalFields - Additional fields to include (tool_calls, etc.)
     * @return {Object} Conversation message object
     */
    function createConversationMessage(role, content, displayMetadata, additionalFields) {
        const message = {
            role: role,
            content: content
        };

        // Add display metadata if present
        if (displayMetadata) {
            message.display = displayMetadata;
        }

        // Add any additional fields (tool_calls, etc.)
        if (additionalFields && typeof additionalFields === 'object') {
            Object.keys(additionalFields).forEach(function(key) {
                if (!message.hasOwnProperty(key)) {
                    message[key] = additionalFields[key];
                }
            });
        }

        return message;
    }

    /**
     * Save the current conversation to CCT via the REST API.
     * This is called before clearing a conversation to ensure messages are not lost.
     * 
     * @param {Object} state - Chat state object
     * @returns {Promise<{success: boolean, error?: string}>} Promise that resolves with save status
     */
    /**
     * Check if a URL is a real attachment URL (HTTP/HTTPS) vs display-only (blob:/data:).
     * Real attachment URLs should be preserved for the API, while display-only URLs should be stripped.
     * Uses URL constructor for robust validation.
     * 
     * @param {string} url - URL to check
     * @return {boolean} True if URL is a valid HTTP/HTTPS URL, false for blob:/data: or invalid URLs
     */
    function isRealAttachmentUrl(url) {
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
    }

    /**
     * Strip display-only data from attachment segments.
     * Removes blob:/data: URLs used only for display.
     * Preserves attachment_id, real HTTP/HTTPS URLs, and API-required fields following OpenAI image tool pattern.
     * 
     * @param {Object} segment - Attachment segment object
     * @return {Object} Cleaned segment object with API-compatible fields
     */
    function stripSegmentDisplayData(segment) {
        if (!segment || typeof segment !== 'object') {
            return segment;
        }

        // Only process attachment segments
        if (segment.type !== 'input_image' && segment.type !== 'input_file') {
            return segment;
        }

        // Create clean segment with API-required fields only
        const cleanSegment = {
            type: segment.type,
            attachment_id: segment.attachment_id
        };

        // Preserve real attachment URLs (HTTP/HTTPS) for the agentic workflow.
        // These URLs are used by the API to access the file directly without needing
        // to resolve the attachment_id. Strip blob:/data: URLs which are display-only.
        if (segment.url && isRealAttachmentUrl(segment.url)) {
            cleanSegment.url = segment.url;
        }

        // Preserve API-required fields
        if (segment.display_name !== undefined) {
            cleanSegment.display_name = segment.display_name;
        }
        if (segment.caption !== undefined) {
            cleanSegment.caption = segment.caption;
        }
        if (segment.detail !== undefined) {
            cleanSegment.detail = segment.detail;
        }
        
        // Preserve file metadata for agentic workflow (following OpenAI image tool pattern)
        // These fields provide context about the file without including large binary data
        if (segment.file_name !== undefined) {
            cleanSegment.file_name = segment.file_name;
        }
        if (segment.name !== undefined) {
            cleanSegment.name = segment.name;
        }
        if (segment.mime_type !== undefined) {
            cleanSegment.mime_type = segment.mime_type;
        }
        if (segment.bytes !== undefined) {
            cleanSegment.bytes = segment.bytes;
        }

        return cleanSegment;
    }

    /**
     * Strip UI-only metadata from message content.
     * Handles both string content and array of segments.
     * For segments, removes display-only data like blob:/data: URLs.
     * 
     * @param {string|Array<Object>} content - Message content (string or array of segment objects)
     * @return {string|Array<Object>} Cleaned content
     */
    function stripContentDisplayData(content) {
        // String content passes through unchanged
        if (typeof content === 'string') {
            return content;
        }

        // Array content (segments) needs cleaning
        if (Array.isArray(content)) {
            return content.map(function(segment) {
                // Text segments pass through unchanged
                if (!segment || typeof segment !== 'object') {
                    return segment;
                }
                if (segment.type === 'text') {
                    return segment;
                }
                // Attachment segments get display data stripped
                return stripSegmentDisplayData(segment);
            });
        }

        // Other content types pass through unchanged
        return content;
    }

    /**
     * Strip large file content from tool result content for API submission.
     * Preserves essential fields like attachment_id and url while removing
     * large data like base64-encoded content, data URLs, and raw binary data.
     * 
     * @param {string} content - Tool result content (typically JSON string)
     * @return {string} Cleaned content with large file data stripped
     */
    function stripToolResultLargeContent(content) {
        if (typeof content !== 'string' || !content.trim()) {
            return content;
        }

        // Try to parse as JSON
        let parsed;
        try {
            parsed = JSON.parse(content);
        } catch (e) {
            // Not JSON, return as-is
            return content;
        }

        if (!parsed || typeof parsed !== 'object') {
            return content;
        }

        // Recursively strip large content from the parsed object
        const cleaned = stripLargeContentFromObject(parsed);

        // Return re-serialized JSON
        try {
            return JSON.stringify(cleaned);
        } catch (e) {
            return content;
        }
    }

    /**
     * Recursively strip large content from an object.
     * Removes base64 data, data URLs, and other large binary content while
     * preserving essential fields like attachment_id, url, file_name, etc.
     * 
     * @param {*} obj - Object to clean
     * @param {number} depth - Current recursion depth (to prevent infinite loops)
     * @return {*} Cleaned object
     */
    // Keys that typically contain large binary data to be stripped
    var LARGE_CONTENT_KEYS = ['data', 'base64', 'data_url', 'raw_data', 'binary'];

    function stripLargeContentFromObject(obj, depth) {
        if (depth === undefined) {
            depth = 0;
        }
        
        // Prevent infinite recursion
        if (depth > 10) {
            return obj;
        }

        // Handle null/undefined
        if (obj === null || obj === undefined) {
            return obj;
        }

        // Handle arrays
        if (Array.isArray(obj)) {
            return obj.map(function(item) {
                return stripLargeContentFromObject(item, depth + 1);
            });
        }

        // Handle non-objects
        if (typeof obj !== 'object') {
            // Check if this is a large string (likely base64 or data URL)
            if (typeof obj === 'string') {
                // Strip data URLs (base64 encoded images/files)
                if (obj.indexOf('data:') === 0 && obj.length > 1000) {
                    return '[data URL stripped]';
                }
                // Strip large base64 strings (typically > 1KB when encoded)
                // Check length first to avoid expensive regex on small strings
                if (obj.length > 5000) {
                    // Sample first 100 chars to check if it looks like base64
                    var sample = obj.substring(0, 100);
                    if (/^[A-Za-z0-9+/=]+$/.test(sample)) {
                        return '[base64 data stripped]';
                    }
                }
            }
            return obj;
        }

        // Handle objects - create clean copy
        const cleaned = {};
        
        for (var key in obj) {
            if (!Object.prototype.hasOwnProperty.call(obj, key)) {
                continue;
            }

            var value = obj[key];

            // Skip keys that typically contain large binary data
            if (LARGE_CONTENT_KEYS.indexOf(key) !== -1) {
                // Check if this is actually large content
                if (typeof value === 'string' && value.length > 1000) {
                    cleaned[key] = '[' + key + ' stripped - ' + value.length + ' chars]';
                    continue;
                }
            }

            // Special handling for 'content' objects that might contain encoding info
            if (key === 'content' && typeof value === 'object' && value !== null) {
                if (value.encoding === 'base64' && value.data) {
                    // This is a base64-encoded content block
                    cleaned[key] = {
                        encoding: value.encoding,
                        mime_type: value.mime_type,
                        stripped: true,
                        original_size: typeof value.data === 'string' ? value.data.length : 0
                    };
                    continue;
                }
            }

            // Recursively clean nested objects
            cleaned[key] = stripLargeContentFromObject(value, depth + 1);
        }

        return cleaned;
    }

    /**
     * Strip UI-only metadata from a message for API submission.
     * Preserves 'display' field for CCT persistence to ensure proper UI restoration.
     * Also strips display-only data (blob:/data: URLs) from attachment segments.
     * For tool messages, also strips large file content while preserving attachment_id and url.
     * 
     * @param {Object} message - Original message object
     * @return {Object} Cleaned message object with API-compatible fields
     */
    function stripMessageDisplayMetadata(message) {
        // Handle invalid input
        if (!message || typeof message !== 'object') {
            if (window.console && console.warn) {
                console.warn('[WP oOS] stripMessageDisplayMetadata: Invalid message object', message);
            }
            return null;
        }

        // Validate required field 'role' is present
        if (!message.role) {
            if (window.console && console.warn) {
                console.warn('[WP oOS] stripMessageDisplayMetadata: Message missing required "role" field', message);
            }
            return null;
        }

        // For tool messages, strip large file content from the result
        // This keeps attachment_id and url but removes base64 data to keep payloads lean
        let cleanedContent;
        if (message.role === 'tool') {
            cleanedContent = stripToolResultLargeContent(message.content);
        } else {
            cleanedContent = stripContentDisplayData(message.content);
        }

        // Create a new object with API-compatible fields
        const cleanMessage = {
            role: message.role,
            content: cleanedContent
        };

        // Preserve other API-required fields if present
        if (message.tool_calls !== undefined) {
            cleanMessage.tool_calls = message.tool_calls;
        }
        if (message.tool_call_id !== undefined) {
            cleanMessage.tool_call_id = message.tool_call_id;
        }
        if (message.name !== undefined) {
            cleanMessage.name = message.name;
        }

        // Preserve display metadata for UI restoration from CCT
        // This includes video attachments, text, bubble type, usage/cost badges
        // Critical for async video generation results to persist across sessions
        if (message.display && typeof message.display === 'object') {
            cleanMessage.display = message.display;
        }

        return cleanMessage;
    }

    /**
     * Enhanced function to save conversation to CCT (Custom Content Type) with retry logic.
     * This function includes timeout support, retry logic, and better error handling.
     * 
     * @param {Object} state - Chat state object
     * @param {Object} options - Optional settings (retry, timeout, etc.)
     * @return {Promise} Promise that resolves with save result
     */
    function saveConversationToCCT(state, options) {
        // Return resolved promise if conditions aren't met for saving
        // Use originalAssistantId if available (for when loading sessions from different assistants)
        // Otherwise fall back to config.assistantId for backwards compatibility
        const assistantIdToCheck = state.originalAssistantId || (state.config && state.config.assistantId);
        
        if (!state || !assistantIdToCheck) {
            return Promise.resolve({ success: true, skipped: true });
        }

        if (!state.conversation || !Array.isArray(state.conversation) || state.conversation.length === 0) {
            return Promise.resolve({ success: true, skipped: true });
        }

        if (!state.config.sessionKey) {
            return Promise.resolve({ success: true, skipped: true });
        }

        if (!state.config.transcriptsEndpoint) {
            return Promise.resolve({ success: true, skipped: true });
        }

        // Default options
        const opts = options || {};
        const maxRetries = opts.maxRetries !== undefined ? opts.maxRetries : 2;
        const retryDelay = opts.retryDelay || 1000;
        const timeout = opts.timeout || 15000;
        const silent = opts.silent !== false; // Silent by default

        // Use originalAssistantId if available (for when loading sessions from different assistants)
        // Otherwise fall back to config.assistantId for backwards compatibility
        const assistantIdToUse = state.originalAssistantId || state.config.assistantId;

        // Clean messages for API submission while preserving display metadata for CCT persistence.
        // Strips blob:/data: URLs from attachments, large file content from tool results,
        // but keeps the display field (video attachments, bubble types, usage/cost badges).
        const cleanMessages = state.conversation
            .map(stripMessageDisplayMetadata)
            .filter(function(msg) { return msg !== null; });

        const payload = {
            assistant_id: assistantIdToUse,
            session_key: state.config.sessionKey,
            messages: cleanMessages
        };

        /**
         * Internal function to attempt the save request.
         * 
         * @param {number} attempt - Current attempt number
         * @return {Promise} Promise that resolves with save result
         */
        function attemptSave(attempt) {
            // Log save attempt
            if (!silent && window.console && console.log) {
                console.log('[WP oOS] Saving conversation to CCT:', {
                    session_key: payload.session_key,
                    assistant_id: payload.assistant_id,
                    message_count: payload.messages.length,
                    attempt: attempt + 1
                });
            }

            // Create abort controller for timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(function() {
                controller.abort();
            }, timeout);

            return fetch(state.config.transcriptsEndpoint, {
                method: 'POST',
                headers: buildJsonHeaders(state),
                credentials: 'same-origin',
                body: JSON.stringify(payload),
                signal: controller.signal
            })
                .then(function(response) {
                    clearTimeout(timeoutId);
                    
                    // Clone response for error handling
                    const responseClone = response.clone();
                    
                    return response.json()
                        .catch(function(parseError) {
                            // If JSON parsing fails, try to get text for debugging
                            return responseClone.text().then(function(text) {
                                if (!silent && window.console && console.error) {
                                    console.error('Invalid JSON response from CCT save:', text.substring(0, 200));
                                }
                                return null;
                            }).catch(function() {
                                return null;
                            });
                        })
                        .then(function(body) {
                            if (!response.ok) {
                                // Log error and potentially retry
                                const errorMessage = body && body.message ? body.message : 'Failed to save conversation';
                                
                                if (!silent && window.console && console.error) {
                                    console.error('Failed to save conversation to CCT (attempt ' + (attempt + 1) + '):', body);
                                }
                                
                                // Throw error to trigger retry logic
                                const error = new Error(errorMessage);
                                error.response = response;
                                error.status = response.status;
                                throw error;
                            }

                            // Log successful save
                            if (!silent && window.console && console.log) {
                                console.log('[WP oOS] Conversation saved successfully to CCT');
                            }

                            return { success: true, attempt: attempt + 1 };
                        });
                })
                .catch(function(error) {
                    clearTimeout(timeoutId);
                    
                    // Check if we should retry
                    const isTimeout = error.name === 'AbortError';
                    const isNetworkError = !error.response;
                    const isServerError = error.status && error.status >= 500;
                    
                    // Retry on timeout, network errors, or server errors (5xx)
                    const shouldRetry = (isTimeout || isNetworkError || isServerError) && attempt < maxRetries;
                    
                    if (shouldRetry) {
                        if (!silent && window.console && console.warn) {
                            console.warn('Retrying CCT save (attempt ' + (attempt + 1) + ' of ' + maxRetries + ') after ' + retryDelay + 'ms...');
                        }
                        
                        // Wait before retrying
                        return new Promise(function(resolve) {
                            setTimeout(function() {
                                resolve(attemptSave(attempt + 1));
                            }, retryDelay * (attempt + 1)); // Exponential backoff
                        });
                    }
                    
                    // No more retries - return failure status
                    if (!silent && window.console && console.error) {
                        console.error('Error saving conversation to CCT after ' + (attempt + 1) + ' attempts:', error);
                    }
                    
                    const errorMessage = error && error.message ? error.message : 
                                       (isTimeout ? 'Request timed out after ' + (timeout / 1000) + ' seconds' : 
                                                   'Network error while saving conversation');
                    
                    return { 
                        success: false, 
                        error: errorMessage,
                        attempts: attempt + 1,
                        timeout: isTimeout
                    };
                });
        }

        return attemptSave(0);
    }

    function registerObjectUrl(url) {
        if (audioService && audioService.registerObjectUrl) {
            return audioService.registerObjectUrl(url);
        }

        if (!url) {
            return;
        }

        objectUrlRegistry.push(url);
    }

    function revokeObjectUrls() {
        if (audioService && audioService.revokeObjectUrls) {
            return audioService.revokeObjectUrls();
        }

        if (!objectUrlRegistry.length) {
            return;
        }

        objectUrlRegistry.forEach(function (url) {
            try {
                URL.revokeObjectURL(url);
            } catch (error) {
                // Ignore revoke errors.
            }
        });

        objectUrlRegistry = [];
    }

    function normalizeSpeechText(text) {
        if (typeof text !== 'string') {
            return '';
        }

        return text.trim();
    }

    function updateSpeechButtonIcon(button, stateName) {
        if (!button) {
            return;
        }

        if (button.classList) {
            button.classList.remove(SPEECH_ERROR_CLASS);
        }

        button.dataset.state = stateName;

        if (stateName === 'loading') {
            button.innerHTML = SPEECH_SPINNER_ICON;
            button.setAttribute('aria-label', 'Generating audio...');
            button.setAttribute('title', 'Generating audio...');
            button.setAttribute('aria-busy', 'true');
            return;
        }

        button.removeAttribute('aria-busy');

        if (stateName === 'playing') {
            button.innerHTML = SPEECH_STOP_ICON;
            button.setAttribute('aria-label', 'Stop audio playback');
            button.setAttribute('title', 'Stop audio playback');
            return;
        }

        button.innerHTML = SPEECH_PLAY_ICON;
        button.setAttribute('aria-label', 'Play response audio');
        button.setAttribute('title', 'Play response audio');
    }

    function clearSpeechCacheEntry(state, text) {
        if (!state || !state.speechCache || !text) {
            return;
        }

        delete state.speechCache[text];
    }

    function setSpeechButtonErrorState(state, button, text) {
        if (!button) {
            return;
        }

        button.dataset.state = 'error';
        button.innerHTML = SPEECH_PLAY_ICON;
        button.setAttribute('aria-label', 'Unable to generate audio');
        button.setAttribute('title', 'Unable to generate audio');
        button.removeAttribute('aria-busy');
        button.disabled = false;

        if (button.classList) {
            button.classList.add(SPEECH_ERROR_CLASS);
        }

        if (button._wpMcpAiAudio) {
            try {
                button._wpMcpAiAudio.pause();
            } catch (error) {}
        }

        button._wpMcpAiAudio = null;

        if (state && state.activeSpeech && state.activeSpeech.button === button) {
            state.activeSpeech = null;
        }

        clearSpeechCacheEntry(state, text);
    }

    function stopSpeechPlayback(state, button) {
        if (!state || !button) {
            return;
        }

        let audio = button._wpMcpAiAudio;
        if (!audio && state.activeSpeech && state.activeSpeech.button === button) {
            audio = state.activeSpeech.audio;
        }

        if (audio) {
            try {
                audio.pause();
            } catch (error) {}

            try {
                audio.currentTime = 0;
            } catch (error) {}
        }

        if (state.activeSpeech && state.activeSpeech.button === button) {
            state.activeSpeech = null;
        }

        updateSpeechButtonIcon(button, 'idle');
    }

    function startSpeechPlayback(state, button, audio, text) {
        if (!audio) {
            return;
        }

        if (state.activeSpeech && state.activeSpeech.audio && state.activeSpeech.audio !== audio) {
            try {
                state.activeSpeech.audio.pause();
            } catch (error) {}

            if (state.activeSpeech.button) {
                updateSpeechButtonIcon(state.activeSpeech.button, 'idle');
            }
        }

        audio.currentTime = 0;

        const playPromise = audio.play();
        if (playPromise && typeof playPromise.then === 'function') {
            playPromise.catch(function () {
                const currentText = button.dataset ? button.dataset.speechText || text : text;
                setSpeechButtonErrorState(state, button, currentText);
            });
        }
    }

    function createSpeechAudio(state, button, url, text) {
        const audio = new Audio(url);
        audio.preload = 'auto';

        audio.addEventListener('ended', function () {
            if (state.activeSpeech && state.activeSpeech.audio === audio) {
                state.activeSpeech = null;
            }
            updateSpeechButtonIcon(button, 'idle');
        });

        audio.addEventListener('pause', function () {
            if (button.dataset && button.dataset.state === 'error') {
                return;
            }

            if (!audio.duration || audio.currentTime < audio.duration) {
                if (state.activeSpeech && state.activeSpeech.audio === audio) {
                    state.activeSpeech = null;
                }
                updateSpeechButtonIcon(button, 'idle');
            }
        });

        audio.addEventListener('play', function () {
            state.activeSpeech = { button: button, audio: audio, text: text };
            updateSpeechButtonIcon(button, 'playing');
        });

        audio.addEventListener('error', function () {
            setSpeechButtonErrorState(state, button, text);
        });

        return audio;
    }

    function ensureSpeechAudio(state, button, url, text) {
        if (!url) {
            return;
        }

        let audio = button._wpMcpAiAudio;
        if (!audio || audio.src !== url) {
            audio = createSpeechAudio(state, button, url, text);
            button._wpMcpAiAudio = audio;
        }

        startSpeechPlayback(state, button, audio, text);
    }

    function requestSpeechAudio(state, text) {
        if (!state || !state.config || !state.config.toolsEndpoint) {
            return Promise.reject(new Error('Speech tool unavailable.'));
        }

        const payload = {
            assistant_id: state.config.assistantId,
            tool: SPEECH_TOOL_NAME,
            arguments: {
                text: text,
            },
        };

        return fetch(state.config.toolsEndpoint, {
            method: 'POST',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return null;
                    })
                    .then(function (body) {
                        if (!response.ok) {
                            throw response;
                        }
                        if (!body || typeof body !== 'object') {
                            return Promise.reject(new Error('Invalid response.'));
                        }
                        return body;
                    });
            })
            .then(function (body) {

                const result = Object.prototype.hasOwnProperty.call(body, 'result') ? body.result : body;
                if (!result || typeof result !== 'object' || !result.url) {
                    return Promise.reject(new Error('Missing audio result.'));
                }

                return {
                    url: result.url,
                    attachmentId: result.attachment_id,
                    format: result.format,
                    mimeType: result.mime_type,
                };
            });
    }

    function handleSpeechButtonClick(state, button) {
        if (!state || !button) {
            return;
        }

        const text = normalizeSpeechText(button.dataset.speechText || '');
        if (!text) {
            return;
        }

        const currentState = button.dataset.state;
        if (currentState === 'loading') {
            return;
        }

        if (currentState === 'playing') {
            stopSpeechPlayback(state, button);
            return;
        }

        if (!state.speechCache) {
            state.speechCache = Object.create(null);
        }

        const cache = state.speechCache[text];
        if (cache && cache.url) {
            ensureSpeechAudio(state, button, cache.url, text);
            return;
        }

        updateSpeechButtonIcon(button, 'loading');
        button.disabled = true;

        requestSpeechAudio(state, text)
            .then(function (info) {
                if (!info || !info.url) {
                    throw new Error('Invalid speech response');
                }

                state.speechCache[text] = { url: info.url };
                ensureSpeechAudio(state, button, info.url, text);
            })
            .catch(function () {
                setSpeechButtonErrorState(state, button, text);
            })
            .finally(function () {
                button.disabled = false;
                if (button.dataset.state === 'loading') {
                    updateSpeechButtonIcon(button, 'idle');
                }
            });
    }

    function resolveSpeechText(bubble, text) {
        const provided = normalizeSpeechText(text || '');
        if (provided) {
            return provided;
        }

        if (bubble && bubble.dataset && bubble.dataset.speechText) {
            const stored = normalizeSpeechText(bubble.dataset.speechText);
            if (stored) {
                return stored;
            }
        }

        if (!bubble) {
            return '';
        }

        let textContent = '';
        if (typeof bubble.textContent === 'string') {
            textContent = bubble.textContent;
        } else if (bubble.innerText) {
            textContent = bubble.innerText;
        }

        return normalizeSpeechText(textContent);
    }

    function attachSpeechButton(bubble, state, text) {
        if (audioService && audioService.attachSpeechButton) {
            return audioService.attachSpeechButton(bubble, state, text, buildJsonHeaders);
        }

        // Fallback implementation
        if (!bubble || !state || !state.config || !state.config.toolsEndpoint || !state.config.assistantId) {
            return;
        }

        const normalisedText = resolveSpeechText(bubble, text);
        if (!normalisedText) {
            return;
        }

        if (bubble.classList) {
            bubble.classList.add(SPEECH_ENABLED_CLASS);
        }

        if (bubble.dataset) {
            bubble.dataset.speechText = normalisedText;
        }

        if (!state.speechCache) {
            state.speechCache = Object.create(null);
        }

        const existing = bubble.querySelector('.' + SPEECH_BUTTON_CLASS);
        if (existing) {
            const previousText = normalizeSpeechText(existing.dataset.speechText || '');

            if (previousText && previousText !== normalisedText) {
                stopSpeechPlayback(state, existing);
                clearSpeechCacheEntry(state, previousText);
            }

            existing.dataset.speechText = normalisedText;
            existing.disabled = false;
            updateSpeechButtonIcon(existing, 'idle');
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = SPEECH_BUTTON_CLASS;
        button.dataset.speechText = normalisedText;
        button.setAttribute('aria-label', 'Play response audio');
        button.setAttribute('title', 'Play response audio');

        updateSpeechButtonIcon(button, 'idle');

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            handleSpeechButtonClick(state, button);
        });

        bubble.appendChild(button);
    }

    /**
     * Update copy button visual state.
     * Uses clipboard service if available, otherwise uses internal implementation.
     * 
     * @param {HTMLElement} button - The copy button element
     * @param {string} stateName - State name ('idle', 'copied', 'error')
     */
    function updateCopyButtonState(button, stateName) {
        if (clipboardService && clipboardService.updateCopyButtonState) {
            return clipboardService.updateCopyButtonState(button, stateName);
        }

        if (!button) {
            return;
        }

        button.classList.remove(COPY_ERROR_CLASS);
        button.dataset.state = stateName;

        if (stateName === 'copied') {
            button.innerHTML = COPY_SUCCESS_ICON;
            button.setAttribute('aria-label', 'Copied response');
            button.setAttribute('title', 'Copied response');
            return;
        }

        if (stateName === 'error') {
            button.innerHTML = COPY_ICON;
            button.setAttribute('aria-label', 'Unable to copy');
            button.setAttribute('title', 'Unable to copy');
            button.classList.add(COPY_ERROR_CLASS);
            return;
        }

        button.innerHTML = COPY_ICON;
        button.setAttribute('aria-label', 'Copy response');
        button.setAttribute('title', 'Copy response');
    }

    /**
     * Copy text to clipboard using modern API.
     * Uses clipboard service if available, otherwise uses internal implementation.
     * 
     * @param {string} text - Text to copy
     * @return {Promise<boolean>} Promise resolving to success status
     */
    function copyTextToClipboard(text) {
        if (clipboardService && clipboardService.copyTextToClipboard) {
            return clipboardService.copyTextToClipboard(text);
        }

        if (!text) {
            return Promise.resolve(false);
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard
                .writeText(text)
                .then(function () {
                    return true;
                })
                .catch(function () {
                    return fallbackCopyText(text);
                });
        }

        return fallbackCopyText(text);
    }

    function fallbackCopyText(text) {
        return new Promise(function (resolve) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';

            document.body.appendChild(textarea);

            const selection = document.getSelection ? document.getSelection().rangeCount : 0;

            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);

            let succeeded = false;

            try {
                succeeded = document.execCommand('copy');
            } catch (error) {
                succeeded = false;
            }

            document.body.removeChild(textarea);

            if (selection && document.getSelection) {
                try {
                    document.getSelection().removeAllRanges();
                } catch (error) {}
            }

            resolve(Boolean(succeeded));
        });
    }

    /**
     * Attach copy button to a message bubble.
     * Uses clipboard service if available, otherwise uses internal implementation.
     * 
     * @param {HTMLElement} bubble - Message bubble element
     * @param {string} text - Optional explicit text to copy
     */
    function attachCopyButton(bubble, text) {
        if (clipboardService && clipboardService.attachCopyButton) {
            return clipboardService.attachCopyButton(bubble, text);
        }

        if (!bubble) {
            return;
        }

        const normalisedText = resolveSpeechText(bubble, text);
        if (!normalisedText) {
            return;
        }

        if (bubble.classList) {
            bubble.classList.add(COPY_ENABLED_CLASS);
        }

        if (bubble.dataset) {
            bubble.dataset.copyText = normalisedText;
        }

        const existing = bubble.querySelector('.' + COPY_BUTTON_CLASS);
        if (existing) {
            existing.dataset.copyText = normalisedText;
            existing.disabled = false;
            updateCopyButtonState(existing, 'idle');
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = COPY_BUTTON_CLASS;
        button.dataset.copyText = normalisedText;

        updateCopyButtonState(button, 'idle');

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const textToCopy = resolveSpeechText(bubble, button.dataset.copyText || text);
            if (!textToCopy) {
                updateCopyButtonState(button, 'error');
                setTimeout(function () {
                    domUpdateBatcher.schedule(function() {
                        updateCopyButtonState(button, 'idle');
                    });
                }, 2000);
                return;
            }

            button.disabled = true;

            copyTextToClipboard(textToCopy)
                .then(function (success) {
                    if (success) {
                        updateCopyButtonState(button, 'copied');
                    } else {
                        updateCopyButtonState(button, 'error');
                    }

                    setTimeout(function () {
                        domUpdateBatcher.schedule(function() {
                            updateCopyButtonState(button, 'idle');
                            button.disabled = false;
                        });
                    }, 2000);
                })
                .catch(function () {
                    updateCopyButtonState(button, 'error');
                    setTimeout(function () {
                        domUpdateBatcher.schedule(function() {
                            updateCopyButtonState(button, 'idle');
                            button.disabled = false;
                        });
                    }, 2000);
                });
        });

        bubble.appendChild(button);
    }

    /**
     * Attach delete button to a chat bubble.
     * For assistant bubbles: bottom-left corner
     * For user bubbles: center of the bubble
     * 
     * @param {HTMLElement} bubble - The bubble element
     * @param {Object} state - Chat state object
     * @param {string} role - Message role ('user' or 'assistant')
     */
    function attachDeleteButton(bubble, state, role) {
        if (!bubble || !state) {
            return;
        }

        // Add delete-enabled class
        if (bubble.classList) {
            bubble.classList.add(DELETE_ENABLED_CLASS);
        }

        // Check if button already exists
        const existing = bubble.querySelector('.' + DELETE_BUTTON_CLASS);
        if (existing) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = DELETE_BUTTON_CLASS;
        
        // Add position-specific class for user bubbles
        if (role === 'user') {
            button.classList.add(DELETE_BUTTON_CLASS + '--user');
        }
        
        button.innerHTML = DELETE_ICON;
        button.setAttribute('aria-label', 'Delete message');
        button.setAttribute('title', 'Delete message');

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            
            // Confirm deletion
            const confirmMessage = role === 'user' 
                ? 'Delete this message?' 
                : 'Delete this assistant response?';
            
            if (window.confirm(confirmMessage)) {
                // Find the message index before removing from DOM
                // Only count conversation bubbles (user, assistant, tool) - not system messages
                let indexToRemove = -1;
                const messagesEl = state.messagesEl;
                
                if (messagesEl && bubble.parentNode === messagesEl) {
                    // Get only conversation-relevant bubbles (exclude system messages)
                    const conversationBubbles = messagesEl.querySelectorAll(
                        '.wp-mcp-ai-chat__bubble--user, .wp-mcp-ai-chat__bubble--assistant, .wp-mcp-ai-chat__bubble--tool'
                    );
                    indexToRemove = Array.prototype.indexOf.call(conversationBubbles, bubble);
                }
                
                // Remove the bubble from DOM
                if (bubble.parentNode) {
                    bubble.parentNode.removeChild(bubble);
                }
                
                // Remove from conversation state if available
                if (state && state.conversation && Array.isArray(state.conversation)) {
                    if (indexToRemove >= 0 && indexToRemove < state.conversation.length) {
                        // Remove by exact index
                        state.conversation.splice(indexToRemove, 1);
                    }
                }
                
                // Save updated conversation
                saveConversationToStorage(state, { immediate: true });
                
                // Log deletion for verification
                if (window.console && console.log) {
                    console.log('[WP oOS] Message deleted:', {
                        role: role,
                        deletedIndex: indexToRemove,
                        conversationLength: state.conversation ? state.conversation.length : 0
                    });
                }
            }
        });

        bubble.appendChild(button);
    }

    /**
     * Get the list of saved message keys from localStorage.
     * 
     * @return {Object} Object with message keys as keys, timestamps as values
     */
    function getSavedMessages() {
        if (!window.localStorage) {
            return {};
        }

        try {
            const stored = window.localStorage.getItem(SAVE_STORAGE_KEY);
            if (!stored) {
                return {};
            }
            return JSON.parse(stored) || {};
        } catch (error) {
            return {};
        }
    }

    /**
     * Save a message key to localStorage.
     * 
     * @param {string} messageKey - Unique key for the message
     */
    function markMessageAsSaved(messageKey) {
        if (!window.localStorage || !messageKey) {
            return;
        }

        try {
            const saved = getSavedMessages();
            saved[messageKey] = Date.now();
            window.localStorage.setItem(SAVE_STORAGE_KEY, JSON.stringify(saved));
        } catch (error) {
            if (window.console && console.warn) {
                console.warn('[WP oOS] Failed to save message marker:', error);
            }
        }
    }

    /**
     * Check if a message is already saved.
     * 
     * @param {string} messageKey - Unique key for the message
     * @return {boolean} True if already saved
     */
    function isMessageSaved(messageKey) {
        if (!messageKey) {
            return false;
        }
        const saved = getSavedMessages();
        return !!saved[messageKey];
    }

    /**
     * Update save button visual state.
     * 
     * @param {HTMLElement} button - The save button element
     * @param {string} newState - 'idle', 'saving', 'saved', or 'error'
     */
    function updateSaveButtonState(button, newState) {
        if (!button) {
            return;
        }

        button.classList.remove(
            SAVE_BUTTON_CLASS + '--saving',
            SAVE_BUTTON_CLASS + '--saved',
            SAVE_BUTTON_CLASS + '--error'
        );

        switch (newState) {
            case 'saving':
                button.classList.add(SAVE_BUTTON_CLASS + '--saving');
                button.innerHTML = '<span class="wp-mcp-ai-save-spinner" aria-hidden="true"></span>';
                button.setAttribute('aria-label', 'Saving...');
                button.setAttribute('title', 'Saving...');
                break;
            case 'saved':
                button.classList.add(SAVE_BUTTON_CLASS + '--saved');
                button.innerHTML = SAVE_SUCCESS_ICON;
                button.setAttribute('aria-label', 'Saved');
                button.setAttribute('title', 'Saved');
                break;
            case 'error':
                button.classList.add(SAVE_BUTTON_CLASS + '--error');
                button.innerHTML = SAVE_ICON;
                button.setAttribute('aria-label', 'Save failed, click to retry');
                button.setAttribute('title', 'Save failed, click to retry');
                break;
            default:
                button.innerHTML = SAVE_ICON;
                button.setAttribute('aria-label', 'Save this response');
                button.setAttribute('title', 'Save this response');
        }
    }

    /**
     * Attach save button to an assistant bubble.
     * The save button is always visible (not hover-reveal) and positioned at top-left.
     * It allows users to save individual AI responses to localStorage and CCT.
     * 
     * @param {HTMLElement} bubble - The bubble element
     * @param {Object} state - Chat state object
     * @param {number} messageIndex - Index of the message in the conversation
     */
    function attachSaveButton(bubble, state, messageIndex) {
        if (!bubble || !state) {
            return;
        }

        // Generate a unique key for this message using message content hash
        const assistantId = state.config && state.config.assistantId ? state.config.assistantId : 'unknown';
        const sessionKey = state.config && state.config.sessionKey ? state.config.sessionKey : 'nosession';
        const bubbleText = bubble.textContent || bubble.innerText || '';
        // Create a simple content hash for uniqueness
        const contentHash = bubbleText.length > 0 ? bubbleText.substring(0, 50).replace(/\s/g, '') : messageIndex;
        const messageKey = assistantId + '_' + sessionKey + '_' + messageIndex + '_' + contentHash;

        // Add save-enabled class
        if (bubble.classList) {
            bubble.classList.add(SAVE_ENABLED_CLASS);
        }

        // Check if button already exists
        const existing = bubble.querySelector('.' + SAVE_BUTTON_CLASS);
        if (existing) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = SAVE_BUTTON_CLASS;
        button.dataset.messageKey = messageKey;
        button.dataset.messageIndex = messageIndex;

        // Check if already saved and set initial state
        const alreadySaved = isMessageSaved(messageKey);
        if (alreadySaved) {
            updateSaveButtonState(button, 'saved');
        } else {
            updateSaveButtonState(button, 'idle');
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            // If already saved, don't do anything
            if (button.classList.contains(SAVE_BUTTON_CLASS + '--saved')) {
                return;
            }

            // Show saving state
            button.disabled = true;
            updateSaveButtonState(button, 'saving');

            // Attempt to save to CCT if endpoint is available
            const savePromise = state.config && state.config.transcriptsEndpoint
                ? saveConversationToCCT(state, { silent: true })
                : Promise.resolve({ success: true });

            savePromise
                .then(function (result) {
                    // Mark as saved in localStorage
                    markMessageAsSaved(messageKey);
                    updateSaveButtonState(button, 'saved');

                    if (window.console && console.log) {
                        console.log('[WP oOS] Message saved:', {
                            messageKey: messageKey,
                            messageIndex: messageIndex
                        });
                    }
                })
                .catch(function (error) {
                    updateSaveButtonState(button, 'error');
                    button.disabled = false;

                    if (window.console && console.warn) {
                        console.warn('[WP oOS] Failed to save message:', error);
                    }
                });
        });

        // Insert at the beginning of the bubble (top-left positioning via CSS)
        bubble.insertBefore(button, bubble.firstChild);
    }

    function supportsAudioRecording() {
        if (audioService && audioService.supportsAudioRecording) {
            return audioService.supportsAudioRecording();
        }

        // Fallback implementation
        return (
            typeof window !== 'undefined' &&
            window.navigator &&
            navigator.mediaDevices &&
            typeof navigator.mediaDevices.getUserMedia === 'function' &&
            typeof window.MediaRecorder !== 'undefined'
        );
    }

    function stopRecordingStream(state) {
        if (!state || !state.recordingStream) {
            return;
        }

        const tracks = state.recordingStream.getTracks ? state.recordingStream.getTracks() : [];
        tracks.forEach(function (track) {
            try {
                track.stop();
            } catch (error) {}
        });

        state.recordingStream = null;
    }

    function setTranscribeRecordingState(state, recording) {
        if (!state) {
            return;
        }

        state.isRecording = !!recording;

        const button = state.transcribeButton;
        if (button && button.classList) {
            if (state.isRecording) {
                button.classList.add(TRANSCRIBE_RECORDING_CLASS);
            } else {
                button.classList.remove(TRANSCRIBE_RECORDING_CLASS);
            }
        }

        if (button) {
            const label = state.isRecording
                ? getString('stopRecording', 'Stop recording')
                : getString('transcribeAudio', 'Transcribe audio');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        }

        if (state.container) {
            if (state.isRecording) {
                setStatus(state.container, getString('recording', 'Recording… tap to stop.'));
            } else if (!state.transcribing && !state.busy) {
                setStatus(state.container, '');
            }
        }
    }

    function updateTranscribeButtonState(state) {
        if (audioService && audioService.updateTranscribeButtonState) {
            return audioService.updateTranscribeButtonState(state);
        }

        // Fallback implementation
        if (!state) {
            return;
        }

        const button = state.transcribeButton;
        const input = state.transcribeInput;

        const canUse = !!state.canUploadAttachments;
        let disabled = !canUse || state.busy || state.uploading > 0 || state.transcribing;

        if (state.isRecording) {
            disabled = false;
        }

        if (button) {
            button.disabled = disabled;

            if (!canUse) {
                button.hidden = true;
            } else {
                button.hidden = false;
            }
        }

        if (input) {
            input.disabled = !canUse || state.busy || state.uploading > 0 || state.transcribing || state.isRecording;
        }
    }

    function handleTranscribeButtonClick(state) {
        if (audioService && audioService.handleTranscribeButtonClick) {
            const helpers = {
                getString: getString,
                setStatus: setStatus,
                uploadAudioForTranscription: uploadAudioForTranscription,
                requestTranscription: requestTranscription,
                insertTranscriptionResult: insertTranscriptionResult,
            };
            return audioService.handleTranscribeButtonClick(state, helpers);
        }

        // Fallback implementation
        if (!state || state.transcribing) {
            return;
        }

        if (state.isRecording) {
            stopTranscribeRecording(state);
            return;
        }

        if (!state.canUploadAttachments) {
            return;
        }

        if (supportsAudioRecording()) {
            let shouldRecord = true;

            if (state.transcribeInput) {
                const message = getString(
                    'transcribeChooseSource',
                    'Press OK to record with your microphone, or Cancel to choose an audio file.'
                );

                if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
                    shouldRecord = window.confirm(message);
                }
            }

            if (shouldRecord) {
                startTranscribeRecording(state);
                return;
            }
        }

        if (state.transcribeInput && !state.transcribeInput.disabled) {
            state.transcribeInput.click();
        }
    }

    function startTranscribeRecording(state) {
        if (!state || !supportsAudioRecording()) {
            return;
        }

        state.recordingShouldProcess = false;

        if (state.transcribeButton) {
            state.transcribeButton.disabled = true;
        }

        navigator.mediaDevices
            .getUserMedia({ audio: true })
            .then(function (stream) {
                state.recordingStream = stream;
                state.recordedChunks = [];

                try {
                    state.mediaRecorder = new MediaRecorder(stream);
                } catch (error) {
                    stopRecordingStream(state);
                    setStatus(
                        state.container,
                        getString(
                            'recordingError',
                            'Could not access your microphone. Please allow access or upload an audio file instead.'
                        )
                    );
                    updateTranscribeButtonState(state);
                    return;
                }

                if (!state.mediaRecorder) {
                    stopRecordingStream(state);
                    updateTranscribeButtonState(state);
                    return;
                }

                state.recordingShouldProcess = true;

                state.mediaRecorder.addEventListener('dataavailable', function (event) {
                    if (event && event.data && event.data.size) {
                        state.recordedChunks.push(event.data);
                    }
                });

                state.mediaRecorder.addEventListener('stop', function () {
                    const chunks = state.recordedChunks || [];
                    const mimeType = state.mediaRecorder && state.mediaRecorder.mimeType ? state.mediaRecorder.mimeType : 'audio/webm';
                    let baseMimeType = typeof mimeType === 'string' ? mimeType.split(';')[0] : '';
                    if (!baseMimeType && typeof mimeType === 'string') {
                        baseMimeType = mimeType;
                    }

                    stopRecordingStream(state);
                    setTranscribeRecordingState(state, false);

                    if (!state.recordingShouldProcess) {
                        state.mediaRecorder = null;
                        state.recordedChunks = [];
                        return;
                    }

                    let blob = null;
                    try {
                        let blobType = baseMimeType || mimeType;
                        if (blobType && typeof blobType === 'string') {
                            blobType = blobType.split(';')[0];
                        }
                        blob = new Blob(chunks, { type: blobType || 'audio/webm' });
                    } catch (error) {}

                    state.mediaRecorder = null;
                    state.recordedChunks = [];

                    if (!blob || !blob.size) {
                        updateTranscribeButtonState(state);
                        return;
                    }

                    let extension = '';
                    if (baseMimeType && baseMimeType.indexOf('/') !== -1) {
                        extension = baseMimeType.split('/')[1] || '';
                    }

                    let safeExtension = extension ? extension.replace(/[^a-z0-9]/gi, '') : 'webm';
                    if (!safeExtension) {
                        safeExtension = 'webm';
                    }
                    const fileName = 'transcription-' + Date.now() + '.' + safeExtension;

                    let file = null;
                    try {
                        let fileType = blob && blob.type ? blob.type : baseMimeType || 'audio/webm';
                        if (fileType && typeof fileType === 'string') {
                            fileType = fileType.split(';')[0];
                        }
                        file = new File([blob], fileName, { type: fileType || 'audio/webm' });
                    } catch (error) {
                        file = blob;
                        file.name = fileName;
                        if (file && file.type && typeof file.type === 'string') {
                            file.type = file.type.split(';')[0];
                        }
                        if (file && !file.type && baseMimeType) {
                            file.type = baseMimeType;
                        }
                    }

                    transcribeAudioFile(state, file);
                });

                state.mediaRecorder.start();
                setTranscribeRecordingState(state, true);
                updateTranscribeButtonState(state);
            })
            .catch(function () {
                stopRecordingStream(state);
                setStatus(
                    state.container,
                    getString(
                        'recordingError',
                        'Could not access your microphone. Please allow access or upload an audio file instead.'
                    )
                );

                if (state.transcribeInput && !state.transcribeInput.disabled) {
                    state.transcribeInput.click();
                }

                updateTranscribeButtonState(state);
            });
    }

    function stopTranscribeRecording(state) {
        if (!state || !state.mediaRecorder) {
            return;
        }

        state.recordingShouldProcess = true;

        try {
            if (state.mediaRecorder.state !== 'inactive') {
                state.mediaRecorder.stop();
            }
        } catch (error) {
            stopRecordingStream(state);
            setTranscribeRecordingState(state, false);
            updateTranscribeButtonState(state);
        }
    }

    function handleTranscribeFileSelection(event, state) {
        if (audioService && audioService.handleTranscribeFileSelection) {
            const helpers = {
                getString: getString,
                setStatus: setStatus,
                uploadAudioForTranscription: uploadAudioForTranscription,
                requestTranscription: requestTranscription,
                insertTranscriptionResult: insertTranscriptionResult,
                formatDuration: formatDuration,
            };
            return audioService.handleTranscribeFileSelection(event, state, helpers);
        }

        // Fallback implementation
        if (!state || !state.canUploadAttachments) {
            return;
        }

        if (!event || !event.target || !event.target.files) {
            return;
        }

        const files = Array.prototype.slice.call(event.target.files);
        event.target.value = '';

        if (!files.length) {
            return;
        }

        const file = files[0];
        transcribeAudioFile(state, file);
    }

    function transcribeAudioFile(state, file) {
        if (!state || !file || !state.canUploadAttachments || state.transcribing) {
            return;
        }

        if (file.size && file.size > MAX_TRANSCRIBE_BYTES) {
            setStatus(
                state.container,
                getString(
                    'transcriptionFileTooLarge',
                    'The selected audio file is too large. Please choose a file under 25MB.'
                )
            );
            updateTranscribeButtonState(state);
            return;
        }

        state.transcribing = true;
        updateTranscribeButtonState(state);

        setStatus(state.container, getString('transcribing', 'Transcribing audio…'));

        let uploadedRecord = null;

        uploadAudioForTranscription(state, file)
            .then(function (record) {
                uploadedRecord = record;
                if (!record || typeof record.id === 'undefined') {
                    throw new Error('Upload failed');
                }

                if (state.attachmentLibrary && record.fileId) {
                    state.attachmentLibrary[record.fileId] = record;
                }

                return requestTranscription(state, record);
            })
            .then(function (response) {
                const result = extractTranscriptionResult(response);
                insertTranscriptionResult(state, result, uploadedRecord || file);

                let label = '';
                if (uploadedRecord && uploadedRecord.name) {
                    label = uploadedRecord.name;
                } else if (file && file.name) {
                    label = file.name;
                }

                const messageLabel = label || getString('transcribeAudio', 'Transcribe audio');
                const message = formatString(
                    getString('transcriptionSuccess', 'Inserted transcription from “%s”.'),
                    messageLabel
                );
                setStatus(state.container, message);
            })
            .catch(function (error) {
                setStatus(
                    state.container,
                    getString('transcriptionError', 'The transcription request failed. Please try again.')
                );

                if (window.console && console.error) {
                    console.error('Transcription failed', error);
                }
            })
            .finally(function () {
                state.transcribing = false;
                updateTranscribeButtonState(state);
            });
    }

    function uploadAudioForTranscription(state, file) {
        if (!state || !file || !state.config || !state.config.uploadEndpoint) {
            if (window.console && console.error) {
                console.error('Voice chat: Upload configuration missing', {
                    hasState: !!state,
                    hasFile: !!file,
                    hasConfig: !!(state && state.config),
                    uploadEndpoint: state && state.config ? state.config.uploadEndpoint : 'undefined'
                });
            }
            return Promise.reject(new Error('Upload unavailable'));
        }

        if (window.console && console.log) {
            console.log('Voice chat: Uploading audio file', {
                fileName: file.name,
                fileSize: file.size,
                fileType: file.type,
                endpoint: state.config.uploadEndpoint
            });
        }

        const headers = {
            'X-WP-Nonce': globalConfig.nonce || '',
            Accept: 'application/json',
        };

        const contentDisposition = createContentDispositionHeader(file.name || 'audio');
        if (contentDisposition) {
            headers['Content-Disposition'] = contentDisposition;
        }

        let contentType = '';
        if (file && file.type && typeof file.type === 'string') {
            contentType = file.type.split(';')[0];
        }

        headers['Content-Type'] = contentType || 'audio/webm';

        return fetch(state.config.uploadEndpoint, {
            method: 'POST',
            headers: headers,
            body: file,
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (window.console && console.log) {
                    console.log('Voice chat: Upload response received', {
                        status: response.status,
                        statusText: response.statusText,
                        ok: response.ok
                    });
                }

                return response
                    .json()
                    .catch(function (parseError) {
                        if (window.console && console.error) {
                            console.error('Voice chat: Failed to parse upload response JSON', parseError);
                        }
                        return null;
                    })
                    .then(function (data) {
                        if (!response.ok) {
                            if (window.console && console.error) {
                                console.error('Voice chat: Upload failed', {
                                    status: response.status,
                                    statusText: response.statusText,
                                    data: data
                                });
                            }
                            const error = new Error('Upload failed');
                            error.response = response;
                            error.status = response.status;
                            error.data = data;
                            throw error;
                        }
                        return data;
                    });
            })
            .then(function (data) {
                const record = normaliseUploadResponse(data, file);
                if (window.console && console.log) {
                    console.log('Voice chat: Media file created successfully', {
                        id: record ? record.id : 'none',
                        fileId: record ? record.fileId : 'none',
                        name: record ? record.name : 'none'
                    });
                }
                return record;
            });
    }

    function requestTranscription(state, record) {
        if (!state || !record || typeof record.id === 'undefined') {
            return Promise.reject(new Error('Missing attachment id'));
        }

        if (!state.config || !state.config.toolsEndpoint) {
            if (window.console && console.error) {
                console.error('Voice chat: Tools endpoint not configured', {
                    hasConfig: !!state.config,
                    toolsEndpoint: state.config ? state.config.toolsEndpoint : 'undefined'
                });
            }
            return Promise.reject(new Error('Tools endpoint unavailable'));
        }

        const payload = {
            assistant_id: state.config.assistantId,
            tool: TRANSCRIBE_TOOL_NAME,
            arguments: {
                attachment_id: record.id,
            },
        };

        if (window.console && console.log) {
            console.log('Voice chat: Requesting transcription', {
                endpoint: state.config.toolsEndpoint,
                attachmentId: record.id,
                tool: TRANSCRIBE_TOOL_NAME
            });
        }

        return fetch(state.config.toolsEndpoint, {
            method: 'POST',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        }).then(function (response) {
            if (!response.ok) {
                if (window.console && console.error) {
                    console.error('Voice chat: Transcription request failed', {
                        status: response.status,
                        statusText: response.statusText,
                        url: response.url,
                        endpoint: state.config.toolsEndpoint,
                        attachmentId: record.id
                    });
                }
            }

            return response
                .json()
                .catch(function (parseError) {
                    if (window.console && console.error) {
                        console.error('Voice chat: Failed to parse response JSON', parseError);
                    }
                    return null;
                })
                .then(function (data) {
                    if (!response.ok) {
                        const error = new Error('Transcription request failed');
                        error.response = response;
                        error.status = response.status;
                        error.data = data;
                        throw error;
                    }
                    return data;
                });
        });
    }

    function extractTranscriptionResult(body) {
        if (!body || typeof body !== 'object') {
            return null;
        }

        if (Object.prototype.hasOwnProperty.call(body, 'result')) {
            return body.result;
        }

        return body;
    }

    function insertTranscriptionResult(state, result, record) {
        if (!state || !state.textarea) {
            return;
        }

        const payload = result || {};
        let text = '';

        if (payload && typeof payload.text === 'string') {
            text = payload.text.trim();
        }

        const metaParts = [];
        if (record && record.name) {
            metaParts.push(record.name);
        }

        if (payload.language) {
            metaParts.push('Language: ' + payload.language);
        }

        if (typeof payload.duration === 'number') {
            const duration = formatDuration(payload.duration);
            if (duration) {
                metaParts.push('Duration: ' + duration);
            }
        }

        if (payload.translated) {
            metaParts.push('Translated to English');
        }

        let segmentsText = '';
        if (Array.isArray(payload.segments) && payload.segments.length) {
            segmentsText = payload.segments
                .map(function (segment) {
                    if (!segment) {
                        return '';
                    }

                    const start = formatDuration(segment.start);
                    const end = formatDuration(segment.end);
                    const segmentText = segment.text || '';
                    let prefix = '';

                    if (start && end) {
                        prefix = start + '–' + end;
                    } else if (start) {
                        prefix = start;
                    }

                    if (prefix) {
                        return prefix + ': ' + segmentText;
                    }

                    return segmentText;
                })
                .filter(function (segmentText) {
                    return segmentText && segmentText.trim();
                })
                .join('\n');
        }

        const hasTextContent = Boolean(text) || Boolean(segmentsText);
        if (!hasTextContent) {
            return;
        }

        const sections = [];
        if (metaParts.length) {
            sections.push(metaParts.join(' • '));
        }

        if (text) {
            sections.push(text);
        }

        if (segmentsText) {
            sections.push(segmentsText);
        }

        const combined = sections.join('\n\n').trim();
        if (!combined) {
            return;
        }

        const existing = state.textarea.value || '';
        const trimmedExisting = existing.replace(/\s+$/, '');
        const newValue = trimmedExisting ? trimmedExisting + '\n\n' + combined : combined;

        state.textarea.value = newValue;
        state.textarea.focus();

        try {
            const caret = newValue.length;
            state.textarea.setSelectionRange(caret, caret);
        } catch (error) {}
    }

    /**
     * Format duration in seconds to MM:SS or HH:MM:SS format.
     * Uses UI utilities service if available, otherwise uses internal implementation.
     * 
     * @param {number} value - Duration in seconds
     * @return {string} Formatted duration (e.g., "1:30", "1:05:30")
     */
    function formatDuration(value) {
        if (uiUtilsService && uiUtilsService.formatDuration) {
            return uiUtilsService.formatDuration(value);
        }

        const seconds = Number(value);
        if (!isFinite(seconds) || seconds < 0) {
            return '';
        }

        const totalSeconds = Math.round(seconds);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const secs = totalSeconds % 60;

        const parts = [];
        if (hours) {
            parts.push(hours);
        }

        parts.push(hours ? String(minutes).padStart(2, '0') : String(minutes));
        parts.push(String(secs).padStart(2, '0'));

        return parts.join(':');
    }

    /**
     * Voice Chat Functions
     */
    function handleVoiceChatButtonClick(state) {
        if (audioService && audioService.handleVoiceChatButtonClick) {
            const helpers = {
                getString: getString,
                setStatus: setStatus,
                uploadAudioForTranscription: uploadAudioForTranscription,
                requestTranscription: requestTranscription,
                sendMessage: sendChat,
            };
            return audioService.handleVoiceChatButtonClick(state, helpers);
        }

        // Fallback implementation
        if (!state || state.voiceChatProcessing) {
            return;
        }

        if (state.isVoiceChatRecording) {
            stopVoiceChatRecording(state);
            return;
        }

        if (!state.canUploadAttachments) {
            return;
        }

        if (supportsAudioRecording()) {
            startVoiceChatRecording(state);
        } else {
            setStatus(
                state.container,
                getString('voiceChatUnavailable', 'Voice chat is not available in your browser.')
            );
        }
    }

    function startVoiceChatRecording(state) {
        if (!state || !supportsAudioRecording()) {
            return;
        }

        state.voiceChatShouldProcess = false;
        updateVoiceChatButtonState(state);

        navigator.mediaDevices
            .getUserMedia({ audio: true })
            .then(function (stream) {
                state.voiceChatStream = stream;
                state.voiceChatChunks = [];

                try {
                    state.voiceChatRecorder = new MediaRecorder(stream);
                } catch (error) {
                    stopVoiceChatStream(state);
                    setStatus(
                        state.container,
                        getString('voiceChatRecorderError', 'Could not start voice recording.')
                    );
                    updateVoiceChatButtonState(state);
                    return;
                }

                state.voiceChatRecorder.addEventListener('dataavailable', function (event) {
                    if (event.data && event.data.size > 0) {
                        state.voiceChatChunks.push(event.data);
                    }
                });

                state.voiceChatRecorder.addEventListener('stop', function () {
                    stopVoiceChatStream(state);

                    if (!state.voiceChatShouldProcess) {
                        state.voiceChatChunks = [];
                        updateVoiceChatButtonState(state);
                        return;
                    }

                    if (!state.voiceChatChunks || !state.voiceChatChunks.length) {
                        setStatus(state.container, getString('voiceChatNoData', 'No audio was recorded.'));
                        updateVoiceChatButtonState(state);
                        return;
                    }

                    const blob = new Blob(state.voiceChatChunks, { type: 'audio/webm' });
                    state.voiceChatChunks = [];

                    processVoiceChatAudio(state, blob);
                });

                state.voiceChatRecorder.start();
                state.voiceChatShouldProcess = true;
                setVoiceChatRecordingState(state, true);
                updateVoiceChatButtonState(state);
            })
            .catch(function (error) {
                setStatus(
                    state.container,
                    getString('voiceChatPermissionDenied', 'Microphone access was denied.')
                );
                updateVoiceChatButtonState(state);
            });
    }

    function stopVoiceChatRecording(state) {
        if (!state) {
            return;
        }

        setVoiceChatRecordingState(state, false);

        if (state.voiceChatRecorder && state.voiceChatRecorder.state !== 'inactive') {
            state.voiceChatRecorder.stop();
        } else {
            stopVoiceChatStream(state);
            updateVoiceChatButtonState(state);
        }
    }

    function stopVoiceChatStream(state) {
        if (!state || !state.voiceChatStream) {
            return;
        }

        try {
            state.voiceChatStream.getTracks().forEach(function (track) {
                track.stop();
            });
        } catch (error) {}

        state.voiceChatStream = null;
    }

    function setVoiceChatRecordingState(state, recording) {
        if (!state) {
            return;
        }

        state.isVoiceChatRecording = !!recording;

        const button = state.voiceChatButton;
        if (button && button.classList) {
            if (state.isVoiceChatRecording) {
                button.classList.add(VOICE_CHAT_RECORDING_CLASS);
            } else {
                button.classList.remove(VOICE_CHAT_RECORDING_CLASS);
            }
        }

        if (button) {
            const label = state.isVoiceChatRecording
                ? getString('stopVoiceChat', 'Stop voice chat')
                : getString('voiceChat', 'Voice chat');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        }

        if (state.container) {
            if (state.isVoiceChatRecording) {
                setStatus(state.container, getString('voiceChatRecording', 'Recording… tap to stop and send.'));
            } else if (!state.voiceChatProcessing && !state.busy) {
                setStatus(state.container, '');
            }
        }
    }

    function updateVoiceChatButtonState(state) {
        if (audioService && audioService.updateVoiceChatButtonState) {
            return audioService.updateVoiceChatButtonState(state);
        }

        // Fallback implementation
        if (!state) {
            return;
        }

        const button = state.voiceChatButton;

        const canUse = !!state.canUploadAttachments;
        let disabled = !canUse || state.busy || state.uploading > 0 || state.voiceChatProcessing;

        if (state.isVoiceChatRecording) {
            disabled = false;
        }

        if (button) {
            button.disabled = disabled;

            if (!canUse) {
                button.hidden = true;
            } else {
                button.hidden = false;
            }
        }
    }

    function processVoiceChatAudio(state, blob) {
        if (!state || !blob || state.voiceChatProcessing) {
            return;
        }

        if (blob.size > MAX_TRANSCRIBE_BYTES) {
            setStatus(
                state.container,
                getString(
                    'voiceChatFileTooLarge',
                    'The recorded audio is too large. Please try a shorter message.'
                )
            );
            updateVoiceChatButtonState(state);
            return;
        }

        state.voiceChatProcessing = true;
        updateVoiceChatButtonState(state);

        const button = state.voiceChatButton;
        if (button && button.classList) {
            button.classList.add(VOICE_CHAT_PROCESSING_CLASS);
        }

        setStatus(state.container, getString('voiceChatProcessing', 'Processing your voice message…'));

        const file = new File([blob], 'voice-chat-' + Date.now() + '.webm', {
            type: 'audio/webm',
            lastModified: Date.now(),
        });

        let uploadedRecord = null;

        uploadAudioForTranscription(state, file)
            .then(function (record) {
                uploadedRecord = record;
                if (!record || typeof record.id === 'undefined') {
                    throw new Error('Upload failed');
                }

                if (state.attachmentLibrary && record.fileId) {
                    state.attachmentLibrary[record.fileId] = record;
                }

                return requestTranscription(state, record);
            })
            .then(function (response) {
                const result = extractTranscriptionResult(response);
                
                if (!result || !result.text || !result.text.trim()) {
                    throw new Error('No text transcribed');
                }

                // Enable voice chat mode to auto-play the response
                state.voiceChatModeActive = true;

                // Automatically send the transcribed text as a message
                state.textarea.value = result.text.trim();
                setStatus(state.container, getString('voiceChatSending', 'Sending your message…'));

                // Trigger form submission
                const form = state.container.querySelector('.wp-mcp-ai-chat__form');
                if (form) {
                    const submitEvent = new Event('submit', {
                        bubbles: true,
                        cancelable: true,
                    });
                    form.dispatchEvent(submitEvent);
                }
            })
            .catch(function (error) {
                setStatus(
                    state.container,
                    getString('voiceChatError', 'Voice chat processing failed. Please try again.')
                );

                if (window.console && console.error) {
                    console.error('Voice chat failed', error);
                }
            })
            .finally(function () {
                state.voiceChatProcessing = false;
                const button = state.voiceChatButton;
                if (button && button.classList) {
                    button.classList.remove(VOICE_CHAT_PROCESSING_CLASS);
                }
                updateVoiceChatButtonState(state);
            });
    }

    function handleToolShortcutClick(state, button) {
        if (!state || !button || !state.textarea) {
            return;
        }

        let payload = '';

        if (button.dataset) {
            payload = button.dataset.shortcutPayload || button.dataset.shortcutTool || '';
        }

        if (typeof payload !== 'string') {
            payload = '';
        }

        payload = payload.trim();

        if (!payload) {
            return;
        }

        state.textarea.value = payload;
        state.textarea.focus();

        try {
            const caret = state.textarea.value.length;
            state.textarea.setSelectionRange(caret, caret);
        } catch (error) {}

        copyTextToClipboard(payload).catch(function () {});
    }

    function renderToolShortcuts(state) {
        if (!state || !state.toolShortcutsContainer) {
            return;
        }

        const container = state.toolShortcutsContainer;
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }

        let shortcuts = [];
        if (state.config && Array.isArray(state.config.toolShortcuts)) {
            shortcuts = state.config.toolShortcuts;
        }

        shortcuts.forEach(function (shortcut) {
            if (!shortcut) {
                return;
            }

            let label = '';
            let payload = '';
            let tool = '';
            let description = '';

            if (typeof shortcut === 'string') {
                label = shortcut;
                payload = shortcut;
            } else if (typeof shortcut === 'object') {
                if (typeof shortcut.label === 'string') {
                    label = shortcut.label;
                }

                if (typeof shortcut.payload === 'string') {
                    payload = shortcut.payload;
                }

                if (typeof shortcut.tool === 'string') {
                    tool = shortcut.tool;
                }

                if (typeof shortcut.description === 'string') {
                    description = shortcut.description;
                }
            }

            label = label ? label.trim() : '';
            payload = payload ? payload.trim() : '';
            tool = tool ? tool.trim() : '';
            description = description ? description.trim() : '';

            if (!label && tool) {
                label = tool;
            }

            if (!label && !payload) {
                return;
            }

            if (!payload) {
                payload = tool || label;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = TOOL_SHORTCUT_BUTTON_CLASS;
            button.textContent = label;

            if (tool) {
                button.dataset.shortcutTool = tool;
            }

            if (payload) {
                button.dataset.shortcutPayload = payload;
            }

            if (description) {
                button.dataset.shortcutDescription = description;
            }

            const ariaTemplate = getString('toolShortcutLabel', 'Insert task: %s');
            let ariaLabel = formatString(ariaTemplate, label);

            if (description) {
                ariaLabel += '. ' + description;
            }

            button.setAttribute('aria-label', ariaLabel);
            button.setAttribute('title', description || label);

            button.addEventListener('click', function (event) {
                event.preventDefault();
                handleToolShortcutClick(state, button);
            });

            container.appendChild(button);
        });

        // Show/hide the wrapper based on whether there are shortcuts
        if (state.toolShortcutsWrapper) {
            state.toolShortcutsWrapper.hidden = !container.children.length;
        }
    }

    function initialiseExistingSpeechButtons(state) {
        if (!state || !state.messagesEl) {
            return;
        }

        const selector = '.wp-mcp-ai-chat__message.wp-mcp-ai-chat__bubble--assistant';
        const bubbles = state.messagesEl.querySelectorAll(selector);

        // Attach speech and copy buttons for assistant messages
        Array.prototype.forEach.call(bubbles, function (bubble) {
            const storedText = bubble && bubble.dataset ? bubble.dataset.speechText || '' : '';
            attachSpeechButton(bubble, state, storedText);
            attachCopyButton(bubble, storedText);
        });

        // Attach save buttons for system messages only
        const systemSelector = '.wp-mcp-ai-chat__message.wp-mcp-ai-chat__bubble--system';
        const systemBubbles = state.messagesEl.querySelectorAll(systemSelector);
        
        let systemMsgIndex = 0;
        Array.prototype.forEach.call(systemBubbles, function (bubble, domIndex) {
            // Use the actual position in conversation if available, otherwise use DOM index
            const conversationIndex = (state.conversation && Array.isArray(state.conversation))
                ? findSystemMessageIndex(state.conversation, systemMsgIndex)
                : domIndex;
            attachSaveButton(bubble, state, conversationIndex);
            systemMsgIndex++;
        });
    }

    /**
     * Find the index of the nth system message in the conversation array.
     * 
     * @param {Array} conversation - The conversation array
     * @param {number} nthSystem - Which system message to find (0-based)
     * @return {number} The index in the conversation array, or -1 if not found
     */
    function findSystemMessageIndex(conversation, nthSystem) {
        if (!Array.isArray(conversation)) {
            return -1;
        }
        let systemCount = 0;
        for (let i = 0; i < conversation.length; i++) {
            if (conversation[i] && conversation[i].role === 'system') {
                if (systemCount === nthSystem) {
                    return i;
                }
                systemCount++;
            }
        }
        return -1;
    }

    /**
     * Find the index of the nth assistant message in the conversation array.
     * 
     * @param {Array} conversation - The conversation array
     * @param {number} nthAssistant - Which assistant message to find (0-based)
     * @return {number} The index in the conversation array, or -1 if not found
     */
    function findAssistantMessageIndex(conversation, nthAssistant) {
        if (!Array.isArray(conversation)) {
            return -1;
        }
        let assistantCount = 0;
        for (let i = 0; i < conversation.length; i++) {
            if (conversation[i] && conversation[i].role === 'assistant') {
                if (assistantCount === nthAssistant) {
                    return i;
                }
                assistantCount++;
            }
        }
        return -1;
    }

    function normaliseList(value) {
        if (!Array.isArray(value)) {
            return [];
        }

        const seen = {};
        const result = [];

        value.forEach(function (item) {
            if (typeof item !== 'string') {
                return;
            }

            const normalised = item.trim().toLowerCase();
            if (!normalised || seen[normalised]) {
                return;
            }

            seen[normalised] = true;
            result.push(normalised);
        });

        return result;
    }

    function getFileExtension(file) {
        if (!file || !file.name) {
            return '';
        }

        const name = String(file.name);
        const dotIndex = name.lastIndexOf('.');

        if (dotIndex === -1) {
            return '';
        }

        return name.slice(dotIndex + 1).toLowerCase();
    }

    function isFileTypeAllowed(file, state) {
        if (!file) {
            return false;
        }

        if (!state || !state.config) {
            return true;
        }

        const allowedImageMimes = Array.isArray(state.config.allowedImageMimes) ? state.config.allowedImageMimes : [];
        const allowedFileMimes = Array.isArray(state.config.allowedFileMimes) ? state.config.allowedFileMimes : [];
        const allowedExtensions = Array.isArray(state.config.allowedExtensions) ? state.config.allowedExtensions : [];

        if (!allowedImageMimes.length && !allowedFileMimes.length && !allowedExtensions.length) {
            return true;
        }

        let mime = (file.type || '').toLowerCase();

        if (mime) {
            const semicolonIndex = mime.indexOf(';');

            if (semicolonIndex !== -1) {
                mime = mime.slice(0, semicolonIndex);
            }

            mime = mime.trim();
        }

        if (mime) {
            if (allowedImageMimes.indexOf(mime) !== -1 || allowedFileMimes.indexOf(mime) !== -1) {
                return true;
            }

            const extensionFromMime = getFileExtension(file);
            if (extensionFromMime && allowedExtensions.indexOf(extensionFromMime) !== -1) {
                return true;
            }

            return false;
        }

        const extension = getFileExtension(file);
        if (extension) {
            return allowedExtensions.indexOf(extension) !== -1;
        }

        return true;
    }

    if (typeof window !== 'undefined' && window.addEventListener) {
        window.addEventListener('beforeunload', revokeObjectUrls);
    }

    function getString(key, fallback) {
        if (globalConfig.strings && Object.prototype.hasOwnProperty.call(globalConfig.strings, key)) {
            return globalConfig.strings[key];
        }
        return fallback;
    }

    function setTranscriptExpanded(state, expanded) {
        if (!state) {
            return;
        }

        state.transcriptExpanded = !!expanded;

        if (state.container && state.container.classList) {
            if (state.transcriptExpanded) {
                state.container.classList.add('wp-mcp-ai-chat--expanded');
            } else {
                state.container.classList.remove('wp-mcp-ai-chat--expanded');
            }
        }

        if (state.transcriptToggle) {
            const label = state.transcriptExpanded
                ? getString('collapseTranscript', 'Collapse conversation')
                : getString('expandTranscript', 'Expand conversation');
            state.transcriptToggle.setAttribute('aria-expanded', state.transcriptExpanded ? 'true' : 'false');
            state.transcriptToggle.setAttribute('aria-label', label);

            const screenReaderText = state.transcriptToggle.querySelector('.screen-reader-text');
            if (screenReaderText) {
                screenReaderText.textContent = label;
            }
        }

        if (state.transcriptExpanded && state.messagesEl) {
            scrollBatcher.scrollToBottom(state.messagesEl);
        }
    }

    /**
     * Toggle the tool shortcuts section visibility.
     *
     * @param {Object} state - Chat state object
     */
    function toggleToolShortcuts(state) {
        if (!state) {
            return;
        }

        state.toolShortcutsExpanded = !state.toolShortcutsExpanded;

        if (state.toolShortcutsContainer) {
            state.toolShortcutsContainer.hidden = !state.toolShortcutsExpanded;
            
            if (state.toolShortcutsContainer.classList) {
                if (state.toolShortcutsExpanded) {
                    state.toolShortcutsContainer.classList.remove('wp-mcp-ai-chat__tool-shortcuts--collapsed');
                } else {
                    state.toolShortcutsContainer.classList.add('wp-mcp-ai-chat__tool-shortcuts--collapsed');
                }
            }
        }

        if (state.toolShortcutsToggle) {
            const expanded = state.toolShortcutsExpanded;
            state.toolShortcutsToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            
            if (state.toolShortcutsToggle.classList) {
                if (expanded) {
                    state.toolShortcutsToggle.classList.remove('wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed');
                } else {
                    state.toolShortcutsToggle.classList.add('wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed');
                }
            }
        }
    }

    /**
     * Update quota monitor UI element with current localStorage usage.
     * 
     * @param {HTMLElement} monitorEl - The quota monitor element
     */
    /**
     * Update quota monitor UI element with current localStorage usage.
     * Uses async quota calculation to avoid blocking the main thread.
     * 
     * @param {Element} monitorEl - The quota monitor element
     */
    function updateQuotaMonitor(monitorEl) {
        if (!monitorEl) {
            return;
        }

        getLocalStorageQuota(function(quota) {
            // Batch DOM updates to prevent setTimeout violations
            domUpdateBatcher.schedule(function() {
                if (!quota.available) {
                    monitorEl.innerHTML = '<span class="wp-mcp-ai-chat__quota-unavailable">Storage monitoring unavailable</span>';
                    return;
                }

                const percentage = quota.percentage;
                let statusClass = 'wp-mcp-ai-chat__quota-ok';
                let statusText = 'OK';
                
                if (percentage >= 90) {
                    statusClass = 'wp-mcp-ai-chat__quota-critical';
                    statusText = 'Critical';
                } else if (percentage >= 75) {
                    statusClass = 'wp-mcp-ai-chat__quota-warning';
                    statusText = 'High';
                }

                monitorEl.innerHTML = '' +
                    '<span class="wp-mcp-ai-chat__quota-label">Storage:</span> ' +
                    '<span class="wp-mcp-ai-chat__quota-bar">' +
                        '<span class="wp-mcp-ai-chat__quota-fill ' + statusClass + '" style="width: ' + percentage + '%"></span>' +
                    '</span> ' +
                    '<span class="wp-mcp-ai-chat__quota-text ' + statusClass + '">' +
                        quota.formattedUsed + ' / ' + quota.formattedTotal + ' (' + Math.round(percentage) + '%' + (statusText !== 'OK' ? ' - ' + statusText : '') + ')' +
                    '</span>';

                // Add tooltip with detailed info
                monitorEl.setAttribute('title', 
                    'Total localStorage: ' + quota.formattedUsed + ' / ' + quota.formattedTotal + '\n' +
                    'WP oOS chats: ' + quota.formattedWpMcpAiUsed + '\n' +
                    'Status: ' + statusText
                );
            });
        });
    }

    /**
     * Handle conversation export button click.
     * Shows format selection dialog and triggers download.
     * 
     * @param {Object} state - Chat state object
     */
    function handleExportConversation(state) {
        if (!state || !state.conversation || state.conversation.length === 0) {
            alert(getString('noConversationToExport', 'No conversation to export. Start chatting first!'));
            return;
        }

        // Ask user for format
        const format = prompt(
            getString('exportFormatPrompt', 'Choose export format:\n- json\n- markdown\n- text'),
            'json'
        );

        if (!format) {
            return; // User cancelled
        }

        const normalizedFormat = format.toLowerCase().trim();
        if (normalizedFormat !== 'json' && normalizedFormat !== 'markdown' && normalizedFormat !== 'text') {
            alert(getString('invalidExportFormat', 'Invalid format. Please choose json, markdown, or text.'));
            return;
        }

        const result = exportConversation(state, normalizedFormat);
        
        if (!result.success) {
            const errorMsg = extractErrorMessage(result.error, 'Unknown error');
            alert(getString('exportFailed', 'Export failed: ') + errorMsg);
            return;
        }

        downloadFile(result.content, result.filename, result.mimeType);
        
        setStatus(state.container, getString('exportSuccess', 'Conversation exported successfully as ') + result.filename);
        setTimeout(function() {
            domUpdateBatcher.schedule(function() {
                clearStatus(state.container);
            });
        }, 3000);
    }

    /**
     * Handle save conversation button click.
     * Saves the current conversation to CCT without clearing it.
     * 
     * @param {Object} state - Chat state object
     */
    function handleSaveConversation(state) {
        if (!state) {
            return;
        }

        // Check if there's anything to save
        if (!state.conversation || state.conversation.length === 0) {
            setStatus(state.container, getString('noConversationToSave', 'No conversation to save. Start chatting first!'));
            setTimeout(function() {
                domUpdateBatcher.schedule(function() {
                    clearStatus(state.container);
                });
            }, 3000);
            return;
        }

        // Show saving status
        setStatus(state.container, getString('savingConversation', 'Saving current conversation...'));

        // Save to CCT
        saveConversationToCCT(state, { silent: false }).then(function(result) {
            if (!result || result.skipped) {
                // No save needed or save was skipped
                setStatus(state.container, getString('saveSkipped', 'Save not available for this conversation.'));
                setTimeout(function() {
                    domUpdateBatcher.schedule(function() {
                        clearStatus(state.container);
                    });
                }, 3000);
                return;
            }

            if (result.success) {
                // Save succeeded
                setStatus(state.container, getString('conversationSaved', 'Conversation saved successfully.'));
                
                // Helper to clear status after delay
                const clearStatusAfterDelay = function() {
                    setTimeout(function() {
                        domUpdateBatcher.schedule(function() {
                            clearStatus(state.container);
                        });
                    }, 3000);
                };
                
                // Refresh history list to include the newly saved conversation
                // This ensures the history panel shows the correct session_key
                if (state.historyLoaded) {
                    const refreshPromise = refreshHistorySessions(state);
                    
                    // If history panel is visible, wait for refresh to complete before clearing status
                    if (state.historyVisible && refreshPromise && typeof refreshPromise.then === 'function') {
                        refreshPromise.then(clearStatusAfterDelay).catch(function(error) {
                            if (window.console && console.error) {
                                console.error('Error refreshing history after save:', error);
                            }
                            clearStatusAfterDelay();
                        });
                        return;
                    }
                }
                
                clearStatusAfterDelay();
            } else {
                // Save failed
                const errorMsg = extractErrorMessage(result.error, 'Failed to save conversation');
                setStatus(state.container, getString('saveFailed', 'Failed to save conversation. See console for details.'));
                
                if (window.console && console.error) {
                    console.error('Failed to save conversation:', errorMsg);
                }
                
                setTimeout(function() {
                    domUpdateBatcher.schedule(function() {
                        clearStatus(state.container);
                    });
                }, 5000);
            }
        }).catch(function(error) {
            // Handle unexpected errors
            setStatus(state.container, getString('saveFailed', 'Failed to save conversation. See console for details.'));
            
            if (window.console && console.error) {
                console.error('Error saving conversation:', error);
            }
            
            setTimeout(function() {
                domUpdateBatcher.schedule(function() {
                    clearStatus(state.container);
                });
            }, 5000);
        });
    }

    function startNewConversation(state) {
        if (!state) {
            return;
        }

        // If there are messages, save to CCT before clearing to preserve session key
        if (state.conversation && state.conversation.length > 0) {
            const confirmMessage = getString('confirmClearConversation', 'Start a new conversation? Your current conversation will be saved automatically.');
            if (!confirm(confirmMessage)) {
                return;
            }

            // Mark as busy and disable form to prevent edits during save/clear sequence
            state.busy = true;
            disableForm(state, true);

            // Show saving status
            setStatus(state.container, getString('savingConversation', 'Saving current conversation...'));

            // Helper function to restore form state
            function restoreFormState() {
                state.busy = false;
                disableForm(state, false);
            }

            // Save to CCT before clearing to preserve session key
            saveConversationToCCT(state, { silent: false })
                .then(function(result) {
                    if (result && result.success) {
                        // Save succeeded - show success message briefly
                        setStatus(state.container, getString('conversationSaved', 'Conversation saved successfully.'));
                        setTimeout(function() {
                            performConversationClear(state);
                            restoreFormState();
                        }, 500);
                    } else if (result && result.skipped) {
                        // Save was skipped (e.g., no CCT endpoint configured) - clear anyway
                        performConversationClear(state);
                        restoreFormState();
                    } else {
                        // Save failed - ask user whether to proceed
                        const errorMsg = extractErrorMessage(result && result.error, 'Unknown error');
                        clearStatus(state.container);
                        
                        const proceedMessage = getString(
                            'saveFailed',
                            'Failed to save conversation: ' + errorMsg + '\n\nDo you want to clear the conversation anyway? (It will be lost)'
                        );
                        
                        if (confirm(proceedMessage)) {
                            // User chose to proceed despite save failure
                            performConversationClear(state);
                            restoreFormState();
                        } else {
                            // User chose to keep the conversation
                            setStatus(state.container, getString('conversationKept', 'Conversation kept. Please try again or use the Save button.'));
                            setTimeout(function() {
                                domUpdateBatcher.schedule(function() {
                                    clearStatus(state.container);
                                });
                            }, 3000);
                            restoreFormState();
                        }
                    }
                })
                .catch(function(error) {
                    // Unexpected error during save attempt
                    clearStatus(state.container);
                    const errorMsg = error && error.message ? error.message : 'Save failed';
                    
                    const proceedMessage = getString(
                        'saveError',
                        'Error saving conversation: ' + errorMsg + '\n\nDo you want to clear the conversation anyway? (It will be lost)'
                    );
                    
                    if (confirm(proceedMessage)) {
                        performConversationClear(state);
                        restoreFormState();
                    } else {
                        setStatus(state.container, getString('conversationKept', 'Conversation kept. Please try again or use the Save button.'));
                        setTimeout(function() {
                            domUpdateBatcher.schedule(function() {
                                clearStatus(state.container);
                            });
                        }, 3000);
                        restoreFormState();
                    }
                });
        } else {
            // No messages - just clear without saving
            performConversationClear(state);
        }
    }

    /**
     * Actually perform the conversation clear after saving is complete.
     * Extracted from startNewConversation to allow async save before clear.
     * 
     * @param {Object} state - Chat state object
     */
    function performConversationClear(state) {
        // Log clear operation
        if (window.console && console.log) {
            console.log('[WP oOS] Clearing conversation:', {
                session_key: state.config && state.config.sessionKey,
                message_count: state.conversation ? state.conversation.length : 0
            });
        }

        // Clear the conversation array
        state.conversation = [];

        // Clear localStorage
        clearConversationFromStorage(state);

        // Clear the messages UI
        if (state.messagesEl) {
            state.messagesEl.innerHTML = '';
        }

        // Clear the textarea
        if (state.textarea) {
            state.textarea.value = '';
        }

        // Clear pending attachments
        state.pendingAttachments = [];
        renderPendingAttachments(state);
        updateAttachButtonState(state);

        // Clear message bundling state and re-enable form if bundling was in progress
        if (state.messageBundleTimer) {
            clearTimeout(state.messageBundleTimer);
            state.messageBundleTimer = null;
            // Re-enable form since bundling was cancelled
            disableForm(state, false);
        }
        state.pendingMessageBundle = [];

        // Clear status message
        setStatus(state.container, '');

        // Collapse the transcript if expanded
        setTranscriptExpanded(state, false);

        // Generate a new session key for the new conversation
        let newSessionKey;
        
        if (typeof window !== 'undefined' && typeof window.crypto !== 'undefined' && crypto.randomUUID) {
            newSessionKey = crypto.randomUUID();
        } else if (typeof window !== 'undefined' && typeof window.crypto !== 'undefined' && crypto.getRandomValues) {
            // Use crypto.getRandomValues for secure random generation
            const array = new Uint8Array(16);
            crypto.getRandomValues(array);
            newSessionKey = 'wp-mcp-ai-session-' + Array.from(array, function(byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        } else {
            // Fallback for environments without crypto API (uses timestamp only)
            newSessionKey = 'wp-mcp-ai-session-' + Date.now();
        }

        if (state.config) {
            state.config.sessionKey = newSessionKey;
        }
    }

    function updateHistoryToggle(state) {
        if (!state || !state.historyToggle) {
            return;
        }

        const expanded = !!state.historyVisible;
        const label = expanded
            ? getString('historyToggleHide', 'Hide previous conversations')
            : getString('historyToggleShow', 'Show previous conversations');

        state.historyToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        state.historyToggle.setAttribute('aria-label', label);

        const screenReaderText = state.historyToggle.querySelector('.screen-reader-text');
        if (screenReaderText) {
            screenReaderText.textContent = label;
        }

        if (expanded) {
            state.historyToggle.classList.add('wp-mcp-ai-chat__history-toggle--active');
        } else {
            state.historyToggle.classList.remove('wp-mcp-ai-chat__history-toggle--active');
        }
    }

    function setHistoryStatus(state, message, isError) {
        if (!state || !state.historyStatus) {
            return;
        }

        if (!message) {
            state.historyStatus.textContent = '';
            state.historyStatus.hidden = true;
            state.historyStatus.classList.remove('wp-mcp-ai-chat__history-status--error');
            return;
        }

        state.historyStatus.hidden = false;
        state.historyStatus.textContent = message;

        if (isError) {
            state.historyStatus.classList.add('wp-mcp-ai-chat__history-status--error');
        } else {
            state.historyStatus.classList.remove('wp-mcp-ai-chat__history-status--error');
        }
    }

    function getHistoryEndpoint(state) {
        if (state && state.config && state.config.transcriptsEndpoint) {
            return state.config.transcriptsEndpoint;
        }

        if (globalConfig.transcriptsEndpoint) {
            return globalConfig.transcriptsEndpoint;
        }

        return '';
    }

    function buildHistoryHeaders(state) {
        const headers = { 'Accept': 'application/json' };
        let nonce = '';

        if (state && state.config) {
            if (state.config.restNonce) {
                nonce = state.config.restNonce;
            } else if (globalConfig.nonce) {
                nonce = globalConfig.nonce;
            }

            if (state.config.guestToken) {
                headers['X-WP-MCP-AI-Guest'] = state.config.guestToken;
            }
        } else if (globalConfig.nonce) {
            nonce = globalConfig.nonce;
        }

        if (nonce) {
            headers['X-WP-Nonce'] = nonce;
        }

        return headers;
    }

    function formatHistoryDate(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value);

        if (isNaN(date.getTime())) {
            return '';
        }

        if (typeof window !== 'undefined' && window.Intl && window.Intl.DateTimeFormat) {
            try {
                return new window.Intl.DateTimeFormat(undefined, {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                }).format(date);
            } catch (error) {}
        }

        if (date.toLocaleString) {
            return date.toLocaleString();
        }

        return date.toISOString();
    }

    function formatHistoryMessageCount(count) {
        const total = parseInt(count, 10) || 0;

        if (total === 1) {
            return getString('historySingleMessage', '1 message');
        }

        const template = getString('historyMessageCount', '%d messages');

        if (template.indexOf('%d') !== -1) {
            return template.replace('%d', total);
        }

        return template + ' ' + total;
    }

    function formatHistorySessionTitle(state, session, index) {
        if (session && session.preview) {
            return session.preview;
        }

        if (session && session.assistant_title) {
            return session.assistant_title;
        }

        const template = getString('historyPreviewFallback', 'Conversation %s');
        const placeholder = template.indexOf('%s') !== -1 ? template : template + ' %s';
        const number = typeof index === 'number' ? index + 1 : 1;

        return placeholder.replace('%s', number);
    }

    /**
     * Extract inline content data from tool result.
     * Used for tools that return base64-encoded content (e.g., generate_gemini_image).
     * 
     * @param {Object} result - Tool result object
     * @return {Object|null} Object with data and mime_type, or null if no inline content
     */
    function extractInlineContentData(result) {
        if (!result || typeof result !== 'object') {
            return null;
        }

        const content = result.content;
        if (!content || typeof content !== 'object') {
            return null;
        }

        let base64Data = '';
        let mimeType = '';

        // Extract base64 data from various possible formats
        if (typeof content.data === 'string' && content.data.trim()) {
            base64Data = content.data.trim();
        }

        // Extract MIME type
        if (typeof content.mime_type === 'string' && content.mime_type.trim()) {
            mimeType = content.mime_type.trim();
        }

        if (!base64Data) {
            return null;
        }

        return {
            data: base64Data,
            mime_type: mimeType
        };
    }

    /**
     * Truncate preview text to a maximum length with ellipsis.
     * 
     * @param {string} text - Text to truncate
     * @param {number} maxLength - Maximum length before truncation
     * @return {string} Truncated text
     */
    function truncatePreviewText(text, maxLength) {
        if (!text || typeof text !== 'string') {
            return '';
        }

        const trimmed = text.trim();
        
        if (trimmed.length <= maxLength) {
            return trimmed;
        }

        // Truncate at word boundary if possible
        let truncated = trimmed.substring(0, maxLength);
        const lastSpace = truncated.lastIndexOf(' ');
        
        if (lastSpace > maxLength * 0.7) {
            truncated = truncated.substring(0, lastSpace);
        }

        return truncated + '…';
    }

    function buildHistoryMeta(state, session) {
        const parts = [];

        if (session) {
            const timestamp = session.updated_at || session.completed_at || session.started_at;
            const formattedDate = formatHistoryDate(timestamp);

            if (formattedDate) {
                parts.push(formattedDate);
            }

            if (session.turn_count) {
                parts.push(formatHistoryMessageCount(session.turn_count));
            }
        }

        return parts.join(' · ');
    }

    function renderHistorySessions(state) {
        if (!state || !state.historyList) {
            return;
        }

        state.historyList.innerHTML = '';

        if (!Array.isArray(state.historySessions) || !state.historySessions.length) {
            return;
        }

        const fragment = document.createDocumentFragment();

        state.historySessions.forEach(function (session, index) {
            const item = document.createElement('li');
            item.className = 'wp-mcp-ai-chat__history-item';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'wp-mcp-ai-chat__history-session';
            button.dataset.sessionKey = session && session.session_key ? session.session_key : '';
            
            const sessionTitle = formatHistorySessionTitle(state, session, index);
            const metaText = buildHistoryMeta(state, session);
            const previewText = session && session.preview ? session.preview : '';
            const ariaLabel = metaText ? sessionTitle + ' - ' + metaText : sessionTitle;
            button.setAttribute('aria-label', getString('loadConversation', 'Load conversation') + ': ' + ariaLabel);

            const content = document.createElement('div');
            content.className = 'wp-mcp-ai-chat__history-session-content';

            // Add preview text as the main title if available
            if (previewText) {
                const preview = document.createElement('span');
                preview.className = 'wp-mcp-ai-chat__history-session-preview';
                preview.textContent = truncatePreviewText(previewText, 60);
                content.appendChild(preview);
            } else {
                const title = document.createElement('span');
                title.className = 'wp-mcp-ai-chat__history-session-title';
                title.textContent = sessionTitle;
                content.appendChild(title);
            }

            if (metaText) {
                const meta = document.createElement('span');
                meta.className = 'wp-mcp-ai-chat__history-session-meta';
                meta.textContent = metaText;
                content.appendChild(meta);
            }

            button.appendChild(content);

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'wp-mcp-ai-chat__history-delete';
            deleteButton.setAttribute('aria-label', getString('deleteConversation', 'Delete this conversation'));
            deleteButton.setAttribute('title', getString('deleteConversation', 'Delete this conversation'));
            deleteButton.innerHTML =
                '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
                '<path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />' +
                '</svg>';

            deleteButton.addEventListener('click', function (event) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }
                if (event && typeof event.stopPropagation === 'function') {
                    event.stopPropagation();
                }

                handleHistoryDelete(state, session, item);
            });

            item.appendChild(button);
            item.appendChild(deleteButton);

            button.addEventListener('click', function (event) {
                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }

                toggleHistorySession(state, item, session);
            });

            if (
                state.activeHistorySessionKey &&
                session &&
                session.session_key &&
                state.activeHistorySessionKey === session.session_key
            ) {
                item.classList.add('wp-mcp-ai-chat__history-item--active');
            }

            fragment.appendChild(item);
        });

        state.historyList.appendChild(fragment);
    }

    function handleHistoryDelete(state, session, item) {
        if (!state || !session || !session.session_key) {
            return;
        }

        const confirmMessage = getString(
            'confirmDeleteConversation',
            'Are you sure you want to delete this conversation? This action cannot be undone.'
        );

        if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
            if (!window.confirm(confirmMessage)) {
                return;
            }
        }

        const sessionKey = session.session_key;
        const endpoint = getHistoryEndpoint(state);

        if (!endpoint) {
            setHistoryStatus(state, getString('historyDeleteError', 'Unable to delete conversation.'), true);
            return;
        }

        const deleteUrl = endpoint + '/' + encodeURIComponent(sessionKey);

        // Log delete request
        if (window.console && console.log) {
            console.log('[WP oOS] Deleting conversation:', {
                session_key: sessionKey
            });
        }

        fetch(deleteUrl, {
            method: 'DELETE',
            headers: buildHistoryHeaders(state),
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return null;
                    })
                    .then(function (data) {
                        if (!response.ok) {
                            if (response.status === 401) {
                                throw new Error('unauthorized');
                            } else if (response.status === 404) {
                                throw new Error('not_found');
                            } else if (response.status >= 500) {
                                throw new Error('server_error');
                            }
                            throw new Error('delete_failed');
                        }
                        return data;
                    });
            })
            .then(function () {
                // Log successful delete
                if (window.console && console.log) {
                    console.log('[WP oOS] Conversation deleted successfully:', {
                        session_key: sessionKey
                    });
                }

                if (item && item.parentNode) {
                    item.parentNode.removeChild(item);
                }

                state.historySessions = state.historySessions.filter(function (s) {
                    return s.session_key !== sessionKey;
                });

                if (state.activeHistorySessionKey === sessionKey) {
                    state.activeHistorySessionKey = '';
                }

                if (state.historySessionDetails && state.historySessionDetails[sessionKey]) {
                    delete state.historySessionDetails[sessionKey];
                }

                setHistoryStatus(state, getString('historyDeleteSuccess', 'Conversation deleted successfully.'), false);

                setTimeout(function () {
                    setHistoryStatus(state, '', false);
                }, 3000);
            })
            .catch(function (error) {
                let errorMessage;
                
                if (error && error.message === 'unauthorized') {
                    errorMessage = getString('historyDeleteUnauthorized', 'You are not authorized to delete this conversation.');
                } else if (error && error.message === 'not_found') {
                    errorMessage = getString('historyDeleteNotFound', 'This conversation could not be found.');
                } else if (error && error.message === 'server_error') {
                    errorMessage = getString('historyDeleteServerError', 'A server error occurred. Please try again later.');
                } else {
                    errorMessage = getString('historyDeleteError', 'Unable to delete conversation.');
                }
                
                setHistoryStatus(state, errorMessage, true);
            });
    }

    function toggleHistoryVisibility(state) {
        if (!state) {
            return;
        }

        setHistoryVisibility(state, !state.historyVisible);
    }

    function setHistoryVisibility(state, visible) {
        if (!state) {
            return;
        }

        state.historyVisible = !!visible;

        if (state.historyContainer) {
            state.historyContainer.hidden = !state.historyVisible;
        }

        updateHistoryToggle(state);

        if (state.historyVisible) {
            ensureHistorySessions(state);
        }
    }

    function ensureHistorySessions(state) {
        if (!state) {
            return Promise.resolve();
        }

        if (state.historyLoaded || state.historyLoading) {
            return state.historyLoadPromise || Promise.resolve();
        }

        state.historyLoadPromise = loadHistorySessions(state, 1);
        return state.historyLoadPromise;
    }

    function refreshHistorySessions(state) {
        if (!state) {
            return Promise.resolve();
        }

        // Reset the loaded state to force a fresh fetch
        state.historyLoaded = false;
        state.historyCurrentPage = 0;
        state.historyLoadPromise = loadHistorySessions(state, 1);
        return state.historyLoadPromise;
    }

    function loadMoreHistorySessions(state) {
        if (!state || state.historyLoading) {
            return Promise.resolve();
        }

        const nextPage = state.historyCurrentPage + 1;
        state.historyLoadPromise = loadHistorySessions(state, nextPage);
        return state.historyLoadPromise;
    }

    function updateLoadMoreButton(state) {
        if (!state || !state.historyLoadMore) {
            return;
        }

        const loadedCount = state.historySessions.length;
        const totalCount = state.historyTotalSessions;
        const hasMore = loadedCount < totalCount;

        if (hasMore) {
            state.historyLoadMore.hidden = false;
        } else {
            state.historyLoadMore.hidden = true;
        }
    }

    function loadHistorySessions(state, page) {
        const endpoint = getHistoryEndpoint(state);

        if (!endpoint) {
            state.historyLoaded = true;
            setHistoryStatus(state, getString('historyError', 'Unable to load conversation history.'), true);
            state.historyLoadPromise = null;
            return Promise.resolve();
        }

        state.historyLoading = true;
        setHistoryStatus(state, getString('historyLoading', 'Loading conversations…'), false);

        let perPage = state.config && state.config.historyPerPage ? state.config.historyPerPage : globalConfig.historyPerPage;
        if (!perPage || perPage < 1) {
            perPage = 20;
        }
        state.historyPerPage = perPage;

        const currentPage = page || 1;

        return fetchHistorySessions(state, endpoint, perPage, currentPage)
            .then(function (data) {
                let sessions = Array.isArray(data && data.sessions) ? data.sessions : [];
                const assistantId = state.config && state.config.assistantId ? parseInt(state.config.assistantId, 10) : 0;

                if (assistantId) {
                    sessions = sessions.filter(function (session) {
                        return parseInt(session.assistant_id, 10) === assistantId;
                    });
                }

                // For page 1, replace sessions. For subsequent pages, append.
                if (currentPage === 1) {
                    state.historySessions = sessions;
                } else {
                    state.historySessions = state.historySessions.concat(sessions);
                }
                
                state.historyCurrentPage = currentPage;
                state.historyTotalSessions = data && typeof data.total === 'number' ? data.total : 0;
                state.historyLoaded = true;

                renderHistorySessions(state);
                updateLoadMoreButton(state);

                if (!state.historySessions.length) {
                    const message = data && data.message ? data.message : getString('historyEmpty', 'No previous conversations yet.');
                    setHistoryStatus(state, message, false);
                } else {
                    setHistoryStatus(state, '', false);
                }
            })
            .catch(function (error) {
                if (currentPage === 1) {
                    state.historySessions = [];
                }
                renderHistorySessions(state);

                const message = error && error.message ? error.message : getString('historyError', 'Unable to load conversation history.');
                setHistoryStatus(state, message, true);
                state.historyLoaded = false;
            })
            .finally(function () {
                state.historyLoading = false;
                state.historyLoadPromise = null;
            });
    }

    function fetchHistorySessions(state, endpoint, perPage, page) {
        let url = endpoint;
        
        // Add user_id parameter
        let userId = null;
        if (state && state.config && typeof state.config.userId !== 'undefined') {
            userId = state.config.userId;
        } else if (typeof globalConfig.currentUserId !== 'undefined') {
            userId = globalConfig.currentUserId;
        }
        
        if (userId !== null) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'user_id=' + encodeURIComponent(userId);
        }

        if (perPage && perPage > 0) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'per_page=' + encodeURIComponent(perPage);
        }

        if (page && page > 0) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'page=' + encodeURIComponent(page);
        }

        // Add assistant_id parameter
        let assistantId = null;
        if (state && state.config && typeof state.config.assistantId !== 'undefined') {
            assistantId = state.config.assistantId;
        }
        
        if (assistantId !== null) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'assistant_id=' + encodeURIComponent(assistantId);
        }

        // Log fetch history sessions request
        if (window.console && console.log) {
            console.log('[WP oOS] Loading conversation history:', {
                user_id: userId,
                assistant_id: assistantId,
                per_page: perPage,
                page: page,
                endpoint: endpoint
            });
        }

        return fetch(url, {
            method: 'GET',
            headers: buildHistoryHeaders(state),
            credentials: 'same-origin',
        }).then(function (response) {
            return response
                .json()
                .catch(function () {
                    return null;
                })
                .then(function (data) {
                    if (!response.ok) {
                        const message = data && data.message ? data.message : getString('historyError', 'Unable to load conversation history.');
                        const error = new Error(message);
                        error.status = response.status;
                        throw error;
                    }

                    return data;
                });
        });
    }

    function fetchHistorySessionDetails(state, sessionKey) {
        if (!sessionKey) {
            return Promise.reject(new Error(getString('historySessionError', 'Unable to load this conversation. Please try again.')));
        }

        const endpoint = getHistoryEndpoint(state);

        if (!endpoint) {
            return Promise.reject(new Error(getString('historySessionError', 'Unable to load this conversation. Please try again.')));
        }

        // Construct URL with session_key in path (not as query param)
        let url = endpoint;
        if (!url.endsWith('/')) {
            url += '/';
        }
        url += encodeURIComponent(sessionKey);
        
        // Add user_id parameter as query string
        let userId = null;
        if (state && state.config && typeof state.config.userId !== 'undefined') {
            userId = state.config.userId;
        } else if (typeof globalConfig.currentUserId !== 'undefined') {
            userId = globalConfig.currentUserId;
        }
        
        if (userId !== null) {
            url += '?user_id=' + encodeURIComponent(userId);
        }
        
        // Add assistant_id parameter as query string
        let assistantId = null;
        if (state && state.config && typeof state.config.assistantId !== 'undefined') {
            assistantId = state.config.assistantId;
        }
        
        if (assistantId !== null) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'assistant_id=' + encodeURIComponent(assistantId);
        }

        // Log load session request with full details
        if (window.console && console.log) {
            console.log('[WP oOS] Loading conversation details:', {
                session_key: sessionKey,
                url: url,
                user_id: userId,
                assistant_id: assistantId
            });
        }

        return fetch(url, {
            method: 'GET',
            headers: buildHistoryHeaders(state),
            credentials: 'same-origin',
        }).then(function (response) {
            // Log response status for debugging
            if (window.console && console.log) {
                console.log('[WP oOS] Conversation details response:', {
                    status: response.status,
                    ok: response.ok,
                    session_key: sessionKey
                });
            }

            return response
                .json()
                .catch(function (jsonError) {
                    if (window.console && console.error) {
                        console.error('[WP oOS] Failed to parse conversation details JSON:', jsonError);
                    }
                    return null;
                })
                .then(function (data) {
                    // Log parsed data for debugging
                    if (window.console && console.log) {
                        console.log('[WP oOS] Conversation details data:', {
                            has_session: !!(data && data.session),
                            has_message: !!(data && data.message),
                            session_key: sessionKey
                        });
                    }

                    if (!response.ok) {
                        const message = data && data.message ? data.message : getString('historySessionError', 'Unable to load this conversation. Please try again.');
                        const error = new Error(message);
                        error.status = response.status;
                        throw error;
                    }

                    if (data && data.session) {
                        // Log successful retrieval
                        if (window.console && console.log) {
                            console.log('[WP oOS] Conversation details loaded successfully:', {
                                session_key: sessionKey,
                                message_count: data.session.messages ? data.session.messages.length : 0
                            });
                        }
                        return data.session;
                    }

                    // Handle graceful degradation when transcript storage is unavailable
                    // (e.g., JetEngine not active) - session will be null with a message
                    if (data && data.session === null && data.message) {
                        throw new Error(data.message);
                    }

                    throw new Error(getString('historySessionError', 'Unable to load this conversation. Please try again.'));
                });
        }).catch(function (error) {
            // Log error for debugging (handles both network-level and application-level errors)
            if (window.console && console.error) {
                console.error('[WP oOS] Error fetching conversation details:', error);
            }
            // Re-throw the error to propagate it to the caller
            // Errors from the response handler above already have user-friendly messages
            throw error;
        });
    }

    function normaliseHistoryRole(role) {
        if (!role) {
            return '';
        }

        const normalised = String(role).toLowerCase();

        if (normalised === 'function' || normalised === 'tool_result' || normalised === 'observation') {
            return 'tool';
        }

        if (normalised === 'assistant' || normalised === 'user' || normalised === 'system' || normalised === 'tool') {
            return normalised;
        }

        return '';
    }

    function setActiveHistorySession(state, sessionKey, activeItem) {
        if (!state || !state.historyList) {
            return;
        }

        state.activeHistorySessionKey = sessionKey || '';

        const items = state.historyList.querySelectorAll('.wp-mcp-ai-chat__history-item');
        Array.prototype.forEach.call(items, function (node) {
            if (node === activeItem) {
                node.classList.add('wp-mcp-ai-chat__history-item--active');
            } else {
                node.classList.remove('wp-mcp-ai-chat__history-item--active');
            }
        });
    }

    function loadHistorySessionIntoChat(state, session, activeItem) {
        if (!state || !state.messagesEl) {
            if (window.console && console.error) {
                console.error('[WP oOS] Cannot load history session: missing state or messagesEl');
            }
            return;
        }

        if (!session || typeof session !== 'object') {
            if (window.console && console.error) {
                console.error('[WP oOS] Cannot load history session: invalid session data', session);
            }
            setActiveHistorySession(state, '', activeItem);
            setStatus(state.container, getString('historySessionError', 'Unable to load this conversation. Please try again.'));
            return;
        }

        // Save the current conversation before replacing it (if it has messages)
        if (state.conversation && state.conversation.length > 0) {
            saveConversationToStorage(state);
            // Also save to CCT to prevent message loss
            saveConversationToCCT(state);
        }

        const sessionKey = sanitizeSessionKey(session.session_key ? String(session.session_key) : '');
        
        if (window.console && console.log) {
            console.log('[WP oOS] Loading conversation into chat:', {
                session_key: sessionKey,
                message_count: session.messages ? session.messages.length : 0,
                assistant_id: session.assistant_id
            });
        }
        
        setActiveHistorySession(state, sessionKey, activeItem);

        if (sessionKey) {
            state.config.sessionKey = sessionKey;
        }

        // Note: We do NOT change state.config.assistantId here.
        // The widget's assistant ID should remain fixed to its original configuration.
        // We only update the session key to track which conversation is loaded.

        state.messagesEl.textContent = '';
        state.conversation = [];
        state.pendingAttachments = [];
        state.validationNotice = '';

        // Clear message bundling state when loading history and re-enable form if needed
        if (state.messageBundleTimer) {
            clearTimeout(state.messageBundleTimer);
            state.messageBundleTimer = null;
            // Re-enable form since bundling was cancelled
            disableForm(state, false);
        }
        state.pendingMessageBundle = [];

        renderPendingAttachments(state);
        updateAttachButtonState(state);

        if (state.textarea) {
            state.textarea.value = '';
        }

        const messages = Array.isArray(session.messages) ? session.messages : [];

        if (!messages.length) {
            appendMessage(state.messagesEl, 'system', {
                text: getString('historyNoMessages', 'No messages were saved for this conversation.'),
            }, false, { state: state });
            setTranscriptExpanded(state, true);
            setStatus(state.container, '');
            return;
        }

        messages.forEach(function (message) {
            if (!message || typeof message !== 'object') {
                return;
            }

            const role = normaliseHistoryRole(message.role);
            if (!role) {
                return;
            }

            let content = '';
            if (typeof message.content === 'string') {
                content = message.content;
            } else if (message.content && typeof message.content.text === 'string') {
                content = message.content.text;
            }

            const trimmedContent = typeof content === 'string' ? content : '';
            const hasContent = trimmedContent.trim() !== '';

            if (!hasContent && role !== 'tool') {
                return;
            }

            // Use display metadata if available for accurate reconstruction
            let payload;
            if (message.display && typeof message.display === 'object') {
                payload = message.display;
            } else {
                payload = { text: trimmedContent };
            }
            
            const allowMarkdown = role === 'assistant';

            // Pass state in options to ensure delete buttons are attached
            // For assistant messages, also pass speech info for TTS button
            const appendOptions = { state: state };
            if (role === 'assistant') {
                appendOptions.speech = { state: state, text: trimmedContent };
            }

            appendMessage(state.messagesEl, role, payload, allowMarkdown, appendOptions);
            // Only persist non-system messages (system bubbles are transient UI feedback)
            if ((hasContent || role === 'tool') && role !== 'system') {
                // Preserve the original message structure including display metadata
                state.conversation.push(message);
            }
        });

        // Save the loaded conversation to localStorage
        saveConversationToStorage(state);

        if (window.console && console.log) {
            console.log('[WP oOS] Conversation loaded successfully into chat:', {
                session_key: sessionKey,
                loaded_message_count: state.conversation.length
            });
        }

        setTranscriptExpanded(state, true);
        setStatus(state.container, '');
    }

    function toggleHistorySession(state, item, session) {
        if (!state || !item) {
            return;
        }

        const sessionKey = session && session.session_key ? session.session_key : '';

        // Check if we have cached session data
        if (sessionKey && state.historySessionDetails && state.historySessionDetails[sessionKey]) {
            const cachedSession = state.historySessionDetails[sessionKey];
            loadHistorySessionIntoChat(state, cachedSession, item);
            return;
        }

        // Show loading status in main chat
        setStatus(state.container, getString('historyLoading', 'Loading conversation...'));

        fetchHistorySessionDetails(state, sessionKey)
            .then(function (data) {
                if (window.console && console.log) {
                    console.log('[WP oOS] Successfully fetched conversation details, loading into chat:', {
                        session_key: sessionKey,
                        has_data: !!data
                    });
                }

                if (sessionKey) {
                    state.historySessionDetails[sessionKey] = data;
                }

                loadHistorySessionIntoChat(state, data, item);
            })
            .catch(function (error) {
                if (window.console && console.error) {
                    console.error('[WP oOS] Failed to load conversation details:', {
                        session_key: sessionKey,
                        error: error.message || error
                    });
                }
                const message = error && error.message ? error.message : getString('historySessionError', 'Unable to load this conversation. Please try again.');
                appendMessage(state.messagesEl, 'system', { text: message }, false, { state: state });
                // Clear status after showing error message in chat
                clearStatus(state.container);
            });
    }

    function buildJsonHeaders(state) {
        let nonce = '';
        
        // Priority: state.config.restNonce > globalConfig.nonce
        if (state && state.config && state.config.restNonce) {
            nonce = state.config.restNonce;
        } else if (globalConfig && globalConfig.nonce) {
            nonce = globalConfig.nonce;
        }
        
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        
        // Add nonce if available
        if (nonce) {
            headers['X-WP-Nonce'] = nonce;
        }

        // Add guest token if available
        if (state && state.config && state.config.guestToken) {
            headers['X-WP-MCP-AI-Guest'] = state.config.guestToken;
        }

        return headers;
    }

    function handleFileSelection(event, state) {
        if (!state || !state.canUploadAttachments) {
            return;
        }

        if (!event || !event.target || !event.target.files) {
            return;
        }

        state.validationNotice = '';

        const files = Array.prototype.slice.call(event.target.files);
        event.target.value = '';

        if (!files.length) {
            return;
        }

        const allowedFiles = [];
        const rejectedFiles = [];

        files.forEach(function (file) {
            if (isFileTypeAllowed(file, state)) {
                allowedFiles.push(file);
            } else {
                rejectedFiles.push(file);
            }
        });

        if (rejectedFiles.length) {
            let notice;

            if (rejectedFiles.length === 1) {
                const label = (rejectedFiles[0] && rejectedFiles[0].name) || getString('unsupportedFileLabel', 'This file');
                notice = formatString(
                    getString('unsupportedFileType', '“%s” is not a supported file type. Please choose a different file.'),
                    label
                );
            } else {
                notice = getString(
                    'unsupportedMultipleFiles',
                    'Some selected files are not supported. Please try different files.'
                );
            }

            state.validationNotice = notice;
            setStatus(state.container, notice);
        }

        if (!allowedFiles.length) {
            return;
        }

        let sequence = Promise.resolve();

        allowedFiles.forEach(function (file) {
            sequence = sequence.then(function () {
                return uploadAttachment(state, file);
            });
        });

        sequence.catch(function (error) {
            if (window.console && console.error) {
                console.error('Attachment upload failed', error);
            }
        });
    }

    function uploadAttachment(state, file) {
        if (!state || !state.canUploadAttachments) {
            return Promise.resolve();
        }

        if (!file || !state.config || !state.config.uploadEndpoint) {
            return Promise.resolve();
        }

        state.uploading = (state.uploading || 0) + 1;
        updateAttachButtonState(state);

        const message = formatString(getString('uploadingFile', 'Uploading “%s”…'), file.name || '');
        setStatus(state.container, message);

        let hadError = false;

        const headers = {
            'X-WP-Nonce': globalConfig.nonce || '',
            Accept: 'application/json',
        };

        const contentDisposition = createContentDispositionHeader(file.name || 'attachment');
        if (contentDisposition) {
            headers['Content-Disposition'] = contentDisposition;
        }

        headers['Content-Type'] = file.type || 'application/octet-stream';

        return fetch(state.config.uploadEndpoint, {
            method: 'POST',
            headers: headers,
            body: file,
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return null;
                    })
                    .then(function (data) {
                        if (!response.ok) {
                            const error = new Error('Upload failed');
                            error.response = response;
                            throw error;
                        }
                        return data;
                    });
            })
            .then(function (data) {
                const record = normaliseUploadResponse(data, file);
                if (!record) {
                    return;
                }

                state.pendingAttachments.push(record);
                state.attachmentLibrary[record.fileId] = record;
                renderPendingAttachments(state);
            })
            .catch(function (error) {
                hadError = true;
                handleUploadError(state, error);
            })
            .finally(function () {
                state.uploading = Math.max(0, (state.uploading || 1) - 1);
                updateAttachButtonState(state);

                if (!state.busy && state.uploading === 0) {
                    if (!hadError && state.validationNotice) {
                        setStatus(state.container, state.validationNotice);
                        state.validationNotice = '';
                    } else if (!hadError) {
                        setStatus(state.container, '');
                    }
                }
            });
    }

    function normaliseUploadResponse(data, file) {
        if (!data || typeof data !== 'object') {
            return null;
        }

        let id = data.id;
        if (!id && data.data && typeof data.data.id !== 'undefined') {
            id = data.data.id;
        }

        if (typeof id === 'undefined' || id === null) {
            return null;
        }

        const fileId = 'wp-attachment-' + id;
        let title = '';

        if (data.title) {
            if (typeof data.title === 'string') {
                title = data.title;
            } else if (typeof data.title.rendered === 'string') {
                title = data.title.rendered;
            }
        }

        if (!title && typeof data.slug === 'string') {
            title = data.slug;
        }

        const name = title || (file && file.name) || '';
        const url = data.source_url || (data.guid && data.guid.rendered) || '';
        const mime = data.mime_type || (file && file.type) || '';

        let size = null;
        if (data.media_details && typeof data.media_details === 'object') {
            if (typeof data.media_details.filesize === 'number') {
                size = data.media_details.filesize;
            }
        }

        if (size === null && typeof data.filesize === 'number') {
            size = data.filesize;
        }

        if (size === null && file && typeof file.size === 'number') {
            size = file.size;
        }

        const isImage = typeof mime === 'string' && mime.indexOf('image/') === 0;

        return {
            id: id,
            fileId: fileId,
            name: name || (file ? file.name : ''),
            originalName: file ? file.name : '',
            url: url,
            mime: mime,
            size: size,
            isImage: isImage,
        };
    }

    function handleUploadError(state, error) {
        if (error && error.response && typeof error.response.json === 'function') {
            error.response
                .json()
                .then(function (body) {
                    const message = body && (body.message || (body.data && body.data.message));
                    setStatus(state.container, message || getString('uploadError', 'The file could not be uploaded. Please try again.'));
                })
                .catch(function () {
                    setStatus(state.container, getString('uploadError', 'The file could not be uploaded. Please try again.'));
                });
        } else {
            setStatus(state.container, getString('uploadError', 'The file could not be uploaded. Please try again.'));
        }

        if (window.console && console.error) {
            console.error('File upload failed', error);
        }
    }

    function renderPendingAttachments(state) {
        const container = state.attachmentsContainer;
        const list = state.attachmentsList;

        if (!container || !list) {
            return;
        }

        list.innerHTML = '';

        if (!state.pendingAttachments.length) {
            container.hidden = true;
            return;
        }

        container.hidden = false;

        state.pendingAttachments.forEach(function (attachment) {
            const item = document.createElement('li');
            item.className = 'wp-mcp-ai-chat__attachments-item';

            const info = document.createElement('div');
            info.className = 'wp-mcp-ai-chat__attachments-info';

            const name = document.createElement('div');
            name.className = 'wp-mcp-ai-chat__attachments-name';
            name.textContent = attachment.name || attachment.originalName || getString('downloadAttachment', 'Download attachment');
            info.appendChild(name);

            const metaText = buildAttachmentMeta(attachment);
            if (metaText) {
                const meta = document.createElement('div');
                meta.className = 'wp-mcp-ai-chat__attachments-meta';
                meta.textContent = metaText;
                info.appendChild(meta);
            }

            item.appendChild(info);

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'wp-mcp-ai-chat__attachments-remove';
            removeButton.textContent = getString('removeAttachment', 'Remove');
            removeButton.addEventListener('click', function () {
                removePendingAttachment(state, attachment.fileId);
            });

            if (state.busy || state.uploading > 0) {
                removeButton.disabled = true;
            }

            item.appendChild(removeButton);
            list.appendChild(item);
        });
    }

    function removePendingAttachment(state, fileId) {
        if (!fileId) {
            return;
        }

        state.pendingAttachments = state.pendingAttachments.filter(function (attachment) {
            return attachment.fileId !== fileId;
        });

        renderPendingAttachments(state);
        updateAttachButtonState(state);
    }

    function updateAttachButtonState(state) {
        if (!state) {
            return;
        }

        if (!state.canUploadAttachments) {
            if (state.attachButton) {
                state.attachButton.disabled = true;
            }

            if (state.fileInput) {
                state.fileInput.disabled = true;
            }

            updateTranscribeButtonState(state);
            return;
        }

        const disabled = !!state.busy || state.uploading > 0;

        if (state.attachButton) {
            state.attachButton.disabled = disabled;
        }

        if (state.fileInput) {
            state.fileInput.disabled = disabled;
        }

        if (state.attachmentsList) {
            const removeButtons = state.attachmentsList.querySelectorAll('.wp-mcp-ai-chat__attachments-remove');
            Array.prototype.forEach.call(removeButtons, function (button) {
                button.disabled = disabled;
            });
        }

        updateTranscribeButtonState(state);
    }

    function buildDisplayAttachment(attachment, state) {
        if (!attachment || typeof attachment !== 'object') {
            return null;
        }

        let record = attachment;

        if (attachment.fileId && state && state.attachmentLibrary && state.attachmentLibrary[attachment.fileId]) {
            record = state.attachmentLibrary[attachment.fileId];
        }

        const url = getAttachmentUrlFromRecord(record, state) || attachment.url || '';
        if (!url) {
            return null;
        }

        let label = record.name || record.originalName || attachment.name || attachment.originalName || '';
        if (!label) {
            label = getString('downloadAttachment', 'Download attachment');
        }

        return {
            url: url,
            label: label,
            downloadName: record.originalName || record.name || '',
            meta: buildAttachmentMeta(record),
        };
    }

    function addAttachmentMetadataToSegment(segment, attachment) {
        if (!segment || typeof segment !== 'object') {
            return;
        }
        if (!attachment || typeof attachment !== 'object') {
            return;
        }
        
        // Include metadata for agentic workflow, following OpenAI image tool pattern
        // This includes URL, name, MIME type, and size for complete file context
        if (attachment.url) {
            segment.url = attachment.url;
        }
        if (attachment.name) {
            segment.name = attachment.name;
            // file_name matches OpenAI tool return format (only set when name exists)
            segment.file_name = attachment.name;
        }
        if (attachment.mime) {
            segment.mime_type = attachment.mime;
        }
        if (attachment.size) {
            segment.bytes = attachment.size;
        }
    }

    function createSegmentFromAttachment(attachment) {
        if (!attachment) {
            if (window.console && console.warn) {
                console.warn('[WP oOS] createSegmentFromAttachment: No attachment provided');
            }
            return null;
        }

        let id = attachment.id;

        if (!id && attachment.fileId && attachment.fileId.indexOf('wp-attachment-') === 0) {
            const parsed = parseInt(attachment.fileId.replace('wp-attachment-', ''), 10);
            if (!isNaN(parsed)) {
                id = parsed;
            }
        }

        if (!id) {
            if (window.console && console.warn) {
                console.warn('[WP oOS] createSegmentFromAttachment: No valid ID found in attachment:', {
                    has_id: !!attachment.id,
                    has_fileId: !!attachment.fileId,
                    fileId: attachment.fileId,
                    attachment: attachment
                });
            }
            return null;
        }

        const mime = attachment.mime || attachment.type || '';
        const isImage = typeof attachment.isImage === 'boolean' ? attachment.isImage : typeof mime === 'string' && mime.indexOf('image/') === 0;

        if (isImage) {
            const segment = {
                type: 'input_image',
                attachment_id: id,
            };
            
            addAttachmentMetadataToSegment(segment, attachment);
            return segment;
        }

        const segment = {
            type: 'input_file',
            attachment_id: id,
        };

        const displayName = attachment.originalName || attachment.name || '';
        if (displayName) {
            segment.display_name = displayName;
        }
        
        addAttachmentMetadataToSegment(segment, attachment);
        return segment;
    }

    function prepareAssistantDisplay(message, state) {
        let text = '';
        let attachments = [];

        if (message && typeof message.content !== 'undefined') {
            text = normaliseContent(message.content);
        }

        attachments = extractAttachmentsFromMessage(message, state);

        return {
            text: text,
            attachments: attachments,
        };
    }

    function extractAttachmentsFromMessage(message, state) {
        if (!message) {
            return [];
        }

        const lookup = buildAttachmentLookup(message, state);
        const attachments = [];
        const attachmentsByKey = {};
        const defaultAttachmentLabel = getString('downloadAttachment', 'Download attachment');

        function normaliseMeta(meta) {
            if (!meta) {
                return '';
            }

            return String(meta).replace(/\s+/g, ' ').trim();
        }

        function mergeMeta(extra, existing) {
            if (!extra) {
                return existing || '';
            }

            if (!existing) {
                return extra;
            }

            if (existing.indexOf(extra) !== -1) {
                return existing;
            }

            return extra + ' • ' + existing;
        }

        function registerAttachmentEntry(key, entry, extraMeta, fallbackLabel) {
            if (!key) {
                return;
            }

            const extra = normaliseMeta(extraMeta);
            const label = fallbackLabel ? String(fallbackLabel).trim() : '';
            const existing = attachmentsByKey[key];

            if (existing) {
                if (label && (!existing.label || existing.label === defaultAttachmentLabel)) {
                    existing.label = label;
                }

                if (extra) {
                    existing.meta = mergeMeta(extra, existing.meta);
                }

                return;
            }

            if (label) {
                entry.label = label;
            }

            if (extra) {
                entry.meta = mergeMeta(extra, entry.meta);
            }

            attachmentsByKey[key] = entry;
            attachments.push(entry);
        }

        function addAttachment(record, fallbackLabel, extraMeta) {
            if (!record) {
                return;
            }

            const url = getAttachmentUrlFromRecord(record, state);
            if (!url) {
                return;
            }

            const key = record.fileId || url;
            let label = record.name || record.originalName || record.downloadName || '';
            if (!label) {
                label = defaultAttachmentLabel;
            }

            registerAttachmentEntry(
                key,
                {
                    url: url,
                    label: label,
                    downloadName: record.downloadName || record.originalName || record.name || '',
                    meta: buildAttachmentMeta(record),
                },
                extraMeta,
                fallbackLabel
            );
        }

        function addRemoteAttachment(url, label, extraMeta) {
            if (!url) {
                return;
            }

            const key = 'url:' + url;
            registerAttachmentEntry(
                key,
                {
                    url: url,
                    label: label || defaultAttachmentLabel,
                    downloadName: '',
                    meta: '',
                },
                extraMeta,
                label
            );
        }

        function processAnnotationCandidate(candidate, fallbackLabel) {
            if (!candidate || typeof candidate !== 'object') {
                return;
            }

            if (Array.isArray(candidate)) {
                candidate.forEach(function (entry) {
                    processAnnotationCandidate(entry, fallbackLabel);
                });
                return;
            }

            let label = fallbackLabel || candidate.label || candidate.title || candidate.name || candidate.text || '';
            let extraMeta = candidate.quote || candidate.meta || '';
            let fileId = '';

            if (candidate.file_id) {
                fileId = candidate.file_id;
            } else if (candidate.id && String(candidate.id).indexOf('file-') === 0) {
                fileId = candidate.id;
            }

            if (!fileId && candidate.file && typeof candidate.file === 'object') {
                fileId = candidate.file.file_id || candidate.file.id || '';
                if (!label) {
                    label = candidate.file.display_name || candidate.file.filename || candidate.file.name || '';
                }

                if (!extraMeta && candidate.file.caption) {
                    extraMeta = candidate.file.caption;
                }
            }

            if (!fileId && candidate.file_citation && typeof candidate.file_citation === 'object') {
                fileId = candidate.file_citation.file_id || '';
                if (!extraMeta && candidate.file_citation.quote) {
                    extraMeta = candidate.file_citation.quote;
                }
            }

            const record = fileId ? lookup[fileId] : null;
            if (record) {
                addAttachment(record, label, extraMeta);
                return;
            }

            let url = candidate.url || (candidate.link && candidate.link.url) || (candidate.web && candidate.web.url) || '';
            if (!url && candidate.file && typeof candidate.file === 'object') {
                url = candidate.file.url || candidate.file.download_url || candidate.file.href || candidate.file.source_url || '';
            }

            if (url) {
                addRemoteAttachment(url, label, extraMeta);
            }

            if (candidate.attachments && Array.isArray(candidate.attachments)) {
                candidate.attachments.forEach(function (attachment) {
                    processAnnotationCandidate(attachment, label);
                });
            }
        }

        function processAnnotationCollection(collection) {
            if (!collection) {
                return;
            }

            if (Array.isArray(collection)) {
                collection.forEach(function (item) {
                    processAnnotationCandidate(item);
                });
                return;
            }

            processAnnotationCandidate(collection);
        }

        function attachToolResultAttachments(segment) {
            if (!segment || typeof segment !== 'object') {
                return;
            }

            let toolCallId = '';

            if (typeof segment.tool_call_id === 'string' && segment.tool_call_id) {
                toolCallId = segment.tool_call_id;
            } else if (typeof segment.call_id === 'string' && segment.call_id) {
                toolCallId = segment.call_id;
            } else if (typeof segment.id === 'string' && segment.id.indexOf('call_') === 0) {
                toolCallId = segment.id;
            }

            if (!toolCallId) {
                return;
            }

            let fallbackLabel = '';
            if (typeof segment.label === 'string' && segment.label.trim()) {
                fallbackLabel = segment.label.trim();
            } else if (typeof segment.title === 'string' && segment.title.trim()) {
                fallbackLabel = segment.title.trim();
            } else if (typeof segment.name === 'string' && segment.name.trim()) {
                fallbackLabel = segment.name.trim();
            } else if (typeof segment.tool_name === 'string' && segment.tool_name.trim()) {
                fallbackLabel = segment.tool_name.trim();
            }

            const lookupResult = findToolResultInConversation(state, toolCallId);
            if (!lookupResult || !lookupResult.result) {
                return;
            }

            const toolName = fallbackLabel || lookupResult.toolName || '';
            const normalised = normaliseToolResultForDisplay(toolName, lookupResult.result);

            if (!normalised || !normalised.attachments || !normalised.attachments.length) {
                return;
            }

            normalised.attachments.forEach(function (attachment, index) {
                if (!attachment || typeof attachment !== 'object' || !attachment.url) {
                    return;
                }

                const entry = {
                    url: attachment.url,
                    label: attachment.label || defaultAttachmentLabel,
                    downloadName: attachment.downloadName || '',
                    meta: attachment.meta || '',
                };

                registerAttachmentEntry(
                    'tool-call-' + toolCallId + '-' + index,
                    entry,
                    '',
                    fallbackLabel || toolName
                );
            });
        }

        function processSegment(segment) {
            if (segment === null || typeof segment === 'undefined') {
                return;
            }

            if (Array.isArray(segment)) {
                segment.forEach(processSegment);
                return;
            }

            if (typeof segment === 'string') {
                return;
            }

            if (typeof segment !== 'object') {
                return;
            }

            const type = typeof segment.type === 'string' ? segment.type : '';

            if (type === 'tool_result') {
                attachToolResultAttachments(segment);

                if (segment.content) {
                    processSegment(segment.content);
                }

                return;
            }

            if (segment.attachments && Array.isArray(segment.attachments)) {
                segment.attachments.forEach(processSegment);
            }

            if (segment.text && typeof segment.text === 'object' && segment.text.annotations) {
                processAnnotationCollection(segment.text.annotations);
            }

            processAnnotationCollection(segment.annotations);

            if (segment.metadata && typeof segment.metadata === 'object') {
                processAnnotationCollection(segment.metadata.annotations);
                processAnnotationCollection(segment.metadata.references);
                processAnnotationCollection(segment.metadata.citations);
            }

            processAnnotationCollection(segment.references);
            processAnnotationCollection(segment.citations);

            if (type === 'input_file' || type === 'output_file' || type === 'file_path' || type === 'file') {
                const fileId = segment.file_id || (segment.file && segment.file.id) || segment.id || '';
                if (fileId && lookup[fileId]) {
                    addAttachment(lookup[fileId], segment.display_name || (segment.file && segment.file.filename), segment.quote || '');
                }
                return;
            }

            if (type === 'input_image' || type === 'image_file' || type === 'output_image' || type === 'image_url') {
                let inlineFileId = segment.file_id || '';
                if (!inlineFileId) {
                    const imageFile = segment.image_file || segment.image || segment.file || null;
                    if (imageFile) {
                        inlineFileId = imageFile.file_id || imageFile.id || '';
                    }
                }

                if (inlineFileId && lookup[inlineFileId]) {
                    const imageMeta = segment.image_file || segment.image || segment.file || {};
                    addAttachment(lookup[inlineFileId], segment.caption || imageMeta.display_name || imageMeta.filename, segment.quote || '');
                    return;
                }

                let url = '';
                if (typeof segment.image_url === 'string') {
                    url = segment.image_url;
                } else if (segment.image_url && typeof segment.image_url.url === 'string') {
                    url = segment.image_url.url;
                } else if (typeof segment.url === 'string') {
                    url = segment.url;
                }
                if (url) {
                    addRemoteAttachment(url, segment.caption || '', segment.quote || '');
                }
                return;
            }

            if (segment.content) {
                processSegment(segment.content);
            }
        }

        processSegment(message.content);
        processAnnotationCollection(message.annotations);

        if (message.metadata && typeof message.metadata === 'object') {
            processAnnotationCollection(message.metadata.annotations);
            processAnnotationCollection(message.metadata.references);
            processAnnotationCollection(message.metadata.citations);
        }

        processAnnotationCollection(message.references);

        return attachments;
    }

    function buildAttachmentLookup(message, state) {
        const lookup = {};

        function shouldReplace(existing, candidate) {
            if (!existing) {
                return true;
            }

            if (!existing.url && candidate.url) {
                return true;
            }

            if (!existing.data && candidate.data) {
                return true;
            }

            if (!existing.name && candidate.name) {
                return true;
            }

            if (!existing.downloadName && candidate.downloadName) {
                return true;
            }

            return false;
        }

        function registerRecord(record) {
            if (!record || !record.fileId) {
                return;
            }

            let downloadUrl = record.url || '';
            if (!downloadUrl) {
                downloadUrl = buildFileDownloadUrl(state, record.fileId);
                if (downloadUrl) {
                    record.url = downloadUrl;
                }
            }

            if (!record.downloadName) {
                if (record.name) {
                    record.downloadName = record.name;
                } else {
                    record.downloadName = record.fileId;
                }
            }

            if (!lookup[record.fileId] || shouldReplace(lookup[record.fileId], record)) {
                lookup[record.fileId] = record;
            } else if (!lookup[record.fileId].url && downloadUrl) {
                lookup[record.fileId].url = downloadUrl;
            }

            if (state && state.attachmentLibrary) {
                const existing = state.attachmentLibrary[record.fileId];
                if (existing && !existing.url && downloadUrl) {
                    existing.url = downloadUrl;
                }
                if (!existing || shouldReplace(existing, record)) {
                    state.attachmentLibrary[record.fileId] = record;
                }
            }
        }

        if (message && Array.isArray(message.attachments)) {
            message.attachments.forEach(function (item) {
                const record = normaliseAttachmentRecord(item);
                if (record) {
                    registerRecord(record);
                }
            });
        }

        if (message && Array.isArray(message.references)) {
            message.references.forEach(function (reference) {
                if (!reference || typeof reference !== 'object') {
                    return;
                }

                let record = null;

                if (reference.file && typeof reference.file === 'object') {
                    record = normaliseAttachmentRecord(reference.file);
                }

                if (!record && reference.content && typeof reference.content === 'object') {
                    record = normaliseAttachmentRecord(reference.content);
                }

                if (!record) {
                    record = normaliseAttachmentRecord(reference);
                }

                if (!record && reference.file_citation && reference.file_citation.file_id) {
                    record = normaliseAttachmentRecord({ file_id: reference.file_citation.file_id });
                }

                if (record) {
                    registerRecord(record);
                }
            });
        }

        if (state && state.attachmentLibrary) {
            Object.keys(state.attachmentLibrary).forEach(function (key) {
                if (!lookup[key]) {
                    lookup[key] = state.attachmentLibrary[key];
                }
            });
        }

        return lookup;
    }

    function buildFileDownloadUrl(state, fileId) {
        if (!fileId) {
            return '';
        }

        let base = '';

        if (state && state.config && state.config.filesEndpoint) {
            base = state.config.filesEndpoint;
        } else if (globalConfig.filesEndpoint) {
            base = globalConfig.filesEndpoint;
        }

        if (!base) {
            return '';
        }

        if (base.charAt(base.length - 1) === '/') {
            base = base.slice(0, -1);
        }

        let url = base + '/' + encodeURIComponent(String(fileId)) + '/download';
        const params = [];

        if (state && state.config && state.config.assistantId) {
            params.push('assistant_id=' + encodeURIComponent(state.config.assistantId));
        }

        const guestToken = state && state.config ? state.config.guestToken : '';
        if (guestToken) {
            params.push('guest_token=' + encodeURIComponent(guestToken));
        } else {
            let nonce = '';
            if (state && state.config && state.config.restNonce) {
                nonce = state.config.restNonce;
            } else if (globalConfig.nonce) {
                nonce = globalConfig.nonce;
            }

            if (nonce) {
                params.push('_wpnonce=' + encodeURIComponent(nonce));
            }
        }

        if (params.length) {
            url += '?' + params.join('&');
        }

        return url;
    }

    function normaliseAttachmentRecord(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }

        function pickString() {
            for (let index = 0; index < arguments.length; index++) {
                const candidate = arguments[index];
                if (typeof candidate === 'string' && candidate) {
                    return candidate;
                }
            }

            return '';
        }

        let fileId = raw.file_id || raw.id || raw.fileId || raw.reference_id || '';
        if (!fileId && raw.image_file && raw.image_file.file_id) {
            fileId = raw.image_file.file_id;
        }

        if (!fileId && raw.file && typeof raw.file === 'object') {
            fileId = raw.file.file_id || raw.file.id || '';
        }

        if (!fileId) {
            return null;
        }

        let name = pickString(
            raw.display_name,
            raw.filename,
            raw.name,
            raw.label,
            raw.title && typeof raw.title === 'string' ? raw.title : ''
        );

        if (!name && raw.title && typeof raw.title.rendered === 'string') {
            name = raw.title.rendered;
        }

        if (!name) {
            name = pickString(raw.caption, raw.original_name);
        }

        let downloadName = pickString(
            raw.filename,
            raw.name,
            raw.download_name,
            raw.original_name,
            raw.display_name
        );

        let url = pickString(
            raw.url,
            raw.download_url,
            raw.href,
            raw.source_url,
            typeof raw.image_url === 'string' ? raw.image_url : '',
            raw.image_url && raw.image_url.url ? raw.image_url.url : '',
            typeof raw.video_url === 'string' ? raw.video_url : '',
            raw.video_url && raw.video_url.url ? raw.video_url.url : ''
        );

        let mime = pickString(raw.mime_type, raw.type, raw.mime);

        let size = null;
        if (typeof raw.bytes === 'number') {
            size = raw.bytes;
        } else if (typeof raw.size === 'number') {
            size = raw.size;
        }

        let data = '';
        if (typeof raw.data === 'string') {
            data = raw.data;
        } else if (raw.data && typeof raw.data.base64 === 'string') {
            data = raw.data.base64;
        }

        if (raw.file && typeof raw.file === 'object') {
            const fileEntry = raw.file;

            if (!name) {
                if (typeof fileEntry.display_name === 'string' && fileEntry.display_name) {
                    name = fileEntry.display_name;
                } else if (typeof fileEntry.filename === 'string' && fileEntry.filename) {
                    name = fileEntry.filename;
                } else if (typeof fileEntry.name === 'string' && fileEntry.name) {
                    name = fileEntry.name;
                } else if (typeof fileEntry.title === 'string' && fileEntry.title) {
                    name = fileEntry.title;
                }
            }

            if (!downloadName) {
                downloadName = pickString(fileEntry.filename, fileEntry.name, fileEntry.display_name);
            }

            if (!url) {
                url = pickString(fileEntry.url, fileEntry.download_url, fileEntry.href, fileEntry.source_url);
            }

            if (!mime) {
                mime = pickString(fileEntry.mime_type, fileEntry.type, fileEntry.content_type);
            }

            if (size === null) {
                if (typeof fileEntry.bytes === 'number') {
                    size = fileEntry.bytes;
                } else if (typeof fileEntry.size === 'number') {
                    size = fileEntry.size;
                }
            }

            if (!data) {
                if (typeof fileEntry.data === 'string') {
                    data = fileEntry.data;
                } else if (fileEntry.data && typeof fileEntry.data.base64 === 'string') {
                    data = fileEntry.data.base64;
                }
            }
        }

        if (!name && downloadName) {
            name = downloadName;
        }

        return {
            fileId: String(fileId),
            name: name,
            url: url,
            mime: mime,
            size: size,
            data: data,
            downloadName: downloadName,
        };
    }

    function getAttachmentUrlFromRecord(record, state) {
        if (!record) {
            return '';
        }

        if (record.url) {
            return record.url;
        }

        if (record.fileId) {
            const fallbackUrl = buildFileDownloadUrl(state, record.fileId);
            if (fallbackUrl) {
                record.url = fallbackUrl;
                return record.url;
            }
        }

        if (record.data) {
            const cacheKey = record.fileId || record.downloadName || '';
            if (cacheKey && state && state.attachmentBlobUrls && state.attachmentBlobUrls[cacheKey]) {
                return state.attachmentBlobUrls[cacheKey];
            }

            const objectUrl = createObjectUrlFromBase64(record.data, record.mime);
            if (objectUrl) {
                if (cacheKey && state && state.attachmentBlobUrls) {
                    state.attachmentBlobUrls[cacheKey] = objectUrl;
                }
                registerObjectUrl(objectUrl);
                return objectUrl;
            }
        }

        return '';
    }

    function createObjectUrlFromBase64(base64, mimeType) {
        try {
            const binary = atob(base64);
            const length = binary.length;
            const bytes = new Uint8Array(length);

            for (let index = 0; index < length; index++) {
                bytes[index] = binary.charCodeAt(index);
            }

            const blob = new Blob([bytes], { type: mimeType || 'application/octet-stream' });
            return URL.createObjectURL(blob);
        } catch (error) {
            if (window.console && console.error) {
                console.error('Failed to create object URL', error);
            }
            return '';
        }
    }

    function buildAttachmentMeta(record) {
        if (!record) {
            return '';
        }

        const parts = [];
        let size = null;

        if (typeof record.size === 'number') {
            size = record.size;
        } else if (typeof record.bytes === 'number') {
            size = record.bytes;
        }

        if (size && size > 0) {
            parts.push(formatBytes(size));
        }

        const mime = record.mime || record.mime_type || record.type;
        if (mime) {
            parts.push(mime);
        }

        // Include attachment_id if available (matching tool result format)
        const attachmentId = record.id || record.attachment_id;
        if (typeof attachmentId === 'number' || (typeof attachmentId === 'string' && attachmentId)) {
            parts.push('ID: ' + attachmentId);
        }

        return parts.join(' • ');
    }

    /**
     * Normalize chart output for display in chat.
     * Chart results include HTML content (from Chart.js visualization tools)
     * that should be rendered in a sandboxed iframe for security.
     * 
     * @param {Object} result - Chart result with output_format: 'chart' and html property
     * @return {Object} Normalized result with chartHtml property for iframe rendering
     */
    function normaliseChartResult(result) {
        if (!result || typeof result !== 'object') {
            return null;
        }

        const html = result.html || '';
        if (!html.trim()) {
            return null;
        }

        // Extract chart metadata for display
        const chartType = result.chart_type || 'chart';
        const chartTitle = result.chart_title || '';
        const width = result.width || 800;
        const height = result.height || 400;

        // Build text description
        let text = '';
        if (chartTitle) {
            text = chartTitle;
        } else {
            // Generate default title based on chart type
            const typeLabels = {
                'line': 'Line Chart',
                'bar': 'Bar Chart',
                'pie': 'Pie Chart',
                'doughnut': 'Doughnut Chart',
                'radar': 'Radar Chart',
                'polarArea': 'Polar Area Chart',
                'scatter': 'Scatter Chart',
                'bubble': 'Bubble Chart'
            };
            text = typeLabels[chartType] || 'Chart Visualization';
        }

        return {
            text: text,
            attachments: [],
            chartHtml: html,
            chartWidth: width,
            chartHeight: height,
            chartType: chartType,
            chartTitle: chartTitle
        };
    }

    function normaliseCrawl4aiResult(result) {
        if (!result) {
            return null;
        }

        // Handle string results (JSON string responses) - parse them first
        if (typeof result === 'string') {
            try {
                result = JSON.parse(result);
            } catch (e) {
                // Not valid JSON, return as text
                return {
                    text: result,
                    attachments: []
                };
            }
        }

        // After parsing, check if result is now a valid object
        if (typeof result !== 'object') {
            return null;
        }

        // Extract results array - handle nested structures
        let results = [];
        if (Array.isArray(result.results)) {
            results = result.results;
        } else if (result.raw && Array.isArray(result.raw.results)) {
            // Check raw field for results
            results = result.raw.results;
        } else if (Array.isArray(result)) {
            // Handle case where result itself is the array
            results = result;
        }

        if (!results.length) {
            // No results yet, show status message
            const status = result.status || 'unknown';
            const taskId = result.task_id || '';
            let text = 'Crawl job status: ' + status;
            if (taskId) {
                text += ' (Task ID: ' + taskId + ')';
            }
            
            // Include error information if present
            if (result.metadata && result.metadata.error) {
                const errorMsg = extractErrorMessage(result.metadata.error, '');
                if (errorMsg) {
                    text += '\nError: ' + errorMsg;
                }
            }
            
            return {
                text: text,
                attachments: []
            };
        }

        // Build display from crawled results
        const textParts = [];
        const status = result.status || 'completed';

        // Add summary header
        const urlCount = results.length;
        let summaryText = `Crawled ${urlCount} ${urlCount === 1 ? 'page' : 'pages'}`;
        if (status !== 'completed') {
            summaryText += ` (Status: ${status})`;
        }
        textParts.push(summaryText);

        // Process each crawled result
        results.forEach(function(crawlResult, index) {
            if (!crawlResult || typeof crawlResult !== 'object') {
                return;
            }

            const resultParts = [];
            
            // Add URL with status code
            const url = crawlResult.url || '';
            const statusCode = crawlResult.status_code;
            if (url) {
                let urlLine = `**URL ${index + 1}:** ${url}`;
                if (statusCode) {
                    urlLine += ` (HTTP ${statusCode})`;
                }
                resultParts.push(urlLine);
            }

            // Add content type if available
            const contentType = crawlResult.content_type;
            if (contentType) {
                resultParts.push('**Content-Type:** ' + contentType);
            }

            // Add the main content - prefer markdown, fall back to text
            let content = '';
            if (crawlResult.markdown && typeof crawlResult.markdown === 'string') {
                content = crawlResult.markdown.trim();
            } else if (crawlResult.text && typeof crawlResult.text === 'string') {
                content = crawlResult.text.trim();
            }

            if (content) {
                // Limit content length for very long pages
                if (content.length > CRAWL4AI_MAX_CONTENT_LENGTH) {
                    content = content.substring(0, CRAWL4AI_MAX_CONTENT_LENGTH) + `\n\n[Content truncated - ${content.length} characters total]`;
                }
                resultParts.push('\n' + content);
            }

            if (resultParts.length > 0) {
                textParts.push('\n---\n' + resultParts.join('\n'));
            }
        });

        // Add metadata if available
        const metadata = result.metadata;
        if (metadata && typeof metadata === 'object') {
            const metaParts = [];
            
            if (metadata.mode) {
                metaParts.push('Mode: ' + metadata.mode);
            }
            
            if (metadata.fetched_at || metadata.queued_at) {
                const timestamp = metadata.fetched_at || metadata.queued_at;
                metaParts.push('Timestamp: ' + timestamp);
            }

            // Add truncation notice if content was truncated
            if (metadata.truncated === true) {
                const tokenLimit = metadata.approximate_token_limit || 'unknown';
                metaParts.push('⚠️ Content truncated (token limit: ' + tokenLimit + ')');
            }

            if (metaParts.length > 0) {
                textParts.push('\n*' + metaParts.join(' • ') + '*');
            }
        }

        const crawlResult = {
            text: textParts.join('\n\n'),
            attachments: []
        };

        // Preserve usage and cost data for token cost display (Phase 7 Week 5-6)
        // Crawl4AI results may include usage data from the original result object
        if (result.usage && typeof result.usage === 'object') {
            crawlResult.usage = result.usage;
        }
        if (result.cost && typeof result.cost === 'object') {
            crawlResult.cost = result.cost;
        }
        if (result.provider && typeof result.provider === 'string') {
            crawlResult.provider = result.provider;
        }
        if (result.model && typeof result.model === 'string') {
            crawlResult.model = result.model;
        }

        return crawlResult;
    }

    /**
     * Check if a result object has the structure of a site health response.
     * 
     * Site health responses have both a 'summary' object and a 'tests' object.
     * 
     * @param {*} result - Tool result to check
     * @return {boolean} True if result appears to be a site health response
     */
    function isSiteHealthStructure(result) {
        return result && 
               typeof result === 'object' &&
               typeof result.summary === 'object' && 
               result.summary !== null &&
               typeof result.tests === 'object' && 
               result.tests !== null;
    }

    /**
     * Extract site health summary from get_site_health tool result.
     * 
     * Formats the summary counts and badge information into a readable string.
     * Example: "Site Health: 0 critical, 1 warning, 18 passing [Performance, Security]"
     * 
     * @param {Object} result - Tool result object with summary and tests properties
     * @return {string|null} Formatted summary text or null if structure is invalid
     */
    function extractSiteHealthSummary(result) {
        if (!isSiteHealthStructure(result)) {
            return null;
        }

        const summary = result.summary;
        const tests = result.tests;
        const parts = [];

        // Build summary count string
        if (typeof summary.critical === 'number') {
            parts.push(summary.critical + ' critical');
        }
        if (typeof summary.warning === 'number') {
            parts.push(summary.warning + ' warning' + (summary.warning !== 1 ? 's' : ''));
        }
        if (typeof summary.pass === 'number') {
            parts.push(summary.pass + ' passing');
        }

        if (parts.length === 0) {
            return null;
        }

        let text = 'Site Health: ' + parts.join(', ');

        // Extract badge information from tests
        const badgeLabels = extractSiteHealthBadges(tests);
        if (badgeLabels.length > 0) {
            text += ' [' + badgeLabels.join(', ') + ']';
        }

        return text;
    }

    /**
     * Extract unique badge labels from site health tests.
     * 
     * Collects badge labels from critical and warning tests, returning
     * only unique values to avoid duplication in the summary.
     * 
     * @param {Object} tests - Tests object containing critical, warning, and pass arrays
     * @return {Array<string>} Array of unique badge labels
     */
    function extractSiteHealthBadges(tests) {
        if (!tests || typeof tests !== 'object') {
            return [];
        }

        const badgeInfo = [];

        // Check for badges in critical tests
        if (Array.isArray(tests.critical)) {
            tests.critical.forEach(function(test) {
                if (test && test.badge && typeof test.badge.label === 'string' && test.badge.label.trim()) {
                    badgeInfo.push(test.badge.label.trim());
                }
            });
        }

        // Check for badges in warning tests
        if (Array.isArray(tests.warning)) {
            tests.warning.forEach(function(test) {
                if (test && test.badge && typeof test.badge.label === 'string' && test.badge.label.trim()) {
                    badgeInfo.push(test.badge.label.trim());
                }
            });
        }

        // Return unique badge labels
        const uniqueBadges = [];
        badgeInfo.forEach(function(badge) {
            if (uniqueBadges.indexOf(badge) === -1) {
                uniqueBadges.push(badge);
            }
        });

        return uniqueBadges;
    }

    /**
     * Check if a result object has the structure of a site security response.
     * 
     * Site security responses have a 'summary' object and a 'checks' object.
     * 
     * @param {*} result - Tool result to check
     * @return {boolean} True if result appears to be a site security response
     */
    function isSiteSecurityStructure(result) {
        return result && 
               typeof result === 'object' &&
               typeof result.summary === 'object' && 
               result.summary !== null &&
               typeof result.checks === 'object' && 
               result.checks !== null;
    }

    /**
     * Extract site security summary from check_site_security tool result.
     * 
     * Formats the summary counts and risk level into a readable string.
     * Example: "Security: LOW risk - 0 critical, 1 warning, 7 passing"
     * 
     * @param {Object} result - Tool result object with summary and checks properties
     * @return {string|null} Formatted summary text or null if structure is invalid
     */
    function extractSiteSecuritySummary(result) {
        if (!isSiteSecurityStructure(result)) {
            return null;
        }

        const summary = result.summary;
        const parts = [];

        // Build summary count string
        if (typeof summary.critical === 'number') {
            parts.push(summary.critical + ' critical');
        }
        if (typeof summary.warning === 'number') {
            parts.push(summary.warning + ' warning' + (summary.warning !== 1 ? 's' : ''));
        }
        if (typeof summary.pass === 'number') {
            parts.push(summary.pass + ' passing');
        }

        if (parts.length === 0) {
            return null;
        }

        // Add risk level if available
        let text = 'Security';
        if (typeof result.risk_level === 'string' && result.risk_level.trim()) {
            text += ': ' + result.risk_level.toUpperCase() + ' risk';
        }
        text += ' - ' + parts.join(', ');

        return text;
    }

    /**
     * Check if a result object has the structure of an update status response.
     * 
     * Update status responses have a 'summary' object and a 'components' object.
     * 
     * @param {*} result - Tool result to check
     * @return {boolean} True if result appears to be an update status response
     */
    function isUpdateStatusStructure(result) {
        return result && 
               typeof result === 'object' &&
               typeof result.summary === 'object' && 
               result.summary !== null &&
               typeof result.components === 'object' && 
               result.components !== null;
    }

    /**
     * Extract update status summary from get_update_status tool result.
     * 
     * Formats the update counts into a readable string.
     * Example: "Updates: 5 total (1 core, 3 plugins, 1 theme)"
     * 
     * @param {Object} result - Tool result object with summary and components properties
     * @return {string|null} Formatted summary text or null if structure is invalid
     */
    function extractUpdateStatusSummary(result) {
        if (!isUpdateStatusStructure(result)) {
            return null;
        }

        const summary = result.summary;
        
        // Check if there are any updates
        const total = typeof summary.total === 'number' ? summary.total : 0;
        
        if (total === 0) {
            return 'Updates: No updates available';
        }

        const parts = [];

        // Build component breakdown
        if (typeof summary.core === 'number' && summary.core > 0) {
            parts.push(summary.core + ' core');
        }
        if (typeof summary.plugins === 'number' && summary.plugins > 0) {
            parts.push(summary.plugins + ' plugin' + (summary.plugins !== 1 ? 's' : ''));
        }
        if (typeof summary.themes === 'number' && summary.themes > 0) {
            parts.push(summary.themes + ' theme' + (summary.themes !== 1 ? 's' : ''));
        }

        let text = 'Updates: ' + total + ' total';
        if (parts.length > 0) {
            text += ' (' + parts.join(', ') + ')';
        }

        return text;
    }

    /**
     * Check if a result object has the structure of a video metadata response.
     * 
     * Video metadata responses have a 'format' object with duration and other technical details.
     * 
     * @param {*} result - Tool result to check
     * @return {boolean} True if result appears to be a video metadata response
     */
    function isVideoMetadataStructure(result) {
        return result && 
               typeof result === 'object' &&
               typeof result.format === 'object' && 
               result.format !== null;
    }

    /**
     * Extract video metadata summary from get_video_metadata tool result.
     * 
     * Formats the video technical details into a readable string.
     * Example: "Video: 1920x1080, 00:02:30, 15.5 MB, h264"
     * 
     * @param {Object} result - Tool result object with format property
     * @return {string|null} Formatted summary text or null if structure is invalid
     */
    function extractVideoMetadataSummary(result) {
        if (!isVideoMetadataStructure(result)) {
            return null;
        }

        const format = result.format;
        const parts = [];

        // Extract dimensions from video streams if available
        if (Array.isArray(result.video_streams) && result.video_streams.length > 0) {
            const firstVideo = result.video_streams[0];
            if (typeof firstVideo.width === 'number' && typeof firstVideo.height === 'number') {
                parts.push(firstVideo.width + 'x' + firstVideo.height);
            }
        }

        // Add duration if available
        if (typeof format.duration_formatted === 'string' && format.duration_formatted.trim()) {
            parts.push(format.duration_formatted);
        } else if (typeof format.duration === 'number' && format.duration > 0) {
            parts.push(Math.floor(format.duration) + 's');
        }

        // Add file size if available
        if (typeof format.size_formatted === 'string' && format.size_formatted.trim()) {
            parts.push(format.size_formatted);
        }

        // Add format name if available
        if (typeof format.format_name === 'string' && format.format_name.trim()) {
            parts.push(format.format_name);
        }

        if (parts.length === 0) {
            return 'Video metadata retrieved';
        }

        return 'Video: ' + parts.join(', ');
    }

    /**
     * Check if a result object has the structure of an environment status response.
     * 
     * Environment status responses have environment, plugin, and assistants objects.
     * 
     * @param {*} result - Tool result to check
     * @return {boolean} True if result appears to be an environment status response
     */
    function isEnvironmentStatusStructure(result) {
        return result && 
               typeof result === 'object' &&
               typeof result.environment === 'object' && 
               result.environment !== null &&
               typeof result.plugin === 'object' && 
               result.plugin !== null;
    }

    /**
     * Extract environment status summary from get_environment_status tool result.
     * 
     * Formats the environment information into a readable string.
     * Example: "Environment: WordPress 6.4, PHP 8.1, WP oOS v1.0.0, 3 assistants, 2 warnings"
     * 
     * @param {Object} result - Tool result object with environment and plugin properties
     * @return {string|null} Formatted summary text or null if structure is invalid
     */
    function extractEnvironmentStatusSummary(result) {
        if (!isEnvironmentStatusStructure(result)) {
            return null;
        }

        const env = result.environment;
        const plugin = result.plugin;
        const parts = [];

        // Add WordPress version
        if (typeof env.wordpress_version === 'string' && env.wordpress_version.trim()) {
            parts.push('WordPress ' + env.wordpress_version);
        }

        // Add PHP version
        if (typeof env.php_version === 'string' && env.php_version.trim()) {
            parts.push('PHP ' + env.php_version);
        }

        // Add plugin version
        if (typeof plugin.version === 'string' && plugin.version.trim()) {
            parts.push('WP oOS ' + plugin.version);
        }

        // Add assistant count
        if (result.assistants && typeof result.assistants.total_assistants === 'number') {
            parts.push(result.assistants.total_assistants + ' assistant' + (result.assistants.total_assistants !== 1 ? 's' : ''));
        }

        // Add warnings count if any
        if (Array.isArray(result.warnings) && result.warnings.length > 0) {
            parts.push(result.warnings.length + ' warning' + (result.warnings.length !== 1 ? 's' : ''));
        }

        if (parts.length === 0) {
            return 'Environment status retrieved';
        }

        return 'Environment: ' + parts.join(', ');
    }

    /**
     * Check if a result object has the structure of a web search response.
     * 
     * Web search responses have a 'query' string, a 'results' array, and a 'provider' string.
     * 
     * @param {*} result - Tool result to check
     * @return {boolean} True if result appears to be a web search response
     */
    function isWebSearchStructure(result) {
        return result && 
               typeof result === 'object' &&
               typeof result.query === 'string' &&
               Array.isArray(result.results) &&
               typeof result.provider === 'string';
    }

    /**
     * Extract web search summary from web_search tool result.
     * 
     * Formats the search results into a readable summary with links.
     * Example: "Found 3 results for 'example query' (via DuckDuckGo)" with clickable links
     * 
     * @param {Object} result - Tool result object with query, results, and provider properties
     * @return {Object|null} Object with text and attachments (links) or null if structure is invalid
     */
    function extractWebSearchSummary(result) {
        if (!isWebSearchStructure(result)) {
            return null;
        }

        const query = result.query || '';
        const results = result.results || [];
        const provider = result.provider || 'search engine';
        const resultCount = results.length;

        let text = '';
        const links = [];

        // Build summary text
        if (resultCount === 0) {
            if (result.note && typeof result.note === 'string') {
                text = result.note;
            } else {
                text = 'No web search results found for "' + query + '"';
            }
        } else {
            // Format provider name nicely
            let providerName = provider;
            if (provider === 'duckduckgo') {
                providerName = 'DuckDuckGo';
            } else if (provider === 'brave') {
                providerName = 'Brave Search';
            }
            
            text = 'Found ' + resultCount + ' result' + (resultCount !== 1 ? 's' : '') + 
                   ' for "' + query + '" (via ' + providerName + ')';

            // Add top results as links
            results.forEach(function(item, index) {
                if (item && typeof item.url === 'string' && item.url.trim()) {
                    const linkLabel = item.title && typeof item.title === 'string' && item.title.trim() 
                        ? item.title.trim() 
                        : 'Result ' + (index + 1);
                    
                    links.push({
                        url: item.url.trim(),
                        label: linkLabel,
                        snippet: item.snippet && typeof item.snippet === 'string' ? item.snippet.trim() : ''
                    });
                }
            });
        }

        return {
            text: text,
            links: links
        };
    }

    /**
     * Extract displayable content from generic tool responses that don't have downloadable assets.
     * Looks for common fields like message, text, links, IDs, and status information.
     *
     * @param {Object} result Tool result object
     * @returns {Object|null} Normalized response with text and/or attachments, or null if nothing to extract
     */
    function extractGenericToolResponse(result) {
        if (!result || typeof result !== 'object') {
            return null;
        }

        let text = '';
        const links = [];

        // Extract both message and text fields when available
        const hasMessage = typeof result.message === 'string' && result.message.trim();
        const hasText = typeof result.text === 'string' && result.text.trim();
        
        if (hasMessage && hasText) {
            // Both fields exist - combine them if different
            const messageStr = result.message.trim();
            const textStr = result.text.trim();
            if (messageStr !== textStr) {
                text = messageStr + '\n\n' + textStr;
            } else {
                text = messageStr;
            }
        } else if (hasMessage) {
            text = result.message.trim();
        } else if (hasText) {
            text = result.text.trim();
        } else if (typeof result.summary === 'string' && result.summary.trim()) {
            text = result.summary.trim();
        } else if (isSiteHealthStructure(result)) {
            // Handle get_site_health tool result structure with summary object and tests
            const siteHealthText = extractSiteHealthSummary(result);
            if (siteHealthText) {
                text = siteHealthText;
            }
        } else if (isSiteSecurityStructure(result)) {
            // Handle check_site_security tool result structure with summary object and checks
            const siteSecurityText = extractSiteSecuritySummary(result);
            if (siteSecurityText) {
                text = siteSecurityText;
            }
        } else if (isUpdateStatusStructure(result)) {
            // Handle get_update_status tool result structure with summary object and components
            const updateStatusText = extractUpdateStatusSummary(result);
            if (updateStatusText) {
                text = updateStatusText;
            }
        } else if (isVideoMetadataStructure(result)) {
            // Handle get_video_metadata tool result structure with format object
            const videoMetadataText = extractVideoMetadataSummary(result);
            if (videoMetadataText) {
                text = videoMetadataText;
            }
        } else if (isEnvironmentStatusStructure(result)) {
            // Handle get_environment_status tool result structure
            const environmentText = extractEnvironmentStatusSummary(result);
            if (environmentText) {
                text = environmentText;
            }
        } else if (isWebSearchStructure(result)) {
            // Handle web_search tool result structure with query, results array, and provider
            const webSearchData = extractWebSearchSummary(result);
            if (webSearchData) {
                text = webSearchData.text;
                // Add search result links to the links array
                if (webSearchData.links && Array.isArray(webSearchData.links)) {
                    webSearchData.links.forEach(function(link) {
                        links.push({
                            url: link.url,
                            label: link.label,
                            type: 'search_result',
                            snippet: link.snippet || ''
                        });
                    });
                }
            }
        } else if (typeof result.title === 'string' && result.title.trim()) {
            text = result.title.trim();
        } else if (Array.isArray(result.notices) && result.notices.length > 0) {
            // Some tools return 'notices' array with messages
            const firstNotice = result.notices.find(function(notice) {
                return typeof notice === 'string' && notice.trim().length > 0;
            });
            if (firstNotice) {
                text = firstNotice.trim();
                if (result.notices.length > 1) {
                    text += ' (+' + (result.notices.length - 1) + ' more)';
                }
            }
        } else if (Array.isArray(result.messages) && result.messages.length > 0) {
            // Some tools return 'messages' array
            const firstMessage = result.messages.find(function(msg) {
                return typeof msg === 'string' && msg.trim().length > 0;
            });
            if (firstMessage) {
                text = firstMessage.trim();
                if (result.messages.length > 1) {
                    text += ' (+' + (result.messages.length - 1) + ' more)';
                }
            }
        }

        // Extract actionable links (permalink, edit_link, link, etc.)
        if (typeof result.permalink === 'string' && result.permalink.trim()) {
            links.push({
                url: result.permalink.trim(),
                label: 'View',
                type: 'permalink'
            });
        }

        if (typeof result.edit_link === 'string' && result.edit_link.trim()) {
            links.push({
                url: result.edit_link.trim(),
                label: 'Edit',
                type: 'edit_link'
            });
        }

        if (typeof result.edit_url === 'string' && result.edit_url.trim()) {
            links.push({
                url: result.edit_url.trim(),
                label: 'Edit',
                type: 'edit_url'
            });
        }

        if (typeof result.link === 'string' && result.link.trim()) {
            links.push({
                url: result.link.trim(),
                label: 'Open Link',
                type: 'link'
            });
        }

        if (typeof result.htmlLink === 'string' && result.htmlLink.trim()) {
            links.push({
                url: result.htmlLink.trim(),
                label: 'View',
                type: 'htmlLink'
            });
        }

        // Extract identifiers and add to text if we have a message
        const identifiers = [];
        if (typeof result.ID === 'number' || typeof result.ID === 'string') {
            identifiers.push('ID: ' + result.ID);
        }
        if (typeof result.post_id === 'number' || typeof result.post_id === 'string') {
            identifiers.push('Post ID: ' + result.post_id);
        }
        if (typeof result.attachment_id === 'number' || typeof result.attachment_id === 'string') {
            identifiers.push('Attachment ID: ' + result.attachment_id);
        }

        // Append identifiers to text if available
        if (identifiers.length > 0) {
            if (text) {
                text += ' (' + identifiers.join(', ') + ')';
            } else {
                text = identifiers.join(', ');
            }
        }

        // Extract status information if we don't have text yet
        if (!text) {
            if (result.sent === true || result.success === true || result.ok === true) {
                text = 'Operation completed successfully.';
            } else if (result.created === true) {
                text = 'Resource created successfully.';
            } else if (result.updated === true) {
                text = 'Resource updated successfully.';
            }
        }

        // If we still don't have any extractable content, return null
        if (!text && links.length === 0) {
            return null;
        }

        // Format links as attachments-style for consistent display
        const attachments = links.map(function(link) {
            return {
                url: link.url,
                label: link.label,
                downloadName: '',
                meta: link.type
            };
        });

        const genericResult = {
            text: text,
            attachments: attachments.length > 0 ? attachments : []
        };

        // Preserve usage and cost data for token cost display (Phase 7 Week 5-6)
        if (result.usage && typeof result.usage === 'object') {
            genericResult.usage = result.usage;
        }
        if (result.cost && typeof result.cost === 'object') {
            genericResult.cost = result.cost;
        }
        if (result.provider && typeof result.provider === 'string') {
            genericResult.provider = result.provider;
        }
        if (result.model && typeof result.model === 'string') {
            genericResult.model = result.model;
        }

        return genericResult;
    }

    /**
     * Async tool status values that indicate a tool is still processing.
     * Used for checking if a tool result represents a pending background job.
     */
    const ASYNC_PENDING_STATUSES = ['pending', 'queued', 'running'];

    /**
     * Parse tool result content from JSON string or return as-is if already an object.
     * @param {string|Object} content - The tool result content to parse
     * @return {Object|string} Parsed content object or original string if parsing fails
     */
    function parseToolResultContent(content) {
        if (typeof content === 'string') {
            try {
                return JSON.parse(content);
            } catch (e) {
                // If parsing fails, return original string
                return content;
            }
        }
        return content;
    }

    /**
     * Check if a parsed tool result represents an async pending job.
     * 
     * The system supports both sync and async tool execution:
     * - Sync tools: Execute immediately and return complete results
     * - Async tools: Return {async: true, status: 'pending', job_id: '...'} for background execution
     * - Background-only tools (e.g., Veo video): MUST run async even in agentic loops
     * 
     * @param {Object} parsedContent - The parsed tool result content
     * @return {boolean} True if the tool is async and still pending/processing
     */
    function isAsyncPendingToolResult(parsedContent) {
        return parsedContent && 
               parsedContent.async === true && 
               ASYNC_PENDING_STATUSES.indexOf(parsedContent.status) !== -1 && 
               parsedContent.job_id;
    }

    /**
     * Start polling for an async tool result.
     * Displays the initial message if provided, then calls waitForAsyncToolResult.
     * 
     * This function handles background-only tools (e.g., Veo video generation) that
     * return immediately with a pending status. The actual result is retrieved via:
     * 1. SSE (Server-Sent Events) - preferred, real-time updates
     * 2. REST polling - fallback when SSE is not available
     * 
     * The job_id format is typically: async_{uuid} for orchestrated jobs
     * or veo_{operation_name} for direct video generation jobs.
     * 
     * @param {Object} state Chat state object
     * @param {Object} parsedContent Parsed tool result content with job_id
     * @param {string} toolName Tool name for display purposes
     */
    function startAsyncToolPolling(state, parsedContent, toolName, toolCallId) {
        if (!parsedContent || !parsedContent.job_id) {
            if (window.console && console.warn) {
                console.warn('[WP oOS] startAsyncToolPolling called without valid job_id:', {
                    hasContent: !!parsedContent,
                    jobId: parsedContent ? parsedContent.job_id : undefined,
                    toolName: toolName
                });
            }
            return;
        }
        
        // Log async job initiation for observability
        // This helps debug long-running operations like video generation (60-180 seconds)
        if (window.console && console.log) {
            console.log('[WP oOS] Starting async tool polling:', {
                jobId: parsedContent.job_id,
                toolName: toolName,
                toolCallId: toolCallId || '(not provided)',
                assistantId: state.originalAssistantId || (state.config && state.config.assistantId) || '(not set)',
                status: parsedContent.status,
                hasMessage: !!parsedContent.message
            });
        }
        
        // Display initial message and video placeholder for video generation
        // For video generation jobs, show a placeholder video element that will become active
        // when the video file is created (typically within 5 minutes).
        // Use a generic property-based check (expected_url + .mp4 filename) to avoid tight coupling.
        var isVideoPending = parsedContent.expected_url && 
                             parsedContent.expected_filename && 
                             parsedContent.expected_filename.indexOf('.mp4') !== -1;
        
        if (isVideoPending) {
            // Create a message payload with the video placeholder as an attachment
            // This uses the standard attachment rendering which includes video player support
            var videoPlaceholderPayload = {
                text: parsedContent.message || getString('videoGenerating', 'Video generation started. Your video will be available within approximately 5 minutes.'),
                attachments: [{
                    url: parsedContent.expected_url,
                    label: parsedContent.expected_filename || 'Video (generating...)',
                    downloadName: parsedContent.expected_filename || 'video.mp4',
                    meta: getString('videoPending', 'Pending • ~5 min')
                }]
            };
            appendMessage(state.messagesEl, 'system', videoPlaceholderPayload, false, { state: state });
        } else if (parsedContent.message) {
            // Display initial message if provided (non-video tools)
            appendMessage(state.messagesEl, 'system', parsedContent.message, false, { state: state });
        }
        
        // Start polling for the async result (SSE-first with REST fallback)
        // toolCallId is required to ensure the completed result has the correct ID for API compatibility
        // Without it, the conversation would have mismatched tool_call_ids causing API errors
        waitForAsyncToolResult(state, parsedContent.job_id, toolName, toolCallId).catch(function (error) {
            if (window.console && console.error) {
                console.error('[WP oOS] Async tool polling failed:', error);
            }
        });
    }

    /**
     * Normalise list_jetengine_rest_routes tool result for display.
     * Converts the routes array into a formatted list with descriptions.
     * 
     * @param {Object} result - Result object with namespace and routes array
     * @return {Object|null} Normalized result with text, or null if empty
     */
    function normaliseJetEngineRoutesResult(result) {
        if (!result || typeof result !== 'object') {
            return null;
        }

        const routes = result.routes;
        if (!Array.isArray(routes) || routes.length === 0) {
            return {
                text: 'No JetEngine REST routes found.',
                attachments: []
            };
        }

        const namespace = result.namespace || 'jet-engine/v2';
        const lines = ['Available JetEngine REST API Routes (' + namespace + '):'];
        lines.push('');

        routes.forEach(function(route, index) {
            if (!route || typeof route !== 'object') {
                return;
            }

            const path = route.path || '';
            const methods = Array.isArray(route.methods) ? route.methods.join(', ') : '';
            const description = route.description || '';

            // Format: 1. GET /search-posts/
            lines.push((index + 1) + '. ' + methods + ' ' + path);
            
            // Add description if available
            if (description) {
                lines.push('   ' + description);
            }

            // Add additional requirements if available
            if (Array.isArray(route.additional_requirements) && route.additional_requirements.length > 0) {
                route.additional_requirements.forEach(function(req) {
                    lines.push('   • ' + req);
                });
            }

            // Add spacing between routes
            if (index < routes.length - 1) {
                lines.push('');
            }
        });

        return {
            text: lines.join('\n'),
            attachments: []
        };
    }

    /**
     * Normalise array tool results for display (e.g., from search_attachments).
     * Converts an array of attachment objects into a formatted display with downloadable links.
     * 
     * @param {Array} resultArray - Array of attachment objects
     * @param {string} toolName - Name of the tool that returned the array
     * @return {Object|null} Normalized result with text and attachments, or null if empty
     */
    function normaliseArrayToolResult(resultArray, toolName) {
        if (!Array.isArray(resultArray) || resultArray.length === 0) {
            return null;
        }

        // Handle search_attachments results specifically
        // Each attachment has: id, title, mime_type, download_url, permalink, filesize_human, uploaded_at
        if (toolName === 'search_attachments') {
            const attachments = [];
            const attachmentTitles = [];

            resultArray.forEach(function(item, index) {
                if (!item || typeof item !== 'object') {
                    return;
                }

                const title = item.title || 'Untitled';
                const downloadUrl = item.download_url || item.url || '';
                const permalink = item.permalink || '';
                const mimeType = item.mime_type || '';
                const filesize = item.filesize_human || '';
                const uploadedAt = item.uploaded_at || '';

                // Build metadata string
                const metaParts = [];
                if (mimeType) {
                    const mimeTypeParts = mimeType.split('/');
                    if (mimeTypeParts.length > 0) {
                        metaParts.push(mimeTypeParts[mimeTypeParts.length - 1].toUpperCase());
                    }
                }
                if (filesize) {
                    metaParts.push(filesize);
                }
                if (uploadedAt) {
                    const uploadDate = new Date(uploadedAt);
                    if (!isNaN(uploadDate.getTime())) {
                        metaParts.push('Uploaded: ' + uploadDate.toISOString().split('T')[0]);
                    }
                }

                const meta = metaParts.join(' • ');

                // Add to attachments list if we have a download URL
                if (downloadUrl) {
                    attachments.push({
                        url: downloadUrl,
                        label: title,
                        downloadName: '',
                        meta: meta
                    });
                    attachmentTitles.push(title);
                }

                // Also add permalink if different from download URL
                if (permalink && permalink !== downloadUrl) {
                    attachments.push({
                        url: permalink,
                        label: 'View Details',
                        downloadName: '',
                        meta: ''
                    });
                }
            });

            if (attachments.length === 0) {
                return {
                    text: 'No attachments found.',
                    attachments: []
                };
            }

            const text = 'Here are the last ' + resultArray.length + ' attachments from the media library:';

            return {
                text: text,
                attachments: attachments
            };
        }

        // Generic array handling for other tools
        // Build a simple list from the array
        const items = resultArray.map(function(item, index) {
            if (typeof item === 'string') {
                return item;
            }
            if (item && typeof item === 'object') {
                return item.title || item.name || item.message || item.text || JSON.stringify(item);
            }
            return String(item);
        }).filter(function(item) {
            return item && item.trim();
        });

        if (items.length === 0) {
            return null;
        }

        return {
            text: items.join('\n'),
            attachments: []
        };
    }

    function normaliseToolResultForDisplay(toolName, result) {
        // Handle string results (e.g., truncated tool results from orchestration layer)
        // When a tool result is too large, the orchestration layer converts it to a truncated
        // JSON string with a marker. We need to display this string to the user.
        if (typeof result === 'string' && result.trim()) {
            return {
                text: result,
                attachments: []
            };
        }

        if (!result || typeof result !== 'object') {
            return null;
        }

        // Skip async pending tool results - they are handled by waitForAsyncToolResult
        // and should not be displayed as completed results in the chat UI.
        // This prevents the "Tool is processing in the background" message from being
        // shown after the video generation has already completed.
        if (isAsyncPendingToolResult(result)) {
            return null;
        }

        // Handle array results (e.g., from search_attachments tool)
        // Arrays of attachment objects should be formatted as downloadable links
        if (Array.isArray(result)) {
            return normaliseArrayToolResult(result, toolName);
        }

        // Special handling for chart output format (e.g., get_open_meteo_forecast with output_format: 'chart')
        // Chart responses include HTML content that should be rendered in an iframe
        if (result.output_format === 'chart' && typeof result.html === 'string' && result.html.trim()) {
            return normaliseChartResult(result);
        }

        // Special handling for run_crawl4ai_job tool
        if (toolName === 'run_crawl4ai_job') {
            return normaliseCrawl4aiResult(result);
        }

        // Special handling for list_jetengine_rest_routes tool
        // This tool returns an object with a 'routes' array that needs formatting
        if (toolName === 'list_jetengine_rest_routes') {
            return normaliseJetEngineRoutesResult(result);
        }

        const nestedImage = result && result.image && typeof result.image === 'object' ? result.image : null;
        // Check for video_url structure (similar to image_url for generate_veo_video)
        const nestedVideo = result && result.video_url && typeof result.video_url === 'object' ? result.video_url : null;

        let url = '';
        if (typeof result.url === 'string' && result.url.trim()) {
            url = result.url.trim();
        } else if (typeof result.download_url === 'string' && result.download_url.trim()) {
            url = result.download_url.trim();
        } else if (typeof result.downloadUrl === 'string' && result.downloadUrl.trim()) {
            url = result.downloadUrl.trim();
        } else if (nestedVideo) {
            // Handle video_url structure from generate_veo_video
            if (typeof nestedVideo.url === 'string' && nestedVideo.url.trim()) {
                url = nestedVideo.url.trim();
            }
        } else if (nestedImage) {
            if (typeof nestedImage.url === 'string' && nestedImage.url.trim()) {
                url = nestedImage.url.trim();
            } else if (typeof nestedImage.download_url === 'string' && nestedImage.download_url.trim()) {
                url = nestedImage.download_url.trim();
            } else if (typeof nestedImage.downloadUrl === 'string' && nestedImage.downloadUrl.trim()) {
                url = nestedImage.downloadUrl.trim();
            }
        }

        // Check for inline content with base64 data (e.g., from generate_gemini_image)
        const inlineContent = extractInlineContentData(result);
        
        // If no URL found and no inline content, try generic extraction for tools that return structured data
        if (!url && !inlineContent) {
            return extractGenericToolResponse(result);
        }

        const attachments = [];
        let label = '';

        if (typeof result.title === 'string' && result.title.trim()) {
            label = result.title.trim();
        } else if (typeof result.file_name === 'string' && result.file_name.trim()) {
            label = result.file_name.trim();
        } else if (typeof result.fileName === 'string' && result.fileName.trim()) {
            label = result.fileName.trim();
        } else if (nestedImage) {
            if (typeof nestedImage.title === 'string' && nestedImage.title.trim()) {
                label = nestedImage.title.trim();
            } else if (typeof nestedImage.file_name === 'string' && nestedImage.file_name.trim()) {
                label = nestedImage.file_name.trim();
            } else if (typeof nestedImage.fileName === 'string' && nestedImage.fileName.trim()) {
                label = nestedImage.fileName.trim();
            }
        }

        const metaParts = [];
        const metaRecord = {
            bytes: typeof result.bytes === 'number' ? result.bytes : null,
            mime_type: result.mime_type || result.mimeType || '',
            attachment_id: result.attachment_id || null,
        };

        if (metaRecord.bytes === null && nestedImage && typeof nestedImage.bytes === 'number') {
            metaRecord.bytes = nestedImage.bytes;
        }

        if (!metaRecord.mime_type && nestedImage) {
            metaRecord.mime_type = nestedImage.mime_type || nestedImage.mimeType || '';
        }

        if (!metaRecord.attachment_id && nestedImage) {
            metaRecord.attachment_id = nestedImage.attachment_id || null;
        }

        const baseMeta = buildAttachmentMeta(metaRecord);
        if (baseMeta) {
            metaParts.push(baseMeta);
        }

        let sizeValue = '';
        if (typeof result.size === 'string' && result.size.trim()) {
            sizeValue = result.size.trim();
        } else if (nestedImage && typeof nestedImage.size === 'string' && nestedImage.size.trim()) {
            sizeValue = nestedImage.size.trim();
        }

        let qualityValue = '';
        if (typeof result.quality === 'string' && result.quality.trim()) {
            qualityValue = result.quality.trim();
        } else if (nestedImage && typeof nestedImage.quality === 'string' && nestedImage.quality.trim()) {
            qualityValue = nestedImage.quality.trim();
        }

        let aspectRatioValue = '';
        if (typeof result.aspect_ratio === 'string' && result.aspect_ratio.trim()) {
            aspectRatioValue = result.aspect_ratio.trim();
        } else if (nestedImage && typeof nestedImage.aspect_ratio === 'string' && nestedImage.aspect_ratio.trim()) {
            aspectRatioValue = nestedImage.aspect_ratio.trim();
        }

        let formatValue = '';
        if (typeof result.format === 'string' && result.format.trim()) {
            formatValue = result.format.trim();
        } else if (nestedImage && typeof nestedImage.format === 'string' && nestedImage.format.trim()) {
            formatValue = nestedImage.format.trim();
        }

        if (toolName === 'generate_openai_image') {
            if (sizeValue) {
                metaParts.push(sizeValue);
            }

            if (qualityValue) {
                metaParts.push(qualityValue);
            }
        } else if (toolName === 'generate_gemini_image' || toolName === 'edit_gemini_image') {
            if (aspectRatioValue) {
                metaParts.push(aspectRatioValue);
            }

            if (formatValue) {
                metaParts.push(formatValue.toUpperCase());
            }
        } else if (toolName === SPEECH_TOOL_NAME) {
            if (typeof result.duration_formatted === 'string' && result.duration_formatted.trim()) {
                metaParts.push(result.duration_formatted.trim());
            }

            if (formatValue) {
                metaParts.push(formatValue.toUpperCase());
            }
        } else if (toolName === 'generate_veo_video') {
            // Video-specific metadata for Veo videos
            if (typeof result.duration === 'number') {
                metaParts.push(result.duration + 's');
            }
            
            if (aspectRatioValue) {
                metaParts.push(aspectRatioValue);
            }
            
            if (typeof result.resolution === 'string' && result.resolution.trim()) {
                metaParts.push(result.resolution.trim());
            }
            
            if (typeof result.model === 'string' && result.model.trim()) {
                const modelName = result.model.trim();
                // Extract clean model name (e.g., "veo-3.1-generate-preview" -> "Veo 3.1")
                if (modelName.indexOf('veo-3.1') !== -1) {
                    metaParts.push('Veo 3.1');
                } else if (modelName.indexOf('veo-2') !== -1) {
                    metaParts.push('Veo 2.0');
                } else {
                    metaParts.push(modelName);
                }
            }
        }

        const attachmentMeta = metaParts.join(' • ');

        let downloadName = '';
        if (typeof result.file_name === 'string' && result.file_name.trim()) {
            downloadName = result.file_name.trim();
        } else if (typeof result.fileName === 'string' && result.fileName.trim()) {
            downloadName = result.fileName.trim();
        } else if (nestedImage) {
            if (typeof nestedImage.file_name === 'string' && nestedImage.file_name.trim()) {
                downloadName = nestedImage.file_name.trim();
            } else if (typeof nestedImage.fileName === 'string' && nestedImage.fileName.trim()) {
                downloadName = nestedImage.fileName.trim();
            }
        }

        if (!label && downloadName) {
            label = downloadName;
        }

        // Build attachment entry with URL or inline content
        const attachmentEntry = {
            url: url,
            label: label || getString('downloadAttachment', 'Download attachment'),
            downloadName: downloadName,
            meta: attachmentMeta,
        };

        // If we have inline content data and no URL, create a blob URL from the base64 data
        // This allows the attachment to be displayed in the chat UI
        if (inlineContent && !url) {
            const blobUrl = createObjectUrlFromBase64(inlineContent.data, inlineContent.mime_type);
            if (blobUrl) {
                attachmentEntry.url = blobUrl;
                registerObjectUrl(blobUrl);
            }
        }

        attachments.push(attachmentEntry);

        if (!attachments.length) {
            return null;
        }

        let text = '';

        if (typeof result.message === 'string' && result.message.trim()) {
            text = result.message.trim();
        } else if (typeof result.text === 'string' && result.text.trim()) {
            text = result.text.trim();
        } else if (toolName === 'generate_openai_image') {
            text = getString('imageToolSuccess', 'Image saved to the Media Library.');
        } else if (toolName === 'generate_gemini_image') {
            text = getString('geminiImageToolSuccess', 'Gemini image saved to the Media Library.');
        } else if (toolName === 'edit_gemini_image') {
            text = getString('editGeminiImageToolSuccess', 'Gemini image edited and saved to the Media Library.');
        } else if (toolName === SPEECH_TOOL_NAME) {
            text = getString('speechToolSuccess', 'Speech audio saved to the Media Library.');
        } else if (toolName === 'generate_veo_video') {
            text = getString('veoVideoToolSuccess', 'Video generated successfully and saved to the Media Library.');
        }

        // Extract usage and cost data for token cost display (Phase 7 Week 5-6)
        const normalizedResult = {
            text: text,
            attachments: attachments,
        };

        // Preserve usage data if present in tool result
        if (result.usage && typeof result.usage === 'object') {
            normalizedResult.usage = result.usage;
        }

        // Preserve cost data if present in tool result
        if (result.cost && typeof result.cost === 'object') {
            normalizedResult.cost = result.cost;
        }

        // Preserve provider and model for cost calculation
        if (result.provider && typeof result.provider === 'string') {
            normalizedResult.provider = result.provider;
        }
        if (result.model && typeof result.model === 'string') {
            normalizedResult.model = result.model;
        }

        return normalizedResult;
    }

    function parseToolMessagePayload(content, seen) {
        if (content === null || typeof content === 'undefined') {
            return null;
        }

        if (typeof content === 'string') {
            const trimmed = content.trim();

            if (!trimmed) {
                return null;
            }

            try {
                return JSON.parse(trimmed);
            } catch (error) {
                return null;
            }
        }

        let visited = seen || null;
        const shouldTrack = typeof content === 'object';

        if (shouldTrack && content !== null) {
            if (!visited && typeof WeakSet === 'function') {
                visited = new WeakSet();
            }

            if (visited) {
                if (visited.has(content)) {
                    return null;
                }

                visited.add(content);
            }
        }

        if (Array.isArray(content)) {
            for (let index = 0; index < content.length; index++) {
                const parsedItem = parseToolMessagePayload(content[index], visited);
                if (parsedItem) {
                    return parsedItem;
                }
            }

            return null;
        }

        if (typeof content !== 'object') {
            return null;
        }

        if (
            Object.prototype.hasOwnProperty.call(content, 'url') ||
            Object.prototype.hasOwnProperty.call(content, 'download_url') ||
            Object.prototype.hasOwnProperty.call(content, 'downloadUrl')
        ) {
            return content;
        }

        if (typeof content.text === 'string') {
            return parseToolMessagePayload(content.text, visited);
        }

        if (content.text && typeof content.text.value === 'string') {
            return parseToolMessagePayload(content.text.value, visited);
        }

        if (typeof content.content !== 'undefined') {
            return parseToolMessagePayload(content.content, visited);
        }

        if (typeof content.value === 'string') {
            return parseToolMessagePayload(content.value, visited);
        }

        if (typeof content.result !== 'undefined') {
            return parseToolMessagePayload(content.result, visited);
        }

        const keys = Object.keys(content);
        for (let i = 0; i < keys.length; i++) {
            const key = keys[i];
            if (!Object.prototype.hasOwnProperty.call(content, key)) {
                continue;
            }

            const candidate = content[key];
            if (candidate && typeof candidate === 'object') {
                const parsed = parseToolMessagePayload(candidate, visited);
                if (parsed) {
                    return parsed;
                }
            }
        }

        return null;
    }

    function findToolResultInConversation(state, toolCallId) {
        if (!state || !Array.isArray(state.conversation) || !toolCallId) {
            return null;
        }

        for (let index = state.conversation.length - 1; index >= 0; index--) {
            const entry = state.conversation[index];
            if (!entry || entry.role !== 'tool') {
                continue;
            }

            let entryCallId = '';

            if (typeof entry.tool_call_id === 'string' && entry.tool_call_id) {
                entryCallId = entry.tool_call_id;
            } else if (entry.metadata && typeof entry.metadata.tool_call_id === 'string') {
                entryCallId = entry.metadata.tool_call_id;
            }

            if (entryCallId !== toolCallId) {
                continue;
            }

            let payload = parseToolMessagePayload(entry.content);

            if (!payload && typeof entry.content === 'object' && entry.content !== null) {
                payload = parseToolMessagePayload({ content: entry.content });
            }

            if (!payload && typeof entry.text === 'string') {
                payload = parseToolMessagePayload(entry.text);
            }

            if (!payload) {
                continue;
            }

            let toolName = '';
            if (typeof entry.name === 'string' && entry.name.trim()) {
                toolName = entry.name.trim();
            }

            return {
                result: payload,
                toolName: toolName,
            };
        }

        return null;
    }

    function parseNumberValue(value) {
        if (typeof value === 'number' && isFinite(value)) {
            return value;
        }

        if (typeof value === 'string') {
            const parsed = parseFloat(value);
            if (!isNaN(parsed) && isFinite(parsed)) {
                return parsed;
            }
        }

        return NaN;
    }

    function getCrawl4aiPollDelay(metadata, state) {
        const poll = metadata && Object.prototype.hasOwnProperty.call(metadata, 'poll_interval') ? metadata.poll_interval : null;
        const parsed = parseNumberValue(poll);
        if (isNaN(parsed) || parsed <= 0) {
            let fallback = state && state.config ? parseInt(state.config.crawl4aiDefaultPollMs, 10) : 0;
            if (!fallback || fallback < 1000) {
                fallback = 5000;
            }
            return fallback;
        }

        return Math.max(1000, Math.round(parsed * 1000));
    }

    function getCrawl4aiTimeout(metadata) {
        const timeout = metadata && Object.prototype.hasOwnProperty.call(metadata, 'wait_timeout') ? metadata.wait_timeout : null;
        const parsed = parseNumberValue(timeout);
        if (isNaN(parsed) || parsed <= 0) {
            return 600000;
        }

        return Math.max(10000, Math.round(parsed * 1000));
    }

    function isCrawl4aiPendingResult(result) {
        if (!result || typeof result !== 'object') {
            return false;
        }

        const taskId = typeof result.task_id === 'string' ? result.task_id : '';
        if (!taskId) {
            return false;
        }

        if (Array.isArray(result.results) && result.results.length) {
            return false;
        }

        const status = typeof result.status === 'string' ? result.status.toLowerCase() : '';
        return !status || status === 'pending' || status === 'queued' || status === 'running';
    }

    function buildCrawl4aiTaskUrl(state, taskId) {
        if (!state || !state.config || !state.config.crawl4aiTaskEndpoint) {
            return '';
        }

        let base = state.config.crawl4aiTaskEndpoint;
        if (base.charAt(base.length - 1) !== '/') {
            base += '/';
        }

        return base + encodeURIComponent(taskId);
    }

    function fetchCrawl4aiTask(state, taskId) {
        const url = buildCrawl4aiTaskUrl(state, taskId);
        if (!url) {
            return Promise.reject(new Error('Crawl4AI endpoint not configured.'));
        }

        return fetch(url, {
            method: 'GET',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
        }).then(function (response) {
            // 404 means task not found yet - this is expected early in async execution
            if (response.status === 404) {
                return null;
            }

            // Check response status BEFORE trying to parse JSON
            if (!response.ok) {
                // For server errors, try to extract error message from JSON if available
                return response
                    .json()
                    .catch(function () {
                        const error = new Error('HTTP ' + response.status);
                        error.status = response.status;
                        throw error;
                    })
                    .then(function (data) {
                        const errorMessage = data && data.message ? data.message : ('HTTP ' + response.status);
                        const error = new Error(errorMessage);
                        error.status = response.status;
                        error.data = data;
                        throw error;
                    });
            }

            // Success response - parse JSON normally
            return response
                .json()
                .catch(function () {
                    return null;
                });
        });
    }

    function updatePendingTaskEntry(entry, message) {
        if (!entry) {
            return;
        }

        // Entry is now the bubble itself (merged structure)
        if (!entry.classList.contains('wp-mcp-ai-chat__bubble')) {
            return;
        }

        entry.textContent = message;
    }

    /**
     * Client-side async polling for Crawl4AI tasks.
     * NOTE: Currently unused - system uses server-side WP-Cron polling instead (WP_MCP_AI_Crawler class).
     * This implementation is kept for potential future use with client-side polling.
     * See: includes/crawler/class-wp-mcp-ai-crawler.php
     */
    // eslint-disable-next-line no-unused-vars
    function waitForCrawl4aiTask(state, toolName, result) {
        if (!isCrawl4aiPendingResult(result)) {
            return Promise.resolve(result);
        }

        if (!state || !state.config || !state.config.crawl4aiTaskEndpoint) {
            return Promise.resolve(result);
        }

        const taskId = result.task_id;
        const metadata = result && typeof result === 'object' && result.metadata ? result.metadata : {};
        const pollDelay = getCrawl4aiPollDelay(metadata, state);
        const timeout = getCrawl4aiTimeout(metadata);
        const startTime = Date.now();
        const pendingEntry = appendMessage(state.messagesEl, 'system', getString('toolQueued', 'Crawl queued. Results will appear shortly.'), false, { state: state });

        state.pendingCrawlTasks[taskId] = {
            entry: pendingEntry,
            pollDelay: pollDelay,
            timeout: timeout,
            start: startTime,
            timer: null,
            toolName: toolName,
        };

        return new Promise(function (resolve, reject) {
            function cleanup() {
                const record = state.pendingCrawlTasks[taskId];
                if (record && record.timer) {
                    clearTimeout(record.timer);
                }

                delete state.pendingCrawlTasks[taskId];
            }

            function scheduleNext() {
                const record = state.pendingCrawlTasks[taskId];
                if (!record) {
                    return;
                }

                record.timer = setTimeout(poll, record.pollDelay);
            }

            function poll() {
                const record = state.pendingCrawlTasks[taskId];
                if (!record) {
                    return;
                }

                if (Date.now() - record.start >= record.timeout) {
                    cleanup();
                    updatePendingTaskEntry(pendingEntry, getString('toolTimeout', 'Crawl timed out before completing.'));
                    reject(new Error('timeout'));
                    return;
                }

                fetchCrawl4aiTask(state, taskId)
                    .then(function (payload) {
                        if (!payload) {
                            updatePendingTaskEntry(pendingEntry, getString('toolPolling', 'Crawl in progress…'));
                            scheduleNext();
                            return;
                        }

                        const status = typeof payload.status === 'string' ? payload.status.toLowerCase() : '';
                        if (status === 'failed' || status === 'error') {
                            cleanup();
                            const errorMessage = payload && payload.metadata && payload.metadata.error ? payload.metadata.error : getString('toolError', 'The tool request failed.');
                            const toolDisplayName = record.toolName || 'Tool';
                            updatePendingTaskEntry(pendingEntry, formatString('%s failed: %s', toolDisplayName, errorMessage));
                            reject(new Error(errorMessage));
                            return;
                        }

                        if (status === 'timeout') {
                            cleanup();
                            updatePendingTaskEntry(pendingEntry, getString('toolTimeout', 'Crawl timed out before completing.'));
                            reject(new Error('timeout'));
                            return;
                        }

                        if (Array.isArray(payload.results) && payload.results.length) {
                            cleanup();
                            updatePendingTaskEntry(pendingEntry, getString('toolSuccess', 'Tool completed successfully.'));
                            resolve(payload);
                            return;
                        }

                        updatePendingTaskEntry(pendingEntry, getString('toolPolling', 'Crawl in progress…'));
                        record.pollDelay = getCrawl4aiPollDelay(payload.metadata || {}, state);
                        scheduleNext();
                    })
                    .catch(function (error) {
                        cleanup();
                        const message = error && error.message ? error.message : getString('toolError', 'The tool request failed.');
                        const toolDisplayName = record.toolName || 'Tool';
                        updatePendingTaskEntry(pendingEntry, formatString('%s failed: %s', toolDisplayName, message));
                        reject(error);
                    });
            }

            poll();
        });
    }

    /**
     * Wait for async tool result using SSE streaming
     * Uses SSE service for proper separation of concerns.
     * 
     * @param {Object} state Chat state object
     * @param {string} jobId Job ID for the async tool execution
     * @param {string} toolName Tool name for display purposes
     * @param {string} toolCallId Original tool_call_id from LLM for API compatibility
     * @return {Promise} Promise that resolves with the tool result
     */
    function waitForAsyncToolResultSSE(state, jobId, toolName, toolCallId) {
        if (!jobId || !state || !state.config || !state.config.restUrl) {
            return Promise.reject(new Error('Missing job ID, state, or REST URL'));
        }

        // Check if SSE service is available
        if (!sseService || !sseService.isSupported()) {
            // Fall back to polling
            return waitForAsyncToolResultPolling(state, jobId, toolName, null, toolCallId);
        }

        // Use configurable timeout from settings (default 5 minutes)
        // This allows admins to adjust for long-running tools like video generation
        const timeout = (state.config && state.config.asyncToolTimeout) ? state.config.asyncToolTimeout : ASYNC_TOOL_TIMEOUT_DEFAULT_MS;
        const startTime = Date.now();
        const pendingEntry = appendMessage(state.messagesEl, 'system', getString('toolQueued', 'Tool is processing in the background. Results will appear shortly.'), false, { state: state });

        return new Promise(function (resolve, reject) {
            let sseConnection = null;
            let timeoutTimer = null;
            let resolved = false;

            function cleanup() {
                if (sseConnection) {
                    sseConnection.close();
                    sseConnection = null;
                }
                if (timeoutTimer) {
                    clearTimeout(timeoutTimer);
                    timeoutTimer = null;
                }
                delete state.pendingAsyncTools[jobId];
            }

            function handleComplete(result) {
                if (resolved) {
                    return;
                }
                resolved = true;
                cleanup();

                // Remove the pending message
                if (pendingEntry && pendingEntry.parentNode) {
                    pendingEntry.parentNode.removeChild(pendingEntry);
                }

                // Display the actual result
                if (result) {
                    displayAsyncToolResult(state, toolName, result, toolCallId);
                    resolve(result);
                } else {
                    updatePendingTaskEntry(pendingEntry, getString('toolSuccess', 'Tool completed successfully.'));
                    resolve({});
                }
            }

            function handleError(errorMessage) {
                if (resolved) {
                    return;
                }
                resolved = true;
                cleanup();
                updatePendingTaskEntry(pendingEntry, formatString('%s failed: %s', toolName || 'Tool', errorMessage));
                reject(new Error(errorMessage));
            }

            // Set up timeout with recovery attempt
            timeoutTimer = setTimeout(function () {
                if (!resolved) {
                    // Before failing, attempt one final check for file-based completion
                    // This handles cases where the video file was created but not yet detected
                    attemptTimeoutRecovery(state, jobId).then(function (recoveredResult) {
                        if (resolved) {
                            return; // Already resolved during recovery attempt
                        }
                        if (recoveredResult) {
                            // Recovery successful - job actually completed
                            handleComplete(recoveredResult);
                        } else {
                            // Recovery failed - report timeout error
                            handleError(getString('toolTimeout', 'Tool timed out before completing.'));
                        }
                    }).catch(function () {
                        if (!resolved) {
                            handleError(getString('toolTimeout', 'Tool timed out before completing.'));
                        }
                    });
                }
            }, timeout);

            // Build SSE URL with stream=true parameter and authentication
            // EventSource cannot send custom headers, so we pass auth via query params
            let url = state.config.restUrl;
            if (url.charAt(url.length - 1) !== '/') {
                url += '/';
            }
            url += 'cron-status/' + encodeURIComponent(jobId) + '?stream=true';

            // Add assistant_id for multi-widget isolation and context.
            // The cron-status service uses this to filter jobs by assistant.
            const assistantId = state.originalAssistantId || (state.config && state.config.assistantId);
            if (assistantId) {
                url += '&assistant_id=' + encodeURIComponent(assistantId);
            }

            // Add authentication query params for SSE (EventSource can't use headers)
            const guestToken = state && state.config ? state.config.guestToken : '';
            if (guestToken) {
                url += '&guest_token=' + encodeURIComponent(guestToken);
            } else {
                let nonce = '';
                if (state && state.config && state.config.restNonce) {
                    nonce = state.config.restNonce;
                } else if (globalConfig.nonce) {
                    nonce = globalConfig.nonce;
                }

                if (nonce) {
                    url += '&_wpnonce=' + encodeURIComponent(nonce);
                }
            }

            // Create SSE connection using service
            sseConnection = sseService.connect(url, {
                eventHandlers: {
                    cron_job_status: function (payload) {
                        const status = typeof payload.status === 'string' ? payload.status.toLowerCase() : '';

                        if (status === 'completed') {
                            // Job completed - extract result and display
                            if (payload.result) {
                                handleComplete(payload.result);
                            } else {
                                handleComplete(payload);
                            }
                        } else if (status === 'failed' || status === 'error') {
                            const errorMessage = payload && payload.error ? payload.error : getString('toolError', 'The tool request failed.');
                            handleError(errorMessage);
                        } else if (status === 'delegated' && payload.delegated_to) {
                            // Job was delegated to a nested async job (e.g., veo video generation).
                            // Continue polling the PARENT job (not the delegated veo job).
                            // The veo service will update the parent job when it completes via complete_parent_job().
                            // This ensures we get the final result on the original job ID.
                            if (window.console && console.log) {
                                console.log('[WP oOS] Job delegated to:', payload.delegated_to);
                            }
                            // Use the message from the delegated job if available (includes veo job ID)
                            // otherwise fall back to generic message
                            const delegatedMessage = payload.message || getString('toolDelegated', 'Video generation in progress…');
                            updatePendingTaskEntry(pendingEntry, delegatedMessage);
                            // Update status indicator with delegation message
                            setStatus(state.container, {
                                message: delegatedMessage,
                                type: 'text-stream',
                                showTime: false
                            });
                            // Continue polling this same parent job - it will be updated when veo completes
                        } else {
                            // Still pending, polling or running - update status with progress message
                            // Use progress_message from server if available for more informative updates
                            let statusMessage = getString('toolPolling', 'Tool is processing…');
                            
                            if (payload && payload.progress_message) {
                                // Use the detailed progress message from the server
                                statusMessage = payload.progress_message;
                            } else if (payload && typeof payload.progress === 'number' && payload.progress > 0) {
                                // Fall back to percentage if available
                                statusMessage = formatString(getString('toolProgress', 'Tool is processing… (%s%%)'), String(Math.round(payload.progress)));
                            }
                            
                            // Update both the pending task entry AND the status indicator
                            updatePendingTaskEntry(pendingEntry, statusMessage);
                            setStatus(state.container, {
                                message: statusMessage,
                                type: 'text-stream',
                                showTime: false
                            });
                        }
                    }
                },
                onMessage: function (data) {
                    // Handle [DONE] marker
                    if (!resolved && !data) {
                        handleComplete({});
                    }
                },
                onError: function (error) {
                    console.error('[WP oOS] SSE connection error:', error);
                    // Fall back to polling
                    cleanup();
                    waitForAsyncToolResultPolling(state, jobId, toolName, pendingEntry, toolCallId).then(resolve).catch(reject);
                }
            });

            // Track in pending async tools
            state.pendingAsyncTools[jobId] = {
                entry: pendingEntry,
                timeout: timeout,
                start: startTime,
                toolName: toolName,
                toolCallId: toolCallId,
                sseConnection: sseConnection,
            };

            // Handle case where connection failed to be created
            if (!sseConnection) {
                cleanup();
                waitForAsyncToolResultPolling(state, jobId, toolName, pendingEntry, toolCallId).then(resolve).catch(reject);
            }
        });
    }

    /**
     * Poll for async tool execution result (fallback for when SSE is not available)
     * 
     * @param {Object} state Chat state object
     * @param {string} jobId Job ID for the async tool execution
     * @param {string} toolName Tool name for display purposes
     * @param {Element} pendingEntry Optional existing pending entry element
     * @param {string} toolCallId Original tool_call_id from LLM for API compatibility
     * @return {Promise} Promise that resolves with the tool result
     */
    function waitForAsyncToolResultPolling(state, jobId, toolName, pendingEntry, toolCallId) {
        if (!jobId || !state || !state.config) {
            return Promise.reject(new Error('Missing job ID or state'));
        }

        const pollDelay = 3000; // Poll every 3 seconds
        // Use configurable timeout from settings (default 5 minutes)
        // This allows admins to adjust for long-running tools like video generation
        const timeout = (state.config && state.config.asyncToolTimeout) ? state.config.asyncToolTimeout : ASYNC_TOOL_TIMEOUT_DEFAULT_MS;
        const startTime = Date.now();
        
        if (!pendingEntry) {
            pendingEntry = appendMessage(state.messagesEl, 'system', getString('toolQueued', 'Tool is processing in the background. Results will appear shortly.'), false, { state: state });
        }

        state.pendingAsyncTools[jobId] = {
            entry: pendingEntry,
            pollDelay: pollDelay,
            timeout: timeout,
            start: startTime,
            timer: null,
            toolName: toolName,
            toolCallId: toolCallId,
        };

        return new Promise(function (resolve, reject) {
            function cleanup() {
                const record = state.pendingAsyncTools[jobId];
                if (record && record.timer) {
                    clearTimeout(record.timer);
                }
                delete state.pendingAsyncTools[jobId];
            }

            function scheduleNext() {
                const record = state.pendingAsyncTools[jobId];
                if (!record) {
                    return;
                }
                record.timer = setTimeout(poll, record.pollDelay);
            }

            function poll() {
                const record = state.pendingAsyncTools[jobId];
                if (!record) {
                    return;
                }

                if (Date.now() - record.start >= record.timeout) {
                    // Before failing, attempt one final check for file-based completion
                    // This handles cases where the video file was created but not yet detected
                    attemptTimeoutRecovery(state, jobId).then(function (recoveredResult) {
                        if (recoveredResult) {
                            // Recovery successful - job actually completed
                            cleanup();
                            // Remove the pending message
                            if (pendingEntry && pendingEntry.parentNode) {
                                pendingEntry.parentNode.removeChild(pendingEntry);
                            }
                            displayAsyncToolResult(state, record.toolName, recoveredResult, record.toolCallId);
                            resolve(recoveredResult);
                        } else {
                            // Recovery failed - report timeout error
                            cleanup();
                            updatePendingTaskEntry(pendingEntry, getString('toolTimeout', 'Tool timed out before completing.'));
                            reject(new Error('timeout'));
                        }
                    }).catch(function () {
                        cleanup();
                        updatePendingTaskEntry(pendingEntry, getString('toolTimeout', 'Tool timed out before completing.'));
                        reject(new Error('timeout'));
                    });
                    return;
                }

                fetchAsyncToolResult(state, jobId)
                    .then(function (payload) {
                        if (!payload) {
                            updatePendingTaskEntry(pendingEntry, getString('toolPolling', 'Tool is processing…'));
                            setStatus(state.container, {
                                message: getString('toolPolling', 'Tool is processing…'),
                                type: 'text-stream',
                                showTime: false
                            });
                            scheduleNext();
                            return;
                        }

                        const status = typeof payload.status === 'string' ? payload.status.toLowerCase() : '';

                        if (status === 'failed' || status === 'error') {
                            cleanup();
                            // Extract error message - handle both string and object formats.
                            // The backend may send error as a string or as an object with {message, code} fields.
                            const errorMessage = extractErrorMessage(
                                payload && payload.error,
                                getString('toolError', 'The tool request failed.')
                            );
                            const toolDisplayName = record.toolName || 'Tool';
                            updatePendingTaskEntry(pendingEntry, formatString('%s failed: %s', toolDisplayName, errorMessage));
                            reject(new Error(errorMessage));
                            return;
                        }

                        if (status === 'completed') {
                            cleanup();
                            // Remove the pending message
                            if (pendingEntry && pendingEntry.parentNode) {
                                pendingEntry.parentNode.removeChild(pendingEntry);
                            }
                            // Display the actual result
                            if (payload.result) {
                                displayAsyncToolResult(state, record.toolName, payload.result, record.toolCallId);
                                resolve(payload.result);
                            } else {
                                updatePendingTaskEntry(pendingEntry, getString('toolSuccess', 'Tool completed successfully.'));
                                resolve(payload);
                            }
                            return;
                        }

                        // Handle delegated status - job was handed off to nested async job (e.g., veo video generation)
                        if (status === 'delegated' && payload.delegated_to) {
                            // Job was delegated to a nested async job (e.g., veo video generation).
                            // Continue polling the PARENT job (not the delegated veo job).
                            // The veo service will update the parent job when it completes via complete_parent_job().
                            if (window.console && console.log) {
                                console.log('[WP oOS] Job delegated to:', payload.delegated_to);
                            }
                            // Use the message from the delegated job if available (includes veo job ID)
                            // otherwise fall back to generic message
                            const delegatedMessage = payload.message || getString('toolDelegated', 'Video generation in progress…');
                            updatePendingTaskEntry(pendingEntry, delegatedMessage);
                            // Update status indicator with delegation message
                            setStatus(state.container, {
                                message: delegatedMessage,
                                type: 'text-stream',
                                showTime: false
                            });
                            // Continue polling this same parent job - it will be updated when veo completes
                            // Don't switch to the delegated job or return here
                        }

                        // Still pending, polling or running - update with progress message if available
                        let statusMessage = getString('toolPolling', 'Tool is processing…');
                        
                        if (payload && payload.progress_message) {
                            // Use the detailed progress message from the server
                            statusMessage = payload.progress_message;
                        } else if (payload && typeof payload.progress === 'number' && payload.progress > 0) {
                            // Fall back to percentage if available
                            statusMessage = formatString(getString('toolProgress', 'Tool is processing… (%s%%)'), String(Math.round(payload.progress)));
                        }
                        
                        updatePendingTaskEntry(pendingEntry, statusMessage);
                        // Update status indicator with polling message
                        setStatus(state.container, {
                            message: statusMessage,
                            type: 'text-stream',
                            showTime: false
                        });
                        scheduleNext();
                    })
                    .catch(function (error) {
                        cleanup();
                        const message = error && error.message ? error.message : getString('toolError', 'The tool request failed.');
                        const toolDisplayName = record.toolName || 'Tool';
                        updatePendingTaskEntry(pendingEntry, formatString('%s failed: %s', toolDisplayName, message));
                        reject(error);
                    });
            }

            poll();
        });
    }

    /**
     * Poll for async tool execution result
     * Automatically uses SSE if supported, falls back to polling
     * Delegates to SSE service for proper separation of concerns.
     * 
     * @param {Object} state Chat state object
     * @param {string} jobId Job ID for the async tool execution
     * @param {string} toolName Tool name for display purposes
     * @param {string} toolCallId Original tool_call_id from LLM for API compatibility
     * @return {Promise} Promise that resolves with the tool result
     */
    function waitForAsyncToolResult(state, jobId, toolName, toolCallId) {
        if (!jobId || !state || !state.config) {
            return Promise.reject(new Error('Missing job ID or state'));
        }

        // Try to use the job event bus first - this allows the cron-status SSE stream
        // to deliver job completions without needing a separate SSE connection per job.
        // This prevents connection conflicts and improves efficiency.
        if (window.wpMcpAiJobBus) {
            // Check if job is already completed in cache
            const cached = window.wpMcpAiJobBus.getCached(jobId);
            if (cached && cached.data) {
                const status = cached.data.status ? cached.data.status.toLowerCase() : '';
                if (status === 'completed' && cached.data.result) {
                    // Job already completed - display result immediately
                    displayAsyncToolResult(state, toolName, cached.data.result, toolCallId);
                    return Promise.resolve(cached.data.result);
                }
            }

            // Use job event bus with fallback to direct polling
            return waitForAsyncToolResultWithEventBus(state, jobId, toolName, toolCallId);
        }

        // Fall back to original behavior if job event bus not available
        // Check if SSE service is available and supported
        if (sseService && sseService.isSupported()) {
            return waitForAsyncToolResultSSE(state, jobId, toolName, toolCallId);
        }

        // Fall back to polling
        return waitForAsyncToolResultPolling(state, jobId, toolName, null, toolCallId);
    }

    /**
     * Wait for async tool result using job event bus
     * 
     * Listens to the job event bus for completion events from the cron-status
     * SSE stream. Falls back to direct polling if event bus doesn't deliver
     * within a reasonable time.
     * 
     * @param {Object} state Chat state object
     * @param {string} jobId Job ID for the async tool execution
     * @param {string} toolName Tool name for display purposes
     * @param {string} toolCallId Original tool_call_id from LLM for API compatibility
     * @return {Promise} Promise that resolves with the tool result
     */
    function waitForAsyncToolResultWithEventBus(state, jobId, toolName, toolCallId) {
        const timeout = (state.config && state.config.asyncToolTimeout) ? state.config.asyncToolTimeout : ASYNC_TOOL_TIMEOUT_DEFAULT_MS;
        const pendingEntry = appendMessage(state.messagesEl, 'system', getString('toolQueued', 'Tool is processing in the background. Results will appear shortly.'), false, { state: state });
        
        // Track in pending async tools
        state.pendingAsyncTools[jobId] = {
            entry: pendingEntry,
            toolName: toolName,
            toolCallId: toolCallId,
            start: Date.now()
        };

        return new Promise(function (resolve, reject) {
            let resolved = false;
            let completedHandler = null;
            let failedHandler = null;
            let progressHandler = null;
            let timeoutTimer = null;
            let fallbackTimer = null;

            function cleanup() {
                if (timeoutTimer) {
                    clearTimeout(timeoutTimer);
                    timeoutTimer = null;
                }
                if (fallbackTimer) {
                    clearTimeout(fallbackTimer);
                    fallbackTimer = null;
                }
                if (completedHandler && window.wpMcpAiJobBus) {
                    window.wpMcpAiJobBus.off('job:completed', completedHandler);
                }
                if (failedHandler && window.wpMcpAiJobBus) {
                    window.wpMcpAiJobBus.off('job:failed', failedHandler);
                }
                if (progressHandler && window.wpMcpAiJobBus) {
                    window.wpMcpAiJobBus.off('job:progress', progressHandler);
                }
                delete state.pendingAsyncTools[jobId];
            }

            function handleComplete(result) {
                if (resolved) return;
                resolved = true;
                cleanup();

                // Remove pending message
                if (pendingEntry && pendingEntry.parentNode) {
                    pendingEntry.parentNode.removeChild(pendingEntry);
                }

                // Display result
                if (result) {
                    displayAsyncToolResult(state, toolName, result, toolCallId);
                    resolve(result);
                } else {
                    updatePendingTaskEntry(pendingEntry, getString('toolSuccess', 'Tool completed successfully.'));
                    resolve({});
                }
            }

            function handleError(errorMessage) {
                if (resolved) return;
                resolved = true;
                cleanup();
                updatePendingTaskEntry(pendingEntry, formatString('%s failed: %s', toolName || 'Tool', errorMessage));
                reject(new Error(errorMessage));
            }

            // Set up timeout with recovery attempt
            timeoutTimer = setTimeout(function () {
                if (!resolved) {
                    // Before failing, attempt one final check for file-based completion
                    // This handles cases where the video file was created but not yet detected
                    attemptTimeoutRecovery(state, jobId).then(function (recoveredResult) {
                        if (resolved) {
                            return; // Already resolved during recovery attempt
                        }
                        if (recoveredResult) {
                            // Recovery successful - job actually completed
                            handleComplete(recoveredResult);
                        } else {
                            // Recovery failed - report timeout error
                            handleError(getString('toolTimeout', 'Tool timed out before completing.'));
                        }
                    }).catch(function () {
                        if (!resolved) {
                            handleError(getString('toolTimeout', 'Tool timed out before completing.'));
                        }
                    });
                }
            }, timeout);

            // Listen for completion via event bus
            completedHandler = function (evt) {
                if (evt.jobId === jobId) {
                    const result = evt.data && evt.data.result ? evt.data.result : evt.data;
                    handleComplete(result);
                }
            };
            window.wpMcpAiJobBus.on('job:completed', completedHandler);

            // Listen for failure via event bus
            failedHandler = function (evt) {
                if (evt.jobId === jobId) {
                    const errorMsg = evt.data && evt.data.error ? evt.data.error : getString('toolError', 'The tool request failed.');
                    handleError(errorMsg);
                }
            };
            window.wpMcpAiJobBus.on('job:failed', failedHandler);

            // Listen for progress updates
            progressHandler = function (evt) {
                if (evt.jobId === jobId && evt.data) {
                    const statusMessage = evt.data.progress_message || evt.data.message || getString('toolPolling', 'Tool is processing…');
                    updatePendingTaskEntry(pendingEntry, statusMessage);
                    setStatus(state.container, {
                        message: statusMessage,
                        type: 'text-stream',
                        showTime: false
                    });
                }
            };
            window.wpMcpAiJobBus.on('job:progress', progressHandler);

            // Set up fallback to direct polling if event bus doesn't deliver
            // within 10 seconds (cron-status SSE might not be active)
            fallbackTimer = setTimeout(function () {
                if (!resolved) {
                    if (window.console && console.log) {
                        console.log('[WP oOS] Event bus fallback: switching to direct polling for job', jobId);
                    }
                    // Clean up event bus listeners but keep timeout
                    if (completedHandler) {
                        window.wpMcpAiJobBus.off('job:completed', completedHandler);
                        completedHandler = null;
                    }
                    if (failedHandler) {
                        window.wpMcpAiJobBus.off('job:failed', failedHandler);
                        failedHandler = null;
                    }
                    if (progressHandler) {
                        window.wpMcpAiJobBus.off('job:progress', progressHandler);
                        progressHandler = null;
                    }
                    
                    // Start direct polling (SSE or REST)
                    // Remove our pending entry since the fallback will create its own
                    if (pendingEntry && pendingEntry.parentNode) {
                        pendingEntry.parentNode.removeChild(pendingEntry);
                    }
                    
                    let pollPromise;
                    if (sseService && sseService.isSupported()) {
                        // Use SSE polling - it will create its own pending entry
                        pollPromise = waitForAsyncToolResultSSE(state, jobId, toolName, toolCallId);
                    } else {
                        // REST polling reuses our pending entry via parameter
                        pollPromise = waitForAsyncToolResultPolling(state, jobId, toolName, null, toolCallId);
                    }
                    
                    pollPromise.then(function(result) {
                        if (!resolved) {
                            resolved = true;
                            cleanup();
                            // Result already displayed by the polling function
                            resolve(result);
                        }
                    }).catch(function(error) {
                        if (!resolved) {
                            handleError(error.message || 'Polling failed');
                        }
                    });
                }
            }, 10000); // 10 second fallback delay
        });
    }

    /**
     * Legacy polling function - kept for compatibility
     * Now just an alias to waitForAsyncToolResult
     * 
     * @deprecated Use waitForAsyncToolResult instead
     * @param {Object} state Chat state object
     * @param {string} jobId Job ID for the async tool execution
     * @param {string} toolName Tool name for display purposes
     * @return {Promise} Promise that resolves with the tool result
     */
    function waitForAsyncToolResultLegacy(state, jobId, toolName) {
        return waitForAsyncToolResult(state, jobId, toolName);
    }

    /**
     * Attempt timeout recovery by making a final check for job completion.
     * 
     * When an async tool times out, the video/file may have actually been created
     * but not yet detected by the polling loop. This function makes one final
     * check to see if the job has completed, checking for file-based completion
     * (e.g., a video file in the media library matching the job ID).
     * 
     * @param {Object} state Chat state object
     * @param {string} jobId Job ID for the async tool execution
     * @return {Promise<Object|null>} Promise resolving to result if completed, null if still pending/failed
     */
    function attemptTimeoutRecovery(state, jobId) {
        if (!state || !state.config || !state.config.restUrl) {
            return Promise.resolve(null);
        }

        let url = state.config.restUrl;
        if (url.charAt(url.length - 1) !== '/') {
            url += '/';
        }
        url += 'cron-status/' + encodeURIComponent(jobId);

        // Add assistant_id for context
        const assistantId = state.originalAssistantId || (state.config && state.config.assistantId);
        const queryParams = [];
        if (assistantId) {
            queryParams.push('assistant_id=' + encodeURIComponent(assistantId));
        }
        // Add timeout_recovery flag to signal backend to do aggressive file-based detection
        queryParams.push('timeout_recovery=1');
        
        if (queryParams.length > 0) {
            url += '?' + queryParams.join('&');
        }

        if (window.console && console.log) {
            console.log('[WP oOS] Attempting timeout recovery for job:', jobId);
        }

        return fetch(url, {
            method: 'GET',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                return null;
            }
            return response.json().catch(function () {
                return null;
            });
        }).then(function (payload) {
            if (!payload) {
                return null;
            }

            const status = typeof payload.status === 'string' ? payload.status.toLowerCase() : '';
            
            // If job is now completed, return the result
            if (status === 'completed' && payload.result) {
                if (window.console && console.log) {
                    console.log('[WP oOS] Timeout recovery successful - job completed:', jobId);
                }
                return payload.result;
            }
            
            // Job still not completed or failed
            return null;
        }).catch(function (error) {
            if (window.console && console.warn) {
                console.warn('[WP oOS] Timeout recovery check failed:', error);
            }
            return null;
        });
    }

    /**
     * Fetch async tool result from cron-status endpoint
     * 
     * @param {Object} state Chat state object
     * @param {string} jobId Job ID for the async tool execution
     * @return {Promise} Promise that resolves with the job status
     */
    function fetchAsyncToolResult(state, jobId) {
        if (!state || !state.config || !state.config.restUrl) {
            return Promise.reject(new Error('REST URL not configured.'));
        }

        let url = state.config.restUrl;
        if (url.charAt(url.length - 1) !== '/') {
            url += '/';
        }
        url += 'cron-status/' + encodeURIComponent(jobId);

        // Add assistant_id for multi-widget isolation and context.
        // The cron-status service uses this to filter jobs by assistant.
        const assistantId = state.originalAssistantId || (state.config && state.config.assistantId);
        if (assistantId) {
            url += '?assistant_id=' + encodeURIComponent(assistantId);
        }

        return fetch(url, {
            method: 'GET',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
        }).then(function (response) {
            // 404 means job not found yet - this is expected early in async execution
            // Return null to signal "not ready yet" rather than an error
            if (response.status === 404) {
                return null;
            }

            // Check response status BEFORE trying to parse JSON
            // This provides better error messages for server errors
            if (!response.ok) {
                // For server errors, try to extract error message from JSON if available
                return response
                    .json()
                    .catch(function () {
                        // JSON parsing failed - just use HTTP status
                        const error = new Error('HTTP ' + response.status);
                        error.status = response.status;
                        throw error;
                    })
                    .then(function (data) {
                        // Got JSON error response - use message if available
                        const errorMessage = data && data.message ? data.message : ('HTTP ' + response.status);
                        const error = new Error(errorMessage);
                        error.status = response.status;
                        error.data = data;
                        throw error;
                    });
            }

            // Success response - parse JSON normally
            return response
                .json()
                .catch(function () {
                    // JSON parsing failed on success response - return null
                    return null;
                });
        });
    }

    /**
     * Display async tool result in the chat and persist it in the conversation.
     * This ensures tool results are part of the agentic flow and available for:
     * - Subsequent AI messages (context continuity)
     * - Conversation restoration from storage
     * - CCT persistence
     * 
     * @param {Object} state Chat state object
     * @param {string} toolName Tool name
     * @param {Object} result Tool result data
     * @param {string} toolCallId Original tool_call_id from LLM for API compatibility
     */
    function displayAsyncToolResult(state, toolName, result, toolCallId) {
        if (!state || !state.messagesEl || !result) {
            return;
        }

        // Extract attachments from tool result (e.g., generated images, audio files)
        const normalized = typeof result === 'object' && result !== null ? 
            normaliseToolResultForDisplay(toolName, result) : null;

        let resultText = '';
        let attachments = [];

        if (normalized && normalized.attachments && normalized.attachments.length > 0) {
            // Tool result has attachments (images, files) - use normalized text and attachments
            resultText = normalized.text || (toolName + ': ' + getString('completed', 'Completed'));
            attachments = normalized.attachments;
        } else if (result.text) {
            resultText = result.text;
        } else if (result.message) {
            resultText = result.message;
        } else {
            resultText = toolName + ': ' + getString('completed', 'Completed successfully');
        }

        // Update status indicator with tool completion
        setStatus(state.container, {
            message: '✓ ' + resultText,
            type: 'text-stream',
            showTime: false
        });

        // Build display payload for UI rendering
        const displayPayload = {
            text: '✓ ' + resultText,
            attachments: attachments
        };

        // Display the tool result with attachments
        const messageElement = appendMessage(state.messagesEl, 'tool', displayPayload, false, { state: state });

        // Add tool result to conversation state for agentic flow continuity
        // This ensures the tool result is available for subsequent AI messages
        if (state.conversation && Array.isArray(state.conversation)) {
            // Build the tool message for conversation history
            // Content should be the raw result for API compatibility
            let contentForApi = '';
            if (typeof result === 'object') {
                try {
                    contentForApi = JSON.stringify(result);
                } catch (e) {
                    // Log serialization error for debugging
                    if (window.console && console.warn) {
                        console.warn('[WP oOS] Failed to serialize tool result:', {
                            tool_name: toolName,
                            error: e.message || 'Unknown serialization error'
                        });
                    }
                    contentForApi = resultText;
                }
            } else {
                contentForApi = String(result);
            }

            // Extract display metadata for proper UI restoration
            const displayMetadata = extractDisplayMetadata(messageElement, displayPayload);

            // Determine the tool_call_id to use for this tool result.
            // Priority:
            // 1. toolCallId parameter (original LLM tool_call_id passed through polling chain)
            // 2. tool_results[0].tool_call_id (from backend's async completion response)
            // 3. result.tool_call_id (direct field)
            // 4. Generate new ID as fallback (legacy behavior)
            let finalToolCallId = '';
            
            // First, use the parameter if provided (this is the correct original ID)
            if (toolCallId && typeof toolCallId === 'string' && toolCallId.trim()) {
                finalToolCallId = toolCallId.trim();
            }
            
            // If not provided, try to extract from result structure
            if (!finalToolCallId && typeof result === 'object' && result !== null) {
                // Check tool_results array first (OpenAI/backend format)
                if (Array.isArray(result.tool_results) && result.tool_results.length > 0) {
                    const firstToolResult = result.tool_results[0];
                    if (typeof firstToolResult === 'object' && firstToolResult !== null && firstToolResult.tool_call_id) {
                        finalToolCallId = String(firstToolResult.tool_call_id);
                    }
                }
                
                // Fallback to direct tool_call_id field
                if (!finalToolCallId && result.tool_call_id && typeof result.tool_call_id === 'string' && result.tool_call_id !== '') {
                    finalToolCallId = String(result.tool_call_id);
                }
            }
            
            // Generate fallback tool_call_id if still not found
            if (!finalToolCallId) {
                // Use timestamp + random suffix to avoid collisions if multiple tools complete simultaneously
                // Sanitize toolName to only allow alphanumeric and underscore characters
                const sanitizedToolName = toolName.replace(/[^a-zA-Z0-9_]/g, '_');
                finalToolCallId = 'async_' + sanitizedToolName + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
            }

            // Create tool message with display metadata for persistence
            const toolMessage = createConversationMessage(
                'tool',
                contentForApi,
                displayMetadata,
                {
                    name: toolName,
                    tool_call_id: finalToolCallId
                }
            );

            state.conversation.push(toolMessage);

            // Log for debugging
            if (window.console && console.log) {
                console.log('[WP oOS] Added async tool result to conversation:', {
                    tool_name: toolName,
                    tool_call_id: finalToolCallId,
                    passed_tool_call_id: toolCallId || '(not provided)',
                    conversation_length: state.conversation.length
                });
            }

            // Save conversation to storage for persistence
            saveConversationToStorage(state);

            // Also save to CCT if available (silent, non-blocking)
            saveConversationToCCT(state, { silent: true });
        }
    }

    function formatBytes(bytes) {
        if (typeof bytes !== 'number' || !isFinite(bytes) || bytes <= 0) {
            return '';
        }

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let exponent = Math.floor(Math.log(bytes) / Math.log(1024));
        exponent = Math.min(units.length - 1, Math.max(exponent, 0));

        const value = bytes / Math.pow(1024, exponent);
        const decimals = exponent === 0 ? 0 : value >= 10 ? 1 : 2;

        return value.toFixed(decimals) + ' ' + units[exponent];
    }

    function createContentDispositionHeader(filename) {
        const fallback = (filename || 'attachment').replace(/"/g, '');
        const encoded = encodeRFC5987ValueChars(filename || fallback);

        return 'attachment; filename="' + fallback + '"; filename*=UTF-8\'' + encoded + '\'';
    }

    function encodeRFC5987ValueChars(str) {
        return encodeURIComponent(str)
            .replace(/['()*]/g, function (character) {
                return '%' + character.charCodeAt(0).toString(16).toUpperCase();
            })
            .replace(/%(7C|60|5E)/g, function (match, hex) {
                return '%' + hex;
            });
    }

    /**
     * Public API: Load a session transcript into a chat widget
     * 
     * @param {Object} options - Configuration options
     * @param {string} options.sessionKey - The session key to load
     * @param {string} options.assistantId - The assistant ID for the session
     * @param {Array} options.messages - Array of message objects with role and content
     * @param {string|HTMLElement} options.target - CSS selector or element for the target chat widget
     * @return {boolean} True if successful, false otherwise
     */
    function loadSessionIntoChat(options) {
        if (!options || typeof options !== 'object') {
            return false;
        }

        let targetElement = null;

        // Find target element
        if (typeof options.target === 'string') {
            targetElement = document.querySelector(options.target);
        } else if (options.target && options.target.nodeType === 1) {
            targetElement = options.target;
        }

        if (!targetElement) {
            if (console && console.warn) {
                console.warn('[WP oOS] Could not find target chat widget');
            }
            return false;
        }

        // Get the state from the target element
        const state = targetElement.__wpMcpAiChatState;
        if (!state) {
            if (console && console.warn) {
                console.warn('[WP oOS] Target element is not a chat widget');
            }
            return false;
        }

        // Prepare session object
        const session = {
            session_key: options.sessionKey || '',
            assistant_id: options.assistantId || state.config.assistantId,
            messages: Array.isArray(options.messages) ? options.messages : []
        };

        // Load the session into the chat
        loadHistorySessionIntoChat(state, session, null);
        
        return true;
    }

    // Expose public API globally
    if (typeof window !== 'undefined') {
        window.wpMcpAiLoadSession = loadSessionIntoChat;

        /**
         * Console testing utility for GET /chat-transcripts/{session_key} endpoint.
         * 
         * Usage in browser console:
         *   wpMcpAiTestGetTranscript('1e05412c-c158-44c1-8f8d-584c9f29a1e9', 1, 14)
         *   wpMcpAiTestGetTranscript('session-key-here') // user_id and assistant_id optional
         * 
         * @param {string} sessionKey - The session key/UUID to retrieve
         * @param {number|null} userId - Optional user ID (defaults to current user)
         * @param {number|null} assistantId - Optional assistant ID
         * @return {Promise} Promise that resolves with the transcript data
         */
        window.wpMcpAiTestGetTranscript = function(sessionKey, userId, assistantId) {
            if (!sessionKey) {
                console.error('[wpMcpAiTestGetTranscript] Error: sessionKey is required');
                return Promise.reject(new Error('sessionKey parameter is required'));
            }

            // Get the transcripts endpoint from global config
            const baseEndpoint = (globalConfig && globalConfig.transcriptsEndpoint) || '';
            if (!baseEndpoint) {
                console.error('[wpMcpAiTestGetTranscript] Error: transcriptsEndpoint not found in global config');
                console.log('[wpMcpAiTestGetTranscript] Available config:', globalConfig);
                return Promise.reject(new Error('transcriptsEndpoint not configured'));
            }

            // Build URL with session key
            let url = baseEndpoint;
            if (!url.endsWith('/')) {
                url += '/';
            }
            url += encodeURIComponent(sessionKey);

            // Add query parameters
            const params = [];
            if (userId) {
                params.push('user_id=' + encodeURIComponent(userId));
            }
            if (assistantId) {
                params.push('assistant_id=' + encodeURIComponent(assistantId));
            }
            if (params.length > 0) {
                url += '?' + params.join('&');
            }

            // Build headers with nonce
            const headers = {
                'Accept': 'application/json'
            };
            const nonce = (globalConfig && globalConfig.nonce) || '';
            if (nonce) {
                headers['X-WP-Nonce'] = nonce;
            }

            console.log('[wpMcpAiTestGetTranscript] Request:', {
                url: url,
                sessionKey: sessionKey,
                userId: userId || 'not specified',
                assistantId: assistantId || 'not specified',
                headers: headers
            });

            // Make the fetch request
            return fetch(url, {
                method: 'GET',
                headers: headers,
                credentials: 'same-origin'
            }).then(function(response) {
                console.log('[wpMcpAiTestGetTranscript] Response status:', response.status, response.statusText);
                
                return response.json().then(function(data) {
                    if (response.ok) {
                        console.log('[wpMcpAiTestGetTranscript] Success! Data:', data);
                    } else {
                        console.error('[wpMcpAiTestGetTranscript] Error response:', data);
                    }
                    return data;
                }).catch(function(jsonError) {
                    console.error('[wpMcpAiTestGetTranscript] Failed to parse JSON response:', jsonError);
                    throw jsonError;
                });
            }).catch(function(error) {
                console.error('[wpMcpAiTestGetTranscript] Fetch error:', error);
                throw error;
            });
        };
    }

    /**
     * Initialize keyboard shortcuts for the chat interface.
     * 
     * @param {Object} state - Chat state object
     * @param {HTMLElement} container - Chat container element
     * @param {HTMLElement} saveButton - Save button element (optional)
     * @param {HTMLElement} exportButton - Export button element (optional)
     * @param {HTMLElement} newChatButton - New chat button element (optional)
     */
    function initializeKeyboardShortcuts(state, container, saveButton, exportButton, newChatButton) {
        if (!state || !container) {
            return;
        }

        // Track if help modal is open
        let helpModalOpen = false;

        document.addEventListener('keydown', function(event) {
            // Don't trigger shortcuts if user is typing in an input/textarea
            const target = event.target;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
                // Allow Escape to work in inputs/textareas
                if (event.key !== 'Escape' && event.keyCode !== 27) {
                    return;
                }
            }

            const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
            const modKey = isMac ? event.metaKey : event.ctrlKey;

            // Ctrl/Cmd + S: Save conversation
            if (modKey && (event.key === 's' || event.key === 'S') && saveButton && !event.shiftKey) {
                event.preventDefault();
                if (!state.busy && state.conversation && state.conversation.length > 0) {
                    handleSaveConversation(state);
                }
                return;
            }

            // Ctrl/Cmd + E: Export conversation
            if (modKey && (event.key === 'e' || event.key === 'E') && exportButton && !event.shiftKey) {
                event.preventDefault();
                if (!state.busy && state.conversation && state.conversation.length > 0) {
                    handleExportConversation(state);
                }
                return;
            }

            // Ctrl/Cmd + N: New conversation
            if (modKey && (event.key === 'n' || event.key === 'N') && newChatButton && !event.shiftKey) {
                event.preventDefault();
                if (!state.busy) {
                    startNewConversation(state);
                }
                return;
            }

            // Ctrl/Cmd + /: Show keyboard shortcuts help
            if (modKey && event.key === '/') {
                event.preventDefault();
                toggleKeyboardShortcutsHelp(container);
                helpModalOpen = !helpModalOpen;
                return;
            }

            // Escape: Close modals or clear status
            if (event.key === 'Escape' || event.keyCode === 27) {
                if (helpModalOpen) {
                    toggleKeyboardShortcutsHelp(container);
                    helpModalOpen = false;
                } else {
                    clearStatus(container);
                }
                return;
            }
        });
    }

    /**
     * Toggle keyboard shortcuts help modal.
     * 
     * @param {HTMLElement} container - Chat container element
     */
    function toggleKeyboardShortcutsHelp(container) {
        if (!container) {
            return;
        }

        // Check if help modal already exists
        let helpModal = container.querySelector('.wp-mcp-ai-chat__shortcuts-help');
        
        if (helpModal) {
            // Remove existing modal
            helpModal.parentNode.removeChild(helpModal);
            return;
        }

        // Create help modal
        helpModal = document.createElement('div');
        helpModal.className = 'wp-mcp-ai-chat__shortcuts-help';
        
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const modKeyName = isMac ? 'Cmd' : 'Ctrl';

        helpModal.innerHTML = '' +
            '<div class="wp-mcp-ai-chat__shortcuts-overlay"></div>' +
            '<div class="wp-mcp-ai-chat__shortcuts-modal">' +
                '<h3 class="wp-mcp-ai-chat__shortcuts-title">Keyboard Shortcuts</h3>' +
                '<button type="button" class="wp-mcp-ai-chat__shortcuts-close" aria-label="Close">&times;</button>' +
                '<div class="wp-mcp-ai-chat__shortcuts-list">' +
                    '<div class="wp-mcp-ai-chat__shortcut">' +
                        '<kbd class="wp-mcp-ai-chat__shortcut-key">' + modKeyName + ' + S</kbd>' +
                        '<span class="wp-mcp-ai-chat__shortcut-desc">Save conversation</span>' +
                    '</div>' +
                    '<div class="wp-mcp-ai-chat__shortcut">' +
                        '<kbd class="wp-mcp-ai-chat__shortcut-key">' + modKeyName + ' + E</kbd>' +
                        '<span class="wp-mcp-ai-chat__shortcut-desc">Export conversation</span>' +
                    '</div>' +
                    '<div class="wp-mcp-ai-chat__shortcut">' +
                        '<kbd class="wp-mcp-ai-chat__shortcut-key">' + modKeyName + ' + N</kbd>' +
                        '<span class="wp-mcp-ai-chat__shortcut-desc">Start new conversation</span>' +
                    '</div>' +
                    '<div class="wp-mcp-ai-chat__shortcut">' +
                        '<kbd class="wp-mcp-ai-chat__shortcut-key">' + modKeyName + ' + /</kbd>' +
                        '<span class="wp-mcp-ai-chat__shortcut-desc">Show this help</span>' +
                    '</div>' +
                    '<div class="wp-mcp-ai-chat__shortcut">' +
                        '<kbd class="wp-mcp-ai-chat__shortcut-key">Escape</kbd>' +
                        '<span class="wp-mcp-ai-chat__shortcut-desc">Close modals or clear status</span>' +
                    '</div>' +
                '</div>' +
            '</div>';

        container.appendChild(helpModal);

        // Add close button functionality
        const closeButton = helpModal.querySelector('.wp-mcp-ai-chat__shortcuts-close');
        const overlay = helpModal.querySelector('.wp-mcp-ai-chat__shortcuts-overlay');
        
        if (closeButton) {
            closeButton.addEventListener('click', function() {
                toggleKeyboardShortcutsHelp(container);
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function() {
                toggleKeyboardShortcutsHelp(container);
            });
        }
    }

    function init() {
        const containers = document.querySelectorAll('[data-wp-mcp-ai-chat]');
        Array.prototype.forEach.call(containers, function (container) {
            // Skip if already initialized
            if (container.hasAttribute('data-wp-mcp-ai-initialized')) {
                return;
            }

            const instanceId = container.getAttribute('id');
            const config = window.wpMcpAiChatInstances[instanceId];

            if (!config) {
                setStatus(container, getString('missingAssistant', 'Assistant configuration missing.'));
                return;
            }

            const form = container.querySelector('.wp-mcp-ai-chat__form');
            const textarea = container.querySelector('.wp-mcp-ai-chat__input');
            const messagesEl = container.querySelector('.wp-mcp-ai-chat__messages');
            const statusEl = container.querySelector('.wp-mcp-ai-chat__status');
            const attachmentsContainer = container.querySelector('.wp-mcp-ai-chat__attachments');
            const attachmentsList = container.querySelector('.wp-mcp-ai-chat__attachments-list');
            const attachmentsHeader = container.querySelector('.wp-mcp-ai-chat__attachments-header');
            const attachButton = container.querySelector('.wp-mcp-ai-chat__attach');
            const fileInput = container.querySelector('.wp-mcp-ai-chat__file-input');
            const transcribeButton = container.querySelector('.wp-mcp-ai-chat__transcribe');
            const transcribeInput = container.querySelector('.wp-mcp-ai-chat__transcribe-input');
            const voiceChatButton = container.querySelector('.wp-mcp-ai-chat__voice-chat');
            const toolShortcutsContainer = container.querySelector('.' + TOOL_SHORTCUT_CONTAINER_CLASS);
            const toolShortcutsWrapper = container.querySelector('.wp-mcp-ai-chat__tool-shortcuts-wrapper');
            const toolShortcutsToggle = container.querySelector('.wp-mcp-ai-chat__tool-shortcuts-toggle');
            const transcriptToggle = container.querySelector('.wp-mcp-ai-chat__transcript-toggle');
            const newChatButton = container.querySelector('.wp-mcp-ai-chat__new-chat');
            const historyToggle = container.querySelector('.wp-mcp-ai-chat__history-toggle');
            const historyContainer = container.querySelector('.wp-mcp-ai-chat__history');
            const historyStatusEl = container.querySelector('.wp-mcp-ai-chat__history-status');
            const historyList = container.querySelector('.wp-mcp-ai-chat__history-list');
            const historyRefresh = container.querySelector('.wp-mcp-ai-chat__history-refresh');
            const historyLoadMore = container.querySelector('.wp-mcp-ai-chat__history-load-more');

            if (!form || !textarea || !messagesEl || !statusEl) {
                return;
            }

            const instanceConfig = Object.assign({}, config);
            if (!instanceConfig.uploadEndpoint) {
                instanceConfig.uploadEndpoint = globalConfig.uploadEndpoint || '';
            }

            if (!instanceConfig.filesEndpoint) {
                instanceConfig.filesEndpoint = globalConfig.filesEndpoint || '';
            }

            if (!instanceConfig.toolsEndpoint) {
                instanceConfig.toolsEndpoint = globalConfig.toolsEndpoint || '';
            }

            if (!instanceConfig.restUrl) {
                instanceConfig.restUrl = globalConfig.restUrl || '';
            }

            if (!instanceConfig.restNonce) {
                instanceConfig.restNonce = globalConfig.nonce || '';
            }

            if (!instanceConfig.transcriptsEndpoint) {
                instanceConfig.transcriptsEndpoint = globalConfig.transcriptsEndpoint || '';
            }

            if (!instanceConfig.historyPerPage) {
                instanceConfig.historyPerPage = globalConfig.historyPerPage || 20;
            }

            if (!instanceConfig.crawl4aiTaskEndpoint) {
                instanceConfig.crawl4aiTaskEndpoint = '';
            }

            if (!instanceConfig.crawl4aiDefaultPollMs || instanceConfig.crawl4aiDefaultPollMs < 1000) {
                instanceConfig.crawl4aiDefaultPollMs = globalConfig.crawl4aiDefaultPollMs || 5000;
            }

            // Set async tool timeout from instance config or global config (default 5 minutes)
            if (!instanceConfig.asyncToolTimeout || instanceConfig.asyncToolTimeout < ASYNC_TOOL_TIMEOUT_MIN_MS) {
                instanceConfig.asyncToolTimeout = globalConfig.asyncToolTimeout || ASYNC_TOOL_TIMEOUT_DEFAULT_MS;
            }

            if (!Object.prototype.hasOwnProperty.call(instanceConfig, 'canUploadAttachments')) {
                instanceConfig.canUploadAttachments = true;
            } else {
                instanceConfig.canUploadAttachments = !!instanceConfig.canUploadAttachments;
            }

            instanceConfig.allowedImageMimes = normaliseList(instanceConfig.allowedImageMimes);
            instanceConfig.allowedFileMimes = normaliseList(instanceConfig.allowedFileMimes);
            instanceConfig.allowedExtensions = normaliseList(instanceConfig.allowedExtensions);
            if (!Array.isArray(instanceConfig.toolShortcuts)) {
                instanceConfig.toolShortcuts = [];
            }

            if (fileInput && instanceConfig.fileAccept) {
                fileInput.setAttribute('accept', instanceConfig.fileAccept);
            }

            const state = {
                conversation: [],
                busy: false,
                uploading: 0,
                config: instanceConfig,
                canUploadAttachments: instanceConfig.canUploadAttachments,
                originalAssistantId: instanceConfig.assistantId, // Store original assistant_id for transcript saves
                container: container,
                textarea: textarea,
                messagesEl: messagesEl,
                statusEl: statusEl,
                attachmentsContainer: attachmentsContainer,
                attachmentsList: attachmentsList,
                attachmentsHeader: attachmentsHeader,
                attachButton: attachButton,
                fileInput: fileInput,
                transcribeButton: transcribeButton,
                transcribeInput: transcribeInput,
                voiceChatButton: voiceChatButton,
                toolShortcutsContainer: toolShortcutsContainer,
                toolShortcutsWrapper: toolShortcutsWrapper,
                toolShortcutsToggle: toolShortcutsToggle,
                toolShortcutsExpanded: false,
                transcriptToggle: transcriptToggle,
                historyToggle: historyToggle,
                historyContainer: historyContainer,
                historyStatus: historyStatusEl,
                historyList: historyList,
                historyRefresh: historyRefresh,
                historyLoadMore: historyLoadMore,
                transcriptExpanded: false,
                historyVisible: false,
                historyLoaded: false,
                historyLoading: false,
                historyLoadPromise: null,
                historySessions: [],
                historyCurrentPage: 0,
                historyTotalSessions: 0,
                historyPerPage: 20,
                historySessionDetails: Object.create(null),
                activeHistorySessionKey: '',
                pendingAttachments: [],
                attachmentLibrary: {},
                attachmentBlobUrls: {},
                validationNotice: '',
                speechCache: Object.create(null),
                activeSpeech: null,
                pendingCrawlTasks: Object.create(null),
                pendingAsyncTools: Object.create(null),
                transcribing: false,
                isRecording: false,
                recordingStream: null,
                recordedChunks: [],
                mediaRecorder: null,
                recordingShouldProcess: false,
                pendingMessageBundle: [], // Queue for bundling rapid user inputs
                messageBundleTimer: null, // Timer for message bundling delay
            };

            initialiseExistingSpeechButtons(state);
            renderToolShortcuts(state);

            // Initialize tool shortcuts collapsed state
            if (state.toolShortcutsContainer) {
                state.toolShortcutsContainer.hidden = !state.toolShortcutsExpanded;
                
                if (state.toolShortcutsContainer.classList) {
                    if (state.toolShortcutsExpanded) {
                        state.toolShortcutsContainer.classList.remove('wp-mcp-ai-chat__tool-shortcuts--collapsed');
                    } else {
                        state.toolShortcutsContainer.classList.add('wp-mcp-ai-chat__tool-shortcuts--collapsed');
                    }
                }
            }
            if (state.toolShortcutsToggle) {
                state.toolShortcutsToggle.setAttribute('aria-expanded', state.toolShortcutsExpanded ? 'true' : 'false');
                if (state.toolShortcutsToggle.classList) {
                    if (state.toolShortcutsExpanded) {
                        state.toolShortcutsToggle.classList.remove('wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed');
                    } else {
                        state.toolShortcutsToggle.classList.add('wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed');
                    }
                }
            }

            setTranscriptExpanded(state, false);
            setHistoryVisibility(state, false);

            if (historyToggle) {
                historyToggle.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    toggleHistoryVisibility(state);
                });
            }

            if (historyRefresh) {
                historyRefresh.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    refreshHistorySessions(state);
                });
            }

            if (historyLoadMore) {
                historyLoadMore.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    loadMoreHistorySessions(state);
                });
            }

            if (transcriptToggle) {
                transcriptToggle.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    setTranscriptExpanded(state, !state.transcriptExpanded);
                });
            }

            if (toolShortcutsToggle) {
                toolShortcutsToggle.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    toggleToolShortcuts(state);
                });
            }

            if (newChatButton) {
                newChatButton.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    startNewConversation(state);
                });
            }

            // Initialize save, export and quota monitoring UI controls
            const saveButton = container.querySelector('.wp-mcp-ai-chat__save');
            const exportButton = container.querySelector('.wp-mcp-ai-chat__export');
            const quotaMonitor = container.querySelector('.wp-mcp-ai-chat__quota-monitor');
            
            if (saveButton) {
                saveButton.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }
                    handleSaveConversation(state);
                });
            }
            
            if (exportButton) {
                exportButton.addEventListener('click', function (event) {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }
                    handleExportConversation(state);
                });
            }
            
            // Update quota monitor periodically
            if (quotaMonitor) {
                updateQuotaMonitor(quotaMonitor);
                // Update quota monitor every 30 seconds
                setInterval(function() {
                    updateQuotaMonitor(quotaMonitor);
                }, 30000);
            }

            textarea.setAttribute('placeholder', getString('placeholder', textarea.getAttribute('placeholder')));
            form.addEventListener('submit', function (event) {
                handleSubmit(event, state);
            });

            if (attachmentsHeader) {
                attachmentsHeader.textContent = getString('attachmentsLabel', attachmentsHeader.textContent);
            }

            if (!state.canUploadAttachments) {
                if (attachmentsContainer) {
                    attachmentsContainer.hidden = true;
                }

                if (attachButton) {
                    attachButton.hidden = true;
                }

                if (fileInput) {
                    fileInput.disabled = true;
                }

                if (transcribeButton) {
                    transcribeButton.hidden = true;
                    transcribeButton.disabled = true;
                }

                if (transcribeInput) {
                    transcribeInput.disabled = true;
                }
            } else if (attachButton) {
                attachButton.textContent = getString('attachFile', attachButton.textContent);
                attachButton.addEventListener('click', function () {
                    if (state.busy || state.uploading > 0) {
                        return;
                    }

                    if (fileInput) {
                        fileInput.click();
                    }
                });
            }

            if (state.canUploadAttachments && fileInput) {
                fileInput.addEventListener('change', function (event) {
                    handleFileSelection(event, state);
                });
            }

            if (state.canUploadAttachments && transcribeButton) {
                transcribeButton.hidden = false;
                transcribeButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    handleTranscribeButtonClick(state);
                });
            }

            if (state.canUploadAttachments && transcribeInput) {
                transcribeInput.addEventListener('change', function (event) {
                    handleTranscribeFileSelection(event, state);
                });
            }

            if (state.canUploadAttachments && voiceChatButton) {
                voiceChatButton.hidden = false;
                voiceChatButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    handleVoiceChatButtonClick(state);
                });
            }

            updateAttachButtonState(state);
            updateTranscribeButtonState(state);
            updateVoiceChatButtonState(state);

            // Initialize keyboard shortcuts
            initializeKeyboardShortcuts(state, container, saveButton, exportButton, newChatButton);

            // Store state globally for cross-widget communication
            container.__wpMcpAiChatState = state;
            
            // Load and restore conversation from localStorage
            restoreConversationFromStorage(state);

            // Mark container as initialized to prevent double-initialization
            container.setAttribute('data-wp-mcp-ai-initialized', 'true');
        });
    }

    function restoreConversationFromStorage(state) {
        if (!state) {
            return;
        }

        const saved = loadConversationFromStorage(state);

        if (!saved || !Array.isArray(saved.conversation) || !saved.conversation.length) {
            return;
        }

        // Restore session key if available
        // Only restore if no session key exists to avoid overwriting an active session.
        // This ensures we don't mix conversations from different sessions.
        if (saved.sessionKey && !state.config.sessionKey) {
            state.config.sessionKey = saved.sessionKey;
        }

        // Note: We do NOT restore assistantId from localStorage.
        // localStorage is backup storage only. The widget's assistantId should remain
        // fixed to its original configuration (state.originalAssistantId).
        // The saved assistantId is only used for reference/validation.

        // Restore conversation state (excluding system messages which are transient UI feedback)
        state.conversation = saved.conversation.filter(function(message) {
            return message && message.role !== 'system';
        });

        // Render each message in the UI (using filtered state.conversation, not saved.conversation)
        state.conversation.forEach(function (message) {
            if (!message || !message.role) {
                return;
            }

            const role = message.role;
            const content = message.content;
            const display = message.display || null;

            if (role === 'tool') {
                // Render tool responses
                // Use display metadata if available, otherwise build from content
                let toolPayload;
                if (display && typeof display === 'object') {
                    // Use saved display metadata for consistency
                    toolPayload = display;
                } else if (typeof content === 'string' && content.trim()) {
                    // Parse JSON content if it's a string (raw tool result)
                    let parsedContent = content;
                    try {
                        parsedContent = JSON.parse(content);
                    } catch (e) {
                        // Not JSON, use as-is
                    }
                    
                    // Build display payload from parsed content
                    // Try to extract displayable text from various tool result formats
                    let displayText = '';
                    if (typeof parsedContent === 'object' && parsedContent !== null) {
                        // Common patterns for tool result text
                        displayText = parsedContent.text || 
                                     parsedContent.message || 
                                     parsedContent.result || 
                                     parsedContent.summary ||
                                     (typeof parsedContent.content === 'string' ? parsedContent.content : '');
                        
                        // If still no text, try to stringify key fields
                        if (!displayText && Object.keys(parsedContent).length > 0) {
                            displayText = JSON.stringify(parsedContent, null, 2);
                        }
                    } else {
                        displayText = String(parsedContent);
                    }
                    
                    toolPayload = { text: displayText };
                } else {
                    // Fallback for empty content
                    toolPayload = { text: '[Tool result]' };
                }
                
                appendMessage(state.messagesEl, 'tool', toolPayload, false, { state: state });
                return;
            }

            if (role === 'user') {
                // Render user messages
                // Use display metadata if available, otherwise build from content
                let displayPayload;
                
                if (display) {
                    // Use saved display metadata for consistency
                    // Include both message and text fields if available
                    displayPayload = {
                        attachments: display.attachments || []
                    };
                    
                    // Preserve message field if present
                    if (display.message) {
                        displayPayload.message = display.message;
                    }
                    
                    // Preserve text field if present
                    if (display.text) {
                        displayPayload.text = display.text;
                    }
                    
                    // Preserve bubbleType if present
                    if (display.bubbleType) {
                        displayPayload.bubbleType = display.bubbleType;
                    }
                    
                    // Preserve chart HTML for Chart.js visualizations
                    if (display.chartHtml) {
                        displayPayload.chartHtml = display.chartHtml;
                        displayPayload.chartWidth = display.chartWidth || 800;
                        displayPayload.chartHeight = display.chartHeight || 400;
                    }
                } else {
                    // Fallback: build display payload from content
                    displayPayload = { text: '', attachments: [] };

                    if (typeof content === 'string') {
                        displayPayload.text = content;
                    } else if (Array.isArray(content)) {
                        // Extract text from structured content and build attachment links
                        const textParts = [];
                        content.forEach(function (segment) {
                            if (segment && segment.type === 'text' && segment.text) {
                                textParts.push(segment.text);
                            } else if (segment && (segment.type === 'input_image' || segment.type === 'image_url')) {
                                // Build attachment link for image
                                // Handle both segment.url and segment.image_url formats
                                let imageUrl = segment.url || '';
                                if (!imageUrl && segment.image_url) {
                                    if (typeof segment.image_url === 'string') {
                                        imageUrl = segment.image_url;
                                    } else if (segment.image_url.url) {
                                        imageUrl = segment.image_url.url;
                                    }
                                }
                                
                                if (imageUrl) {
                                    displayPayload.attachments.push({
                                        url: imageUrl,
                                        label: segment.caption || segment.name || 'Image attachment',
                                        downloadName: segment.name || '',
                                        meta: '',
                                    });
                                } else {
                                    textParts.push('[Image attachment]');
                                }
                            } else if (segment && segment.type === 'input_file') {
                                // Build attachment link for file
                                if (segment.url) {
                                    displayPayload.attachments.push({
                                        url: segment.url,
                                        label: segment.display_name || segment.name || 'File attachment',
                                        downloadName: segment.display_name || segment.name || '',
                                        meta: '',
                                    });
                                } else {
                                    textParts.push('[File attachment]');
                                }
                            }
                        });
                        displayPayload.text = textParts.join('\n');
                    }
                }

                appendMessage(state.messagesEl, 'user', displayPayload, false, { state: state });
                return;
            }

            if (role === 'assistant') {
                // Render assistant messages
                // Use display metadata if available, otherwise build from content
                let assistantPayload;
                
                // Check if this message has tool_calls (either in message or display metadata)
                const hasToolCalls = message.tool_calls || (display && display.tool_calls);
                
                if (display) {
                    // Use saved display metadata for consistency
                    // Include both message and text fields if available
                    assistantPayload = {
                        attachments: display.attachments || []
                    };
                    
                    // Preserve message field if present
                    if (display.message) {
                        assistantPayload.message = display.message;
                    }
                    
                    // Preserve text field if present
                    if (display.text) {
                        assistantPayload.text = display.text;
                    }
                    
                    // Preserve bubbleType if present
                    if (display.bubbleType) {
                        assistantPayload.bubbleType = display.bubbleType;
                    }
                    
                    // Preserve chart HTML for Chart.js visualizations
                    if (display.chartHtml) {
                        assistantPayload.chartHtml = display.chartHtml;
                        assistantPayload.chartWidth = display.chartWidth || 800;
                        assistantPayload.chartHeight = display.chartHeight || 400;
                    }
                    
                    // Preserve tool_calls in display for rendering context
                    if (display.tool_calls) {
                        assistantPayload.tool_calls = display.tool_calls;
                    }
                } else {
                    // Fallback: build from content
                    // If content is an array (structured content with image_url blocks), 
                    // pass it as content so normaliseContent can handle it properly
                    assistantPayload = Array.isArray(content) 
                        ? { content: content }
                        : { text: content || '' };
                }
                
                // For speech, combine message and text if both present
                let textForSpeech = '';
                if (display) {
                    // Use preserved speechText if available (preferred for accuracy)
                    if (display.speechText) {
                        textForSpeech = display.speechText;
                    } else {
                        const messageStr = display.message || '';
                        const textStr = display.text || '';
                        if (messageStr && textStr && messageStr !== textStr) {
                            textForSpeech = messageStr + '\n\n' + textStr;
                        } else {
                            textForSpeech = messageStr || textStr;
                        }
                    }
                }
                if (!textForSpeech) {
                    textForSpeech = Array.isArray(content) ? normaliseContent(content) : (content || '');
                }
                
                // Extract persisted badge data (usage, cost, capability flags)
                const usage = display && display.usage ? display.usage : null;
                const cost = display && display.cost ? display.cost : null;
                const capabilityFlags = display && display.capabilityFlags ? display.capabilityFlags : null;
                
                // Determine if we have content to render
                // Assistant messages with tool_calls but no text/attachments are still valid
                // but we skip rendering an empty bubble (the tool result will show content)
                const hasTextContent = (assistantPayload.text && assistantPayload.text.trim()) || 
                                      (assistantPayload.message && assistantPayload.message.trim());
                const hasAttachments = assistantPayload.attachments && assistantPayload.attachments.length > 0;
                
                // Only render if there's content to display
                // Messages with only tool_calls (no text/attachments) are kept in conversation
                // for API continuity but don't need a visible bubble
                if (hasTextContent || hasAttachments) {
                    appendMessage(state.messagesEl, 'assistant', assistantPayload, true, {
                        state: state,
                        speech: {
                            state: state,
                            text: textForSpeech,
                        },
                        usage: usage,
                        cost: cost,
                        capabilityFlags: capabilityFlags
                    });
                }
                return;
            }
        });

        // Scroll to bottom after restoration
        if (state.messagesEl) {
            scrollBatcher.scrollToBottom(state.messagesEl);
        }
    }

    function handleSubmit(event, state) {
        event.preventDefault();
        if (state.busy) {
            return;
        }

        if (state.uploading > 0) {
            setStatus(state.container, getString('uploadInProgress', 'Please wait for uploads to finish before sending.'));
            return;
        }

        const inputValue = state.textarea.value;
        const trimmedMessage = inputValue.trim();
        const pending = state.pendingAttachments.slice();
        const hasAttachments = pending.length > 0;

        if (!trimmedMessage && !hasAttachments) {
            setStatus(state.container, getString('emptyMessage', 'Enter a message before sending.'));
            return;
        }

        // Log send button click
        if (window.console && console.log) {
            console.log('[WP oOS] User clicked send:', {
                message_length: trimmedMessage.length,
                has_attachments: hasAttachments,
                attachment_count: pending.length,
                assistant_id: state.config ? state.config.assistantId : null,
                conversation_length: state.conversation.length
            });
        }

        state.textarea.value = '';

        const segments = [];
        if (trimmedMessage) {
            segments.push({
                type: 'text',
                text: inputValue,
            });
        }

        const displayAttachments = [];

        pending.forEach(function (attachment) {
            const segment = createSegmentFromAttachment(attachment);
            if (segment) {
                segments.push(segment);
                // Debug logging for attachment segments
                if (window.console && console.log) {
                    console.log('[WP oOS] Created segment from attachment:', {
                        attachment_id: attachment.id,
                        fileId: attachment.fileId,
                        segment_type: segment.type,
                        segment_attachment_id: segment.attachment_id,
                        has_url: !!segment.url,
                        has_name: !!segment.name
                    });
                }
            } else {
                // Warning if segment creation failed
                if (window.console && console.warn) {
                    console.warn('[WP oOS] Failed to create segment from attachment:', attachment);
                }
            }

            const displayAttachment = buildDisplayAttachment(attachment, state);
            if (displayAttachment) {
                displayAttachments.push(displayAttachment);
            }
        });

        let userMessageElement = null;
        const displayPayload = {
            text: inputValue,
            attachments: displayAttachments,
        };

        if (trimmedMessage || displayAttachments.length) {
            userMessageElement = appendMessage(state.messagesEl, 'user', displayPayload, false, { state: state });
        }

        let payloadContent;
        if (segments.length === 1 && segments[0].type === 'text') {
            payloadContent = segments[0].text;
        } else {
            payloadContent = segments;
        }

        // Debug logging for payload content
        if (window.console && console.log) {
            console.log('[WP oOS] Created payloadContent:', {
                is_array: Array.isArray(payloadContent),
                segment_count: Array.isArray(payloadContent) ? payloadContent.length : 0,
                segments: Array.isArray(payloadContent) ? payloadContent : null
            });
        }

        const previousConversationLength = state.conversation.length;
        
        // Extract display metadata from rendered message
        const displayMetadata = extractDisplayMetadata(userMessageElement, displayPayload);
        
        // Create conversation message with display metadata for proper restoration
        const userMessage = createConversationMessage('user', payloadContent, displayMetadata);
        
        state.conversation.push(userMessage);

        // Save conversation immediately after user message
        saveConversationToStorage(state);

        state.pendingAttachments = [];
        renderPendingAttachments(state);
        updateAttachButtonState(state);

        // Use message bundling if optimizations are enabled
        if (OPTIMIZATIONS_ENABLED) {
            queueMessageForBundling(state, {
                previousConversationLength: previousConversationLength,
                pendingAttachments: pending,
                messageElement: userMessageElement,
                inputValue: inputValue,
            });
        } else {
            // In debug mode, send immediately without bundling
            sendChat(state, {
                previousConversationLength: previousConversationLength,
                pendingAttachments: pending,
                messageElement: userMessageElement,
                inputValue: inputValue,
            });
        }
    }

    /**
     * Queue a message for bundling with other rapid inputs.
     * If another message arrives within MESSAGE_BUNDLE_DELAY_MS, they will be sent together.
     * 
     * Note: The form is disabled on the first queued message to provide consistent UI feedback.
     * This prevents user confusion during the bundling delay and ensures the form state
     * matches what the user expects (message has been submitted and is being processed).
     */
    function queueMessageForBundling(state, submissionContext) {
        // Track if this is the first message in the bundle
        const isFirstMessage = state.pendingMessageBundle.length === 0;

        // Clear any existing timer
        if (state.messageBundleTimer) {
            clearTimeout(state.messageBundleTimer);
            state.messageBundleTimer = null;
        }

        // Add this submission to the bundle queue
        state.pendingMessageBundle.push(submissionContext);

        // On first message, disable form to provide consistent UI feedback
        // This prevents user confusion during the bundling delay
        // Note: We don't set state.busy here because the actual sending hasn't started yet
        // The form is disabled to match user expectations (message submitted, awaiting response)
        if (isFirstMessage) {
            disableForm(state, true);
        }

        // Set visual indicator that messages are being bundled
        setStatus(state.container, getString('bundlingMessages', 'Preparing to send…'));

        // Set timer to send all bundled messages
        state.messageBundleTimer = setTimeout(function() {
            sendBundledMessages(state);
        }, MESSAGE_BUNDLE_DELAY_MS);
    }

    /**
     * Send all messages that have been bundled during the delay window.
     */
    function sendBundledMessages(state) {
        // Clear the timer reference
        state.messageBundleTimer = null;

        // Get all bundled submissions
        const bundledSubmissions = state.pendingMessageBundle.slice();
        state.pendingMessageBundle = [];

        if (bundledSubmissions.length === 0) {
            return;
        }

        // For multiple rapid messages, we already added them to conversation
        // during handleSubmit, so we just need to send the current conversation state.
        // Use the first submission's context for restoration if needed.
        // Note: On error, using the first submission's previousConversationLength will
        // correctly remove ALL bundled messages (since they were all added after that point).
        // However, only the first message's input can be restored to the textarea.
        // This trade-off is acceptable since errors are rare and it's better to restore
        // one message than lose all of them.
        const firstSubmission = bundledSubmissions[0];

        sendChat(state, firstSubmission);
    }

    function sendChat(state, submissionContext) {
        state.busy = true;
        disableForm(state, true);
        setStatus(state.container, {
            message: getString('sending', 'Sending…'),
            type: 'processing',
            showTime: true,
            startTime: Date.now()
        });

        // Filter out system messages before sending to API
        // System messages are UI feedback only and should not be sent to the AI
        // This prevents breaking the agentic workflow with error messages and notices
        const filteredMessages = state.conversation.filter(function(message) {
            return message && message.role !== 'system';
        });

        // Strip display-only metadata (including blob:/data: URLs from attachments) before sending to API
        // The REST API schema only accepts specific fields and will reject extra properties
        // Also strips large file content from tool results (keeping attachment_id and url)
        const cleanMessages = filteredMessages
            .map(stripMessageDisplayMetadata)
            .filter(function(msg) { return msg !== null; });

        // Debug logging to trace attachment segments
        if (window.console && console.log) {
            console.log('[WP oOS] Sending messages to API:', JSON.stringify(cleanMessages, null, 2));
        }

        const payload = {
            assistant_id: state.originalAssistantId || state.config.assistantId,
            messages: cleanMessages,
            save_transcript: state.config.saveTranscript !== false,
        };

        if (state.config.sessionKey) {
            payload.session_key = state.config.sessionKey;
        }

        function finalize() {
            state.busy = false;
            disableForm(state, false);
        }

        // Check if streaming is enabled
        const enableStreaming = state.config.enableStreaming === true;

        if (enableStreaming) {
            // Add stream flag to payload
            payload.stream = true;
            
            return sendChatStreaming(state, payload, submissionContext, finalize);
        }

        // Non-streaming request (original implementation)
        return fetch(state.config.messagesEndpoint, {
            method: 'POST',
            headers: buildJsonHeaders(state),
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
            .then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return null;
                    })
                    .then(function (data) {
                        if (!response.ok) {
                            throw response;
                        }
                        return data;
                    });
            })
            .then(function (data) {
                return handleChatResponse(state, data);
            })
            .then(function (result) {
                saveConversationToStorage(state);
                // Note: handleChatResponse already saves to CCT internally
                finalize();
                return result;
            }, function (error) {
                handleError(state, error);
                restoreSubmissionState(state, submissionContext);
                finalize();
            });
    }

    function sendChatStreaming(state, payload, submissionContext, finalize) {
        const headers = buildJsonHeaders(state);
        headers['Accept'] = 'text/event-stream';

        let streamingMessageElement = null;
        let streamCompleted = false;

        // Diagnostic logging (Separation of Concerns - delegated to logger utility)
        streamingLogger.logRequestStart({
            endpoint: state.config.messagesEndpoint,
            assistantId: payload.assistant_id,
            messageCount: payload.messages ? payload.messages.length : 0,
            streamEnabled: payload.stream,
            hasSessionKey: !!payload.session_key
        });

        // Create a placeholder message element for streaming content
        function createStreamingMessage() {
            if (!streamingMessageElement) {
                // Create the message element structure directly for streaming
                // We can't use appendMessage with empty text as it returns null
                const entry = document.createElement('div');
                entry.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--assistant';
                entry.textContent = ''; // Empty initially, will be filled as chunks arrive
                
                state.messagesEl.appendChild(entry);
                
                streamingMessageElement = entry;
                
                // Scroll to show the new message
                scrollBatcher.scrollToBottom(state.messagesEl);
                
                if (window.console && console.log) {
                    console.log('[WP oOS] Created streaming message element');
                }
            }
            return streamingMessageElement;
        }

        // Update status area with streaming preview (Separation of Concerns)
        function updateStreamingStatus(content) {
            // Always update status when called, even if content is empty
            // This ensures status transitions from "thinking" to "streaming" immediately
            if (content && content.length > 0) {
                const preview = content.length > STREAMING_STATUS_PREVIEW_LENGTH 
                    ? content.substring(0, STREAMING_STATUS_PREVIEW_LENGTH) + '…' 
                    : content;
                
                setStatus(state.container, {
                    message: preview,
                    type: 'text-stream',
                    showTime: false
                });
            } else {
                // Content is empty, but streaming has started - show generic streaming status
                setStatus(state.container, {
                    message: getString('streaming', 'Streaming response...'),
                    type: 'streaming',
                    showTime: false
                });
            }
        }

        // Update the streaming message bubble with new content
        function updateStreamingMessage(content) {
            // Ensure content is a string
            const safeContent = content != null ? String(content) : '';
            
            // ALWAYS log streaming updates for debugging (even when DEBUG_MODE is off)
            if (window.console && console.log) {
                console.log('[WP oOS] updateStreamingMessage called:', {
                    contentLength: safeContent.length,
                    contentSample: safeContent.substring(0, 50) + (safeContent.length > 50 ? '...' : ''),
                    elementExists: !!streamingMessageElement,
                    elementInDOM: streamingMessageElement ? streamingMessageElement.parentNode !== null : false
                });
            }
            
            if (!streamingMessageElement) {
                createStreamingMessage();
            }

            // Concern 1: Update message bubble content
            if (streamingMessageElement) {
                // Render markdown progressively during streaming
                // The renderMarkdown function escapes HTML before processing, making it XSS-safe
                const renderedContent = renderMarkdown(safeContent);
                if (renderedContent && renderedContent.trim()) {
                    streamingMessageElement.innerHTML = renderedContent;
                } else {
                    // Fallback to escaped text if markdown rendering fails
                    streamingMessageElement.innerHTML = escapeHtml(safeContent).replace(/\n/g, '<br />');
                }
                
                // VERIFY the content was actually set
                if (window.console && console.log) {
                    console.log('[WP oOS] After setting innerHTML:', {
                        elementTextContent: streamingMessageElement.textContent,
                        elementInnerHTML: streamingMessageElement.innerHTML,
                        elementOuterHTML: streamingMessageElement.outerHTML.substring(0, 200)
                    });
                }
                
                // Concern 3: Auto-scroll to keep content visible
                scrollBatcher.scrollToBottom(state.messagesEl);
            } else if (window.console && console.warn) {
                console.warn('[WP oOS] Streaming message element not found after creation attempt');
            }

            // Concern 2: Update status area (delegated to separate function)
            // This is intentionally OUTSIDE the streamingMessageElement check
            // because the status preview should work independently of the message bubble
            updateStreamingStatus(safeContent);
        }

        return fetch(state.config.messagesEndpoint, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
            .then(function (response) {
                // Diagnostic logging (Separation of Concerns)
                streamingLogger.logResponseReceived(response);
                
                if (!response.ok) {
                    // Diagnostic logging (Separation of Concerns)
                    streamingLogger.logHttpError(response);
                    throw response;
                }

                // Check if response is actually SSE
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('text/event-stream')) {
                    // Fallback to non-streaming if SSE not supported
                    // Clean up streaming message element if it was created
                    if (streamingMessageElement && streamingMessageElement.parentNode) {
                        streamingMessageElement.parentNode.removeChild(streamingMessageElement);
                        streamingMessageElement = null;
                    }
                    
                    return response.json().then(function (data) {
                        return handleChatResponse(state, data);
                    });
                }

                // Create the streaming message bubble immediately when SSE streaming begins
                // This ensures users see where the streaming text will appear
                createStreamingMessage();

                // Also update status immediately to show streaming has started
                // This provides immediate feedback in the status section before first chunk arrives
                updateStreamingStatus('');

                return processSSEStream(state, response, updateStreamingMessage);
            })
            .then(function (streamResult) {
                streamCompleted = true;

                // Handle final message if available
                if (streamResult && streamResult.finalData) {
                    // If we have streamed content, inject it into the finalData structure
                    // This ensures the complete streamed text is used for rendering
                    // instead of potentially incomplete message.content from the API
                    if (streamResult.content && streamResult.content.trim()) {
                        // Ensure the data structure exists
                        if (!streamResult.finalData.data) {
                            streamResult.finalData.data = {};
                        }
                        if (!streamResult.finalData.data.choices) {
                            streamResult.finalData.data.choices = [{}];
                        }
                        if (!streamResult.finalData.data.choices[0]) {
                            streamResult.finalData.data.choices[0] = {};
                        }
                        if (!streamResult.finalData.data.choices[0].message) {
                            streamResult.finalData.data.choices[0].message = { role: 'assistant' };
                        }
                        
                        // Use the streamed content as the message content
                        // This preserves what the user saw during streaming
                        streamResult.finalData.data.choices[0].message.content = streamResult.content;
                        
                        if (DEBUG_MODE && window.console && console.log) {
                            console.log('[WP oOS] Injected streamed content into finalData:', {
                                contentLength: streamResult.content.length,
                                contentSample: streamResult.content.substring(0, 100)
                            });
                        }
                    }
                    
                    // Remove temporary streaming message BEFORE calling handleChatResponse
                    // This prevents duplicate messages from appearing in the chat.
                    // The streamed content has already been captured in streamResult.content
                    // and injected into finalData, so the final message will have the same content.
                    if (streamingMessageElement && streamingMessageElement.parentNode) {
                        streamingMessageElement.parentNode.removeChild(streamingMessageElement);
                        streamingMessageElement = null;
                    }
                    
                    // Process the final response data using standard handler
                    return handleChatResponse(state, streamResult.finalData).then(function() {
                        saveConversationToStorage(state);
                        // Note: handleChatResponse already saves to CCT internally
                        finalize();
                        // Add brief delay before clearing status to allow completion message to be visible
                        // This ensures users see "Tool completed successfully." before the status clears
                        setTimeout(function() {
                            clearStatus(state.container);
                        }, 1500);
                        return streamResult;
                    });
                }

                // Fallback: Add accumulated content to conversation if no final data
                if (streamResult && streamResult.content) {
                    // Detect truncated responses once (avoid duplicate detection)
                    const isTruncated = isTruncatedByOrchestration(streamResult.content);
                    
                    // Update the streaming message with proper formatting
                    if (streamingMessageElement) {
                        // streamingMessageElement is now the bubble itself (merged structure)
                        
                        // Render content with proper structure based on type
                        if (isTruncated) {
                            // Clear existing content and render as truncated response with collapsible details
                            streamingMessageElement.innerHTML = '';
                            streamingMessageElement.classList.add('wp-mcp-ai-chat__bubble--truncated');
                            streamingMessageElement.appendChild(createTruncatedResponseElement(streamResult.content));
                        } else {
                            // Standard markdown rendering for regular messages
                            // Preserve original content before rendering in case of failure
                            // Note: We use streamResult.content (the accumulated content) rather than
                            // streamingMessageElement.textContent to ensure we have the full content
                            const renderedHtml = renderMarkdown(streamResult.content);
                            
                            // Only update innerHTML if rendering produced content
                            // This prevents the bubble from becoming empty if rendering fails
                            if (renderedHtml && renderedHtml.trim()) {
                                streamingMessageElement.innerHTML = renderedHtml;
                            } else {
                                // Fallback: keep original content as escaped text if rendering fails
                                if (window.console && console.warn) {
                                    console.warn('[WP MCP AI] Markdown rendering returned empty, preserving original content');
                                }
                                // Convert textContent to escaped HTML to preserve the content
                                streamingMessageElement.innerHTML = escapeHtml(streamResult.content).replace(/\n/g, '<br />');
                            }
                        }
                        
                        attachSpeechButton(streamingMessageElement, state, streamResult.content);
                        attachCopyButton(streamingMessageElement, streamResult.content);
                        attachDeleteButton(streamingMessageElement, state, 'assistant');

                        // Auto-play speech if voice chat mode is active
                        if (state.voiceChatModeActive && streamingMessageElement) {
                            setTimeout(function() {
                                const speechButton = streamingMessageElement.querySelector('.' + SPEECH_BUTTON_CLASS);
                                if (speechButton && speechButton.dataset && speechButton.dataset.speechText) {
                                    handleSpeechButtonClick(state, speechButton);
                                }
                                // Reset voice chat mode after auto-playing
                                state.voiceChatModeActive = false;
                            }, 300);
                        }
                    }

                    // Create assistant message with display metadata
                    const displayPayload = { text: streamResult.content };
                    
                    // Set bubble type on the element before extracting metadata
                    // This ensures truncated responses preserve their bubble type in storage
                    if (streamingMessageElement && isTruncated) {
                        streamingMessageElement.dataset.bubbleType = 'truncated';
                        displayPayload.bubbleType = 'truncated';
                    }
                    
                    const displayMetadata = extractDisplayMetadata(streamingMessageElement, displayPayload);
                    const assistantMessage = createConversationMessage('assistant', streamResult.content, displayMetadata);
                    
                    state.conversation.push(assistantMessage);
                    
                    // Clear thinking text buffer
                    state.thinkingText = null;
                    // Clear streaming content buffer
                    state.streamingContent = null;
                }

                saveConversationToStorage(state);
                
                // Also save to CCT if available (silent, non-blocking)
                // This ensures the final assistant response persists across page reloads
                saveConversationToCCT(state, { silent: true });
                
                finalize();
                // Add brief delay before clearing status to allow completion message to be visible
                setTimeout(function() {
                    clearStatus(state.container);
                }, 1500);
                return streamResult;
            })
            .catch(function (error) {
                if (!streamCompleted) {
                    // Diagnostic logging (Separation of Concerns)
                    streamingLogger.logFetchFailure(error, {
                        endpoint: state.config.messagesEndpoint,
                        assistantId: payload.assistant_id,
                        streamCompleted: streamCompleted
                    });
                    
                    handleError(state, error);
                    restoreSubmissionState(state, submissionContext);
                    
                    // Remove the incomplete streaming message
                    if (streamingMessageElement && streamingMessageElement.parentNode) {
                        streamingMessageElement.parentNode.removeChild(streamingMessageElement);
                    }
                    
                    // Clear streaming buffers on error
                    state.thinkingText = null;
                    state.streamingContent = null;
                }
                finalize();
            });
    }

    function processSSEStream(state, response, updateCallback) {
        // Diagnostic logging (Separation of Concerns)
        streamingLogger.logStreamStart();
        
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let fullContent = '';
        
        // Track the final data structure containing tool_results and complete response
        // This is captured when we receive an SSE event with data.data
        let capturedFinalData = null;
        
        // Initialize streaming content state variable
        state.streamingContent = '';

        function readChunk() {
            return reader.read().then(function (result) {
                if (result.done) {
                    // Diagnostic logging (Separation of Concerns)
                    streamingLogger.logStreamComplete({
                        contentLength: fullContent.length,
                        contentSample: fullContent.substring(0, 100),
                        hasFinalData: !!capturedFinalData
                    });
                    
                    // Include captured finalData if we have it
                    // This ensures tool_results and complete response data are preserved
                    if (capturedFinalData) {
                        return { content: fullContent, finalData: capturedFinalData };
                    }
                    return { content: fullContent };
                }

                buffer += decoder.decode(result.value, { stream: true });

                // Process complete SSE events (separated by \n\n)
                const events = buffer.split('\n\n');
                buffer = events.pop() || ''; // Keep incomplete event in buffer

                for (let i = 0; i < events.length; i++) {
                    const eventBlock = events[i];
                    if (!eventBlock.trim()) {
                        continue;
                    }

                    // Parse SSE event block
                    const lines = eventBlock.split('\n');
                    let eventType = '';
                    let eventData = '';

                    for (let j = 0; j < lines.length; j++) {
                        const line = lines[j];
                        if (line.startsWith('event: ')) {
                            eventType = line.substring(7).trim();
                        } else if (line.startsWith('data: ')) {
                            eventData = line.substring(6);
                        }
                    }

                    // Handle [DONE] marker
                    if (eventData.trim() === '[DONE]') {
                        // Include captured finalData if we have it
                        if (capturedFinalData) {
                            return { content: fullContent, finalData: capturedFinalData };
                        }
                        return { content: fullContent };
                    }

                    // Skip empty event data
                    if (!eventData || !eventData.trim()) {
                        continue;
                    }

                    try {
                        const data = JSON.parse(eventData);
                        
                        if (DEBUG_MODE) {
                            if (window.console && console.log) {
                                console.log('[WP oOS] SSE event:', {
                                    eventType: eventType || '(none)',
                                    hasData: !!data
                                });
                            }
                        }

                        // Handle different event types
                        if (eventType === 'status') {
                            handleStatusEvent(state, data);
                        } else if (eventType === 'tool_execution') {
                            handleToolExecutionEvent(state, data);
                        } else if (eventType === 'error') {
                            handleErrorEvent(state, data);
                        } else if (eventType === 'message' || !eventType) {
                            // Handle streaming responses from different AI providers
                            let contentChunk = null;
                            let thinkingChunk = null;
                            
                            // Log SSE message events for debugging both sync and async tool results
                            // SSE message format differs between streaming chunks and final responses:
                            // - Streaming chunks: data.choices[0].delta.content (top-level choices)
                            // - Final response: data.data.choices[0].message (nested in data.data)
                            // - Tool results: data.tool_results[] (sync completed or async pending)
                            if (window.console && console.log) {
                                // For final responses, choices are in data.data.choices, not data.choices
                                const isFinalMessage = !!(data.data);
                                const hasStreamingChoices = !!(data.choices);
                                const hasFinalChoices = !!(data.data && data.data.choices);
                                const hasToolResults = !!(data.tool_results && Array.isArray(data.tool_results) && data.tool_results.length > 0);
                                
                                console.log('[WP oOS] SSE message event received:', {
                                    isFinalMessage: isFinalMessage,
                                    hasChoices: isFinalMessage ? hasFinalChoices : hasStreamingChoices,
                                    hasDelta: !!(data.choices && data.choices[0] && data.choices[0].delta),
                                    hasContent: !!(data.choices && data.choices[0] && data.choices[0].delta && data.choices[0].delta.content),
                                    hasData: isFinalMessage,
                                    hasToolResults: hasToolResults,
                                    dataKeys: Object.keys(data),
                                    fullData: data
                                });
                            }
                            
                            // INDUSTRY BEST PRACTICE: Check for final complete response FIRST
                            // If data.data exists, this is the final message (not a streaming chunk).
                            // Skip streaming chunk extraction to prevent "choices" from overwriting content.
                            // The final response has choices[0].message.content nested in data.data,
                            // NOT choices[0].delta.content at the top level.
                            const isFinalResponse = !!(data.data);
                            
                            // Only extract streaming chunks if this is NOT the final response
                            // OpenAI format: choices[0].delta.content
                            if (!isFinalResponse && data.choices && data.choices[0]) {
                                const delta = data.choices[0].delta;
                                if (delta) {
                                    // Main content
                                    if (delta.content) {
                                        contentChunk = delta.content;
                                        if (window.console && console.log) {
                                            console.log('[WP oOS] Content chunk extracted:', contentChunk.substring(0, 50));
                                        }
                                    }
                                    // OpenAI o1 models may have reasoning_content (if exposed in future)
                                    if (delta.reasoning_content && typeof delta.reasoning_content === 'string') {
                                        thinkingChunk = delta.reasoning_content;
                                    }
                                    // Alternative reasoning field
                                    if (delta.reasoning && typeof delta.reasoning === 'string') {
                                        thinkingChunk = delta.reasoning;
                                    }
                                }
                            }
                            // Gemini format: candidates[0].content.parts[0].text
                            else if (!isFinalResponse && data.candidates && data.candidates[0]) {
                                const candidate = data.candidates[0];
                                if (candidate.content && candidate.content.parts) {
                                    // Check for thinking/thought parts
                                    for (let p = 0; p < candidate.content.parts.length; p++) {
                                        const part = candidate.content.parts[p];
                                        // Gemini 2.0 Flash Thinking mode
                                        if (part.thought && typeof part.thought === 'string') {
                                            thinkingChunk = part.thought;
                                        } else if (part.text && typeof part.text === 'string') {
                                            contentChunk = part.text;
                                        }
                                    }
                                }
                            }
                            // Anthropic format: May have thinking in content blocks
                            else if (!isFinalResponse && data.type === 'content_block_delta' && data.delta) {
                                if (data.delta.type === 'text_delta' && data.delta.text) {
                                    contentChunk = data.delta.text;
                                }
                                // Anthropic extended thinking (if exposed)
                                if (data.delta.type === 'thinking_delta' && data.delta.thinking) {
                                    thinkingChunk = data.delta.thinking;
                                }
                            }
                            // Ollama/LM Studio format: message.content or response
                            else if (!isFinalResponse && data.message && data.message.content) {
                                contentChunk = data.message.content;
                                // Check if message has thinking field (some models may support this)
                                if (data.message.thinking && typeof data.message.thinking === 'string') {
                                    thinkingChunk = data.message.thinking;
                                }
                            }
                            else if (!isFinalResponse && data.response) {
                                contentChunk = data.response;
                            }
                            // Direct content field (only for streaming, not final)
                            else if (!isFinalResponse && data.content && typeof data.content === 'string') {
                                contentChunk = data.content;
                            }
                            // Direct text field (only for streaming, not final)
                            else if (!isFinalResponse && data.text && typeof data.text === 'string') {
                                contentChunk = data.text;
                            }
                            
                            // Check for thinking/reasoning in common fields across providers (only for streaming)
                            if (!isFinalResponse && !thinkingChunk) {
                                // Generic thinking field
                                if (data.thinking && typeof data.thinking === 'string') {
                                    thinkingChunk = data.thinking;
                                }
                                // Generic reasoning field
                                if (data.reasoning && typeof data.reasoning === 'string') {
                                    thinkingChunk = data.reasoning;
                                }
                            }
                            
                            // Display thinking text in bubble AND status section if present
                            if (thinkingChunk) {
                                // Initialize or append to thinking buffer
                                if (!state.thinkingText) {
                                    state.thinkingText = '';
                                }
                                state.thinkingText += thinkingChunk;
                                
                                // Update status with thinking text
                                setStatus(state.container, {
                                    message: state.thinkingText,
                                    type: 'text-stream',
                                    showTime: false
                                });
                                
                                // ALSO update the streaming bubble with thinking text
                                // This ensures users can see the AI's reasoning process
                                updateCallback(state.thinkingText);
                            }
                            
                            // Check for final response with complete data first
                            // This ensures tool_results and structured content are captured
                            if (data.data) {
                                // Capture the final data for use when stream ends
                                // This preserves tool_results and complete response structure
                                capturedFinalData = data;
                                
                                // ALWAYS extract text from final response to ensure we have the complete message
                                // This handles cases where streaming chunks weren't sent OR were incomplete
                                let finalText = '';
                                
                                // Try to extract text from data.data structure
                                // Handle OpenAI/Ollama format - choices[0].message.content
                                if (data.data.choices && data.data.choices[0] && data.data.choices[0].message && data.data.choices[0].message.content) {
                                    finalText = extractTextFromContent(data.data.choices[0].message.content);
                                } 
                                // Handle generic content field
                                else if (data.data.content) {
                                    finalText = extractTextFromContent(data.data.content);
                                } 
                                // Handle response field
                                else if (data.data.response) {
                                    finalText = extractTextFromContent(data.data.response);
                                } 
                                // Handle Gemini format - candidates[0].content.parts
                                else if (data.data.candidates && data.data.candidates[0] && data.data.candidates[0].content && data.data.candidates[0].content.parts) {
                                    // Gemini format - optimize by caching parts array reference
                                    const parts = data.data.candidates[0].content.parts;
                                    for (let p = 0; p < parts.length; p++) {
                                        const part = parts[p];
                                        if (part.text && typeof part.text === 'string') {
                                            finalText += part.text;
                                        }
                                    }
                                }
                                
                                // Handle tool_results from PHP's final 'message' SSE event.
                                // PHP (class-wp-mcp-ai-rest.php line ~3341-3345) sends:
                                //   $payload['tool_results'] = $tool_result_messages;
                                //   $this->send_sse_event('message', $payload);
                                // 
                                // BUG FIX: Previously this was inside `if (!finalText && ...)` which meant
                                // when both message content AND tool_results were present, the status would
                                // remain stuck on "Tool is processing..." instead of updating to completion.
                                // This now runs REGARDLESS of whether finalText was extracted from message content.
                                if (data.tool_results && Array.isArray(data.tool_results) && data.tool_results.length > 0) {
                                    // Check if any tool results are async/pending (e.g., Veo video generation)
                                    let hasAsyncPending = false;
                                    
                                    for (const toolResult of data.tool_results) {
                                        if (!toolResult || !toolResult.content) {
                                            continue;
                                        }
                                        
                                        // Parse and check if this is an async tool that's still pending
                                        const parsedContent = parseToolResultContent(toolResult.content);
                                        if (isAsyncPendingToolResult(parsedContent)) {
                                            hasAsyncPending = true;
                                            break;
                                        }
                                    }
                                    
                                    // Show appropriate status based on whether tools are still processing
                                    if (hasAsyncPending) {
                                        // Async tool is still processing in background - show processing message
                                        setStatus(state.container, {
                                            message: getString('toolPolling', 'Tool is processing…'),
                                            type: 'text-stream',
                                            showTime: false
                                        });
                                    } else {
                                        // All tools have completed - show completion message
                                        // This status will be cleared by setTimeout in sendChatStreaming after 1.5s
                                        setStatus(state.container, {
                                            message: getString('toolSuccess', 'Tool completed successfully.'),
                                            type: 'success',
                                            showTime: false
                                        });
                                    }
                                    
                                    // If no message content found, extract text from tool results.
                                    // This handles the "agentic loop" case where AI uses tools without
                                    // generating additional text (e.g., image generation tools).
                                    if (!finalText) {
                                        for (const toolResult of data.tool_results) {
                                            if (!toolResult || !toolResult.content) {
                                                continue;
                                            }
                                            
                                            // Parse tool result content
                                            const parsedContent = parseToolResultContent(toolResult.content);
                                            
                                            // Skip async pending results - they'll be handled by waitForAsyncToolResult
                                            if (isAsyncPendingToolResult(parsedContent)) {
                                                continue;
                                            }
                                            
                                            // Use existing extractTextFromContent helper for consistent text extraction
                                            const toolText = extractTextFromContent(parsedContent);
                                            if (toolText) {
                                                if (finalText) {
                                                    finalText += '\n\n';
                                                }
                                                finalText += toolText;
                                            }
                                        }
                                    }
                                }
                                
                                // Use the final complete text if it's different or more complete than streamed content
                                // This ensures truncated responses and final messages are properly displayed
                                if (finalText && typeof finalText === 'string') {
                                    // Use finalText if:
                                    // 1. We have no streamed content yet, OR
                                    // 2. Final text is longer (more complete), OR  
                                    // 3. Final text is different (may contain corrections/formatting)
                                    // This handles cases where the stream may have been incomplete or incorrect
                                    if (!fullContent || finalText.length > fullContent.length || finalText !== fullContent) {
                                        fullContent = finalText;
                                        // Update the streaming bubble with the final text
                                        updateCallback(fullContent);
                                    }
                                }
                                
                                // IMPORTANT: Don't return here - continue processing remaining events.
                                // Previously, returning early here would lose capturedFinalData if the
                                // [DONE] marker or result.done arrived in the same or subsequent chunk.
                                // By continuing, we ensure capturedFinalData is preserved and included
                                // in the final return when the stream ends.
                            }
                            // If we found streaming content, add it to fullContent and update UI
                            else if (contentChunk) {
                                fullContent += contentChunk;
                                // Store in state for status system access
                                state.streamingContent = fullContent;
                                
                                if (DEBUG_MODE) {
                                    if (window.console && console.log) {
                                        console.log('[WP oOS] Content chunk:', {
                                            chunkLength: contentChunk.length,
                                            totalLength: fullContent.length
                                        });
                                    }
                                }
                                
                                updateCallback(fullContent);
                            }
                        }
                    } catch (parseError) {
                        // Diagnostic logging (Separation of Concerns)
                        streamingLogger.logParseError(parseError, {
                            eventType: eventType,
                            eventData: eventData.substring(0, 200)
                        });
                    }
                }

                // Continue reading
                return readChunk();
            }).catch(function(readError) {
                // Diagnostic logging (Separation of Concerns)
                streamingLogger.logStreamReadError(readError);
                throw readError;
            });
        }

        return readChunk().catch(function(streamError) {
            // Diagnostic logging (Separation of Concerns)
            streamingLogger.logStreamError(streamError);
            
            // GRACEFUL DEGRADATION: If we already captured the final data and content,
            // treat this as a successful completion rather than an error.
            // This handles the case where the server closes the connection after sending [DONE]
            // but before the browser's reader.read() returns {done: true}.
            // The network error is benign in this case - we have all the data we need.
            var hasContent = typeof fullContent === 'string' && fullContent.length > 0;
            if (capturedFinalData || hasContent) {
                if (window.console && console.log) {
                    console.log('[WP oOS] Stream read error after final data received, treating as successful completion:', {
                        hasFinalData: !!capturedFinalData,
                        contentLength: hasContent ? fullContent.length : 0,
                        errorType: streamError ? streamError.name || streamError.constructor.name : 'unknown'
                    });
                }
                // Return the captured data as if the stream completed successfully
                if (capturedFinalData) {
                    return { content: fullContent, finalData: capturedFinalData };
                }
                return { content: fullContent };
            }
            
            // No data captured yet - this is a real error
            throw streamError;
        });
    }

    /**
     * Handle status SSE events from PHP backend.
     * 
     * PHP sends these status event types during chat processing:
     * - 'thinking': Initial processing or analyzing tool results
     * - 'generating': AI is generating a response  
     * - 'model_switched': Switched to fallback model due to token limits
     * - 'messages_truncated': Reduced context to fit token limits
     * - 'max_iterations': Agentic loop reached maximum tool execution iterations
     * - 'processing_attachments': Processing uploaded files/images before AI call
     * - 'loading_memory': Loading memory documents from assistant configuration
     * 
     * Additional status types supported for future PHP enhancements:
     * - 'executing_tool': Detailed tool execution (with tool name)
     * - 'saving': Saving data (post, transcript, etc.)
     * - 'searching': Performing search operations
     * - 'crawling': Web crawling in progress
     * - 'generating_media': Creating images/audio/video
     * - 'transcribing': Audio transcription in progress
     * 
     * @param {Object} state - Chat state object
     * @param {Object} data - Event data from PHP containing type and message
     */
    function handleStatusEvent(state, data) {
        if (!data || !state || !state.container) {
            return;
        }

        const message = data.message || '';
        const type = data.type || '';

        if (type === 'thinking') {
            // Don't override streaming status if content is actively streaming
            // This prevents "thinking" status from interrupting active content streaming
            if (state.streamingContent && state.streamingContent.length > 0) {
                // Content is already streaming, ignore this thinking status
                return;
            }
            
            setStatus(state.container, {
                message: message,
                type: 'thinking',
                showTime: true,
                startTime: Date.now()
            });
        } else if (type === 'generating') {
            // Don't override streaming status if content is actively streaming
            // This prevents "generating" status from being shown when actual content is streaming
            if (state.streamingContent && state.streamingContent.length > 0) {
                // Content is already streaming, keep current status
                return;
            }
            
            setStatus(state.container, {
                message: message,
                type: 'streaming',
                showTime: true,
                startTime: Date.now()
            });
        } else if (type === 'model_switched' || type === 'messages_truncated') {
            // Show brief notification without timer
            setStatus(state.container, {
                message: message,
                type: 'default',
                showTime: false
            });
            setTimeout(function() {
                setStatus(state.container, {
                    message: getString('sending', 'Sending…'),
                    type: 'processing',
                    showTime: false
                });
            }, 2000);
        } else if (type === 'max_iterations') {
            // PHP sends this when agentic loop reaches maximum tool execution iterations.
            // Show warning that tool loop was capped, then allow response to display.
            setStatus(state.container, {
                message: message,
                type: 'default',
                showTime: false
            });
        } else if (type === 'processing_attachments' || type === 'loading_memory' ||
                   type === 'transcribing' || type === 'crawling' || 
                   type === 'searching' || type === 'generating_media') {
            // Status types for operations that may take a moment.
            // 'processing_attachments': Processing uploaded files/images before AI call
            // 'loading_memory': Loading memory documents from assistant configuration
            // Show with timer since these operations can take a while.
            setStatus(state.container, {
                message: message,
                type: 'text-stream',
                showTime: true,
                startTime: Date.now()
            });
        } else if (type === 'saving' || type === 'executing_tool') {
            // Status types for shorter operations.
            // Show without timer as these should complete quickly.
            setStatus(state.container, {
                message: message,
                type: 'text-stream',
                showTime: false
            });
        } else if (type && message) {
            // Generic fallback handler for any unknown status type from PHP.
            // This ensures forward compatibility - new status types added to PHP
            // will still be displayed even if the frontend hasn't been updated.
            setStatus(state.container, {
                message: message,
                type: 'default',
                showTime: false
            });
        }
    }

    /**
     * Handle tool_execution SSE events from PHP backend.
     * 
     * PHP sends these event types during the agentic loop:
     * - 'start': Beginning of tool execution batch (includes tool names and count)
     * - 'tool_start': Individual tool starting execution
     * - 'tool_result': Individual tool completed with result
     * 
     * @param {Object} state - Chat state object
     * @param {Object} data - Event data from PHP containing type, tool_name, result, etc.
     */
    function handleToolExecutionEvent(state, data) {
        if (!data || !state || !state.messagesEl) {
            return;
        }

        const type = data.type || '';

        if (type === 'start') {
            // Show that tools are being executed
            const toolNames = (data.tools || []).join(', ');
            const message = formatString(
                getString('executingTools', 'Executing tools: %s'),
                toolNames || getString('tools', 'multiple tools')
            );
            setStatus(state.container, {
                message: message,
                type: 'text-stream',
                showTime: true,
                startTime: Date.now()
            });

            // Optionally show tool execution in chat
            appendMessage(state.messagesEl, 'system', {
                text: '⚙️ ' + message
            }, false, { state: state });
        } else if (type === 'tool_start') {
            const toolName = data.tool_name || 'tool';
            const message = formatString(
                getString('executingTool', 'Executing %s…'),
                toolName
            );
            setStatus(state.container, {
                message: message,
                type: 'text-stream',
                showTime: true,
                startTime: Date.now()
            });
        } else if (type === 'tool_result') {
            const toolName = data.tool_name || 'tool';
            const result = data.result || {};
            // Extract tool_call_id from event data (sent as tool_id by PHP backend)
            const toolCallId = data.tool_id || '';
            
            // Check if this is an async tool execution that's still pending
            // Async tools have: async=true, status='pending', job_id
            if (result.async === true && result.status === 'pending' && result.job_id) {
                // Update status indicator with async tool pending message
                const asyncMessage = result.message || formatString(
                    getString('executingTool', 'Executing %s…'),
                    toolName
                );
                setStatus(state.container, {
                    message: asyncMessage,
                    type: 'text-stream',
                    showTime: false
                });
                
                // Display initial message if provided
                // Note: The backend already includes Job ID in the message, so we don't add it again
                if (result.message) {
                    appendMessage(state.messagesEl, 'system', result.message, false, { state: state });
                }
                
                // Start polling for the async result
                // Pass toolCallId to ensure the completed result has the correct ID for API compatibility
                waitForAsyncToolResult(state, result.job_id, toolName, toolCallId).catch(function (error) {
                    // Error is already displayed by waitForAsyncToolResult
                    if (window.console && console.error) {
                        console.error('[WP oOS] Async tool polling failed:', error);
                    }
                });
                return;
            }
            
            // Check if this is a sync tool with a pending/processing status (e.g., web_search HTTP 202)
            // These tools have: status='processing' (NOT async=true, NO job_id)
            // The agentic loop should continue - the LLM gets a message to use alternative sources
            if (result.status === 'processing' && !result.job_id && !result.async) {
                // This is a sync tool that's temporarily unavailable (e.g., web_search pending)
                // Display the informational message for the user
                const processingMessage = result.message || formatString(
                    getString('toolProcessing', '%s is temporarily processing your request. The assistant will continue using available information.'),
                    toolName
                );
                
                // Show as informational system message (not an error)
                appendMessage(state.messagesEl, 'system', {
                    text: 'ℹ️ ' + processingMessage,
                    attachments: []
                }, false, { state: state });
                
                // Update status to indicate tool is processing
                setStatus(state.container, {
                    message: formatString(getString('toolTemporarilyUnavailable', '%s temporarily unavailable'), toolName),
                    type: 'text-stream',
                    showTime: false
                });
                
                // Log the processing status for debugging
                if (window.console && console.log) {
                    console.log('[WP oOS] Tool returned processing status (sync pending):', {
                        tool_name: toolName,
                        status: result.status,
                        message: result.message,
                        note: result.note
                    });
                }
                
                // DO NOT poll - this is not an async job, just a temporarily unavailable sync tool
                // The agentic loop continues and the LLM can proceed with alternative information
                return;
            }
            
            // Extract attachments from tool result (e.g., generated images, audio files)
            const normalized = typeof result === 'object' && result !== null ? 
                normaliseToolResultForDisplay(toolName, result) : null;
            
            // Show tool result in chat
            let resultText = '';
            let isError = false;
            let attachments = [];
            
            if (typeof result === 'string') {
                resultText = result;
                // Check if the result looks like an error message.
                // Error strings from execute_tool_call_internal() contain these keywords.
                // This is a pragmatic approach that works without changing the backend response structure.
                const lowerResult = resultText.toLowerCase();
                isError = lowerResult.indexOf('error') !== -1 || 
                          lowerResult.indexOf('invalid') !== -1 ||
                          lowerResult.indexOf('failed') !== -1 ||
                          lowerResult.indexOf('forbidden') !== -1 ||
                          lowerResult.indexOf('missing') !== -1;
            } else if (normalized && (normalized.attachments && normalized.attachments.length > 0 || normalized.chartHtml)) {
                // Tool result has attachments (images, files) or chart HTML - use normalized text and attachments
                resultText = normalized.text || (toolName + ': ' + getString('completed', 'Completed'));
                attachments = normalized.attachments || [];
            } else if (result.summary) {
                resultText = toolName + ': ' + result.summary;
            } else if (result.text) {
                resultText = result.text;
            } else {
                resultText = toolName + ': ' + getString('completed', 'Completed');
            }

            // Use different prefix for errors vs success
            const prefix = isError ? '⚠️ ' : '✓ ';
            const messageType = isError ? 'system' : 'tool';
            
            // Update status indicator with tool result
            setStatus(state.container, {
                message: prefix + resultText,
                type: 'text-stream',
                showTime: false
            });
            
            // Build display payload for UI rendering
            const displayPayload = {
                text: prefix + resultText,
                attachments: attachments
            };
            
            // Include chart data if available (from Chart.js visualization tools)
            if (normalized && normalized.chartHtml) {
                displayPayload.chartHtml = normalized.chartHtml;
                displayPayload.chartWidth = normalized.chartWidth || 800;
                displayPayload.chartHeight = normalized.chartHeight || 400;
            }
            
            // Display the tool result with attachments if available
            const messageElement = appendMessage(state.messagesEl, messageType, displayPayload, false, { state: state });
            
            // Add tool result to conversation state for persistence
            // This ensures tool results are saved to localStorage and CCT
            if (messageType === 'tool' && state.conversation && Array.isArray(state.conversation)) {
                // Build the tool message for conversation history
                // Content should be the raw result for API compatibility
                let contentForApi = '';
                if (typeof result === 'object' && result !== null) {
                    try {
                        contentForApi = JSON.stringify(result);
                    } catch (e) {
                        // Log serialization error for debugging
                        if (window.console && console.warn) {
                            console.warn('[WP oOS] Failed to serialize tool result:', {
                                tool_name: toolName,
                                error: e.message || 'Unknown serialization error'
                            });
                        }
                        contentForApi = resultText;
                    }
                } else {
                    contentForApi = String(result);
                }

                // Extract display metadata for proper UI restoration
                const displayMetadata = extractDisplayMetadata(messageElement, displayPayload);

                // Determine the tool_call_id to use for this tool result.
                // Use the toolCallId from event data if provided and valid,
                // otherwise generate a fallback ID for API compatibility.
                let finalToolCallId = '';
                if (toolCallId && typeof toolCallId === 'string' && toolCallId.trim()) {
                    finalToolCallId = toolCallId.trim();
                }
                
                // Generate fallback tool_call_id if not found
                if (!finalToolCallId) {
                    // Use timestamp + random suffix to avoid collisions
                    const sanitizedToolName = toolName.replace(/[^a-zA-Z0-9_]/g, '_');
                    finalToolCallId = 'sse_' + sanitizedToolName + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
                }

                // Create tool message with display metadata for persistence
                const toolMessage = createConversationMessage(
                    'tool',
                    contentForApi,
                    displayMetadata,
                    {
                        name: toolName,
                        tool_call_id: finalToolCallId
                    }
                );

                state.conversation.push(toolMessage);

                // Log for debugging
                if (window.console && console.log) {
                    console.log('[WP oOS] Added SSE tool result to conversation:', {
                        tool_name: toolName,
                        tool_call_id: finalToolCallId,
                        conversation_length: state.conversation.length
                    });
                }

                // Save conversation to storage for persistence
                saveConversationToStorage(state);

                // Also save to CCT if available (silent, non-blocking)
                saveConversationToCCT(state, { silent: true });
            }
        }
    }

    function handleErrorEvent(state, data) {
        if (!data || !state || !state.container) {
            return;
        }

        const message = data.message || getString('error', 'Something went wrong.');
        setStatus(state.container, message);
        appendMessage(state.messagesEl, 'system', {
            text: message
        }, false, { state: state });
    }

    function restoreSubmissionState(state, submissionContext) {
        if (!submissionContext || typeof submissionContext !== 'object') {
            return;
        }

        if (typeof submissionContext.previousConversationLength === 'number') {
            state.conversation = state.conversation.slice(0, submissionContext.previousConversationLength);
        }

        if (Array.isArray(submissionContext.pendingAttachments)) {
            state.pendingAttachments = submissionContext.pendingAttachments.slice();
            renderPendingAttachments(state);
            updateAttachButtonState(state);
        }

        if (submissionContext.messageElement && submissionContext.messageElement.parentNode) {
            submissionContext.messageElement.parentNode.removeChild(submissionContext.messageElement);
        }

        if (typeof submissionContext.inputValue === 'string' && state.textarea) {
            state.textarea.value = submissionContext.inputValue;

            if (typeof state.textarea.focus === 'function') {
                state.textarea.focus();

                if (typeof state.textarea.setSelectionRange === 'function') {
                    const length = state.textarea.value.length;
                    state.textarea.setSelectionRange(length, length);
                }
            }
        }
    }

    function extractFilteredResponseNotice(choice, message) {
        if (message && typeof message.refusal === 'string' && message.refusal.trim()) {
            return message.refusal.trim();
        }

        const metadata = message && message.metadata ? message.metadata : null;
        if (metadata && typeof metadata === 'object') {
            if (typeof metadata.warning === 'string' && metadata.warning.trim()) {
                return metadata.warning.trim();
            }

            if (typeof metadata.message === 'string' && metadata.message.trim()) {
                return metadata.message.trim();
            }

            if (typeof metadata.reason === 'string' && metadata.reason.trim()) {
                return metadata.reason.trim();
            }

            // Handle error as string or object
            if (metadata.error) {
                const errorMsg = extractErrorMessage(metadata.error, '');
                if (errorMsg) {
                    return errorMsg.trim();
                }
            }
        }

        const filterResults = message && message.content_filter_results ? message.content_filter_results : null;
        if (filterResults && typeof filterResults === 'object') {
            if (typeof filterResults.message === 'string' && filterResults.message.trim()) {
                return filterResults.message.trim();
            }

            if (typeof filterResults.reason === 'string' && filterResults.reason.trim()) {
                return filterResults.reason.trim();
            }

            if (filterResults.error && typeof filterResults.error === 'object') {
                if (typeof filterResults.error.message === 'string' && filterResults.error.message.trim()) {
                    return filterResults.error.message.trim();
                }
            }
        }

        const finishReason = choice && typeof choice.finish_reason === 'string' ? choice.finish_reason.trim() : '';

        if (finishReason === 'content_filter') {
            return getString('responseFiltered', 'The assistant response was blocked by safety filters.');
        }

        if (finishReason === 'length') {
            return getString('responseIncomplete', 'The assistant response ended prematurely and could not be displayed.');
        }

        if (finishReason === 'error') {
            return getString('responseErrored', 'The assistant response could not be displayed due to an error.');
        }

        return '';
    }

    function handleChatResponse(state, data) {
        // Capture and save the session key if provided by the server
        if (data && data.sessionKey && state.config) {
            state.config.sessionKey = sanitizeSessionKey(data.sessionKey);
        }

        const chatData = data && data.data ? data.data : null;
        const choices = chatData && Array.isArray(chatData.choices) ? chatData.choices : [];
        const choice = choices.length ? choices[0] : null;
        const message = choice && choice.message ? choice.message : null;

        // Check if we have tool_results even without a message (async tool completion case)
        const hasToolResults = data && Array.isArray(data.tool_results) && data.tool_results.length > 0;

        if (!message && !hasToolResults) {
            if (DEBUG_MODE && window.console && console.error) {
                console.error('[WP oOS] handleChatResponse: No message or tool_results found in response');
            }
            setStatus(state.container, getString('error', 'Something went wrong.'));
            return Promise.resolve();
        }

        // Handle case where we only have tool_results (async tool completion without new message)
        if (!message && hasToolResults) {
            // Dynamically create an assistant message with the tool results
            // This is similar to how image generation results are displayed
            
            const assistantDisplay = {
                text: '',
                attachments: []
            };
            
            // Process each tool result and extract attachments
            data.tool_results.forEach(function (toolResult) {
                if (!toolResult || !toolResult.content) {
                    return;
                }

                const toolName = toolResult.name || '';
                
                // Parse the tool result content (JSON string) into an object
                let parsedContent = toolResult.content;
                if (typeof parsedContent === 'string') {
                    try {
                        parsedContent = JSON.parse(parsedContent);
                    } catch (e) {
                        // If parsing fails, use the string as-is
                        parsedContent = toolResult.content;
                    }
                }
                
                // Check if this is an async tool result that's still pending
                if (isAsyncPendingToolResult(parsedContent)) {
                    startAsyncToolPolling(state, parsedContent, toolName, toolResult.tool_call_id);
                    return;
                }
                
                // Normalize the tool result for display (extracts attachments, text, etc.)
                const normalized = normaliseToolResultForDisplay(toolName, parsedContent);
                
                if (normalized) {
                    // Add text from tool result to assistant display if available
                    if (normalized.text && typeof normalized.text === 'string') {
                        if (assistantDisplay.text) {
                            assistantDisplay.text += '\n\n' + normalized.text;
                        } else {
                            assistantDisplay.text = normalized.text;
                        }
                    }
                    
                    // Add chart HTML to assistant display if available (Chart.js visualizations)
                    mergeChartDataToDisplay(assistantDisplay, normalized);
                    
                    // Add attachments to the assistant display (videos, images, files)
                    if (normalized.attachments && normalized.attachments.length > 0) {
                        assistantDisplay.attachments = assistantDisplay.attachments.concat(normalized.attachments);
                    }
                }
                
                // Add tool result to conversation for agentic flow continuity
                // Preserve display metadata for proper UI restoration after page reload
                if (state.conversation && Array.isArray(state.conversation)) {
                    // Add display metadata to tool result for persistence
                    const toolDisplay = createToolDisplayMetadata(normalized);
                    if (toolDisplay) {
                        toolResult.display = toolDisplay;
                    }
                    state.conversation.push(toolResult);
                }
            });

            // Display the assistant message with attachments if we have any content
            if (assistantDisplay.text || (assistantDisplay.attachments && assistantDisplay.attachments.length > 0) || assistantDisplay.chartHtml) {
                // Extract usage and cost from data (similar to normal flow)
                const usage = chatData && chatData.usage ? chatData.usage : null;
                const cost = data && data.cost ? data.cost : null;
                
                const messageElement = appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {
                    state: state,
                    speech: {
                        state: state,
                        text: assistantDisplay.text || '',
                    },
                    usage: usage,
                    cost: cost
                });
                
                // Add assistant message to conversation
                if (state.conversation && Array.isArray(state.conversation)) {
                    const assistantMessage = createConversationMessage(
                        'assistant',
                        assistantDisplay.text || '',
                        extractDisplayMetadata(messageElement, assistantDisplay, {
                            usage: usage,
                            cost: cost
                        })
                    );
                    state.conversation.push(assistantMessage);
                }
            }

            // Save conversation after processing tool results
            saveConversationToStorage(state);
            
            // Check if any tool results are async pending before setting final status.
            // This mirrors the logic at the end of handleChatResponse (lines ~10780-10814).
            // By setting "Tool completed successfully" here instead of clearing immediately,
            // the calling code's delayed clearStatus (after 1.5s) will work properly,
            // giving users time to see the completion message.
            var hasAsyncPendingTools = false;
            data.tool_results.forEach(function(toolResult) {
                if (toolResult && toolResult.content) {
                    var parsedContent = parseToolResultContent(toolResult.content);
                    if (isAsyncPendingToolResult(parsedContent)) {
                        hasAsyncPendingTools = true;
                    }
                }
            });
            
            if (!hasAsyncPendingTools) {
                // All tools have completed - set completion status
                // The calling code (sendChatStreaming) will clear this after 1.5s delay
                setStatus(state.container, {
                    message: getString('toolSuccess', 'Tool completed successfully.'),
                    type: 'success',
                    showTime: false
                });
            } else {
                // Async tools still pending - clear status (polling will update it)
                setStatus(state.container, '');
            }
            
            return Promise.resolve();
        }

        const assistantMessage = { role: 'assistant' };
        const assistantDisplay = prepareAssistantDisplay(message, state);
        let hasDisplayText = typeof assistantDisplay.text === 'string' && assistantDisplay.text.trim() !== '';
        const hasDisplayAttachments = assistantDisplay.attachments.length > 0;
        let hasDisplayContent = hasDisplayText || hasDisplayAttachments;
        const hasToolCalls = message.tool_calls && Array.isArray(message.tool_calls) && message.tool_calls.length;

        if (!hasDisplayContent) {
            let fallbackText = '';

            function normaliseCandidate(candidate) {
                if (candidate === null || typeof candidate === 'undefined') {
                    return '';
                }

                if (typeof candidate === 'string') {
                    return candidate;
                }

                if (Array.isArray(candidate)) {
                    return normaliseContent(candidate);
                }

                if (typeof candidate === 'object') {
                    if (typeof candidate.text !== 'undefined') {
                        return normaliseCandidate(candidate.text);
                    }

                    if (typeof candidate.content !== 'undefined') {
                        return normaliseCandidate(candidate.content);
                    }

                    if (typeof candidate.value !== 'undefined') {
                        return normaliseCandidate(candidate.value);
                    }

                    return normaliseContent(candidate);
                }

                return '';
            }

            if (chatData && typeof chatData.output_text !== 'undefined' && chatData.output_text !== null) {
                fallbackText = normaliseCandidate(chatData.output_text).trim();
            }

            if (!fallbackText && chatData && Array.isArray(chatData.output)) {
                fallbackText = chatData.output
                    .map(function (item) {
                        return normaliseCandidate(item).trim();
                    })
                    .filter(function (value) {
                        return value;
                    })
                    .join('\n\n')
                    .trim();
            }

            if (!fallbackText && chatData && chatData.response && typeof chatData.response === 'object') {
                const nestedResponse = chatData.response;

                if (typeof nestedResponse.output_text !== 'undefined' && nestedResponse.output_text !== null) {
                    fallbackText = normaliseCandidate(nestedResponse.output_text).trim();
                }

                if (!fallbackText && Array.isArray(nestedResponse.output)) {
                    fallbackText = nestedResponse.output
                        .map(function (item) {
                            return normaliseCandidate(item).trim();
                        })
                        .filter(function (value) {
                            return value;
                        })
                        .join('\n\n')
                        .trim();
                }
            }

            if (fallbackText) {
                assistantDisplay.text = fallbackText;
                hasDisplayText = true;
                hasDisplayContent = true;
            }
        }

        // Declare assistantMessageElement at this scope so it's accessible
        // when processing async tool results later (for updating DOM with video attachments)
        let assistantMessageElement = null;

        if (hasDisplayContent) {
            // Extract usage and cost data for Phase 7 Week 5-6 Enhanced Token Tracking
            const usage = chatData && chatData.usage ? chatData.usage : null;
            const cost = data && data.cost ? data.cost : null;

            // Aggregate tool usage and cost data (Phase 7 Week 5-6 Enhancement for Tool Token Display)
            let aggregatedUsage = usage ? Object.assign({}, usage) : null;
            let aggregatedCost = cost ? Object.assign({}, cost) : null;

            // Collect usage and cost from all tool results
            if (data && Array.isArray(data.tool_results) && data.tool_results.length > 0) {
                data.tool_results.forEach(function (toolResult) {
                    if (!toolResult) {
                        return;
                    }

                    // Phase 7: Enhanced Token Tracking - Check for usage data in two locations:
                    // 1. Direct usage field on the tool result (new method, preferred)
                    // 2. Inside parsed content (legacy method, for backwards compatibility)
                    let toolUsage = null;
                    let toolCost = null;
                    let toolModel = null;
                    let toolProvider = null;

                    // Try direct usage field first (added in Phase 7)
                    if (toolResult.usage && typeof toolResult.usage === 'object') {
                        toolUsage = toolResult.usage;
                        toolModel = toolUsage.model || null;
                        toolProvider = toolUsage.provider || null;
                    }

                    // Try direct cost field (for tools that provide cost estimates like generate_openai_image)
                    if (toolResult.cost && typeof toolResult.cost === 'object') {
                        toolCost = toolResult.cost;
                        if (!toolModel && toolCost.model) {
                            toolModel = toolCost.model;
                        }
                        if (!toolProvider && toolCost.provider) {
                            toolProvider = toolCost.provider;
                        }
                    }

                    // Fall back to parsing content for usage and cost data (legacy support)
                    if ((!toolUsage || !toolCost) && toolResult.content) {
                        let parsedContent = toolResult.content;
                        if (typeof parsedContent === 'string') {
                            try {
                                parsedContent = JSON.parse(parsedContent);
                            } catch (e) {
                                parsedContent = null;
                            }
                        }

                        if (parsedContent && typeof parsedContent === 'object') {
                            if (!toolUsage && parsedContent.usage && typeof parsedContent.usage === 'object') {
                                toolUsage = parsedContent.usage;
                            }
                            if (!toolCost && parsedContent.cost && typeof parsedContent.cost === 'object') {
                                toolCost = parsedContent.cost;
                            }
                            // Extract model and provider from parsed content
                            if (parsedContent.model && !toolModel) {
                                toolModel = parsedContent.model;
                            }
                            if (parsedContent.provider && !toolProvider) {
                                toolProvider = parsedContent.provider;
                            }
                        }
                    }

                    // Aggregate usage data if found
                    if (toolUsage) {
                        // Initialize aggregated usage if not present
                        if (!aggregatedUsage) {
                            aggregatedUsage = {
                                prompt_tokens: 0,
                                completion_tokens: 0,
                                total_tokens: 0
                            };
                        }

                        // Aggregate token counts
                        aggregatedUsage.prompt_tokens = (aggregatedUsage.prompt_tokens || 0) + (toolUsage.prompt_tokens || 0);
                        aggregatedUsage.completion_tokens = (aggregatedUsage.completion_tokens || 0) + (toolUsage.completion_tokens || 0);
                        aggregatedUsage.total_tokens = (aggregatedUsage.total_tokens || 0) + (toolUsage.total_tokens || 0);
                        
                        // Preserve is_estimated flag if any tool usage is estimated
                        if (toolUsage.is_estimated) {
                            aggregatedUsage.is_estimated = true;
                        }
                    }

                    // Aggregate cost data if found
                    if (toolCost && typeof toolCost.cost_usd === 'number') {
                        // Initialize aggregated cost if not present
                        if (!aggregatedCost) {
                            aggregatedCost = {
                                cost_usd: 0
                            };
                        }

                        // Aggregate cost amounts
                        aggregatedCost.cost_usd = (aggregatedCost.cost_usd || 0) + toolCost.cost_usd;

                        // Preserve is_estimated flag if any tool cost is estimated
                        if (toolCost.is_estimated) {
                            aggregatedCost.is_estimated = true;
                        }

                        // Preserve provider and model from first tool with cost
                        if (!aggregatedCost.provider && toolCost.provider) {
                            aggregatedCost.provider = toolCost.provider;
                        }
                        if (!aggregatedCost.model && toolCost.model) {
                            aggregatedCost.model = toolCost.model;
                        }
                    }

                    // Include model and provider in aggregated usage
                    if (toolModel || toolProvider) {
                        if (!aggregatedUsage) {
                            aggregatedUsage = {};
                        }
                        
                        // Prefer tool's explicit model/provider over defaults
                        if (toolModel && !aggregatedUsage.model) {
                            aggregatedUsage.model = toolModel;
                        }
                        if (toolProvider && !aggregatedUsage.provider) {
                            aggregatedUsage.provider = toolProvider;
                        }
                    }
                });
            }

            // Collect capability flags from tool results
            const capabilityFlags = [];
            if (data && Array.isArray(data.tool_results) && data.tool_results.length > 0) {
                data.tool_results.forEach(function (toolResult) {
                    if (!toolResult) {
                        return;
                    }

                    // Extract capability flags if present
                    if (toolResult.capability_flags && Array.isArray(toolResult.capability_flags)) {
                        // Merge unique flags
                        toolResult.capability_flags.forEach(function (flag) {
                            if (flag && typeof flag === 'string' && capabilityFlags.indexOf(flag) === -1) {
                                capabilityFlags.push(flag);
                            }
                        });
                    }
                });
            }

            assistantMessageElement = appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {
                state: state,
                speech: {
                    state: state,
                    text: assistantDisplay.text || '',
                },
                usage: aggregatedUsage,
                cost: aggregatedCost,
                capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null,
            });
            
            // Preserve the original content structure if it's an array (contains image blocks)
            // This is needed to maintain image_url content in the agentic loop
            // When content is array, it means it has structured data like image_url blocks
            if (Array.isArray(message.content)) {
                assistantMessage.content = message.content;
            } else {
                assistantMessage.content = assistantDisplay.text || '';
            }
            
            // Extract and preserve display metadata for persistence (including badges)
            const displayMetadata = extractDisplayMetadata(assistantMessageElement, assistantDisplay, {
                usage: aggregatedUsage,
                cost: aggregatedCost,
                capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null
            });
            if (displayMetadata) {
                assistantMessage.display = displayMetadata;
            }
        }

        if (!hasDisplayContent && !hasToolCalls) {
            let notice = extractFilteredResponseNotice(choice, message);
            if (!notice) {
                notice = getString('responseMissing', 'The assistant response could not be displayed.');
            }

            appendMessage(state.messagesEl, 'system', { text: notice }, false, { state: state });
            setStatus(state.container, notice);

            return Promise.resolve();
        }

        if (hasToolCalls) {
            assistantMessage.tool_calls = message.tool_calls;
            
            // BUG FIX: When assistant has tool_calls but no display content (e.g., the AI
            // calls a tool without providing text), we still need to preserve display metadata
            // for proper restoration from localStorage/CCT. Previously, display metadata was
            // only extracted when hasDisplayContent was true, causing tool_calls-only messages
            // to lose their display info on reload.
            if (!hasDisplayContent) {
                // Set content from the original message for API compatibility
                if (Array.isArray(message.content)) {
                    assistantMessage.content = message.content;
                } else if (message.content && typeof message.content === 'string' && message.content.trim()) {
                    assistantMessage.content = message.content;
                }
                
                // Extract display metadata for persistence even without visible content.
                // This ensures the tool_calls information can be restored properly.
                // Note: We create a minimal display payload since there's no element to extract from.
                const toolCallsDisplay = {
                    bubbleType: 'assistant',
                    text: assistantDisplay.text || '',
                    attachments: assistantDisplay.attachments || []
                };
                
                // Add tool_calls to display metadata for restoration
                if (message.tool_calls && Array.isArray(message.tool_calls)) {
                    toolCallsDisplay.tool_calls = message.tool_calls;
                }
                
                assistantMessage.display = toolCallsDisplay;
            }
        }

        // OpenAI requires assistant messages with tool_calls to have valid content.
        // Empty string causes "Invalid parameter(s): messages" errors.
        // Use null for messages with tool_calls but no text content.
        if (assistantMessage.content || assistantMessage.tool_calls) {
            if (!assistantMessage.hasOwnProperty('content')) {
                // Use null instead of empty string for tool_calls without content
                // This prevents "Invalid parameter(s): messages" errors from OpenAI
                assistantMessage.content = hasToolCalls ? null : '';
            } else if (assistantMessage.content === '' && hasToolCalls) {
                // Convert empty string to null for tool_calls messages
                // OpenAI accepts null but not empty string for content when tool_calls present
                assistantMessage.content = null;
            }
            state.conversation.push(assistantMessage);
        }

        // Add intermediate assistant messages with tool_calls from agentic loop to conversation.
        // These messages capture the AI's reasoning and tool invocations during multi-step workflows.
        // They must be added before tool_results to maintain correct message ordering for API compatibility.
        if (data && Array.isArray(data.agentic_tool_messages) && data.agentic_tool_messages.length > 0) {
            data.agentic_tool_messages.forEach(function (agenticMessage) {
                if (agenticMessage && agenticMessage.role === 'assistant') {
                    // Create a properly formatted assistant message for conversation state
                    const formattedMessage = {
                        role: 'assistant',
                        content: agenticMessage.content || null
                    };
                    
                    // Include tool_calls if present
                    if (agenticMessage.tool_calls && Array.isArray(agenticMessage.tool_calls)) {
                        formattedMessage.tool_calls = agenticMessage.tool_calls;
                    }
                    
                    // BUG FIX: Add display metadata for agentic messages to enable proper restoration.
                    // Previously, agentic messages lacked display metadata, causing them to not
                    // render correctly when restored from localStorage/CCT.
                    const agenticDisplay = {
                        bubbleType: 'assistant',
                        text: agenticMessage.content || '',
                        attachments: []
                    };
                    
                    // Preserve tool_calls in display for restoration context
                    if (agenticMessage.tool_calls && Array.isArray(agenticMessage.tool_calls)) {
                        agenticDisplay.tool_calls = agenticMessage.tool_calls;
                    }
                    
                    formattedMessage.display = agenticDisplay;
                    
                    state.conversation.push(formattedMessage);
                    
                    // Log for debugging
                    if (window.console && console.log) {
                        console.log('[WP oOS] Added agentic assistant message to conversation:', {
                            has_content: !!agenticMessage.content,
                            tool_calls_count: agenticMessage.tool_calls ? agenticMessage.tool_calls.length : 0,
                            conversation_length: state.conversation.length
                        });
                    }
                }
            });
        }

        /**
         * Helper function to aggregate usage and cost data from a normalized tool result.
         * 
         * @param {Object} normalized - The normalized tool result containing usage/cost data
         * @param {Object|null} aggregatedUsage - Current aggregated usage object (will be modified)
         * @param {Object|null} aggregatedCost - Current aggregated cost object (will be modified)
         * @return {Object} Object with updated aggregatedUsage and aggregatedCost
         */
        function aggregateToolUsageAndCost(normalized, aggregatedUsage, aggregatedCost) {
            if (!normalized) {
                return { usage: aggregatedUsage, cost: aggregatedCost };
            }

            // Aggregate usage from normalized tool result
            if (normalized.usage && typeof normalized.usage === 'object') {
                if (!aggregatedUsage) {
                    aggregatedUsage = {
                        prompt_tokens: 0,
                        completion_tokens: 0,
                        total_tokens: 0
                    };
                }
                aggregatedUsage.prompt_tokens = (aggregatedUsage.prompt_tokens || 0) + (normalized.usage.prompt_tokens || 0);
                aggregatedUsage.completion_tokens = (aggregatedUsage.completion_tokens || 0) + (normalized.usage.completion_tokens || 0);
                aggregatedUsage.total_tokens = (aggregatedUsage.total_tokens || 0) + (normalized.usage.total_tokens || 0);
                if (normalized.usage.is_estimated) {
                    aggregatedUsage.is_estimated = true;
                }
                if (normalized.usage.model && !aggregatedUsage.model) {
                    aggregatedUsage.model = normalized.usage.model;
                }
                if (normalized.usage.provider && !aggregatedUsage.provider) {
                    aggregatedUsage.provider = normalized.usage.provider;
                }
            }

            // Aggregate cost from normalized tool result
            if (normalized.cost && typeof normalized.cost === 'object' && typeof normalized.cost.cost_usd === 'number') {
                if (!aggregatedCost) {
                    aggregatedCost = {
                        cost_usd: 0
                    };
                }
                aggregatedCost.cost_usd = (aggregatedCost.cost_usd || 0) + normalized.cost.cost_usd;
                if (normalized.cost.is_estimated) {
                    aggregatedCost.is_estimated = true;
                }
                if (!aggregatedCost.provider && normalized.cost.provider) {
                    aggregatedCost.provider = normalized.cost.provider;
                }
                if (!aggregatedCost.model && normalized.cost.model) {
                    aggregatedCost.model = normalized.cost.model;
                }
            }

            // Also collect top-level provider/model if not already set from usage
            if (aggregatedUsage) {
                if (normalized.provider && !aggregatedUsage.provider) {
                    aggregatedUsage.provider = normalized.provider;
                }
                if (normalized.model && !aggregatedUsage.model) {
                    aggregatedUsage.model = normalized.model;
                }
            }

            return { usage: aggregatedUsage, cost: aggregatedCost };
        }

        /**
         * Merge chart data from normalized tool result into assistantDisplay.
         * Extracted as a helper function to reduce duplication.
         * 
         * @param {Object} assistantDisplay - The display object to merge chart data into
         * @param {Object} normalized - Normalized tool result with potential chart data
         */
        function mergeChartDataToDisplay(assistantDisplay, normalized) {
            if (!assistantDisplay || !normalized || !normalized.chartHtml || typeof normalized.chartHtml !== 'string') {
                return;
            }
            assistantDisplay.chartHtml = normalized.chartHtml;
            assistantDisplay.chartWidth = normalized.chartWidth || 800;
            assistantDisplay.chartHeight = normalized.chartHeight || 400;
            assistantDisplay.chartType = normalized.chartType || 'chart';
            assistantDisplay.chartTitle = normalized.chartTitle || '';
        }

        /**
         * Create display metadata object for tool results.
         * This ensures consistent metadata preservation across all tool result code paths.
         * 
         * @param {Object} normalized - Normalized tool result from normaliseToolResultForDisplay
         * @return {Object} Display metadata object with bubbleType, text, attachments, and optional chartHtml
         */
        function createToolDisplayMetadata(normalized) {
            if (!normalized) {
                return null;
            }
            
            const toolDisplay = {
                bubbleType: 'tool',
                text: normalized.text || '',
                attachments: normalized.attachments || []
            };
            
            // Preserve chart data if present
            if (normalized.chartHtml) {
                toolDisplay.chartHtml = normalized.chartHtml;
                toolDisplay.chartWidth = normalized.chartWidth || 800;
                toolDisplay.chartHeight = normalized.chartHeight || 400;
            }
            
            return toolDisplay;
        }

        /**
         * Add video placeholder attachment to assistant display for pending video generation.
         * This ensures the video player appears in both system and assistant bubbles.
         * Extracted as a helper function to reduce duplication.
         * 
         * @param {Object} assistantDisplay - The display object to add video attachment to
         * @param {Object} parsedContent - Parsed tool result with expected_url and expected_filename
         * @param {HTMLElement|null} bubbleElement - Optional DOM element to update with the attachment
         */
        function addVideoPendingAttachment(assistantDisplay, parsedContent, bubbleElement) {
            // Check if this is a pending video generation
            var isVideoPending = parsedContent.expected_url && 
                                 parsedContent.expected_filename && 
                                 parsedContent.expected_filename.indexOf('.mp4') !== -1;
            
            if (!isVideoPending) {
                return;
            }
            
            // Add text to assistant display if not already present
            var videoText = parsedContent.message || getString('videoGenerating', 'Video generation started. Your video will be available within approximately 5 minutes.');
            if (!assistantDisplay.text) {
                assistantDisplay.text = videoText;
            } else if (assistantDisplay.text.indexOf(videoText) === -1) {
                assistantDisplay.text += '\n\n' + videoText;
            }
            
            // Add video placeholder attachment to assistant display
            var videoAttachment = {
                url: parsedContent.expected_url,
                label: parsedContent.expected_filename || 'Video (generating...)',
                downloadName: parsedContent.expected_filename || 'video.mp4',
                meta: getString('videoPending', 'Pending • ~5 min')
            };
            assistantDisplay.attachments = (assistantDisplay.attachments || []).concat([videoAttachment]);
            
            // If a bubble element was provided, also update the DOM to show the video attachment
            if (bubbleElement && bubbleElement.nodeType === Node.ELEMENT_NODE) {
                appendVideoAttachmentToBubble(bubbleElement, videoAttachment);
            }
        }
        
        /**
         * Append a video attachment to an existing bubble element's DOM.
         * Creates or appends to the attachments list as needed.
         * 
         * @param {HTMLElement} bubbleElement - The bubble element to update
         * @param {Object} attachment - The video attachment object with url, label, downloadName, meta
         */
        function appendVideoAttachmentToBubble(bubbleElement, attachment) {
            if (!bubbleElement || !attachment || !attachment.url) {
                return;
            }
            
            // Find or create the attachments list
            var list = bubbleElement.querySelector('.wp-mcp-ai-chat__bubble-attachments');
            if (!list) {
                list = document.createElement('ul');
                list.className = 'wp-mcp-ai-chat__bubble-attachments';
                bubbleElement.appendChild(list);
            }
            
            // Create the video attachment item
            var item = document.createElement('li');
            item.className = 'wp-mcp-ai-chat__bubble-attachment';
            
            // Render video player
            var videoContainer = document.createElement('div');
            videoContainer.className = 'wp-mcp-ai-chat__video-container';
            
            var video = document.createElement('video');
            video.controls = true;
            video.preload = 'metadata';
            video.className = 'wp-mcp-ai-chat__video-player';
            
            var source = document.createElement('source');
            source.src = attachment.url;
            
            // Determine MIME type based on URL
            var mimeType = getVideoMimeType(attachment.url);
            source.type = mimeType;
            
            video.appendChild(source);
            
            // Add fallback text
            var fallbackText = document.createTextNode(
                getString('videoNotSupported', 'Your browser does not support video playback.')
            );
            video.appendChild(fallbackText);
            
            videoContainer.appendChild(video);
            
            // Add download link below video
            var downloadLink = document.createElement('a');
            downloadLink.href = attachment.url;
            downloadLink.download = attachment.downloadName || 'video.mp4';
            downloadLink.className = 'wp-mcp-ai-chat__video-download';
            downloadLink.textContent = getString('downloadVideo', 'Download video');
            videoContainer.appendChild(downloadLink);
            
            item.appendChild(videoContainer);
            
            // Add metadata if present
            if (attachment.meta) {
                var meta = document.createElement('div');
                meta.className = 'wp-mcp-ai-chat__attachments-meta';
                meta.textContent = attachment.meta;
                item.appendChild(meta);
            }
            
            list.appendChild(item);
        }
        
        /**
         * Persist video attachment display metadata to assistant message and save conversation.
         * This ensures video placeholders are saved immediately and can be restored on page reload.
         * 
         * @param {Object} state - Chat state object
         * @param {Object} assistantMessage - The assistant message object to update
         * @param {Object} assistantDisplay - The display payload containing attachments and text
         * @param {HTMLElement} assistantMessageElement - The DOM element for extracting display metadata
         * @param {Object} options - Options for display metadata extraction (usage, cost, capabilityFlags)
         */
        function persistVideoAttachmentMetadata(state, assistantMessage, assistantDisplay, assistantMessageElement, options) {
            if (!assistantMessageElement || !assistantDisplay.attachments || assistantDisplay.attachments.length === 0) {
                return;
            }
            
            const displayMetadata = extractDisplayMetadata(assistantMessageElement, assistantDisplay, options || {});
            if (displayMetadata) {
                assistantMessage.display = displayMetadata;
            }
            
            // Save conversation to persist the video attachment immediately
            saveConversationToStorage(state);
            saveConversationToCCT(state, { silent: true });
        }

        // Add tool result messages to conversation if included in response.
        if (data && Array.isArray(data.tool_results) && data.tool_results.length > 0) {
            // Note: We don't update status here because the SSE stream processing
            // has already set the appropriate status (either "Tool completed successfully"
            // or "Tool is processing" for async pending tools).
            // Setting "Tool is processing" here would overwrite that completion status,
            // causing the status to show "Tool is processing" even after tools complete.
            
            data.tool_results.forEach(function (toolResult) {
                if (toolResult && toolResult.role === 'tool') {
                    // Skip pending async tool results - they will be added by displayAsyncToolResult
                    // when the async job completes. This prevents duplicate tool messages with
                    // mismatched tool_call_ids in the conversation, which would cause API errors
                    // when sending subsequent messages.
                    let parsedContent = toolResult.content;
                    if (typeof parsedContent === 'string') {
                        try {
                            parsedContent = JSON.parse(parsedContent);
                        } catch (e) {
                            parsedContent = null;
                        }
                    }
                    if (isAsyncPendingToolResult(parsedContent)) {
                        // Log for debugging
                        if (window.console && console.log) {
                            console.log('[WP oOS] Skipping pending async tool result in conversation (will be added on completion):', {
                                tool_name: toolResult.name,
                                job_id: parsedContent && parsedContent.job_id
                            });
                        }
                        return; // Skip this tool result
                    }
                    
                    // Add display metadata to tool result for persistence across page reloads
                    // Parse and normalize the tool result to extract display information
                    let parsedForDisplay = toolResult.content;
                    if (typeof parsedForDisplay === 'string') {
                        try {
                            parsedForDisplay = JSON.parse(parsedForDisplay);
                        } catch (e) {
                            parsedForDisplay = toolResult.content;
                        }
                    }
                    
                    const normalizedForDisplay = normaliseToolResultForDisplay(toolResult.name || '', parsedForDisplay);
                    const toolDisplay = createToolDisplayMetadata(normalizedForDisplay);
                    if (toolDisplay) {
                        toolResult.display = toolDisplay;
                    }
                    
                    state.conversation.push(toolResult);
                }
            });

            // Render tool results as attachments in the assistant's message.
            // This ensures images and other media files are displayed with links.
            // 
            // Handle two cases:
            // 1. Message has tool_calls: Match tool_results with tool_calls for proper ordering
            // 2. Message has no tool_calls: Process all tool_results (agentic loop final response)
            if (hasToolCalls && message.tool_calls) {
                // Case 1: Match tool results with tool calls in the current message
                // Log tool call matching for debugging sync/async tool execution
                if (window.console && console.log) {
                    console.log('[WP oOS] Processing tool_calls with matching tool_results:', {
                        toolCallsCount: message.tool_calls.length,
                        toolResultsCount: data.tool_results.length,
                        toolCallIds: message.tool_calls.map(function(tc) { return tc.id; }),
                        toolResultIds: data.tool_results.map(function(tr) { return tr.tool_call_id; })
                    });
                }
                
                message.tool_calls.forEach(function (toolCall) {
                    const toolCallId = toolCall.id || '';
                    if (!toolCallId) {
                        if (window.console && console.warn) {
                            console.warn('[WP oOS] Tool call missing ID, skipping:', toolCall);
                        }
                        return;
                    }

                    // Find matching tool result by tool_call_id
                    // This links the LLM's tool request with the backend's execution result
                    const matchingResult = data.tool_results.find(function (result) {
                        return result.tool_call_id === toolCallId;
                    });

                    if (!matchingResult || !matchingResult.content) {
                        if (window.console && console.warn) {
                            console.warn('[WP oOS] No matching tool result found for tool_call_id:', toolCallId);
                        }
                        return;
                    }

                    const toolName = matchingResult.name || (toolCall.function && toolCall.function.name) || '';
                    
                    // Parse the tool result content (JSON string) into an object
                    let parsedContent = matchingResult.content;
                    if (typeof parsedContent === 'string') {
                        try {
                            parsedContent = JSON.parse(parsedContent);
                        } catch (e) {
                            // If parsing fails, use the string as-is
                            parsedContent = matchingResult.content;
                        }
                    }
                    
                    // Check if this is an async pending result vs a sync completed result
                    // Sync tools: Return complete result immediately (e.g., search, get_post)
                    // Async tools: Return {async: true, status: 'pending', job_id: '...'} (e.g., video generation)
                    const isAsync = isAsyncPendingToolResult(parsedContent);
                    
                    // Log tool result type for debugging both sync and async flows
                    if (window.console && console.log) {
                        console.log('[WP oOS] Tool result:', {
                            toolName: toolName,
                            toolCallId: toolCallId,
                            executionType: isAsync ? 'async (pending)' : 'sync (completed)',
                            asyncFlag: parsedContent && parsedContent.async,
                            status: parsedContent && parsedContent.status,
                            jobId: parsedContent && parsedContent.job_id
                        });
                    }
                    
                    if (isAsync) {
                        // Async tool: Start polling for result (SSE-first with REST fallback)
                        // The job will complete in the background via WP Cron
                        if (window.console && console.log) {
                            console.log('[WP oOS] Starting async polling for background job:', {
                                toolName: toolName,
                                jobId: parsedContent.job_id,
                                toolCallId: toolCallId
                            });
                        }
                        
                        // For video generation, add placeholder attachment to assistant display
                        // Pass the bubble element to update the DOM immediately
                        addVideoPendingAttachment(assistantDisplay, parsedContent, assistantMessageElement);
                        
                        // Persist video attachment to assistant message for CCT/localStorage restoration
                        persistVideoAttachmentMetadata(state, assistantMessage, assistantDisplay, assistantMessageElement, {
                            usage: aggregatedUsage,
                            cost: aggregatedCost,
                            capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null
                        });
                        
                        startAsyncToolPolling(state, parsedContent, toolName, toolCallId);
                        return;
                    }
                    
                    // Sync tool: Process completed result immediately
                    const normalized = normaliseToolResultForDisplay(toolName, parsedContent);

                    if (normalized) {
                        // Add text from tool result to assistant display if available
                        if (normalized.text && typeof normalized.text === 'string') {
                            if (assistantDisplay.text) {
                                assistantDisplay.text += '\n\n' + normalized.text;
                            } else {
                                assistantDisplay.text = normalized.text;
                            }
                        }
                        
                        // Add chart HTML to assistant display if available (Chart.js visualizations)
                        mergeChartDataToDisplay(assistantDisplay, normalized);
                        
                        // Add attachments to the assistant display.
                        if (normalized.attachments && normalized.attachments.length > 0) {
                            assistantDisplay.attachments = (assistantDisplay.attachments || []).concat(normalized.attachments);
                        }

                        // Aggregate usage and cost from normalized tool result
                        const aggregated = aggregateToolUsageAndCost(normalized, aggregatedUsage, aggregatedCost);
                        aggregatedUsage = aggregated.usage;
                        aggregatedCost = aggregated.cost;
                    }
                });
            } else {
                // Case 2: No tool_calls in message, process all tool results
                // This happens in the agentic loop where the final response doesn't have tool_calls
                data.tool_results.forEach(function (toolResult) {
                    if (!toolResult || !toolResult.content) {
                        return;
                    }

                    const toolName = toolResult.name || '';
                    
                    // Parse the tool result content (JSON string) into an object
                    let parsedContent = toolResult.content;
                    if (typeof parsedContent === 'string') {
                        try {
                            parsedContent = JSON.parse(parsedContent);
                        } catch (e) {
                            // If parsing fails, use the string as-is
                            parsedContent = toolResult.content;
                        }
                    }
                    
                    // Check for async pending status (background-only tools like Veo video)
                    const isAsync = isAsyncPendingToolResult(parsedContent);
                    
                    // Log tool result type for agentic loop (final response without tool_calls)
                    // This path is taken when the LLM's final message doesn't include tool_calls
                    // but the backend has tool_results from earlier agentic iterations
                    if (window.console && console.log) {
                        console.log('[WP oOS] Agentic loop tool result:', {
                            toolName: toolName,
                            executionType: isAsync ? 'async (pending)' : 'sync (completed)',
                            asyncFlag: parsedContent && parsedContent.async,
                            status: parsedContent && parsedContent.status,
                            jobId: parsedContent && parsedContent.job_id
                        });
                    }
                    
                    if (isAsync) {
                        // Background-only tools (e.g., Veo video) must run async even in agentic loops
                        // Start polling for result via SSE or REST
                        if (window.console && console.log) {
                            console.log('[WP oOS] Starting async polling (agentic loop background-only tool):', {
                                toolName: toolName,
                                jobId: parsedContent.job_id,
                                toolCallId: toolResult.tool_call_id
                            });
                        }
                        
                        // For video generation, add placeholder attachment to assistant display
                        // Pass the bubble element to update the DOM immediately
                        addVideoPendingAttachment(assistantDisplay, parsedContent, assistantMessageElement);
                        
                        // Persist video attachment to assistant message for CCT/localStorage restoration
                        persistVideoAttachmentMetadata(state, assistantMessage, assistantDisplay, assistantMessageElement, {
                            usage: aggregatedUsage,
                            cost: aggregatedCost,
                            capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null
                        });
                        
                        startAsyncToolPolling(state, parsedContent, toolName, toolResult.tool_call_id);
                        return;
                    }
                    
                    // Sync tool result from agentic loop - process immediately
                    const normalized = normaliseToolResultForDisplay(toolName, parsedContent);

                    if (normalized) {
                        // Add text from tool result to assistant display if available
                        if (normalized.text && typeof normalized.text === 'string') {
                            if (assistantDisplay.text) {
                                assistantDisplay.text += '\n\n' + normalized.text;
                            } else {
                                assistantDisplay.text = normalized.text;
                            }
                        }
                        
                        // Add chart HTML to assistant display if available (Chart.js visualizations)
                        mergeChartDataToDisplay(assistantDisplay, normalized);
                        
                        // Add attachments to the assistant display.
                        if (normalized.attachments && normalized.attachments.length > 0) {
                            assistantDisplay.attachments = (assistantDisplay.attachments || []).concat(normalized.attachments);
                        }

                        // Aggregate usage and cost from normalized tool result
                        const aggregated = aggregateToolUsageAndCost(normalized, aggregatedUsage, aggregatedCost);
                        aggregatedUsage = aggregated.usage;
                        aggregatedCost = aggregated.cost;
                    }
                });
            }

            // Re-render the assistant message if we added attachments or text from tool results.
            if (assistantDisplay.attachments.length > 0 || assistantDisplay.text || assistantDisplay.chartHtml) {
                if (hasDisplayContent) {
                    // Update existing assistant message with attachments
                    const lastMessage = state.messagesEl.lastElementChild;
                    if (lastMessage && lastMessage.classList.contains('wp-mcp-ai-chat__bubble--assistant')) {
                        lastMessage.parentNode.removeChild(lastMessage);
                    }
                    const updatedMessageElement = appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {
                        state: state,
                        speech: {
                            state: state,
                            text: assistantDisplay.text || '',
                        },
                        usage: aggregatedUsage,
                        cost: aggregatedCost,
                        capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null,
                    });
                    
                    // Update conversation content with text from tool results.
                    // The assistantMessage was already pushed to the conversation array above,
                    // but since objects are passed by reference, we can update its content here
                    // and the change will be reflected in the conversation array.
                    // This ensures that any text added from tool results is properly persisted.
                    if (assistantDisplay.text) {
                        assistantMessage.content = assistantDisplay.text;
                    }
                    
                    // Update display metadata in already-saved message (including badges)
                    const displayMetadata = extractDisplayMetadata(updatedMessageElement, assistantDisplay, {
                        usage: aggregatedUsage,
                        cost: aggregatedCost,
                        capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null
                    });
                    if (displayMetadata) {
                        assistantMessage.display = displayMetadata;
                    }
                } else {
                    // No text content from LLM but we have attachments or text from tool results - show them
                    const newMessageElement = appendMessage(state.messagesEl, 'assistant', assistantDisplay, true, {
                        state: state,
                        speech: {
                            state: state,
                            text: assistantDisplay.text || '',
                        },
                        usage: aggregatedUsage,
                        cost: aggregatedCost,
                        capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null,
                    });
                    // Update conversation content with text from tool results.
                    // This ensures text from tools is persisted even when LLM returned no content.
                    if (assistantDisplay.text) {
                        assistantMessage.content = assistantDisplay.text;
                    } else if (!assistantMessage.content) {
                        // For OpenAI API compatibility, use null instead of empty string
                        // when we have tool_calls but no content
                        assistantMessage.content = hasToolCalls ? null : '';
                    }
                    // Add to conversation if not already added
                    // Use indexOf to check entire array, not just last element,
                    // since tool results may have been pushed after the assistant message
                    if (state.conversation.indexOf(assistantMessage) === -1) {
                        // Extract and preserve display metadata (including badges)
                        const displayMetadata = extractDisplayMetadata(newMessageElement, assistantDisplay, {
                            usage: aggregatedUsage,
                            cost: aggregatedCost,
                            capabilityFlags: capabilityFlags && capabilityFlags.length > 0 ? capabilityFlags : null
                        });
                        if (displayMetadata) {
                            assistantMessage.display = displayMetadata;
                        }
                        state.conversation.push(assistantMessage);
                    }
                    hasDisplayContent = true;
                }
            }
        }

        if (hasToolCalls) {
            // Tools are now executed server-side automatically in the agentic loop.
            // The frontend just displays the response which includes tool results.
            if (window.console && console.log) {
                console.log('[WP oOS] Server executed tools:', message.tool_calls);
                if (data && data.tool_results) {
                    console.log('[WP oOS] Tool results:', data.tool_results);
                }
            }
        }

        // Check if there are tool results that have completed (not async pending)
        // If so, set "Tool completed successfully" status instead of clearing
        // This allows the calling code's delayed clearStatus to work properly
        // hasToolResults was already declared at the start of this function
        var hasAsyncPending = false;
        
        if (hasToolResults) {
            // Check if any tool results are async pending
            for (var i = 0; i < data.tool_results.length; i++) {
                var toolResult = data.tool_results[i];
                if (toolResult && toolResult.content) {
                    var parsedContent = parseToolResultContent(toolResult.content);
                    if (isAsyncPendingToolResult(parsedContent)) {
                        hasAsyncPending = true;
                        break;
                    }
                }
            }
            
            if (!hasAsyncPending) {
                // All tools have completed - set completion status
                // The calling code will clear this after a delay
                setStatus(state.container, {
                    message: getString('toolSuccess', 'Tool completed successfully.'),
                    type: 'success',
                    showTime: false
                });
            } else {
                // Async tools still pending - clear status (polling will update it)
                setStatus(state.container, '');
            }
        } else {
            // No tool results - clear status as before
            setStatus(state.container, '');
        }

        // Save conversation to localStorage after all messages have been added.
        // This ensures that assistant responses to tool results persist on page reload.
        saveConversationToStorage(state);
        
        // Also save to CCT if available (silent, non-blocking)
        // This ensures the conversation persists across page reloads
        saveConversationToCCT(state, { silent: true });
        
        return Promise.resolve();
    }

    function handleError(state, error) {
        const fallbackMessage = getString('error', 'Something went wrong.');

        function extractMessage(payload) {
            if (!payload) {
                return '';
            }

            if (typeof payload === 'string') {
                return payload;
            }

            if (typeof payload.message === 'string' && payload.message.trim()) {
                return payload.message;
            }

            if (typeof payload.reason === 'string' && payload.reason.trim()) {
                return payload.reason;
            }

            if (payload.data) {
                const dataMessage = extractMessage(payload.data);
                if (dataMessage) {
                    return dataMessage;
                }
            }

            const nestedKeys = ['last_error', 'error', 'incomplete_details', 'response'];

            for (let i = 0; i < nestedKeys.length; i++) {
                const key = nestedKeys[i];
                if (payload[key]) {
                    const nestedMessage = extractMessage(payload[key]);
                    if (nestedMessage) {
                        return nestedMessage;
                    }
                }
            }

            return '';
        }

        function handleResolvedMessage(resolvedMessage) {
            let message = resolvedMessage;

            if (typeof message !== 'string') {
                message = '';
            }

            message = message.trim() || fallbackMessage;

            appendMessage(state.messagesEl, 'system', { text: message }, false, { state: state });
            setStatus(state.container, message);
        }

        if (error && typeof error.json === 'function') {
            error
                .json()
                .then(function (body) {
                    handleResolvedMessage(extractMessage(body));
                })
                .catch(function () {
                    handleResolvedMessage('');
                });
        } else {
            handleResolvedMessage('');
        }
    }

    function disableForm(state, disabled) {
        const container = state.container;
        const elements = container.querySelectorAll('button, textarea, input');
        Array.prototype.forEach.call(elements, function (element) {
            element.disabled = disabled;
        });

        if (disabled) {
            if (state.attachButton) {
                state.attachButton.disabled = true;
            }
            if (state.fileInput) {
                state.fileInput.disabled = true;
            }
        } else {
            updateAttachButtonState(state);
        }
    }

    /**
     * Set status message with optional indicator type and time tracking.
     * 
     * @param {HTMLElement} container - Chat container element
     * @param {string|Object} message - Status message or options object
     * @param {Object} options - Optional settings (type, showTime, startTime)
     */
    /**
     * Set status message in a chat container.
     * Uses UI utilities service if available, otherwise uses internal implementation.
     * 
     * @param {Element} container - Chat container element
     * @param {string|Object} message - Status message or options object
     * @param {Object} options - Additional options (if message is string)
     */
    function setStatus(container, message, options) {
        if (uiUtilsService && uiUtilsService.setStatus) {
            return uiUtilsService.setStatus(container, message, options);
        }

        const statusEl = container.querySelector('.wp-mcp-ai-chat__status');
        if (!statusEl) {
            return;
        }

        // Handle both string and object parameters for backward compatibility
        let messageText = '';
        let opts = options || {};
        
        if (typeof message === 'object' && message !== null) {
            opts = message;
            messageText = opts.message || '';
        } else {
            messageText = message || '';
        }

        if (!messageText) {
            statusEl.innerHTML = '';
            statusEl.hidden = true;
            statusEl.className = 'wp-mcp-ai-chat__status';
            // Clear any time tracking
            if (statusEl._timeInterval) {
                clearInterval(statusEl._timeInterval);
                statusEl._timeInterval = null;
            }
            return;
        }

        // Clear existing time interval if any
        if (statusEl._timeInterval) {
            clearInterval(statusEl._timeInterval);
            statusEl._timeInterval = null;
        }

        // Determine indicator type
        const type = opts.type || 'default';
        const showTime = opts.showTime !== false; // Show time by default
        const startTime = opts.startTime || Date.now();
        
        // Build status HTML with indicator
        let indicatorHTML = '';
        let statusClass = 'wp-mcp-ai-chat__status';
        
        if (type === 'thinking') {
            statusClass += ' wp-mcp-ai-chat__status--thinking';
            indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
                '<span class="wp-mcp-ai-chat__status-spinner"></span>' +
                '</span>';
        } else if (type === 'processing') {
            statusClass += ' wp-mcp-ai-chat__status--processing';
            indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
                '<span class="wp-mcp-ai-chat__status-spinner"></span>' +
                '</span>';
        } else if (type === 'streaming') {
            statusClass += ' wp-mcp-ai-chat__status--streaming';
            indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
                '<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
                '<path d="M2 10a8 8 0 0116 0H2zm8-8a8 8 0 010 16V2z" opacity="0.3"/>' +
                '<path d="M10 2a8 8 0 018 8h-2a6 6 0 00-6-6V2z">' +
                '<animateTransform attributeName="transform" type="rotate" from="0 10 10" to="360 10 10" dur="1s" repeatCount="indefinite"/>' +
                '</path>' +
                '</svg>' +
                '</span>';
        } else if (type === 'text-stream') {
            statusClass += ' wp-mcp-ai-chat__status--text-stream';
            indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
                '<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
                '<path d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1z"/>' +
                '</svg>' +
                '</span>';
        } else if (type === 'tool') {
            indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
                '<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
                '<path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm0 12a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM3 10a1 1 0 011-1h1a1 1 0 110 2H4a1 1 0 01-1-1zm12 0a1 1 0 011-1h1a1 1 0 110 2h-1a1 1 0 01-1-1zM7.05 4.636a1 1 0 010 1.414L6.343 6.757a1 1 0 11-1.414-1.414L5.636 4.636a1 1 0 011.414 0zm8.485 8.485a1 1 0 010 1.414l-.707.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zm-9.9 0a1 1 0 011.414 0l.707.707a1 1 0 11-1.414 1.414l-.707-.707a1 1 0 010-1.414zm8.486-8.486a1 1 0 011.414 0l.707.707a1 1 0 11-1.414 1.414l-.707-.707a1 1 0 010-1.414z"/>' +
                '<animateTransform attributeName="transform" type="rotate" from="0 10 10" to="360 10 10" dur="2s" repeatCount="indefinite"/>' +
                '</svg>' +
                '</span>';
        } else if (type === 'success') {
            statusClass += ' wp-mcp-ai-chat__status--success';
            indicatorHTML = '<span class="wp-mcp-ai-chat__status-indicator">' +
                '<svg class="wp-mcp-ai-chat__status-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
                '<path fill="currentColor" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>' +
                '</svg>' +
                '</span>';
        }
        
        // Build time display
        let timeHTML = '';
        if (showTime && (type === 'thinking' || type === 'processing' || type === 'tool' || type === 'text-stream')) {
            timeHTML = '<span class="wp-mcp-ai-chat__status-time" data-start-time="' + startTime + '">0s</span>';
        }
        
        // Escape message text
        const escapedMessage = escapeHtml(messageText);
        
        // Set status content
        statusEl.className = statusClass;
        statusEl.innerHTML = indicatorHTML + 
            '<span class="wp-mcp-ai-chat__status-text">' + escapedMessage + '</span>' + 
            timeHTML;
        statusEl.hidden = false;
        
        // Start time tracking if enabled
        if (timeHTML) {
            const timeEl = statusEl.querySelector('.wp-mcp-ai-chat__status-time');
            if (timeEl) {
                // Use batched DOM updates to prevent setTimeout violations
                statusEl._timeInterval = setInterval(function() {
                    const elapsed = Math.floor((Date.now() - startTime) / 1000);
                    
                    // Schedule DOM update in next animation frame to prevent forced reflow
                    domUpdateBatcher.schedule(function() {
                        if (timeEl && timeEl.parentNode) {
                            timeEl.textContent = formatElapsedTime(elapsed);
                        } else {
                            // Element removed, clear interval
                            if (statusEl._timeInterval) {
                                clearInterval(statusEl._timeInterval);
                                statusEl._timeInterval = null;
                            }
                        }
                    });
                }, 1000);
            }
        }
    }

    /**
     * Format elapsed time in seconds to human-readable string.
     * Uses UI utilities service if available, otherwise uses internal implementation.
     * 
     * @param {number} seconds - Elapsed seconds
     * @return {string} Formatted time (e.g., "5s", "1m 30s", "2m")
     */
    function formatElapsedTime(seconds) {
        if (uiUtilsService && uiUtilsService.formatElapsedTime) {
            return uiUtilsService.formatElapsedTime(seconds);
        }

        if (seconds < 60) {
            return seconds + 's';
        }
        
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        
        if (remainingSeconds === 0) {
            return minutes + 'm';
        }
        
        return minutes + 'm ' + remainingSeconds + 's';
    }

    /**
     * Clear status message in a chat container.
     * Uses UI utilities service if available, otherwise uses internal implementation.
     * 
     * @param {Element} container - Chat container element
     */
    function clearStatus(container) {
        if (uiUtilsService && uiUtilsService.clearStatus) {
            return uiUtilsService.clearStatus(container);
        }
        setStatus(container, '');
    }

    /**
     * Attach usage and cost badges to an assistant message element.
     * Phase 7 Week 5-6: Enhanced Token Tracking with Real-Time Cost Attribution
     * 
     * @param {HTMLElement} messageElement - The message element to attach badges to
     * @param {Object|null} usage - Usage data {prompt_tokens, completion_tokens, total_tokens}
     * @param {Object|null} costData - Cost data {cost_usd, is_estimated, provider, model}
     */
    function attachUsageBadges(messageElement, usage, costData) {
        // Only show if setting is enabled
        if (!globalConfig.showUsageCosts) {
            return;
        }

        // Need at least usage data
        if (!usage || typeof usage !== 'object') {
            return;
        }

        // Extract token counts
        const promptTokens = parseInt(usage.prompt_tokens || 0, 10);
        const completionTokens = parseInt(usage.completion_tokens || 0, 10);
        const totalTokens = parseInt(usage.total_tokens || (promptTokens + completionTokens), 10);
        const isEstimated = usage.is_estimated === true || usage.is_estimated === 1;

        // Extract model and provider
        const model = usage.model || (costData && costData.model) || '';
        const provider = usage.provider || (costData && costData.provider) || '';

        // Don't show if no tokens
        if (totalTokens === 0) {
            return;
        }

        // Create container for badges
        const usageInfoEl = document.createElement('div');
        usageInfoEl.className = 'wp-mcp-ai-chat__usage-info';

        // Create tokens badge
        const tokensBadge = document.createElement('span');
        tokensBadge.className = 'wp-mcp-ai-chat__usage-badge wp-mcp-ai-chat__usage-badge--tokens';
        if (isEstimated) {
            tokensBadge.classList.add('wp-mcp-ai-chat__usage-badge--estimated');
        }
        
        const tokensLabel = document.createElement('span');
        tokensLabel.className = 'wp-mcp-ai-chat__usage-badge-label';
        tokensLabel.textContent = (isEstimated ? '~' : '') + getString('tokensLabel', 'Tokens') + ': ';
        
        const tokensValue = document.createElement('span');
        tokensValue.className = 'wp-mcp-ai-chat__usage-badge-value';
        tokensValue.textContent = totalTokens.toLocaleString();
        
        tokensBadge.appendChild(tokensLabel);
        tokensBadge.appendChild(tokensValue);
        
        // Add tooltip with breakdown
        if (promptTokens > 0 || completionTokens > 0) {
            const breakdownText = getString('tokensBreakdown', '%1$d total (%2$d in / %3$d out)')
                .replace('%1$d', totalTokens.toLocaleString())
                .replace('%2$d', promptTokens.toLocaleString())
                .replace('%3$d', completionTokens.toLocaleString());
            const tooltipParts = [breakdownText];
            if (isEstimated) {
                tooltipParts.push('(Estimated)');
            }
            tokensBadge.title = tooltipParts.join(' ');
        }
        
        usageInfoEl.appendChild(tokensBadge);

        // Create cost badge if cost data is available
        if (costData && typeof costData === 'object' && typeof costData.cost_usd === 'number') {
            const costBadge = document.createElement('span');
            const isEstimated = costData.is_estimated === true || costData.is_estimated === 1;
            
            costBadge.className = 'wp-mcp-ai-chat__usage-badge wp-mcp-ai-chat__usage-badge--cost';
            if (isEstimated) {
                costBadge.classList.add('wp-mcp-ai-chat__usage-badge--estimated');
            }
            
            const costLabel = document.createElement('span');
            costLabel.className = 'wp-mcp-ai-chat__usage-badge-label';
            costLabel.textContent = (isEstimated ? getString('estimatedCostLabel', 'Est. Cost') : getString('costLabel', 'Cost')) + ': ';
            
            const costValue = document.createElement('span');
            costValue.className = 'wp-mcp-ai-chat__usage-badge-value';
            
            // Format cost as currency
            const costStr = costData.cost_usd.toFixed(4);
            costValue.textContent = getString('costAmount', '$%s').replace('%s', costStr);
            
            costBadge.appendChild(costLabel);
            costBadge.appendChild(costValue);
            
            // Add tooltip with provider/model if available
            if (costData.provider || costData.model) {
                const tooltipParts = [];
                if (costData.provider) {
                    tooltipParts.push('Provider: ' + costData.provider);
                }
                if (costData.model) {
                    tooltipParts.push('Model: ' + costData.model);
                }
                if (isEstimated) {
                    tooltipParts.push('(Estimated)');
                }
                costBadge.title = tooltipParts.join(' | ');
            }
            
            usageInfoEl.appendChild(costBadge);
        }

        // Create model badge if model is available
        if (model) {
            const modelBadge = document.createElement('span');
            modelBadge.className = 'wp-mcp-ai-chat__usage-badge wp-mcp-ai-chat__usage-badge--model';
            if (isEstimated) {
                modelBadge.classList.add('wp-mcp-ai-chat__usage-badge--estimated');
            }
            
            const modelLabel = document.createElement('span');
            modelLabel.className = 'wp-mcp-ai-chat__usage-badge-label';
            modelLabel.textContent = getString('modelLabel', 'Model') + ': ';
            
            const modelValue = document.createElement('span');
            modelValue.className = 'wp-mcp-ai-chat__usage-badge-value';
            modelValue.textContent = model;
            
            modelBadge.appendChild(modelLabel);
            modelBadge.appendChild(modelValue);
            
            usageInfoEl.appendChild(modelBadge);
        }

        // Create provider badge if provider is available
        if (provider) {
            const providerBadge = document.createElement('span');
            providerBadge.className = 'wp-mcp-ai-chat__usage-badge wp-mcp-ai-chat__usage-badge--provider';
            if (isEstimated) {
                providerBadge.classList.add('wp-mcp-ai-chat__usage-badge--estimated');
            }
            
            const providerLabel = document.createElement('span');
            providerLabel.className = 'wp-mcp-ai-chat__usage-badge-label';
            providerLabel.textContent = getString('providerLabel', 'Provider') + ': ';
            
            const providerValue = document.createElement('span');
            providerValue.className = 'wp-mcp-ai-chat__usage-badge-value';
            // Capitalize first letter of provider
            providerValue.textContent = provider.charAt(0).toUpperCase() + provider.slice(1);
            
            providerBadge.appendChild(providerLabel);
            providerBadge.appendChild(providerValue);
            
            usageInfoEl.appendChild(providerBadge);
        }

        // Append to message element
        messageElement.appendChild(usageInfoEl);
    }

    /**
     * Attach capability flag badges to a message element.
     * Displays tool capability flags like 'read-only', 'local-only', etc.
     * 
     * @param {HTMLElement} messageElement - The message element to attach badges to
     * @param {Array|null} capabilityFlags - Array of capability flag strings
     */
    function attachCapabilityFlagBadges(messageElement, capabilityFlags) {
        // Only show if we have flags
        if (!capabilityFlags || !Array.isArray(capabilityFlags) || capabilityFlags.length === 0) {
            return;
        }

        // Create container for capability badges
        const flagsContainer = document.createElement('div');
        flagsContainer.className = 'wp-mcp-ai-chat__capability-flags';
        flagsContainer.style.cssText = 'display: flex; flex-wrap: wrap; gap: 4px; margin-top: 0.5rem;';

        // Create a badge for each flag
        capabilityFlags.forEach(function (flag) {
            if (!flag || typeof flag !== 'string') {
                return;
            }

            const badge = document.createElement('span');
            badge.className = 'wp-mcp-ai-chat__capability-badge';
            
            // Style the badge with appropriate colors based on flag type
            let backgroundColor = '#6b7280'; // Default gray
            const color = 'white';
            
            // Color coding for different flag types
            if (flag.includes('read-only') || flag.includes('local-only')) {
                backgroundColor = '#10b981'; // Green for safe operations
            } else if (flag.includes('write') || flag.includes('state-changing')) {
                backgroundColor = '#f59e0b'; // Orange for write operations
            } else if (flag.includes('requires-')) {
                backgroundColor = '#3b82f6'; // Blue for requirements
            } else if (flag.includes('external-api') || flag.includes('network-dependent')) {
                backgroundColor = '#8b5cf6'; // Purple for external operations
            } else if (flag.includes('consumes-tokens')) {
                backgroundColor = '#ec4899'; // Pink for token-consuming operations
            }
            
            badge.style.cssText = 'display: inline-block; font-size: 10px; padding: 2px 6px; ' +
                'background: ' + backgroundColor + '; color: ' + color + '; border-radius: 3px; ' +
                'font-weight: 500; text-transform: lowercase;';
            badge.textContent = flag;
            badge.title = 'Tool capability: ' + flag;
            
            flagsContainer.appendChild(badge);
        });

        // Append to message element
        messageElement.appendChild(flagsContainer);
    }

    function appendMessage(listEl, role, payload, allowMarkdown, options) {
        if (typeof payload === 'undefined' || payload === null) {
            return null;
        }

        let text = '';
        let attachments = [];

        if (typeof payload === 'object' && !Array.isArray(payload)) {
            if (Array.isArray(payload.attachments)) {
                attachments = payload.attachments
                    .map(function (attachment) {
                        if (!attachment || typeof attachment !== 'object') {
                            return null;
                        }

                        const url = attachment.url || '';
                        if (!url) {
                            return null;
                        }

                        let label = attachment.label || attachment.name || '';
                        if (!label) {
                            label = getString('downloadAttachment', 'Download attachment');
                        }

                        return {
                            url: url,
                            label: label,
                            downloadName: attachment.downloadName || '',
                            meta: attachment.meta || '',
                        };
                    })
                    .filter(function (attachment) {
                        return attachment !== null;
                    });
            }

            // Handle both 'message' and 'text' fields when available
            // Priority: combine message + text if both exist, then fallback to content/raw
            const hasMessageField = Object.prototype.hasOwnProperty.call(payload, 'message');
            const hasTextField = Object.prototype.hasOwnProperty.call(payload, 'text');
            
            if (hasMessageField && hasTextField) {
                // Both fields exist - combine them with a separator
                const messageStr = String(payload.message || '').trim();
                const textStr = String(payload.text || '').trim();
                if (messageStr && textStr && messageStr !== textStr) {
                    text = messageStr + '\n\n' + textStr;
                } else if (messageStr) {
                    text = messageStr;
                } else {
                    text = textStr;
                }
            } else if (hasMessageField) {
                text = String(payload.message || '');
            } else if (hasTextField) {
                text = String(payload.text || '');
            } else if (payload.content) {
                text = normaliseContent(payload.content);
            } else if (payload.raw) {
                text = String(payload.raw);
            }
        } else {
            text = String(payload);
        }

        if (typeof text !== 'string') {
            text = '';
        }

        const hasText = text.trim() !== '';
        const hasAttachments = attachments.length > 0;
        
        // Check for chart HTML content (from Chart.js visualization tools)
        const chartHtml = payload && typeof payload.chartHtml === 'string' ? payload.chartHtml : '';
        const hasChartHtml = chartHtml.trim() !== '';
        const chartWidth = payload && payload.chartWidth ? payload.chartWidth : 800;
        const chartHeight = payload && payload.chartHeight ? payload.chartHeight : 400;
        
        // Check for bubble type hints from payload
        const bubbleType = payload && payload.bubbleType ? payload.bubbleType : null;
        
        // Determine bubble type based on content or hint
        const showJsonResponse = bubbleType === 'json' || (
            hasText &&
            !hasAttachments &&
            shouldDisplayJsonResponse(role, text, allowMarkdown)
        );
        // Truncated responses should be detected regardless of markdown mode
        // because orchestration layer truncation is a special case that needs
        // to be visually distinguished from normal responses
        const showTruncatedResponse = bubbleType === 'truncated' || (
            hasText &&
            !showJsonResponse &&
            isTruncatedByOrchestration(text)
        );

        if (!hasText && !hasAttachments && !hasChartHtml) {
            return null;
        }

        // Create a single element with both message and bubble classes
        const entry = document.createElement('div');
        entry.className = 'wp-mcp-ai-chat__message wp-mcp-ai-chat__bubble wp-mcp-ai-chat__bubble--' + role;
        
        // Track bubble type for persistence
        let activeBubbleType = null;

        if (showJsonResponse) {
            entry.classList.add('wp-mcp-ai-chat__bubble--json');
            activeBubbleType = 'json';
            entry.appendChild(createJsonResponseElement(text));
        } else if (showTruncatedResponse) {
            entry.classList.add('wp-mcp-ai-chat__bubble--truncated');
            activeBubbleType = 'truncated';
            entry.appendChild(createTruncatedResponseElement(text));
        } else if (hasText) {
            if (allowMarkdown) {
                entry.innerHTML = renderMarkdown(text);
            } else {
                const normalisedText = String(text).replace(/\r\n|\r|\u2028|\u2029/g, '\n');
                entry.innerHTML = escapeHtml(normalisedText).replace(/\n/g, '<br />');
            }
        }
        
        // Render chart HTML in a sandboxed iframe (Chart.js visualizations)
        if (hasChartHtml) {
            entry.classList.add('wp-mcp-ai-chat__bubble--chart');
            activeBubbleType = 'chart';
            entry.appendChild(createChartBlockElement(chartHtml, chartWidth, chartHeight));
        }
        
        // Store bubble type on element for retrieval
        if (activeBubbleType) {
            entry.dataset.bubbleType = activeBubbleType;
        }

        if (hasAttachments) {
            const list = document.createElement('ul');
            list.className = 'wp-mcp-ai-chat__bubble-attachments';

            // Use DocumentFragment to batch DOM operations (optimization, disabled in debug mode)
            const useFragment = OPTIMIZATIONS_ENABLED;
            const container = useFragment ? document.createDocumentFragment() : list;
            
            attachments.forEach(function (attachment) {
                const item = document.createElement('li');
                item.className = 'wp-mcp-ai-chat__bubble-attachment';

                // Check if this is a video attachment
                const isVideo = isVideoAttachment(attachment);
                
                if (isVideo) {
                    // Render video player
                    const videoContainer = document.createElement('div');
                    videoContainer.className = 'wp-mcp-ai-chat__video-container';
                    
                    const video = document.createElement('video');
                    video.controls = true;
                    video.preload = 'metadata';
                    video.className = 'wp-mcp-ai-chat__video-player';
                    
                    const source = document.createElement('source');
                    source.src = attachment.url;
                    
                    // Determine MIME type based on URL
                    const mimeType = getVideoMimeType(attachment.url);
                    source.type = mimeType;
                    
                    video.appendChild(source);
                    
                    // Add fallback text as a separate text node AFTER the source element
                    // This is the correct way to add fallback content to HTML5 video elements
                    const fallbackText = document.createTextNode(
                        getString('videoNotSupported', 'Your browser does not support video playback.')
                    );
                    video.appendChild(fallbackText);
                    
                    videoContainer.appendChild(video);
                    
                    // Add download link below video
                    const downloadLink = document.createElement('a');
                    downloadLink.href = attachment.url;
                    downloadLink.download = attachment.downloadName || 'video.mp4';
                    downloadLink.className = 'wp-mcp-ai-chat__video-download';
                    downloadLink.textContent = getString('downloadVideo', 'Download video');
                    videoContainer.appendChild(downloadLink);
                    
                    item.appendChild(videoContainer);
                    
                    if (attachment.meta) {
                        const meta = document.createElement('div');
                        meta.className = 'wp-mcp-ai-chat__attachments-meta';
                        meta.textContent = attachment.meta;
                        item.appendChild(meta);
                    }
                } else {
                    // Render as download link (existing behavior)
                    const link = document.createElement('a');
                    link.href = attachment.url;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = attachment.label;

                    if (attachment.downloadName) {
                        link.download = attachment.downloadName;
                    }

                    item.appendChild(link);

                    if (attachment.meta) {
                        const meta = document.createElement('span');
                        meta.className = 'wp-mcp-ai-chat__attachments-meta';
                        meta.textContent = attachment.meta;
                        item.appendChild(document.createTextNode(' – '));
                        item.appendChild(meta);
                    }
                }

                container.appendChild(item);
            });

            if (useFragment) {
                list.appendChild(container);
            }
            entry.appendChild(list);
        }

        // Get state for delete button - use options.state if available, fallback to speech state
        const chatState = (options && options.state) || (options && options.speech ? options.speech.state : null);

        if (role === 'assistant') {
            const speechState = options && options.speech ? options.speech.state || null : null;
            const speechText = options && options.speech ? options.speech.text || '' : text;
            attachSpeechButton(entry, speechState, speechText);
            attachCopyButton(entry, speechText);
            
            // Attach delete button for assistant messages
            if (chatState) {
                attachDeleteButton(entry, chatState, role);
            }

            // Attach usage and cost badges if data is available
            // Phase 7 Week 5-6: Enhanced Token Tracking
            const usage = options && options.usage ? options.usage : null;
            const costData = options && options.cost ? options.cost : null;
            attachUsageBadges(entry, usage, costData);

            // Attach capability flag badges if data is available
            const capabilityFlags = options && options.capabilityFlags ? options.capabilityFlags : null;
            attachCapabilityFlagBadges(entry, capabilityFlags);

            // Auto-play speech if voice chat mode is active
            if (speechState && speechState.voiceChatModeActive) {
                // Find the speech button and trigger it after a short delay
                setTimeout(function() {
                    const speechButton = entry.querySelector('.' + SPEECH_BUTTON_CLASS);
                    if (speechButton && speechButton.dataset && speechButton.dataset.speechText) {
                        handleSpeechButtonClick(speechState, speechButton);
                    }
                    // Reset voice chat mode after auto-playing
                    speechState.voiceChatModeActive = false;
                }, 300);
            }
        }
        
        // Attach delete button for user messages
        if (role === 'user' && chatState) {
            attachDeleteButton(entry, chatState, role);
        }
        
        // Attach delete button for tool messages
        if (role === 'tool' && chatState) {
            attachDeleteButton(entry, chatState, role);
        }
        
        // Attach delete button for system messages
        if (role === 'system' && chatState) {
            attachDeleteButton(entry, chatState, role);
            
            // Attach save button for system messages
            // Allows users to save individual system responses to localStorage and CCT
            const messageIndex = (options && typeof options.messageIndex === 'number')
                ? options.messageIndex
                : (chatState.conversation && Array.isArray(chatState.conversation))
                    ? chatState.conversation.length - 1
                    : 0;
            attachSaveButton(entry, chatState, messageIndex);
        }

        listEl.appendChild(entry);
        scrollBatcher.scrollToBottom(listEl);

        return entry;
    }

    function shouldDisplayJsonResponse(role, text, allowMarkdown) {
        if (allowMarkdown) {
            return false;
        }

        return isLikelyJson(text);
    }

    function isLikelyJson(text) {
        if (!text || typeof text !== 'string') {
            return false;
        }

        const trimmed = text.trim();
        if (!trimmed) {
            return false;
        }

        const firstChar = trimmed.charAt(0);
        if (firstChar !== '{' && firstChar !== '[') {
            return false;
        }

        try {
            JSON.parse(trimmed);
            return true;
        } catch (error) {
            return false;
        }
    }

    function isTruncatedByOrchestration(text) {
        if (!text || typeof text !== 'string') {
            return false;
        }

        return text.includes('[... Result truncated by orchestration layer to fit within budget constraints ...]');
    }

    function createJsonResponseElement(text) {
        const details = document.createElement('details');
        details.className = 'wp-mcp-ai-chat__json-response';

        const summary = document.createElement('summary');
        summary.className = 'wp-mcp-ai-chat__json-summary';

        const icon = document.createElement('span');
        icon.className = 'wp-mcp-ai-chat__json-icon';
        icon.innerHTML =
            '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
            '<path d="M12 8.5a1 1 0 0 1 .7.29l5 5a1 1 0 0 1-1.4 1.42L12 10.91l-4.3 4.3a1 1 0 1 1-1.4-1.42l5-5a1 1 0 0 1 .7-.29z" />' +
            '</svg>';
        summary.appendChild(icon);

        const label = document.createElement('span');
        label.className = 'wp-mcp-ai-chat__json-label';
        label.textContent = getString('jsonResponse', 'JSON response');
        summary.appendChild(label);

        details.appendChild(summary);

        const pre = document.createElement('pre');
        pre.className = 'wp-mcp-ai-chat__json-content';
        pre.textContent = text;
        details.appendChild(pre);

        return details;
    }

    function createTruncatedResponseElement(text) {
        const details = document.createElement('details');
        details.className = 'wp-mcp-ai-chat__truncated-response';

        const summary = document.createElement('summary');
        summary.className = 'wp-mcp-ai-chat__truncated-summary';

        const icon = document.createElement('span');
        icon.className = 'wp-mcp-ai-chat__truncated-icon';
        icon.innerHTML =
            '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
            '<path d="M12 8.5a1 1 0 0 1 .7.29l5 5a1 1 0 0 1-1.4 1.42L12 10.91l-4.3 4.3a1 1 0 1 1-1.4-1.42l5-5a1 1 0 0 1 .7-.29z" />' +
            '</svg>';
        summary.appendChild(icon);

        const label = document.createElement('span');
        label.className = 'wp-mcp-ai-chat__truncated-label';
        label.textContent = getString('truncatedResponse', 'Truncated response (click to expand)');
        summary.appendChild(label);

        details.appendChild(summary);

        const pre = document.createElement('pre');
        pre.className = 'wp-mcp-ai-chat__truncated-content';
        pre.textContent = text;
        details.appendChild(pre);

        return details;
    }

    /**
     * Create a chart block element for rendering Chart.js visualizations.
     * Uses a sandboxed iframe for security, following WordPress standards.
     * 
     * @param {string} html - Complete HTML document with Chart.js code
     * @param {number} width - Chart width in pixels
     * @param {number} height - Chart height in pixels
     * @return {HTMLElement} Chart block container element
     */
    function createChartBlockElement(html, width, height) {
        const container = document.createElement('div');
        container.className = 'wp-mcp-ai-chat__chart-block';
        
        // Create sandboxed iframe for secure chart rendering
        // The sandbox attribute restricts the iframe's capabilities for security
        const iframe = document.createElement('iframe');
        iframe.className = 'wp-mcp-ai-chat__chart-iframe';
        iframe.setAttribute('sandbox', 'allow-scripts');
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('title', getString('chartVisualization', 'Chart Visualization'));
        
        // Calculate aspect ratio with guard against division by zero
        const safeWidth = width > 0 ? width : 800;
        const safeHeight = height > 0 ? height : 400;
        const aspectRatio = safeHeight / safeWidth;
        
        // Create wrapper for responsive aspect ratio sizing
        const wrapper = document.createElement('div');
        wrapper.className = 'wp-mcp-ai-chat__chart-wrapper';
        wrapper.style.position = 'relative';
        wrapper.style.width = '100%';
        wrapper.style.paddingBottom = (aspectRatio * 100) + '%';
        wrapper.style.overflow = 'hidden';
        
        // Set iframe styles for absolute positioning inside wrapper
        iframe.style.position = 'absolute';
        iframe.style.top = '0';
        iframe.style.left = '0';
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';
        iframe.style.borderRadius = '8px';
        
        // Use srcdoc to inject the HTML content directly
        // This is more secure than blob URLs and works well with the sandbox
        iframe.setAttribute('srcdoc', html);
        
        wrapper.appendChild(iframe);
        container.appendChild(wrapper);
        
        return container;
    }

    /**
     * Render markdown to HTML.
     * Uses markdown service if available, otherwise uses internal implementation.
     * 
     * @param {string} text - Markdown text
     * @return {string} HTML output
     */
    function renderMarkdown(text) {
        if (markdownService && markdownService.renderMarkdown) {
            return markdownService.renderMarkdown(text);
        }

        if (!text) {
            return '';
        }

        const placeholderBase = 'WP_MCP_AI_' + Math.random().toString(36).slice(2);
        const codeBlocks = [];
        const inlineCodes = [];
        const images = [];
        const links = [];
        let processed = String(text).replace(/\r\n|\r|\u2028|\u2029/g, '\n');

        processed = processed.replace(/```([\w+-]*)\n?([\s\S]*?)```/g, function (match, language, code) {
            const placeholder = '@@' + placeholderBase + '_CODE_' + codeBlocks.length + '@@';
            codeBlocks.push({
                placeholder: placeholder,
                language: (language || '').trim(),
                code: code.replace(/\s+$/, ''),
            });
            return placeholder;
        });

        processed = processed.replace(/`([^`]+)`/g, function (match, code) {
            const placeholder = '@@' + placeholderBase + '_INLINE_' + inlineCodes.length + '@@';
            inlineCodes.push({
                placeholder: placeholder,
                code: code,
            });
            return placeholder;
        });

        // Extract images BEFORE links (images use ![alt](url) syntax)
        processed = processed.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function (match, alt, url) {
            const placeholder = '@@' + placeholderBase + '_IMAGE_' + images.length + '@@';
            images.push({
                placeholder: placeholder,
                alt: alt,
                url: url,
            });
            return placeholder;
        });

        // Extract links (after images to avoid matching image syntax)
        processed = processed.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (match, label, url) {
            const placeholder = '@@' + placeholderBase + '_LINK_' + links.length + '@@';
            links.push({
                placeholder: placeholder,
                label: label,
                url: url,
            });
            return placeholder;
        });

        processed = escapeHtml(processed);

        const codePlaceholderMap = {};
        codeBlocks.forEach(function (item) {
            codePlaceholderMap[item.placeholder] = true;
        });

        const lines = processed.split('\n');
        const htmlParts = [];
        let paragraphLines = [];
        const listStack = []; // Stack to handle nested lists: [{type: 'ul', items: []}]

        function flushParagraph() {
            if (!paragraphLines.length) {
                return;
            }
            htmlParts.push('<p>' + paragraphLines.join('<br />') + '</p>');
            paragraphLines = [];
        }

        function flushAllLists() {
            while (listStack.length > 0) {
                const list = listStack.pop();
                if (list.items.length > 0) {
                    const html = '<' + list.type + '>' + list.items.join('') + '</' + list.type + '>';
                    if (listStack.length > 0) {
                        // Nested list - append to parent's last item before closing tag
                        // This is safe because we control the HTML generation and </li> only appears
                        // at the end of each list item string we create
                        const parent = listStack[listStack.length - 1];
                        if (parent.items.length > 0) {
                            const lastItemIndex = parent.items.length - 1;
                            parent.items[lastItemIndex] = parent.items[lastItemIndex].replace('</li>', html + '</li>');
                        }
                    } else {
                        // Top-level list - add to HTML parts
                        htmlParts.push(html);
                    }
                }
            }
        }

        function getIndentLevel(line) {
            const match = line.match(/^(\s*)/);
            if (!match) {
                return 0;
            }
            // Count spaces and tabs (1 tab = 2 spaces for indent calculation)
            const spaces = match[1].replace(/\t/g, '  ');
            return Math.floor(spaces.length / 2); // 2 spaces = 1 indent level
        }

        function processListItem(indent, listType, itemText) {
            flushParagraph();

            // Determine target depth based on indentation
            const targetDepth = indent;

            // Close lists deeper than target
            while (listStack.length > targetDepth + 1) {
                const list = listStack.pop();
                if (list.items.length > 0) {
                    const html = '<' + list.type + '>' + list.items.join('') + '</' + list.type + '>';
                    if (listStack.length > 0) {
                        const parent = listStack[listStack.length - 1];
                        if (parent.items.length > 0) {
                            parent.items[parent.items.length - 1] = parent.items[parent.items.length - 1].replace('</li>', html + '</li>');
                        }
                    }
                }
            }

            // If we need a deeper list or different type, create new list
            if (listStack.length === 0 || listStack.length <= targetDepth) {
                listStack.push({ type: listType, items: [] });
            } else {
                // Check if current list type matches; if not, close and start new
                const currentList = listStack[listStack.length - 1];
                if (currentList.type !== listType) {
                    const list = listStack.pop();
                    if (list.items.length > 0) {
                        const html = '<' + list.type + '>' + list.items.join('') + '</' + list.type + '>';
                        if (listStack.length > 0) {
                            const parent = listStack[listStack.length - 1];
                            if (parent.items.length > 0) {
                                parent.items[parent.items.length - 1] = parent.items[parent.items.length - 1].replace('</li>', html + '</li>');
                            }
                        } else {
                            htmlParts.push(html);
                        }
                    }
                    listStack.push({ type: listType, items: [] });
                }
            }

            // Add item to current list
            const currentList = listStack[listStack.length - 1];
            currentList.items.push('<li>' + formatInline(itemText) + '</li>');
        }

        lines.forEach(function (line) {
            const trimmed = line.trim();

            if (!trimmed) {
                flushParagraph();
                flushAllLists();
                return;
            }

            if (codePlaceholderMap[trimmed]) {
                flushParagraph();
                flushAllLists();
                htmlParts.push(trimmed);
                return;
            }

            if (trimmed.indexOf('&gt;') === 0) {
                flushParagraph();
                flushAllLists();
                htmlParts.push('<blockquote><p>' + formatInline(trimmed.replace(/^&gt;\s*/, '')) + '</p></blockquote>');
                return;
            }

            const headingMatch = trimmed.match(/^(#{1,6})\s+(.*)$/);
            if (headingMatch) {
                flushParagraph();
                flushAllLists();
                const level = headingMatch[1].length;
                const headingText = formatInline(headingMatch[2]);
                htmlParts.push('<h' + level + '>' + headingText + '</h' + level + '>');
                return;
            }

            // Check for list items (with indentation support)
            const indent = getIndentLevel(line);
            const orderedMatch = trimmed.match(/^(\d+)\.\s+(.*)$/);
            if (orderedMatch) {
                processListItem(indent, 'ol', orderedMatch[2]);
                return;
            }

            const bulletMatch = trimmed.match(/^[-*+]\s+(.*)$/);
            if (bulletMatch) {
                processListItem(indent, 'ul', bulletMatch[1]);
                return;
            }

            // Not a list item
            if (listStack.length > 0) {
                flushAllLists();
            }

            paragraphLines.push(formatInline(line));
        });

        flushParagraph();
        flushAllLists();

        let html = htmlParts.join('');

        inlineCodes.forEach(function (item) {
            html = replaceAll(html, item.placeholder, '<code>' + escapeHtml(item.code) + '</code>');
        });

        // Restore images
        images.forEach(function (item) {
            const src = sanitizeUrl(item.url);
            const altText = escapeHtml(item.alt || '');
            let imgHtml = '<img src="' + src + '" alt="' + altText + '" class="wp-mcp-ai-chat__image" loading="lazy"';
            imgHtml += ' />';
            html = replaceAll(html, item.placeholder, imgHtml);
        });

        links.forEach(function (item) {
            const labelHtml = renderInlineLabel(item.label);
            const href = sanitizeUrl(item.url);
            let attributes = ' href="' + href + '"';
            if (/^https?:/i.test(href)) {
                attributes += ' target="_blank" rel="noopener noreferrer"';
            }
            html = replaceAll(html, item.placeholder, '<a' + attributes + '>' + labelHtml + '</a>');
        });

        codeBlocks.forEach(function (item) {
            const language = item.language.replace(/[^a-z0-9+#.-]/gi, '').toLowerCase();
            const className = language ? ' class="language-' + language + '"' : '';
            const codeHtml = '<pre class="wp-mcp-ai-chat__code-block"><code' + className + '>' + escapeHtml(item.code) + '</code></pre>';
            html = replaceAll(html, item.placeholder, codeHtml);
        });

        return html;
    }

    /**
     * Render inline label with inline code support.
     * Uses markdown service if available, otherwise uses internal implementation.
     * 
     * @param {string} text - Text to render
     * @return {string} Rendered HTML
     */
    function renderInlineLabel(text) {
        if (markdownService && markdownService.renderInlineLabel) {
            return markdownService.renderInlineLabel(text);
        }

        if (!text) {
            return '';
        }

        const inlineBase = 'WP_MCP_AI_INLINE_' + Math.random().toString(36).slice(2);
        const inlineCodes = [];
        let processed = String(text).replace(/\r\n|\r|\u2028|\u2029/g, ' ');

        processed = processed.replace(/`([^`]+)`/g, function (match, code) {
            const placeholder = '@@' + inlineBase + '_CODE_' + inlineCodes.length + '@@';
            inlineCodes.push({
                placeholder: placeholder,
                code: code,
            });
            return placeholder;
        });

        processed = escapeHtml(processed);
        processed = formatInline(processed);

        inlineCodes.forEach(function (item) {
            processed = replaceAll(processed, item.placeholder, '<code>' + escapeHtml(item.code) + '</code>');
        });

        return processed;
    }

    /**
     * Sanitize URL to prevent XSS.
     * Uses markdown service if available, otherwise uses internal implementation.
     * 
     * @param {string} url - URL to sanitize
     * @return {string} Sanitized URL or '#' if invalid
     */
    function sanitizeUrl(url) {
        if (markdownService && markdownService.sanitizeUrl) {
            return markdownService.sanitizeUrl(url);
        }

        if (!url) {
            return '#';
        }

        const trimmed = url.trim();
        if (!trimmed) {
            return '#';
        }

        try {
            const parsed = new URL(trimmed, window.location.origin);
            const protocol = parsed.protocol ? parsed.protocol.replace(/:$/, '').toLowerCase() : '';
            if (protocol && ['http', 'https', 'mailto', 'tel'].indexOf(protocol) === -1) {
                return '#';
            }
        } catch (error) {
            if (!/^https?:/i.test(trimmed) && !/^mailto:/i.test(trimmed) && !/^tel:/i.test(trimmed)) {
                return '#';
            }
        }

        return trimmed.replace(/"/g, '%22');
    }

    /**
     * Escape HTML to prevent XSS.
     * Uses markdown service if available, otherwise uses internal implementation.
     * 
     * @param {string} text - Text to escape
     * @return {string} Escaped text
     */
    /**
     * Escape HTML to prevent XSS.
     * Uses UI utilities service if available, falls back to markdown service, or uses internal implementation.
     * 
     * @param {string} text - Text to escape
     * @return {string} Escaped text
     */
    function escapeHtml(text) {
        if (uiUtilsService && uiUtilsService.escapeHtml) {
            return uiUtilsService.escapeHtml(text);
        }

        if (markdownService && markdownService.escapeHtml) {
            return markdownService.escapeHtml(text);
        }

        return String(text).replace(/[&<>"']/g, function (character) {
            switch (character) {
                case '&':
                    return '&amp;';
                case '<':
                    return '&lt;';
                case '>':
                    return '&gt;';
                case '"':
                    return '&quot;';
                case '\'':
                    return '&#39;';
                default:
                    return character;
            }
        });
    }

    /**
     * Format inline markdown (bold, italic, strikethrough).
     * Uses markdown service if available, otherwise uses internal implementation.
     * 
     * @param {string} text - Text to format
     * @return {string} Formatted HTML
     */
    function formatInline(text) {
        if (markdownService && markdownService.formatInline) {
            return markdownService.formatInline(text);
        }

        let result = text;
        result = result.replace(/~~(?=\S)(.+?)(?<=\S)~~/g, '<del>$1</del>');
        result = result.replace(/\*\*(?=\S)(.+?)(?<=\S)\*\*/g, '<strong>$1</strong>');
        result = result.replace(/\*(?=\S)(.+?)(?<=\S)\*/g, '<em>$1</em>');
        return result;
    }

    function replaceAll(text, search, replacement) {
        return text.split(search).join(replacement);
    }

    /**
     * Get video MIME type based on URL or file extension.
     * 
     * @param {string} url - Video URL
     * @return {string} MIME type
     */
    function getVideoMimeType(url) {
        if (!url || typeof url !== 'string') {
            return 'video/mp4'; // Default fallback
        }

        const lowerUrl = url.toLowerCase();

        // Check for data URL with explicit MIME type
        if (lowerUrl.indexOf('data:video/') === 0) {
            const mimeMatch = url.match(/^data:(video\/[^;,]+)/);
            if (mimeMatch && mimeMatch[1]) {
                return mimeMatch[1];
            }
        }

        // Determine MIME type by file extension
        if (lowerUrl.indexOf('.webm') !== -1) {
            return 'video/webm';
        } else if (lowerUrl.indexOf('.ogg') !== -1 || lowerUrl.indexOf('.ogv') !== -1) {
            return 'video/ogg';
        } else if (lowerUrl.indexOf('.mov') !== -1) {
            return 'video/quicktime';
        } else if (lowerUrl.indexOf('.avi') !== -1) {
            return 'video/x-msvideo';
        } else if (lowerUrl.indexOf('.mkv') !== -1) {
            return 'video/x-matroska';
        }

        // Default to MP4
        return 'video/mp4';
    }

    /**
     * Check if an attachment is a video based on URL or MIME type.
     * Detects video files from veo generation and other video sources.
     * 
     * @param {Object} attachment - Attachment object with url and optional meta properties
     * @return {boolean} True if attachment is a video
     */
    function isVideoAttachment(attachment) {
        if (!attachment || typeof attachment !== 'object') {
            return false;
        }

        const url = attachment.url || '';
        const meta = attachment.meta || '';
        const label = attachment.label || '';

        // Check for video file extensions in URL
        if (url && typeof url === 'string') {
            const lowerUrl = url.toLowerCase();
            
            // Extract the path part of the URL (before query string or hash)
            const urlPath = lowerUrl.split('?')[0].split('#')[0];
            
            // Check for common video file extensions at the end of the path
            const videoExtensions = ['.mp4', '.webm', '.ogg', '.ogv', '.mov', '.avi', '.mkv'];
            for (let i = 0; i < videoExtensions.length; i++) {
                const ext = videoExtensions[i];
                if (urlPath.lastIndexOf(ext) === urlPath.length - ext.length) {
                    return true;
                }
            }
            
            // Check for data URLs with video MIME type
            if (lowerUrl.indexOf('data:video/') === 0) {
                return true;
            }
            
            // Check for 'video' in path segments (more specific than global match)
            // Only match if it's part of a path segment like /video/ or /videos/
            if (lowerUrl.indexOf('/video/') !== -1 || lowerUrl.indexOf('/videos/') !== -1) {
                return true;
            }
        }

        // Check metadata for video indicators
        if (meta && typeof meta === 'string') {
            const lowerMeta = meta.toLowerCase();
            // Only check for explicit video type mentions in metadata
            // Avoid false positives by checking for word boundaries where possible
            if (lowerMeta.indexOf(' video') !== -1 || // " video" with space
                lowerMeta.indexOf('video ') !== -1 || // "video " with space
                lowerMeta === 'video' || // exact match
                lowerMeta.indexOf('.mp4') !== -1 ||
                lowerMeta.indexOf('.webm') !== -1 ||
                lowerMeta.indexOf('.ogg') !== -1 ||
                lowerMeta.indexOf('.mov') !== -1) {
                return true;
            }
        }

        // Check label for video indicators (e.g., "Veo Generated Video")
        if (label && typeof label === 'string') {
            const lowerLabel = label.toLowerCase();
            // More specific label checks for Veo or explicit "video" mentions
            if (lowerLabel.indexOf('veo') !== -1 || 
                lowerLabel.indexOf('video') === 0 || // "video" at start
                lowerLabel.indexOf(' video') !== -1) { // " video" anywhere
                return true;
            }
        }

        return false;
    }

    function normaliseContent(content) {
        if (typeof content === 'string') {
            return content;
        }

        if (Array.isArray(content)) {
            return content
                .map(renderContentPiece)
                .filter(function (value) {
                    return value && value.trim();
                })
                .join('\n\n')
                .trim();
        }

        if (content && typeof content === 'object') {
            return renderContentPiece(content);
        }

        return '';
    }

    /**
     * Extract text content from various AI provider response formats.
     * Handles string content, object content with text/content properties,
     * and arrays of content items.
     * 
     * This is used during SSE response parsing to normalize different
     * provider formats (OpenAI, Ollama, etc.) into plain text.
     * 
     * Note: This differs from extractNestedText() which:
     * - Returns an array (not string)
     * - Recursively extracts from many properties (value, message, reason, etc.)
     * - Is used for complex content parsing, not AI responses
     * 
     * @param {*} content - Content to extract text from (string, object, or array)
     * @return {string} Extracted text or empty string
     */
    function extractTextFromContent(content) {
        if (!content) {
            return '';
        }
        
        // If already a string, return it
        if (typeof content === 'string') {
            return content;
        }
        
        // Handle array of content items (some providers return this)
        if (Array.isArray(content)) {
            let text = '';
            for (let i = 0; i < content.length; i++) {
                const item = content[i];
                if (typeof item === 'string') {
                    text += item;
                } else if (item && typeof item === 'object') {
                    // Handle nested object in array
                    if (typeof item.text === 'string') {
                        text += item.text;
                    } else if (typeof item.content === 'string') {
                        text += item.content;
                    }
                }
            }
            return text;
        }
        
        // Handle object with text property (common format)
        if (typeof content === 'object') {
            if (typeof content.text === 'string') {
                return content.text;
            }
            // Some formats nest text deeper
            if (typeof content.content === 'string') {
                return content.content;
            }
        }
        
        return '';
    }

    function extractNestedText(value, depth) {
        if (depth > 5) {
            return [];
        }

        if (typeof value === 'string' || typeof value === 'number') {
            const normalised = String(value).trim();
            return normalised ? [normalised] : [];
        }

        if (!value) {
            return [];
        }

        if (Array.isArray(value)) {
            return value.reduce(function (parts, item) {
                return parts.concat(extractNestedText(item, depth + 1));
            }, []);
        }

        if (typeof value === 'object') {
            let segments = [];

            if (typeof value.text === 'string') {
                segments.push(value.text);
            } else if (value.text && typeof value.text.value === 'string') {
                segments.push(value.text.value);
            }

            if (typeof value.value === 'string') {
                segments.push(value.value);
            }

            if (typeof value.message === 'string') {
                segments.push(value.message);
            }

            if (typeof value.reason === 'string') {
                segments.push(value.reason);
            }

            if (typeof value.explanation === 'string') {
                segments.push(value.explanation);
            }

            if (typeof value.summary === 'string') {
                segments.push(value.summary);
            }

            if (typeof value.content === 'string') {
                segments.push(value.content);
            }

            const nestedKeys = ['summary', 'reasoning', 'content', 'steps', 'output', 'parts', 'messages'];
            nestedKeys.forEach(function (key) {
                if (value[key] && value[key] !== value) {
                    segments = segments.concat(extractNestedText(value[key], depth + 1));
                }
            });

            return segments;
        }

        return [];
    }

    function dedupeTextParts(parts) {
        const seen = Object.create(null);

        return parts
            .map(function (part) {
                return typeof part === 'string' ? part.trim() : '';
            })
            .filter(function (part) {
                if (!part) {
                    return false;
                }

                if (seen[part]) {
                    return false;
                }

                seen[part] = true;
                return true;
            });
    }

    function renderReasoningSegment(piece) {
        if (!piece || typeof piece !== 'object') {
            return '';
        }

        let fragments = [];

        fragments = fragments.concat(extractNestedText(piece.summary, 0));
        fragments = fragments.concat(extractNestedText(piece.reasoning, 0));
        fragments = fragments.concat(extractNestedText(piece.text, 0));
        fragments = fragments.concat(extractNestedText(piece.output, 0));
        fragments = fragments.concat(extractNestedText(piece.content, 0));

        const unique = dedupeTextParts(fragments);

        if (!unique.length) {
            return '';
        }

        const heading = getString('reasoningLabel', 'Reasoning');
        return heading + ':\n\n' + unique.join('\n\n');
    }

    function renderFunctionCallSegment(piece) {
        if (!piece || typeof piece !== 'object') {
            return '';
        }

        const parts = [];
        const name = typeof piece.name === 'string' ? piece.name.trim() : '';
        const status = typeof piece.status === 'string' ? piece.status.trim() : '';
        const callId = typeof piece.call_id === 'string' ? piece.call_id.trim() : '';
        const identifier = typeof piece.id === 'string' ? piece.id.trim() : '';

        if (name) {
            parts.push(formatString(getString('functionCallTitle', 'Function call: %s'), name));
        } else {
            parts.push(getString('functionCallFallback', 'Function call'));
        }

        if (status) {
            parts.push(formatString(getString('functionCallStatus', 'Status: %s'), status));
        }

        if (callId) {
            parts.push(formatString(getString('functionCallId', 'Call ID: %s'), callId));
        } else if (identifier) {
            parts.push(formatString(getString('functionCallId', 'Call ID: %s'), identifier));
        }

        const rawArguments = typeof piece.arguments !== 'undefined' ? piece.arguments : null;
        let argumentText = '';
        let parsedArguments = null;

        if (typeof rawArguments === 'string') {
            const trimmed = rawArguments.trim();

            if (trimmed) {
                try {
                    parsedArguments = JSON.parse(trimmed);
                } catch (error) {
                    parsedArguments = null;
                }

                argumentText = parsedArguments ? JSON.stringify(parsedArguments, null, 2) : trimmed;
            }
        } else if (rawArguments && typeof rawArguments === 'object') {
            try {
                argumentText = JSON.stringify(rawArguments, null, 2);
                parsedArguments = rawArguments;
            } catch (error) {
                argumentText = String(rawArguments);
            }
        }

        if (argumentText) {
            const argumentsLabel = getString('functionCallArgumentsLabel', 'Arguments:');

            if (parsedArguments) {
                parts.push(argumentsLabel + '\n```json\n' + argumentText + '\n```');
            } else {
                parts.push(argumentsLabel + ' ' + argumentText);
            }
        }

        return parts.join('\n\n');
    }

    function renderContentPiece(piece) {
        if (typeof piece === 'string') {
            return piece;
        }

        if (!piece || typeof piece !== 'object') {
            return '';
        }

        if (typeof piece.text === 'string') {
            return piece.text;
        }

        if (piece.text && typeof piece.text.value === 'string') {
            return piece.text.value;
        }

        if (typeof piece.content === 'string') {
            return piece.content;
        }

        if (Array.isArray(piece.content)) {
            return piece.content
                .map(renderContentPiece)
                .filter(function (value) {
                    return value && value.trim();
                })
                .join('\n\n');
        }

        if (piece.value && typeof piece.value === 'string') {
            return piece.value;
        }

        const type = typeof piece.type === 'string' ? piece.type : '';

        if (type === 'reasoning') {
            return renderReasoningSegment(piece);
        }

        if (type === 'function_call') {
            return renderFunctionCallSegment(piece);
        }

        if (type === 'image_file') {
            let label = '';

            if (piece.caption && typeof piece.caption === 'string') {
                label = piece.caption;
            } else if (typeof piece.file_id === 'string' && piece.file_id) {
                label = piece.file_id;
            } else if (piece.image_file && typeof piece.image_file === 'object') {
                if (typeof piece.image_file.display_name === 'string') {
                    label = piece.image_file.display_name;
                } else if (typeof piece.image_file.file_id === 'string') {
                    label = piece.image_file.file_id;
                }
            }

            if (!label) {
                label = 'Image';
            }

            return '[' + label + ']';
        }

        if (type === 'image_url' || type === 'input_image') {
            if (piece.image_url && typeof piece.image_url.url === 'string') {
                return '[Image: ' + piece.image_url.url + ']';
            }

            if (typeof piece.url === 'string' && piece.url) {
                return '[Image: ' + piece.url + ']';
            }

            if (typeof piece.caption === 'string' && piece.caption) {
                return '[' + piece.caption + ']';
            }

            return '[Image]';
        }

        if (type === 'tool_result') {
            const parts = [];

            if (piece.output) {
                if (typeof piece.output === 'string') {
                    parts.push(piece.output);
                } else if (Array.isArray(piece.output)) {
                    parts.push(
                        piece.output
                            .map(renderContentPiece)
                            .filter(function (value) {
                                return value && value.trim();
                            })
                            .join('\n\n')
                    );
                } else if (typeof piece.output === 'object') {
                    try {
                        parts.push(JSON.stringify(piece.output));
                    } catch (error) {
                        parts.push('[Tool Result]');
                    }
                }
            }

            if (piece.content) {
                parts.push(normaliseContent(piece.content));
            }

            const toolText = parts
                .filter(function (value) {
                    return value && value.trim();
                })
                .join('\n\n');

            return toolText || '[Tool Result]';
        }

        try {
            return JSON.stringify(piece);
        } catch (error) {
            return '[' + (type || 'content') + ']';
        }
    }

    function formatString(template) {
        if (!template) {
            return '';
        }

        // Get all arguments after the template
        const values = Array.prototype.slice.call(arguments, 1);
        
        // Replace each %s with the corresponding value
        let result = template;
        for (let i = 0; i < values.length; i++) {
            result = result.replace('%s', values[i] !== undefined ? values[i] : '');
        }
        
        return result;
    }

    /**
     * Dedicated function for saving chat posts with enhanced error handling.
     * 
     * @param {Object} state - Chat state object
     * @param {Object} saveData - Data to save (title, content, post_type, etc.)
     * @param {Object} options - Optional settings (retry, timeout, etc.)
     * @return {Promise} Promise that resolves with save result
     */
    function saveChatPost(state, saveData, options) {
        if (!state || !state.config || !state.config.toolsEndpoint) {
            return Promise.reject(new Error('Tools endpoint not configured'));
        }

        if (!saveData || typeof saveData !== 'object') {
            return Promise.reject(new Error('Invalid save data'));
        }

        if (!saveData.content && !saveData.post_id) {
            return Promise.reject(new Error('Save data must include content or post_id'));
        }

        // Default options
        const opts = options || {};
        const maxRetries = opts.maxRetries || 1;
        const retryDelay = opts.retryDelay || 1000;
        const timeout = opts.timeout || 30000;

        // Build the payload for save_post tool
        const payload = {
            assistant_id: state.config.assistantId,
            tool: 'save_post',
            arguments: {
                title: saveData.title || '',
                content: saveData.content || '',
                post_type: saveData.post_type || 'post',
                status: saveData.status || 'draft',
            },
        };

        // Add optional fields
        if (saveData.post_id) {
            const postId = parseInt(saveData.post_id, 10);
            if (isNaN(postId) || postId <= 0) {
                return Promise.reject(new Error('Invalid post_id provided'));
            }
            payload.arguments.post_id = postId;
        }
        if (saveData.excerpt) {
            payload.arguments.excerpt = saveData.excerpt;
        }
        if (saveData.slug) {
            payload.arguments.slug = saveData.slug;
        }

        // Add session key if available
        if (state.config.sessionKey) {
            payload.session_key = state.config.sessionKey;
        }

        /**
         * Internal function to attempt the save request.
         * 
         * @param {number} attempt - Current attempt number
         * @return {Promise} Promise that resolves with response data
         */
        function attemptSave(attempt) {
            const controller = new AbortController();
            const timeoutId = setTimeout(function() {
                controller.abort();
            }, timeout);

            return fetch(state.config.toolsEndpoint, {
                method: 'POST',
                headers: buildJsonHeaders(state),
                credentials: 'same-origin',
                body: JSON.stringify(payload),
                signal: controller.signal,
            })
                .then(function(response) {
                    clearTimeout(timeoutId);
                    
                    // Clone response for error handling
                    const responseClone = response.clone();
                    
                    return response.json()
                        .catch(function(parseError) {
                            // If JSON parsing fails, try to get text for debugging
                            return responseClone.text().then(function(text) {
                                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                            }).catch(function() {
                                throw parseError;
                            });
                        })
                        .then(function(data) {
                            if (!response.ok) {
                                // Extract error message from response - prefer data.message, fallback to data.error
                                const defaultMsg = 'Save failed with status ' + response.status;
                                const errorMsg = data && data.message 
                                    ? data.message 
                                    : extractErrorMessage(data && data.error, defaultMsg);
                                throw new Error(errorMsg);
                            }
                            
                            // Validate response data structure
                            if (!data || typeof data !== 'object') {
                                throw new Error('Invalid response format from save endpoint');
                            }
                            
                            return data;
                        });
                })
                .catch(function(error) {
                    clearTimeout(timeoutId);
                    
                    // Check if we should retry
                    if (attempt < maxRetries && error.name !== 'AbortError') {
                        // Wait before retrying
                        return new Promise(function(resolve) {
                            setTimeout(function() {
                                resolve(attemptSave(attempt + 1));
                            }, retryDelay);
                        });
                    }
                    
                    // Transform error for better user feedback
                    if (error.name === 'AbortError') {
                        throw new Error('Save request timed out after ' + (timeout / 1000) + ' seconds');
                    }
                    
                    throw error;
                });
        }

        return attemptSave(0);
    }

    /**
     * Helper function to save post from chat message.
     * This wraps saveChatPost with user-friendly feedback.
     * 
     * @param {Object} state - Chat state object
     * @param {Object} saveData - Data to save
     * @return {Promise} Promise that resolves with formatted result
     */
    function savePostFromChat(state, saveData) {
        if (!state || !state.container) {
            return Promise.reject(new Error('Invalid chat state'));
        }

        // Show saving status
        setStatus(state.container, getString('savingPost', 'Saving post...'));

        return saveChatPost(state, saveData, {
            maxRetries: 2,
            retryDelay: 1000,
            timeout: 30000,
        })
            .then(function(result) {
                clearStatus(state.container);
                
                // Format success message
                let message = 'Post saved successfully';
                if (result && result.post_id) {
                    message += ' (ID: ' + result.post_id + ')';
                }
                if (result && result.edit_url) {
                    message += '. <a href="' + escapeHtml(result.edit_url) + '" target="_blank">Edit post</a>';
                }
                
                // Show success message in chat
                if (state.messagesEl) {
                    appendMessage(state.messagesEl, 'system', {
                        text: message,
                        attachments: [],
                    }, false, { state: state });
                }
                
                return result;
            })
            .catch(function(error) {
                clearStatus(state.container);
                
                // Show error message
                const errorMessage = error && error.message ? error.message : 'Failed to save post';
                if (state.messagesEl) {
                    appendMessage(state.messagesEl, 'system', {
                        text: 'Error: ' + escapeHtml(errorMessage),
                        attachments: [],
                    }, false, { state: state });
                }
                
                throw error;
            });
    }

    /**
     * Escape HTML to prevent XSS in dynamic content.
     * 
     * @param {string} text - Text to escape
     * @return {string} Escaped text
     */
    function escapeHtml(text) {
        if (!text) {
            return '';
        }
        
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Initialize cron status display for a chat container
     * 
     * @param {HTMLElement} container - Chat container element
     * @param {Object} config - Chat instance configuration
     */
    function initializeCronStatus(container, config) {
        if (!container || !config) {
            return;
        }

        const cronStatusEl = container.querySelector('.wp-mcp-ai-chat__cron-status');
        if (!cronStatusEl) {
            return;
        }

        // Check if cron status service is available
        if (typeof window.wpMcpAiCronStatus === 'undefined') {
            return;
        }

        const instanceId = container.getAttribute('id');
        if (!instanceId) {
            return;
        }

        // Build cron status endpoint using global restUrl to avoid cross-domain issues
        // (config.messagesEndpoint might point to an external URL)
        const restUrl = (window.wpMcpAiChat && window.wpMcpAiChat.restUrl) || '';
        const cronStatusEndpoint = restUrl ? restUrl + '/cron-status' : '';
        const nonce = config.restNonce || (window.wpMcpAiChat && window.wpMcpAiChat.nonce) || '';
        const guestToken = config.guestToken || '';

        // Don't start polling if endpoint is not available
        if (!cronStatusEndpoint) {
            return;
        }

        // Update cron status display
        function updateCronStatusDisplay(data) {
            if (!data || !data.counts) {
                return;
            }

            const counts = data.counts;
            const total = counts.total || 0;

            // Hide if no jobs
            if (total === 0) {
                cronStatusEl.setAttribute('hidden', '');
                return;
            }

            // Show and update counts
            cronStatusEl.removeAttribute('hidden');

            const pendingEl = cronStatusEl.querySelector('.wp-mcp-ai-chat__cron-status-pending .wp-mcp-ai-chat__cron-status-count');
            const completedEl = cronStatusEl.querySelector('.wp-mcp-ai-chat__cron-status-completed .wp-mcp-ai-chat__cron-status-count');

            if (pendingEl) {
                pendingEl.textContent = counts.pending || 0;
                pendingEl.parentElement.className = 'wp-mcp-ai-chat__cron-status-pending';
                if (counts.pending > 0) {
                    pendingEl.parentElement.className += ' wp-mcp-ai-chat__cron-status-pending--active';
                }
            }

            if (completedEl) {
                completedEl.textContent = counts.completed || 0;
                completedEl.parentElement.className = 'wp-mcp-ai-chat__cron-status-completed';
                if (counts.completed > 0) {
                    completedEl.parentElement.className += ' wp-mcp-ai-chat__cron-status-completed--done';
                }
            }
        }

        // Start monitoring cron status with SSE-first approach (30s fallback to REST polling)
        const assistantId = config.assistantId || null;
        window.wpMcpAiCronStatus.startMonitoring(instanceId, cronStatusEndpoint, nonce, updateCronStatusDisplay, assistantId, guestToken);

        // Stop monitoring when chat is destroyed or hidden
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'hidden') {
                    if (container.hasAttribute('hidden')) {
                        window.wpMcpAiCronStatus.stopMonitoring(instanceId);
                    }
                }
            });
        });

        observer.observe(container, { attributes: true });
    }

    /**
     * Enhanced init function to include cron status
     */
    function initWithCronStatus() {
        // Call original init
        init();

        // Initialize cron status for all chat containers after a brief delay
        // Use requestIdleCallback to avoid blocking main thread
        setTimeout(function() {
            domUpdateBatcher.schedule(function() {
                const containers = document.querySelectorAll('[data-wp-mcp-ai-chat]');
                Array.prototype.forEach.call(containers, function(container) {
                    const instanceId = container.getAttribute('id');
                    const config = window.wpMcpAiChatInstances && window.wpMcpAiChatInstances[instanceId];
                    if (config) {
                        initializeCronStatus(container, config);
                    }
                });
            });
        }, 500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWithCronStatus);
    } else {
        initWithCronStatus();
    }
})();
