// ---------------------------------------------------------------------------
// util.ts — shared MCP tool helpers (Proposal 096 Phase 4; 095 pattern).
//
// Annotation presets: the MCP spec defaults are pessimistic, so every tool
// declares its hints explicitly — clients gate read-only mode on these.
// The forge's fetch-capable tools (site_analyze, site_add, rebuild_trigger)
// declare open-world hints and enforce the SSRF guard in their handlers.
// ---------------------------------------------------------------------------

import type { ToolAnnotations } from '@modelcontextprotocol/server';
import { getMcpRequestContext } from './context.ts';

export const READ_ONLY_TOOL: ToolAnnotations = {
	readOnlyHint: true,
	destructiveHint: false,
	idempotentHint: false,
	openWorldHint: false,
};
export const OPEN_WORLD_READ_TOOL: ToolAnnotations = {
	readOnlyHint: true,
	destructiveHint: false,
	idempotentHint: false,
	openWorldHint: true,
};
export const OPEN_WORLD_WRITE_TOOL: ToolAnnotations = {
	readOnlyHint: false,
	destructiveHint: false,
	idempotentHint: false,
	openWorldHint: true,
};
export const WRITE_TOOL: ToolAnnotations = {
	readOnlyHint: false,
	destructiveHint: false,
	idempotentHint: false,
	openWorldHint: false,
};
export const IDEMPOTENT_WRITE_TOOL: ToolAnnotations = {
	readOnlyHint: false,
	destructiveHint: false,
	idempotentHint: true,
	openWorldHint: false,
};
export const DESTRUCTIVE_WRITE_TOOL: ToolAnnotations = {
	readOnlyHint: false,
	destructiveHint: true,
	idempotentHint: false,
	openWorldHint: false,
};

/** Text tool result — plain-text content plus an optional structured payload. */
export function text( content: string, structured?: unknown ) {
	return {
		content: [ { type: 'text' as const, text: content } ],
		...( structured !== undefined ? { structuredContent: structured as Record<string, unknown> } : {} ),
	};
}

/** Typed error tool result — isError: true so the SDK flags the call. */
export function errorResult( message: string, structured?: unknown ) {
	return { ...text( message, structured ), isError: true };
}

/** One env key from the per-request context (process.env in stdio mode). */
export function envValue( key: string ): string | undefined {
	const ctx = getMcpRequestContext();
	return ctx ? ctx.env[ key ] : process.env[ key ];
}
