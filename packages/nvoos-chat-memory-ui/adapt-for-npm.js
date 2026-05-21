const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-memory-drawer.js for NPM...\n');

let c = fs.readFileSync(path.join(__dirname, 'chat-memory-drawer.js'), 'utf8');

// Strip IIFE
c = c.replace('(function(window, document) {\n\t\'use strict\';\n\n\t', '');
c = c.replace(/\n\}\)\(window, document\);\s*$/, '');

// Replace WP i18n with injectable
c = c.replace(
  /const i18n = \(window\.wp && window\.wp\.i18n\) \|\| \{[\s\S]*?\};/,
  `var _i18nConfig = null;
function __(text) {
  if (_i18nConfig && typeof _i18nConfig.__ === 'function') return _i18nConfig.__(text);
  return text;
}
function _sprintf(format) {
  var args = Array.prototype.slice.call(arguments, 1);
  if (_i18nConfig && typeof _i18nConfig.sprintf === 'function') return _i18nConfig.sprintf.apply(null, [format].concat(args));
  var i = 0;
  return String(format).replace(/%s/g, function() { return args[i++]; });
}`
);

// Remove const __ and references to i18n.sprintf
c = c.replace(/const __ = i18n\.__;/, '');
c = c.replace(/i18n\.sprintf/g, '_sprintf');

// Replace window.wpMcpAiChatMemory with injected client
c = c.replace(
  /function memoryService\(\) \{\s*\n\s*return window\.wpMcpAiChatMemory \|\| null;\s*\n\s*\}/,
  `var _memoryClient = null;
function memoryService() { return _memoryClient; }`
);

// Add configure() at the top
const cfg = `
/**
 * Configure the memory drawer.
 *
 * @param {Object} options
 * @param {Object} options.memoryClient - nvoos-chat-memory client instance (required)
 * @param {Object} [options.i18n] - i18n functions ({ __, sprintf })
 * @param {string} [options.cssPrefix] - CSS class prefix (default 'wp-mcp-ai')
 */
function configure(options) {
  if (!options) return;
  if (options.memoryClient) _memoryClient = options.memoryClient;
  if (options.i18n) _i18nConfig = options.i18n;
  if (typeof options.cssPrefix === 'string') CSS_PREFIX = options.cssPrefix;
}
var CSS_PREFIX = 'wp-mcp-ai';
`;

c = cfg + '\n' + c;

// Replace CSS class prefix references
c = c.replace(/'wp-mcp-ai-memory-/g, "CSS_PREFIX + '-memory-");

// Remove __() domain argument (, 'mcp-ai-wpoos')
c = c.replace(/, 'mcp-ai-wpoos'/g, '');
c = c.replace(/, "mcp-ai-wpoos"/g, '');

// Add exports
c = c.trim() + `

// ─── ES Module exports ───────────────────────────────────────────────────────
var MemoryDrawer = {
  attach: attach,
  buildDrawer: buildDrawer,
  injectToggle: injectToggle,
  decorateMessageWithBadge: decorateMessageWithBadge,
  handleSseMemoryEvent: handleSseMemoryEvent,
  configure: configure,
};

export { MemoryDrawer, configure, attach, buildDrawer, decorateMessageWithBadge, handleSseMemoryEvent };
export default MemoryDrawer;
`;

// Write dist
const d = path.join(__dirname, 'dist');
if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
fs.writeFileSync(path.join(d, 'nvoos-chat-memory-ui.js'), c);
console.log('   → dist/nvoos-chat-memory-ui.js');

// TypeScript definitions
const dts = `/**
 * Chat memory drawer UI — side panel for long-term AI memories.
 * Requires @nvdigitalsolutions/nvoos-chat-memory as peer dependency.
 * @package @nvdigitalsolutions/nvoos-chat-memory-ui
 */

export interface MemoryUIConfig {
  memoryClient: {
    isAvailable(): boolean;
    recall(query: string, filters?: Record<string, unknown>): Promise<unknown>;
    store?(data: Record<string, unknown>): Promise<unknown>;
    update?(id: string, data: Record<string, unknown>): Promise<unknown>;
    remove?(id: string, opts?: Record<string, unknown>): Promise<unknown>;
    audit?(opts?: Record<string, unknown>): Promise<unknown>;
    sessionReplay?(sessionId: string, opts?: Record<string, unknown>): Promise<unknown>;
  };
  i18n?: { __(text: string): string; sprintf?(format: string, ...args: unknown[]): string };
  cssPrefix?: string;
}

export declare function configure(options: MemoryUIConfig): void;

export interface MemoryDrawerController {
  open(returnTarget?: HTMLElement): void;
  close(): void;
  isOpen(): boolean;
  root: HTMLElement;
  refresh(): void;
}

export declare function buildDrawer(container: HTMLElement, state: Record<string, unknown>): MemoryDrawerController;
export declare function attach(container: HTMLElement): void;
export declare function injectToggle(container: HTMLElement, controller: MemoryDrawerController): void;
export declare function decorateMessageWithBadge(bubble: HTMLElement, toolCalls: unknown[]): void;
export declare function handleSseMemoryEvent(payload: { action: string; tool_name?: string }): void;

export declare const MemoryDrawer: {
  attach: typeof attach;
  buildDrawer: typeof buildDrawer;
  injectToggle: typeof injectToggle;
  decorateMessageWithBadge: typeof decorateMessageWithBadge;
  handleSseMemoryEvent: typeof handleSseMemoryEvent;
  configure: typeof configure;
};

export default MemoryDrawer;
`;
fs.writeFileSync(path.join(d, 'nvoos-chat-memory-ui.d.ts'), dts);
console.log('   → dist/nvoos-chat-memory-ui.d.ts');
console.log('\n✅ nvoos-chat-memory-ui built!\n');
