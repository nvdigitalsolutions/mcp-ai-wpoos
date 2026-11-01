// ---------------------------------------------------------------------------
// design.ts — nvoos-astro-mcp design tool slice (Proposal 095 §3.3).
// Tokens/themes/contrast over the ADS TokenService. Every bulk write is
// transactional: validate → dry-run diff → snapshot → all-or-nothing apply.
// ---------------------------------------------------------------------------

import type { McpServer } from '@modelcontextprotocol/server';
import { z } from 'zod';
import { analyzeDesignLanguage, type AnalyzeExtra } from '../../src/lib/design-import/analyzer';
import { compareDesignLanguages } from '../../src/lib/design-import/compare';
import { detectMode, fetchDesignSource, FetchGuardError } from '../../src/lib/design-import/fetcher';
import { fetchAstroThemeAssets } from '../../src/lib/design-import/modes/astro-theme';
import { fetchWpThemeAssets, wpThemeJsonFamilies, wpThemeJsonRoles } from '../../src/lib/design-import/modes/wp';
import { buildAdaptationPlan, type ContentHint } from '../../src/lib/design-import/plan';
import { renderDesignSource } from '../../src/lib/design-import/render';
import { emitTheme } from '../../src/lib/design-import/theme';
import type { DesignAnalysisResult } from '../../src/lib/design-import/types';
import { TokenService, contrastCheck } from '../../src/lib/tokens/service';
import {
	DESTRUCTIVE_WRITE_TOOL,
	IDEMPOTENT_WRITE_TOOL,
	OPEN_WORLD_READ_TOOL,
	OPEN_WORLD_WRITE_TOOL,
	READ_ONLY_TOOL,
	WRITE_TOOL,
	envValue,
	errorResult,
	text,
} from './util';

/** Process-wide service — the BFF is long-lived, so MCP state persists
 *  across requests within the process (not across restarts; the durable
 *  path is the /manage Design tab + NDS round-trip, Proposal 095 Phase 4). */
export const designService = new TokenService();

/** Shared analysis path for every design-import tool (Proposal 098). */
async function analyzeUrl(url: string, maxStylesheets = 8): Promise<DesignAnalysisResult> {
	const allowPrivate = envValue('NVOOS_MCP_FETCH_ALLOW_PRIVATE') === '1';
	const fetchOptions = { allowPrivate };
	const { html, stylesheets, finalUrl } = await fetchDesignSource(url, {
		...fetchOptions,
		maxStylesheets,
	});
	const mode = detectMode(url, html);
	const extra: AnalyzeExtra = {};

	// Mode-driven extras (Phase 1).
	if (mode === 'wordpress') {
		const wp = await fetchWpThemeAssets(html, finalUrl, fetchOptions);
		if (wp.themeJson) {
			extra.themeJsonRoles = wpThemeJsonRoles(wp.themeJson);
			extra.themeJsonFamilies = wpThemeJsonFamilies(wp.themeJson);
		}
	}
	if (mode === 'astro-theme') {
		const astro = await fetchAstroThemeAssets(url, fetchOptions);
		stylesheets.push(...astro.stylesheets);
		extra.tokenDocuments = astro.tokenDocuments;
	}

	// Opt-in rendered capture for JS-heavy sources (Phase 2.1) — degrades to
	// the static read when the browser or the opt-in is absent.
	if (envValue('NVOOS_MCP_FETCH_RENDER') === '1') {
		const rendered = await renderDesignSource(finalUrl);
		if (rendered) {
			return analyzeDesignLanguage(rendered.renderedHtml, [...stylesheets, rendered.computedCss], {
				url,
				mode,
				fetchedAt: new Date().toISOString(),
			}, extra);
		}
	}

	return analyzeDesignLanguage(html, stylesheets, {
		url,
		mode,
		fetchedAt: new Date().toISOString(),
	}, extra);
}

/** Reuse a caller-supplied analysis, or fetch + analyze the URL. */
async function resolveAnalysis(args: { url?: string; analysis?: unknown; max_stylesheets?: number }): Promise<DesignAnalysisResult> {
	if (args.analysis && typeof args.analysis === 'object') {
		const candidate = args.analysis as { designLanguage?: unknown; source?: unknown };
		if (candidate.designLanguage && candidate.source) {
			return candidate as DesignAnalysisResult;
		}
	}
	if (!args.url) {
		throw new FetchGuardError('missing_input', 'design tools need either a url or a previous analysis.');
	}
	return analyzeUrl(args.url, args.max_stylesheets ?? 8);
}

