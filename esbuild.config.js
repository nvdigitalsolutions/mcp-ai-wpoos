/**
 * esbuild configuration for WP MCP AI JavaScript files
 *
 * This provides:
 * - Fast bundling (10-100x faster than webpack)
 * - Minification
 * - Source maps for debugging
 * - ES6+ to ES2015 transpilation
 * - Tree shaking for smaller bundles
 *
 * Chat Bundle Optimization:
 * The chat-bundle.js entry point bundles all chat-related services into a single
 * optimized file, reducing HTTP requests from 10 files to just 1 file.
 *
 * Bundled modules include:
 * - sse-service.js (Server-Sent Events)
 * - job-event-bus.js (event coordination)
 * - cron-status-service.js (async job status)
 * - chat-http-client-service.js (HTTP with retry logic)
 * - chat-storage-service.js (localStorage)
 * - chat-clipboard-service.js (copy functionality)
 * - chat-markdown-service.js (markdown rendering)
 * - chat-ui-utilities-service.js (DOM helpers)
 * - chat-audio-service.js (TTS/transcription)
 * - chat-attachments-service.js (file upload/attachment handling)
 * - chat-transcription-service.js (audio recording and transcription)
 * - chat.js (main chat application)
 */

const esbuild = require('esbuild');
const path = require('path');
const fs = require('fs');

// Common build options for unbundled files (minify only)
const commonOptions = {
	bundle: false,
	minify: true,
	sourcemap: true,
	target: ['es2015'], // Compatible with WordPress requirements
	format: 'iife', // Immediately Invoked Function Expression for browser
	logLevel: 'info',
};

// Bundled build options (for chat-bundle)
const bundledOptions = {
	bundle: true,
	minify: true,
	sourcemap: true,
	target: ['es2015'],
	format: 'iife',
	logLevel: 'info',
};

// TypeScript ESM build options (for src/ TypeScript services)
const tsBuildOptions = {
	bundle: true,
	minify: true,
	sourcemap: true,
	target: ['es2020', 'chrome113', 'safari18'],
	format: 'esm',
	splitting: false,
	logLevel: 'info',
	loader: {
		'.wasm': 'file',
		'.data': 'file',
	},
};

