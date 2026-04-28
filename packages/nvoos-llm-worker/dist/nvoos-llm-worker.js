/**
 * LLM Worker Manager
 *
 * Manages Web Worker lifecycle for LLM operations.
 * Provides high-level API for worker communication.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/* global Worker */



/**
 * LLM Worker Manager
 *
 * Manages a Web Worker for non-blocking LLM operations
 */
class LLMWorkerManager {
	constructor( options = {} ) {
		this.config = {
			workerUrl: options.workerUrl || null,
			workerOptions: options.workerOptions || { type: 'module' }
		};
this.worker = null;
this.listeners = new Map();
this.isInitialized = false;
this.isWorkerReady = false;
this.messageQueue = [];

console.log( '[nvoos-llm-worker] Initialized' );
}

/**
 * Check if Web Workers are supported
 *
 * @return {boolean} True if supported
 */
isSupported() {
return typeof Worker !== 'undefined';
}

	/**
	 * Configure the worker manager.
	 * @param {Object} options
	 * @param {string} [options.workerUrl] URL to the LLM worker script.
	 * @param {Object} [options.workerOptions] Options forwarded to the Worker constructor.
	 */
	configure( options = {} ) {
		if ( options.workerUrl ) {
			this.config.workerUrl = options.workerUrl;
		}
		if ( options.workerOptions ) {
			this.config.workerOptions = options.workerOptions;
		}
	}



/**
 * Create and initialize the worker
 *
 * @return {Promise<void>}
 */
async createWorker() {
if ( ! this.isSupported() ) {
throw new Error( 'Web Workers not supported in this browser' );
}

if ( this.worker ) {
console.warn( '[nvoos-llm-worker] Worker already created' );
return;
}

try {
// Create worker from separate file
const workerUrl = this.getWorkerUrl();
this.worker = new Worker( workerUrl, this.config.workerOptions );

// Set up message handler
this.worker.addEventListener( 'message', this.handleMessage.bind( this ) );

// Set up error handler
this.worker.addEventListener( 'error', this.handleError.bind( this ) );

// Wait for worker_ready message
await this.waitForWorkerReady();

this.isInitialized = true;
console.log( '[nvoos-llm-worker] Worker created and ready' );
} catch ( error ) {
console.error( '[nvoos-llm-worker] Failed to create worker:', error );
this.worker = null;
throw error;
}
}

/**
 * Get worker script URL
 *
 * @return {string} Worker URL
 */
getWorkerUrl() {
		if ( ! this.config.workerUrl ) {
			throw new Error( 'nvoos-llm-worker: workerUrl is not configured. Call configure({ workerUrl }) first.' );
		}
		return this.config.workerUrl;
	}

/**
 * Wait for worker to be ready
 *
 * @return {Promise<void>}
 */
waitForWorkerReady() {
return new Promise( ( resolve, reject ) => {
const timeout = setTimeout( () => {
reject( new Error( 'Worker ready timeout' ) );
}, 10000 );

const readyListener = () => {
clearTimeout( timeout );
this.isWorkerReady = true;
resolve();
};

this.listeners.set( 'worker_ready', readyListener );
} );
}

/**
 * Load model in worker
 *
 * @param {string} modelId - Model ID to load
 * @param {Function} onProgress - Progress callback
 * @return {Promise<void>}
 */
async loadModel( modelId, onProgress ) {
if ( ! this.worker ) {
throw new Error( 'Worker not created' );
}

return new Promise( ( resolve, reject ) => {
const timeout = setTimeout( () => {
reject( new Error( 'Model load timeout' ) );
}, 300000 ); // 5 minutes

const progressListener = ( data ) => {
if ( onProgress ) {
onProgress( data );
}
};

const readyListener = ( data ) => {
clearTimeout( timeout );
this.listeners.delete( 'progress' );
this.listeners.delete( 'ready' );
console.log( '[nvoos-llm-worker] Model loaded:', data.modelId );
resolve();
};

const errorListener = ( data ) => {
clearTimeout( timeout );
this.listeners.delete( 'progress' );
this.listeners.delete( 'ready' );
reject( new Error( data.message ) );
};

this.listeners.set( 'progress', progressListener );
this.listeners.set( 'ready', readyListener );
this.listeners.set( 'error', errorListener );

this.worker.postMessage( {
type: 'init',
data: { modelId }
} );
} );
}

/**
 * Generate text with streaming
 *
 * @param {Array} messages - Chat messages
 * @param {Object} options - Generation options
 * @param {Function} onChunk - Chunk callback
 * @return {Promise<string>} Complete generated text
 */
async generate( messages, options, onChunk ) {
if ( ! this.worker ) {
throw new Error( 'Worker not created' );
}

return new Promise( ( resolve, reject ) => {
let fullText = '';

const chunkListener = ( data ) => {
if ( data.content ) {
fullText += data.content;
if ( onChunk ) {
onChunk( data );
}
}
};

const doneListener = () => {
this.listeners.delete( 'chunk' );
this.listeners.delete( 'done' );
this.listeners.delete( 'error' );
resolve( fullText );
};

const errorListener = ( data ) => {
this.listeners.delete( 'chunk' );
this.listeners.delete( 'done' );
this.listeners.delete( 'error' );
reject( new Error( data.message ) );
};

this.listeners.set( 'chunk', chunkListener );
this.listeners.set( 'done', doneListener );
this.listeners.set( 'error', errorListener );

this.worker.postMessage( {
type: 'generate',
data: { messages, options }
} );
} );
}

/**
 * Unload model and free resources
 *
 * @return {Promise<void>}
 */
async unloadModel() {
if ( ! this.worker ) {
return;
}

return new Promise( ( resolve ) => {
const unloadedListener = () => {
this.listeners.delete( 'unloaded' );
console.log( '[nvoos-llm-worker] Model unloaded' );
resolve();
};

this.listeners.set( 'unloaded', unloadedListener );

this.worker.postMessage( {
type: 'unload',
data: {}
} );

// Resolve anyway after timeout
setTimeout( resolve, 5000 );
} );
}

/**
 * Get runtime statistics
 *
 * @return {Promise<string>} Stats text
 */
async getStats() {
if ( ! this.worker ) {
throw new Error( 'Worker not created' );
}

return new Promise( ( resolve, reject ) => {
const timeout = setTimeout( () => {
reject( new Error( 'Stats timeout' ) );
}, 5000 );

const statsListener = ( data ) => {
clearTimeout( timeout );
this.listeners.delete( 'stats' );
resolve( data.stats );
};

const errorListener = ( data ) => {
clearTimeout( timeout );
this.listeners.delete( 'stats' );
reject( new Error( data.message ) );
};

this.listeners.set( 'stats', statsListener );
this.listeners.set( 'error', errorListener );

this.worker.postMessage( {
type: 'stats',
data: {}
} );
} );
}

/**
 * Terminate the worker
 */
terminate() {
if ( this.worker ) {
this.worker.terminate();
this.worker = null;
this.isInitialized = false;
this.isWorkerReady = false;
this.listeners.clear();
console.log( '[nvoos-llm-worker] Worker terminated' );
}
}

/**
 * Handle messages from worker
 *
 * @param {MessageEvent} event - Message event
 */
handleMessage( event ) {
const { type, data } = event.data;

const listener = this.listeners.get( type );
if ( listener ) {
listener( data );
} else {
console.log( '[nvoos-llm-worker] Unhandled message:', type, data );
}
}

/**
 * Handle worker errors
 *
 * @param {ErrorEvent} event - Error event
 */
handleError( event ) {
console.error( '[nvoos-llm-worker] Worker error:', event );

const errorListener = this.listeners.get( 'error' );
if ( errorListener ) {
errorListener( {
message: event.message,
filename: event.filename,
lineno: event.lineno
} );
}
}

/**
 * Check if worker is ready
 *
 * @return {boolean} Ready state
 */
isReady() {
return this.isInitialized && this.isWorkerReady && this.worker !== null;
}
}

// ES Module exports
export { LLMWorkerManager };
export default LLMWorkerManager;