/** Typed guard errors for the design-import tools. */
function guardErrorResult(tool: string, error: unknown, url: string) {
	const code = error instanceof Error && 'code' in error ? String((error as { code?: unknown }).code) : undefined;
	const suffix = code ? ` (${code})` : '';
	return errorResult(`${tool} failed${suffix}: ${(error as Error).message}`, {
		error: 'analysis_failed',
		code: code ?? 'unknown',
		url,
	});
}

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

	server.registerTool(
		'design_analyze',
		{
			title: 'Analyze site design language',
			description:
				'Analyze a website URL (Astro theme repo, WordPress site, or any website) and extract its design language — palette roles, typography, spacing, component vocabulary, and a concept summary — as an adapted, inspired-by token draft. Never clones: the result is design data plus an adaptation brief only; no source CSS, markup, copy, or assets are copied. Fetches the target URL (http/https only; private hosts denied unless NVOOS_MCP_FETCH_ALLOW_PRIVATE=1; rendered capture requires NVOOS_MCP_FETCH_RENDER=1). Read-only, open-world.',
			annotations: OPEN_WORLD_READ_TOOL,
			inputSchema: z.object({
				url: z.string().url(),
				max_stylesheets: z.number().int().min(0).max(16).optional(),
			}),
		},
		async (args) => {
			try {
				const result = await analyzeUrl(args.url, args.max_stylesheets ?? 8);
				return text(JSON.stringify(result, null, 2), result);
			} catch (error) {
				return guardErrorResult('design_analyze', error, args.url);
			}
		}
	);

	server.registerTool(
		'design_compare',
		{
			title: 'Compare design language drift',
			description:
				'Re-analyze a source URL and diff against a previous design_analyze snapshot (pass it back as the snapshot argument). Reports role/concept/type/scale drift — the re-extraction discipline for sources whose design evolves. Read-only, open-world.',
			annotations: OPEN_WORLD_READ_TOOL,
			inputSchema: z.object({
				url: z.string().url(),
				snapshot: z.record(z.string(), z.unknown()).optional(),
			}),
		},
		async (args) => {
			try {
				const after = await analyzeUrl(args.url);
				if (!args.snapshot?.designLanguage) {
					return text(JSON.stringify({ snapshot: after, note: 'first capture — pass this result back as the snapshot next time' }, null, 2), {
						snapshot: after,
						note: 'first capture — pass this result back as the snapshot next time',
					});
				}
				const before = args.snapshot as unknown as DesignAnalysisResult;
				const diff = compareDesignLanguages(before.designLanguage, after.designLanguage);
				return text(JSON.stringify(diff, null, 2), diff);
			} catch (error) {
				return guardErrorResult('design_compare', error, args.url);
			}
		}
	);

	server.registerTool(
		'design_plan',
		{
			title: 'Plan content-first adaptation',
			description:
				'Build a deterministic adaptation plan from an analysis (pass a design_analyze result) or a URL: palette and typography mapped onto the USER\'s own content slots (storefront, blog, or marketing), a component checklist, and execution steps. The user\'s content drives the layout; the source only supplies the design language. Read-only, open-world when a URL is fetched.',
			annotations: OPEN_WORLD_READ_TOOL,
			inputSchema: z.object({
				url: z.string().url().optional(),
				analysis: z.record(z.string(), z.unknown()).optional(),
				content_hint: z.enum(['storefront', 'blog', 'marketing']).optional(),
			}),
		},
		async (args) => {
			try {
				const analysis = await resolveAnalysis(args);
				const plan = buildAdaptationPlan(analysis, (args.content_hint as ContentHint | undefined) ?? 'storefront');
				return text(JSON.stringify(plan, null, 2), plan);
			} catch (error) {
				return guardErrorResult('design_plan', error, args.url ?? 'provided analysis');
			}
		}
	);

	server.registerTool(
		'design_theme',
		{
			title: 'Emit theme from design language',
			description:
				'Emit a runtime theme from an analysis (pass a design_analyze result) or a URL: a tokens_import_dtcg-ready DTCG document, a theme_create descriptor, a WCAG 2.2 contrast audit of the critical pairs, and attribution. dry_run previews; otherwise the runtime theme is created (apply it afterwards with theme_apply). Requires a write credential.',
			annotations: OPEN_WORLD_WRITE_TOOL,
			inputSchema: z.object({
				url: z.string().url().optional(),
				analysis: z.record(z.string(), z.unknown()).optional(),
				name: z.string().regex(/^[a-z0-9-]+$/).optional(),
				dry_run: z.boolean().optional(),
			}),
		},
		async (args) => {
			try {
				const analysis = await resolveAnalysis(args);
				const emitted = emitTheme(analysis, args.name);
				const payload: Record<string, unknown> = {
					...emitted,
					applied: false,
				};
				if (args.dry_run !== true) {
					const result = service.createTheme(emitted.themeDescriptor);
					payload.applied = result.applied;
					payload.report = result.report;
					payload.snapshot_id = result.snapshotId ?? null;
				}
				return text(JSON.stringify(payload, null, 2), payload);
			} catch (error) {
				return guardErrorResult('design_theme', error, args.url ?? 'provided analysis');
			}
		}
	);
}
