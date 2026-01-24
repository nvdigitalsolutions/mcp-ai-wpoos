/**
 * Client-Side Embedded LLM using WebLLM
 * 
 * Runs LLM models entirely in the browser using WebGPU/WebAssembly.
 * No server-side dependencies required - fully private and local.
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

(function() {
	'use strict';

	// WebLLM will be loaded via CDN or bundled
	let webLLM = null;
	let currentEngine = null;
	let isInitializing = false;
	let modelLoaded = false;

	/**
	 * Available models for client-side inference
	 * Optimized for browser performance (quantized, small models)
	 */
	const AVAILABLE_MODELS = {
		'Llama-3.2-1B-Instruct-q4f16_1-MLC': {
			name: 'Llama 3.2 1B Instruct',
			size: '~800MB',
			description: 'Fast, efficient model for general tasks',
			contextWindow: 128000,
			recommended: true
		},
		'Llama-3.2-3B-Instruct-q4f16_1-MLC': {
			name: 'Llama 3.2 3B Instruct',
			size: '~2GB',
			description: 'Balanced quality and performance',
			contextWindow: 128000,
			recommended: false
		},
		'Phi-3.5-mini-instruct-q4f16_1-MLC': {
			name: 'Phi-3.5 Mini Instruct',
			size: '~2.5GB',
			description: 'Microsoft model, good reasoning',
			contextWindow: 128000,
			recommended: false
		},
		'Qwen2.5-0.5B-Instruct-q4f16_1-MLC': {
			name: 'Qwen2.5 0.5B Instruct',
			size: '~400MB',
			description: 'Ultra-fast, minimal resource usage',
			contextWindow: 32768,
			recommended: false
		},
		'Qwen2.5-1.5B-Instruct-q4f16_1-MLC': {
			name: 'Qwen2.5 1.5B Instruct',
			size: '~1GB',
			description: 'Efficient multilingual model',
			contextWindow: 32768,
			recommended: false
		}
	};

	/**
	 * Initialize WebLLM library
	 */
	async function initializeWebLLM() {
		if (webLLM) {
			return true;
		}

		try {
			// Try to load from global scope (CDN)
			if (typeof window.webllm !== 'undefined') {
				webLLM = window.webllm;
				return true;
			}

			// Try dynamic import for bundled version
			if (typeof window.mlcWebLLM !== 'undefined') {
				webLLM = window.mlcWebLLM;
				return true;
			}

			console.error('WebLLM library not found. Please ensure it is loaded.');
			return false;
		} catch (error) {
			console.error('Error initializing WebLLM:', error);
			return false;
		}
	}

	/**
	 * Check if WebGPU is supported
	 */
	async function checkWebGPUSupport() {
		if (!navigator.gpu) {
			return {
				supported: false,
				message: 'WebGPU is not supported in this browser. Try Chrome, Edge, or Safari (macOS).'
			};
		}

		try {
			const adapter = await navigator.gpu.requestAdapter();
			if (!adapter) {
				return {
					supported: false,
					message: 'WebGPU adapter not available. Your GPU may not be supported.'
				};
			}

			return {
				supported: true,
				adapter: adapter.name || 'Unknown GPU'
			};
		} catch (error) {
			return {
				supported: false,
				message: 'Error checking WebGPU support: ' + error.message
			};
		}
	}

	/**
	 * Load and initialize a model
	 * 
	 * @param {string} modelId Model identifier
	 * @param {Function} progressCallback Progress update callback
	 */
	async function loadModel(modelId, progressCallback) {
		if (isInitializing) {
			throw new Error('Model is already being initialized');
		}

		if (!AVAILABLE_MODELS[modelId]) {
			throw new Error('Invalid model ID: ' + modelId);
		}

		isInitializing = true;
		modelLoaded = false;

		try {
			// Initialize WebLLM if not already done
			const initialized = await initializeWebLLM();
			if (!initialized) {
				throw new Error('Failed to initialize WebLLM library');
			}

			// Check WebGPU support
			const gpuSupport = await checkWebGPUSupport();
			if (!gpuSupport.supported) {
				throw new Error(gpuSupport.message);
			}

			// Progress callback setup
			const initProgressCallback = (progress) => {
				if (progressCallback) {
					progressCallback({
						text: progress.text || '',
						progress: progress.progress || 0
					});
				}
			};

			// Create engine with progress tracking
			currentEngine = await webLLM.CreateMLCEngine(
				modelId,
				{
					initProgressCallback: initProgressCallback,
					logLevel: 'INFO'
				}
			);

			modelLoaded = true;
			isInitializing = false;

			return {
				success: true,
				model: modelId,
				modelName: AVAILABLE_MODELS[modelId].name
			};

		} catch (error) {
			isInitializing = false;
			modelLoaded = false;
			throw error;
		}
	}

	/**
	 * Unload current model and free memory
	 */
	async function unloadModel() {
		if (currentEngine) {
			try {
				await currentEngine.unload();
				currentEngine = null;
				modelLoaded = false;
				return true;
			} catch (error) {
				console.error('Error unloading model:', error);
				return false;
			}
		}
		return true;
	}

	/**
	 * Generate chat completion
	 * 
	 * @param {Array} messages Array of message objects [{role: 'user', content: '...'}]
	 * @param {Object} options Generation options
	 */
	async function generateCompletion(messages, options = {}) {
		if (!modelLoaded || !currentEngine) {
			throw new Error('No model is currently loaded. Please load a model first.');
		}

		try {
			const response = await currentEngine.chat.completions.create({
				messages: messages,
				temperature: options.temperature || 0.7,
				max_tokens: options.max_tokens || 512,
				top_p: options.top_p || 0.9,
				stream: false
			});

			return {
				success: true,
				content: response.choices[0].message.content,
				usage: response.usage || {}
			};

		} catch (error) {
			throw new Error('Generation failed: ' + error.message);
		}
	}

	/**
	 * Generate streaming chat completion
	 * 
	 * @param {Array} messages Array of message objects
	 * @param {Object} options Generation options
	 * @param {Function} onChunk Callback for each chunk
	 */
	async function generateStreamingCompletion(messages, options = {}, onChunk) {
		if (!modelLoaded || !currentEngine) {
			throw new Error('No model is currently loaded. Please load a model first.');
		}

		try {
			const asyncChunkGenerator = await currentEngine.chat.completions.create({
				messages: messages,
				temperature: options.temperature || 0.7,
				max_tokens: options.max_tokens || 512,
				top_p: options.top_p || 0.9,
				stream: true
			});

			let fullContent = '';

			for await (const chunk of asyncChunkGenerator) {
				const delta = chunk.choices[0]?.delta?.content || '';
				if (delta) {
					fullContent += delta;
					if (onChunk) {
						onChunk({
							content: delta,
							fullContent: fullContent,
							done: false
						});
					}
				}
			}

			if (onChunk) {
				onChunk({
					content: '',
					fullContent: fullContent,
					done: true
				});
			}

			return {
				success: true,
				content: fullContent
			};

		} catch (error) {
			throw new Error('Streaming generation failed: ' + error.message);
		}
	}

	/**
	 * Get runtime stats
	 */
	async function getRuntimeStats() {
		if (!currentEngine) {
			return null;
		}

		try {
			const stats = await currentEngine.runtimeStatsText();
			return stats;
		} catch (error) {
			console.error('Error getting runtime stats:', error);
			return null;
		}
	}

	/**
	 * Enhanced error categorization for better user feedback
	 * Based on industry best practices for WebLLM error handling
	 * 
	 * @param {Error} error Original error object
	 * @returns {Object} Categorized error with user-friendly message
	 */
	function categorizeError(error) {
		const errorMessage = error.message || error.toString();
		const errorCategories = {
			MEMORY_ERROR: {
				message: 'Your device is running low on memory. Try closing other tabs or using a smaller model.',
				action: 'Switch to Lightweight Model',
				recoverable: true,
				technicalCategory: 'memory'
			},
			GPU_UNSUPPORTED: {
				message: 'Your browser doesn\'t support WebGPU. Please update to the latest version or try Chrome, Edge, or Safari.',
				action: 'Learn More',
				recoverable: false,
				technicalCategory: 'compatibility'
			},
			NETWORK_ERROR: {
				message: 'Model download failed. Check your internet connection and try again.',
				action: 'Retry Download',
				recoverable: true,
				technicalCategory: 'network'
			},
			MODEL_LOAD_ERROR: {
				message: 'Failed to initialize the AI model. This may be a temporary issue.',
				action: 'Retry',
				recoverable: true,
				technicalCategory: 'initialization'
			},
			INITIALIZATION_ERROR: {
				message: 'WebLLM library failed to initialize. Please refresh the page.',
				action: 'Refresh Page',
				recoverable: true,
				technicalCategory: 'initialization'
			}
		};

		// Detect error type from message patterns
		if (/memory|out of memory|OOM|heap/i.test(errorMessage)) {
			return errorCategories.MEMORY_ERROR;
		} else if (/gpu|webgpu|adapter|not supported/i.test(errorMessage)) {
			return errorCategories.GPU_UNSUPPORTED;
		} else if (/network|fetch|download|connection/i.test(errorMessage)) {
			return errorCategories.NETWORK_ERROR;
		} else if (/initialize|init|webllm library/i.test(errorMessage)) {
			return errorCategories.INITIALIZATION_ERROR;
		}

		// Default to generic model load error
		return errorCategories.MODEL_LOAD_ERROR;
	}

	/**
	 * Check device capabilities for model suitability
	 * Helps prevent out-of-memory errors by warning users
	 * 
	 * @param {string} modelId Model identifier
	 * @returns {Object} Suitability assessment
	 */
	function checkModelSuitability(modelId) {
		const model = AVAILABLE_MODELS[modelId];
		if (!model) {
			return { suitable: false, warning: 'Unknown model' };
		}

		// Parse model size (e.g., "~800MB" -> 800)
		const sizeMatch = model.size.match(/(\d+(?:\.\d+)?)\s*([KMG]B)/i);
		let sizeInMB = 0;
		if (sizeMatch) {
			const value = parseFloat(sizeMatch[1]);
			const unit = sizeMatch[2].toUpperCase();
			if (unit === 'GB') {
				sizeInMB = value * 1024;
			} else if (unit === 'MB') {
				sizeInMB = value;
			}
		}

		// Check device memory if available (not supported in all browsers)
		const deviceMemoryGB = navigator.deviceMemory || 4; // Default to 4GB if not available
		const isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent);

		// Rule of thumb: model should be < 25% of device RAM
		// Mobile devices need more headroom
		const maxRecommendedSizeMB = (deviceMemoryGB * 1024) * (isMobile ? 0.15 : 0.25);

		if (sizeInMB > maxRecommendedSizeMB) {
			// Find a more suitable model
			let suggestedModel = null;
			for (const [id, modelData] of Object.entries(AVAILABLE_MODELS)) {
				const suggestedSizeMatch = modelData.size.match(/(\d+(?:\.\d+)?)\s*([KMG]B)/i);
				if (suggestedSizeMatch) {
					const suggestedValue = parseFloat(suggestedSizeMatch[1]);
					const suggestedUnit = suggestedSizeMatch[2].toUpperCase();
					let suggestedSizeInMB = 0;
					if (suggestedUnit === 'GB') {
						suggestedSizeInMB = suggestedValue * 1024;
					} else if (suggestedUnit === 'MB') {
						suggestedSizeInMB = suggestedValue;
					}

					if (suggestedSizeInMB <= maxRecommendedSizeMB && suggestedSizeInMB > 0) {
						suggestedModel = { id: id, name: modelData.name, size: modelData.size };
						break;
					}
				}
			}

			return {
				suitable: false,
				warning: 'This model (' + model.size + ') may be too large for your device (' + deviceMemoryGB + 'GB RAM). Consider using a smaller model for better performance.',
				suggestedModel: suggestedModel,
				deviceMemoryGB: deviceMemoryGB,
				isMobile: isMobile
			};
		}

		return {
			suitable: true,
			deviceMemoryGB: deviceMemoryGB,
			isMobile: isMobile
		};
	}

	// Export public API
	window.WP_MCP_AI_EmbeddedLLM = {
		// Model management
		availableModels: AVAILABLE_MODELS,
		loadModel: loadModel,
		unloadModel: unloadModel,
		isModelLoaded: () => modelLoaded,
		getCurrentModel: () => currentEngine ? 'loaded' : null,

		// Generation
		generateCompletion: generateCompletion,
		generateStreamingCompletion: generateStreamingCompletion,

		// Utilities
		checkWebGPUSupport: checkWebGPUSupport,
		getRuntimeStats: getRuntimeStats,
		
		// Enhanced error handling (best practices)
		categorizeError: categorizeError,
		checkModelSuitability: checkModelSuitability
	};

})();
