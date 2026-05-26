/**
 * Typed build functions for NV oOS REST API endpoints.
 *
 * Centralises URL construction so that every route follows the same
 * base path and parameter-encoding conventions.  Callers import the
 * builder they need and pass only the varying parts (IDs, query params).
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

import type { GlobalChatConfig, ToolExecutionPayload } from './types';

// ── Endpoint builders ────────────────────────────────────────────────

/** Base for NV oOS REST routes (e.g. `wp-json/mcp-ai/v1`). */
function restBase( config: Pick< GlobalChatConfig, 'restUrl' > ): string {
	// Strip trailing slash so we consistently join with `/`.
	return config.restUrl.replace( /\/+$/, '' );
}

/** Chat endpoint (POST). */
export function chatEndpoint( config: Pick< GlobalChatConfig, 'restUrl' > ): string {
	return `${ restBase( config ) }/chat`;
}

/** Chat-client endpoint (POST, for the SPA / SSE adapter). */
export function chatClientEndpoint( config: Pick< GlobalChatConfig, 'restUrl' > ): string {
	return `${ restBase( config ) }/chat-client`;
}

/** Tools list endpoint (GET). */
export function toolsListEndpoint( config: Pick< GlobalChatConfig, 'restUrl' > ): string {
	return `${ restBase( config ) }/tools`;
}

/** Tool execution endpoint (POST). */
export function toolExecuteEndpoint( config: Pick< GlobalChatConfig, 'restUrl' > ): string {
	return `${ restBase( config ) }/tools/execute`;
}

/** Upload endpoint (POST multipart). */
export function uploadEndpoint( config: Pick< GlobalChatConfig, 'restUrl' | 'uploadEndpoint' > ): string {
	return config.uploadEndpoint || `${ restBase( config ) }/upload`;
}

/** Transcripts endpoint (GET / POST / DELETE). */
export function transcriptsEndpoint(
	config: Pick< GlobalChatConfig, 'restUrl' | 'transcriptsEndpoint' >,
	sessionKey?: string,
): string {
	const base = config.transcriptsEndpoint || `${ restBase( config ) }/transcripts`;
	if ( ! sessionKey ) {
		return base;
	}
	return `${ base }/${ encodeURIComponent( sessionKey ) }`;
}

/** History sessions endpoint (GET). */
export function historyEndpoint(
	config: Pick< GlobalChatConfig, 'restUrl' >,
	params?: Record< string, string | number >,
): string {
	let url = `${ restBase( config ) }/history`;
	if ( params ) {
		const qs = Object.entries( params )
			.map(
				( [ k, v ] ) =>
					`${ encodeURIComponent( k ) }=${ encodeURIComponent( String( v ) ) }`,
			)
			.join( '&' );
		if ( qs ) {
			url += `?${ qs }`;
		}
	}
	return url;
}

/** SSE endpoint (GET, EventSource). */
export function sseEndpoint(
	config: Pick< GlobalChatConfig, 'restUrl' >,
	params?: Record< string, string | number >,
): string {
	let url = `${ restBase( config ) }/sse`;
	if ( params ) {
		const qs = Object.entries( params )
			.map(
				( [ k, v ] ) =>
					`${ encodeURIComponent( k ) }=${ encodeURIComponent( String( v ) ) }`,
			)
			.join( '&' );
		if ( qs ) {
			url += `?${ qs }`;
		}
	}
	return url;
}

// ── Payload builders ─────────────────────────────────────────────────

/**
 * Build the payload for a chat request (non-streaming fallback).
 */
export function buildChatPayload(
	assistantId: string | number,
	messages: unknown[],
): Record< string, unknown > {
	return {
		assistant_id: assistantId,
		messages,
		save_transcript: true,
	};
}

/**
 * Build the payload for a tool execution request.
 */
export function buildToolExecutionPayload(
	payload: ToolExecutionPayload,
): Record< string, unknown > {
	return {
		tool: payload.tool,
		arguments: payload.arguments,
		...( payload.assistant_id ? { assistant_id: payload.assistant_id } : {} ),
	};
}
