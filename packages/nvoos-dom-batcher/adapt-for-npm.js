// Adaptation script: Convert WordPress UI utilities service to standalone NPM package

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-ui-utilities-service for NPM distribution...\n');

// ─── 1. Read source file ─────────────────────────────────────────────────────
console.log('   → Reading source file');
let code = fs.readFileSync(path.join(__dirname, 'chat-ui-utilities-service.js'), 'utf8');

// ─── 2. Strip IIFE wrapper ────────────────────────────────────────────────────
console.log('   → Stripping IIFE wrapper');
// Step 1 – Remove opening line.
code = code.replace(/^\(function\(window\) \{\s*[\r\n]+\s*'use strict';\s*[\r\n]+/, '');
// Step 2 – Remove the "// Export public API\n\twindow.wpMcpAiChatUIUtils = { … };" block.
code = code.replace(/[\r\n]+\s*\/\/ Export public API[\r\n]+\s*window\.wpMcpAiChatUIUtils\s*=\s*\{[\s\S]*?\n\s*\};/, '');
// Step 3 – Remove the trailing IIFE close "})(window);\n"
code = code.replace(/[\r\n]+\}\)\(window\);\s*$/, '');

// ─── 3. Make DEBUG_MODE configurable ─────────────────────────────────────────
console.log('   → Making DEBUG_MODE configurable');
code = code.replace(
	'// Performance optimization settings\n\tconst DEBUG_MODE = window.wpMcpAiChatDebugMode === true;\n\tconst OPTIMIZATIONS_ENABLED = !DEBUG_MODE;',
	`// Performance optimization settings
	// These are module-level variables that can be overridden via configure().
	let DEBUG_MODE = false;
	let OPTIMIZATIONS_ENABLED = true;`
);

// ─── 4. Remove residual window.console guards ─────────────────────────────────
console.log('   → Normalising console calls');
code = code.replace(/if \(window\.console && console\.(error|warn|log)\)/g, 'if (console && console.$1)');
code = code.replace(/window\.console && console\./g, 'console && console.');

// ─── 5. Add configure() function ──────────────────────────────────────────────
console.log('   → Adding configure()');
const configureBlock = `
/**
 * Configure the DOM batcher module.
 *
 * @param {Object}  options
 * @param {boolean} [options.debug=false]          - Enable debug mode (disables RAF batching)
 * @param {boolean} [options.optimizations=true]   - Enable requestAnimationFrame batching
 */
function configure(options) {
	if (!options) return;
	if (typeof options.debug === 'boolean') {
		DEBUG_MODE = options.debug;
		OPTIMIZATIONS_ENABLED = !DEBUG_MODE;
	}
	if (typeof options.optimizations === 'boolean') {
		OPTIMIZATIONS_ENABLED = options.optimizations;
	}
}

`;

// Insert configure() before domUpdateBatcher
code = code.replace(
	'\n\t/**\n\t * DOM update batcher',
	'\n' + configureBlock + '\t/**\n\t * DOM update batcher'
);

// ─── 6. Add ES module exports ─────────────────────────────────────────────────
console.log('   → Adding ES module exports');
code = code.trim() + `

// ES Module exports
export {
	configure,

	// Core batchers
	domUpdateBatcher,
	scrollBatcher,

	// Formatting utilities
	escapeHtml,
	formatBytes,
	formatDuration,
	formatElapsedTime,

	// Status management
	setStatus,
	clearStatus,

	// Button management
	toggleButtonClass,
	setButtonState,
	setButtonIcon,
	updateButtonLabel,

	// Cross-chat communication
	broadcastMessage,
	listenToChatEvents,
	getOtherChatInstances,
	copyMessageToClipboard,

	// Attachment library
	validateAttachment,
	addToAttachmentLibrary,
	getFromAttachmentLibrary,
	removeFromAttachmentLibrary,

	// Recording timer
	displayRecordingTimer,
};

export default {
	configure,
	domUpdateBatcher,
	scrollBatcher,
	escapeHtml,
	formatBytes,
	formatDuration,
	formatElapsedTime,
	setStatus,
	clearStatus,
	toggleButtonClass,
	setButtonState,
	setButtonIcon,
	updateButtonLabel,
	broadcastMessage,
	listenToChatEvents,
	getOtherChatInstances,
	copyMessageToClipboard,
	validateAttachment,
	addToAttachmentLibrary,
	getFromAttachmentLibrary,
	removeFromAttachmentLibrary,
	displayRecordingTimer,
};
`;

// ─── 7. Write dist ────────────────────────────────────────────────────────────
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
	fs.mkdirSync(distDir, { recursive: true });
}
fs.writeFileSync(path.join(distDir, 'nvoos-dom-batcher.js'), code);
console.log('   → Generated dist/nvoos-dom-batcher.js');

