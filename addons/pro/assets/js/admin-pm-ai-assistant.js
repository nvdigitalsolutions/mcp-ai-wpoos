/**
 * Project Management AI Assistant Metabox - Inline Chat Implementation
 *
 * Renders the chat client inline within the metabox based on the selected assistant.
 * Uses direct HTML rendering for reliable inline display.
 *
 * @package WP_MCP_AI
 */

(function ($) {
'use strict';

console.log('[PM AI Assistant] Script loaded:', new Date().toISOString());

/**
 * Initialize the AI assistant metabox.
 */
function initPmAiAssistant() {
console.log('[PM AI Assistant] Initializing...');

const $selector = $('#wp-mcp-ai-pm-assistant-select');
const $inlineContainer = $('#wp-mcp-ai-pm-assistant-inline-container');
const $chatContainer = $('#wp-mcp-ai-pm-assistant-chat-container');
const $inlineTitle = $('#wp-mcp-ai-pm-assistant-inline-title');

// Verify all required elements exist
if (!$selector.length || !$inlineContainer.length || !$chatContainer.length) {
console.error('[PM AI Assistant] Required elements not found');
return;
}

console.log('[PM AI Assistant] ✓ All elements found, attaching event handlers');

// Handle assistant selection
$selector.on('change', function () {
const assistantId = $(this).val();
const $selectedOption = $(this).find('option:selected');
const assistantTitle = $selectedOption.data('title') || $selectedOption.text();

console.log('[PM AI Assistant] Assistant selected:', assistantId, assistantTitle);

if (!assistantId) {
// Hide inline container if no assistant selected
$inlineContainer.hide();
$chatContainer.empty();
return;
}

// Update title
$inlineTitle.text(assistantTitle);

// Show inline container
$inlineContainer.show();

// Render chat interface inline
renderChatInline(assistantId, assistantTitle);
});

console.log('[PM AI Assistant] ✓ Initialization complete');
}

/**
 * Render the chat interface inline.
 *
 * @param {number} assistantId     Assistant post ID.
 * @param {string} assistantTitle  Assistant display name.
 */
function renderChatInline(assistantId, assistantTitle) {
const $chatContainer = $('#wp-mcp-ai-pm-assistant-chat-container');

console.log('[PM AI Assistant] Rendering chat inline for assistant:', assistantId);

// Clear previous chat
$chatContainer.empty();

// Show loading indicator
$chatContainer.html('<div class="wp-mcp-ai-pm-assistant-loading"><p>' + 
'<span class="spinner is-active" style="float:none; margin: 0 10px 0 0;"></span>' +
'Loading chat interface...</p></div>');

// Build the chat HTML inline (same structure as shortcode)
const instanceId = 'wp-mcp-ai-pm-chat-' + assistantId + '-' + Date.now();
const chatHtml = buildChatHTML(instanceId, assistantTitle);

// Inject HTML into container
$chatContainer.html(chatHtml);

// Create configuration for this chat instance
if (!window.wpMcpAiChatInstances) {
window.wpMcpAiChatInstances = {};
}

// Build configuration (inherit from global wpMcpAiChat if available)
const baseConfig = window.wpMcpAiChat || {};

// Warn if base configuration is missing
if (!window.wpMcpAiChat) {
console.warn('[PM AI Assistant] wpMcpAiChat global not found, using defaults');
console.warn('[PM AI Assistant] Available globals:', Object.keys(window).filter(function(k) { return k.toLowerCase().includes('mcp'); }));
}

// Warn if critical fields are missing
if (!baseConfig.nonce) {
console.error('[PM AI Assistant] REST nonce is missing! Authentication will fail.');
console.error('[PM AI Assistant] wpMcpAiChat contents:', baseConfig);
}

const restUrl = baseConfig.restUrl || '/wp-json/mcp-ai/v1';

console.log('[PM AI Assistant] Base configuration:', {
hasGlobal: !!window.wpMcpAiChat,
hasNonce: !!baseConfig.nonce,
hasRestUrl: !!baseConfig.restUrl,
nonce: baseConfig.nonce ? baseConfig.nonce.substring(0, 10) + '...' : 'MISSING',
restUrl: restUrl
});

window.wpMcpAiChatInstances[instanceId] = {
id: instanceId,
assistantId: assistantId,
userId: baseConfig.currentUserId || 0,
restUrl: restUrl,
messagesEndpoint: restUrl + '/chat-client',
toolsEndpoint: restUrl + '/tools',
filesEndpoint: baseConfig.filesEndpoint || (restUrl + '/files/'),
transcriptsEndpoint: baseConfig.transcriptsEndpoint || (restUrl + '/chat-transcripts'),
crawl4aiTaskEndpoint: restUrl + '/crawl4ai/task/',
crawl4aiDefaultPollMs: 5000,
sessionKey: generateSessionKey(),
enableStreaming: true,
canUploadAttachments: true,
saveTranscript: false, // Don't save metabox chats
allowSensitiveTools: true, // Admin users can access all tools
requiredCapability: 'edit_posts',
allowGuests: false,
toolShortcuts: [],
fileAccept: baseConfig.fileAccept || '',
allowedImageMimes: baseConfig.allowedImageMimes || [],
allowedFileMimes: baseConfig.allowedFileMimes || [],
allowedExtensions: baseConfig.allowedExtensions || [],
restNonce: baseConfig.nonce || '',
historyPerPage: 20,
asyncToolTimeout: baseConfig.asyncToolTimeout || 300000
};

console.log('[PM AI Assistant] ✓ Configuration created for instance:', instanceId);
console.log('[PM AI Assistant] Configuration:', {
assistantId: assistantId,
hasNonce: !!baseConfig.nonce,
hasRestUrl: !!baseConfig.restUrl,
hasUserId: !!baseConfig.currentUserId
});

// Initialize the chat instance
setTimeout(function() {
initializeChatInstance(instanceId);
}, 100);
}

/**
 * Build the chat interface HTML structure.
 *
 * @param {string} instanceId      Unique instance identifier.
 * @param {string} assistantTitle  Assistant display name.
 * @return {string} HTML string for chat interface.
 */
function buildChatHTML(instanceId, assistantTitle) {
// Using template literals would be cleaner but not supported in IE11
// So we use string concatenation for maximum compatibility
let html = '';
html += '<div class="wp-mcp-ai-chat wp-mcp-ai-chat--template-compact" id="' + esc(instanceId) + '" data-wp-mcp-ai-chat data-template="compact">';
html += '<div class="wp-mcp-ai-chat__assistant">';
html += '<label class="wp-mcp-ai-chat__label" for="' + esc(instanceId + '-input') + '">' + esc(assistantTitle) + '</label>';
html += '</div>';
html += '<div class="wp-mcp-ai-chat__transcript-controls">';
html += '<button type="button" class="wp-mcp-ai-chat__transcript-toggle" aria-expanded="false" aria-label="Expand conversation">';
html += '<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
html += '<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z"></path>';
html += '</svg>';
html += '<span class="screen-reader-text">Expand conversation</span>';
html += '</button>';
html += '</div>';
html += '<div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>';
html += '<form class="wp-mcp-ai-chat__form" data-instance-id="' + esc(instanceId) + '">';
html += '<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden><span class="wp-mcp-ai-chat__status-text"></span></div>';
html += '<div class="wp-mcp-ai-chat__tool-shortcuts-wrapper" hidden>';
html += '<button type="button" class="wp-mcp-ai-chat__tool-shortcuts-toggle wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed" aria-expanded="false">';
html += '<span class="wp-mcp-ai-chat__tool-shortcuts-toggle-text">Quick Tasks</span>';
html += '<svg class="wp-mcp-ai-chat__tool-shortcuts-toggle-icon" viewBox="0 0 24 24"><path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z"></path></svg>';
html += '</button>';
html += '<div id="' + esc(instanceId + '-tool-shortcuts') + '" class="wp-mcp-ai-chat__tool-shortcuts wp-mcp-ai-chat__tool-shortcuts--collapsed" role="group" hidden></div>';
html += '</div>';
html += '<textarea id="' + esc(instanceId + '-input') + '" class="wp-mcp-ai-chat__input" rows="4" placeholder="Ask something…" required></textarea>';
html += '<div class="wp-mcp-ai-chat__attachments" hidden>';
html += '<div class="wp-mcp-ai-chat__attachments-header">Attachments</div>';
html += '<ul class="wp-mcp-ai-chat__attachments-list"></ul>';
html += '</div>';
html += '<div class="wp-mcp-ai-chat__actions">';
html += '<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden>';
html += '<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden>';
html += '<button type="button" class="wp-mcp-ai-chat__voice-chat" aria-label="Voice chat"><svg class="wp-mcp-ai-chat__voice-chat-icon" viewBox="0 0 24 24"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path><circle cx="12" cy="12" r="1.5" fill="currentColor"></circle></svg><span class="screen-reader-text">Voice chat</span></button>';
html += '<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="Transcribe audio"><svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path><path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path></svg><span class="screen-reader-text">Transcribe audio</span></button>';
html += '<button type="button" class="wp-mcp-ai-chat__attach">Attach file</button>';
html += '<button type="button" class="wp-mcp-ai-chat__build" hidden>Build</button>';
html += '<button type="submit" class="wp-mcp-ai-chat__submit">Send</button>';
html += '</div>';
html += '</form>';
html += '<div class="wp-mcp-ai-chat__controls">';
html += '<div class="wp-mcp-ai-chat__quota-monitor" role="status" aria-live="polite" aria-atomic="true"></div>';
html += '<div class="wp-mcp-ai-chat__cron-status" role="status" aria-live="polite" aria-atomic="true" hidden>';
html += '<span class="wp-mcp-ai-chat__cron-status-label">Jobs:</span>';
html += '<span class="wp-mcp-ai-chat__cron-status-pending" title="Pending jobs"><span class="wp-mcp-ai-chat__cron-status-count">0</span></span>';
html += '<span class="wp-mcp-ai-chat__cron-status-completed" title="Completed jobs"><span class="wp-mcp-ai-chat__cron-status-count">0</span></span>';
html += '</div>';
html += '<div class="wp-mcp-ai-chat__control-buttons">';
html += '<button type="button" class="wp-mcp-ai-chat__save" aria-label="Save conversation" title="Save conversation"><svg class="wp-mcp-ai-chat__save-icon" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM5 5v14h14V9h-4V5H5z"></path><path d="M7 5h6v3H7V5zm5 9a2 2 0 11-4 0 2 2 0 014 0z"></path></svg><span class="screen-reader-text">Save conversation</span></button>';
html += '<button type="button" class="wp-mcp-ai-chat__export" aria-label="Export conversation" title="Export conversation"><svg class="wp-mcp-ai-chat__export-icon" viewBox="0 0 24 24"><path d="M12 16a1 1 0 01-1-1V5a1 1 0 012 0v10a1 1 0 01-1 1z"></path><path d="M12 16a1 1 0 01-.707-.293l-4-4a1 1 0 011.414-1.414L12 13.586l3.293-3.293a1 1 0 011.414 1.414l-4 4A1 1 0 0112 16z"></path><path d="M5 19a1 1 0 010-2h14a1 1 0 010 2H5z"></path></svg><span class="screen-reader-text">Export conversation</span></button>';
html += '<button type="button" class="wp-mcp-ai-chat__history-toggle" aria-expanded="false" aria-label="Show previous conversations"><svg class="wp-mcp-ai-chat__history-toggle-icon" viewBox="0 0 24 24"><path d="M6 5.5a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h7a1 1 0 010 2H7a1 1 0 01-1-1z"></path><path d="M5 9a1 1 0 012 0 1 1 0 11-2 0zm0 6a1 1 0 012 0 1 1 0 11-2 0zm0-12a1 1 0 012 0 1 1 0 11-2 0z"></path></svg><span class="screen-reader-text">Show previous conversations</span></button>';
html += '<button type="button" class="wp-mcp-ai-chat__new-chat" aria-label="Start new conversation"><svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24"><path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z"></path></svg><span class="screen-reader-text">Start new conversation</span></button>';
html += '</div>';
html += '</div>';
html += '<section class="wp-mcp-ai-chat__history" id="' + esc(instanceId + '-history') + '" hidden aria-label="Previous conversations">';
html += '<div class="wp-mcp-ai-chat__history-header">';
html += '<button type="button" class="wp-mcp-ai-chat__history-refresh" aria-label="Refresh conversation history" title="Refresh conversation history"><svg class="wp-mcp-ai-chat__history-refresh-icon" viewBox="0 0 24 24"><path d="M4 12a8 8 0 018-8V3c-1.105 0-2.165.21-3.13.594l1.42 1.42A6.004 6.004 0 0112 5a7 7 0 110 14 7 7 0 01-6.93-6H3a8 8 0 008 8 8 8 0 000-16V3l-3 3 3 3v-1.078z"></path></svg><span class="screen-reader-text">Refresh conversation history</span></button>';
html += '</div>';
html += '<div class="wp-mcp-ai-chat__history-status" role="status" aria-live="polite" hidden></div>';
html += '<ul class="wp-mcp-ai-chat__history-list" role="list"></ul>';
html += '<button type="button" class="wp-mcp-ai-chat__history-load-more" hidden>Load More</button>';
html += '</section>';
html += '</div>';
return html;
}

/**
 * Escape HTML to prevent XSS.
 *
 * @param {string} text Text to escape.
 * @return {string} Escaped text.
 */
function esc(text) {
const div = document.createElement('div');
div.textContent = text;
return div.innerHTML;
}

/**
 * Generate a unique session key.
 *
 * @return {string} Session key.
 */
function generateSessionKey() {
return 'pm-' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
}

/**
 * Initialize a chat instance.
 *
 * @param {string} instanceId Instance identifier.
 * @param {number} retryCount Current retry attempt (for internal use).
 */
function initializeChatInstance(instanceId, retryCount) {
console.log('[PM AI Assistant] Initializing chat instance:', instanceId);

retryCount = retryCount || 0;
const maxRetries = 10;
const retryDelay = 100;

const container = document.getElementById(instanceId);

if (!container) {
console.error('[PM AI Assistant] Container not found:', instanceId);
return;
}

// Check if chat initialization function is available
if (!window.wpMcpAiChatInit || typeof window.wpMcpAiChatInit.init !== 'function') {
// Retry if we haven't exceeded max attempts
if (retryCount < maxRetries) {
console.log('[PM AI Assistant] Chat init not ready, retrying... (' + (retryCount + 1) + '/' + maxRetries + ')');
setTimeout(function() {
initializeChatInstance(instanceId, retryCount + 1);
}, retryDelay);
return;
}
console.error('[PM AI Assistant] Chat init function not available after ' + maxRetries + ' retries');
return;
}

try {
window.wpMcpAiChatInit.init();
console.log('[PM AI Assistant] ✓ Chat initialized successfully');

setTimeout(function() {
const textarea = container.querySelector('.wp-mcp-ai-chat__input');
if (textarea) {
textarea.focus();
}
}, 200);
} catch (error) {
console.error('[PM AI Assistant] Chat initialization error:', error);
}
}

/**
 * Check if block editor is active.
 *
 * @return {boolean} True if block editor is active.
 */
function isBlockEditorActive() {
try {
return typeof wp !== 'undefined' && 
   wp.data && 
   typeof wp.data.select === 'function' &&
   wp.data.select('core/editor') !== undefined;
} catch (error) {
return false;
}
}

/**
 * Wait for metabox elements to be available in DOM.
 *
 * @param {Function} callback Function to call when elements are ready.
 * @param {number} maxAttempts Maximum polling attempts.
 */
function waitForMetabox(callback, maxAttempts) {
maxAttempts = maxAttempts || 50;
let attempts = 0;
let delay = 100;

function checkElements() {
attempts++;

const $selector = $('#wp-mcp-ai-pm-assistant-select');
const $container = $('#wp-mcp-ai-pm-assistant-inline-container');

if ($selector.length && $container.length) {
console.log('[PM AI Assistant] ✓ Elements found after ' + attempts + ' attempts');
callback();
return;
}

if (attempts >= maxAttempts) {
console.error('[PM AI Assistant] Timeout: Elements not found after ' + maxAttempts + ' attempts');
return;
}

delay = Math.min(delay * 1.5, 500);
setTimeout(checkElements, delay);
}

checkElements();
}

// Initialize based on editor type
if (isBlockEditorActive()) {
console.log('[PM AI Assistant] Block editor detected');

if (typeof wp !== 'undefined' && wp.domReady) {
wp.domReady(function() {
console.log('[PM AI Assistant] wp.domReady fired');
waitForMetabox(initPmAiAssistant);
});
} else {
$(document).ready(function() {
console.log('[PM AI Assistant] document.ready (fallback)');
waitForMetabox(initPmAiAssistant);
});
}
} else {
console.log('[PM AI Assistant] Classic editor');

$(document).ready(function () {
const $selector = $('#wp-mcp-ai-pm-assistant-select');

if ($selector.length) {
initPmAiAssistant();
} else {
waitForMetabox(initPmAiAssistant, 30);
}
});
}

})(jQuery);
