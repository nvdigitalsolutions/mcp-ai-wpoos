// ---------------------------------------------------------------------------
// tool-def.ts — the single-source tool contract (Proposal 096 Phase 4, the
// 095 registry pattern). Every tool: name, title, description, a Zod input
// schema, explicit annotations, a write flag (auth-gated), and a handler
// that receives parsed args + the resolved serving env. The registry
// coverage test fails CI when any of these is missing.
// ---------------------------------------------------------------------------

import type { StandardSchemaWithJSON, ToolAnnotations } from '@modelcontextprotocol/server';

/** Resolved per-request environment handed to every handler. */
export interface ToolEnv {
	/** The forge home directory (sites registry, vault, profiles). */
	homeDir: string;
	/** The scaffold work directory (targets must live inside it). */
	workDir: string;
	/** NVOOS_FORGE_OPEN_ANALYZE=1 — open-world fetch opt-in. */
	openAnalyze: boolean;
}

export interface ToolResult {
	content: { type: 'text'; text: string }[];
	/** Must be an index-signature-compatible object (the SDK's CallToolResult union). */
	structuredContent?: Record<string, unknown>;
	isError?: boolean;
}

export interface ToolDef {
	name: string;
	title: string;
	description: string;
	/**
	 * The tool's zod schema — typed as the SDK's structural
	 * StandardSchemaWithJSON so z.object({...}) instances register through
	 * the non-deprecated overload (the raw-shape overload's ZodRawShape
	 * expects a zod classic surface that zod 4.6 no longer provides).
	 */
	schema: StandardSchemaWithJSON;
	annotations: ToolAnnotations;
	/** Write tools are gated on the bearer token (auth.ts). */
	write: boolean;
	handler: ( args: unknown, env: ToolEnv ) => Promise<ToolResult>;
}
