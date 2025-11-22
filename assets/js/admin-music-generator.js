/**
 * Gemini Music Generator Admin UI
 *
 * Provides an interactive interface for real-time music generation using
 * Google Gemini's Lyria RealTime model.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

/* global wpMcpAi, GeminiMusicService, MusicGenerationMode */

(function() {
	'use strict';

	/**
	 * Music Generator UI Controller
	 */
	class MusicGeneratorUI {
		/**
		 * Constructor
		 */
		constructor() {
			this.musicService = null;
			this.isInitialized = false;
			this.elements = {};
			this.promptInputs = [];
		}

		/**
		 * Initialize the UI
		 */
		async init() {
			if (this.isInitialized) {
				return;
			}

			// Get DOM elements
			this.cacheElements();

			// Initialize music service
			this.musicService = new GeminiMusicService();

			// Set up event listeners
			this.setupEventListeners();

			// Initialize with API key from settings
			const apiKey = this.getGeminiApiKey();
			if (apiKey) {
				try {
					await this.musicService.initialize(apiKey);
					this.showStatus('Ready to generate music', 'success');
				} catch (error) {
					this.showStatus('Failed to initialize: ' + error.message, 'error');
				}
			} else {
				this.showStatus('Please configure Gemini API key in settings', 'warning');
			}

			this.isInitialized = true;
		}

		/**
		 * Cache DOM elements
		 */
		cacheElements() {
			this.elements = {
				// Connection controls
				connectBtn: document.getElementById('music-connect-btn'),
				disconnectBtn: document.getElementById('music-disconnect-btn'),
				statusDisplay: document.getElementById('music-status'),

				// Prompts
				promptsContainer: document.getElementById('music-prompts-container'),
				addPromptBtn: document.getElementById('add-prompt-btn'),

				// Configuration
				bpmSlider: document.getElementById('music-bpm'),
				bpmValue: document.getElementById('music-bpm-value'),
				densitySlider: document.getElementById('music-density'),
				densityValue: document.getElementById('music-density-value'),
				temperatureSlider: document.getElementById('music-temperature'),
				temperatureValue: document.getElementById('music-temperature-value'),
				modeSelect: document.getElementById('music-mode'),

				// Playback controls
				playBtn: document.getElementById('music-play-btn'),
				pauseBtn: document.getElementById('music-pause-btn'),
				stopBtn: document.getElementById('music-stop-btn'),
				resetBtn: document.getElementById('music-reset-btn'),

				// Export
				exportBtn: document.getElementById('music-export-btn'),

				// Audio visualization
				audioVisualizer: document.getElementById('music-visualizer')
			};
		}

		/**
		 * Set up event listeners
		 */
		setupEventListeners() {
			// Connection controls
			if (this.elements.connectBtn) {
				this.elements.connectBtn.addEventListener('click', () => this.connect());
			}

			if (this.elements.disconnectBtn) {
				this.elements.disconnectBtn.addEventListener('click', () => this.disconnect());
			}

			// Add prompt button
			if (this.elements.addPromptBtn) {
				this.elements.addPromptBtn.addEventListener('click', () => this.addPromptInput());
			}

			// Configuration sliders
			if (this.elements.bpmSlider) {
				this.elements.bpmSlider.addEventListener('input', (e) => {
					this.elements.bpmValue.textContent = e.target.value;
					this.updateConfig();
				});
			}

			if (this.elements.densitySlider) {
				this.elements.densitySlider.addEventListener('input', (e) => {
					this.elements.densityValue.textContent = (parseFloat(e.target.value)).toFixed(2);
					this.updateConfig();
				});
			}

			if (this.elements.temperatureSlider) {
				this.elements.temperatureSlider.addEventListener('input', (e) => {
					this.elements.temperatureValue.textContent = (parseFloat(e.target.value)).toFixed(1);
					this.updateConfig();
				});
			}

			if (this.elements.modeSelect) {
				this.elements.modeSelect.addEventListener('change', () => this.updateConfig());
			}

			// Playback controls
			if (this.elements.playBtn) {
				this.elements.playBtn.addEventListener('click', () => this.play());
			}

			if (this.elements.pauseBtn) {
				this.elements.pauseBtn.addEventListener('click', () => this.pause());
			}

			if (this.elements.stopBtn) {
				this.elements.stopBtn.addEventListener('click', () => this.stop());
			}

			if (this.elements.resetBtn) {
				this.elements.resetBtn.addEventListener('click', () => this.resetContext());
			}

			// Export
			if (this.elements.exportBtn) {
				this.elements.exportBtn.addEventListener('click', () => this.exportAudio());
			}

			// Music service events
			this.setupServiceListeners();
		}

		/**
		 * Set up music service event listeners
		 */
		setupServiceListeners() {
			if (!this.musicService) {
				return;
			}

			this.musicService.on('stateChange', (data) => {
				this.updateUIState(data.newState);
			});

			this.musicService.on('error', (data) => {
				this.showStatus('Error: ' + data.message, 'error');
				console.error('Music service error:', data);
			});

			this.musicService.on('connected', () => {
				this.showStatus('Connected to music generation service', 'success');
			});

			this.musicService.on('playing', () => {
				this.showStatus('Generating music...', 'info');
			});

			this.musicService.on('paused', () => {
				this.showStatus('Paused', 'info');
			});

			this.musicService.on('stopped', () => {
				this.showStatus('Stopped', 'info');
			});

			this.musicService.on('audioChunk', (data) => {
				this.updateVisualizer(data);
			});
		}

		/**
		 * Connect to music generation session
		 */
		async connect() {
			try {
				this.showStatus('Connecting...', 'info');
				await this.musicService.connect();

				// Set initial prompts and config
				await this.updatePrompts();
				await this.updateConfig();
			} catch (error) {
				this.showStatus('Connection failed: ' + error.message, 'error');
			}
		}

		/**
		 * Disconnect from session
		 */
		async disconnect() {
			try {
				await this.musicService.disconnect();
				this.showStatus('Disconnected', 'info');
			} catch (error) {
				this.showStatus('Disconnect failed: ' + error.message, 'error');
			}
		}

		/**
		 * Update weighted prompts
		 */
		async updatePrompts() {
			const prompts = this.getPromptsFromInputs();

			if (prompts.length === 0) {
				this.showStatus('Please add at least one prompt', 'warning');
				return;
			}

			try {
				await this.musicService.setWeightedPrompts(prompts);
			} catch (error) {
				this.showStatus('Failed to update prompts: ' + error.message, 'error');
			}
		}

		/**
		 * Update music configuration
		 */
		async updateConfig() {
			if (!this.musicService || this.musicService.getState() === 'disconnected') {
				return;
			}

			const config = {
				bpm: parseInt(this.elements.bpmSlider.value, 10),
				density: parseFloat(this.elements.densitySlider.value),
				temperature: parseFloat(this.elements.temperatureSlider.value),
				mode: this.elements.modeSelect.value
			};

			try {
				await this.musicService.setConfig(config);
			} catch (error) {
				this.showStatus('Failed to update config: ' + error.message, 'error');
			}
		}

		/**
		 * Start music generation
		 */
		async play() {
			try {
				// Update prompts before playing
				await this.updatePrompts();
				await this.musicService.play();
			} catch (error) {
				this.showStatus('Failed to start: ' + error.message, 'error');
			}
		}

		/**
		 * Pause music generation
		 */
		async pause() {
			try {
				await this.musicService.pause();
			} catch (error) {
				this.showStatus('Failed to pause: ' + error.message, 'error');
			}
		}

		/**
		 * Stop music generation
		 */
		async stop() {
			try {
				await this.musicService.stop();
			} catch (error) {
				this.showStatus('Failed to stop: ' + error.message, 'error');
			}
		}

		/**
		 * Reset context
		 */
		async resetContext() {
			try {
				await this.musicService.resetContext();
				this.showStatus('Context reset', 'success');
			} catch (error) {
				this.showStatus('Failed to reset: ' + error.message, 'error');
			}
		}

		/**
		 * Export generated audio
		 */
		exportAudio() {
			try {
				const filename = 'gemini-music-' + Date.now() + '.mp3';
				this.musicService.exportAudio(filename);
				this.showStatus('Audio exported', 'success');
			} catch (error) {
				this.showStatus('Export failed: ' + error.message, 'error');
			}
		}

		/**
		 * Add prompt input field
		 */
		addPromptInput() {
			const container = this.elements.promptsContainer;
			const index = this.promptInputs.length;

			const promptRow = document.createElement('div');
			promptRow.className = 'music-prompt-row';
			promptRow.innerHTML = `
				<input type="text" 
					   class="music-prompt-text" 
					   placeholder="e.g., Harmonica, Jazz, Upbeat" 
					   data-index="${index}">
				<input type="number" 
					   class="music-prompt-weight" 
					   min="0" 
					   max="1" 
					   step="0.1" 
					   value="0.5" 
					   data-index="${index}">
				<button type="button" class="button remove-prompt-btn" data-index="${index}">Remove</button>
			`;

			container.appendChild(promptRow);
			this.promptInputs.push(promptRow);

			// Add remove listener
			promptRow.querySelector('.remove-prompt-btn').addEventListener('click', () => {
				this.removePromptInput(index);
			});
		}

		/**
		 * Remove prompt input field
		 *
		 * @param {number} index - Prompt index
		 */
		removePromptInput(index) {
			const promptRow = this.promptInputs[index];
			if (promptRow) {
				promptRow.remove();
				this.promptInputs[index] = null;
			}
		}

		/**
		 * Get prompts from input fields
		 *
		 * @returns {Array<{text: string, weight: number}>} Prompts array
		 */
		getPromptsFromInputs() {
			const prompts = [];

			this.promptInputs.forEach((row) => {
				if (!row) return;

				const textInput = row.querySelector('.music-prompt-text');
				const weightInput = row.querySelector('.music-prompt-weight');

				const text = textInput.value.trim();
				const weight = parseFloat(weightInput.value);

				if (text && weight > 0) {
					prompts.push({ text, weight });
				}
			});

			return prompts;
		}

		/**
		 * Update UI based on state
		 *
		 * @param {string} state - Session state
		 */
		updateUIState(state) {
			// Enable/disable buttons based on state
			const connected = state !== 'disconnected';
			const playing = state === 'playing';

			if (this.elements.connectBtn) {
				this.elements.connectBtn.disabled = connected;
			}

			if (this.elements.disconnectBtn) {
				this.elements.disconnectBtn.disabled = !connected;
			}

			if (this.elements.playBtn) {
				this.elements.playBtn.disabled = !connected || playing;
			}

			if (this.elements.pauseBtn) {
				this.elements.pauseBtn.disabled = !playing;
			}

			if (this.elements.stopBtn) {
				this.elements.stopBtn.disabled = !connected;
			}

			if (this.elements.resetBtn) {
				this.elements.resetBtn.disabled = !connected;
			}

			if (this.elements.exportBtn) {
				this.elements.exportBtn.disabled = !connected;
			}
		}

		/**
		 * Show status message
		 *
		 * @param {string} message - Status message
		 * @param {string} type - Message type (success, error, warning, info)
		 */
		showStatus(message, type = 'info') {
			if (!this.elements.statusDisplay) {
				return;
			}

			this.elements.statusDisplay.textContent = message;
			this.elements.statusDisplay.className = 'music-status music-status-' + type;
		}

		/**
		 * Update audio visualizer
		 *
		 * @param {Object} data - Audio chunk data
		 */
		updateVisualizer(data) {
			if (!this.elements.audioVisualizer) {
				return;
			}

			// Simple visualization - update progress bar or similar
			const progress = (data.totalChunks * 10) + '%';
			this.elements.audioVisualizer.style.width = progress;
		}

		/**
		 * Get Gemini API key from WordPress settings
		 *
		 * @returns {string|null} API key or null
		 */
		getGeminiApiKey() {
			// Try to get from wpMcpAi global
			if (typeof wpMcpAi !== 'undefined' && wpMcpAi.geminiApiKey) {
				return wpMcpAi.geminiApiKey;
			}

			return null;
		}
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			const musicUI = new MusicGeneratorUI();
			musicUI.init();
			window.musicGeneratorUI = musicUI;
		});
	} else {
		const musicUI = new MusicGeneratorUI();
		musicUI.init();
		window.musicGeneratorUI = musicUI;
	}

})();
