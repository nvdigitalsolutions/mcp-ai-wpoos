/**
 * Client-Side Vector Store
 *
 * Browser-native vector storage and semantic search using Transformers.js embeddings.
 * Enables RAG (Retrieval-Augmented Generation) without server dependencies.
 *
 * Features:
 * - Text embedding generation
 * - Cosine similarity search
 * - IndexedDB persistence
 * - Incremental document addition
 * - Efficient vector operations
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/* global wpMcpAiTransformers */

(function() {
	'use strict';

	/**
	 * Client Vector Store
	 * Manages document embeddings and semantic search in browser
	 */
	class ClientVectorStore {
		constructor( storeName = 'wp-mcp-ai-vectors' ) {
			this.storeName = storeName;
			this.documents = [];
			this.embeddings = [];
			this.embedder = null;
			this.db = null;
			this.isInitialized = false;
			this.config = window.wpMcpAiTransformers || {};
		}

		/**
		 * Initialize vector store
		 */
		async initialize() {
			if ( this.isInitialized ) {
				return;
			}

			try {
				// Initialize embedding model
				if ( window.WP_MCP_AI_Transformers ) {
					this.embedder = window.WP_MCP_AI_Transformers;
				} else {
					throw new Error( 'Transformers client not available' );
				}

				// Initialize IndexedDB
				await this.initIndexedDB();

				// Load existing vectors from storage
				await this.loadFromStorage();

				this.isInitialized = true;
				this.log( 'Vector store initialized' );
			} catch ( error ) {
				this.error( 'Failed to initialize vector store:', error );
				throw error;
			}
		}

		/**
		 * Initialize IndexedDB for persistence
		 */
		async initIndexedDB() {
			return new Promise( ( resolve, reject ) => {
				const request = indexedDB.open( this.storeName, 1 );

				request.onerror = () => {
					reject( new Error( 'Failed to open IndexedDB' ) );
				};

				request.onsuccess = ( event ) => {
					this.db = event.target.result;
					resolve();
				};

				request.onupgradeneeded = ( event ) => {
					const db = event.target.result;

					// Create object store if it doesn't exist
					if ( ! db.objectStoreNames.contains( 'documents' ) ) {
						const objectStore = db.createObjectStore( 'documents', {
							keyPath: 'id',
							autoIncrement: true,
						} );
						objectStore.createIndex( 'timestamp', 'timestamp', { unique: false } );
					}
				};
			} );
		}

		/**
		 * Load vectors from IndexedDB
		 */
		async loadFromStorage() {
			return new Promise( ( resolve, reject ) => {
				const transaction = this.db.transaction( [ 'documents' ], 'readonly' );
				const objectStore = transaction.objectStore( 'documents' );
				const request = objectStore.getAll();

				request.onsuccess = ( event ) => {
					const stored = event.target.result;
					if ( stored && stored.length > 0 ) {
						stored.forEach( ( item ) => {
							this.documents.push( item.document );
							this.embeddings.push( item.embedding );
						} );
						this.log( `Loaded ${stored.length} documents from storage` );
					}
					resolve();
				};

				request.onerror = () => {
					reject( new Error( 'Failed to load from storage' ) );
				};
			} );
		}

		/**
		 * Add documents to vector store
		 *
		 * @param {Array} docs - Array of document objects { text, metadata }
		 * @return {Promise<number>} Number of documents added
		 */
		async addDocuments( docs ) {
			await this.initialize();

			let addedCount = 0;

			for ( const doc of docs ) {
				try {
					// Generate embedding
					const embedding = await this.embedder.embed( doc.text );

					// Add to in-memory store
					const document = {
						text: doc.text,
						metadata: doc.metadata || {},
						timestamp: Date.now(),
					};
					this.documents.push( document );
					this.embeddings.push( embedding );

					// Persist to IndexedDB
					await this.persistDocument( document, embedding );

					addedCount++;
					this.log( `Added document ${addedCount}/${docs.length}` );
				} catch ( error ) {
					this.error( 'Failed to add document:', error );
				}
			}

			return addedCount;
		}

		/**
		 * Persist document to IndexedDB
		 *
		 * @param {Object} document - Document object
		 * @param {Array} embedding - Embedding vector
		 */
		async persistDocument( document, embedding ) {
			return new Promise( ( resolve, reject ) => {
				const transaction = this.db.transaction( [ 'documents' ], 'readwrite' );
				const objectStore = transaction.objectStore( 'documents' );

				const request = objectStore.add( {
					document,
					embedding,
				} );

				request.onsuccess = () => resolve();
				request.onerror = () => reject( new Error( 'Failed to persist document' ) );
			} );
		}

		/**
		 * Search for similar documents
		 *
		 * @param {string} query - Query text
		 * @param {number} k - Number of results to return
		 * @param {Object} options - Search options
		 * @return {Promise<Array>} Similar documents with scores
		 */
		async search( query, k = 5, options = {} ) {
			await this.initialize();

			if ( this.documents.length === 0 ) {
				return [];
			}

			// Generate query embedding
			const queryEmbedding = await this.embedder.embed( query );

			// Calculate similarity scores
			const scores = this.embeddings.map( ( embedding, idx ) => ( {
				index: idx,
				score: this.cosineSimilarity( queryEmbedding, embedding ),
				document: this.documents[ idx ],
			} ) );

			// Filter by minimum score if specified
			let filtered = scores;
			if ( options.minScore ) {
				filtered = scores.filter( ( s ) => s.score >= options.minScore );
			}

			// Sort by score (descending)
			filtered.sort( ( a, b ) => b.score - a.score );

			// Return top k results
			return filtered.slice( 0, k ).map( ( result ) => ( {
				text: result.document.text,
				metadata: result.document.metadata,
				score: result.score,
				similarity: ( result.score * 100 ).toFixed( 2 ) + '%',
			} ) );
		}

		/**
		 * Calculate cosine similarity between two vectors
		 *
		 * @param {Array} a - First vector
		 * @param {Array} b - Second vector
		 * @return {number} Similarity score (0-1)
		 */
		cosineSimilarity( a, b ) {
			if ( a.length !== b.length ) {
				throw new Error( 'Vectors must have same length' );
			}

			let dotProduct = 0;
			let magnitudeA = 0;
			let magnitudeB = 0;

			for ( let i = 0; i < a.length; i++ ) {
				dotProduct += a[ i ] * b[ i ];
				magnitudeA += a[ i ] * a[ i ];
				magnitudeB += b[ i ] * b[ i ];
			}

			magnitudeA = Math.sqrt( magnitudeA );
			magnitudeB = Math.sqrt( magnitudeB );

			if ( magnitudeA === 0 || magnitudeB === 0 ) {
				return 0;
			}

			return dotProduct / ( magnitudeA * magnitudeB );
		}

		/**
		 * Clear all documents from vector store
		 */
		async clear() {
			this.documents = [];
			this.embeddings = [];

			// Clear IndexedDB
			return new Promise( ( resolve, reject ) => {
				const transaction = this.db.transaction( [ 'documents' ], 'readwrite' );
				const objectStore = transaction.objectStore( 'documents' );
				const request = objectStore.clear();

				request.onsuccess = () => {
					this.log( 'Vector store cleared' );
					resolve();
				};

				request.onerror = () => {
					reject( new Error( 'Failed to clear vector store' ) );
				};
			} );
		}

		/**
		 * Get number of documents in store
		 *
		 * @return {number} Document count
		 */
		size() {
			return this.documents.length;
		}

		/**
		 * Get document by index
		 *
		 * @param {number} index - Document index
		 * @return {Object|null} Document or null
		 */
		getDocument( index ) {
			return this.documents[ index ] || null;
		}

		/**
		 * Remove document by index
		 *
		 * @param {number} index - Document index
		 * @return {Promise<boolean>} Success status
		 */
		async removeDocument( index ) {
			if ( index < 0 || index >= this.documents.length ) {
				return false;
			}

			this.documents.splice( index, 1 );
			this.embeddings.splice( index, 1 );

			// Note: This doesn't remove from IndexedDB by index
			// Would need to track IDs for proper removal
			this.log( `Removed document at index ${index}` );

			return true;
		}

		/**
		 * Log message (if debug enabled)
		 *
		 * @param {...*} args - Log arguments
		 */
		log( ...args ) {
			if ( this.config.debug || window.WP_MCP_AI_DEBUG ) {
				console.log( '[NV oOS Vector Store]', ...args );
			}
		}

		/**
		 * Log error
		 *
		 * @param {...*} args - Error arguments
		 */
		error( ...args ) {
			console.error( '[NV oOS Vector Store]', ...args );
		}
	}

	// Export to global scope
	window.WP_MCP_AI_ClientVectorStore = ClientVectorStore;

	// Auto-initialize default store if config present
	if ( window.wpMcpAiTransformers && window.wpMcpAiTransformers.autoInitVectorStore ) {
		window.WP_MCP_AI_VectorStore = new ClientVectorStore();
	}

})();
