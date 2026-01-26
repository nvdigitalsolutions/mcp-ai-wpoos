/**
 * LangChain Tool Adapter for WordPress
 *
 * Converts WordPress tool definitions to LangChain-compatible tool formats
 * Handles both client-side and server-side tool execution
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.0.0
 */

/* global wpMcpAiChat */

(function() {
	'use strict';

	/**
	 * WordPress Tool to LangChain Tool Adapter
	 *
	 * Provides conversion utilities and execution wrappers for WordPress tools
	 * to work seamlessly with LangChain.js agents and chains
	 */
	class WP_MCP_AI_LangChain_Tool_Adapter {
		constructor() {
			this.tools = [];
			this.toolsLoaded = false;

			console.log( '[NV oOS LangChain Adapter] Tool adapter initialized' );
		}

		/**
		 * Fetch available tools from WordPress REST API
		 *
		 * @return {Promise<Array>} Array of tool definitions
		 */
		async fetchTools() {
			if ( this.toolsLoaded ) {
				return this.tools;
			}

			const endpoint = wpMcpAiChat?.toolsEndpoint || '/wp-json/mcp-ai/v1/tools';

			try {
				console.log( '[NV oOS LangChain Adapter] Fetching tools from:', endpoint );

				const response = await fetch( endpoint, {
					method: 'GET',
					headers: {
						'X-WP-Nonce': wpMcpAiChat?.nonce || ''
					}
				} );

				if ( ! response.ok ) {
					throw new Error( `Failed to fetch tools: ${response.status} ${response.statusText}` );
				}

				const data = await response.json();
				this.tools = Array.isArray( data ) ? data : ( data.tools || [] );
				this.toolsLoaded = true;

				console.log( `[NV oOS LangChain Adapter] Loaded ${this.tools.length} tools` );
				return this.tools;
			} catch ( error ) {
				console.error( '[NV oOS LangChain Adapter] Failed to fetch tools:', error );
				return [];
			}
		}

		/**
		 * Convert WordPress tool schema to LangChain DynamicTool format
		 *
		 * @param {Object} wpTool - WordPress tool definition
		 * @return {Object} LangChain tool format
		 */
		convertToLangChainTool( wpTool ) {
			return {
				name: wpTool.name || wpTool.slug,
				description: wpTool.description || 'No description provided',
				schema: this.convertSchema( wpTool.parameters ),
				client_executable: wpTool.client_executable || false,

				/**
				 * Execute the tool
				 *
				 * @param {Object} input - Tool input parameters
				 * @return {Promise<any>} Tool result
				 */
				func: async ( input ) => {
					if ( wpTool.client_executable ) {
						return this.executeClientSide( wpTool.name, input );
					}
					return this.executeServerSide( wpTool.name, input );
				}
			};
		}

		/**
		 * Convert WordPress tool parameter schema to JSON Schema
		 *
		 * WordPress format (REST API):
		 * {
		 *   type: 'object',
		 *   properties: { title: { type: 'string' }, ... },
		 *   required: ['title']
		 * }
		 *
		 * LangChain expects standard JSON Schema
		 *
		 * @param {Object} wpSchema - WordPress parameter schema
		 * @return {Object} JSON Schema format
		 */
		convertSchema( wpSchema ) {
			if ( ! wpSchema ) {
				return {
					type: 'object',
					properties: {},
					required: []
				};
			}

			// Already in correct format
			if ( wpSchema.type && wpSchema.properties ) {
				return {
					type: wpSchema.type,
					properties: wpSchema.properties,
					required: wpSchema.required || []
				};
			}

			// Handle legacy format
			return {
				type: 'object',
				properties: wpSchema.properties || {},
				required: wpSchema.required || []
			};
		}

		/**
		 * Execute a tool client-side (browser)
		 *
		 * Uses Transformers.js client for browser-native execution
		 *
		 * @param {string} toolName - Tool name
		 * @param {Object} args - Tool arguments
		 * @return {Promise<any>} Execution result
		 */
		async executeClientSide( toolName, args ) {
			console.log( `[NV oOS LangChain Adapter] Executing client-side tool: ${toolName}` );

			// Check if Transformers.js client is available
			if ( ! window.WP_MCP_AI_Transformers ) {
				console.warn( '[NV oOS LangChain Adapter] Transformers.js not available, falling back to server-side' );
				return this.executeServerSide( toolName, args );
			}

			try {
				// Map tool names to Transformers.js methods
				const methodMap = {
					client_summarize_text: 'summarizeText',
					client_analyze_sentiment: 'analyzeSentiment',
					client_extract_entities: 'extractEntities',
					client_translate_text: 'translateText',
					client_question_answering: 'answerQuestion',
					client_semantic_search: 'generateEmbeddings'
				};

				const method = methodMap[ toolName ];

				if ( ! method || ! window.WP_MCP_AI_Transformers[ method ] ) {
					throw new Error( `Client-side tool method not found: ${toolName}` );
				}

				// Execute the tool
				const result = await window.WP_MCP_AI_Transformers[ method ]( args );

				console.log( `[NV oOS LangChain Adapter] Client-side execution successful:`, result );
				return result;
			} catch ( error ) {
				console.error( `[NV oOS LangChain Adapter] Client-side execution failed:`, error );
				// Fallback to server-side
				return this.executeServerSide( toolName, args );
			}
		}

		/**
		 * Execute a tool server-side (WordPress REST API)
		 *
		 * @param {string} toolName - Tool name
		 * @param {Object} args - Tool arguments
		 * @return {Promise<any>} Execution result
		 */
		async executeServerSide( toolName, args ) {
			console.log( `[NV oOS LangChain Adapter] Executing server-side tool: ${toolName}` );

			const endpoint = wpMcpAiChat?.toolsEndpoint || '/wp-json/mcp-ai/v1/tools/execute';

			try {
				const response = await fetch( endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': wpMcpAiChat?.nonce || ''
					},
					body: JSON.stringify( {
						tool: toolName,
						arguments: args
					} )
				} );

				if ( ! response.ok ) {
					const errorText = await response.text();
					throw new Error( `Server-side execution failed (${response.status}): ${errorText}` );
				}

				const result = await response.json();

				console.log( `[NV oOS LangChain Adapter] Server-side execution successful:`, result );
				return result;
			} catch ( error ) {
				console.error( `[NV oOS LangChain Adapter] Server-side execution failed:`, error );
				return {
					error: error.message,
					tool: toolName,
					args
				};
			}
		}

		/**
		 * Convert all WordPress tools to LangChain format
		 *
		 * @return {Promise<Array>} Array of LangChain tools
		 */
		async convertAllTools() {
			if ( ! this.toolsLoaded ) {
				await this.fetchTools();
			}

			return this.tools.map( tool => this.convertToLangChainTool( tool ) );
		}

		/**
		 * Get tool by name
		 *
		 * @param {string} name - Tool name
		 * @return {Object|null} Tool definition
		 */
		getTool( name ) {
			return this.tools.find( t => t.name === name || t.slug === name ) || null;
		}

		/**
		 * Filter tools by capability
		 *
		 * @param {string} capability - WordPress capability (e.g., 'edit_posts')
		 * @return {Array} Filtered tools
		 */
		filterByCapability( capability ) {
			return this.tools.filter( t => {
				if ( ! t.required_capability ) {
					return true; // No capability required
				}
				return t.required_capability === capability;
			} );
		}

		/**
		 * Filter tools by execution type
		 *
		 * @param {string} type - 'client' or 'server'
		 * @return {Array} Filtered tools
		 */
		filterByExecutionType( type ) {
			if ( type === 'client' ) {
				return this.tools.filter( t => t.client_executable === true );
			}
			return this.tools.filter( t => ! t.client_executable );
		}
	}

	// Export to global scope
	window.WP_MCP_AI_LangChain_Tool_Adapter = new WP_MCP_AI_LangChain_Tool_Adapter();

	console.log( '[NV oOS LangChain Adapter] Tool adapter loaded' );

})();
