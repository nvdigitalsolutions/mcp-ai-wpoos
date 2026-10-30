// ---------------------------------------------------------------------------
// design.ts — nvoos-astro-mcp design tool slice (Proposal 095 §3.3).
// Tokens/themes/contrast over the ADS TokenService. Every bulk write is
// transactional: validate → dry-run diff → snapshot → all-or-nothing apply.
// ---------------------------------------------------------------------------

import type { McpServer } from '@modelcontextprotocol/server';
import { z } from 'zod';
import { TokenService, contrastCheck } from '../../src/lib/tokens/service';
import {
	DESTRUCTIVE_WRITE_TOOL,
	IDEMPOTENT_WRITE_TOOL,
	READ_ONLY_TOOL,
	WRITE_TOOL,
	text,
} from './util';

/** Process-wide service — the BFF is long-lived, so MCP state persists
 *  across requests within the process (not across restarts; the durable
 *  path is the /manage Design tab + NDS round-trip, Proposal 095 Phase 4). */
export const designService = new TokenService();

export function registerDesignTools(server: McpServer, service: TokenService = designService): void {
	server.registerTool(
		'tokens_get',
		{
			title: 'Read design tokens',
			description:
				'Read the ADS design tokens. Returns the resolved semantic layer for the active theme (plus the full group trees when include_groups is true). Read-only.',
			annotations: READ_ONLY_TOOL,
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
			title: 'Set design tokens',
			description:
				'Apply semantic token overrides transactionally. Validation errors reject the whole change (all-or-nothing). Pass dry_run to receive the diff without applying. On apply, the result carries a snapshot_id that undoes exactly this write when passed to tokens_rollback. Requires a write credential.',
			annotations: IDEMPOTENT_WRITE_TOOL,
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
			title: 'Import DTCG tokens',
			description:
				'Import a DTCG 2025.10 JSON document (paste or {"groups": ..., "themes": ...}). Validates, then applies the semantic layer transactionally. Requires a write credential.',
			annotations: IDEMPOTENT_WRITE_TOOL,
			inputSchema: z.object({
				json: z.string(),
				dry_run: z.boolean().optional(),
			}),
		},
		async (args) => {
			const result = service.importDTCG(args.json, args.dry_run === true);
			const payload = {
				applied: result.applied,
				diff: result.diff,
				report: result.report,
				snapshot_id: result.snapshotId ?? null,
			};
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'tokens_rollback',
		{
			title: 'Roll back tokens',
			description:
				'Restore the token/theme state captured by a snapshot. Pass the snapshot_id returned by a previous write (tokens_set, tokens_import_dtcg, theme_create, theme_apply, theme_delete) to undo exactly that write; without a snapshot_id, rolls back to the most recent snapshot. Requires a write credential.',
			annotations: WRITE_TOOL,
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
			title: 'List themes',
			description: 'List available themes (light, dark, static brand themes, runtime themes). Read-only.',
			annotations: READ_ONLY_TOOL,
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
			title: 'Get theme',
			description: 'Read one theme descriptor (name, description, semantic overrides). Read-only.',
			annotations: READ_ONLY_TOOL,
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
			title: 'Create runtime theme',
			description:
				'Create a runtime brand theme from semantic token overrides. Name must match [a-z0-9-]. Requires a write credential.',
			annotations: WRITE_TOOL,
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
			const payload = {
				applied: result.applied,
				report: result.report,
				snapshot_id: result.snapshotId ?? null,
			};
			return text(JSON.stringify(payload, null, 2), payload);
		}
	);

	server.registerTool(
		'theme_apply',
		{
			title: 'Apply theme',
			description: 'Set the active theme by name. Requires a write credential.',
			annotations: IDEMPOTENT_WRITE_TOOL,
			inputSchema: z.object({ name: z.string() }),
		},
		async (args) => {
			const result = service.applyTheme(args.name);
			const payload = {
				ok: result.ok,
				message: result.message,
				snapshot_id: result.snapshotId ?? null,
			};
			return text(JSON.stringify(payload), payload);
		}
	);

	server.registerTool(
		'theme_delete',
		{
			title: 'Delete runtime theme',
			description: 'Delete a runtime theme (static themes cannot be deleted). Requires a write credential.',
			annotations: DESTRUCTIVE_WRITE_TOOL,
			inputSchema: z.object({ name: z.string() }),
		},
		async (args) => {
			const result = service.deleteTheme(args.name);
			const payload = {
				ok: result.ok,
				message: result.message,
				snapshot_id: result.snapshotId ?? null,
			};
			return text(JSON.stringify(payload), payload);
		}
	);

	server.registerTool(
		'contrast_check',
		{
			title: 'Check color contrast',
			description:
				'Compute the WCAG 2.2 contrast ratio between two colors (hex preferred) and report AA/AAA pass levels. Read-only.',
			annotations: READ_ONLY_TOOL,
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
