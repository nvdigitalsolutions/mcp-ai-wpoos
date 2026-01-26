/**
 * Transformers.js Tasks Client
 *
 * Browser-native AI tasks using HuggingFace Transformers.js
 * No server round-trip needed - instant AI operations in the browser
 *
 * Phase 2: Transformers.js Integration
 * - Summarization (text summary generation)
 * - Sentiment analysis (positive/negative detection)
 * - Named Entity Recognition (extract entities from text)
 * - Translation (multi-language translation)
 * - Question Answering (extract answers from context)
 * - Semantic Search (vector embeddings for search)
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/* eslint-disable camelcase, no-console */

/**
 * Transformers Tasks Client Class
 *
 * Manages browser-native AI tasks using HuggingFace models.
 * Models are lazy-loaded and cached for performance.
 */
class WP_MCP_AI_TransformersTasksClient {
	/**
	 * Constructor
	 */
	constructor() {
		this.pipelines = new Map();
		this.loadingStates = new Map();
		this.modelCache = new Map();
		this.isInitialized = false;
		this.transformersModule = null;
		
		// Model configurations
		this.models = {
			summarization: 'Xenova/distilbart-cnn-6-6',
			sentiment: 'Xenova/distilbert-base-uncased-finetuned-sst-2-english',
			ner: 'Xenova/bert-base-NER',
			translation: 'Xenova/nllb-200-distilled-600M',
			qa: 'Xenova/distilbert-base-uncased-distilled-squad',
			embedding: 'Xenova/all-MiniLM-L6-v2'
		};
		
		this.log( 'TransformersTasksClient initialized' );
	}

	/**
	 * Load Transformers.js library from CDN
	 *
	 * @return {Promise<void>}
	 */
	async loadTransformers() {
		if ( this.transformersModule ) {
			return this.transformersModule;
		}

		if ( this.isInitialized ) {
			// Wait for ongoing initialization
			return new Promise( ( resolve ) => {
				const checkInterval = setInterval( () => {
					if ( this.transformersModule ) {
						clearInterval( checkInterval );
						resolve( this.transformersModule );
					}
				}, 100 );
			} );
		}

		this.isInitialized = true;
		this.log( 'Loading Transformers.js from CDN...' );

		try {
			// Load from esm.run CDN
			const module = await import( 'https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2' );
			this.transformersModule = module;
			this.log( 'Transformers.js loaded successfully' );
			return module;
		} catch ( error ) {
			this.error( 'Failed to load Transformers.js', error );
			this.isInitialized = false;
			throw error;
		}
	}

	/**
	 * Get or create a pipeline
	 *
	 * @param {string} task - Pipeline task (e.g., 'summarization')
	 * @param {string} model - Model identifier
	 * @return {Promise<Object>} Pipeline instance
	 */
	async getPipeline( task, model ) {
		const cacheKey = `${ task }:${ model }`;

		// Return cached pipeline if exists
		if ( this.pipelines.has( cacheKey ) ) {
			return this.pipelines.get( cacheKey );
		}

		// Check if already loading
		if ( this.loadingStates.has( cacheKey ) ) {
			return this.loadingStates.get( cacheKey );
		}

		// Load Transformers.js if not loaded
		const transformers = await this.loadTransformers();

		// Create loading promise
		const loadingPromise = ( async () => {
			try {
				this.log( `Loading pipeline: ${ task } with model: ${ model }` );
				
				const pipeline = await transformers.pipeline( task, model, {
					// Use quantized models for faster loading
					quantized: true,
					// Progress callback for loading feedback
					progress_callback: ( progress ) => {
						if ( progress.status === 'downloading' ) {
							const percent = Math.round( ( progress.loaded / progress.total ) * 100 );
							this.log( `Downloading ${ model }: ${ percent }%` );
						}
					}
				} );

				this.pipelines.set( cacheKey, pipeline );
				this.loadingStates.delete( cacheKey );
				this.log( `Pipeline loaded: ${ task }` );
				
				return pipeline;
			} catch ( error ) {
				this.error( `Failed to load pipeline: ${ task }`, error );
				this.loadingStates.delete( cacheKey );
				throw error;
			}
		} )();

		this.loadingStates.set( cacheKey, loadingPromise );
		return loadingPromise;
	}

	/**
	 * Summarize text
	 *
	 * @param {string} text - Text to summarize
	 * @param {Object} options - Summarization options
	 * @param {number} options.maxLength - Maximum summary length (default: 130)
	 * @param {number} options.minLength - Minimum summary length (default: 30)
	 * @return {Promise<Object>} Summary result
	 */
	async summarize( text, options = {} ) {
		const pipeline = await this.getPipeline( 'summarization', this.models.summarization );
		
		const result = await pipeline( text, {
			max_length: options.maxLength || 130,
			min_length: options.minLength || 30,
			do_sample: false
		} );

		return {
			success: true,
			summary: result[ 0 ].summary_text,
			originalLength: text.length,
			summaryLength: result[ 0 ].summary_text.length
		};
	}

