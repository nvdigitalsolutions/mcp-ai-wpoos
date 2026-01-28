/**
 * Progressive Model Loader
 *
 * Provides a user-friendly UI for loading AI models with progress tracking
 * and cache awareness.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.0.0
 */

/* global CreateMLCEngine */

(function() {
	'use strict';

	/**
	 * Progressive Model Loader Class
	 *
	 * Handles model loading with a 4-stage UI showing progress and status.
	 */
	class ProgressiveModelLoader {
		constructor() {
			this.loadingStages = [
				{ name: 'checking', progress: 0, message: 'Checking cache...' },
				{ name: 'downloading', progress: 0, message: 'Downloading model...' },
				{ name: 'initializing', progress: 95, message: 'Initializing...' },
				{ name: 'ready', progress: 100, message: 'Ready!' }
			];
		}

		/**
		 * Load model with UI feedback
		 *
		 * @param {string} modelId - Model identifier
		 * @param {HTMLElement} container - Container element for UI
		 * @return {Promise<Object>} Initialized engine
		 */
		async loadWithUI(modelId, container) {
			const ui = this.createLoadingUI(container);

			try {
				// Stage 1: Check cache
				this.updateStage(ui, 0);
				const cached = await this.checkModelCache(modelId);

				// Stage 2: Download if needed
				if (!cached) {
					this.updateStage(ui, 1);
					await this.downloadModel(modelId, (progress) => {
						this.updateProgress(ui, progress);
					});
				} else {
					// Skip to stage 3 if cached
					this.updateStage(ui, 2);
				}

				// Stage 3: Initialize
				this.updateStage(ui, 2);
				const engine = await CreateMLCEngine(modelId, {
					initProgressCallback: (report) => {
						// Convert progress report to percentage
						const progress = Math.min(95, report.progress * 95);
						this.updateProgress(ui, progress);
						this.updateDetails(ui, report.text);
					}
				});

				// Stage 4: Ready
				this.updateStage(ui, 3);
				setTimeout(() => ui.remove(), 1000);

				return engine;

			} catch (error) {
				this.showError(ui, error);
				throw error;
			}
		}

		/**
		 * Check if model is cached
		 *
		 * @param {string} modelId - Model identifier
		 * @return {Promise<boolean>} True if cached
		 */
		async checkModelCache(modelId) {
			// Check browser cache for model files
			if (!window.caches) {
				return false;
			}

			try {
				const cache = await caches.open('webllm-models');
				const keys = await cache.keys();

				// Check if any cached files match the model ID
				const hasCached = keys.some(request => 
					request.url.includes(modelId)
				);

				return hasCached;
			} catch (error) {
				console.warn('[Progressive Loader] Cache check failed:', error);
				return false;
			}
		}

		/**
		 * Download model with progress tracking
		 *
		 * @param {string} modelId - Model identifier
		 * @param {Function} onProgress - Progress callback
		 * @return {Promise<void>}
		 */
		async downloadModel(modelId, onProgress) {
			// This is a placeholder - actual download is handled by WebLLM
			// We simulate progress for UI feedback
			let progress = 0;
			const interval = setInterval(() => {
				progress += 5;
				if (progress >= 90) {
					clearInterval(interval);
				}
				onProgress(progress);
			}, 100);

			return new Promise(resolve => {
				setTimeout(() => {
					clearInterval(interval);
					onProgress(90);
					resolve();
				}, 2000);
			});
		}

		/**
		 * Create loading UI
		 *
		 * @param {HTMLElement} container - Container element
		 * @return {HTMLElement} Loading UI element
		 */
		createLoadingUI(container) {
			const ui = document.createElement('div');
			ui.className = 'wp-mcp-ai-model-loading';
			ui.innerHTML = `
				<div class="loading-animation">
					<div class="spinner"></div>
				</div>
				<div class="loading-stage"></div>
				<div class="loading-progress">
					<div class="progress-bar">
						<div class="progress-fill"></div>
					</div>
					<div class="progress-text">0%</div>
				</div>
				<div class="loading-details"></div>
			`;
			container.appendChild(ui);
			return ui;
		}

		/**
		 * Update loading stage
		 *
		 * @param {HTMLElement} ui - UI element
		 * @param {number} stageIndex - Stage index
		 */
		updateStage(ui, stageIndex) {
			const stage = this.loadingStages[stageIndex];
			ui.querySelector('.loading-stage').textContent = stage.message;
			this.updateProgress(ui, stage.progress);
		}

		/**
		 * Update progress bar
		 *
		 * @param {HTMLElement} ui - UI element
		 * @param {number} progress - Progress percentage (0-100)
		 */
		updateProgress(ui, progress) {
			const fill = ui.querySelector('.progress-fill');
			const text = ui.querySelector('.progress-text');

			fill.style.width = `${progress}%`;
			text.textContent = `${Math.round(progress)}%`;
		}

		/**
		 * Update details text
		 *
		 * @param {HTMLElement} ui - UI element
		 * @param {string} details - Details text
		 */
		updateDetails(ui, details) {
			ui.querySelector('.loading-details').textContent = details;
		}

		/**
		 * Show error message
		 *
		 * @param {HTMLElement} ui - UI element
		 * @param {Error} error - Error object
		 */
		showError(ui, error) {
			ui.innerHTML = `
				<div class="loading-error">
					<div class="error-icon">⚠️</div>
					<div class="error-message">Failed to load model</div>
					<div class="error-details">${error.message}</div>
				</div>
			`;
		}
	}

	// Export to global scope
	if (typeof window !== 'undefined') {
		window.WP_MCP_AI_ProgressiveModelLoader = ProgressiveModelLoader;
	}

	// Also export as module if available
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = ProgressiveModelLoader;
	}
})();
