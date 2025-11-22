/**
 * Gemini Music Generation Service (JavaScript/WebSocket)
 *
 * Provides real-time music generation using Google Gemini's Lyria RealTime model.
 * Uses the official @google/genai JavaScript SDK for WebSocket-based streaming.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

/* global wpMcpAi */

/**
 * Music generation modes
 */
const MusicGenerationMode = {
	QUALITY: 'quality',
	SPEED: 'speed',
	BALANCED: 'balanced'
};

/**
 * Session states
 */
const SessionState = {
	DISCONNECTED: 'disconnected',
	CONNECTING: 'connecting',
	CONNECTED: 'connected',
	PLAYING: 'playing',
	PAUSED: 'paused',
	ERROR: 'error'
};

/**
 * Gemini Music Generation Service
 * 
 * Handles real-time music generation using WebSocket connection to Lyria RealTime.
 */
class GeminiMusicService {
	/**
	 * Constructor
	 */
	constructor() {
		this.session = null;
		this.state = SessionState.DISCONNECTED;
		this.audioContext = null;
		this.audioChunks = [];
		this.listeners = new Map();
		this.config = {
			bpm: 120,
			density: 0.5,
			temperature: 1.0,
			mode: MusicGenerationMode.BALANCED
		};
		this.weightedPrompts = [];
	}

