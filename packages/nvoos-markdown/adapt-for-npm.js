// Adaptation script: Convert WordPress plugin markdown renderer to standalone NPM package
// This script removes WordPress-specific code and makes the module framework-agnostic

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting chat-markdown-service.js for NPM distribution...\n');

// Read the original WordPress plugin file
const sourceFile = path.join(__dirname, 'chat-markdown-service.js');
let code = fs.readFileSync(sourceFile, 'utf8');

// Step 1: Keep ES6 imports as-is (they're already good)
console.log('   → Preserving ES6 imports');

// Step 2: Remove WordPress-specific IIFE wrapper
console.log('   → Converting from IIFE to ES module');
code = code.replace(/\(function\(window\) \{[\s]*'use strict';/, '');
code = code.replace(/\/\/ Export public API[\s\S]*?window\.wpMcpAiChatMarkdown = \{[\s\S]*?\};[\s\S]*?\}\)\(window\);/, '');

// Step 3: Extract internal helper functions
console.log('   → Exposing helper functions as exports');

// Mark the renderMarkdown function for export
code = code.replace('function renderMarkdown(text)', 'export function renderMarkdown(text)');
code = code.replace('function renderInlineLabel(text)', 'export function renderInlineLabel(text)');
code = code.replace('function escapeHtml(text)', 'export function escapeHtml(text)');
code = code.replace('function sanitizeUrl(url)', 'export function sanitizeUrl(url)');
code = code.replace('function formatInline(text)', 'export function formatInline(text)');

// Step 4: Create a default export class that wraps all functionality
const classWrapper = `

/**
 * Markdown renderer with security hardening
 * Wraps marked + DOMPurify with production-ready configuration
 */
export class MarkdownRenderer {
	constructor(markedInstance, domPurifyInstance, customConfig = {}) {
		this.marked = markedInstance || marked;
		this.DOMPurify = domPurifyInstance || DOMPurify;
		this.config = {
			codeBlockClass: customConfig.codeBlockClass || 'nvoos-code-block',
			imageClass: customConfig.imageClass || 'nvoos-image',
			allowedTags: customConfig.allowedTags || [
				'p', 'br', 'strong', 'em', 'code', 'pre', 'a', 
				'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 
				'h3', 'h4', 'h5', 'h6', 'del', 'img'
			],
			allowedAttributes: customConfig.allowedAttributes || ['href', 'target', 'rel', 'class', 'src', 'alt', 'title', 'loading']
		};
		this._setupRenderer();
	}
	
	_setupRenderer() {
		// Configure marked
		this.marked.setOptions({
			breaks: true,
			gfm: true,
			headerIds: false,
			mangle: false,
			sanitize: false,
		});
		
		// Set up custom renderer
		const renderer = new this.marked.Renderer();
		const config = this.config;
		
		renderer.code = function(code, language, escaped) {
			const safeCode = code || '';
			const lang = language || '';
			const escapedLang = lang.replace(/[^a-z0-9+#.-]/gi, '').toLowerCase();
			const className = escapedLang ? ' class="language-' + escapedLang + '"' : '';
			return '<pre class="' + config.codeBlockClass + '"><code' + className + '>' + escapeHtml(safeCode) + '</code></pre>';
		};
		
		renderer.image = function(href, title, text) {
			const safeHref = href || '';
			const safeTitle = title || '';
			const safeText = text || '';
			const titleAttr = safeTitle ? ' title="' + safeTitle + '"' : '';
			return '<img src="' + safeHref + '" alt="' + safeText + '"' + titleAttr + ' class="' + config.imageClass + '" loading="lazy" />';
		};
		
		this.marked.use({ renderer });
	}
	
	render(text) {
		return renderMarkdown.call(this, text);
	}
	
	renderInline(text) {
		return renderInlineLabel(text);
	}
}

// Default export
export default MarkdownRenderer;
`;

code = code.trim() + classWrapper;

// Step 5: Update renderMarkdown to work with instance
code = code.replace(
	/const rawHtml = marked\.parse\(text\);/g,
	'const rawHtml = this.marked.parse(text);'
);

code = code.replace(
	/const sanitized = DOMPurify\.sanitize\(rawHtml, \{[\s\S]*?\}\);/,
	`const sanitized = this.DOMPurify.sanitize(rawHtml, {
				ALLOWED_TAGS: this.config.allowedTags,
				ALLOWED_ATTR: this.config.allowedAttributes,
				ALLOW_DATA_ATTR: false,
			});`
);

// Step 6: Create dist directory
const distDir = path.join(__dirname, 'dist');
if (!fs.existsSync(distDir)) {
  fs.mkdirSync(distDir, { recursive: true });
}

// Step 7: Write adapted file
const outputFile = path.join(distDir, 'nvoos-markdown.js');
fs.writeFileSync(outputFile, code);

console.log('   → Generated dist/nvoos-markdown.js');

// Step 8: Generate TypeScript definitions
const dtsContent = `/**
 * Security-hardened markdown renderer
 * @package @nvdigital/nvoos-markdown
 */

import { marked } from 'marked';
import DOMPurify from 'dompurify';

export interface MarkdownConfig {
  codeBlockClass?: string;
  imageClass?: string;
  allowedTags?: string[];
  allowedAttributes?: string[];
}

export declare class MarkdownRenderer {
  marked: typeof marked;
  DOMPurify: typeof DOMPurify;
  config: Required<MarkdownConfig>;
  
  constructor(
    markedInstance?: typeof marked,
    domPurifyInstance?: typeof DOMPurify,
    customConfig?: MarkdownConfig
  );
  
  render(text: string): string;
  renderInline(text: string): string;
}

export declare function renderMarkdown(text: string): string;
export declare function renderInlineLabel(text: string): string;
export declare function escapeHtml(text: string): string;
export declare function sanitizeUrl(url: string): string;
export declare function formatInline(text: string): string;

export default MarkdownRenderer;
`;

fs.writeFileSync(path.join(distDir, 'nvoos-markdown.d.ts'), dtsContent);
console.log('   → Generated TypeScript definitions');

console.log('\n✅ Package adapted successfully!');
console.log('📦 Ready for NPM publication\n');