// ─── 8. TypeScript definitions ────────────────────────────────────────────────
const dts = `/**
 * requestAnimationFrame DOM update batcher, scroll batcher, and UI utilities.
 * Prevents forced reflows in high-frequency streaming UIs (SSE / WebSocket).
 * Zero external dependencies — uses only standard browser APIs.
 * @package @nvdigitalsolutions/nvoos-dom-batcher
 */

export interface DomBatcherConfig {
  /** Enable debug mode (disables RAF batching, runs updates synchronously). Default: false */
  debug?: boolean;
  /** Enable requestAnimationFrame batching. Default: true */
  optimizations?: boolean;
}

/** Configure the DOM batcher module. Call once at application startup. */
export declare function configure(options: DomBatcherConfig): void;

/** RAF-based DOM update batcher — schedule updates with schedule(fn). */
export declare const domUpdateBatcher: {
  schedule(updateFn: () => void): void;
};

/** RAF-based scroll-to-bottom batcher — coalesces multiple scrolls per frame. */
export declare const scrollBatcher: {
  scrollToBottom(element: HTMLElement): void;
};

/** Escape HTML special characters to prevent XSS. */
export declare function escapeHtml(text: string): string;

/** Format a byte count as a human-readable string (e.g. "1.5 KB"). */
export declare function formatBytes(bytes: number): string;

/** Format seconds as MM:SS or HH:MM:SS. */
export declare function formatDuration(value: number): string;

/** Format elapsed seconds as a short string ("5s", "1m 30s"). */
export declare function formatElapsedTime(seconds: number): string;

/** Display a status message inside a container element. */
export declare function setStatus(container: HTMLElement | null, message: string, type?: string): void;

/** Clear the status message inside a container element. */
export declare function clearStatus(container: HTMLElement | null): void;

/** Toggle a CSS class on a button, or set/remove it based on a boolean. */
export declare function toggleButtonClass(button: HTMLElement | null, className: string, force?: boolean): void;

/** Set a named visual state on a button via data-state attribute. */
export declare function setButtonState(button: HTMLElement | null, stateName: string): void;

/** Safely set SVG/HTML icon content inside a button, guarding against XSS. */
export declare function setButtonIcon(button: HTMLElement | null, iconHTML: string, selector?: string): void;

/** Update aria-label and title on an element. */
export declare function updateButtonLabel(button: HTMLElement | null, label: string): void;

/** Broadcast a message to other chat instances on the same page via BroadcastChannel. */
export declare function broadcastMessage(channel: string, data: unknown): void;

/** Listen for messages from other chat instances via BroadcastChannel. */
export declare function listenToChatEvents(channel: string, handler: (data: unknown) => void): () => void;

/** Get references to other chat instances on the page. */
export declare function getOtherChatInstances(currentInstance: HTMLElement): HTMLElement[];

/** Copy the text content of a message element to the clipboard. */
export declare function copyMessageToClipboard(element: HTMLElement): Promise<boolean>;

/** Validate a file attachment against size and type constraints. */
export declare function validateAttachment(
  file: File,
  options?: { maxBytes?: number; allowedTypes?: string[] }
): { valid: boolean; error?: string };

/** Add a file to the in-memory attachment library. */
export declare function addToAttachmentLibrary(id: string, file: File): void;

/** Retrieve a file from the in-memory attachment library. */
export declare function getFromAttachmentLibrary(id: string): File | undefined;

/** Remove a file from the in-memory attachment library. */
export declare function removeFromAttachmentLibrary(id: string): void;

/** Display and update a recording timer in a container element. */
export declare function displayRecordingTimer(
  container: HTMLElement | null,
  startTime: number
): () => void;

declare const _default: {
  configure: typeof configure;
  domUpdateBatcher: typeof domUpdateBatcher;
  scrollBatcher: typeof scrollBatcher;
  escapeHtml: typeof escapeHtml;
  formatBytes: typeof formatBytes;
  formatDuration: typeof formatDuration;
  formatElapsedTime: typeof formatElapsedTime;
  setStatus: typeof setStatus;
  clearStatus: typeof clearStatus;
  toggleButtonClass: typeof toggleButtonClass;
  setButtonState: typeof setButtonState;
  setButtonIcon: typeof setButtonIcon;
  updateButtonLabel: typeof updateButtonLabel;
  broadcastMessage: typeof broadcastMessage;
  listenToChatEvents: typeof listenToChatEvents;
  getOtherChatInstances: typeof getOtherChatInstances;
  copyMessageToClipboard: typeof copyMessageToClipboard;
  validateAttachment: typeof validateAttachment;
  addToAttachmentLibrary: typeof addToAttachmentLibrary;
  getFromAttachmentLibrary: typeof getFromAttachmentLibrary;
  removeFromAttachmentLibrary: typeof removeFromAttachmentLibrary;
  displayRecordingTimer: typeof displayRecordingTimer;
};
export default _default;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-dom-batcher.d.ts'), dts);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
