/**
 * WebLLM Function Calling Client
 * 
 * Thin wrapper around WebLLM for tool calling support.
 * NO BUNDLED DEPENDENCIES - uses CDN-loaded @mlc-ai/web-llm
 * 
 * Extends existing EmbeddedLLMClient (embedded-llm-client.js) with tool calling.
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
	'use strict';
	
	/**
	 * Wait for dependencies to load
	 */
	function waitForDependencies() {
		return new Promise( function( resolve, reject ) {
			var checkInterval = setInterval( function() {
				if ( window.WP_MCP_AI_EmbeddedLLM && window.WP_MCP_AI_ToolAdapter ) {
					clearInterval( checkInterval );
					resolve();
				}
			}, 100 );
			
			// Timeout after 30 seconds
			setTimeout( function() {
				clearInterval( checkInterval );
				reject( new Error( 'Timeout waiting for dependencies' ) );
			}, 30000 );
		} );
	}
	
	/**
	 * Enhanced WebLLM client with tool calling support
	 * Extends existing EmbeddedLLMClient
	 */
	class WebLLMFunctionCallingClient extends window.WP_MCP_AI_EmbeddedLLM {
		constructor( instanceId, config = {} ) {
			// Pass config to parent constructor to store assistant configuration
			super( instanceId, config );
			this.toolAdapter = window.WP_MCP_AI_ToolAdapter;
			this.availableTools = [];
			console.log( '[NV oOS WebLLM Function Calling] Client created:', instanceId );
		}
		
		/**
		 * Load available tools from WordPress
		 */
		async loadTools() {
			try {
				console.log( '[NV oOS WebLLM] Loading available tools...' );
				var tools = await this.toolAdapter.fetchTools();
				this.availableTools = tools;
				console.log( '[NV oOS WebLLM] Loaded ' + tools.length + ' tools' );
				return tools;
			} catch ( error ) {
				console.error( '[NV oOS WebLLM] Failed to load tools:', error );
				return [];
			}
		}
		
		/**
		 * Chat with tool calling support
		 * 
		 * @param {Array} messages - Chat messages
		 * @param {Array} tools - WordPress tools (optional, uses loaded tools if not provided)
		 * @param {Object} options - Generation options
		 */
		async chatWithTools( messages, tools, options ) {
			options = options || {};
			
			if ( ! this.modelLoaded || ! this.currentEngine ) {
				throw new Error( 'Model not loaded. Please load a model first.' );
			}
			
			// Diagnostic: Log system prompt configuration
			var systemMessage = messages.find( function( msg ) {
				return msg.role === 'system';
			} );
			
			if ( systemMessage ) {
				console.log( '[NV oOS WebLLM] System prompt detected:', {
					hasSystemPrompt: true,
					systemPromptLength: systemMessage.content.length,
					systemPromptPreview: systemMessage.content.substring( 0, 100 ) + '...',
					instanceId: this.instanceId
				} );
			} else {
				console.warn( '[NV oOS WebLLM] No system prompt in messages for instance:', this.instanceId );
			}
			
			// Use provided tools or loaded tools
			var toolsToUse = tools || this.availableTools;
			if ( toolsToUse.length === 0 ) {
				console.warn( '[NV oOS WebLLM] No tools available, loading...' );
				toolsToUse = await this.loadTools();
			}
			
			// Convert WordPress tools to OpenAI function format
			var formattedTools = this.toolAdapter.convertTools( toolsToUse );
			
			console.log( '[NV oOS WebLLM] Starting chat with tools:', {
				toolCount: formattedTools.length,
				messageCount: messages.length,
				hasSystemPrompt: !! systemMessage,
				instanceId: this.instanceId,
				temperature: options.temperature || 0.7,
				maxTokens: options.max_tokens || 512
			} );
			
			try {
				var response = await this.currentEngine.chat.completions.create( {
					messages: messages,
					tools: formattedTools,
					tool_choice: options.tool_choice || 'auto',
					temperature: options.temperature || 0.7,
					max_tokens: options.max_tokens || 512,
					stream: true
				} );
				
				return this.processToolStream( response, options.onChunk );
			} catch ( error ) {
				console.error( '[NV oOS WebLLM] Chat with tools failed:', error );
				throw error;
			}
		}
		
		/**
		 * Process streaming response with tool calls
		 * 
		 * @param {AsyncIterable} stream - WebLLM streaming response
		 * @param {Function} onChunk - Callback for each chunk
		 * @returns {AsyncGenerator} Generator yielding chunks
		 */
		async *processToolStream( stream, onChunk ) {
			var contentBuffer = '';
			var toolCallsBuffer = [];
			var chunkCount = 0;
			
			try {
				for await ( var chunk of stream ) {
					chunkCount++;
					var delta = chunk.choices && chunk.choices[0] && chunk.choices[0].delta;
					
					if ( ! delta ) {
						continue;
					}
					
					// Handle content
					if ( delta.content ) {
						contentBuffer += delta.content;
						if ( onChunk ) {
							onChunk( { type: 'content', data: delta.content } );
						}
						yield { type: 'content', data: delta.content };
					}
					
					// Handle tool calls
					if ( delta.tool_calls ) {
						this.bufferToolCalls( toolCallsBuffer, delta.tool_calls );
						if ( onChunk ) {
							onChunk( { type: 'tool_call', data: delta.tool_calls } );
						}
						yield { type: 'tool_call', data: delta.tool_calls };
					}
				}
				
				console.log( '[NV oOS WebLLM] Stream completed:', {
					chunks: chunkCount,
					contentLength: contentBuffer.length,
					toolCalls: toolCallsBuffer.length
				} );
				
				// Return final result
				yield {
					type: 'done',
					content: contentBuffer,
					tool_calls: toolCallsBuffer.length > 0 ? toolCallsBuffer : undefined,
					chunks: chunkCount
				};
				
			} catch ( error ) {
				console.error( '[NV oOS WebLLM] Stream processing error:', error );
				throw error;
			}
		}
		
		/**
		 * Buffer streaming tool calls
		 * 
		 * @param {Array} buffer - Tool calls buffer
		 * @param {Array} toolCallDeltas - Streaming tool call deltas
		 */
		bufferToolCalls( buffer, toolCallDeltas ) {
			var self = this;
			toolCallDeltas.forEach( function( delta ) {
				var index = delta.index || 0;
				
				if ( ! buffer[index] ) {
					buffer[index] = {
						id: delta.id || 'call_' + Date.now() + '_' + index,
						type: 'function',
						function: {
							name: delta.function && delta.function.name || '',
							arguments: delta.function && delta.function.arguments || ''
						}
					};
				} else {
					if ( delta.function && delta.function.name ) {
						buffer[index].function.name += delta.function.name;
					}
					if ( delta.function && delta.function.arguments ) {
						buffer[index].function.arguments += delta.function.arguments;
					}
				}
			} );
		}
		
		/**
		 * Execute tool calls
		 * 
		 * @param {Array} toolCalls - Tool calls from model
		 * @returns {Promise<Array>} Tool execution results
		 */
		async executeToolCalls( toolCalls ) {
			var self = this;
			var results = [];
			
			console.log( '[NV oOS WebLLM] Executing ' + toolCalls.length + ' tool calls' );
			
			for ( var i = 0; i < toolCalls.length; i++ ) {
				var toolCall = toolCalls[i];
				try {
					var args = JSON.parse( toolCall.function.arguments );
					var result = await this.toolAdapter.executeTool( toolCall.function.name, args );
					
					results.push( {
						tool_call_id: toolCall.id,
						role: 'tool',
						name: toolCall.function.name,
						content: JSON.stringify( result )
					} );
					
					console.log( '[NV oOS WebLLM] Tool executed successfully:', toolCall.function.name );
				} catch ( error ) {
					console.error( '[NV oOS WebLLM] Tool execution failed:', toolCall.function.name, error );
					results.push( {
						tool_call_id: toolCall.id,
						role: 'tool',
						name: toolCall.function.name,
						content: JSON.stringify( { error: error.message } )
					} );
				}
			}
			
			return results;
		}
	}
	
	// Initialize when dependencies are ready
	waitForDependencies().then( function() {
		// Export to global scope
		window.WP_MCP_AI_WebLLM_FunctionCalling = WebLLMFunctionCallingClient;
		
		// Dispatch ready event
		if ( typeof Event === 'function' ) {
			window.dispatchEvent( new Event( 'wp-mcp-ai-webllm-function-calling-ready' ) );
		} else {
			var event = document.createEvent( 'Event' );
			event.initEvent( 'wp-mcp-ai-webllm-function-calling-ready', true, true );
			window.dispatchEvent( event );
		}
		
		console.log( '[NV oOS WebLLM Function Calling] Ready' );
	} ).catch( function( error ) {
		console.error( '[NV oOS WebLLM Function Calling] Failed to initialize:', error );
	} );
	
})();
