/**
 * Research Bundle Entry Point
 * 
 * Bundles cheerio + turndown with research compiler
 * for HTML parsing and markdown conversion.
 * 
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

import ResearchCompiler from './orchestration/research-compiler.js';

// Export to window for WordPress
window.WpMcpAiResearchCompiler = ResearchCompiler;

// Also export as module for modern builds
export default ResearchCompiler;
