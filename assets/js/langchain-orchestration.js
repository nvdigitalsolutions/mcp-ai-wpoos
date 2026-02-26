/**
 * LangChain.js Orchestration Client for WordPress
 *
 * Provides multi-step reasoning, chains, agents, and memory management
 * using LangChain.js integrated with WebLLM for browser-first AI orchestration.
 *
 * Best practices implemented (v1.1.0):
 * - Dynamic ESM import() instead of window.langchain check (fixes CDN ESM detection)
 * - Exponential-backoff retry on all LLM and tool calls
 * - ConversationBufferWindowMemory with localStorage persistence
 * - Token streaming via OpenAI-compatible stream API
 * - ReAct-style Thought/Action/Observation agent loop
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.1.0
 */

/* global wpMcpAiChat, wpMcpAiLangChain */

(function() {
'use strict';

/**
 * Async retry with exponential backoff.
 * Mirrors LangChain's withRetry() pattern.
 *
 * @param {Function} fn         Async function to attempt
 * @param {number}   maxRetries Maximum retry count (default 3)
 * @param {number}   baseDelay  Base delay ms (default 500)
 * @return {Promise<any>}
 */
async function withRetry( fn, maxRetries, baseDelay ) {
maxRetries = maxRetries || 3;
baseDelay  = baseDelay  || 500;
let last;
for ( let i = 0; i <= maxRetries; i++ ) {
try { return await fn(); } catch ( e ) {
last = e;
if ( i < maxRetries ) {
await new Promise( r => setTimeout( r, Math.min( baseDelay * Math.pow( 2, i ), 10000 ) ) );
}
}
}
throw last;
}

// Export so langchain-tool-adapter (loaded first) can reuse without duplication.
window.WP_MCP_AI_LangChain_withRetry = withRetry;

/**
 * LangChain Orchestrator for browser-side AI workflows.
 *
 * Enables:
 * - Multi-step reasoning chains with memory
 * - ReAct-style agent tool orchestration
 * - Conversation memory with sliding window + localStorage persistence
 * - Token streaming with onToken callback
 * - Sequential and parallel execution
 */
class WP_MCP_AI_LangChain_Orchestrator {
/**
 * @param {Object} webllmEngine WebLLM engine (OpenAI-compatible API)
 * @param {Object} config       Optional config overrides
 */
constructor( webllmEngine, config ) {
this.webllmEngine = webllmEngine;
this.tools        = [];
this.initialized  = false;
this._lc          = null;

const php = ( typeof wpMcpAiLangChain !== 'undefined' ) ? wpMcpAiLangChain : {};
this.config = Object.assign(
{ maxIterations: 10, maxRetries: 3, memoryWindowK: 10, enableStreaming: false, verbose: false },
php,
config || {}
);

// onToken callback for streaming UIs.
this.onToken = null;

this.memory = this._createMemory( this.config.memoryWindowK );
console.log( '[NV oOS LangChain] Orchestrator constructed' );
}

/**
 * Lazy-load LangChain ESM modules via dynamic import().
 *
 * ESM modules loaded from CDN do NOT set window.langchain, so
 * checking typeof window.langchain is unreliable. Dynamic import()
 * is the correct approach for CDN-hosted ESM bundles.
 *
 * @return {Promise<Object|null>}
 */
async _loadLangChain() {
if ( this._lc ) { return this._lc; }
const urls = ( typeof wpMcpAiLangChain !== 'undefined' && wpMcpAiLangChain.cdnUrls )
? wpMcpAiLangChain.cdnUrls
: { core: 'https://cdn.jsdelivr.net/npm/@langchain/core/+esm' };
try {
this._lc = { core: await import( urls.core ) };
if ( urls.langchain ) {
try { this._lc.lc = await import( urls.langchain ); } catch ( _ ) { /* optional */ }
}
console.log( '[NV oOS LangChain] ESM modules loaded' );
} catch ( e ) {
console.warn( '[NV oOS LangChain] ESM import failed; running standalone.', e );
this._lc = null;
}
return this._lc;
}

/**
 * Initialize the orchestrator (loads ESM + validates engine).
 *
 * @return {Promise<void>}
 */
async initialize() {
if ( this.initialized ) { return; }
if ( ! this.webllmEngine ) {
throw new Error( '[NV oOS LangChain] WebLLM engine not provided.' );
}
await this._loadLangChain();
this.initialized = true;
console.log( '[NV oOS LangChain] Initialized' );
}

/**
 * ConversationBufferWindowMemory with localStorage persistence.
 *
 * Mirrors LangChain's ConversationBufferWindowMemory(k=N): retains the
 * last k turn-pairs and persists across page loads via localStorage (24h TTL).
 *
 * @param {number} k Window size in turn-pairs
 * @return {Object}
 */
_createMemory( k ) {
const key = 'wp_mcp_ai_lc_memory';
const ttl = 864e5; // 24 h
const max = ( k || 10 ) * 2;
let messages = [];

// Restore from localStorage if not expired.
try {
const s = JSON.parse( localStorage.getItem( key ) || 'null' );
if ( s && s.expires > Date.now() ) { messages = s.messages || []; }
} catch ( _ ) { /* unavailable */ }

const persist = () => {
try { localStorage.setItem( key, JSON.stringify( { messages, expires: Date.now() + ttl } ) ); }
catch ( _ ) { /* full */ }
};

return {
messages,
addMessage( role, content ) {
this.messages.push( { role, content } );
if ( this.messages.length > max ) { this.messages = this.messages.slice( -max ); }
persist();
},
getMessages() { return this.messages; },
formatContext() {
return this.messages.map( m => ( m.role === 'user' ? 'Human' : 'AI' ) + ': ' + m.content ).join( '\n' );
},
clear() {
this.messages = [];
try { localStorage.removeItem( key ); } catch ( _ ) { /* ok */ }
},
};
}

/**
 * Call WebLLM with retry and optional token streaming.
 *
 * Streaming follows the OpenAI streaming protocol; each delta token is
 * forwarded to this.onToken (or options.onToken) as it arrives.
 *
 * @param {Array}  messages Chat messages
 * @param {Object} opts     {stream, onToken}
 * @return {Promise<string>}
 */
async _callLLM( messages, opts ) {
opts = opts || {};
const streaming = opts.stream !== undefined ? opts.stream : this.config.enableStreaming;
const onToken   = opts.onToken || this.onToken;

return withRetry( async () => {
if ( streaming && onToken ) {
const stream = await this.webllmEngine.chat.completions.create( { messages, stream: true } );
let text = '';
for await ( const chunk of stream ) {
const delta = ( chunk.choices[ 0 ] || {} ).delta;
const t = delta ? ( delta.content || '' ) : '';
if ( t ) { text += t; onToken( t ); }
}
return text;
}
const r = await this.webllmEngine.chat.completions.create( { messages, stream: false } );
return r.choices[ 0 ].message.content;
}, this.config.maxRetries );
}

/**
 * Create a simple chain with template.
 *
 * Injects {chat_history} from memory when the placeholder is present,
 * or prepends conversation context automatically.
 *
 * @param {string} template  Prompt template with {variables}
 * @param {Object} variables Variables to substitute
 * @return {Promise<string>}
 */
async createChain( template, variables ) {
if ( ! this.initialized ) { await this.initialize(); }
variables = variables || {};
try {
let prompt = template;
for ( const [ k, v ] of Object.entries( variables ) ) {
prompt = prompt.replace( new RegExp( '\\{' + k + '\\}', 'g' ), String( v ) );
}

if ( this.memory.messages.length > 0 ) {
if ( template.includes( '{chat_history}' ) ) {
prompt = prompt.replace( '{chat_history}', this.memory.formatContext() );
} else {
prompt = 'Chat history:\n' + this.memory.formatContext() + '\n\nCurrent:\n' + prompt;
}
}

const messages = [
{ role: 'system', content: 'You are a helpful AI assistant.' },
{ role: 'user', content: prompt },
];
const result = await this._callLLM( messages );

this.memory.addMessage( 'user', variables.input || prompt );
this.memory.addMessage( 'assistant', result );
return result;
} catch ( error ) {
console.error( '[NV oOS LangChain] Chain execution failed:', error );
throw error;
}
}

/**
 * Execute multiple chains sequentially, piping output of each step to the next.
 * Mirrors LangChain's RunnableSequence / SequentialChain pattern.
 *
 * @param {Array<{template: string, variables: Object}>} steps
 * @return {Promise<string[]>}
 */
async createSequentialChain( steps ) {
if ( ! this.initialized ) { await this.initialize(); }
const results = [];
let prev = '';
for ( let i = 0; i < steps.length; i++ ) {
console.log( '[NV oOS LangChain] Sequential step ' + ( i + 1 ) + '/' + steps.length );
const vars = Object.assign( {}, steps[ i ].variables, { previous_result: prev } );
const r = await this.createChain( steps[ i ].template, vars );
results.push( r );
prev = r;
}
return results;
}

/**
 * Register WordPress tools for agent use.
 *
 * @param {Array} wpTools Tool definitions
 */
setTools( wpTools ) {
this.tools = wpTools;
console.log( '[NV oOS LangChain] Registered ' + this.tools.length + ' tools' );
}

/**
 * Run a ReAct-style agent (Thought -> Action -> Observation loop).
 *
 * Implements the ReAct paper pattern used by LangChain's AgentExecutor:
 * the model reasons in "Thought:" steps, calls tools via "Action:" steps,
 * and receives "Observation:" results before continuing to the next step.
 *
 * @param {string} task    Task description
 * @param {Object} options {maxIterations, verbose, onToken, stream}
 * @return {Promise<{success: boolean, result: string, iterations: number, executionLog: Array}>}
 */
async createAgent( task, options ) {
if ( ! this.initialized ) { await this.initialize(); }
options = options || {};
const maxIter = options.maxIterations || this.config.maxIterations;
const verbose = options.verbose || this.config.verbose;

const systemPrompt =
'WordPress AI agent. Tools:\n\n' +
this.tools.map( t =>
'Tool: ' + t.name + '\nDesc: ' + t.description +
'\nSchema: ' + JSON.stringify( t.schema || {} )
).join( '\n\n' ) +
'\n\nFormat:\nThought: [reason]\nAction: [tool]\nAction Input: [JSON]\nObservation: [result]\n\n' +
'Final: Thought: Done.\nFinal Answer: [answer]\n\nOne tool per turn. Action Input = valid JSON.';

const messages = [
{ role: 'system', content: systemPrompt },
{ role: 'user', content: task },
];

let iteration = 0;
const executionLog = [];

try {
while ( iteration < maxIter ) {
iteration++;
if ( verbose ) { console.log( '[NV oOS LangChain] Agent iteration ' + iteration ); }

const agentResponse = await this._callLLM( messages, {
stream: options.stream,
onToken: options.onToken || this.onToken,
} );

executionLog.push( { iteration, type: 'thought', content: agentResponse } );

const finalMatch = agentResponse.match( /Final Answer:\s*([\s\S]+)$/i );
if ( finalMatch ) {
const result = finalMatch[ 1 ].trim();
this.memory.addMessage( 'user', task );
this.memory.addMessage( 'assistant', result );
return { success: true, result, iterations: iteration, executionLog };
}

const actionMatch      = agentResponse.match( /Action:\s*(.+)/i );
const actionInputMatch = agentResponse.match( /Action Input:\s*([\s\S]+?)(?=\n(?:Thought|Action|Observation|$))/i );

if ( actionMatch ) {
const toolName = actionMatch[ 1 ].trim();
let toolArgs = {};
if ( actionInputMatch ) {
try { toolArgs = JSON.parse( actionInputMatch[ 1 ].trim() ); } catch ( _ ) { /* invalid JSON */ }
}

const toolResult = await this.executeTool( toolName, toolArgs );
executionLog.push( { iteration, type: 'tool_call', tool: toolName, args: toolArgs, result: toolResult } );

messages.push( { role: 'assistant', content: agentResponse } );
messages.push( { role: 'user', content: 'Observation: ' + JSON.stringify( toolResult ) } );
} else {
// No action found - treat as final answer.
this.memory.addMessage( 'user', task );
this.memory.addMessage( 'assistant', agentResponse );
return { success: true, result: agentResponse, iterations: iteration, executionLog };
}
}

console.warn( '[NV oOS LangChain] Agent reached max iterations' );
return { success: false, error: 'Max iterations reached', iterations: iteration, executionLog };
} catch ( error ) {
console.error( '[NV oOS LangChain] Agent execution failed:', error );
return { success: false, error: error.message, executionLog };
}
}

/**
 * Execute a WordPress tool (client-side or server-side) with retry.
 *
 * @param {string} toolName Tool name or slug
 * @param {Object} args     Tool arguments
 * @return {Promise<any>}
 */
async executeTool( toolName, args ) {
if ( this.config.verbose ) { console.log( '[NV oOS LangChain] Executing tool:', toolName, args ); }

const tool = this.tools.find( t => t.name === toolName || t.slug === toolName );

if ( tool && tool.client_executable && window.WP_MCP_AI_Transformers ) {
try {
return await withRetry(
() => window.WP_MCP_AI_Transformers.executeClientTool( toolName, args ),
this.config.maxRetries
);
} catch ( _ ) { /* fall through to server */ }
}

const endpoint = ( typeof wpMcpAiChat !== 'undefined' && wpMcpAiChat.toolsEndpoint )
? wpMcpAiChat.toolsEndpoint : '/wp-json/mcp-ai/v1/tools/execute';
const nonce = ( typeof wpMcpAiChat !== 'undefined' ) ? ( wpMcpAiChat.nonce || '' ) : '';

return withRetry( async () => {
const r = await fetch( endpoint, {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
body: JSON.stringify( { tool: toolName, arguments: args } ),
} );
if ( ! r.ok ) { throw new Error( 'Tool execution failed: ' + r.status + ' ' + r.statusText ); }
return r.json();
}, this.config.maxRetries ).catch( e => ( { error: e.message, tool: toolName } ) );
}

/** @return {Object} Memory instance */
getMemory() { return this.memory; }

/** Clear memory and localStorage entry */
clearMemory() { this.memory.clear(); console.log( '[NV oOS LangChain] Memory cleared' ); }

/** @return {boolean} True when initialized */
isReady() { return this.initialized; }
}

window.WP_MCP_AI_LangChain_Orchestrator = WP_MCP_AI_LangChain_Orchestrator;
console.log( '[NV oOS LangChain] Orchestration client loaded (v1.1.0)' );

})();