// Build configurations for each file
const builds = [
	{
		entryPoints: ['assets/js/admin-settings.js'],
		outfile: 'assets/js/admin-settings.min.js',
		...commonOptions,
	},
	// Bundled chat build (combines all chat services into single file)
	{
		entryPoints: ['assets/js/chat-bundle.js'],
		outfile: 'assets/js/chat-bundle.min.js',
		...bundledOptions,
	},
	// Keep individual chat.min.js for backward compatibility (unbundled)
	{
		entryPoints: ['assets/js/chat.js'],
		outfile: 'assets/js/chat.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/settings-dashboard.js'],
		outfile: 'assets/js/settings-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/user-chats.js'],
		outfile: 'assets/js/user-chats.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/auth0-setup.js'],
		outfile: 'assets/js/auth0-setup.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/mcp-diagnostic.js'],
		outfile: 'assets/js/mcp-diagnostic.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/performance-blocks.js'],
		outfile: 'assets/js/performance-blocks.min.js',
		...commonOptions,
	},
	// Admin dashboard assets
	{
		entryPoints: ['assets/js/ajax-error-service.js'],
		outfile: 'assets/js/ajax-error-service.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-tool-orchestration.js'],
		outfile: 'assets/js/admin-tool-orchestration.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/performance-admin.js'],
		outfile: 'assets/js/performance-admin.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/tools-manager.js'],
		outfile: 'assets/js/tools-manager.min.js',
		...commonOptions,
	},
	// WebLLM enhancement files (Phase 1) — moved to embedded addon
	{
		entryPoints: ['addons/embedded/assets/js/webllm-tool-adapter.js'],
		outfile: 'addons/embedded/assets/js/webllm-tool-adapter.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/embedded/assets/js/webllm-function-calling-client.js'],
		outfile: 'addons/embedded/assets/js/webllm-function-calling-client.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/embedded/assets/js/webllm-multimodal-client.js'],
		outfile: 'addons/embedded/assets/js/webllm-multimodal-client.min.js',
		...commonOptions,
	},
	// Transformers.js files (Phase 2)
	{
		entryPoints: ['assets/js/transformers-tasks-client.js'],
		outfile: 'assets/js/transformers-tasks-client.min.js',
		...commonOptions,
	},
	// LangChain.js files (Phase 3) — target ES2020+ since WebLLM requires modern browsers
	{
		entryPoints: ['addons/pro/assets/js/langchain-tool-adapter.js'],
		outfile: 'addons/pro/assets/js/langchain-tool-adapter.min.js',
		...commonOptions,
		target: ['es2020', 'chrome89', 'safari15', 'firefox90'],
	},
	{
		entryPoints: ['addons/pro/assets/js/langchain-orchestration.js'],
		outfile: 'addons/pro/assets/js/langchain-orchestration.min.js',
		...commonOptions,
		target: ['es2020', 'chrome89', 'safari15', 'firefox90'],
	},
	// Web Workers files (Phase 4)
	{
		entryPoints: ['assets/js/llm-worker-manager.js'],
		outfile: 'assets/js/llm-worker-manager.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/workers/llm-worker.js'],
		outfile: 'assets/js/workers/llm-worker.min.js',
		...commonOptions,
	},
	// Browser-native AI tools (Phase 7)
	{
		entryPoints: ['assets/js/client-tools.js'],
		outfile: 'assets/js/client-tools.min.js',
		...commonOptions,
	},
	// UX improvements (Phase 8)
	{
		entryPoints: ['assets/js/progressive-model-loader.js'],
		outfile: 'assets/js/progressive-model-loader.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/offline-chat-manager.js'],
		outfile: 'assets/js/offline-chat-manager.min.js',
		...commonOptions,
	},
	// Voice enhancement files (realtime, browser, integration)
	{
		entryPoints: ['assets/js/chat-voice-realtime-service.js'],
		outfile: 'assets/js/chat-voice-realtime-service.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/chat-browser-voice-service.js'],
		outfile: 'assets/js/chat-browser-voice-service.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/chat-voice-mode-integration.js'],
		outfile: 'assets/js/chat-voice-mode-integration.min.js',
		...commonOptions,
	},
	// ── TypeScript service builds (WP_MCP_AI_USE_TS_BUILD) ──────────
	// Compiles from assets/js/src/ → assets/js/dist/
	// Activated by: define('WP_MCP_AI_USE_TS_BUILD', true);
	{
		entryPoints: ['assets/js/src/shared/index.ts'],
		outfile: 'assets/js/dist/shared.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/storage.ts'],
		outfile: 'assets/js/dist/storage.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/clipboard.ts'],
		outfile: 'assets/js/dist/clipboard.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/markdown.ts'],
		outfile: 'assets/js/dist/markdown.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/http-client.ts'],
		outfile: 'assets/js/dist/http-client.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/sse.ts'],
		outfile: 'assets/js/dist/sse.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/ui-utilities.ts'],
		outfile: 'assets/js/dist/ui-utilities.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/audio.ts'],
		outfile: 'assets/js/dist/audio.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/transcription.ts'],
		outfile: 'assets/js/dist/transcription.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/attachments.ts'],
		outfile: 'assets/js/dist/attachments.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/memory-service.ts'],
		outfile: 'assets/js/dist/memory-service.js',
		...tsBuildOptions,
	},
	{
		entryPoints: ['assets/js/src/services/storage-util.ts'],
		outfile: 'assets/js/dist/storage-util.js',
		...tsBuildOptions,
	},
	// Chat bundle (TS version)
	{
		entryPoints: ['assets/js/chat-bundle.js'],
		outfile: 'assets/js/dist/chat-bundle.js',
		...bundledOptions,
	},
	// ── End TypeScript builds ─────────────────────────────────────

	// Admin page scripts (new)
	{
		entryPoints: ['assets/js/admin-add-assistant.js'],
		outfile: 'assets/js/admin-add-assistant.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-add-team.js'],
		outfile: 'assets/js/admin-add-team.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-build-assistant.js'],
		outfile: 'assets/js/admin-build-assistant.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-content-assistant.js'],
		outfile: 'assets/js/admin-content-assistant.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-crawl4ai-monitor.js'],
		outfile: 'assets/js/admin-crawl4ai-monitor.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-create-assistant-modal.js'],
		outfile: 'assets/js/admin-create-assistant-modal.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-create-team-modal.js'],
		outfile: 'assets/js/admin-create-team-modal.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-cron-manager.js'],
		outfile: 'assets/js/admin-cron-manager.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-model-selector.js'],
		outfile: 'assets/js/admin-model-selector.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-multi-agent-dashboard.js'],
		outfile: 'assets/js/admin-multi-agent-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-orchestration-dashboard.js'],
		outfile: 'assets/js/admin-orchestration-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-slash-commands-dashboard.js'],
		outfile: 'assets/js/admin-slash-commands-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-test-assistant.js'],
		outfile: 'assets/js/admin-test-assistant.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-test-profession.js'],
		outfile: 'assets/js/admin-test-profession.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin-test-team.js'],
		outfile: 'assets/js/admin-test-team.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin/admin-create-assistant-button.js'],
		outfile: 'assets/js/admin/admin-create-assistant-button.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/admin/admin-create-team-button.js'],
		outfile: 'assets/js/admin/admin-create-team-button.min.js',
		...commonOptions,
	},

	// Dashboard and page scripts (new)
	{
		entryPoints: ['assets/js/accessibility-enhancements.js'],
		outfile: 'assets/js/accessibility-enhancements.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/analytics-dashboard.js'],
		outfile: 'assets/js/analytics-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/asset-inventory.js'],
		outfile: 'assets/js/asset-inventory.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/datasets-admin.js'],
		outfile: 'assets/js/datasets-admin.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/enhanced-research-page.js'],
		outfile: 'assets/js/enhanced-research-page.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/orchestration-dashboard.js'],
		outfile: 'assets/js/orchestration-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/pro-dashboard.js'],
		outfile: 'assets/js/pro-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/security-audit-admin.js'],
		outfile: 'assets/js/security-audit-admin.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/security-training.js'],
		outfile: 'assets/js/security-training.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/supplier-security.js'],
		outfile: 'assets/js/supplier-security.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/token-manager-charts.js'],
		outfile: 'assets/js/token-manager-charts.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/workflow-editor.js'],
		outfile: 'assets/js/workflow-editor.min.js',
		...commonOptions,
	},

	// Feature and utility scripts (new)
	{
		entryPoints: ['assets/js/blocks/assistant-builder-blocks.js'],
		outfile: 'assets/js/blocks/assistant-builder-blocks.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/blocks/assistant-builder-blocks-frontend.js'],
		outfile: 'assets/js/blocks/assistant-builder-blocks-frontend.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/command-autocomplete.js'],
		outfile: 'assets/js/command-autocomplete.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/elementor-quick-actions-widget.js'],
		outfile: 'assets/js/elementor-quick-actions-widget.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/embedded/assets/js/embedded-llm-client.js'],
		outfile: 'addons/embedded/assets/js/embedded-llm-client.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/mesh-peer-test.js'],
		outfile: 'assets/js/mesh-peer-test.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/professional-selector.js'],
		outfile: 'assets/js/professional-selector.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/slash-commands.js'],
		outfile: 'assets/js/slash-commands.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/storage-util.js'],
		outfile: 'assets/js/storage-util.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['assets/js/storage-worker.js'],
		outfile: 'assets/js/storage-worker.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/embedded/assets/js/webllm-loader.js'],
		outfile: 'addons/embedded/assets/js/webllm-loader.min.js',
		...commonOptions,
	},

	// Pro addon browser scripts (new)
	{
		entryPoints: ['addons/pro/assets/js/agent-command-center.js'],
		outfile: 'addons/pro/assets/js/agent-command-center.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/admin-pm-ai-actions.js'],
		outfile: 'addons/pro/assets/js/admin-pm-ai-actions.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/admin-pm-ai-assistant.js'],
		outfile: 'addons/pro/assets/js/admin-pm-ai-assistant.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/admin-pm-ai-assistant-unified.js'],
		outfile: 'addons/pro/assets/js/admin-pm-ai-assistant-unified.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/chat-channels-inbox.js'],
		outfile: 'addons/pro/assets/js/chat-channels-inbox.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/skill-manager-admin.js'],
		outfile: 'addons/pro/assets/js/skill-manager-admin.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/cpt-assistant.js'],
		outfile: 'addons/pro/assets/js/cpt-assistant.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/health-consolidate.js'],
		outfile: 'addons/pro/assets/js/health-consolidate.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/media-collection-admin.js'],
		outfile: 'addons/pro/assets/js/media-collection-admin.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/media-template-admin.js'],
		outfile: 'addons/pro/assets/js/media-template-admin.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/orchestration-dashboard.js'],
		outfile: 'addons/pro/assets/js/orchestration-dashboard.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/password-vault-admin.js'],
		outfile: 'addons/pro/assets/js/password-vault-admin.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/product-research-page.js'],
		outfile: 'addons/pro/assets/js/product-research-page.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/quiz-questions.js'],
		outfile: 'addons/pro/assets/js/quiz-questions.min.js',
		...commonOptions,
	},
	{
		entryPoints: ['addons/pro/assets/js/research-page.js'],
		outfile: 'addons/pro/assets/js/research-page.min.js',
		...commonOptions,
	},
];

