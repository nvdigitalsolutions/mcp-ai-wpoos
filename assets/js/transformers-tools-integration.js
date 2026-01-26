/**
 * Transformers.js Tools Integration
 *
 * Integrates browser-native Transformers.js tasks with WordPress chat interface.
 * Provides 7 client-side tools that execute instantly without server round-trips.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/* global wpMcpAiTransformers */

(function() {
	'use strict';

	/**
	 * Client-side tool registry for Transformers.js tasks
	 */
	class TransformersToolsIntegration {
		constructor() {
			this.tools = new Map();
			this.client = null;
			this.vectorStore = null;
			this.isInitialized = false;
			this.config = window.wpMcpAiTransformers || {};

			// Initialize when DOM is ready
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', () => this.initialize() );
			} else {
				this.initialize();
			}
		}

		/**
		 * Initialize tools integration
		 */
		async initialize() {
			if ( this.isInitialized ) {
				return;
			}

			try {
				// Wait for Transformers client
				if ( window.WP_MCP_AI_Transformers ) {
					this.client = window.WP_MCP_AI_Transformers;
				} else if ( window.WP_MCP_AI_TransformersClient ) {
					this.client = new window.WP_MCP_AI_TransformersClient();
				} else {
					this.log( 'Transformers client not available, waiting...' );
					// Wait a bit and retry
					setTimeout( () => this.initialize(), 1000 );
					return;
				}

				// Initialize vector store if enabled
				if ( this.config.semanticSearchEnabled && window.WP_MCP_AI_ClientVectorStore ) {
					this.vectorStore = new window.WP_MCP_AI_ClientVectorStore();
					await this.vectorStore.initialize();
				}

				// Register tools
				this.registerTools();

				this.isInitialized = true;
				this.log( 'Tools integration initialized with', this.tools.size, 'tools' );

				// Dispatch event for other components
				window.dispatchEvent( new CustomEvent( 'transformers-tools-ready', {
					detail: { tools: Array.from( this.tools.keys() ) },
				} ) );
			} catch ( error ) {
				this.error( 'Failed to initialize tools:', error );
			}
		}

		/**
		 * Register all available tools
		 */
		registerTools() {
			// Tool 1: Summarize Text
			this.registerTool( 'client_summarize_text', {
				name: 'Summarize Text (Browser)',
				description: 'Summarize long text into a concise summary. Runs instantly in your browser.',
				parameters: {
					type: 'object',
					properties: {
						text: {
							type: 'string',
							description: 'The text to summarize',
						},
						max_length: {
							type: 'number',
							description: 'Maximum length of summary (default: 130)',
							default: 130,
						},
						min_length: {
							type: 'number',
							description: 'Minimum length of summary (default: 30)',
							default: 30,
						},
					},
					required: [ 'text' ],
				},
				execute: async ( args ) => {
					const result = await this.client.summarize( args.text, {
						maxLength: args.max_length || 130,
						minLength: args.min_length || 30,
					} );
					return {
						success: true,
						summary: result.summary,
						original_length: result.originalLength,
						summary_length: result.summaryLength,
						reduction: ( ( 1 - result.summaryLength / result.originalLength ) * 100 ).toFixed( 1 ) + '%',
					};
				},
			} );

			// Tool 2: Analyze Sentiment
			this.registerTool( 'client_analyze_sentiment', {
				name: 'Analyze Sentiment (Browser)',
				description: 'Analyze the sentiment of text (positive/negative). Runs instantly in your browser.',
				parameters: {
					type: 'object',
					properties: {
						text: {
							type: 'string',
							description: 'The text to analyze',
						},
					},
					required: [ 'text' ],
				},
				execute: async ( args ) => {
					const result = await this.client.analyzeSentiment( args.text );
					return {
						success: true,
						sentiment: result.label,
						score: result.score,
						confidence: result.confidence,
					};
				},
			} );

			// Tool 3: Extract Entities (NER)
			this.registerTool( 'client_extract_entities', {
				name: 'Extract Entities (Browser)',
				description: 'Extract named entities (people, places, organizations) from text. Runs instantly in your browser.',
				parameters: {
					type: 'object',
					properties: {
						text: {
							type: 'string',
							description: 'The text to analyze',
						},
					},
					required: [ 'text' ],
				},
				execute: async ( args ) => {
					const entities = await this.client.extractEntities( args.text );
					return {
						success: true,
						entities: entities,
						count: Object.values( entities ).reduce( ( sum, arr ) => sum + arr.length, 0 ),
					};
				},
			} );

			// Tool 4: Semantic Search
			if ( this.config.semanticSearchEnabled && this.vectorStore ) {
				this.registerTool( 'client_semantic_search', {
					name: 'Semantic Search (Browser)',
					description: 'Search documents by semantic similarity. Runs instantly in your browser.',
					parameters: {
						type: 'object',
						properties: {
							query: {
								type: 'string',
								description: 'The search query',
							},
							limit: {
								type: 'number',
								description: 'Maximum number of results (default: 5)',
								default: 5,
							},
							min_score: {
								type: 'number',
								description: 'Minimum similarity score 0-1 (default: 0)',
								default: 0,
							},
						},
						required: [ 'query' ],
					},
					execute: async ( args ) => {
						const results = await this.vectorStore.search(
							args.query,
							args.limit || 5,
							{ minScore: args.min_score || 0 }
						);
						return {
							success: true,
							results: results,
							count: results.length,
						};
					},
				} );
			}

			// Tool 5: Translate Text
			if ( this.config.features && this.config.features.translation ) {
				this.registerTool( 'client_translate_text', {
					name: 'Translate Text (Browser)',
					description: 'Translate text to another language. Runs instantly in your browser.',
					parameters: {
						type: 'object',
						properties: {
							text: {
								type: 'string',
								description: 'The text to translate',
							},
							target_language: {
								type: 'string',
								description: 'Target language code (e.g., "fr", "es", "de")',
								default: 'fr',
							},
						},
						required: [ 'text' ],
					},
					execute: async ( args ) => {
						const translated = await this.client.translate(
							args.text,
							args.target_language || 'fr'
						);
						return {
							success: true,
							translated_text: translated,
							original_text: args.text,
							target_language: args.target_language || 'fr',
						};
					},
				} );
			}

			// Tool 6: Question Answering
			this.registerTool( 'client_question_answering', {
				name: 'Answer Question (Browser)',
				description: 'Answer a question based on provided context. Runs instantly in your browser.',
				parameters: {
					type: 'object',
					properties: {
						question: {
							type: 'string',
							description: 'The question to answer',
						},
						context: {
							type: 'string',
							description: 'The context text containing the answer',
						},
					},
					required: [ 'question', 'context' ],
				},
				execute: async ( args ) => {
					const result = await this.client.answerQuestion( args.question, args.context );
					return {
						success: true,
						answer: result.answer,
						score: result.score,
						confidence: result.confidence,
					};
				},
			} );

			// Tool 7: Zero-Shot Classification
			this.registerTool( 'client_classify_text', {
				name: 'Classify Text (Browser)',
				description: 'Classify text into categories without training. Runs instantly in your browser.',
				parameters: {
					type: 'object',
					properties: {
						text: {
							type: 'string',
							description: 'The text to classify',
						},
						labels: {
							type: 'array',
							description: 'Array of possible labels/categories',
							items: {
								type: 'string',
							},
						},
					},
					required: [ 'text', 'labels' ],
				},
				execute: async ( args ) => {
					const results = await this.client.classify( args.text, args.labels );
					return {
						success: true,
						classifications: results,
						top_label: results[ 0 ].label,
						top_confidence: results[ 0 ].confidence,
					};
				},
			} );
		}

		/**
		 * Register a tool
		 *
		 * @param {string} slug - Tool slug
		 * @param {Object} tool - Tool definition
		 */
		registerTool( slug, tool ) {
			this.tools.set( slug, tool );
			this.log( 'Registered tool:', slug );
		}

		/**
		 * Get a tool by slug
		 *
		 * @param {string} slug - Tool slug
		 * @return {Object|null} Tool definition or null
		 */
		getTool( slug ) {
			return this.tools.get( slug ) || null;
		}

		/**
		 * Check if a tool is available
		 *
		 * @param {string} slug - Tool slug
		 * @return {boolean} Whether tool is available
		 */
		hasTool( slug ) {
			return this.tools.has( slug );
		}

		/**
		 * Execute a tool
		 *
		 * @param {string} slug - Tool slug
		 * @param {Object} args - Tool arguments
		 * @return {Promise<Object>} Tool result
		 */
		async executeTool( slug, args ) {
			const tool = this.getTool( slug );
			if ( ! tool ) {
				throw new Error( `Tool not found: ${slug}` );
			}

			try {
				this.log( 'Executing tool:', slug, 'with args:', args );
				const result = await tool.execute( args );
				this.log( 'Tool executed successfully:', slug );
				return result;
			} catch ( error ) {
				this.error( 'Tool execution failed:', slug, error );
				throw error;
			}
		}

		/**
		 * Get all available tools
		 *
		 * @return {Array} Array of tool definitions
		 */
		getAllTools() {
			return Array.from( this.tools.entries() ).map( ( [ slug, tool ] ) => ( {
				slug,
				name: tool.name,
				description: tool.description,
				parameters: tool.parameters,
			} ) );
		}

		/**
		 * Log message (if debug enabled)
		 *
		 * @param {...*} args - Log arguments
		 */
		log( ...args ) {
			if ( this.config.debug || window.WP_MCP_AI_DEBUG ) {
				console.log( '[NV oOS Transformers Tools]', ...args );
			}
		}

		/**
		 * Log error
		 *
		 * @param {...*} args - Error arguments
		 */
		error( ...args ) {
			console.error( '[NV oOS Transformers Tools]', ...args );
		}
	}

	// Initialize and export
	const toolsIntegration = new TransformersToolsIntegration();
	window.WP_MCP_AI_TransformersTools = toolsIntegration;

	// Also export to global for backward compatibility
	if ( ! window.wpMcpAi ) {
		window.wpMcpAi = {};
	}
	window.wpMcpAi.transformersTools = toolsIntegration;

})();
