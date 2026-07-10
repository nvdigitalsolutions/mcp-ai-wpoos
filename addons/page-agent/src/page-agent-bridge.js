/**
 * NV oOS Page Agent Bridge (source)
 *
 * Client-side bridge that wires the Alibaba Page Agent library into
 * the NV oOS chat ecosystem. Listens for page-agent delegate events
 * from the chat LLM and executes them in the browser.
 *
 * This file is the entry point for esbuild bundling.
 * The built output in assets/js/ is loaded by WordPress.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 *
 * @link    https://github.com/alibaba/page-agent Upstream page-agent library (MIT)
 * @credit  Alibaba — page-agent browser automation library
 */

( function ( config ) {
	'use strict';

	/**
	 * The Page Agent instance.
	 *
	 * @type {object|null}
	 */
	var agent = null;

	/**
	 * Whether the agent has been successfully initialized.
	 *
	 * @type {boolean}
	 */
	var active = false;

	/**
	 * Initialize the Page Agent.
	 *
	 * Creates a new PageAgent instance with configuration from the server.
	 *
	 * @since 0.1.0
	 *
	 * @returns {void}
	 */
	function init() {
		if ( ! config || ! config.enabled ) {
			return;
		}

		if ( ! config.model || ! config.baseURL ) {
			console.warn( '[NV oOS Page Agent] Missing required configuration (model or baseURL).' );
			return;
		}

		// PageAgent global is provided by page-agent.bundle.js loaded first.
		if ( typeof window.PageAgent === 'undefined' ) {
			console.warn( '[NV oOS Page Agent] PageAgent global not found — is page-agent.bundle.js loaded?' );
			return;
		}

		try {
			agent = new window.PageAgent( {
				model:        config.model,
				baseURL:      config.baseURL,
				apiKey:       config.apiKey,
				language:     config.language || 'en-US',
				maxSteps:     config.maxSteps || 50,
				systemPrompt: 'You are a helpful WordPress page assistant. Interact with the current web page based on natural language instructions. Be precise and efficient.',
			} );

			// Listen for delegated instructions from the chat LLM.
			if ( window.wpMcpAiJobBus ) {
				window.wpMcpAiJobBus.on( 'page-agent:execute', handleExecute );
				window.wpMcpAiJobBus.on( 'page-agent:abort', handleAbort );
			}

			// Expose for programmatic use.
			window.wpMcpAiPageAgentInstance = agent;

			active = true;

			// Emit ready event.
			if ( window.wpMcpAiJobBus ) {
				window.wpMcpAiJobBus.emit( 'page-agent:ready', {
					model:    config.model,
					language: config.language,
				} );
			}
		} catch ( error ) {
			console.error( '[NV oOS Page Agent] Initialization failed:', error );
		}
	}

	/**
	 * Handle a delegated instruction from the chat LLM.
	 *
	 * @since 0.1.0
	 *
	 * @param {Object} payload
	 * @param {string} payload.instruction
	 * @param {string} payload.requestId
	 * @param {boolean} payload.waitForResult
	 * @param {number} [payload.maxSteps]
	 * @returns {Promise<void>}
	 */
	async function handleExecute( payload ) {
		var instruction   = payload.instruction;
		var requestId     = payload.requestId;
		var waitForResult = payload.waitForResult !== false;
		var maxSteps      = payload.maxSteps || 0;

		if ( ! agent || ! active ) {
			emitError( requestId, 'Page Agent is not initialized.' );
			return;
		}

		if ( ! instruction ) {
			emitError( requestId, 'No instruction provided.' );
			return;
		}

		try {
			var executeOptions = {};
			if ( maxSteps > 0 ) {
				executeOptions.maxSteps = maxSteps;
			}

			var result = await agent.execute( instruction, executeOptions );

			if ( waitForResult && window.wpMcpAiJobBus ) {
				window.wpMcpAiJobBus.emit( 'page-agent:result', {
					requestId:   requestId,
					success:     true,
					result:      result,
					instruction: instruction,
				} );
			}
		} catch ( error ) {
			if ( waitForResult && window.wpMcpAiJobBus ) {
				window.wpMcpAiJobBus.emit( 'page-agent:result', {
					requestId:   requestId,
					success:     false,
					error:       error.message || 'Unknown error',
					instruction: instruction,
				} );
			}
		}
	}

	/**
	 * Handle an abort request from the chat interface.
	 *
	 * @since 0.1.0
	 *
	 * @returns {void}
	 */
	function handleAbort() {
		if ( agent && typeof agent.stop === 'function' ) {
			try {
				agent.stop();
			} catch ( error ) {
				// Silently ignore.
			}
		}
	}

	/**
	 * Emit an error result to the job bus.
	 *
	 * @since 0.1.0
	 *
	 * @param {string} requestId
	 * @param {string} message
	 * @returns {void}
	 */
	function emitError( requestId, message ) {
		if ( window.wpMcpAiJobBus ) {
			window.wpMcpAiJobBus.emit( 'page-agent:result', {
				requestId: requestId,
				success:   false,
				error:     message,
			} );
		}
	}

	/**
	 * Cleanup on page unload.
	 *
	 * @since 0.1.0
	 *
	 * @returns {void}
	 */
	function destroy() {
		if ( agent && typeof agent.destroy === 'function' ) {
			try {
				agent.destroy();
			} catch ( error ) {
				// Silently ignore cleanup errors.
			}
		}

		if ( window.wpMcpAiJobBus ) {
			window.wpMcpAiJobBus.off( 'page-agent:execute', handleExecute );
			window.wpMcpAiJobBus.off( 'page-agent:abort', handleAbort );
		}

		agent  = null;
		active = false;
	}

	// Initialize when DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Cleanup on page unload.
	window.addEventListener( 'beforeunload', destroy );

} )( window.wpMcpAiPageAgent || {} );
