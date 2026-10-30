// ---------------------------------------------------------------------------
// design.ts — nvoos-astro-mcp design tool slice (Proposal 095 §3.3).
// Tokens/themes/contrast over the ADS TokenService. Every bulk write is
// transactional: validate → dry-run diff → snapshot → all-or-nothing apply.
// ---------------------------------------------------------------------------

import type { McpServer } from '@modelcontextprotocol/server';
import { z } from 'zod';
import { TokenService, contrastCheck } from '../../src/lib/tokens/service';
import { text } from './util';

/** Process-wide service — the BFF is long-lived, so MCP state persists
 *  across requests within the process (not across restarts; the durable
 *  path is the /manage Design tab + NDS round-trip, Proposal 095 Phase 4). */
export const designService = new TokenService();

export function registerDesignTools(server: McpServer, service: TokenService = designService): void {
	server.registerTool(
		'tokens_get',
		{
			description:
				'Read the ADS design tokens. Returns the resolved semantic layer for the active theme (plus the full group trees when include_groups is true). Read-only.',
			inputSchema: z.object({
				group: z.enum(['core', 'semantic', 'component']).optional(),
				include_groups: z.boolean().optional(),
			}),
		},
		async (args) => {
			const resolved = service.resolveActiveSemantic();
			const payload: Record<string, unknown> = {
				active_theme: service.getActiveTheme(),
				semantic: resolved,
			};
			if (args.include_groups) {
				payload.groups = service.groups();
			}
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'tokens_set',
		{
			description:
				'Apply semantic token overrides transactionally. Validation errors reject the whole change (all-or-nothing). Pass dry_run to receive the diff without applying. Requires a write credential.',
			inputSchema: z.object({
				changes: z.record(z.string(), z.string()),
				dry_run: z.boolean().optional(),
			}),
		},
		async (args) => {
			const result = service.applyOverrides(args.changes, args.dry_run === true);
			return text(
				JSON.stringify(result, null, 2),
				{ applied: result.applied, diff: result.diff, report: result.report, snapshot_id: result.snapshotId ?? null }
			);
		}
	);

	server.registerTool(
		'tokens_import_dtcg',
		{
			description:
				'Import a DTCG 2025.10 JSON document (paste or {"groups": ..., "themes": ...}). Validates, then applies the semantic layer transactionally. Requires a write credential.',
			inputSchema: z.object({
				json: z.string(),
				dry_run: z.boolean().optional(),
			}),
		},
		async (args) => {
			const result = service.importDTCG(args.json, args.dry_run === true);
			return text(
				JSON.stringify({ applied: result.applied, diff: result.diff, report: result.report }, null, 2),
				{ applied: result.applied, diff: result.diff, report: result.report }
			);
		}
	);

	server.registerTool(
		'tokens_rollback',
		{
			description:
				'Restore the token/theme state captured by a snapshot. Without a snapshot_id, rolls back to the most recent snapshot. Requires a write credential.',
			inputSchema: z.object({ snapshot_id: z.string().optional() }),
		},
		async (args) => {
			const result = service.rollback(args.snapshot_id);
			return text(JSON.stringify(result), result);
		}
	);

	server.registerTool(
		'theme_list',
		{
			description: 'List available themes (light, dark, static brand themes, runtime themes). Read-only.',
			inputSchema: z.object({}),
		},
		async () => {
			const payload = {
				active: service.getActiveTheme(),
				themes: service.listThemes().map((name) => {
					const descriptor = service.themeDescriptor(name);
					return { name, description: descriptor?.description ?? null, tokens: descriptor?.tokens ?? {} };
				}),
			};
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'theme_get',
		{
			description: 'Read one theme descriptor (name, description, semantic overrides). Read-only.',
			inputSchema: z.object({ name: z.string() }),
		},
		async (args) => {
			const descriptor = service.themeDescriptor(args.name);
			if (!descriptor) {
				return text(`unknown theme: ${args.name}`);
			}
			return text(JSON.stringify(descriptor, null, 2), descriptor);
		}
	);

	server.registerTool(
		'theme_create',
		{
			description:
				'Create a runtime brand theme from semantic token overrides. Name must match [a-z0-9-]. Requires a write credential.',
			inputSchema: z.object({
				name: z.string(),
				description: z.string().optional(),
				tokens: z.record(z.string(), z.string()),
			}),
		},
		async (args) => {
			const result = service.createTheme({
				name: args.name,
				description: args.description,
				tokens: args.tokens,
			});
			return text(
				JSON.stringify({ applied: result.applied, report: result.report }, null, 2),
				{ applied: result.applied, report: result.report }
			);
		}
	);

	server.registerTool(
		'theme_apply',
		{
			description: 'Set the active theme by name. Requires a write credential.',
			inputSchema: z.object({ name: z.string() }),
		},
		async (args) => {
			const result = service.applyTheme(args.name);
			return text(JSON.stringify(result), result);
		}
	);

	server.registerTool(
		'theme_delete',
		{
			description: 'Delete a runtime theme (static themes cannot be deleted). Requires a write credential.',
			inputSchema: z.object({ name: z.string() }),
		},
		async (args) => {
			const result = service.deleteTheme(args.name);
			return text(JSON.stringify(result), result);
		}
	);

	server.registerTool(
		'contrast_check',
		{
			description:
				'Compute the WCAG 2.2 contrast ratio between two colors (hex preferred) and report AA/AAA pass levels. Read-only.',
			inputSchema: z.object({ fg: z.string(), bg: z.string() }),
		},
		async (args) => {
			const result = contrastCheck(args.fg, args.bg);
			if (!result) {
				return text('could not parse one of the colors (hex required)');
			}
			return text(JSON.stringify(result, null, 2), result);
		}
	);
}
