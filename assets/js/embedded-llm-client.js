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
		getRuntimeStats: getRuntimeStats
	};

})();
