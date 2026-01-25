/**
 * Client-Side Embedded LLM using WebLLM
 * 
 * Runs LLM models entirely in the browser using WebGPU/WebAssembly.
 * No server-side dependencies required - fully private and local.
 * 
 * Refactored to support multiple instances per page (one per chat widget).
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

(function() {
	'use strict';

	// Global WebLLM library state (loaded once, shared across all instances)
	let webLLM = null;
	let webLLMReady = false;

	// Logging configuration
	const CHUNK_LOG_FREQUENCY = 5; // Log every Nth chunk to avoid console spam

	/**
	 * Wait for WebLLM to be loaded
	 * WebLLM is loaded asynchronously via dynamic import()
	 * 
	 * Best Practice: Use event-based waiting for async module loading.
	 * Reference: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/import
	 */
	function waitForWebLLM() {
		return new Promise(function(resolve, reject) {
			// Check if already loaded.
			if (window.webLLM) {
				webLLM = window.webLLM;
				webLLMReady = true;
				resolve(webLLM);
				return;
			}

			// Check if there was an error loading.
			if (window.wpMcpAiWebLLMError) {
				reject(new Error('WebLLM failed to load: ' + window.wpMcpAiWebLLMError.message));
				return;
			}

			// Set up timeout (30 seconds).
			var timeoutId = setTimeout(function() {
				reject(new Error('Timeout waiting for WebLLM to load. Check your internet connection and browser console for errors.'));
			}, 30000);

			// Wait for the webllm-ready event.
			function onReady() {
				clearTimeout(timeoutId);
				webLLM = window.webLLM;
				webLLMReady = true;
				window.removeEventListener('webllm-ready', onReady);
				window.removeEventListener('webllm-error', onError);
				resolve(webLLM);
			}

			// Wait for error event.
			function onError(event) {
				clearTimeout(timeoutId);
				window.removeEventListener('webllm-ready', onReady);
				window.removeEventListener('webllm-error', onError);
				var error = event.detail || new Error('Unknown error loading WebLLM');
				reject(error);
			}

			window.addEventListener('webllm-ready', onReady);
			window.addEventListener('webllm-error', onError);
		});
	}

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
	 * Initialize WebLLM library (global, called once)
	 * Waits for WebLLM to be loaded via dynamic import
	 */
	async function initializeWebLLM() {
		if (webLLMReady && webLLM) {
			return true;
		}

		try {
			// Wait for WebLLM to be loaded by the loader script.
			await waitForWebLLM();
			return true;
		} catch (error) {
			console.error('[NV oOS] Error initializing WebLLM:', error);
			return false;
		}
	}

	/**
	 * Check if WebGPU is supported (static utility)
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
	 * EmbeddedLLMClient Class
	 * Instance-based client that can be created per chat widget
	 */
	class EmbeddedLLMClient {
		constructor(instanceId, config) {
			// Validate and generate instance ID
			if (!instanceId || typeof instanceId !== 'string' || instanceId.trim() === '') {
				// Generate unique ID if not provided or invalid
				// Format: embedded-{timestamp}-{random9chars}
				this.instanceId = 'embedded-' + Date.now() + '-' + Math.random().toString(36).slice(2, 11);
				console.warn('[NV oOS Embedded Client] No valid instanceId provided, generated:', this.instanceId);
			} else {
				this.instanceId = instanceId;
			}
			
			this.currentEngine = null;
			this.isInitializing = false;
			this.modelLoaded = false;
			this.currentModelId = null;
			
			// Store assistant configuration (system prompt, tools, knowledge)
			// This ensures the instance has access to its configuration throughout the session
			config = config || {};
			this.systemPrompt = config.systemPrompt || null;
			this.tools = config.tools || [];
			this.memoryFiles = config.memoryFiles || [];
			this.vectorStoreId = config.vectorStoreId || null;
			this.hasTools = !!(config.tools && Array.isArray(config.tools) && config.tools.length > 0);
			this.hasKnowledge = !!(config.memoryFiles && Array.isArray(config.memoryFiles) && config.memoryFiles.length > 0) || !!config.vectorStoreId;
			this.hasSystemPrompt = !!config.systemPrompt;
			
			console.log('[NV oOS Embedded Client] Created new instance:', this.instanceId);
		}

		/**
		 * Wait for WebLLM library to be ready
		 */
		async waitForReady() {
			return waitForWebLLM();
		}

		/**
		 * Check if WebLLM is ready
		 */
		isReady() {
			return webLLMReady && webLLM !== null;
		}

		/**
		 * Load and initialize a model for this instance
		 * 
		 * @param {string} modelId Model identifier
		 * @param {Function} progressCallback Progress update callback
		 */
		async loadModel(modelId, progressCallback) {
			if (this.isInitializing) {
				throw new Error('Model is already being initialized for instance ' + this.instanceId);
			}

			if (!AVAILABLE_MODELS[modelId]) {
				throw new Error('Invalid model ID: ' + modelId);
			}

			console.log('[NV oOS Embedded Client] Loading model for instance:', {
				instanceId: this.instanceId,
				modelId: modelId
			});

			this.isInitializing = true;
			this.modelLoaded = false;

			try {
				// Initialize WebLLM if not already done (global)
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

				// Create engine with progress tracking for this instance
				this.currentEngine = await webLLM.CreateMLCEngine(
					modelId,
					{
						initProgressCallback: initProgressCallback,
						logLevel: 'INFO'
					}
				);

				this.modelLoaded = true;
				this.isInitializing = false;
				this.currentModelId = modelId;

				console.log('[NV oOS Embedded Client] Model loaded successfully for instance:', {
					instanceId: this.instanceId,
					modelId: modelId
				});

				return {
					success: true,
					model: modelId,
					modelName: AVAILABLE_MODELS[modelId].name
				};

			} catch (error) {
				this.isInitializing = false;
				this.modelLoaded = false;
				console.error('[NV oOS Embedded Client] Model load failed for instance:', this.instanceId, error);
				throw error;
			}
		}

		/**
		 * Unload current model and free memory for this instance
		 */
		async unloadModel() {
			if (this.currentEngine) {
				try {
					console.log('[NV oOS Embedded Client] Unloading model for instance:', this.instanceId);
					await this.currentEngine.unload();
					this.currentEngine = null;
					this.modelLoaded = false;
					this.currentModelId = null;
					return true;
				} catch (error) {
					console.error('[NV oOS Embedded Client] Error unloading model for instance:', this.instanceId, error);
					return false;
				}
			}
			return true;
		}

		/**
		 * Check if a model is currently loaded
		 */
		isModelLoaded() {
			return this.modelLoaded;
		}

		/**
		 * Get current model ID
		 */
		getCurrentModel() {
			return this.currentModelId;
		}

		/**
		 * Generate chat completion (non-streaming)
		 * 
		 * @param {Array} messages Array of message objects [{role: 'user', content: '...'}]
		 * @param {Object} options Generation options
		 */
		async generateCompletion(messages, options = {}) {
			if (!this.modelLoaded || !this.currentEngine) {
				throw new Error('No model is currently loaded. Please load a model first.');
			}

			try {
				const response = await this.currentEngine.chat.completions.create({
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
		async generateStreamingCompletion(messages, options = {}, onChunk) {
			if (!this.modelLoaded || !this.currentEngine) {
				throw new Error('No model is currently loaded. Please load a model first.');
			}

			try {
				// Diagnostic: Log system prompt configuration (PR #3197)
				const systemMessage = messages.find(msg => msg.role === 'system');
				if (systemMessage) {
					console.log('[NV oOS Embedded Client] System prompt detected:', {
						hasSystemPrompt: true,
						systemPromptLength: systemMessage.content.length,
						systemPromptPreview: systemMessage.content.substring(0, 100) + '...',
						instanceId: this.instanceId
					});
				} else {
					console.warn('[NV oOS Embedded Client] No system prompt in messages for instance:', this.instanceId);
				}

				// Build request payload
				const requestPayload = {
					messages: messages,
					temperature: options.temperature || 0.7,
					max_tokens: options.max_tokens || 512,
					top_p: options.top_p || 0.9,
					stream: true
				};

				// Add tools if provided (Phase 2: Tool Support Implementation)
				if (options.tools && Array.isArray(options.tools) && options.tools.length > 0) {
					requestPayload.tools = options.tools;

					if (options.tool_choice) {
						requestPayload.tool_choice = options.tool_choice;
					}

					console.log('[NV oOS Embedded Client] Tools enabled for request:', {
						instanceId: this.instanceId,
						toolCount: options.tools.length,
						toolNames: options.tools.map(function(t) {
							return t.function ? t.function.name : 'unknown';
						})
					});
				}

				// Log streaming start
				console.log('[NV oOS Embedded Client] Starting streaming completion for instance:', {
					instanceId: this.instanceId,
					messageCount: messages.length,
					temperature: requestPayload.temperature,
					maxTokens: requestPayload.max_tokens,
					hasTools: !!(requestPayload.tools && requestPayload.tools.length > 0)
				});

				const asyncChunkGenerator = await this.currentEngine.chat.completions.create(requestPayload);

				let fullContent = '';
				let toolCalls = []; // NEW: Collect tool calls from streaming response
				let lastChunk = null;
				let chunkCount = 0;

				for await (const chunk of asyncChunkGenerator) {
					lastChunk = chunk; // Keep track of last chunk for usage data
					const delta = chunk.choices[0]?.delta?.content || '';

					// Handle tool calls (Phase 2: Tool Support Implementation)
					// WebLLM may stream tool calls incrementally
					if (chunk.choices[0]?.delta?.tool_calls) {
						const toolCallDelta = chunk.choices[0].delta.tool_calls;

						toolCallDelta.forEach(function(tc) {
							const index = tc.index || 0;

							if (!toolCalls[index]) {
								// Initialize new tool call
								toolCalls[index] = {
									id: tc.id || 'call_' + Date.now() + '_' + index,
									type: 'function',
									function: {
										name: tc.function?.name || '',
										arguments: tc.function?.arguments || ''
									}
								};
							} else {
								// Append to existing tool call (streaming)
								if (tc.function?.name) {
									toolCalls[index].function.name += tc.function.name;
								}
								if (tc.function?.arguments) {
									toolCalls[index].function.arguments += tc.function.arguments;
								}
							}
						});

						console.log('[NV oOS Embedded Client] Tool call delta received:', {
							instanceId: this.instanceId,
							toolCallsCount: toolCalls.length
						});
					}

					// Handle text content as before
					if (delta) {
						chunkCount++;
						fullContent += delta;

						// Log chunk received at configurable frequency (initial chunks + every Nth)
						if (chunkCount <= CHUNK_LOG_FREQUENCY || chunkCount % CHUNK_LOG_FREQUENCY === 0) {
							console.log('[NV oOS Embedded Client] Chunk received for instance:', {
								instanceId: this.instanceId,
								chunkNumber: chunkCount,
								deltaLength: delta.length,
								totalLength: fullContent.length
							});
						}

						if (onChunk) {
							onChunk({
								content: delta,
								fullContent: fullContent,
								done: false
							});
						}
					}
				}

				// Log completion
				console.log('[NV oOS Embedded Client] Streaming completed for instance:', {
					instanceId: this.instanceId,
					totalChunks: chunkCount,
					contentLength: fullContent.length,
					toolCallsCount: toolCalls.length,
					hasUsage: !!(lastChunk && lastChunk.usage)
				});

				if (onChunk) {
					console.log('[NV oOS Embedded Client] Calling onChunk with done=true for instance:', this.instanceId);
					onChunk({
						content: '',
						fullContent: fullContent,
						done: true
					});
				}

				// Extract usage data from last chunk if available
				const usage = lastChunk && lastChunk.usage ? lastChunk.usage : {};

				const result = {
					success: true,
					content: fullContent,
					tool_calls: toolCalls.length > 0 ? toolCalls : undefined, // NEW: Include tool calls if present
					usage: usage,
					done: true
				};

				console.log('[NV oOS Embedded Client] Returning final result for instance:', {
					instanceId: this.instanceId,
					success: result.success,
					contentLength: result.content.length,
					hasToolCalls: !!result.tool_calls,
					toolCallsCount: result.tool_calls ? result.tool_calls.length : 0,
					usageData: result.usage
				});

				return result;

			} catch (error) {
				console.error('[NV oOS Embedded Client] Streaming generation failed for instance:', this.instanceId, error);
				throw new Error('Streaming generation failed: ' + error.message);
			}
		}

		/**
		 * Get runtime stats for this instance
		 */
		async getRuntimeStats() {
			if (!this.currentEngine) {
				return null;
			}

			try {
				const stats = await this.currentEngine.runtimeStatsText();
				return stats;
			} catch (error) {
				console.error('[NV oOS Embedded Client] Error getting runtime stats for instance:', this.instanceId, error);
				return null;
			}
		}
	}

	/**
	 * Enhanced error categorization for better user feedback
	 * Based on industry best practices for WebLLM error handling
	 * Static utility function
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
	 * Device memory limits for model suitability checks.
	 * 
	 * These constants define safe memory usage thresholds to prevent out-of-memory errors.
	 * Based on industry best practices for in-browser LLM execution.
	 */
	const DEVICE_MEMORY_DEFAULTS = {
		// Conservative default for devices without memory detection API
		// Most modern devices have at least 2GB RAM, but we default to 4GB
		// to avoid unnecessary warnings on capable devices
		DEFAULT_GB: 4,
		
		// Mobile devices need more memory headroom due to:
		// - OS overhead and background apps
		// - Less aggressive memory management
		// - More frequent multitasking
		// Recommends models up to 15% of device RAM
		MOBILE_THRESHOLD: 0.15,
		
		// Desktop devices can handle larger models due to:
		// - More RAM available
		// - Better memory management
		// - Less background pressure
		// Recommends models up to 25% of device RAM
		DESKTOP_THRESHOLD: 0.25
	};

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
		const deviceMemoryGB = navigator.deviceMemory || DEVICE_MEMORY_DEFAULTS.DEFAULT_GB;
		const isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent);

		// Use appropriate threshold based on device type
		const threshold = isMobile ? 
			DEVICE_MEMORY_DEFAULTS.MOBILE_THRESHOLD : 
			DEVICE_MEMORY_DEFAULTS.DESKTOP_THRESHOLD;
		const maxRecommendedSizeMB = (deviceMemoryGB * 1024) * threshold;

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
	// Provide both class for creating instances and legacy singleton-style API for backward compatibility
	window.WP_MCP_AI_EmbeddedLLM = EmbeddedLLMClient;
	
	// Static utilities attached to the class
	window.WP_MCP_AI_EmbeddedLLM.availableModels = AVAILABLE_MODELS;
	window.WP_MCP_AI_EmbeddedLLM.checkWebGPUSupport = checkWebGPUSupport;
	window.WP_MCP_AI_EmbeddedLLM.categorizeError = categorizeError;
	window.WP_MCP_AI_EmbeddedLLM.checkModelSuitability = checkModelSuitability;
	window.WP_MCP_AI_EmbeddedLLM.waitForWebLLM = waitForWebLLM;
	window.WP_MCP_AI_EmbeddedLLM.isWebLLMReady = function() {
		return webLLMReady && webLLM !== null;
	};

})();
