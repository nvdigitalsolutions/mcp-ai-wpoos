const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-bubble.js for NPM...\n');

let c = fs.readFileSync(path.join(__dirname, 'chat-bubble.js'), 'utf8');

// Strip IIFE
c = c.replace(/\( function\(\) \{[\s\S]*?'use strict';\s*[\r\n]+/, '');
c = c.replace(/[\r\n]+\} \)\(\);\s*$/, '');

// Remove the window.wpMcpAiChatBubble global assignment block
c = c.replace(
  /\/\/ Register the global API[\s\S]*?window\.wpMcpAiChatBubble\s*=\s*\{[\s\S]*?\n\t\};/,
  ''
);

// Remove Elementor integration + Bootstrap block at the end
c = c.replace(
  /\t\/\* -+[\r\n]+\t \* Elementor Integration[\s\S]*$/,
  ''
);

// Make constants configurable: const → let
c = c.replace('const CLASSES = {', 'let CLASSES = {');
c = c.replace('const EVENTS = {',   'let EVENTS = {');
c = c.replace("const STORAGE_PREFIX = '", "let STORAGE_PREFIX = '");
c = c.replace("const LOG_PREFIX = '",     "let LOG_PREFIX = '");

// Add _lazyInitCallback variable
c = c.replace('let domObserver = null;', 'let domObserver = null;\nlet _lazyInitCallback = null;');

// Add configure() before the helpers section
const cfg = `
/**
 * Configure class names, event names, and lazy-init callback.
 *
 * @param {Object} options
 * @param {Object} [options.classes] - BEM class name overrides
 * @param {string} [options.classes.ROOT]
 * @param {string} [options.classes.TRIGGER]
 * @param {string} [options.classes.PANEL]
 * @param {string} [options.classes.PANEL_CLOSE]
 * @param {string} [options.classes.BADGE]
 * @param {string} [options.classes.OPEN]
 * @param {Object} [options.events] - Custom event name overrides
 * @param {string} [options.events.OPEN]
 * @param {string} [options.events.CLOSE]
 * @param {string} [options.storagePrefix]
 * @param {string} [options.logPrefix]
 * @param {Function} [options.lazyInitCallback]
 */
function configure(options) {
	if (!options) return;
	if (options.classes) {
		var cls = options.classes;
		if (cls.ROOT) CLASSES.ROOT = cls.ROOT;
		if (cls.TRIGGER) CLASSES.TRIGGER = cls.TRIGGER;
		if (cls.PANEL) CLASSES.PANEL = cls.PANEL;
		if (cls.PANEL_CLOSE) CLASSES.PANEL_CLOSE = cls.PANEL_CLOSE;
		if (cls.BADGE) CLASSES.BADGE = cls.BADGE;
		if (cls.OPEN) CLASSES.OPEN = cls.OPEN;
	}
	if (options.events) {
		var ev = options.events;
		if (ev.OPEN) EVENTS.OPEN = ev.OPEN;
		if (ev.CLOSE) EVENTS.CLOSE = ev.CLOSE;
	}
	if (typeof options.storagePrefix === 'string') STORAGE_PREFIX = options.storagePrefix;
	if (typeof options.logPrefix === 'string') LOG_PREFIX = options.logPrefix;
	if (typeof options.lazyInitCallback === 'function') _lazyInitCallback = options.lazyInitCallback;
}
`;

c = c.replace(/(\/\* -+[\s\S]*?Helpers[\s\S]*?-+ \*\/)/, cfg + '\n$1');

// Replace window.wpMcpAiChatInit with _lazyInitCallback
c = c.replace(
  /if\s*\(\s*window\.wpMcpAiChatInit\s*&&\s*typeof window\.wpMcpAiChatInit\.init\s*===\s*'function'\s*\)\s*\{[\s\S]*?window\.wpMcpAiChatInit\.init\(\s*this\.panel\s*\);[\s\S]*?\}/,
  `if (typeof _lazyInitCallback === 'function') {
			log('Initializing embedded chat', {
				bubbleId: this.bubbleId,
			});
			_lazyInitCallback( this.panel );
		}`
);

// Remove window.console guards
c = c.replace(/window\.console && console\./g, 'console && console.');
c = c.replace(/! window\.console \|\| typeof console\.log !== 'function'/g, '!console || typeof console.log !== "function"');

// Add exports
c = c.trim() + `

// ─── ES Module exports ───────────────────────────────────────────────────────
var ChatBubble = {
	init: init,
	open: function (id) {
		var inst = getInstance(id);
		if (inst) inst.open();
	},
	close: function (id) {
		var inst = getInstance(id);
		if (inst) inst.close();
	},
	toggle: function (id) {
		var inst = getInstance(id);
		if (inst) inst.toggle();
	},
	setBadge: function (id, count) {
		var inst = getInstance(id);
		if (inst) inst.setBadge(count);
	},
	getInstance: getInstance,
	registerDomObserver: registerDomObserver,
};

export { configure, ChatBubble };
export default ChatBubble;
`;

// Write dist
const d = path.join(__dirname, 'dist');
if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
fs.writeFileSync(path.join(d, 'nvoos-chat-bubble.js'), c);
console.log('   → dist/nvoos-chat-bubble.js');

// TypeScript definitions
const dts = `/**
 * Floating chat bubble widget with accessibility, sessionStorage persistence,
 * badge notifications, and MutationObserver auto-discovery.
 * Zero external dependencies.
 * @package @nvdigitalsolutions/nvoos-chat-bubble
 */

export interface ChatBubbleClasses {
  ROOT?: string;
  TRIGGER?: string;
  PANEL?: string;
  PANEL_CLOSE?: string;
  BADGE?: string;
  OPEN?: string;
}

export interface ChatBubbleEvents {
  OPEN?: string;
  CLOSE?: string;
}

export interface ChatBubbleConfig {
  classes?: ChatBubbleClasses;
  events?: ChatBubbleEvents;
  storagePrefix?: string;
  logPrefix?: string;
  lazyInitCallback?: (panel: HTMLElement) => void;
}

export declare function configure(options: ChatBubbleConfig): void;

export interface BubbleInstance {
  readonly bubbleId: string;
  readonly isOpen: boolean;
  open(): void;
  close(): void;
  toggle(): void;
  setBadge(count: number): void;
  destroy(): void;
}

export declare const ChatBubble: {
  init(scope?: HTMLElement): void;
  open(bubbleId: string): void;
  close(bubbleId: string): void;
  toggle(bubbleId: string): void;
  setBadge(bubbleId: string, count: number): void;
  getInstance(bubbleId: string): BubbleInstance | undefined;
  registerDomObserver(): void;
};

export default ChatBubble;
`;
fs.writeFileSync(path.join(d, 'nvoos-chat-bubble.d.ts'), dts);
console.log('   → dist/nvoos-chat-bubble.d.ts');
console.log('\n✅ nvoos-chat-bubble built!\n');