	/**
	 * Analyze sentiment
	 *
	 * @param {string} text - Text to analyze
	 * @return {Promise<Object>} Sentiment result
	 */
	async sentiment( text ) {
		const pipeline = await this.getPipeline( 'sentiment-analysis', this.models.sentiment );
		
		const result = await pipeline( text );

		return {
			success: true,
			label: result[ 0 ].label,
			score: result[ 0 ].score,
			confidence: Math.round( result[ 0 ].score * 100 ) + '%'
		};
	}

	/**
	 * Extract named entities
	 *
	 * @param {string} text - Text to analyze
	 * @return {Promise<Object>} Named entities
	 */
	async extractEntities( text ) {
		const pipeline = await this.getPipeline( 'token-classification', this.models.ner );
		
		const result = await pipeline( text );

		// Group consecutive tokens of same entity
		const entities = [];
		let currentEntity = null;

		result.forEach( ( token ) => {
			if ( token.entity.startsWith( 'B-' ) ) {
				// Begin new entity
				if ( currentEntity ) {
					entities.push( currentEntity );
				}
				currentEntity = {
					text: token.word,
					type: token.entity.substring( 2 ),
					score: token.score
				};
			} else if ( token.entity.startsWith( 'I-' ) && currentEntity ) {
				// Continue current entity
				currentEntity.text += token.word;
			}
		} );

		if ( currentEntity ) {
			entities.push( currentEntity );
		}

		return {
			success: true,
			entities: entities,
			count: entities.length
		};
	}

	/**
	 * Translate text
	 *
	 * @param {string} text - Text to translate
	 * @param {Object} options - Translation options
	 * @param {string} options.sourceLang - Source language code (e.g., 'eng_Latn')
	 * @param {string} options.targetLang - Target language code (e.g., 'fra_Latn')
	 * @return {Promise<Object>} Translation result
	 */
	async translate( text, options = {} ) {
		const pipeline = await this.getPipeline( 'translation', this.models.translation );
		
		const result = await pipeline( text, {
			src_lang: options.sourceLang || 'eng_Latn',
			tgt_lang: options.targetLang || 'fra_Latn'
		} );

		return {
			success: true,
			translatedText: result[ 0 ].translation_text,
			sourceLang: options.sourceLang || 'eng_Latn',
			targetLang: options.targetLang || 'fra_Latn'
		};
	}

	/**
	 * Answer question based on context
	 *
	 * @param {string} question - Question to answer
	 * @param {string} context - Context containing the answer
	 * @return {Promise<Object>} Answer result
	 */
	async questionAnswering( question, context ) {
		const pipeline = await this.getPipeline( 'question-answering', this.models.qa );
		
		const result = await pipeline( question, context );

		return {
			success: true,
			answer: result.answer,
			score: result.score,
			confidence: Math.round( result.score * 100 ) + '%',
			start: result.start,
			end: result.end
		};
	}

	/**
	 * Generate embeddings for semantic search
	 *
	 * @param {string|Array} text - Text or array of texts to embed
	 * @return {Promise<Object>} Embedding result
	 */
	async embed( text ) {
		const pipeline = await this.getPipeline( 'feature-extraction', this.models.embedding );
		
		const result = await pipeline( text, {
			pooling: 'mean',
			normalize: true
		} );

		// Convert tensor to array
		const embeddings = Array.isArray( text ) ? result : [ result ];

		return {
			success: true,
			embeddings: embeddings.map( e => Array.from( e.data ) ),
			dimensions: embeddings[ 0 ].dims[ 1 ]
		};
	}

	/**
	 * Check if a task is available
	 *
	 * @param {string} task - Task name
	 * @return {boolean} True if task is supported
	 */
	isTaskAvailable( task ) {
		return Object.keys( this.models ).includes( task );
	}

	/**
	 * Get available tasks
	 *
	 * @return {Array<string>} List of available tasks
	 */
	getAvailableTasks() {
		return Object.keys( this.models );
	}

	/**
	 * Clear all cached pipelines
	 */
	clearCache() {
		this.pipelines.clear();
		this.modelCache.clear();
		this.log( 'Pipeline cache cleared' );
	}

	/**
	 * Log message
	 *
	 * @param {string} message - Message to log
	 */
	log( message ) {
		if ( typeof console !== 'undefined' && console.log ) {
			console.log( '[NV oOS Transformers]', message );
		}
	}

	/**
	 * Log error
	 *
	 * @param {string} message - Error message
	 * @param {Error} error - Error object
	 */
	error( message, error ) {
		if ( typeof console !== 'undefined' && console.error ) {
			console.error( '[NV oOS Transformers]', message, error );
		}
	}
}

// Initialize global instance
if ( typeof window !== 'undefined' ) {
	window.WP_MCP_AI_TransformersTasksClient = WP_MCP_AI_TransformersTasksClient;
	window.WP_MCP_AI_Transformers = new WP_MCP_AI_TransformersTasksClient();
	
	// Log availability
	console.log( '[NV oOS] Transformers.js client ready for browser-native AI tasks' );
}
