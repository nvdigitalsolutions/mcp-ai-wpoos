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
	 * Decode HTML entities in a string
	 * 
	 * This is needed because WordPress sanitizes meta fields with wp_kses_post()
	 * which converts characters like & to &amp;. When passed via wp_json_encode()
	 * to JavaScript, these HTML entities need to be decoded back to plain text.
	 * 
	 * @param {string} text - Text with potential HTML entities
	 * @return {string} Decoded text
	 */
	function decodeHtmlEntities(text) {
		if (!text || typeof text !== 'string') {
			return text;
		}
		
		// Create a temporary DOM element to leverage browser's built-in HTML entity decoding
		const textarea = document.createElement('textarea');
		textarea.innerHTML = text;
		return textarea.value;
	}

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
			const timeoutId = setTimeout(function() {
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
				const error = event.detail || new Error('Unknown error loading WebLLM');
				reject(error);
			}

			window.addEventListener('webllm-ready', onReady);
			window.addEventListener('webllm-error', onError);
		});
	}

	/**
	 * Available models for client-side inference
	 * Optimized for browser performance (quantized models)
	 * 
	 * Models with functionCalling: true support tool use and function calling
	 * Models with functionCalling: false are suitable for basic chat only
	 */
	const AVAILABLE_MODELS = {
		'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC': {
			name: 'Hermes 2 Pro Llama 3 8B',
			size: '~4.5GB',
			description: 'Best for function calling and tool use',
			contextWindow: 8192,
			recommended: true,
			functionCalling: true
		},
		'Qwen2.5-7B-Instruct-q4f16_1-MLC': {
			name: 'Qwen2.5 7B Instruct',
			size: '~4.5GB',
			description: 'Advanced multilingual model with function calling',
			contextWindow: 32768,
			recommended: false,
			functionCalling: true
		},
		'Phi-3.5-mini-instruct-q4f16_1-MLC': {
			name: 'Phi-3.5 Mini Instruct',
			size: '~2.5GB',
			description: 'Smaller Microsoft model, supports function calling',
			contextWindow: 128000,
			recommended: false,
			functionCalling: true
		},
		'Llama-3.2-3B-Instruct-q4f16_1-MLC': {
			name: 'Llama 3.2 3B Instruct',
			size: '~2GB',
			description: 'Balanced model for general chat (does not support function calling)',
			contextWindow: 131072,
			recommended: false,
			functionCalling: false
		},
		'Qwen2.5-1.5B-Instruct-q4f16_1-MLC': {
			name: 'Qwen2.5 1.5B Instruct',
			size: '~1GB',
			description: 'Compact multilingual model with function calling support',
			contextWindow: 32768,
			recommended: false,
			functionCalling: true
		},
		'Llama-3.2-1B-Instruct-q4f16_1-MLC': {
			name: 'Llama 3.2 1B Instruct',
			size: '~800MB',
			description: 'Fast, lightweight model for basic chat (does not support function calling)',
			contextWindow: 131072,
			recommended: false,
			functionCalling: false
		},
		'Qwen2.5-0.5B-Instruct-q4f16_1-MLC': {
			name: 'Qwen2.5 0.5B Instruct',
			size: '~400MB',
			description: 'Ultra-compact model for simple responses (does not support function calling)',
			contextWindow: 32768,
			recommended: false,
			functionCalling: false
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
		constructor(instanceId, config = {}) {
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
			// Decode HTML entities that may have been added by WordPress sanitization (wp_kses_post)
			this.systemPrompt = config.systemPrompt ? decodeHtmlEntities(config.systemPrompt) : null;
			this.tools = config.tools || [];
			this.memoryFiles = config.memoryFiles || [];
			this.vectorStoreId = config.vectorStoreId || null;
			
			// Computed configuration flags for easy checking
			// Use stored values (this.*) instead of config values for consistency
			// This ensures flags reflect the actual values that will be used later
			this.hasTools = this._hasValidTools(this.tools);
			this.hasKnowledge = this._hasValidKnowledge(this.memoryFiles, this.vectorStoreId);
			// Check decoded systemPrompt, not the original config value
			// This ensures we detect system prompts even after HTML entity decoding
			this.hasSystemPrompt = !!(this.systemPrompt && this.systemPrompt.trim());
			
			console.log('[NV oOS Embedded Client] Created new instance:', {
				instanceId: this.instanceId,
				hasSystemPrompt: this.hasSystemPrompt,
				systemPromptLength: this.systemPrompt ? this.systemPrompt.length : 0,
				systemPromptPreview: this.systemPrompt && this.systemPrompt.length > 150 ? this.systemPrompt.substring(0, 150) + '...' : this.systemPrompt || 'none',
				hasTools: this.hasTools,
				toolCount: this.tools ? this.tools.length : 0,
				hasKnowledge: this.hasKnowledge,
				memoryFileCount: this.memoryFiles ? this.memoryFiles.length : 0,
				hasVectorStore: !!this.vectorStoreId
			});
		}
		
		/**
		 * Check if tools configuration is valid and non-empty
		 * @private
		 */
		_hasValidTools(tools) {
			return !!(tools && Array.isArray(tools) && tools.length > 0);
		}
		
		/**
		 * Check if knowledge configuration is valid and non-empty
		 * @private
		 */
		_hasValidKnowledge(memoryFiles, vectorStoreId) {
			const hasMemoryFiles = !!(memoryFiles && Array.isArray(memoryFiles) && memoryFiles.length > 0);
			return hasMemoryFiles || !!vectorStoreId;
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

				// Initialize model context with system prompt and base knowledge if available
				// This primes the model with instructions before any user interaction
				await this.initializeModelContext();

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
		 * Build knowledge context message for system prompt enhancement
		 * 
		 * Creates a formatted message that informs the model about available
		 * knowledge base resources.
		 * 
		 * @private
		 * @returns {string} Formatted knowledge context message
		 */
		_buildKnowledgeContext() {
			let knowledgeContext = '\n\n## Base Knowledge\n\n';
			knowledgeContext += 'You have access to the following knowledge base:\n';
			
			if (this.memoryFiles && this.memoryFiles.length > 0) {
				knowledgeContext += '- ' + this.memoryFiles.length + ' file(s) in your knowledge base\n';
			}
			
			if (this.vectorStoreId) {
				knowledgeContext += '- Vector store ID: ' + this.vectorStoreId + '\n';
			}
			
			knowledgeContext += 'Use this knowledge to provide accurate and contextual responses.\n';
			
			return knowledgeContext;
		}

		/**
		 * DEPRECATED: This method is no longer needed.
		 * 
		 * Web-LLM follows OpenAI API patterns where system prompts must be sent
		 * with EVERY request, not initialized once. The API is stateless regarding
		 * system context - each chat.completions.create() call should include the
		 * full conversation history including system prompt.
		 * 
		 * Reference: https://github.com/mlc-ai/web-llm?tab=readme-ov-file#full-openai-compatibility
		 * 
		 * @private
		 * @deprecated This method is kept for backwards compatibility but does nothing
		 */
		async initializeModelContext() {
			console.log('[NV oOS Embedded Client] Model loaded - no initialization needed (OpenAI-compatible API):', {
				instanceId: this.instanceId,
				hasSystemPrompt: this.hasSystemPrompt,
				hasTools: this.hasTools,
				hasKnowledge: this.hasKnowledge,
				note: 'System prompt, tools, and knowledge will be included with each request'
			});
			// No-op: System prompts are sent with each request, not initialized once
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
				// Inject stored system prompt if caller did not include one, matching the
				// behaviour of generateStreamingCompletion so both paths behave consistently.
				const systemMessage = messages.find(msg => msg.role === 'system');
				if (!systemMessage && this.systemPrompt) {
					messages = [{ role: 'system', content: this.systemPrompt }].concat(messages);
				}

				const response = await this.currentEngine.chat.completions.create({
					messages: messages,
					temperature: options.temperature || 0.7,
					max_tokens: options.max_tokens || 512,
					top_p: options.top_p || 0.9,
					stream: false
				});

				const choice = response.choices[0];
				return {
					success: true,
					role: choice.message.role || 'assistant', // OpenAI-compatible
					content: choice.message.content,
					tool_calls: choice.message.tool_calls, // OpenAI-compatible: tool calls if present
					finish_reason: choice.finish_reason || 'stop', // OpenAI-compatible: why generation stopped
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
				console.log('[NV oOS Embedded Client] ===== STARTING STREAMING COMPLETION =====');
				console.log('[NV oOS Embedded Client] Request details:', {
					instanceId: this.instanceId,
					messageCount: messages.length,
					messageRoles: messages.map(function(m) { return m.role; }),
					temperature: options.temperature || 0.7,
					maxTokens: options.max_tokens || 512,
					hasTools: !!(options.tools && options.tools.length > 0)
				});
				
				// Diagnostic: Log system prompt configuration; inject stored prompt if caller omitted it.
				let systemMessage = messages.find(msg => msg.role === 'system');
				if (systemMessage) {
					console.log('[NV oOS Embedded Client] System prompt included in request (OpenAI-compatible):', {
						hasSystemPrompt: true,
						systemPromptLength: systemMessage.content.length,
						systemPromptPreview: systemMessage.content.length > 200 ? systemMessage.content.substring(0, 200) + '...' : systemMessage.content,
						instanceId: this.instanceId,
						note: 'System prompt must be sent with every request per OpenAI API pattern'
					});
				} else if (this.systemPrompt) {
					// Caller did not inject a system message but this client has a stored system prompt.
					// Inject it here as a safety net so it is always sent with every request.
					messages = [{ role: 'system', content: this.systemPrompt }].concat(messages);
					systemMessage = messages[0];
					console.log('[NV oOS Embedded Client] Injected stored system prompt (fallback):', {
						instanceId: this.instanceId,
						systemPromptLength: this.systemPrompt.length,
						systemPromptPreview: this.systemPrompt.length > 200 ? this.systemPrompt.substring(0, 200) + '...' : this.systemPrompt
					});
				} else {
					console.warn('[NV oOS Embedded Client] WARNING: No system prompt in messages for instance:', {
						instanceId: this.instanceId,
						messageCount: messages.length,
						note: 'System prompt should be included in messages array for each request'
					});
				}

				// IMPORTANT: Web-LLM follows OpenAI API patterns - it is STATELESS
				// System prompts, tools, and conversation history must be sent with EVERY request
				// DO NOT filter out system messages - they are required for proper context
				// Reference: https://github.com/mlc-ai/web-llm?tab=readme-ov-file#full-openai-compatibility
				
				// Build request payload with ALL messages (including system)
				const requestPayload = {
					messages: messages,
					temperature: options.temperature || 0.7,
					max_tokens: options.max_tokens || 512,
					top_p: options.top_p || 0.9,
					stream: true
				};
				
				// Add stream_options for usage data (OpenAI-compatible)
				// This ensures usage stats are included in the final chunk
				if (options.stream_options) {
					requestPayload.stream_options = options.stream_options;
				} else {
					// Default: include usage in streaming responses
					requestPayload.stream_options = { include_usage: true };
				}

				// Add tools if provided (Phase 2: Tool Support Implementation)
				// Use instance tools if not provided in options (TOOL FIX)
				const toolsToUse = (options.tools && Array.isArray(options.tools) && options.tools.length > 0) 
					? options.tools 
					: this.tools;
				
				if (toolsToUse && Array.isArray(toolsToUse) && toolsToUse.length > 0) {
					requestPayload.tools = toolsToUse;

					if (options.tool_choice) {
						requestPayload.tool_choice = options.tool_choice;
					}

					console.log('[NV oOS Embedded Client] Tools enabled for request:', {
						instanceId: this.instanceId,
						toolCount: toolsToUse.length,
						toolNames: toolsToUse.map(function(t) {
							return t.function ? t.function.name : 'unknown';
						}),
						source: options.tools ? 'options' : 'instance'
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
				const toolCalls = []; // NEW: Collect tool calls from streaming response
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

				// Extract usage data from last chunk if available (OpenAI-compatible)
				const usage = lastChunk && lastChunk.usage ? lastChunk.usage : {};
				
				// Extract finish_reason from last chunk (OpenAI-compatible)
				// Possible values: 'stop', 'length', 'tool_calls', 'content_filter'
				const finishReason = lastChunk && lastChunk.choices && lastChunk.choices[0] && lastChunk.choices[0].finish_reason
					? lastChunk.choices[0].finish_reason
					: (toolCalls.length > 0 ? 'tool_calls' : 'stop');

				const result = {
					success: true,
					role: 'assistant', // OpenAI-compatible: always 'assistant' for completions
					content: fullContent,
					tool_calls: toolCalls.length > 0 ? toolCalls : undefined, // NEW: Include tool calls if present
					finish_reason: finishReason, // OpenAI-compatible: why generation stopped
					usage: usage,
					done: true
				};

				console.log('[NV oOS Embedded Client] Returning final result for instance:', {
					instanceId: this.instanceId,
					success: result.success,
					role: result.role,
					contentLength: result.content.length,
					hasToolCalls: !!result.tool_calls,
					toolCallsCount: result.tool_calls ? result.tool_calls.length : 0,
					finishReason: result.finish_reason,
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
