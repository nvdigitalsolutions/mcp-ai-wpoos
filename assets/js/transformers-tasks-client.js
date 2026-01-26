/**
 * Transformers.js Tasks Client
 *
 * Browser-native AI tasks using Hugging Face Transformers.js.
 * Enables instant AI operations without server round-trips.
 *
 * Features:
 * - Text summarization
 * - Sentiment analysis
 * - Named entity recognition (NER)
 * - Text embeddings for semantic search
 * - Translation
 * - Question answering
 * - Zero-shot classification
 *
 * Models are loaded on-demand from Hugging Face CDN and cached in browser.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/* global wpMcpAiTransformers */

(function() {
	'use strict';

	/**
	 * Transformers Tasks Client
	 * Manages pipelines and provides browser-native AI task execution
	 */
	class TransformersTasksClient {
		constructor() {
			this.pipelines = new Map();
			this.isInitialized = false;
			this.loadingPromises = new Map();
			this.config = window.wpMcpAiTransformers || {};

			// Available models configuration
			this.models = {
				summarization: {
					model: 'Xenova/distilbart-cnn-6-6',
					size: '~60MB',
					description: 'Fast summarization model',
				},
				sentiment: {
					model: 'Xenova/distilbert-base-uncased-finetuned-sst-2-english',
					size: '~30MB',
					description: 'Sentiment analysis (positive/negative)',
				},
				ner: {
					model: 'Xenova/bert-base-NER',
					size: '~40MB',
					description: 'Named entity recognition',
				},
				embedding: {
					model: 'Xenova/all-MiniLM-L6-v2',
					size: '~23MB',
					description: 'Text embeddings for semantic search',
				},
				translation: {
					model: 'Xenova/t5-small',
					size: '~60MB',
					description: 'Translation model',
				},
				questionAnswering: {
					model: 'Xenova/distilbert-base-cased-distilled-squad',
					size: '~30MB',
					description: 'Question answering on context',
				},
				zeroShot: {
					model: 'Xenova/distilbert-base-uncased-mnli',
					size: '~30MB',
					description: 'Zero-shot text classification',
				},
			};

			this.log( 'Transformers Tasks Client initialized' );
		}

		/**
		 * Initialize Transformers.js library
		 */
		async initialize() {
			if ( this.isInitialized ) {
				return;
			}

			try {
				// Import Transformers.js dynamically
				if ( typeof window.transformers === 'undefined' ) {
					// Load from CDN if not bundled
					const script = document.createElement( 'script' );
					script.type = 'module';
					script.textContent = `
						import { pipeline, env } from 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.4.0';
						window.transformers = { pipeline, env };
						window.dispatchEvent(new Event('transformers-loaded'));
					`;
					document.head.appendChild( script );

					// Wait for load
					await new Promise( ( resolve ) => {
						window.addEventListener( 'transformers-loaded', resolve, { once: true } );
					} );
				}

				this.isInitialized = true;
				this.log( 'Transformers.js library loaded successfully' );
			} catch ( error ) {
				this.error( 'Failed to initialize Transformers.js:', error );
				throw error;
			}
		}

		/**
		 * Get or create a pipeline
		 *
		 * @param {string} task - Pipeline task type
		 * @param {string} model - Model identifier
		 * @return {Promise<Object>} Pipeline instance
		 */
		async getPipeline( task, model ) {
			const key = `${task}:${model}`;

			// Return cached pipeline
			if ( this.pipelines.has( key ) ) {
				return this.pipelines.get( key );
			}

			// Return in-progress loading promise
			if ( this.loadingPromises.has( key ) ) {
				return this.loadingPromises.get( key );
			}

			// Create new pipeline
			const loadPromise = this.loadPipeline( task, model, key );
			this.loadingPromises.set( key, loadPromise );

			try {
				const pipeline = await loadPromise;
				this.pipelines.set( key, pipeline );
				this.loadingPromises.delete( key );
				return pipeline;
			} catch ( error ) {
				this.loadingPromises.delete( key );
				throw error;
			}
		}

		/**
		 * Load a pipeline
		 *
		 * @param {string} task - Pipeline task
		 * @param {string} model - Model identifier
		 * @param {string} key - Cache key
		 * @return {Promise<Object>} Pipeline instance
		 */
		async loadPipeline( task, model, key ) {
			await this.initialize();

			this.log( `Loading pipeline: ${key}` );

			try {
				const pipeline = await window.transformers.pipeline( task, model, {
					// Use cached models in browser storage
					cache_dir: '.cache/transformers',
					// Show progress
					progress_callback: ( progress ) => {
						this.onProgress( key, progress );
					},
				} );

				this.log( `Pipeline loaded: ${key}` );
				return pipeline;
			} catch ( error ) {
				this.error( `Failed to load pipeline ${key}:`, error );
				throw error;
			}
		}

		/**
		 * Summarize text
		 *
		 * @param {string} text - Text to summarize
		 * @param {Object} options - Summarization options
		 * @return {Promise<Object>} Summarization result
		 */
		async summarize( text, options = {} ) {
			const model = this.models.summarization.model;
			const summarizer = await this.getPipeline( 'summarization', model );

			const result = await summarizer( text, {
				max_length: options.maxLength || 130,
				min_length: options.minLength || 30,
				do_sample: false,
			} );

			return {
				summary: result[ 0 ].summary_text,
				originalLength: text.length,
				summaryLength: result[ 0 ].summary_text.length,
			};
		}

		/**
		 * Analyze sentiment
		 *
		 * @param {string} text - Text to analyze
		 * @return {Promise<Object>} Sentiment result
		 */
		async analyzeSentiment( text ) {
			const model = this.models.sentiment.model;
			const classifier = await this.getPipeline( 'sentiment-analysis', model );

			const result = await classifier( text );

			return {
				label: result[ 0 ].label.toLowerCase(),
				score: result[ 0 ].score,
				confidence: ( result[ 0 ].score * 100 ).toFixed( 2 ) + '%',
			};
		}

		/**
		 * Extract named entities (NER)
		 *
		 * @param {string} text - Text to analyze
		 * @return {Promise<Array>} Named entities
		 */
		async extractEntities( text ) {
			const model = this.models.ner.model;
			const ner = await this.getPipeline( 'token-classification', model );

			const result = await ner( text );

			// Group entities by type
			const entities = {};
			result.forEach( ( entity ) => {
				const type = entity.entity.replace( /^[BI]-/, '' );
				if ( ! entities[ type ] ) {
					entities[ type ] = [];
				}
				entities[ type ].push( {
					text: entity.word,
					score: entity.score,
				} );
			} );

			return entities;
		}

		/**
		 * Generate text embeddings
		 *
		 * @param {string} text - Text to embed
		 * @return {Promise<Array>} Embedding vector
		 */
		async embed( text ) {
			const model = this.models.embedding.model;
			const embedder = await this.getPipeline( 'feature-extraction', model );

			const result = await embedder( text, {
				pooling: 'mean',
				normalize: true,
			} );

			return Array.from( result.data );
		}

		/**
		 * Translate text
		 *
		 * @param {string} text - Text to translate
		 * @param {string} targetLang - Target language code
		 * @return {Promise<string>} Translated text
		 */
		async translate( text, targetLang = 'fr' ) {
			const model = this.models.translation.model;
			const translator = await this.getPipeline( 'translation', model );

			const result = await translator( text, {
				tgt_lang: targetLang,
			} );

			return result[ 0 ].translation_text;
		}

		/**
		 * Answer question based on context
		 *
		 * @param {string} question - Question to answer
		 * @param {string} context - Context text
		 * @return {Promise<Object>} Answer result
		 */
		async answerQuestion( question, context ) {
			const model = this.models.questionAnswering.model;
			const qa = await this.getPipeline( 'question-answering', model );

			const result = await qa( question, context );

			return {
				answer: result.answer,
				score: result.score,
				confidence: ( result.score * 100 ).toFixed( 2 ) + '%',
				start: result.start,
				end: result.end,
			};
		}

		/**
		 * Zero-shot text classification
		 *
		 * @param {string} text - Text to classify
		 * @param {Array} labels - Possible labels
		 * @return {Promise<Array>} Classification results
		 */
		async classify( text, labels ) {
			const model = this.models.zeroShot.model;
			const classifier = await this.getPipeline( 'zero-shot-classification', model );

			const result = await classifier( text, labels );

			return result.labels.map( ( label, idx ) => ( {
				label,
				score: result.scores[ idx ],
				confidence: ( result.scores[ idx ] * 100 ).toFixed( 2 ) + '%',
			} ) );
		}

		/**
		 * Progress callback for model loading
		 *
		 * @param {string} key - Pipeline key
		 * @param {Object} progress - Progress data
		 */
		onProgress( key, progress ) {
			if ( progress.status === 'progress' ) {
				const percent = ( progress.progress / progress.total * 100 ).toFixed( 1 );
				this.log( `Loading ${key}: ${percent}%` );
			}
		}

		/**
		 * Log message (if debug enabled)
		 *
		 * @param {...*} args - Log arguments
		 */
		log( ...args ) {
			if ( this.config.debug || window.WP_MCP_AI_DEBUG ) {
				console.log( '[NV oOS Transformers]', ...args );
			}
		}

		/**
		 * Log error
		 *
		 * @param {...*} args - Error arguments
		 */
		error( ...args ) {
			console.error( '[NV oOS Transformers]', ...args );
		}

		/**
		 * Get available models info
		 *
		 * @return {Object} Models configuration
		 */
		getModelsInfo() {
			return this.models;
		}

		/**
		 * Check if a pipeline is loaded
		 *
		 * @param {string} task - Task type
		 * @return {boolean} Whether pipeline is loaded
		 */
		isPipelineLoaded( task ) {
			for ( const key of this.pipelines.keys() ) {
				if ( key.startsWith( task + ':' ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Unload a pipeline to free memory
		 *
		 * @param {string} task - Task type to unload
		 */
		unloadPipeline( task ) {
			const keysToDelete = [];
			for ( const key of this.pipelines.keys() ) {
				if ( key.startsWith( task + ':' ) ) {
					keysToDelete.push( key );
				}
			}

			keysToDelete.forEach( ( key ) => {
				this.pipelines.delete( key );
				this.log( `Unloaded pipeline: ${key}` );
			} );
		}

		/**
		 * Clear all pipelines
		 */
		clearAll() {
			this.pipelines.clear();
			this.log( 'All pipelines cleared' );
		}
	}

	// Export to global scope
	window.WP_MCP_AI_TransformersClient = TransformersTasksClient;

	// Auto-initialize if config present
	if ( window.wpMcpAiTransformers && window.wpMcpAiTransformers.autoInit ) {
		window.WP_MCP_AI_Transformers = new TransformersTasksClient();
	}

})();
