/**
 * WebLLM Multi-Modal Client
 * 
 * Vision and audio support for embedded LLM.
 * NO BUNDLED DEPENDENCIES - uses CDN-loaded models
 * 
 * Extends WebLLM Function Calling Client with multi-modal capabilities.
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
				if ( window.WP_MCP_AI_WebLLM_FunctionCalling ) {
					clearInterval( checkInterval );
					resolve();
				}
			}, 100 );
			
			// Timeout after 30 seconds
			setTimeout( function() {
				clearInterval( checkInterval );
				reject( new Error( 'Timeout waiting for WebLLM Function Calling client' ) );
			}, 30000 );
		} );
	}
	
	/**
	 * Available vision models (loaded on-demand from MLC AI CDN)
	 * Models are downloaded to user's browser, not bundled in plugin
	 */
	var VISION_MODELS = {
		'LLaVA-1.5-7B-q4f16_1-MLC': {
			name: 'LLaVA 1.5 7B',
			size: '~4GB',
			capabilities: ['image_understanding', 'visual_qa'],
			url: 'https://huggingface.co/mlc-ai/LLaVA-1.5-7B-q4f16_1-MLC',
			description: 'General-purpose vision model, good for image understanding'
		},
		'Qwen2-VL-2B-Instruct-q4f16_1-MLC': {
			name: 'Qwen2-VL 2B',
			size: '~1.5GB',
			capabilities: ['image_understanding', 'ocr', 'visual_reasoning'],
			url: 'https://huggingface.co/mlc-ai/Qwen2-VL-2B-Instruct-q4f16_1-MLC',
			description: 'Smaller, faster vision model with OCR support'
		},
		'Qwen2-VL-7B-Instruct-q4f16_1-MLC': {
			name: 'Qwen2-VL 7B',
			size: '~4.5GB',
			capabilities: ['image_understanding', 'ocr', 'visual_reasoning', 'complex_scenes'],
			url: 'https://huggingface.co/mlc-ai/Qwen2-VL-7B-Instruct-q4f16_1-MLC',
			description: 'High-quality vision model for complex visual tasks'
		}
	};
	
	/**
	 * WebLLM Multi-Modal Client
	 * Extends function calling client with vision support
	 */
	class WebLLMMultiModalClient extends window.WP_MCP_AI_WebLLM_FunctionCalling {
		constructor( instanceId ) {
			super( instanceId );
			console.log( '[NV oOS WebLLM Multi-Modal] Client created:', instanceId );
		}
		
		/**
		 * Check if current model supports vision
		 * 
		 * @returns {boolean}
		 */
		supportsVision() {
			if ( ! this.currentModelId ) {
				return false;
			}
			
			return this.currentModelId.includes( 'LLaVA' ) || 
			       this.currentModelId.includes( 'Qwen2-VL' ) ||
			       this.currentModelId.includes( 'Qwen-VL' );
		}
		
		/**
		 * Get vision model info
		 * 
		 * @param {string} modelId - Model ID
		 * @returns {Object|null}
		 */
		getVisionModelInfo( modelId ) {
			modelId = modelId || this.currentModelId;
			
			if ( ! modelId ) {
				return null;
			}
			
			return VISION_MODELS[modelId] || null;
		}
		
		/**
		 * Chat with images
		 * 
		 * @param {Array} messages - Messages with optional images
		 * @param {Array} images - Image URLs or base64 data (optional if in messages)
		 * @param {Array} tools - WordPress tools (optional)
		 * @param {Object} options - Generation options
		 */
		async chatWithImages( messages, images, tools, options ) {
			images = images || [];
			tools = tools || [];
			options = options || {};
			
			if ( ! this.modelLoaded || ! this.currentEngine ) {
				throw new Error( 'Model not loaded. Please load a vision model first.' );
			}
			
			// Check if current model supports vision
			if ( ! this.supportsVision() ) {
				var modelInfo = this.getVisionModelInfo( this.currentModelId );
				throw new Error( 
					'Current model (' + this.currentModelId + ') does not support vision. ' +
					'Please load a vision model like LLaVA-1.5-7B or Qwen2-VL-2B.'
				);
			}
			
			console.log( '[NV oOS WebLLM Multi-Modal] Starting chat with images:', {
				messageCount: messages.length,
				imageCount: images.length,
				toolCount: tools.length,
				modelId: this.currentModelId,
				instanceId: this.instanceId
			} );
			
			// Format messages with images
			var formattedMessages = this.formatMessagesWithImages( messages, images );
			
			// Use parent class chatWithTools method if tools provided
			if ( tools && tools.length > 0 ) {
				return this.chatWithTools( formattedMessages, tools, options );
			}
			
			// Otherwise, use standard generation
			return this.generateStreamingCompletion( formattedMessages, options, options.onChunk );
		}
		
		/**
		 * Format messages with images for vision models
		 * 
		 * Converts simple messages to multi-part content format required by vision models
		 * 
		 * @param {Array} messages - Original messages
		 * @param {Array} images - Image URLs or base64 strings
		 * @returns {Array} Formatted messages
		 */
		formatMessagesWithImages( messages, images ) {
			var self = this;
			
			// If no images provided separately, return messages as-is
			// (images might already be embedded in message content)
			if ( ! images || images.length === 0 ) {
				return messages;
			}
			
			// Find the last user message and add images to it
			var lastUserIndex = -1;
			for ( var i = messages.length - 1; i >= 0; i-- ) {
				if ( messages[i].role === 'user' ) {
					lastUserIndex = i;
					break;
				}
			}
			
			if ( lastUserIndex === -1 ) {
				// No user message found, create one
				console.warn( '[NV oOS WebLLM Multi-Modal] No user message found, creating one' );
				messages.push( {
					role: 'user',
					content: 'Analyze this image:'
				} );
				lastUserIndex = messages.length - 1;
			}
			
			// Format the user message with images
			var userMessage = messages[lastUserIndex];
			var formattedContent = [];
			
			// Add text content
			if ( typeof userMessage.content === 'string' ) {
				formattedContent.push( {
					type: 'text',
					text: userMessage.content
				} );
			} else if ( Array.isArray( userMessage.content ) ) {
				// Content is already in multi-part format
				formattedContent = userMessage.content.slice();
			}
			
			// Add images
			images.forEach( function( img ) {
				formattedContent.push( {
					type: 'image_url',
					image_url: {
						url: img
					}
				} );
			} );
			
			// Create new messages array with formatted user message
			var formattedMessages = messages.slice();
			formattedMessages[lastUserIndex] = {
				role: 'user',
				content: formattedContent
			};
			
			console.log( '[NV oOS WebLLM Multi-Modal] Formatted messages with images:', {
				originalMessageCount: messages.length,
				formattedMessageCount: formattedMessages.length,
				imagesAdded: images.length,
				userMessageIndex: lastUserIndex
			} );
			
			return formattedMessages;
		}
		
		/**
		 * Analyze a single image with a question
		 * 
		 * Convenience method for simple image analysis
		 * 
		 * @param {string} imageUrl - Image URL or base64 data
		 * @param {string} question - Question about the image
		 * @param {Object} options - Generation options
		 */
		async analyzeImage( imageUrl, question, options ) {
			options = options || {};
			
			console.log( '[NV oOS WebLLM Multi-Modal] Analyzing single image:', {
				imageUrl: imageUrl.substring( 0, 100 ) + '...',
				question: question,
				instanceId: this.instanceId
			} );
			
			var messages = [
				{
					role: 'user',
					content: question || 'What do you see in this image?'
				}
			];
			
			return this.chatWithImages( messages, [imageUrl], [], options );
		}
		
		/**
		 * Get available vision models
		 * 
		 * @returns {Object} Vision models dictionary
		 */
		static getAvailableVisionModels() {
			return VISION_MODELS;
		}
		
		/**
		 * Check if a model ID is a vision model
		 * 
		 * @param {string} modelId - Model ID to check
		 * @returns {boolean}
		 */
		static isVisionModel( modelId ) {
			return !! VISION_MODELS[modelId];
		}
	}
	
	// Initialize when dependencies are ready
	waitForDependencies().then( function() {
		// Export to global scope
		window.WP_MCP_AI_WebLLM_MultiModal = WebLLMMultiModalClient;
		window.WP_MCP_AI_VISION_MODELS = VISION_MODELS;
		
		// Dispatch ready event
		if ( typeof Event === 'function' ) {
			window.dispatchEvent( new Event( 'wp-mcp-ai-webllm-multimodal-ready' ) );
		} else {
			var event = document.createEvent( 'Event' );
			event.initEvent( 'wp-mcp-ai-webllm-multimodal-ready', true, true );
			window.dispatchEvent( event );
		}
		
		console.log( '[NV oOS WebLLM Multi-Modal] Ready' );
	} ).catch( function( error ) {
		console.error( '[NV oOS WebLLM Multi-Modal] Failed to initialize:', error );
	} );
	
})();