	/**
	 * Initialize the service with API key
	 * 
	 * @param {string} apiKey - Gemini API key
	 * @returns {Promise<void>}
	 */
	async initialize(apiKey) {
		if (!apiKey) {
			throw new Error('Gemini API key is required for music generation.');
		}

		try {
			// Dynamic import of @google/genai
			const { GoogleGenerativeAI } = await import('@google/genai');
			this.genAI = new GoogleGenerativeAI(apiKey);
			this.emit('initialized', { success: true });
		} catch (error) {
			this.emit('error', { 
				message: 'Failed to initialize Gemini SDK', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Connect to Lyria RealTime session
	 * 
	 * @returns {Promise<void>}
	 */
	async connect() {
		if (this.state === SessionState.CONNECTED || this.state === SessionState.PLAYING) {
			console.warn('Already connected or playing');
			return;
		}

		this.setState(SessionState.CONNECTING);
		this.emit('connecting');

		try {
			// Create WebSocket session for Lyria RealTime
			// Note: This is conceptual - actual implementation depends on SDK version
			this.session = await this.genAI.models.createMusicSession({
				model: 'models/lyria-realtime-exp'
			});

			// Set up audio context for playback
			this.audioContext = new (window.AudioContext || window.webkitAudioContext)();

			// Set up event listeners
			this.setupSessionListeners();

			this.setState(SessionState.CONNECTED);
			this.emit('connected', { session: this.session });

		} catch (error) {
			this.setState(SessionState.ERROR);
			this.emit('error', { 
				message: 'Failed to connect to music generation service', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Set weighted prompts for music style
	 * 
	 * @param {Array<{text: string, weight: number}>} prompts - Weighted prompts
	 * @returns {Promise<void>}
	 */
	async setWeightedPrompts(prompts) {
		if (!this.session) {
			throw new Error('Not connected to music generation session');
		}

		if (!Array.isArray(prompts) || prompts.length === 0) {
			throw new Error('Weighted prompts must be a non-empty array');
		}

		// Validate prompts
		prompts.forEach((prompt, index) => {
			if (!prompt.text || typeof prompt.weight !== 'number') {
				throw new Error(`Invalid prompt at index ${index}: must have text and weight`);
			}
			if (prompt.weight < 0 || prompt.weight > 1) {
				throw new Error(`Invalid weight at index ${index}: must be between 0 and 1`);
			}
		});

		this.weightedPrompts = prompts;

		try {
			await this.session.setMusicGenerationConfig({
				weightedPrompts: prompts
			});

			this.emit('promptsUpdated', { prompts });
		} catch (error) {
			this.emit('error', { 
				message: 'Failed to set weighted prompts', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Update music generation configuration
	 * 
	 * @param {Object} config - Configuration object
	 * @param {number} [config.bpm] - Beats per minute (40-200)
	 * @param {number} [config.density] - Note density (0-1)
	 * @param {number} [config.temperature] - Creativity temperature (0-2)
	 * @param {string} [config.mode] - Generation mode (quality, speed, balanced)
	 * @returns {Promise<void>}
	 */
	async setConfig(config) {
		if (!this.session) {
			throw new Error('Not connected to music generation session');
		}

		// Validate and merge config
		const newConfig = { ...this.config };

		if (config.bpm !== undefined) {
			if (config.bpm < 40 || config.bpm > 200) {
				throw new Error('BPM must be between 40 and 200');
			}
			newConfig.bpm = config.bpm;
		}

		if (config.density !== undefined) {
			if (config.density < 0 || config.density > 1) {
				throw new Error('Density must be between 0 and 1');
			}
			newConfig.density = config.density;
		}

		if (config.temperature !== undefined) {
			if (config.temperature < 0 || config.temperature > 2) {
				throw new Error('Temperature must be between 0 and 2');
			}
			newConfig.temperature = config.temperature;
		}

		if (config.mode !== undefined) {
			if (!Object.values(MusicGenerationMode).includes(config.mode)) {
				throw new Error('Invalid generation mode');
			}
			newConfig.mode = config.mode;
		}

		this.config = newConfig;

		try {
			await this.session.setMusicGenerationConfig({
				musicGenerationConfig: {
					bpm: newConfig.bpm,
					density: newConfig.density,
					temperature: newConfig.temperature,
					musicGenerationMode: newConfig.mode
				}
			});

			this.emit('configUpdated', { config: newConfig });
		} catch (error) {
			this.emit('error', { 
				message: 'Failed to update music configuration', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Start music generation and playback
	 * 
	 * @returns {Promise<void>}
	 */
	async play() {
		if (!this.session) {
			throw new Error('Not connected to music generation session');
		}

		if (this.state === SessionState.PLAYING) {
			console.warn('Already playing');
			return;
		}

		try {
			await this.session.play();
			this.setState(SessionState.PLAYING);
			this.emit('playing');
		} catch (error) {
			this.emit('error', { 
				message: 'Failed to start music generation', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Pause music generation
	 * 
	 * @returns {Promise<void>}
	 */
	async pause() {
		if (!this.session) {
			throw new Error('Not connected to music generation session');
		}

		if (this.state !== SessionState.PLAYING) {
			console.warn('Not currently playing');
			return;
		}

		try {
			await this.session.pause();
			this.setState(SessionState.PAUSED);
			this.emit('paused');
		} catch (error) {
			this.emit('error', { 
				message: 'Failed to pause music generation', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Stop music generation
	 * 
	 * @returns {Promise<void>}
	 */
	async stop() {
		if (!this.session) {
			throw new Error('Not connected to music generation session');
		}

		try {
			await this.session.stop();
			this.setState(SessionState.CONNECTED);
			this.audioChunks = [];
			this.emit('stopped');
		} catch (error) {
			this.emit('error', { 
				message: 'Failed to stop music generation', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Reset context (clear generation history)
	 * 
	 * @returns {Promise<void>}
	 */
	async resetContext() {
		if (!this.session) {
			throw new Error('Not connected to music generation session');
		}

		try {
			await this.session.reset_context();
			this.audioChunks = [];
			this.emit('contextReset');
		} catch (error) {
			this.emit('error', { 
				message: 'Failed to reset context', 
				error: error.message 
			});
			throw error;
		}
	}

	/**
	 * Disconnect from session
	 * 
	 * @returns {Promise<void>}
	 */
	async disconnect() {
		if (this.session) {
			try {
				await this.session.close();
			} catch (error) {
				console.error('Error closing session:', error);
			}
			this.session = null;
		}

		if (this.audioContext) {
			await this.audioContext.close();
			this.audioContext = null;
		}

		this.audioChunks = [];
		this.setState(SessionState.DISCONNECTED);
		this.emit('disconnected');
	}

	/**
	 * Export generated audio as downloadable file
	 * 
	 * @param {string} filename - Desired filename
	 * @returns {Blob} Audio blob
	 */
	exportAudio(filename = 'generated-music.mp3') {
		if (this.audioChunks.length === 0) {
			throw new Error('No audio generated to export');
		}

		// Combine audio chunks into single blob
		const blob = new Blob(this.audioChunks, { type: 'audio/mp3' });

		// Create download link
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);

		return blob;
	}

	/**
	 * Set up session event listeners
	 * 
	 * @private
	 */
	setupSessionListeners() {
		if (!this.session) {
			return;
		}

		// Listen for audio chunks
		this.session.on('audio', (chunk) => {
			this.handleAudioChunk(chunk);
		});

		// Listen for errors
		this.session.on('error', (error) => {
			this.setState(SessionState.ERROR);
			this.emit('error', { 
				message: 'Session error', 
				error: error.message 
			});
		});

		// Listen for session end
		this.session.on('end', () => {
			this.setState(SessionState.CONNECTED);
			this.emit('generationComplete');
		});
	}

	/**
	 * Handle incoming audio chunk
	 * 
	 * @private
	 * @param {ArrayBuffer} chunk - Audio chunk data
	 */
	handleAudioChunk(chunk) {
		this.audioChunks.push(chunk);

		// Decode and play audio chunk
		if (this.audioContext) {
			this.audioContext.decodeAudioData(
				chunk.slice(0),
				(buffer) => {
					const source = this.audioContext.createBufferSource();
					source.buffer = buffer;
					source.connect(this.audioContext.destination);
					source.start();
				},
				(error) => {
					console.error('Error decoding audio chunk:', error);
				}
			);
		}

		this.emit('audioChunk', { chunk, totalChunks: this.audioChunks.length });
	}

	/**
	 * Set session state
	 * 
	 * @private
	 * @param {string} state - New state
	 */
	setState(state) {
		const oldState = this.state;
		this.state = state;
		this.emit('stateChange', { oldState, newState: state });
	}

	/**
	 * Add event listener
	 * 
	 * @param {string} event - Event name
	 * @param {Function} callback - Callback function
	 */
	on(event, callback) {
		if (!this.listeners.has(event)) {
			this.listeners.set(event, []);
		}
		this.listeners.get(event).push(callback);
	}

	/**
	 * Remove event listener
	 * 
	 * @param {string} event - Event name
	 * @param {Function} callback - Callback function
	 */
	off(event, callback) {
		if (!this.listeners.has(event)) {
			return;
		}
		const callbacks = this.listeners.get(event);
		const index = callbacks.indexOf(callback);
		if (index > -1) {
			callbacks.splice(index, 1);
		}
	}

	/**
	 * Emit event
	 * 
	 * @private
	 * @param {string} event - Event name
	 * @param {*} data - Event data
	 */
	emit(event, data) {
		if (!this.listeners.has(event)) {
			return;
		}
		this.listeners.get(event).forEach(callback => {
			try {
				callback(data);
			} catch (error) {
				console.error(`Error in ${event} listener:`, error);
			}
		});
	}

	/**
	 * Get current state
	 * 
	 * @returns {string} Current state
	 */
	getState() {
		return this.state;
	}

	/**
	 * Get current configuration
	 * 
	 * @returns {Object} Current configuration
	 */
	getConfig() {
		return { ...this.config };
	}

	/**
	 * Get weighted prompts
	 * 
	 * @returns {Array} Current weighted prompts
	 */
	getWeightedPrompts() {
		return [...this.weightedPrompts];
	}
}

// Export for use in WordPress
if (typeof window !== 'undefined') {
	window.GeminiMusicService = GeminiMusicService;
	window.MusicGenerationMode = MusicGenerationMode;
	window.SessionState = SessionState;
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
	module.exports = {
		GeminiMusicService,
		MusicGenerationMode,
		SessionState
	};
}
