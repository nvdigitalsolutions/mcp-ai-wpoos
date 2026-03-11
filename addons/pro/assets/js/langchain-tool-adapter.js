/**
 * LangChain Tool Adapter for WordPress
 *
 * Converts WordPress tool definitions to LangChain-compatible tool formats.
 * Handles both client-side and server-side tool execution.
 *
 * Best practices implemented (v1.1.0):
 * - DynamicStructuredTool-compatible JSON Schema conversion
 * - Retry via shared WP_MCP_AI_LangChain_withRetry (defined in orchestration.js)
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.1.0
 */

/* global wpMcpAiChat */

(function() {
'use strict';

/**
 * Resolve the shared retry helper (exported by langchain-orchestration.js).
 * Falls back to a single attempt when the orchestrator is not yet loaded.
 *
 * @param {Function} fn         Async function
 * @param {number}   maxRetries Retry count
 * @return {Promise<any>}
 */
function retry( fn, maxRetries ) {
const h = window.WP_MCP_AI_LangChain_withRetry;
return h ? h( fn, maxRetries ) : fn();
}

/**
 * WordPress Tool to LangChain Tool Adapter.
 *
 * Provides conversion utilities and execution wrappers for WordPress tools
 * to work seamlessly with LangChain.js agents and chains.
 */
class WP_MCP_AI_LangChain_Tool_Adapter {
constructor() {
this.tools       = [];
this.toolsLoaded = false;
console.log( '[NV oOS LangChain Adapter] Tool adapter initialized (v1.1.0)' );
}

/**
 * Fetch available tools from WordPress REST API with retry.
 *
 * @return {Promise<Array>} Array of WordPress tool definitions
 */
async fetchTools() {
if ( this.toolsLoaded ) {
return this.tools;
}

const endpoint = ( typeof wpMcpAiChat !== 'undefined' && wpMcpAiChat.toolsEndpoint )
? wpMcpAiChat.toolsEndpoint : '/wp-json/mcp-ai/v1/tools';

try {
console.log( '[NV oOS LangChain Adapter] Fetching tools from:', endpoint );
const nonce = ( typeof wpMcpAiChat !== 'undefined' ) ? ( wpMcpAiChat.nonce || '' ) : '';
const response = await retry(
() => fetch( endpoint, { method: 'GET', headers: { 'X-WP-Nonce': nonce } } ),
3
);
if ( ! response.ok ) {
throw new Error( 'Failed to fetch tools: ' + response.status + ' ' + response.statusText );
}
const data = await response.json();
this.tools = Array.isArray( data ) ? data : ( data.tools || [] );
this.toolsLoaded = true;
console.log( '[NV oOS LangChain Adapter] Loaded ' + this.tools.length + ' tools' );
} catch ( error ) {
console.error( '[NV oOS LangChain Adapter] Failed to fetch tools:', error );
}
return this.tools;
}

/**
 * Convert WordPress tool parameter schema to JSON Schema.
 *
 * WordPress REST API already uses JSON Schema format; this normalises
 * missing fields for LangChain DynamicStructuredTool compatibility.
 *
 * @param {Object} wpSchema WordPress parameter schema
 * @return {Object} Normalised JSON Schema
 */
convertSchema( wpSchema ) {
if ( ! wpSchema ) {
return { type: 'object', properties: {}, required: [] };
}
if ( wpSchema.type && wpSchema.properties ) {
return {
type: wpSchema.type,
properties: wpSchema.properties,
required: wpSchema.required || [],
};
}
return { type: 'object', properties: wpSchema.properties || {}, required: wpSchema.required || [] };
}

/**
 * Convert a WordPress tool definition to LangChain DynamicStructuredTool format.
 *
 * @param {Object} wpTool WordPress tool definition
 * @return {Object} LangChain-compatible tool descriptor
 */
convertToLangChainTool( wpTool ) {
return {
name: wpTool.name || wpTool.slug,
description: wpTool.description || 'No description provided',
schema: this.convertSchema( wpTool.parameters ),
client_executable: wpTool.client_executable || false,
func: async ( input ) => {
if ( wpTool.client_executable ) {
return this.executeClientSide( wpTool.name || wpTool.slug, input );
}
return this.executeServerSide( wpTool.name || wpTool.slug, input );
},
};
}

/**
 * Execute a tool client-side via Transformers.js (falls back to server-side).
 *
 * @param {string} toolName Tool name
 * @param {Object} args     Tool arguments
 * @return {Promise<any>}
 */
async executeClientSide( toolName, args ) {
console.log( '[NV oOS LangChain Adapter] Executing client-side tool:', toolName );
if ( ! window.WP_MCP_AI_Transformers ) {
return this.executeServerSide( toolName, args );
}
const methodMap = {
client_summarize_text: 'summarizeText',
client_analyze_sentiment: 'analyzeSentiment',
client_extract_entities: 'extractEntities',
client_translate_text: 'translateText',
client_question_answering: 'answerQuestion',
client_semantic_search: 'generateEmbeddings',
};
const method = methodMap[ toolName ];
if ( ! method || ! window.WP_MCP_AI_Transformers[ method ] ) {
return this.executeServerSide( toolName, args );
}
try {
return await retry( () => window.WP_MCP_AI_Transformers[ method ]( args ), 3 );
} catch ( error ) {
console.error( '[NV oOS LangChain Adapter] Client-side execution failed:', error );
return this.executeServerSide( toolName, args );
}
}

/**
 * Execute a tool server-side via the WordPress REST API with retry.
 *
 * @param {string} toolName Tool name
 * @param {Object} args     Tool arguments
 * @return {Promise<any>}
 */
async executeServerSide( toolName, args ) {
console.log( '[NV oOS LangChain Adapter] Executing server-side tool:', toolName );

const endpoint = ( typeof wpMcpAiChat !== 'undefined' && wpMcpAiChat.toolsEndpoint )
? wpMcpAiChat.toolsEndpoint : '/wp-json/mcp-ai/v1/tools/execute';
const nonce = ( typeof wpMcpAiChat !== 'undefined' ) ? ( wpMcpAiChat.nonce || '' ) : '';

try {
return await retry( async () => {
const response = await fetch( endpoint, {
method: 'POST',
headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
body: JSON.stringify( { tool: toolName, arguments: args } ),
} );
if ( ! response.ok ) {
const errorText = await response.text();
throw new Error( 'Server-side execution failed (' + response.status + '): ' + errorText );
}
const result = await response.json();
console.log( '[NV oOS LangChain Adapter] Server-side result for', toolName, result );
return result;
}, 3 );
} catch ( error ) {
console.error( '[NV oOS LangChain Adapter] Server-side execution failed:', error );
return { error: error.message, tool: toolName, args };
}
}

/**
 * Fetch and convert all WordPress tools to LangChain DynamicStructuredTool format.
 *
 * @return {Promise<Object[]>}
 */
async convertAllTools() {
if ( ! this.toolsLoaded ) { await this.fetchTools(); }
return this.tools.map( tool => this.convertToLangChainTool( tool ) );
}

/**
 * Get a tool definition by name or slug.
 *
 * @param {string} name Tool name or slug
 * @return {Object|null}
 */
getTool( name ) {
return this.tools.find( t => t.name === name || t.slug === name ) || null;
}

/**
 * Filter tools by required WordPress capability.
 * Tools with no required_capability are always included.
 *
 * @param {string} capability WordPress capability (e.g. 'edit_posts')
 * @return {Object[]}
 */
filterByCapability( capability ) {
return this.tools.filter( t => ! t.required_capability || t.required_capability === capability );
}

/**
 * Filter tools by execution type.
 *
 * @param {'client'|'server'} type Execution type
 * @return {Object[]}
 */
filterByExecutionType( type ) {
if ( type === 'client' ) { return this.tools.filter( t => t.client_executable === true ); }
return this.tools.filter( t => ! t.client_executable );
}
}

window.WP_MCP_AI_LangChain_Tool_Adapter = new WP_MCP_AI_LangChain_Tool_Adapter();
console.log( '[NV oOS LangChain Adapter] Tool adapter loaded (v1.1.0)' );

})();
