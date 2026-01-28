/**
 * WordPress Tool to OpenAI Function Adapter
 * 
 * Converts WordPress tool definitions (from REST API) to OpenAI function format.
 * NO BUNDLED DEPENDENCIES - pure JavaScript
 * 
 * @package WP_MCP_AI
 * @since 1.2.0
 */

(function() {
	'use strict';
	
	class ToolAdapter {
		/**
		 * Convert WordPress tool schema to OpenAI function schema
		 * 
		 * WordPress format (from REST API):
		 * {
		 *   slug: 'create_post',
		 *   description: 'Create a post',
		 *   parameters: { type: 'object', properties: {...} }
		 * }
		 * 
		 * OpenAI format:
		 * {
		 *   type: 'object',
		 *   properties: {...},
		 *   required: [...]
		 * }
		 */
		convertSchema( wpSchema ) {
			if ( ! wpSchema ) {
				return { type: 'object', properties: {} };
			}
			
			// Already in correct format
			if ( wpSchema.type && wpSchema.properties ) {
				return wpSchema;
			}
			
			// Convert from WordPress format
			return {
				type: 'object',
				properties: wpSchema.properties || {},
				required: wpSchema.required || []
			};
		}
		
		/**
		 * Convert WordPress tool to OpenAI function definition
		 * 
		 * @param {Object} wpTool - WordPress tool object
		 * @returns {Object} OpenAI function definition
		 */
		convertTool( wpTool ) {
			return {
				type: 'function',
				function: {
					name: wpTool.slug || wpTool.name,
					description: wpTool.description || '',
					parameters: this.convertSchema( wpTool.parameters || wpTool.schema )
				}
			};
		}
		
		/**
		 * Convert array of WordPress tools to OpenAI functions
		 * 
		 * @param {Array} wpTools - Array of WordPress tools
		 * @returns {Array} Array of OpenAI function definitions
		 */
		convertTools( wpTools ) {
			if ( ! Array.isArray( wpTools ) ) {
				return [];
			}
			
			return wpTools.map( function( tool ) {
				return this.convertTool( tool );
			}.bind( this ) );
		}
		
		/**
		 * Fetch available tools from WordPress REST API
		 * 
		 * @returns {Promise<Array>} Promise resolving to array of tools
		 */
		async fetchTools() {
			var endpoint = window.wpMcpAiChat && window.wpMcpAiChat.toolsEndpoint;
			if ( ! endpoint ) {
				throw new Error( 'Tools endpoint not configured' );
			}
			
			var response = await fetch( endpoint, {
				method: 'GET',
				headers: {
					'X-WP-Nonce': window.wpMcpAiChat && window.wpMcpAiChat.nonce || ''
				}
			} );
			
			if ( ! response.ok ) {
				throw new Error( 'Failed to fetch tools: ' + response.statusText );
			}
			
			var data = await response.json();
			return data.tools || data || [];
		}
		
		/**
		 * Execute a tool via WordPress REST API
		 * 
		 * @param {string} toolName - Tool name/slug
		 * @param {Object} args - Tool arguments
		 * @returns {Promise<Object>} Tool execution result
		 */
		async executeTool( toolName, args ) {
			var endpoint = window.wpMcpAiChat && window.wpMcpAiChat.toolsEndpoint;
			if ( ! endpoint ) {
				throw new Error( 'Tools endpoint not configured' );
			}
			
			var response = await fetch( endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.wpMcpAiChat && window.wpMcpAiChat.nonce || ''
				},
				body: JSON.stringify( {
					tool: toolName,
					arguments: args
				} )
			} );
			
			if ( ! response.ok ) {
				throw new Error( 'Tool execution failed: ' + response.statusText );
			}
			
			return response.json();
		}
	}
	
	// Export to global scope
	window.WP_MCP_AI_ToolAdapter = new ToolAdapter();
	
	// Dispatch ready event
	if ( typeof Event === 'function' ) {
		window.dispatchEvent( new Event( 'wp-mcp-ai-tool-adapter-ready' ) );
	} else {
		// Fallback for older browsers
		var event = document.createEvent( 'Event' );
		event.initEvent( 'wp-mcp-ai-tool-adapter-ready', true, true );
		window.dispatchEvent( event );
	}
	
})();
