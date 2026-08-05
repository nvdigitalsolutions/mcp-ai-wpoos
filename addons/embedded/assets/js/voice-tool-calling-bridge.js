/**
 * Voice Tool Calling Bridge
 *
 * Bridges the voice/STT pipeline with WebLLM function calling so that
 * spoken commands trigger tool execution. After the STT backend produces
 * a transcript, this bridge sends it to WebLLM with tool definitions.
 * The AI decides whether the transcript is a direct message or a tool
 * invocation, then executes accordingly.
 *
 * Flow:
 *   Mic → STT → Transcript → VoiceToolCallingBridge → WebLLM (+tools) → Result
 *                                                          ↓
 *                                                    Tool execution
 *                                                          ↓
 *                                                    Final response
 *
 * @package NV_oOS_Embedded
 * @since   1.3.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  Proprietary
 */

(function () {
	'use strict';

	/**
	 * Voice Tool Calling Bridge.
	 *
	 * Connects the voice transcription pipeline to the WebLLM function
	 * calling client. Handles the full loop: transcript → AI analysis →
	 * tool execution (if needed) → final response.
	 */
	class VoiceToolCallingBridge {
		/**
		 * @param {Object} options
		 * @param {Object} options.webllmClient   WebLLM function calling client instance.
		 * @param {Object} options.toolAdapter    Tool adapter for fetching/executing tools.
		 * @param {Array}  [options.tools=[]]     WordPress tools available to the AI.
		 * @param {Function} [options.onResponse]  (text) => void — final AI response.
		 * @param {Function} [options.onToolCall]  (toolName, args) => void — tool invoked.
		 * @param {Function} [options.onProgress]  (status) => void — progress updates.
		 * @param {Function} [options.onError]     (error) => void — error occurred.
		 */
		constructor(options) {
			options = options || {};

			this.webllmClient = options.webllmClient || null;
			this.toolAdapter = options.toolAdapter || null;
			this.tools = options.tools || [];
			this.onResponse = options.onResponse || null;
			this.onToolCall = options.onToolCall || null;
			this.onProgress = options.onProgress || null;
			this.onError = options.onError || null;

			/** Conversation history for context. */
			this._conversation = [];

			/** Whether currently processing. */
			this._processing = false;
		}

		/**
		 * Process a voice transcript — send to AI with tools, execute any
		 * tool calls, and return the final response.
		 *
		 * @param {string} transcript The transcribed text from STT.
		 * @return {Promise<string>} Final AI response text.
		 */
		async processTranscript(transcript) {
			if (!transcript || !transcript.trim()) {
				return '';
			}

			if (this._processing) {
				console.warn('[NV oOS Voice Bridge] Already processing a transcript.');
				return '';
			}

			this._processing = true;
			this._setProgress('Thinking...');

			try {
				// Build messages with conversation context.
				const messages = this._buildMessages(transcript);

				// Send to WebLLM with tool definitions.
				const result = await this._sendToAI(messages);

				this._processing = false;
				return result;
			} catch (err) {
				this._processing = false;
				this._setError(err);
				throw err;
			}
		}

		/**
		 * Set available tools for the AI to use.
		 *
		 * @param {Array} tools Tool definitions.
		 */
		setTools(tools) {
			this.tools = tools || [];
		}

		/**
		 * Clear conversation history.
		 */
		clearHistory() {
			this._conversation = [];
		}

		// ── Private helpers ────────────────────────────────────────────

		/**
		 * Build message array with conversation context.
		 *
		 * @param {string} transcript User's spoken text.
		 * @return {Array} Messages in OpenAI format.
		 */
		_buildMessages(transcript) {
			const messages = [];

			// Add system prompt to encourage tool use for actionable requests.
			messages.push({
				role: 'system',
				content: 'You are a voice assistant. The user is speaking to you. '
					+ 'If the user asks you to perform an action (create a post, search, '
					+ 'get the time, etc.), use the available tools to fulfill the request. '
					+ 'If the user is just chatting, respond naturally. Keep responses '
					+ 'concise since they may be read aloud.',
			});

			// Add recent conversation context (last 6 messages).
			const contextStart = Math.max(0, this._conversation.length - 6);
			for (let i = contextStart; i < this._conversation.length; i++) {
				messages.push(this._conversation[i]);
			}

			// Add the new user message.
			const userMsg = { role: 'user', content: transcript };
			messages.push(userMsg);
			this._conversation.push(userMsg);

			return messages;
		}

		/**
		 * Send messages to WebLLM, handle tool calls in a loop.
		 *
		 * The AI may request tool calls. We execute them and feed results
		 * back to the AI until it produces a final text response.
		 *
		 * @param {Array} messages Message array.
		 * @return {Promise<string>} Final response text.
		 */
		async _sendToAI(messages) {
			const currentMessages = messages.slice();
			let loopCount = 0;
			const maxLoops = 5; // Prevent infinite loops.

			while (loopCount < maxLoops) {
				loopCount++;

				let aiResponse;
				try {
					aiResponse = await this._callWebLLM(currentMessages);
				} catch (err) {
					throw new Error('AI request failed: ' + (err.message || 'Unknown error'));
				}

				// Check if the AI wants to call tools.
				const toolCalls = this._extractToolCalls(aiResponse);

				if (toolCalls.length === 0) {
					// No tool calls — this is the final response.
					const text = this._extractText(aiResponse);
					if (text) {
						const assistantMsg = { role: 'assistant', content: text };
						this._conversation.push(assistantMsg);
					}
					return text;
				}

				// Execute tool calls.
				this._setProgress('Running: ' + toolCalls.map(function (tc) {
					return tc.name;
				}).join(', '));

				const toolResults = await this._executeToolCalls(toolCalls);

				// Add assistant message with tool calls to conversation.
				const assistantMsg = {
					role: 'assistant',
					content: null,
					tool_calls: toolCalls,
				};
				this._conversation.push(assistantMsg);

				// Add tool results to messages for the next AI call.
				currentMessages.push(assistantMsg);
				for (let i = 0; i < toolResults.length; i++) {
					const toolMsg = {
						role: 'tool',
						tool_call_id: toolCalls[i].id,
						content: typeof toolResults[i] === 'string'
							? toolResults[i]
							: JSON.stringify(toolResults[i]),
					};
					currentMessages.push(toolMsg);
					this._conversation.push(toolMsg);
				}
			}

			// If we hit the loop limit, return the last response text.
			return 'I processed your request but it took too many steps.';
		}

		/**
		 * Call the WebLLM client with messages and tools.
		 *
		 * @param {Array} messages Message array.
		 * @return {Promise<Object>} Raw AI response.
		 */
		async _callWebLLM(messages) {
			if (!this.webllmClient) {
				throw new Error('WebLLM client not available.');
			}

			// Use the function calling client if available, otherwise fall back
			// to basic chat completion.
			if (typeof this.webllmClient.chatWithTools === 'function') {
				return this.webllmClient.chatWithTools(messages, this.tools, {
					temperature: 0.7,
					max_tokens: 512,
				});
			}

			if (typeof this.webllmClient.createChatCompletion === 'function') {
				return this.webllmClient.createChatCompletion(messages, {
					temperature: 0.7,
					max_tokens: 512,
				});
			}

			throw new Error('WebLLM client has no compatible chat method.');
		}

		/**
		 * Extract tool calls from an AI response.
		 *
		 * Handles both OpenAI-style tool_calls and WebLLM-specific formats.
		 *
		 * @param {Object} response AI response object.
		 * @return {Array} Array of { id, type: 'function', function: { name, arguments } }.
		 */
		_extractToolCalls(response) {
			if (!response) {
				return [];
			}

			// OpenAI format: response.choices[0].message.tool_calls.
			if (response.choices && response.choices[0] && response.choices[0].message) {
				const msg = response.choices[0].message;
				if (msg.tool_calls && msg.tool_calls.length) {
					return msg.tool_calls;
				}
			}

			// Direct format: response.tool_calls.
			if (response.tool_calls && response.tool_calls.length) {
				return response.tool_calls;
			}

			return [];
		}

		/**
		 * Extract text content from an AI response.
		 *
		 * @param {Object} response AI response object.
		 * @return {string} Response text.
		 */
		_extractText(response) {
			if (!response) {
				return '';
			}

			// OpenAI format.
			if (response.choices && response.choices[0] && response.choices[0].message) {
				return response.choices[0].message.content || '';
			}

			// Direct format.
			if (typeof response.content === 'string') {
				return response.content;
			}

			if (typeof response.text === 'string') {
				return response.text;
			}

			return '';
		}

		/**
		 * Execute multiple tool calls, notifying on each.
		 *
		 * @param {Array} toolCalls Array of tool call objects.
		 * @return {Promise<Array>} Array of result strings/objects.
		 */
		async _executeToolCalls(toolCalls) {
			const self = this;
			const results = [];

			for (let i = 0; i < toolCalls.length; i++) {
				const tc = toolCalls[i];
				const toolName = tc.function ? tc.function.name : tc.name;
				let toolArgs = {};

				try {
					toolArgs = tc.function
						? JSON.parse(tc.function.arguments || '{}')
						: (tc.arguments || {});
				} catch (e) {
					toolArgs = {};
				}

				// Notify listeners.
				if (typeof self.onToolCall === 'function') {
					self.onToolCall(toolName, toolArgs);
				}

				self._setProgress('Running: ' + toolName + '...');

				// Execute the tool.
				let result;
				try {
					if (self.toolAdapter && typeof self.toolAdapter.executeTool === 'function') {
						result = await self.toolAdapter.executeTool(toolName, toolArgs);
					} else {
						result = 'Tool adapter not available for: ' + toolName;
					}
				} catch (err) {
					result = 'Tool execution failed: ' + (err.message || 'Unknown error');
					console.error('[NV oOS Voice Bridge] Tool execution error:', toolName, err);
				}

				results.push(result);
			}

			return results;
		}

		/**
		 * Set progress status.
		 *
		 * @param {string} status Progress message.
		 */
		_setProgress(status) {
			if (typeof this.onProgress === 'function') {
				this.onProgress(status);
			}
		}

		/**
		 * Set error.
		 *
		 * @param {Error} err Error object.
		 */
		_setError(err) {
			if (typeof this.onError === 'function') {
				this.onError(err);
			}
		}
	}

	// Export to global scope.
	window.NV_oOS_VoiceToolCallingBridge = VoiceToolCallingBridge;
})();
