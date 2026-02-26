/**
 * LLM Web Worker
 *
 * Offloads heavy AI computation to a Web Worker to prevent UI blocking.
 * Handles model loading, inference, and streaming responses in background thread.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.0.0
 */

/* global self */

/**
 * Web Worker for LLM operations
 *
 * This worker runs in a separate thread and handles:
 * - Model initialization and loading
 * - Text generation with streaming
 * - Model unloading and cleanup
 *
 * Communication via postMessage:
 * - Receives: { type: 'init|generate|unload', data: {...} }
 * - Sends: { type: 'ready|progress|chunk|done|error', data: {...} }
 */

let engine = null;
let isInitializing = false;

/**
 * Initialize WebLLM engine
 *
 * @param {Object} data - Initialization data
 * @param {string} data.modelId - Model ID to load
 * @param {Object} data.config - Optional configuration
 */
async function initializeEngine( data ) {
if ( isInitializing ) {
self.postMessage( {
type: 'error',
data: { message: 'Engine is already initializing' }
} );
return;
}

if ( engine ) {
self.postMessage( {
type: 'error',
data: { message: 'Engine already initialized' }
} );
return;
}

try {
isInitializing = true;

// Dynamically import WebLLM from CDN
const { CreateMLCEngine } = await import( 'https://esm.run/@mlc-ai/web-llm@0.2.80' );

// Create engine with progress callback
engine = await CreateMLCEngine( data.modelId, {
initProgressCallback: ( progress ) => {
self.postMessage( {
type: 'progress',
data: progress
} );
},
...( data.config || {} )
} );

isInitializing = false;

self.postMessage( {
type: 'ready',
data: { modelId: data.modelId }
} );
} catch ( error ) {
isInitializing = false;
engine = null;

self.postMessage( {
type: 'error',
data: {
message: error.message,
stack: error.stack,
phase: 'initialization'
}
} );
}
}

/**
 * Generate text with streaming
 *
 * @param {Object} data - Generation data
 * @param {Array} data.messages - Chat messages
 * @param {Object} data.options - Generation options
 */
async function generateText( data ) {
if ( ! engine ) {
self.postMessage( {
type: 'error',
data: { message: 'Engine not initialized' }
} );
return;
}

try {
const options = {
messages: data.messages,
stream: true,
temperature: data.options?.temperature || 0.7,
max_tokens: data.options?.max_tokens || 512,
...( data.options || {} )
};

const response = await engine.chat.completions.create( options );

// Stream chunks back to main thread
for await ( const chunk of response ) {
const content = chunk.choices[ 0 ]?.delta?.content;
const toolCalls = chunk.choices[ 0 ]?.delta?.tool_calls;
const finishReason = chunk.choices[ 0 ]?.finish_reason;

if ( content || toolCalls || finishReason ) {
self.postMessage( {
type: 'chunk',
data: {
content: content || '',
tool_calls: toolCalls,
finish_reason: finishReason
}
} );
}
}

self.postMessage( {
type: 'done',
data: { completed: true }
} );
} catch ( error ) {
self.postMessage( {
type: 'error',
data: {
message: error.message,
stack: error.stack,
phase: 'generation'
}
} );
}
}

/**
 * Unload the engine and free resources
 */
async function unloadEngine() {
if ( ! engine ) {
self.postMessage( {
type: 'unloaded',
data: { wasLoaded: false }
} );
return;
}

try {
await engine.unload();
engine = null;

self.postMessage( {
type: 'unloaded',
data: { wasLoaded: true }
} );
} catch ( error ) {
// Still clear the engine reference even if unload fails
engine = null;

self.postMessage( {
type: 'error',
data: {
message: error.message,
stack: error.stack,
phase: 'unload'
}
} );
}
}

/**
 * Get runtime statistics
 */
async function getRuntimeStats() {
if ( ! engine ) {
self.postMessage( {
type: 'error',
data: { message: 'Engine not initialized' }
} );
return;
}

try {
const stats = await engine.runtimeStatsText();

self.postMessage( {
type: 'stats',
data: { stats }
} );
} catch ( error ) {
self.postMessage( {
type: 'error',
data: {
message: error.message,
stack: error.stack,
phase: 'stats'
}
} );
}
}

/**
 * Main message handler
 *
 * Kept synchronous at the listener level to avoid the Chromium
 * "message channel closed before a response was received" error that
 * occurs when an async listener implicitly returns a Promise.  All
 * async work is performed inside a self-invoking async function whose
 * rejection is caught and forwarded as an 'error' message.
 */
self.addEventListener( 'message', ( event ) => {
const { type, data } = event.data;

( async () => {
switch ( type ) {
case 'init':
await initializeEngine( data );
break;

case 'generate':
await generateText( data );
break;

case 'unload':
await unloadEngine();
break;

case 'stats':
await getRuntimeStats();
break;

case 'ping':
self.postMessage( {
type: 'pong',
data: { ready: !! engine }
} );
break;

default:
self.postMessage( {
type: 'error',
data: { message: `Unknown message type: ${type}` }
} );
}
} )().catch( ( error ) => {
self.postMessage( {
type: 'error',
data: {
message: error.message,
stack: error.stack,
phase: 'message_handler'
}
} );
} );
} );

/**
 * Handle worker errors
 */
self.addEventListener( 'error', ( event ) => {
self.postMessage( {
type: 'error',
data: {
message: event.message,
filename: event.filename,
lineno: event.lineno,
colno: event.colno,
phase: 'worker_error'
}
} );
} );

/**
 * Handle unhandled promise rejections
 */
self.addEventListener( 'unhandledrejection', ( event ) => {
self.postMessage( {
type: 'error',
data: {
message: event.reason?.message || 'Unhandled promise rejection',
stack: event.reason?.stack,
phase: 'unhandled_rejection'
}
} );
} );

// Signal that worker is ready to receive messages
self.postMessage( {
type: 'worker_ready',
data: { timestamp: Date.now() }
} );
