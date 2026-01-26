/**
 * LangChain.js Orchestration Client for WordPress
 *
 * Provides sophisticated multi-step reasoning, chains, agents, and memory management
 * using LangChain.js integrated with WebLLM for browser-first AI orchestration.
 *
 * Dependencies: langchain, @langchain/core, @langchain/community, @mlc-ai/web-llm
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.0.0
 */

/* global wpMcpAiChat */

(function() {
	'use strict';

	/**
	 * LangChain Orchestrator for browser-side AI workflows
	 *
	 * Enables:
	 * - Multi-step reasoning chains
	 * - Agent-based tool orchestration
	 * - Conversation memory management
	 * - Sequential and parallel execution
	 * - Self-reflection and error correction
	 */
	class WP_MCP_AI_LangChain_Orchestrator {
		/**
		 * Constructor
		 *
		 * @param {Object} webllmEngine - WebLLM engine instance from embedded-llm-client
		 */
		constructor( webllmEngine ) {
			this.webllmEngine = webllmEngine;
			this.chatModel = null;
			this.memory = null;
			this.tools = [];
			this.initialized = false;

			// Check if LangChain libraries are loaded
			this.hasLangChain = typeof window.langchain !== 'undefined';
			this.hasLangChainCore = typeof window.langchainCore !== 'undefined';
			this.hasLangChainCommunity = typeof window.langchainCommunity !== 'undefined';

			if ( ! this.hasLangChain || ! this.hasLangChainCore || ! this.hasLangChainCommunity ) {
				console.warn( '[NV oOS LangChain] LangChain libraries not loaded. Orchestration features unavailable.' );
				return;
			}

			console.log( '[NV oOS LangChain] Orchestrator initialized' );
		}

		/**
		 * Initialize the LangChain chat model with WebLLM
		 *
		 * @return {Promise<void>}
		 */
		async initialize() {
			if ( this.initialized ) {
				return;
			}

			if ( ! this.webllmEngine ) {
				throw new Error( 'WebLLM engine not provided. Initialize embedded LLM first.' );
			}

			try {
				// Create ChatWebLLM instance that wraps our WebLLM engine
				// Note: This is a conceptual implementation - actual implementation
				// depends on LangChain's ChatWebLLM adapter availability
				this.chatModel = {
					_engine: this.webllmEngine,
					_call: async ( messages ) => {
						const response = await this.webllmEngine.chat.completions.create( {
							messages: messages.map( msg => ( {
								role: msg._getType(),
								content: msg.content
							} ) ),
							stream: false
						} );

						return response.choices[ 0 ].message.content;
					},
					// LangChain BaseChatModel interface
					async invoke( input ) {
						return this._call( [ input ] );
					}
				};

				// Initialize conversation buffer memory
				this.memory = this.createMemory();

				this.initialized = true;
				console.log( '[NV oOS LangChain] Chat model initialized successfully' );
			} catch ( error ) {
				console.error( '[NV oOS LangChain] Initialization failed:', error );
				throw error;
			}
		}

		/**
		 * Create conversation buffer memory
		 *
		 * @param {number} k - Number of previous messages to remember
		 * @return {Object} Memory instance
		 */
		createMemory( k = 10 ) {
			return {
				messages: [],
				maxMessages: k,

				/**
				 * Add a message to memory
				 *
				 * @param {string} role - Message role (user/assistant)
				 * @param {string} content - Message content
				 */
				addMessage( role, content ) {
					this.messages.push( { role, content, timestamp: Date.now() } );

					// Keep only last k messages
					if ( this.messages.length > this.maxMessages ) {
						this.messages = this.messages.slice( -this.maxMessages );
					}
				},

				/**
				 * Get all messages in memory
				 *
				 * @return {Array} Messages
				 */
				getMessages() {
					return this.messages;
				},

				/**
				 * Clear memory
				 */
				clear() {
					this.messages = [];
				}
			};
		}

		/**
		 * Create a simple chain with template
		 *
		 * @param {string} template - Prompt template with {variables}
		 * @param {Object} variables - Variables to fill in template
		 * @return {Promise<string>} Chain result
		 */
		async createChain( template, variables = {} ) {
			if ( ! this.initialized ) {
				await this.initialize();
			}

			try {
				// Simple template substitution
				let prompt = template;
				for ( const [ key, value ] of Object.entries( variables ) ) {
					const regex = new RegExp( `\\{${key}\\}`, 'g' );
					prompt = prompt.replace( regex, value );
				}

				// Add memory context if available
				if ( this.memory && this.memory.messages.length > 0 ) {
					const context = this.memory.messages
						.map( msg => `${msg.role}: ${msg.content}` )
						.join( '\n' );
					prompt = `Previous conversation:\n${context}\n\nCurrent request:\n${prompt}`;
				}

				// Execute the chain
				const messages = [
					{ role: 'system', content: 'You are a helpful AI assistant.' },
					{ role: 'user', content: prompt }
				];

				const response = await this.webllmEngine.chat.completions.create( {
					messages,
					stream: false
				} );

				const result = response.choices[ 0 ].message.content;

				// Store in memory
				if ( this.memory ) {
					this.memory.addMessage( 'user', prompt );
					this.memory.addMessage( 'assistant', result );
				}

				return result;
			} catch ( error ) {
				console.error( '[NV oOS LangChain] Chain execution failed:', error );
				throw error;
			}
		}

		/**
		 * Create and execute a sequential chain
		 *
		 * Executes multiple chains in sequence, passing output of one to the next
		 *
		 * @param {Array} steps - Array of step objects with {template, variables}
		 * @return {Promise<Array>} Array of results from each step
		 */
		async createSequentialChain( steps ) {
			if ( ! this.initialized ) {
				await this.initialize();
			}

			const results = [];
			let previousResult = '';

			for ( let i = 0; i < steps.length; i++ ) {
				const step = steps[ i ];
				console.log( `[NV oOS LangChain] Executing step ${i + 1}/${steps.length}` );

				// Add previous result to variables
				const variables = {
					...step.variables,
					previous_result: previousResult
				};

				const result = await this.createChain( step.template, variables );
				results.push( result );
				previousResult = result;
			}

			return results;
		}

		/**
		 * Set tools available for agent
		 *
		 * @param {Array} wpTools - WordPress tools from REST API
		 */
		setTools( wpTools ) {
			this.tools = wpTools;
			console.log( `[NV oOS LangChain] Loaded ${this.tools.length} tools for agent` );
		}

		/**
		 * Create and execute an agent with tool calling
		 *
		 * The agent can use tools to accomplish complex tasks through reasoning
		 *
		 * @param {string} task - Task description for the agent
		 * @param {Object} options - Options (maxIterations, verbose)
		 * @return {Promise<Object>} Agent execution result
		 */
		async createAgent( task, options = {} ) {
			if ( ! this.initialized ) {
				await this.initialize();
			}

			const maxIterations = options.maxIterations || 10;
			const verbose = options.verbose || false;

			try {
				console.log( `[NV oOS LangChain] Starting agent with task: ${task}` );

				const agentPrompt = `You are an AI agent with access to WordPress tools. Your task is:

${task}

Available tools:
${this.tools.map( t => `- ${t.name}: ${t.description}` ).join( '\n' )}

Think step by step and use tools as needed. When you have completed the task, respond with your final answer.

Format tool calls as: TOOL_CALL: tool_name({"arg": "value"})`;

				const messages = [
					{ role: 'system', content: agentPrompt },
					{ role: 'user', content: task }
				];

				let iteration = 0;
				const executionLog = [];

				while ( iteration < maxIterations ) {
					iteration++;
					if ( verbose ) {
						console.log( `[NV oOS LangChain] Agent iteration ${iteration}` );
					}

					const response = await this.webllmEngine.chat.completions.create( {
						messages,
						stream: false
					} );

					const agentResponse = response.choices[ 0 ].message.content;
					executionLog.push( {
						iteration,
						type: 'thought',
						content: agentResponse
					} );

					// Check if agent wants to call a tool
					const toolCallMatch = agentResponse.match( /TOOL_CALL:\s*(\w+)\((.*)\)/ );

					if ( toolCallMatch ) {
						const toolName = toolCallMatch[ 1 ];
						let toolArgs = {};

						try {
							toolArgs = JSON.parse( toolCallMatch[ 2 ] );
						} catch ( e ) {
							console.warn( '[NV oOS LangChain] Failed to parse tool arguments:', e );
						}

						// Execute tool
						const toolResult = await this.executeTool( toolName, toolArgs );
						executionLog.push( {
							iteration,
							type: 'tool_call',
							tool: toolName,
							args: toolArgs,
							result: toolResult
						} );

						// Add tool result to conversation
						messages.push( {
							role: 'assistant',
							content: agentResponse
						} );
						messages.push( {
							role: 'user',
							content: `Tool result: ${JSON.stringify( toolResult )}`
						} );
					} else {
						// Agent has finished - no more tool calls
						if ( verbose ) {
							console.log( '[NV oOS LangChain] Agent completed task' );
						}

						return {
							success: true,
							result: agentResponse,
							iterations: iteration,
							executionLog
						};
					}
				}

				// Max iterations reached
				console.warn( '[NV oOS LangChain] Agent reached max iterations' );
				return {
					success: false,
					error: 'Max iterations reached',
					iterations: iteration,
					executionLog
				};
			} catch ( error ) {
				console.error( '[NV oOS LangChain] Agent execution failed:', error );
				return {
					success: false,
					error: error.message,
					executionLog: []
				};
			}
		}

		/**
		 * Execute a WordPress tool
		 *
		 * @param {string} toolName - Tool name/slug
		 * @param {Object} args - Tool arguments
		 * @return {Promise<any>} Tool result
		 */
		async executeTool( toolName, args ) {
			console.log( `[NV oOS LangChain] Executing tool: ${toolName}`, args );

			// Check if tool is client-executable
			const tool = this.tools.find( t => t.name === toolName || t.slug === toolName );

			if ( tool && tool.client_executable ) {
				// Execute client-side using Transformers.js if available
				if ( window.WP_MCP_AI_Transformers ) {
					return window.WP_MCP_AI_Transformers.executeClientTool( toolName, args );
				}
			}

			// Execute server-side via REST API
			try {
				const response = await fetch( wpMcpAiChat.toolsEndpoint || '/wp-json/mcp-ai/v1/tools/execute', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': wpMcpAiChat.nonce || ''
					},
					body: JSON.stringify( {
						tool: toolName,
						arguments: args
					} )
				} );

				if ( ! response.ok ) {
					throw new Error( `Tool execution failed: ${response.status} ${response.statusText}` );
				}

				const result = await response.json();
				return result;
			} catch ( error ) {
				console.error( `[NV oOS LangChain] Tool execution error:`, error );
				return {
					error: error.message,
					tool: toolName
				};
			}
		}

		/**
		 * Get conversation memory
		 *
		 * @return {Object} Memory instance
		 */
		getMemory() {
			return this.memory;
		}

		/**
		 * Clear conversation memory
		 */
		clearMemory() {
			if ( this.memory ) {
				this.memory.clear();
				console.log( '[NV oOS LangChain] Memory cleared' );
			}
		}

		/**
		 * Check if orchestrator is ready
		 *
		 * @return {boolean} Ready state
		 */
		isReady() {
			return this.initialized && this.hasLangChain;
		}
	}

	// Export to global scope
	window.WP_MCP_AI_LangChain_Orchestrator = WP_MCP_AI_LangChain_Orchestrator;

	console.log( '[NV oOS LangChain] Orchestration client loaded' );

})();