// Build all files
async function buildAll() {
	console.log('🚀 Building JavaScript files with esbuild...\n');

	const startTime = Date.now();
	const results = [];

	// List of all files bundled together in chat-bundle.js
	const bundledFiles = [
		'assets/js/sse-service.js',
		'assets/js/job-event-bus.js',
		'assets/js/cron-status-service.js',
		'assets/js/chat-http-client-service.js',
		'assets/js/chat-storage-service.js',
		'assets/js/chat-clipboard-service.js',
		'assets/js/chat-markdown-service.js',
		'assets/js/chat-ui-utilities-service.js',
		'assets/js/chat-audio-service.js',
		'assets/js/chat-attachments-service.js',
		'assets/js/chat-transcription-service.js',
		'assets/js/chat-memory-service.js',
		'assets/js/chat.js',
		'assets/js/chat-memory-drawer.js',
	];

	for (const config of builds) {
		try {
			await esbuild.build(config);
			const inputFile = config.entryPoints[0];
			const outputFile = config.outfile;

			// For bundled builds, calculate combined input size
			let inputSize;
			const isBundled = config.bundle === true;

			if (isBundled && inputFile.includes('chat-bundle')) {
				// Sum up all bundled file sizes
				inputSize = bundledFiles.reduce((total, file) => {
					try {
						return total + fs.statSync(file).size;
					} catch {
						return total;
					}
				}, 0);
			} else {
				inputSize = fs.statSync(inputFile).size;
			}

			const outputSize = fs.statSync(outputFile).size;
			const reduction = inputSize > 0 ? ((1 - outputSize / inputSize) * 100).toFixed(1) : '0.0';

			results.push({
				input: path.basename(inputFile),
				output: path.basename(outputFile),
				inputSize: (inputSize / 1024).toFixed(1) + ' KB',
				outputSize: (outputSize / 1024).toFixed(1) + ' KB',
				reduction: reduction + '%',
				bundled: isBundled,
			});

			if (isBundled) {
				console.log(`✅ ${path.basename(inputFile)} → ${path.basename(outputFile)} (bundled: ${bundledFiles.length} files)`);
			} else {
				console.log(`✅ ${path.basename(inputFile)} → ${path.basename(outputFile)}`);
			}
		} catch (error) {
			console.error(`❌ Error building ${config.entryPoints[0]}:`, error);
			process.exit(1);
		}
	}
	
	const endTime = Date.now();
	const duration = ((endTime - startTime) / 1000).toFixed(2);
	
	console.log('\n📊 Build Summary:');
	console.log('┌─────────────────────────────┬────────────┬─────────────┬────────────┐');
	console.log('│ File                        │ Original   │ Minified    │ Reduction  │');
	console.log('├─────────────────────────────┼────────────┼─────────────┼────────────┤');
	
	results.forEach(r => {
		const file = r.input.padEnd(27);
		const original = r.inputSize.padStart(10);
		const minified = r.outputSize.padStart(11);
		const reduction = r.reduction.padStart(10);
		console.log(`│ ${file} │ ${original} │ ${minified} │ ${reduction} │`);
	});
	
	console.log('└─────────────────────────────┴────────────┴─────────────┴────────────┘');
	console.log(`\n⚡ Build completed in ${duration}s`);
}

// Run the build
buildAll().catch((error) => {
	console.error('Build failed:', error);
	process.exit(1);
});
